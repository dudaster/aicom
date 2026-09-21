<?php
/**
 * Connect / disconnect lifecycle (PROTOCOL §3, PRD §10–12).
 *
 *   begin()  → self-signed POST /api/v1/pairings, returns connect_url for the browser
 *   poll()   → signed GET /api/v1/pairings/{id} until confirmed → pins the AICOMBase key
 *   disconnect() / handle_revoked() → wipe pairing + keys, stop signing
 *
 * The AICOMBase password authenticates the human only; the WordPress admin password,
 * the private key and AI bearer keys are never sent.
 */
defined( 'ABSPATH' ) || exit;

class AICOM_Base_Connection {

    const PAIRING_PATH = '/api/v1/pairings';
    const CRON_POLL    = 'aicom_base_pairing_poll';

    // ── begin ─────────────────────────────────────────────────────────────

    /** @return array{ok:bool,connect_url?:string,error?:string,message?:string} */
    public static function begin(): array {
        if ( ! AICOM_Base_Identity::available() ) {
            return [ 'ok' => false, 'error' => 'no_sodium', 'message' => __( 'The PHP sodium extension is required to connect to AICOMBase.', 'aicom' ) ];
        }
        if ( AICOM_Base_State::is_connected() ) {
            return [ 'ok' => false, 'error' => 'already_connected', 'message' => __( 'This site is already connected.', 'aicom' ) ];
        }
        $err = AICOM_Base_State::validate_base_url( AICOM_Base_State::base_url() );
        if ( $err !== '' ) {
            return [ 'ok' => false, 'error' => 'bad_base_url', 'message' => $err ];
        }

        // A brand-new keypair for every fresh pairing — a previously revoked/disconnected key is never reused.
        $ident = AICOM_Base_Identity::regenerate();
        $now   = time();
        $body  = [
            'pairing_id'      => bin2hex( random_bytes( 12 ) ),
            'site_nonce'      => bin2hex( random_bytes( 16 ) ),
            'site_public_key' => $ident['public_key'],
            'site_url'        => untrailingslashit( home_url() ),
            'installation_id' => AICOM_Base_Identity::installation_id(),
            'wp_version'      => get_bloginfo( 'version' ),
            'aicom_version'   => AICOM_VERSION,
            'php_version'     => PHP_VERSION,
            'timestamp'       => $now,
            'expires_at'      => $now + 600,
        ];

        $res = AICOM_Base_Client::raw( 'POST', self::PAIRING_PATH, $body, [ 'pairing_id' => $body['pairing_id'], 'timeout' => 15 ] );
        if ( ! $res['ok'] ) {
            AICOM_Base_Identity::wipe();
            return [ 'ok' => false, 'error' => $res['error'], 'message' => self::friendly( $res ) ];
        }
        $connect_url = (string) ( $res['data']['connect_url'] ?? '' );
        if ( $connect_url === '' ) {
            AICOM_Base_Identity::wipe();
            return [ 'ok' => false, 'error' => 'bad_response', 'message' => __( 'AICOMBase returned no connect URL.', 'aicom' ) ];
        }

        AICOM_Base_State::reset( [
            'status'  => AICOM_Base_State::S_PAIRING,
            'pairing' => [
                'id'          => $body['pairing_id'],
                'connect_url' => $connect_url,
                'key_id'      => $ident['key_id'],
                'base_url'    => AICOM_Base_State::base_url(),
                'created_at'  => $now,
                'expires_at'  => (int) ( $res['data']['expires_at'] ?? $body['expires_at'] ),
            ],
        ] );
        self::audit( 'base.pairing_started', 'success', [ 'pairing_id' => $body['pairing_id'] ] );
        self::schedule_poll();
        return [ 'ok' => true, 'connect_url' => $connect_url ];
    }

    // ── poll ──────────────────────────────────────────────────────────────

