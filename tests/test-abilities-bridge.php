<?php
/**
 * Abilities bridge: WordPress Abilities published as AICOM tools with no
 * plugin-specific code in AICOM. Uses Clautron (1.5+, which registers its
 * operations as abilities) as the real provider, through the real Tool Router
 * with a real key and session, and with NO WordPress user — like an actual
 * AICOM request, so the bridge's permission grant is what lets abilities run.
 *
 * Covers: the pure ability → tool mapping, legacy tool ids kept and taken over,
 * scope/session gates, dry run, the scopes_resolver, error mapping, the
 * permission grant not leaking past the call, the inventory, the fingerprint,
 * and clautron.primitive.apply end to end (apply → idempotent → deactivate →
 * reactivated, never a duplicate blueprint).
 *
 * Cleans up what it creates. Run:
 *   docker compose exec -T wp php -d memory_limit=512M /var/www/html/wp-content/plugins/aicom/tests/test-abilities-bridge.php
 */

define( 'DOING_AJAX', true );
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require_once '/var/www/html/wp-load.php';
wp_set_current_user( 0 );

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
    return (string) ( $r['error']['data']['code'] ?? $r['error']['code'] ?? $r['code'] ?? '' );
}
function as_key( array $key ): void {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key['plain_key'];
}

if ( ! function_exists( 'wp_get_ability' ) || ! wp_get_ability( 'clautron/catalog-list' ) ) {
    echo "SKIPPED: needs WordPress 7.1+ and Clautron 1.5+ (clautron/* abilities).\n";
    exit( 0 );
}

$keys       = [];
$blueprints = [];
$caps       = [];

section( 'Mapping (pure)' );
ok( 'default name: ns/a-b-c → ns.a.b.c', AICOM_Module_Abilities::tool_name( 'clautron', 'capability-meta-get', null ) === 'clautron.capability.meta.get' );
ok( 'meta.tool_name keeps an established id', AICOM_Module_Abilities::tool_name( 'clautron', 'blueprint-smoke-test', 'clautron.blueprint.smoke_test' ) === 'clautron.blueprint.smoke_test' );
ok( 'meta.tool_name outside the namespace is ignored (no hijacking wp.*)', AICOM_Module_Abilities::tool_name( 'clautron', 'x', 'wp.posts.delete' ) === 'clautron.x' );

$schema = AICOM_Module_Abilities::input_schema( [
    'type'       => 'object',
    'properties' => [ 'a' => [ 'type' => 'string' ], 'b' => [ 'type' => 'integer' ], 'dry_run' => [ 'type' => 'boolean' ] ],
    'required'   => [ 'a' ],
] );
ok( 'JSON Schema → flat input_schema, required flag kept, dry_run left to the router',
    $schema === [ 'a' => [ 'type' => 'string', 'required' => true ], 'b' => [ 'type' => 'integer' ] ], wp_json_encode( $schema ) );

$scopes = AICOM_Auth::scope_slugs();
$list   = wp_get_ability( 'clautron/catalog-list' );
$resolv = wp_get_ability( 'clautron/capability-scopes' );
ok( 'non-public ability (a scopes resolver) is not published', null === AICOM_Module_Abilities::tool_meta( $resolv, [ 'clautron' ], $scopes ) );
ok( 'namespace not opted in is not published', null === AICOM_Module_Abilities::tool_meta( $list, [ 'woocommerce' ], $scopes ) );
ok( 'unknown scope refuses the ability instead of publishing it unguarded', null === AICOM_Module_Abilities::tool_meta( $list, [ 'clautron' ], [ 'manage.clautron' ] ) );
$meta = AICOM_Module_Abilities::tool_meta( wp_get_ability( 'clautron/capability-meta-set' ), [ 'clautron' ], $scopes );
ok( 'meta.scopes honoured (capability.meta.set needs manage.meta too)', $meta && $meta['required_scopes'] === [ 'manage.meta', 'manage.clautron' ], wp_json_encode( $meta['required_scopes'] ?? null ) );

