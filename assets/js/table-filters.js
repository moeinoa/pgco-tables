/**
 * Price tables: filter dropdowns in the bar above each table (after the VAT checkbox, before the date).
 *
 * UI: one "فیلترها" button in the bar (with the number of active filters); the dropdowns live in a panel that opens under
 * the bar, whatever their number. The panel stays open while filters are changed; outside click or Esc closes it.
 *
 * Server: Shortcode.php prints data-pgco-filters='{"items":[...],"group":"","empty":{"on":true,"text":"..."}}' on the table, built
 * from the filters set in the table's settings (PostType::filters_config). A "filter" item becomes a single-choice dropdown whose
 * options are the distinct values read from the rows (table-filters-core.js); the "sort" item (the price column) becomes a
 * cheapest / most expensive dropdown. A filter is shown only when the tables that share it (the group, or the table alone)
 * have at least two distinct values together: two tables that each hold one value, but a different one, still get the dropdown.
 *
 * Rules:
 * - Tables of one page that have the same "group" name share one state: a dropdown is shared by its title, and its options are
 *   the values of all the tables in the group. A table without a group works on its own. Groups combine with AND.
 * - A row that does not match gets the `hidden` attribute and `pgco-tf-hide` (the print copy in tax-tables.js keeps them hidden);
 *   the folded-columns row of the mobile layout (.pgco-detail) follows its row.
 * - A table with no matching row is hidden (together with its parent <section>, e.g. the title, when that section holds only this table) only while another table of its group shows rows AND that table has the dropdowns
 *   that caused it; the first visible table of the group then shows a notice with a clear button, so it is always reversible.
 *   Otherwise the table stays (header + dropdowns) with a "nothing found" box (clear button) above it.
 * - The result stays in sight: the control the visitor used keeps its place on the screen; if it disappears with its table the page
 *   scrolls to the first table that is left (see change()).
 * - Zebra rows (nth-child counts hidden rows) are redone with a class while some rows are hidden.
 *
 * Needs the bar built by tax-tables.js (this file is enqueued after it). TablePress fallback tables have no attribute and are skipped.
 */
