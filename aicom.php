<?php
/**
 * Plugin Name:       AICOM - AI Commander
 * Plugin URI:        https://wordpress.org/plugins/aicom/
 * Description:       Use your AI subscription to manage WordPress: create Elementor pages, update content, automate tasks, and stay fully in control.
 * Version:           3.12.0
 * Author:            dudaster
 * Author URI:        https://profiles.wordpress.org/dudaster/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aicom
 * Domain Path:       /languages
 * Requires PHP:      7.4
 * Requires at least: 6.0
 * Tested up to:      7.1
 */

defined( 'ABSPATH' ) || exit;

// ── Constants ──────────────────────────────────────────────────────────────
define( 'AICOM_VERSION', '3.12.0' );
define( 'AICOM_DIR',     plugin_dir_path( __FILE__ ) );
define( 'AICOM_URL',     plugin_dir_url( __FILE__ ) );

// ── Autoloader ─────────────────────────────────────────────────────────────
spl_autoload_register( function ( string $class ): void {
    // Only handle AICOM_ prefixed classes
    if ( strpos( $class, 'AICOM_' ) !== 0 ) {
        return;
    }

    // Strip 'AICOM_' → remaining suffix, e.g. "Module_WP_Core", "Admin", "DB", "Module_Detector"
    $suffix = substr( $class, strlen( 'AICOM_' ) );
    $slug   = strtolower( str_replace( '_', '-', $suffix ) ); // "module-wp-core", "admin", "db", "module-detector"

    // Candidate paths — checked in order:
    //   1. includes/class-{slug}.php          (e.g. class-module-detector.php, class-db.php)
    //   2. modules/class-{slug}.php           (e.g. class-module-wp-core.php — unlikely)
    //   3. admin/class-{slug}.php             (e.g. class-admin.php)
    //   4. modules/class-{suffix_no_module}.php  (e.g. class-wp-core.php from Module_WP_Core)
    $candidates = [
        AICOM_DIR . "includes/class-$slug.php",
        AICOM_DIR . "modules/class-$slug.php",
        AICOM_DIR . "admin/class-$slug.php",
    ];

    // For Module_* classes, also try modules/class-{without-module-prefix}.php
    if ( strpos( $suffix, 'Module_' ) === 0 ) {
        $inner = substr( $suffix, strlen( 'Module_' ) ); // "WP_Core", "Base", "Elementor"...
        $inner_slug = strtolower( str_replace( '_', '-', $inner ) );
        $candidates[] = AICOM_DIR . "modules/class-$inner_slug.php";
    }

    foreach ( $candidates as $path ) {
        if ( file_exists( $path ) ) {
            require_once $path;
            return;
        }
    }
} );

// ── Activation Hook ────────────────────────────────────────────────────────
register_activation_hook( __FILE__, function (): void {
    AICOM_DB::install();
    flush_rewrite_rules();
    if ( ! wp_next_scheduled( 'aicom_expire_keys' ) ) {
        wp_schedule_event( time(), 'hourly', 'aicom_expire_keys' );
    }
    if ( ! wp_next_scheduled( 'aicom_cleanup_backups' ) ) {
        wp_schedule_event( time(), 'daily', 'aicom_cleanup_backups' );
    }
    if ( ! wp_next_scheduled( 'aicom_hub_sync' ) ) {
        wp_schedule_event( time(), 'hourly', 'aicom_hub_sync' );
    }
    if ( ! wp_next_scheduled( 'aicom_hub_nonce_gc' ) ) {
        wp_schedule_event( time(), 'daily', 'aicom_hub_nonce_gc' );
    }
    if ( ! wp_next_scheduled( 'aicom_idempotency_gc' ) ) {
        wp_schedule_event( time(), 'daily', 'aicom_idempotency_gc' );
    }
} );

// ── Deactivation Hook ──────────────────────────────────────────────────────
register_deactivation_hook( __FILE__, function (): void {
    wp_clear_scheduled_hook( 'aicom_expire_keys' );
    wp_clear_scheduled_hook( 'aicom_cleanup_backups' );
    wp_clear_scheduled_hook( 'aicom_hub_sync' );
    wp_clear_scheduled_hook( 'aicom_hub_nonce_gc' );
    wp_clear_scheduled_hook( 'aicom_idempotency_gc' );
} );

