/**
 * Price tables: VAT toggle, today's date and print button above each table; the bar sticks while its table scrolls.
 *
 * The bar's markup comes from the pgco-tables shortcode (Shortcode.php). Tables without one (TablePress) get the same markup built here.
 * VAT per table: data-pgco-vat = divide | multiply | none, data-pgco-rate = the factor (1.1). Legacy values: subtract = divide, add = multiply. Ticking always shows the price with VAT. Styles live in frontend.css.
 */
(function () {
    'use strict';

    var config = window.pgcoTablesTax || {};

    // Sticky top offsets as [min window width (exclusive), offset in px]; filterable in PHP (pgco_tables_sticky_offsets)
    var STICKY_OFFSETS = config.offsets || [[1024, 115], [768, 81], [0, 64]];

    // A table inside an element with this class never sticks (toggle and print still work)
    var NO_STICKY_CLASS = 'pgco-no-sticky';

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
    function printTable(table) {
        var html = '<!DOCTYPE html><html dir="rtl"><head><meta charset="utf-8"><style>' +
            'body{direction:rtl;font-family:Tahoma,sans-serif;margin:16px;}' +
            'table{width:100%;border-collapse:collapse;}' +
            'td,th{border:1px solid #ccc;padding:6px;text-align:center;}' +
            '.print-date{font-size:16px;color:#555;margin-bottom:8px;text-align:end;}' +
            '</style></head><body><div class="print-date">' + formatTodayJalali() + '</div>' + table.outerHTML + '</body></html>';

        var iframe = document.createElement('iframe');
        iframe.style.cssText = 'position:fixed;top:-9999px;left:-9999px;width:0;height:0;border:0;';

        function cleanup() {
            if (iframe.parentNode) {
                iframe.parentNode.removeChild(iframe);
            }
        }

        // onload is set before the content exists, so it can never be missed
        iframe.onload = function () {
            var win = iframe.contentWindow;
            win.addEventListener('afterprint', cleanup);
            win.focus();
            win.print();
            setTimeout(cleanup, 300000);
        };
        iframe.srcdoc = html;
        document.body.appendChild(iframe);
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

    // ===== sticky bar =====
    var stickyItems = [];
    var ticking = false;

    function getStickyOffset() {
        var width = window.innerWidth;
        for (var i = 0; i < STICKY_OFFSETS.length; i++) {
            if (width > STICKY_OFFSETS[i][0]) {
                return STICKY_OFFSETS[i][1];
            }
        }
        return 0;
    }

    // Ancestors that can scroll on their own (the page itself is not one of them)
    function scrollingAncestors(element) {
        var list = [];
        for (var node = element.parentElement; node && node !== document.body && node !== document.documentElement; node = node.parentElement) {
            var overflowY = window.getComputedStyle(node).overflowY;
            if (overflowY === 'auto' || overflowY === 'scroll') {
                list.push(node);
            }
        }
        return list;
    }

    function setStatic(item) {
        if (item.state === 'static') {
            return;
        }
        var style = item.bar.style;
        style.position = '';
        style.top = '';
        style.left = '';
        style.width = '';
        if (item.placeholder.parentNode) {
            item.placeholder.parentNode.removeChild(item.placeholder);
        }
        item.state = 'static';
        item.top = item.left = item.width = null;
    }

    function setFixed(item, top, left, width, height) {
        var style = item.bar.style;
        if (item.state !== 'fixed') {
            // The placeholder takes the bar's place in the flow only while the bar is fixed, so the table does not jump
            // (and it adds no gap of its own in a flex/grid parent while the bar is static)
            item.placeholder.style.height = height + 'px';
            item.bar.parentNode.insertBefore(item.placeholder, item.bar);
            style.position = 'fixed';
            item.state = 'fixed';
        }
        if (item.top !== top) {
            style.top = top + 'px';
            item.top = top;
        }
        if (item.left !== left) {
            style.left = left + 'px';
            item.left = left;
        }
        if (item.width !== width) {
            style.width = width + 'px';
            item.width = width;
        }
    }

    function updateSticky() {
        ticking = false;

        var offset = getStickyOffset();

        // read everything first, then write (no layout thrash)
        var reads = stickyItems.map(function (item) {
            var read = {
                // where the bar naturally is: the bar itself while static, its placeholder while fixed
                placeholder: (item.state === 'fixed' ? item.placeholder : item.bar).getBoundingClientRect(),
                table: item.table.getBoundingClientRect(),
                height: item.bar.offsetHeight,
                limitTop: offset,
                limitBottom: Infinity
            };

            // A table inside a box that scrolls on its own (e.g. the price board) sticks to that box, not to the window
            item.scrollers.forEach(function (box) {
                if (box.scrollHeight > box.clientHeight + 1) {
                    var rect = box.getBoundingClientRect();
                    read.limitTop = Math.max(read.limitTop, rect.top);
                    read.limitBottom = Math.min(read.limitBottom, rect.bottom);
                }
            });

            return read;
        });

        stickyItems.forEach(function (item, i) {
            var read = reads[i];

            // Not displayed (e.g. in an inactive tab): nothing to stick
            if (!read.table.height) {
                setStatic(item);
                return;
            }

            // read.placeholder is the bar's natural position (see above)
            if (read.placeholder.top > read.limitTop) {
                setStatic(item);
                return;
            }

            // the bar stays on top of the table's last row once the table ends
            var top = Math.min(read.limitTop, Math.min(read.table.bottom, read.limitBottom) - read.height);
            setFixed(item, top, read.placeholder.left, read.placeholder.width, read.height);
        });
    }

    function requestUpdate() {
        if (!ticking) {
            ticking = true;
            window.requestAnimationFrame(updateSticky);
        }
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

            var bar = table.previousElementSibling;
            if (!bar || !bar.classList.contains('tp-vat-header')) {
                bar = buildBar(mode);
                table.parentNode.insertBefore(bar, table);
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

            if (table.closest('.' + NO_STICKY_CLASS)) {
                return;
            }

            var placeholder = document.createElement('div');
            placeholder.className = 'tp-sticky-placeholder';

            stickyItems.push({
                table: table,
                bar: bar,
                placeholder: placeholder,
                scrollers: scrollingAncestors(table),
                state: 'static',
                top: null,
                left: null,
                width: null
            });
        });

        if (stickyItems.length) {
            // capture: a scroll inside a box (not only the window's) also moves the bar
            window.addEventListener('scroll', requestUpdate, { passive: true, capture: true });
            window.addEventListener('resize', requestUpdate);

            // a table that gets shown or resized (tab switch, late layout) is measured again at once
            if (window.ResizeObserver) {
                var observer = new ResizeObserver(requestUpdate);
                stickyItems.forEach(function (item) {
                    observer.observe(item.table);
                });
            }

            updateSticky();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
