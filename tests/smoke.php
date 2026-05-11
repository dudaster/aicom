<?php
/**
 * AICOM smoke test suite — full CRUD round-trips across all core modules.
 *
 * Run: docker compose exec -T wp php /var/www/html/wp-content/plugins/aicom/tests/smoke.php --allow-root
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

/**
 * Call a tool via MCP dispatch, return the flat result data.
 * ok() merges data flat; result lands in $r['result'] for jsonrpc format.
 */
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

// ── Create API key with all scopes ─────────────────────────────────────────

// Clean up any leftover terms from crashed previous runs
$stale = get_term_by( 'name', 'Smoke Test Category', 'category' );
if ( $stale ) wp_delete_term( $stale->term_id, 'category' );

$uid = substr( uniqid(), -6 );

$key = AICOM_Auth::create_key( 'Smoke Test', [
    'read.wp', 'write.wp.posts', 'delete.wp.posts',
    'manage.taxonomies', 'manage.meta', 'manage.elementor',
    'manage.yoast', 'manage.media', 'read.users', 'manage.users',
    'manage.backups', 'manage.wordpress.settings', 'manage.menus',
    'manage.roles',
] );
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key['plain_key'];
$key_id        = $key['id'];
$cleanup_posts = [];
$cleanup_terms = [];

// ═══════════════════════════════════════════════════════════════════════════
// 1. Discovery / Public
// ═══════════════════════════════════════════════════════════════════════════

section( 'server.status' );
$r = tool( 'server.status', [] );
// ok() flat-merges data, so keys are top-level; 'server' sub-object is the top key
ok( 'has server sub-object', isset( $r['server'] ) && is_array( $r['server'] ), json_encode( $r ) );
ok( 'has lock state', isset( $r['lock'] ) );
ok( 'has modules map', isset( $r['modules'] ) && is_array( $r['modules'] ) );
ok( 'version matches AICOM_VERSION', ( $r['server']['version'] ?? '' ) === AICOM_VERSION );

section( 'wp.site.info' );
$r = tool( 'wp.site.info', [] );
ok( 'returns name', ! empty( $r['name'] ) );
ok( 'returns wordpress_version', ! empty( $r['wordpress_version'] ), json_encode( $r ) );
ok( 'returns url', ! empty( $r['url'] ), json_encode( $r ) );

section( 'wp.post_types.list' );
$r = tool( 'wp.post_types.list', [] );
// response: { post_types: [...], _meta: {...} }
ok( 'returns post_types array', isset( $r['post_types'] ) && count( $r['post_types'] ) > 0 );
$slugs = array_column( $r['post_types'] ?? [], 'slug' );
ok( 'includes post', in_array( 'post', $slugs ) );
ok( 'includes page', in_array( 'page', $slugs ) );

section( 'wp.taxonomies.list' );
$r = tool( 'wp.taxonomies.list', [] );
// response: { taxonomies: [...], _meta: {...} }
ok( 'returns taxonomies array', isset( $r['taxonomies'] ) && count( $r['taxonomies'] ) > 0 );
$tax_slugs = array_column( $r['taxonomies'] ?? [], 'slug' );
ok( 'includes category', in_array( 'category', $tax_slugs ) );
ok( 'includes post_tag', in_array( 'post_tag', $tax_slugs ) );

// ═══════════════════════════════════════════════════════════════════════════
// 2. Sessions — enforcement + lifecycle
// ═══════════════════════════════════════════════════════════════════════════

section( 'session enforcement: write without session → error' );
$r = tool( 'wp.posts.create', [ 'post_title' => 'Should fail', 'post_type' => 'post', 'post_status' => 'draft' ] );
ok( 'returns NO_ACTIVE_SESSION error', ( $r['error']['code'] ?? '' ) === 'NO_ACTIVE_SESSION', json_encode( $r ) );

section( 'session.open' );
$r = tool( 'session.open', [ 'name' => 'Smoke Test Session', 'description' => 'Automated smoke tests' ] );
ok( 'no error', ! is_err( $r ), json_encode( $r ) );
ok( 'returns session_id', isset( $r['session_id'] ) && $r['session_id'] > 0, json_encode( $r ) );
ok( 'returns name', ( $r['name'] ?? '' ) === 'Smoke Test Session' );
ok( 'returns opened_at', ! empty( $r['opened_at'] ) );
$smoke_session_id = $r['session_id'] ?? 0;

