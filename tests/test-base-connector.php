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
    'seen'     => get_option( AICOM_Base_Policy::OPT_SEEN, null ),
    'xmlrpc'   => get_option( AICOM_Xmlrpc::OPTION, null ),
    'url'      => get_option( AICOM_Base_State::OPT_URL, null ),
    'events'   => $wpdb->get_results( "SELECT * FROM $t_events", ARRAY_A ),
];

function restore_state(): void {
    global $wpdb, $snap, $t_events;
    foreach ( [ AICOM_Base_State::OPT => 'state', AICOM_Base_Identity::OPT_IDENTITY => 'identity', AICOM_Base_Policy::OPT_ALLOWED => 'policy', AICOM_Base_Policy::OPT_SEEN => 'seen', AICOM_Xmlrpc::OPTION => 'xmlrpc', AICOM_Base_State::OPT_URL => 'url' ] as $opt => $k ) {
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
ok( 'heartbeat reports the narrowed local cap', AICOM_Base_Heartbeat::body( [] )['local_allowed_scopes'] === [ 'read.wp' ] );
delete_option( AICOM_Base_Policy::OPT_ALLOWED );
$hb_scopes = AICOM_Base_Heartbeat::body( [] )['local_allowed_scopes'];
ok( 'heartbeat reports the default cap (no critical scopes)', $hb_scopes === AICOM_Base_Policy::default_scopes() && ! in_array( 'manage.plugins', $hb_scopes, true ) );

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
section( 'Execute — only inside a session AICOMBase opened' );
fake_connect();
$tt    = wp_generate_uuid4();
$sess  = wp_generate_uuid4();
$token = issue_token( [ 'task_target_id' => $tt, 'session_id' => $sess, 'allowed_scopes' => [ 'read.wp' ] ] );
$cmd   = [ 'id' => wp_generate_uuid4(), 'type' => 'execute', 'authorization' => $token, 'task_target_id' => $tt, 'tool_id' => 'wp.site.info', 'params' => [] ];
$keys_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aicom_api_keys WHERE status='active'" );

// No session opened by AICOMBase → nothing runs.
$tt0 = wp_generate_uuid4();
AICOM_Base_Executor::execute( [ 'id' => wp_generate_uuid4(), 'type' => 'execute', 'task_target_id' => $tt0, 'tool_id' => 'wp.site.info', 'params' => [],
    'authorization' => issue_token( [ 'task_target_id' => $tt0, 'session_id' => wp_generate_uuid4(), 'allowed_scopes' => [ 'read.wp' ] ] ) ] );
ok( 'without an open AICOMBase session the work is refused (NO_ACTIVE_SESSION), nothing ran',
    strpos( (string) ( AICOM_Base_State::get( 'pending_results', [] )[ $tt0 ]['error'] ?? '' ), 'NO_ACTIVE_SESSION' ) !== false && ! in_array( 'action', array_column( queued(), 'type' ), true ) );

// open_session command → an ordinary AICOM session, tagged as AICOMBase's, listed in WP admin.
AICOM_Base_Events::purge();
$local = AICOM_Sessions::open_remote( $sess, 'Bilingual news post', 'Add a bilingual RO/EN article to News.', 'Claude Desktop' );
ok( 'open_session creates an AICOM session tagged aicombase with the agent label', is_array( $local ) && $local['source'] === 'aicombase' && $local['base_session_id'] === $sess && strpos( $local['api_key_label'], 'Claude Desktop' ) !== false );
ok( 'opening it again returns the same session', (int) AICOM_Sessions::open_remote( $sess, 'x', 'y' )['id'] === (int) $local['id'] );
$opened = current( array_filter( queued(), static fn( $e ) => $e['type'] === 'session.started' ) );
ok( 'session.started reported with the AICOMBase session id', ( $opened['session_id'] ?? '' ) === $sess );

AICOM_Base_Events::purge();
ok( 'execute() handles the command', AICOM_Base_Executor::execute( $cmd ) === true );
$ev    = queued();
$types = array_column( $ev, 'type' );
$started = current( array_filter( $ev, static fn( $e ) => $e['type'] === 'session.started' ) );
ok( 'task linked to its target inside the session (session.started + task_target_id)', ( $started['session_id'] ?? '' ) === $sess && ( $started['task_target_id'] ?? '' ) === $tt );
$action = current( array_filter( $ev, static fn( $e ) => $e['type'] === 'action' ) );
ok( 'action: tool wp.site.info, success, capability content, in the session', ( $action['tool_id'] ?? '' ) === 'wp.site.info' && ( $action['result'] ?? '' ) === 'success' && ( $action['capability_id'] ?? '' ) === 'content' && ( $action['session_id'] ?? '' ) === $sess );
ok( 'the session stays open after the task (AICOMBase closes it)', ! in_array( 'session.ended', $types, true ) && AICOM_Sessions::find_open_remote( $sess ) !== null );
ok( 'no ephemeral API key left active', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aicom_api_keys WHERE status='active'" ) === $keys_before );
ok( 'result kept for retry while AICOMBase is unreachable', isset( AICOM_Base_State::get( 'pending_results', [] )[ $tt ] ) && AICOM_Base_State::get( 'pending_results' )[ $tt ]['status'] === 'completed' );
$blob = (string) wp_json_encode( [ $ev, get_option( AICOM_Base_State::OPT ) ] );
ok( 'no bearer key / secret material in queued events or state', ! preg_match( '/aicom_[A-Za-z0-9]{8}_[a-f0-9]{40}/', $blob ) && strpos( $blob, 'secret_enc' ) === false );

// A second task in the same session runs in the same AICOM session; its change is backed up there and restorable.
$post_id = wp_insert_post( [ 'post_title' => 'Original title', 'post_status' => 'draft', 'post_type' => 'post' ] );
$tt8 = wp_generate_uuid4();
AICOM_Base_Executor::execute( [ 'id' => wp_generate_uuid4(), 'type' => 'execute', 'task_target_id' => $tt8, 'tool_id' => 'wp.posts.update', 'params' => [ 'id' => $post_id, 'title' => 'Changed by the agent' ],
    'authorization' => issue_token( [ 'task_target_id' => $tt8, 'session_id' => $sess, 'allowed_scopes' => [ 'read.wp', 'write.wp.posts' ] ] ) ] );
ok( 'second task ran in the same session', ( AICOM_Base_State::get( 'pending_results', [] )[ $tt8 ]['status'] ?? '' ) === 'completed' && count( AICOM_Sessions::remote_ids( $sess ) ) === 1 );
ok( 'its change landed', get_the_title( $post_id ) === 'Changed by the agent' );
AICOM_Base_Events::purge();
AICOM_Base_Heartbeat::cmd_restore_session( $sess );
clean_post_cache( $post_id );
ok( 'restore_session puts the backed-up content back', get_the_title( $post_id ) === 'Original title' );
$restored = current( array_filter( queued(), static fn( $e ) => $e['type'] === 'session.restored' ) );
ok( 'session.restored reported to AICOMBase with the count', ( $restored['session_id'] ?? '' ) === $sess && (int) ( $restored['restored'] ?? 0 ) >= 1 );
ok( 'a restored session is closed — nothing more runs in it', AICOM_Sessions::find_open_remote( $sess ) === null );
wp_delete_post( $post_id, true );

// The one-click fix AICOMBase sends for finding SEC-EXP-003 ("Turn off XML-RPC"): the signed execute exactly as
// dispatchTarget queues it (params = the task's params: arguments + tool_id), then Undo (restore_session).
$xsess = wp_generate_uuid4();
AICOM_Sessions::open_remote( $xsess, 'Turn off XML-RPC', 'The owner pressed "Turn off XML-RPC" on finding SEC-EXP-003.' );
delete_option( AICOM_Xmlrpc::OPTION );
$xtt = wp_generate_uuid4();
AICOM_Base_Executor::execute( [ 'id' => wp_generate_uuid4(), 'type' => 'execute', 'task_target_id' => $xtt, 'tool_id' => 'wp.security.xmlrpc.set',
    'params' => [ 'arguments' => [ 'enabled' => false ], 'tool_id' => 'wp.security.xmlrpc.set' ],
    'authorization' => issue_token( [ 'task_target_id' => $xtt, 'session_id' => $xsess, 'allowed_scopes' => [ 'manage.security.hardening' ] ] ) ] );
$xres = AICOM_Base_State::get( 'pending_results', [] )[ $xtt ] ?? [];
ok( 'AICOMBase fix for SEC-EXP-003: XML-RPC off, result completed and verified', ( $xres['status'] ?? '' ) === 'completed' && ( $xres['result']['verified'] ?? null ) === true && AICOM_Xmlrpc::is_off(), wp_json_encode( $xres ) );
AICOM_Base_Heartbeat::cmd_restore_session( $xsess );
ok( 'Undo from AICOMBase (restore_session) turns it back on — the setting is gone again', get_option( AICOM_Xmlrpc::OPTION, null ) === null && ! AICOM_Xmlrpc::is_off() );

// A tool's own `content` field reaches AICOMBase (2026-11-05: wp.posts.get's text was stripped with the MCP
// envelope's `content`), and an oversized result is cut field by field, not replaced by a 2,000-character preview.
$csess = wp_generate_uuid4();
AICOM_Sessions::open_remote( $csess, 'Read a post', 'The owner asked what the post says.' );
$short = wp_insert_post( [ 'post_title' => 'AICOM content test', 'post_content' => 'echipamente laser vansatee', 'post_status' => 'draft' ] );
$long  = wp_insert_post( [ 'post_title' => 'AICOM long content test', 'post_content' => str_repeat( 'ăîșțâ lorem ipsum ', 20000 ), 'post_status' => 'draft' ] );
$read  = static function ( int $post_id ) use ( $csess ): array {
    $tt = wp_generate_uuid4();
    AICOM_Base_Executor::execute( [ 'id' => wp_generate_uuid4(), 'type' => 'execute', 'task_target_id' => $tt, 'tool_id' => 'wp.posts.get',
        'params' => [ 'arguments' => [ 'id' => $post_id ], 'tool_id' => 'wp.posts.get' ],
        'authorization' => issue_token( [ 'task_target_id' => $tt, 'session_id' => $csess, 'allowed_scopes' => [ 'read.wp' ] ] ) ] );
    return AICOM_Base_State::get( 'pending_results', [] )[ $tt ] ?? [];
};
$rs = $read( $short );
ok( 'wp.posts.get result keeps the post text (content) for AICOMBase', strpos( (string) ( $rs['result']['content'] ?? '' ), 'echipamente laser vansatee' ) !== false && ( $rs['result']['id'] ?? 0 ) === $short, wp_json_encode( $rs['result'] ?? $rs ) );
$rl = $read( $long );
ok( 'oversized result: content cut and listed in _truncated, the rest whole', ( $rl['result']['id'] ?? 0 ) === $long && ( $rl['result']['title'] ?? '' ) === 'AICOM long content test'
    && mb_strlen( (string) ( $rl['result']['content'] ?? '' ) ) === AICOM_Base_Executor::FIELD_MAX_CHARS && isset( $rl['result']['_truncated']['content'] ), wp_json_encode( array_diff_key( $rl['result'] ?? $rl, [ 'content' => 1 ] ) ) );
wp_delete_post( $short, true );
wp_delete_post( $long, true );
AICOM_Sessions::close_remote( $csess, 'completed' );

// close_session command / admin "Close" → session.ended to AICOMBase.
$sess2 = wp_generate_uuid4();
$l2    = AICOM_Sessions::open_remote( $sess2, 'To close', 'closing test' );
AICOM_Base_Events::purge();
AICOM_Sessions::close_remote( $sess2, 'completed' );
$ended = current( array_filter( queued(), static fn( $e ) => $e['type'] === 'session.ended' ) );
ok( 'close_session closes it and reports session.ended', ( $ended['session_id'] ?? '' ) === $sess2 && AICOM_Sessions::find_open_remote( $sess2 ) === null );
$sess3 = wp_generate_uuid4();
$l3    = AICOM_Sessions::open_remote( $sess3, 'Admin closes', 'admin closing test' );
AICOM_Base_Events::purge();
AICOM_Sessions::close_by_id( (int) $l3['id'] );
$ended3 = current( array_filter( queued(), static fn( $e ) => $e['type'] === 'session.ended' ) );
ok( 'closing it in WP admin reports session.ended (cancelled) to AICOMBase', ( $ended3['session_id'] ?? '' ) === $sess3 && ( $ended3['status'] ?? '' ) === 'cancelled' );
// Re-open the first session for the token tests below (replay etc.).
AICOM_Sessions::open_remote( $sess, 'Bilingual news post', 'Add a bilingual RO/EN article to News.' );

// Replay of the same authorization
AICOM_Base_Events::purge();
AICOM_Base_Executor::execute( $cmd );
$ev = queued();
ok( 'replayed token is refused (credential_anomaly security event, no session)', in_array( 'credential_anomaly', array_column( array_filter( $ev, static fn( $e ) => $e['type'] === 'security' ), 'kind' ), true ) && ! in_array( 'session.started', array_column( $ev, 'type' ), true ) );

// Duplicate DELIVERY of one command (live 2026-10-01: several wake pings → simultaneous check-ins → the same execute
// command processed more than once → false credential_anomaly). The second delivery must be skipped quietly.
AICOM_Base_Events::purge();
$ttd  = wp_generate_uuid4();
$dcmd = [ 'id' => wp_generate_uuid4(), 'type' => 'execute', 'task_target_id' => $ttd, 'tool_id' => 'wp.site.info', 'params' => [], 'expires_at' => time() + 600,
    'authorization' => issue_token( [ 'task_target_id' => $ttd, 'session_id' => $sess, 'allowed_scopes' => [ 'read.wp' ] ] ) ];
$pc = new ReflectionMethod( 'AICOM_Base_Heartbeat', 'process_commands' );
$pc->setAccessible( true );
$pc->invoke( null, [ $dcmd ], microtime( true ) + 30 );
$first = queued();
AICOM_Base_State::update( [ 'done_commands' => array_values( array_diff( (array) AICOM_Base_State::get( 'done_commands', [] ), [ $dcmd['id'] ] ) ) ] ); // as if the 2nd check-in raced the 1st
AICOM_Base_Events::purge();
$pc->invoke( null, [ $dcmd ], microtime( true ) + 30 );
$second = queued();
ok( 'a command delivered twice runs once', in_array( 'session.started', array_column( $first, 'type' ), true ) && ! in_array( 'session.started', array_column( $second, 'type' ), true ), json_encode( array_column( $second, 'type' ) ) );
ok( 'duplicate delivery raises no credential_anomaly', ! in_array( 'credential_anomaly', array_column( array_filter( $second, static fn( $e ) => $e['type'] === 'security' ), 'kind' ), true ) );
ok( 'duplicate delivery keeps the real result (not reported as interrupted)', ( AICOM_Base_State::get( 'pending_results', [] )[ $ttd ]['status'] ?? '' ) === 'completed' );

// Claimed but never finished (live 2026-11-04, www.anconav.ro: LiteSpeed ended PHP when the wake caller hung up,
// leaving an execute claimed and never run). Its redelivery must be reported, not acked silently — and not run.
AICOM_Base_Events::purge();
$tti  = wp_generate_uuid4();
$icmd = [ 'id' => wp_generate_uuid4(), 'type' => 'execute', 'task_target_id' => $tti, 'tool_id' => 'wp.site.info', 'params' => [], 'expires_at' => time() + 600,
    'authorization' => issue_token( [ 'task_target_id' => $tti, 'session_id' => $sess, 'allowed_scopes' => [ 'read.wp' ] ] ) ];
AICOM_Base_Executor::consume_nonce( 'cmd:' . substr( $icmd['id'], 0, 60 ), time() + 600 ); // the dead run's claim
$pc->invoke( null, [ $icmd ], microtime( true ) + 30 );
$ires = AICOM_Base_State::get( 'pending_results', [] )[ $tti ] ?? [];
ok( 'claimed-but-unfinished command reported as failed: INTERRUPTED', ( $ires['status'] ?? '' ) === 'failed' && strpos( (string) ( $ires['error'] ?? '' ), 'INTERRUPTED' ) === 0, wp_json_encode( $ires ) );
ok( 'interrupted command is not run again, and is marked done', ! in_array( 'session.started', array_column( queued(), 'type' ), true ) && AICOM_Base_State::command_done( $icmd['id'] ) );
$lk = new ReflectionMethod( 'AICOM_Base_Heartbeat', 'acquire' );
$lk->setAccessible( true );
$rl = new ReflectionMethod( 'AICOM_Base_Heartbeat', 'release' );
$rl->setAccessible( true );
$rl->invoke( null );
ok( 'heartbeat lock: first acquire wins, second loses', $lk->invoke( null ) === true && $lk->invoke( null ) === false );
$rl->invoke( null );

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
// A tool becoming undoable changes the hash AICOMBase is sent, so the inventory is uploaded again without a version bump
// (2026-11-26: tools newly proven undoable stayed non-reversible in AICOMBase on staging).
$probe = [ 'module' => 'test', 'class' => 'write', 'required_scopes' => [], 'description' => 'Probe', 'input_schema' => [], 'handler' => '__return_empty_array' ];
AICOM_Tool_Registry::register( 'test.caps.probe', $probe + [ 'reversible' => false ] );
$h1 = AICOM_Base_Capabilities::current_hash();
AICOM_Tool_Registry::register( 'test.caps.probe', $probe + [ 'reversible' => true ] );
ok( 'the cached hash changes when a tool becomes reversible', AICOM_Base_Capabilities::current_hash() !== $h1 );
foreach ( [ 'privacy', 'security', 'technical', 'accessibility' ] as $kind ) {
    $e = AICOM_Base_Inspector::collect( $kind );
    ok( "inspect $kind returns evidence", is_array( $e ) && $e );
}
$priv = AICOM_Base_Inspector::collect( 'privacy' );
ok( 'privacy evidence has the PROTOCOL §7 keys', ! array_diff( [ 'active_plugins', 'analytics', 'forms', 'newsletter', 'woocommerce', 'registration_enabled', 'comments_enabled', 'consent_plugin', 'wp' ], array_keys( $priv ) ) );
ok( 'unknown inspection kind → null', AICOM_Base_Inspector::collect( 'nope' ) === null );
$privjson = (string) wp_json_encode( $priv );
ok( 'privacy evidence contains no emails / passwords', ! preg_match( '/[\w.+-]+@[\w-]+\.[\w.]+/', $privjson ) && stripos( $privjson, 'password' ) === false );

// ═══ 7. Wake ping (PROTOCOL §11) ═════════════════════════════════════════════
section( 'Wake ping' );
function issue_wake_token( array $over = [] ): string {
    global $base_sec, $base_kid, $site_id;
    $now = time();
    $c   = array_merge( [
        'v' => 1, 'iss' => $base_kid, 'aud' => $site_id, 'purpose' => 'wake', 'allowed_scopes' => [],
        'iat' => $now, 'expires_at' => $now + 60, 'nonce' => bin2hex( random_bytes( 12 ) ),
    ], $over );
    $payload = json_encode( $c );
    return AICOM_Base_Signer::b64u( $payload ) . '.' . AICOM_Base_Signer::b64u( sodium_crypto_sign_detached( $payload, $base_sec ) );
}
function wake_request( string $token ): array {
    $req = new WP_REST_Request( 'POST', '/aicom/v1/wake' );
    $req->set_body( wp_json_encode( [ 'token' => $token ] ) );
    $res = AICOM_Base_Wake::handle( $req );
    return [ 'status' => $res->get_status(), 'data' => $res->get_data() ];
}

fake_connect();
$r = wake_request( 'not-a-token' );
ok( 'garbage token refused (401)', $r['status'] === 401 && ( $r['data']['error'] ?? '' ) === 'malformed_token' );

$r = wake_request( issue_wake_token( [ 'expires_at' => time() - 10, 'iat' => time() - 100 ] ) );
ok( 'expired wake token refused', $r['status'] === 401 && ( $r['data']['error'] ?? '' ) === 'expired' );

$r = wake_request( issue_wake_token( [ 'aud' => wp_generate_uuid4() ] ) );
ok( 'wrong audience refused', $r['status'] === 401 && ( $r['data']['error'] ?? '' ) === 'wrong_audience' );

// A perfectly valid EXECUTE-style token (no purpose:"wake" claim) must not be usable to wake — the two
// token kinds share a verifier but not a use.
$r = wake_request( issue_token() );
ok( 'execute-authorization token (no purpose:wake) refused for /wake', $r['status'] === 401 && ( $r['data']['error'] ?? '' ) === 'wrong_purpose' );

$valid = issue_wake_token();
$r1    = wake_request( $valid );
ok( 'valid wake token accepted at once (200, accepted)', $r1['status'] === 200 && ! empty( $r1['data']['accepted'] ) );
ok( 'the check-in runs after the response is sent (shutdown)', has_action( 'shutdown', [ 'AICOM_Base_Wake', 'run_after_response' ] ) !== false );
remove_action( 'shutdown', [ 'AICOM_Base_Wake', 'run_after_response' ], 100 ); // don't tick for real when this script ends
ok( 'wake lingers while commands keep coming', AICOM_Base_Wake::keep_lingering( [ 'ok' => true, 'commands' => 1 ], 0 ) );
ok( 'wake retries when another check-in holds the lock', AICOM_Base_Wake::keep_lingering( [ 'ok' => false, 'skipped' => 'locked' ], 0 ) );
ok( 'an empty check-in ends the wake', ! AICOM_Base_Wake::keep_lingering( [ 'ok' => true, 'commands' => 0 ], 0 ) );
ok( 'not connected / backoff end the wake', ! AICOM_Base_Wake::keep_lingering( [ 'ok' => false, 'skipped' => 'not_connected' ], 0 ) && ! AICOM_Base_Wake::keep_lingering( [ 'ok' => false, 'skipped' => 'backoff' ], 0 ) );
ok( 'a lock held past the linger limit ends the wake', ! AICOM_Base_Wake::keep_lingering( [ 'ok' => false, 'skipped' => 'locked' ], AICOM_Base_Wake::LINGER_MAX_S ) );
$r2 = wake_request( $valid );
ok( 'replayed wake token refused', $r2['status'] === 401 && ( $r2['data']['error'] ?? '' ) === 'replayed' );

AICOM_Base_State::update( [ 'status' => 'pairing' ] );
$r = wake_request( issue_wake_token() );
ok( 'not connected → 409', $r['status'] === 409 && ( $r['data']['error'] ?? '' ) === 'not_connected' );
fake_connect();

// ═══ 8. Extension points (PROTOCOL §12): features, notices, site.notice ═══════
section( 'Features, notices, site.notice' );
fake_connect();
function notices_of( string $type ): array {
    return array_values( array_filter( queued(), static fn( $e ) => $e['type'] === $type ) );
}
function forget_notice_codes(): void {
    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_aicom\\_base\\_notice\\_%' OR option_name LIKE '\\_transient\\_timeout\\_aicom\\_base\\_notice\\_%'" );
    wp_cache_flush();
}

// features: what this AICOM says it does, and what it reads back.
$hb = AICOM_Base_Heartbeat::body( [] );
ok( 'heartbeat lists its features', ( $hb['features'] ?? null ) === AICOM_Base_Heartbeat::FEATURES
    && ! array_diff( [ 'undo.journal', 'dry_run.refuses_unsupported', 'session.restored.kept', 'lists.page_cap', 'notices', 'site.notice' ], $hb['features'] ) );
ok( '... each a valid name, at most 50', count( AICOM_Base_State::clean_features( $hb['features'] ) ) === count( $hb['features'] ) && count( $hb['features'] ) <= 50 );
ok( 'malformed feature names are dropped, the rest kept', AICOM_Base_State::clean_features( [ 'notices', 'Bad Name', str_repeat( 'a', 61 ), 7, 'notices', 'site.notice' ] ) === [ 'notices', 'site.notice' ] );
ok( 'features that are not a list mean none', AICOM_Base_State::clean_features( 'notices' ) === [] && AICOM_Base_State::clean_features( null ) === [] );
ok( 'a fresh connection supports nothing extra', ! AICOM_Base_State::base_supports( 'notices' ) && ! AICOM_Base_State::base_supports( 'site.notice' ) );

$hb_sent   = null;
$hb_answer = [ 'ok' => true, 'server_time' => time(), 'commands' => [], 'capabilities_needed' => false, 'features' => [ 'notices', 'site.notice', 'per_site_reversible', 'NOT OK' ] ];
$fake_base = static function ( $pre, $args, $url ) use ( &$hb_sent, &$hb_answer ) {
    if ( strpos( $url, '/api/v1/site/heartbeat' ) !== false ) {
        $hb_sent = json_decode( (string) $args['body'], true );
        return [ 'headers' => [], 'body' => wp_json_encode( $hb_answer ), 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
    }
    return [ 'headers' => [], 'body' => '{"ok":true}', 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
};
add_filter( 'pre_http_request', $fake_base, 10, 3 );
AICOM_Base_Identity::ensure();
$r = AICOM_Base_Heartbeat::run( 'manual' );
ok( 'the heartbeat sent its features', $r['ok'] === true && ( $hb_sent['features'] ?? null ) === AICOM_Base_Heartbeat::FEATURES, wp_json_encode( $r ) );
ok( "AICOMBase's features kept from its answer (malformed dropped)", AICOM_Base_State::get( 'base_features' ) === [ 'notices', 'site.notice', 'per_site_reversible' ] );
unset( $hb_answer['features'] );
AICOM_Base_Heartbeat::run( 'manual' );
ok( 'an answer without features means none again', AICOM_Base_State::get( 'base_features' ) === [] && ! AICOM_Base_State::base_supports( 'notices' ) );
remove_filter( 'pre_http_request', $fake_base, 10 );
fake_connect();

// notices on a result: wp.posts.update changing post_content of a page built with Elementor.
$el_page = wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'AICOM Elementor notice test', 'post_content' => 'old', 'post_status' => 'draft' ] );
update_post_meta( $el_page, '_elementor_edit_mode', 'builder' );
update_post_meta( $el_page, '_elementor_data', '[]' );
$plain_page = wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'AICOM plain page test', 'post_content' => 'old', 'post_status' => 'draft' ] );
$nsess = wp_generate_uuid4();
AICOM_Sessions::open_remote( $nsess, 'Edit pages', 'Notice test' );
$update = static function ( int $id, array $args ) use ( $nsess ): array {
    $tt = wp_generate_uuid4();
    AICOM_Base_Executor::execute( [ 'id' => wp_generate_uuid4(), 'type' => 'execute', 'task_target_id' => $tt, 'tool_id' => 'wp.posts.update',
        'params' => [ 'arguments' => [ 'id' => $id ] + $args, 'tool_id' => 'wp.posts.update' ],
        'authorization' => issue_token( [ 'task_target_id' => $tt, 'session_id' => $nsess, 'allowed_scopes' => [ 'read.wp', 'write.wp.posts' ] ] ) ] );
    return AICOM_Base_State::get( 'pending_results', [] )[ $tt ] ?? [];
};
$res = $update( $el_page, [ 'post_content' => 'new text' ] );
ok( 'an AICOMBase that does not list notices gets none in the body; the notice stays in the result', $res['status'] === 'completed' && ! isset( $res['notices'] )
    && ( $res['result']['notices'][0]['code'] ?? '' ) === 'builder.elementor', wp_json_encode( $res ) );
AICOM_Base_State::update( [ 'base_features' => [ 'notices', 'site.notice' ] ] );
$res = $update( $el_page, [ 'post_content' => 'newer text' ] );
ok( 'an AICOMBase that lists notices gets the Elementor warning as the result notice', ( $res['notices'][0] ?? null ) === [ 'level' => 'warn', 'text' => "This page is built with Elementor; visitors see Elementor's content, not post_content — use elementor.page.get_texts / elementor.page.bulk_update_texts.", 'code' => 'builder.elementor' ], wp_json_encode( $res ) );
ok( '... and not twice (moved out of the result)', ! isset( $res['result']['notices'] ) && ( $res['result']['updated'] ?? false ) === true );
$res = $update( $el_page, [ 'post_title' => 'AICOM Elementor notice test 2' ] );
ok( 'no warning when post_content is not changed', $res['status'] === 'completed' && ! isset( $res['notices'] ) );
$res = $update( $plain_page, [ 'post_content' => 'new text' ] );
ok( 'no warning on a page not built with Elementor', $res['status'] === 'completed' && ! isset( $res['notices'] ) );
AICOM_Sessions::close_remote( $nsess, 'completed' );

// Any MCP client reads the notice in the text content too, and on a dry run before making the change.
$nkey = AICOM_Auth::create_key( 'Notice test', [ 'read.wp', 'write.wp.posts' ] );
$saved_auth = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $nkey['plain_key'];
$call = static function ( string $name, array $args ): array {
    $r = AICOM_Tool_Router::dispatch( (string) wp_json_encode( [ 'jsonrpc' => '2.0', 'method' => 'tools/call', 'params' => [ 'name' => $name, 'arguments' => $args ], 'id' => 7 ] ), '2025-06-18' );
    return $r['result'] ?? $r;
};
$call( 'session.open', [ 'name' => 'Notice test', 'description' => 'Direct MCP client' ] );
$r = $call( 'wp.posts.update', [ 'id' => $el_page, 'content' => 'direct', 'dry_run' => true ] );
ok( 'dry run already warns (content alias resolved to post_content)', ( $r['structuredContent']['notices'][0]['code'] ?? '' ) === 'builder.elementor', wp_json_encode( $r ) );
$r = $call( 'wp.posts.update', [ 'id' => $el_page, 'post_content' => 'direct' ] );
ok( 'the MCP text content carries the warning', strpos( (string) ( $r['content'][0]['text'] ?? '' ), "WARN: This page is built with Elementor" ) !== false, (string) ( $r['content'][0]['text'] ?? '' ) );
ok( '... and structuredContent has it as a notice', ( $r['structuredContent']['notices'][0]['level'] ?? '' ) === 'warn' );
$call( 'session.close', [] );
if ( $saved_auth === null ) { unset( $_SERVER['HTTP_AUTHORIZATION'] ); } else { $_SERVER['HTTP_AUTHORIZATION'] = $saved_auth; }
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}aicom_backups WHERE api_key_id = %d", $nkey['id'] ) );
$wpdb->delete( $wpdb->prefix . 'aicom_sessions', [ 'api_key_id' => $nkey['id'] ] );
AICOM_Auth::revoke_key( $nkey['id'] );
$desc = AICOM_Tool_Registry::get( 'wp.posts.update' )['description'] ?? '';
ok( 'wp.posts.update tells agents about Elementor pages in its description', strpos( $desc, 'elementor.page.bulk_update_texts' ) !== false );
wp_delete_post( $el_page, true );
wp_delete_post( $plain_page, true );

