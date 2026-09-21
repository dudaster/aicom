<?php
/**
 * AICOMBase admin: "AICOMBase" page (connect card, status, disconnect, settings, local policy).
 *
 * All actions are capability-checked (manage_options) and nonce-protected. Secrets are never
 * rendered: the page shows public identifiers only (key id, truncated site id).
 */
defined( 'ABSPATH' ) || exit;

class AICOM_Base_Admin {

    const PAGE  = 'aicom-base';
    const NONCE = 'aicom_base_admin';

    public function __construct() {
        add_action( 'admin_menu',                        [ $this, 'register_menu' ], 20 );
        add_action( 'wp_ajax_aicom_base_connect',        [ $this, 'ajax_connect' ] );
        add_action( 'wp_ajax_aicom_base_status',         [ $this, 'ajax_status' ] );
        add_action( 'wp_ajax_aicom_base_cancel',         [ $this, 'ajax_cancel' ] );
        add_action( 'admin_post_aicom_base_action',      [ $this, 'handle_post' ] );
    }

    public function register_menu(): void {
        add_submenu_page( AICOM_Admin::MENU_SLUG, __( 'AICOMBase', 'aicom' ), __( 'AICOMBase', 'aicom' ), AICOM_Admin::CAPABILITY, self::PAGE, [ $this, 'render_page' ] );
    }

    public function render_page(): void {
        if ( ! current_user_can( AICOM_Admin::CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'aicom' ), 403 );
        }
        require AICOM_DIR . 'admin/pages/base.php';
    }

    // ── AJAX ──────────────────────────────────────────────────────────────

    private function ajax_guard(): void {
        if ( ! current_user_can( AICOM_Admin::CAPABILITY ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'aicom' ) ], 403 );
        }
        check_ajax_referer( self::NONCE, 'nonce' );
    }

    public function ajax_connect(): void {
        $this->ajax_guard();
        AICOM_Base_State::update( [ 'notice' => '' ] );
        $r = AICOM_Base_Connection::begin();
        if ( empty( $r['ok'] ) ) {
            AICOM_Base_State::update( [ 'notice' => (string) ( $r['message'] ?? '' ) ] );
            wp_send_json_error( [ 'message' => (string) ( $r['message'] ?? __( 'Could not start pairing.', 'aicom' ) ) ] );
        }
        wp_send_json_success( [ 'connect_url' => $r['connect_url'] ] );
    }

    /** Polled by the admin page while a pairing is pending (also advances the pairing). */
    public function ajax_status(): void {
        $this->ajax_guard();
        $poll = [];
        if ( AICOM_Base_State::status() === AICOM_Base_State::S_PAIRING ) {
            $poll = AICOM_Base_Connection::poll();
        }
        wp_send_json_success( [
            'state'   => AICOM_Base_Connection::ui_state(),
            'poll'    => $poll['state'] ?? '',
            'message' => (string) ( $poll['message'] ?? AICOM_Base_State::get( 'notice', '' ) ),
        ] );
    }

    public function ajax_cancel(): void {
        $this->ajax_guard();
        if ( AICOM_Base_State::status() === AICOM_Base_State::S_PAIRING ) {
            AICOM_Base_Connection::disconnect( 'pairing_cancelled' );
        }
        wp_send_json_success();
    }

    // ── admin-post actions ────────────────────────────────────────────────

    public function handle_post(): void {
        if ( ! current_user_can( AICOM_Admin::CAPABILITY ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'aicom' ), 403 );
        }
        check_admin_referer( self::NONCE );
        $action = sanitize_key( wp_unslash( $_POST['base_action'] ?? '' ) );
        $msg    = '';

        switch ( $action ) {
            case 'disconnect':
                AICOM_Base_Connection::disconnect( 'user' );
                $msg = 'disconnected';
                break;

            case 'sync_now':
                $r   = AICOM_Base_Heartbeat::run( 'manual' );
                $msg = ! empty( $r['ok'] ) ? 'synced' : 'sync_failed';
                break;

            case 'pause':
            case 'resume':
                if ( AICOM_Base_State::is_connected() ) {
                    $paused = $action === 'pause';
                    AICOM_Base_State::update( [ 'paused' => $paused ] );
                    AICOM_Base_Connection::audit( $paused ? 'base.paused' : 'base.resumed', $paused ? 'blocked' : 'success', [ 'paused' => $paused, 'by' => 'local_admin' ] );
                    AICOM_Base_Heartbeat::run( 'manual' ); // tell AICOMBase right away
                }
                $msg = $action === 'pause' ? 'paused' : 'resumed';
                break;

            case 'save_settings':
                if ( ! AICOM_Base_State::base_url_locked_by_constant() && ! AICOM_Base_State::is_connected() && isset( $_POST['base_url'] ) ) {
                    $err = AICOM_Base_State::set_base_url( sanitize_text_field( wp_unslash( $_POST['base_url'] ) ) );
                    if ( $err !== '' ) {
                        set_transient( 'aicom_base_admin_error', $err, 60 );
                        $msg = 'url_error';
                        break;
                    }
                }
                $raw    = filter_input( INPUT_POST, 'allowed_scopes', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY ) ?? [];
                $scopes = array_map( 'sanitize_text_field', wp_unslash( (array) $raw ) );
                AICOM_Base_Policy::set_allowed_scopes( $scopes );
                AICOM_Base_Connection::audit( 'base.policy_updated', 'success', [ 'allowed_scopes' => count( AICOM_Base_Policy::allowed_scopes() ) ] );
                $msg = 'saved';
                break;
        }
        wp_safe_redirect( add_query_arg( 'base_msg', $msg, admin_url( 'admin.php?page=' . self::PAGE ) ) );
        exit;
    }
}
