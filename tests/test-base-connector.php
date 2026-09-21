<?php
/**
 * AICOMBase connector tests: signing canonicalisation, token verification
 * (valid / expired / wrong aud / replayed nonce / scope escalation), event queue,
 * and in-process execution of a token-authorized task.
 *
 * Offline: never contacts AICOMBase (the fake connection is pinned to a closed port) and
 * snapshots/restores any real connection state, so it is safe to run on a paired site.
 *
 * Run: docker compose exec -T wp php /var/www/html/wp-content/plugins/aicom/tests/test-base-connector.php --allow-root
 */

define( 'DOING_AJAX', true );
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require_once '/var/www/html/wp-load.php';
wp_set_current_user( 1 );

global $wpdb;
$pass = 0;
$fail = 0;

function ok( string $label, bool $cond, string $debug = '' ): void {
    global $pass, $fail;
    if ( $cond ) {
        echo "  [PASS] $label\n";
        $pass++;
    } else {
        echo "  [FAIL] $label" . ( $debug ? " — $debug" : '' ) . "\n";
        $fail++;
    }
}
function section( string $n ): void { echo "\n== $n ==\n"; }

// ── Snapshot real state ───────────────────────────────────────────────────
$t_events = $wpdb->prefix . 'aicom_base_events';
$snap = [
    'state'    => get_option( AICOM_Base_State::OPT, null ),
    'identity' => get_option( AICOM_Base_Identity::OPT_IDENTITY, null ),
    'policy'   => get_option( AICOM_Base_Policy::OPT_ALLOWED, null ),
    'url'      => get_option( AICOM_Base_State::OPT_URL, null ),
    'events'   => $wpdb->get_results( "SELECT * FROM $t_events", ARRAY_A ),
];

function restore_state(): void {
    global $wpdb, $snap, $t_events;
    foreach ( [ AICOM_Base_State::OPT => 'state', AICOM_Base_Identity::OPT_IDENTITY => 'identity', AICOM_Base_Policy::OPT_ALLOWED => 'policy', AICOM_Base_State::OPT_URL => 'url' ] as $opt => $k ) {
        if ( $snap[ $k ] === null ) { delete_option( $opt ); } else { update_option( $opt, $snap[ $k ], false ); }
    }
    $wpdb->query( "DELETE FROM $t_events" );
    foreach ( $snap['events'] as $row ) { $wpdb->insert( $t_events, $row ); }
    delete_option( AICOM_Base_Heartbeat::LOCK_OPT );
}
register_shutdown_function( 'restore_state' );

// ── Test base key + fake connection ───────────────────────────────────────
$base_kp  = sodium_crypto_sign_keypair();
$base_sec = sodium_crypto_sign_secretkey( $base_kp );
$base_pub = AICOM_Base_Signer::b64u( sodium_crypto_sign_publickey( $base_kp ) );
$base_kid = AICOM_Base_Signer::base_key_id( $base_pub );
$site_id  = wp_generate_uuid4();

function issue_token( array $over = [] ): string {
    global $base_sec, $base_kid, $site_id;
    $now = time();
    $c   = array_merge( [
        'v' => 1, 'iss' => $base_kid, 'aud' => $site_id, 'task_id' => wp_generate_uuid4(), 'task_target_id' => wp_generate_uuid4(),
        'session_id' => wp_generate_uuid4(), 'allowed_scopes' => [ 'read.wp' ], 'iat' => $now, 'expires_at' => $now + 600,
        'nonce' => bin2hex( random_bytes( 12 ) ),
    ], $over );
    $payload = json_encode( $c );
    return AICOM_Base_Signer::b64u( $payload ) . '.' . AICOM_Base_Signer::b64u( sodium_crypto_sign_detached( $payload, $base_sec ) );
}
function fake_connect(): void {
    global $base_pub, $base_kid, $site_id, $wpdb, $t_events;
    delete_option( AICOM_Base_State::OPT );
    AICOM_Base_State::update( [
        'status' => 'connected', 'site_id' => $site_id, 'connection_id' => 'c', 'organization_id' => 'o',
        'base_public_key' => $base_pub, 'base_key_id' => $base_kid, 'base_url' => 'http://127.0.0.1:9', 'paused' => false, 'base_lock' => 'none',
    ] );
    $wpdb->query( "DELETE FROM $t_events" );
}
function queued(): array {
    global $wpdb, $t_events;
    return array_map( static fn( $r ) => json_decode( $r['payload'], true ), $wpdb->get_results( "SELECT payload FROM $t_events ORDER BY id", ARRAY_A ) );
}

