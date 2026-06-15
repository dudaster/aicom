<?php
/**
 * Tool Router: 12-step validation pipeline per SPEC section 11.
 *
 * Input formats supported:
 *  - MCP standard (JSON-RPC 2.0): {"jsonrpc":"2.0","method":"tools/call","params":{"name":"tool","arguments":{}},"id":1}
 *  - MCP list:                    {"jsonrpc":"2.0","method":"tools/list","params":{},"id":1}
 *  - Shorthand method:            {"method":"tool.name","params":{}}
 *  - Shorthand tool:              {"tool":"tool.name","arguments":{}}
 *  - JSON-RPC 2.0 batch:          [{...},{...}] — array of any of the above; returns array of responses
 *
 * Step order (MUST NOT be changed — deterministic, auditable):
 *  1.  Parse request + normalize tool_name/arguments + generate request_id
 *  2.  Check lock state (hard → soft) against tool class
 *  3.  Authenticate API key (hash verify, status active, IP allowlist)
 *  4.  Check tool exists in registry
 *  5.  Check module dependency active (WooCommerce / Elementor / Polylang)
 *  6.  Verify scopes on API key
 *  7.  (Delegated to handler) Allowlist/restriction checks
 *  8.  Check confirm flag for destructive/admin_sensitive tools
 *  9.  Determine dry_run mode
 * 10.  Execute tool handler
 * 11.  Update key last_used_at
 * 12.  Audit log + return MCP response
 */
class AICOM_Tool_Router {

    /** Session ID of the active session for the current request (0 = none). */
    public static int $current_session_id = 0;

    /** Scopes of the currently-executing key, set just before the cap filter and cleared after. */
    private static array $current_key_scopes = [];

    // ── Main Dispatch ─────────────────────────────────────────────────────

    public static function dispatch( string $raw_body ): array {
        $remote_ip = self::remote_ip();
        $start     = microtime( true );
        $payload   = json_decode( $raw_body, true, 64 );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return self::early_error( 'parse_error', 'Invalid JSON payload', 400, wp_generate_uuid4(), $remote_ip, '', 'unknown', $start, 0, '', null );
        }

        // ── JSON-RPC 2.0 batch: top-level JSON array (empty or indexed) ──
        if ( is_array( $payload ) && ( empty( $payload ) || array_key_exists( 0, $payload ) ) ) {
            if ( empty( $payload ) ) {
                return self::mcp_error( 'INVALID_REQUEST', 'Empty batch array', wp_generate_uuid4(), null );
            }
            $responses = [];
            foreach ( $payload as $item ) {
                if ( ! is_array( $item ) ) {
                    $responses[] = self::mcp_error( 'INVALID_REQUEST', 'Batch item must be an object', wp_generate_uuid4(), null );
                    continue;
                }
                $rpc_id = $item['id'] ?? null;
                $result = self::run( $item, $remote_ip );
                if ( $rpc_id !== null ) {
                    $responses[] = $result; // notifications (no id) are processed but not returned
                }
            }
            return $responses;
        }

