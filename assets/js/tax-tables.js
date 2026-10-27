/**
 * Price tables: VAT toggle, today's date and print button above each table.
 *
 * The bar's markup comes from the pgco-tables shortcode (Shortcode.php). Tables without one (TablePress) get the same markup built here.
 * VAT per table: data-pgco-vat = divide | multiply | none, data-pgco-rate = the factor (1.1). Legacy values: subtract = divide, add = multiply. Ticking always shows the price with VAT. Styles live in frontend.css.
 * The bar sticks with CSS (position: sticky in frontend.css), not with JavaScript: this file only makes sure the bar and its table sit in one
 * .tp-table-block so the bar leaves together with its own table.
 */
(function () {
    'use strict';

    var DEFAULT_VAT_RATE = 1.1;

    // ===== digits =====
    function toEnglishDigits(str) {
        return String(str).replace(/[۰-۹]/g, function (ch) {
            return ch.charCodeAt(0) - 0x06F0;
        }).replace(/[٠-٩]/g, function (ch) {
            return ch.charCodeAt(0) - 0x0660;
        });
    }

    function toPersianDigits(str) {
        return String(str).replace(/\d/g, function (d) {
            return String.fromCharCode(0x06F0 + Number(d));
        });
    }

    // Plain numbers only ("125,000", "۱۲۵٬۰۰۰"); anything else ("100-200", "1200 تومان", "تماس بگیرید") gives NaN and is left untouched
    function parseRawNumber(str) {
        var normalized = toEnglishDigits(str).trim().replace(/[,٬،]/g, '').replace(/٫/g, '.');
        return /^\d+(\.\d+)?$/.test(normalized) ? parseFloat(normalized) : NaN;
    }

    function formatPersianNumber(num) {
        return toPersianDigits(Math.round(num).toLocaleString('en-US'));
    }

    // ===== today's Jalali date (Tehran time) =====
    function formatTodayJalali() {
        try {
            return new Intl.DateTimeFormat('fa-IR', {
                day: 'numeric',
                month: 'long',
                year: 'numeric',
                timeZone: 'Asia/Tehran'
            }).format(new Date());
        } catch (e) {
            return '';
        }
    }

    // ===== print =====
    // Prints one table: a copy of it (and today's date) is put in a print-only block, everything else on the page is
    // hidden by a print stylesheet while the print dialog is open. (A hidden iframe printed the whole page in Chrome.)
    function printTable(table) {
        var area = document.createElement('div');
        area.id = 'pgco-print-area';

        var date = document.createElement('div');
        date.className = 'pgco-print-date';
        date.textContent = formatTodayJalali();

        var copy = table.cloneNode(true);
        copy.removeAttribute('id');

        area.appendChild(date);
        area.appendChild(copy);

        var style = document.createElement('style');
        style.textContent = '@media screen{#pgco-print-area{display:none!important}}' +
            '@media print{' +
            'body>*:not(#pgco-print-area){display:none!important}' +
            '#pgco-print-area{display:block!important;direction:rtl;font-family:Tahoma,sans-serif;margin:16px;color:#000!important;background:#fff!important}' +
            '#pgco-print-area .pgco-print-date{font-size:16px;color:#555!important;margin-bottom:8px;text-align:end}' +
            '#pgco-print-area table{width:100%;border-collapse:collapse}' +
            '#pgco-print-area th,#pgco-print-area td{border:1px solid #ccc!important;padding:6px;text-align:center;color:#000!important;background:#fff!important}' +
            '#pgco-print-area th{font-weight:bold}' +
            '}';

        function cleanup() {
            window.removeEventListener('afterprint', cleanup);
            if (area.parentNode) {
                area.parentNode.removeChild(area);
            }
            if (style.parentNode) {
                style.parentNode.removeChild(style);
            }
        }

        document.head.appendChild(style);
        document.body.appendChild(area);
        window.addEventListener('afterprint', cleanup);

        // Chrome blocks here until the dialog is closed; other browsers return at once and fire afterprint later
        window.print();

        // safety net for a browser that never fires afterprint
        setTimeout(cleanup, 300000);
    }

    // ===== bar markup (same as Shortcode.php) =====
    function buildBar(mode) {
        var bar = document.createElement('div');
        bar.className = 'tp-vat-header';

        var label;
        if (mode === 'none') {
            label = document.createElement('span');
        } else {
            label = document.createElement('label');
            var checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'tp-vat-toggle';
            checkbox.autocomplete = 'off';
            label.appendChild(checkbox);
            label.appendChild(document.createTextNode('نمایش قیمت با ارزش افزوده'));
        }
        label.className = 'tp-vat-label';

        var date = document.createElement('span');
        date.className = 'tp-table-date';

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'tp-print-btn';
        button.title = 'پرینت جدول';
        button.setAttribute('aria-label', 'پرینت جدول');

        bar.appendChild(label);
        bar.appendChild(date);
        bar.appendChild(button);
        return bar;
    }

    function getVatMode(table) {
        var holder = table.hasAttribute('data-pgco-vat') ? table : table.closest('[data-pgco-vat]');
        var mode = holder ? holder.getAttribute('data-pgco-vat') : '';
        return mode === 'none' ? 'none' : ((mode === 'multiply' || mode === 'add') ? 'multiply' : 'divide');
    }

    function getVatRate(table) {
        var holder = table.hasAttribute('data-pgco-rate') ? table : table.closest('[data-pgco-rate]');
        var rate = holder ? parseFloat(holder.getAttribute('data-pgco-rate')) : NaN;
        return rate > 0 ? rate : DEFAULT_VAT_RATE;
    }

    function lastCellOf(row) {
        var cells = row.querySelectorAll('td');
        return cells.length ? cells[cells.length - 1] : null;
    }

    // ===== VAT toggle =====
    // Ticking always raises the price (shows it with VAT):
    // multiply: the shown price is the table's price, ticking shows it * rate.
    // divide: the shown price is the table's price / rate (already so when the page is shown), ticking shows the table's own price (data-full).
    function bindToggle(checkbox, rows, mode, rate) {
        checkbox.checked = false;
        checkbox.addEventListener('change', function () {
            var checked = this.checked;
            rows.forEach(function (row) {
                var cell = lastCellOf(row);
                if (!cell) {
                    return;
                }
                var value = (mode === 'divide')
                    ? (cell.dataset.full === undefined ? NaN : parseRawNumber(cell.dataset.full))
                    : parseRawNumber(cell.dataset.raw) * rate;
                if (isNaN(value)) {
                    return;
                }
                cell.textContent = checked ? formatPersianNumber(value) : cell.dataset.raw;
            });
        });
    }

    // 250539 -> "250,539", in Persian digits when the original text uses them
    function formatLike(original, num) {
        var text = Math.round(num).toLocaleString('en-US');
        return /[۰-۹]/.test(original) ? toPersianDigits(text) : text;
    }

    // ===== block: bar + scroll box + table =====
    // The shortcode prints this block already. TablePress tables, and pages cached before the block existed, get it here: the table moves into a
    // scroll box (a wide table scrolls sideways there, never in an ancestor of the bar, which would stop position: sticky) and the bar
    // is put in front of the box, both inside one .tp-table-block.
    function ensureBlock(table) {
        var box = table.parentElement;
        if (box && box.classList.contains('tp-table-scroll') && box.parentElement && box.parentElement.classList.contains('tp-table-block')) {
            return box.parentElement;
        }

        var bar = table.previousElementSibling;
        if (!bar || !bar.classList.contains('tp-vat-header')) {
            bar = null;
        }

        var block = document.createElement('div');
        block.className = 'tp-table-block';
        box = document.createElement('div');
        box.className = 'tp-table-scroll';

        table.parentNode.insertBefore(block, table);
        if (bar) {
            block.appendChild(bar);
        }
        block.appendChild(box);
        box.appendChild(table);
        return block;
    }

    // ===== init =====
    function init() {
        var tables = document.querySelectorAll('table[id^="tablepress-"], table.tablepress, table.pgco-table');

        tables.forEach(function (table) {
            var tbody = table.querySelector('tbody');
            if (!tbody) {
                return;
            }

            var rows = tbody.querySelectorAll('tr');
            if (!rows.length) {
                return;
            }

            var mode = getVatMode(table);

            var block = ensureBlock(table);
            var bar = block.querySelector('.tp-vat-header');
            if (!bar) {
                bar = buildBar(mode);
                block.insertBefore(bar, block.firstChild);
            }

            if (bar.hasAttribute('data-ready')) {
                return;
            }
            bar.setAttribute('data-ready', '');

            var rate = getVatRate(table);

            rows.forEach(function (row) {
                var cell = lastCellOf(row);
                if (!cell) {
                    return;
                }

                // divide: the table's prices include VAT and the page starts with them divided. The shortcode already
                // prints them so (data-full holds the table's own price); other tables (TablePress) are divided here.
                if (mode === 'divide' && cell.dataset.full === undefined) {
                    var original = cell.textContent.trim();
                    var price = parseRawNumber(original);
                    if (!isNaN(price)) {
                        cell.dataset.full = original;
                        cell.textContent = formatLike(original, price / rate);
                    }
                }

                // the value shown at the start (the toggle goes back to it)
                if (cell.dataset.raw === undefined) {
                    cell.dataset.raw = cell.textContent.trim();
                }
            });

            var date = bar.querySelector('.tp-table-date');
            if (date) {
                date.textContent = formatTodayJalali();
            }

            var button = bar.querySelector('.tp-print-btn');
            if (button) {
                button.addEventListener('click', function () {
                    printTable(table);
                });
            }

            var checkbox = bar.querySelector('.tp-vat-toggle');
            if (checkbox && mode !== 'none') {
                bindToggle(checkbox, rows, mode, rate);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