section( 'Published tools' );
$legacy = [
    'clautron.catalog.list', 'clautron.catalog.install', 'clautron.capability.inspect', 'clautron.capability.update',
    'clautron.capability.deactivate', 'clautron.primitives.list', 'clautron.blueprint.examples', 'clautron.blueprint.list',
    'clautron.blueprint.validate', 'clautron.blueprint.create', 'clautron.blueprint.compile', 'clautron.blueprint.smoke_test',
    'clautron.capability.meta.get', 'clautron.capability.meta.set', 'clautron.events.types', 'clautron.events.query',
    'clautron.events.aggregate', 'clautron.capability.ensure',
];
$not_bridged = array_filter( $legacy, static fn( $t ) => empty( AICOM_Tool_Registry::get( $t )['ability'] ) );
ok( 'all 18 legacy clautron.* ids still exist, now ability-backed', ! $not_bridged, implode( ', ', $not_bridged ) );
ok( 'new operation appears with no AICOM change: clautron.primitive.apply', ( AICOM_Tool_Registry::get( 'clautron.primitive.apply' )['ability'] ?? '' ) === 'clautron/primitive-apply' );
ok( 'read-only ability → read class, read.wp', AICOM_Tool_Registry::get( 'clautron.catalog.list' )['class'] === 'read' && AICOM_Tool_Registry::get( 'clautron.catalog.list' )['required_scopes'] === [ 'read.wp' ] );
ok( 'write ability → write class, manage.clautron, dry run from schema', AICOM_Tool_Registry::get( 'clautron.primitive.apply' )['class'] === 'write' && AICOM_Tool_Registry::get( 'clautron.primitive.apply' )['supports_dry_run'] === true );

$inventory = AICOM_Base_Capabilities::build();
$clautron  = current( array_filter( $inventory, static fn( $c ) => $c['id'] === 'clautron' ) );
ok( 'inventory sent to AICOMBase lists clautron.primitive.apply in the clautron capability',
    $clautron && in_array( 'clautron.primitive.apply', array_column( $clautron['tools'], 'id' ), true ) );

$fp     = new ReflectionMethod( AICOM_Base_Capabilities::class, 'fingerprint' );
$before = $fp->invoke( null );
$orig   = AICOM_Tool_Registry::get( 'clautron.catalog.list' );
AICOM_Tool_Registry::register( 'clautron.catalog.list', [ 'description' => 'changed' ] + $orig );
$after = $fp->invoke( null );
AICOM_Tool_Registry::register( 'clautron.catalog.list', $orig );
ok( 'fingerprint changes when a tool description changes (plugin update, same AICOM version)', $before !== $after );

section( 'Gates' );
$reader = AICOM_Auth::create_key( 'Abilities bridge test (read)', [ 'read.wp' ] );
$writer = AICOM_Auth::create_key( 'Abilities bridge test (write)', [ 'read.wp', 'manage.clautron' ] );
$keys   = [ $reader['id'], $writer['id'] ];

as_key( $reader );
$r = tool( 'clautron.primitives.list', [ 'category' => 'security' ] );
ok( 'read tool works without a session, no WordPress user', ( $r['count'] ?? 0 ) >= 4 && empty( $r['isError'] ), wp_json_encode( $r ) );
ok( '... and returns safe/verifiable per primitive', isset( $r['primitives'][0]['safe'], $r['primitives'][0]['verifiable'] ) );
ok( 'the permission grant did not outlive the call', is_wp_error( wp_get_ability( 'clautron/catalog-list' )->execute() ) );
$r = tool( 'clautron.events.types' );
ok( 'a tool with no fields accepts {} (what MCP clients always send)', empty( $r['isError'] ) && isset( $r['event_types'] ), wp_json_encode( $r ) );