        // ── Single request ────────────────────────────────────────────────
        return self::run( is_array( $payload ) ? $payload : [], $remote_ip );
    }

    /**
     * Process a single pre-parsed JSON-RPC/shorthand payload through the full 12-step pipeline.
     */
    private static function run( array $payload, string $remote_ip ): array {
        $start      = microtime( true );
        $request_id = wp_generate_uuid4();
        $rpc_id     = null;

        // ── Step 1: Parse ─────────────────────────────────────────────────
        // Extract JSON-RPC id (present when client uses MCP standard format)
        $rpc_id     = $payload['id'] ?? null;
        $rpc_method = trim( (string) ( $payload['method'] ?? '' ) );

        if ( $rpc_method === 'tools/call' && isset( $payload['params']['name'] ) ) {
            // MCP standard: {"jsonrpc":"2.0","method":"tools/call","params":{"name":"...","arguments":{}},"id":1}
            $tool_name = trim( (string) $payload['params']['name'] );
            $arguments = (array) ( $payload['params']['arguments'] ?? [] );
        } elseif ( $rpc_method === 'tools/list' ) {
            // MCP standard list request
            $tool_name = 'tools/list';
            $arguments = [];
        } else {
            // Shorthand: {"method":"tool.name","params":{}} or {"tool":"tool.name","arguments":{}}
            $tool_name = trim( (string) ( $payload['tool'] ?? $rpc_method ) );
            $arguments = (array) ( $payload['arguments'] ?? $payload['params'] ?? [] );
        }

        // ── MCP handshake methods ─────────────────────────────────────────
        // Handled before lock/auth/session so strict MCP clients can complete
        // their initialize handshake. No tool is invoked, no state is mutated.
        if ( $rpc_method === 'initialize' ) {
            return self::jsonrpc_wrap( [
                'protocolVersion' => '2024-11-05',
                'capabilities'    => [
                    'tools' => [ 'listChanged' => false ],
                ],
                'serverInfo'      => [
                    'name'    => 'AICOM',
                    'version' => defined( 'AICOM_VERSION' ) ? AICOM_VERSION : '0.0.0',
                ],
                'instructions'    => implode( "\n", [
                    'ALWAYS start with these 3 calls in order:',
                    '1. tools/list  →  {"jsonrpc":"2.0","method":"tools/list","params":{},"id":1}',
                    '2. aicom.recipes(task:"what you want to do")  →  get step-by-step guidance scoped to your key',
                    '3. session.open(name:"label", description:"what and why")  →  required before any write tool',
                    '',
                    'Errors:',
                    '  NO_ACTIVE_SESSION  → do step 3 first',
                    '  TOOL_NOT_FOUND     → do step 1; call aicom.recipes for task guidance',
                    '  DENIED_SCOPE       → your key lacks the required scope; ask the site admin',
                    '  SOFT_LOCK_ACTIVE   → site is read-only; only read/discovery tools work',
                    '  CONFIRM_REQUIRED   → add "confirm":true to arguments',
                ] ),
                'request_id'      => $request_id,
            ], $rpc_id );
        }

        if ( $rpc_method === 'notifications/initialized' ) {
            // Notification — no response body. Some clients still expect a 200.
            return self::jsonrpc_wrap( [ 'request_id' => $request_id ], $rpc_id );
        }

        if ( $rpc_method === 'ping' ) {
            return self::jsonrpc_wrap( [ 'request_id' => $request_id ], $rpc_id );
        }

        if ( $tool_name === '' ) {
            return self::early_error( 'parse_error', 'Missing tool name', 400, $request_id, $remote_ip, '', 'unknown', $start, 0, '', $rpc_id );
        }

        // Resolve metadata early (needed for lock check)
        $tool_meta   = AICOM_Tool_Registry::get( $tool_name );
        $tool_class  = $tool_meta['class']  ?? 'unknown';
        $tool_module = $tool_meta['module'] ?? 'unknown';

        // ── Step 2: Lock check ────────────────────────────────────────────
        $effective_lock = AICOM_Lock_Manager::get_effective_lock();

        // If tool exists AND its class is blocked — reject before auth.
        // Unknown tools fall through to step 4 (tool not found) after auth.
        if ( $tool_meta !== null && ! AICOM_Policy_Engine::is_tool_allowed_by_lock( $tool_class, $effective_lock ) ) {
            $lock_status = $effective_lock === 'hard_locked' ? 'blocked_hard_lock' : 'blocked_soft_lock';
            $lock_code   = $effective_lock === 'hard_locked' ? 'HARD_LOCK_ACTIVE'  : 'SOFT_LOCK_ACTIVE';

            AICOM_Audit_Logger::log( [
                'request_id'  => $request_id,
                'remote_ip'   => $remote_ip,
                'tool_name'   => $tool_name,
                'module'      => $tool_module,
                'status'      => $lock_status,
                'http_status' => 403,
                'duration_ms' => self::elapsed( $start ),
                'error_code'  => $lock_code,
                'params_json' => self::safe_json( $arguments ),
            ] );

            return self::mcp_error( $lock_code, ucwords( str_replace( '_', ' ', $lock_status ) ), $request_id, $rpc_id );
        }

        // ── Step 3: Auth ──────────────────────────────────────────────────
        $plain_key = AICOM_Auth::extract_key_from_request();

        if ( ! $plain_key ) {
            return self::early_error( 'auth_failed', 'API key missing', 401, $request_id, $remote_ip, $tool_name, $tool_module, $start, 0, '', $rpc_id );
        }

        if ( self::is_rate_limited( $remote_ip ) ) {
            return self::early_error( 'rate_limited', 'Too many failed authentication attempts — try again later', 429, $request_id, $remote_ip, $tool_name, $tool_module, $start, 0, '', $rpc_id );
        }

        $key_record = AICOM_Auth::validate_key( $plain_key );

        if ( ! $key_record ) {
            self::record_auth_failure( $remote_ip );
            return self::early_error( 'auth_failed', 'Invalid or revoked API key', 403, $request_id, $remote_ip, $tool_name, $tool_module, $start, 0, '', $rpc_id );
        }

        AICOM_Auth::maybe_auto_bind_ip( $key_record, $remote_ip );

        if ( ! AICOM_Auth::check_ip_allowlist( $key_record, $remote_ip ) ) {
            return self::early_error( 'auth_failed', 'IP not in allowlist', 403, $request_id, $remote_ip, $tool_name, $tool_module, $start, (int) $key_record['id'], $key_record['label'], $rpc_id );
        }

        // Per-key Working Hours: optional schedule restriction, mirrors the
        // site-wide Safety schedule but scoped to this single key.
        if ( ! AICOM_Auth::check_working_hours( $key_record ) ) {
            return self::early_error( 'blocked_working_hours', 'This key is outside its working hours', 403, $request_id, $remote_ip, $tool_name, $tool_module, $start, (int) $key_record['id'], $key_record['label'], $rpc_id );
        }

        $key_id    = (int) $key_record['id'];
        $key_label = $key_record['label'];

        // ── Step 3.5: Session enforcement ─────────────────────────────────
        // Write/destructive/admin_sensitive tools require an open named session.
        // Exempt: session.open, session.close, tools/list, and read/discovery tools.
        $session_exempt = in_array( $tool_name, [ 'session.open', 'session.close', 'tools/list', 'skills.suggestions' ], true )
            || in_array( $tool_class, [ 'read', 'discovery', 'public' ], true )
            || $tool_meta === null; // unknown tools → rejected at step 4

        if ( $session_exempt ) {
            // Read/discovery tools don't require a session, but associate with one
            // if open — so they appear in the session's audit log for skill analysis.
            $ambient = AICOM_Sessions::get_active( $key_id );
            self::$current_session_id = $ambient ? (int) $ambient['id'] : 0;
        } else {
            $active_session = AICOM_Sessions::get_active( $key_id );
            if ( ! $active_session ) {
                return self::keyed_error(
                    $request_id, $remote_ip, $key_id, $key_label,
                    $tool_name, $tool_module,
                    'NO_ACTIVE_SESSION',
                    'REQUIRED — not optional. Open a session first: session.open(name: "Short label", description: "What you plan to do and why"). Read/discovery tools work without a session.',
                    'validation_failed', 400, $arguments, $start, $rpc_id
                );
            }
            self::$current_session_id = (int) $active_session['id'];
        }

        // ── Step 4: Tool exists ───────────────────────────────────────────
        if ( $tool_meta === null ) {
            $suggestions = self::suggest_tools( $tool_name );
            $hint = $suggestions
                ? "Tool not found: $tool_name. Did you mean: " . implode( ', ', $suggestions ) . '? Call tools/list to see all tools.'
                : "Tool not found: $tool_name. Call tools/list to see all tools, or aicom.recipes for task guidance.";
            return self::keyed_error( $request_id, $remote_ip, $key_id, $key_label, $tool_name, $tool_module, 'TOOL_NOT_FOUND', $hint, 'error', 404, $arguments, $start, $rpc_id );
        }

        // ── Step 5: Dependency check ──────────────────────────────────────
        $dependency = $tool_meta['dependency'];

        if ( $dependency !== null ) {
            if ( $dependency === 'woocommerce' ) {
                $dep_active = AICOM_Module_Detector::is_woocommerce_active();
            } elseif ( $dependency === 'elementor' ) {
                $dep_active = AICOM_Module_Detector::is_elementor_active();
            } elseif ( $dependency === 'polylang' ) {
                $dep_active = AICOM_Module_Detector::is_polylang_active();
            } elseif ( $dependency === 'ecs' ) {
                $dep_active = AICOM_Module_Detector::is_ecs_active();
            } elseif ( $dependency === 'ecs_pro' ) {
                $dep_active = AICOM_Module_Detector::is_ecs_pro_active();
            } elseif ( $dependency === 'clautron' ) {
                $dep_active = AICOM_Module_Detector::is_clautron_active();
            } elseif ( $dependency === 'yoast' ) {
                $dep_active = AICOM_Module_Detector::is_yoast_active();
            } else {
                $dep_active = false;
            }

            if ( ! $dep_active ) {
                return self::keyed_error( $request_id, $remote_ip, $key_id, $key_label, $tool_name, $tool_module, 'DEPENDENCY_MISSING', "Required plugin not active: $dependency", 'dependency_missing', 422, $arguments, $start, $rpc_id );
            }
        }

        // ── Step 6: Scope check ───────────────────────────────────────────
        $missing_scopes = AICOM_Auth::missing_scopes( $key_record, $tool_meta['required_scopes'] );
        if ( $missing_scopes ) {
            $hint = 'Insufficient scope. Required: ' . implode( ', ', $missing_scopes ) . '. Ask the site admin to enable it on your API key.';
            return self::keyed_error( $request_id, $remote_ip, $key_id, $key_label, $tool_name, $tool_module, 'DENIED_SCOPE', $hint, 'denied_scope', 403, $arguments, $start, $rpc_id );
        }

        // ── Step 7: (Delegated) Allowlist checks are done inside handlers ─

        // ── Step 8: Confirm flag ──────────────────────────────────────────
        if ( ! AICOM_Policy_Engine::check_confirm_flag( $tool_meta, $arguments ) ) {
            return self::keyed_error( $request_id, $remote_ip, $key_id, $key_label, $tool_name, $tool_module, 'CONFIRM_REQUIRED', 'This operation requires arguments.confirm = true', 'validation_failed', 400, $arguments, $start, $rpc_id );
        }

        // ── Step 9: Dry-run mode ──────────────────────────────────────────
        $is_dry_run = ! empty( $arguments['dry_run'] ) || AICOM_Policy_Engine::is_dry_run_only( $key_record );

        // ── Step 10: Execute ──────────────────────────────────────────────
        $handler = $tool_meta['handler'];

        if ( ! is_callable( $handler ) ) {
            return self::keyed_error( $request_id, $remote_ip, $key_id, $key_label, $tool_name, $tool_module, 'HANDLER_MISSING', 'Tool handler not callable', 'error', 500, $arguments, $start, $rpc_id );
        }

        // Grant only the WP capabilities that the key's scopes actually need.
        // Scoped to tool execution only — cleared immediately in finally.
        self::$current_key_scopes = json_decode( $key_record['scopes_json'] ?? '[]', true ) ?: [];
        add_filter( 'user_has_cap', [ 'AICOM_Tool_Router', 'grant_api_request_caps' ], 999 );

        $exec_exception = null;
        try {
            $result = call_user_func( $handler, $arguments, $key_record, $is_dry_run );
        } catch ( Throwable $e ) {
            $exec_exception = $e;
        } finally {
            remove_filter( 'user_has_cap', [ 'AICOM_Tool_Router', 'grant_api_request_caps' ], 999 );
            self::$current_key_scopes = [];
        }

        if ( $exec_exception !== null ) {
            return self::keyed_error( $request_id, $remote_ip, $key_id, $key_label, $tool_name, $tool_module, 'EXECUTION_ERROR', $exec_exception->getMessage(), 'error', 500, $arguments, $start, $rpc_id );
        }

        // ── Step 11: Touch key ────────────────────────────────────────────
        AICOM_Auth::touch_key( $key_id, $remote_ip );

        // ── Step 12: Audit log + return ───────────────────────────────────
        $duration = self::elapsed( $start );

        $is_error = isset( $result['error'] );
        $meta     = $result['_meta'] ?? [];

        AICOM_Audit_Logger::log( [
            'request_id'          => $request_id,
            'session_id'          => self::$current_session_id ?: null,
            'remote_ip'           => $remote_ip,
            'api_key_id'          => $key_id,
            'api_key_label'       => $key_label,
            'tool_name'           => $tool_name,
            'module'              => $tool_module,
            'tool_class'          => $tool_class,
            'status'              => $is_error ? ( $result['error']['status_code'] ?? 'error' ) : 'success',
            'http_status'         => $is_error ? ( $result['http_status'] ?? 500 ) : 200,
            'duration_ms'         => $duration,
            'target_type'         => $meta['target_type'] ?? null,
            'target_id'           => isset( $meta['target_id'] ) ? (string) $meta['target_id'] : null,
            'is_dry_run'          => $is_dry_run ? 1 : 0,
            'error_code'          => $is_error ? ( $result['error']['code'] ?? 'ERROR' ) : null,
            'error_message'       => $is_error ? ( $result['error']['message'] ?? '' ) : null,
            'params_json'         => self::safe_json( $arguments ),
            'result_summary_json' => ! $is_error ? self::safe_json( $meta['summary'] ?? [] ) : null,
        ] );

        // Strip internal keys before returning
        unset( $result['_meta'], $result['http_status'] );
        $result['request_id'] = $request_id;

        return self::jsonrpc_wrap( $result, $rpc_id );
    }

    // ── Error Builders ────────────────────────────────────────────────────

    /**
     * Error before auth (no key_id known). Logs with minimal data.
     */
    private static function early_error(
        string $status_code,
        string $message,
        int    $http_status,
        string $request_id,
        string $remote_ip,
        string $tool_name,
        string $tool_module,
        float  $start,
        int    $key_id = 0,
        string $key_label = '',
        $rpc_id = null
    ): array {
        AICOM_Audit_Logger::log( [
            'request_id'    => $request_id,
            'remote_ip'     => $remote_ip,
            'api_key_id'    => $key_id ?: null,
            'api_key_label' => $key_label ?: null,
            'tool_name'     => $tool_name ?: 'unknown',
            'module'        => $tool_module,
            'status'        => $status_code,
            'http_status'   => $http_status,
            'duration_ms'   => self::elapsed( $start ),
            'error_code'    => strtoupper( $status_code ),
            'error_message' => $message,
        ] );

        return self::mcp_error( strtoupper( $status_code ), $message, $request_id, $rpc_id );
    }

    /**
     * Error after auth (key_id known).
     */
    private static function keyed_error(
        string $request_id,
        string $remote_ip,
        int    $key_id,
        string $key_label,
        string $tool_name,
        string $tool_module,
        string $error_code,
        string $message,
        string $status_code,
        int    $http_status,
        array  $arguments,
        float  $start,
        $rpc_id = null
    ): array {
        AICOM_Audit_Logger::log( [
            'request_id'    => $request_id,
            'remote_ip'     => $remote_ip,
            'api_key_id'    => $key_id,
            'api_key_label' => $key_label,
            'tool_name'     => $tool_name,
            'module'        => $tool_module,
            'status'        => $status_code,
            'http_status'   => $http_status,
            'duration_ms'   => self::elapsed( $start ),
            'error_code'    => $error_code,
            'error_message' => $message,
            'params_json'   => self::safe_json( $arguments ),
        ] );

        return self::mcp_error( $error_code, $message, $request_id, $rpc_id );
    }

    private static function mcp_error( string $code, string $message, string $request_id, $rpc_id = null ): array {
        $response = [
            'error'      => [ 'code' => $code, 'message' => $message ],
            'request_id' => $request_id,
        ];
        return self::jsonrpc_wrap( $response, $rpc_id );
    }

    /**
     * Wrap response in JSON-RPC 2.0 envelope when client sent a jsonrpc request (id present).
     * For error responses, error stays at top level per JSON-RPC spec.
     * For success responses, payload moves into result key.
     */
    private static function jsonrpc_wrap( array $response, $rpc_id ): array {
        if ( $rpc_id === null ) {
            return $response; // legacy / shorthand format — no wrapping
        }

        $base = [ 'jsonrpc' => '2.0', 'id' => $rpc_id ];

        if ( isset( $response['error'] ) ) {
            return $base + [
                'error'      => $response['error'],
                'request_id' => $response['request_id'] ?? null,
            ];
        }

        return $base + [ 'result' => $response ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private static function elapsed( float $start ): int {
        return (int) round( ( microtime( true ) - $start ) * 1000 );
    }

    private static function safe_json( array $data ): string {
        return wp_json_encode( self::strip_sensitive( $data ) ) ?: '{}';
    }

    private static function strip_sensitive( $data ) {
        if ( ! is_array( $data ) ) {
            return $data;
        }
        static $deny = [ 'password', 'pass', 'user_pass', 'secret', 'token', 'api_key', 'auth_key', 'auth_pass', 'auth' ];
        foreach ( $data as $k => $v ) {
            if ( in_array( strtolower( (string) $k ), $deny, true ) ) {
                $data[ $k ] = '***';
            } elseif ( is_array( $v ) ) {
                $data[ $k ] = self::strip_sensitive( $v );
            }
        }
        return $data;
    }

    /**
     * Grant only the WP capabilities that the current key's scopes require.
     * Runs only during step 10 (Execute) and is always removed in finally.
     */
    public static function grant_api_request_caps( array $allcaps ): array {
        $s = self::$current_key_scopes;

        $allcaps['read'] = true;

        if ( in_array( 'read.wp', $s, true ) ) {
            $allcaps['edit_posts'] = true; // WP_Query / get_post() needs this for non-public post types
        }

        if ( in_array( 'write.wp.posts', $s, true ) ) {
            $allcaps['edit_posts']             = true;
            $allcaps['edit_others_posts']      = true;
            $allcaps['edit_published_posts']   = true;
            $allcaps['delete_posts']           = true;
            $allcaps['delete_others_posts']    = true;
            $allcaps['delete_published_posts'] = true;
            $allcaps['publish_posts']          = true;
            $allcaps['manage_categories']      = true;
        }

        if ( in_array( 'manage.meta', $s, true ) ) {
            $allcaps['edit_posts']           = true;
            $allcaps['edit_others_posts']    = true;
            $allcaps['edit_published_posts'] = true;
        }

        if ( in_array( 'manage.media', $s, true ) ) {
            $allcaps['upload_files']           = true;
            $allcaps['edit_posts']             = true;
            $allcaps['edit_others_posts']      = true;
            $allcaps['edit_published_posts']   = true;
            $allcaps['delete_posts']           = true;
            $allcaps['delete_others_posts']    = true;
            $allcaps['delete_published_posts'] = true;
        }

        if ( in_array( 'manage.files', $s, true ) ) {
            $allcaps['upload_files'] = true;
        }

        if ( in_array( 'manage.wordpress.settings', $s, true ) ) {
            $allcaps['manage_options'] = true;
        }

        if ( in_array( 'read.users', $s, true ) ) {
            $allcaps['list_users'] = true;
        }

        if ( in_array( 'manage.users', $s, true ) ) {
            $allcaps['list_users']    = true;
            $allcaps['edit_users']    = true;
            $allcaps['promote_users'] = true;
            $allcaps['create_users']  = true;
        }

        if ( in_array( 'delete.users', $s, true ) ) {
            $allcaps['delete_users'] = true;
            $allcaps['list_users']   = true;
        }

        if ( in_array( 'manage.roles', $s, true ) ) {
            $allcaps['list_users']    = true;
            $allcaps['edit_users']    = true;
            $allcaps['promote_users'] = true;
        }

        if ( in_array( 'manage.backups', $s, true ) ) {
            $allcaps['edit_posts']             = true;
            $allcaps['edit_others_posts']      = true;
            $allcaps['edit_published_posts']   = true;
            $allcaps['delete_posts']           = true;
            $allcaps['delete_others_posts']    = true;
            $allcaps['publish_posts']          = true;
            $allcaps['manage_categories']      = true;
        }

        if ( in_array( 'manage.woocommerce.products', $s, true )
            || in_array( 'manage.woocommerce.settings', $s, true ) ) {
            $allcaps['manage_woocommerce']     = true;
            $allcaps['edit_posts']             = true;
            $allcaps['edit_others_posts']      = true;
            $allcaps['edit_published_posts']   = true;
            $allcaps['delete_posts']           = true;
            $allcaps['delete_others_posts']    = true;
            $allcaps['delete_published_posts'] = true;
            $allcaps['publish_posts']          = true;
        }

        if ( in_array( 'manage.elementor', $s, true ) ) {
            $allcaps['edit_posts']           = true;
            $allcaps['edit_others_posts']    = true;
            $allcaps['edit_published_posts'] = true;
            $allcaps['publish_posts']        = true;
        }

        return $allcaps;
    }

    public static function remote_ip(): string {
        $remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';

        // Only trust X-Forwarded-For when the direct connection comes from a configured trusted proxy.
        // Configure via: add_filter('aicom_trusted_proxies', fn() => ['10.0.0.1', '10.0.0.2']);
        $trusted_proxies = (array) apply_filters( 'aicom_trusted_proxies', [] );
        if ( ! empty( $trusted_proxies ) && in_array( $remote_addr, $trusted_proxies, true ) ) {
            $forwarded = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) : '';
            if ( $forwarded ) {
                return sanitize_text_field( trim( explode( ',', $forwarded )[0] ) );
            }
        }

        return $remote_addr;
    }

    private static function suggest_tools( string $bad_name ): array {
        $all   = array_keys( AICOM_Tool_Registry::get_all() );
        $found = [];
        foreach ( $all as $name ) {
            similar_text( $bad_name, $name, $pct );
            if ( $pct >= 55 ) {
                $found[ $name ] = $pct;
            }
        }
        arsort( $found );
        return array_slice( array_keys( $found ), 0, 3 );
    }

    private static function is_rate_limited( string $ip ): bool {
        return (int) get_transient( 'aicom_rl_' . $ip ) >= 5;
    }

    private static function record_auth_failure( string $ip ): void {
        $key   = 'aicom_rl_' . $ip;
        $count = (int) get_transient( $key );
        // Exponential backoff: 1 min → 2 → 4 → 8 ... capped at 24 h
        $ttl   = min( MINUTE_IN_SECONDS * ( 2 ** $count ), DAY_IN_SECONDS );
        set_transient( $key, $count + 1, $ttl );
    }
}
