<?php

namespace PGCO_Tables;

defined('ABSPATH') || exit;

/**
 * Reads Google Sheet data through the official Google Sheets API (v4)
 * instead of scraping the public HTML export, which is unreliable from
 * some server IPs (Google's bot-protection blocks many hosting IP ranges).
 *
 * The person only ever pastes a normal sheet URL (edit or pubhtml link)
 * containing a gid; this class resolves that gid to a tab name via the
 * spreadsheet metadata endpoint, then fetches just that tab's values.
 */
class GoogleSheetsApi
{
    private const API_BASE = 'https://sheets.googleapis.com/v4/spreadsheets';

    /**
     * How long the gid -> tab title map of a spreadsheet is remembered. Tables of one spreadsheet then share one
     * metadata request, which matters when all tables are synced in a row (Google's per-minute read quota).
     */
    private const TITLES_CACHE_SECONDS = 10 * MINUTE_IN_SECONDS;

    /**
     * Extract the Spreadsheet ID and gid from any Google Sheets URL format
     * (regular /d/{id}/edit, or published /d/e/{publish_id}/pubhtml).
     *
     * Only the regular /d/{id}/edit format carries a usable Spreadsheet ID
     * for the API; the "published to web" ID (/d/e/...) is a different,
     * opaque identifier that the Sheets API cannot use directly.
     *
     * @param string $url
     * @return array{spreadsheet_id: ?string, gid: string}
     */
    public static function parse_url(string $url): array
    {
        $gid = '0';
        if (preg_match('/[?&#]gid=([0-9]+)/', $url, $gid_match)) {
            $gid = $gid_match[1];
        }

        $spreadsheet_id = null;

        // Regular edit link: /spreadsheets/d/{ID}/edit...
        if (preg_match('#/spreadsheets/d/(?!e/)([a-zA-Z0-9_-]+)#', $url, $matches)) {
            $spreadsheet_id = $matches[1];
        }

        return [
            'spreadsheet_id' => $spreadsheet_id,
            'gid'            => $gid,
        ];
    }

