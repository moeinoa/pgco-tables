<?php

namespace PGCO_Tables\Debug;

defined('ABSPATH') || exit;

/**
 * Debugger.php
 *
 * === What does this file do? ===
 * A persistent log, independent of memory, of everything that happens during the display
 * (Shortcode) and sync (Sync) of tables. The goal is that when the problem
 * 'neither the sheet nor the fallback is shown' happens once, looking at
 * this log tells exactly which decision was made when the page rendered, and why.
 *
 * === Where are the logs stored? ===
 * In a text file at: wp-content/uploads/pgco-tables/debug.log
 * (the folder is closed to direct web access; open the file with FTP or the host's File Manager,
 * or from the admin page 'Update tables > Debug report' that this class adds.)
 *
 * === How to enable/disable ===
 * It is off by default (every write costs a file lock and the log can hold table prices). To investigate
 * a problem, add this line to wp-config.php, and remove it afterwards:
 *   define('PGCO_TABLES_DEBUG', true);
 *
 * === How to install ===
 * 1) Put this file in includes/Debug/Debugger.php of the plugin.
 * 2) In the plugin's main file (pgco-tables.php) or in the Autoloader, add this line:
 *      \PGCO_Tables\Debug\Debugger::instance();
 *    (it must be called in the same place where the other classes such as Sync::instance() are called,
 *    usually on the 'plugins_loaded' or 'init' hook.)
 */
class Debugger
{
    private static ?Debugger $instance = null;

    private string $log_file;

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        if (!$this->is_enabled()) {
            // the daily cleanup job is only needed while the log is on
            wp_clear_scheduled_hook('pgco_tables_debug_log_cleanup');
            return;
        }

        $upload_dir     = wp_upload_dir();
        $this->log_file = trailingslashit($upload_dir['basedir']) . 'pgco-tables/debug.log';

        // --- hooks to observe render and sync moments ---

        // Record when the shortcode could not print the cached table (fallback / empty).
        add_filter('pgco_tables_after_render', [$this, 'log_after_render'], 10, 3);

        // sync events
        add_action('pgco_tables_sync_started', [$this, 'log_sync_started'], 10, 1);
        add_action('pgco_tables_sync_finished', [$this, 'log_sync_finished'], 10, 2);

        // New debug line: right before writing the cache to the database, record the whole JSON
        // content. This is the only way to find out later where a strange price came from
        // - since this snapshot is exactly what was received from the API and is about to be
        // stored, if the cache of this post later shows something else, another place
        // (not the normal sync path) changed this value.
        add_action('pgco_tables_before_cache_write', [$this, 'log_before_cache_write'], 10, 3);

        // New debug line: every time _table_options of a table post changes
        // (from any path, not only Sync), record it. This is to check the
        // hypothesis that another source (e.g. a manual post save in the admin,
        // or a race condition bug) is overwriting one post's cache with
        // another post's data.
        add_action('updated_post_meta', [$this, 'log_meta_updated'], 10, 4);
        add_action('added_post_meta', [$this, 'log_meta_updated'], 10, 4);

        // report page in the admin
        add_action('admin_menu', [$this, 'register_admin_page'], 20);

