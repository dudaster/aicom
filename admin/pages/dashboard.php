<?php
// Ensure only called from within WordPress admin.
defined( 'ABSPATH' ) || exit;

$lock_state = AICOM_Lock_Manager::get_state();
$stats      = AICOM_Audit_Logger::get_stats_today();
$mcp_url    = get_site_url() . '/wp-json/aicom/v1/mcp';
$fallback    = get_site_url() . '/?aicom=1';

$badge_class = match ( $lock_state['effective_lock'] ) {
    'hard_locked' => 'aicom-badge-danger',
    'soft_locked' => 'aicom-badge-warning',
    default       => 'aicom-badge-success',
};
$badge_label = match ( $lock_state['effective_lock'] ) {
    'hard_locked' => 'Hard Lock Active',
    'soft_locked' => 'Soft Lock Active',
    default       => 'Unlocked',
};
?>
<div class="wrap aicom-wrap">
    <h1>AICOM - AI Commander for WordPress</h1>

    <div class="aicom-dashboard-grid">

        <!-- Status Badge -->
        <div class="aicom-card">
            <h2>Server Status</h2>
            <p class="aicom-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_label ); ?></p>
            <p>WordPress: <strong><?php echo esc_html( get_bloginfo( 'version' ) ); ?></strong></p>
            <p>Plugin: <strong><?php echo esc_html( AICOM_VERSION ); ?></strong></p>
        </div>

        <!-- MCP Connection -->
        <div class="aicom-card">
            <h2>MCP Endpoint</h2>
            <label><strong>Primary URL</strong></label>
            <div class="aicom-copy-row">
                <input type="text" readonly value="<?php echo esc_attr( $mcp_url ); ?>" class="regular-text aicom-copy-input" />
                <button class="button aicom-copy-btn" data-target="<?php echo esc_attr( $mcp_url ); ?>">Copy</button>
            </div>
            <label><strong>Fallback URL</strong></label>
            <div class="aicom-copy-row">
                <input type="text" readonly value="<?php echo esc_attr( $fallback ); ?>" class="regular-text aicom-copy-input" />
                <button class="button aicom-copy-btn" data-target="<?php echo esc_attr( $fallback ); ?>">Copy</button>
            </div>
        </div>

        <!-- Quick Lock Controls -->
        <div class="aicom-card">
            <h2>Lock Controls</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="aicom_save" />
                <input type="hidden" name="aicom_action" value="set_lock" />
                <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>

                <p>
                    <label>
                        <input type="checkbox" name="soft_lock" value="1" <?php checked( $lock_state['soft_lock'] ); ?> <?php disabled( $lock_state['hard_lock'] ); ?> />
                        Soft Lock
                        <span class="description">(blocks write/destructive)</span>
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="hard_lock" value="1" <?php checked( $lock_state['hard_lock'] ); ?> id="aicom-hard-lock-toggle" />
                        Hard Lock
                        <span class="description">(allows only server.status)</span>
                    </label>
                </p>
                <?php if ( $lock_state['hard_lock'] ) : ?>
                <p class="description aicom-hard-lock-notice">Hard Lock overrides Soft Lock.</p>
                <?php endif; ?>
                <?php submit_button( 'Apply Lock Settings', 'primary', 'submit', false ); ?>
            </form>
        </div>

        <!-- Today's Stats -->
        <div class="aicom-card">
            <h2>Today's Activity</h2>
            <div class="aicom-stats-grid">
                <div class="aicom-stat">
                    <span class="aicom-stat-number"><?php echo esc_html( $stats['total_today'] ); ?></span>
                    <span class="aicom-stat-label">Total Requests</span>
                </div>
                <div class="aicom-stat">
                    <span class="aicom-stat-number aicom-success"><?php echo esc_html( $stats['success_today'] ); ?></span>
                    <span class="aicom-stat-label">Success</span>
                </div>
                <div class="aicom-stat">
                    <span class="aicom-stat-number aicom-error"><?php echo esc_html( $stats['errors_today'] ); ?></span>
                    <span class="aicom-stat-label">Errors</span>
                </div>
                <div class="aicom-stat">
                    <span class="aicom-stat-number aicom-warning"><?php echo esc_html( $stats['blocked_today'] ); ?></span>
                    <span class="aicom-stat-label">Blocked</span>
                </div>
            </div>
            <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=aicom-audit-logs' ) ); ?>">View full audit log →</a></p>
        </div>

    </div><!-- .aicom-dashboard-grid -->

    <!-- Module Status -->
    <div class="aicom-card aicom-card-full">
        <h2>Active Modules</h2>
        <div class="aicom-modules-row">
            <?php foreach ( AICOM_Module_Detector::get_module_status_map() as $module => $status ) : ?>
            <div class="aicom-module-pill <?php echo $status === 'active' ? 'aicom-module-active' : 'aicom-module-inactive'; ?>">
                <?php echo esc_html( ucfirst( str_replace( '_', ' ', $module ) ) ); ?>
                <span class="aicom-dot"></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

</div><!-- .aicom-wrap -->
