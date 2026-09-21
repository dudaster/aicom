<?php
/**
 * Site identity for the AICOMBase connection: Ed25519 keypair, installation id.
 *
 * - The private key is encrypted at rest with sodium secretbox, key derived from
 *   wp_salt('secure_auth') (same pattern as AICOM_Hub_Crypto, but domain-separated
 *   so a compromise of one derived key does not affect the other).
 * - installation_id is a UUIDv4 that survives disconnect/reconnect.
 * - key_id = 'sk_' + first 16 hex of sha256(raw public key).
 * - Rotation (PROTOCOL §8): a `pending` keypair lives next to the active one until
 *   AICOMBase confirms it; then it is promoted and the old key discarded.
 *
 * The private key is NEVER returned by any admin/REST/AJAX path and never logged.
 */
defined( 'ABSPATH' ) || exit;

class AICOM_Base_Identity {

    const OPT_INSTALLATION = 'aicom_base_installation_id';
    const OPT_IDENTITY     = 'aicom_base_identity';

    // ── installation id ───────────────────────────────────────────────────

    public static function installation_id(): string {
        $id = (string) get_option( self::OPT_INSTALLATION, '' );
        if ( $id === '' ) {
            $id = wp_generate_uuid4();
            update_option( self::OPT_INSTALLATION, $id, false );
        }
        return $id;
    }

    // ── keypair lifecycle ─────────────────────────────────────────────────

    public static function available(): bool {
        return function_exists( 'sodium_crypto_sign_keypair' ) && function_exists( 'sodium_crypto_sign_detached' );
    }

    private static function generate(): array {
        $kp  = sodium_crypto_sign_keypair();
        $pub = AICOM_Base_Signer::b64u( sodium_crypto_sign_publickey( $kp ) );
        $sec = sodium_crypto_sign_secretkey( $kp );
        $rec = [
            'key_id'     => AICOM_Base_Signer::site_key_id( $pub ),
            'public_key' => $pub,
            'secret_enc' => self::encrypt( $sec ),
            'created_at' => time(),
        ];
        sodium_memzero( $sec );
        return $rec;
    }

    /** Load the active identity, creating one on first use. */
    public static function ensure(): array {
        $all = self::load();
        if ( empty( $all['active'] ) ) {
            $all['active'] = self::generate();
            self::save( $all );
        }
        return $all['active'];
    }

    /** Public view of the active identity (never contains the secret). */
    public static function public_info(): array {
        $a = self::load()['active'] ?? null;
        return $a ? [ 'key_id' => $a['key_id'], 'public_key' => $a['public_key'], 'created_at' => $a['created_at'] ] : [];
    }

    public static function has_identity(): bool {
        return ! empty( self::load()['active'] );
    }

    /** Discard every key (disconnect / revoke). installation_id is kept. */
    public static function wipe(): void {
        delete_option( self::OPT_IDENTITY );
    }

    /** Fresh keypair, discarding the old one (used when starting a NEW pairing after a disconnect). */
    public static function regenerate(): array {
        self::wipe();
        return self::ensure();
    }

    /**
     * Sign a message with the active (or pending) key.
     *
     * @param string $which 'active' | 'pending'
     * @return array{key_id:string,signature:string}|null
     */
    public static function sign( string $message, string $which = 'active' ): ?array {
        $rec = self::load()[ $which ] ?? null;
        if ( ! $rec ) {
            return null;
        }
        $sec = self::decrypt( (string) $rec['secret_enc'] );
        if ( $sec === null ) {
            return null;
        }
        $sig = AICOM_Base_Signer::sign( $message, $sec );
        sodium_memzero( $sec );
        return [ 'key_id' => $rec['key_id'], 'signature' => $sig ];
    }

    // ── rotation (PROTOCOL §8) ────────────────────────────────────────────

    /** Create (or return the existing) pending keypair for a rotation. */
    public static function begin_rotation( string $rotation_id ): array {
        $all = self::load();
        if ( ! empty( $all['pending'] ) && ( $all['pending']['rotation_id'] ?? '' ) === $rotation_id ) {
            return $all['pending'];
        }
        $rec                = self::generate();
        $rec['rotation_id'] = $rotation_id;
        $all['pending']     = $rec;
        self::save( $all );
        return $rec;
    }

    public static function pending(): ?array {
        $p = self::load()['pending'] ?? null;
        return $p ?: null;
    }

    /** Promote pending → active after AICOMBase confirmed. */
    public static function commit_rotation(): bool {
        $all = self::load();
        if ( empty( $all['pending'] ) ) {
            return false;
        }
        $new = $all['pending'];
        unset( $new['rotation_id'] );
        $all['active'] = $new;
        unset( $all['pending'] );
        self::save( $all );
        return true;
    }

    public static function abort_rotation(): void {
        $all = self::load();
        unset( $all['pending'] );
        self::save( $all );
    }

    // ── storage ───────────────────────────────────────────────────────────

    private static function load(): array {
        $v = get_option( self::OPT_IDENTITY, [] );
        return is_array( $v ) ? $v : [];
    }

    private static function save( array $all ): void {
        update_option( self::OPT_IDENTITY, $all, false );
    }

    // ── at-rest encryption ────────────────────────────────────────────────

    private static function key(): string {
        return hash( 'sha256', wp_salt( 'secure_auth' ) . '|aicombase-identity', true );
    }

    private static function encrypt( string $plain ): string {
        $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) );
    }

    private static function decrypt( string $encoded ): ?string {
        $bin = base64_decode( $encoded, true );
        if ( ! is_string( $bin ) || strlen( $bin ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 16 ) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(
            substr( $bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
            substr( $bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
            self::key()
        );
        return $plain === false ? null : $plain;
    }
}
