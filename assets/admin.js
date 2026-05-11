/* AICOM - AI Commander for WordPress — Admin JS */
(function ($) {
    'use strict';

    $(function () {

        // ── Tab switching ──────────────────────────────────────────────────
        var $tabBar = $('#aicom-tab-bar');
        if ( $tabBar.length ) {
            var tabStoreKey = 'aicom_tab_api_keys';
            var defaultTab  = $tabBar.data('default') || localStorage.getItem( tabStoreKey ) || 'generate';

            var activateTab = function ( tab ) {
                $('.aicom-tab-btn').removeClass('is-active').filter('[data-tab="' + tab + '"]').addClass('is-active');
                $('.aicom-tab-panel').removeClass('is-active').filter('#aicom-tab-' + tab).addClass('is-active');
            };

            activateTab( defaultTab );

            $(document).on('click', '.aicom-tab-btn', function () {
                var tab = $(this).data('tab');
                activateTab( tab );
                localStorage.setItem( tabStoreKey, tab );
            });
        }

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

        // ── Kebab actions menu ──────────────────────────────────────────────
        $(document).on('click', '.aicom-km-trigger', function (e) {
            e.stopPropagation();
            var $menu = $(this).closest('.aicom-km');
            var wasOpen = $menu.hasClass('is-open');
            $('.aicom-km.is-open').removeClass('is-open');
            if ( !wasOpen ) {
                $menu.addClass('is-open');
                // Position the fixed dropdown relative to the trigger's viewport rect
                var rect      = $menu[0].getBoundingClientRect();
                var $drop     = $menu.find('.aicom-km-dropdown');
                var dropH     = $drop.outerHeight(true) || 180;
                var spaceBelow = window.innerHeight - rect.bottom;
                if ( spaceBelow < dropH + 8 ) {
                    // Open upward
                    $drop.css({ top: 'auto', bottom: (window.innerHeight - rect.top + 4) + 'px', right: (window.innerWidth - rect.right) + 'px' });
                } else {
                    $drop.css({ top: (rect.bottom + 4) + 'px', bottom: 'auto', right: (window.innerWidth - rect.right) + 'px' });
                }
            }
        });
        // Close on outside click
        $(document).on('click', function () {
            $('.aicom-km.is-open').removeClass('is-open').find('.aicom-km-dropdown').css({ top: '', bottom: '', right: '' });
        });
        // Keep open when clicking inside dropdown (until form submit)
        $(document).on('click', '.aicom-km-dropdown', function (e) {
            e.stopPropagation();
        });

        // ── Collapsible Connection Info card ───────────────────────────────
        var $connCard  = $('#aicom-conn-card');
        if ( $connCard.length ) {
            var connKey      = 'aicom_conn_collapsed';
            var connCollapsed = localStorage.getItem( connKey ) !== 'no'; // default collapsed
            $connCard.toggleClass( 'is-collapsed', connCollapsed );

            $('#aicom-conn-toggle').on( 'click', function () {
                $connCard.toggleClass( 'is-collapsed' );
                localStorage.setItem( connKey, $connCard.hasClass( 'is-collapsed' ) ? 'yes' : 'no' );
            });
        }

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

        // ── Preset picker ───────────────────────────────────────────────────
        $(document).on('click', '.aicom-preset-card', function () {
            var $card     = $(this);
            var scopesRaw = $card.data('scopes');
            var scopes    = Array.isArray(scopesRaw) ? scopesRaw : JSON.parse(scopesRaw || '[]');

            $('.aicom-preset-card').removeClass('is-active');
            $card.addClass('is-active');

            $('.aicom-scope-cb').prop('checked', false);
            $.each(scopes, function (i, s) {
                $('input[name="scopes[]"][value="' + s + '"]').prop('checked', true);
            });
            updateGroupCounts();
        });

        // ── Scope tree group collapse ───────────────────────────────────────
        $(document).on('click', '.aicom-tree-group-header', function () {
            $(this).closest('.aicom-tree-group').toggleClass('is-collapsed');
        });

        // ── Manual scope change → activate Custom card ─────────────────────
        $(document).on('change', '.aicom-scope-cb', function () {
            $('.aicom-preset-card').removeClass('is-active');
            $('.aicom-preset-card[data-preset="custom"]').addClass('is-active');
            updateGroupCounts();
        });

        // ── Scope search filter ─────────────────────────────────────────────
        $('#aicom-scope-search').on('input', function () {
            var q = $(this).val().toLowerCase().trim();
            $('#aicom-scope-tree .aicom-tree-scope-row').each(function () {
                var text = $(this).text().toLowerCase();
                $(this).toggleClass('is-hidden', q !== '' && text.indexOf(q) === -1);
            });
            if (q) {
                $('#aicom-scope-tree .aicom-tree-group').each(function () {
                    var hasVisible = $(this).find('.aicom-tree-scope-row:not(.is-hidden)').length > 0;
                    $(this).toggleClass('is-collapsed', !hasVisible);
                });
            }
        });

        // ── Edit form: scope search ─────────────────────────────────────────
        $('#aicom-scope-search-edit').on('input', function () {
            var q = $(this).val().toLowerCase().trim();
            $('#aicom-scope-tree-edit .aicom-tree-scope-row').each(function () {
                var text = $(this).text().toLowerCase();
                $(this).toggleClass('is-hidden', q !== '' && text.indexOf(q) === -1);
            });
            if (q) {
                $('#aicom-scope-tree-edit .aicom-tree-group').each(function () {
                    var hasVisible = $(this).find('.aicom-tree-scope-row:not(.is-hidden)').length > 0;
                    $(this).toggleClass('is-collapsed', !hasVisible);
                });
            }
        });

        // ── Edit form: live scope diff ──────────────────────────────────────
        var $editForm = $('#aicom-edit-key-form');
        if ($editForm.length) {
            var originalScopes = JSON.parse($editForm.data('original-scopes') || '[]');

            $editForm.on('change', '.aicom-scope-cb', function () {
                var current = $editForm.find('.aicom-scope-cb:checked').map(function () {
                    return this.value;
                }).get();
                var added   = current.filter(function (s) { return originalScopes.indexOf(s) === -1; });
                var removed = originalScopes.filter(function (s) { return current.indexOf(s) === -1; });
                var parts   = [];
                if (added.length)   { parts.push('<span class="aicom-diff-added">+' + added.join(' +') + '</span>'); }
                if (removed.length) { parts.push('<span class="aicom-diff-removed">−' + removed.join(' −') + '</span>'); }
                var $diff = $('#aicom-scope-diff');
                $diff.html(parts.length ? parts.join('&nbsp;&nbsp;') : '<em style="color:#888">No changes</em>').show();
                updateGroupCounts();
            });
        }

        function updateGroupCounts() {
            $('.aicom-tree-group').each(function () {
                var total   = $(this).find('.aicom-scope-cb').length;
                var checked = $(this).find('.aicom-scope-cb:checked').length;
                var $cnt    = $(this).find('.aicom-tree-group-count');
                $cnt.text( checked > 0 ? checked + '/' + total + ' selected' : '' );
            });
        }
        updateGroupCounts();

        // ── Save as preset ──────────────────────────────────────────────────
        var $saveBtn     = $('#aicom-save-preset-btn');
        var $saveForm    = $('#aicom-save-preset-form');
        var $nameInput   = $('#aicom-preset-name-input');
        var $saveConfirm = $('#aicom-preset-save-confirm');
        var $saveCancel  = $('#aicom-preset-save-cancel');
        var ajaxUrl      = (typeof AICOM_MCP !== 'undefined' && AICOM_MCP.ajaxUrl) || ajaxurl;
        var nonce        = (typeof AICOM_MCP !== 'undefined' && AICOM_MCP.nonce) || '';

        $saveBtn.on('click', function () {
            $saveForm.show();
            $nameInput.val('').trigger('focus');
            $saveBtn.hide();
        });

        $saveCancel.on('click', function () {
            $saveForm.hide();
            $saveBtn.show();
        });

        $saveConfirm.on('click', function () {
            var name = $nameInput.val().trim();
            if ( ! name ) { $nameInput.trigger('focus'); return; }

            var checkedScopes = $('.aicom-scope-cb:checked').map(function () {
                return this.value;
            }).get();
            if ( ! checkedScopes.length ) {
                alert('Select at least one scope before saving a preset.');
                return;
            }

            $saveConfirm.prop('disabled', true).text('Saving…');

            var data = { action: 'aicom_save_preset', nonce: nonce, name: name };
            $.each(checkedScopes, function (i, s) { data['scopes[' + i + ']'] = s; });

            $.post(ajaxUrl, data, function (resp) {
                $saveConfirm.prop('disabled', false).text('Save');
                if ( resp.success ) {
                    var d = resp.data;
                    var scopeCount = d.scopes.length;
                    var $newCard = $(
                        '<button type="button" class="aicom-preset-card aicom-preset-user"' +
                        ' data-preset="user-' + d.id + '"' +
                        ' data-scopes=\'' + JSON.stringify(d.scopes) + '\'' +
                        ' data-preset-id="' + d.id + '">' +
                        '<span class="aicom-preset-name">' + $('<span>').text(d.name).html() + '</span>' +
                        '<span class="aicom-preset-count">' + scopeCount + ' scope' + (scopeCount !== 1 ? 's' : '') + '</span>' +
                        '<span class="aicom-preset-user-badge">Custom</span>' +
                        '<span class="aicom-preset-delete" data-preset-id="' + d.id + '" title="Delete preset" role="button" tabindex="0">&#10005;</span>' +
                        '</button>'
                    );
                    // Insert before the "Custom" card
                    $('.aicom-preset-custom').before($newCard);
                    $saveForm.hide();
                    $saveBtn.show();
                    $nameInput.val('');
                } else {
                    alert('Could not save preset: ' + (resp.data || 'unknown error'));
                }
            });
        });

        $nameInput.on('keydown', function (e) {
            if ( e.key === 'Enter' ) { e.preventDefault(); $saveConfirm.trigger('click'); }
            if ( e.key === 'Escape' ) { $saveCancel.trigger('click'); }
        });

        // ── Rename custom preset ───────────────────────────────────────────
        $(document).on('click', '.aicom-preset-rename', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var $span    = $(this);
            var presetId = $span.data('preset-id');
            var $card    = $span.closest('.aicom-preset-card');
            var cur      = $card.find('.aicom-preset-name').text();
            var name     = prompt( 'Rename preset:', cur );
            if ( !name || name === cur ) { return; }
            $.post( ajaxUrl, {
                action: 'aicom_rename_preset',
                nonce:  nonce,
                id:     presetId,
                name:   name
            }, function (r) {
                if ( r.success ) { $card.find('.aicom-preset-name').text( r.data.name ); }
            });
        });

        // ── Duplicate custom preset ────────────────────────────────────────
        $(document).on('click', '.aicom-preset-duplicate', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var presetId = $(this).data('preset-id');
            $.post( ajaxUrl, {
                action: 'aicom_duplicate_preset',
                nonce:  nonce,
                id:     presetId
            }, function (r) {
                if ( !r.success ) { return; }
                var d    = r.data;
                var $new = $('<button type="button"></button>')
                    .addClass('aicom-preset-card aicom-preset-user')
                    .attr('data-preset', 'user-' + d.id)
                    .attr('data-scopes', JSON.stringify(d.scopes))
                    .attr('data-preset-id', d.id)
                    .html(
                        '<span class="aicom-preset-name">' + $('<span>').text(d.name).html() + '</span>' +
                        '<span class="aicom-preset-count">' + d.count + ' scopes</span>' +
                        '<span class="aicom-preset-user-badge">Custom</span>' +
                        '<span class="aicom-preset-rename" data-preset-id="' + d.id + '" title="Rename preset" role="button" tabindex="0">&#9999;</span>' +
                        '<span class="aicom-preset-duplicate" data-preset-id="' + d.id + '" title="Duplicate preset" role="button" tabindex="0">&#10064;</span>' +
                        '<span class="aicom-preset-delete" data-preset-id="' + d.id + '" title="Delete preset" role="button" tabindex="0">&#10005;</span>'
                    );
                $('.aicom-preset-custom').before($new);
            });
        });

        // ── Delete custom preset ────────────────────────────────────────────
        $(document).on('click', '.aicom-preset-delete', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var $btn      = $(this);
            var presetId  = $btn.data('preset-id');
            var $card     = $btn.closest('.aicom-preset-card');
            var presetName = $card.find('.aicom-preset-name').text();

            if ( ! confirm('Delete preset "' + presetName + '"?') ) { return; }

            $.post(ajaxUrl, {
                action:    'aicom_delete_preset',
                nonce:     nonce,
                preset_id: presetId
            }, function (resp) {
                if ( resp.success ) {
                    if ( $card.hasClass('is-active') ) {
                        $('.aicom-preset-custom').trigger('click');
                    }
                    $card.remove();
                }
            });
        });

        // ── Sessions: graph bar click → server-side filter by log date ────────
        $(document).on('click', '.aicom-graph-bar', function () {
            var $bar = $(this);
            if ( $bar.hasClass('is-empty') ) { return; }
            var url = $bar.data('url');
            if ( url ) { window.location.href = url; }
        });

        // ── Sessions: expand/collapse session card ─────────────────────────
        $(document).on('click', '.aicom-session-head', function (e) {
            if ( $(e.target).closest('button, a, form').length ) { return; }
            $(this).closest('.aicom-session').toggleClass('is-open');
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

    // ── Resource Restrictions collapsible ────────────────────────────────
    $(document).on('click', '.aicom-res-header', function () {
        $(this).closest('.aicom-res-section').toggleClass('is-open');
    });

    function aicomResUpdateField( $textarea ) {
        var $field      = $textarea.closest('.aicom-res-field');
        var $status     = $field.find('.aicom-res-status');
        var isFilePaths = $textarea.attr('name') === 'res_file_paths';
        var hasVal      = $textarea.val().trim() !== '';
        $field.toggleClass('has-value', hasVal).toggleClass('is-warn-empty', !hasVal && isFilePaths);
        if ( hasVal ) {
            $status.removeClass('is-unset is-blocked').addClass('is-set')
                   .text( isFilePaths ? 'Paths set' : 'Active' );
        } else if ( isFilePaths ) {
            $status.removeClass('is-set is-unset').addClass('is-blocked').text('All blocked');
        } else {
            $status.removeClass('is-set is-blocked').addClass('is-unset').text('Inactive');
        }
    }

    $(document).on('input', '.aicom-res-field textarea', function () {
        aicomResUpdateField( $(this) );
    });

    // ── Toolbar lock buttons ──────────────────────────────────────────────
    $(document).on( 'click', '.aicom-tb-lock-btn', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $btn    = $(this);
        var mode    = $btn.data('lock');
        var nonce   = $btn.data('nonce');
        var lockUrl = (typeof AICOM_MCP !== 'undefined' && AICOM_MCP.ajaxUrl) || ajaxurl;
        $.post( lockUrl, { action: 'aicom_toolbar_lock', lock_mode: mode, nonce: nonce }, function (res) {
            if ( ! res.success ) { return; }
            var status = res.data.lock_status;
            // Update active state on buttons.
            var modeMap = { unlocked: 'unlock', soft_locked: 'soft', hard_locked: 'hard' };
            $('.aicom-tb-lock-btn').each( function () {
                $(this).toggleClass( 'is-active', $(this).data('lock') === modeMap[status] );
                $(this).data( 'nonce', res.data.new_nonce );
            });
            // Update toolbar background class.
            var $tb = $('#wp-admin-bar-aicom-toolbar');
            $tb.removeClass('aicom-tb-hardlocked aicom-tb-softlocked');
            if ( status === 'hard_locked' ) { $tb.addClass('aicom-tb-hardlocked'); }
            if ( status === 'soft_locked' ) { $tb.addClass('aicom-tb-softlocked'); }
        });
    });

    // Scroll to highlighted session (from backup snapshots link).
    var $hl = $('.aicom-session.is-highlighted');
    if ( $hl.length ) {
        setTimeout( function() {
            $hl[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 150 );
    }

}(jQuery));
