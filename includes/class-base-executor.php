<?php
/**
 * Remote execution of AICOMBase-authorized tasks (PROTOCOL §9, PRD §13–14).
 *
 * Trust chain, all of which must hold before anything runs:
 *   1. site not paused / hard-locked (soft lock ⇒ read-only work only);
 *   2. token signature verifies against the PINNED AICOMBase public key, aud == site_id,
 *      not expired, lifetime ≤ 15 min, nonce unused (single-use, TTL-stored);
 *   3. token.allowed_scopes ⊆ LOCAL policy (authoritative scope tree + the admin-configured
 *      allow-list) — a violation is refused and reported as a `security` event `scope_rejected`;
 *   4. the requested tool's own required scopes ⊆ token scopes;
 *   5. the existing Tool Router then applies its own gates (lock matrix, confirm flag, session, audit).
 *
 * The tool runs through the normal machinery under a FRESH AICOM session with an ephemeral,
 * short-lived internal API key limited to the token's scopes. That key never leaves this
 * process and is revoked + archived right after; no AI Bearer key is ever sent to AICOMBase.
 */
defined( 'ABSPATH' ) || exit;

class AICOM_Base_Executor {

    const NONCE_TTL       = 1200; // > max token lifetime (900 s)
    const RESULT_MAX_JSON = 60000;

    // ── entry point (called from the heartbeat command loop) ──────────────

