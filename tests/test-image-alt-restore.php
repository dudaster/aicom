<?php
/**
 * Test: setting an image's alt text inside a session is backed up and can be undone by restoring the session —
 * including when the image had NO alt text before (live football.ehot.ro, 2026-10-01: a11y.set_image_alt was
 * non-reversible, so an AI fix of 8 alt texts couldn't be undone).
 * Run: docker compose exec -T wp php wp-content/plugins/aicom/tests/test-image-alt-restore.php --allow-root
 */

define( 'DOING_AJAX', true );
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require_once '/var/www/html/wp-load.php';
wp_set_current_user( 1 );

global $wpdb;
$pass = 0;
$fail = 0;

function ok( string $label, bool $cond, string $detail = '' ): void {
    global $pass, $fail;
    if ( $cond ) { echo "  ✓ $label\n"; $pass++; } else { echo "  ✗ $label" . ( $detail ? " → $detail" : '' ) . "\n"; $fail++; }
}
function tool( string $name, array $args ): array {
    $r = AICOM_Tool_Router::dispatch( json_encode( [ 'jsonrpc' => '2.0', 'method' => 'tools/call', 'params' => [ 'name' => $name, 'arguments' => $args ], 'id' => 1 ] ) );
    return $r['result'] ?? $r;
}
function alt_of( int $id ) { wp_cache_delete( $id, 'post_meta' ); return metadata_exists( 'post', $id, '_wp_attachment_image_alt' ) ? get_post_meta( $id, '_wp_attachment_image_alt', true ) : null; }

$key = AICOM_Auth::create_key( 'Image Alt Restore Test', [ 'read.wp', 'manage.a11y', 'manage.media', 'manage.backups' ] );
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key['plain_key'];

// Two images: one with no alt at all, one with an existing (bad) alt.
$no_alt  = wp_insert_post( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'omar-ram-GSneTuGO0dQ-unsplash', 'post_mime_type' => 'image/jpeg' ] );
$has_alt = wp_insert_post( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'IMG_1234', 'post_mime_type' => 'image/jpeg' ] );
update_post_meta( $has_alt, '_wp_attachment_image_alt', 'IMG_1234' );

echo "\n══ Session: set real descriptions ══\n";
$r = tool( 'session.open', [ 'name' => 'Fix image descriptions', 'description' => 'Replace file-name alt texts with real descriptions' ] );
$sid = (int) ( $r['session_id'] ?? 0 );
ok( 'session opened', $sid > 0, json_encode( $r ) );

$r = tool( 'a11y.set_image_alt', [ 'id' => $no_alt, 'alt' => 'Young players jumping for a header' ] );
ok( 'set alt on image without alt', ( $r['alt'] ?? '' ) === 'Young players jumping for a header', json_encode( $r ) );
$r = tool( 'media.update_meta', [ 'id' => $has_alt, 'alt' => 'Team photo after the final', 'title' => 'Team photo' ] );
ok( 'media.update_meta on image with alt', ! isset( $r['error'] ), json_encode( $r ) );
ok( 'alt written (no alt before)', alt_of( $no_alt ) === 'Young players jumping for a header' );
ok( 'alt written (had alt before)', alt_of( $has_alt ) === 'Team photo after the final' );

$backups = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}aicom_backups WHERE session_id = %d", $sid ) );
ok( 'a backup was taken for each change', $backups === 2, "got $backups" );
$payload = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT payload_json FROM {$wpdb->prefix}aicom_backups WHERE session_id = %d AND target_id = %s", $sid, (string) $no_alt ) ), true );
ok( 'backup remembers the alt was absent', in_array( '_wp_attachment_image_alt', (array) ( $payload['absent_meta'] ?? [] ), true ), json_encode( $payload['absent_meta'] ?? null ) );
tool( 'session.close', [] );

echo "\n══ Restore the session ══\n";
$restored = AICOM_Admin::do_restore_session( $sid );
ok( 'restore replayed both backups', $restored === 2, "got $restored" );
ok( 'image without alt has no alt again', alt_of( $no_alt ) === null, var_export( alt_of( $no_alt ), true ) );
ok( 'image with alt has its old alt back', alt_of( $has_alt ) === 'IMG_1234', var_export( alt_of( $has_alt ), true ) );
clean_post_cache( $has_alt );
ok( 'title restored too', get_post( $has_alt )->post_title === 'IMG_1234', get_post( $has_alt )->post_title );

echo "\n══ Single-backup restore (backup.post.restore) ══\n";
tool( 'session.open', [ 'name' => 'Again', 'description' => 'Set alt then restore one backup' ] );
tool( 'a11y.set_image_alt', [ 'id' => $no_alt, 'alt' => 'Another description' ] );
$bid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}aicom_backups WHERE target_id = %s ORDER BY id DESC LIMIT 1", (string) $no_alt ) );
$r = tool( 'backup.post.restore', [ 'backup_id' => $bid, 'confirm' => true ] );
ok( 'backup.post.restore ok', ( $r['restored'] ?? false ) === true, json_encode( $r ) );
ok( 'alt removed again', alt_of( $no_alt ) === null, var_export( alt_of( $no_alt ), true ) );
tool( 'session.close', [] );

echo "\n══ AICOMBase sees both tools as reversible ══\n";
$caps = AICOM_Base_Capabilities::build();
$rev  = [];
foreach ( $caps as $c ) { foreach ( (array) ( $c['tools'] ?? [] ) as $t ) { $rev[ $t['name'] ?? $t['id'] ?? '' ] = $t['reversible'] ?? null; } }
ok( 'a11y.set_image_alt reversible', ( $rev['a11y.set_image_alt'] ?? null ) === true, var_export( $rev['a11y.set_image_alt'] ?? 'missing', true ) );
ok( 'media.update_meta reversible', ( $rev['media.update_meta'] ?? null ) === true, var_export( $rev['media.update_meta'] ?? 'missing', true ) );

// Cleanup
wp_delete_post( $no_alt, true ); wp_delete_post( $has_alt, true );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}aicom_backups WHERE api_key_id = %d", $key['id'] ) );
$wpdb->delete( $wpdb->prefix . 'aicom_sessions', [ 'api_key_id' => $key['id'] ] );
AICOM_Auth::revoke_key( $key['id'] );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
