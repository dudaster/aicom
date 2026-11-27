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
        unset( $r['user_pass'], $r['user_activation_key'] ); // never copied: a deleted user comes back with a new password (checked below)
        $f[ "user {$r['ID']}" ] = h( $r );
    }
    foreach ( $wpdb->get_results( "SELECT * FROM {$wpdb->comments}", ARRAY_A ) as $r ) {
        $f[ "comment {$r['comment_ID']}" ] = h( $r );
    }
    global $files_dir;
    $month = wp_upload_dir()['path']; // where media.upload puts what it adds
    foreach ( [ $files_dir, $month ] as $dir ) {
        foreach ( is_dir( $dir ) ? (array) glob( "$dir/*" ) : [] as $file ) {
            if ( is_file( $file ) ) {
                $f[ 'file ' . wp_normalize_path( $file ) ] = filesize( $file ) . ':' . ( filesize( $file ) < 1048576 ? md5_file( $file ) : '' );
            }
        }
    }
    foreach ( AICOM_Change_Journal::ROW_TABLES as $t => [ $pk ] ) { // plugin tables put back row by row
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $t ) ) ) {
            foreach ( $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}$t", ARRAY_A ) as $r ) {
                $f[ "$t {$r[ $pk ]}" ] = h( $r );
            }
        }
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
/** A tool call that sets up the next case, in a session of its own. */
function fixture( string $tool, array $args ): array {
    tool( 'session.open', [ 'name' => 'Fixture', 'description' => 'Something for the next case' ] );
    $r = tool( $tool, $args );
    tool( 'session.close', [] );
    return $r;
}
function ecs_id( string $tool, string $name ): string {
    tool( 'session.open', [ 'name' => 'Fixture', 'description' => 'Something for the next case to delete' ] );
    $r = tool( $tool, [ 'name' => $name, 'label' => $name, 'colors' => [ [ 'id' => 'c1', 'value' => '#112233', 'type' => 'custom' ] ], 'fonts' => [ [ 'id' => 'f1', 'type' => 'custom', 'family' => 'Inter' ] ], 'css' => 'body{color:red}' ] );
    tool( 'session.close', [] );
    return (string) ( $r['id'] ?? '' );
}

