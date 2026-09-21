<?php
/**
 * Ed25519 signer / verifier for the AICOMBase connection protocol.
 *
 * Wire contract: ~/Projects/aicombase/docs/PROTOCOL.md (§1, §2, §9). Every byte
 * here is shared with AICOMBase (lib/crypto.ts) — do not "improve" the
 * canonical layout without changing both sides.
 *
 * All binary values are base64url (no padding); keys are the RAW 32-byte
 * Ed25519 public key / 64-byte libsodium secret key (encrypted at rest by
 * AICOM_Base_Identity, never leaves the site).
 */
defined( 'ABSPATH' ) || exit;

class AICOM_Base_Signer {

    /** Skew window for signed site→base requests, seconds (PROTOCOL §2). */
    const MAX_SKEW = 300;
    /** Max lifetime of an execution authorization token, seconds (PROTOCOL §9). */
    const MAX_TOKEN_LIFETIME = 900;

    // ── base64url ─────────────────────────────────────────────────────────

    public static function b64u( string $bin ): string {
        return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
    }

    /** Returns null on malformed input. */
    public static function b64u_decode( string $s ): ?string {
        if ( $s === '' || preg_match( '/^[A-Za-z0-9_-]+$/', $s ) !== 1 ) {
            return null;
        }
        $pad = strlen( $s ) % 4;
        if ( $pad === 1 ) {
            return null;
        }
        if ( $pad ) {
            $s .= str_repeat( '=', 4 - $pad );
        }
        $bin = base64_decode( strtr( $s, '-_', '+/' ), true );
        return $bin === false ? null : $bin;
    }

    // ── Identity helpers ──────────────────────────────────────────────────

    /** `sk_` + first 16 hex of sha256(raw public key) — PROTOCOL §1. */
    public static function site_key_id( string $public_key_b64u ): string {
        $raw = self::b64u_decode( $public_key_b64u );
        return 'sk_' . substr( hash( 'sha256', (string) $raw ), 0, 16 );
    }

    /** `bk_` + first 16 hex of sha256(raw public key). */
    public static function base_key_id( string $public_key_b64u ): string {
        $raw = self::b64u_decode( $public_key_b64u );
        return 'bk_' . substr( hash( 'sha256', (string) $raw ), 0, 16 );
    }

    // ── Canonical string + signature (site → base) ────────────────────────

    /**
     * METHOD \n PATH(+?query) \n TS \n NONCE \n hex(sha256(raw body)).
     * No trailing newline. An empty body hashes the empty string.
     */
    public static function canonical( string $method, string $path_with_query, int $ts, string $nonce, string $raw_body ): string {
        return implode( "\n", [
            strtoupper( $method ),
            $path_with_query,
            (string) $ts,
            $nonce,
            hash( 'sha256', $raw_body ),
        ] );
    }

    /** Detached Ed25519 signature over $message, base64url. $secret_key is the 64-byte libsodium secret key. */
    public static function sign( string $message, string $secret_key ): string {
        return self::b64u( sodium_crypto_sign_detached( $message, $secret_key ) );
    }

    public static function verify( string $message, string $sig_b64u, string $public_key_b64u ): bool {
        $sig = self::b64u_decode( $sig_b64u );
        $pub = self::b64u_decode( $public_key_b64u );
        if ( $sig === null || $pub === null || strlen( $sig ) !== SODIUM_CRYPTO_SIGN_BYTES || strlen( $pub ) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached( $sig, $message, $pub );
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    /** 16–64 chars of [A-Za-z0-9_-] (PROTOCOL §2). 24 random bytes → 32 chars. */
    public static function nonce(): string {
        return self::b64u( random_bytes( 24 ) );
    }

    // ── Execution authorization token (§9) ────────────────────────────────

    /**
     * Verify a Base-issued authorization token. Pure function (no I/O) so it
     * can be unit-tested: nonce single-use tracking is done by the caller
     * (AICOM_Base_Executor) only AFTER this returns ok.
     *
     * @param string   $token        `b64u(payloadJSON).b64u(sig)`
     * @param string   $base_pub     pinned AICOMBase public key (b64u)
     * @param string   $base_key_id  pinned `bk_…` id (must equal payload.iss)
     * @param string   $site_id      this site's id (must equal payload.aud)
     * @param int|null $now          override clock (tests)
     * @return array{ok:bool,error?:string,claims?:array}
     */
    public static function verify_token( string $token, string $base_pub, string $base_key_id, string $site_id, ?int $now = null ): array {
        $now   = $now ?? time();
        $parts = explode( '.', $token );
        if ( count( $parts ) !== 2 ) {
            return [ 'ok' => false, 'error' => 'malformed_token' ];
        }
        $payload = self::b64u_decode( $parts[0] );
        if ( $payload === null || ! self::verify( $payload, $parts[1], $base_pub ) ) {
            return [ 'ok' => false, 'error' => 'bad_signature' ];
        }
        $c = json_decode( $payload, true );
        if ( ! is_array( $c ) ) {
            return [ 'ok' => false, 'error' => 'malformed_token' ];
        }
        if ( ( $c['v'] ?? null ) !== 1 ) {
            return [ 'ok' => false, 'error' => 'unsupported_version' ];
        }
        if ( ! hash_equals( $base_key_id, (string) ( $c['iss'] ?? '' ) ) ) {
            return [ 'ok' => false, 'error' => 'wrong_issuer' ];
        }
        if ( ! hash_equals( $site_id, (string) ( $c['aud'] ?? '' ) ) || $site_id === '' ) {
            return [ 'ok' => false, 'error' => 'wrong_audience' ];
        }
        $iat = isset( $c['iat'] ) ? (int) $c['iat'] : 0;
        $exp = isset( $c['expires_at'] ) ? (int) $c['expires_at'] : 0;
        if ( $iat <= 0 || $exp <= 0 ) {
            return [ 'ok' => false, 'error' => 'malformed_token' ];
        }
        if ( $exp <= $now ) {
            return [ 'ok' => false, 'error' => 'expired' ];
        }
        if ( $exp - $iat > self::MAX_TOKEN_LIFETIME || $iat > $now + self::MAX_SKEW ) {
            return [ 'ok' => false, 'error' => 'lifetime_too_long' ];
        }
        $nonce = (string) ( $c['nonce'] ?? '' );
        if ( strlen( $nonce ) < 8 || strlen( $nonce ) > 64 ) {
            return [ 'ok' => false, 'error' => 'bad_nonce' ];
        }
        if ( ! isset( $c['allowed_scopes'] ) || ! is_array( $c['allowed_scopes'] ) ) {
            return [ 'ok' => false, 'error' => 'malformed_token' ];
        }
        foreach ( $c['allowed_scopes'] as $s ) {
            if ( ! is_string( $s ) || $s === '' ) {
                return [ 'ok' => false, 'error' => 'malformed_token' ];
            }
        }
        return [ 'ok' => true, 'claims' => $c ];
    }
}
