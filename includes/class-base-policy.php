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

    /** Scopes AICOMBase may request on this site. */
    public static function allowed_scopes(): array {
        $stored = get_option( self::OPT_ALLOWED, null );
        if ( is_array( $stored ) ) {
            return array_values( array_intersect( $stored, AICOM_Auth::scope_slugs() ) );
        }
        return self::default_scopes();
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
