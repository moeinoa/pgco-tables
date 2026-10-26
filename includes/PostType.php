<?php

namespace PGCO_Tables;

class PostType
{
    /**
     * Single instance of the class
     */
    private static ?PostType $instance = null;

    private $labels;

    private $args;

    private $post_type;

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

    public function __construct()
    {
        add_action('init', [$this, 'register']);
        add_action('add_meta_boxes', [$this, 'meta_boxes']);
        add_action('save_post', [$this, 'save']);

        add_action('post_submitbox_misc_actions', [$this, 'shortcode']);

        add_filter('manage_edit-table_columns', [$this, 'columns']);
        add_action('manage_table_posts_custom_column', [$this, 'column'], 10, 2);

        add_filter('bulk_actions-edit-table', [$this, 'bulk_actions']);
        add_filter('handle_bulk_actions-edit-table', [$this, 'handle_bulk_actions'], 10, 3);
        add_action('admin_notices', [$this, 'bulk_action_notice']);

        add_filter('manage_edit-table_sortable_columns', [$this, 'sortable_columns']);
        add_action('pre_get_posts', [$this, 'sort_by_sync_status']);
    }

    /**
     * Make the "sync_status" column clickable/sortable in the Tables list.
     *
     * @param array $columns
     * @return array
     */
    public function sortable_columns($columns)
    {
        $columns['sync_status'] = 'sync_status';
        return $columns;
    }

    /**
     * Sort the Tables list by the last sync status meta value when the
     * "sync_status" column is clicked (ascending: error, none, success —
     * so failed syncs surface first).
     *
     * @param \WP_Query $query
     * @return void
     */
    public function sort_by_sync_status($query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('orderby') !== 'sync_status') {
            return;
        }