// ═══ 1. Signing / canonicalisation ═══════════════════════════════════════════
section( 'Canonical string + Ed25519' );
$body  = '{"a":1}';
$canon = AICOM_Base_Signer::canonical( 'post', '/api/v1/site/heartbeat', 1790000000, 'nonce_nonce_nonce_1', $body );
ok( 'canonical layout METHOD\\nPATH\\nTS\\nNONCE\\nsha256hex(body), no trailing newline',
    $canon === "POST\n/api/v1/site/heartbeat\n1790000000\nnonce_nonce_nonce_1\n" . hash( 'sha256', $body ) );
ok( 'empty body hashes the empty string', substr( AICOM_Base_Signer::canonical( 'GET', '/x', 1, 'n', '' ), -64 ) === 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855' );
ok( 'query string is part of the canonical path', strpos( AICOM_Base_Signer::canonical( 'GET', '/x?y=1', 1, 'n', '' ), "\n/x?y=1\n" ) !== false );
$kp  = sodium_crypto_sign_keypair();
$pub = AICOM_Base_Signer::b64u( sodium_crypto_sign_publickey( $kp ) );
$sig = AICOM_Base_Signer::sign( $canon, sodium_crypto_sign_secretkey( $kp ) );
ok( 'signature verifies', AICOM_Base_Signer::verify( $canon, $sig, $pub ) );
ok( 'tampered message fails', ! AICOM_Base_Signer::verify( $canon . 'x', $sig, $pub ) );
ok( 'public key is raw 32 bytes / 43 chars b64url', strlen( $pub ) === 43 && strlen( AICOM_Base_Signer::b64u_decode( $pub ) ) === 32 );
ok( 'signature is 64 bytes / 86 chars b64url', strlen( $sig ) === 86 );
ok( 'key_id = sk_ + first16hex(sha256(raw pub))', AICOM_Base_Signer::site_key_id( $pub ) === 'sk_' . substr( hash( 'sha256', AICOM_Base_Signer::b64u_decode( $pub ) ), 0, 16 ) );
ok( 'base64url round-trips without padding', AICOM_Base_Signer::b64u_decode( AICOM_Base_Signer::b64u( "\xff\xfe\xfd\x00" ) ) === "\xff\xfe\xfd\x00" && strpos( AICOM_Base_Signer::b64u( "a" ), '=' ) === false );
ok( 'nonce matches [A-Za-z0-9_-]{16,64}', preg_match( '/^[A-Za-z0-9_-]{16,64}$/', AICOM_Base_Signer::nonce() ) === 1 );
ok( 'Identity: private key is not stored in clear', ( function () {
    $id = AICOM_Base_Identity::ensure();
    $raw = wp_json_encode( get_option( AICOM_Base_Identity::OPT_IDENTITY ) );
    return $raw !== false && strpos( $raw, 'secret_enc' ) !== false && strlen( (string) $id['public_key'] ) === 43;
} )() );

// ═══ 2. Token verification (pure) ════════════════════════════════════════════
section( 'Authorization token (PROTOCOL §9)' );
$v = static fn( string $t, ?int $now = null ) => AICOM_Base_Signer::verify_token( $t, $base_pub, $base_kid, $site_id, $now );
ok( 'valid token accepted', $v( issue_token() )['ok'] === true );
ok( 'expired token refused', ( $v( issue_token( [ 'iat' => time() - 1000, 'expires_at' => time() - 10 ] ) )['error'] ?? '' ) === 'expired' );
ok( 'wrong audience refused', ( $v( issue_token( [ 'aud' => wp_generate_uuid4() ] ) )['error'] ?? '' ) === 'wrong_audience' );
ok( 'wrong issuer refused', ( $v( issue_token( [ 'iss' => 'bk_0000000000000000' ] ) )['error'] ?? '' ) === 'wrong_issuer' );
ok( 'lifetime > 15 min refused', ( $v( issue_token( [ 'expires_at' => time() + 901 ] ) )['error'] ?? '' ) === 'lifetime_too_long' );
ok( 'exactly 15 min accepted', $v( issue_token( [ 'iat' => time(), 'expires_at' => time() + 900 ] ) )['ok'] === true );
$other = sodium_crypto_sign_keypair();
$forged_payload = json_encode( [ 'v' => 1, 'iss' => $base_kid, 'aud' => $site_id, 'task_id' => 't', 'task_target_id' => 'tt', 'session_id' => 's', 'allowed_scopes' => [ 'manage.plugins' ], 'iat' => time(), 'expires_at' => time() + 60, 'nonce' => 'abcdefghijkl' ] );
$forged = AICOM_Base_Signer::b64u( $forged_payload ) . '.' . AICOM_Base_Signer::b64u( sodium_crypto_sign_detached( $forged_payload, sodium_crypto_sign_secretkey( $other ) ) );
ok( 'token signed by a different key refused', ( $v( $forged )['error'] ?? '' ) === 'bad_signature' );
$parts = explode( '.', issue_token() );
$tampered = AICOM_Base_Signer::b64u( str_replace( 'read.wp', 'manage.plugins', (string) AICOM_Base_Signer::b64u_decode( $parts[0] ) ) ) . '.' . $parts[1];
ok( 'tampered payload refused', ( $v( $tampered )['error'] ?? '' ) === 'bad_signature' );
ok( 'garbage refused', ( $v( 'not-a-token' )['error'] ?? '' ) === 'malformed_token' && ( $v( '' )['error'] ?? '' ) === 'malformed_token' );
ok( 'unsupported version refused', ( $v( issue_token( [ 'v' => 2 ] ) )['error'] ?? '' ) === 'unsupported_version' );

section( 'Nonce single-use' );
$n = bin2hex( random_bytes( 12 ) );
ok( 'first use accepted', AICOM_Base_Executor::consume_nonce( $n, time() + 600 ) === true );
ok( 'replay refused', AICOM_Base_Executor::consume_nonce( $n, time() + 600 ) === false );
ok( 'different nonce accepted', AICOM_Base_Executor::consume_nonce( bin2hex( random_bytes( 12 ) ), time() + 600 ) === true );

// ═══ 3. Local policy cap ═════════════════════════════════════════════════════
section( 'Local policy (scope escalation)' );
delete_option( AICOM_Base_Policy::OPT_ALLOWED );
ok( 'default allows non-critical scopes', AICOM_Base_Policy::check_scopes( [ 'read.wp', 'write.wp.posts' ] )['ok'] );
$esc = AICOM_Base_Policy::check_scopes( [ 'read.wp', 'manage.plugins' ] );
ok( 'critical scope (manage.plugins) is refused by default', ! $esc['ok'] && $esc['excess'] === [ 'manage.plugins' ] );
$unk = AICOM_Base_Policy::check_scopes( [ 'read.wp', 'nuke.everything' ] );
ok( 'unknown scope refused outright', ! $unk['ok'] && $unk['unknown'] === [ 'nuke.everything' ] );
AICOM_Base_Policy::set_allowed_scopes( [ 'read.wp' ] );
ok( 'admin-narrowed policy applies', ! AICOM_Base_Policy::check_scopes( [ 'write.wp.posts' ] )['ok'] && AICOM_Base_Policy::check_scopes( [ 'read.wp' ] )['ok'] );
delete_option( AICOM_Base_Policy::OPT_ALLOWED );

// ═══ 4. Event queue ══════════════════════════════════════════════════════════
section( 'Event queue' );
delete_option( AICOM_Base_State::OPT );
$wpdb->query( "DELETE FROM $t_events" );
AICOM_Base_Events::enqueue( 'lock', [ 'state' => 'soft', 'by' => 'local' ] );
ok( 'nothing is queued while not connected', count( queued() ) === 0 );
fake_connect();
AICOM_Base_Events::enqueue( 'lock', [ 'state' => 'soft', 'by' => 'local' ] );
AICOM_Base_Events::security( 'plugin_theme_change', 'info', [] );
AICOM_Base_Events::enqueue( 'action', [ 'session_id' => 's', 'summary' => 'x', 'duration_ms' => null ] );
$q = queued();
ok( 'events buffered locally (3)', count( $q ) === 3 );
ok( 'each event has type + ISO at', ! array_filter( $q, static fn( $e ) => empty( $e['type'] ) || ! preg_match( '/^\d{4}-\d\d-\d\dT[\d:]+Z$/', $e['at'] ?? '' ) ) );
ok( 'null fields are omitted, empty data is an object', ! array_key_exists( 'duration_ms', $q[2] ) && strpos( (string) $wpdb->get_var( "SELECT payload FROM $t_events WHERE event_type='security'" ), '"data":{}' ) !== false );
$scrubbed = AICOM_Base_Client::scrub( [ 'password' => 'hunter2hunter2', 'note' => 'Bearer abcdefghijklmnopqrstuvwxyz0123', 'key' => 'aicom_deadbeef_' . str_repeat( 'a', 40 ), 'ok' => 'fine', 'nested' => [ 'token' => 'zzzzzzzz' ] ] );
ok( 'scrub redacts passwords, bearer tokens, AICOM keys, nested tokens', $scrubbed['password'] === '[redacted]' && $scrubbed['note'] === '[redacted]' && $scrubbed['key'] === '[redacted]' && $scrubbed['nested']['token'] === '[redacted]' && $scrubbed['ok'] === 'fine' );
$roundtrip = AICOM_Base_Client::scrub( json_decode( (string) $wpdb->get_var( "SELECT payload FROM $t_events WHERE event_type='security'" ) ) );
ok( 'flush path keeps `data:{}` an object after decode + scrub', strpos( (string) wp_json_encode( $roundtrip ), '"data":{}' ) !== false );
$r = AICOM_Base_Events::flush( 1 );
ok( 'failed flush keeps events and counts an attempt', $r['failed'] === true && AICOM_Base_Events::pending_count() === 3 && (int) $wpdb->get_var( "SELECT MIN(attempts) FROM $t_events" ) === 1 );
for ( $i = 0; $i < 205; $i++ ) { AICOM_Base_Events::enqueue( 'lock', [ 'state' => 'none', 'by' => 'local' ] ); }
ok( 'queue accepts >200 events (batched on send)', AICOM_Base_Events::pending_count() === 208 );
AICOM_Base_Events::purge();

// ═══ 5. Token-authorized execution, in-process ═══════════════════════════════
section( 'Execute (token → ephemeral key → fresh session → tool)' );
fake_connect();
$tt    = wp_generate_uuid4();
$sess  = wp_generate_uuid4();
$token = issue_token( [ 'task_target_id' => $tt, 'session_id' => $sess, 'allowed_scopes' => [ 'read.wp' ] ] );
$cmd   = [ 'id' => wp_generate_uuid4(), 'type' => 'execute', 'authorization' => $token, 'task_target_id' => $tt, 'tool_id' => 'wp.site.info', 'params' => [] ];
$keys_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aicom_api_keys WHERE status='active'" );
ok( 'execute() handles the command', AICOM_Base_Executor::execute( $cmd ) === true );
$ev    = queued();
$types = array_column( $ev, 'type' );
ok( 'session.started, action, session.ended queued in order', array_values( array_intersect( $types, [ 'session.started', 'action', 'session.ended' ] ) ) === [ 'session.started', 'action', 'session.ended' ], implode( ',', $types ) );
$started = current( array_filter( $ev, static fn( $e ) => $e['type'] === 'session.started' ) );
ok( 'session.started carries the AICOMBase session id + task target', ( $started['session_id'] ?? '' ) === $sess && ( $started['task_target_id'] ?? '' ) === $tt );
$action = current( array_filter( $ev, static fn( $e ) => $e['type'] === 'action' ) );
ok( 'action: tool wp.site.info, success, capability content', ( $action['tool_id'] ?? '' ) === 'wp.site.info' && ( $action['result'] ?? '' ) === 'success' && ( $action['capability_id'] ?? '' ) === 'content' );
ok( 'no ephemeral API key left active', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aicom_api_keys WHERE status='active'" ) === $keys_before );
ok( 'the session was closed', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aicom_sessions WHERE status='open' AND name LIKE 'AICOMBase task:%'" ) === 0 );
ok( 'result kept for retry while AICOMBase is unreachable', isset( AICOM_Base_State::get( 'pending_results', [] )[ $tt ] ) && AICOM_Base_State::get( 'pending_results' )[ $tt ]['status'] === 'completed' );
$blob = (string) wp_json_encode( [ $ev, get_option( AICOM_Base_State::OPT ) ] );
ok( 'no bearer key / secret material in queued events or state', ! preg_match( '/aicom_[A-Za-z0-9]{8}_[a-f0-9]{40}/', $blob ) && strpos( $blob, 'secret_enc' ) === false );

// Replay of the same authorization
AICOM_Base_Events::purge();
AICOM_Base_Executor::execute( $cmd );
$ev = queued();
ok( 'replayed token is refused (credential_anomaly security event, no session)', in_array( 'credential_anomaly', array_column( array_filter( $ev, static fn( $e ) => $e['type'] === 'security' ), 'kind' ), true ) && ! in_array( 'session.started', array_column( $ev, 'type' ), true ) );

// Scope escalation
AICOM_Base_Events::purge();
$tt2 = wp_generate_uuid4();
AICOM_Base_Executor::execute( [ 'id' => wp_generate_uuid4(), 'type' => 'execute', 'task_target_id' => $tt2, 'tool_id' => 'wp.plugins.list', 'params' => [],
    'authorization' => issue_token( [ 'task_target_id' => $tt2, 'allowed_scopes' => [ 'manage.plugins' ] ] ) ] );
$sec = array_values( array_filter( queued(), static fn( $e ) => $e['type'] === 'security' && $e['kind'] === 'scope_rejected' ) );
ok( 'scope beyond local policy → refused + security scope_rejected', count( $sec ) === 1 && ( $sec[0]['data']['excess'] ?? [] ) === [ 'manage.plugins' ] );
ok( 'refusal reported with status refused', ( AICOM_Base_State::get( 'pending_results', [] )[ $tt2 ]['status'] ?? '' ) === 'refused' && strpos( AICOM_Base_State::get( 'pending_results' )[ $tt2 ]['error'], 'scope_rejected' ) !== false );

// Tool needing more than the token grants
AICOM_Base_Events::purge();
$tt3 = wp_generate_uuid4();
AICOM_Base_Executor::execute( [ 'id' => wp_generate_uuid4(), 'type' => 'execute', 'task_target_id' => $tt3, 'tool_id' => 'wp.posts.create', 'params' => [ 'post_title' => 'x' ],
    'authorization' => issue_token( [ 'task_target_id' => $tt3, 'allowed_scopes' => [ 'read.wp' ] ] ) ] );
ok( 'tool scope not covered by token → refused, nothing ran', strpos( (string) ( AICOM_Base_State::get( 'pending_results', [] )[ $tt3 ]['error'] ?? '' ), 'scope_insufficient' ) !== false && ! in_array( 'session.started', array_column( queued(), 'type' ), true ) );

// Wrong task target in token
$tt4 = wp_generate_uuid4();
AICOM_Base_Executor::execute( [ 'id' => wp_generate_uuid4(), 'type' => 'execute', 'task_target_id' => $tt4, 'tool_id' => 'wp.site.info', 'params' => [], 'authorization' => issue_token( [ 'allowed_scopes' => [ 'read.wp' ] ] ) ] );
ok( 'token bound to a different task target refused', strpos( (string) ( AICOM_Base_State::get( 'pending_results', [] )[ $tt4 ]['error'] ?? '' ), 'target_mismatch' ) !== false );

// Pause / hard lock / soft lock
AICOM_Base_State::update( [ 'paused' => true ] );
$tt5 = wp_generate_uuid4();
AICOM_Base_Executor::execute( [ 'id' => wp_generate_uuid4(), 'type' => 'execute', 'task_target_id' => $tt5, 'tool_id' => 'wp.site.info', 'params' => [], 'authorization' => issue_token( [ 'task_target_id' => $tt5 ] ) ] );
ok( 'paused site refuses remote work', strpos( (string) ( AICOM_Base_State::get( 'pending_results', [] )[ $tt5 ]['error'] ?? '' ), 'paused' ) !== false );
AICOM_Base_State::update( [ 'paused' => false, 'base_lock' => 'hard' ] );
$tt6 = wp_generate_uuid4();
AICOM_Base_Executor::execute( [ 'id' => wp_generate_uuid4(), 'type' => 'execute', 'task_target_id' => $tt6, 'tool_id' => 'wp.site.info', 'params' => [], 'authorization' => issue_token( [ 'task_target_id' => $tt6 ] ) ] );
ok( 'hard lock refuses remote work', strpos( (string) ( AICOM_Base_State::get( 'pending_results', [] )[ $tt6 ]['error'] ?? '' ), 'hard_locked' ) !== false );
AICOM_Base_State::update( [ 'base_lock' => 'soft' ] );
$tt7 = wp_generate_uuid4();
AICOM_Base_Executor::execute( [ 'id' => wp_generate_uuid4(), 'type' => 'execute', 'task_target_id' => $tt7, 'tool_id' => 'wp.posts.create', 'params' => [ 'post_title' => 'x' ],
    'authorization' => issue_token( [ 'task_target_id' => $tt7, 'allowed_scopes' => [ 'write.wp.posts' ] ] ) ] );
ok( 'soft lock refuses a write tool', strpos( (string) ( AICOM_Base_State::get( 'pending_results', [] )[ $tt7 ]['error'] ?? '' ), 'soft_locked' ) !== false );
AICOM_Base_State::update( [ 'base_lock' => 'none' ] );

// ═══ 6. Capabilities / inspection ════════════════════════════════════════════
section( 'Capability inventory + inspection' );
$caps = AICOM_Base_Capabilities::build();
$tools = array_merge( ...array_column( $caps, 'tools' ) );
ok( 'inventory has capabilities and tools', count( $caps ) >= 8 && count( $tools ) >= 80 );
ok( 'every tool has id, risk in low|med|high, scopes array, schema object', ! array_filter( $tools, static fn( $t ) => empty( $t['id'] ) || ! in_array( $t['risk'], [ 'low', 'med', 'high' ], true ) || ! is_array( $t['required_scopes'] ) || empty( $t['input_schema'] ) ) );
$posts_create = current( array_filter( $tools, static fn( $t ) => $t['id'] === 'wp.posts.create' ) );
ok( 'wp.posts.create: write.wp.posts scope, med risk', $posts_create && $posts_create['required_scopes'] === [ 'write.wp.posts' ] && $posts_create['risk'] === 'med' );
ok( 'hash is stable across builds', AICOM_Base_Capabilities::hash_of( $caps ) === AICOM_Base_Capabilities::hash_of( AICOM_Base_Capabilities::build() ) );
foreach ( [ 'privacy', 'security', 'technical', 'accessibility' ] as $kind ) {
    $e = AICOM_Base_Inspector::collect( $kind );
    ok( "inspect $kind returns evidence", is_array( $e ) && $e );
}
$priv = AICOM_Base_Inspector::collect( 'privacy' );
ok( 'privacy evidence has the PROTOCOL §7 keys', ! array_diff( [ 'active_plugins', 'analytics', 'forms', 'newsletter', 'woocommerce', 'registration_enabled', 'comments_enabled', 'consent_plugin', 'wp' ], array_keys( $priv ) ) );
ok( 'unknown inspection kind → null', AICOM_Base_Inspector::collect( 'nope' ) === null );
$privjson = (string) wp_json_encode( $priv );
ok( 'privacy evidence contains no emails / passwords', ! preg_match( '/[\w.+-]+@[\w-]+\.[\w.]+/', $privjson ) && stripos( $privjson, 'password' ) === false );

// ═══ Summary ═════════════════════════════════════════════════════════════════
restore_state();
echo "\n" . ( $fail ? "FAILED" : "OK" ) . ": $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
