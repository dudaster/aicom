<?php
defined( 'ABSPATH' ) || exit;

( function () {
$lock_state = AICOM_Lock_Manager::get_state();
$stats      = AICOM_Audit_Logger::get_stats_today();
$mcp_url    = get_site_url() . '/wp-json/aicom/v1/mcp';
$fallback    = get_site_url() . '/?aicom=1';

if ( $lock_state['effective_lock'] === 'hard_locked' ) {
    $badge_class = 'aicom-badge-danger';
    $badge_label = __( 'Hard Lock Active', 'aicom' );
} elseif ( $lock_state['effective_lock'] === 'soft_locked' ) {
    $badge_class = 'aicom-badge-warning';
    $badge_label = __( 'Soft Lock Active', 'aicom' );
} else {
    $badge_class = 'aicom-badge-success';
    $badge_label = __( 'Unlocked', 'aicom' );
}

$success_pct = $stats['total_today'] > 0
    ? round( $stats['success_today'] / $stats['total_today'] * 100 )
    : 0;
?>
<?php include AICOM_DIR . 'admin/partials/layout-top.php'; ?>

    <div class="aicom-page-header">
        <h1><?php esc_html_e( 'Dashboard', 'aicom' ); ?></h1>
    </div>

    <?php if ( ! empty( $_GET['onboarded'] ) ) : ?>
    <div class="aicom-onboarding-banner" id="aicom-onboarding-banner">
        <div class="aicom-onboarding-banner-mark" aria-hidden="true">✓</div>
        <div class="aicom-onboarding-banner-text">
            <strong><?php esc_html_e( 'Your first key is ready.', 'aicom' ); ?></strong>
            <span><?php esc_html_e( "Open your AI client, paste the snippet from the modal, and try a sentence like \"list my last five published posts.\" Activity will appear in the card below as soon as your agent gets to work.", 'aicom' ); ?></span>
        </div>
        <button type="button" class="aicom-onboarding-banner-close" aria-label="<?php esc_attr_e( 'Dismiss', 'aicom' ); ?>" onclick="this.closest('.aicom-onboarding-banner').remove();">&times;</button>
    </div>
    <?php endif; ?>

    <!-- Row 0: Task-oriented quick start (mirrors Hub /aicomhub-keys Quick Start) -->
    <?php
    $tasks      = AICOM_Admin::task_presets();
    $scope_flat = AICOM_Auth::scope_flat();

    // Inline SVG icons — line style, currentColor so CSS can recolor.
    $task_icons = [
        'pencil' => '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>',
        'search' => '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/></svg>',
        'tag'    => '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><circle cx="7" cy="7" r="1.5" fill="currentColor"/></svg>',
        'eye'    => '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>',
        'image'  => '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>',
        'cart'   => '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>',
        'layout' => '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>',
    ];
    ?>
    <div class="aicom-card aicom-quick-keys">
        <div class="aicom-card-head">
            <h2 class="aicom-card-title"><?php esc_html_e( 'What kind of power do you want to give your AI Agent?', 'aicom' ); ?></h2>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=aicom-api-keys' ) ); ?>" class="aicom-card-link"><?php esc_html_e( 'More options →', 'aicom' ); ?></a>
        </div>
        <div class="aicom-card-body">
            <p class="aicom-quick-keys-lede"><?php esc_html_e( 'Each card describes a role your AI assistant could play on this site. Pick the one that fits and we will mint a key with the right permissions. You still review and confirm before it is created.', 'aicom' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aicom-quick-keys-form" id="aicom-quick-form">
                <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                <input type="hidden" name="action" value="aicom_save">
                <input type="hidden" name="aicom_action" value="create_from_task">
                <input type="hidden" name="task" id="aicom-quick-preset-input" value="">
                <div class="aicom-task-grid">
                    <?php foreach ( $tasks as $slug => $t ) :
                        // Build friendly scope labels for the confirm modal.
                        $scope_labels = [];
                        foreach ( $t['scopes'] as $scope_slug ) {
                            $entry = $scope_flat[ $scope_slug ] ?? null;
                            $scope_labels[] = [
                                'slug'  => $scope_slug,
                                'label' => $entry ? $entry[0] : $scope_slug,
                                'risk'  => $entry ? $entry[1] : 'low',
                            ];
                        }
                        $icon_svg = $task_icons[ $t['icon'] ] ?? '';
                    ?>
                        <button type="button"
                                class="aicom-task-card"
                                data-preset="<?php echo esc_attr( $slug ); ?>"
                                data-preset-name="<?php echo esc_attr( $t['name'] ); ?>"
                                data-preset-risk="med"
                                data-scopes="<?php echo esc_attr( wp_json_encode( $scope_labels ) ); ?>">
                            <span class="aicom-task-icon" aria-hidden="true"><?php echo $icon_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG ?></span>
                            <span class="aicom-task-name"><?php echo esc_html( $t['name'] ); ?></span>
                            <span class="aicom-task-desc"><?php echo esc_html( $t['desc'] ); ?></span>
                            <span class="aicom-task-meta">
                                <?php
                                /* translators: %d: number of permissions */
                                printf( esc_html( _n( '%d permission', '%d permissions', count( $t['scopes'] ), 'aicom' ) ), count( $t['scopes'] ) );
                                if ( ! empty( $t['dry_run'] ) ) {
                                    echo ' · <span class="aicom-task-meta-tag">' . esc_html__( 'dry-run', 'aicom' ) . '</span>';
                                }
                                ?>
                            </span>
                            <span class="aicom-task-cta"><?php esc_html_e( 'use this →', 'aicom' ); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <p class="aicom-task-footer">
                    <?php esc_html_e( 'Nothing here matches what you need?', 'aicom' ); ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=aicom-api-keys' ) ); ?>"><?php esc_html_e( 'Build a custom key →', 'aicom' ); ?></a>
                </p>
            </form>
        </div>
    </div>

    <!-- Confirm modal (hidden until a preset is clicked) -->
    <div id="aicom-quick-confirm-overlay" class="aicom-confirm-overlay" hidden>
        <div class="aicom-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="aicom-confirm-title">
            <button type="button" class="aicom-confirm-close" id="aicom-confirm-close" aria-label="<?php esc_attr_e( 'Close', 'aicom' ); ?>">&times;</button>
            <h3 id="aicom-confirm-title"><?php esc_html_e( 'You\'re about to create a key with:', 'aicom' ); ?></h3>
            <p class="aicom-confirm-sub">
                <span class="aicom-confirm-sub-label"><?php esc_html_e( 'Preset', 'aicom' ); ?></span>
                <strong id="aicom-confirm-preset-name"></strong>
                <span class="aicom-risk-badge" id="aicom-confirm-preset-risk"></span>
            </p>
            <p class="aicom-confirm-scopes-label"><?php esc_html_e( 'Scopes included', 'aicom' ); ?></p>
            <ul class="aicom-confirm-scopes" id="aicom-confirm-scopes"></ul>
            <div class="aicom-confirm-actions">
                <button type="button" class="button" id="aicom-confirm-cancel"><?php esc_html_e( 'Cancel', 'aicom' ); ?></button>
                <button type="button" class="button button-primary" id="aicom-confirm-ok"><?php esc_html_e( 'Create key', 'aicom' ); ?></button>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var overlay  = document.getElementById('aicom-quick-confirm-overlay');
        var nameEl   = document.getElementById('aicom-confirm-preset-name');
        var riskEl   = document.getElementById('aicom-confirm-preset-risk');
        var listEl   = document.getElementById('aicom-confirm-scopes');
        var input    = document.getElementById('aicom-quick-preset-input');
        var form     = document.getElementById('aicom-quick-form');
        var cancelBtn = document.getElementById('aicom-confirm-cancel');
        var closeBtn  = document.getElementById('aicom-confirm-close');
        var okBtn     = document.getElementById('aicom-confirm-ok');

        function hide() {
            overlay.hidden = true;
            document.removeEventListener('keydown', onEsc);
        }
        function show() {
            overlay.hidden = false;
            document.addEventListener('keydown', onEsc);
            setTimeout(function () { okBtn.focus(); }, 30);
        }
        function onEsc(e) { if (e.key === 'Escape') hide(); }

        document.querySelectorAll('.aicom-quick-keys-form .aicom-task-card, .aicom-quick-keys-form .aicom-preset-card').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var slug   = btn.dataset.preset;
                var name   = btn.dataset.presetName;
                var risk   = btn.dataset.presetRisk;
                var scopes = [];
                try { scopes = JSON.parse(btn.dataset.scopes || '[]'); } catch (e) {}

                input.value = slug;
                nameEl.textContent = name;
                riskEl.textContent = risk.toUpperCase();
                riskEl.className = 'aicom-risk-badge aicom-risk-' + risk;
                listEl.innerHTML = scopes.map(function (s) {
                    return '<li>' +
                        '<span class="aicom-confirm-scope-label">' + s.label + '</span>' +
                        '<span class="aicom-confirm-scope-slug"><code>' + s.slug + '</code></span>' +
                        '<span class="aicom-risk-badge aicom-risk-' + s.risk + '">' + s.risk.toUpperCase() + '</span>' +
                    '</li>';
                }).join('');
                show();
            });
        });

        if (overlay) overlay.addEventListener('click', function (e) { if (e.target === overlay) hide(); });
        cancelBtn.addEventListener('click', hide);
        closeBtn.addEventListener('click', hide);
        okBtn.addEventListener('click', function () { form.submit(); });
    })();
    </script>

    <!-- Row 1: Stats + Status + Lock -->
    <div class="aicom-dashboard-grid">

        <!-- Today's Stats -->
        <div class="aicom-card">
            <div class="aicom-card-head">
                <h2 class="aicom-card-title"><?php esc_html_e( "Today's Activity", 'aicom' ); ?></h2>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=aicom-audit-logs' ) ); ?>" class="aicom-card-link"><?php esc_html_e( 'View log →', 'aicom' ); ?></a>
            </div>
            <div class="aicom-card-body">
                <div class="aicom-stats-grid">
                    <div class="aicom-stat">
                        <span class="aicom-stat-number"><?php echo esc_html( $stats['total_today'] ); ?></span>
                        <span class="aicom-stat-label"><?php esc_html_e( 'Total', 'aicom' ); ?></span>
                    </div>
                    <div class="aicom-stat">
                        <span class="aicom-stat-number aicom-success"><?php echo esc_html( $stats['success_today'] ); ?></span>
                        <span class="aicom-stat-label"><?php esc_html_e( 'Success', 'aicom' ); ?></span>
                    </div>
                    <div class="aicom-stat">
                        <span class="aicom-stat-number aicom-error"><?php echo esc_html( $stats['errors_today'] ); ?></span>
                        <span class="aicom-stat-label"><?php esc_html_e( 'Errors', 'aicom' ); ?></span>
                    </div>
                    <div class="aicom-stat">
                        <span class="aicom-stat-number aicom-warning"><?php echo esc_html( $stats['blocked_today'] ); ?></span>
                        <span class="aicom-stat-label"><?php esc_html_e( 'Blocked', 'aicom' ); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Lock Controls -->
        <div class="aicom-card">
            <div class="aicom-card-head">
                <h2 class="aicom-card-title"><?php esc_html_e( 'Lock Controls', 'aicom' ); ?></h2>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=aicom-safety' ) ); ?>" class="aicom-card-link"><?php esc_html_e( 'Details →', 'aicom' ); ?></a>
            </div>
            <div class="aicom-card-body">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="aicom_save" />
                    <input type="hidden" name="aicom_action" value="set_lock" />
                    <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                    <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:16px">
                        <label class="aicom-toggle-label">
                            <input type="checkbox" name="soft_lock" value="1"
                                   <?php checked( $lock_state['soft_lock'] ); ?>
                                   <?php disabled( $lock_state['hard_lock'] ); ?> />
                            <?php esc_html_e( 'Soft Lock', 'aicom' ); ?>
                            <span style="font-size:0.79em;color:var(--aicom-text-xs)"><?php esc_html_e( '(blocks write/destructive)', 'aicom' ); ?></span>
                        </label>
                        <label class="aicom-toggle-label">
                            <input type="checkbox" name="hard_lock" value="1"
                                   <?php checked( $lock_state['hard_lock'] ); ?> />
                            <?php esc_html_e( 'Hard Lock', 'aicom' ); ?>
                            <span style="font-size:0.79em;color:var(--aicom-text-xs)"><?php esc_html_e( '(allows only server.status)', 'aicom' ); ?></span>
                        </label>
                        <?php if ( $lock_state['hard_lock'] ) : ?>
                        <p style="margin:0;font-size:0.8em;color:var(--aicom-warning-text);font-style:italic"><?php esc_html_e( 'Hard Lock overrides Soft Lock.', 'aicom' ); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php submit_button( __( 'Apply', 'aicom' ), 'primary small', 'submit', false ); ?>
                </form>
                <p style="font-size:0.82em;color:var(--aicom-text-sm);margin:14px 0 0;line-height:1.55">
                    <strong><?php esc_html_e( 'Soft Lock', 'aicom' ); ?></strong> <?php esc_html_e( 'blocks all write and delete tools — agents can only read.', 'aicom' ); ?><br>
                    <strong><?php esc_html_e( 'Hard Lock', 'aicom' ); ?></strong> <?php esc_html_e( 'stops everything, except', 'aicom' ); ?> <code>server.status</code>.
                </p>
            </div>
        </div>

    </div><!-- .aicom-dashboard-grid -->

    <!-- Row 3: Active Modules -->
    <div class="aicom-card">
        <div class="aicom-card-head">
            <h2 class="aicom-card-title"><?php esc_html_e( 'Active Modules', 'aicom' ); ?></h2>
            <span style="font-size:0.79em;color:var(--aicom-text-xs)">
                <?php
                $active_count = count( array_filter( AICOM_Module_Detector::get_module_status_map(), fn( $s ) => str_starts_with( $s, 'active' ) ) );
                /* translators: %d: number of active modules */
                printf( esc_html( _n( '%d active', '%d active', $active_count, 'aicom' ) ), $active_count );
                ?>
            </span>
        </div>
        <div class="aicom-card-body">
            <div class="aicom-modules-row">
                <?php foreach ( AICOM_Module_Detector::get_module_status_map() as $module => $status ) : ?>
                <div class="aicom-module-pill <?php echo esc_attr( str_starts_with( $status, 'active' ) ? 'aicom-module-active' : 'aicom-module-inactive' ); ?>">
                    <span class="aicom-dot"></span>
                    <?php echo esc_html( ucfirst( str_replace( '_', ' ', $module ) ) ); ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

<?php include AICOM_DIR . 'admin/partials/layout-bottom.php'; ?>
<?php
} )();
