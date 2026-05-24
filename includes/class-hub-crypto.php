<?php
/**
 * Symmetric encryption for the management secret stored on this Local site.
 *
 * The secret is reversible (we must reproduce it to compute HMACs), so it
 * is encrypted at rest with sodium, not hashed. The key is derived from
 * wp_salt('secure_auth') — same scheme the Hub uses, see AICOMHUB_Credentials.
 *
 * Stealing the DB without wp-config leaves the ciphertext useless.
 */
defined( 'ABSPATH' ) || exit;

class AICOM_Hub_Crypto {

    public static function encrypt( string $plaintext ): string {
        $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $ct    = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );
        return base64_encode( $nonce . $ct );
    }

    public static function decrypt( string $encoded ): ?string {
        $bin = base64_decode( $encoded, true );
        if ( ! is_string( $bin ) || strlen( $bin ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 16 ) {
            return null;
        }
        $nonce = substr( $bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $ct    = substr( $bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $plain = sodium_crypto_secretbox_open( $ct, $nonce, self::key() );
        return $plain === false ? null : $plain;
    }

    private static function key(): string {
        return hash( 'sha256', wp_salt( 'secure_auth' ) . '|aicomhub-mgmt', true );
    }
}
