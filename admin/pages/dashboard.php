<?php
// Ensure only called from within WordPress admin.
defined( 'ABSPATH' ) || exit;

$lock_state = WPOPS_Lock_Manager::get_state();
$stats      = WPOPS_Audit_Logger::get_stats_today();
$mcp_url    = get_site_url() . '/wp-json/wpops-mcp/v1/mcp';
$fallback    = get_site_url() . '/?wpops_mcp=1';

$badge_class = match ( $lock_state['effective_lock'] ) {
    'hard_locked' => 'wpops-badge-danger',
    'soft_locked' => 'wpops-badge-warning',
    default       => 'wpops-badge-success',
};
$badge_label = match ( $lock_state['effective_lock'] ) {
    'hard_locked' => 'Hard Lock Active',
    'soft_locked' => 'Soft Lock Active',
    default       => 'Unlocked',
};
?>
<div class="wrap wpops-wrap">
    <h1>WP Ops MCP Gateway</h1>

    <div class="wpops-dashboard-grid">

        <!-- Status Badge -->
        <div class="wpops-card">
            <h2>Server Status</h2>
            <p class="wpops-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_label ); ?></p>
            <p>WordPress: <strong><?php echo esc_html( get_bloginfo( 'version' ) ); ?></strong></p>
            <p>Plugin: <strong><?php echo esc_html( WPOPS_MCP_VERSION ); ?></strong></p>
        </div>

        <!-- MCP Connection -->
        <div class="wpops-card">
            <h2>MCP Endpoint</h2>
            <label><strong>Primary URL</strong></label>
            <div class="wpops-copy-row">
                <input type="text" readonly value="<?php echo esc_attr( $mcp_url ); ?>" class="regular-text wpops-copy-input" />
                <button class="button wpops-copy-btn" data-target="<?php echo esc_attr( $mcp_url ); ?>">Copy</button>
            </div>
            <label><strong>Fallback URL</strong></label>
            <div class="wpops-copy-row">
                <input type="text" readonly value="<?php echo esc_attr( $fallback ); ?>" class="regular-text wpops-copy-input" />
                <button class="button wpops-copy-btn" data-target="<?php echo esc_attr( $fallback ); ?>">Copy</button>
            </div>
        </div>

        <!-- Quick Lock Controls -->
        <div class="wpops-card">
            <h2>Lock Controls</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="wpops_mcp_save" />
                <input type="hidden" name="wpops_action" value="set_lock" />
                <?php wp_nonce_field( WPOPS_Admin::NONCE_ACTION ); ?>

                <p>
                    <label>
                        <input type="checkbox" name="soft_lock" value="1" <?php checked( $lock_state['soft_lock'] ); ?> <?php disabled( $lock_state['hard_lock'] ); ?> />
                        Soft Lock
                        <span class="description">(blocks write/destructive)</span>
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="hard_lock" value="1" <?php checked( $lock_state['hard_lock'] ); ?> id="wpops-hard-lock-toggle" />
                        Hard Lock
                        <span class="description">(allows only server.status)</span>
                    </label>
                </p>
                <?php if ( $lock_state['hard_lock'] ) : ?>
                <p class="description wpops-hard-lock-notice">Hard Lock overrides Soft Lock.</p>
                <?php endif; ?>
                <?php submit_button( 'Apply Lock Settings', 'primary', 'submit', false ); ?>
            </form>
        </div>

        <!-- Today's Stats -->
        <div class="wpops-card">
            <h2>Today's Activity</h2>
            <div class="wpops-stats-grid">
                <div class="wpops-stat">
                    <span class="wpops-stat-number"><?php echo esc_html( $stats['total_today'] ); ?></span>
                    <span class="wpops-stat-label">Total Requests</span>
                </div>
                <div class="wpops-stat">
                    <span class="wpops-stat-number wpops-success"><?php echo esc_html( $stats['success_today'] ); ?></span>
                    <span class="wpops-stat-label">Success</span>
                </div>
                <div class="wpops-stat">
                    <span class="wpops-stat-number wpops-error"><?php echo esc_html( $stats['errors_today'] ); ?></span>
                    <span class="wpops-stat-label">Errors</span>
                </div>
                <div class="wpops-stat">
                    <span class="wpops-stat-number wpops-warning"><?php echo esc_html( $stats['blocked_today'] ); ?></span>
                    <span class="wpops-stat-label">Blocked</span>
                </div>
            </div>
            <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=wpops-mcp-audit-logs' ) ); ?>">View full audit log →</a></p>
        </div>

    </div><!-- .wpops-dashboard-grid -->

    <!-- Module Status -->
    <div class="wpops-card wpops-card-full">
        <h2>Active Modules</h2>
        <div class="wpops-modules-row">
            <?php foreach ( WPOPS_Module_Detector::get_module_status_map() as $module => $status ) : ?>
            <div class="wpops-module-pill <?php echo $status === 'active' ? 'wpops-module-active' : 'wpops-module-inactive'; ?>">
                <?php echo esc_html( ucfirst( str_replace( '_', ' ', $module ) ) ); ?>
                <span class="wpops-dot"></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

</div><!-- .wpops-wrap -->
