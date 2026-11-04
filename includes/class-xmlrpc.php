<?php
/**
 * "Disable XML-RPC" — its own setting, separate from AICOM-only mode (which also turns XML-RPC off).
 *
 * xmlrpc.php is a common brute-force target (many password guesses in one request) and rarely needed
 * today; AICOMBase reports it as finding SEC-EXP-003. Site owners can turn it off here (Safety page),
 * through the wp.security.xmlrpc.set tool, or from AICOMBase's one-click fix — which is why the tool
 * snapshots the option first (target_type "option"), so Undo in AICOMBase puts it back.
 *
 * Jetpack and the WordPress mobile app need XML-RPC: the tool refuses while a plugin that needs it is
 * active, unless forced.
 */
defined( 'ABSPATH' ) || exit;

class AICOM_Xmlrpc {

    const OPTION = 'aicom_xmlrpc_disabled';

    /** Off by this setting (not counting AICOM-only mode). */
    public static function is_disabled_by_setting(): bool {
        return (bool) get_option( self::OPTION, false );
    }

    public static function set_disabled( bool $disabled ): void {
        update_option( self::OPTION, $disabled ? 1 : 0 );
    }

    /** What visitors get: off by this setting or by AICOM-only mode. */
    public static function is_off(): bool {
        return self::is_disabled_by_setting() || AICOM_Lockdown::is_enabled();
    }

    /**
     * Active plugins known to need XML-RPC, as [ plugin file => name ]. Filterable for other
     * XML-RPC clients: `aicom_xmlrpc_needed_by`.
     */
    public static function needed_by(): array {
        $known = [
            'jetpack/jetpack.php' => 'Jetpack',
        ];
        $active = (array) get_option( 'active_plugins', [] );
        if ( is_multisite() ) {
            $active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
        }
        $needed = array_intersect_key( $known, array_flip( $active ) );
        return (array) apply_filters( 'aicom_xmlrpc_needed_by', $needed );
    }

    /** plugins_loaded, priority 1 — before the XML-RPC server is built. */
    public static function bootstrap(): void {
        if ( self::is_disabled_by_setting() ) {
            self::block( __( 'XML-RPC is disabled on this site.', 'aicom' ) );
        }
    }

    /**
     * Refuse xmlrpc.php outright and report XML-RPC as off. The `xmlrpc_enabled` filter alone only stops
     * authenticated methods; system.listMethods (defined in IXR_Server) is not filterable, so it would
     * still leak the method list — hence the 403 before WordPress builds the XML-RPC server.
     */
    public static function block( string $message ): void {
        if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
            status_header( 403 );
            header( 'Content-Type: text/plain; charset=UTF-8' );
            echo esc_html( $message );
            exit;
        }
        add_filter( 'xmlrpc_enabled', '__return_false' );
    }
}