section( 'session.open again → SESSION_ALREADY_OPEN' );
$r = tool( 'session.open', [ 'name' => 'Duplicate session' ] );
ok( 'returns SESSION_ALREADY_OPEN', ( $r['error']['code'] ?? '' ) === 'SESSION_ALREADY_OPEN', json_encode( $r ) );

// ═══════════════════════════════════════════════════════════════════════════
// 3. Posts — full CRUD + preview_url
// ═══════════════════════════════════════════════════════════════════════════

section( 'wp.posts.create' );
$r = tool( 'wp.posts.create', [
    'post_type'    => 'post',
    'post_title'   => 'Smoke Test Post',
    'post_status'  => 'draft',
    'post_name'    => 'smoke-test-post-' . $uid,
    'post_excerpt' => 'A test excerpt',
    'post_content' => 'Hello smoke test',
] );
ok( 'no error', ! is_err( $r ), json_encode( $r ) );
ok( 'returns id', isset( $r['id'] ) && $r['id'] > 0, json_encode( $r ) );
$post_id = $r['id'] ?? 0;
if ( $post_id ) $cleanup_posts[] = $post_id;

section( 'wp.posts.get' );
// response keys: id, title, content, excerpt, status, post_type, slug, author, terms
$r = tool( 'wp.posts.get', [ 'id' => $post_id ] );
ok( 'no error', ! is_err( $r ), json_encode( $r ) );
ok( 'title matches', ( $r['title'] ?? '' ) === 'Smoke Test Post', $r['title'] ?? '?' );
ok( 'slug matches', ( $r['slug'] ?? '' ) === 'smoke-test-post-' . $uid, $r['slug'] ?? '?' );
ok( 'excerpt matches', ( $r['excerpt'] ?? '' ) === 'A test excerpt', $r['excerpt'] ?? '?' );
ok( 'status is draft', ( $r['status'] ?? '' ) === 'draft', $r['status'] ?? '?' );
ok( 'terms map present', isset( $r['terms'] ) && is_array( $r['terms'] ) );
ok( 'author > 0', ( $r['author'] ?? 0 ) > 0, 'author=' . ( $r['author'] ?? 0 ) );

section( 'wp.posts.update' );
$r = tool( 'wp.posts.update', [
    'id'           => $post_id,
    'post_title'   => 'Smoke Test Post Updated',
    'post_name'    => 'smoke-test-post-upd-' . $uid,
    'post_author'  => 1,
    'post_excerpt' => 'Updated excerpt',
] );
ok( 'no error', ! is_err( $r ), json_encode( $r ) );
ok( 'updated:true', ! empty( $r['updated'] ), json_encode( $r ) );

$p = get_post( $post_id );
ok( 'title updated in DB', $p->post_title === 'Smoke Test Post Updated', $p->post_title );
ok( 'slug updated in DB', $p->post_name === 'smoke-test-post-upd-' . $uid, $p->post_name );
ok( 'excerpt updated in DB', $p->post_excerpt === 'Updated excerpt', $p->post_excerpt );
ok( 'author=1 in DB', (int) $p->post_author === 1, 'author=' . $p->post_author );

section( 'wp.posts.list' );
// response: { posts: [...], total: N, total_pages: N }
$r = tool( 'wp.posts.list', [ 'post_type' => 'post', 'posts_per_page' => 5 ] );
ok( 'posts key exists', isset( $r['posts'] ) && is_array( $r['posts'] ), json_encode( array_slice( $r['posts'] ?? [], 0, 1 ) ) );
ok( 'total key exists', isset( $r['total'] ) && $r['total'] > 0 );

section( 'wp.posts.preview_url (draft)' );
$r = tool( 'wp.posts.preview_url', [ 'post_id' => $post_id ] );
ok( 'no error', ! is_err( $r ), json_encode( $r ) );
ok( 'status=draft', ( $r['post_status'] ?? '' ) === 'draft' );
ok( 'preview_url has preview', strpos( $r['preview_url'] ?? '', 'preview' ) !== false, $r['preview_url'] ?? '' );
ok( 'admin_edit_url present', strpos( $r['admin_edit_url'] ?? '', 'action=edit' ) !== false );

