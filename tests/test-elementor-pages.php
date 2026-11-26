<?php
/**
 * Test: pages built with Elementor (AICOMBase brief A6) and the Content Assistant preset (A7).
 *
 * Live on puzzleeducenter.ro, 2026-11-26: an AI changed the Contact page's address with wp.posts.update (post_content),
 * twice, both "verified", and visitors still saw the old one — the page is built with Elementor. Now the post tools say
 * so (built_with, a notice, visible_change: false), a write of _elementor_data drops the page's Elementor caches, and
 * wp.meta.set no longer calls & stored as &amp; a failed write.
 * Run: docker compose exec -T wp php wp-content/plugins/aicom/tests/test-elementor-pages.php --allow-root
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
    $r = AICOM_Tool_Router::dispatch( json_encode( [ 'jsonrpc' => '2.0', 'method' => 'tools/call', 'params' => [ 'name' => $name, 'arguments' => $args ], 'id' => 1 ] ), '2025-06-18' );
    return $r['result']['structuredContent'] ?? $r['result'] ?? $r;
}

if ( ! AICOM_Module_Detector::is_elementor_active() ) {
    echo "Elementor is not active — nothing to test.\n";
    exit( 1 );
}

$key = AICOM_Auth::create_key( 'Elementor Pages Test', [ 'read.wp', 'write.wp.posts', 'manage.meta' ] );
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key['plain_key'];

$el = wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'AICOM Elementor page test', 'post_content' => 'Fallback copy', 'post_status' => 'draft' ] );
update_post_meta( $el, '_elementor_edit_mode', 'builder' );
update_post_meta( $el, '_elementor_data', '[]' );
$plain = wp_insert_post( [ 'post_type' => 'page', 'post_title' => 'AICOM plain page test', 'post_content' => 'Plain', 'post_status' => 'draft' ] );

tool( 'session.open', [ 'name' => 'Elementor pages', 'description' => 'Check what the post tools say about pages built with Elementor' ] );

echo "\n══ Reading: the tools say which pages Elementor shows ══\n";
$r    = tool( 'wp.posts.list', [ 'post_type' => 'page', 'status' => 'draft', 'search' => 'AICOM', 'posts_per_page' => 50 ] );
$byid = array_column( (array) ( $r['posts'] ?? [] ), null, 'id' );
ok( 'wp.posts.list: built_with elementor on the Elementor page', ( $byid[ $el ]['built_with'] ?? null ) === 'elementor', wp_json_encode( $byid[ $el ] ?? $r ) );
ok( '... and nothing on a plain page', isset( $byid[ $plain ] ) && ! array_key_exists( 'built_with', $byid[ $plain ] ) );
$r = tool( 'wp.posts.get', [ 'id' => $el ] );
ok( 'wp.posts.get: built_with elementor, with a notice pointing to the Elementor tools', ( $r['built_with'] ?? null ) === 'elementor' && ( $r['notices'][0]['code'] ?? '' ) === 'builder.elementor'
    && strpos( $r['notices'][0]['text'], 'elementor.page.get_texts' ) !== false, wp_json_encode( $r ) );
$r = tool( 'wp.posts.get', [ 'id' => $plain ] );
ok( '... a plain page has neither', ! isset( $r['built_with'] ) && ! isset( $r['notices'] ) );

echo "\n══ Writing post_content of an Elementor page ══\n";
$r = tool( 'wp.posts.update', [ 'id' => $el, 'post_content' => 'Str. Nouă 5' ] );
ok( 'it still works', ( $r['updated'] ?? false ) === true && get_post_field( 'post_content', $el ) === 'Str. Nouă 5', wp_json_encode( $r ) );
ok( '... says visitors will not see it (visible_change: false + warning)', ( $r['visible_change'] ?? null ) === false && ( $r['notices'][0]['level'] ?? '' ) === 'warn' && ( $r['notices'][0]['code'] ?? '' ) === 'builder.elementor', wp_json_encode( $r ) );
$r = tool( 'wp.posts.update', [ 'id' => $el, 'post_content' => 'Str. Nouă 6', 'post_title' => 'AICOM Elementor page test 2' ] );
ok( 'with the title changed too: still the warning, but the change is visible', isset( $r['notices'] ) && ! array_key_exists( 'visible_change', $r ), wp_json_encode( $r ) );
$r = tool( 'wp.posts.update', [ 'id' => $plain, 'post_content' => 'Plain 2' ] );
ok( 'a plain page: no warning, no visible_change', ! isset( $r['notices'] ) && ! array_key_exists( 'visible_change', $r ) );
$d = AICOM_Tool_Registry::get( 'wp.posts.update' )['description'];
ok( 'wp.posts.update description names the Elementor tools', strpos( $d, 'elementor.page.bulk_update_texts' ) !== false );

echo "\n══ wp.meta.set ══\n";
update_post_meta( $el, '_elementor_css', [ 'status' => 'file' ] );
update_post_meta( $el, '_elementor_element_cache', 'cached html' );
update_post_meta( $el, '_elementor_page_assets', [ 'styles' => [] ] );
$data = '[{"id":"a1","elType":"widget","widgetType":"heading","settings":{"title":"Nou"}}]';
$r    = tool( 'wp.meta.set', [ 'post_id' => $el, 'meta_key' => '_elementor_data', 'meta_value' => $data ] );
wp_cache_delete( $el, 'post_meta' );
ok( 'writing _elementor_data drops the page\'s Elementor caches', ( $r['verified'] ?? false ) === true && ! metadata_exists( 'post', $el, '_elementor_css' ) && ! metadata_exists( 'post', $el, '_elementor_element_cache' ) && ! metadata_exists( 'post', $el, '_elementor_page_assets' ), wp_json_encode( $r ) );
ok( '... and can still be undone in full (the caches are not part of Undo)', ( $r['undo']['complete'] ?? null ) === true, wp_json_encode( $r['undo'] ?? null ) );
ok( 'wp.meta.set / set_many descriptions point to the Elementor tools', strpos( AICOM_Tool_Registry::get( 'wp.meta.set' )['description'], 'elementor.widget.update_field' ) !== false && strpos( AICOM_Tool_Registry::get( 'wp.meta.set_many' )['description'], 'elementor.page.bulk_update_texts' ) !== false );

// A site where another plugin's filter stores & as &amp; (as on puzzleeducenter.ro).
$amp = static fn( $v ) => is_string( $v ) ? str_replace( '&', '&amp;', $v ) : $v;
add_filter( 'sanitize_post_meta_aicom_amp_test', $amp );
$r = tool( 'wp.meta.set', [ 'post_id' => $plain, 'meta_key' => 'aicom_amp_test', 'meta_value' => '{"text":"Tom & Jerry"}' ] );
ok( '& stored as &amp; is the same text: verified', ( $r['verified'] ?? false ) === true && ( $r['persisted'] ?? '' ) === '{"text":"Tom &amp; Jerry"}' && ! isset( $r['warning'] ), wp_json_encode( $r ) );
$r = tool( 'wp.meta.set_many', [ 'post_id' => $plain, 'meta' => [ 'aicom_amp_test' => 'A & B', 'aicom_num_test' => 5, 'aicom_list_test' => [ 1, 'x & y' ] ] ] );
ok( 'set_many: &amp;, a number stored as a string, a list — all verified', ( $r['verified'] ?? false ) === true, wp_json_encode( $r ) );
remove_filter( 'sanitize_post_meta_aicom_amp_test', $amp );
$other = static fn( $v ) => 'something else';
add_filter( 'sanitize_post_meta_aicom_amp_test', $other );
$r = tool( 'wp.meta.set', [ 'post_id' => $plain, 'meta_key' => 'aicom_amp_test', 'meta_value' => 'Tom & Jerry' ] );
ok( 'a really different stored value is still reported', ( $r['verified'] ?? true ) === false && isset( $r['warning'] ), wp_json_encode( $r ) );
remove_filter( 'sanitize_post_meta_aicom_amp_test', $other );

tool( 'session.close', [] );

echo "\n══ A7: Content Assistant preset = AICOMBase's ══\n";
$ca   = AICOM_Admin::system_presets()['content-assistant'];
$want = [ 'read.wp', 'write.wp.posts', 'manage.meta', 'manage.taxonomies', 'read.skills', 'manage.skills', 'manage.media', 'manage.a11y', 'read.elementor', 'manage.elementor', 'read.woocommerce', 'manage.woocommerce.products', 'read.polylang', 'manage.polylang', 'read.wpml', 'manage.yoast', 'manage.seopress', 'manage.clautron' ];
ok( 'the same 18 scopes', ! array_diff( $want, $ca['scopes'] ) && ! array_diff( $ca['scopes'], $want ), wp_json_encode( $ca['scopes'] ) );
ok( 'every one is a real AICOM scope', ! array_diff( $ca['scopes'], AICOM_Auth::scope_slugs() ), wp_json_encode( array_values( array_diff( $ca['scopes'], AICOM_Auth::scope_slugs() ) ) ) );
ok( 'no settings, files, users, backups or plugins', ! array_filter( $ca['scopes'], static fn( $s ) => preg_match( '/settings|files|users|backups|plugins|roles/', $s ) ) );
ok( 'still medium risk, with the new description', $ca['risk'] === 'med' && strpos( $ca['desc'], 'page builder, shop, SEO and translation' ) !== false );

// Cleanup
wp_delete_post( $el, true );
wp_delete_post( $plain, true );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}aicom_backups WHERE api_key_id = %d", $key['id'] ) );
$wpdb->delete( $wpdb->prefix . 'aicom_sessions', [ 'api_key_id' => $key['id'] ] );
AICOM_Auth::revoke_key( $key['id'] );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
