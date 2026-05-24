<?php
/**
 * HMAC signer for the Hub ↔ Local management channel.
 *
 * Byte-identical with AICOM Hub's AICOMHUB_Signer. Both sides MUST derive
 * the same canonical string from the same inputs, or the signature will
 * never match. Do not "improve" the canonical format — change requires
 * a coordinated update in both plugins.
 *
 * Wire contract: ../MANAGEMENT-CHANNEL.md
 */
defined( 'ABSPATH' ) || exit;

class AICOM_Hub_Signer {

    /** Reject requests whose timestamp drifts more than this (seconds). */
    const MAX_SKEW = 300;

    /**
     * Canonical string layout — fixed and shared with the Hub:
     *   METHOD \n PATH \n TS \n NONCE \n hex(sha256(body))
     */
    public static function canonical( string $method, string $path, int $ts, string $nonce, string $body ): string {
        return implode( "\n", [
            strtoupper( $method ),
            $path,
            (string) $ts,
            $nonce,
            hash( 'sha256', $body ),
        ] );
    }

    public static function sign( string $canonical, string $secret ): string {
        return hash_hmac( 'sha256', $canonical, $secret );
    }

    /** Constant-time check + skew window. */
    public static function verify( string $signature, string $canonical, string $secret, int $ts ): bool {
        if ( abs( time() - $ts ) > self::MAX_SKEW ) {
            return false;
        }
        return hash_equals( self::sign( $canonical, $secret ), $signature );
    }

    public static function nonce(): string {
        return bin2hex( random_bytes( 16 ) );
    }
}