section( 'wp.posts.preview_url (published)' );
wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );
$r = tool( 'wp.posts.preview_url', [ 'post_id' => $post_id ] );
ok( 'no error', ! is_err( $r ), json_encode( $r ) );
ok( 'status=publish', ( $r['post_status'] ?? '' ) === 'publish' );
$expected_url = get_permalink( $post_id );
ok( 'preview_url = permalink', ( $r['preview_url'] ?? '' ) === $expected_url, ( $r['preview_url'] ?? '?' ) . ' vs ' . $expected_url );

section( 'wp.posts.trash + restore' );
wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );
$r = tool( 'wp.posts.trash', [ 'id' => $post_id ] );
ok( 'trash: no error', ! is_err( $r ), json_encode( $r ) );
ok( 'trash: trashed=true', ! empty( $r['trashed'] ) );
ok( 'status is trash in DB', get_post_status( $post_id ) === 'trash' );

$r = tool( 'wp.posts.restore', [ 'id' => $post_id ] );
ok( 'restore: no error', ! is_err( $r ), json_encode( $r ) );
ok( 'restore: restored=true', ! empty( $r['restored'] ) );

// ═══════════════════════════════════════════════════════════════════════════
// 3. Terms — CRUD
// ═══════════════════════════════════════════════════════════════════════════

section( 'wp.terms.create + assign + remove + delete' );
$term_uid = 'smoke-' . substr( uniqid(), -6 );
$r = tool( 'wp.terms.create', [ 'taxonomy' => 'category', 'name' => 'Smoke Test Cat ' . $term_uid, 'slug' => $term_uid ] );
ok( 'create: no error', ! is_err( $r ), json_encode( $r ) );
// response: { term_id: N, taxonomy: ..., name: ... }
ok( 'create: returns term_id', isset( $r['term_id'] ) && $r['term_id'] > 0, json_encode( $r ) );
$cat_id = $r['term_id'] ?? 0;
if ( $cat_id ) $cleanup_terms[] = [ 'id' => $cat_id, 'tax' => 'category' ];

$r = tool( 'wp.terms.get', [ 'taxonomy' => 'category', 'term_id' => $cat_id ] );
ok( 'get: no error', ! is_err( $r ), json_encode( $r ) );
ok( 'get: name matches', ( $r['name'] ?? '' ) === 'Smoke Test Cat ' . $term_uid, $r['name'] ?? '?' );

$r = tool( 'wp.terms.assign_to_post', [ 'post_id' => $post_id, 'taxonomy' => 'category', 'term_ids' => [ $cat_id ] ] );
ok( 'assign: no error', ! is_err( $r ), json_encode( $r ) );

$r = tool( 'wp.posts.get', [ 'id' => $post_id ] );
$post_cats = array_column( $r['terms']['category'] ?? [], 'id' );
ok( 'category appears in post terms', in_array( $cat_id, $post_cats ), json_encode( $post_cats ) );

$r = tool( 'wp.terms.remove_from_post', [ 'post_id' => $post_id, 'taxonomy' => 'category', 'term_ids' => [ $cat_id ] ] );
ok( 'remove: no error', ! is_err( $r ), json_encode( $r ) );

$r = tool( 'wp.terms.update', [ 'taxonomy' => 'category', 'term_id' => $cat_id, 'name' => 'Smoke Test Cat Renamed' ] );
ok( 'update: no error', ! is_err( $r ), json_encode( $r ) );
$term = get_term( $cat_id, 'category' );
ok( 'name updated in DB', $term->name === 'Smoke Test Cat Renamed', $term->name );

$r = tool( 'wp.terms.delete', [ 'taxonomy' => 'category', 'term_id' => $cat_id, 'confirm' => true ] );
ok( 'delete: no error', ! is_err( $r ), json_encode( $r ) );
ok( 'term gone from DB', get_term( $cat_id, 'category' ) === null || is_wp_error( get_term( $cat_id, 'category' ) ) );
$cleanup_terms = array_filter( $cleanup_terms, fn( $t ) => $t['id'] !== $cat_id );

// ═══════════════════════════════════════════════════════════════════════════
// 4. Meta
// ═══════════════════════════════════════════════════════════════════════════

section( 'wp.meta.set / get / set_many / delete' );
$r = tool( 'wp.meta.set', [ 'post_id' => $post_id, 'meta_key' => 'smoke_key', 'meta_value' => 'hello' ] );
ok( 'set: no error', ! is_err( $r ), json_encode( $r ) );

$r = tool( 'wp.meta.get', [ 'post_id' => $post_id, 'meta_key' => 'smoke_key' ] );
// response: { post_id, meta_key, values: [...] }
ok( 'get: returns value in values[0]', ( $r['values'][0] ?? '' ) === 'hello', json_encode( $r ) );