$first_new_term = 1 + (int) $wpdb->get_var( "SELECT MAX(term_id) FROM {$wpdb->terms}" );
$first_new_post = 1 + (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" );
$first_new_tt   = 1 + (int) $wpdb->get_var( "SELECT MAX(term_taxonomy_id) FROM {$wpdb->term_taxonomy}" );
$files_dir      = wp_normalize_path( wp_upload_dir()['basedir'] . '/aicom-undo-test' );
wp_mkdir_p( $files_dir );
$key = AICOM_Auth::create_key( 'Undo Journal Test', array_values( array_diff( AICOM_Auth::scope_slugs(), [] ) ), [ 'file_paths' => [ $files_dir ] ] );
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key['plain_key'];

// Undo recounts terms, as WordPress does. A count something else left stale (posts deleted with plain SQL) would
// come back right and look like a difference, so every count starts right.
foreach ( get_taxonomies() as $tax ) { // plain SQL: WPML's filters would give only one language's terms
    $tts = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $tax ) ) );
    if ( $tts ) {
        wp_update_term_count_now( $tts, $tax );
    }
}

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

    'media.upload' => fn() => [ 'filename' => 'undo-upload-' . wp_rand() . '.png', 'base64_content' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ],
    'files.upload' => function () use ( $files_dir ) {
        file_put_contents( "$files_dir/undo-replaced.txt", "the file as it was\n" );
        return [ 'path' => $files_dir, 'filename' => 'undo-replaced.txt', 'base64_content' => base64_encode( 'what the AI wrote' ) ];
    },
    'files.delete' => function () use ( $files_dir ) {
        file_put_contents( "$files_dir/undo-deleted.bin", random_bytes( 2048 ) ); // not text: travels as base64
        return [ 'path' => "$files_dir/undo-deleted.bin", 'confirm' => true ];
    },
    // Putting a snapshot back is a change too: undoing it brings back what was there before the restore.
    'backup.post.restore' => function () {
        $p  = mkpost( [ 'post_title' => 'Undo fixture snapshot' ] );
        $id = (int) ( fixture( 'backup.post.create', [ 'post_id' => $p ] )['backup_id'] ?? 0 );
        wp_update_post( [ 'ID' => $p, 'post_title' => 'Undo fixture edited after the snapshot', 'post_content' => 'Edited' ] );
        return [ 'backup_id' => $id, 'confirm' => true ];
    },
    'backup.term.restore' => function () {
        $t  = mkterm( 'category', [ 'description' => 'As in the snapshot' ] );
        $id = (int) ( fixture( 'backup.term.create', [ 'term_id' => $t, 'taxonomy' => 'category' ] )['backup_id'] ?? 0 );
        wp_update_term( $t, 'category', [ 'name' => 'Undo term edited after the snapshot', 'description' => 'Edited' ] );
        return [ 'backup_id' => $id, 'confirm' => true ];
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
    // Back with the same id, role, profile and posts; only the password is new (see "A deleted user comes back").
    'wp.users.delete' => function () {
        $u = mkuser( 'author' );
        update_user_meta( $u, 'description', 'Writes the match reports' );
        mkpost( [ 'post_author' => $u ] );
        mkpost( [ 'post_author' => $u, 'post_status' => 'draft' ] );
        return [ 'id' => $u, 'confirm' => true ];
    },
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
        'ecs.color_schemes.activate_global' => fn() => [ 'id' => ecs_id( 'ecs.color_schemes.save', 'Undo scheme global' ) ],
        'ecs.alt_logos.save' => fn() => [ 'label' => 'Undo logo', 'url' => 'https://example.com/undo-logo.png', 'alt' => 'Undo logo' ],
        'ecs.alt_logos.delete' => function () {
            tool( 'session.open', [ 'name' => 'Fixture', 'description' => 'A logo for the next case to delete' ] );
            $r = tool( 'ecs.alt_logos.save', [ 'label' => 'Undo logo del', 'url' => 'https://example.com/undo-logo-del.png' ] );
            tool( 'session.close', [] );
            return [ 'id' => (string) ( $r['id'] ?? $r['entry']['id'] ?? '' ) ];
        },
        'ecs.drb.presets.save' => fn() => [ 'name' => 'Undo preset', 'config' => [ 'source' => 'json', 'source_config' => [ 'json' => '[{"title":"Row"}]' ], 'mapping' => [ 'title' => 'title' ] ] ],
        'ecs.drb.presets.delete' => function () {
            tool( 'session.open', [ 'name' => 'Fixture', 'description' => 'A preset for the next case to delete' ] );
            $r = tool( 'ecs.drb.presets.save', [ 'name' => 'Undo preset del', 'config' => [ 'source' => 'json', 'source_config' => [ 'json' => '[]' ], 'mapping' => [] ] ] );
            tool( 'session.close', [] );
            return [ 'id' => (string) ( $r['id'] ?? $r['preset']['id'] ?? '' ) ];
        },
        'ecs.drb.bindings.set' => fn() => [ 'post_id' => elementor_page(), 'element_id' => 'w1w2w3w', 'control_key' => 'items', 'source' => 'json', 'source_config' => [ 'json' => '[{"title":"Row"}]' ], 'mapping' => [ 'title' => 'title' ] ],
        'ecs.drb.bindings.clear' => function () {
            $p = elementor_page();
            tool( 'session.open', [ 'name' => 'Fixture', 'description' => 'A binding for the next case to clear' ] );
            tool( 'ecs.drb.bindings.set', [ 'post_id' => $p, 'element_id' => 'w1w2w3w', 'control_key' => 'items', 'source' => 'json', 'source_config' => [ 'json' => '[]' ] ] );
            tool( 'session.close', [] );
            return [ 'post_id' => $p, 'element_id' => 'w1w2w3w', 'control_key' => 'items' ];
        },
    ];
}
if ( AICOM_Tool_Registry::get( 'wpml.post.set_language' ) && AICOM_Module_Detector::is_wpml_active() ) {
    $wl    = array_keys( (array) apply_filters( 'wpml_active_languages', null, [] ) );
    $wdef  = (string) apply_filters( 'wpml_default_language', null );
    $wother = current( array_diff( $wl, [ $wdef ] ) );
    $wpost = static function ( string $lang ) { $p = mkpost(); fixture( 'wpml.post.set_language', [ 'post_id' => $p, 'language' => $lang ] ); return $p; };
    $wterm = static function ( string $lang ) { $t = mkterm( 'category' ); fixture( 'wpml.term.set_language', [ 'term_id' => $t, 'taxonomy' => 'category', 'language' => $lang ] ); return $t; };
    $cases += [
        'wpml.post.set_language' => fn() => [ 'post_id' => $wpost( $wdef ), 'language' => $wother ],
        'wpml.post.link_translation' => fn() => [ 'translations' => [ $wdef => $wpost( $wdef ), $wother => $wpost( $wother ) ], 'confirm' => true ],
        'wpml.post.unlink_translation' => function () use ( $wpost, $wdef, $wother ) {
            $a = $wpost( $wdef );
            fixture( 'wpml.post.link_translation', [ 'translations' => [ $wdef => $a, $wother => $wpost( $wother ) ], 'confirm' => true ] );
            return [ 'post_id' => $a, 'confirm' => true ];
        },
        'wpml.term.set_language' => fn() => [ 'term_id' => $wterm( $wdef ), 'taxonomy' => 'category', 'language' => $wother ],
        'wpml.term.link_translation' => fn() => [ 'taxonomy' => 'category', 'translations' => [ $wdef => $wterm( $wdef ), $wother => $wterm( $wother ) ], 'confirm' => true ],
    ];
}
$skill = static function ( string $status = 'draft' ): int {
    $r = fixture( 'skills.create', [ 'slug' => 'undo_skill_' . wp_rand(), 'name' => 'Undo skill', 'description' => 'A Skill for the next case', 'steps' => [ [ 'tool' => 'wp.site.info', 'args' => [] ] ] ] );
    $id = (int) ( $r['id'] ?? 0 );
    if ( $status === 'active' ) {
        fixture( 'skills.activate', [ 'id' => $id ] );
    }
    return $id;
};
$cases += [
    'skills.create' => fn() => [ 'slug' => 'undo_skill_new', 'name' => 'Undo new skill', 'description' => 'Made by the case', 'tags' => [ 'undo' ] ],
    'skills.activate' => fn() => [ 'id' => $skill() ],
    'skills.update' => fn() => [ 'id' => $skill(), 'name' => 'Undo skill renamed', 'description' => 'Changed', 'tags' => [ 'changed' ] ],
    'skills.archive' => fn() => [ 'id' => $skill( 'active' ) ],
    'skills.delete' => function () use ( $skill ) { $id = $skill(); fixture( 'skills.update', [ 'id' => $id, 'name' => 'Undo skill v2' ] ); return [ 'id' => $id, 'confirm' => true ]; },
    'skills.import' => fn() => [ 'slug' => 'undo_skill_imported', 'name' => 'Undo imported skill', 'steps' => [ [ 'tool' => 'wp.site.info', 'args' => [] ] ] ],
    'skills.propose_update' => fn() => [ 'skill_id' => $skill( 'active' ), 'name' => 'Undo skill proposal', 'rationale' => 'A better way' ],
    'skills.suggestions' => fn() => [ 'action' => get_option( 'aicom_skill_suggestions', '1' ) === '1' ? 'disable' : 'enable' ],
];
if ( defined( 'ELEMENTOR_VERSION' ) ) {
    $cases += [
        'elementor.widget.update_field' => fn() => [ 'post_id' => elementor_page(), 'widget_id' => 'w1w2w3w', 'field' => 'title', 'value' => 'Changed heading' ],
        'elementor.page.bulk_update_texts' => fn() => [ 'post_id' => elementor_page(), 'updates' => [ [ 'widget_id' => 'w1w2w3w', 'field' => 'title', 'text' => 'Bulk heading' ] ] ],
        'elementor.page.create_from_template' => fn() => [ 'source_post_id' => elementor_page(), 'title' => 'Undo copy', 'status' => 'draft' ],
        'elementor.page.restore' => function () {
            $p  = elementor_page();
            $id = (int) ( fixture( 'elementor.page.backup', [ 'post_id' => $p ] )['backup_id'] ?? 0 );
            update_post_meta( $p, '_elementor_data', wp_slash( '[{"id":"a1b2c3d","elType":"container","settings":[],"elements":[]}]' ) );
            return [ 'backup_id' => $id, 'confirm' => true ];
        },
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

echo "\n══ A deleted user comes back, with a new password ══\n";
$u     = mkuser( 'editor' );
$login = get_userdata( $u )->user_login;
wp_set_password( 'the-old-password', $u );
$old_hash = (string) $wpdb->get_var( $wpdb->prepare( "SELECT user_pass FROM {$wpdb->users} WHERE ID = %d", $u ) );
update_user_meta( $u, 'session_tokens', [ 'abc' => [ 'expiration' => time() + 3600 ] ] ); // logged in somewhere
$sid = (int) ( tool( 'session.open', [ 'name' => 'Delete a user', 'description' => 'Delete a user, then undo it' ] )['session_id'] ?? 0 );
$r   = tool( 'wp.users.delete', [ 'id' => $u, 'confirm' => true ] );
tool( 'session.close', [] );
ok( 'the call says it can be undone in full', ( $r['undo']['complete'] ?? null ) === true, wp_json_encode( $r['undo'] ?? $r ) );
AICOM_Admin::$restore_report = [ 'kept' => [], 'failed' => [], 'password' => [] ];
AICOM_Admin::do_restore_session( $sid );
clean_user_cache( $u );
$back     = get_userdata( $u );
$new_hash = (string) $wpdb->get_var( $wpdb->prepare( "SELECT user_pass FROM {$wpdb->users} WHERE ID = %d", $u ) );
ok( 'same id, login and role', $back && $back->user_login === $login && in_array( 'editor', $back->roles, true ) );
ok( 'a new password, which nobody knows: the old one no longer works', $new_hash !== '' && $new_hash !== $old_hash && ! wp_check_password( 'the-old-password', $new_hash, $u ) );
ok( 'not logged in anywhere', ! get_user_meta( $u, 'session_tokens', true ) );
ok( 'Restore session lists the user, to set their password again', AICOM_Admin::$restore_report['password'] === [ "user $u ($login)" ] && ! AICOM_Admin::$restore_report['kept'], wp_json_encode( AICOM_Admin::$restore_report ) );

$u     = mkuser();
$login = get_userdata( $u )->user_login;
$sid   = (int) ( tool( 'session.open', [ 'name' => 'Delete a user', 'description' => 'Delete a user; someone else takes the login' ] )['session_id'] ?? 0 );
tool( 'wp.users.delete', [ 'id' => $u, 'confirm' => true ] );
tool( 'session.close', [] );
$taken           = wp_insert_user( [ 'user_login' => $login, 'user_email' => "other-$login@example.com", 'user_pass' => wp_generate_password() ] );
$made['user'][]  = $taken;
AICOM_Admin::$restore_report = [ 'kept' => [], 'failed' => [], 'password' => [] ];
AICOM_Admin::do_restore_session( $sid );
// (user_count is kept too: the new user changed it after the AI.)
$of_user = array_values( array_filter( AICOM_Admin::$restore_report['kept'], static fn( $k ) => strpos( $k, "user $u" ) === 0 ) );
ok( 'its login is someone else\'s now: it stays away, reported once', ! get_userdata( $u ) && $of_user === [ "user $u" ] && ! AICOM_Admin::$restore_report['password'], wp_json_encode( AICOM_Admin::$restore_report ) );
ok( '... and none of its meta is written', ! (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d", $u ) ) );
ok( 'the new user is untouched', get_userdata( $taken )->user_email === "other-$login@example.com" );

echo "\n══ Files ══\n";
$sid = (int) ( tool( 'session.open', [ 'name' => 'Files', 'description' => 'A new file, and one a person changes after the AI' ] )['session_id'] ?? 0 );
@unlink( "$files_dir/undo-brand-new.txt" );
file_put_contents( "$files_dir/undo-person.txt", 'before' );
tool( 'files.upload', [ 'path' => $files_dir, 'filename' => 'undo-brand-new.txt', 'base64_content' => base64_encode( 'new' ) ] );
$r = tool( 'files.upload', [ 'path' => $files_dir, 'filename' => 'undo-person.txt', 'base64_content' => base64_encode( 'AI' ) ] );
tool( 'session.close', [] );
file_put_contents( "$files_dir/undo-person.txt", 'the person' );
AICOM_Admin::$restore_report = [ 'kept' => [], 'failed' => [] ];
AICOM_Admin::do_restore_session( $sid );
ok( 'a file the AI made is gone again', ! file_exists( "$files_dir/undo-brand-new.txt" ) );
ok( "a file a person changed since keeps the person's version, reported", file_get_contents( "$files_dir/undo-person.txt" ) === 'the person' && in_array( 'file undo-person.txt', AICOM_Admin::$restore_report['kept'], true ), wp_json_encode( AICOM_Admin::$restore_report ) );
$big = "$files_dir/undo-big.bin";
file_put_contents( $big, str_repeat( 'x', 5 * MB_IN_BYTES ) );
tool( 'session.open', [ 'name' => 'Big file', 'description' => 'A file too large to keep' ] );
$r = tool( 'files.delete', [ 'path' => $big, 'confirm' => true ] );
tool( 'session.close', [] );
ok( 'a file too large to keep: the undo is incomplete and says why', ( $r['undo']['complete'] ?? null ) === false && strpos( implode( ' ', $r['undo']['reasons'] ?? [] ), 'too large' ) !== false, wp_json_encode( $r['undo'] ?? $r ) );

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
$drb_index = get_option( 'ecs_drb_binding_index', null ); // bindings of fixture pages, deleted above
if ( is_array( $drb_index ) ) {
    $alive = array_filter( $drb_index, static fn( $post_id ) => (bool) get_post( (int) $post_id ) );
    if ( count( $alive ) !== count( $drb_index ) ) {
        update_option( 'ecs_drb_binding_index', $alive, false );
    }
}
foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_title IN ('Undo created','Undo copy','Undo new product') OR post_title LIKE 'Undo fixture%'" ) as $id ) {
    wp_delete_post( (int) $id, true ); // what the tools created, left in the trash by Undo
}
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'icl_translations' ) ) ) {
    // WPML leaves the translation rows of deleted fixtures behind (its delete hooks don't run here).
    $wpdb->query( $wpdb->prepare( "DELETE t FROM {$wpdb->prefix}icl_translations t LEFT JOIN {$wpdb->posts} p ON p.ID = t.element_id WHERE t.element_type LIKE 'post\\_%%' AND t.element_id >= %d AND p.ID IS NULL", $first_new_post ) );
    $wpdb->query( $wpdb->prepare( "DELETE t FROM {$wpdb->prefix}icl_translations t LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = t.element_id WHERE t.element_type LIKE 'tax\\_%%' AND t.element_id >= %d AND tt.term_taxonomy_id IS NULL", $first_new_tt ) );
}
foreach ( (array) glob( "$files_dir/*" ) as $file ) { // file fixtures
    @unlink( $file );
}
@rmdir( $files_dir );
foreach ( $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}aicom_skills WHERE slug LIKE 'undo\\_skill%'" ) as $id ) { // Skill fixtures
    $wpdb->delete( $wpdb->prefix . 'aicom_skill_revisions', [ 'skill_id' => (int) $id ] );
    $wpdb->delete( $wpdb->prefix . 'aicom_skills', [ 'id' => (int) $id ] );
}
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}aicom_backups WHERE api_key_id = %d", $key['id'] ) );
$wpdb->delete( $wpdb->prefix . 'aicom_sessions', [ 'api_key_id' => $key['id'] ] );
AICOM_Auth::revoke_key( $key['id'] );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
