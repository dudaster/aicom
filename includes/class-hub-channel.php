<?php
/**
 * AICOM Hub ↔ Local management channel.
 *
 * Three responsibilities:
 *   1. Pair endpoint  — one-time bootstrap that exchanges a pairing token
 *      for a long-lived HMAC management secret.
 *   2. Management endpoint — every subsequent Hub-initiated action, HMAC-
 *      signed, with Local Policy Cap (the site has the final word).
 *   3. Outbound sync push — batched, signed heartbeat + audit events that
 *      this Local site sends back to every paired Hub.
 *
 * Wire contract: ../MANAGEMENT-CHANNEL.md (kept in sync — same path on disk).
 */
defined( 'ABSPATH' ) || exit;

class AICOM_Hub_Channel {

    /** Path used inside the canonical string — must match the registered route. */
    const PAIR_PATH       = '/wp-json/aicom/v1/pair';
    const MANAGEMENT_PATH = '/wp-json/aicom/v1/management';
    /** Hub-side sync endpoint suffix — fixed in the Hub plugin. */
    const HUB_SYNC_PATH   = '/wp-json/aicomhub/v1/sync';

    /**
     * Per-site whitelist of management actions the Hub is allowed to invoke
     * (PRD §16.3 — Local Policy Cap). Hardcoded for now; future work moves
     * this into an admin setting so each site can refuse specific verbs.
     */
    const ALLOWED_ACTIONS = [
        'status.get', 'lock.set',
        'session.create', 'session.revoke', 'key.rotate',
        'key.suspend', 'key.unsuspend', 'key.reset_ip_lock', 'key.archive', 'key.unarchive',
    ];

    /** Absolute cap on session lifetime regardless of what the Hub requests. */
    const SESSION_MAX_MINUTES = 240;

    /** Restriction keys we accept from Hub (everything else is dropped). */
    const ALLOWED_RESTRICTIONS = [
        'dry_run_only', 'ip_lock', 'ip_allowlist',
        'post_types', 'taxonomies', 'meta_keys', 'options', 'file_paths', 'languages',
    ];

    // ── REST registration ─────────────────────────────────────────────────

