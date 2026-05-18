<?php
defined( 'ABSPATH' ) || exit;

$_aicom_current_page = sanitize_key( wp_unslash( $_GET['page'] ?? 'aicom' ) );

$_aicom_nav = [
    'aicom'            => [ __( 'Dashboard',  'aicom' ), 'dashicons-dashboard' ],
    'aicom-api-keys'   => [ __( 'API Keys',   'aicom' ), 'dashicons-lock' ],
    'aicom-audit-logs' => [ __( 'Audit Logs', 'aicom' ), 'dashicons-list-view' ],
    'aicom-safety'     => [ __( 'Safety',     'aicom' ), 'dashicons-shield' ],
    'aicom-modules'    => [ __( 'Modules',    'aicom' ), 'dashicons-grid-view' ],
    'aicom-backups'    => [ __( 'Backups',    'aicom' ), 'dashicons-backup' ],
    'aicom-skills'     => [ __( 'Skills',     'aicom' ), 'dashicons-media-text' ],
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
        </div>
    </nav>

    <div class="aicom-page-content">
