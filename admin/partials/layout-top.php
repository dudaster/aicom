<?php
defined( 'ABSPATH' ) || exit;

$_aicom_current_page = sanitize_key( wp_unslash( $_GET['page'] ?? 'aicom' ) );

$_aicom_new_version = '';
if ( current_user_can( 'update_plugins' ) ) {
    $_aicom_plugin_updates = get_site_transient( 'update_plugins' );
    if ( isset( $_aicom_plugin_updates->response['aicom/aicom.php']->new_version ) ) {
        $_aicom_new_version = $_aicom_plugin_updates->response['aicom/aicom.php']->new_version;
    }
}

$_aicom_nav = [
    'aicom'            => [ __( 'Dashboard',               'aicom' ), 'dashicons-dashboard' ],
    'aicom-api-keys'   => [ __( 'Manage API Keys',         'aicom' ), 'dashicons-lock' ],
    'aicom-audit-logs' => [ __( 'Activity',                'aicom' ), 'dashicons-list-view' ],
    'aicom-safety'     => [ __( 'Safety',                  'aicom' ), 'dashicons-shield' ],
    'aicom-modules'    => [ __( 'Capabilities',            'aicom' ), 'dashicons-grid-view' ],
    'aicom-backups'    => [ __( 'Snapshots',               'aicom' ), 'dashicons-backup' ],
    'aicom-skills'     => [ __( 'Saved Workflows',         'aicom' ), 'dashicons-media-text' ],
    'aicom-help'       => [ __( 'Help',                    'aicom' ), 'dashicons-editor-help' ],
];
?>
<div class="wrap aicom-wrap">
<div class="aicom-layout">

    <nav class="aicom-sidenav" aria-label="AICOM navigation">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=aicom' ) ); ?>" class="aicom-sidenav-logo">
            <img src="<?php echo esc_url( AICOM_URL . 'assets/branding/aicom-logo-primary.svg' ); ?>" alt="aicom" class="aicom-sidenav-logo-img">
        </a>
        <ul class="aicom-sidenav-menu">
            <?php foreach ( $_aicom_nav as $slug => [ $label, $icon ] ) : ?>
            <li class="<?php echo $_aicom_current_page === $slug ? 'is-active' : ''; ?>">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>">
                    <span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
                    <span><?php echo esc_html( $label ); ?></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
        <div class="aicom-sidenav-footer">
            <span class="aicom-sidenav-version">v<?php echo esc_html( AICOM_VERSION ); ?></span>
            <?php if ( $_aicom_new_version ) : ?>
                <a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>" class="aicom-sidenav-update">
                    <?php
                    printf(
                        /* translators: %s: new plugin version number */
                        esc_html__( 'New version %s available. Update now.', 'aicom' ),
                        esc_html( $_aicom_new_version )
                    );
                    ?>
                </a>
            <?php endif; ?>
        </div>
    </nav>

    <div class="aicom-page-content">