    /**
     * Resolve a gid to its sheet (tab) title using the spreadsheet metadata.
     *
     * @param string $spreadsheet_id
     * @param string $gid
     * @param string $api_key
     * @return array{success: bool, title: ?string, message: ?string}
     */
    public static function resolve_sheet_title(string $spreadsheet_id, string $gid, string $api_key): array
    {
        $cache_key = 'pgco_tables_sheets_' . md5($spreadsheet_id);
        $titles = get_transient($cache_key);

        // A tab added or renamed since the map was stored is simply missing from it: the map is then fetched again.
        if (is_array($titles) && isset($titles[$gid])) {
            return ['success' => true, 'title' => $titles[$gid], 'message' => null];
        }

        $result = self::request_with_retry(function () use ($spreadsheet_id, $api_key) {
            $url = sprintf(
                '%s/%s?fields=sheets.properties&key=%s',
                self::API_BASE,
                rawurlencode($spreadsheet_id),
                rawurlencode($api_key)
            );

            $response = wp_remote_get($url, ['timeout' => 15]);

            if (is_wp_error($response)) {
                return ['success' => false, 'titles' => [], 'message' => $response->get_error_message(), 'retryable' => false];
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($code !== 200) {
                $message = $body['error']['message'] ?? ('HTTP ' . $code);
                return ['success' => false, 'titles' => [], 'message' => $message, 'retryable' => self::is_quota_error($message, $code)];
            }

            $titles = [];
            foreach (($body['sheets'] ?? []) as $sheet) {
                $props = $sheet['properties'] ?? [];
                if (isset($props['sheetId'], $props['title'])) {
                    $titles[(string) $props['sheetId']] = $props['title'];
                }
            }

            return ['success' => true, 'titles' => $titles, 'message' => null];
        });

        if (!$result['success']) {
            return ['success' => false, 'title' => null, 'message' => $result['message']];
        }

        set_transient($cache_key, $result['titles'], self::TITLES_CACHE_SECONDS);

        if (!isset($result['titles'][$gid])) {
            return ['success' => false, 'title' => null, 'message' => __('تبی با این gid در شیت پیدا نشد.', 'pgco-tables')];
        }

        return ['success' => true, 'title' => $result['titles'][$gid], 'message' => null];
    }

    /**
     * Fetch all values for a given sheet tab (by title) and return them
     * in the same internal shape the rest of the plugin expects:
     * ['head' => [...], 'body' => [...]].
     *
     * The first row is treated as the header row.
     *
     * @param string $spreadsheet_id
     * @param string $sheet_title
     * @param string $api_key
     * @return array{success: bool, data: ?array, message: ?string}
     */
    public static function fetch_values(string $spreadsheet_id, string $sheet_title, string $api_key): array
    {
        return self::request_with_retry(function () use ($spreadsheet_id, $sheet_title, $api_key) {
            // A1 notation: a tab name with spaces or special characters must be single-quoted (a quote inside is doubled)
            $range = rawurlencode("'" . str_replace("'", "''", $sheet_title) . "'");

            $url = sprintf(
                '%s/%s/values/%s?valueRenderOption=FORMATTED_VALUE&key=%s',
                self::API_BASE,
                rawurlencode($spreadsheet_id),
                $range,
                rawurlencode($api_key)
            );

            $response = wp_remote_get($url, ['timeout' => 15]);

            if (is_wp_error($response)) {
                return ['success' => false, 'data' => null, 'message' => $response->get_error_message(), 'retryable' => false];
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($code !== 200) {
                $message = $body['error']['message'] ?? ('HTTP ' . $code);
                return ['success' => false, 'data' => null, 'message' => $message, 'retryable' => self::is_quota_error($message, $code)];
            }

            $rows = $body['values'] ?? [];

            if (empty($rows)) {
                return ['success' => false, 'data' => null, 'message' => __('شیت خالی است یا داده‌ای برنگشت.', 'pgco-tables'), 'retryable' => false];
            }

            $head = [array_shift($rows)];

            // Normalize row lengths (Sheets API omits trailing empty cells).
            $col_count = count($head[0]);
            $body_rows = array_map(function ($row) use ($col_count) {
                $row = array_map('strval', $row);
                while (count($row) < $col_count) {
                    $row[] = '';
                }
                return $row;
            }, $rows);

            return [
                'success' => true,
                'data'    => [
                    'head' => $head,
                    'body' => $body_rows,
                ],
                'message' => null,
            ];
        });
    }

    /**
     * Detect whether the error message is about a temporary Google quota, so that
     * a retry happens only for this kind of error (which goes away after a short wait), not for
     * permanent errors such as a wrong API key or a sheet that was not found.
     *
     * @param string $message
     * @param int $code
     * @return bool
     */
    private static function is_quota_error(string $message, int $code): bool
    {
        if ($code === 429) {
            return true;
        }
        return stripos($message, 'quota') !== false || stripos($message, 'currently unavailable') !== false;
    }

    /**
     * Run an API request with an automatic retry on a temporary quota error.
     * Tries at most 3 times, with an increasing delay between attempts (1, 2, then 4
     * seconds) to give Google a chance to free up its per-minute quota window.
     *
     * @param callable $request_fn function that performs one request and returns an array
     *                             result with an (optional) 'retryable' key.
     * @return array
     */
    private static function request_with_retry(callable $request_fn): array
    {
        $max_attempts = (int) apply_filters('pgco_tables_api_max_retry_attempts', 3);
        $base_delay_seconds = 1;

        $result = null;
        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $result = $request_fn();

            if (!empty($result['success']) || empty($result['retryable']) || $attempt === $max_attempts) {
                break;
            }

            // increasing delay: 1, 2, 4 seconds...
            sleep($base_delay_seconds * (2 ** ($attempt - 1)));
        }

        // The internal 'retryable' key must not leak to the rest of the code.
        unset($result['retryable']);

        return $result;
    }

    /**
     * High-level helper: given a raw sheet URL and the stored API key,
     * return the table data in the internal head/body shape, or an error.
     *
     * @param string $url
     * @param string $api_key
     * @return array{success: bool, data: ?array, message: string}
     */
    public static function fetch_table_from_url(string $url, string $api_key): array
    {
        if (empty($api_key)) {
            return ['success' => false, 'data' => null, 'message' => __('API Key گوگل شیت تنظیم نشده است.', 'pgco-tables')];
        }

        $parsed = self::parse_url($url);

        if (empty($parsed['spreadsheet_id'])) {
            return [
                'success' => false,
                'data'    => null,
                'message' => __('آدرس شیت نامعتبر است. لطفاً لینک معمولی گوگل شیت (حاوی .../d/{id}/edit) را وارد کنید.', 'pgco-tables'),
            ];
        }

        $title_result = self::resolve_sheet_title($parsed['spreadsheet_id'], $parsed['gid'], $api_key);

        if (!$title_result['success']) {
            return ['success' => false, 'data' => null, 'message' => self::redact($title_result['message'], $api_key)];
        }

        $values_result = self::fetch_values($parsed['spreadsheet_id'], $title_result['title'], $api_key);

        if (!$values_result['success']) {
            return ['success' => false, 'data' => null, 'message' => self::redact($values_result['message'], $api_key)];
        }

        return ['success' => true, 'data' => $values_result['data'], 'message' => ''];
    }

    /**
     * The API key travels in the request URL, and a connection error message can contain that URL; it must not
     * end up in the sync log or on screen.
     *
     * @param string|null $message
     * @param string $api_key
     * @return string
     */
    private static function redact(?string $message, string $api_key): string
    {
        return str_replace([$api_key, rawurlencode($api_key)], '***', (string) $message);
    }
}
