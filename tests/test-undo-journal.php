<?php
/**
 * Test: every journaled tool can be undone exactly (AICOM_Change_Journal, 2026-11-26).
 *
 * For each case: set up what the tool needs, fingerprint the database, run the tool in a session, Restore session,
 * fingerprint again. The two must be identical (a post the call created may stay behind in the trash, as Undo has
 * always done), and the call must have reported undo.complete. AICOM_Change_Journal::VERIFIED must list exactly the
 * tools that pass here — it is what AICOMBase is told is reversible.
 *
 * Then the journal's own rules: a person's later edit is kept, a dry run that writes is put back and refused, and what
 * it can't follow (another table, an email) makes the undo incomplete with the reason.
 *
 * Run: docker compose exec -T wp php -d opcache.jit=0 -d memory_limit=512M wp-content/plugins/aicom/tests/test-undo-journal.php --allow-root
 * One case, with the differing rows in full: add -e UNDO_CASE=wp.terms.delete after `exec`.
 */

define( 'DOING_AJAX', true );
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require_once '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
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
function failed( array $r ): bool { return ! empty( $r['isError'] ) || isset( $r['error'] ); }

// ── the database, row by row, as content (no caches, no bookkeeping) ──────

/** Each row as a short hash — or, for one case being looked into (UNDO_CASE), as its content. */
function h( $v ): string { return getenv( 'UNDO_CASE' ) ? substr( is_string( $v ) ? $v : serialize( $v ), 0, 400 ) : md5( is_string( $v ) ? $v : serialize( $v ) ); }

function fingerprint(): array {
    global $wpdb;
    $f    = [];
    $skip = "('revision','oembed_cache')";
    foreach ( $wpdb->get_results( "SELECT * FROM {$wpdb->posts} WHERE post_type NOT IN $skip", ARRAY_A ) as $r ) {
        $f[ "post {$r['ID']}" ] = h( $r );
    }
    $meta = static function ( string $table, string $col, string $pk, string $label, string $extra = '' ) use ( &$f, $wpdb ) {
        $skip_keys = "('_edit_lock','session_tokens','_elementor_css','_elementor_element_cache','_elementor_page_assets','product_count_product_cat','product_count_product_tag')";
        foreach ( $wpdb->get_results( "SELECT $col AS o, meta_key AS k, meta_value AS v FROM $table WHERE meta_key NOT IN $skip_keys $extra ORDER BY $pk", ARRAY_A ) as $r ) {
            $f[ "$label {$r['o']} {$r['k']}" ] = ( $f[ "$label {$r['o']} {$r['k']}" ] ?? '' ) . h( (string) $r['v'] );
        }
    };
    $meta( $wpdb->postmeta, 'post_id', 'meta_id', 'pm', "AND post_id NOT IN (SELECT ID FROM {$wpdb->posts} WHERE post_type IN $skip)" );
    $meta( $wpdb->termmeta, 'term_id', 'meta_id', 'tm' );
    $meta( $wpdb->usermeta, 'user_id', 'umeta_id', 'um' );
    $meta( $wpdb->commentmeta, 'comment_id', 'meta_id', 'cm' );
    foreach ( $wpdb->get_results( "SELECT t.*, tt.* FROM {$wpdb->terms} t LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id", ARRAY_A ) as $r ) {
        $f[ "term {$r['term_id']}" ] = h( $r );
    }
    foreach ( $wpdb->get_results( "SELECT * FROM {$wpdb->term_relationships}", ARRAY_A ) as $r ) {
        $f[ "rel {$r['object_id']} {$r['term_taxonomy_id']}" ] = $r['term_order'];
    }
    foreach ( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options}", ARRAY_A ) as $r ) {
        if ( ! preg_match( '/^(_site)?_transient_|^(cron|rewrite_rules)$|^action_scheduler_|_children(_all)?$|\.lock$/', $r['option_name'] ) ) {
            $f[ "option {$r['option_name']}" ] = h( (string) $r['option_value'] );
        }
    }
    foreach ( $wpdb->get_results( "SELECT * FROM {$wpdb->users}", ARRAY_A ) as $r ) {
        $f[ "user {$r['ID']}" ] = h( $r );
    }
    foreach ( $wpdb->get_results( "SELECT * FROM {$wpdb->comments}", ARRAY_A ) as $r ) {
        $f[ "comment {$r['comment_ID']}" ] = h( $r );
    }
    return $f;
}