// Notices on events: cleaned, and only to an AICOMBase that reads them.
AICOM_Base_Events::purge();
$raw_notices = [ [ 'level' => 'info', 'text' => 'Fine', 'code' => 'x.y' ], [ 'level' => 'loud', 'text' => 'dropped' ], [ 'level' => 'warn', 'text' => '<b>Bold</b> ' . str_repeat( 'a', 600 ), 'items' => [ 'one', [ 'nested' ], str_repeat( 'b', 300 ) ], 'code' => 'bad code' ] ];
AICOM_Base_Events::enqueue( 'action', [ 'session_id' => 's', 'summary' => 'x', 'notices' => $raw_notices ] );
$n = queued()[0]['notices'] ?? [];
ok( 'event notices: malformed one dropped, tags stripped, text and items capped, bad code left out', count( $n ) === 2 && $n[0] === [ 'level' => 'info', 'text' => 'Fine', 'code' => 'x.y' ]
    && strpos( $n[1]['text'], 'Bold a' ) === 0 && mb_strlen( $n[1]['text'] ) === 500 && $n[1]['items'] === [ 'one', str_repeat( 'b', 200 ) ] && ! isset( $n[1]['code'] ), wp_json_encode( $n ) );
ok( 'at most 10 notices', count( AICOM_Notices::clean( array_fill( 0, 15, [ 'level' => 'info', 'text' => 't' ] ) ) ) === 10 );
AICOM_Base_State::update( [ 'base_features' => [] ] );
AICOM_Base_Events::purge();
AICOM_Base_Events::enqueue( 'action', [ 'session_id' => 's', 'summary' => 'x', 'notices' => $raw_notices ] );
ok( 'no notices to an AICOMBase that does not list them', ! array_key_exists( 'notices', queued()[0] ) );
AICOM_Base_State::update( [ 'base_features' => [ 'notices' ] ] );
AICOM_Base_Events::purge();
AICOM_Base_Events::session_restored( 'sess-x', 3, '', [ 'post 5: title' ], [ 'term 9: A term with the name provided already exists' ] );
$sr = queued()[0];
ok( 'session.restored: what Undo could not put back is an error notice', ( $sr['notices'][0]['code'] ?? '' ) === 'restore.failed' && $sr['notices'][0]['level'] === 'error' && $sr['notices'][0]['items'] === [ 'term 9: A term with the name provided already exists' ] && $sr['kept'] === [ 'post 5: title' ] );
AICOM_Base_Events::purge();
AICOM_Base_Events::session_restored( 'sess-x', 4, '', [], [], [ 'user 12 (ana)' ] );
$sr = queued()[0];
ok( '... a deleted user brought back with a new password is a warning, naming them', count( $sr['notices'] ?? [] ) === 1 && $sr['notices'][0]['code'] === 'restore.user_password' && $sr['notices'][0]['level'] === 'warn' && $sr['notices'][0]['items'] === [ 'user 12 (ana)' ], wp_json_encode( $sr ) );

