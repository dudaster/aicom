<?php
/**
 * Heartbeat + Base → Site command processing (PROTOCOL §4, §6; PRD §16).
 *
 * Pull model (works behind firewalls): every tick the site POSTs a small signed status
 * document (no WordPress content), the response carries commands. Commands are acknowledged
 * through the NEXT heartbeat (`acks`) and de-duplicated by id so a redelivered command is
 * never executed twice. After the commands, queued events and (if needed) the capability
 * inventory are uploaded.
 *
 * Runs from WP-Cron (custom `aicom_minute` schedule) with a throttled opportunistic tick from
 * wp-admin; guarded by an atomic lock so ticks never overlap; never runs on front-end page loads.
 */
defined( 'ABSPATH' ) || exit;

class AICOM_Base_Heartbeat {

    const LOCK_OPT = 'aicom_base_hb_lock';
    const LOCK_TTL = 150;

    // ── lock state ────────────────────────────────────────────────────────

    /** Strictest of the local Lock Manager and the lock imposed by AICOMBase: none|soft|hard. */
    public static function effective_lock(): string {
        $local = [ 'unlocked' => 0, 'soft_locked' => 1, 'hard_locked' => 2 ][ AICOM_Lock_Manager::get_effective_lock() ] ?? 0;
        $base  = [ 'none' => 0, 'soft' => 1, 'hard' => 2 ][ AICOM_Base_State::base_lock() ] ?? 0;
        return [ 'none', 'soft', 'hard' ][ max( $local, $base ) ];
    }

    // ── tick ──────────────────────────────────────────────────────────────

    /**
     * @param string $source cron|admin|connect|manual|wake
     * @return array{ok:bool,skipped?:string,error?:string,commands?:int}
     */
    public static function run( string $source = 'cron' ): array {
        if ( ! AICOM_Base_State::is_connected() ) {
            return [ 'ok' => false, 'skipped' => 'not_connected' ];
        }
        // A wake ping means AICOMBase just queued something — bypass backoff like an explicit admin action would.
        $force = in_array( $source, [ 'connect', 'manual', 'wake' ], true );
        if ( ! $force && time() < (int) AICOM_Base_State::get( 'next_attempt_at', 0 ) ) {
            return [ 'ok' => false, 'skipped' => 'backoff' ];
        }
        if ( ! self::acquire() ) {
            return [ 'ok' => false, 'skipped' => 'locked' ];
        }
        try {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            @set_time_limit( 150 );
            AICOM_Base_Rotation::resume();
            if ( ! AICOM_Base_State::is_connected() ) {
                return [ 'ok' => false, 'skipped' => 'not_connected' ];
            }

            $sent_acks = (array) AICOM_Base_State::get( 'acks', [] );
            $timeout   = ( $source === 'admin' ) ? 4 : 10;
            $res       = AICOM_Base_Client::site_request( 'POST', '/api/v1/site/heartbeat', self::body( $sent_acks ), [ 'timeout' => $timeout ] );
            AICOM_Base_State::update( [ 'last_hb_at' => time() ] );
            if ( ! $res['ok'] ) {
                return [ 'ok' => false, 'error' => $res['error'] ];
            }

            $d = $res['data'];
            $remaining_acks = array_values( array_diff( (array) AICOM_Base_State::get( 'acks', [] ), $sent_acks ) );
            AICOM_Base_State::update( [
                'acks'        => $remaining_acks,
                'caps_needed' => ! empty( $d['capabilities_needed'] ) || (bool) AICOM_Base_State::get( 'caps_needed', false ),
                'clock_skew'  => isset( $d['server_time'] ) ? ( is_numeric( $d['server_time'] ) ? (int) $d['server_time'] : (int) strtotime( (string) $d['server_time'] ) ) - time() : 0,
                'health'      => self::health(),
            ] );

            // Commands first (they may take a while), then uploads.
            $deadline   = microtime( true ) + 100;
            $cmd_count  = self::process_commands( is_array( $d['commands'] ?? null ) ? $d['commands'] : [], $deadline );
            if ( ! AICOM_Base_State::is_connected() ) {
                return [ 'ok' => true ]; // revoked mid-tick
            }

            // Upload the inventory when AICOMBase asks, on resync, or when our hash changed —
            // throttled so a misbehaving server can't make us re-send it every minute.
            $hash_changed = AICOM_Base_Capabilities::current_hash() !== (string) AICOM_Base_State::get( 'caps_hash_sent', '' );
            $recent       = time() - (int) AICOM_Base_State::get( 'caps_uploaded_at', 0 ) < 300;
            if ( ( AICOM_Base_State::get( 'caps_needed' ) && ( ! $recent || ! AICOM_Base_State::get( 'caps_hash_sent' ) ) ) || ( $hash_changed && ! $recent ) ) {
                AICOM_Base_Capabilities::upload();
            }
            AICOM_Base_Events::flush();
            AICOM_Base_Executor::flush_pending_results();
            return [ 'ok' => true, 'commands' => $cmd_count ];
        } catch ( \Throwable $e ) {
            AICOM_Base_State::update( [ 'last_error' => [ 'code' => 'exception', 'status' => 0, 'message' => substr( $e->getMessage(), 0, 200 ), 'at' => time() ] ] );
            return [ 'ok' => false, 'error' => 'exception' ];
        } finally {
            self::release();
        }
    }

