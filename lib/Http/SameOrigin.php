<?php
/**
 * @license GPL-3.0
 * @desc Same-origin (Origin / Referer header) verification for
 *       state changing requests. Protects the admin area against
 *       Cross-Site Request Forgery without per-form tokens.
 *       See OWASP CSRF Prevention Cheat Sheet "Verifying Origin
 *       With Standard Headers".
 */

namespace SLiMS\Http;

use SLiMS\Config;

final class SameOrigin
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * Return false when a state changing request (POST, PUT, ...)
     * clearly comes from another site.
     *
     * - Origin header present (and not "null") -> must be a trusted host
     * - otherwise Referer present               -> must be a trusted host
     * - Origin "null" without a Referer         -> rejected
     * - neither header present                  -> allowed (non-browser client;
     *                                              a browser always sends Origin on POST)
     */
    public static function isValidRequest(): bool
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (in_array($method, self::SAFE_METHODS, true)) return true;

        $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));

        if ($origin !== '' && strtolower($origin) !== 'null') return self::isTrusted($origin);
        if ($referer !== '') return self::isTrusted($referer);
        if (strtolower($origin) === 'null') return false;

        return true;
    }

    /**
     * Stop the request with HTTP 403 when it is not same-origin
     */
    public static function enforce(): void
    {
        if (self::isValidRequest()) return;

        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Request blocked: cross-site request detected (CSRF protection).');
    }

    private static function isTrusted(string $url): bool
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === '') return false;

        return in_array($host, self::trustedHosts(), true);
    }

    /**
     * Hosts this SLiMS instance is served from
     */
    private static function trustedHosts(): array
    {
        $hosts = [];

        $hosts[] = self::stripPort($_SERVER['HTTP_HOST'] ?? '');
        $hosts[] = self::stripPort($_SERVER['SERVER_NAME'] ?? '');

        $config = Config::getInstance();

        // custom base url (e.g. https://library.example.org)
        $baseUrl = (string)$config->get('url.base', '');
        if ($baseUrl !== '') $hosts[] = strtolower((string)parse_url($baseUrl, PHP_URL_HOST));

        // reverse proxy / load balancer
        $loadBalanced = $config->get('loadbalanced');
        if ($loadBalanced && !empty($loadBalanced['env'])) {
            $hosts[] = self::stripPort($loadBalanced['options']['host'] ?? '');
            foreach (explode(',', (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? '')) as $forwardedHost) {
                $hosts[] = self::stripPort($forwardedHost);
            }
        }

        // extra hosts, e.g. config/csrf.php: return ['trusted_hosts' => ['library.example.org']];
        foreach ((array)$config->get('csrf.trusted_hosts', []) as $extraHost) {
            $hosts[] = self::stripPort((string)$extraHost);
        }

        return array_values(array_unique(array_filter($hosts)));
    }

    private static function stripPort(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') return '';
        // [ipv6]:port
        if ($host[0] === '[') return substr($host, 0, (int)strpos($host, ']') + 1);
        return explode(':', $host)[0];
    }
}
