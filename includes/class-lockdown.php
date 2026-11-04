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
 *   3. REST writes (POST/PUT/PATCH/DELETE) outside /aicom/* blocked when they
 *      are signed in some other way than the browser's login cookie (JWT,
 *      OAuth, Basic Auth plugins) and carry no valid wp_rest nonce. Visitors
 *      (forms, comments, cart, checkout), signed-in users on the site's own
 *      pages and wp-admin / Gutenberg keep working.
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
     * lets visitors and signed-in browser sessions through, lets requests with
     * a valid wp_rest nonce through (Gutenberg + classic wp-admin), and returns
     * 403 for a write signed in any other way.
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

        // This mode stops administration from outside wp-admin — a write signed in by a non-cookie method (JWT,
        // OAuth, Basic Auth plugins) — never the site's own features (blocking those broke CF7 forms and carts).
        // Visitors: an anonymous write can only do what any visitor may — submit a form (Contact Form 7 drops
        // the nonce on purpose), comment, use the WooCommerce cart and checkout — each limited by its own route.
        if ( ! is_user_logged_in() ) {
            return $result;
        }
        // Signed-in users in their browser: the login cookie. Some routes keep that user signed in without a
        // wp_rest nonce and guard themselves with their own (WooCommerce Store API — a customer's cart and
        // checkout); everywhere else WordPress already treats a cookie without a valid nonce as anonymous.
        $cookie_user = wp_validate_auth_cookie( '', 'logged_in' );
        if ( $cookie_user && (int) $cookie_user === get_current_user_id() ) {
            return $result;
        }

        // Gutenberg and wp-admin attach a wp_rest nonce; verify it.
        $nonce = $request->get_header( 'x_wp_nonce' );
        if ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return $result;
        }

        return new WP_Error(
            'aicom_lockdown_rest_blocked',
            __( 'REST writes signed in from outside the site are disabled — site is in AICOM-only mode. Use the AICOM MCP endpoint or the wp-admin interface.', 'aicom' ),
            [ 'status' => 403 ]
        );
    }
}
