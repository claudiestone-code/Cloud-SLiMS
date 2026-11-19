<?php
/**
 * @license GPL-3.0
 * @desc Brute-force protection for login, two factor and password reset forms.
 *
 * Failed attempts are counted per account and per client IP inside a time
 * window. When a limit is reached the account (or IP) is locked for a while.
 * Counters live in the `login_throttle` table (created automatically), so the
 * protection also works when SLiMS runs on several application servers.
 *
 * Tunable in config/login_throttle.php:
 *   return [
 *     'enabled' => true,
 *     'max_attempts' => 5,      // per account inside the window
 *     'ip_max_attempts' => 20,  // per client IP inside the window
 *     'window' => 900,          // seconds
 *     'lockout' => 900,         // seconds
 *   ];
 */

namespace SLiMS\Auth;

use SLiMS\DB;

final class LoginThrottle
{
    private static bool $tableReady = false;

    /**
     * Seconds the caller must wait before trying again (0 = allowed)
     */
    public static function retryAfter(string $scope, string $account): int
    {
        if (!self::enabled()) return 0;

        try {
            self::ensureTable();
            $now = time();
            $wait = 0;
            foreach (self::keys($scope, $account) as $key => $limit) {
                $row = self::row($key);
                if ($row && (int)$row['locked_until'] > $now) {
                    $wait = max($wait, (int)$row['locked_until'] - $now);
                }
            }
            return $wait;
        } catch (\Throwable $e) {
            // never block logins because the throttle store is unavailable
            return 0;
        }
    }

    /**
     * Register one failed (or, for rate limited forms, one) attempt
     */
    public static function fail(string $scope, string $account): void
    {
        if (!self::enabled()) return;

        try {
            self::ensureTable();
            $now = time();
            $window = (int)config('login_throttle.window', 900);
            $lockout = (int)config('login_throttle.lockout', 900);

            foreach (self::keys($scope, $account) as $key => $limit) {
                $row = self::row($key);
                $inWindow = $row && ((int)$row['window_start'] + $window) > $now;
                $attempts = $inWindow ? (int)$row['attempts'] + 1 : 1;
                $windowStart = $inWindow ? (int)$row['window_start'] : $now;
                $lockedUntil = $attempts >= $limit ? $now + $lockout : (int)($row['locked_until'] ?? 0);

                DB::getInstance()->prepare(
                    'INSERT INTO `login_throttle` (`throttle_key`, `attempts`, `window_start`, `locked_until`) VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE `attempts` = VALUES(`attempts`), `window_start` = VALUES(`window_start`), `locked_until` = VALUES(`locked_until`)'
                )->execute([$key, $attempts, $windowStart, $lockedUntil]);
            }

            // occasional housekeeping
            if (random_int(1, 50) === 1) {
                DB::getInstance()->prepare('DELETE FROM `login_throttle` WHERE `locked_until` < ? AND `window_start` < ?')
                    ->execute([$now, $now - $window]);
            }
        } catch (\Throwable $e) {
            // ignore storage errors (see retryAfter)
        }
    }

    /**
     * Reset the account counter after a successful login.
     * The per-IP counter is kept on purpose.
     */
    public static function clear(string $scope, string $account): void
    {
        if (!self::enabled()) return;

        try {
            self::ensureTable();
            DB::getInstance()->prepare('DELETE FROM `login_throttle` WHERE `throttle_key` = ?')
                ->execute([self::accountKey($scope, $account)]);
        } catch (\Throwable $e) {
        }
    }

    /**
     * Human readable "try again" message
     */
    public static function message(int $seconds): string
    {
        $minutes = max(1, (int)ceil($seconds / 60));
        return str_replace('{minutes}', $minutes, __('Too many failed attempts. Please try again in {minutes} minute(s).'));
    }

    private static function enabled(): bool
    {
        return config('login_throttle.enabled', true) !== false;
    }

    /**
     * @return array<string,int> key => attempt limit
     */
    private static function keys(string $scope, string $account): array
    {
        return [
            self::accountKey($scope, $account) => max(1, (int)config('login_throttle.max_attempts', 5)),
            hash('sha256', $scope . '|ip|' . (string)ip()) => max(1, (int)config('login_throttle.ip_max_attempts', 20)),
        ];
    }

    private static function accountKey(string $scope, string $account): string
    {
        return hash('sha256', $scope . '|account|' . mb_strtolower(trim($account)));
    }

    private static function row(string $key): ?array
    {
        $statement = DB::getInstance()->prepare('SELECT `attempts`, `window_start`, `locked_until` FROM `login_throttle` WHERE `throttle_key` = ?');
        $statement->execute([$key]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function ensureTable(): void
    {
        if (self::$tableReady) return;

        DB::getInstance()->exec('CREATE TABLE IF NOT EXISTS `login_throttle` (
            `throttle_key` CHAR(64) NOT NULL,
            `attempts` INT NOT NULL DEFAULT 0,
            `window_start` INT NOT NULL DEFAULT 0,
            `locked_until` INT NOT NULL DEFAULT 0,
            PRIMARY KEY (`throttle_key`)
        ) DEFAULT CHARSET=utf8mb4');

        self::$tableReady = true;
    }
}