    /** @return bool true when the command was handled (ack it) */
    public static function execute( array $cmd ): bool {
        $tt = (string) ( $cmd['task_target_id'] ?? '' );
        if ( $tt === '' ) {
            AICOM_Base_Connection::audit( 'base.execute', 'error', [ 'reason' => 'missing task_target_id' ] );
            return true;
        }
        $t0 = microtime( true );

        // 1 ── pause / lock ────────────────────────────────────────────────
        if ( AICOM_Base_State::is_paused() ) {
            return self::refuse( $cmd, 'paused', __( 'Remote work is paused on this site.', 'aicom' ) );
        }
        $lock = AICOM_Base_Heartbeat::effective_lock();
        if ( $lock === 'hard' ) {
            return self::refuse( $cmd, 'hard_locked', __( 'Hard lock: remote execution is disabled.', 'aicom' ) );
        }

        // 2 ── token ───────────────────────────────────────────────────────
        $token = (string) ( $cmd['authorization'] ?? '' );
        $v     = AICOM_Base_Signer::verify_token(
            $token,
            (string) AICOM_Base_State::get( 'base_public_key', '' ),
            (string) AICOM_Base_State::get( 'base_key_id', '' ),
            AICOM_Base_State::site_id()
        );
        if ( ! $v['ok'] ) {
            if ( in_array( $v['error'], [ 'bad_signature', 'wrong_audience', 'wrong_issuer', 'malformed_token' ], true ) ) {
                AICOM_Base_Events::security( 'credential_anomaly', 'high', [ 'reason' => 'authorization_' . $v['error'], 'task_target_id' => $tt ] );
            }
            AICOM_Base_Connection::audit( 'base.execute', 'blocked', [ 'reason' => $v['error'], 'task_target_id' => $tt ] );
            return self::refuse( $cmd, $v['error'], __( 'The execution authorization was rejected.', 'aicom' ) );
        }
        $claims = $v['claims'];
        if ( ! hash_equals( (string) ( $claims['task_target_id'] ?? '' ), $tt ) ) {
            AICOM_Base_Events::security( 'credential_anomaly', 'high', [ 'reason' => 'authorization_target_mismatch', 'task_target_id' => $tt ] );
            return self::refuse( $cmd, 'target_mismatch', __( 'The authorization is not valid for this task.', 'aicom' ) );
        }
        if ( ! self::consume_nonce( (string) $claims['nonce'], (int) $claims['expires_at'] ) ) {
            AICOM_Base_Events::security( 'credential_anomaly', 'high', [ 'reason' => 'authorization_replay', 'task_target_id' => $tt ] );
            AICOM_Base_Connection::audit( 'base.execute', 'blocked', [ 'reason' => 'replay', 'task_target_id' => $tt ] );
            return self::refuse( $cmd, 'replay', __( 'This authorization was already used.', 'aicom' ) );
        }

        // 3 ── local policy cap ────────────────────────────────────────────
        $scopes = array_values( array_unique( array_map( 'strval', $claims['allowed_scopes'] ) ) );
        $chk    = AICOM_Base_Policy::check_scopes( $scopes );
        if ( ! $chk['ok'] ) {
            AICOM_Base_Events::security( 'scope_rejected', 'high', [
                'source'  => 'aicombase_token',
                'unknown' => $chk['unknown'],
                'excess'  => $chk['excess'],
                'task_target_id' => $tt,
            ] );
            AICOM_Base_Connection::audit( 'base.scope_rejected', 'blocked', [ 'unknown' => $chk['unknown'], 'excess' => $chk['excess'], 'task_target_id' => $tt ] );
            return self::refuse( $cmd, 'scope_rejected', __( 'The requested scopes exceed this site\'s local policy.', 'aicom' ), [ 'unknown' => $chk['unknown'], 'excess' => $chk['excess'] ] );
        }

        // 4 ── tool resolution + its own scopes ────────────────────────────
        $params = isset( $cmd['params'] ) && is_array( $cmd['params'] ) ? $cmd['params'] : [];
        $tool   = (string) ( $cmd['tool_id'] ?? ( $params['tool_id'] ?? $params['tool'] ?? '' ) );
        if ( isset( $params['arguments'] ) && is_array( $params['arguments'] ) && $tool !== '' && ( $params['tool_id'] ?? $params['tool'] ?? '' ) !== '' ) {
            $params = $params['arguments'];
        }
        $meta = $tool !== '' ? AICOM_Tool_Registry::get( $tool ) : null;
        if ( ! $meta ) {
            return self::refuse( $cmd, 'unknown_tool', $tool === '' ? __( 'No tool was specified for this task.', 'aicom' ) : __( 'This site does not have the requested tool.', 'aicom' ) );
        }
        if ( $meta['dependency'] !== null && ! AICOM_Module_Detector::is_dependency_active( $meta['dependency'] ) ) {
            return self::refuse( $cmd, 'dependency_missing', __( 'A plugin required by this tool is not active.', 'aicom' ) );
        }
        $have    = AICOM_Auth::expand_implied_scopes( $scopes );
        $missing = array_values( array_diff( (array) $meta['required_scopes'], $have ) );
        if ( $missing ) {
            AICOM_Base_Events::security( 'scope_rejected', 'medium', [ 'source' => 'aicombase_token', 'tool' => $tool, 'missing' => $missing ] );
            return self::refuse( $cmd, 'scope_insufficient', __( 'The authorization does not cover the scopes this tool needs.', 'aicom' ), [ 'missing' => $missing ] );
        }
        if ( $lock === 'soft' && ! in_array( $meta['class'], AICOM_Tool_Registry::READ_ONLY_CLASSES, true ) ) {
            return self::refuse( $cmd, 'soft_locked', __( 'Soft lock: only read-only remote work is allowed.', 'aicom' ) );
        }

        // 5 ── run under an ephemeral key + fresh session ──────────────────
        $result = self::run_tool( $cmd, $claims, $scopes, $tool, $params, $meta );
        $ms     = (int) round( ( microtime( true ) - $t0 ) * 1000 );
        // PROTOCOL: /site/tasks/{id}/result → {status:completed|failed, summary?, result?:object, error?:string, session_id?, verification?:object}
        $body = [
            'status'     => $result['status'],
            'session_id' => (string) $claims['session_id'],
            'summary'    => $result['status'] === 'completed'
                ? sprintf( '%s completed in %d ms', $tool, $ms )
                : sprintf( '%s failed: %s', $tool, (string) ( $result['error']['message'] ?? 'error' ) ),
        ];
        if ( isset( $result['result'] ) ) {
            $body['result'] = $result['result'] ?: new stdClass();
        }
        if ( $result['status'] !== 'completed' ) {
            $body['error'] = substr( ( $result['error']['code'] ?? 'EXECUTION_ERROR' ) . ': ' . ( $result['error']['message'] ?? '' ), 0, 1900 );
        }
        $body['verification'] = [ 'ok' => $result['status'] === 'completed', 'detail' => 'Tool router returned ' . ( $result['status'] === 'completed' ? 'success' : 'an error' ), 'duration_ms' => $ms ];
        self::report( $tt, $body );
        return true;
    }