(function () {
    'use strict';

    var tf = window.pgcoTf;

    if (!tf) {
        return;
    }

    function detailOf(row) {
        var next = row.nextElementSibling;
        return next && next.classList.contains('pgco-detail') ? next : null;
    }

    function setHidden(el, hide) {
        el.hidden = hide;
        el.classList.toggle('pgco-tf-hide', hide);
    }

    function option(value, text) {
        var el = document.createElement('option');
        el.value = value;
        el.textContent = text;
        return el;
    }

    // The key two tables share a dropdown by: its title (the sort dropdown has its own namespace)
    function keyOf(item) {
        return (item.type === 'sort' ? 'sort:' : '') + tf.norm(item.label);
    }

    function build(table) {
        var config;
        try {
            config = JSON.parse(table.getAttribute('data-pgco-filters'));
        } catch (e) {
            return null;
        }

        var block = table.closest('.tp-table-block');
        var bar = block ? block.querySelector('.tp-vat-header') : null;
        var tbody = table.querySelector('tbody');

        if (!config || !config.items || !bar || !tbody || bar.hasAttribute('data-tf-ready')) {
            return null;
        }

        var rows = [];
        Array.prototype.forEach.call(tbody.children, function (row) {
            if (row.tagName === 'TR' && !row.classList.contains('pgco-detail')) {
                rows.push({
                    el: row,
                    index: rows.length,
                    keys: [],
                    texts: Array.prototype.map.call(row.children, function (cell) {
                        return cell.textContent;
                    })
                });
            }
        });

        if (rows.length < 2) {
            return null;
        }

        var fields = [];
        var keys = {};
        config.items.forEach(function (item, id) {
            var field = { id: id, key: keyOf(item), item: item, type: item.type, values: [] };

            if (item.type === 'filter') {
                field.values = tf.collect(item, rows.map(function (row) {
                    return row.texts[item.col] || '';
                }));
                if (!field.values.length || !field.key) {
                    return; // the "at least two values" rule is checked per group (prune), not per table
                }
                rows.forEach(function (row) {
                    var value = tf.pick(item, row.texts[item.col] || '');
                    row.keys[id] = value ? value.key : '';
                });
            }

            keys[field.key] = true;
            fields.push(field);
        });

        if (!fields.length) {
            return null;
        }

        // What is hidden when the table has no result: the section that holds only this table (with its title), else the table block
        var section = block.closest('section');
        var wrap = section && section.querySelectorAll('.tp-table-block').length === 1 ? section : block;

        return {
            table: table, block: block, wrap: wrap, bar: bar, tbody: tbody, rows: rows, fields: fields, keys: keys,
            empty: config.empty || {}, group: tf.norm(config.group || ''), box: null, sorted: false, shown: rows.length
        };
    }

    // ===== sets: the tables that share one state =====
    function buildSets(models) {
        var byGroup = {};
        var sets = [];

        models.forEach(function (model) {
            var set = model.group ? byGroup[model.group] : null;

            if (!set) {
                set = { models: [], state: {} };
                sets.push(set);
                if (model.group) {
                    byGroup[model.group] = set;
                }
            }

            set.models.push(model);
            model.set = set;
        });

        return sets;
    }

    // A dropdown shared by several tables lists the values of all of them
    function sharedValues(set, field) {
        var seen = {};
        var list = [];

        set.models.forEach(function (model) {
            model.fields.forEach(function (other) {
                if (other.key === field.key) {
                    other.values.forEach(function (value) {
                        if (!seen[value.key]) {
                            seen[value.key] = true;
                            list.push(value);
                        }
                    });
                }
            });
        });

        if (field.item.order !== 'appear') {
            list.sort(function (a, b) {
                return tf.compare(a.key, b.key);
            });
        }

        return list;
    }

    // Drops the filters that would offer fewer than two choices over the whole group, then the tables left with no dropdown
    function prune(set) {
        var enough = {};

        set.models.forEach(function (model) {
            model.fields.forEach(function (field) {
                if (field.type === 'sort' || sharedValues(set, field).length >= 2) {
                    enough[field.key] = true;
                }
            });
        });

        set.models.forEach(function (model) {
            model.fields = model.fields.filter(function (field) {
                return enough[field.key];
            });
            model.keys = {};
            model.fields.forEach(function (field) {
                model.keys[field.key] = true;
            });
        });

        set.models = set.models.filter(function (model) {
            return model.fields.length > 0;
        });
    }

    function buildSelect(set, field) {
        var item = field.item;
        var select = document.createElement('select');
        select.setAttribute('aria-label', item.label);

        select.appendChild(option('', item.label + ': ' + item.all));
        if (field.type === 'sort') {
            select.appendChild(option('asc', item.label + ': ' + item.asc));
            select.appendChild(option('desc', item.label + ': ' + item.desc));
            if (item['default'] && !set.state[field.key]) {
                set.state[field.key] = item['default'];
            }
        } else {
            sharedValues(set, field).forEach(function (value) {
                select.appendChild(option(value.key, value.label));
            });
        }

        select.addEventListener('change', function () {
            change(set, select, function () {
                if (select.value) {
                    set.state[field.key] = select.value;
                } else {
                    delete set.state[field.key];
                }
            });
        });

        field.select = select;
        return select;
    }

    // ===== the "فیلترها" button + the panel under the bar =====
    var panelCount = 0;
    var models = [];

    function setOpen(model, open) {
        model.ui.panel.hidden = !open;
        model.ui.toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function closeAll(except) {
        models.forEach(function (model) {
            if (model !== except && !model.ui.panel.hidden) {
                setOpen(model, false);
            }
        });
    }

    function buildUi(model) {
        var wrap = document.createElement('div');
        wrap.className = 'pgco-tf';

        var panel = document.createElement('div');
        panel.className = 'pgco-tf-panel';
        panel.id = 'pgco-tf-panel-' + (++panelCount);
        panel.setAttribute('role', 'group');
        panel.setAttribute('aria-label', 'فیلترها');
        panel.hidden = true;

        model.fields.forEach(function (field) {
            panel.appendChild(buildSelect(model.set, field));
        });

        var clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'pgco-tf-clear';
        clear.textContent = 'پاک کردن فیلترها';
        clear.addEventListener('click', function () {
            change(model.set, clear, function () {
                model.set.state = {};
            });
        });
        panel.appendChild(clear);

        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'pgco-tf-toggle';
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-controls', panel.id);
        toggle.appendChild(document.createTextNode('فیلترها'));

        var count = document.createElement('span');
        count.className = 'pgco-tf-count';
        count.hidden = true;
        toggle.appendChild(count);

        toggle.addEventListener('click', function () {
            var open = panel.hidden;
            closeAll(model);
            setOpen(model, open);
        });

        wrap.appendChild(toggle);
        wrap.appendChild(panel);

        var label = model.bar.querySelector('.tp-vat-label');
        model.bar.insertBefore(wrap, label ? label.nextSibling : model.bar.firstChild);
        model.bar.classList.add('pgco-tf-on');
        model.bar.setAttribute('data-tf-ready', '');
        model.ui = { wrap: wrap, toggle: toggle, count: count, panel: panel };
    }

    // ===== message box above a table ("nothing found" / "n tables hidden") =====
    function setBox(model, set, message) {
        if (!message) {
            if (model.box) {
                model.box.hidden = true;
            }
            return;
        }

        if (!model.box) {
            var box = document.createElement('div');
            box.className = 'pgco-tf-empty';
            box.setAttribute('role', 'status');

            var text = document.createElement('span');
            var button = document.createElement('button');
            button.type = 'button';
            button.textContent = 'پاک کردن فیلترها';
            button.addEventListener('click', function () {
                change(set, button, function () {
                    set.state = {};
                });
            });

            box.appendChild(text);
            box.appendChild(button);
            model.block.parentNode.insertBefore(box, model.block);
            model.box = box;
        }

        model.box.firstChild.textContent = message;
        model.box.hidden = false;
    }

    // ===== keep the result in sight =====
    // Changing a filter can hide or show whole tables above the control the visitor just used. The control stays where it
    // was on the screen; if it is gone (its table was hidden, or the notice box was cleared) the page scrolls to the first
    // table that is left (its section), at the offset of the sticky VAT bar.
    function change(set, anchor, mutate) {
        var before = anchor.getBoundingClientRect().top;

        mutate();
        apply(set);

        if (anchor.getClientRects().length) {
            var delta = anchor.getBoundingClientRect().top - before;

            if (Math.abs(delta) > 1) {
                window.scrollBy({ top: delta, left: 0, behavior: 'instant' });
            }
            return;
        }

        var host = set.host;
        if (!host) {
            return;
        }

        var target = host.wrap; // the parent <section> (title + table + notice), not the table itself
        var offset = parseFloat(window.getComputedStyle(host.bar).top) || 0; // the same offset the sticky VAT bar keeps (64 / 81 / 115)
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        window.scrollTo({
            top: Math.max(0, target.getBoundingClientRect().top + window.pageYOffset - offset),
            left: 0,
            behavior: reduce ? 'auto' : 'smooth'
        });
    }

    // ===== apply =====
    function matches(model, row) {
        var state = model.set.state;

        return model.fields.every(function (field) {
            var wanted = state[field.key];
            return field.type !== 'filter' || !wanted || row.keys[field.id] === wanted;
        });
    }

    function sortRows(model) {
        var field = model.fields.filter(function (f) {
            return f.type === 'sort';
        })[0];
        var dir = field ? model.set.state[field.key] : '';

        if (!dir && !model.sorted) {
            return; // never sorted: the DOM is still in the original order
        }
        model.sorted = !!dir;

        var ordered = model.rows.slice();

        if (dir) {
            var col = field.item.col;
            var price = function (row) {
                return tf.cellNumber(row.el.children[col] ? row.el.children[col].textContent : ''); // live text: the VAT toggle rewrites it
            };

            ordered.sort(function (a, b) {
                var x = price(a);
                var y = price(b);

                if (isNaN(x) || isNaN(y)) {
                    return (isNaN(x) ? 1 : 0) - (isNaN(y) ? 1 : 0) || a.index - b.index; // not a number ("تماس بگیرید") goes last
                }
                return (dir === 'asc' ? x - y : y - x) || a.index - b.index;
            });
        }

        // read every detail row first: moving a row would break the "next sibling" link
        var pairs = ordered.map(function (row) {
            return [row.el, detailOf(row.el)];
        });
        pairs.forEach(function (pair) {
            model.tbody.appendChild(pair[0]);
            if (pair[1]) {
                model.tbody.appendChild(pair[1]);
            }
        });
    }

    function applyRows(model) {
        var shown = 0;

        var active = 0;

        model.fields.forEach(function (field) {
            var value = model.set.state[field.key] || '';
            field.select.value = value;
            field.select.classList.toggle('is-active', !!value && field.type === 'filter');
            if (value) {
                active++;
            }
        });

        model.ui.count.textContent = active.toLocaleString('fa-IR');
        model.ui.count.hidden = !active;
        model.ui.toggle.classList.toggle('is-active', active > 0);

        model.rows.forEach(function (row) {
            var ok = matches(model, row);
            var detail = detailOf(row.el);

            setHidden(row.el, !ok);
            if (detail) {
                setHidden(detail, !ok);
            }
            row.el.classList.toggle('pgco-tf-alt', ok && shown % 2 === 1);
            if (ok) {
                shown++;
            }
        });

        model.shown = shown;
        model.table.classList.toggle('pgco-tf-striped', shown < model.rows.length);
        sortRows(model);
    }

    function activeKeys(model) {
        return Object.keys(model.keys).filter(function (key) {
            return model.set.state[key] && key.indexOf('sort:') !== 0;
        });
    }

    function apply(set) {
        set.models.forEach(applyRows);

        var visible = set.models.filter(function (model) {
            return model.shown > 0;
        });
        var empty = set.models.filter(function (model) {
            return model.shown === 0;
        });
        var keep;

        if (!visible.length) {
            keep = empty.slice(0, 1); // all empty: the first table stays with its bar
        } else {
            // an empty table stays when no visible table offers the dropdowns that cause its emptiness
            keep = empty.filter(function (model) {
                return !activeKeys(model).every(function (key) {
                    return visible.some(function (other) {
                        return other.keys[key];
                    });
                });
            });
        }

        var hidden = 0;
        var host = null; // first table of the set that is on the page: it carries the "n tables hidden" notice

        set.models.forEach(function (model) {
            var gone = model.shown === 0 && keep.indexOf(model) === -1;

            setHidden(model.wrap, gone);
            if (gone) {
                setOpen(model, false);
            }
            if (gone) {
                hidden++;
            } else if (!host) {
                host = model;
            }
        });

        set.host = host;

        set.models.forEach(function (model) {
            var parts = [];

            if (model.shown === 0 && keep.indexOf(model) !== -1 && model.empty.on !== false) {
                parts.push(model.empty.text || 'موردی پیدا نشد');
            }
            if (model === host && hidden > 0) {
                parts.push(hidden.toLocaleString('fa-IR') + ' جدول بدون نتیجه پنهان شد');
            }

            setBox(model, set, parts.join(' — '));
            model.table.dispatchEvent(new CustomEvent('pgco:table-filtered', { bubbles: true, detail: { shown: model.shown } }));
        });
    }

    function init() {
        var built = [];

        document.querySelectorAll('table.pgco-table[data-pgco-filters]').forEach(function (table) {
            var model = build(table);
            if (model) {
                built.push(model);
            }
        });

        var sets = buildSets(built);
        sets.forEach(prune);
        sets = sets.filter(function (set) {
            return set.models.length > 0;
        });

        // shared list (closeAll): only the tables that kept at least one dropdown
        models = [];
        sets.forEach(function (set) {
            models = models.concat(set.models);
        });

        sets.forEach(function (set) {
            set.models.forEach(buildUi);
            apply(set);
        });

        if (models.length) {
            // outside click / Esc close the panel
            document.addEventListener('click', function (event) {
                models.forEach(function (model) {
                    if (!model.ui.panel.hidden && !model.ui.wrap.contains(event.target)) {
                        setOpen(model, false);
                    }
                });
            });
            document.addEventListener('keydown', function (event) {
                if (event.key !== 'Escape') {
                    return;
                }
                models.forEach(function (model) {
                    if (!model.ui.panel.hidden) {
                        setOpen(model, false);
                        model.ui.toggle.focus();
                    }
                });
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();