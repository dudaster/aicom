<?php
/**
 * AICOM Lockdown — closes the non-AICOM write paths so that AI agents can
 * only modify the site through the AICOM MCP endpoint (audited, scoped,
 * session-tracked). Three filters are registered when the option is on:
 *
 *   1. Application Passwords disabled site-wide.
 *      → Kills REST + Basic Auth used by external clients that don't know
 *        about AICOM.
 *   2. XML-RPC disabled.
 *      → Kills the legacy /xmlrpc.php endpoint.
 *   3. REST writes (POST/PUT/PATCH/DELETE) blocked outside /aicom/* unless
 *      the request carries a valid wp_rest nonce (which Gutenberg / wp-admin
 *      send, so the human editor keeps working).
 *
 * AICOM tool handlers call wp_insert_post() etc. directly inside the PHP
 * process — those calls never traverse the REST API, so they are not
 * affected by the rest_pre_dispatch filter. The router pipeline (auth,
 * scope, lock, session, audit) still applies.
 *
 * Option key: aicom_lockdown_mode (boolean, default false).
 */
class AICOM_Lockdown {

    const OPTION = 'aicom_lockdown_mode';

    public static function is_enabled(): bool {
        return (bool) get_option( self::OPTION, false );
    }

    /**
     * Idempotent enable/disable for the option. Returns the new state.
     */
    public static function set_enabled( bool $on ): bool {
        update_option( self::OPTION, $on ? 1 : 0 );
        return $on;
    }

    /**
     * Hook into init. Registers the three filters only when the option is on.
     */
    public static function bootstrap(): void {
        if ( ! self::is_enabled() ) {
            return;
        }

        // Hard-block XML-RPC entirely. The `xmlrpc_enabled` filter only stops
        // authenticated methods; system.listMethods (defined in IXR_Server) is
        // not filterable, so it still leaks the method list. Honest fix is to
        // refuse the request before WP loads the XML-RPC server.
        if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
            status_header( 403 );
            header( 'Content-Type: text/plain; charset=UTF-8' );
            echo 'XML-RPC is disabled — site is in AICOM-only mode.';
            exit;
        }

        add_filter( 'wp_is_application_passwords_available', '__return_false' );
        add_filter( 'xmlrpc_enabled', '__return_false' );
        add_filter( 'rest_pre_dispatch', [ self::class, 'gate_rest_writes' ], 10, 3 );
    }

    /**
     * REST gatekeeper. Lets reads through, lets AICOM's own namespace through,
     * lets requests with a valid wp_rest nonce through (Gutenberg + classic
     * wp-admin), and returns 403 for anything else attempting a write.
     */
    public static function gate_rest_writes( $result, $server, WP_REST_Request $request ) {
        // Already short-circuited by another plugin/filter — don't override.
        if ( $result !== null ) {
            return $result;
        }

        $method = strtoupper( $request->get_method() );
        if ( in_array( $method, [ 'GET', 'HEAD', 'OPTIONS' ], true ) ) {
            return $result;
        }

        $route = (string) $request->get_route();

        // AICOM's own REST endpoints (/wp-json/aicom/*) — the MCP entry point.
        if ( strpos( $route, '/aicom/' ) === 0 ) {
            return $result;
        }

        // Gutenberg and wp-admin attach a wp_rest nonce; verify it.
        $nonce = $request->get_header( 'x_wp_nonce' );
        if ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return $result;
        }

        return new WP_Error(
            'aicom_lockdown_rest_blocked',
            __( 'External REST writes are disabled — site is in AICOM-only mode. Use the AICOM MCP endpoint or the wp-admin interface.', 'aicom' ),
            [ 'status' => 403 ]
        );
    }
}
