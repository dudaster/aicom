/* AICOM - AI Commander for WordPress — Admin JS */
(function ($) {
    'use strict';

    $(function () {

        // ── Copy buttons ───────────────────────────────────────────────────
        $(document).on('click', '.aicom-copy-btn', function () {
            var text = $(this).data('target') || $(this).siblings('.aicom-copy-input').val();
            if (!text) return;

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    showCopySuccess();
                });
            } else {
                // Fallback for older browsers
                var $tmp = $('<textarea>').val(text).css({ position: 'fixed', top: 0, left: 0, opacity: 0 });
                $('body').append($tmp);
                $tmp.select();
                document.execCommand('copy');
                $tmp.remove();
                showCopySuccess();
            }

            function showCopySuccess() {
                var $btn = this instanceof $ ? this : $(document.activeElement);
                var original = $btn.text();
                $btn.text('Copied!').addClass('button-primary');
                setTimeout(function () {
                    $btn.text(original).removeClass('button-primary');
                }, 1500);
            }
        });

        // ── Hard lock disables soft lock checkbox ──────────────────────────
        var $hardLock = $('#aicom-hard-lock, #aicom-hard-lock-toggle');
        var $softLock = $('#aicom-soft-lock');

        function syncLockState() {
            if ($hardLock.is(':checked')) {
                $softLock.prop('disabled', true).prop('checked', false);
            } else {
                $softLock.prop('disabled', false);
            }
        }

        $hardLock.on('change', syncLockState);
        syncLockState();

        // ── Custom date range for audit log period selector ────────────────
        var $periodSelect = $('#aicom-period-select');
        var $customRange  = $('#aicom-custom-range');

        function syncPeriod() {
            if ($periodSelect.val() === 'custom') {
                $customRange.show();
            } else {
                $customRange.hide();
            }
        }

        $periodSelect.on('change', syncPeriod);
        syncPeriod();

        // ── Scope "Check all" toggle ───────────────────────────────────────
        var $checkAll = $('#aicom-scope-check-all');
        var $scopeCbs = $('.aicom-scope-cb');

        $checkAll.on('change', function () {
            $scopeCbs.prop('checked', $(this).is(':checked'));
        });

        $scopeCbs.on('change', function () {
            $checkAll.prop('checked', $scopeCbs.length === $scopeCbs.filter(':checked').length);
        });

        // ── Auto-select plain key text on focus ────────────────────────────
        $('#aicom-plain-key').on('focus click', function () {
            $(this).select();
        });

    });

    // ── Admin bar: suspend / unsuspend (outside DOM-ready, works on toolbar) ──
    $(document).on('click', '.aicom-tb-btn', function (e) {
        e.preventDefault();
        e.stopPropagation();

        var $btn = $(this);
        if ($btn.data('loading')) { return; }

        var keyId  = $btn.data('key-id');
        var action = $btn.data('action');
        var nonce  = $btn.data('nonce');
        var ajaxUrl = (typeof AICOM_MCP !== 'undefined' && AICOM_MCP.ajaxUrl) || ajaxurl;

        $btn.data('loading', true).text('...');

        $.post(ajaxUrl, {
            action:       'aicom_toolbar_toggle',
            key_id:       keyId,
            aicom_action: action,
            nonce:        nonce
        }, function (resp) {
            if (resp.success) {
                var d    = resp.data;
                var $li  = $('#wp-admin-bar-aicom-key-' + keyId);
                var $cnt = $('#wp-admin-bar-aicom-toolbar .aicom-tb-count');

                $li.find('.aicom-tb-dot').text(d.new_dot);
                $btn
                    .text(d.new_btn_label)
                    .removeClass('aicom-tb-btn-suspend aicom-tb-btn-unsuspend')
                    .addClass(d.new_btn_class)
                    .data('action', d.new_action)
                    .data('nonce',  d.new_nonce);

                // Actualizează badge-ul verde din titlu
                var delta = (action === 'suspend_key') ? -1 : 1;
                var cur   = parseInt($cnt.text(), 10) || 0;
                var next  = cur + delta;
                if (next <= 0) {
                    $cnt.remove();
                } else {
                    if ($cnt.length) {
                        $cnt.text(next);
                    } else {
                        $('#wp-admin-bar-aicom-toolbar > .ab-item > .ab-label')
                            .after('<span class="aicom-tb-count">' + next + '</span>');
                    }
                }
            }
            $btn.data('loading', false);
        });
    });

}(jQuery));