    // ── run ───────────────────────────────────────────────────────────────

    private static function run_tool( array $cmd, array $claims, array $scopes, string $tool, array $params, array $meta ): array {
        $tt      = (string) $claims['task_target_id'];
        $started = gmdate( 'Y-m-d\TH:i:s\Z' );

        $key = AICOM_Auth::create_key(
            'AICOMBase task ' . substr( $tt, 0, 8 ),
            $scopes,
            [],
            gmdate( 'Y-m-d H:i:s', time() + 900 )
        );
        $key_id = (int) $key['id'];

        // The Tool Router authenticates from the request headers — present the ephemeral key to it,
        // then restore whatever was there. The plain key exists only in this stack frame.
        $saved_auth = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key['plain_key'];

        $out     = [ 'status' => 'failed', 'tool_id' => $tool, 'session_id' => (string) $claims['session_id'], 'started_at' => $started ];
        $session = null;
        try {
            AICOM_Base_Events::expect_remote_session( (string) $claims['session_id'], $tt );
            $title   = sprintf( /* translators: %s: tool id */ __( 'AICOMBase task: %s', 'aicom' ), $tool );
            $session = AICOM_Sessions::open( $key_id, 'AICOMBase task ' . substr( $tt, 0, 8 ), $title, 'Remote work authorized by AICOMBase (task target ' . $tt . ')' );
            if ( ! is_array( $session ) ) {
                $out['error'] = [ 'code' => 'session_failed', 'message' => 'Could not open a session.' ];
            } else {
                AICOM_Base_Events::bind_remote_session( (int) $session['id'], (string) $claims['session_id'], $tt );
                $out['local_session_id'] = (int) $session['id'];

                $body = wp_json_encode( [
                    'jsonrpc' => '2.0',
                    'method'  => 'tools/call',
                    'params'  => [ 'name' => $tool, 'arguments' => $params ? $params : new stdClass() ],
                    'id'      => 1,
                ] );
                $resp = function_exists( 'aicom_safe_dispatch' )
                    ? aicom_safe_dispatch( (string) $body, '2025-06-18' )
                    : AICOM_Tool_Router::dispatch( (string) $body, '2025-06-18' );

                $r = $resp['result'] ?? null;
                if ( is_array( $r ) && empty( $r['isError'] ) ) {
                    $out['status'] = 'completed';
                    $data          = $r['structuredContent'] ?? $r;
                    unset( $data['content'], $data['structuredContent'], $data['request_id'], $data['_meta'] );
                    $out['result'] = self::bound( $data );
                } else {
                    $err = is_array( $r ) ? ( $r['error'] ?? [] ) : ( $resp['error'] ?? [] );
                    $out['error'] = [
                        'code'    => (string) ( $err['code'] ?? 'EXECUTION_ERROR' ),
                        'message' => substr( (string) ( $err['message'] ?? 'Tool execution failed' ), 0, 500 ),
                    ];
                }
            }
        } catch ( \Throwable $e ) {
            $out['error'] = [ 'code' => 'EXECUTION_ERROR', 'message' => substr( $e->getMessage(), 0, 300 ) ];
        } finally {
            if ( $saved_auth === null ) {
                unset( $_SERVER['HTTP_AUTHORIZATION'] );
            } else {
                $_SERVER['HTTP_AUTHORIZATION'] = $saved_auth;
            }
        }

        // Close the session (emits session.ended with the final status) and burn the ephemeral key.
        if ( is_array( $session ) ) {
            AICOM_Sessions::close( $key_id, $out['status'] === 'completed' ? 'completed' : 'failed' );
        }
        AICOM_Auth::revoke_key( $key_id );
        AICOM_Auth::archive_key( $key_id );

        $out['ended_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );
        AICOM_Base_Connection::audit( 'base.execute', $out['status'] === 'completed' ? 'success' : 'error', [ 'tool' => $tool, 'task_target_id' => $tt, 'status' => $out['status'] ] );
        return $out;
    }

    /** Keep the reported result bounded. */
    private static function bound( $data ): array {
        $json = (string) wp_json_encode( $data );
        if ( strlen( $json ) <= self::RESULT_MAX_JSON ) {
            return is_array( $data ) ? $data : [ 'value' => $data ];
        }
        return [ 'truncated' => true, 'bytes' => strlen( $json ), 'preview' => substr( $json, 0, 2000 ) ];
    }

    // ── reporting ─────────────────────────────────────────────────────────

    private static function refuse( array $cmd, string $code, string $message, array $extra = [] ): bool {
        $tt = (string) ( $cmd['task_target_id'] ?? '' );
        // AICOMBase's result endpoint knows only completed|failed; a refusal is a failure with a `refused:` reason.
        $detail = $extra ? ' ' . wp_json_encode( $extra ) : '';
        self::report( $tt, [
            'status'  => 'failed',
            'summary' => 'Refused by the site: ' . $code,
            'error'   => substr( 'refused:' . $code . ' — ' . $message . $detail, 0, 1900 ),
        ] );
        return true;
    }

    private static function report( string $tt, array $body ): void {
        if ( $tt === '' ) {
            return;
        }
        $res = AICOM_Base_Client::site_request( 'POST', '/api/v1/site/tasks/' . rawurlencode( $tt ) . '/result', $body, [ 'timeout' => 20 ] );
        if ( ! $res['ok'] ) {
            // Keep it for the next tick — the result is not lost if AICOMBase is briefly unreachable.
            $pending   = (array) AICOM_Base_State::get( 'pending_results', [] );
            $pending[ $tt ] = $body;
            AICOM_Base_State::update( [ 'pending_results' => array_slice( $pending, -20, null, true ) ] );
        }
    }

    /** Retry results that could not be delivered earlier. */
    public static function flush_pending_results(): void {
        $pending = (array) AICOM_Base_State::get( 'pending_results', [] );
        if ( ! $pending ) {
            return;
        }
        foreach ( $pending as $tt => $body ) {
            $res = AICOM_Base_Client::site_request( 'POST', '/api/v1/site/tasks/' . rawurlencode( (string) $tt ) . '/result', $body, [ 'timeout' => 20 ] );
            if ( $res['ok'] || ( $res['status'] >= 400 && $res['status'] < 500 && $res['status'] !== 429 ) ) {
                unset( $pending[ $tt ] );
            } else {
                break;
            }
        }
        AICOM_Base_State::update( [ 'pending_results' => $pending ] );
    }

    // ── nonce single-use store ────────────────────────────────────────────

    /** Atomically record the nonce. False when it was already seen. */
    public static function consume_nonce( string $nonce, int $expires_at ): bool {
        global $wpdb;
        $t   = $wpdb->prefix . 'aicom_base_nonces';
        $exp = gmdate( 'Y-m-d H:i:s', max( $expires_at, time() ) + self::NONCE_TTL );
        $wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE expires_at < %s", current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $ok = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $t (nonce, expires_at) VALUES (%s, %s)", $nonce, $exp ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        return $ok === 1;
    }

    public static function purge_nonces(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}aicom_base_nonces" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    }
}
