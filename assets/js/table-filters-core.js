/**
 * Price tables: reading the filter values out of cell text. Shared by the front end (table-filters.js) and the table
 * screen of the admin (admin-filters.js) so the live preview shows exactly what visitors get. No DOM work here.
 *
 * A filter item (from PostType::filters_config) reads its value from the cell text with `source`:
 * - cell:   the whole cell
 * - number: the n-th number in the text ("... عرض 10 سانتی متر" -> 10)
 * - after:  the first number / word after the given `word` ("عرض 10" -> 10)
 * A cell without the value gives null (the row does not match any choice of that filter).
 */
(function (root) {
    'use strict';

    var collator = (typeof Intl !== 'undefined' && Intl.Collator) ? new Intl.Collator('fa', { numeric: true }) : null;
    var NUMBER = '\\d+(?:[.٫]\\d+)?';

    function toEnglishDigits(str) {
        return String(str).replace(/[۰-۹]/g, function (ch) {
            return ch.charCodeAt(0) - 0x06F0;
        }).replace(/[٠-٩]/g, function (ch) {
            return ch.charCodeAt(0) - 0x0660;
        });
    }

    // One character in, one character out: indexes found in the folded text are valid in the original text
    function fold(str) {
        return toEnglishDigits(str).replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/[\u200c\u200f\u200e]/g, ' ').toLowerCase();
    }

    // Same text for "ي/ی", "ك/ک", Persian/Arabic/Latin digits, half-spaces and extra spaces
    function norm(str) {
        return fold(String(str)).replace(/\s+/g, ' ').trim();
    }

    function cellNumber(str) {
        var text = toEnglishDigits(str).trim().replace(/[,٬،]/g, '').replace(/٫/g, '.');
        return /^\d+(\.\d+)?$/.test(text) ? parseFloat(text) : NaN;
    }

    function compare(a, b) {
        return collator ? collator.compare(a, b) : (a < b ? -1 : (a > b ? 1 : 0));
    }

    /**
     * @param {Object} item Filter item.
     * @param {string} text Cell text.
     * @return {{key: string, label: string}|null}
     */
    function pick(item, text) {
        var raw = String(text == null ? '' : text);
        var folded = fold(raw);
        var found = '';
        var match;

        if (item.source === 'number') {
            var re = new RegExp(NUMBER, 'g');
            var wanted = parseInt(item.n, 10) || 1;
            var seen = 0;

            while ((match = re.exec(folded))) {
                seen++;
                if (seen === wanted) {
                    found = raw.substr(match.index, match[0].length);
                    break;
                }
            }
        } else if (item.source === 'after') {
            var word = fold(String(item.word || '')).trim();
            var at = word ? folded.indexOf(word) : -1;

            if (at !== -1) {
                var from = at + word.length;
                var rest = folded.slice(from);
                var next = new RegExp('^[\\s:：=\\-–]*(' + NUMBER + '|[^\\s\\d:：=,،.؛;()\\-–]+)').exec(rest);

                if (next) {
                    found = raw.substr(from + next[0].length - next[1].length, next[1].length);
                }
            }
        } else {
            found = raw;
        }

        found = found.trim();
        if (!found) {
            return null;
        }

        return { key: norm(found), label: item.suffix ? found + ' ' + item.suffix : found };
    }

    /**
     * The distinct values of a filter over the texts of its column, in the order set on the item.
     *
     * @param {Object} item Filter item.
     * @param {string[]} texts Cell texts of the column.
     * @return {{key: string, label: string}[]}
     */
    function collect(item, texts) {
        var seen = {};
        var list = [];

        texts.forEach(function (text) {
            var value = pick(item, text);
            if (value && !seen[value.key]) {
                seen[value.key] = true;
                list.push(value);
            }
        });

        if (item.order !== 'appear') {
            list.sort(function (a, b) {
                return compare(a.key, b.key);
            });
        }

        return list;
    }

    root.pgcoTf = { norm: norm, cellNumber: cellNumber, compare: compare, pick: pick, collect: collect };
})(window);
