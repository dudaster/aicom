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
        add_action( 'admin_menu',            [ $this, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_post_aicom_save', [ $this, 'handle_post' ] );
        add_action( 'admin_notices',         [ $this, 'display_key_notice' ] );
        add_filter( 'plugin_action_links_aicom/aicom.php', [ $this, 'plugin_action_links' ] );
        add_action( 'aicom_expire_keys',     [ 'AICOM_Auth', 'expire_overdue_keys' ] );
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

    // ── Admin notices (key created / rotated) ─────────────────────────────

    public function display_key_notice(): void {
        $screen = get_current_screen();
        if ( ! $screen || strpos( $screen->id ?? '', 'aicom' ) === false ) {
            return;
        }

        $created    = isset( $_GET['created'] ) ? absint( $_GET['created'] ) : 0;
        $rotated    = isset( $_GET['rotated'] ) ? absint( $_GET['rotated'] ) : 0;
        $new_key_id = $created ?: $rotated;

        if ( ! $new_key_id ) {
            return;
        }

        $plain_key = get_transient( 'aicom_new_key_' . $new_key_id );
        if ( ! $plain_key ) {
            return;
        }
        delete_transient( 'aicom_new_key_' . $new_key_id );

        $mcp_url      = get_site_url() . '/wp-json/aicom/v1/mcp';
        $fallback_url = get_site_url() . '/?aicom=1';
        /* translators: 1: MCP endpoint URL, 2: fallback URL, 3: API key */
        $agent_text = sprintf(
            __( "Connect to my WordPress site via MCP:\n\nEndpoint: %1\$s\nFallback: %2\$s\nAuthorization: Bearer %3\$s\n\nUse the endpoint and the Authorization header for every request. If the connection is refused or returns 401, retry the request with real browser headers (User-Agent, Accept, Accept-Language).", 'aicom' ),
            $mcp_url, $fallback_url, $plain_key
        );
        $label = $created
            ? __( 'Key created', 'aicom' )
            : __( 'Key rotated', 'aicom' );
        ?>
        <div id="aicom-key-modal-overlay" style="display:none;position:fixed;inset:0;z-index:999999;background:rgba(15,23,42,.55);backdrop-filter:blur(2px);align-items:center;justify-content:center;">
            <div id="aicom-key-modal" role="dialog" aria-modal="true" style="background:#fff;border-radius:12px;box-shadow:0 24px 64px rgba(15,23,42,.22);width:100%;max-width:560px;margin:20px;padding:32px;position:relative;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">

                <button id="aicom-key-modal-close" aria-label="<?php esc_attr_e( 'Close', 'aicom' ); ?>"
                        style="position:absolute;top:16px;right:16px;background:none;border:none;cursor:pointer;color:#64748b;font-size:20px;line-height:1;padding:4px;">&#10005;</button>

                <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px;">
                    <span style="background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;border-radius:6px;padding:4px 10px;font-size:0.78em;font-weight:700;letter-spacing:.4px;text-transform:uppercase;"><?php echo esc_html( $label ); ?></span>
                    <span style="color:#dc2626;font-size:0.82em;font-weight:600;"><?php esc_html_e( 'Copy it now — won\'t be shown again', 'aicom' ); ?></span>
                </div>

                <p style="margin:0 0 6px;font-size:0.82em;font-weight:600;color:#334155;text-transform:uppercase;letter-spacing:.5px;"><?php esc_html_e( 'API Key', 'aicom' ); ?></p>
                <div style="display:flex;gap:8px;margin-bottom:20px;">
                    <input type="text" readonly value="<?php echo esc_attr( $plain_key ); ?>"
                           id="aicom-plain-key"
                           style="flex:1;font-family:monospace;font-size:13px;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:7px;background:#f8fafc;color:#0f172a;min-width:0;"
                           onclick="this.select()" />
                    <button class="button aicom-copy-btn" data-target="<?php echo esc_attr( $plain_key ); ?>"
                            style="white-space:nowrap;flex-shrink:0;"><?php esc_html_e( 'Copy Key', 'aicom' ); ?></button>
                </div>

                <p style="margin:0 0 6px;font-size:0.82em;font-weight:600;color:#334155;text-transform:uppercase;letter-spacing:.5px;"><?php esc_html_e( 'Agent connect prompt', 'aicom' ); ?></p>
                <p style="margin:0 0 8px;font-size:0.8em;color:#64748b;"><?php esc_html_e( 'Paste into OpenClaw or any MCP client:', 'aicom' ); ?></p>
                <div style="display:flex;gap:8px;align-items:flex-start;">
                    <textarea readonly rows="5"
                              style="flex:1;font-family:monospace;font-size:11px;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:7px;background:#f8fafc;color:#334155;resize:none;min-width:0;"><?php echo esc_textarea( $agent_text ); ?></textarea>
                    <button class="button aicom-copy-btn" data-target="<?php echo esc_attr( $agent_text ); ?>"
                            style="white-space:nowrap;flex-shrink:0;"><?php esc_html_e( 'Copy', 'aicom' ); ?></button>
                </div>

                <div style="margin-top:24px;text-align:right;">
                    <button id="aicom-key-modal-done" class="button button-primary"><?php esc_html_e( 'Done', 'aicom' ); ?></button>
                </div>
            </div>
        </div>
        <script>
        (function() {
            var overlay = document.getElementById('aicom-key-modal-overlay');
            if (!overlay) return;
            overlay.style.display = 'flex';

            function closeModal() { overlay.style.display = 'none'; }

            document.getElementById('aicom-key-modal-close').addEventListener('click', closeModal);
            document.getElementById('aicom-key-modal-done').addEventListener('click', closeModal);
            overlay.addEventListener('click', function(e) { if (e.target === overlay) closeModal(); });
            document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeModal(); });
        })();
        </script>
        <?php
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
                    sanitize_textarea_field( wp_unslash( $_POST['ip_allowlist'] ?? '' ) ),
                    sanitize_text_field( wp_unslash( $_POST['expires_at'] ?? '' ) )
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

            case 'edit_key':
                $raw_scopes  = filter_input( INPUT_POST, 'scopes', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY ) ?? [];
                $scopes      = array_map( 'sanitize_text_field', wp_unslash( $raw_scopes ) );
                $this->handle_edit_key(
                    absint( wp_unslash( $_POST['key_id'] ?? 0 ) ),
                    $scopes,
                    ! empty( $_POST['dry_run_only'] ),
                    sanitize_textarea_field( wp_unslash( $_POST['ip_allowlist'] ?? '' ) ),
                    sanitize_text_field( wp_unslash( $_POST['expires_at'] ?? '' ) ),
                    ! empty( $_POST['rotate_secret'] )
                );
                break;

            case 'archive_key':
                AICOM_Auth::archive_key( absint( wp_unslash( $_POST['key_id'] ?? 0 ) ) );
                wp_safe_redirect( admin_url( 'admin.php?page=aicom-api-keys&archived=' . absint( wp_unslash( $_POST['key_id'] ?? 0 ) ) ) );
                exit;

            case 'unarchive_key':
                AICOM_Auth::unarchive_key( absint( wp_unslash( $_POST['key_id'] ?? 0 ) ) );
                wp_safe_redirect( admin_url( 'admin.php?page=aicom-api-keys&unarchived=' . absint( wp_unslash( $_POST['key_id'] ?? 0 ) ) ) );
                exit;

            case 'delete_backup':
                $this->handle_delete_backup( absint( wp_unslash( $_POST['backup_id'] ?? 0 ) ) );
                break;

            case 'restore_session':
                $this->handle_restore_session( absint( wp_unslash( $_POST['session_id'] ?? 0 ) ) );
                break;

            case 'save_backup_settings':
                update_option( 'aicom_backup_max_age_days', absint( wp_unslash( $_POST['max_age_days'] ?? 0 ) ) );
                update_option( 'aicom_backup_max_size_mb',  absint( wp_unslash( $_POST['max_size_mb']  ?? 0 ) ) );
                wp_safe_redirect( admin_url( 'admin.php?page=aicom-backups&settings_saved=1' ) );
                exit;

            case 'save_schedule':
                $this->handle_save_schedule();
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

    private function handle_save_schedule(): void {
        $enabled   = ! empty( $_POST['schedule_enabled'] );
        $raw_days  = filter_input( INPUT_POST, 'schedule_days', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY ) ?? [];
        $days      = array_values( array_map( 'intval', (array) $raw_days ) );
        $start     = sanitize_text_field( wp_unslash( $_POST['schedule_start'] ?? '09:00' ) );
        $end       = sanitize_text_field( wp_unslash( $_POST['schedule_end']   ?? '18:00' ) );
        $lock_type = in_array( wp_unslash( $_POST['schedule_lock_type'] ?? '' ), [ 'soft_locked', 'hard_locked' ], true )
            ? sanitize_key( wp_unslash( $_POST['schedule_lock_type'] ) )
            : 'soft_locked';

        if ( ! preg_match( '/^\d{2}:\d{2}$/', $start ) ) { $start = '09:00'; }
        if ( ! preg_match( '/^\d{2}:\d{2}$/', $end ) )   { $end   = '18:00'; }

        AICOM_Lock_Manager::set_schedule( [
            'enabled'   => $enabled,
            'days'      => $days,
            'start'     => $start,
            'end'       => $end,
            'lock_type' => $lock_type,
        ] );

        wp_safe_redirect( admin_url( 'admin.php?page=aicom-safety&schedule_saved=1' ) );
        exit;
    }

    private function parse_lines( string $raw ): array {
        return array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', $raw ) ) ) );
    }

    private function apply_resource_restrictions( array &$restrictions ): void {
        $fields = [
            'res_post_types' => 'post_types',
            'res_taxonomies' => 'taxonomies',
            'res_meta_keys'  => 'meta_keys',
            'res_options'    => 'options',
            'res_file_paths' => 'file_paths',
        ];
        foreach ( $fields as $post_key => $restriction_key ) {
            $raw = sanitize_textarea_field( wp_unslash( $_POST[ $post_key ] ?? '' ) );
            if ( $raw !== '' ) {
                $restrictions[ $restriction_key ] = $this->parse_lines( $raw );
            }
        }
        if ( AICOM_Module_Detector::is_polylang_active() ) {
            $raw = sanitize_textarea_field( wp_unslash( $_POST['res_languages'] ?? '' ) );
            if ( $raw !== '' ) {
                $restrictions['languages'] = $this->parse_lines( $raw );
            }
        }
    }

    private function handle_create_key( string $label, array $scopes, bool $dry_run_only, string $ip_allowlist_raw, string $expires_raw = '' ): void {
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
        $this->apply_resource_restrictions( $restrictions );

        $expires_at = $expires_raw ? gmdate( 'Y-m-d 23:59:59', strtotime( $expires_raw ) ) : null;

        $result = AICOM_Auth::create_key( $label, $scopes, $restrictions, $expires_at );

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

    private function handle_edit_key( int $id, array $scopes, bool $dry_run_only, string $ip_raw, string $expires_raw, bool $rotate ): void {
        if ( ! $id ) {
            wp_safe_redirect( admin_url( 'admin.php?page=aicom-api-keys&error=invalid_key' ) );
            exit;
        }

        $restrictions = [];
        if ( $dry_run_only ) {
            $restrictions['dry_run_only'] = true;
        }
        if ( $ip_raw ) {
            $restrictions['ip_allowlist'] = array_filter( array_map( 'trim', explode( "\n", $ip_raw ) ) );
        }
        $this->apply_resource_restrictions( $restrictions );
        $expires_at = $expires_raw ? gmdate( 'Y-m-d 23:59:59', strtotime( $expires_raw ) ) : null;

        $diff    = AICOM_Auth::update_key( $id, $scopes, $restrictions, $expires_at );
        $rotated = false;

        if ( $rotate ) {
            $new_key = AICOM_Auth::rotate_key( $id );
            if ( $new_key ) {
                set_transient( 'aicom_new_key_' . $id, $new_key, 60 );
                $rotated = true;
            }
        }

        set_transient( 'aicom_edit_result_' . $id, wp_json_encode( [
            'added'   => $diff['added'],
            'removed' => $diff['removed'],
            'rotated' => $rotated,
        ] ), 30 );

        wp_safe_redirect( admin_url( 'admin.php?page=aicom-api-keys&edited=' . $id . ( $rotated ? '&rotated=' . $id : '' ) ) );
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

    private function handle_restore_session( int $session_id ): void {
        if ( ! $session_id ) {
            wp_safe_redirect( admin_url( 'admin.php?page=aicom-audit-logs&tab=sessions&restore_error=1' ) );
            exit;
        }
        $restored = self::do_restore_session( $session_id );
        wp_safe_redirect( admin_url( 'admin.php?page=aicom-audit-logs&tab=sessions&restored=' . $restored ) );
        exit;
    }

    /**
     * Core restore logic — replays all backups for a session in reverse order.
     * Returns the number of objects restored.
     * Public so tests can call it directly without going through the POST handler.
     */
    public static function do_restore_session( int $session_id ): int {
        global $wpdb;

        $backups = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}aicom_backups WHERE session_id = %d ORDER BY created_at DESC",
                $session_id
            ),
            ARRAY_A
        );

        $restored = 0;

        foreach ( $backups as $backup ) {
            $payload = json_decode( $backup['payload_json'], true );
            if ( ! $payload ) {
                continue;
            }

            if ( $backup['target_type'] === 'post' && ! empty( $payload['post']['ID'] ) ) {
                $post_data       = $payload['post'];
                $original_id     = (int) $post_data['ID'];
                unset( $post_data['post_modified'], $post_data['post_modified_gmt'] );

                // Check at DB level — get_post() uses object cache which may be stale.
                $exists = (bool) $wpdb->get_var( $wpdb->prepare(
                    "SELECT 1 FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", $original_id
                ) );

                if ( $exists ) {
                    $post_data['ID'] = $original_id;
                    wp_update_post( $post_data );
                } else {
                    // Re-insert with the original ID via import_id (wp_insert_post INSERT branch).
                    unset( $post_data['ID'] );
                    $post_data['import_id'] = $original_id;
                    wp_insert_post( $post_data );
                    $post_data['ID'] = $original_id; // restore for meta/terms below
                }

                foreach ( $payload['meta'] ?? [] as $key => $values ) {
                    delete_post_meta( $original_id, $key );
                    foreach ( (array) $values as $val ) {
                        add_post_meta( $original_id, $key, maybe_unserialize( $val ) );
                    }
                }
                foreach ( $payload['terms'] ?? [] as $tax => $term_ids ) {
                    wp_set_post_terms( $original_id, $term_ids, $tax );
                }
                clean_post_cache( $original_id );
                $restored++;

            } elseif ( $backup['target_type'] === 'term' && ! empty( $payload['term']['term_id'] ) ) {
                $t = $payload['term'];
                wp_update_term( (int) $t['term_id'], $t['taxonomy'] ?? '', [
                    'name'        => $t['name']        ?? '',
                    'slug'        => $t['slug']        ?? '',
                    'description' => $t['description'] ?? '',
                    'parent'      => $t['parent']      ?? 0,
                ] );

                foreach ( $payload['meta'] ?? [] as $key => $values ) {
                    delete_term_meta( (int) $t['term_id'], $key );
                    foreach ( (array) $values as $val ) {
                        add_term_meta( (int) $t['term_id'], $key, maybe_unserialize( $val ) );
                    }
                }
                $restored++;
            }
        }

        return $restored;
    }

    /**
     * Delete old backups per configured age/size caps. Called by daily cron.
     */
    public static function run_backup_cleanup(): void {
        global $wpdb;

        $max_age = (int) get_option( 'aicom_backup_max_age_days', 0 );
        $max_mb  = (int) get_option( 'aicom_backup_max_size_mb',  0 );

        if ( $max_age > 0 ) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}aicom_backups WHERE created_at < DATE_SUB(%s, INTERVAL %d DAY)",
                    current_time( 'mysql', true ),
                    $max_age
                )
            );
        }

        if ( $max_mb > 0 ) {
            $max_bytes = $max_mb * 1024 * 1024;
            for ( $i = 0; $i < 10000; $i++ ) { // hard limit to avoid infinite loop
                $total = (int) $wpdb->get_var( "SELECT SUM(LENGTH(payload_json)) FROM {$wpdb->prefix}aicom_backups" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                if ( $total <= $max_bytes ) {
                    break;
                }
                $oldest = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}aicom_backups ORDER BY created_at ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                if ( ! $oldest ) {
                    break;
                }
                $wpdb->delete( $wpdb->prefix . 'aicom_backups', [ 'id' => $oldest ] );
            }
        }
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

        $lock_status = AICOM_Lock_Manager::get_effective_lock();
        $tb_class    = 'aicom-tb-parent';
        if ( $lock_status === 'hard_locked' ) {
            $tb_class .= ' aicom-tb-hardlocked';
        } elseif ( $lock_status === 'soft_locked' ) {
            $tb_class .= ' aicom-tb-softlocked';
        }

        $bar->add_node( [
            'id'    => 'aicom-toolbar',
            'title' => '<span class="ab-icon dashicons dashicons-rest-api"></span>'
                     . '<span class="ab-label">AICOM Keys</span>'
                     . $count_html,
            'href'  => $manage_url,
            'meta'  => [ 'class' => $tb_class ],
        ] );

        // ── Lock controls (first child) ────────────────────────────────────
        $lock_nonce = wp_create_nonce( 'aicom_toolbar_lock' );
        $bar->add_node( [
            'id'     => 'aicom-toolbar-lock',
            'parent' => 'aicom-toolbar',
            'title'  => '<button class="aicom-tb-lock-btn' . ( $lock_status === 'unlocked' ? ' is-active' : '' ) . '"'
                      . ' data-lock="unlock" data-nonce="' . esc_attr( $lock_nonce ) . '">Unlock</button>'
                      . '<button class="aicom-tb-lock-btn' . ( $lock_status === 'soft_locked' ? ' is-active' : '' ) . '"'
                      . ' data-lock="soft" data-nonce="' . esc_attr( $lock_nonce ) . '">Soft Lock</button>'
                      . '<button class="aicom-tb-lock-btn aicom-tb-lock-hard' . ( $lock_status === 'hard_locked' ? ' is-active' : '' ) . '"'
                      . ' data-lock="hard" data-nonce="' . esc_attr( $lock_nonce ) . '">Hard Lock</button>',
            'href'   => false,
            'meta'   => [ 'class' => 'aicom-tb-lock-row' ],
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

    public static function ajax_save_preset(): void {
        if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json_error( 'bad_nonce', 403 );
        }
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( 'unauthorized', 403 );
        }

        $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        if ( ! $name ) {
            wp_send_json_error( 'missing_name', 400 );
        }

        $raw_scopes = filter_input( INPUT_POST, 'scopes', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY ) ?? [];
        $scopes     = array_values( array_map( 'sanitize_text_field', wp_unslash( $raw_scopes ) ) );
        if ( empty( $scopes ) ) {
            wp_send_json_error( 'no_scopes', 400 );
        }

        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'aicom_presets',
            [
                'name'                => $name,
                'scopes_json'         => wp_json_encode( $scopes ),
                'created_at'          => current_time( 'mysql' ),
                'created_by_user_id'  => get_current_user_id(),
            ]
        );

        wp_send_json_success( [
            'id'     => (int) $wpdb->insert_id,
            'name'   => $name,
            'scopes' => $scopes,
        ] );
    }

    public static function ajax_delete_preset(): void {
        if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json_error( 'bad_nonce', 403 );
        }
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( 'unauthorized', 403 );
        }

        $id = absint( $_POST['preset_id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( 'invalid_id', 400 );
        }

        global $wpdb;
        $deleted = $wpdb->delete( $wpdb->prefix . 'aicom_presets', [ 'id' => $id ] );
        if ( ! $deleted ) {
            wp_send_json_error( 'not_found', 404 );
        }

        wp_send_json_success( [ 'deleted' => $id ] );
    }

    public static function ajax_rename_preset(): void {
        if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json_error( 'bad_nonce', 403 );
        }
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( 'unauthorized', 403 );
        }

        $id   = absint( wp_unslash( $_POST['id'] ?? 0 ) );
        $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        if ( ! $id || ! $name ) {
            wp_send_json_error( 'invalid_data', 400 );
        }

        global $wpdb;
        $updated = $wpdb->update( $wpdb->prefix . 'aicom_presets', [ 'name' => $name ], [ 'id' => $id ] );
        if ( $updated === false ) {
            wp_send_json_error( 'db_error', 500 );
        }

        wp_send_json_success( [ 'id' => $id, 'name' => $name ] );
    }

    public static function ajax_duplicate_preset(): void {
        if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json_error( 'bad_nonce', 403 );
        }
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( 'unauthorized', 403 );
        }

        $id = absint( wp_unslash( $_POST['id'] ?? 0 ) );
        if ( ! $id ) {
            wp_send_json_error( 'invalid_id', 400 );
        }

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aicom_presets WHERE id = %d", $id ),
            ARRAY_A
        );
        if ( ! $row ) {
            wp_send_json_error( 'not_found', 404 );
        }

        $new_name = 'Copy of ' . $row['name'];
        $wpdb->insert( $wpdb->prefix . 'aicom_presets', [
            'name'               => $new_name,
            'scopes_json'        => $row['scopes_json'],
            'created_at'         => current_time( 'mysql', true ),
            'created_by_user_id' => get_current_user_id(),
        ] );
        $new_id = (int) $wpdb->insert_id;
        $scopes = json_decode( $row['scopes_json'], true ) ?: [];

        wp_send_json_success( [
            'id'     => $new_id,
            'name'   => $new_name,
            'scopes' => $scopes,
            'count'  => count( $scopes ),
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

    public static function ajax_toolbar_lock(): void {
        if ( ! check_ajax_referer( 'aicom_toolbar_lock', 'nonce', false ) ) {
            wp_send_json_error( 'bad_nonce', 403 );
        }
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( 'unauthorized', 403 );
        }

        $mode = sanitize_key( wp_unslash( $_POST['lock_mode'] ?? '' ) );
        if ( ! in_array( $mode, [ 'unlock', 'soft', 'hard' ], true ) ) {
            wp_send_json_error( 'invalid_mode', 400 );
        }

        AICOM_Lock_Manager::set_hard_lock( $mode === 'hard' );
        AICOM_Lock_Manager::set_soft_lock( $mode === 'soft' );

        if ( $mode === 'unlock' ) {
            // If schedule would lock us, override it until the next working period
            $next = AICOM_Lock_Manager::get_schedule_next_start();
            if ( $next > 0 ) {
                AICOM_Lock_Manager::set_schedule_override_until( $next );
            } else {
                AICOM_Lock_Manager::clear_schedule_override();
            }
        } else {
            // Manual lock applied — clear any schedule override
            AICOM_Lock_Manager::clear_schedule_override();
        }

        $new_status = AICOM_Lock_Manager::get_effective_lock();
        wp_send_json_success( [
            'lock_status' => $new_status,
            'new_nonce'   => wp_create_nonce( 'aicom_toolbar_lock' ),
        ] );
    }

    // ── Private Helpers ───────────────────────────────────────────────────

    private function require_cap(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'aicom' ) );
        }
    }
}