    /** @return array{state:string,message?:string,connect_url?:string} */
    public static function poll(): array {
        $st = AICOM_Base_State::all();
        if ( $st['status'] === AICOM_Base_State::S_CONN ) {
            return [ 'state' => 'connected' ];
        }
        if ( $st['status'] !== AICOM_Base_State::S_PAIRING || empty( $st['pairing']['id'] ) ) {
            return [ 'state' => 'none' ];
        }
        $p = $st['pairing'];
        if ( time() > (int) $p['expires_at'] + 30 ) {
            self::abort_pairing( __( 'The pairing request expired. Start again.', 'aicom' ) );
            return [ 'state' => 'expired', 'message' => __( 'The pairing request expired. Start again.', 'aicom' ) ];
        }

        $path = self::PAIRING_PATH . '/' . rawurlencode( $p['id'] );
        $res  = AICOM_Base_Client::raw( 'GET', $path, null, [ 'pairing_id' => $p['id'], 'base_url' => $p['base_url'] ?? null ?: AICOM_Base_State::base_url(), 'timeout' => 10 ] );
        if ( ! $res['ok'] ) {
            // Transient failure — keep waiting; the human may still be confirming.
            return [ 'state' => 'pending', 'connect_url' => $p['connect_url'], 'message' => self::friendly( $res ) ];
        }
        $d = $res['data'];
        switch ( $d['status'] ?? '' ) {
            case 'confirmed':
                return self::finish( $d, $p );
            case 'expired':
            case 'cancelled':
                self::abort_pairing( ( $d['status'] === 'cancelled' )
                    ? __( 'The pairing was cancelled in AICOMBase.', 'aicom' )
                    : __( 'The pairing request expired. Start again.', 'aicom' ) );
                return [ 'state' => (string) $d['status'] ];
        }
        return [ 'state' => 'pending', 'connect_url' => $p['connect_url'] ];
    }

    /** Persist the confirmed pairing; pin the AICOMBase public key. */
    private static function finish( array $d, array $p ): array {
        $need = [ 'site_id', 'connection_id', 'base_public_key' ];
        foreach ( $need as $k ) {
            if ( empty( $d[ $k ] ) || ! is_string( $d[ $k ] ) ) {
                self::abort_pairing( __( 'AICOMBase returned an incomplete pairing response.', 'aicom' ) );
                return [ 'state' => 'error', 'message' => __( 'AICOMBase returned an incomplete pairing response.', 'aicom' ) ];
            }
        }
        $pub_raw = AICOM_Base_Signer::b64u_decode( $d['base_public_key'] );
        if ( $pub_raw === null || strlen( $pub_raw ) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ) {
            self::abort_pairing( __( 'AICOMBase returned an invalid public key.', 'aicom' ) );
            return [ 'state' => 'error', 'message' => __( 'AICOMBase returned an invalid public key.', 'aicom' ) ];
        }
        // Derive the key id locally — never trust a claimed id blindly.
        $base_key_id = AICOM_Base_Signer::base_key_id( $d['base_public_key'] );
        if ( ! empty( $d['base_key_id'] ) && ! hash_equals( $base_key_id, (string) $d['base_key_id'] ) ) {
            self::abort_pairing( __( 'AICOMBase key id mismatch.', 'aicom' ) );
            return [ 'state' => 'error', 'message' => __( 'AICOMBase key id mismatch.', 'aicom' ) ];
        }

        AICOM_Base_State::reset( [
            'status'             => AICOM_Base_State::S_CONN,
            'site_id'            => $d['site_id'],
            'connection_id'      => $d['connection_id'],
            'organization_id'    => (string) ( $d['organization_id'] ?? '' ),
            'base_public_key'    => $d['base_public_key'],
            'base_key_id'        => $base_key_id,
            'base_url'           => untrailingslashit( (string) ( $p['base_url'] ?? AICOM_Base_State::base_url() ) ),
            'heartbeat_interval' => max( 30, min( 600, (int) ( $d['heartbeat_interval'] ?? 60 ) ) ),
            'connected_at'       => time(),
            'paused'             => false,
            'base_lock'          => 'none',
            'caps_needed'        => true,
            'acks'               => [],
            'done_commands'      => [],
        ] );
        self::unschedule_poll();
        AICOM_Base::schedule_heartbeat();
        self::audit( 'base.connected', 'success', [ 'site_id' => $d['site_id'], 'base_key_id' => $base_key_id ] );

        // Kick the first heartbeat + capability upload right away (best-effort, short timeout).
        AICOM_Base_Heartbeat::run( 'connect' );
        return [ 'state' => 'connected' ];
    }

    private static function abort_pairing( string $message ): void {
        AICOM_Base_Identity::wipe();
        AICOM_Base_State::reset( [ 'status' => AICOM_Base_State::S_NONE, 'notice' => $message ] );
        self::unschedule_poll();
        self::audit( 'base.pairing_failed', 'error', [ 'reason' => $message ] );
    }

    // ── cron fallback for the pairing poll ────────────────────────────────

    public static function schedule_poll(): void {
        if ( ! wp_next_scheduled( self::CRON_POLL ) ) {
            wp_schedule_single_event( time() + 20, self::CRON_POLL );
        }
    }

    public static function unschedule_poll(): void {
        wp_clear_scheduled_hook( self::CRON_POLL );
    }

    /** WP-Cron callback: keep polling while a pairing is pending (covers "user closed the wp-admin tab"). */
    public static function cron_poll(): void {
        $r = self::poll();
        if ( $r['state'] === 'pending' ) {
            wp_schedule_single_event( time() + 20, self::CRON_POLL );
        }
    }