$r = tool( 'session.open', [ 'name' => 'Bridge test', 'description' => 'Testing the abilities bridge.' ] );
$r = tool( 'clautron.primitive.apply', [ 'type' => 'hide_wp_version', 'dry_run' => true ] );
ok( 'write tool refused for a key without manage.clautron (DENIED_SCOPE)', err_code( $r ) === 'DENIED_SCOPE', wp_json_encode( $r ) );
tool( 'session.close' );

as_key( $writer );
$r = tool( 'clautron.primitive.apply', [ 'type' => 'hide_wp_version' ] );
ok( 'write tool refused outside a session', err_code( $r ) === 'NO_ACTIVE_SESSION', wp_json_encode( $r ) );

$r = tool( 'session.open', [ 'name' => 'Bridge test', 'description' => 'Testing the abilities bridge end to end.' ] );
ok( 'session opened', ! empty( $r['session_id'] ), wp_json_encode( $r ) );

$r = tool( 'clautron.capability.meta.set', [ 'capability_id' => 'seo', 'post_id' => 1, 'fields' => [ 'x' => 'y' ], 'dry_run' => true ] );
ok( 'meta.scopes enforced: meta.set refused without manage.meta (DENIED_SCOPE)', err_code( $r ) === 'DENIED_SCOPE', wp_json_encode( $r ) );

$force = static fn( $result, string $name ) => 'clautron/capability-scopes' === $name ? [ 'scopes' => [ 'manage.users' ] ] : $result;
add_filter( 'wp_ability_execute_result', $force, 10, 2 );
$r = tool( 'clautron.catalog.install', [ 'capability_id' => 'seo', 'dry_run' => true ] );
remove_filter( 'wp_ability_execute_result', $force, 10 );
ok( 'scopes_resolver: a manifest scope the key lacks refuses the action', err_code( $r ) === 'DENIED_CAPABILITY_SCOPE', wp_json_encode( $r ) );
add_filter( 'wp_ability_execute_result', $force, 10, 2 );
$r = tool( 'clautron.capability.ensure', [ 'capability_id' => 'seo', 'dry_run' => true ] );
remove_filter( 'wp_ability_execute_result', $force, 10 );
ok( '... capability.ensure too (it can install the same template)', err_code( $r ) === 'DENIED_CAPABILITY_SCOPE', wp_json_encode( $r ) );

section( 'Errors' );
$r = tool( 'clautron.primitive.apply', [ 'type' => 'rate_limit_check', 'config' => [ 'key' => 'x' ] ] );
ok( 'ability WP_Error → tool error with its code (RUNTIME_PRIMITIVE)', err_code( $r ) === 'RUNTIME_PRIMITIVE', wp_json_encode( $r ) );
$r = tool( 'clautron.primitive.apply', [ 'type' => 'no_such_primitive' ] );
ok( 'unknown primitive → UNKNOWN_PRIMITIVE', err_code( $r ) === 'UNKNOWN_PRIMITIVE', wp_json_encode( $r ) );
$r = tool( 'clautron.blueprint.compile', [ 'blueprint_id' => 'abc' ] );
ok( 'schema violation caught by core validation, nothing runs (ABILITY_INVALID_INPUT)', err_code( $r ) === 'ABILITY_INVALID_INPUT', wp_json_encode( $r ) );

section( 'primitive.apply end to end' );
$r = tool( 'clautron.primitive.apply', [ 'type' => 'hide_wp_version', 'dry_run' => true ] );
ok( 'dry run: valid, not active, nothing created', ( $r['dry_run'] ?? false ) === true && ( $r['already_active'] ?? null ) === false, wp_json_encode( $r ) );
ok( 'dry run returns effects before anything happens (none, fully reversible)', is_array( $r['effects'] ?? null ) && ! array_filter( $r['effects'] ) && ( $r['reversible'] ?? null ) === true, wp_json_encode( $r ) );
$cap_id = (string) ( $r['capability_id'] ?? '' );
$caps[] = $cap_id;

