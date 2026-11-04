<?php
/**
 * Local policy for AICOMBase-issued work (PROTOCOL §9, PRD §14).
 *
 * AICOMBase policy and LOCAL policy must BOTH allow an action. The local cap is:
 *   1. every scope in a token must exist in AICOM's authoritative scope tree
 *      (AICOM_Auth::scope_tree()) — unknown scopes are refused outright;
 *   2. every scope must be inside the admin-configured allow-list for remote work
 *      (default: everything except `critical`-risk scopes such as plugin/settings management);
 *   3. the usual Tool Router gates (lock matrix, tool scopes, confirm flag) still apply at execution.
 */
defined( 'ABSPATH' ) || exit;

class AICOM_Base_Policy {

    const OPT_ALLOWED = 'aicom_base_allowed_scopes';
    /** Every scope that existed when the admin last saved the allow-list — what they were shown. */
    const OPT_SEEN = 'aicom_base_scopes_seen';
    /** Scopes added since lists were saved without OPT_SEEN; such a list was never offered these. */
    const ADDED_SINCE_SEEN = [ 'manage.security.hardening' ];

    /** Scopes AICOMBase may request on this site. */
    public static function allowed_scopes(): array {
        $stored = get_option( self::OPT_ALLOWED, null );
        if ( ! is_array( $stored ) ) {
            return self::default_scopes();
        }
        // A scope added after the admin last saved this list wasn't offered to them: it follows the default
        // (allowed unless critical) instead of being refused on every site that was set up before it existed.
        $seen = get_option( self::OPT_SEEN, null );
        $seen = is_array( $seen ) ? $seen : array_diff( AICOM_Auth::scope_slugs(), self::ADDED_SINCE_SEEN );
        $new  = array_intersect( self::default_scopes(), array_diff( AICOM_Auth::scope_slugs(), $seen ) );
        return array_values( array_intersect( AICOM_Auth::scope_slugs(), array_merge( $stored, $new ) ) );
    }

    /** Default cap: the whole scope tree minus `critical` scopes. */
    public static function default_scopes(): array {
        $out = [];
        foreach ( AICOM_Auth::scope_flat() as $slug => $def ) {
            if ( ( $def[1] ?? '' ) !== 'critical' ) {
                $out[] = $slug;
            }
        }
        return $out;
    }

    public static function set_allowed_scopes( array $scopes ): void {
        $scopes = array_values( array_unique( array_intersect( array_map( 'strval', $scopes ), AICOM_Auth::scope_slugs() ) ) );
        update_option( self::OPT_ALLOWED, $scopes, false );
        update_option( self::OPT_SEEN, AICOM_Auth::scope_slugs(), false );
    }

    /**
     * Check the scopes a token asks for.
     *
     * @return array{ok:bool,unknown:string[],excess:string[]}
     */
    public static function check_scopes( array $requested ): array {
        $requested = array_values( array_unique( array_map( 'strval', $requested ) ) );
        $unknown   = array_values( array_diff( $requested, AICOM_Auth::scope_slugs() ) );
        $excess    = array_values( array_diff( array_diff( $requested, $unknown ), self::allowed_scopes() ) );
        return [ 'ok' => ! $unknown && ! $excess, 'unknown' => $unknown, 'excess' => $excess ];
    }
}
