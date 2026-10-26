(function ($) {
    'use strict';

    var i18n = window.pgcoTablesAdmin || {};

    function ajaxUrl() {
        return (i18n.ajaxUrl) || (window.ajaxurl) || '/wp-admin/admin-ajax.php';
    }

    /**
     * Sync a single table post via AJAX.
     * Works both on the post-edit screen (.pgco-tables-sync-box) and on
     * the "All Tables" list screen (row status/last-synced cells).
     */
    function syncSingle($button) {
        var postId = $button.data('post-id');
        var nonce = $button.data('nonce');
        var $box = $button.closest('.pgco-tables-sync-box');
        var $result = $box.find('.pgco-tables-sync-result');
        var $statusCell = $('#pgco-tables-status-cell-' + postId);
        var $row = $button.closest('tr');

        $button.prop('disabled', true);
        var originalText = $button.text();
        $button.text(i18n.syncing || '...در حال بروزرسانی');

        $result.hide().removeClass('notice-success notice-error');

        $.post(ajaxUrl(), {
            action: 'pgco_tables_sync_single',
            post_id: postId,
            nonce: nonce
        }).done(function (response) {
            var data = response && response.data ? response.data : {};
            var message = data.message || '';
            var badgeClass = response.success ? 'pgco-tables-status-success' : 'pgco-tables-status-error';
            var badgeText = response.success ? (i18n.success || 'موفق') : (i18n.failed || 'ناموفق');

            if ($result.length) {
                $result
                    .addClass(response.success ? 'notice notice-success' : 'notice notice-error')
                    .text(message)
                    .show();
            }

            if ($statusCell.length) {
                $statusCell.html('<span class="pgco-tables-status-badge ' + badgeClass + '">' + badgeText + '</span>');
            }

            if (response.success && $row.length) {
                $row.find('.column-last_synced').text(i18n.justNow || 'همین الان');
            }
        }).fail(function () {
            if ($result.length) {
                $result.addClass('notice notice-error').text(i18n.networkError || 'خطا در ارتباط با سرور.').show();
            }
            if ($statusCell.length) {
                $statusCell.html('<span class="pgco-tables-status-badge pgco-tables-status-error">' + (i18n.failed || 'ناموفق') + '</span>');
            }
        }).always(function () {
            $button.prop('disabled', false).text(originalText);
        });
    }

    function badge(success) {
        var cls = success ? 'pgco-tables-status-success' : 'pgco-tables-status-error';
        var text = success ? (i18n.success || 'موفق') : (i18n.failed || 'ناموفق');
        return '<span class="pgco-tables-status-badge ' + cls + '">' + text + '</span>';
    }

    /**
     * Sync tables one after the other: one request per table, so a long list never hits the server's time limit
     * and Google's per-minute quota gets a pause between tables.
     *
     * @param {string[]} ids   table post IDs
     * @param {string}   nonce
     * @param {jQuery}   $progress element that shows "n / total"
     * @param {jQuery}   $list     <ul> that gets one line per table
     * @param {Function} done      called after the last table
     */
    function syncQueue(ids, nonce, $progress, $list, done) {
        var delay = 400;

        function next(index) {
            if (index >= ids.length) {
                done();
                return;
            }

            var postId = ids[index];
            $progress.text((index + 1) + ' / ' + ids.length);

            var $li = $('<li></li>');

            function report(success, title, message) {
                $li.html(
                    '<strong>' + $('<div>').text(title || ('#' + postId)).html() + '</strong> — ' +
                    badge(success) + ' — ' + $('<div>').text(message || '').html()
                );
                $list.append($li);

                var $statusCell = $('#pgco-tables-status-cell-' + postId);
                if ($statusCell.length) {
                    $statusCell.html(badge(success));
                    if (success) {
                        $statusCell.closest('tr').find('.column-last_synced').text(i18n.justNow || 'همین الان');
                    }
                }
            }

            $.post(ajaxUrl(), {
                action: 'pgco_tables_sync_single',
                post_id: postId,
                nonce: nonce
            }).done(function (response) {
                var data = response && response.data ? response.data : {};
                report(!!(response && response.success), data.title, data.message);
            }).fail(function () {
                report(false, '', i18n.networkError || 'خطا در ارتباط با سرور.');
            }).always(function () {
                setTimeout(function () {
                    next(index + 1);
                }, delay);
            });
        }

        next(0);
    }

    /**
     * Sync all table posts (overview page).
     */
    function syncAll($button) {
        var ids = String($button.data('ids') || '').split(',').filter(Boolean);
        var $spinner = $('.pgco-tables-sync-all-spinner');
        var $result = $('.pgco-tables-sync-all-result');

        $result.empty();

        if (!ids.length) {
            $result.append($('<div class="notice notice-warning"></div>').text(i18n.noTables || 'جدولی برای بروزرسانی وجود ندارد.'));
            return;
        }

        var $progress = $('<p></p>');
        var $list = $('<ul></ul>');
        $result.append($progress).append($list);

        $button.prop('disabled', true);
        $spinner.addClass('is-active');

        syncQueue(ids, $button.data('nonce'), $progress, $list, function () {
            $button.prop('disabled', false);
            $spinner.removeClass('is-active');
            $progress.replaceWith(
                $('<div class="notice notice-success"></div>').text(i18n.bulkDone || 'بروزرسانی دسته‌جمعی انجام شد.')
            );
        });
    }

    /**
     * Bulk action of the Tables list ("sync from Google Sheets"): the selected tables are synced one by one with
     * the same requests as the single button, instead of one long request that the server would cut off.
     */
    function syncSelected(ids) {
        var $apply = $('#doaction, #doaction2');
        var $notice = $('<div class="notice notice-info pgco-tables-sync-all-result"></div>');
        var $progress = $('<p></p>');
        var $list = $('<ul></ul>');

        $notice.append($progress).append($list);
        $('.pgco-tables-bulk-notice').remove();
        $notice.addClass('pgco-tables-bulk-notice');
        $('.wp-header-end').first().after($notice);

        $apply.prop('disabled', true);

        syncQueue(ids, i18n.syncNonce, $progress, $list, function () {
            $apply.prop('disabled', false);
            $progress.text(i18n.bulkDone || 'بروزرسانی دسته‌جمعی انجام شد.');
            $notice.removeClass('notice-info').addClass('notice-success');
        });
    }

    /**
     * Copy the sheet URL to clipboard when the copy icon is clicked.
     */
    function copySheetUrl($button) {
        var url = $button.data('url');
        var originalHtml = $button.html();

        function showCopied() {
            $button.html('<span class="dashicons dashicons-yes" style="font-size:14px; width:14px; height:14px; vertical-align:middle;"></span>');
            setTimeout(function () {
                $button.html(originalHtml);
            }, 1200);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(showCopied).catch(function () {
                fallbackCopy(url, showCopied);
            });
        } else {
            fallbackCopy(url, showCopied);
        }
    }

    function fallbackCopy(text, done) {
        var $temp = $('<textarea>').val(text).css({ position: 'fixed', opacity: 0 }).appendTo('body');
        $temp[0].select();
        try {
            document.execCommand('copy');
        } catch (e) {
            // ignore
        }
        $temp.remove();
        done();
    }

    $(document).ready(function () {
        $(document).on('click', '.pgco-tables-sync-single', function (e) {
            e.preventDefault();
            syncSingle($(this));
        });

        $(document).on('click', '.pgco-tables-sync-all', function (e) {
            e.preventDefault();
            syncAll($(this));
        });

        $('#posts-filter').on('submit', function (e) {
            var action = $('select[name="action"]').val();
            if (action === '-1') {
                action = $('select[name="action2"]').val();
            }
            if (action !== 'pgco_tables_sync') {
                return;
            }

            e.preventDefault();

            var ids = $('input[name="post[]"]:checked').map(function () {
                return this.value;
            }).get();

            if (ids.length) {
                syncSelected(ids);
            }
        });

        $(document).on('click', '.pgco-tables-copy-url', function (e) {
            e.preventDefault();
            copySheetUrl($(this));
        });
    });
})(jQuery)