// site.notice: only to an AICOMBase that lists it, each code once an hour.
forget_notice_codes();
AICOM_Base_Events::purge();
AICOM_Base_State::update( [ 'base_features' => [ 'notices' ] ] );
AICOM_Base_Events::site_notice( 'warn', 'test.notice', 'Something happened.', [ 'title' => 'Test' ] );
ok( 'no site.notice to an AICOMBase that does not list it', ! notices_of( 'site.notice' ) );
AICOM_Base_State::update( [ 'base_features' => [ 'notices', 'site.notice' ] ] );
AICOM_Base_Events::site_notice( 'warn', 'test.notice', 'Something happened.', [ 'title' => 'Test', 'category' => 'monitoring', 'items' => [ 'a' ], 'session_id' => '' ] );
AICOM_Base_Events::site_notice( 'warn', 'test.notice', 'Something happened again.' );
AICOM_Base_Events::site_notice( 'info', 'test.other', 'Another kind.', [ 'category' => 'nonsense' ] );
$sn = notices_of( 'site.notice' );
ok( 'site.notice queued with its shape, no empty fields', count( $sn ) === 2 && $sn[0]['level'] === 'warn' && $sn[0]['code'] === 'test.notice' && $sn[0]['text'] === 'Something happened.'
    && $sn[0]['title'] === 'Test' && $sn[0]['category'] === 'monitoring' && $sn[0]['items'] === [ 'a' ] && ! array_key_exists( 'session_id', $sn[0] ) && ! empty( $sn[0]['at'] ), wp_json_encode( $sn ) );