// ── Auto-migrate on version mismatch (e.g. plugin update without deactivate/activate) ─
add_action( 'plugins_loaded', function (): void {
    if ( get_option( AICOM_DB::VERSION_OPT ) !== AICOM_DB::DB_VERSION ) {
        AICOM_DB::install();
    }
}, 1 );

// ── Boot on init ───────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function (): void {
    load_plugin_textdomain( 'aicom', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}, 1 );

add_action( 'plugins_loaded', 'aicom_boot', 5 );

// AICOM-only mode (off by default): closes Application Passwords, XML-RPC, and
// unsigned REST writes so AI agents can only modify the site through AICOM.
// Registered on plugins_loaded so the xmlrpc_methods filter is in place before
// wp_xmlrpc_server applies it during set_callbacks().
add_action( 'plugins_loaded', [ 'AICOM_Lockdown', 'bootstrap' ], 1 );

// ── HTTP response reliability ────────────────────────────────────────────
// $aicom_dispatching is true only while we are actually inside one of our own
// HTTP entry points (aicom_safe_dispatch() below) — the shutdown guard uses it
// so a fatal in a completely unrelated page load is never mistaken for ours.
$GLOBALS['aicom_dispatching'] = false;

/**
 * Call AICOM_Tool_Router::dispatch() with an output-buffer guard so a stray
 * PHP notice/warning from another active plugin (this install runs Elementor,
 * WooCommerce, Yoast, ACF alongside AICOM) can never corrupt the JSON body —
 * a corrupted body reads to a client as a hang, not a clean error. Every real
 * HTTP entry point below calls this instead of AICOM_Tool_Router::dispatch()
 * directly. See the shutdown handler below for the true-PHP-fatal case, which
 * this buffer alone cannot catch.
 */
function aicom_safe_dispatch( string $body, string $protocol_version_header = '' ): array {
    $GLOBALS['aicom_dispatching'] = true;

    ob_start();
    $result = AICOM_Tool_Router::dispatch( $body, $protocol_version_header );
    $stray  = ob_get_clean();

    if ( $stray !== '' && $stray !== false ) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate diagnostic, not left-over debug code
        error_log( 'AICOM: discarded stray output during MCP dispatch: ' . substr( $stray, 0, 500 ) );
    }

    $GLOBALS['aicom_dispatching'] = false;
    return $result;
}

// A true PHP fatal (not a catchable Throwable — parse error, memory exhaustion)
// inside dispatch() stops execution before aicom_safe_dispatch() ever reaches
// its own ob_get_clean(), and PHP's default shutdown behavior would flush
// whatever partial/HTML content was buffered with a 200 status. This is the
// actual fix for "malformed body causes client hang" — the buffer above only
// catches non-fatal stray output. Infra-level timeouts (PHP-FPM
// request_terminate_timeout, nginx/Apache) are out of reach here and remain a
// deployment-config concern.
register_shutdown_function( function (): void {
    if ( empty( $GLOBALS['aicom_dispatching'] ) || headers_sent() ) {
        return;
    }
    $error = error_get_last();
    if ( ! $error || ! in_array( $error['type'], [ E_ERROR, E_PARSE, E_COMPILE_ERROR ], true ) ) {
        return;
    }
    while ( ob_get_level() > 0 ) {
        ob_end_clean();
    }
    header( 'Content-Type: application/json; charset=utf-8' );
    $body = [
        'jsonrpc' => '2.0',
        'error'   => [ 'code' => -32603, 'message' => 'Internal server error', 'data' => [ 'code' => 'INTERNAL_ERROR' ] ],
    ];
    // wp_json_encode() may not be available if the fatal happened before WP
    // finished loading — fall back to the native encoder for this last resort.
    echo function_exists( 'wp_json_encode' ) ? wp_json_encode( $body ) : json_encode( $body );
} );

