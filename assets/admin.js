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

}(jQuery));
