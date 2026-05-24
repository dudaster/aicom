<?php
/**
 * Seeds demo sessions for UI preview.
 * Run: docker compose exec -T wp php wp-content/plugins/aicom/tests/seed-demo-sessions.php --allow-root
 */

define( 'DOING_AJAX', true );
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';

require_once '/var/www/html/wp-load.php';
wp_set_current_user( 1 );

global $wpdb;

// ── Create a demo API key ──────────────────────────────────────────────────
$key    = AICOM_Auth::create_key( 'OpenClaw Production', [
    'read.wp', 'write.wp.posts', 'manage.backups', 'manage.taxonomies',
] );
$key_id = $key['id'];
echo "Created API key ID: $key_id\n";

function fake_log( int $key_id, int $session_id, string $tool, string $status, int $ms, string $time, ?string $target_type = null, ?string $target_id = null ): void {
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'aicom_logs', [
        'created_at'    => $time,
        'request_id'    => wp_generate_uuid4(),
        'session_id'    => $session_id,
        'remote_ip'     => '192.168.1.100',
        'api_key_id'    => $key_id,
        'api_key_label' => 'OpenClaw Production',
        'tool_name'     => $tool,
        'module'        => explode( '.', $tool )[0],
        'status'        => $status,
        'http_status'   => 200,
        'duration_ms'   => $ms,
        'target_type'   => $target_type,
        'target_id'     => $target_id,
    ] );
}

function fake_backup( int $key_id, int $session_id, int $post_id, string $time ): void {
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'aicom_backups', [
        'created_at'   => $time,
        'session_id'   => $session_id,
        'api_key_id'   => $key_id,
        'tool_name'    => 'backup.post.create',
        'target_type'  => 'post',
        'target_id'    => (string) $post_id,
        'manifest_json'=> wp_json_encode( [ 'post_id' => $post_id ] ),
        'payload_json' => wp_json_encode( [ 'post' => [ 'ID' => $post_id, 'post_title' => 'Demo Post ' . $post_id, 'post_content' => 'Original content.' ], 'meta' => [], 'terms' => [] ] ),
    ] );
}

// ── Session 1: closed, 7 days ago ─────────────────────────────────────────
$wpdb->insert( $wpdb->prefix . 'aicom_sessions', [
    'api_key_id'    => $key_id,
    'api_key_label' => 'OpenClaw Production',
    'name'          => 'Update homepage hero section',
    'description'   => 'Rewrite hero title and CTA buttons for Q2 campaign',
    'status'        => 'closed',
    'opened_at'     => gmdate( 'Y-m-d H:i:s', strtotime( '-7 days -2 hours' ) ),
    'closed_at'     => gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) ),
] );
$s1 = (int) $wpdb->insert_id;
echo "Session 1 (closed, 7d ago): $s1\n";

$base1 = strtotime( '-7 days -2 hours' );
fake_log( $key_id, $s1, 'session.open',       'success', 12,  gmdate( 'Y-m-d H:i:s', $base1 ) );
fake_log( $key_id, $s1, 'wp.posts.get',       'success', 38,  gmdate( 'Y-m-d H:i:s', $base1 + 60 ) );
fake_log( $key_id, $s1, 'backup.post.create', 'success', 45,  gmdate( 'Y-m-d H:i:s', $base1 + 120 ), 'post', '1' );
fake_log( $key_id, $s1, 'wp.posts.update',    'success', 210, gmdate( 'Y-m-d H:i:s', $base1 + 180 ), 'post', '1' );
fake_log( $key_id, $s1, 'wp.posts.update',    'success', 188, gmdate( 'Y-m-d H:i:s', $base1 + 240 ), 'post', '1' );
fake_log( $key_id, $s1, 'session.close',      'success', 8,   gmdate( 'Y-m-d H:i:s', $base1 + 300 ) );
fake_backup( $key_id, $s1, 1, gmdate( 'Y-m-d H:i:s', $base1 + 110 ) );

// ── Session 2: closed, 3 days ago ─────────────────────────────────────────
$wpdb->insert( $wpdb->prefix . 'aicom_sessions', [
    'api_key_id'    => $key_id,
    'api_key_label' => 'OpenClaw Production',
    'name'          => 'Add WooCommerce product descriptions',
    'description'   => 'Fill in missing product descriptions for 3 products',
    'status'        => 'closed',
    'opened_at'     => gmdate( 'Y-m-d H:i:s', strtotime( '-3 days -3 hours' ) ),
    'closed_at'     => gmdate( 'Y-m-d H:i:s', strtotime( '-3 days -1 hour' ) ),
] );
$s2 = (int) $wpdb->insert_id;
echo "Session 2 (closed, 3d ago): $s2\n";

