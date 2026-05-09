<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin bootstrap: menus, assets, POST handler routing.
 */
class AICOM_Admin {

    const MENU_SLUG    = 'aicom';
    const CAPABILITY   = 'manage_options';
    const NONCE_ACTION = 'aicom_admin';

    public function __construct() {
        add_action( 'admin_menu',          [ $this, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_post_aicom_save', [ $this, 'handle_post' ] );
        add_filter( 'plugin_action_links_aicom/aicom.php', [ $this, 'plugin_action_links' ] );
    }

    public function plugin_action_links( array $links ): array {
        $setup_link = '<a href="' . esc_url( admin_url( 'admin.php?page=aicom-api-keys' ) ) . '"><strong>Setup API Key</strong></a>';
        array_unshift( $links, $setup_link );
        return $links;
    }

    // ── Menu Registration ─────────────────────────────────────────────────

    public function register_menus(): void {
        add_menu_page(
            __( 'AICOM', 'aicom' ),
            __( 'AICOM', 'aicom' ),
            self::CAPABILITY,
            self::MENU_SLUG,
            [ $this, 'page_dashboard' ],
            'dashicons-rest-api',
            80
        );

        add_submenu_page( self::MENU_SLUG, __( 'Dashboard', 'aicom' ),   __( 'Dashboard', 'aicom' ),   self::CAPABILITY, self::MENU_SLUG,              [ $this, 'page_dashboard' ] );
        add_submenu_page( self::MENU_SLUG, __( 'API Keys', 'aicom' ),    __( 'API Keys', 'aicom' ),    self::CAPABILITY, 'aicom-api-keys',         [ $this, 'page_api_keys' ] );
        add_submenu_page( self::MENU_SLUG, __( 'Audit Logs', 'aicom' ),  __( 'Audit Logs', 'aicom' ),  self::CAPABILITY, 'aicom-audit-logs',       [ $this, 'page_audit_logs' ] );
        add_submenu_page( self::MENU_SLUG, __( 'Safety', 'aicom' ),      __( 'Safety', 'aicom' ),      self::CAPABILITY, 'aicom-safety',           [ $this, 'page_safety' ] );
        add_submenu_page( self::MENU_SLUG, __( 'Modules', 'aicom' ),     __( 'Modules', 'aicom' ),     self::CAPABILITY, 'aicom-modules',          [ $this, 'page_modules' ] );
        add_submenu_page( self::MENU_SLUG, __( 'Backups', 'aicom' ),     __( 'Backups', 'aicom' ),     self::CAPABILITY, 'aicom-backups',          [ $this, 'page_backups' ] );
    }

    // ── Asset Enqueuing ───────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {
        $plugin_url = AICOM_URL;
        $version    = AICOM_VERSION;
        $on_aicom   = strpos( $hook, 'aicom' ) !== false;

        // CSS enqueued on every admin page (admin bar styles needed everywhere)
        wp_enqueue_style(
            'aicom-admin',
            $plugin_url . 'assets/admin.css',
            [],
            $version
        );

        // JS only needed on AICOM pages OR when admin bar is showing (toolbar toggle)
        wp_enqueue_script(
            'aicom-admin',
            $plugin_url . 'assets/admin.js',
            [ 'jquery' ],
            $version,
            true
        );

        wp_localize_script( 'aicom-admin', 'AICOM_MCP', [
            'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        ] );
    }

    // ── Page Renderers ────────────────────────────────────────────────────

    public function page_dashboard(): void {
        $this->require_cap();
        require AICOM_DIR . 'admin/pages/dashboard.php';
    }

    public function page_api_keys(): void {
        $this->require_cap();
        require AICOM_DIR . 'admin/pages/api-keys.php';
    }

    public function page_audit_logs(): void {
        $this->require_cap();
        require AICOM_DIR . 'admin/pages/audit-logs.php';
    }

    public function page_safety(): void {
        $this->require_cap();
        require AICOM_DIR . 'admin/pages/safety.php';
    }

    public function page_modules(): void {
        $this->require_cap();
        require AICOM_DIR . 'admin/pages/modules.php';
    }

    public function page_backups(): void {
        $this->require_cap();
        require AICOM_DIR . 'admin/pages/backups.php';
    }

    // ── POST Handler ──────────────────────────────────────────────────────

    public function handle_post(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'aicom' ), 403 );
        }
        check_admin_referer( self::NONCE_ACTION );

        // All $_POST reads happen here, after nonce verification, then passed as typed params.
        $action = sanitize_key( wp_unslash( $_POST['aicom_action'] ?? '' ) );

        switch ( $action ) {
            case 'set_lock':
                $this->handle_set_lock(
                    ! empty( $_POST['soft_lock'] ),
                    ! empty( $_POST['hard_lock'] )
                );
                break;

            case 'create_key':
                $raw_scopes = filter_input( INPUT_POST, 'scopes', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY ) ?? [];
                $scopes = array_map( 'sanitize_text_field', wp_unslash( $raw_scopes ) );
                $this->handle_create_key(
                    sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ),
                    $scopes,
                    ! empty( $_POST['dry_run_only'] ),
                    sanitize_textarea_field( wp_unslash( $_POST['ip_allowlist'] ?? '' ) )
                );
                break;

            case 'rotate_key':
                $this->handle_rotate_key( absint( wp_unslash( $_POST['key_id'] ?? 0 ) ) );
                break;

            case 'revoke_key':
                $this->handle_revoke_key( absint( wp_unslash( $_POST['key_id'] ?? 0 ) ) );
                break;

            case 'suspend_key':
                $this->handle_suspend_key( absint( wp_unslash( $_POST['key_id'] ?? 0 ) ) );
                break;

            case 'unsuspend_key':
                $this->handle_unsuspend_key( absint( wp_unslash( $_POST['key_id'] ?? 0 ) ) );
                break;

            case 'delete_backup':
                $this->handle_delete_backup( absint( wp_unslash( $_POST['backup_id'] ?? 0 ) ) );
                break;

            default:
                wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&error=unknown_action' ) );
                exit;
        }
    }

    // ── Action Handlers ───────────────────────────────────────────────────

    private function handle_set_lock( bool $soft_lock, bool $hard_lock ): void {
        AICOM_Lock_Manager::set_soft_lock( $soft_lock );
        AICOM_Lock_Manager::set_hard_lock( $hard_lock );

        AICOM_Audit_Logger::log( [
            'remote_ip'     => AICOM_Tool_Router::remote_ip(),
            'tool_name'     => 'admin.set_lock',
            'module'        => 'admin',
            'status'        => 'success',
            'http_status'   => 200,
            'api_key_label' => wp_get_current_user()->user_login,
            'result_summary_json' => wp_json_encode( [
                'soft_lock' => $soft_lock,
                'hard_lock' => $hard_lock,
            ] ),
        ] );

        wp_safe_redirect( admin_url( 'admin.php?page=aicom-safety&updated=lock' ) );
        exit;
    }

    private function handle_create_key( string $label, array $scopes, bool $dry_run_only, string $ip_allowlist_raw ): void {
        if ( ! $label ) {
            wp_safe_redirect( admin_url( 'admin.php?page=aicom-api-keys&error=missing_label' ) );
            exit;
        }

        $restrictions = [];
        if ( $dry_run_only ) {
            $restrictions['dry_run_only'] = true;
        }
        if ( $ip_allowlist_raw ) {
            $restrictions['ip_allowlist'] = array_filter( array_map( 'trim', explode( "\n", $ip_allowlist_raw ) ) );
        }

        $result = AICOM_Auth::create_key( $label, $scopes, $restrictions );

        // Store the plain key in a short-lived transient for one-time display
        set_transient( 'aicom_new_key_' . $result['id'], $result['plain_key'], 60 );

        wp_safe_redirect( admin_url( 'admin.php?page=aicom-api-keys&created=' . $result['id'] ) );
        exit;
    }

    private function handle_rotate_key( int $key_id ): void {
        $new_key = AICOM_Auth::rotate_key( $key_id );
        if ( $new_key ) {
            set_transient( 'aicom_new_key_' . $key_id, $new_key, 60 );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=aicom-api-keys&rotated=' . $key_id ) );
        exit;
    }

    private function handle_revoke_key( int $key_id ): void {
        AICOM_Auth::revoke_key( $key_id );
        wp_safe_redirect( admin_url( 'admin.php?page=aicom-api-keys&revoked=' . $key_id ) );
        exit;
    }

    private function handle_suspend_key( int $key_id ): void {
        AICOM_Auth::suspend_key( $key_id );
        wp_safe_redirect( admin_url( 'admin.php?page=aicom-api-keys&suspended=' . $key_id ) );
        exit;
    }

    private function handle_unsuspend_key( int $key_id ): void {
        AICOM_Auth::unsuspend_key( $key_id );
        wp_safe_redirect( admin_url( 'admin.php?page=aicom-api-keys&unsuspended=' . $key_id ) );
        exit;
    }

    private function handle_delete_backup( int $id ): void {
        global $wpdb;
        if ( $id ) {
            $wpdb->delete( $wpdb->prefix . 'aicom_backups', [ 'id' => $id ] );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=aicom-backups&deleted=' . $id ) );
        exit;
    }

    // ── Admin Bar ─────────────────────────────────────────────────────────

    public static function register_admin_bar( WP_Admin_Bar $bar ): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }

        global $wpdb;
        $keys = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT id, label, status FROM {$wpdb->prefix}aicom_api_keys
             WHERE status IN ('active','suspended')
             ORDER BY created_at DESC LIMIT 20",
            ARRAY_A
        );

        $manage_url   = admin_url( 'admin.php?page=aicom-api-keys' );
        $active_count = count( array_filter( $keys, fn( $k ) => $k['status'] === 'active' ) );
        $count_html   = $active_count > 0
            ? ' <span class="aicom-tb-count">' . $active_count . '</span>'
            : '';

        $bar->add_node( [
            'id'    => 'aicom-toolbar',
            'title' => '<span class="ab-icon dashicons dashicons-rest-api"></span>'
                     . '<span class="ab-label">AICOM Keys</span>'
                     . $count_html,
            'href'  => $manage_url,
            'meta'  => [ 'class' => 'aicom-tb-parent' ],
        ] );

        foreach ( $keys as $key ) {
            $is_active  = $key['status'] === 'active';
            $dot        = $is_active ? '🟢' : '🟡';
            $action     = $is_active ? 'suspend_key' : 'unsuspend_key';
            $btn_label  = $is_active ? 'Suspend' : 'Unsuspend';
            $btn_class  = $is_active ? 'aicom-tb-btn-suspend' : 'aicom-tb-btn-unsuspend';

            $bar->add_node( [
                'id'     => 'aicom-key-' . (int) $key['id'],
                'parent' => 'aicom-toolbar',
                'title'  => '<span class="aicom-tb-dot">' . $dot . '</span>'
                          . '<span class="aicom-tb-label">' . esc_html( $key['label'] ) . '</span>'
                          . '<button class="aicom-tb-btn ' . esc_attr( $btn_class ) . '"'
                          . ' data-key-id="' . (int) $key['id'] . '"'
                          . ' data-action="' . esc_attr( $action ) . '"'
                          . ' data-nonce="' . esc_attr( wp_create_nonce( 'aicom_toolbar_' . (int) $key['id'] ) ) . '">'
                          . esc_html( $btn_label )
                          . '</button>',
                'href'   => false,
                'meta'   => [ 'class' => 'aicom-tb-key' ],
            ] );
        }

        $bar->add_node( [
            'id'     => 'aicom-toolbar-manage',
            'parent' => 'aicom-toolbar',
            'title'  => 'Manage API Keys',
            'href'   => $manage_url,
            'meta'   => [ 'class' => 'aicom-tb-manage' ],
        ] );
    }

    public static function ajax_toolbar_toggle(): void {
        $key_id = absint( $_POST['key_id'] ?? 0 );
        $action = sanitize_key( wp_unslash( $_POST['aicom_action'] ?? '' ) );

        if ( ! $key_id || ! in_array( $action, [ 'suspend_key', 'unsuspend_key' ], true ) ) {
            wp_send_json_error( 'invalid_params', 400 );
        }

        if ( ! check_ajax_referer( 'aicom_toolbar_' . $key_id, 'nonce', false ) ) {
            wp_send_json_error( 'bad_nonce', 403 );
        }

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( 'unauthorized', 403 );
        }

        $ok = $action === 'suspend_key'
            ? AICOM_Auth::suspend_key( $key_id )
            : AICOM_Auth::unsuspend_key( $key_id );

        if ( ! $ok ) {
            wp_send_json_error( 'no_change', 409 );
        }

        $new_status    = $action === 'suspend_key' ? 'suspended' : 'active';
        $new_dot       = $new_status === 'active' ? '🟢' : '🟡';
        $new_action    = $new_status === 'active' ? 'suspend_key' : 'unsuspend_key';
        $new_btn_label = $new_status === 'active' ? 'Suspend' : 'Unsuspend';
        $new_btn_class = $new_status === 'active' ? 'aicom-tb-btn-suspend' : 'aicom-tb-btn-unsuspend';
        $new_nonce     = wp_create_nonce( 'aicom_toolbar_' . $key_id );

        wp_send_json_success( [
            'new_status'    => $new_status,
            'new_dot'       => $new_dot,
            'new_action'    => $new_action,
            'new_btn_label' => $new_btn_label,
            'new_btn_class' => $new_btn_class,
            'new_nonce'     => $new_nonce,
        ] );
    }

    // ── Private Helpers ───────────────────────────────────────────────────

    private function require_cap(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'aicom' ) );
        }
    }
}