$r = tool( 'wp.meta.set_many', [ 'post_id' => $post_id, 'meta' => [ 'smoke_a' => 'aaa', 'smoke_b' => 'bbb' ] ] );
ok( 'set_many: no error', ! is_err( $r ), json_encode( $r ) );
// response: { post_id, updated: ['smoke_a','smoke_b'] }
ok( 'set_many: 2 keys', count( $r['updated'] ?? [] ) === 2, json_encode( $r['updated'] ?? '?' ) );
ok( 'smoke_a in DB', get_post_meta( $post_id, 'smoke_a', true ) === 'aaa' );
ok( 'smoke_b in DB', get_post_meta( $post_id, 'smoke_b', true ) === 'bbb' );

$r = tool( 'wp.meta.delete', [ 'post_id' => $post_id, 'meta_key' => 'smoke_key' ] );
ok( 'delete: no error', ! is_err( $r ), json_encode( $r ) );
ok( 'key gone from DB', get_post_meta( $post_id, 'smoke_key', true ) === '' );

// ═══════════════════════════════════════════════════════════════════════════
// 5. Options
// ═══════════════════════════════════════════════════════════════════════════

section( 'wp.options.get / set' );
$r = tool( 'wp.options.get', [ 'option_name' => 'blogname' ] );
ok( 'get blogname: no error', ! is_err( $r ), json_encode( $r ) );
ok( 'returns value key', isset( $r['value'] ) );

$original_blogname = get_option( 'blogname' );
// wp.options.set uses 'option_value' param (not 'value')
$r = tool( 'wp.options.set', [ 'option_name' => 'blogname', 'option_value' => 'Smoke Test Site', 'confirm' => true ] );
ok( 'set: no error', ! is_err( $r ), json_encode( $r ) );
ok( 'updated in DB', get_option( 'blogname' ) === 'Smoke Test Site' );
update_option( 'blogname', $original_blogname );

// ═══════════════════════════════════════════════════════════════════════════
// 6. Users (read only)
// ═══════════════════════════════════════════════════════════════════════════

section( 'wp.users.list / get' );
$r = tool( 'wp.users.list', [ 'number' => 5 ] );
ok( 'list: returns array', is_array( $r ) && count( $r ) > 0, json_encode( array_slice( $r, 0, 1 ) ) );

$admin_id = $r[0]['id'] ?? 1;
$r = tool( 'wp.users.get', [ 'id' => $admin_id ] );
ok( 'get: no error', ! is_err( $r ), json_encode( $r ) );
ok( 'get: returns user_login', ! empty( $r['user_login'] ), json_encode( $r ) );

section( 'wp.roles.list' );
$r = tool( 'wp.roles.list', [] );
// response: { roles: [ { slug, name, capabilities: [...] }, ... ] }
ok( 'returns roles array', isset( $r['roles'] ) && count( $r['roles'] ) > 0 );
ok( 'includes administrator', in_array( 'administrator', array_column( $r['roles'] ?? [], 'slug' ) ) );

// ═══════════════════════════════════════════════════════════════════════════
// 7. Backup
// ═══════════════════════════════════════════════════════════════════════════

section( 'backup.post.create / list / delete' );
$r = tool( 'backup.post.create', [ 'post_id' => $post_id, 'note' => 'smoke-test-backup' ] );
ok( 'create: no error', ! is_err( $r ), json_encode( $r ) );
ok( 'create: returns backup_id', isset( $r['backup_id'] ) && $r['backup_id'] > 0, json_encode( $r ) );
$backup_id = $r['backup_id'] ?? 0;

// backup.list filters by target_type + target_id; response: { backups: [...], count: N }
$r = tool( 'backup.list', [ 'target_type' => 'post', 'target_id' => $post_id ] );
ok( 'list: no error', ! is_err( $r ), json_encode( $r ) );
ok( 'list: contains our backup', count( array_filter( $r['backups'] ?? [], fn( $b ) => (int)( $b['id'] ?? 0 ) === $backup_id ) ) === 1 );

if ( $backup_id ) {
    // backup.delete uses 'id' param (not 'backup_id')
    $r = tool( 'backup.delete', [ 'id' => $backup_id, 'confirm' => true ] );
    ok( 'delete: no error', ! is_err( $r ), json_encode( $r ) );
}

