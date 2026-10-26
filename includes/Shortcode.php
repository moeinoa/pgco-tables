<?php

namespace PGCO_Tables;

defined('ABSPATH') || exit;

class Shortcode
{
	private static $instance = null;

	public static function instance(): self
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct()
	{
		add_shortcode('pgco-tables', [$this, 'render']);
		add_shortcode('pgco-tables-price', [$this, 'render_price']);
	}

	/**
	 * Table post ID of a shortcode, or 0 when it is not a table.
	 */
	private function table_id($atts, string $tag): int
	{
		$atts = shortcode_atts(['id' => ''], $atts, $tag);

		$post_id = (int) $atts['id'];

		return ($post_id > 0 && get_post_type($post_id) === 'table') ? $post_id : 0;
	}

	/**
	 * Cached table of the last successful Google Sheet sync, or null when it is empty/unavailable.
	 */
	private function cached_table(array $options): ?array
	{
		if (empty($options['table'])) {
			return null;
		}

		$decoded = json_decode($options['table'], true);

		if (! empty($decoded) && (! empty($decoded['head']) || ! empty($decoded['body']))) {
			return $decoded;
		}

		return null;
	}

	/**
	 * [pgco-tables id="123"]: the table.
	 */
	public function render($atts)
	{
		$post_id = $this->table_id($atts, 'pgco-tables');

		if (! $post_id) {
			return '';
		}

		$options = get_post_meta($post_id, '_table_options', true);
		$options = is_array($options) ? $options : [];

		// 1) Prefer the cached copy from the last successful Google Sheet sync.
		$table = $this->cached_table($options);

		// 2) Fallback to a linked TablePress table if the cache is empty/unavailable.
		$using_fallback = ! $table && ! empty($options['tablepress-id']) && shortcode_exists('table');

		// debug line: record the final result of the decision (cache / fallback / empty).
		$pgco_debug_source = $table ? 'cache' : ($using_fallback ? 'fallback' : 'empty');
		apply_filters('pgco_tables_after_render', $post_id, $pgco_debug_source, [
			'tablepress_id' => $options['tablepress-id'] ?? '',
		]);

		$vat      = PostType::vat_settings($options);
		$vat_mode = $vat['enabled'] ? $vat['operation'] : 'none';
		$vat_rate = (string) $vat['rate'];

		ob_start();

		echo Enqueue::load(); // phpcs:ignore WordPress.Security.EscapeOutput -- stylesheet tag printed by WordPress

		if ($using_fallback) {
			// the wrapper carries the VAT mode, tax-tables.js reads it for the TablePress table inside
			echo '<div data-pgco-vat="' . esc_attr($vat_mode) . '" data-pgco-rate="' . esc_attr($vat_rate) . '">';
			echo do_shortcode('[table id="' . esc_attr($options['tablepress-id']) . '" /]'); // phpcs:ignore WordPress.Security.EscapeOutput -- table plugin output
			echo '</div>';
		} elseif ($table) {
			$this->render_bar($vat_mode);
			$this->render_table($table, $vat_mode, $vat_rate);
		} else { ?>
<div class="pgco-tables-empty">
	<?php esc_html_e('در حال حاضر اطلاعاتی برای نمایش این جدول موجود نیست.', 'pgco-tables'); ?>
</div>
<?php }

		if (current_user_can('edit_post', $post_id)) { ?>
<div class="pgco-tables-edit">
	<a href="<?php echo esc_url(get_edit_post_link($post_id)); ?>">
		<?php esc_html_e('ویرایش', 'pgco-tables'); ?>
	</a>
	<?php if ($using_fallback) { ?>
		<span class="pgco-tables-fallback-note">
			<?php esc_html_e('(در حال نمایش نسخه‌ی جایگزین از TablePress)', 'pgco-tables'); ?>
		</span>
	<?php } ?>
</div>
<?php }

		return ob_get_clean();
	}

