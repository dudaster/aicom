<?php
/**
 * Signed HTTP client for AICOMBase (PROTOCOL §2).
 *
 * - Every request carries Ed25519-signed headers over the canonical string.
 * - Bounded timeouts, no redirects, never throws; failures are recorded in the
 *   connection state and trigger exponential backoff so a down AICOMBase can
 *   never slow down or break the WordPress site (AICOM keeps working standalone).
 * - Outgoing JSON is scrubbed of anything credential-shaped before signing.
 * - The private key, AI bearer keys and the WP admin password never pass through here.
 */
defined( 'ABSPATH' ) || exit;

class AICOM_Base_Client {

    const TIMEOUT_DEFAULT = 10;
    const BACKOFF_CAP     = 600;
    const SECRET_KEY      = '/^(pass(word|wd)?|pwd|secret|private_?key|api_?key|access_?token|refresh_?token|auth_?token|token|authorization|bearer|cookie|session_?cookie)$/i';

    // ── high-level: established site connection ───────────────────────────

    /**
     * Signed request as the connected site. Records health/backoff in state.
     *
     * @param array $opts key ('active'|'pending'), timeout, respect_backoff(bool)
     * @return array{ok:bool,status:int,data:array,error:string,message:string}
     */
    public static function site_request( string $method, string $path, ?array $body = null, array $opts = [] ): array {
        if ( ! AICOM_Base_State::is_connected() ) {
            return self::fail( 0, 'not_connected', 'Site is not connected to AICOMBase.' );
        }
        if ( ! empty( $opts['respect_backoff'] ) && time() < (int) AICOM_Base_State::get( 'next_attempt_at', 0 ) ) {
            return self::fail( 0, 'backoff', 'Backing off after earlier failures.' );
        }
        $res = self::raw( $method, $path, $body, [
            // Talk only to the AICOMBase origin that was pinned at pairing time — a later change
            // to the configurable URL must never redirect a live connection's signed traffic.
            'base_url' => (string) AICOM_Base_State::get( 'base_url', '' ) ?: AICOM_Base_State::base_url(),
            'site_id' => AICOM_Base_State::site_id(),
            'key'     => $opts['key'] ?? 'active',
            'timeout' => $opts['timeout'] ?? self::TIMEOUT_DEFAULT,
        ] );
        self::record( $res );
        return $res;
    }

    private static function record( array $res ): void {
        if ( $res['ok'] ) {
            AICOM_Base_State::update( [ 'fail_count' => 0, 'next_attempt_at' => 0, 'last_ok_at' => time(), 'last_error' => null ] );
            return;
        }
        // AICOMBase tells a revoked site with 403 `revoked` (PROTOCOL §2 step 6).
        if ( $res['status'] === 403 && $res['error'] === 'revoked' ) {
            AICOM_Base_Connection::handle_revoked( 'AICOMBase reported this connection as revoked.' );
            return;
        }
        // Errors that are a real problem (not just "network down") flag the connection for attention.
        $fails = (int) AICOM_Base_State::get( 'fail_count', 0 ) + 1;
        AICOM_Base_State::update( [
            'fail_count'      => $fails,
            'next_attempt_at' => time() + min( self::BACKOFF_CAP, 30 * ( 2 ** min( $fails - 1, 6 ) ) ),
            'last_error'      => [ 'code' => $res['error'], 'status' => $res['status'], 'message' => substr( $res['message'], 0, 200 ), 'at' => time() ],
        ] );
    }

    // ── low-level: sign + send ────────────────────────────────────────────