// ═══════════════════════════════════════════════════════════════════════════
// 8. Elementor — create_from_template + get_tree
// ═══════════════════════════════════════════════════════════════════════════

section( 'elementor.page.create_from_template' );
$elementor_data = json_encode( [
    [ 'id' => 'aaa', 'elType' => 'section', 'settings' => [], 'elements' => [
        [ 'id' => 'bbb', 'elType' => 'column', 'settings' => [], 'elements' => [
            [ 'id' => 'ccc', 'elType' => 'widget', 'widgetType' => 'heading',
              'settings' => [ 'title' => 'Smoke Heading' ], 'elements' => [] ],
        ] ],
    ] ],
] );

$src_page = wp_insert_post( [ 'post_title' => 'Source', 'post_status' => 'publish', 'post_type' => 'page' ] );
update_post_meta( $src_page, '_elementor_data', $elementor_data );
update_post_meta( $src_page, '_elementor_edit_mode', 'builder' );
update_post_meta( $src_page, '_wp_page_template', 'elementor_canvas' );
$cleanup_posts[] = $src_page;

$r = tool( 'elementor.page.create_from_template', [
    'source_post_id' => $src_page,
    'title'          => 'Clone Dry Run',
    'dry_run'        => true,
] );
ok( 'dry_run: no error', ! is_err( $r ), json_encode( $r ) );
ok( 'dry_run: dry_run=true', ! empty( $r['dry_run'] ) );
ok( 'dry_run: element_count=1', ( $r['element_count'] ?? 0 ) === 1 );

$r = tool( 'elementor.page.create_from_template', [
    'source_post_id' => $src_page,
    'title'          => 'Smoke Clone Page',
    'slug'           => 'smoke-clone-' . $uid,
    'status'         => 'draft',
] );
ok( 'create: no error', ! is_err( $r ), json_encode( $r ) );
ok( 'create: returns id', isset( $r['id'] ) && $r['id'] > 0, json_encode( $r ) );
$clone_id = $r['id'] ?? 0;
if ( $clone_id ) $cleanup_posts[] = $clone_id;
ok( 'create: slug matches', ( $r['slug'] ?? '' ) === 'smoke-clone-' . $uid );
ok( 'create: preview_url present', strpos( $r['preview_url'] ?? '', 'preview' ) !== false );
ok( 'create: edit_url has elementor', strpos( $r['edit_url'] ?? '', 'elementor' ) !== false );
ok( 'meta _elementor_data copied', get_post_meta( $clone_id, '_elementor_data', true ) === $elementor_data );
ok( 'meta _elementor_edit_mode copied', get_post_meta( $clone_id, '_elementor_edit_mode', true ) === 'builder' );
ok( 'meta _wp_page_template copied', get_post_meta( $clone_id, '_wp_page_template', true ) === 'elementor_canvas' );

if ( $clone_id ) {
    $r = tool( 'elementor.page.get_tree', [ 'post_id' => $clone_id ] );
    ok( 'get_tree: no error', ! is_err( $r ), json_encode( $r ) );
    ok( 'get_tree: tree is array', is_array( $r['tree'] ?? null ) );
}

// ═══════════════════════════════════════════════════════════════════════════
// 9. Yoast SEO (if active)
// ═══════════════════════════════════════════════════════════════════════════

section( 'yoast.status' );
$r = tool( 'yoast.status', [] );
if ( is_err( $r ) && strpos( $r['error']['code'] ?? '', 'DEPENDENCY' ) !== false ) {
    echo "  [SKIP] Yoast not active\n";
} else {
    ok( 'status: no error', ! is_err( $r ), json_encode( $r ) );
    ok( 'status: active=true', ! empty( $r['active'] ) );

    section( 'yoast.post.get / set' );
    $r = tool( 'yoast.post.get', [ 'post_id' => $post_id ] );
    ok( 'get: no error', ! is_err( $r ), json_encode( $r ) );
    // response keys: title, metadesc, focuskw, canonical, ...
    ok( 'get: has title key', array_key_exists( 'title', $r ) );

    $r = tool( 'yoast.post.set', [ 'post_id' => $post_id, 'title' => 'Smoke SEO Title', 'metadesc' => 'Smoke SEO desc' ] );
    ok( 'set: no error', ! is_err( $r ), json_encode( $r ) );
    ok( 'set: updated=true', ! empty( $r['updated'] ) );
    ok( 'title in DB', get_post_meta( $post_id, '_yoast_wpseo_title', true ) === 'Smoke SEO Title' );
    ok( 'metadesc in DB', get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ) === 'Smoke SEO desc' );

    section( 'yoast.site.get' );
    $r = tool( 'yoast.site.get', [] );
    ok( 'no error', ! is_err( $r ), json_encode( $r ) );
    // response keys: post_type_templates, taxonomy_templates, social_profiles
    ok( 'has post_type_templates', isset( $r['post_type_templates'] ) );
}