/** Keys that differ, leaving out posts the call created that Undo moved to the trash (and their meta and terms). */
function differences( array $before, array $after ): array {
    global $wpdb;
    $trashed = [];
    foreach ( array_diff_key( $after, $before ) as $k => $_ ) {
        if ( preg_match( '/^post (\d+)$/', $k, $m ) && get_post_status( (int) $m[1] ) === 'trash' ) {
            $trashed[ $m[1] ] = true;
        }
    }
    $out = [];
    foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $k ) {
        if ( ( $before[ $k ] ?? null ) === ( $after[ $k ] ?? null ) ) {
            continue;
        }
        if ( preg_match( '/^(?:post|pm|rel) (\d+)/', $k, $m ) && isset( $trashed[ $m[1] ] ) ) {
            continue;
        }
        $out[] = $k;
    }
    return $out;
}

// ── fixtures ──────────────────────────────────────────────────────────────

$made = [ 'post' => [], 'term' => [], 'user' => [], 'menu' => [], 'option' => [] ];
function mkpost( array $a = [] ): int {
    global $made;
    $id = wp_insert_post( wp_slash( $a + [ 'post_title' => 'Undo fixture ' . wp_rand(), 'post_content' => '<p>Original \\ "content"</p>', 'post_status' => 'publish', 'post_type' => 'post' ] ) );
    $made['post'][] = $id;
    return (int) $id;
}
function mkterm( string $tax, array $a = [] ): int {
    global $made;
    $t = wp_insert_term( 'Undo term ' . wp_rand(), $tax, $a );
    $made['term'][] = [ (int) $t['term_id'], $tax ];
    return (int) $t['term_id'];
}
function mkimage(): int {
    global $made;
    $id = wp_insert_post( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'IMG_' . wp_rand(), 'post_mime_type' => 'image/jpeg' ] );
    update_post_meta( $id, '_wp_attached_file', "aicom-test/undo-$id.jpg" );
    $made['post'][] = $id;
    return (int) $id;
}
function mkuser( string $role = 'subscriber' ): int {
    global $made;
    $login = 'undo_fixture_' . wp_rand();
    $id    = wp_insert_user( [ 'user_login' => $login, 'user_email' => "$login@example.com", 'user_pass' => wp_generate_password(), 'role' => $role ] );
    $made['user'][] = $id;
    return (int) $id;
}
function mkproduct( array $a = [] ): int {
    global $made;
    $p = new WC_Product_Simple();
    $p->set_name( 'Undo product ' . wp_rand() );
    $p->set_regular_price( '10' );
    $p->set_status( 'publish' );
    $id = $p->save();
    $made['post'][] = $id;
    return (int) $id;
}
function mkmenu( int $items = 2 ): array {
    global $made;
    $menu = wp_create_nav_menu( 'Undo menu ' . wp_rand() );
    $made['menu'][] = $menu;
    $ids = [];
    for ( $i = 1; $i <= $items; $i++ ) {
        $ids[] = wp_update_nav_menu_item( $menu, 0, [ 'menu-item-title' => "Item $i", 'menu-item-url' => "https://example.com/$i", 'menu-item-status' => 'publish', 'menu-item-type' => 'custom' ] );
    }
    return [ $menu, $ids ];
}
function elementor_page(): int {
    $id   = mkpost( [ 'post_type' => 'page', 'post_title' => 'Undo Elementor page' ] );
    $data = [ [ 'id' => 'a1b2c3d', 'elType' => 'container', 'settings' => [], 'elements' => [
        [ 'id' => 'w1w2w3w', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Hello' ], 'elements' => [] ],
    ] ] ];
    update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
    update_post_meta( $id, '_elementor_edit_mode', 'builder' );
    return $id;
}
function ecs_id( string $tool, string $name ): string {
    tool( 'session.open', [ 'name' => 'Fixture', 'description' => 'Something for the next case to delete' ] );
    $r = tool( $tool, [ 'name' => $name, 'label' => $name, 'colors' => [ [ 'id' => 'c1', 'value' => '#112233', 'type' => 'custom' ] ], 'fonts' => [ [ 'id' => 'f1', 'type' => 'custom', 'family' => 'Inter' ] ], 'css' => 'body{color:red}' ] );
    tool( 'session.close', [] );
    return (string) ( $r['id'] ?? '' );
}

$first_new_term = 1 + (int) $wpdb->get_var( "SELECT MAX(term_id) FROM {$wpdb->terms}" );
$key = AICOM_Auth::create_key( 'Undo Journal Test', array_values( array_diff( AICOM_Auth::scope_slugs(), [] ) ) );
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key['plain_key'];

// ── each tool: run, restore, compare ──────────────────────────────────────

$cases = [
    'wp.posts.create' => fn() => [ 'post_title' => 'Undo created', 'post_content' => '<p>new</p>', 'post_status' => 'draft' ],
    'wp.posts.update' => function () {
        $p = mkpost();
        wp_set_post_terms( $p, [ mkterm( 'category' ) ], 'category' );
        return [ 'id' => $p, 'post_title' => 'Changed', 'post_content' => '<p>Changed \\ "quoted"</p><script>x()</script>', 'post_status' => 'draft', 'post_name' => 'changed-slug' ];
    },
    'wp.posts.trash' => function () {
        $p = mkpost();
        wp_insert_comment( [ 'comment_post_ID' => $p, 'comment_content' => 'A comment', 'comment_approved' => 1, 'comment_author' => 'Ana' ] );
        return [ 'id' => $p ];
    },
    'wp.posts.restore' => function () { $p = mkpost(); wp_trash_post( $p ); return [ 'id' => $p ]; },
    'wp.posts.delete' => function () {
        $parent = mkpost( [ 'post_type' => 'page' ] );
        mkpost( [ 'post_type' => 'page', 'post_parent' => $parent ] );
        update_post_meta( $parent, 'undo_meta', [ 'a' => 1 ] );
        wp_insert_comment( [ 'comment_post_ID' => $parent, 'comment_content' => 'Gone with the page', 'comment_approved' => 1 ] );
        set_post_thumbnail( $parent, mkimage() );
        return [ 'id' => $parent, 'force' => true, 'confirm' => true ];
    },
    'wp.terms.create' => fn() => [ 'name' => 'Undo created term', 'taxonomy' => 'category' ],
    'wp.terms.update' => fn() => [ 'term_id' => mkterm( 'category' ), 'taxonomy' => 'category', 'name' => 'Renamed', 'slug' => 'renamed-undo', 'description' => 'New description' ],
    'wp.terms.delete' => function () {
        $t = mkterm( 'category' );
        mkterm( 'category', [ 'parent' => $t ] );
        add_term_meta( $t, 'undo_term_meta', 'kept' );
        wp_set_post_terms( mkpost(), [ $t ], 'category' );
        return [ 'term_id' => $t, 'taxonomy' => 'category', 'confirm' => true ];
    },
    'wp.terms.assign_to_post' => function () {
        $p = mkpost();
        wp_set_post_terms( $p, [ mkterm( 'category' ) ], 'category' );
        return [ 'post_id' => $p, 'term_ids' => [ mkterm( 'category' ), mkterm( 'category' ) ], 'taxonomy' => 'category', 'append' => false ];
    },
    'wp.terms.remove_from_post' => function () {
        $p = mkpost();
        wp_set_post_terms( $p, [ mkterm( 'post_tag' ), mkterm( 'post_tag' ) ], 'post_tag' );
        return [ 'post_id' => $p, 'taxonomy' => 'post_tag' ];
    },
    'wp.meta.set' => function () { $p = mkpost(); update_post_meta( $p, 'undo_k', 'old' ); return [ 'post_id' => $p, 'meta_key' => 'undo_k', 'meta_value' => [ 'new' => true ] ]; },
    'wp.meta.set_many' => function () { $p = mkpost(); update_post_meta( $p, 'undo_a', 'old' ); return [ 'post_id' => $p, 'meta' => [ 'undo_a' => 'new a', 'undo_b' => 'new b' ] ]; },
    'wp.meta.delete' => function () { $p = mkpost(); add_post_meta( $p, 'undo_del', 'one' ); add_post_meta( $p, 'undo_del', 'two' ); return [ 'post_id' => $p, 'meta_key' => 'undo_del' ]; },
    'wp.options.set' => fn() => [ 'option_name' => 'blogdescription', 'option_value' => 'Undo tagline ' . wp_rand(), 'confirm' => true ],
    'wp.security.xmlrpc.set' => fn() => [ 'enabled' => AICOM_Xmlrpc::is_disabled_by_setting(), 'force' => true ],

    'wp.menus.create' => fn() => [ 'name' => 'Undo new menu ' . wp_rand() ],
    'wp.menus.rename' => fn() => [ 'menu_id' => mkmenu( 1 )[0], 'name' => 'Undo renamed ' . wp_rand() ],
    'wp.menus.delete' => fn() => [ 'menu_id' => mkmenu( 2 )[0], 'confirm' => true ],
    'wp.menus.items.add' => fn() => [ 'menu_id' => mkmenu( 1 )[0], 'type' => 'custom', 'title' => 'Added', 'url' => 'https://example.com/added' ],
    'wp.menus.items.update' => function () { [ $m, $items ] = mkmenu( 2 ); return [ 'menu_id' => $m, 'item_id' => $items[1], 'title' => 'Updated', 'url' => 'https://example.com/updated' ]; },
    'wp.menus.items.remove' => function () { [ , $items ] = mkmenu( 2 ); return [ 'item_id' => $items[0], 'confirm' => true ]; },
    'wp.menus.locations.set' => function () {
        $loc = array_key_first( get_registered_nav_menus() );
        return $loc ? [ 'location' => $loc, 'menu_id' => mkmenu( 1 )[0], 'confirm' => true ] : null;
    },

    'media.update_meta' => function () { $i = mkimage(); update_post_meta( $i, '_wp_attachment_image_alt', 'IMG_1' ); return [ 'id' => $i, 'alt' => 'A real description', 'title' => 'Real title', 'caption' => 'Caption' ]; },
    'media.attach_to_post' => fn() => [ 'id' => mkimage(), 'post_id' => mkpost() ],
    'media.set_featured' => function () { $p = mkpost(); set_post_thumbnail( $p, mkimage() ); return [ 'id' => mkimage(), 'post_id' => $p ]; },
    'a11y.set_image_alt' => fn() => [ 'id' => mkimage(), 'alt' => 'Players at the final whistle' ],

    'wp.users.create' => fn() => [ 'user_login' => 'undo_new_' . wp_rand(), 'user_email' => 'undo_new_' . wp_rand() . '@example.com', 'role' => 'subscriber', 'user_pass' => wp_generate_password() ],
    // Not the email: WordPress mails the old address about it, which no Undo takes back (the call says so).
    'wp.users.update' => fn() => [ 'id' => mkuser(), 'display_name' => 'Changed Name', 'first_name' => 'Changed', 'last_name' => 'Person' ],
    'wp.users.set_role' => fn() => [ 'id' => mkuser(), 'role' => 'editor', 'confirm' => true ],
    'wp.users.add_role' => fn() => [ 'id' => mkuser(), 'role' => 'author' ],
    'wp.users.remove_role' => function () { $u = mkuser(); ( new WP_User( $u ) )->add_role( 'author' ); return [ 'id' => $u, 'role' => 'author', 'confirm' => true ]; },
    'wp.roles.create' => fn() => [ 'slug' => 'undo_role_new', 'name' => 'Undo Role New', 'capabilities' => [ 'read' ] ],
    'wp.roles.update_caps' => function () { add_role( 'undo_role_caps', 'Undo Role Caps', [ 'read' => true ] ); return [ 'slug' => 'undo_role_caps', 'add_caps' => [ 'edit_posts' ], 'remove_caps' => [ 'read' ], 'confirm' => true ]; },
    'wp.roles.delete' => function () { add_role( 'undo_role_del', 'Undo Role Del', [ 'read' => true ] ); return [ 'slug' => 'undo_role_del', 'confirm' => true ]; },
];

if ( class_exists( 'WooCommerce' ) ) {
    $cases += [
        'wc.products.create' => fn() => [ 'name' => 'Undo new product', 'regular_price' => '12', 'status' => 'draft' ],
        'wc.products.update' => fn() => [ 'id' => mkproduct(), 'name' => 'Changed product', 'regular_price' => '20', 'sale_price' => '15', 'description' => 'New', 'sku' => 'UNDO-' . wp_rand() ],
        'wc.products.trash' => fn() => [ 'id' => mkproduct() ],
        'wc.products.restore' => function () { $p = mkproduct(); wp_trash_post( $p ); return [ 'id' => $p ]; },
        'wc.products.delete' => fn() => [ 'id' => mkproduct(), 'confirm' => true ],
        'wc.products.set_price' => fn() => [ 'id' => mkproduct(), 'regular_price' => '30', 'sale_price' => '25' ],
        'wc.products.set_stock' => fn() => [ 'id' => mkproduct(), 'manage_stock' => true, 'stock_quantity' => 50 ],
        'wc.products.assign_terms' => fn() => [ 'id' => mkproduct(), 'taxonomy' => 'product_cat', 'term_ids' => [ mkterm( 'product_cat' ) ] ],
        'wc.categories.create' => fn() => [ 'name' => 'Undo new product cat' ],
        'wc.categories.update' => fn() => [ 'term_id' => mkterm( 'product_cat' ), 'name' => 'Renamed cat', 'description' => 'New' ],
        'wc.categories.delete' => function () { $t = mkterm( 'product_cat' ); wp_set_object_terms( mkproduct(), [ $t ], 'product_cat' ); return [ 'term_id' => $t, 'confirm' => true ]; },
        'wc.settings.set' => fn() => [ 'key' => 'woocommerce_store_city', 'value' => 'Undo City ' . wp_rand(), 'confirm' => true ],
    ];
}
if ( function_exists( 'pll_languages_list' ) && count( (array) pll_languages_list() ) >= 2 ) {
    [ $l1, $l2 ] = array_slice( (array) pll_languages_list(), 0, 2 );
    $lang_post = static function ( string $lang ) { $p = mkpost(); pll_set_post_language( $p, $lang ); return $p; };
    $lang_term = static function ( string $lang ) { $t = mkterm( 'category' ); pll_set_term_language( $t, $lang ); return $t; };
    $cases += [
        'pll.post.set_language' => fn() => [ 'post_id' => $lang_post( $l1 ), 'language' => $l2 ],
        'pll.post.link_translation' => fn() => [ 'translations' => [ $l1 => $lang_post( $l1 ), $l2 => $lang_post( $l2 ) ], 'confirm' => true ],
        'pll.post.unlink_translation' => function () use ( $lang_post, $l1, $l2 ) {
            $a = $lang_post( $l1 );
            pll_save_post_translations( [ $l1 => $a, $l2 => $lang_post( $l2 ) ] );
            return [ 'post_id' => $a, 'confirm' => true ];
        },
        'pll.term.set_language' => fn() => [ 'term_id' => $lang_term( $l1 ), 'language' => $l2 ],
        'pll.term.link_translation' => fn() => [ 'translations' => [ $l1 => $lang_term( $l1 ), $l2 => $lang_term( $l2 ) ], 'confirm' => true ],
        'pll.string.set' => fn() => [ 'wp_option' => 'blogdescription', 'language' => $l2, 'translation' => 'Undo translated tagline ' . wp_rand() ],
        // AICOMBase's brief, A5: the draft it creates must go when the session is undone.
        'pll.create_bilingual_pair' => fn() => [ 'source_post_id' => $lang_post( $l1 ), 'target_language' => $l2, 'post_title' => 'Undo fixture translated', 'post_content' => '<p>Tradus</p>' ],
    ];
}
if ( defined( 'SEOPRESS_VERSION' ) ) {
    $cases += [
        'seopress.post.set' => fn() => [ 'post_id' => mkpost(), 'title' => 'SEO title', 'metadesc' => 'SEO description' ],
        'seopress.post.social.set' => fn() => [ 'post_id' => mkpost(), 'fb_title' => 'FB title' ],
        'seopress.term.set' => fn() => [ 'term_id' => mkterm( 'category' ), 'taxonomy' => 'category', 'title' => 'Term SEO title' ],
    ];
}
if ( defined( 'WPSEO_VERSION' ) ) {
    $cases += [
        'yoast.post.set' => fn() => [ 'post_id' => mkpost(), 'title' => 'Yoast title', 'metadesc' => 'Yoast description' ],
        'yoast.post.social.set' => fn() => [ 'post_id' => mkpost(), 'og_title' => 'OG title' ],
        'yoast.term.set' => fn() => [ 'term_id' => mkterm( 'category' ), 'taxonomy' => 'category', 'title' => 'Yoast term title' ],
    ];
}
if ( AICOM_Tool_Registry::get( 'ecs.color_schemes.save' ) && AICOM_Module_Detector::is_dependency_active( AICOM_Tool_Registry::get( 'ecs.color_schemes.save' )['dependency'] ) ) {
    $cases += [
        'ecs.color_schemes.save' => fn() => [ 'name' => 'Undo scheme', 'colors' => [ [ 'id' => 'c1', 'value' => '#112233', 'type' => 'custom' ] ] ],
        'ecs.color_schemes.delete' => fn() => [ 'id' => ecs_id( 'ecs.color_schemes.save', 'Undo scheme del' ) ],
        'ecs.font_schemes.save' => fn() => [ 'name' => 'Undo fonts', 'fonts' => [ [ 'id' => 'f1', 'type' => 'custom', 'family' => 'Inter' ] ] ],
        'ecs.font_schemes.delete' => fn() => [ 'id' => ecs_id( 'ecs.font_schemes.save', 'Undo fonts del' ) ],
        'ecs.custom_css.save' => fn() => [ 'label' => 'Undo CSS', 'css' => 'body{color:red}' ],
        'ecs.custom_css.delete' => fn() => [ 'id' => ecs_id( 'ecs.custom_css.save', 'Undo CSS del' ) ],
        'ecs.custom_looks.save' => fn() => [ 'name' => 'Undo look' ],
        'ecs.custom_looks.delete' => fn() => [ 'id' => ecs_id( 'ecs.custom_looks.save', 'Undo look del' ) ],
        'ecs.custom_looks.toggle' => fn() => [ 'id' => ecs_id( 'ecs.custom_looks.save', 'Undo look toggle' ), 'enabled' => false ],
    ];
}
if ( defined( 'ELEMENTOR_VERSION' ) ) {
    $cases += [
        'elementor.widget.update_field' => fn() => [ 'post_id' => elementor_page(), 'widget_id' => 'w1w2w3w', 'field' => 'title', 'value' => 'Changed heading' ],
        'elementor.page.bulk_update_texts' => fn() => [ 'post_id' => elementor_page(), 'updates' => [ [ 'widget_id' => 'w1w2w3w', 'field' => 'title', 'text' => 'Bulk heading' ] ] ],
        'elementor.page.create_from_template' => fn() => [ 'source_post_id' => elementor_page(), 'title' => 'Undo copy', 'status' => 'draft' ],
        'elementor.template.set_conditions' => function () {
            $t = mkpost( [ 'post_type' => 'elementor_library', 'post_title' => 'Undo header' ] );
            update_post_meta( $t, '_elementor_template_type', 'header' );
            return [ 'post_id' => $t, 'conditions' => [ 'include/general' ], 'template_type' => 'header' ];
        },
    ];
}

echo "\n══ Each tool, undone ══\n";
$passed = [];
if ( getenv( 'UNDO_CASE' ) ) {
    $cases = array_intersect_key( $cases, [ getenv( 'UNDO_CASE' ) => true ] );
}
foreach ( $cases as $tool_name => $setup ) {
    $args = $setup();
    if ( $args === null ) {
        echo "  - $tool_name: nothing to test it with here\n";
        continue;
    }
    $before = fingerprint();
    $sid    = (int) ( tool( 'session.open', [ 'name' => "Undo $tool_name", 'description' => 'Run one tool, then restore the session' ] )['session_id'] ?? 0 );
    $r      = tool( $tool_name, $args );
    $unfollowed = AICOM_Change_Journal::$unfollowed;
    tool( 'session.close', [] );
    $changed = (bool) differences( $before, fingerprint() );
    AICOM_Admin::$restore_report = [ 'kept' => [], 'failed' => [] ];
    $restored = AICOM_Admin::do_restore_session( $sid );
    $final    = fingerprint();
    $diff     = differences( $before, $final );
    if ( getenv( 'UNDO_CASE' ) ) {
        foreach ( $diff as $k ) {
            echo "    $k\n      before: " . ( $before[ $k ] ?? '(none)' ) . "\n      after:  " . ( $final[ $k ] ?? '(none)' ) . "\n";
        }
        echo '    journal: ' . substr( (string) $wpdb->get_var( $wpdb->prepare( "SELECT payload_json FROM {$wpdb->prefix}aicom_backups WHERE session_id = %d AND target_type = 'journal'", $sid ) ), 0, 3000 ) . "\n";
    }
    $undo     = $r['undo'] ?? null;

    $problems = [];
    if ( failed( $r ) ) {
        $problems[] = 'the call failed: ' . substr( wp_json_encode( $r['error'] ?? $r['content'] ?? $r ), 0, 300 );
    } elseif ( ( $undo['complete'] ?? null ) !== true ) {
        $problems[] = 'undo not complete: ' . wp_json_encode( $undo ) . ( $unfollowed ? ' SQL: ' . implode( ' ;; ', array_map( static fn( $q ) => substr( $q, 0, 160 ), $unfollowed ) ) : '' );
    } elseif ( ! $changed ) {
        $problems[] = 'the call changed nothing to undo';
    }
    if ( $diff ) {
        $problems[] = 'after Undo, different: ' . implode( ', ', array_slice( $diff, 0, 8 ) ) . ( count( $diff ) > 8 ? ' …' : '' );
    }
    if ( AICOM_Admin::$restore_report['kept'] || AICOM_Admin::$restore_report['failed'] ) {
        $problems[] = 'restore report: ' . wp_json_encode( AICOM_Admin::$restore_report );
    }
    ok( "$tool_name (" . ( $undo['changes'] ?? 0 ) . " changes, $restored restored)", ! $problems, implode( ' | ', $problems ) );
    if ( ! $problems ) {
        $passed[] = $tool_name;
    }
}

echo "\n══ VERIFIED is what passed here ══\n";
$unproven = array_values( array_diff( AICOM_Change_Journal::VERIFIED, $passed ) );
ok( 'every tool listed as verified passed here', ! $unproven, implode( ', ', $unproven ) );
$unlisted = array_values( array_diff( $passed, AICOM_Change_Journal::VERIFIED ) );
ok( 'every tool that passed is listed', ! $unlisted, implode( ', ', $unlisted ) );
$caps = [];
foreach ( AICOM_Base_Capabilities::build() as $c ) { foreach ( (array) $c['tools'] as $t ) { $caps[ $t['id'] ] = $t['reversible']; } }
$not_rev = array_values( array_filter( $passed, static fn( $t ) => ( $caps[ $t ] ?? false ) !== true ) );
ok( 'AICOMBase is told they are reversible', ! $not_rev, implode( ', ', $not_rev ) );

// ── the journal's own rules ───────────────────────────────────────────────

echo "\n══ A person's later edit is kept ══\n";
$p   = mkpost( [ 'post_title' => 'Before AI', 'post_content' => 'Before AI content' ] );
$sid = (int) ( tool( 'session.open', [ 'name' => 'Conflict', 'description' => 'AI edits, then a person edits the same post' ] )['session_id'] ?? 0 );
tool( 'wp.posts.update', [ 'id' => $p, 'post_title' => 'AI title', 'post_content' => 'AI content' ] );
tool( 'wp.meta.set', [ 'post_id' => $p, 'meta_key' => 'undo_conflict', 'meta_value' => 'AI value' ] );
tool( 'session.close', [] );
wp_update_post( [ 'ID' => $p, 'post_title' => 'Person title' ] ); // the person, after the AI
update_post_meta( $p, 'undo_conflict', 'Person value' );
AICOM_Admin::$restore_report = [ 'kept' => [], 'failed' => [] ];
AICOM_Admin::do_restore_session( $sid );
clean_post_cache( $p );
ok( "the person's title stays", get_post( $p )->post_title === 'Person title', get_post( $p )->post_title );
ok( 'the content only the AI changed goes back', get_post( $p )->post_content === 'Before AI content', get_post( $p )->post_content );
ok( "the person's meta value stays", get_post_meta( $p, 'undo_conflict', true ) === 'Person value' );
ok( 'both are reported as kept', count( AICOM_Admin::$restore_report['kept'] ) === 2, wp_json_encode( AICOM_Admin::$restore_report ) );

echo "\n══ A term snapshot whose term is gone (brief A4) ══\n";
$t   = mkterm( 'category' );
$nm  = get_term( $t )->name;
$sid = (int) ( tool( 'session.open', [ 'name' => 'Old snapshot', 'description' => 'Snapshot a term, then it is deleted outside AICOM' ] )['session_id'] ?? 0 );
tool( 'backup.term.create', [ 'term_id' => $t, 'taxonomy' => 'category' ] );
tool( 'session.close', [] );
wp_delete_term( $t, 'category' );
AICOM_Admin::$restore_report = [ 'kept' => [], 'failed' => [] ];
$n     = AICOM_Admin::do_restore_session( $sid );
$again = get_term_by( 'name', $nm, 'category' );
ok( 'the term is made again and counted', $again && $n === 1, "restored=$n " . wp_json_encode( AICOM_Admin::$restore_report ) );
if ( $again ) {
    $made['term'][] = [ (int) $again->term_id, 'category' ];
}
$sid = (int) ( tool( 'session.open', [ 'name' => 'Broken snapshot', 'description' => 'A term snapshot of a taxonomy that is gone' ] )['session_id'] ?? 0 );
tool( 'session.close', [] );
$wpdb->insert( $wpdb->prefix . 'aicom_backups', [ 'created_at' => current_time( 'mysql', true ), 'session_id' => $sid, 'api_key_id' => $key['id'], 'tool_name' => 'backup.term.create', 'target_type' => 'term', 'target_id' => '999999', 'payload_json' => wp_json_encode( [ 'term' => [ 'term_id' => 999999, 'taxonomy' => 'no_such_taxonomy', 'name' => 'X', 'slug' => 'x' ], 'meta' => [] ] ) ] );
AICOM_Admin::$restore_report = [ 'kept' => [], 'failed' => [] ];
$n = AICOM_Admin::do_restore_session( $sid );
ok( 'one that fails is not counted, and is reported', $n === 0 && count( AICOM_Admin::$restore_report['failed'] ) === 1, "restored=$n " . wp_json_encode( AICOM_Admin::$restore_report ) );

echo "\n══ A dry run that writes anyway is put back and refused ══\n";
AICOM_Tool_Registry::register( 'test.journal.ignores_dry_run', [
    'module' => 'test', 'class' => 'write', 'required_scopes' => [], 'supports_dry_run' => true, 'description' => 'Test tool',
    'input_schema' => [], 'handler' => static function () { update_option( 'aicom_test_dry_run_write', 'written' ); return [ 'done' => true, '_meta' => [] ]; },
] );
delete_option( 'aicom_test_dry_run_write' );
tool( 'session.open', [ 'name' => 'Dry run', 'description' => 'A tool that ignores dry_run' ] );
$r = tool( 'test.journal.ignores_dry_run', [ 'dry_run' => true ] );
ok( 'refused with DRY_RUN_UNSUPPORTED', ( $r['error']['code'] ?? '' ) === 'DRY_RUN_UNSUPPORTED', wp_json_encode( $r ) );
ok( 'and what it wrote is gone', get_option( 'aicom_test_dry_run_write', 'absent' ) === 'absent' );
$r = tool( 'test.journal.ignores_dry_run', [] );
ok( 'without dry_run it runs, undo complete', ( $r['undo']['complete'] ?? null ) === true && get_option( 'aicom_test_dry_run_write' ) === 'written', wp_json_encode( $r['undo'] ?? $r ) );
tool( 'session.close', [] );
delete_option( 'aicom_test_dry_run_write' );

echo "\n══ What it can't follow makes the undo incomplete ══\n";
AICOM_Tool_Registry::register( 'test.journal.other_table', [
    'module' => 'test', 'class' => 'write', 'required_scopes' => [], 'description' => 'Test tool', 'input_schema' => [],
    'handler' => static function () { global $wpdb; $wpdb->insert( $wpdb->links, [ 'link_url' => 'https://example.com', 'link_name' => 'Undo test link' ] ); return [ 'done' => true, '_meta' => [] ]; },
] );
AICOM_Tool_Registry::register( 'test.journal.mail', [
    'module' => 'test', 'class' => 'write', 'required_scopes' => [], 'description' => 'Test tool', 'input_schema' => [],
    'handler' => static function () { update_option( 'aicom_test_mail', 'x' ); wp_mail( 'nobody@example.com', 'Undo test', 'Body' ); return [ 'done' => true, '_meta' => [] ]; },
] );
add_filter( 'pre_wp_mail', '__return_true', 10 ); // after the journal (priority 1): nothing is really sent
tool( 'session.open', [ 'name' => 'Incomplete', 'description' => 'Writes the journal does not cover' ] );
$r = tool( 'test.journal.other_table', [] );
ok( 'a write to another table: incomplete, with the table named', ( $r['undo']['complete'] ?? null ) === false && strpos( implode( ' ', $r['undo']['reasons'] ?? [] ), $wpdb->links ) !== false, wp_json_encode( $r['undo'] ?? $r ) );
$r = tool( 'test.journal.mail', [] );
ok( 'an email: incomplete, and the option it set is still undone', ( $r['undo']['complete'] ?? null ) === false && ( $r['undo']['changes'] ?? 0 ) === 1 && strpos( implode( ' ', $r['undo']['reasons'] ?? [] ), 'email' ) !== false, wp_json_encode( $r['undo'] ?? $r ) );
if ( defined( 'ICL_SITEPRESS_VERSION' ) && AICOM_Tool_Registry::get( 'wpml.post.set_language' ) ) {
    $p = mkpost();
    $r = tool( 'wpml.post.set_language', [ 'post_id' => $p, 'language' => 'en' ] );
    ok( "WPML (its own translations table): incomplete, it says so", failed( $r ) || ( $r['undo']['complete'] ?? null ) === false, wp_json_encode( $r['undo'] ?? $r ) );
}
tool( 'session.close', [] );
remove_filter( 'pre_wp_mail', '__return_true', 10 );
$wpdb->delete( $wpdb->links, [ 'link_name' => 'Undo test link' ] );
delete_option( 'aicom_test_mail' );

// ── cleanup ───────────────────────────────────────────────────────────────

foreach ( $made['menu'] as $m ) { wp_delete_nav_menu( $m ); }
foreach ( $made['post'] as $id ) { wp_delete_post( $id, true ); }
foreach ( $made['term'] as [ $id, $tax ] ) { wp_delete_term( $id, $tax ); }
foreach ( $made['user'] as $id ) { wp_delete_user( $id ); }
foreach ( [ 'undo_role_new', 'undo_role_caps', 'undo_role_del' ] as $role ) { remove_role( $role ); }
// Polylang keeps a one-member translation group when a term is deleted: the ones the fixtures left, with no member left.
foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT t.term_id, tt.taxonomy, tt.description FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy IN ('term_translations','post_translations') AND t.term_id >= %d", $first_new_term ) ) as $g ) {
    $alive = array_filter( array_values( (array) maybe_unserialize( $g->description ) ), static fn( $m ) => $g->taxonomy === 'term_translations' ? term_exists( (int) $m ) : get_post( (int) $m ) );
    if ( ! $alive ) {
        wp_delete_term( (int) $g->term_id, $g->taxonomy );
    }
}
foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'ecs\\_%'" ) as $name ) { // ECS fixtures
    $v = get_option( $name );
    if ( is_array( $v ) ) {
        $kept = array_values( array_filter( $v, static fn( $e ) => ! ( is_array( $e ) && strpos( (string) ( $e['name'] ?? $e['label'] ?? '' ), 'Undo' ) === 0 ) ) );
        if ( count( $kept ) !== count( $v ) ) {
            update_option( $name, $kept );
        }
    }
}
foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_title IN ('Undo created','Undo copy','Undo new product') OR post_title LIKE 'Undo fixture%'" ) as $id ) {
    wp_delete_post( (int) $id, true ); // what the tools created, left in the trash by Undo
}
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}aicom_backups WHERE api_key_id = %d", $key['id'] ) );
$wpdb->delete( $wpdb->prefix . 'aicom_sessions', [ 'api_key_id' => $key['id'] ] );
AICOM_Auth::revoke_key( $key['id'] );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