function aicom_boot(): void {
    // Fallback: schedule cron if plugin was already active before this version
    if ( ! wp_next_scheduled( 'aicom_expire_keys' ) ) {
        wp_schedule_event( time(), 'hourly', 'aicom_expire_keys' );
    }
    if ( ! wp_next_scheduled( 'aicom_cleanup_backups' ) ) {
        wp_schedule_event( time(), 'daily', 'aicom_cleanup_backups' );
    }
    if ( ! wp_next_scheduled( 'aicom_hub_sync' ) ) {
        wp_schedule_event( time(), 'hourly', 'aicom_hub_sync' );
    }
    if ( ! wp_next_scheduled( 'aicom_hub_nonce_gc' ) ) {
        wp_schedule_event( time(), 'daily', 'aicom_hub_nonce_gc' );
    }
    if ( ! wp_next_scheduled( 'aicom_idempotency_gc' ) ) {
        wp_schedule_event( time(), 'daily', 'aicom_idempotency_gc' );
    }
    add_action( 'aicom_expire_keys',      function() { AICOM_Sessions::close_stale( 2 ); } );
    add_action( 'aicom_cleanup_backups',  [ 'AICOM_Admin', 'run_backup_cleanup' ] );
    add_action( 'aicom_hub_sync',         [ 'AICOM_Hub_Channel', 'cron_push_all' ] );
    add_action( 'aicom_hub_nonce_gc',     [ 'AICOM_Hub_Pairing', 'gc_nonces' ] );
    add_action( 'aicom_idempotency_gc',   [ 'AICOM_Idempotency', 'gc' ] );
    // ── Register all module tools ──────────────────────────────────────────
    $modules = [
        new AICOM_Module_Session(),
        new AICOM_Module_WP_Core(),
        new AICOM_Module_Menus(),
        new AICOM_Module_Media(),
        new AICOM_Module_Users(),
        new AICOM_Module_Backup(),
        new AICOM_Module_A11y(),
        new AICOM_Module_Skills(),
    ];

    // Conditional modules (only instantiate if dependency is active)
    if ( AICOM_Module_Detector::is_woocommerce_active() ) {
        $modules[] = new AICOM_Module_WooCommerce();
    }
    if ( AICOM_Module_Detector::is_elementor_active() ) {
        $modules[] = new AICOM_Module_Elementor();
    }
    if ( AICOM_Module_Detector::is_polylang_active() ) {
        $modules[] = new AICOM_Module_Polylang();
    }
    if ( AICOM_Module_Detector::is_ecs_active() ) {
        $modules[] = new AICOM_Module_ECS();
    }
    if ( AICOM_Module_Detector::is_clautron_active() ) {
        $modules[] = new AICOM_Module_Clautron();
    }
    if ( AICOM_Module_Detector::is_yoast_active() ) {
        $modules[] = new AICOM_Module_Yoast();
    }

    foreach ( $modules as $module ) {
        $module->register_tools();
    }

    // ── Admin ──────────────────────────────────────────────────────────────
    if ( is_admin() ) {
        new AICOM_Admin();
    }

    // ── Admin Bar (fires on both admin and frontend for logged-in admins) ──
    add_action( 'admin_bar_menu',               [ 'AICOM_Admin', 'register_admin_bar' ], 100 );
    add_action( 'wp_ajax_aicom_toolbar_toggle', [ 'AICOM_Admin', 'ajax_toolbar_toggle' ] );
    add_action( 'wp_ajax_aicom_toolbar_lock',   [ 'AICOM_Admin', 'ajax_toolbar_lock' ] );
    add_action( 'wp_ajax_aicom_save_preset',      [ 'AICOM_Admin', 'ajax_save_preset' ] );
    add_action( 'wp_ajax_aicom_delete_preset',    [ 'AICOM_Admin', 'ajax_delete_preset' ] );
    add_action( 'wp_ajax_aicom_rename_preset',    [ 'AICOM_Admin', 'ajax_rename_preset' ] );
    add_action( 'wp_ajax_aicom_duplicate_preset', [ 'AICOM_Admin', 'ajax_duplicate_preset' ] );

    // Enqueue toolbar JS/CSS on frontend too (admin_enqueue_scripts only fires in /wp-admin)
    add_action( 'wp_enqueue_scripts', function (): void {
        if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        wp_enqueue_style( 'aicom-admin', AICOM_URL . 'assets/admin.css', [], AICOM_VERSION );
        wp_enqueue_script( 'aicom-admin', AICOM_URL . 'assets/admin.js', [ 'jquery' ], AICOM_VERSION, true );
        wp_localize_script( 'aicom-admin', 'AICOM_MCP', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        ] );
    } );

    // ── Bypass WP JSON validation for /mcp ────────────────────────────────
    // WP_REST_Server validates Content-Type: application/json bodies before
    // calling our callback. Malformed JSON (e.g. from weak local models that
    // mis-escape content) would produce an opaque rest_invalid_json error
    // instead of our MCP-formatted error. We intercept the route here so our
    // router handles the raw body directly and returns a proper MCP error.
    add_filter( 'rest_pre_dispatch', function ( $result, $server, $request ) {
        $route = $request->get_route();
        if ( $route !== '/aicom/v1/mcp' ) {
            return $result;
        }
        if ( $request->get_method() === 'GET' ) {
            return new WP_REST_Response( [
                'ok'      => true,
                'server'  => 'AICOM - AI Commander',
                'version' => AICOM_VERSION,
                'lock'    => AICOM_Lock_Manager::get_state(),
            ] );
        }
        $body   = $request->get_body();
        $data   = aicom_safe_dispatch( $body ?: '{}', (string) $request->get_header( 'MCP-Protocol-Version' ) );
        return new WP_REST_Response( $data, 200 );
    }, 10, 3 );

    // ── REST Endpoints ─────────────────────────────────────────────────────
    add_action( 'rest_api_init', function (): void {
        // Main MCP endpoint
        register_rest_route( 'aicom/v1', '/mcp', [
            'methods'             => [ 'POST', 'GET' ],
            'callback'            => 'aicom_rest_handler',
            // permission_callback is intentionally open — authentication and authorization
            // are fully handled inside AICOM_Tool_Router::dispatch() via API key validation,
            // scope checks, and lock-state enforcement before any operation is executed.
            'permission_callback' => '__return_true',
        ] );

        // OpenAPI schema — for ChatGPT Custom GPT Actions import
        register_rest_route( 'aicom/v1', '/schema', [
            'methods'             => 'GET',
            'callback'            => 'aicom_schema_handler',
            'permission_callback' => '__return_true',
        ] );

        // Individual tool endpoints — REST wrapper around the MCP dispatcher
        register_rest_route( 'aicom/v1', '/tools/(?P<tool>[a-zA-Z0-9._-]+)', [
            'methods'             => 'POST',
            'callback'            => 'aicom_tool_handler',
            'permission_callback' => '__return_true',
            'args'                => [
                'tool' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        // Hub ↔ Local management channel (PRD §16) — /pair and /management.
        AICOM_Hub_Channel::register_routes();
    } );

    // ── Fallback Endpoint (/index.php?aicom=1) ─────────────────────────
    add_action( 'init', function (): void {
        if ( ! empty( $_GET['aicom'] ) && ( $_GET['aicom'] === '1' ) ) {
            if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'GET' ) {
                // Health check
                wp_send_json( [ 'ok' => true, 'server' => 'AICOM - AI Commander', 'version' => AICOM_VERSION ] );
            }

            $body             = file_get_contents( 'php://input' );
            $header_protocol  = isset( $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ) ) : '';
            $result           = aicom_safe_dispatch( $body ?: '{}', $header_protocol );

            wp_send_json( $result );
        }
    } );
}

