<?php

namespace PGCO_Tables;

defined('ABSPATH') || exit;

/**
 * Handles manual (non-cron) synchronization of a table post's content
 * from its configured Google Sheet URL, with logging of every attempt.
 */
class Sync
{
    private static ?Sync $instance = null;

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('wp_ajax_pgco_tables_sync_single', [$this, 'ajax_sync_single']);
        add_action('wp_ajax_pgco_tables_sync_all', [$this, 'ajax_sync_all']);
    }

    /**
     * Sync a single table post from its Google Sheet.
     * On success, updates _table_options['table'] and _table_last_synced.
     * Always writes a log entry (success or error).
     *
     * @param int $post_id
     * @return array{status: string, message: string, rows_count: ?int}
     */
    public function sync_post(int $post_id): array
    {
        // A table that fails in an unexpected way is reported as a failed sync (and logged), it never takes the
        // whole request (a bulk sync of many tables) down with a server error.
        try {
            return $this->run_sync($post_id);
        } catch (\Throwable $e) {
            $result = [
                'status'     => 'error',
                'message'    => sprintf(
                    /* translators: %s: PHP error message */
                    __('خطای غیرمنتظره: %s', 'pgco-tables'),
                    $e->getMessage()
                ),
                'rows_count' => null,
            ];
            $this->write_log($post_id, $result);

            return $result;
        }
    }

    /**
     * @param int $post_id
     * @return array{status: string, message: string, rows_count: ?int}
     */
    private function run_sync(int $post_id): array
    {
        // Only table posts are synced (the ID comes from an AJAX request or a bulk action)
        if (get_post_type($post_id) !== 'table') {
            return [
                'status'     => 'error',
                'message'    => __('این مورد یک جدول نیست.', 'pgco-tables'),
                'rows_count' => null,
            ];
        }

        do_action('pgco_tables_sync_started', $post_id);

        $options = get_post_meta($post_id, '_table_options', true);
        $options = is_array($options) ? $options : [];

        $sheet_url = $options['sheet-url'] ?? '';

        if (empty($sheet_url)) {
            $result = [
                'status'     => 'error',
                'message'    => __('آدرس گوگل شیت برای این جدول تنظیم نشده است.', 'pgco-tables'),
                'rows_count' => null,
            ];
            $this->write_log($post_id, $result);
            return $result;
        }

        $api_key = get_option('pgco_tables_google_api_key', '');

        $api_result = GoogleSheetsApi::fetch_table_from_url($sheet_url, $api_key);

        if (!$api_result['success']) {
            $result = [
                'status'     => 'error',
                'message'    => $api_result['message'] ?: __('دریافت اطلاعات از گوگل شیت ناموفق بود.', 'pgco-tables'),
                'rows_count' => null,
            ];
            $this->write_log($post_id, $result);
            return $result;
        }

        $decoded = $api_result['data'];

        if (empty($decoded) || (empty($decoded['body']) && empty($decoded['head']))) {
            $result = [
                'status'     => 'error',
                'message'    => __('جدول دریافت شده خالی بود.', 'pgco-tables'),
                'rows_count' => null,
            ];
            $this->write_log($post_id, $result);
            return $result;
        }

        $json = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);

        // debug line (observation only, changes no logic): right before writing
        // the cache, report the full snapshot so that if this post's cache later shows something
        // wrong, its exact moment of writing can be found in the log.
        do_action('pgco_tables_before_cache_write', $post_id, $sheet_url, $json);

        $options['table'] = $json;
        update_post_meta($post_id, '_table_options', $options);
        update_post_meta($post_id, '_table_last_synced', current_time('mysql'));
        update_post_meta($post_id, '_table_last_sync_status', 'success');

        $rows_count = isset($decoded['body']) ? count($decoded['body']) : 0;

        $result = [
            'status'     => 'success',
            'message'    => sprintf(
                /* translators: %d: number of rows synced */
                __('بروزرسانی موفق. %d ردیف دریافت شد.', 'pgco-tables'),
                $rows_count
            ),
            'rows_count' => $rows_count,
        ];

        $this->write_log($post_id, $result);

        return $result;
    }

    /**
     * IDs of all table posts.
     *
     * @return int[]
     */
    public function table_ids(): array
    {
        return array_map('intval', get_posts([
            'post_type'      => 'table',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]));
    }

    /**
     * Sync all published table posts.
     *
     * @return array<int, array{post_id: int, title: string, status: string, message: string}>
     */
    public function sync_all(): array
    {
        $posts = $this->table_ids();

        $results = [];

        // Important note: each sync makes two separate requests to the Google Sheets API
        // (resolve_sheet_title + fetch_values). With many tables, if we fire all of them
        // back to back without a pause, we exceed Google's quota ('Read requests per minute')
        // and requests fail with a quota exceeded error. That makes
        // tables that have never had a successful sync (no cache) and also have no TablePress
        // fallback show up completely empty.
        // A small delay between requests prevents reaching the quota ceiling.
        $delay_microseconds = (int) apply_filters('pgco_tables_sync_all_delay_microseconds', 400000); // 0.4 seconds

        $total = count($posts);
        foreach ($posts as $index => $post_id) {
            $result = $this->sync_post($post_id);
            $results[] = [
                'post_id' => $post_id,
                'title'   => get_the_title($post_id),
                'status'  => $result['status'],
                'message' => $result['message'],
            ];

            // No delay is needed after the last item.
            if ($delay_microseconds > 0 && $index < $total - 1) {
                usleep($delay_microseconds);
            }
        }

        return $results;
    }

    /**
     * Persist a log entry, on error also flag the post so on the failure
     * the frontend knows to fall back to TablePress.
     *
     * @param int   $post_id
     * @param array $result
     * @return void
     */
    private function write_log(int $post_id, array $result): void
    {
        if ($result['status'] === 'error') {
            update_post_meta($post_id, '_table_last_sync_status', 'error');
        }

        Database::instance()->log(
            $post_id,
            $result['status'],
            $result['message'],
            $result['rows_count'] ?? null
        );

        do_action('pgco_tables_sync_finished', $post_id, $result);
    }

    /**
     * AJAX: sync a single table post.
     *
     * @return void
     */
    public function ajax_sync_single(): void
    {
        check_ajax_referer('pgco-tables-sync', 'nonce');

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('دسترسی غیرمجاز.', 'pgco-tables')], 403);
        }

        $result = $this->sync_post($post_id);
        $result['title'] = get_the_title($post_id);

        if ($result['status'] === 'success') {
            wp_send_json_success($result);
        }

        wp_send_json_error($result);
    }

    /**
     * AJAX: sync all table posts.
     *
     * @return void
     */
    public function ajax_sync_all(): void
    {
        check_ajax_referer('pgco-tables-sync', 'nonce');

        if (!current_user_can('edit_others_posts')) {
            wp_send_json_error(['message' => __('دسترسی غیرمجاز.', 'pgco-tables')], 403);
        }

        $results = $this->sync_all();

        wp_send_json_success(['results' => $results]);
    }
}