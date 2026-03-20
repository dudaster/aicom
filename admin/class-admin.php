<?php
/**
 * Admin bootstrap: menus, assets, POST handler routing.
 */
class ACL_Admin {

    const MENU_SLUG    = 'acl';
    const CAPABILITY   = 'manage_options';
    const NONCE_ACTION = 'acl_admin';

    public function __construct() {
        add_action( 'admin_menu',          [ $this, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_post_acl_save', [ $this, 'handle_post' ] );
    }

    // ── Menu Registration ─────────────────────────────────────────────────

    public function register_menus(): void {
        add_menu_page(
            __( 'ACL', 'acl-ai-control-layer' ),
            __( 'ACL', 'acl-ai-control-layer' ),
            self::CAPABILITY,
            self::MENU_SLUG,
            [ $this, 'page_dashboard' ],
            'dashicons-rest-api',
            80
        );

        add_submenu_page( self::MENU_SLUG, __( 'Dashboard', 'acl-ai-control-layer' ),   __( 'Dashboard', 'acl-ai-control-layer' ),   self::CAPABILITY, self::MENU_SLUG,              [ $this, 'page_dashboard' ] );
        add_submenu_page( self::MENU_SLUG, __( 'API Keys', 'acl-ai-control-layer' ),    __( 'API Keys', 'acl-ai-control-layer' ),    self::CAPABILITY, 'acl-api-keys',         [ $this, 'page_api_keys' ] );
        add_submenu_page( self::MENU_SLUG, __( 'Audit Logs', 'acl-ai-control-layer' ),  __( 'Audit Logs', 'acl-ai-control-layer' ),  self::CAPABILITY, 'acl-audit-logs',       [ $this, 'page_audit_logs' ] );
        add_submenu_page( self::MENU_SLUG, __( 'Safety', 'acl-ai-control-layer' ),      __( 'Safety', 'acl-ai-control-layer' ),      self::CAPABILITY, 'acl-safety',           [ $this, 'page_safety' ] );
        add_submenu_page( self::MENU_SLUG, __( 'Modules', 'acl-ai-control-layer' ),     __( 'Modules', 'acl-ai-control-layer' ),     self::CAPABILITY, 'acl-modules',          [ $this, 'page_modules' ] );
        add_submenu_page( self::MENU_SLUG, __( 'Backups', 'acl-ai-control-layer' ),     __( 'Backups', 'acl-ai-control-layer' ),     self::CAPABILITY, 'acl-backups',          [ $this, 'page_backups' ] );
    }

    // ── Asset Enqueuing ───────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {
        // Only enqueue on our own pages
        if ( strpos( $hook, 'acl' ) === false ) {
            return;
        }

        $plugin_url = ACL_URL;
        $version    = ACL_VERSION;

        wp_enqueue_style(
            'acl-admin',
            $plugin_url . 'assets/admin.css',
            [],
            $version
        );

        wp_enqueue_script(
            'acl-admin',
            $plugin_url . 'assets/admin.js',
            [ 'jquery' ],
            $version,
            true
        );

        wp_localize_script( 'acl-admin', 'ACL_MCP', [
            'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        ] );
    }

    // ── Page Renderers ────────────────────────────────────────────────────

    public function page_dashboard(): void {
        $this->require_cap();
        require ACL_DIR . 'admin/pages/dashboard.php';
    }

    public function page_api_keys(): void {
        $this->require_cap();
        require ACL_DIR . 'admin/pages/api-keys.php';
    }

    public function page_audit_logs(): void {
        $this->require_cap();
        require ACL_DIR . 'admin/pages/audit-logs.php';
    }

    public function page_safety(): void {
        $this->require_cap();
        require ACL_DIR . 'admin/pages/safety.php';
    }

    public function page_modules(): void {
        $this->require_cap();
        require ACL_DIR . 'admin/pages/modules.php';
    }

    public function page_backups(): void {
        $this->require_cap();
        require ACL_DIR . 'admin/pages/backups.php';
    }

    // ── POST Handler ──────────────────────────────────────────────────────

    public function handle_post(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( 'Unauthorized', 403 );
        }
        check_admin_referer( self::NONCE_ACTION );

        $action = sanitize_key( $_POST['acl_action'] ?? '' );

        switch ( $action ) {
            case 'set_lock':
                $this->handle_set_lock();
                break;

            case 'create_key':
                $this->handle_create_key();
                break;

            case 'rotate_key':
                $this->handle_rotate_key();
                break;

            case 'revoke_key':
                $this->handle_revoke_key();
                break;

            case 'delete_backup':
                $this->handle_delete_backup();
                break;

            default:
                wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&error=unknown_action' ) );
                exit;
        }
    }

    // ── Action Handlers ───────────────────────────────────────────────────

    private function handle_set_lock(): void {
        $soft_lock = ! empty( $_POST['soft_lock'] );
        $hard_lock = ! empty( $_POST['hard_lock'] );

        ACL_Lock_Manager::set_soft_lock( $soft_lock );
        ACL_Lock_Manager::set_hard_lock( $hard_lock );

        ACL_Audit_Logger::log( [
            'remote_ip'     => ACL_Tool_Router::remote_ip(),
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

        wp_safe_redirect( admin_url( 'admin.php?page=acl-safety&updated=lock' ) );
        exit;
    }

    private function handle_create_key(): void {
        $label  = sanitize_text_field( $_POST['label'] ?? '' );
        $scopes = array_map( 'sanitize_text_field', (array) ( $_POST['scopes'] ?? [] ) );

        $restrictions = [];
        if ( ! empty( $_POST['dry_run_only'] ) ) {
            $restrictions['dry_run_only'] = true;
        }
        if ( ! empty( $_POST['ip_allowlist'] ) ) {
            $restrictions['ip_allowlist'] = array_filter( array_map( 'trim', explode( "\n", $_POST['ip_allowlist'] ) ) );
        }

        if ( ! $label ) {
            wp_safe_redirect( admin_url( 'admin.php?page=acl-api-keys&error=missing_label' ) );
            exit;
        }

        $result = ACL_Auth::create_key( $label, $scopes, $restrictions );

        // Store the plain key in a short-lived transient for one-time display
        set_transient( 'acl_new_key_' . $result['id'], $result['plain_key'], 60 );

        wp_safe_redirect( admin_url( 'admin.php?page=acl-api-keys&created=' . $result['id'] ) );
        exit;
    }

    private function handle_rotate_key(): void {
        $key_id  = (int) ( $_POST['key_id'] ?? 0 );
        $new_key = ACL_Auth::rotate_key( $key_id );

        if ( $new_key ) {
            set_transient( 'acl_new_key_' . $key_id, $new_key, 60 );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=acl-api-keys&rotated=' . $key_id ) );
        exit;
    }

    private function handle_revoke_key(): void {
        $key_id = (int) ( $_POST['key_id'] ?? 0 );
        ACL_Auth::revoke_key( $key_id );
        wp_safe_redirect( admin_url( 'admin.php?page=acl-api-keys&revoked=' . $key_id ) );
        exit;
    }

    private function handle_delete_backup(): void {
        global $wpdb;
        $id = (int) ( $_POST['backup_id'] ?? 0 );
        if ( $id ) {
            $wpdb->delete( $wpdb->prefix . 'acl_backups', [ 'id' => $id ] );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=acl-backups&deleted=' . $id ) );
        exit;
    }

    // ── Private Helpers ───────────────────────────────────────────────────

    private function require_cap(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'acl-ai-control-layer' ) );
        }
    }
}
