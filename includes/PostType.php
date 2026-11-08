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

    /**
     * The columns of a table that stay visible on a narrow screen; the other columns are folded into a row that
     * opens on tap. From the table's "mobile-columns" option (column numbers, 1 = the first column); empty = the first
     * three columns. The last column (the price) is always visible.
     *
     * @param array $options The table's _table_options meta.
     * @param int   $cols    Number of columns of the table.
     * @return int[] 0-based column indexes, ascending (never empty for a table with columns).
     */
    public static function mobile_columns(array $options, int $cols): array
    {
        if ($cols <= 0) {
            return [];
        }

        $visible = [];

        foreach (preg_split('/[\s,،]+/u', trim((string) ($options['mobile-columns'] ?? ''))) ?: [] as $part) {
            $number = PriceRange::parse_number($part);
            $index = $number ? (int) $number - 1 : -1;

            if ($index >= 0 && $index < $cols) {
                $visible[$index] = $index;
            }
        }

        if (! $visible) {
            $visible = array_slice(range(0, $cols - 1), 0, 3, true);
        }

        $visible[$cols - 1] = $cols - 1;
        ksort($visible);

        return array_values($visible);
    }

    /**
     * The filter dropdowns above a table, normalised from the table's "filters" option (a list set in the table's settings).
     * Without that option the older "filter-columns" list (column numbers) is read as plain "whole cell" filters.
     * The last column (the price) can only be a sort dropdown; every other column is a filter whose values are built from the
     * rows by table-filters-core.js (source: whole cell / n-th number in the text / the word after a given text).
     *
     * @param array $options The table's _table_options meta.
     * @param array $table   The cached table (head/body/foot).
     * @return array{items: array[], group: string, empty_on: bool, empty_text: string}
     */
    public static function filters_config(array $options, array $table): array
    {
        $head = array_map(static function ($cell) {
            return trim(wp_strip_all_tags((string) $cell));
        }, (array) ($table['head'][0] ?? []));
        $cols = count($head);
        $last = $cols - 1;
        $rows = [];

        if (isset($options['filters']) && is_array($options['filters'])) {
            $rows = array_values($options['filters']);
        } else {
            foreach (preg_split('/[\s,،]+/u', trim((string) ($options['filter-columns'] ?? ''))) ?: [] as $part) {
                $number = PriceRange::parse_number($part);
                if ($number) {
                    $rows[] = ['col' => (int) $number - 1, 'enabled' => 1];
                }
            }
        }

        $items = [];
        $has_sort = false;

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['col']) || ! is_numeric($row['col'])) {
                continue;
            }

            $col = (int) $row['col'];
            if ($col < 0 || $col >= $cols) {
                continue;
            }

            $is_sort = $col === $last;
            if ($is_sort && $has_sort) {
                continue;
            }
            $has_sort = $has_sort || $is_sort;

            $label = trim((string) ($row['label'] ?? ''));
            $all   = trim((string) ($row['all'] ?? ''));

            $item = [
                'enabled' => ! isset($row['enabled']) || (string) $row['enabled'] !== '0',
                'col'     => $col,
                'type'    => $is_sort ? 'sort' : 'filter',
                'label'   => $label !== '' ? $label : $head[$col],
                'all'     => $all !== '' ? $all : ($is_sort ? 'پیش‌فرض' : 'همه'),
            ];

            if ($is_sort) {
                $asc  = trim((string) ($row['asc'] ?? ''));
                $desc = trim((string) ($row['desc'] ?? ''));
                $default = (string) ($row['default'] ?? '');

                $item['asc']     = $asc !== '' ? $asc : 'ارزان‌ترین';
                $item['desc']    = $desc !== '' ? $desc : 'گران‌ترین';
                $item['default'] = in_array($default, ['asc', 'desc'], true) ? $default : '';
            } else {
                $source = (string) ($row['source'] ?? 'cell');
                $n      = (int) ($row['n'] ?? 1);

                $item['source'] = in_array($source, ['cell', 'number', 'after'], true) ? $source : 'cell';
                $item['n']      = max(1, min(9, $n ?: 1));
                $item['word']   = trim((string) ($row['word'] ?? ''));
                $item['order']  = ($row['order'] ?? '') === 'appear' ? 'appear' : 'natural';
                $item['suffix'] = trim((string) ($row['suffix'] ?? ''));
            }

            $items[] = $item;
        }

        $empty_text = trim((string) ($options['filters-empty-text'] ?? ''));

        return [
            'items'      => $items,
            'group'      => trim((string) ($options['filters-group'] ?? '')),
            'empty_on'   => (string) ($options['filters-empty'] ?? '1') !== '0',
            'empty_text' => $empty_text !== '' ? $empty_text : 'موردی پیدا نشد',
        ];
    }

    /**
     * Clean the posted filter rows before they are stored (the shape filters_config() reads).
     *
     * @param mixed $posted $_POST['options']['filters'] (already unslashed).
     * @return array[]
     */
    private static function sanitize_filters($posted): array
    {
        $clean = [];

        foreach (array_slice(is_array($posted) ? array_values($posted) : [], 0, 20) as $row) {
            if (! is_array($row) || ! isset($row['col']) || ! is_numeric($row['col'])) {
                continue;
            }

            $item = [
                'enabled' => (string) ($row['enabled'] ?? '1') === '0' ? 0 : 1,
                'col'     => max(0, min(98, (int) $row['col'])),
            ];

            foreach (['label', 'all', 'asc', 'desc', 'word', 'suffix'] as $field) {
                $item[$field] = sanitize_text_field((string) ($row[$field] ?? ''));
            }

            $item['source']  = in_array($row['source'] ?? '', ['cell', 'number', 'after'], true) ? $row['source'] : 'cell';
            $item['n']       = max(1, min(9, (int) ($row['n'] ?? 1)));
            $item['order']   = ($row['order'] ?? '') === 'appear' ? 'appear' : 'natural';
            $item['default'] = in_array($row['default'] ?? '', ['asc', 'desc'], true) ? $row['default'] : '';

            $clean[] = $item;
        }

        return $clean;
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

        // column numbers, normalised to "1,2,3" (empty = the default: the first three columns)
        if (isset($_POST['options']['mobile-columns'])) {
            $numbers = [];
            foreach (preg_split('/[\s,،]+/u', sanitize_text_field(wp_unslash($_POST['options']['mobile-columns']))) ?: [] as $part) {
                $number = PriceRange::parse_number($part);
                if ($number && $number >= 1 && $number <= 99) {
                    $numbers[(int) $number] = (int) $number;
                }
            }
            ksort($numbers);
            $options['mobile-columns'] = implode(',', $numbers);
        }

        // filters: rows posted in the order of the screen; the marker lets an empty list clear the option
        // (the marker is only printed once the table has been synced, so a table without columns never wipes it)
        if (isset($_POST['options']['filters-form'])) {
            $options['filters'] = self::sanitize_filters(isset($_POST['options']['filters']) ? wp_unslash($_POST['options']['filters']) : []);
            $options['filters-empty'] = (string) ($_POST['options']['filters-empty'] ?? '1') === '0' ? '0' : '1';
            // tables of one page with the same group name filter together; empty = this table works on its own
            $group = isset($_POST['options']['filters-group']) ? sanitize_text_field(wp_unslash($_POST['options']['filters-group'])) : '';
            $options['filters-group'] = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $group)), 0, 40);
            $options['filters-empty-text'] = isset($_POST['options']['filters-empty-text'])
                ? sanitize_text_field(wp_unslash($_POST['options']['filters-empty-text']))
                : '';
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

    /**
     * "Table filters" box of the table screen: one card per dropdown (column, how the values are read, titles, order),
     * a live preview bar and the "nothing found" box setting. Behaviour: assets/js/admin-filters.js.
     */
    private function filters_field(array $options, int $post_id): void
    {
        $cached = ! empty($options['table']) ? json_decode($options['table'], true) : null;
        $cached = is_array($cached) ? $cached : [];
        $head   = array_map(static function ($cell) {
            return trim(wp_strip_all_tags((string) $cell));
        }, (array) ($cached['head'][0] ?? []));
        $config = self::filters_config($options, $cached);
        $body   = [];

        foreach ((array) ($cached['body'] ?? []) as $row) {
            $body[] = array_map(static function ($cell) {
                return trim(wp_strip_all_tags((string) $cell));
            }, array_values((array) $row));
        }
        ?>
        <div class="settings-field pgco-tables-filter-field">
            <strong><?php esc_html_e('فیلترهای جدول', 'pgco-tables'); ?></strong>

            <?php if (! $head) : ?>
                <span class="settings-field-description"><?php esc_html_e('ستون‌ها بعد از اولین بروزرسانی از گوگل شیت اینجا نمایش داده می‌شوند.', 'pgco-tables'); ?></span>
            <?php else : ?>
                <div class="pgco-tf-admin"
                     data-head="<?php echo esc_attr(wp_json_encode($head)); ?>"
                     data-body="<?php echo esc_attr(wp_json_encode($body)); ?>"
                     data-next="<?php echo esc_attr((string) count($config['items'])); ?>">
                    <input type="hidden" name="options[filters-form]" value="1">

                    <div class="pgco-tf-preview" aria-live="polite">
                        <span class="pgco-tf-preview__title"><?php esc_html_e('پیش‌نمایش', 'pgco-tables'); ?></span>
                        <div class="pgco-tf-preview__bar"></div>
                    </div>

                    <div class="pgco-tf-rows">
                        <?php foreach ($config['items'] as $index => $item) : ?>
                            <?php $this->filter_row((string) $index, $item, $head); ?>
                        <?php endforeach; ?>
                    </div>

                    <template class="pgco-tf-template">
                        <?php $this->filter_row('__i__', ['enabled' => true, 'col' => 0, 'type' => 'filter', 'label' => '', 'all' => '', 'source' => 'cell', 'n' => 1, 'word' => '', 'order' => 'natural', 'suffix' => ''], $head, true); ?>
                    </template>

                    <button type="button" class="button pgco-tf-add"><?php esc_html_e('افزودن فیلتر', 'pgco-tables'); ?></button>

                    <div class="pgco-tf-group-setting">
                        <label>
                            <span><?php esc_html_e('گروه فیلتر (اختیاری)', 'pgco-tables'); ?></span>
                            <input type="text" name="options[filters-group]" maxlength="40" list="pgco-tf-groups" value="<?php echo esc_attr($config['group']); ?>" placeholder="<?php esc_attr_e('مثلاً سینی-کابل', 'pgco-tables'); ?>">
                        </label>
                        <datalist id="pgco-tf-groups">
                            <?php foreach ($this->filter_groups($post_id) as $group_name) : ?>
                                <option value="<?php echo esc_attr($group_name); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <span class="settings-field-description"><?php esc_html_e('جدول‌های یک صفحه که نام گروهشان یکی است، فیلترهایشان با هم اجرا می‌شود (فیلترهای هم‌عنوان یکی می‌شوند). خالی = این جدول مستقل است.', 'pgco-tables'); ?></span>
                    </div>

                    <div class="pgco-tf-empty-setting">
                        <input type="hidden" name="options[filters-empty]" value="0">
                        <label>
                            <input type="checkbox" name="options[filters-empty]" value="1" <?php checked($config['empty_on']); ?>>
                            <?php esc_html_e('نمایش باکس «موردی پیدا نشد» وقتی فیلتر نتیجه‌ای ندارد', 'pgco-tables'); ?>
                        </label>
                        <input type="text" name="options[filters-empty-text]" value="<?php echo esc_attr((string) ($options['filters-empty-text'] ?? '')); ?>" placeholder="<?php echo esc_attr($config['empty_text']); ?>">
                    </div>

                    <span class="settings-field-description">
                        <?php esc_html_e('هر کارت یک دراپ‌داون بالای جدول می‌سازد. گزینه‌ها خودکار از مقدارهای جدول ساخته می‌شوند (نیازی به انتخاب دستی نیست). ستون آخر (قیمت) فقط مرتب‌سازی دارد. ستونی که فقط یک مقدار دارد در سایت نمایش داده نمی‌شود.', 'pgco-tables'); ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Filter group names already used by the other tables (suggestions for the "group" field).
     *
     * @return string[]
     */
    private function filter_groups(int $exclude): array
    {
        $groups = [];

        foreach (get_posts(['post_type' => $this->post_type, 'post_status' => 'any', 'posts_per_page' => 300, 'fields' => 'ids', 'post__not_in' => [$exclude]]) as $id) {
            $meta  = get_post_meta($id, '_table_options', true);
            $group = is_array($meta) ? trim((string) ($meta['filters-group'] ?? '')) : '';
            if ($group !== '') {
                $groups[$group] = $group;
            }
        }

        return array_values($groups);
    }

    /**
     * One filter card.
     *
     * @param string   $i        Row key in the posted array ("__i__" in the template for new rows).
     * @param array    $item     A row of filters_config().
     * @param string[] $head     Column titles.
     * @param bool     $is_open  Open (new rows) or collapsed.
     */
    private function filter_row(string $i, array $item, array $head, bool $is_open = false): void
    {
        $name   = 'options[filters][' . $i . ']';
        $col    = (int) $item['col'];
        $last   = count($head) - 1;
        $type   = $col === $last ? 'sort' : 'filter';
        $source = (string) ($item['source'] ?? 'cell');
        $asc    = (string) ($item['asc'] ?? '');
        $desc   = (string) ($item['desc'] ?? '');
        $label  = $i === '__i__' ? '' : (string) ($item['label'] ?? '');
        $all    = $i === '__i__' ? '' : (string) ($item['all'] ?? '');

        // titles saved equal to the defaults are shown as placeholders, so a renamed column keeps following its title
        if (isset($head[$col]) && $label === $head[$col]) {
            $label = '';
        }
        if (in_array($all, ['همه', 'پیش‌فرض'], true)) {
            $all = '';
        }
        if ($asc === 'ارزان‌ترین') {
            $asc = '';
        }
        if ($desc === 'گران‌ترین') {
            $desc = '';
        }
        ?>
        <div class="pgco-tf-row<?php echo $is_open ? ' is-open' : ''; ?>" data-type="<?php echo esc_attr($type); ?>" data-source="<?php echo esc_attr($source); ?>">
            <div class="pgco-tf-row__head">
                <input type="hidden" name="<?php echo esc_attr($name); ?>[enabled]" value="0">
                <label class="pgco-tf-row__on" title="<?php esc_attr_e('فعال', 'pgco-tables'); ?>">
                    <input type="checkbox" data-f="enabled" name="<?php echo esc_attr($name); ?>[enabled]" value="1" <?php checked(! empty($item['enabled'])); ?>>
                </label>
                <button type="button" class="pgco-tf-row__title" data-act="toggle"></button>
                <span class="pgco-tf-row__info"></span>
                <span class="pgco-tf-row__actions">
                    <button type="button" class="button-link" data-act="up" aria-label="<?php esc_attr_e('بالا', 'pgco-tables'); ?>">↑</button>
                    <button type="button" class="button-link" data-act="down" aria-label="<?php esc_attr_e('پایین', 'pgco-tables'); ?>">↓</button>
                    <button type="button" class="button-link pgco-tf-row__remove" data-act="remove"><?php esc_html_e('حذف', 'pgco-tables'); ?></button>
                </span>
            </div>

            <div class="pgco-tf-row__body">
                <label class="pgco-tf-f">
                    <span><?php esc_html_e('ستون', 'pgco-tables'); ?></span>
                    <select data-f="col" name="<?php echo esc_attr($name); ?>[col]">
                        <?php foreach ($head as $index => $title) : ?>
                            <option value="<?php echo esc_attr((string) $index); ?>" <?php selected($col, $index); ?>><?php echo esc_html($title !== '' ? $title : ('#' . ($index + 1))); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="pgco-tf-f">
                    <span><?php esc_html_e('عنوان فیلتر', 'pgco-tables'); ?></span>
                    <input type="text" data-f="label" name="<?php echo esc_attr($name); ?>[label]" value="<?php echo esc_attr($label); ?>" placeholder="<?php esc_attr_e('پیش‌فرض: نام ستون', 'pgco-tables'); ?>">
                </label>

                <label class="pgco-tf-f">
                    <span><?php esc_html_e('متن گزینه‌ی همه', 'pgco-tables'); ?></span>
                    <input type="text" data-f="all" name="<?php echo esc_attr($name); ?>[all]" value="<?php echo esc_attr($all); ?>" placeholder="<?php echo esc_attr($type === 'sort' ? 'پیش‌فرض' : 'همه'); ?>">
                </label>

                <label class="pgco-tf-f pgco-tf-f--filter">
                    <span><?php esc_html_e('مقدار از کجا خوانده شود', 'pgco-tables'); ?></span>
                    <select data-f="source" name="<?php echo esc_attr($name); ?>[source]">
                        <option value="cell" <?php selected($source, 'cell'); ?>><?php esc_html_e('کل سلول', 'pgco-tables'); ?></option>
                        <option value="number" <?php selected($source, 'number'); ?>><?php esc_html_e('عدد داخل متن', 'pgco-tables'); ?></option>
                        <option value="after" <?php selected($source, 'after'); ?>><?php esc_html_e('بعد از یک کلمه', 'pgco-tables'); ?></option>
                    </select>
                </label>

                <label class="pgco-tf-f pgco-tf-f--filter pgco-tf-f--n">
                    <span><?php esc_html_e('شماره‌ی عدد در متن', 'pgco-tables'); ?></span>
                    <input type="number" min="1" max="9" data-f="n" name="<?php echo esc_attr($name); ?>[n]" value="<?php echo esc_attr((string) ($item['n'] ?? 1)); ?>">
                </label>

                <label class="pgco-tf-f pgco-tf-f--filter pgco-tf-f--word">
                    <span><?php esc_html_e('کلمه‌ی قبل از مقدار (مثلاً عرض)', 'pgco-tables'); ?></span>
                    <input type="text" data-f="word" name="<?php echo esc_attr($name); ?>[word]" value="<?php echo esc_attr((string) ($item['word'] ?? '')); ?>">
                </label>

                <label class="pgco-tf-f pgco-tf-f--filter">
                    <span><?php esc_html_e('ترتیب گزینه‌ها', 'pgco-tables'); ?></span>
                    <select data-f="order" name="<?php echo esc_attr($name); ?>[order]">
                        <option value="natural" <?php selected($item['order'] ?? 'natural', 'natural'); ?>><?php esc_html_e('عددی/الفبایی', 'pgco-tables'); ?></option>
                        <option value="appear" <?php selected($item['order'] ?? '', 'appear'); ?>><?php esc_html_e('به ترتیب ظاهر شدن در جدول', 'pgco-tables'); ?></option>
                    </select>
                </label>

                <label class="pgco-tf-f pgco-tf-f--filter">
                    <span><?php esc_html_e('پسوند کنار گزینه (مثلاً سانتی‌متر)', 'pgco-tables'); ?></span>
                    <input type="text" data-f="suffix" name="<?php echo esc_attr($name); ?>[suffix]" value="<?php echo esc_attr((string) ($item['suffix'] ?? '')); ?>">
                </label>

                <label class="pgco-tf-f pgco-tf-f--sort">
                    <span><?php esc_html_e('متن ارزان‌ترین', 'pgco-tables'); ?></span>
                    <input type="text" data-f="asc" name="<?php echo esc_attr($name); ?>[asc]" value="<?php echo esc_attr($asc); ?>" placeholder="ارزان‌ترین">
                </label>

                <label class="pgco-tf-f pgco-tf-f--sort">
                    <span><?php esc_html_e('متن گران‌ترین', 'pgco-tables'); ?></span>
                    <input type="text" data-f="desc" name="<?php echo esc_attr($name); ?>[desc]" value="<?php echo esc_attr($desc); ?>" placeholder="گران‌ترین">
                </label>

                <label class="pgco-tf-f pgco-tf-f--sort">
                    <span><?php esc_html_e('مرتب‌سازی اولیه', 'pgco-tables'); ?></span>
                    <select data-f="default" name="<?php echo esc_attr($name); ?>[default]">
                        <option value="" <?php selected($item['default'] ?? '', ''); ?>><?php esc_html_e('بدون مرتب‌سازی', 'pgco-tables'); ?></option>
                        <option value="asc" <?php selected($item['default'] ?? '', 'asc'); ?>><?php esc_html_e('ارزان‌ترین اول', 'pgco-tables'); ?></option>
                        <option value="desc" <?php selected($item['default'] ?? '', 'desc'); ?>><?php esc_html_e('گران‌ترین اول', 'pgco-tables'); ?></option>
                    </select>
                </label>
            </div>
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

            <!-- Start: Mobile Columns Field -->
            <div class="settings-field settings-field-text">
                <label for="mobile-columns" data-title="<?php esc_attr_e('ستون‌های نمایان در موبایل', 'pgco-tables'); ?>">
                    <input type="text"
                        id="mobile-columns"
                        name="options[mobile-columns]"
                        dir="ltr"
                        value="<?php echo esc_attr($options['mobile-columns'] ?? ''); ?>"
                        placeholder="1,2,3">
                </label>
                <span class="settings-field-description">
                    <?php esc_html_e('شماره‌ی ستون‌هایی که در موبایل همیشه دیده شوند (۱ = اولین ستون از راست)، مثلاً 1,3. بقیه با لمس ردیف باز می‌شوند. اگر خالی باشد سه ستون اول نمایش داده می‌شود. ستون آخر (قیمت) همیشه دیده می‌شود.', 'pgco-tables'); ?>
                </span>
            </div>
            <!-- End: Mobile Columns Field -->

            <!-- Start: Filters Field -->
            <?php $this->filters_field($options, (int) $post->ID); ?>
            <!-- End: Filters Field -->

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