        // Automatic cleanup of very old logs (more than 7 days) once a day
        add_action('pgco_tables_debug_log_cleanup', [$this, 'cleanup_old_logs']);
        if (!wp_next_scheduled('pgco_tables_debug_log_cleanup')) {
            wp_schedule_event(time(), 'daily', 'pgco_tables_debug_log_cleanup');
        }
    }

    private function is_enabled(): bool
    {
        if (defined('PGCO_TABLES_DEBUG')) {
            return (bool) PGCO_TABLES_DEBUG;
        }
        return false;
    }

    /**
     * Create the log folder, closed to direct web access (the log holds table prices and request URLs).
     */
    private function prepare_log_dir(): void
    {
        $dir = dirname($this->log_file);

        if (is_dir($dir)) {
            return;
        }

        wp_mkdir_p($dir);

        @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
    }

    /**
     * Write one log line with the time and request ID, so the events of one
     * page load can be told apart.
     */
    public function write(string $context, array $data = []): void
    {
        if (!$this->is_enabled()) {
            return;
        }

        static $request_id = null;
        if ($request_id === null) {
            $request_id = substr(md5((string) microtime(true) . wp_rand()), 0, 8);
        }

        $line = sprintf(
            "[%s] [req:%s] [%s] %s\n",
            current_time('mysql'),
            $request_id,
            $context,
            wp_json_encode($data, JSON_UNESCAPED_UNICODE)
        );

        $this->prepare_log_dir();

        // append without locking the whole site; if the file gets too big, rotate it.
        if (file_exists($this->log_file) && filesize($this->log_file) > 5 * 1024 * 1024) {
            @rename($this->log_file, $this->log_file . '.old');
        }

        @file_put_contents($this->log_file, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * What was finally shown to the user: cache / fallback / empty
     */
    public function log_after_render($post_id, string $source_used, array $extra = [])
    {
        // The normal case (cached table) is not worth a write on every page view
        if ($source_used === 'cache') {
            return $post_id;
        }

        $this->write('RENDER_RESULT', array_merge([
            'post_id'     => $post_id,
            'source_used' => $source_used, // 'cache' | 'fallback' | 'empty'
        ], $extra));

        // If the result was 'empty', this is exactly the bug we are looking for -
        // write a line with a distinct label that is easy to find by searching.
        if ($source_used === 'empty') {
            $this->write('*** BUG_DETECTED_EMPTY_TABLE ***', [
                'post_id'    => $post_id,
                'post_title' => get_the_title($post_id),
                'message'    => 'نه کش معتبر بود، نه فالبک TablePress در دسترس بود.',
            ]);
        }

        return $post_id;
    }

    public function log_sync_started($post_id)
    {
        $this->write('SYNC_START', ['post_id' => $post_id, 'post_title' => get_the_title($post_id)]);
    }

    public function log_sync_finished($post_id, array $result)
    {
        $this->write('SYNC_FINISH', array_merge(['post_id' => $post_id], $result));
    }

    /**
     * Right before writing the cache, store the whole JSON (not only the row count). This
     * is so that when a strange price is seen later, searching for that number
     * in the log file tells exactly which sync and which post it came from - or
     * if it is not found anywhere in the log, it did not come through the sync path at all and
     * another source must be sought (e.g. a manual edit, or a server-level/CDN cache).
     *
     * @param int    $post_id
     * @param string $sheet_url
     * @param string $json
     */
    public function log_before_cache_write($post_id, string $sheet_url, string $json)
    {
        $this->write('CACHE_WRITE', [
            'post_id'    => $post_id,
            'post_title' => get_the_title($post_id),
            'sheet_url'  => $sheet_url,
            // Since the table may be big, we store only the first 5000 characters;
            // for most price tables that is enough to contain all the prices.
            'json_snapshot' => mb_substr($json, 0, 5000),
            'json_length'   => strlen($json),
            'backtrace'     => $this->short_backtrace(),
        ]);
    }

    /**
     * Every time the '_table_options' meta of a table post changes, record
     * it - regardless of whether it came from the Sync path or from anywhere else in
     * WordPress (e.g. a manual post save, another plugin, or a race
     * condition bug). If we see this line in the log but not the matching
     * CACHE_WRITE line, a source outside the normal sync path is
     * writing this value - which is an important clue in itself.
     *
     * @param int    $meta_id
     * @param int    $object_id  ID of the post whose meta changed
     * @param string $meta_key
     * @param mixed  $meta_value
     */
    public function log_meta_updated($meta_id, $object_id, $meta_key, $meta_value)
    {
        if ($meta_key !== '_table_options') {
            return;
        }

        $post_type = get_post_type($object_id);
        if ($post_type !== 'table') {
            return;
        }

        $table_json = is_array($meta_value) ? ($meta_value['table'] ?? '') : '';

        $this->write('META_UPDATED', [
            'post_id'       => $object_id,
            'post_title'    => get_the_title($object_id),
            'json_snapshot' => mb_substr((string) $table_json, 0, 5000),
            'json_length'   => strlen((string) $table_json),
            'backtrace'     => $this->short_backtrace(),
        ]);
    }

    /**
     * A short backtrace (file name and line only, without heavy arguments) so
     * we can tell which code path called this change - manual Sync, bulk,
     * saving a post in the admin, or anywhere else.
     *
     * @return array
     */
    private function short_backtrace(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);
        $result = [];
        foreach ($trace as $frame) {
            $result[] = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
        }
        return $result;
    }

    /**
     * Delete logs older than 7 days (only removes the .old file to keep the size
     * down; the current file is left alone because it is always being written).
     */
    public function cleanup_old_logs(): void
    {
        $old_file = $this->log_file . '.old';
        if (file_exists($old_file) && (time() - filemtime($old_file)) > 7 * DAY_IN_SECONDS) {
            @unlink($old_file);
        }
    }

    /**
     * Admin page to view the logs easily without needing FTP.
     */
    public function register_admin_page(): void
    {
        add_submenu_page(
            'edit.php?post_type=table',
            'گزارش دیباگ',
            'گزارش دیباگ',
            'manage_options',
            'pgco-tables-debug-log',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('دسترسی غیرمجاز.', 'pgco-tables'));
        }

        // Option to clear the log from this very page
        if (isset($_POST['pgco_clear_debug_log']) && check_admin_referer('pgco_clear_debug_log')) {
            @file_put_contents($this->log_file, '');
            echo '<div class="notice notice-success"><p>لاگ پاک شد.</p></div>';
        }

        $content = '';
        if (file_exists($this->log_file)) {
            $content = file_get_contents($this->log_file);
        }

        // --- search for a specific number/text (e.g. a strange price) in the whole log ---
        // This is for when you saw a wrong price on the site and want to find out
        // where this number came from: it shows every log line that contains this number
        // (not only the last 500 lines), with the post name and the exact time.
        $search_term = isset($_GET['pgco_search']) ? sanitize_text_field(wp_unslash($_GET['pgco_search'])) : '';
        $search_results = [];
        if ($search_term !== '' && $content !== '') {
            $all_lines = explode("\n", $content);
            foreach ($all_lines as $line) {
                if ($line !== '' && mb_strpos($line, $search_term) !== false) {
                    $search_results[] = $line;
                }
            }
        }

        // For the normal view, show only the last 500 lines so the page does not get heavy.
        $lines = explode("\n", $content);
        $lines = array_slice($lines, -500);
        $content = implode("\n", $lines);

        // Highlight the lines related to the bug for faster finding
        $highlighted = esc_html($content);
        $highlighted = str_replace(
            esc_html('*** BUG_DETECTED_EMPTY_TABLE ***'),
            '<mark style="background:#ffb3b3;">*** BUG_DETECTED_EMPTY_TABLE ***</mark>',
            $highlighted
        );

        echo '<div class="wrap">';
        echo '<h1>گزارش دیباگ جدول‌های PGCO</h1>';
        echo '<p>مسیر فایل لاگ: <code>' . esc_html($this->log_file) . '</code></p>';
        echo '<p>برای پیدا کردن سریع مشکل "نه شیت نه فالبک"، دنبال خط‌های <code>BUG_DETECTED_EMPTY_TABLE</code> بگرد (با رنگ قرمز مشخص شدن).</p>';

        echo '<h2>جستجوی یک قیمت یا متن مشخص در کل لاگ</h2>';
        echo '<p>وقتی یک قیمت اشتباه روی سایت می‌بینی، همون عدد (مثلاً <code>125000</code>) رو اینجا جستجو کن تا ببینی این عدد از کدوم پست، در چه لحظه‌ای، از چه مسیری وارد شده.</p>';
        echo '<form method="get" style="margin-bottom:15px;">';
        echo '<input type="hidden" name="post_type" value="table" />';
        echo '<input type="hidden" name="page" value="pgco-tables-debug-log" />';
        echo '<input type="text" name="pgco_search" value="' . esc_attr($search_term) . '" style="width:300px;" placeholder="مثلاً: 125000" />';
        echo '<button type="submit" class="button button-primary">جستجو در لاگ</button>';
        echo '</form>';

        if ($search_term !== '') {
            echo '<h3>نتایج جستجو برای «' . esc_html($search_term) . '» (' . count($search_results) . ' مورد)</h3>';
            if (empty($search_results)) {
                echo '<p style="color:#a00;">هیچ خطی با این عبارت پیدا نشد — یعنی این مقدار از هیچ‌کدام از رویدادهای ثبت‌شده‌ی این پلاگین (sync، نوشتن کش، رندر) نیامده. احتمالاً منبعش خارج از این پلاگین است (مثلاً کش سطح سرور/CDN، یا یک ویرایش دستی قدیمی).</p>';
            } else {
                echo '<textarea readonly style="width:100%;height:300px;direction:ltr;font-family:monospace;font-size:12px;background:#fffbe6;">' . esc_html(implode("\n", $search_results)) . '</textarea>';
            }
        }

        echo '<h2>لاگ کامل (۵۰۰ خط آخر)</h2>';
        echo '<form method="post" style="margin-bottom:10px;">';
        wp_nonce_field('pgco_clear_debug_log');
        echo '<button type="submit" name="pgco_clear_debug_log" class="button button-secondary" onclick="return confirm(\'مطمئنی می‌خوای لاگ رو پاک کنی؟\');">پاک کردن لاگ</button>';
        echo '</form>';

        echo '<textarea readonly style="width:100%;height:600px;direction:ltr;font-family:monospace;font-size:12px;">' . $highlighted . '</textarea>';
        echo '</div>';
    }
}