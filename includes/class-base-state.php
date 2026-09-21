<?php
/**
 * AICOMBase connection state + configuration (single option, autoload off).
 *
 * Nothing secret lives here: the site private key is in AICOM_Base_Identity
 * (encrypted), the pinned AICOMBase *public* key and ids are public data.
 */
defined( 'ABSPATH' ) || exit;

class AICOM_Base_State {

    const OPT         = 'aicom_base_state';
    const OPT_URL     = 'aicom_base_url';
    const DEFAULT_URL = 'https://aicombase.com';

    const S_NONE    = 'none';
    const S_PAIRING = 'pairing';
    const S_CONN    = 'connected';
    const S_REVOKED = 'revoked';

    /** Keep at most this many processed command ids (replay guard for redelivered commands). */
    const DONE_CAP = 200;

    // ── raw state ─────────────────────────────────────────────────────────

    public static function all(): array {
        $s = get_option( self::OPT, [] );
        $s = is_array( $s ) ? $s : [];
        return $s + [ 'status' => self::S_NONE ];
    }

    public static function get( string $key, $default = null ) {
        $s = self::all();
        return $s[ $key ] ?? $default;
    }

    /** Merge fields into the stored state. */
    public static function update( array $fields ): void {
        // Re-read from DB to shrink the lost-update window between cron and admin-ajax.
        wp_cache_delete( self::OPT, 'options' );
        $s = self::all();
        update_option( self::OPT, array_merge( $s, $fields ), false );
    }

    public static function reset( array $keep = [] ): void {
        delete_option( self::OPT );
        if ( $keep ) {
            update_option( self::OPT, $keep, false );
        }
    }

    // ── convenience ───────────────────────────────────────────────────────

    public static function status(): string {
        return (string) self::get( 'status', self::S_NONE );
    }

    public static function is_connected(): bool {
        return self::status() === self::S_CONN && self::get( 'site_id' ) && self::get( 'base_public_key' );
    }

    public static function site_id(): string {
        return (string) self::get( 'site_id', '' );
    }

    public static function is_paused(): bool {
        return (bool) self::get( 'paused', false );
    }

    /** none|soft|hard — lock imposed by AICOMBase (separate from the local Lock Manager). */
    public static function base_lock(): string {
        $l = (string) self::get( 'base_lock', 'none' );
        return in_array( $l, [ 'soft', 'hard' ], true ) ? $l : 'none';
    }

    // ── base URL ──────────────────────────────────────────────────────────

    /** AICOM_BASE_URL constant > filter > option > default. */
    public static function base_url(): string {
        $url = '';
        if ( defined( 'AICOM_BASE_URL' ) && is_string( AICOM_BASE_URL ) && AICOM_BASE_URL !== '' ) {
            $url = AICOM_BASE_URL;
        } else {
            $url = (string) get_option( self::OPT_URL, '' );
            if ( $url === '' ) {
                $url = self::DEFAULT_URL;
            }
            $url = (string) apply_filters( 'aicom_base_url', $url );
        }
        return untrailingslashit( $url );
    }

    public static function base_url_locked_by_constant(): bool {
        return defined( 'AICOM_BASE_URL' ) && is_string( AICOM_BASE_URL ) && AICOM_BASE_URL !== '';
    }

    /**
     * HTTPS is required except for loopback / dev hosts (or AICOM_BASE_ALLOW_HTTP).
     * Returns an error string, or '' when the URL is acceptable.
     */
    public static function validate_base_url( string $url ): string {
        $p = wp_parse_url( $url );
        if ( empty( $p['scheme'] ) || empty( $p['host'] ) || ! in_array( $p['scheme'], [ 'http', 'https' ], true ) ) {
            return __( 'The AICOMBase URL must start with https://', 'aicom' );
        }
        if ( isset( $p['query'] ) || isset( $p['fragment'] ) || ( isset( $p['path'] ) && trim( $p['path'], '/' ) !== '' ) ) {
            return __( 'The AICOMBase URL must be a bare origin (no path or query).', 'aicom' );
        }
        if ( $p['scheme'] === 'http' ) {
            $host = strtolower( $p['host'] );
            $dev  = in_array( $host, [ 'localhost', '127.0.0.1', '::1', 'host.docker.internal' ], true )
                || preg_match( '/\.(test|local|localhost)$/', $host ) === 1;
            if ( ! $dev && ! ( defined( 'AICOM_BASE_ALLOW_HTTP' ) && AICOM_BASE_ALLOW_HTTP ) ) {
                return __( 'HTTPS is required for AICOMBase (plain http is only allowed for local development hosts).', 'aicom' );
            }
        }
        return '';
    }

    public static function set_base_url( string $url ): string {
        $url = untrailingslashit( esc_url_raw( trim( $url ) ) );
        if ( $url === '' ) {
            delete_option( self::OPT_URL );
            return '';
        }
        $err = self::validate_base_url( $url );
        if ( $err === '' ) {
            update_option( self::OPT_URL, $url, false );
        }
        return $err;
    }

    // ── command replay guard / acks ───────────────────────────────────────

    public static function command_done( string $id ): bool {
        return in_array( $id, (array) self::get( 'done_commands', [] ), true );
    }

    public static function mark_command_done( string $id ): void {
        $done   = (array) self::get( 'done_commands', [] );
        $done[] = $id;
        $acks   = (array) self::get( 'acks', [] );
        $acks[] = $id;
        self::update( [
            'done_commands' => array_slice( array_values( array_unique( $done ) ), -self::DONE_CAP ),
            'acks'          => array_slice( array_values( array_unique( $acks ) ), -self::DONE_CAP ),
        ] );
    }
}
