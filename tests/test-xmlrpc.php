<?php
/**
 * "Disable XML-RPC": the setting, the wp.security.xmlrpc.get/.set tools (through the real Tool Router,
 * so session, scope and auto-backup gates apply), Undo via Restore session (option snapshot), the
 * Jetpack guard, AICOM-only-mode interplay, what AICOMBase is told (inventory + inspector), and the
 * remote allow-list rule for scopes added after an admin saved it.
 *
 * Restores every option it touches. Run:
 *   docker compose exec -T wp php -d opcache.jit=0 /var/www/html/wp-content/plugins/aicom/tests/test-xmlrpc.php
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
function tool( string $name, array $args = [] ): array {
    $r = AICOM_Tool_Router::dispatch( (string) wp_json_encode( [
        'jsonrpc' => '2.0', 'method' => 'tools/call', 'id' => 1,
        'params'  => [ 'name' => $name, 'arguments' => $args ? $args : new stdClass() ],
    ] ) );
    $res = $r['result'] ?? $r;
    return isset( $res['structuredContent'] ) ? $res['structuredContent'] + [ 'isError' => ! empty( $res['isError'] ) ] : $res;
}
function err_code( array $r ): string {
    // Tool errors carry their code in error.code; router gate errors (JSON-RPC) in error.data.code.
    return (string) ( $r['error']['data']['code'] ?? $r['error']['code'] ?? $r['code'] ?? '' );
}
function setting_raw() {
    $missing = new stdClass();
    $v       = get_option( AICOM_Xmlrpc::OPTION, $missing );
    return $v === $missing ? 'absent' : (string) $v;
}

// ── Snapshot what we touch ────────────────────────────────────────────────
$opts = [ AICOM_Xmlrpc::OPTION, AICOM_Lockdown::OPTION, AICOM_Base_Policy::OPT_ALLOWED, AICOM_Base_Policy::OPT_SEEN ];
$snap = [];
foreach ( $opts as $o ) {
    $missing    = new stdClass();
    $v          = get_option( $o, $missing );
    $snap[ $o ] = $v === $missing ? $missing : $v;
}
delete_option( AICOM_Xmlrpc::OPTION );      // a site that never touched the setting
update_option( AICOM_Lockdown::OPTION, 0 );

$key = AICOM_Auth::create_key( 'XML-RPC test', [ 'read.wp', 'manage.security.hardening' ] );
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key['plain_key'];

section( 'Gates' );
$r = tool( 'wp.security.xmlrpc.set', [ 'enabled' => false ] );
ok( 'a change outside a session is refused', err_code( $r ) === 'NO_ACTIVE_SESSION', wp_json_encode( $r ) );
ok( '... and nothing changed', setting_raw() === 'absent' );

$r = tool( 'session.open', [ 'name' => 'Turn off XML-RPC', 'description' => 'The owner asked to turn off XML-RPC (finding SEC-EXP-003).' ] );
$sid = (int) ( $r['session_id'] ?? 0 );
ok( 'session opened', $sid > 0, wp_json_encode( $r ) );

$r = tool( 'wp.security.xmlrpc.get' );
ok( 'get: on by default, nothing turns it off', ( $r['xmlrpc_enabled'] ?? null ) === true && ( $r['disabled_by_setting'] ?? null ) === false, wp_json_encode( $r ) );

$r = tool( 'wp.security.xmlrpc.set', [] );
ok( 'missing enabled → MISSING_PARAM, never read as "off"', err_code( $r ) === 'MISSING_PARAM' && setting_raw() === 'absent', wp_json_encode( $r ) );
$r = tool( 'wp.security.xmlrpc.set', [ 'enabled' => 'maybe' ] );
ok( 'non-boolean enabled → MISSING_PARAM', err_code( $r ) === 'MISSING_PARAM' && setting_raw() === 'absent', wp_json_encode( $r ) );

$backups_before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}aicom_backups WHERE session_id = %d", $sid ) );
$r = tool( 'wp.security.xmlrpc.set', [ 'enabled' => false, 'dry_run' => true ] );
ok( 'dry run: would change, changes nothing, snapshots nothing', ( $r['would_change'] ?? null ) === true && setting_raw() === 'absent'
    && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}aicom_backups WHERE session_id = %d", $sid ) ) === $backups_before, wp_json_encode( $r ) );

section( 'Jetpack guard' );
$jetpack = static fn() => [ 'jetpack/jetpack.php' => 'Jetpack' ];
add_filter( 'aicom_xmlrpc_needed_by', $jetpack );
$r = tool( 'wp.security.xmlrpc.set', [ 'enabled' => false ] );
ok( 'refused while Jetpack is active (XMLRPC_NEEDED), nothing changed', err_code( $r ) === 'XMLRPC_NEEDED' && setting_raw() === 'absent', wp_json_encode( $r ) );
remove_filter( 'aicom_xmlrpc_needed_by', $jetpack );

section( 'Turn it off' );
$r = tool( 'wp.security.xmlrpc.set', [ 'enabled' => false ] );
ok( 'off: changed, read back, verified', ( $r['changed'] ?? null ) === true && ( $r['xmlrpc_enabled'] ?? null ) === false && ( $r['verified'] ?? null ) === true, wp_json_encode( $r ) );
ok( 'setting stored', AICOM_Xmlrpc::is_disabled_by_setting() && AICOM_Xmlrpc::is_off() );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aicom_backups WHERE session_id = %d AND target_type = 'option' ORDER BY id DESC LIMIT 1", $sid ), ARRAY_A );
$pl  = json_decode( (string) ( $row['payload_json'] ?? '' ), true );
ok( 'the old value was snapshotted first (option did not exist)', $row && $row['target_id'] === AICOM_Xmlrpc::OPTION && ( $pl['exists'] ?? null ) === false, wp_json_encode( $row ) );
$r = tool( 'wp.security.xmlrpc.set', [ 'enabled' => false ] );
ok( 'off again: no change', ( $r['changed'] ?? null ) === false && ( $r['xmlrpc_enabled'] ?? null ) === false, wp_json_encode( $r ) );

$sec = AICOM_Base_Inspector::collect( 'security' );
ok( 'AICOMBase security evidence: xmlrpc_enabled false, setting true', ( $sec['config']['xmlrpc_enabled'] ?? null ) === false && ( $sec['config']['aicom_xmlrpc_disabled'] ?? null ) === true, wp_json_encode( $sec['config'] ?? [] ) );
tool( 'session.close' );

section( 'Undo (Restore session)' );
$n = AICOM_Admin::do_restore_session( $sid );
ok( 'restore puts it back exactly — the option is gone again, XML-RPC on', $n >= 1 && setting_raw() === 'absent' && ! AICOM_Xmlrpc::is_off(), "restored=$n setting=" . setting_raw() );

// A second session: on → off → on, from an explicit "0": restore returns to "0" (newest snapshot first).
update_option( AICOM_Xmlrpc::OPTION, 0 );
$r    = tool( 'session.open', [ 'name' => 'Toggle XML-RPC', 'description' => 'The owner asked to try XML-RPC off, then on again.' ] );
$sid2 = (int) ( $r['session_id'] ?? 0 );
tool( 'wp.security.xmlrpc.set', [ 'enabled' => false ] );
tool( 'wp.security.xmlrpc.set', [ 'enabled' => true ] );
tool( 'wp.security.xmlrpc.set', [ 'enabled' => false ] );
tool( 'session.close' );
AICOM_Admin::do_restore_session( $sid2 );
ok( 'several changes in one session: restore returns to the value before the session', setting_raw() === '0', 'setting=' . setting_raw() );

// Restore only writes options the router snapshots — a tampered row can't rewrite another setting.
$blog = get_option( 'blogname' );
$wpdb->insert( $wpdb->prefix . 'aicom_backups', [ 'created_at' => current_time( 'mysql', true ), 'session_id' => 987654321, 'api_key_id' => (int) $key['id'], 'tool_name' => 'x', 'target_type' => 'option', 'target_id' => 'blogname', 'manifest_json' => '{}', 'payload_json' => wp_json_encode( [ 'option' => 'blogname', 'exists' => true, 'value' => 'HACKED' ] ) ] );
AICOM_Admin::do_restore_session( 987654321 );
ok( 'restore refuses an option the router never snapshots', get_option( 'blogname' ) === $blog );
$wpdb->delete( $wpdb->prefix . 'aicom_backups', [ 'session_id' => 987654321 ] );

section( 'AICOM-only mode' );
update_option( AICOM_Lockdown::OPTION, 1 );
$r    = tool( 'session.open', [ 'name' => 'XML-RPC on', 'description' => 'The owner asked to turn XML-RPC back on.' ] );
$sid3 = (int) ( $r['session_id'] ?? 0 );
$r    = tool( 'wp.security.xmlrpc.set', [ 'enabled' => true ] );
ok( 'turning it on while AICOM-only mode is on: setting saved, XML-RPC stays off, and the result says why', ( $r['disabled_by_setting'] ?? null ) === false && ( $r['xmlrpc_enabled'] ?? null ) === false && strpos( (string) ( $r['note'] ?? '' ), 'AICOM-only mode' ) !== false, wp_json_encode( $r ) );
tool( 'session.close' );
update_option( AICOM_Lockdown::OPTION, 0 );

section( 'What AICOMBase sees' );
$tools = array_merge( ...array_column( AICOM_Base_Capabilities::build(), 'tools' ) );
$set   = current( array_filter( $tools, static fn( $t ) => $t['id'] === 'wp.security.xmlrpc.set' ) );
ok( 'inventory: wp.security.xmlrpc.set, med risk, reversible, scope manage.security.hardening', $set && $set['risk'] === 'med' && $set['reversible'] === true && $set['required_scopes'] === [ 'manage.security.hardening' ], wp_json_encode( $set ) );
ok( 'in its own capability group (site_security)', AICOM_Base_Capabilities::capability_id( 'wp.security.xmlrpc.set', 'wp_core' ) === 'site_security' );

section( 'Remote allow-list and scopes added later' );
delete_option( AICOM_Base_Policy::OPT_ALLOWED );
delete_option( AICOM_Base_Policy::OPT_SEEN );
ok( 'default list allows it (not critical)', in_array( 'manage.security.hardening', AICOM_Base_Policy::allowed_scopes(), true ) );
update_option( AICOM_Base_Policy::OPT_ALLOWED, [ 'read.wp', 'write.wp.posts' ] ); // saved before this scope existed
ok( 'a list saved before the scope existed allows it, as the default would', in_array( 'manage.security.hardening', AICOM_Base_Policy::allowed_scopes(), true ) );
ok( '... but adds nothing the admin left out', ! in_array( 'manage.media', AICOM_Base_Policy::allowed_scopes(), true ) && ! in_array( 'manage.plugins', AICOM_Base_Policy::allowed_scopes(), true ) );
AICOM_Base_Policy::set_allowed_scopes( [ 'read.wp', 'write.wp.posts' ] ); // saved now, with the scope on screen and left unticked
ok( 'a list saved with the scope shown and unticked keeps it out', ! in_array( 'manage.security.hardening', AICOM_Base_Policy::allowed_scopes(), true ) );

// ── Clean up ──────────────────────────────────────────────────────────────
AICOM_Auth::revoke_key( $key['id'] );
foreach ( $snap as $o => $v ) {
    if ( $v instanceof stdClass ) {
        delete_option( $o );
    } else {
        update_option( $o, $v );
    }
}

echo "\n" . ( $fail ? "FAILED: $fail failed, $pass passed" : "OK: $pass passed, 0 failed" ) . "\n";
exit( $fail ? 1 : 0 );