ok( 'the same code is not repeated within the hour; an unknown category is left out', $sn[1]['code'] === 'test.other' && ! array_key_exists( 'category', $sn[1] ) );

// Producers.
forget_notice_codes();
AICOM_Base_Events::purge();
AICOM_Base_Events::on_plugin_deactivated( 'elementor/elementor.php' );
AICOM_Base_Events::on_plugin_deactivated( 'akismet/akismet.php' );
$el_tools = count( array_filter( AICOM_Tool_Registry::get_all(), static fn( $t ) => ( $t['dependency'] ?? null ) === 'elementor' ) );
$sn = notices_of( 'site.notice' );
ok( 'deactivating Elementor: a warning that its tools are gone, with how many', count( $sn ) === 1 && $sn[0]['code'] === 'dependency.deactivated.elementor' && $sn[0]['level'] === 'warn'
    && strpos( $sn[0]['text'], "its $el_tools AICOM tools" ) !== false && $sn[0]['category'] === 'monitoring', wp_json_encode( $sn ) );
ok( '... and a plugin no tool depends on raises none (only the security event)', count( array_filter( queued(), static fn( $e ) => $e['type'] === 'security' ) ) === 2 );

forget_notice_codes();
AICOM_Sessions::close_stale( 2 ); // sessions earlier runs left open
forget_notice_codes();
$ssess = wp_generate_uuid4();
$sl    = AICOM_Sessions::open_remote( $ssess, 'Long running work', 'Stale test' );
$wpdb->update( $wpdb->prefix . 'aicom_sessions', [ 'opened_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS ) ], [ 'id' => (int) $sl['id'] ] );
AICOM_Base_Events::purge();
AICOM_Sessions::close_stale( 2 );
$sn = notices_of( 'site.notice' );
ok( 'the 2 h rule closing an AICOMBase session tells the owner, linked to that session', count( $sn ) === 1 && $sn[0]['code'] === 'session.closed_stale' && ( $sn[0]['session_id'] ?? '' ) === $ssess
    && ( $sn[0]['items'] ?? [] ) === [ 'Long running work' ] && AICOM_Sessions::find_open_remote( $ssess ) === null, wp_json_encode( $sn ) );

forget_notice_codes();
$opt_age = get_option( 'aicom_backup_max_age_days', null );
$opt_mb  = get_option( 'aicom_backup_max_size_mb', null );
$t_bk    = $wpdb->prefix . 'aicom_backups';
$old_row = static function ( int $bytes ) use ( $wpdb, $t_bk ): int {
    $wpdb->insert( $t_bk, [ 'created_at' => '1901-01-02 00:00:00', 'tool_name' => 'aicom.notice.test', 'target_type' => 'post', 'target_id' => '0', 'payload_json' => str_repeat( 'x', $bytes ) ] );
    return (int) $wpdb->insert_id;
};
// Only rows older than 100 years go: just the test's own.
$bk = $old_row( 10 );
update_option( 'aicom_backup_max_age_days', 36500 );
update_option( 'aicom_backup_max_size_mb', 0 );
AICOM_Base_Events::purge();
AICOM_Admin::run_backup_cleanup();
$sn = notices_of( 'site.notice' );
ok( 'retention deleting snapshots tells the owner', ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t_bk WHERE id = %d", $bk ) ) && count( $sn ) === 1 && $sn[0]['code'] === 'backups.retention' && $sn[0]['level'] === 'info', wp_json_encode( $sn ) );
// A limit just under the current total plus the test's (oldest) row: only that row goes.
$total = (int) $wpdb->get_var( "SELECT SUM(LENGTH(payload_json)) FROM $t_bk" );
$mb    = (int) ceil( ( $total + 1 ) / MB_IN_BYTES );
$bk    = $old_row( $mb * MB_IN_BYTES - $total + 1000 );
update_option( 'aicom_backup_max_age_days', 0 );
update_option( 'aicom_backup_max_size_mb', $mb );
AICOM_Base_Events::purge();
AICOM_Admin::run_backup_cleanup();
$sn = notices_of( 'site.notice' );
ok( 'the size limit deleting snapshots warns the owner', ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t_bk WHERE id = %d", $bk ) ) && (int) $wpdb->get_var( "SELECT SUM(LENGTH(payload_json)) FROM $t_bk" ) === $total
    && count( $sn ) === 1 && $sn[0]['code'] === 'backups.size_limit' && $sn[0]['level'] === 'warn' && strpos( $sn[0]['text'], "$mb MB" ) !== false, wp_json_encode( $sn ) );
foreach ( [ 'aicom_backup_max_age_days' => $opt_age, 'aicom_backup_max_size_mb' => $opt_mb ] as $o => $v ) {
    if ( $v === null ) { delete_option( $o ); } else { update_option( $o, $v ); }
}
forget_notice_codes();
AICOM_Sessions::close_remote( $sess, 'completed' ); // reopened above for the token tests

// ═══ Summary ═════════════════════════════════════════════════════════════════
restore_state();
echo "\n" . ( $fail ? "FAILED" : "OK" ) . ": $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