        $query->set('meta_key', '_table_last_sync_status');
        $query->set('orderby', 'meta_value');
    }

    /**
     * Add a "Sync from Google Sheets" option to the Bulk Actions dropdown
     * on the Tables list screen.
     *
     * @param array $actions
     * @return array
     */
    public function bulk_actions($actions)
    {
        $actions['pgco_tables_sync'] = __('بروزرسانی از گوگل شیت', 'pgco-tables');
        return $actions;
    }

    /**
     * Handle the bulk "sync" action: run Sync::sync_post() for each
     * selected table post and redirect back with a result summary.
     *
     * @param string $redirect_to
     * @param string $action
     * @param array  $post_ids
     * @return string
     */
    public function handle_bulk_actions($redirect_to, $action, $post_ids)
    {
        if ($action !== 'pgco_tables_sync') {
            return $redirect_to;
        }

        // Fallback for a browser without JavaScript: the list normally syncs the selection one table per request
        // (admin.js). Here everything runs in one request, so it stops before the server's time limit and says how
        // many tables are left.
        $started = microtime(true);
        $budget = (float) apply_filters('pgco_tables_bulk_time_budget', 15);
        $delay_microseconds = (int) apply_filters('pgco_tables_sync_all_delay_microseconds', 400000);

        $success = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($post_ids as $post_id) {
            if (!current_user_can('edit_post', $post_id)) {
                continue;
            }

            if (microtime(true) - $started > $budget) {
                $skipped++;
                continue;
            }

            $result = Sync::instance()->sync_post((int) $post_id);

            if ($result['status'] === 'success') {
                $success++;
            } else {
                $failed++;
            }

            // a pause between tables keeps Google's per-minute read quota
            if ($delay_microseconds > 0) {
                usleep($delay_microseconds);
            }
        }

        return add_query_arg(
            [
                'pgco_tables_synced'  => $success,
                'pgco_tables_failed'  => $failed,
                'pgco_tables_skipped' => $skipped,
            ],
            $redirect_to
        );
    }

    /**
     * Show an admin notice with the result summary after a bulk sync.
     *
     * @return void
     */
    public function bulk_action_notice(): void
    {
        if (!isset($_GET['pgco_tables_synced']) && !isset($_GET['pgco_tables_failed'])) {
            return;
        }

        $success = isset($_GET['pgco_tables_synced']) ? absint($_GET['pgco_tables_synced']) : 0;
        $failed = isset($_GET['pgco_tables_failed']) ? absint($_GET['pgco_tables_failed']) : 0;

        $skipped = isset($_GET['pgco_tables_skipped']) ? absint($_GET['pgco_tables_skipped']) : 0;

        $class = ($failed > 0 || $skipped > 0) ? 'notice-warning' : 'notice-success';
?>
        <div class="notice <?php echo esc_attr($class); ?> is-dismissible">
            <p>
                <?php
                printf(
                    /* translators: 1: number of successful syncs, 2: number of failed syncs */
                    esc_html__('بروزرسانی دسته‌جمعی انجام شد: %1$d موفق، %2$d ناموفق.', 'pgco-tables'),
                    $success,
                    $failed
                );

                if ($skipped > 0) {
                    echo ' ';
                    printf(
                        /* translators: %d: number of tables not synced because of the time limit */
                        esc_html__('%d جدول به‌خاطر محدودیت زمان سرور بروز نشد؛ دوباره اجرا کنید (یا جدول‌ها را کمتر انتخاب کنید).', 'pgco-tables'),
                        $skipped
                    );
                }
                ?>
            </p>
        </div>
<?php
    }

    /**
     * The price toggle above a table, from the table's saved options:
     * - price-operation: multiply = the table prices are without VAT, the toggle shows them with VAT;
     *   divide = the table prices include VAT, the toggle shows them without it. A table saved before this
     *   setting existed falls back to vat-mode: add = multiply, anything else = divide.
     * - price-multiplier: the VAT factor (1.1 when empty).
     * - vat-mode: none = the table has no toggle.
     *
     * @param array $options The table's _table_options meta.
     * @return array{enabled: bool, operation: string, rate: float}
     */
    public static function vat_settings(array $options): array
    {
        $mode = (string) ($options['vat-mode'] ?? '');

        $operation = (string) ($options['price-operation'] ?? '');
        if (! in_array($operation, ['multiply', 'divide'], true)) {
            $operation = $mode === 'add' ? 'multiply' : 'divide';
        }

        $rate = PriceRange::parse_number((string) ($options['price-multiplier'] ?? ''));
        if (! $rate || $rate <= 0) {
            $rate = 1.1;
        }

        return [
            'enabled'   => $mode !== 'none',
            'operation' => $operation,
            'rate'      => $rate,
        ];
    }

    public function labels(): void
    {
        $this->labels = [
            'name'                  => _x('Tables', 'Post Type General Name', 'pgco-tables'),
            'singular_name'         => _x('Table', 'Post Type Singular Name', 'pgco-tables'),
            'menu_name'             => __('Tables', 'pgco-tables'),
            'name_admin_bar'        => __('Table', 'pgco-tables'),
            'archives'              => __('Item Tables', 'pgco-tables'),
            'attributes'            => __('Item Attributes', 'pgco-tables'),
            'parent_item_colon'     => __('Parent Table:', 'pgco-tables'),
            'all_items'             => __('All Tables', 'pgco-tables'),
            'add_new_item'          => __('Add New Table', 'pgco-tables'),
            'add_new'               => __('Add New', 'pgco-tables'),
            'new_item'              => __('New Table', 'pgco-tables'),
            'edit_item'             => __('Edit Table', 'pgco-tables'),
            'update_item'           => __('Update Table', 'pgco-tables'),
            'view_item'             => __('View Table', 'pgco-tables'),
            'view_items'            => __('View Tables', 'pgco-tables'),
            'search_items'          => __('Search Table', 'pgco-tables'),
            'not_found'             => __('Not found', 'pgco-tables'),
            'not_found_in_trash'    => __('Not found in Trash', 'pgco-tables'),
            'featured_image'        => __('Featured Image', 'pgco-tables'),
            'set_featured_image'    => __('Set featured image', 'pgco-tables'),
            'remove_featured_image' => __('Remove featured image', 'pgco-tables'),
            'use_featured_image'    => __('Use as featured image', 'pgco-tables'),
            'insert_into_item'      => __('Insert into table', 'pgco-tables'),
            'uploaded_to_this_item' => __('Uploaded to this table', 'pgco-tables'),
            'items_list'            => __('Tables list', 'pgco-tables'),
            'items_list_navigation' => __('Tables list navigation', 'pgco-tables'),
            'filter_items_list'     => __('Filter tables list', 'pgco-tables'),
        ];
    }

    public function args(): void
    {
        $this->args = [
            'label'                 => __('Table', 'pgco-tables'),
            'description'           => __('retrieve and save data from Google Sheets.', 'pgco-tables'),
            'labels'                => $this->labels,
            'supports'              => ['title'],
            'hierarchical'          => false,
            'public'                => false,
            'show_ui'               => true,
            'show_in_menu'          => true,
            'menu_position'         => 10,
            'menu_icon'             => 'dashicons-editor-table',
            'show_in_admin_bar'     => true,
            'show_in_nav_menus'     => false,
            'can_export'            => true,
            'has_archive'           => false,
            'exclude_from_search'   => true,
            'publicly_queryable'    => false,
            'rewrite'               => false,
            'capability_type'       => 'page',
            'show_in_rest'          => false,
        ];
    }

    public function register(): void
    {
        $this->post_type = 'table';

        // built here (init), not earlier: the translations are loaded by now
        $this->labels();
        $this->args();

        register_post_type($this->post_type, $this->args);
    }

    public function meta_boxes($post_type): void
    {
        if ($post_type === $this->post_type) {
            add_meta_box(
                'table_settings',
                __('Table Settings', 'pgco-tables'),
                [$this, 'callback'],
                $this->post_type,
                'advanced',
                'high'
            );
        }
    }

    public function save($post)
    {
        if (! isset($_POST['table-options-nonce'])) {
            return $post;
        }

        $nonce = $_POST['table-options-nonce'];

        if (! wp_verify_nonce($nonce, PGCO_TABLES_BASENAME)) {
            return $post;
        }

        /**
         * If this is an autosave, our form has not been submitted,
         * so we don't want to do anything.
         */
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return $post;
        }

        if (! current_user_can('edit_post', $post)) {
            return $post;
        }

        if (wp_is_post_revision($post)) {
            return $post;
        }

        $existing = get_post_meta($post, '_table_options', true);
        $existing = is_array($existing) ? $existing : [];

        $options = [];

        if (isset($_POST['options']['sheet-url'])) {
            $options['sheet-url'] = sanitize_text_field(wp_unslash($_POST['options']['sheet-url']));
        }

        if (isset($_POST['options']['tablepress-id'])) {
            $options['tablepress-id'] = sanitize_text_field(wp_unslash($_POST['options']['tablepress-id']));
        }

        // none = no toggle above the table; add = toggle shown (the value every existing table already has)
        if (isset($_POST['options']['vat-mode'])) {
            $options['vat-mode'] = sanitize_key(wp_unslash($_POST['options']['vat-mode'])) === 'none' ? 'none' : 'add';
        }

        if (isset($_POST['options']['price-operation'])) {
            $options['price-operation'] = sanitize_key(wp_unslash($_POST['options']['price-operation'])) === 'multiply' ? 'multiply' : 'divide';
        }

        if (isset($_POST['options']['price-multiplier'])) {
            $multiplier = PriceRange::parse_number(sanitize_text_field(wp_unslash($_POST['options']['price-multiplier'])));
            $options['price-multiplier'] = ($multiplier && $multiplier > 0) ? (string) $multiplier : '';
        }

        // Preserve the last-synced cached content; syncing is now a manual,
        // explicit action (button click) rather than something that happens
        // automatically on every save.
        // Every stored key this form does not post is kept as it is (the cache, and settings of other versions)
        $options = array_merge($existing, $options);

        update_post_meta($post, '_table_options', $options);

        return $post;
    }

    public function shortcode($post)
    {
        if ($post->post_type !== $this->post_type) {
            return;
        }
?>
        <div class="misc-pub-section my-options">
            <label for="pgco-tables-shortcode"><?php esc_html_e('Shortcode', 'pgco-tables'); ?></label><br />
            <input
                type="text"
                value='[pgco-tables id="<?php echo esc_attr($post->ID); ?>"]'
                readonly
                id="pgco-tables-shortcode"
                class="widefat"
                onclick="this.select()">
        </div>
        <div class="misc-pub-section my-options">
            <label for="pgco-tables-price-shortcode"><?php esc_html_e('شورت‌کد کمترین و بیشترین قیمت', 'pgco-tables'); ?></label><br />
            <input
                type="text"
                value='[pgco-tables-price id="<?php echo esc_attr($post->ID); ?>"]'
                readonly
                id="pgco-tables-price-shortcode"
                class="widefat"
                onclick="this.select()">
            <span class="description"><?php esc_html_e('خروجی: «… تا … تومان». برای چند جدول با هم: [pgco-tables-price ids="12,13"]', 'pgco-tables'); ?></span>
        </div>
    <?php
    }

    public function callback($post): void
    {

        if ($post->post_type !== $this->post_type) {
            return;
        }

        wp_nonce_field(PGCO_TABLES_BASENAME, 'table-options-nonce');

        $options = get_post_meta($post->ID, '_table_options', true);
        $options = is_array($options) ? $options : [];

        $last_synced = get_post_meta($post->ID, '_table_last_synced', true);
        $last_status = get_post_meta($post->ID, '_table_last_sync_status', true);

    ?>

        <div class="pgco-tables-settings">

            <!-- Start: Sheet URL Field -->
            <div class="settings-field settings-field-text">
                <label for="sheet-url" data-title="<?php esc_attr_e('Sheet URL', 'pgco-tables'); ?>">
                    <input type="text"
                        id="sheet-url"
                        name="options[sheet-url]"
                        value="<?php echo esc_attr($options['sheet-url'] ?? ''); ?>"
                        placeholder="https://docs.google.com/spreadsheets/">
                </label>
                <span class="settings-field-description">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                        <g fill="none">
                            <path stroke="#888888" stroke-linecap="round" stroke-width="1.5" d="M12 17v-6" />
                            <circle cx="1" cy="1" r="1" fill="#888888" transform="matrix(1 0 0 -1 11 9)" />
                            <path stroke="#888888" stroke-linecap="round" stroke-width="1.5" d="M7 3.338A9.95 9.95 0 0 1 12 2c5.523 0 10 4.477 10 10s-4.477 10-10 10S2 17.523 2 12c0-1.821.487-3.53 1.338-5" />
                        </g>
                    </svg>
                    <?php esc_html_e('The URL of the Google Sheet to retrieve data from.', 'pgco-tables'); ?>
                </span>
            </div>
            <!-- End: Sheet URL Field -->

            <!-- Start: TablePress Fallback Field -->
            <div class="settings-field settings-field-text">
                <label for="tablepress-id" data-title="<?php esc_attr_e('TablePress Fallback ID', 'pgco-tables'); ?>">
                    <input type="text"
                        id="tablepress-id"
                        name="options[tablepress-id]"
                        value="<?php echo esc_attr($options['tablepress-id'] ?? ''); ?>"
                        placeholder="<?php esc_attr_e('مثلاً 12', 'pgco-tables'); ?>">
                </label>
                <span class="settings-field-description">
                    <?php esc_html_e('در صورتی که آخرین نسخه‌ی کش‌شده از گوگل شیت موجود نباشد، این جدول TablePress به‌عنوان جایگزین نمایش داده می‌شود.', 'pgco-tables'); ?>
                </span>
            </div>
            <!-- End: TablePress Fallback Field -->

            <?php $vat = self::vat_settings($options); ?>

            <!-- Start: VAT Toggle Field -->
            <div class="settings-field settings-field-text">
                <label for="vat-mode" data-title="<?php esc_attr_e('چک‌باکس ارزش افزوده', 'pgco-tables'); ?>">
                    <select id="vat-mode" name="options[vat-mode]">
                        <option value="add" <?php selected($vat['enabled'], true); ?>><?php esc_html_e('نمایش داده شود', 'pgco-tables'); ?></option>
                        <option value="none" <?php selected($vat['enabled'], false); ?>><?php esc_html_e('نمایش داده نشود', 'pgco-tables'); ?></option>
                    </select>
                </label>
            </div>
            <!-- End: VAT Toggle Field -->

            <!-- Start: Price Operation Field -->
            <div class="settings-field settings-field-text">
                <label for="price-operation" data-title="<?php esc_attr_e('عملیات قیمت', 'pgco-tables'); ?>">
                    <select id="price-operation" name="options[price-operation]">
                        <option value="divide" <?php selected($vat['operation'], 'divide'); ?>><?php esc_html_e('تقسیم: قیمت‌های گوگل شیت با ارزش افزوده است؛ سایت از اول آن‌ها را تقسیم‌شده نشان می‌دهد و با تیک چک‌باکس قیمت اصلی شیت دیده می‌شود', 'pgco-tables'); ?></option>
                        <option value="multiply" <?php selected($vat['operation'], 'multiply'); ?>><?php esc_html_e('ضرب: قیمت‌های گوگل شیت بدون ارزش افزوده است؛ سایت قیمت شیت را نشان می‌دهد و با تیک چک‌باکس در ضریب ضرب می‌شود', 'pgco-tables'); ?></option>
                    </select>
                </label>
                <span class="settings-field-description">
                    <?php esc_html_e('در هر دو حالت با تیک چک‌باکس قیمت بیشتر می‌شود. اگر چک‌باکس خاموش باشد، قیمت‌ها همان‌طور که در شیت هست نمایش داده می‌شوند.', 'pgco-tables'); ?>
                </span>
            </div>
            <!-- End: Price Operation Field -->

            <!-- Start: Price Multiplier Field -->
            <div class="settings-field settings-field-text">
                <label for="price-multiplier" data-title="<?php esc_attr_e('ضریب', 'pgco-tables'); ?>">
                    <input type="text"
                        id="price-multiplier"
                        name="options[price-multiplier]"
                        dir="ltr"
                        value="<?php echo esc_attr($options['price-multiplier'] ?? ''); ?>"
                        placeholder="1.1">
                </label>
                <span class="settings-field-description">
                    <?php esc_html_e('عددی که قیمت در آن ضرب یا بر آن تقسیم می‌شود. اگر خالی باشد ۱٫۱ (ارزش افزوده‌ی ۱۰٪) استفاده می‌شود.', 'pgco-tables'); ?>
                </span>
            </div>
            <!-- End: Price Multiplier Field -->

            <!-- Start: Manual Sync -->
            <div class="settings-field pgco-tables-sync-box">
                <button type="button"
                    class="button button-secondary pgco-tables-sync-single"
                    data-post-id="<?php echo esc_attr($post->ID); ?>"
                    data-nonce="<?php echo esc_attr(wp_create_nonce('pgco-tables-sync')); ?>">
                    <?php esc_html_e('بروزرسانی الان از گوگل شیت', 'pgco-tables'); ?>
                </button>

                <span class="pgco-tables-sync-status">
                    <?php if ($last_synced) : ?>
                        <?php if ($last_status === 'success') : ?>
                            <span class="pgco-tables-status-badge pgco-tables-status-success">
                                <?php
                                printf(
                                    /* translators: %s: human time diff, e.g. "5 mins" */
                                    esc_html__('آخرین بروزرسانی موفق: %s پیش', 'pgco-tables'),
                                    esc_html(human_time_diff(strtotime($last_synced), current_time('timestamp')))
                                );
                                ?>
                            </span>
                        <?php else : ?>
                            <span class="pgco-tables-status-badge pgco-tables-status-error">
                                <?php esc_html_e('آخرین تلاش ناموفق بود — در حال نمایش نسخه‌ی جایگزین (در صورت تنظیم).', 'pgco-tables'); ?>
                            </span>
                        <?php endif; ?>
                    <?php else : ?>
                        <span class="pgco-tables-status-badge pgco-tables-status-none">
                            <?php esc_html_e('هنوز هیچ بروزرسانی‌ای انجام نشده است.', 'pgco-tables'); ?>
                        </span>
                    <?php endif; ?>
                </span>

                <div class="pgco-tables-sync-result" style="display:none;"></div>
            </div>
            <!-- End: Manual Sync -->

            <!-- Start: Recent Log -->
            <div class="settings-field pgco-tables-log-box">
                <label data-title="<?php esc_attr_e('تاریخچه‌ی اخیر بروزرسانی', 'pgco-tables'); ?>"></label>
                <?php $logs = Database::instance()->logs_for($post->ID, 10); ?>
                <?php if ($logs) : ?>
                    <table class="widefat pgco-tables-log-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('تاریخ', 'pgco-tables'); ?></th>
                                <th><?php esc_html_e('وضعیت', 'pgco-tables'); ?></th>
                                <th><?php esc_html_e('پیام', 'pgco-tables'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log) : ?>
                                <tr>
                                    <td><?php echo esc_html($log['created_at']); ?></td>
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
                <?php else : ?>
                    <p><?php esc_html_e('هنوز لاگی ثبت نشده است.', 'pgco-tables'); ?></p>
                <?php endif; ?>
            </div>
            <!-- End: Recent Log -->

            <?php if (!empty($options['table'])) { ?>
                <!-- Start: Table Preview (cached content, updated via the sync button above) -->
                <div class="settings-field table-preview">
                    <?php
                    $table = json_decode($options['table'], true);

                    if ($table) { ?>
                        <table class="pgco-table">

                            <?php if (isset($table['head'])) { ?>
                                <thead>
                                    <?php foreach ($table['head'] as $row) { ?>
                                        <tr>
                                            <?php foreach ($row as $cell) { ?>
                                                <th><?php echo esc_html($cell); ?></th>
                                            <?php } ?>
                                        </tr>
                                    <?php } ?>
                                </thead>
                            <?php } ?>

                            <?php if (isset($table['body'])) { ?>
                                <tbody>
                                    <?php foreach ($table['body'] as $rowIndex => $row) { ?>
                                        <tr>
                                            <?php foreach ($row as $cellIndex => $cell) { ?>
                                                <td class="column-<?php echo esc_attr($cellIndex + 1); ?>" data-title="<?php echo esc_attr($table['head'][0][$cellIndex] ?? ''); ?>"><?php echo esc_html($cell); ?></td>
                                            <?php } ?>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            <?php } ?>

                            <?php if (isset($table['foot'])) { ?>
                                <tfoot>
                                    <?php foreach ($table['foot'] as $row) { ?>
                                        <tr>
                                            <?php foreach ($row as $cell) { ?>
                                                <th><?php echo esc_html($cell); ?></th>
                                            <?php } ?>
                                        </tr>
                                    <?php } ?>
                                </tfoot>
                            <?php } ?>
                        </table>
                    <?php } ?>
                </div>
                <!-- End: Table Preview -->
            <?php } ?>

        </div>
<?php
    }

    public function columns($columns)
    {
        $date = $columns['date'];
        unset($columns['date']);
        $columns['shortcode'] = "شرتکد";
        $columns['sheet_url'] = __('آدرس گوگل شیت', 'pgco-tables');
        $columns['tablepress_fallback'] = __('فالبک TablePress', 'pgco-tables');
        $columns['last_synced'] = __('آخرین بروزرسانی', 'pgco-tables');
        $columns['sync_status'] = __('وضعیت', 'pgco-tables');
        $columns['sync_action'] = __('عملیات', 'pgco-tables');
        $columns['date'] = $date;
        return $columns;
    }

    public function column($column, $post_id)
    {
        if ($column == 'shortcode') {
            echo '<input type="text" value="[pgco-tables id=\'' . (int) $post_id . '\']" readonly dir="ltr" onclick="this.select()">';
            echo '<input type="text" value="[pgco-tables-price id=\'' . (int) $post_id . '\']" readonly dir="ltr" onclick="this.select()" title="' . esc_attr__('کمترین و بیشترین قیمت', 'pgco-tables') . '">';
            return;
        }

        $options = get_post_meta($post_id, '_table_options', true);
        $options = is_array($options) ? $options : [];

        if ($column == 'sheet_url') {
            if (!empty($options['sheet-url'])) {
                echo '<span style="display:inline-flex; align-items:center; gap:4px; max-width: 100%;">';
                echo '<span dir="ltr" style="display:inline-block; max-width: 200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; vertical-align:middle;" title="' . esc_attr($options['sheet-url']) . '">' . esc_html($options['sheet-url']) . '</span>';
                echo '<button type="button" class="button button-small pgco-tables-copy-url" data-url="' . esc_attr($options['sheet-url']) . '" title="' . esc_attr__('کپی آدرس', 'pgco-tables') . '" style="padding:0 4px; line-height:1.6; height:22px;">';
                echo '<span class="dashicons dashicons-clipboard" style="font-size:14px; width:14px; height:14px; vertical-align:middle;"></span>';
                echo '</button>';
                echo '</span>';
            } else {
                echo '—';
            }
            return;
        }

        if ($column == 'tablepress_fallback') {
            echo !empty($options['tablepress-id']) ? esc_html($options['tablepress-id']) : '—';
            return;
        }

        if ($column == 'last_synced') {
            $last_synced = get_post_meta($post_id, '_table_last_synced', true);
            if ($last_synced) {
                printf(
                    /* translators: %s: human time diff */
                    esc_html__('%s پیش', 'pgco-tables'),
                    esc_html(human_time_diff(strtotime($last_synced), current_time('timestamp')))
                );
            } else {
                echo '—';
            }
            return;
        }

        if ($column == 'sync_status') {
            $last_status = get_post_meta($post_id, '_table_last_sync_status', true);
            echo '<span id="pgco-tables-status-cell-' . esc_attr($post_id) . '">';
            if ($last_status === 'success') {
                echo '<span class="pgco-tables-status-badge pgco-tables-status-success">' . esc_html__('موفق', 'pgco-tables') . '</span>';
            } elseif ($last_status === 'error') {
                echo '<span class="pgco-tables-status-badge pgco-tables-status-error">' . esc_html__('ناموفق', 'pgco-tables') . '</span>';
            } else {
                echo '<span class="pgco-tables-status-badge pgco-tables-status-none">—</span>';
            }
            echo '</span>';
            return;
        }

        if ($column == 'sync_action') {
            if (empty($options['sheet-url'])) {
                echo '—';
                return;
            }
            echo '<button type="button" class="button button-small pgco-tables-sync-single" data-post-id="' . esc_attr($post_id) . '" data-nonce="' . esc_attr(wp_create_nonce('pgco-tables-sync')) . '">' . esc_html__('بروزرسانی', 'pgco-tables') . '</button>';
            return;
        }
    }
}
