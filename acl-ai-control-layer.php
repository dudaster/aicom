<?php
/**
 * Plugin Name:  ACL - AI Control Layer
 * Plugin URI:   https://github.com/openclaw/acl-ai-control-layer
 * Description:  Standalone MCP server for OpenClaw. Exposes WordPress operations via a secure HTTP endpoint with API keys, scope control, lock management, and full audit logging.
 * Version:      2.0.0
 * Author:       dudaster
 * Requires PHP: 8.1
 * Requires at least: 6.0
 */

defined( 'ABSPATH' ) || exit;

// ── Constants ──────────────────────────────────────────────────────────────
define( 'ACL_VERSION', '2.0.0' );
define( 'ACL_DIR',     plugin_dir_path( __FILE__ ) );
define( 'ACL_URL',     plugin_dir_url( __FILE__ ) );

// ── Autoloader ─────────────────────────────────────────────────────────────
spl_autoload_register( function ( string $class ): void {
    // Only handle ACL_ prefixed classes
    if ( strpos( $class, 'ACL_' ) !== 0 ) {
        return;
    }

    // Strip 'ACL_' → remaining suffix, e.g. "Module_WP_Core", "Admin", "DB", "Module_Detector"
    $suffix = substr( $class, strlen( 'ACL_' ) );
    $slug   = strtolower( str_replace( '_', '-', $suffix ) ); // "module-wp-core", "admin", "db", "module-detector"

    // Candidate paths — checked in order:
    //   1. includes/class-{slug}.php          (e.g. class-module-detector.php, class-db.php)
    //   2. modules/class-{slug}.php           (e.g. class-module-wp-core.php — unlikely)
    //   3. admin/class-{slug}.php             (e.g. class-admin.php)
    //   4. modules/class-{suffix_no_module}.php  (e.g. class-wp-core.php from Module_WP_Core)
    $candidates = [
        ACL_DIR . "includes/class-$slug.php",
        ACL_DIR . "modules/class-$slug.php",
        ACL_DIR . "admin/class-$slug.php",
    ];

    // For Module_* classes, also try modules/class-{without-module-prefix}.php
    if ( strpos( $suffix, 'Module_' ) === 0 ) {
        $inner = substr( $suffix, strlen( 'Module_' ) ); // "WP_Core", "Base", "Elementor"...
        $inner_slug = strtolower( str_replace( '_', '-', $inner ) );
        $candidates[] = ACL_DIR . "modules/class-$inner_slug.php";
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
    ACL_DB::install();
    // Flush rewrite rules so REST route is immediately accessible
    flush_rewrite_rules();
} );

// ── Boot on init ───────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'acl_boot', 5 );

function acl_boot(): void {
    // ── Register all module tools ──────────────────────────────────────────
    $modules = [
        new ACL_Module_WP_Core(),
        new ACL_Module_Menus(),
        new ACL_Module_Media(),
        new ACL_Module_Users(),
        new ACL_Module_Backup(),
    ];

    // Conditional modules (only instantiate if dependency is active)
    if ( ACL_Module_Detector::is_woocommerce_active() ) {
        $modules[] = new ACL_Module_WooCommerce();
    }
    if ( ACL_Module_Detector::is_elementor_active() ) {
        $modules[] = new ACL_Module_Elementor();
    }
    if ( ACL_Module_Detector::is_polylang_active() ) {
        $modules[] = new ACL_Module_Polylang();
    }

    foreach ( $modules as $module ) {
        $module->register_tools();
    }

    // ── Admin ──────────────────────────────────────────────────────────────
    if ( is_admin() ) {
        new ACL_Admin();
    }

    // ── REST Endpoint ──────────────────────────────────────────────────────
    add_action( 'rest_api_init', function (): void {
        register_rest_route( 'acl/v1', '/mcp', [
            'methods'             => [ 'POST', 'GET' ],
            'callback'            => 'acl_rest_handler',
            'permission_callback' => '__return_true', // Auth handled inside router
        ] );
    } );

    // ── Fallback Endpoint (/index.php?acl=1) ─────────────────────────
    add_action( 'init', function (): void {
        if ( ! empty( $_GET['acl'] ) && ( $_GET['acl'] === '1' ) ) {
            if ( $_SERVER['REQUEST_METHOD'] === 'GET' ) {
                // Health check
                wp_send_json( [ 'ok' => true, 'server' => 'ACL - AI Control Layer', 'version' => ACL_VERSION ] );
            }

            $body   = file_get_contents( 'php://input' );
            $result = ACL_Tool_Router::dispatch( $body ?: '{}' );

            wp_send_json( $result );
        }
    } );
}

// ── REST Handler ───────────────────────────────────────────────────────────

function acl_rest_handler( WP_REST_Request $request ): WP_REST_Response {
    // GET = health/status endpoint
    if ( $request->get_method() === 'GET' ) {
        return new WP_REST_Response( [
            'ok'      => true,
            'server'  => 'ACL - AI Control Layer',
            'version' => ACL_VERSION,
            'lock'    => ACL_Lock_Manager::get_state(),
        ] );
    }

    // POST = MCP dispatch
    $body   = $request->get_body();
    $result = ACL_Tool_Router::dispatch( $body ?: '{}' );

    $http_status = isset( $result['error'] ) ? ( $result['error']['http_status'] ?? 400 ) : 200;
    unset( $result['error']['http_status'] );

    return new WP_REST_Response( $result, 200 ); // Always HTTP 200; errors encoded in body per MCP convention
}