// ═══════════════════════════════════════════════════════════════════════════
// 10. Delete post (confirm required)
// ═══════════════════════════════════════════════════════════════════════════

section( 'wp.posts.delete (confirm required)' );
$del_post = wp_insert_post( [ 'post_title' => 'Delete Me', 'post_status' => 'draft', 'post_type' => 'post' ] );

$r = tool( 'wp.posts.delete', [ 'id' => $del_post ] );
ok( 'without confirm → error', is_err( $r ), json_encode( $r ) );

$r = tool( 'wp.posts.delete', [ 'id' => $del_post, 'confirm' => true ] );
ok( 'with confirm: no error', ! is_err( $r ), json_encode( $r ) );
ok( 'post gone from DB', get_post( $del_post ) === null );

// ═══════════════════════════════════════════════════════════════════════════
// 11. tools/list (MCP standard format)
// ═══════════════════════════════════════════════════════════════════════════

section( 'tools/list (MCP standard)' );
$raw = AICOM_Tool_Router::dispatch( json_encode( [
    'jsonrpc' => '2.0',
    'method'  => 'tools/list',
    'params'  => [],
    'id'      => 1,
] ) );
$tools_list = $raw['result']['tools'] ?? [];
ok( 'returns tools array', is_array( $tools_list ) && count( $tools_list ) > 0, 'count=' . count( $tools_list ) );
ok( 'tools count > 20', count( $tools_list ) > 20, 'got=' . count( $tools_list ) );
$tool_names = array_column( $tools_list, 'name' );
ok( 'wp.posts.create in list', in_array( 'wp.posts.create', $tool_names ) );
ok( 'wp.posts.preview_url in list', in_array( 'wp.posts.preview_url', $tool_names ) );
ok( 'elementor.page.create_from_template in list', in_array( 'elementor.page.create_from_template', $tool_names ) );
ok( 'yoast.post.get in list', in_array( 'yoast.post.get', $tool_names ) );

// ═══════════════════════════════════════════════════════════════════════════
// 12. API Key Lifecycle (v2.7.0)
// ═══════════════════════════════════════════════════════════════════════════

global $wpdb;
$lifecycle_key_ids = [];

section( 'create_key with expires_at' );
$future = gmdate( 'Y-m-d 23:59:59', strtotime( '+30 days' ) );
$lk1 = AICOM_Auth::create_key( 'Lifecycle-Future', [ 'read.wp' ], [], $future );
$lifecycle_key_ids[] = $lk1['id'];
$lk1_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aicom_api_keys WHERE id = %d", $lk1['id'] ), ARRAY_A );
ok( 'expires_at stored in DB', ! empty( $lk1_row['expires_at'] ), $lk1_row['expires_at'] ?? 'null' );
ok( 'expires_at matches', substr( $lk1_row['expires_at'], 0, 10 ) === substr( $future, 0, 10 ) );
ok( 'status is active', $lk1_row['status'] === 'active' );

section( 'validate_key: future expiry → valid' );
$validated = AICOM_Auth::validate_key( $lk1['plain_key'] );
ok( 'valid key accepted', $validated !== null );
ok( 'correct id returned', (int) ( $validated['id'] ?? 0 ) === $lk1['id'] );

section( 'create_key with past expires_at → expired by cron' );
$past = gmdate( 'Y-m-d 23:59:59', strtotime( '-1 day' ) );
$lk2 = AICOM_Auth::create_key( 'Lifecycle-Past', [ 'read.wp' ], [], $past );
$lifecycle_key_ids[] = $lk2['id'];
// Manually force-set status back to active (create_key sets active, validate checks expiry inline).
// validate_key should reject it because expires_at < NOW even though status=active.
$validated_past = AICOM_Auth::validate_key( $lk2['plain_key'] );
ok( 'expired key rejected by validate_key', $validated_past === null );

