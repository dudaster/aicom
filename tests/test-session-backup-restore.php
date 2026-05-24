<?php
/**
 * Test: 3 sessions (create / update / delete) + backup restore verification.
 * Run: docker compose exec -T wp php wp-content/plugins/aicom/tests/test-session-backup-restore.php --allow-root
 */

define( 'DOING_AJAX', true );
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require_once '/var/www/html/wp-load.php';
wp_set_current_user( 1 );

global $wpdb;

// ── Helpers ────────────────────────────────────────────────────────────────

$pass = 0;
$fail = 0;

function ok( string $label, bool $cond, string $detail = '' ): void {
    global $pass, $fail;
    if ( $cond ) {
        echo "  ✓ $label\n";
        $pass++;
    } else {
        echo "  ✗ $label" . ( $detail ? " → $detail" : '' ) . "\n";
        $fail++;
    }
}

function tool( string $name, array $args ): array {
    $r = AICOM_Tool_Router::dispatch( json_encode( [
        'jsonrpc' => '2.0',
        'method'  => 'tools/call',
        'params'  => [ 'name' => $name, 'arguments' => $args ],
        'id'      => 1,
    ] ) );
    return $r['result'] ?? $r;
}

function is_err( array $r ): bool {
    return isset( $r['error'] );
}

function sep( string $title ): void {
    echo "\n══ $title ══\n";
}

// ── Setup ──────────────────────────────────────────────────────────────────

$key = AICOM_Auth::create_key( 'Session Backup Test', [
    'read.wp', 'write.wp.posts', 'delete.wp.posts', 'manage.backups',
] );
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key['plain_key'];
echo "API key: " . $key['id'] . "\n";

// ══ SESSION 1: Create a new post ══════════════════════════════════════════

sep( 'Session 1 — Create' );

$r = tool( 'session.open', [ 'name' => 'Add new campaign post', 'description' => 'Creating a fresh blog post for the Q3 campaign' ] );
ok( 'session.open returns name', ( $r['name'] ?? '' ) === 'Add new campaign post', json_encode( $r ) );
$s1_id = $r['session_id'] ?? 0;

$r = tool( 'wp.posts.create', [
    'post_title'   => 'Campaign Launch Post',
    'post_content' => 'Original content: The Q3 campaign starts today.',
    'post_status'  => 'draft',
    'post_type'    => 'post',
] );
ok( 'wp.posts.create returns id', isset( $r['id'] ) && $r['id'] > 0, json_encode( $r ) );
$post_id = (int) ( $r['id'] ?? 0 );
echo "  → post_id=$post_id\n";

$r = tool( 'session.close', [] );
ok( 'session.close', ( $r['closed'] ?? false ) === true, json_encode( $r ) );

// ── Verify post was created ────────────────────────────────────────────────
$post = get_post( $post_id );
ok( 'post exists in DB', $post !== null );
ok( 'post title correct', ( $post->post_title ?? '' ) === 'Campaign Launch Post' );
ok( 'post status is draft', ( $post->post_status ?? '' ) === 'draft' );

// Session 1 has no backup (no pre-existing post to back up before creation).

// ══ SESSION 2: Update the post ════════════════════════════════════════════

sep( 'Session 2 — Update' );

$r = tool( 'session.open', [ 'name' => 'Update campaign post', 'description' => 'Rewriting headline and body' ] );
ok( 'session.open', ( $r['name'] ?? '' ) === 'Update campaign post', json_encode( $r ) );
$s2_id = $r['session_id'] ?? 0;

$r = tool( 'backup.post.create', [ 'post_id' => $post_id, 'note' => 'before-update' ] );
ok( 'backup.post.create before update', isset( $r['backup_id'] ) && $r['backup_id'] > 0, json_encode( $r ) );
$s2_backup_id = (int) ( $r['backup_id'] ?? 0 );

$r = tool( 'wp.posts.update', [
    'id'           => $post_id,
    'post_title'   => 'Campaign Launch Post (UPDATED)',
    'post_content' => 'UPDATED content: Rewritten by agent.',
] );
ok( 'wp.posts.update', isset( $r['id'] ), json_encode( $r ) );

