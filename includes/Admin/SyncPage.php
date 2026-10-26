<?php

namespace PGCO_Tables\Admin;

use PGCO_Tables\Database;
use PGCO_Tables\Sync;

defined('ABSPATH') || exit;

/**
 * Adds a "بروزرسانی جدول‌ها" submenu page under the "Tables" (table post type)
 * admin menu. Lets the site owner trigger a bulk sync of all Google Sheet
 * backed tables at once, and shows a global log of recent sync attempts.
 */
class SyncPage
{
    private static ?SyncPage $instance = null;

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_post_pgco_tables_save_api_key', [$this, 'save_api_key']);
    }

    public function register_menu(): void
    {
        add_submenu_page(
            'edit.php?post_type=table',
            __('بروزرسانی جدول‌ها', 'pgco-tables'),
            __('بروزرسانی جدول‌ها', 'pgco-tables'),
            'edit_others_posts',
            'pgco-tables-sync',
            [$this, 'render_page']
        );
    }

    /**
     * Save the global Google Sheets API key from the settings form.
     *
     * @return void
     */
    public function save_api_key(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('دسترسی غیرمجاز.', 'pgco-tables'));
        }

        check_admin_referer('pgco-tables-save-api-key');

        $api_key = isset($_POST['pgco_tables_google_api_key'])
            ? sanitize_text_field(wp_unslash($_POST['pgco_tables_google_api_key']))
            : '';

        update_option('pgco_tables_google_api_key', $api_key);

        wp_safe_redirect(add_query_arg(
            ['post_type' => 'table', 'page' => 'pgco-tables-sync', 'api_key_saved' => '1'],
            admin_url('edit.php')
        ));
        exit;
    }

    public function render_page(): void
    {
        if (!current_user_can('edit_others_posts')) {
            return;
        }

        $logs = Database::instance()->all_logs(300);

        $api_key = get_option('pgco_tables_google_api_key', '');
?>
        <div class="wrap pgco-tables-sync-page">
            <h1><?php esc_html_e('بروزرسانی جدول‌ها', 'pgco-tables'); ?></h1>

            <?php if (isset($_GET['api_key_saved'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('API Key ذخیره شد.', 'pgco-tables'); ?></p>
                </div>
            <?php endif; ?>

            <h2><?php esc_html_e('تنظیمات Google Sheets API', 'pgco-tables'); ?></h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="pgco_tables_save_api_key">
                <?php wp_nonce_field('pgco-tables-save-api-key'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="pgco_tables_google_api_key"><?php esc_html_e('API Key', 'pgco-tables'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                dir="ltr"
                                style="min-width: 400px;"
                                id="pgco_tables_google_api_key"
                                name="pgco_tables_google_api_key"
                                value="<?php echo esc_attr($api_key); ?>"
                                placeholder="AIzaSy...">
                            <p class="description">
                                <?php esc_html_e('این کلید برای خواندن اطلاعات همه‌ی جدول‌ها از طریق Google Sheets API استفاده می‌شود.', 'pgco-tables'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('ذخیره API Key', 'pgco-tables')); ?>
            </form>

            <hr>

            <p>
                <?php esc_html_e('این دکمه تمام جدول‌های متصل به گوگل شیت را یکجا بروزرسانی می‌کند. این کار به‌صورت خودکار (کرون) انجام نمی‌شود و فقط با کلیک روی دکمه‌ی زیر اجرا می‌شود. برای بروزرسانی یا دیدن وضعیت هر جدول به‌صورت جداگانه، به لیست «همه‌ی جدول‌ها» مراجعه کنید.', 'pgco-tables'); ?>
            </p>

            <p>
                <button type="button"
                    class="button button-primary button-hero pgco-tables-sync-all"
                    data-ids="<?php echo esc_attr(implode(',', Sync::instance()->table_ids())); ?>"
                    data-nonce="<?php echo esc_attr(wp_create_nonce('pgco-tables-sync')); ?>">
                    <?php esc_html_e('بروزرسانی همه‌ی جدول‌ها الان', 'pgco-tables'); ?>
                </button>
                <span class="spinner pgco-tables-sync-all-spinner" style="float:none;"></span>
            </p>

            <div class="pgco-tables-sync-all-result"></div>

            <h2><?php esc_html_e('لاگ کامل بروزرسانی‌ها', 'pgco-tables'); ?></h2>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('تاریخ', 'pgco-tables'); ?></th>
                        <th><?php esc_html_e('جدول', 'pgco-tables'); ?></th>
                        <th><?php esc_html_e('وضعیت', 'pgco-tables'); ?></th>
                        <th><?php esc_html_e('پیام', 'pgco-tables'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$logs) : ?>
                        <tr>
                            <td colspan="4"><?php esc_html_e('هنوز لاگی ثبت نشده است.', 'pgco-tables'); ?></td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($logs as $log) : ?>
                        <tr>
                            <td><?php echo esc_html($log['created_at']); ?></td>
                            <td>
                                <a href="<?php echo esc_url(get_edit_post_link((int) $log['post'])); ?>">
                                    <?php echo esc_html(get_the_title((int) $log['post']) ?: '#' . $log['post']); ?>
                                </a>
                            </td>
                            <td>
                                <?php if ($log['status'] === 'success') : ?>
                                    <span class="pgco-tables-status-badge pgco-tables-status-success"><?php esc_html_e('موفق', 'pgco-tables'); ?></span>
                                <?php else : ?>
                                    <span class="pgco-tables-status-badge pgco-tables-status-error"><?php esc_html_e('ناموفق', 'pgco-tables'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($log['message']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
<?php
    }
}
