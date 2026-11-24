<?php
/**
 * Test: media.set_featured inside a session keeps the post's previous featured image in the session backup, so
 * Restore session puts it back — or removes it when the post had none — and the call reports undo.complete
 * (AICOMBase, 2026-11-24: a featured image was approved and only the report said it couldn't be undone).
 * Also: id 0 removes the featured image, and that is undone the same way.
 * Run: docker compose exec -T wp php wp-content/plugins/aicom/tests/test-featured-image-restore.php --allow-root
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
function thumb_of( int $post_id ) { wp_cache_delete( $post_id, 'post_meta' ); return metadata_exists( 'post', $post_id, '_thumbnail_id' ) ? (int) get_post_meta( $post_id, '_thumbnail_id', true ) : null; }
function image( string $name ): int {
    $id = wp_insert_post( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => $name, 'post_mime_type' => 'image/jpeg' ] );
    update_post_meta( $id, '_wp_attached_file', "aicom-test/$name.jpg" ); // set_post_thumbnail wants an attachment WordPress can show
    return $id;
}

$key = AICOM_Auth::create_key( 'Featured Image Restore Test', [ 'read.wp', 'write.wp.posts', 'manage.media', 'manage.backups' ] );
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key['plain_key'];

$img_a   = image( 'featured-a' );
$img_b   = image( 'featured-b' );
$no_img  = wp_insert_post( [ 'post_title' => 'Post without a featured image', 'post_status' => 'draft' ] );
$has_img = wp_insert_post( [ 'post_title' => 'Post with a featured image', 'post_status' => 'draft' ] );
set_post_thumbnail( $has_img, $img_a );

echo "\n══ Session: set and remove featured images ══\n";
$r = tool( 'session.open', [ 'name' => 'Featured images', 'description' => 'Set a featured image on one post, change it on another, then remove one' ] );
$sid = (int) ( $r['session_id'] ?? 0 );
ok( 'session opened', $sid > 0, json_encode( $r ) );

$r = tool( 'media.set_featured', [ 'id' => $img_b, 'post_id' => $no_img, 'dry_run' => true ] );
ok( 'dry run says what it would do and that it can be undone', ( $r['would_set_featured']['previous_featured_image_id'] ?? null ) === 0 && ( $r['undo']['complete'] ?? null ) === true, json_encode( $r ) );
ok( '... and changes nothing', thumb_of( $no_img ) === null );

$r = tool( 'media.set_featured', [ 'id' => $img_b, 'post_id' => $no_img ] );
ok( 'set on a post without one', ( $r['verified'] ?? false ) === true && thumb_of( $no_img ) === $img_b, json_encode( $r ) );
ok( '... reports there was none before', ( $r['previous_featured_image_id'] ?? null ) === 0, json_encode( $r ) );
ok( '... and undo.complete with its backup', ( $r['undo']['complete'] ?? null ) === true && ( $r['undo']['backup_id'] ?? 0 ) > 0, json_encode( $r['undo'] ?? null ) );
$payload = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT payload_json FROM {$wpdb->prefix}aicom_backups WHERE id = %d", (int) ( $r['undo']['backup_id'] ?? 0 ) ) ), true );
ok( '... whose backup remembers the post had no featured image', in_array( '_thumbnail_id', (array) ( $payload['absent_meta'] ?? [] ), true ), json_encode( $payload['absent_meta'] ?? null ) );

$r = tool( 'media.set_featured', [ 'id' => $img_b, 'post_id' => $has_img ] );
ok( 'changed on a post that had one', thumb_of( $has_img ) === $img_b && ( $r['previous_featured_image_id'] ?? null ) === $img_a, json_encode( $r ) );

$r = tool( 'media.set_featured', [ 'id' => 0, 'post_id' => $has_img ] );
ok( 'id 0 removes it', ( $r['verified'] ?? false ) === true && thumb_of( $has_img ) === null, json_encode( $r ) );
ok( '... reporting the one it removed, with undo.complete', ( $r['previous_featured_image_id'] ?? null ) === $img_b && ( $r['undo']['complete'] ?? null ) === true, json_encode( $r ) );

$r = tool( 'media.set_featured', [ 'id' => 0, 'post_id' => $no_img + 100000 ] );
ok( 'a post that does not exist is an error', ( $r['isError'] ?? false ) === true || isset( $r['error'] ), json_encode( $r ) );
$r = tool( 'media.set_featured', [ 'post_id' => $no_img ] );
ok( 'a missing id is still an error (not taken as 0)', ( $r['isError'] ?? false ) === true || isset( $r['error'] ), json_encode( $r ) );
ok( '... and changed nothing', thumb_of( $no_img ) === $img_b );
tool( 'session.close', [] );

echo "\n══ Restore the session ══\n";
$restored = AICOM_Admin::do_restore_session( $sid );
// 4: the router also snapshots the post before the call without an id, which the tool then rejects — harmless.
ok( 'restore replayed the backups', $restored === 4, "got $restored" );
ok( 'the post without a featured image has none again', thumb_of( $no_img ) === null, var_export( thumb_of( $no_img ), true ) );
ok( 'the post with one has its original back', thumb_of( $has_img ) === $img_a, var_export( thumb_of( $has_img ), true ) );

echo "\n══ Single-backup restore (backup.post.restore) ══\n";
tool( 'session.open', [ 'name' => 'Again', 'description' => 'Remove a featured image then restore one backup' ] );
$r = tool( 'media.set_featured', [ 'id' => 0, 'post_id' => $has_img ] );
ok( 'removed again', thumb_of( $has_img ) === null, json_encode( $r ) );
$r = tool( 'backup.post.restore', [ 'backup_id' => (int) ( $r['undo']['backup_id'] ?? 0 ), 'confirm' => true ] );
ok( 'backup.post.restore ok', ( $r['restored'] ?? false ) === true, json_encode( $r ) );
ok( 'featured image back', thumb_of( $has_img ) === $img_a, var_export( thumb_of( $has_img ), true ) );
tool( 'session.close', [] );

echo "\n══ AICOMBase sees the tool as reversible ══\n";
$caps = AICOM_Base_Capabilities::build();
$rev  = [];
foreach ( $caps as $c ) { foreach ( (array) ( $c['tools'] ?? [] ) as $t ) { $rev[ $t['name'] ?? $t['id'] ?? '' ] = $t['reversible'] ?? null; } }
ok( 'media.set_featured reversible', ( $rev['media.set_featured'] ?? null ) === true, var_export( $rev['media.set_featured'] ?? 'missing', true ) );

// Cleanup
foreach ( [ $no_img, $has_img, $img_a, $img_b ] as $id ) { wp_delete_post( $id, true ); }
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}aicom_backups WHERE api_key_id = %d", $key['id'] ) );
$wpdb->delete( $wpdb->prefix . 'aicom_sessions', [ 'api_key_id' => $key['id'] ] );
AICOM_Auth::revoke_key( $key['id'] );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
