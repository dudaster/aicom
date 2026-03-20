<?php
// Ensure only called from within WordPress admin.
defined( 'ABSPATH' ) || exit;

$lock_state = ACL_Lock_Manager::get_state();
$stats      = ACL_Audit_Logger::get_stats_today();
$mcp_url    = get_site_url() . '/wp-json/acl/v1/mcp';
$fallback    = get_site_url() . '/?acl=1';

$badge_class = match ( $lock_state['effective_lock'] ) {
    'hard_locked' => 'acl-badge-danger',
    'soft_locked' => 'acl-badge-warning',
    default       => 'acl-badge-success',
};
$badge_label = match ( $lock_state['effective_lock'] ) {
    'hard_locked' => 'Hard Lock Active',
    'soft_locked' => 'Soft Lock Active',
    default       => 'Unlocked',
};
?>
<div class="wrap acl-wrap">
    <h1>ACL - AI Control Layer</h1>

    <div class="acl-dashboard-grid">

        <!-- Status Badge -->
        <div class="acl-card">
            <h2>Server Status</h2>
            <p class="acl-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_label ); ?></p>
            <p>WordPress: <strong><?php echo esc_html( get_bloginfo( 'version' ) ); ?></strong></p>
            <p>Plugin: <strong><?php echo esc_html( ACL_VERSION ); ?></strong></p>
        </div>

        <!-- MCP Connection -->
        <div class="acl-card">
            <h2>MCP Endpoint</h2>
            <label><strong>Primary URL</strong></label>
            <div class="acl-copy-row">
                <input type="text" readonly value="<?php echo esc_attr( $mcp_url ); ?>" class="regular-text acl-copy-input" />
                <button class="button acl-copy-btn" data-target="<?php echo esc_attr( $mcp_url ); ?>">Copy</button>
            </div>
            <label><strong>Fallback URL</strong></label>
            <div class="acl-copy-row">
                <input type="text" readonly value="<?php echo esc_attr( $fallback ); ?>" class="regular-text acl-copy-input" />
                <button class="button acl-copy-btn" data-target="<?php echo esc_attr( $fallback ); ?>">Copy</button>
            </div>
        </div>

        <!-- Quick Lock Controls -->
        <div class="acl-card">
            <h2>Lock Controls</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="acl_save" />
                <input type="hidden" name="acl_action" value="set_lock" />
                <?php wp_nonce_field( ACL_Admin::NONCE_ACTION ); ?>

                <p>
                    <label>
                        <input type="checkbox" name="soft_lock" value="1" <?php checked( $lock_state['soft_lock'] ); ?> <?php disabled( $lock_state['hard_lock'] ); ?> />
                        Soft Lock
                        <span class="description">(blocks write/destructive)</span>
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="hard_lock" value="1" <?php checked( $lock_state['hard_lock'] ); ?> id="acl-hard-lock-toggle" />
                        Hard Lock
                        <span class="description">(allows only server.status)</span>
                    </label>
                </p>
                <?php if ( $lock_state['hard_lock'] ) : ?>
                <p class="description acl-hard-lock-notice">Hard Lock overrides Soft Lock.</p>
                <?php endif; ?>
                <?php submit_button( 'Apply Lock Settings', 'primary', 'submit', false ); ?>
            </form>
        </div>

        <!-- Today's Stats -->
        <div class="acl-card">
            <h2>Today's Activity</h2>
            <div class="acl-stats-grid">
                <div class="acl-stat">
                    <span class="acl-stat-number"><?php echo esc_html( $stats['total_today'] ); ?></span>
                    <span class="acl-stat-label">Total Requests</span>
                </div>
                <div class="acl-stat">
                    <span class="acl-stat-number acl-success"><?php echo esc_html( $stats['success_today'] ); ?></span>
                    <span class="acl-stat-label">Success</span>
                </div>
                <div class="acl-stat">
                    <span class="acl-stat-number acl-error"><?php echo esc_html( $stats['errors_today'] ); ?></span>
                    <span class="acl-stat-label">Errors</span>
                </div>
                <div class="acl-stat">
                    <span class="acl-stat-number acl-warning"><?php echo esc_html( $stats['blocked_today'] ); ?></span>
                    <span class="acl-stat-label">Blocked</span>
                </div>
            </div>
            <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=acl-audit-logs' ) ); ?>">View full audit log →</a></p>
        </div>

    </div><!-- .acl-dashboard-grid -->

    <!-- Module Status -->
    <div class="acl-card acl-card-full">
        <h2>Active Modules</h2>
        <div class="acl-modules-row">
            <?php foreach ( ACL_Module_Detector::get_module_status_map() as $module => $status ) : ?>
            <div class="acl-module-pill <?php echo $status === 'active' ? 'acl-module-active' : 'acl-module-inactive'; ?>">
                <?php echo esc_html( ucfirst( str_replace( '_', ' ', $module ) ) ); ?>
                <span class="acl-dot"></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

</div><!-- .acl-wrap -->
