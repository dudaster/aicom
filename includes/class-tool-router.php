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

    // ── MCP protocol version negotiation ────────────────────────────────────
    // Data-driven (not a string/semver comparison) — MCP version strings aren't
    // guaranteed to sort, and structuredContent's exact introduction revision
    // isn't worth hardcoding assumptions about. Add new versions here only.
    private const PROTOCOL_CAPABILITIES = [
        '2024-11-05' => [ 'structured_content' => false, 'tool_annotations' => false ],
        '2025-06-18' => [ 'structured_content' => true,  'tool_annotations' => true ],
    ];
    private const DEFAULT_PROTOCOL_VERSION = '2024-11-05';
    private const LATEST_PROTOCOL_VERSION  = '2025-06-18';

    /**
     * JSON-RPC 2.0 integer codes for gate errors — failures before a tool handler
     * ever runs (auth, lock, scope, session, tool-not-found, confirm, idempotency
     * conflicts). AICOM-specific codes use JSON-RPC's reserved -32000..-32099
     * server-error range. Append-only: once shipped, never renumber or reuse a
     * code for a different string — it's a wire contract. The original string
     * identifier always travels alongside it in error.data.code, so nothing
     * loses its semantic identity for existing string-code consumers.
     */
    private const GATE_ERROR_CODES = [
        'PARSE_ERROR'             => -32700,
        'INVALID_REQUEST'         => -32600,
        'TOOL_NOT_FOUND'          => -32602, // "unknown tool" ~ invalid params.name, per MCP spec's own example
        'HANDLER_MISSING'         => -32603, // true internal/infra fault, not a client mistake
        'AUTH_FAILED'             => -32001,
        'DENIED_SCOPE'            => -32002,
        'NO_ACTIVE_SESSION'       => -32003,
        'CONFIRM_REQUIRED'        => -32004,
        'HARD_LOCK_ACTIVE'        => -32005,
        'SOFT_LOCK_ACTIVE'        => -32006,
        'RATE_LIMITED'            => -32007,
        'DEPENDENCY_MISSING'      => -32008,
        'BLOCKED_WORKING_HOURS'   => -32009,
        'IDEMPOTENCY_CONFLICT'    => -32010,
        'IDEMPOTENCY_IN_PROGRESS' => -32011,
    ];
    private const DEFAULT_GATE_ERROR_CODE = -32000;

    /**
     * Tools that mutate an existing post/term get a silent snapshot into
     * wp_aicom_backups before execution — independent of whether the key
     * has manage.backups or whether the model remembered to call backup.*.create.
     */
    private const AUTO_BACKUP_MAP = [
        'wp.posts.update' => [ 'type' => 'post', 'id_arg' => 'id' ],
        'wp.posts.trash'  => [ 'type' => 'post', 'id_arg' => 'id' ],
        'wp.posts.delete' => [ 'type' => 'post', 'id_arg' => 'id' ],
        'wp.terms.update' => [ 'type' => 'term', 'id_arg' => 'term_id', 'taxonomy_arg' => 'taxonomy' ],
        'wp.terms.delete' => [ 'type' => 'term', 'id_arg' => 'term_id', 'taxonomy_arg' => 'taxonomy' ],

        'elementor.widget.update_field'    => [ 'type' => 'elementor', 'id_arg' => 'post_id' ],
        'elementor.page.bulk_update_texts' => [ 'type' => 'elementor', 'id_arg' => 'post_id' ],

        // Writes _elementor_conditions/_elementor_template_type postmeta on an existing
        // elementor_library post — a full post+meta snapshot already covers it.
        'elementor.template.set_conditions' => [ 'type' => 'post', 'id_arg' => 'post_id' ],
    ];

    // ── Main Dispatch ─────────────────────────────────────────────────────

    public static function dispatch( string $raw_body, string $protocol_version_header = '' ): array {
        $remote_ip = self::remote_ip();
        $start     = microtime( true );
        $payload   = json_decode( $raw_body, true, 64 );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            // Try to repair common model mistakes before giving up
            $repaired = self::repair_json( $raw_body );
            $payload  = json_decode( $repaired, true, 64 );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                return self::early_error( 'parse_error', 'Invalid JSON payload', 400, wp_generate_uuid4(), $remote_ip, '', 'unknown', $start, 0, '', null );
            }
        }

        // ── JSON-RPC 2.0 batch: top-level JSON array (empty or indexed) ──
        if ( is_array( $payload ) && ( empty( $payload ) || array_key_exists( 0, $payload ) ) ) {
            if ( empty( $payload ) ) {
                return self::build_gate_error( 'INVALID_REQUEST', 'Empty batch array', wp_generate_uuid4(), null );
            }
            $responses = [];
            foreach ( $payload as $item ) {
                if ( ! is_array( $item ) ) {
                    $responses[] = self::build_gate_error( 'INVALID_REQUEST', 'Batch item must be an object', wp_generate_uuid4(), null );
                    continue;
                }
                $rpc_id = $item['id'] ?? null;
                $result = self::run( $item, $remote_ip, $protocol_version_header );
                if ( $rpc_id !== null ) {
                    $responses[] = $result; // notifications (no id) are processed but not returned
                }
            }
            return $responses;
        }

        // ── Single request ────────────────────────────────────────────────
        return self::run( is_array( $payload ) ? $payload : [], $remote_ip, $protocol_version_header );
    }

    /**
     * Process a single pre-parsed JSON-RPC/shorthand payload through the full 12-step pipeline.
     */
    private static function run( array $payload, string $remote_ip, string $protocol_version_header = '' ): array {
        $start      = microtime( true );
        $request_id = wp_generate_uuid4();
        $rpc_id     = null;

        // ── Step 1: Parse ─────────────────────────────────────────────────
        // Extract JSON-RPC id (present when client uses MCP standard format)
        $rpc_id           = $payload['id'] ?? null;
        $rpc_method       = trim( (string) ( $payload['method'] ?? '' ) );
        $protocol_version = self::resolve_protocol_version( $payload, $protocol_version_header );

        if ( $rpc_method === 'tools/call' && isset( $payload['params']['name'] ) ) {
            // MCP standard: {"jsonrpc":"2.0","method":"tools/call","params":{"name":"...","arguments":{}},"id":1}
            $tool_name = trim( (string) $payload['params']['name'] );
            $arguments = (array) ( $payload['params']['arguments'] ?? [] );
        } elseif ( $rpc_method === 'tools/call' && ! isset( $payload['params']['name'] ) ) {
            // tools/call without name — tell model exactly what is missing
            return self::early_error(
                'parse_error',
                'tools/call requires params.name. Example: {"jsonrpc":"2.0","method":"tools/call","params":{"name":"wp.posts.list","arguments":{}},"id":1}',
                400, $request_id, $remote_ip, '', 'unknown', $start, 0, '', $rpc_id
            );
        } elseif ( $rpc_method === 'tools/list' ) {
            // MCP standard list request
            $tool_name = 'tools/list';
            $arguments = [];
        } else {
            // Shorthand: {"method":"tool.name","params":{}} or {"tool":"tool.name","arguments":{}}
            $tool_name = trim( (string) ( $payload['tool'] ?? $rpc_method ) );
            $arguments = (array) ( $payload['arguments'] ?? $payload['params'] ?? [] );

            // Detect specific structural mistakes and return educational errors
            if ( $tool_name === '' ) {
                // No method and no tool field — but params.name present → model used wrong structure
                if ( isset( $payload['params']['name'] ) ) {
                    return self::early_error(
                        'parse_error',
                        'You sent params.name but forgot the method field. Add "method":"tools/call": {"jsonrpc":"2.0","method":"tools/call","params":{"name":"' . esc_html( $payload['params']['name'] ) . '","arguments":{}},"id":1}',
                        400, $request_id, $remote_ip, '', 'unknown', $start, 0, '', $rpc_id
                    );
                }
            }
        }

        // ── MCP handshake methods ─────────────────────────────────────────
        // Handled before lock/auth/session so strict MCP clients can complete
        // their initialize handshake. No tool is invoked, no state is mutated.
        if ( $rpc_method === 'initialize' ) {
            $requested_version  = (string) ( $payload['params']['protocolVersion'] ?? '' );
            $negotiated_version = isset( self::PROTOCOL_CAPABILITIES[ $requested_version ] )
                ? $requested_version
                : self::LATEST_PROTOCOL_VERSION; // spec fallback: respond with a version we support, client decides whether to proceed

            return self::jsonrpc_wrap( [
                'protocolVersion' => $negotiated_version,
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
                    '  Gate errors (request rejected before the tool ran) use a top-level "error" object — error.code is an integer, the original identifier is in error.data.code (e.g. error.data.code === "NO_ACTIVE_SESSION").',
                    '  Tool-execution errors (the tool ran and failed) are a normal result with result.isError === true and result.content describing the failure.',
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
            // Try to give a more specific hint based on what the model actually sent
            if ( $rpc_method === '' && ! isset( $payload['tool'] ) ) {
                $hint = 'Request has no "method" and no "tool" field. To call a tool: {"jsonrpc":"2.0","method":"tools/call","params":{"name":"TOOL_NAME","arguments":{}},"id":1}. To list tools: {"jsonrpc":"2.0","method":"tools/list","params":{},"id":1}';
            } else {
                $hint = 'Missing tool name. Correct format: {"jsonrpc":"2.0","method":"tools/call","params":{"name":"TOOL_NAME","arguments":{}},"id":1}';
            }
            return self::early_error( 'parse_error', $hint, 400, $request_id, $remote_ip, '', 'unknown', $start, 0, '', $rpc_id );
        }

        // Redirect: model called tools/call with name="tools" or "list_tools" instead of using the method.
        // "tools/list" is excluded — it goes through the normal pipeline to handle_tools_list which returns full schemas.
        // Returns compact list (name + class only) to avoid flooding small context windows.
        if ( in_array( $tool_name, [ 'tools', 'list_tools' ], true ) ) {
            // Pulled from the internal registry (not to_mcp_list()'s wire shape) —
            // this compact shorthand is an AICOM-specific extension, not a real
            // MCP tools/list response, so it can freely include AICOM's own
            // 'class' taxonomy without a spec-conformance concern.
            $full    = AICOM_Tool_Registry::get_for_modules( AICOM_Module_Detector::get_active_modules() );
            $compact = array_values( array_map( fn( $t ) => [ 'name' => $t['tool_name'], 'class' => $t['class'] ?? '' ], $full ) );
            return self::jsonrpc_wrap( [
                'tools'      => $compact,
                'total'      => count( $compact ),
                'request_id' => $request_id,
                '_note'      => 'Compact list (name+class only). Use {"method":"tools/list"} for full schemas with inputSchema, or call aicom.recipes for task guidance.',
            ], $rpc_id );
        }

        // Detect: model used "method":"tools/wp.posts.create" instead of tools/call + params.name
        if ( strpos( $rpc_method, 'tools/' ) === 0 && $rpc_method !== 'tools/list' && $rpc_method !== 'tools/call' ) {
            $guessed = substr( $rpc_method, strlen( 'tools/' ) );
            return self::early_error(
                'parse_error',
                'Use method:"tools/call" with params.name, not method:"' . $rpc_method . '". Correct: {"jsonrpc":"2.0","method":"tools/call","params":{"name":"' . esc_html( $guessed ) . '","arguments":{}},"id":1}',
                400, $request_id, $remote_ip, '', 'unknown', $start, 0, '', $rpc_id
            );
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

            return self::build_gate_error( $lock_code, ucwords( str_replace( '_', ' ', $lock_status ) ), $request_id, $rpc_id );
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
            // Detect truncated key: right prefix format but too short
            $expected_len = 55; // "aicom_" (6) + 8-char prefix + "_" (1) + 40-char hex
            $msg = 'Invalid or revoked API key';
            if ( strpos( $plain_key, 'aicom_' ) === 0 && strlen( $plain_key ) < $expected_len ) {
                $msg = sprintf(
                    'API key is incomplete (%d of %d chars). Make sure you copy and use the entire key — do not truncate or shorten it.',
                    strlen( $plain_key ),
                    $expected_len
                );
            }
            return self::early_error( 'auth_failed', $msg, 403, $request_id, $remote_ip, $tool_name, $tool_module, $start, 0, '', $rpc_id );
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

        // ── Step 9b: Unknown parameter detection ─────────────────────────
        // Resolve common aliases before checking unknown params.
        $aliases = [
            'status'    => 'post_status',
            'title'     => 'post_title',
            'content'   => 'post_content',
            'excerpt'   => 'post_excerpt',
            'post_id'   => 'id',           // e.g. wp.posts.update/media.* use 'id'
            'media_id'  => 'id',           // e.g. media.* tools use 'id', not 'media_id'
            'slug'      => 'post_name',    // wp.posts.* uses 'post_name'; wp.terms.* already has its own 'slug' field
        ];
        $schema_keys      = array_keys( $tool_meta['input_schema'] ?? [] );
        $applied_aliases  = [];
        foreach ( $aliases as $wrong => $correct ) {
            if ( isset( $arguments[ $wrong ] ) && ! isset( $arguments[ $correct ] ) ) {
                // Only apply alias if correct key is in schema and wrong key is not
                if ( in_array( $correct, $schema_keys, true ) && ! in_array( $wrong, $schema_keys, true ) ) {
                    $arguments[ $correct ] = $arguments[ $wrong ];
                    unset( $arguments[ $wrong ] );
                    $applied_aliases[] = "\"$wrong\" was used as \"$correct\"";
                }
            }
        }
        if ( ! empty( $applied_aliases ) ) {
            $arguments['_aliases_applied'] = $applied_aliases;
        }
        // Warn about remaining unknown params
        if ( ! empty( $schema_keys ) ) {
            $reserved    = [ 'dry_run', 'confirm', 'idempotency_key', '_aliases_applied' ];
            $unknown     = array_diff( array_keys( $arguments ), $schema_keys, $reserved );
            if ( ! empty( $unknown ) ) {
                $hints = [];
                foreach ( $unknown as $bad_key ) {
                    $best     = '';
                    $best_pct = 0;
                    foreach ( $schema_keys as $good_key ) {
                        similar_text( $bad_key, $good_key, $pct );
                        if ( $pct > $best_pct ) { $best_pct = $pct; $best = $good_key; }
                    }
                    $hints[] = $best_pct >= 50
                        ? "\"$bad_key\" is not a valid parameter — did you mean \"$best\"?"
                        : "\"$bad_key\" is not a valid parameter and was ignored";
                }
                // Inject warning into arguments so it surfaces in the result
                $arguments['_param_warnings'] = $hints;
            }
        }

        // ── Step 9c: Auto-backup ──────────────────────────────────────────
        // Best-effort snapshot before a post/term gets overwritten or removed.
        // Failures here must never block the actual tool call.
        if ( ! $is_dry_run ) {
            self::maybe_auto_backup( $tool_name, $arguments, $key_record );
        }

        // ── Step 9d: Idempotency claim ─────────────────────────────────────
        // Opt-in: only applies when the caller passes idempotency_key on a
        // write/destructive/admin_sensitive tool. Dedupes retried write calls
        // (dropped connection, flaky client) so they can't duplicate side effects.
        $idempotency_key       = (string) ( $arguments['idempotency_key'] ?? '' );
        $idempotent_applicable = $idempotency_key !== ''
            && ! $is_dry_run
            && in_array( $tool_class, [ 'write', 'destructive', 'admin_sensitive' ], true );

        if ( $idempotent_applicable ) {
            $claim = AICOM_Idempotency::claim( $key_id, $idempotency_key, $tool_name, $arguments );

            if ( $claim['status'] === 'conflict' ) {
                return self::keyed_error(
                    $request_id, $remote_ip, $key_id, $key_label, $tool_name, $tool_module,
                    'IDEMPOTENCY_CONFLICT',
                    'This idempotency_key was already used for a different tool or different arguments. Use a new idempotency_key for a different operation.',
                    'validation_failed', 409, $arguments, $start, $rpc_id
                );
            }

            if ( $claim['status'] === 'in_progress' ) {
                return self::keyed_error(
                    $request_id, $remote_ip, $key_id, $key_label, $tool_name, $tool_module,
                    'IDEMPOTENCY_IN_PROGRESS',
                    'Another request with this idempotency_key is still executing. Wait for it to finish before retrying.',
                    'validation_failed', 409, $arguments, $start, $rpc_id
                );
            }

            if ( $claim['status'] === 'replay' ) {
                $cached = $claim['result'];

                AICOM_Audit_Logger::log( [
                    'request_id'    => $request_id,
                    'session_id'    => self::$current_session_id ?: null,
                    'remote_ip'     => $remote_ip,
                    'api_key_id'    => $key_id,
                    'api_key_label' => $key_label,
                    'tool_name'     => $tool_name,
                    'module'        => $tool_module,
                    'tool_class'    => $tool_class,
                    'status'        => 'idempotent_replay',
                    'http_status'   => 200,
                    'duration_ms'   => self::elapsed( $start ),
                    'params_json'   => self::safe_json( $arguments ),
                ] );

                if ( ! empty( $cached['is_error'] ) ) {
                    return self::build_tool_error( $cached['payload'] ?? [], $request_id, $rpc_id );
                }
                $payload = (array) ( $cached['payload'] ?? [] );
                $payload['request_id'] = $request_id;
                return self::build_success_result( $payload, (array) ( $cached['meta'] ?? [] ), $protocol_version, $rpc_id );
            }
            // $claim['status'] === 'claimed' → fall through to Step 10 as normal.
        }

        // ── Step 10: Execute ──────────────────────────────────────────────
        $handler = $tool_meta['handler'];

        if ( ! is_callable( $handler ) ) {
            return self::build_gate_error( 'HANDLER_MISSING', 'Tool handler not callable', $request_id, $rpc_id );
        }

        // Long-running admin_sensitive tools (e.g. wp.plugins.update_all) may call
        // out to wordpress.org — bump the execution time limit so PHP doesn't hard-kill
        // mid-response with no output. Infra-level timeouts (FPM/nginx/Apache) are out
        // of reach here and remain a deployment-config concern, not something this fixes.
        if ( $tool_class === 'admin_sensitive' ) {
            @set_time_limit( 120 );
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

        // A caught exception means the handler DID start running — per MCP spec this is
        // a tool-execution failure (isError:true + content), not a protocol-level error.
        // Normalize it into the same shape a handler's own err() return would produce so
        // it falls through into the unified Step 12 handling below.
        if ( $exec_exception !== null ) {
            $result = [
                'error'       => [ 'code' => 'EXECUTION_ERROR', 'message' => $exec_exception->getMessage(), 'status_code' => 'error' ],
                'http_status' => 500,
            ];
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

        // Tool-execution failure (handler ran and returned err(), or threw): per the MCP
        // spec this is reported inside a *successful* result (isError:true + content),
        // not as a top-level JSON-RPC error — the model needs to see and react to it.
        if ( $is_error ) {
            return self::build_tool_error( $result['error'], $request_id, $rpc_id, $arguments['_aliases_applied'] ?? [] );
        }

        // Strip internal keys before returning
        unset( $result['_meta'], $result['http_status'] );
        $result['request_id'] = $request_id;

        // Surface unknown parameter warnings in the result
        if ( isset( $arguments['_param_warnings'] ) ) {
            $result['_warnings'] = $arguments['_param_warnings'];
        }

        // Surface auto-corrected parameter aliases (e.g. "slug" used as "post_name")
        // so the caller can see exactly what the router silently rewrote.
        if ( isset( $arguments['_aliases_applied'] ) ) {
            $result['_aliases_applied'] = $arguments['_aliases_applied'];
        }

        // Cache for idempotent replay. Only successes are cached — a failed call
        // leaves the claim row as 'in_progress' so it becomes reclaimable after
        // the staleness window instead of permanently caching a possibly-transient
        // failure for up to 48h.
        if ( $idempotent_applicable ) {
            AICOM_Idempotency::complete( $key_id, $idempotency_key, [ 'is_error' => false, 'payload' => $result, 'meta' => $meta ] );
        }

        // tools/list is a distinct top-level RPC method, not a tools/call CallToolResult —
        // it must NOT get content/isError/structuredContent wrapping.
        if ( $tool_name === 'tools/list' ) {
            // Each tool's 'annotations' (ToolAnnotations) is only part of the MCP
            // Tool schema from the version that introduced it — strip it entirely
            // for older negotiated versions so nothing version-specific reaches a
            // client that doesn't expect the field at all.
            if ( ! self::protocol_supports( $protocol_version, 'tool_annotations' ) && isset( $result['tools'] ) && is_array( $result['tools'] ) ) {
                foreach ( $result['tools'] as &$tool_def ) {
                    unset( $tool_def['annotations'] );
                }
                unset( $tool_def );
            }
            return self::jsonrpc_wrap( $result, $rpc_id );
        }

        return self::build_success_result( $result, $meta, $protocol_version, $rpc_id );
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

        return self::build_gate_error( strtoupper( $status_code ), $message, $request_id, $rpc_id );
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

        return self::build_gate_error( $error_code, $message, $request_id, $rpc_id );
    }

    /**
     * Build a top-level JSON-RPC "error" response for a failure that happened
     * BEFORE any tool handler ran (lock, auth, scope, session, tool-not-found,
     * confirm, idempotency conflicts, ...). See build_tool_error() for failures
     * that happen at/after handler execution — those are a different MCP shape.
     *
     * JSON-RPC-enveloped requests (client sent "id"): error.code is the mapped
     * integer per GATE_ERROR_CODES; the original string identifier is preserved
     * in error.data.code so nothing loses its semantic identity.
     *
     * Legacy shorthand requests (no "id"): error.code stays the original string —
     * JSON-RPC integer-code conformance is moot without a jsonrpc/id envelope,
     * and this preserves whatever weak-local-model integrations already parse.
     */
    private static function build_gate_error( string $string_code, string $message, string $request_id, $rpc_id = null ): array {
        if ( $rpc_id === null ) {
            return [
                'error'      => [ 'code' => $string_code, 'message' => $message ],
                'request_id' => $request_id,
            ];
        }

        $int_code = self::GATE_ERROR_CODES[ $string_code ] ?? self::DEFAULT_GATE_ERROR_CODE;

        return [
            'jsonrpc' => '2.0',
            'id'      => $rpc_id,
            'error'   => [
                'code'    => $int_code,
                'message' => $message,
                'data'    => [ 'code' => $string_code, 'request_id' => $request_id ],
            ],
        ];
    }

    /**
     * Build a *successful* JSON-RPC result for a tool-execution failure — the
     * handler ran (or started to) and returned err(), or threw. Per the MCP
     * spec this is reported inside the result (isError:true + content), not as
     * a top-level JSON-RPC error, so the model can see and react to it.
     * The legacy flat error shape ($handler_error) is preserved inside the
     * result for back-compat with existing consumers of result.error.code.
     */
    private static function build_tool_error( array $handler_error, string $request_id, $rpc_id = null, array $aliases_applied = [] ): array {
        $message = $handler_error['message'] ?? 'Tool execution failed';
        $result  = [
            'isError'    => true,
            'content'    => [ [ 'type' => 'text', 'text' => $message ] ],
            'error'      => $handler_error,
            'request_id' => $request_id,
        ];
        if ( ! empty( $aliases_applied ) ) {
            $result['_aliases_applied'] = $aliases_applied;
        }
        return self::jsonrpc_wrap( $result, $rpc_id );
    }

    /**
     * Build a successful tools/call CallToolResult. Existing flat fields in
     * $result are kept verbatim for back-compat; content is always added;
     * structuredContent mirrors the flat fields only when the negotiated
     * protocol version supports it (absent, e.g., for 2024-11-05 clients).
     */
    private static function build_success_result( array $result, array $meta, string $protocol_version, $rpc_id = null ): array {
        $result['content'] = [ [ 'type' => 'text', 'text' => self::summarize_result( $result, $meta ) ] ];

        if ( self::protocol_supports( $protocol_version, 'structured_content' ) ) {
            $structured = $result;
            unset( $structured['content'] );
            $result['structuredContent'] = $structured;
        }

        return self::jsonrpc_wrap( $result, $rpc_id );
    }

    /**
     * Generic human-readable summary for the mandatory content field. Prefers
     * the handler's own _meta.summary (many already set one); otherwise falls
     * back to a compact JSON rendering of the visible result fields.
     */
    private static function summarize_result( array $result, array $meta ): string {
        if ( ! empty( $meta['summary'] ) && is_array( $meta['summary'] ) ) {
            $summary = $meta['summary'];
            $is_list = array_keys( $summary ) === range( 0, count( $summary ) - 1 );

            if ( $is_list ) {
                // e.g. a changed_fields list — join values directly, not "0: x, 1: y".
                $parts = array_map( 'strval', $summary );
            } else {
                $parts = [];
                foreach ( $summary as $k => $v ) {
                    $parts[] = is_bool( $v ) ? ( $v ? $k : "not $k" ) : "$k: $v";
                }
            }
            if ( $parts ) {
                return implode( ', ', $parts );
            }
        }

        $visible = $result;
        unset( $visible['request_id'], $visible['_warnings'] );
        if ( empty( $visible ) ) {
            return 'OK';
        }
        $encoded = wp_json_encode( $visible );
        return $encoded !== false ? $encoded : 'OK';
    }

    private static function protocol_supports( string $protocol_version, string $capability ): bool {
        return (bool) ( self::PROTOCOL_CAPABILITIES[ $protocol_version ][ $capability ] ?? false );
    }

    /**
     * Resolve the MCP protocol version to use when serializing THIS request's
     * response. HTTP is stateless here — no connection survives from
     * initialize() to a later tools/call — so the version is re-declared on
     * every request: an MCP-Protocol-Version header takes priority (matches
     * the Streamable HTTP transport convention of resending it after
     * initialize), falling back to an optional top-level "protocolVersion"
     * field in the JSON-RPC body for simple clients, falling back to the
     * conservative default if neither is present or recognized.
     */
    private static function resolve_protocol_version( array $payload, string $header_version ): string {
        if ( $header_version !== '' && isset( self::PROTOCOL_CAPABILITIES[ $header_version ] ) ) {
            return $header_version;
        }
        $body_version = (string) ( $payload['protocolVersion'] ?? '' );
        if ( $body_version !== '' && isset( self::PROTOCOL_CAPABILITIES[ $body_version ] ) ) {
            return $body_version;
        }
        return self::DEFAULT_PROTOCOL_VERSION;
    }

    /**
     * Wrap a non-tool-call response (handshake/list/meta, or an already-built
     * success-shaped tool result) in the JSON-RPC 2.0 envelope. Gate errors and
     * tool-execution errors build their own envelope directly (build_gate_error()/
     * build_tool_error()) since their shapes diverge from the plain success case.
     */
    private static function jsonrpc_wrap( array $response, $rpc_id ): array {
        if ( $rpc_id === null ) {
            return $response; // legacy / shorthand format — no wrapping
        }

        return [ 'jsonrpc' => '2.0', 'id' => $rpc_id, 'result' => $response ];
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

    /**
     * Best-effort JSON repair for payloads from weak local models.
     * Fixes: UTF-8 BOM, literal control chars inside strings, trailing commas.
     */
    private static function repair_json( string $raw ): string {
        // Strip UTF-8 BOM
        if ( substr( $raw, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $raw = substr( $raw, 3 );
        }

        // State-machine pass: escape literal control characters inside JSON strings
        $out         = '';
        $in_string   = false;
        $escape_next = false;
        $len         = strlen( $raw );

        for ( $i = 0; $i < $len; $i++ ) {
            $c   = $raw[ $i ];
            $ord = ord( $c );

            if ( $escape_next ) {
                $out        .= $c;
                $escape_next = false;
                continue;
            }

            if ( $c === '\\' && $in_string ) {
                $out        .= $c;
                $escape_next = true;
                continue;
            }

            if ( $c === '"' ) {
                $in_string = ! $in_string;
                $out      .= $c;
                continue;
            }

            if ( $in_string && $ord < 0x20 ) {
                switch ( $c ) {
                    case "\n": $out .= '\\n';  break;
                    case "\r": $out .= '\\r';  break;
                    case "\t": $out .= '\\t';  break;
                    default:   $out .= sprintf( '\\u%04x', $ord ); break;
                }
                continue;
            }

            $out .= $c;
        }

        // Remove trailing commas before ] or } (another common model mistake)
        $out = preg_replace( '/,(\s*[\]}])/', '$1', $out );

        return $out ?? $raw;
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

    /**
     * Snapshot the post/term this tool is about to mutate, using the same
     * backup.post.create / backup.term.create handlers a model would call
     * explicitly. Runs with the executing key's identity for attribution,
     * but does not require that key to hold manage.backups — this is a
     * router-level safety net, not a user-invoked tool call.
     */
    private static function maybe_auto_backup( string $tool_name, array $arguments, array $key_record ): void {
        if ( ! isset( self::AUTO_BACKUP_MAP[ $tool_name ] ) ) {
            return;
        }

        $spec = self::AUTO_BACKUP_MAP[ $tool_name ];
        $id   = (int) ( $arguments[ $spec['id_arg'] ] ?? 0 );

        if ( ! $id ) {
            return;
        }

        try {
            if ( $spec['type'] === 'elementor' ) {
                ( new AICOM_Module_Elementor() )->handle_backup( [ 'post_id' => $id ], $key_record, false );
                return;
            }

            $backup = new AICOM_Module_Backup();

            if ( $spec['type'] === 'post' ) {
                $backup->handle_post_create( [ 'post_id' => $id ], $key_record, false );
            } elseif ( $spec['type'] === 'term' ) {
                $backup->handle_term_create( [
                    'term_id'  => $id,
                    'taxonomy' => (string) ( $arguments[ $spec['taxonomy_arg'] ] ?? '' ),
                ], $key_record, false );
            }
        } catch ( Throwable $e ) {
            // Swallow — a failed safety snapshot must not block the real write.
        }
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
