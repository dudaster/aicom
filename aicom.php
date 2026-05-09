<?php
/**
 * Plugin Name:       AICOM - AI Commander
 * Plugin URI:        https://wordpress.org/plugins/aicom/
 * Description:       Let AI agents control your site via MCP. API key auth, scope control, safety locks, audit logging and 87 tools for WP, WooCommerce, Elementor.
 * Version:           2.4.0
 * Author:            dudaster
 * Author URI:        https://profiles.wordpress.org/dudaster/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aicom
 * Domain Path:       /languages
 * Requires PHP:      7.4
 * Requires at least: 6.0
 * Tested up to:      6.9
 */

defined( 'ABSPATH' ) || exit;

// ── Constants ──────────────────────────────────────────────────────────────
define( 'AICOM_VERSION', '2.4.0' );
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
    // Flush rewrite rules so REST route is immediately accessible
    flush_rewrite_rules();
} );

// ── Boot on init ───────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'aicom_boot', 5 );

function aicom_boot(): void {
    // ── Register all module tools ──────────────────────────────────────────
    $modules = [
        new AICOM_Module_WP_Core(),
        new AICOM_Module_Menus(),
        new AICOM_Module_Media(),
        new AICOM_Module_Users(),
        new AICOM_Module_Backup(),
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

    // ── REST Endpoint ──────────────────────────────────────────────────────
    add_action( 'rest_api_init', function (): void {
        register_rest_route( 'aicom/v1', '/mcp', [
            'methods'             => [ 'POST', 'GET' ],
            'callback'            => 'aicom_rest_handler',
            // permission_callback is intentionally open — authentication and authorization
            // are fully handled inside AICOM_Tool_Router::dispatch() via API key validation,
            // scope checks, and lock-state enforcement before any operation is executed.
            'permission_callback' => '__return_true',
        ] );
    } );

    // ── Fallback Endpoint (/index.php?aicom=1) ─────────────────────────
    add_action( 'init', function (): void {
        if ( ! empty( $_GET['aicom'] ) && ( $_GET['aicom'] === '1' ) ) {
            if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'GET' ) {
                // Health check
                wp_send_json( [ 'ok' => true, 'server' => 'AICOM - AI Commander', 'version' => AICOM_VERSION ] );
            }

            $body   = file_get_contents( 'php://input' );
            $result = AICOM_Tool_Router::dispatch( $body ?: '{}' );

            wp_send_json( $result );
        }
    } );
}

// ── REST Handler ───────────────────────────────────────────────────────────

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
    $result = AICOM_Tool_Router::dispatch( $body ?: '{}' );

    $http_status = isset( $result['error'] ) ? ( $result['error']['http_status'] ?? 400 ) : 200;
    unset( $result['error']['http_status'] );

    return new WP_REST_Response( $result, 200 ); // Always HTTP 200; errors encoded in body per MCP convention
}