    /**
     * @param array $o base_url, site_id (omit during pairing), pairing_id, key, timeout
     */
    public static function raw( string $method, string $path, ?array $body, array $o = [] ): array {
        if ( ! AICOM_Base_Identity::available() ) {
            return self::fail( 0, 'no_sodium', 'The PHP sodium extension is required.' );
        }
        $base = $o['base_url'] ?? AICOM_Base_State::base_url();
        $err  = AICOM_Base_State::validate_base_url( $base );
        if ( $err !== '' ) {
            return self::fail( 0, 'bad_base_url', $err );
        }

        $raw = '';
        if ( $body !== null ) {
            $raw = (string) wp_json_encode( self::scrub( $body ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        }
        $ts        = time();
        $nonce     = AICOM_Base_Signer::nonce();
        $canonical = AICOM_Base_Signer::canonical( $method, $path, $ts, $nonce, $raw );
        $signed    = AICOM_Base_Identity::sign( $canonical, $o['key'] ?? 'active' );
        if ( ! $signed ) {
            return self::fail( 0, 'no_key', 'No signing key available.' );
        }

        $headers = [
            'Content-Type'       => 'application/json',
            'Accept'             => 'application/json',
            'X-AICOM-Key-Id'     => $signed['key_id'],
            'X-AICOM-Timestamp'  => (string) $ts,
            'X-AICOM-Nonce'      => $nonce,
            'X-AICOM-Signature'  => $signed['signature'],
        ];
        if ( ! empty( $o['site_id'] ) ) {
            $headers['X-AICOM-Site-Id'] = (string) $o['site_id'];
        }
        if ( ! empty( $o['pairing_id'] ) ) {
            $headers['X-AICOM-Pairing-Id'] = (string) $o['pairing_id'];
        }

        $args = [
            'method'      => strtoupper( $method ),
            'headers'     => $headers,
            'timeout'     => (int) ( $o['timeout'] ?? self::TIMEOUT_DEFAULT ),
            'redirection' => 0,
            'user-agent'  => 'AICOM/' . AICOM_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
        ];
        if ( $raw !== '' || $body !== null ) {
            $args['body'] = $raw;
        }

        $resp = wp_remote_request( $base . $path, $args );
        if ( is_wp_error( $resp ) ) {
            return self::fail( 0, 'network', $resp->get_error_message() );
        }
        $status = (int) wp_remote_retrieve_response_code( $resp );
        $data   = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
        $data   = is_array( $data ) ? $data : [];
        if ( $status >= 200 && $status < 300 && ( $data['ok'] ?? true ) !== false ) {
            return [ 'ok' => true, 'status' => $status, 'data' => $data, 'error' => '', 'message' => '' ];
        }
        return self::fail( $status, (string) ( $data['error'] ?? 'http_' . $status ), (string) ( $data['message'] ?? '' ), $data );
    }

    private static function fail( int $status, string $error, string $message, array $data = [] ): array {
        return [ 'ok' => false, 'status' => $status, 'data' => $data, 'error' => $error, 'message' => $message ];
    }

    // ── payload hygiene ───────────────────────────────────────────────────

    /**
     * Replace anything credential-shaped with "[redacted]" so AICOMBase's
     * secret filter (lib/protocol/secrets.ts) never has to reject a payload,
     * and so nothing sensitive can leave the site by accident.
     */
    public static function scrub( $v, int $depth = 0 ) {
        if ( $depth > 12 ) {
            return null;
        }
        if ( is_string( $v ) ) {
            return self::scrub_string( $v );
        }
        if ( $v instanceof \stdClass ) {
            // Keep JSON objects as objects (an empty `{}` must not turn into `[]` — AICOMBase validates strictly).
            $o = new \stdClass();
            foreach ( get_object_vars( $v ) as $k => $val ) {
                $o->$k = is_string( $val ) && $val !== '' && preg_match( self::SECRET_KEY, (string) $k ) ? '[redacted]' : self::scrub( $val, $depth + 1 );
            }
            return $o;
        }
        if ( is_array( $v ) ) {
            $out = [];
            foreach ( $v as $k => $val ) {
                if ( is_string( $k ) && is_string( $val ) && $val !== '' && preg_match( self::SECRET_KEY, $k ) ) {
                    $out[ $k ] = '[redacted]';
                    continue;
                }
                $out[ $k ] = self::scrub( $val, $depth + 1 );
            }
            return $out;
        }
        return $v;
    }

    private static function scrub_string( string $s ): string {
        static $patterns = [
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
            '/\bBearer\s+[A-Za-z0-9._~+\/-]{20,}/i',
            '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/',
            '/^[A-Za-z0-9_-]{40,}\.[A-Za-z0-9_-]{80,}$/',
            '/\b(AKIA|ASIA)[A-Z0-9]{16}\b/',
            '/\b(sk|rk|pk)_(live|test)_[A-Za-z0-9]{16,}/',
            '/\bgh[pousr]_[A-Za-z0-9]{30,}/',
            '/(password|passwd|pwd|secret|api[_-]?key)\s*[=:]\s*\S{4,}/i',
            '/\baicom_[A-Za-z0-9]{8}_[a-f0-9]{40}\b/', // AICOM's own AI Bearer key format
        ];
        foreach ( $patterns as $re ) {
            if ( preg_match( $re, $s ) === 1 ) {
                return '[redacted]';
            }
        }
        return $s;
    }
}