$base2 = strtotime( '-3 days -3 hours' );
fake_log( $key_id, $s2, 'session.open',       'success', 10,  gmdate( 'Y-m-d H:i:s', $base2 ) );
fake_log( $key_id, $s2, 'wp.posts.list',      'success', 67,  gmdate( 'Y-m-d H:i:s', $base2 + 30 ) );
fake_log( $key_id, $s2, 'backup.post.create', 'success', 52,  gmdate( 'Y-m-d H:i:s', $base2 + 300 ), 'post', '12' );
fake_log( $key_id, $s2, 'wp.posts.update',    'success', 198, gmdate( 'Y-m-d H:i:s', $base2 + 360 ), 'post', '12' );
fake_log( $key_id, $s2, 'backup.post.create', 'success', 48,  gmdate( 'Y-m-d H:i:s', $base2 + 900 ), 'post', '13' );
fake_log( $key_id, $s2, 'wp.posts.update',    'success', 215, gmdate( 'Y-m-d H:i:s', $base2 + 960 ), 'post', '13' );
fake_log( $key_id, $s2, 'backup.post.create', 'success', 51,  gmdate( 'Y-m-d H:i:s', $base2 + 1500 ), 'post', '14' );
fake_log( $key_id, $s2, 'wp.posts.update',    'success', 203, gmdate( 'Y-m-d H:i:s', $base2 + 1560 ), 'post', '14' );
fake_log( $key_id, $s2, 'wp.posts.update',    'error',   145, gmdate( 'Y-m-d H:i:s', $base2 + 2000 ), 'post', '15' );
fake_log( $key_id, $s2, 'session.close',      'success', 9,   gmdate( 'Y-m-d H:i:s', $base2 + 2100 ) );

fake_backup( $key_id, $s2, 12, gmdate( 'Y-m-d H:i:s', $base2 + 290 ) );
fake_backup( $key_id, $s2, 13, gmdate( 'Y-m-d H:i:s', $base2 + 890 ) );
fake_backup( $key_id, $s2, 14, gmdate( 'Y-m-d H:i:s', $base2 + 1490 ) );

// ── Session 3: closed, today, this morning ────────────────────────────────
$wpdb->insert( $wpdb->prefix . 'aicom_sessions', [
    'api_key_id'    => $key_id,
    'api_key_label' => 'OpenClaw Production',
    'name'          => 'Fix SEO titles on blog posts',
    'description'   => '',
    'status'        => 'closed',
    'opened_at'     => gmdate( 'Y-m-d H:i:s', strtotime( '-4 hours' ) ),
    'closed_at'     => gmdate( 'Y-m-d H:i:s', strtotime( '-3 hours' ) ),
] );
$s3 = (int) $wpdb->insert_id;
echo "Session 3 (closed, today): $s3\n";

$base3 = strtotime( '-4 hours' );
fake_log( $key_id, $s3, 'session.open',   'success', 11, gmdate( 'Y-m-d H:i:s', $base3 ) );
fake_log( $key_id, $s3, 'wp.posts.list',  'success', 72, gmdate( 'Y-m-d H:i:s', $base3 + 60 ) );
fake_log( $key_id, $s3, 'wp.posts.update','success', 134, gmdate( 'Y-m-d H:i:s', $base3 + 120 ), 'post', '3' );
fake_log( $key_id, $s3, 'wp.posts.update','success', 141, gmdate( 'Y-m-d H:i:s', $base3 + 180 ), 'post', '4' );
fake_log( $key_id, $s3, 'wp.posts.update','success', 128, gmdate( 'Y-m-d H:i:s', $base3 + 240 ), 'post', '5' );
fake_log( $key_id, $s3, 'session.close',  'success', 8,  gmdate( 'Y-m-d H:i:s', $base3 + 300 ) );

// ── Session 4: ACTIVE right now ───────────────────────────────────────────
$wpdb->insert( $wpdb->prefix . 'aicom_sessions', [
    'api_key_id'    => $key_id,
    'api_key_label' => 'OpenClaw Production',
    'name'          => 'Translate blog posts to Romanian',
    'description'   => 'Translating latest 5 posts for the RO market launch',
    'status'        => 'open',
    'opened_at'     => gmdate( 'Y-m-d H:i:s', strtotime( '-25 minutes' ) ),
    'closed_at'     => null,
] );
$s4 = (int) $wpdb->insert_id;
echo "Session 4 (ACTIVE): $s4\n";

$base4 = strtotime( '-25 minutes' );
fake_log( $key_id, $s4, 'session.open',        'success', 11,  gmdate( 'Y-m-d H:i:s', $base4 ) );
fake_log( $key_id, $s4, 'wp.posts.list',       'success', 74,  gmdate( 'Y-m-d H:i:s', $base4 + 60 ) );
fake_log( $key_id, $s4, 'backup.post.create',  'success', 49,  gmdate( 'Y-m-d H:i:s', $base4 + 120 ), 'post', '6' );
fake_log( $key_id, $s4, 'wp.posts.update',     'success', 189, gmdate( 'Y-m-d H:i:s', $base4 + 180 ), 'post', '6' );
fake_log( $key_id, $s4, 'backup.post.create',  'success', 47,  gmdate( 'Y-m-d H:i:s', $base4 + 600 ), 'post', '7' );
fake_log( $key_id, $s4, 'wp.posts.update',     'success', 201, gmdate( 'Y-m-d H:i:s', $base4 + 660 ), 'post', '7' );
fake_backup( $key_id, $s4, 6, gmdate( 'Y-m-d H:i:s', $base4 + 110 ) );
fake_backup( $key_id, $s4, 7, gmdate( 'Y-m-d H:i:s', $base4 + 590 ) );

echo "\nDone! Demo key ID=$key_id. Visit: http://localhost:8080/wp-admin/admin.php?page=aicom-audit-logs\n";
