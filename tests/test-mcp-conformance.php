<?php
/**
 * AICOM MCP conformance test suite — content field, gate-vs-tool error split,
 * integer gate-error codes, idempotency, protocolVersion negotiation.
 *
 * Run: docker compose exec -T wp php /var/www/html/wp-content/plugins/aicom/tests/test-mcp-conformance.php
 */

define( 'DOING_AJAX', true );
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';

require_once '/var/www/html/wp-load.php';

wp_set_current_user( 1 );

// ── Infrastructure ─────────────────────────────────────────────────────────

$pass    = 0;
$fail    = 0;
$errors  = [];
$SECTION = '';

function section( string $name ): void {
    global $SECTION;
    $SECTION = $name;
    echo "\n== $name ==\n";
}

function ok( string $label, bool $cond, string $debug = '' ): void {
    global $pass, $fail, $errors, $SECTION;
    if ( $cond ) {
        echo "  [PASS] $label\n";
        $pass++;
    } else {
        $msg = "  [FAIL] $label" . ( $debug ? " — $debug" : '' );
        echo "$msg\n";
        $errors[] = "[$SECTION] $label" . ( $debug ? ": $debug" : '' );
        $fail++;
    }
}

/** Full JSON-RPC envelope (jsonrpc/id/result-or-error), in-process. */
function rpc( string $name, array $args, int $id = 1 ): array {
    return AICOM_Tool_Router::dispatch( json_encode( [
        'jsonrpc' => '2.0',
        'method'  => 'tools/call',
        'params'  => [ 'name' => $name, 'arguments' => $args ],
        'id'      => $id,
    ] ) );
}

// ── Setup: dedicated API key + session ───────────────────────────────────────

$key = AICOM_Auth::create_key( 'MCP Conformance Test', [
    'read.wp', 'write.wp.posts', 'delete.wp.posts',
] );
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key['plain_key'];
$key_id = $key['id'];

$open = rpc( 'session.open', [ 'name' => 'MCP conformance test session' ] );
ok( 'setup: session opened', isset( $open['result']['session_id'] ), json_encode( $open ) );

$cleanup_posts = [];

// ═══════════════════════════════════════════════════════════════════════════
// 1. content is mandatory on every successful tools/call
// ═══════════════════════════════════════════════════════════════════════════

section( 'content field on success' );
$r = rpc( 'wp.posts.create', [
    'post_type'   => 'post',
    'post_title'  => 'MCP Conformance Post',
    'post_status' => 'draft',
] );
ok( 'is a success envelope', isset( $r['result'] ) && ! isset( $r['error'] ), json_encode( $r ) );
ok( 'content is a non-empty array', isset( $r['result']['content'] ) && is_array( $r['result']['content'] ) && count( $r['result']['content'] ) > 0, json_encode( $r ) );
ok( 'content[0] has type+text', ( $r['result']['content'][0]['type'] ?? '' ) === 'text' && is_string( $r['result']['content'][0]['text'] ?? null ), json_encode( $r ) );
ok( 'flat field id still present (back-compat)', isset( $r['result']['id'] ) && $r['result']['id'] > 0, json_encode( $r ) );
$post_id = $r['result']['id'] ?? 0;
if ( $post_id ) $cleanup_posts[] = $post_id;

// ═══════════════════════════════════════════════════════════════════════════
// 1b. tools/list has TWO valid invocation paths with DIFFERENT required shapes:
//     method:"tools/list" -> ListToolsResult (no content field)
//     method:"tools/call", params.name:"tools/list" -> ordinary CallToolResult
//     (WITH content) — AICOM registers tools/list as a real callable tool, so
//     a client is entitled to invoke it either way and must get the shape that
//     matches how it actually asked.
// ═══════════════════════════════════════════════════════════════════════════

section( 'tools/list — shape depends on how it was invoked, not just the tool name' );
$via_call = rpc( 'tools/list', [] );
ok( 'tools/call name=tools/list: has content (real CallToolResult)', isset( $via_call['result']['content'][0]['text'] ), json_encode( $via_call ) );
ok( 'tools/call name=tools/list: still has tools array', isset( $via_call['result']['tools'] ) && is_array( $via_call['result']['tools'] ), json_encode( $via_call ) );

