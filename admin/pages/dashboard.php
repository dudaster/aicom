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
        <p class="aicom-page-desc">AICOM - AI Commander for WordPress &nbsp;&mdash;&nbsp; v<?php echo esc_html( AICOM_VERSION ); ?></p>
    </div>

    <!-- Row 0: Quick API key generation (first 4 presets, low/med risk) -->
    <?php
    $quick_presets = array_slice( AICOM_Admin::system_presets(), 0, 4, true );
    $scope_flat    = AICOM_Auth::scope_flat();
    ?>
    <div class="aicom-card aicom-quick-keys">
        <div class="aicom-card-head">
            <h2 class="aicom-card-title"><?php esc_html_e( 'Quick API key generation', 'aicom' ); ?></h2>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=aicom-api-keys' ) ); ?>" class="aicom-card-link"><?php esc_html_e( 'More options →', 'aicom' ); ?></a>
        </div>
        <div class="aicom-card-body">
            <p class="aicom-quick-keys-lede"><?php esc_html_e( 'One click generates a new key with the right scopes already ticked. The label takes the preset name; we add a number if you already have one.', 'aicom' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aicom-quick-keys-form" id="aicom-quick-form">
                <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                <input type="hidden" name="action" value="aicom_save">
                <input type="hidden" name="aicom_action" value="quick_create_key">
                <input type="hidden" name="preset" id="aicom-quick-preset-input" value="">
                <div class="aicom-preset-grid">
                    <?php foreach ( $quick_presets as $slug => $p ) :
                        // Build friendly scope labels for the confirm modal.
                        $scope_labels = [];
                        foreach ( $p['scopes'] as $scope_slug ) {
                            $entry = $scope_flat[ $scope_slug ] ?? null;
                            $scope_labels[] = [
                                'slug'  => $scope_slug,
                                'label' => $entry ? $entry[0] : $scope_slug,
                                'risk'  => $entry ? $entry[1] : 'low',
                            ];
                        }
                    ?>
                        <button type="button"
                                class="aicom-preset-card aicom-preset-risk-<?php echo esc_attr( $p['risk'] ); ?>"
                                data-preset="<?php echo esc_attr( $slug ); ?>"
                                data-preset-name="<?php echo esc_attr( $p['name'] ); ?>"
                                data-preset-risk="<?php echo esc_attr( $p['risk'] ); ?>"
                                data-scopes="<?php echo esc_attr( wp_json_encode( $scope_labels ) ); ?>">
                            <span class="aicom-preset-name"><?php echo esc_html( $p['name'] ); ?></span>
                            <span class="aicom-preset-desc"><?php echo esc_html( $p['desc'] ); ?></span>
                            <span class="aicom-preset-count">
                                <?php
                                /* translators: %d: number of scopes */
                                printf( esc_html( _n( '%d scope', '%d scopes', count( $p['scopes'] ), 'aicom' ) ), count( $p['scopes'] ) );
                                ?>
                            </span>
                            <span class="aicom-risk-badge aicom-risk-<?php echo esc_attr( $p['risk'] ); ?>"><?php echo esc_html( strtoupper( $p['risk'] ) ); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
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

        document.querySelectorAll('.aicom-quick-keys-form .aicom-preset-card').forEach(function (btn) {
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

        <!-- Server Status -->
        <div class="aicom-card">
            <div class="aicom-card-head">
                <h2 class="aicom-card-title"><?php esc_html_e( 'Server Status', 'aicom' ); ?></h2>
                <span class="aicom-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_label ); ?></span>
            </div>
            <div class="aicom-card-body">
                <div class="aicom-info-rows">
                    <div class="aicom-info-row">
                        <span class="aicom-info-label"><?php esc_html_e( 'WordPress', 'aicom' ); ?></span>
                        <span class="aicom-info-value"><?php echo esc_html( get_bloginfo( 'version' ) ); ?></span>
                    </div>
                    <div class="aicom-info-row">
                        <span class="aicom-info-label"><?php esc_html_e( 'AICOM Plugin', 'aicom' ); ?></span>
                        <span class="aicom-info-value"><?php echo esc_html( AICOM_VERSION ); ?></span>
                    </div>
                    <div class="aicom-info-row">
                        <span class="aicom-info-label"><?php esc_html_e( 'PHP', 'aicom' ); ?></span>
                        <span class="aicom-info-value"><?php echo esc_html( PHP_VERSION ); ?></span>
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

    <!-- Row 2: MCP Endpoint — full width so URLs are always readable -->
    <div class="aicom-card">
        <div class="aicom-card-head">
            <h2 class="aicom-card-title"><?php esc_html_e( 'MCP Endpoint', 'aicom' ); ?></h2>
        </div>
        <div class="aicom-card-body" style="padding:0">
            <div class="aicom-endpoint-row">
                <div class="aicom-endpoint-col">
                    <span class="aicom-endpoint-label"><?php esc_html_e( 'Primary Endpoint', 'aicom' ); ?></span>
                    <div class="aicom-endpoint-url-row">
                        <code class="aicom-endpoint-url"><?php echo esc_html( $mcp_url ); ?></code>
                        <button class="button button-small aicom-copy-btn" data-target="<?php echo esc_attr( $mcp_url ); ?>"><?php esc_html_e( 'Copy', 'aicom' ); ?></button>
                    </div>
                </div>
                <div class="aicom-endpoint-divider"></div>
                <div class="aicom-endpoint-col">
                    <span class="aicom-endpoint-label"><?php esc_html_e( 'Fallback Endpoint', 'aicom' ); ?></span>
                    <div class="aicom-endpoint-url-row">
                        <code class="aicom-endpoint-url"><?php echo esc_html( $fallback ); ?></code>
                        <button class="button button-small aicom-copy-btn" data-target="<?php echo esc_attr( $fallback ); ?>"><?php esc_html_e( 'Copy', 'aicom' ); ?></button>
                    </div>
                </div>
                <div class="aicom-endpoint-divider"></div>
                <div class="aicom-endpoint-col">
                    <span class="aicom-endpoint-label"><?php esc_html_e( 'Auth Header', 'aicom' ); ?></span>
                    <div class="aicom-endpoint-url-row">
                        <code class="aicom-endpoint-url">Authorization: Bearer &lt;api-key&gt;</code>
                    </div>
                </div>
            </div>
        </div>
    </div>

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