	/**
	 * [pgco-tables-price id="123"] or [pgco-tables-price ids="123,124"]: "lowest تا highest تومان" of one table,
	 * or of all the listed tables together, e.g. for a sentence in the page text.
	 * Tables without cached data or without a numeric price are skipped; empty when none is left.
	 */
	public function render_price($atts)
	{
		$atts = shortcode_atts(['id' => '', 'ids' => ''], $atts, 'pgco-tables-price');

		$min = null;
		$max = null;

		foreach (preg_split('/[\s,،]+/u', trim($atts['id'] . ',' . $atts['ids'])) ?: [] as $part) {
			$number = PriceRange::parse_number($part);
			$post_id = $number ? (int) $number : 0;

			if ($post_id <= 0 || get_post_type($post_id) !== 'table') {
				continue;
			}

			$range = $this->price_range($post_id);

			if (! $range) {
				continue;
			}

			$min = null === $min ? $range['min'] : min($min, $range['min']);
			$max = null === $max ? $range['max'] : max($max, $range['max']);
		}

		return null === $min ? '' : esc_html(PriceRange::format(['min' => $min, 'max' => $max]));
	}

	/**
	 * Lowest and highest price of one table as shown on the page.
	 *
	 * @return array{min: float, max: float}|null
	 */
	private function price_range(int $post_id): ?array
	{
		$options = get_post_meta($post_id, '_table_options', true);
		$options = is_array($options) ? $options : [];
		$table = $this->cached_table($options);

		$range = $table ? PriceRange::from_table($table) : null;

		// the prices on the page are the table's prices divided (see render_table), so is the range
		$vat = PostType::vat_settings($options);
		if ($range && $vat['enabled'] && $vat['operation'] === 'divide') {
			$range = ['min' => $range['min'] / $vat['rate'], 'max' => $range['max'] / $vat['rate']];
		}

		return $range;
	}

	/**
	 * A price cell of the table body as [shown text, the table's own text or ''].
	 * With $divide the shown price is the table's price divided by $rate (the table's prices include VAT and the
	 * page starts without it); the toggle in tax-tables.js then shows the table's own price. Text that is not a plain
	 * number stays as it is.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function body_cell(string $cell, bool $divide, float $rate): array
	{
		if ($divide && $rate > 0) {
			$scaled = PriceRange::scale_text($cell, 1 / $rate);

			if (null !== $scaled) {
				return [$scaled, $cell];
			}
		}

		return [$cell, ''];
	}

	/**
	 * Bar above the table: VAT toggle, date, print button. tax-tables.js fills the date and binds the events
	 * (and builds the same markup for tables that have no bar, e.g. TablePress).
	 */
	private function render_bar(string $vat_mode): void
	{ ?>
<div class="tp-vat-header">
	<?php if ($vat_mode === 'none') { ?>
	<span class="tp-vat-label"></span>
	<?php } else { ?>
	<label class="tp-vat-label"><input type="checkbox" class="tp-vat-toggle" autocomplete="off"><?php echo esc_html__('نمایش قیمت با ارزش افزوده', 'pgco-tables'); ?></label>
	<?php } ?>
	<span class="tp-table-date"></span>
	<button type="button" class="tp-print-btn" title="<?php esc_attr_e('پرینت جدول', 'pgco-tables'); ?>" aria-label="<?php esc_attr_e('پرینت جدول', 'pgco-tables'); ?>"></button>
</div>
<?php }

	private function render_table(array $table, string $vat_mode, string $vat_rate): void
	{
		$divide = $vat_mode === 'divide';
		$rate   = (float) $vat_rate;
?>
<table class="pgco-table" data-pgco-vat="<?php echo esc_attr($vat_mode); ?>" data-pgco-rate="<?php echo esc_attr($vat_rate); ?>">
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
		<?php foreach ($table['body'] as $row) { $last_cell = array_key_last($row); ?>
		<tr>
			<?php foreach ($row as $cell_index => $cell) { [$shown, $full] = $this->body_cell((string) $cell, $divide && $cell_index === $last_cell, $rate); ?>
			<td<?php if ($full !== '') { ?> data-full="<?php echo esc_attr($full); ?>"<?php } ?>><?php echo esc_html($shown); ?></td>
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
<?php }
}