    public static function register_routes(): void {
        register_rest_route( 'aicom/v1', '/pair', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_pair' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( 'aicom/v1', '/management', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_management' ],
            'permission_callback' => '__return_true',
        ] );
    }

    // ── /pair ─────────────────────────────────────────────────────────────

    public static function handle_pair( WP_REST_Request $request ): WP_REST_Response {
        $body = json_decode( $request->get_body() ?: '{}', true );
        if ( ! is_array( $body ) ) {
            return self::err( 'bad_payload', 400 );
        }

        $token   = (string) ( $body['pairing_token'] ?? '' );
        $hub_id  = sanitize_text_field( (string) ( $body['hub_id'] ?? '' ) );
        $hub_url = esc_url_raw( (string) ( $body['hub_url'] ?? '' ) );

        if ( ! $token || ! $hub_id || ! $hub_url ) {
            self::log_security( 'pairing_attempt', 'missing fields' );
            return self::err( 'missing_fields', 400 );
        }

        if ( ! AICOM_Hub_Pairing::consume_token( $token ) ) {
            self::log_security( 'pairing_attempt', 'invalid or expired token from ' . $hub_url );
            return self::err( 'invalid_token', 401 );
        }

        $key_id = 'mgmt_' . bin2hex( random_bytes( 8 ) );
        $secret = bin2hex( random_bytes( 32 ) );
        AICOM_Hub_Pairing::store( $hub_id, $hub_url, $key_id, $secret );

        AICOM_Audit_Logger::log( [
            'remote_ip'     => AICOM_Tool_Router::remote_ip(),
            'tool_name'     => 'hub.pair',
            'module'        => 'hub',
            'status'        => 'success',
            'http_status'   => 200,
            'api_key_label' => 'hub:' . $hub_id,
            'result_summary_json' => wp_json_encode( [ 'key_id' => $key_id, 'hub_url' => $hub_url ] ),
        ] );

        // Plaintext secret is returned exactly once — Hub stores it encrypted.
        return new WP_REST_Response( [
            'ok'                => true,
            'management_key_id' => $key_id,
            'management_secret' => $secret,
            'aicom_version'     => AICOM_VERSION,
            'site_url'          => untrailingslashit( get_site_url() ),
        ], 200 );
    }

    // ── /management ───────────────────────────────────────────────────────

    public static function handle_management( WP_REST_Request $request ): WP_REST_Response {
        $raw     = $request->get_body() ?: '';
        $key_id  = (string) $request->get_header( 'X-AICOM-Key-Id' );
        $ts      = (int) $request->get_header( 'X-AICOM-Timestamp' );
        $nonce   = (string) $request->get_header( 'X-AICOM-Nonce' );
        $sig     = (string) $request->get_header( 'X-AICOM-Signature' );

        if ( ! $key_id || ! $ts || ! $nonce || ! $sig ) {
            self::log_security( 'invalid_hmac_attempt', 'management call missing headers' );
            return self::err( 'unsigned', 401 );
        }

        $pairing = AICOM_Hub_Pairing::get_by_key_id( $key_id );
        if ( ! $pairing ) {
            self::log_security( 'invalid_hmac_attempt', 'unknown key_id ' . $key_id );
            return self::err( 'unknown_key', 401 );
        }

        $canonical = AICOM_Hub_Signer::canonical( 'POST', self::MANAGEMENT_PATH, $ts, $nonce, $raw );
        if ( ! AICOM_Hub_Signer::verify( $sig, $canonical, $pairing['management_secret'], $ts ) ) {
            self::log_security( 'invalid_hmac_attempt', 'signature failed for ' . $key_id );
            return self::err( 'bad_signature', 401 );
        }

        // Replay protection — single-use nonce per key.
        if ( ! AICOM_Hub_Pairing::mark_nonce( $key_id, $nonce ) ) {
            self::log_security( 'invalid_hmac_attempt', 'nonce replay for ' . $key_id );
            return self::err( 'replay', 401 );
        }

        $body   = json_decode( $raw, true );
        $action = is_array( $body ) ? sanitize_text_field( (string) ( $body['action'] ?? '' ) ) : '';
        $params = is_array( $body ) && isset( $body['params'] ) && is_array( $body['params'] ) ? $body['params'] : [];

        if ( ! in_array( $action, self::ALLOWED_ACTIONS, true ) ) {
            // Local Policy Cap (PRD §16.3) — site has the final word.
            self::log_security( 'scope_violation', 'action ' . $action . ' refused by local cap' );
            return self::err( 'scope_forbidden', 403 );
        }

        AICOM_Hub_Pairing::touch( (int) $pairing['id'] );
        return new WP_REST_Response( self::dispatch( $action, $params, $pairing ), 200 );
    }

    /**
     * In-process bypass for the loopback case — Hub and Local share a PHP
     * process, so authentication is implicit and we skip HMAC/nonce/etc.
     * Same return shape as the wire dispatcher.
     */
    public static function dispatch_local( string $action, array $params ): array {
        if ( ! in_array( $action, self::ALLOWED_ACTIONS, true ) ) {
            return [ 'ok' => false, 'error' => 'unknown_action' ];
        }
        // Loopback: synthesize a "pairing" so audit entries still identify
        // the actor (Hub) even though no HMAC exchange happened.
        $loopback_pairing = [
            'id'     => 0,
            'hub_id' => 'loopback',
            'hub_url'=> get_site_url(),
        ];
        return self::dispatch( $action, $params, $loopback_pairing );
    }

    /**
     * Action dispatcher. $pairing carries the authenticated Hub identity
     * (or a synthetic 'loopback' record) so individual cases can audit it.
     * Add new cases here as the protocol grows.
     */
    private static function dispatch( string $action, array $params, ?array $pairing = null ): array {
        global $wpdb;
        switch ( $action ) {
            case 'status.get':
                $keys     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aicom_api_keys WHERE status='active'" );
                $sessions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aicom_sessions  WHERE status='open'" );
                return [
                    'ok'              => true,
                    'aicom_version'   => AICOM_VERSION,
                    'wp_version'      => get_bloginfo( 'version' ),
                    'lock_status'     => AICOM_Lock_Manager::get_effective_lock(),
                    'active_keys'     => $keys,
                    'open_sessions'   => $sessions,
                ];

            case 'lock.set':
                // Hub keeps unrestricted power here — by design. Lock is the
                // emergency response when a site is under attack or the
                // operator wants a blanket freeze. No Local Policy Cap on
                // lock; the only requirement is that we audit who did it.
                $want_soft = ! empty( $params['soft'] );
                $want_hard = ! empty( $params['hard'] );
                $old_lock  = AICOM_Lock_Manager::get_effective_lock();

                AICOM_Lock_Manager::set_soft_lock( $want_soft );
                AICOM_Lock_Manager::set_hard_lock( $want_hard );

                // If Hub asks for full unlock but a schedule would re-lock
                // us instantly, override the schedule until its next start.
                // (Schedule cooperates with Hub locks already — no override
                // needed when Hub sets soft/hard.)
                if ( ! $want_soft && ! $want_hard ) {
                    if ( method_exists( 'AICOM_Lock_Manager', 'get_schedule_next_start' ) ) {
                        $next = AICOM_Lock_Manager::get_schedule_next_start();
                        if ( $next > 0 && method_exists( 'AICOM_Lock_Manager', 'set_schedule_override_until' ) ) {
                            AICOM_Lock_Manager::set_schedule_override_until( $next );
                        }
                    }
                }

                $new_lock = AICOM_Lock_Manager::get_effective_lock();

                // Local audit — investigation post-incident starts here.
                AICOM_Audit_Logger::log( [
                    'remote_ip'           => AICOM_Tool_Router::remote_ip(),
                    'tool_name'           => 'hub.lock.set',
                    'module'              => 'hub',
                    'status'              => 'success',
                    'http_status'         => 200,
                    'api_key_label'       => $pairing ? 'hub:' . $pairing['hub_id'] : 'hub:unknown',
                    'result_summary_json' => wp_json_encode( [
                        'requested_soft' => $want_soft,
                        'requested_hard' => $want_hard,
                        'old_lock'       => $old_lock,
                        'new_lock'       => $new_lock,
                    ] ),
                ] );

                return [ 'ok' => true, 'lock_status' => $new_lock ];

            case 'session.create':
                // PRD §7-8 + §16.3: Hub sends a full key spec; Local validates
                // scopes against its authoritative scope tree, then mints.
                $hub_session = sanitize_text_field( (string) ( $params['hub_session_id'] ?? '' ) );
                $user_label  = sanitize_text_field( (string) ( $params['label'] ?? '' ) );
                $raw_scopes  = is_array( $params['scopes'] ?? null ) ? $params['scopes'] : [];
                $scopes      = array_values( array_unique( array_map( 'sanitize_text_field', $raw_scopes ) ) );

                // Reject any scope outside the authoritative tree. The whole
                // request fails — partial mints are a foot-gun.
                $known   = AICOM_Auth::scope_slugs();
                $unknown = array_values( array_diff( $scopes, $known ) );
                if ( ! empty( $unknown ) ) {
                    self::log_security( 'scope_violation', 'session.create unknown scopes: ' . implode( ',', $unknown ) );
                    return [ 'ok' => false, 'error' => 'scope_forbidden', 'unknown' => $unknown ];
                }
                if ( empty( $scopes ) ) {
                    return [ 'ok' => false, 'error' => 'no_scopes' ];
                }

                $restrictions = self::sanitize_restrictions(
                    is_array( $params['restrictions'] ?? null ) ? $params['restrictions'] : []
                );

                // Cap expires_at: never longer than SESSION_MAX_MINUTES from now.
                $max_ts = time() + self::SESSION_MAX_MINUTES * 60;
                $req_ts = ! empty( $params['expires_at'] ) ? strtotime( (string) $params['expires_at'] . ' UTC' ) : 0;
                $eff_ts = ( $req_ts && $req_ts > time() ) ? min( $req_ts, $max_ts ) : $max_ts;
                $expires_at = gmdate( 'Y-m-d H:i:s', $eff_ts );

                $label = 'Hub: ' . ( $hub_session ?: substr( bin2hex( random_bytes( 4 ) ), 0, 8 ) )
                       . ( $user_label !== '' ? ' — ' . $user_label : '' );

                $created = AICOM_Auth::create_key( $label, $scopes, $restrictions, $expires_at );
                return [
                    'ok'           => true,
                    'key_id'       => $created['id'],
                    'plain_key'    => $created['plain_key'],   // returned exactly once
                    'key_prefix'   => $created['key_prefix'],
                    'last4'        => substr( $created['plain_key'], -4 ),
                    'scopes'       => $scopes,
                    'restrictions' => $restrictions,
                    'expires_at'   => $expires_at,
                    'label'        => $label,
                ];

            case 'session.revoke':
                $key_id = absint( $params['key_id'] ?? 0 );
                if ( ! $key_id ) {
                    return [ 'ok' => false, 'error' => 'missing_key_id' ];
                }
                $ok = AICOM_Auth::revoke_key( $key_id );
                return [ 'ok' => (bool) $ok, 'key_id' => $key_id, 'status' => 'revoked' ];

            case 'key.rotate':
                $key_id = absint( $params['key_id'] ?? 0 );
                if ( ! $key_id ) {
                    return [ 'ok' => false, 'error' => 'missing_key_id' ];
                }
                $new_plain = AICOM_Auth::rotate_key( $key_id );
                if ( ! $new_plain ) {
                    return [ 'ok' => false, 'error' => 'rotate_failed' ];
                }
                return [
                    'ok'        => true,
                    'key_id'    => $key_id,
                    'plain_key' => $new_plain,   // returned exactly once
                    'last4'     => substr( $new_plain, -4 ),
                ];

            case 'key.suspend':
                $key_id = absint( $params['key_id'] ?? 0 );
                if ( ! $key_id ) return [ 'ok' => false, 'error' => 'missing_key_id' ];
                return [ 'ok' => (bool) AICOM_Auth::suspend_key( $key_id ), 'key_id' => $key_id, 'status' => 'suspended' ];

            case 'key.unsuspend':
                $key_id = absint( $params['key_id'] ?? 0 );
                if ( ! $key_id ) return [ 'ok' => false, 'error' => 'missing_key_id' ];
                return [ 'ok' => (bool) AICOM_Auth::unsuspend_key( $key_id ), 'key_id' => $key_id, 'status' => 'active' ];

            case 'key.reset_ip_lock':
                $key_id = absint( $params['key_id'] ?? 0 );
                if ( ! $key_id ) return [ 'ok' => false, 'error' => 'missing_key_id' ];
                AICOM_Auth::reset_ip_lock( $key_id );
                return [ 'ok' => true, 'key_id' => $key_id, 'reset' => true ];

            case 'key.archive':
                $key_id = absint( $params['key_id'] ?? 0 );
                if ( ! $key_id ) return [ 'ok' => false, 'error' => 'missing_key_id' ];
                AICOM_Auth::archive_key( $key_id );
                return [ 'ok' => true, 'key_id' => $key_id, 'status' => 'archived' ];

            case 'key.unarchive':
                $key_id = absint( $params['key_id'] ?? 0 );
                if ( ! $key_id ) return [ 'ok' => false, 'error' => 'missing_key_id' ];
                AICOM_Auth::unarchive_key( $key_id );
                return [ 'ok' => true, 'key_id' => $key_id, 'status' => 'suspended' ];
        }
        return [ 'ok' => false, 'error' => 'unknown_action' ];
    }

    // ── Outbound sync push (Local → Hub) ──────────────────────────────────

    /**
     * Cron entry point. Walks every paired Hub and pushes a batched
     * heartbeat + new audit events since the last successful push.
     */
    public static function cron_push_all(): void {
        foreach ( AICOM_Hub_Pairing::all() as $pairing ) {
            if ( empty( $pairing['management_secret'] ) ) {
                continue; // decrypt failed — skip silently
            }
            self::push_one( $pairing );
        }
    }

    /** Build, sign, and POST one batch to a paired Hub. */
    private static function push_one( array $pairing ): void {
        global $wpdb;
        $opt_key = 'aicom_hub_last_sync_' . (int) $pairing['id'];
        $since   = get_option( $opt_key, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );

        $events = $wpdb->get_results( $wpdb->prepare(
            "SELECT created_at, tool_name, module, status, error_code, api_key_label
             FROM {$wpdb->prefix}aicom_logs
             WHERE created_at > %s
             ORDER BY created_at ASC LIMIT 200",
            $since
        ), ARRAY_A ) ?: [];

        $payload_events = array_map( [ __CLASS__, 'shape_event' ], $events );

        $response = self::send_payload( $pairing, $payload_events, false );

        // Only advance the cursor when the Hub confirmed acceptance, so a
        // failed push retries the same window instead of skipping events.
        if ( ! is_wp_error( $response ) && (int) wp_remote_retrieve_response_code( $response ) === 200 ) {
            update_option( $opt_key, current_time( 'mysql', true ) );
        }
    }

    /**
     * Shape a single audit_log row into the event format the Hub expects.
     * Public so callers in this class can reuse for both batch and immediate paths.
     */
    private static function shape_event( array $row ): array {
        $is_error = strpos( (string) ( $row['status'] ?? '' ), 'success' ) === false;
        return [
            'event_type' => $row['tool_name'] ?? 'unknown',
            'risk_level' => $is_error ? 'high' : 'info',
            'message'    => ! empty( $row['error_code'] ) ? "Error: {$row['error_code']}" : null,
            'metadata'   => [
                'module' => $row['module'] ?? null,
                'actor'  => $row['api_key_label'] ?? null,
            ],
        ];
    }

    /**
     * Sign + POST events to one pairing. Returns the wp_remote_post result
     * (or null when fired non-blocking — caller can't read it).
     */
    private static function send_payload( array $pairing, array $events, bool $urgent ) {
        $body = wp_json_encode( [
            'site_url'      => untrailingslashit( get_site_url() ),
            'aicom_version' => AICOM_VERSION,
            'wp_version'    => get_bloginfo( 'version' ),
            'lock_status'   => AICOM_Lock_Manager::get_effective_lock(),
            'urgent'        => $urgent,
            'events'        => $events,
        ] );

        $ts        = time();
        $nonce     = AICOM_Hub_Signer::nonce();
        $canonical = AICOM_Hub_Signer::canonical( 'POST', self::HUB_SYNC_PATH, $ts, $nonce, $body );
        $sig       = AICOM_Hub_Signer::sign( $canonical, $pairing['management_secret'] );

        $args = [
            'headers' => [
                'Content-Type'       => 'application/json',
                'X-AICOM-Key-Id'     => $pairing['management_key_id'],
                'X-AICOM-Timestamp'  => (string) $ts,
                'X-AICOM-Nonce'      => $nonce,
                'X-AICOM-Request-Id' => bin2hex( random_bytes( 8 ) ),
                'X-AICOM-Signature'  => $sig,
            ],
            'body' => $body,
        ];

        if ( $urgent ) {
            // Fire-and-forget: page-load latency matters, batch will retry anyway.
            $args['timeout']  = 5;
            $args['blocking'] = false;
        } else {
            $args['timeout'] = 20;
        }

        return wp_remote_post(
            untrailingslashit( $pairing['hub_url'] ) . self::HUB_SYNC_PATH,
            $args
        );
    }

    /**
     * Push a single audit-log event to every paired Hub right now (non-blocking).
     * The same event will also appear in the next hourly batch — Hub dedupes by
     * (event_type, created_at, actor) or its own request_id.
     */
    public static function push_event_now( array $log_row ): void {
        $pairings = AICOM_Hub_Pairing::all();
        if ( empty( $pairings ) ) {
            return;
        }
        $event = self::shape_event( $log_row );
        // Force high risk for urgent — signals Hub to surface it immediately.
        $event['risk_level'] = 'high';

        foreach ( $pairings as $pairing ) {
            if ( empty( $pairing['management_secret'] ) ) {
                continue;
            }
            self::send_payload( $pairing, [ $event ], true );
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private static function err( string $code, int $status ): WP_REST_Response {
        return new WP_REST_Response( [ 'error' => $code ], $status );
    }

    /**
     * Whitelist + clean a `restrictions` payload from the Hub. Unknown keys
     * are dropped; arrays of strings are sanitized item-by-item; booleans
     * are coerced. Mirrors what AICOM Local's manual admin handler does in
     * apply_resource_restrictions() and the surrounding create-key flow.
     */
    private static function sanitize_restrictions( array $in ): array {
        $out = [];
        foreach ( self::ALLOWED_RESTRICTIONS as $key ) {
            if ( ! array_key_exists( $key, $in ) ) {
                continue;
            }
            $v = $in[ $key ];
            if ( in_array( $key, [ 'dry_run_only', 'ip_lock' ], true ) ) {
                $out[ $key ] = (bool) $v;
                continue;
            }
            if ( ! is_array( $v ) ) {
                continue;
            }
            $clean = array_values( array_filter( array_map(
                static fn( $x ) => sanitize_text_field( (string) $x ),
                $v
            ), static fn( $x ) => $x !== '' ) );
            if ( ! empty( $clean ) ) {
                $out[ $key ] = $clean;
            }
        }
        return $out;
    }

    private static function log_security( string $event_type, string $message ): void {
        AICOM_Audit_Logger::log( [
            'remote_ip'     => AICOM_Tool_Router::remote_ip(),
            'tool_name'     => 'hub.' . $event_type,
            'module'        => 'hub',
            'status'        => 'blocked',
            'http_status'   => 401,
            'error_message' => $message,
        ] );
    }
}