section( 'expire_overdue_keys cron job' );
// Reset lk2 to active to simulate the pre-cron state, then run cron.
$wpdb->update( "{$wpdb->prefix}aicom_api_keys", [ 'status' => 'active' ], [ 'id' => $lk2['id'] ] );
AICOM_Auth::expire_overdue_keys();
$lk2_row = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}aicom_api_keys WHERE id = %d", $lk2['id'] ), ARRAY_A );
ok( 'past-expiry key marked expired', ( $lk2_row['status'] ?? '' ) === 'expired' );

// Future key must NOT be expired by cron
$lk1_row_after = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}aicom_api_keys WHERE id = %d", $lk1['id'] ), ARRAY_A );
ok( 'future key still active after cron', ( $lk1_row_after['status'] ?? '' ) === 'active' );

section( 'create_key without expires_at → no expiry' );
$lk3 = AICOM_Auth::create_key( 'Lifecycle-NoExpiry', [ 'read.wp' ] );
$lifecycle_key_ids[] = $lk3['id'];
$lk3_row = $wpdb->get_row( $wpdb->prepare( "SELECT expires_at, status FROM {$wpdb->prefix}aicom_api_keys WHERE id = %d", $lk3['id'] ), ARRAY_A );
ok( 'expires_at is NULL', $lk3_row['expires_at'] === null );
$lk3_validated = AICOM_Auth::validate_key( $lk3['plain_key'] );
ok( 'no-expiry key always valid', $lk3_validated !== null );

section( 'archive_key / unarchive_key' );
$lk_arch = AICOM_Auth::create_key( 'Lifecycle-Archive', [ 'read.wp' ] );
$lifecycle_key_ids[] = $lk_arch['id'];

// Suspend first so archive works from suspended state
AICOM_Auth::suspend_key( $lk_arch['id'] );
$arch_ok = AICOM_Auth::archive_key( $lk_arch['id'] );
ok( 'archive_key returns true', $arch_ok );
$arch_row = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}aicom_api_keys WHERE id = %d", $lk_arch['id'] ), ARRAY_A );
ok( 'status → archived', ( $arch_row['status'] ?? '' ) === 'archived' );

// Archived key must not validate
$arch_validated = AICOM_Auth::validate_key( $lk_arch['plain_key'] );
ok( 'archived key rejected', $arch_validated === null );

$unarch_ok = AICOM_Auth::unarchive_key( $lk_arch['id'] );
ok( 'unarchive_key returns true', $unarch_ok );
$unarch_row = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}aicom_api_keys WHERE id = %d", $lk_arch['id'] ), ARRAY_A );
ok( 'status → suspended after unarchive', ( $unarch_row['status'] ?? '' ) === 'suspended' );

// unarchive_key on non-archived key must fail
$double_unarch = AICOM_Auth::unarchive_key( $lk_arch['id'] ); // already suspended
ok( 'unarchive non-archived key returns false', $double_unarch === false );

// ── update_key (Repurpose Key) ─────────────────────────────────────────────

section( 'update_key: scope diff' );
$lk_edit = AICOM_Auth::create_key( 'Lifecycle-Edit', [ 'read.wp', 'write.wp.posts' ] );
$lifecycle_key_ids[] = $lk_edit['id'];
$new_scopes = [ 'read.wp', 'manage.meta', 'manage.taxonomies' ];
$diff = AICOM_Auth::update_key( $lk_edit['id'], $new_scopes, [], null );
ok( 'update_key returns diff array', is_array( $diff ) );
ok( 'added contains manage.meta', in_array( 'manage.meta', $diff['added'] ?? [], true ) );
ok( 'added contains manage.taxonomies', in_array( 'manage.taxonomies', $diff['added'] ?? [], true ) );
ok( 'removed contains write.wp.posts', in_array( 'write.wp.posts', $diff['removed'] ?? [], true ) );
ok( 'read.wp not in added or removed', ! in_array( 'read.wp', $diff['added'] ?? [], true ) && ! in_array( 'read.wp', $diff['removed'] ?? [], true ) );

$edit_row = $wpdb->get_row( $wpdb->prepare( "SELECT scopes_json FROM {$wpdb->prefix}aicom_api_keys WHERE id = %d", $lk_edit['id'] ), ARRAY_A );
$saved_scopes = json_decode( $edit_row['scopes_json'] ?? '[]', true );
ok( 'new scopes saved in DB', $saved_scopes === $new_scopes );