// ── REST Handlers ──────────────────────────────────────────────────────────

function aicom_rest_handler( WP_REST_Request $request ): WP_REST_Response {
    // GET = health/status endpoint
    if ( $request->get_method() === 'GET' ) {
        return new WP_REST_Response( [
            'ok'      => true,
            'server'  => 'AICOM - AI Commander',
            'version' => AICOM_VERSION,
            'lock'    => AICOM_Lock_Manager::get_state(),
        ] );
    }

    // POST = MCP dispatch
    $body   = $request->get_body();
    $result = aicom_safe_dispatch( $body ?: '{}', (string) $request->get_header( 'MCP-Protocol-Version' ) );

    $http_status = isset( $result['error'] ) ? ( $result['error']['http_status'] ?? 400 ) : 200;
    unset( $result['error']['http_status'] );

    return new WP_REST_Response( $result, 200 ); // Always HTTP 200; errors encoded in body per MCP convention
}

function aicom_schema_handler( WP_REST_Request $request ): WP_REST_Response {
    $schema = AICOM_Schema_Generator::generate();
    $response = new WP_REST_Response( $schema, 200 );
    $response->header( 'Content-Type', 'application/json' );
    return $response;
}

function aicom_tool_handler( WP_REST_Request $request ): WP_REST_Response {
    $tool = $request->get_param( 'tool' );
    $args = $request->get_json_params() ?: [];

    $body = wp_json_encode( [
        'jsonrpc' => '2.0',
        'method'  => 'tools/call',
        'params'  => [ 'name' => $tool, 'arguments' => $args ],
        'id'      => 1,
    ] );

    $result = aicom_safe_dispatch( $body, (string) $request->get_header( 'MCP-Protocol-Version' ) );
    return new WP_REST_Response( $result, 200 );
}