$r = tool( 'session.close', [] );
ok( 'session.close', ( $r['closed'] ?? false ) === true );

// ── Verify update applied ──────────────────────────────────────────────────
clean_post_cache( $post_id );
$post = get_post( $post_id );
ok( 'title changed in DB', ( $post->post_title ?? '' ) === 'Campaign Launch Post (UPDATED)' );
ok( 'content changed in DB', strpos( $post->post_content ?? '', 'UPDATED' ) !== false );

// ── Test restore of Session 2 ──────────────────────────────────────────────
sep( 'Restore Session 2 (rollback update)' );

$restored = AICOM_Admin::do_restore_session( $s2_id );
ok( 'restore returned >= 1 backup', $restored >= 1, "got: $restored" );

clean_post_cache( $post_id );
$post = get_post( $post_id );
ok( 'title rolled back', ( $post->post_title ?? '' ) === 'Campaign Launch Post', 'got: ' . ( $post->post_title ?? '?' ) );
ok( 'content rolled back', strpos( $post->post_content ?? '', 'Original content' ) !== false, 'got: ' . substr( $post->post_content ?? '', 0, 80 ) );

// ══ SESSION 3: Delete the post ════════════════════════════════════════════

sep( 'Session 3 — Delete' );

$r = tool( 'session.open', [ 'name' => 'Delete campaign post', 'description' => 'Removing the draft after campaign was cancelled' ] );
ok( 'session.open', ( $r['name'] ?? '' ) === 'Delete campaign post', json_encode( $r ) );
$s3_id = $r['session_id'] ?? 0;

$r = tool( 'backup.post.create', [ 'post_id' => $post_id, 'note' => 'before-delete' ] );
ok( 'backup.post.create before delete', isset( $r['backup_id'] ) && $r['backup_id'] > 0, json_encode( $r ) );

$r = tool( 'wp.posts.delete', [ 'id' => $post_id, 'confirm' => true ] );
ok( 'wp.posts.delete', ( $r['deleted'] ?? false ) === true, json_encode( $r ) );

$r = tool( 'session.close', [] );
ok( 'session.close', ( $r['closed'] ?? false ) === true );

// ── Verify post is gone ────────────────────────────────────────────────────
clean_post_cache( $post_id );
$post = get_post( $post_id );
ok( 'post is null after permanent delete', $post === null, 'status: ' . ( $post->post_status ?? '?' ) );

// ── Test restore of Session 3 ──────────────────────────────────────────────
sep( 'Restore Session 3 (restore deleted post)' );

$restored = AICOM_Admin::do_restore_session( $s3_id );
ok( 'restore returned >= 1 backup', $restored >= 1, "got: $restored" );

clean_post_cache( $post_id );
$post = get_post( $post_id );
ok( 'post re-exists after restore', $post !== null, 'status: ' . ( $post ? $post->post_status : 'null' ) );
ok( 'restored title is original', ( $post->post_title ?? '' ) === 'Campaign Launch Post', 'got: ' . ( $post->post_title ?? '?' ) );
ok( 'restored content is original', strpos( $post->post_content ?? '', 'Original content' ) !== false, 'got: ' . substr( $post->post_content ?? '', 0, 80 ) );

// ── Cleanup ────────────────────────────────────────────────────────────────

sep( 'Cleanup' );

if ( $post_id ) {
    wp_delete_post( $post_id, true );
}
$wpdb->delete( $wpdb->prefix . 'aicom_sessions', [ 'api_key_id' => $key['id'] ] );
AICOM_Auth::revoke_key( $key['id'] );
echo "  Cleaned up key={$key['id']}, post_id=$post_id\n";

// ── Summary ────────────────────────────────────────────────────────────────

echo "\n";
echo "══════════════════════════════════════\n";
echo "  PASS: $pass  FAIL: $fail\n";
echo "══════════════════════════════════════\n";
if ( $fail === 0 ) {
    echo "  ALL TESTS PASSED\n";
} else {
    echo "  SOME TESTS FAILED\n";
    exit( 1 );
}
