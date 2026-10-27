/**
 * Price tables on a narrow screen: the columns that are not chosen to stay visible (pgco-col-more, hidden by
 * frontend.css) open in a row under the table row when it is tapped.
 *
 * The pgco-tables shortcode marks the columns on the server. A plain TablePress table (the fallback) has no marks, so
 * they are added here: the first three columns and the last one stay visible, like the default of pgco-tables.
 * The row with the folded columns is built on the first tap, not on page load.
 */
(function () {
    'use strict';

    var MOBILE = window.matchMedia('(max-width: 767px)');
    var DEFAULT_VISIBLE = 3;

    // ===== TablePress tables: mark the columns =====
    function markTablePress(table) {
        var headRow = table.querySelector('thead tr');
        if (!headRow || table.classList.contains('pgco-table--collapsible')) {
            return;
        }

        var heads = Array.prototype.slice.call(headRow.children);
        if (heads.length <= DEFAULT_VISIBLE + 1) {
            return;
        }

        var last = heads.length - 1;
        var labels = heads.map(function (th) {
            return th.textContent.trim();
        });

        function mark(cell, index, withLabel) {
            if (index < DEFAULT_VISIBLE || index === last) {
                if (index === 0) {
                    cell.classList.add('pgco-col-first');
                }
                return;
            }
            cell.classList.add('pgco-col-more');
            if (withLabel) {
                cell.setAttribute('data-label', labels[index] || '');
            }
        }

        heads.forEach(function (th, index) {
            mark(th, index, false);
        });
        table.querySelectorAll('tbody > tr').forEach(function (row) {
            Array.prototype.forEach.call(row.children, function (cell, index) {
                mark(cell, index, true);
            });
        });

        table.classList.add('pgco-table--collapsible');
    }

    // ===== the row with the folded columns =====
    function buildDetail(row) {
        var detail = document.createElement('tr');
        detail.className = 'pgco-detail';

        var cell = document.createElement('td');
        var list = document.createElement('dl');
        var shown = 0;

        Array.prototype.forEach.call(row.children, function (td) {
            if (td.classList.contains('pgco-col-more')) {
                var term = document.createElement('dt');
                term.textContent = td.getAttribute('data-label') || '';
                var value = document.createElement('dd');
                value.textContent = td.textContent.trim();
                list.appendChild(term);
                list.appendChild(value);
            } else {
                shown++;
            }
        });

        cell.colSpan = shown || 1;
        cell.appendChild(list);
        detail.appendChild(cell);
        row.parentNode.insertBefore(detail, row.nextSibling);

        return detail;
    }

    function toggle(row) {
        var detail = row.nextElementSibling;
        if (!detail || !detail.classList.contains('pgco-detail')) {
            detail = buildDetail(row);
        }

        var open = row.classList.toggle('is-open');
        detail.classList.toggle('is-open', open);
        row.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    document.addEventListener('click', function (event) {
        if (!MOBILE.matches || !event.target.closest) {
            return;
        }

        var row = event.target.closest('table.pgco-table--collapsible > tbody > tr');
        if (!row || row.classList.contains('pgco-detail') || event.target.closest('a, button, input, label')) {
            return;
        }

        toggle(row);
    });

    function init() {
        document.querySelectorAll('table.tablepress').forEach(markTablePress);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