$r = tool( 'clautron.primitive.apply', [ 'type' => 'hide_wp_version' ] );
$bp = (int) ( $r['blueprint_id'] ?? 0 );
$blueprints[] = $bp;
ok( 'applied: created, compiled, verified (envelope status success)', $bp > 0 && ( $r['status'] ?? '' ) === 'success' && empty( $r['result']['verify']['failed'] ), wp_json_encode( $r ) );
ok( '... with its undo step: deactivate, complete', ( $r['undo'] ?? null ) === [ 'ability' => 'clautron/capability-deactivate', 'input' => [ 'capability_id' => $cap_id ], 'complete' => true ], wp_json_encode( $r['undo'] ?? null ) );

$r = tool( 'clautron.primitive.apply', [ 'type' => 'hide_wp_version' ] );
ok( 'same type + config again: same blueprint, nothing new', (int) ( $r['blueprint_id'] ?? 0 ) === $bp && ( $r['result']['status'] ?? '' ) === 'already_active', wp_json_encode( $r ) );
ok( '... and no undo step (nothing changed)', ! isset( $r['undo'] ) );

$r = tool( 'clautron.primitive.apply', [ 'type' => 'hide_wp_version', 'capability_id' => $cap_id, 'config' => [ 'mask_asset_versions' => false ] ] );
ok( 'same capability_id, different config → CAPABILITY_CONFLICT, not a silent no-op', err_code( $r ) === 'CAPABILITY_CONFLICT', wp_json_encode( $r ) );

$r = tool( 'clautron.capability.deactivate', [ 'capability_id' => $cap_id ] );
ok( 'deactivated through the contract envelope', ( $r['status'] ?? '' ) === 'success' && ( $r['action'] ?? '' ) === 'deactivate', wp_json_encode( $r ) );
ok( '... whose undo is reactivating (catalog-install)', ( $r['undo']['ability'] ?? '' ) === 'clautron/catalog-install', wp_json_encode( $r['undo'] ?? null ) );

$r = tool( 'clautron.primitive.apply', [ 'type' => 'hide_wp_version' ] );
ok( 'applied again after deactivate: reactivated, same blueprint (no duplicate)', (int) ( $r['blueprint_id'] ?? 0 ) === $bp && ( $r['result']['status'] ?? '' ) === 'reactivated', wp_json_encode( $r ) );

$r = tool( 'clautron.capability.inspect', [ 'capability_id' => $cap_id ] );
ok( 'inspect sees it installed and active', ( $r['result']['installed'] ?? false ) === true && ( $r['result']['status'] ?? '' ) === 'active', wp_json_encode( $r ) );

tool( 'clautron.capability.deactivate', [ 'capability_id' => $cap_id ] );
tool( 'session.close' );

section( 'Undo through Restore session' );
$inv     = AICOM_Base_Capabilities::build();
$cl      = current( array_filter( $inv, static fn( $c ) => $c['id'] === 'clautron' ) );
$by_id   = array_column( $cl['tools'] ?? [], null, 'id' );
ok( 'inventory: capability.deactivate is undoable up front (its undo is always complete)', ( $by_id['clautron.capability.deactivate']['reversible'] ?? null ) === true );
ok( '... primitive.apply is not: how complete its undo is depends on the config', ( $by_id['clautron.primitive.apply']['reversible'] ?? null ) === false );

$state       = static fn(): string => (string) CLAUTRON_Catalog::inspect( $cap_id )['status'];
$undo_rows   = static fn( int $sid ): array => $wpdb->get_results( $wpdb->prepare(
    "SELECT tool_name, target_id, payload_json FROM {$wpdb->prefix}aicom_backups WHERE session_id = %d AND target_type = 'ability_undo'", $sid
), ARRAY_A );
$open        = static function ( string $name ) use ( &$sessions ): int {
    $r = tool( 'session.open', [ 'name' => $name, 'description' => 'Abilities bridge undo test.' ] );
    $sessions[] = (int) ( $r['session_id'] ?? 0 );
    return (int) ( $r['session_id'] ?? 0 );
};
$sessions = [];

