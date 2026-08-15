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
// 2. Gate error: integer error.code + string preserved in error.data.code
// ═══════════════════════════════════════════════════════════════════════════

section( 'gate error — integer code, string preserved in data.code' );
$r = rpc( 'nonexistent.tool.xyz', [] );
ok( 'top-level error present', isset( $r['error'] ) && ! isset( $r['result'] ), json_encode( $r ) );
ok( 'error.code is an integer', is_int( $r['error']['code'] ?? null ), json_encode( $r ) );
ok( 'error.data.code is TOOL_NOT_FOUND', ( $r['error']['data']['code'] ?? '' ) === 'TOOL_NOT_FOUND', json_encode( $r ) );

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
// 6. HTTP response is always valid, parseable JSON (output-buffer guard)
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
