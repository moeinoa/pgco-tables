/**
 * Table screen (admin): the "table filters" box. Adds / removes / reorders the filter cards, shows only the fields a card
 * needs (filter vs. price sort, how the value is read) and draws a live preview bar from the cached table with the same
 * value-reading code as the site (table-filters-core.js). Form values are plain inputs named options[filters][i][...];
 * the PHP side (PostType::save) stores them in the order they have on the screen.
 */
(function () {
    'use strict';

    var tf = window.pgcoTf;
    var root = document.querySelector('.pgco-tf-admin');

    if (!tf || !root) {
        return;
    }

    var head = [];
    var body = [];
    try {
        head = JSON.parse(root.getAttribute('data-head')) || [];
        body = JSON.parse(root.getAttribute('data-body')) || [];
    } catch (e) {
        return;
    }

    var last = head.length - 1;
    var list = root.querySelector('.pgco-tf-rows');
    var template = root.querySelector('.pgco-tf-template');
    var bar = root.querySelector('.pgco-tf-preview__bar');
    var next = parseInt(root.getAttribute('data-next'), 10) || 0;
    var groupInput = root.querySelector('[name="options[filters-group]"]');

    function field(row, name) {
        return row.querySelector('[data-f="' + name + '"]');
    }

    function read(row) {
        var col = parseInt(field(row, 'col').value, 10) || 0;
        var get = function (name) {
            var el = field(row, name);
            return el ? el.value.trim() : '';
        };
        var isSort = col === last;

        return {
            enabled: field(row, 'enabled').checked,
            col: col,
            type: isSort ? 'sort' : 'filter',
            label: get('label') || head[col] || '',
            all: get('all') || (isSort ? 'پیش‌فرض' : 'همه'),
            asc: get('asc') || 'ارزان‌ترین',
            desc: get('desc') || 'گران‌ترین',
            source: get('source') || 'cell',
            n: parseInt(get('n'), 10) || 1,
            word: get('word'),
            order: get('order'),
            suffix: get('suffix')
        };
    }

    function values(item) {
        return tf.collect(item, body.map(function (cells) {
            return cells[item.col] || '';
        }));
    }

    function option(value, text) {
        var el = document.createElement('option');
        el.value = value;
        el.textContent = text;
        return el;
    }

    function refresh() {
        bar.textContent = '';

        Array.prototype.forEach.call(list.children, function (row) {
            var item = read(row);
            var info = row.querySelector('.pgco-tf-row__info');
            var count = 0;
            var grouped = !!(groupInput && groupInput.value.trim());

            row.setAttribute('data-type', item.type);
            row.setAttribute('data-source', item.source);
            row.querySelector('.pgco-tf-row__title').textContent = item.label + (item.type === 'sort' ? ' (مرتب‌سازی)' : '');

            var select = document.createElement('select');
            select.appendChild(option('', item.label + ': ' + item.all));

            if (item.type === 'sort') {
                select.appendChild(option('asc', item.label + ': ' + item.asc));
                select.appendChild(option('desc', item.label + ': ' + item.desc));
                info.textContent = '';
                info.classList.remove('is-warn');
            } else {
                var found = values(item);
                count = found.length;
                found.forEach(function (value) {
                    select.appendChild(option(value.key, value.label));
                });

                info.textContent = count + ' مقدار';
                info.classList.toggle('is-warn', count < 2 || count > 40);
                if (count < 2) {
                    info.textContent += grouped
                        ? ' — فقط اگر جدول هم‌گروه مقدار دیگری داشته باشد نمایش داده می‌شود'
                        : ' — در سایت نمایش داده نمی‌شود';
                } else if (count > 40) {
                    info.textContent += ' — زیاد است';
                }
            }

            row.classList.toggle('is-off', !item.enabled);

            if (item.enabled && (item.type === 'sort' || count > 1 || (count === 1 && grouped))) {
                bar.appendChild(select);
            }
        });

        if (!bar.children.length) {
            var none = document.createElement('em');
            none.textContent = 'فیلتر فعالی نیست';
            bar.appendChild(none);
        }
    }

    function add() {
        var html = template.innerHTML.replace(/__i__/g, String(next++));
        var holder = document.createElement('div');
        holder.innerHTML = html;
        var row = holder.firstElementChild;

        // the first column that is not the price is the most likely one
        field(row, 'col').value = String(Math.min(1, Math.max(last - 1, 0)));
        list.appendChild(row);
        refresh();
        field(row, 'col').focus();
    }

    root.querySelector('.pgco-tf-add').addEventListener('click', add);

    list.addEventListener('click', function (event) {
        var button = event.target.closest('[data-act]');
        var row = button && button.closest('.pgco-tf-row');

        if (!row) {
            return;
        }

        event.preventDefault();

        switch (button.getAttribute('data-act')) {
            case 'toggle':
                row.classList.toggle('is-open');
                break;
            case 'up':
                if (row.previousElementSibling) {
                    list.insertBefore(row, row.previousElementSibling);
                }
                break;
            case 'down':
                if (row.nextElementSibling) {
                    list.insertBefore(row.nextElementSibling, row);
                }
                break;
            case 'remove':
                row.remove();
                break;
        }

        refresh();
    });

    if (groupInput) {
        groupInput.addEventListener('input', refresh);
    }

    list.addEventListener('input', refresh);
    list.addEventListener('change', refresh);

    refresh();
})();