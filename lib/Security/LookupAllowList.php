<?php
/**
 * @license GPL-3.0
 * @desc Allow-list for the generic admin AJAX lookup endpoints
 *       (admin/AJAX_lookup_handler.php, AJAX_check_id.php,
 *       AJAX_vocabolary_control.php, circulation/item_AJAX_lookup_handler.php).
 *
 * Those endpoints receive table and column names from the browser and put
 * them into SQL. Without an allow-list any logged-in staff account could
 * read any table (e.g. user.passwd) or inject SQL through column names.
 *
 * Plugins that need more tables/columns can extend the list in
 * config/ajax_lookup.php:
 *   return ['allowed' => ['lookup' => ['my_table' => ['id', 'name']]]];
 */

namespace SLiMS\Security;

final class LookupAllowList
{
    /** table => allowed columns, per endpoint */
    private const DEFAULTS = [
        // admin/AJAX_lookup_handler.php
        'lookup' => [
            'mst_topic' => ['topic_id', 'topic', 'topic_type', 'classification'],
            'mst_publisher' => ['publisher_id', 'publisher_name'],
            'mst_place' => ['place_id', 'place_name'],
            'mst_author' => ['author_id', 'author_name', 'author_year', 'authority_type'],
            'biblio' => ['biblio_id', 'title', 'edition', 'publish_year'],
        ],
        // admin/AJAX_check_id.php
        'check_id' => [
            'member' => ['member_id'],
            'item' => ['item_code'],
        ],
        // admin/AJAX_vocabolary_control.php
        'vocabolary' => [
            'mst_topic' => ['topic_id', 'topic', 'topic_type'],
        ],
        // admin/modules/circulation/item_AJAX_lookup_handler.php (table is fixed)
        'item_lookup' => [
            'item' => ['i.item_code', 'item_code', 'b.title', 'title'],
        ],
    ];

    /**
     * Are the table and every column of a "col1:col2:..." list allowed?
     */
    public static function isAllowed(string $endpoint, string $table, string $colonSeparatedFields): bool
    {
        $allowed = self::DEFAULTS[$endpoint] ?? [];
        foreach ((array)config('ajax_lookup.allowed.' . $endpoint, []) as $extraTable => $extraFields) {
            $allowed[$extraTable] = array_merge($allowed[$extraTable] ?? [], (array)$extraFields);
        }

        $table = trim($table);
        if (!isset($allowed[$table])) return false;

        $fields = array_map('trim', explode(':', $colonSeparatedFields));
        if (!$fields || in_array('', $fields, true)) return false;

        foreach ($fields as $field) {
            if (!preg_match('/^[A-Za-z0-9_.]+$/', $field)) return false;
            if (!in_array($field, $allowed[$table], true)) return false;
        }

        return true;
    }

    /**
     * Stop the request with HTTP 403
     */
    public static function deny(bool $json = false): void
    {
        http_response_code(403);
        if ($json) {
            header('Content-Type: application/json');
            exit(json_encode(['id' => 0, 'text' => 'Lookup not allowed']));
        }
        exit('<option value="0">Lookup not allowed</option>');
    }
}