$via_method = AICOM_Tool_Router::dispatch( json_encode( [ 'jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 1 ] ) );
ok( 'method=tools/list: NO content (ListToolsResult, not CallToolResult)', ! isset( $via_method['result']['content'] ), json_encode( $via_method ) );
ok( 'method=tools/list: still has tools array', isset( $via_method['result']['tools'] ) && is_array( $via_method['result']['tools'] ), json_encode( $via_method ) );

// ═══════════════════════════════════════════════════════════════════════════
// 1c. tools/list is filtered to what the calling key can actually call
// ═══════════════════════════════════════════════════════════════════════════
// Regression test for a real report: a key without read.skills still saw
// skills.list/skills.match in tools/list, only to fail calling them — wasting
// both context and a round-trip. Tools requiring an ungranted scope must not
// appear at all.

section( 'tools/list — scoped to the calling key\'s actual permissions' );
$narrow_key = AICOM_Auth::create_key( 'Narrow Scope Test', [ 'read.wp' ] );
$narrow_endpoint_body = json_encode( [ 'jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 1 ] );
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $narrow_key['plain_key'];
$narrow_result = AICOM_Tool_Router::dispatch( $narrow_endpoint_body );
$narrow_names  = array_column( $narrow_result['result']['tools'] ?? [], 'name' );

ok( 'read.wp-only key does NOT see skills.list (needs read.skills)', ! in_array( 'skills.list', $narrow_names, true ), json_encode( array_slice( $narrow_names, 0, 20 ) ) );
ok( 'read.wp-only key does NOT see wp.posts.create (needs write.wp.posts)', ! in_array( 'wp.posts.create', $narrow_names, true ) );
ok( 'read.wp-only key DOES see wp.posts.list (needs only read.wp)', in_array( 'wp.posts.list', $narrow_names, true ) );

$wpdb->delete( "{$wpdb->prefix}aicom_api_keys", [ 'id' => $narrow_key['id'] ] );
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key['plain_key']; // restore the main test key

// ═══════════════════════════════════════════════════════════════════════════
// 2. Gate error: integer error.code + string preserved in error.data.code
// ═══════════════════════════════════════════════════════════════════════════

section( 'gate error — integer code, string preserved in data.code' );
$r = rpc( 'nonexistent.tool.xyz', [] );
ok( 'top-level error present', isset( $r['error'] ) && ! isset( $r['result'] ), json_encode( $r ) );
ok( 'error.code is an integer', is_int( $r['error']['code'] ?? null ), json_encode( $r ) );
ok( 'error.data.code is TOOL_NOT_FOUND', ( $r['error']['data']['code'] ?? '' ) === 'TOOL_NOT_FOUND', json_encode( $r ) );
ok( 'error.data.retryable is false for TOOL_NOT_FOUND (needs a different tool name, not a retry)', ( $r['error']['data']['retryable'] ?? null ) === false, json_encode( $r ) );

// ═══════════════════════════════════════════════════════════════════════════
// 3. Tool-execution error: NOT a top-level error, isError + content instead
// ═══════════════════════════════════════════════════════════════════════════

section( 'tool-execution error — isError + content, no top-level error' );
$r = rpc( 'wp.posts.get', [ 'id' => 999999999 ] );
ok( 'no top-level error key', ! isset( $r['error'] ), json_encode( $r ) );
ok( 'result present', isset( $r['result'] ), json_encode( $r ) );
ok( 'result.isError is true', ( $r['result']['isError'] ?? false ) === true, json_encode( $r ) );
ok( 'result.content present', isset( $r['result']['content'][0]['text'] ), json_encode( $r ) );
ok( 'legacy result.error.code preserved (string)', is_string( $r['result']['error']['code'] ?? null ), json_encode( $r ) );

// ═══════════════════════════════════════════════════════════════════════════
// 4. Idempotency
// ═══════════════════════════════════════════════════════════════════════════

section( 'idempotency — replay returns cached result, no duplicate' );
$idem_key = 'conformance-' . uniqid();
$before   = (int) wp_count_posts()->draft;

$r1 = rpc( 'wp.posts.create', [
    'post_type'       => 'post',
    'post_title'      => 'Idempotency Conformance Post',
    'post_status'     => 'draft',
    'idempotency_key' => $idem_key,
] );
$r2 = rpc( 'wp.posts.create', [
    'post_type'       => 'post',
    'post_title'      => 'Idempotency Conformance Post',
    'post_status'     => 'draft',
    'idempotency_key' => $idem_key,
] );
$after = (int) wp_count_posts()->draft;

ok( 'first call succeeded', isset( $r1['result']['id'] ), json_encode( $r1 ) );
$idem_post_id = $r1['result']['id'] ?? 0;
if ( $idem_post_id ) $cleanup_posts[] = $idem_post_id;

ok( 'replay returns identical id', ( $r2['result']['id'] ?? null ) === ( $r1['result']['id'] ?? null ), json_encode( [ $r1, $r2 ] ) );
ok( 'no duplicate post created', $after === $before + 1, "before=$before after=$after" );

section( 'idempotency — conflicting args with the same key' );
$r3 = rpc( 'wp.posts.create', [
    'post_type'       => 'post',
    'post_title'      => 'DIFFERENT TITLE',
    'post_status'     => 'draft',
    'idempotency_key' => $idem_key,
] );
ok( 'conflict is a gate error', isset( $r3['error'] ), json_encode( $r3 ) );
ok( 'error.data.code is IDEMPOTENCY_CONFLICT', ( $r3['error']['data']['code'] ?? '' ) === 'IDEMPOTENCY_CONFLICT', json_encode( $r3 ) );

section( 'idempotency — stale in_progress row is reclaimable' );
global $wpdb;
$stale_key = 'conformance-stale-' . uniqid();
$stale_args = [ 'idempotency_key' => $stale_key, 'post_title' => 'Stale reclaim test' ];
$wpdb->insert( $wpdb->prefix . 'aicom_idempotency_keys', [
    'api_key_id'      => $key_id,
    'idempotency_key' => $stale_key,
    'tool_name'       => 'wp.posts.create',
    'args_hash'       => AICOM_Idempotency::hash_args( $stale_args ),
    'status'          => 'in_progress',
    'created_at'      => gmdate( 'Y-m-d H:i:s', time() - AICOM_Idempotency::STALENESS_SECONDS - 30 ),
] );
$claim = AICOM_Idempotency::claim( $key_id, $stale_key, 'wp.posts.create', $stale_args );
ok( 'stale in_progress row is reclaimed as claimed', $claim['status'] === 'claimed', json_encode( $claim ) );
$wpdb->delete( $wpdb->prefix . 'aicom_idempotency_keys', [ 'api_key_id' => $key_id, 'idempotency_key' => $stale_key ] );

// ═══════════════════════════════════════════════════════════════════════════
// 5. protocolVersion negotiation (real HTTP — header-driven, stateless)
// ═══════════════════════════════════════════════════════════════════════════

section( 'protocolVersion negotiation over HTTP' );
// home_url() carries the site's public port (e.g. :8080, the host-side docker-compose
// mapping) which isn't reachable from inside this container's own network namespace —
// the container serves on plain port 80 internally, same as every curl check in this
// suite's development session. Strip any port so the in-container request resolves.
$endpoint = preg_replace( '#(https?://[^/:]+):\d+#', '$1', home_url( '/wp-json/aicom/v1/mcp' ) );

$init = wp_remote_post( $endpoint, [
    'headers' => [ 'Content-Type' => 'application/json' ],
    'body'    => json_encode( [ 'jsonrpc' => '2.0', 'method' => 'initialize', 'params' => [ 'protocolVersion' => '2024-11-05' ], 'id' => 1 ] ),
    'timeout' => 15,
] );
$init_body = ! is_wp_error( $init ) ? json_decode( wp_remote_retrieve_body( $init ), true ) : null;
ok( 'initialize echoes requested 2024-11-05', ( $init_body['result']['protocolVersion'] ?? '' ) === '2024-11-05', json_encode( $init_body ) );

$old = wp_remote_post( $endpoint, [
    'headers' => [
        'Content-Type'         => 'application/json',
        'Authorization'        => 'Bearer ' . $key['plain_key'],
        'MCP-Protocol-Version' => '2024-11-05',
    ],
    'body'    => json_encode( [ 'jsonrpc' => '2.0', 'method' => 'tools/call', 'params' => [ 'name' => 'wp.posts.get', 'arguments' => [ 'id' => $post_id ] ], 'id' => 1 ] ),
    'timeout' => 15,
] );
$old_body = ! is_wp_error( $old ) ? json_decode( wp_remote_retrieve_body( $old ), true ) : null;
ok( 'HTTP request succeeded (2024-11-05)', ! is_wp_error( $old ), is_wp_error( $old ) ? $old->get_error_message() : '' );
ok( '2024-11-05 has content', isset( $old_body['result']['content'] ), json_encode( $old_body ) );
ok( '2024-11-05 has NO structuredContent', ! isset( $old_body['result']['structuredContent'] ), json_encode( $old_body ) );

$new = wp_remote_post( $endpoint, [
    'headers' => [
        'Content-Type'         => 'application/json',
        'Authorization'        => 'Bearer ' . $key['plain_key'],
        'MCP-Protocol-Version' => '2025-06-18',
    ],
    'body'    => json_encode( [ 'jsonrpc' => '2.0', 'method' => 'tools/call', 'params' => [ 'name' => 'wp.posts.get', 'arguments' => [ 'id' => $post_id ] ], 'id' => 1 ] ),
    'timeout' => 15,
] );
$new_body = ! is_wp_error( $new ) ? json_decode( wp_remote_retrieve_body( $new ), true ) : null;
ok( 'HTTP request succeeded (2025-06-18)', ! is_wp_error( $new ), is_wp_error( $new ) ? $new->get_error_message() : '' );
ok( '2025-06-18 HAS structuredContent', isset( $new_body['result']['structuredContent'] ), json_encode( $new_body ) );
ok( 'structuredContent mirrors flat id field', ( $new_body['result']['structuredContent']['id'] ?? null ) === ( $new_body['result']['id'] ?? null ), json_encode( $new_body ) );

// ═══════════════════════════════════════════════════════════════════════════
// 6. tools/list strictly matches the MCP Tool schema per negotiated version
// ═══════════════════════════════════════════════════════════════════════════
// Regression test for a real incident: AICOM used to add a non-standard
// top-level 'class' field to every tool object. A strict MCP client (Pydantic
// model with extra='forbid') rejects the ENTIRE ListToolsResult the moment a
// single tool has an unexpected field — every tool call is broken, not just
// content-shape issues. This asserts every tool's key set is an exact subset
// of what the negotiated protocol version's Tool schema allows, and reports
// every offending tool/field (not just a count) if it isn't.

section( 'tools/list — strict per-tool schema, per negotiated protocol version' );

function assert_tools_list_schema( string $protocol_version, bool $annotations_expected ): void {
    global $endpoint, $key;
    $allowed_top_level = [ 'name', 'description', 'inputSchema' ];
    if ( $annotations_expected ) {
        $allowed_top_level[] = 'annotations';
    }

    $res = wp_remote_post( $endpoint, [
        'headers' => [
            'Content-Type'         => 'application/json',
            'Authorization'        => 'Bearer ' . $key['plain_key'],
            'MCP-Protocol-Version' => $protocol_version,
        ],
        'body'    => json_encode( [ 'jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 1 ] ),
        'timeout' => 15,
    ] );
    $raw_body = ! is_wp_error( $res ) ? wp_remote_retrieve_body( $res ) : '';
    $body     = $raw_body !== '' ? json_decode( $raw_body, true ) : null;
    $tools    = $body['result']['tools'] ?? null;

    ok( "[$protocol_version] tools/list request succeeded", is_array( $tools ) && ! empty( $tools ), json_encode( $body ) );
    if ( ! is_array( $tools ) ) {
        return;
    }

    // Regression check for a real incident: PHP's json_encode can't tell an
    // empty associative array from an empty list, and defaults to "[]" for
    // either — a zero-parameter tool's inputSchema.properties must be "{}"
    // on the wire, not "[]", or a strict JSON-Schema client (Pydantic
    // dict_type validation) rejects the whole ListToolsResult. json_decode
    // with assoc=true collapses this same distinction back on the way in, so
    // this checks the raw response TEXT directly instead of the decoded array.
    ok(
        "[$protocol_version] no empty-list \"properties\":[] on the wire (must be {})",
        strpos( $raw_body, '"properties":[]' ) === false,
        strpos( $raw_body, '"properties":[]' ) !== false ? 'found literal "properties":[] in the raw response body' : ''
    );

    $violations = [];
    foreach ( $tools as $t ) {
        $extra = array_diff( array_keys( $t ), $allowed_top_level );
        if ( $extra ) {
            $violations[] = ( $t['name'] ?? '?' ) . ': unexpected field(s) ' . implode( ',', $extra );
        }
        if ( ! isset( $t['name'] ) || ! is_string( $t['name'] ) ) {
            $violations[] = ( $t['name'] ?? '?' ) . ': name missing or not a string';
        }
        if ( ! isset( $t['inputSchema'] ) || ( $t['inputSchema']['type'] ?? '' ) !== 'object' ) {
            $violations[] = ( $t['name'] ?? '?' ) . ': inputSchema missing or not type:object';
        }
        $has_annotations = array_key_exists( 'annotations', $t );
        if ( $has_annotations !== $annotations_expected ) {
            $violations[] = ( $t['name'] ?? '?' ) . ': annotations ' . ( $has_annotations ? 'present but not expected' : 'expected but missing' ) . " for $protocol_version";
        }
    }

    ok(
        "[$protocol_version] every tool matches the Tool schema exactly (" . count( $tools ) . ' tools checked)',
        empty( $violations ),
        empty( $violations ) ? '' : ( count( $violations ) . ' violation(s): ' . implode( ' | ', array_slice( $violations, 0, 15 ) ) )
    );
}

assert_tools_list_schema( '2024-11-05', false );
assert_tools_list_schema( '2025-06-18', true );

// ═══════════════════════════════════════════════════════════════════════════
// 7. HTTP response is always valid, parseable JSON (output-buffer guard)
// ═══════════════════════════════════════════════════════════════════════════

section( 'HTTP response body is always valid JSON' );
$raw = wp_remote_post( $endpoint, [
    'headers' => [ 'Content-Type' => 'application/json' ],
    'body'    => '{not valid json',
    'timeout' => 15,
] );
$raw_body    = ! is_wp_error( $raw ) ? wp_remote_retrieve_body( $raw ) : '';
$raw_decoded = json_decode( $raw_body, true );
ok( 'malformed-JSON request still gets a valid JSON body back', $raw_decoded !== null, $raw_body );

// ═══════════════════════════════════════════════════════════════════════════
// 8. pll.create_bilingual_pair — atomic composite tool, graceful degradation
// ═══════════════════════════════════════════════════════════════════════════

if ( function_exists( 'pll_languages_list' ) ) {
    section( 'pll.create_bilingual_pair' );

    $bp_source_id = wp_insert_post( [
        'post_title'  => 'Bilingual Pair Source',
        'post_type'   => 'post',
        'post_status' => 'publish',
    ], true );
    $cleanup_posts[] = $bp_source_id;

    $bp_full_key = AICOM_Auth::create_key( 'Bilingual Pair Full', [
        'read.wp', 'write.wp.posts', 'manage.polylang', 'manage.taxonomies', 'manage.media',
    ] );
    $bp_narrow_key = AICOM_Auth::create_key( 'Bilingual Pair Narrow', [
        'read.wp', 'write.wp.posts', 'manage.polylang',
    ] );

    if ( function_exists( 'pll_set_post_language' ) && ! is_wp_error( $bp_source_id ) ) {
        pll_set_post_language( $bp_source_id, 'en' );

        $bp_category = wp_insert_term( 'Bilingual Pair Test Category', 'category' );
        $bp_cat_id   = is_wp_error( $bp_category ) ? 0 : $bp_category['term_id'];

        // Full-scope key: every step should complete.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $bp_full_key['plain_key'];
        rpc( 'session.open', [ 'name' => 'bilingual pair full test' ] );
        $bp_full = rpc( 'pll.create_bilingual_pair', [
            'source_post_id'  => $bp_source_id,
            'target_language' => 'ro',
            'post_title'      => 'Bilingual Pair Target',
            'category_id'     => $bp_cat_id,
        ] );
        rpc( 'session.close', [] );

        ok( 'full-scope: succeeds', ( $bp_full['result']['success'] ?? false ) === true, json_encode( $bp_full ) );
        ok( 'full-scope: not partial', ( $bp_full['result']['partial'] ?? true ) === false, json_encode( $bp_full ) );
        ok( 'full-scope: draft, never published', ( $bp_full['result']['status'] ?? '' ) === 'draft', json_encode( $bp_full ) );
        ok( 'full-scope: source language auto-detected', ( $bp_full['result']['source_language'] ?? '' ) === 'en', json_encode( $bp_full ) );
        ok( 'full-scope: translation link verified both ways', ( $bp_full['result']['translations']['en'] ?? null ) === $bp_source_id, json_encode( $bp_full ) );
        ok( 'full-scope: all requested steps completed, none pending', $bp_full['result']['verified'] ?? false, json_encode( $bp_full ) );
        ok( 'full-scope: category step completed', in_array( 'assigned_category', $bp_full['result']['completed_steps'] ?? [], true ), json_encode( $bp_full ) );
        $bp_target_id = $bp_full['result']['post_id'] ?? 0;
        if ( $bp_target_id ) $cleanup_posts[] = $bp_target_id;

        // Narrow-scope key: core steps succeed, category step gracefully skipped (not failed).
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $bp_narrow_key['plain_key'];
        rpc( 'session.open', [ 'name' => 'bilingual pair narrow test' ] );
        $bp_narrow = rpc( 'pll.create_bilingual_pair', [
            'source_post_id'  => $bp_source_id,
            'target_language' => 'ro',
            'post_title'      => 'Bilingual Pair Target (narrow)',
            'category_id'     => $bp_cat_id,
        ] );
        rpc( 'session.close', [] );

        ok( 'narrow-scope: still succeeds overall (core steps unaffected)', ( $bp_narrow['result']['success'] ?? false ) === true, json_encode( $bp_narrow ) );
        ok( 'narrow-scope: reported as partial', ( $bp_narrow['result']['partial'] ?? false ) === true, json_encode( $bp_narrow ) );
        ok( 'narrow-scope: draft was still created and linked', in_array( 'linked_translation', $bp_narrow['result']['completed_steps'] ?? [], true ), json_encode( $bp_narrow ) );
        ok( 'narrow-scope: category step reported pending with INSUFFICIENT_SCOPE, not a hard failure', strpos( json_encode( $bp_narrow['result']['pending_steps'] ?? [] ), 'INSUFFICIENT_SCOPE' ) !== false, json_encode( $bp_narrow ) );
        $bp_narrow_target_id = $bp_narrow['result']['post_id'] ?? 0;
        if ( $bp_narrow_target_id ) $cleanup_posts[] = $bp_narrow_target_id;

        // From-scratch mode: no pre-existing source post — create both sides in one call,
        // and confirm the post_type override is honored instead of being flagged as unknown.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $bp_full_key['plain_key'];
        rpc( 'session.open', [ 'name' => 'bilingual pair from-scratch test' ] );
        $bp_scratch = rpc( 'pll.create_bilingual_pair', [
            'source_language'   => 'en',
            'source_post_title' => 'Bilingual Pair Scratch Source',
            'target_language'   => 'ro',
            'post_title'        => 'Bilingual Pair Scratch Target',
            'post_type'         => 'post',
        ] );
        rpc( 'session.close', [] );

        ok( 'from-scratch: succeeds without a pre-existing source', ( $bp_scratch['result']['success'] ?? false ) === true, json_encode( $bp_scratch ) );
        ok( 'from-scratch: mode reported correctly', ( $bp_scratch['result']['mode'] ?? '' ) === 'from_scratch', json_encode( $bp_scratch ) );
        ok( 'from-scratch: source post was created too', in_array( 'created_source_draft', $bp_scratch['result']['completed_steps'] ?? [], true ), json_encode( $bp_scratch ) );
        ok( 'from-scratch: post_type honored, not flagged unknown', ! isset( $bp_scratch['result']['_warnings'] ), json_encode( $bp_scratch ) );
        ok( 'from-scratch: both source and target are drafts', ( $bp_scratch['result']['status'] ?? '' ) === 'draft', json_encode( $bp_scratch ) );
        $bp_scratch_source_id = $bp_scratch['result']['source_post_id'] ?? 0;
        $bp_scratch_target_id = $bp_scratch['result']['post_id'] ?? 0;
        if ( $bp_scratch_source_id ) $cleanup_posts[] = $bp_scratch_source_id;
        if ( $bp_scratch_target_id ) $cleanup_posts[] = $bp_scratch_target_id;

        if ( $bp_cat_id ) {
            wp_delete_term( $bp_cat_id, 'category' );
        }
    }

    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key['plain_key']; // restore the main test key
    $wpdb->delete( "{$wpdb->prefix}aicom_sessions", [ 'api_key_id' => $bp_full_key['id'] ] );
    $wpdb->delete( "{$wpdb->prefix}aicom_sessions", [ 'api_key_id' => $bp_narrow_key['id'] ] );
    $wpdb->delete( "{$wpdb->prefix}aicom_api_keys", [ 'id' => $bp_full_key['id'] ] );
    $wpdb->delete( "{$wpdb->prefix}aicom_api_keys", [ 'id' => $bp_narrow_key['id'] ] );
} else {
    section( 'pll.create_bilingual_pair' );
    echo "  [SKIP] Polylang not active in this environment\n";
}

// ═══════════════════════════════════════════════════════════════════════════
// Regression: session.open immediately followed by a write call, no sleep
// (real report: a write sent as a separate HTTP request right after
// session.open could sometimes see NO_ACTIVE_SESSION before the session
// became visible). In-process dispatch() calls are already separate PHP
// executions of the router from a cold static-state standpoint (no shared
// in-memory session cache exists to begin with — see class-tool-router.php
// Step 3.5), so back-to-back calls here exercise the same
// AICOM_Sessions::get_active() read path a real second HTTP request would.
// ═══════════════════════════════════════════════════════════════════════════
section( 'session.open -> immediate write, no sleep' );

$race_key = AICOM_Auth::create_key( 'Session Race Test', [ 'read.wp', 'manage.skills' ] );
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $race_key['plain_key'];

$race_open = rpc( 'session.open', [ 'name' => 'race test', 'description' => 'no sleep before the next call' ] );
ok( 'session.open succeeded', isset( $race_open['result']['session_id'] ), json_encode( $race_open ) );

$race_write = rpc( 'skills.create', [
    'slug' => 'race_test_skill_' . time(),
    'name' => 'Race Test Skill',
    'dry_run' => true,
] );
ok(
    'write immediately after open (same request cycle, no sleep) does not get NO_ACTIVE_SESSION',
    empty( $race_write['result']['isError'] ) && ! isset( $race_write['error'] ),
    json_encode( $race_write )
);

rpc( 'session.close', [] );
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key['plain_key']; // restore main test key
$wpdb->delete( "{$wpdb->prefix}aicom_sessions", [ 'api_key_id' => $race_key['id'] ] );
$wpdb->delete( "{$wpdb->prefix}aicom_api_keys", [ 'id' => $race_key['id'] ] );

// ═══════════════════════════════════════════════════════════════════════════
// Regression: skills.run must deliver the full workflow via content[0].text
// alone — a strict content-only client (no MCP-Protocol-Version header, so
// structuredContent is never included; and a Pydantic-style model that
// drops any flat result fields it doesn't recognize) must still be able to
// parse steps/rules/input_schema/permissions/inputs out of content[0].text.
// ═══════════════════════════════════════════════════════════════════════════
section( 'skills.run — content-only client sees the full workflow' );

$run_key = AICOM_Auth::create_key( 'Skills Run Content Test', [ 'read.wp', 'manage.skills', 'read.skills' ] );
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $run_key['plain_key'];

rpc( 'session.open', [ 'name' => 'skills.run content test', 'description' => 'verify full workflow reaches content-only clients' ] );

$run_slug = 'content_only_run_skill_' . time();
$run_created = rpc( 'skills.create', [
    'slug'         => $run_slug,
    'name'         => 'Content-Only Run Skill',
    'steps'        => [ [ 'action' => 'noop' ] ],
    'rules'        => [ 'must_confirm' => true ],
    'input_schema' => [ 'type' => 'object', 'properties' => [ 'foo' => [ 'type' => 'string' ] ] ],
] );
$run_skill_id = $run_created['result']['id'] ?? null;
ok( 'setup: skill created', $run_skill_id !== null, json_encode( $run_created ) );

if ( $run_skill_id ) {
    rpc( 'skills.activate', [ 'id' => $run_skill_id ] );

    // No protocol_version_header passed -> dispatch() falls back to the
    // conservative default, exactly like a client that never sent
    // MCP-Protocol-Version — structuredContent is omitted (see
    // AICOM_Tool_Router::PROTOCOL_CAPABILITIES), so content is the only
    // place the workflow can legally arrive.
    $run = AICOM_Tool_Router::dispatch( json_encode( [
        'jsonrpc' => '2.0',
        'method'  => 'tools/call',
        'params'  => [ 'name' => 'skills.run', 'arguments' => [ 'id' => $run_skill_id, 'inputs' => [ 'foo' => 'bar' ] ] ],
        'id'      => 1,
    ] ) );

    ok( 'skills.run has no structuredContent for this protocol version', ! isset( $run['result']['structuredContent'] ), json_encode( $run ) );

    $content_text = $run['result']['content'][0]['text'] ?? '';
    $decoded      = json_decode( $content_text, true );

    ok( 'content[0].text is valid JSON', is_array( $decoded ), $content_text );
    ok( 'content-only client sees steps', $decoded && array_key_exists( 'steps', $decoded ) && $decoded['steps'] === [ [ 'action' => 'noop' ] ], $content_text );
    ok( 'content-only client sees rules', $decoded && array_key_exists( 'rules', $decoded ) && $decoded['rules'] === [ 'must_confirm' => true ], $content_text );
    ok( 'content-only client sees input_schema', $decoded && array_key_exists( 'input_schema', $decoded ), $content_text );
    ok( 'content-only client sees permissions', $decoded && array_key_exists( 'permissions', $decoded ), $content_text );
    ok( 'content-only client sees inputs', $decoded && array_key_exists( 'inputs', $decoded ) && $decoded['inputs'] === [ 'foo' => 'bar' ], $content_text );
}

rpc( 'session.close', [] );
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key['plain_key']; // restore main test key
$wpdb->delete( "{$wpdb->prefix}aicom_sessions", [ 'api_key_id' => $run_key['id'] ] );
$wpdb->delete( "{$wpdb->prefix}aicom_api_keys", [ 'id' => $run_key['id'] ] );

// ═══════════════════════════════════════════════════════════════════════════
// Cleanup
// ═══════════════════════════════════════════════════════════════════════════

rpc( 'session.close', [] );
foreach ( array_unique( $cleanup_posts ) as $pid ) {
    wp_delete_post( (int) $pid, true );
}
$wpdb->delete( "{$wpdb->prefix}aicom_sessions", [ 'api_key_id' => $key_id ] );
$wpdb->delete( "{$wpdb->prefix}aicom_idempotency_keys", [ 'api_key_id' => $key_id ] );
$wpdb->delete( "{$wpdb->prefix}aicom_api_keys", [ 'id' => $key_id ] );

// ═══════════════════════════════════════════════════════════════════════════
// Summary
// ═══════════════════════════════════════════════════════════════════════════

echo "\n" . str_repeat( '=', 50 ) . "\n";
echo "Results: $pass passed, $fail failed\n";
if ( $errors ) {
    echo "\nFailed checks:\n";
    foreach ( $errors as $e ) echo "  - $e\n";
}
echo str_repeat( '=', 50 ) . "\n";
