<?php

namespace PGCO_Tables;

defined('ABSPATH') || exit;

/**
 * Enqueue assets class
 */
class Enqueue
{
    private const FRONTEND_HANDLE = 'pgco-tables-frontend';

    private const RESPONSIVE_HANDLE = 'pgco-tables-responsive';

    private static $instance = null;

    /**
     * Get instance
     *
     * @return self
     */
    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        $this->hooks();
    }

    /**
     * Register hooks
     *
     * @return void
     */
    private function hooks(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'admin']);
        add_action('wp_enqueue_scripts', [$this, 'frontend']);

        // Tables printed by a plain TablePress shortcode get the same bar (VAT toggle, date, print) and styles
        add_filter('tablepress_table_output', [$this, 'tablepress_output']);
    }

    /**
     * File version for cache busting: the modification time, so a changed file is never served stale.
     *
     * @param string $relative_path Path inside the plugin folder.
     * @return string
     */
    private static function asset_version(string $relative_path): string
    {
        $file = PGCO_TABLES_DIR . $relative_path;

        return file_exists($file) ? (string) filemtime($file) : PGCO_TABLES_VERSION;
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook
     * @return void
     */
    public function admin($hook): void
    {
        global $post;

        $is_table_screen = ($post && $post->post_type === 'table')
            || (isset($_GET['post_type']) && $_GET['post_type'] === 'table')
            || ($hook === 'table_page_pgco-tables-sync');

        if (! $is_table_screen) {
            return;
        }

        wp_enqueue_style(
            'pgco-tables-admin',
            PGCO_TABLES_URL . 'assets/css/admin.css',
            [],
            self::asset_version('assets/css/admin.css')
        );

        wp_enqueue_script(
            'pgco-tables-admin',
            PGCO_TABLES_URL . 'assets/js/admin.js',
            ['jquery'],
            self::asset_version('assets/js/admin.js'),
            true
        );

        wp_localize_script('pgco-tables-admin', 'pgcoTablesAdmin', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'syncing'      => __('در حال بروزرسانی...', 'pgco-tables'),
            'success'      => __('موفق', 'pgco-tables'),
            'failed'       => __('ناموفق', 'pgco-tables'),
            'justNow'      => __('همین الان', 'pgco-tables'),
            'bulkDone'     => __('بروزرسانی دسته‌جمعی انجام شد.', 'pgco-tables'),
            'networkError' => __('خطا در ارتباط با سرور.', 'pgco-tables'),
            'noTables'     => __('جدولی برای بروزرسانی وجود ندارد.', 'pgco-tables'),
            'syncNonce'    => wp_create_nonce('pgco-tables-sync'),
        ]);
    }

    /**
     * Register frontend assets (enqueued only when a table is printed, see load()).
     *
     * @return void
     */
    public function frontend(): void
    {
        wp_register_style(
            self::FRONTEND_HANDLE,
            PGCO_TABLES_URL . 'assets/css/frontend.css',
            [],
            self::asset_version('assets/css/frontend.css')
        );

        wp_register_script(
            self::FRONTEND_HANDLE,
            PGCO_TABLES_URL . 'assets/js/tax-tables.js',
            [],
            self::asset_version('assets/js/tax-tables.js'),
            true
        );

        // Mobile layout: the columns that are not chosen to stay visible open under the row on tap
        wp_register_script(
            self::RESPONSIVE_HANDLE,
            PGCO_TABLES_URL . 'assets/js/responsive-tables.js',
            [],
            self::asset_version('assets/js/responsive-tables.js'),
            true
        );

        // Sticky bar offsets (the height of the fixed site header per window width)
        $offsets = apply_filters('pgco_tables_sticky_offsets', [[1024, 115], [768, 81], [0, 64]]);

        wp_add_inline_script(
            self::FRONTEND_HANDLE,
            'window.pgcoTablesTax=' . wp_json_encode(['offsets' => $offsets]) . ';',
            'before'
        );
    }

    /**
     * Load the table assets. Call it right where a table is printed.
     *
     * A stylesheet enqueued this late would be printed in the footer, after the table, and the bar would jump when
     * it arrives; so the first call returns the stylesheet tag to print just before the table.
     *
     * @return string HTML to print before the table ('' when nothing is left to print).
     */
    public static function load(): string
    {
        wp_enqueue_script(self::FRONTEND_HANDLE);
        wp_enqueue_script(self::RESPONSIVE_HANDLE);
        wp_enqueue_style(self::FRONTEND_HANDLE);

        if (wp_style_is(self::FRONTEND_HANDLE, 'done') || ! did_action('wp_head') || did_action('wp_print_footer_scripts')) {
            return '';
        }

        ob_start();
        wp_print_styles(self::FRONTEND_HANDLE);

        return (string) ob_get_clean();
    }

    /**
     * Filter: tablepress_table_output
     *
     * @param mixed $output
     * @return mixed
     */
    public function tablepress_output($output)
    {
        $styles = self::load();

        return is_string($output) ? $styles . $output : $output;
    }
}