    // ── disconnect / revoke ───────────────────────────────────────────────

    /** Admin-initiated: forget the pairing. AICOMBase will show the site offline; revoke there to invalidate keys. */
    public static function disconnect( string $reason = 'user' ): void {
        $was = AICOM_Base_State::status();
        // Tell AICOMBase first (PROTOCOL §4a) so it revokes its side. Best-effort: short timeout,
        // failures are ignored — the local disconnect below always happens.
        if ( $reason === 'user' && AICOM_Base_State::is_connected() ) {
            try {
                AICOM_Base_Client::raw( 'POST', '/api/v1/site/disconnect', [], [
                    'base_url' => (string) AICOM_Base_State::get( 'base_url', '' ) ?: AICOM_Base_State::base_url(),
                    'site_id'  => AICOM_Base_State::site_id(),
                    'timeout'  => 4,
                ] );
            } catch ( \Throwable $e ) {
                // ignore
            }
        }
        self::wipe( AICOM_Base_State::S_NONE, '' );
        if ( $was !== AICOM_Base_State::S_NONE ) {
            self::audit( 'base.disconnected', 'success', [ 'reason' => $reason ] );
        }
    }

    /** AICOMBase revoked us (403 revoked / revoke command). Wipe pairing and stop signing. */
    public static function handle_revoked( string $why ): void {
        if ( AICOM_Base_State::status() === AICOM_Base_State::S_REVOKED ) {
            return;
        }
        self::wipe( AICOM_Base_State::S_REVOKED, $why );
        self::audit( 'base.revoked', 'blocked', [ 'why' => $why ] );
    }

    private static function wipe( string $new_status, string $notice ): void {
        AICOM_Base_Identity::wipe();
        AICOM_Base_Events::purge();
        AICOM_Base_Executor::purge_nonces();
        AICOM_Base_State::reset( array_filter( [ 'status' => $new_status, 'notice' => $notice, 'revoked_at' => $new_status === AICOM_Base_State::S_REVOKED ? time() : null ] ) );
        wp_clear_scheduled_hook( AICOM_Base::CRON_HEARTBEAT );
        self::unschedule_poll();
    }

    // ── UI summary ────────────────────────────────────────────────────────

    /**
     * Derived connection state (PRD §15): connected|offline|attention|paused|locked|revoked|none|pairing.
     */
    public static function ui_state(): string {
        $s = AICOM_Base_State::all();
        if ( $s['status'] === AICOM_Base_State::S_NONE || $s['status'] === AICOM_Base_State::S_PAIRING || $s['status'] === AICOM_Base_State::S_REVOKED ) {
            return (string) $s['status'];
        }
        if ( ! empty( $s['paused'] ) ) {
            return 'paused';
        }
        if ( AICOM_Base_Heartbeat::effective_lock() !== 'none' ) {
            return 'locked';
        }
        $last_ok  = (int) ( $s['last_ok_at'] ?? 0 );
        $interval = (int) ( $s['heartbeat_interval'] ?? 60 );
        $err      = $s['last_error'] ?? null;
        if ( $err && in_array( $err['code'] ?? '', [ 'bad_signature', 'stale', 'unknown_key', 'bad_base_url', 'no_key' ], true ) ) {
            return 'attention';
        }
        if ( ! $last_ok || time() - $last_ok > max( 180, $interval * 3 ) ) {
            return 'offline';
        }
        if ( ! empty( $s['health']['issues'] ) ) {
            return 'attention';
        }
        return 'connected';
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private static function friendly( array $res ): string {
        switch ( $res['error'] ) {
            case 'network':
                /* translators: %s: low-level network error */
                return sprintf( __( 'Could not reach AICOMBase (%s).', 'aicom' ), $res['message'] );
            case 'rate_limited':
                return __( 'AICOMBase is rate limiting requests. Try again in a minute.', 'aicom' );
            case 'stale':
                return __( 'Your server clock differs from AICOMBase by more than 5 minutes. Fix the server time and retry.', 'aicom' );
        }
        return $res['message'] !== '' ? $res['message'] : sprintf( 'HTTP %d (%s)', $res['status'], $res['error'] );
    }

    public static function audit( string $tool, string $status, array $summary = [] ): void {
        AICOM_Audit_Logger::log( [
            'remote_ip'           => AICOM_Tool_Router::remote_ip(),
            'tool_name'           => $tool,
            'module'              => 'base',
            'status'              => $status,
            'http_status'         => $status === 'success' ? 200 : 403,
            'api_key_label'       => 'aicombase',
            'result_summary_json' => wp_json_encode( $summary ),
        ] );
    }
}
