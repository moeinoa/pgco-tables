<?php

namespace PGCO_Tables;

defined('ABSPATH') || exit;

/**
 * Lowest and highest price of a table, for the [pgco-tables-price] shortcode.
 *
 * Same rule as the price toggle on the front end (tax-tables.js): the price of a row is its last cell, and only
 * cells that are a plain number count ("125,000", "۱۲۵٬۰۰۰"); text like "تماس بگیرید" or "100-200" is skipped.
 */
class PriceRange
{
    private const PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    private const ARABIC_DIGITS = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    /**
     * Lowest and highest price in the body rows of a cached table.
     *
     * @param array $table Decoded table: ['head' => ..., 'body' => [[cell, ...], ...]].
     * @return array{min: float, max: float}|null null when no row has a numeric price.
     */
    public static function from_table(array $table): ?array
    {
        $prices = [];

        foreach ((array) ($table['body'] ?? []) as $row) {
            if (! is_array($row) || ! $row) {
                continue;
            }

            $price = self::parse_number((string) end($row));

            if (null !== $price) {
                $prices[] = $price;
            }
        }

        if (! $prices) {
            return null;
        }

        return ['min' => min($prices), 'max' => max($prices)];
    }

    /**
     * @param string $value Cell text.
     * @return float|null null when the whole text is not a plain number.
     */
    public static function parse_number(string $value): ?float
    {
        $value = str_replace(
            array_merge(self::PERSIAN_DIGITS, self::ARABIC_DIGITS, [',', '٬', '،', '٫']),
            array_merge(range(0, 9), range(0, 9), ['', '', '', '.']),
            trim($value)
        );

        return preg_match('/^\d+(\.\d+)?$/u', $value) ? (float) $value : null;
    }

    /**
     * A price text multiplied by a factor, written the way the original is: "250,539" * 2 -> "501,078"
     * (Persian digits when the original uses them).
     *
     * @param string $text Cell text.
     * @param float  $factor
     * @return string|null null when the text is not a plain number.
     */
    public static function scale_text(string $text, float $factor): ?string
    {
        $number = self::parse_number($text);

        if (null === $number) {
            return null;
        }

        $scaled = number_format((int) round($number * $factor), 0, '.', ',');

        return preg_match('/[۰-۹]/u', $text) ? str_replace(range(0, 9), self::PERSIAN_DIGITS, $scaled) : $scaled;
    }

    /**
     * "۱۲۰,۰۰۰ تا ۱۵۰,۰۰۰ تومان" (a single price when lowest and highest are equal).
     *
     * @param array{min: float, max: float} $range
     * @return string
     */
    public static function format(array $range): string
    {
        $min = self::format_number($range['min']);
        $max = self::format_number($range['max']);

        if ($min === $max) {
            /* translators: %s: price */
            return sprintf(__('%s تومان', 'pgco-tables'), $min);
        }

        /* translators: 1: lowest price, 2: highest price */
        return sprintf(__('%1$s تا %2$s تومان', 'pgco-tables'), $min, $max);
    }

    /**
     * 1234567 -> ۱,۲۳۴,۵۶۷
     */
    private static function format_number(float $number): string
    {
        return str_replace(range(0, 9), self::PERSIAN_DIGITS, number_format((int) round($number), 0, '.', ','));
    }
}