section( 'update_key: expiry' );
$new_expiry = gmdate( 'Y-m-d 23:59:59', strtotime( '+7 days' ) );
AICOM_Auth::update_key( $lk_edit['id'], $new_scopes, [], $new_expiry );
$expiry_row = $wpdb->get_row( $wpdb->prepare( "SELECT expires_at FROM {$wpdb->prefix}aicom_api_keys WHERE id = %d", $lk_edit['id'] ), ARRAY_A );
ok( 'expiry saved', ! empty( $expiry_row['expires_at'] ) );
ok( 'expiry date correct', substr( $expiry_row['expires_at'], 0, 10 ) === substr( $new_expiry, 0, 10 ) );

// Clear expiry
AICOM_Auth::update_key( $lk_edit['id'], $new_scopes, [], null );
$cleared_row = $wpdb->get_row( $wpdb->prepare( "SELECT expires_at FROM {$wpdb->prefix}aicom_api_keys WHERE id = %d", $lk_edit['id'] ), ARRAY_A );
ok( 'expiry cleared to NULL', $cleared_row['expires_at'] === null );

section( 'update_key: restrictions' );
$restrictions = [ 'dry_run_only' => true, 'ip_allowlist' => [ '10.0.0.1', '192.168.1.0/24' ] ];
AICOM_Auth::update_key( $lk_edit['id'], $new_scopes, $restrictions, null );
$rest_row = $wpdb->get_row( $wpdb->prepare( "SELECT restrictions_json FROM {$wpdb->prefix}aicom_api_keys WHERE id = %d", $lk_edit['id'] ), ARRAY_A );
$saved_rest = json_decode( $rest_row['restrictions_json'] ?? '{}', true );
ok( 'dry_run_only saved', ! empty( $saved_rest['dry_run_only'] ) );
ok( 'ip_allowlist saved', ( $saved_rest['ip_allowlist'] ?? [] ) === [ '10.0.0.1', '192.168.1.0/24' ] );

section( 'rotate after update' );
$old_prefix = $lk_edit['key_prefix'] ?? '';
$new_plain = AICOM_Auth::rotate_key( $lk_edit['id'] );
ok( 'rotate returns new plain key', ! empty( $new_plain ) );
ok( 'new key starts with aicom_', strpos( $new_plain, 'aicom_' ) === 0 );
$rotated_row = $wpdb->get_row( $wpdb->prepare( "SELECT key_prefix FROM {$wpdb->prefix}aicom_api_keys WHERE id = %d", $lk_edit['id'] ), ARRAY_A );
ok( 'prefix changed after rotate', ( $rotated_row['key_prefix'] ?? '' ) !== $old_prefix );
ok( 'new plain key validates', AICOM_Auth::validate_key( $new_plain ) !== null );

section( 'session.close' );
$r = tool( 'session.close', [] );
ok( 'no error', ! is_err( $r ), json_encode( $r ) );
ok( 'closed = true', ( $r['closed'] ?? false ) === true );
ok( 'session_id matches', (int) ( $r['session_id'] ?? 0 ) === $smoke_session_id );

section( 'session.close again → NO_ACTIVE_SESSION' );
$r = tool( 'session.close', [] );
ok( 'returns NO_ACTIVE_SESSION', ( $r['error']['code'] ?? '' ) === 'NO_ACTIVE_SESSION', json_encode( $r ) );

// ═══════════════════════════════════════════════════════════════════════════
// Cleanup
// ═══════════════════════════════════════════════════════════════════════════

foreach ( array_unique( $cleanup_posts ) as $pid ) {
    wp_delete_post( (int) $pid, true );
}
foreach ( $cleanup_terms as $t ) {
    wp_delete_term( $t['id'], $t['tax'] );
}
global $wpdb;
if ( $smoke_session_id ?? 0 ) {
    $wpdb->delete( "{$wpdb->prefix}aicom_sessions", [ 'id' => $smoke_session_id ] );
}
$wpdb->delete( "{$wpdb->prefix}aicom_sessions", [ 'api_key_id' => $key_id ] );
$wpdb->delete( "{$wpdb->prefix}aicom_api_keys", [ 'id' => $key_id ] );
foreach ( $lifecycle_key_ids as $lid ) {
    $wpdb->delete( "{$wpdb->prefix}aicom_api_keys", [ 'id' => $lid ] );
}

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
exit( $fail > 0 ? 1 : 0 );