ok( 'starting point: the capability is deactivated', $state() !== 'active', $state() );

$s1 = $open( 'Undo test: apply' );
tool( 'clautron.primitive.apply', [ 'type' => 'hide_wp_version' ] );
$rows = $undo_rows( $s1 );
ok( 'applying records its undo step in the session backups',
    count( $rows ) === 1 && $rows[0]['tool_name'] === 'clautron.primitive.apply' && $rows[0]['target_id'] === $cap_id
    && ( json_decode( $rows[0]['payload_json'], true )['ability'] ?? '' ) === 'clautron/capability-deactivate', wp_json_encode( $rows ) );
ok( '... and the capability is active', $state() === 'active' );
tool( 'session.close' );
$n = AICOM_Admin::do_restore_session( $s1 );
ok( 'Restore session runs it: the capability is no longer active', $n === 1 && $state() !== 'active', "restored=$n status=" . $state() );

$s2 = $open( 'Undo test: reactivate' );
tool( 'clautron.primitive.apply', [ 'type' => 'hide_wp_version' ] );
tool( 'session.close' );
$s3 = $open( 'Undo test: deactivate' );
tool( 'clautron.capability.deactivate', [ 'capability_id' => $cap_id ] );
tool( 'session.close' );
ok( 'deactivated in a session', $state() !== 'active' );
$n = AICOM_Admin::do_restore_session( $s3 );
ok( 'Restore session of a deactivate reactivates it', $n === 1 && $state() === 'active', "restored=$n status=" . $state() );

$s4 = $open( 'Undo test: no-ops' );
tool( 'clautron.primitive.apply', [ 'type' => 'hide_wp_version', 'dry_run' => true ] );
tool( 'clautron.primitive.apply', [ 'type' => 'hide_wp_version' ] ); // already active
tool( 'session.close' );
ok( 'dry runs and calls that changed nothing record no undo step', ! $undo_rows( $s4 ) );

ok( 'valid_undo refuses an undo step in another namespace', null === AICOM_Module_Abilities::valid_undo( 'clautron/primitive-apply', [ 'ability' => 'core/get-site-info', 'input' => [] ] ) );
ok( 'valid_undo refuses an unregistered ability', null === AICOM_Module_Abilities::valid_undo( 'clautron/primitive-apply', [ 'ability' => 'clautron/no-such-thing', 'input' => [] ] ) );
$ns_before = get_option( AICOM_Module_Abilities::OPTION, null );
update_option( AICOM_Module_Abilities::OPTION, [ 'someone-else' ] );
ok( 'run_undo refuses a namespace no longer opted in', ! AICOM_Module_Abilities::run_undo( [ 'ability' => 'clautron/capability-deactivate', 'input' => [ 'capability_id' => $cap_id ] ] ) && $state() === 'active' );
null === $ns_before ? delete_option( AICOM_Module_Abilities::OPTION ) : update_option( AICOM_Module_Abilities::OPTION, $ns_before );

CLAUTRON_Catalog::deactivate( $cap_id );
foreach ( array_filter( $sessions ) as $sid ) {
    $wpdb->delete( $wpdb->prefix . 'aicom_backups', [ 'session_id' => $sid ] );
}

// ── Clean up ──────────────────────────────────────────────────────────────
foreach ( array_filter( $blueprints ) as $id ) {
    CLAUTRON_Blueprint_Store::update_status( $id, 'draft' );
    CLAUTRON_Blueprint_Store::delete( $id );
}
foreach ( array_filter( $caps ) as $cap ) {
    $wpdb->delete( CLAUTRON_DB::capabilities(), [ 'capability_id' => $cap ] );
}
foreach ( $keys as $id ) {
    AICOM_Auth::revoke_key( $id );
}

echo "\n" . ( $fail ? "FAILED: $fail failed, $pass passed" : "OK: $pass passed, 0 failed" ) . "\n";
exit( $fail ? 1 : 0 );