    // ── body ──────────────────────────────────────────────────────────────

    public static function body( array $acks ): array {
        global $wpdb;
        $last = $wpdb->get_var( "SELECT MAX(opened_at) FROM {$wpdb->prefix}aicom_sessions" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $lock = self::effective_lock();
        $h    = self::health();

        $state = 'connected';
        if ( AICOM_Base_State::is_paused() ) {
            $state = 'paused';
        } elseif ( $lock !== 'none' ) {
            $state = 'locked';
        }
        return [
            'site_id'            => AICOM_Base_State::site_id(),
            'timestamp'          => time(),
            'aicom_version'      => AICOM_VERSION,
            'wp_version'         => get_bloginfo( 'version' ),
            'php_version'        => PHP_VERSION,
            'capabilities_hash'  => AICOM_Base_Capabilities::current_hash(),
            'health'             => $h,
            'last_session_at'    => $last ? gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $last . ' UTC' ) ) : null,
            'connection_state'   => $state,
            'lock'               => [ 'soft' => $lock === 'soft', 'hard' => $lock === 'hard' ],
            'acks'               => array_values( $acks ),
        ];
    }

    /** Small self-diagnosis: {ok, issues[]}. */
    public static function health(): array {
        $issues = [];
        if ( AICOM_Base_Events::pending_count() > 1000 ) {
            $issues[] = 'events_backlog';
        }
        if ( abs( (int) AICOM_Base_State::get( 'clock_skew', 0 ) ) > 120 ) {
            $issues[] = 'clock_skew';
        }
        if ( get_option( AICOM_DB::VERSION_OPT ) !== AICOM_DB::DB_VERSION ) {
            $issues[] = 'db_schema_outdated';
        }
        if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
            $issues[] = 'wp_cron_disabled';
        }
        return [ 'ok' => ! array_diff( $issues, [ 'wp_cron_disabled' ] ), 'issues' => $issues ];
    }

    // ── commands ──────────────────────────────────────────────────────────

    /** @return int how many commands this heartbeat response carried — used by AICOM_Base_Wake to decide whether to keep lingering for more. */
    private static function process_commands( array $cmds, float $deadline ): int {
        foreach ( $cmds as $cmd ) {
            if ( ! is_array( $cmd ) ) {
                continue;
            }
            $id   = (string) ( $cmd['id'] ?? '' );
            $type = (string) ( $cmd['type'] ?? '' );
            if ( $id === '' || $type === '' ) {
                continue;
            }
            if ( AICOM_Base_State::command_done( $id ) ) {
                // Redelivered because our ack didn't land yet — re-ack, never re-run.
                AICOM_Base_State::update( [ 'acks' => array_values( array_unique( array_merge( (array) AICOM_Base_State::get( 'acks', [] ), [ $id ] ) ) ) ] );
                continue;
            }
            if ( ! empty( $cmd['expires_at'] ) ) {
                $exp = is_numeric( $cmd['expires_at'] ) ? (int) $cmd['expires_at'] : (int) strtotime( (string) $cmd['expires_at'] );
                if ( $exp && $exp < time() ) {
                    AICOM_Base_State::mark_command_done( $id );
                    continue;
                }
            }
            // A revoke issued BEFORE this pairing existed is stale (e.g. queued for the previous connection of a
            // re-paired site) — obeying it would disconnect a brand-new connection. Ignore + ack it.
            if ( $type === 'revoke' && ! empty( $cmd['issued_at'] ) && (int) $cmd['issued_at'] < (int) AICOM_Base_State::get( 'connected_at', 0 ) - 2 ) {
                AICOM_Base_Connection::audit( 'base.stale_revoke_ignored', 'success', [ 'command' => $id ] );
                AICOM_Base_State::mark_command_done( $id );
                continue;
            }
            if ( $type === 'execute' && microtime( true ) > $deadline ) {
                continue; // out of time budget; AICOMBase will redeliver it
            }

            $handled = true;
            switch ( $type ) {
                case 'execute':
                    $handled = AICOM_Base_Executor::execute( $cmd );
                    break;
                case 'inspect':
                    $handled = AICOM_Base_Inspector::handle( $cmd );
                    break;
                case 'lock':
                    self::cmd_lock( (string) ( $cmd['state'] ?? ( $cmd['payload']['state'] ?? 'none' ) ) );
                    break;
                case 'pause':
                    $p = $cmd['paused'] ?? ( $cmd['payload']['paused'] ?? true );
                    self::cmd_pause( (bool) $p );
                    break;
                case 'revoke':
                    AICOM_Base_Connection::handle_revoked( 'Revoked from AICOMBase.' );
                    return count( $cmds );
                case 'rotate_credentials':
                    AICOM_Base_Rotation::run( (string) ( $cmd['rotation_id'] ?? ( $cmd['payload']['rotation_id'] ?? '' ) ) );
                    break;
                case 'resync_capabilities':
                    AICOM_Base_State::update( [ 'caps_needed' => true, 'caps_uploaded_at' => 0 ] );
                    break;
                default:
                    AICOM_Base_Connection::audit( 'base.command_unknown', 'error', [ 'type' => substr( $type, 0, 40 ) ] );
            }
            if ( $handled && AICOM_Base_State::is_connected() ) {
                AICOM_Base_State::mark_command_done( $id );
            }
        }
        return count( $cmds );
    }

    private static function cmd_lock( string $state ): void {
        $state = in_array( $state, [ 'soft', 'hard', 'none' ], true ) ? $state : 'none';
        $old   = AICOM_Base_State::base_lock();
        AICOM_Base_State::update( [ 'base_lock' => $state ] );
        AICOM_Base_Connection::audit( 'base.lock', $state === 'none' ? 'success' : 'blocked', [ 'old' => $old, 'new' => $state, 'by' => 'base' ] );
        AICOM_Base_Events::enqueue( 'lock', [ 'state' => $state, 'by' => 'base' ] );
    }

    private static function cmd_pause( bool $paused ): void {
        AICOM_Base_State::update( [ 'paused' => $paused ] );
        AICOM_Base_Connection::audit( $paused ? 'base.paused' : 'base.resumed', $paused ? 'blocked' : 'success', [ 'paused' => $paused, 'by' => 'base' ] );
    }

    // ── overlap lock (atomic add_option) ──────────────────────────────────

    private static function acquire(): bool {
        if ( add_option( self::LOCK_OPT, time(), '', 'no' ) ) {
            return true;
        }
        $t = (int) get_option( self::LOCK_OPT, 0 );
        if ( $t && time() - $t > self::LOCK_TTL ) {
            update_option( self::LOCK_OPT, time(), false );
            return true;
        }
        return false;
    }

    private static function release(): void {
        delete_option( self::LOCK_OPT );
    }
}
