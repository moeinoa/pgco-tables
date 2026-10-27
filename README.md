# PGCO Tables

A WordPress plugin that keeps price tables in Google Sheets and shows them on the site as fast, cached HTML tables.
Each table is a post of the `table` type; a sync copies the sheet into the post, and the front end never calls Google.

> افزونه‌ی «جدول‌های پتروگرایش»: جدول قیمت را از گوگل شیت می‌خواند، در سایت ذخیره می‌کند و با شورت‌کد نمایش می‌دهد.

## Features

- **Google Sheets sync** through the official Sheets API v4 (one API key for the whole site). Per table: a sheet URL with its `gid`; the first row is the header.
- **Cached output**: pages print the last successful sync. If a table has no cache, an optional [TablePress](https://wordpress.org/plugins/tablepress/) table is shown instead.
- **Manual sync, no cron**: one button per table, a bulk action in the table list and "sync all". Every table is a separate request, so a long list never hits the server time limit; Google's per-minute quota is respected (pause between tables, retry on quota errors).
- **Sync log** per table and a global one.
- **Bar above each table**: VAT checkbox, today's Jalali date and a print button; it sticks below the site header while the table scrolls.
- **Price range shortcode**: "X تا Y تومان" from the lowest and highest price of one or several tables.

## Requirements

WordPress 5.8+, PHP 8.0+, a Google Sheets API key (the sheet must be readable by link).

## Install

1. Copy the plugin folder to `wp-content/plugins/pgco-tables` and activate it.
2. **Tables → بروزرسانی جدول‌ها**: save the Google Sheets API key.
3. **Tables → Add New**: set the sheet URL, then press "بروزرسانی الان از گوگل شیت".
4. Put the shortcode in a page.

## Shortcodes

```
[pgco-tables id="12"]              the table
[pgco-tables-price id="12"]        "lowest تا highest تومان" of one table
[pgco-tables-price ids="12,13"]    one range over several tables
```

The price of a row is its **last cell**; only plain numbers count (`125,000`, `۱۲۵٬۰۰۰`). Text such as "تماس بگیرید" or `100-200` is skipped.

## VAT per table

In the table's settings:

| Setting | Meaning |
|---|---|
| Checkbox | show the VAT checkbox above the table or not |
| Operation: **multiply** | the sheet prices are without VAT; ticking shows them × the factor |
| Operation: **divide** | the sheet prices include VAT; the page shows them ÷ the factor (printed by the server), ticking shows the sheet price |
| Factor | default `1.1` |

Ticking always raises the price. Values are stored in the table's `_table_options` meta (`vat-mode`, `price-operation`, `price-multiplier`).

## Hooks

| Hook | Default | Purpose |
|---|---|---|
| `pgco_tables_sticky_offsets` | `[[1024,115],[768,81],[0,64]]` | sticky bar top offset as `[min window width, px]` |
| `pgco_tables_sync_all_delay_microseconds` | `400000` | pause between tables in a bulk sync |
| `pgco_tables_api_max_retry_attempts` | `3` | retries on a Google quota error |
| `pgco_tables_bulk_time_budget` | `15` | seconds before the no-JavaScript bulk action stops |

Add the class `pgco-no-sticky` to a parent element to keep the bar of its tables from sticking.

The bar sticks with CSS (`position: sticky`), so no ancestor of a table may have `overflow` other than `visible`/`clip` (it would stop the bar from sticking). Each table is printed as `.tp-table-block` (bar + `.tp-table-scroll` + table); wide tables scroll sideways in `.tp-table-scroll`. Inside a box that scrolls on its own (e.g. the price board) the theme sets `.tp-vat-header { top: 0 }`.

## Debug log

Off by default. Add `define('PGCO_TABLES_DEBUG', true);` to `wp-config.php`; the log is written to `wp-content/uploads/pgco-tables/debug.log` (a folder closed to web access) and shown under **Tables → گزارش دیباگ**.

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).
