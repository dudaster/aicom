<?php
defined( 'ABSPATH' ) || exit;

/**
 * Curated task recipes — step-by-step guides for common WordPress operations.
 *
 * Each recipe is filtered by:
 *  1. required_scope  — the API key must hold this scope (empty = any key)
 *  2. required_module — the plugin must be active (empty = always available)
 *
 * aicom.recipes tool calls get_for_key() / all_for_key() so the model only
 * sees recipes it can actually execute with the connected key.
 */
class AICOM_Recipes {

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Return recipes matching $task keyword, filtered to what $key_record can do.
     */
    public static function get_for_key( string $task, array $key_record ): array {
        $key_scopes = self::expanded_scopes( $key_record );
        $results    = [];

        foreach ( self::all() as $recipe ) {
            if ( ! self::keyword_match( $task, $recipe['task_keywords'] ) ) {
                continue;
            }
            if ( ! self::is_accessible( $recipe, $key_scopes ) ) {
                continue;
            }
            $results[] = self::render( $recipe );
        }

        return $results;
    }

    /**
     * Return all recipes accessible to $key_record (no keyword filter).
     */
    public static function all_for_key( array $key_record ): array {
        $key_scopes = self::expanded_scopes( $key_record );
        $results    = [];

        foreach ( self::all() as $recipe ) {
            if ( self::is_accessible( $recipe, $key_scopes ) ) {
                $results[] = self::render( $recipe );
            }
        }

        return $results;
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    private static function expanded_scopes( array $key_record ): array {
        $raw = json_decode( $key_record['scopes_json'] ?? '[]', true ) ?: [];
        return AICOM_Auth::expand_implied_scopes( $raw );
    }

    private static function is_accessible( array $recipe, array $key_scopes ): bool {
        if ( $recipe['required_scope'] !== '' && ! in_array( $recipe['required_scope'], $key_scopes, true ) ) {
            return false;
        }
        if ( $recipe['required_module'] !== '' && ! self::module_active( $recipe['required_module'] ) ) {
            return false;
        }
        return true;
    }

    private static function module_active( string $module ): bool {
        switch ( $module ) {
            case 'woocommerce': return AICOM_Module_Detector::is_woocommerce_active();
            case 'elementor':   return AICOM_Module_Detector::is_elementor_active();
            case 'polylang':    return AICOM_Module_Detector::is_polylang_active();
            case 'ecs':         return AICOM_Module_Detector::is_ecs_active();
            case 'clautron':    return AICOM_Module_Detector::is_clautron_active();
            case 'yoast':       return AICOM_Module_Detector::is_yoast_active();
            default:            return false;
        }
    }

    private static function keyword_match( string $task, array $keywords ): bool {
        $task = strtolower( trim( $task ) );
        foreach ( $keywords as $kw ) {
            if ( stripos( $kw, $task ) !== false || stripos( $task, $kw ) !== false ) {
                return true;
            }
        }
        return false;
    }

    private static function render( array $recipe ): array {
        return [
            'id'    => $recipe['id'],
            'title' => $recipe['title'],
            'steps' => $recipe['steps'],
            'tip'   => $recipe['tip'] ?? '',
        ];
    }

    // ── Recipe definitions ────────────────────────────────────────────────────

    private static function all(): array {
        return [

            // ── Core — available to any valid key ────────────────────────────

            [
                'id'              => 'session_workflow',
                'title'           => 'How to start: open a session',
                'task_keywords'   => [ 'how to start', 'session', 'workflow', 'begin', 'get started' ],
                'required_scope'  => '',
                'required_module' => '',
                'steps'           => [
                    [ 'tool' => 'tools/list',    'args' => '(no args) — discover what tools are available' ],
                    [ 'tool' => 'session.open',  'args' => 'name: "My task", description: "What I plan to do and why"' ],
                    [ 'tool' => '(your tool)',   'args' => 'run any write tool after the session is open' ],
                    [ 'tool' => 'session.close', 'args' => 'summary: "What was done"' ],
                ],
                'tip' => 'Always close the session when done — AICOM analyzes it for reusable skill suggestions.',
            ],

            [
                'id'              => 'create_post',
                'title'           => 'Create a blog post',
                'task_keywords'   => [ 'create post', 'blog post', 'new article', 'write post', 'new post', 'draft post' ],
                'required_scope'  => 'write.wp.posts',
                'required_module' => '',
                'steps'           => [
                    [ 'tool' => 'session.open',    'args' => 'name: "Create post", description: "Draft and publish <title>"' ],
                    [ 'tool' => 'wp.posts.create', 'args' => 'title, content, status: "draft", post_type: "post"' ],
                ],
                'tip' => 'Use status:"draft" first. Review the result, then call wp.posts.update(id, status:"publish") to go live.',
            ],

            [
                'id'              => 'update_post',
                'title'           => 'Edit an existing post',
                'task_keywords'   => [ 'edit post', 'update post', 'modify post', 'change post', 'fix post' ],
                'required_scope'  => 'write.wp.posts',
                'required_module' => '',
                'steps'           => [
                    [ 'tool' => 'wp.posts.list',   'args' => 'search: "<title or keyword>", post_type: "post"' ],
                    [ 'tool' => 'wp.posts.get',    'args' => 'id: <post_id>' ],
                    [ 'tool' => 'session.open',    'args' => 'name: "Edit post", description: "Update <title>"' ],
                    [ 'tool' => 'wp.posts.update', 'args' => 'id: <post_id>, (fields to change)' ],
                ],
                'tip' => 'Always read the post first (wp.posts.get) so you only change what is needed.',
            ],

            [
                'id'              => 'upload_media',
                'title'           => 'Upload an image or file',
                'task_keywords'   => [ 'upload image', 'add media', 'upload file', 'upload photo', 'media library' ],
                'required_scope'  => 'manage.media',
                'required_module' => '',
                'steps'           => [
                    [ 'tool' => 'session.open',   'args' => 'name: "Upload media", description: "Add <filename> to media library"' ],
                    [ 'tool' => 'media.upload',   'args' => 'url: "<public image URL>", title, alt_text' ],
                ],
                'tip' => 'media.upload accepts a public URL and downloads it server-side. Returns attachment_id for use in wp.posts.update.',
            ],

            [
                'id'              => 'attach_media_to_post',
                'title'           => 'Set a featured image on a post',
                'task_keywords'   => [ 'attach image', 'featured image', 'add image to post', 'post thumbnail', 'set thumbnail' ],
                'required_scope'  => 'manage.media',
                'required_module' => '',
                'steps'           => [
                    [ 'tool' => 'session.open',    'args' => 'name: "Set featured image", description: "Attach image to post <id>"' ],
                    [ 'tool' => 'media.upload',    'args' => 'url: "<public image URL>", alt_text: "<description>"' ],
                    [ 'tool' => 'wp.posts.update', 'args' => 'id: <post_id>, featured_media: <attachment_id>' ],
                ],
                'tip' => 'featured_media accepts the attachment_id returned by media.upload.',
            ],

            [
                'id'              => 'manage_menus',
                'title'           => 'Update a navigation menu',
                'task_keywords'   => [ 'menu', 'navigation', 'nav', 'add menu item', 'nav menu' ],
                'required_scope'  => 'manage.menus',
                'required_module' => '',
                'steps'           => [
                    [ 'tool' => 'wp.menus.list',   'args' => '(no args)' ],
                    [ 'tool' => 'wp.menus.get',    'args' => 'id: <menu_id>' ],
                    [ 'tool' => 'session.open',    'args' => 'name: "Update menu", description: "Add/remove items in <menu_name>"' ],
                    [ 'tool' => 'wp.menus.update', 'args' => 'id: <menu_id>, items: [...]' ],
                ],
            ],

            // ── Backup ───────────────────────────────────────────────────────

            [
                'id'              => 'backup_snapshot',
                'title'           => 'Create a backup snapshot before making changes',
                'task_keywords'   => [ 'backup', 'snapshot', 'before changes', 'safety', 'save state' ],
                'required_scope'  => 'manage.backups',
                'required_module' => '',
                'steps'           => [
                    [ 'tool' => 'session.open',    'args' => 'name: "Backup", description: "Snapshot before <upcoming change>"' ],
                    [ 'tool' => 'backup.create',   'args' => 'label: "<short description>"' ],
                ],
                'tip' => 'backup.create snapshots all posts and meta. Use backup.restore to roll back if something goes wrong.',
            ],

            // ── Users ─────────────────────────────────────────────────────────

            [
                'id'              => 'list_users',
                'title'           => 'List site users',
                'task_keywords'   => [ 'list users', 'find user', 'user roles', 'who has access', 'site users' ],
                'required_scope'  => 'read.users',
                'required_module' => '',
                'steps'           => [
                    [ 'tool' => 'wp.users.list', 'args' => 'role: "<role>" (optional), search: "<name or email>" (optional)' ],
                ],
            ],

            [
                'id'              => 'create_user',
                'title'           => 'Create a new user',
                'task_keywords'   => [ 'create user', 'add user', 'new account', 'new user', 'register user' ],
                'required_scope'  => 'manage.users',
                'required_module' => '',
                'steps'           => [
                    [ 'tool' => 'session.open',    'args' => 'name: "Create user", description: "Add <name> as <role>"' ],
                    [ 'tool' => 'wp.users.create', 'args' => 'username, email, role: "editor|author|contributor|subscriber"' ],
                ],
            ],

            // ── A11y ─────────────────────────────────────────────────────────

            [
                'id'              => 'audit_alt_text',
                'title'           => 'Audit and fix missing alt text on images',
                'task_keywords'   => [ 'alt text', 'accessibility', 'a11y', 'images seo', 'missing alt', 'image description' ],
                'required_scope'  => 'manage.a11y',
                'required_module' => '',
                'steps'           => [
                    [ 'tool' => 'a11y.site_report',    'args' => '(no args) — find all images missing alt text' ],
                    [ 'tool' => 'session.open',         'args' => 'name: "Fix alt text", description: "Add alt text to top missing images"' ],
                    [ 'tool' => 'a11y.set_image_alt',   'args' => 'attachment_id: <id>, alt_text: "<description>" — repeat per image' ],
                ],
                'tip' => 'Run a11y.site_report first (no session needed). Then open a session and fix in batches.',
            ],

            [
                'id'              => 'audit_post_a11y',
                'title'           => 'Audit accessibility of a single post',
                'task_keywords'   => [ 'post accessibility', 'heading structure', 'a11y audit', 'check headings', 'accessibility report' ],
                'required_scope'  => 'manage.a11y',
                'required_module' => '',
                'steps'           => [
                    [ 'tool' => 'a11y.audit_post', 'args' => 'post_id: <id>' ],
                ],
                'tip' => 'a11y.audit_post is a read-only check — no session needed. Returns heading structure, image alt coverage, and contrast warnings.',
            ],

            // ── Skills ───────────────────────────────────────────────────────

            [
                'id'              => 'run_saved_skill',
                'title'           => 'Find and run a saved Skill',
                'task_keywords'   => [ 'run skill', 'execute skill', 'saved workflow', 'skill', 'automation' ],
                'required_scope'  => 'read.skills',
                'required_module' => '',
                'steps'           => [
                    [ 'tool' => 'skills.list',  'args' => 'search: "<keyword>", status: "active"' ],
                    [ 'tool' => 'skills.run',   'args' => 'id: <skill_id>, inputs: {<input_schema values>}' ],
                ],
                'tip' => 'skills.run returns the full instructions for the AI to follow — execute the steps it describes.',
            ],

            [
                'id'              => 'create_skill',
                'title'           => 'Save a workflow as a reusable Skill',
                'task_keywords'   => [ 'create skill', 'save workflow', 'automate task', 'new skill', 'reusable' ],
                'required_scope'  => 'manage.skills',
                'required_module' => '',
                'steps'           => [
                    [ 'tool' => 'skills.match',    'args' => 'name: "<proposed name>", description: "<what it does>" — check for duplicates first' ],
                    [ 'tool' => 'session.open',    'args' => 'name: "Create skill", description: "Save workflow as <skill_name>"' ],
                    [ 'tool' => 'skills.create',   'args' => 'slug, name, description, steps: [...], type: "simple"' ],
                    [ 'tool' => 'skills.activate', 'args' => 'id: <skill_id> — only after user confirms' ],
                ],
                'tip' => 'Always call skills.match first to avoid duplicates. New skills are saved as draft — ask the user before activating.',
            ],

            // ── WooCommerce ───────────────────────────────────────────────────

            [
                'id'              => 'woo_browse',
                'title'           => 'Browse WooCommerce products and categories',
                'task_keywords'   => [ 'list products', 'browse woocommerce', 'product catalog', 'woocommerce products', 'product list' ],
                'required_scope'  => 'read.woocommerce',
                'required_module' => 'woocommerce',
                'steps'           => [
                    [ 'tool' => 'wc.categories.list', 'args' => '(no args)' ],
                    [ 'tool' => 'wc.products.list',   'args' => 'category: <id> (optional), search: "<name>" (optional), per_page: 20' ],
                ],
                'tip' => 'These are read-only — no session needed. Use wc.products.get(id) to fetch a single product in full.',
            ],

            [
                'id'              => 'woo_create_product',
                'title'           => 'Create a WooCommerce product',
                'task_keywords'   => [ 'create product', 'add product', 'new product', 'woocommerce product', 'add woocommerce' ],
                'required_scope'  => 'manage.woocommerce.products',
                'required_module' => 'woocommerce',
                'steps'           => [
                    [ 'tool' => 'wc.categories.list',   'args' => '(no args) — find the right category id' ],
                    [ 'tool' => 'session.open',          'args' => 'name: "Create product", description: "Add <product name> to WooCommerce"' ],
                    [ 'tool' => 'wc.products.create',    'args' => 'name, regular_price, description, categories: [{id}], status: "draft"' ],
                ],
                'tip' => 'Create as draft first. After reviewing, call wc.products.update(id, status:"publish") to go live.',
            ],

            [
                'id'              => 'woo_update_stock',
                'title'           => 'Bulk update WooCommerce product stock',
                'task_keywords'   => [ 'stock', 'inventory', 'update stock', 'low stock', 'product inventory', 'woocommerce stock' ],
                'required_scope'  => 'manage.woocommerce.products',
                'required_module' => 'woocommerce',
                'steps'           => [
                    [ 'tool' => 'wc.products.list',   'args' => 'per_page: 50, status: "publish"' ],
                    [ 'tool' => 'session.open',        'args' => 'name: "Update stock", description: "Adjust inventory levels"' ],
                    [ 'tool' => 'wc.products.update',  'args' => 'id: <id>, stock_quantity: <qty>, manage_stock: true — repeat per product' ],
                ],
            ],

            // ── Elementor ─────────────────────────────────────────────────────

            [
                'id'              => 'elementor_read_page',
                'title'           => 'Read an Elementor page structure',
                'task_keywords'   => [ 'read elementor page', 'inspect page', 'page tree', 'elementor structure', 'page widgets' ],
                'required_scope'  => 'read.elementor',
                'required_module' => 'elementor',
                'steps'           => [
                    [ 'tool' => 'wp.posts.list',           'args' => 'post_type: "page", search: "<page title>"' ],
                    [ 'tool' => 'elementor.page.get_tree', 'args' => 'post_id: <id>' ],
                    [ 'tool' => 'elementor.page.get_texts','args' => 'post_id: <id>' ],
                ],
                'tip' => 'These are read-only — no session needed. get_texts returns all editable text blocks indexed by widget_id.',
            ],

            [
                'id'              => 'elementor_edit_texts',
                'title'           => 'Edit text content on an Elementor page',
                'task_keywords'   => [ 'edit elementor texts', 'update page copy', 'landing page', 'elementor edit', 'change elementor text' ],
                'required_scope'  => 'manage.elementor',
                'required_module' => 'elementor',
                'steps'           => [
                    [ 'tool' => 'elementor.page.get_texts',  'args' => 'post_id: <id> — read all text blocks first' ],
                    [ 'tool' => 'elementor.page.validate',   'args' => 'post_id: <id>, changes: [{widget_id, content}] — dry-run check' ],
                    [ 'tool' => 'session.open',               'args' => 'name: "Edit Elementor page", description: "Update copy on <page title>"' ],
                    [ 'tool' => 'elementor.page.set_texts',   'args' => 'post_id: <id>, changes: [{widget_id, content}]' ],
                ],
                'tip' => 'Always call get_texts first to get widget IDs. Use validate before set_texts to catch structural errors.',
            ],

            // ── ECS (Elementor Color/Font Schemes) ────────────────────────────

            [
                'id'              => 'ecs_apply_colors',
                'title'           => 'Apply a color scheme site-wide (ECS)',
                'task_keywords'   => [ 'color scheme', 'ecs colors', 'brand colors', 'apply theme', 'site colors', 'ecs' ],
                'required_scope'  => 'manage.elementor',
                'required_module' => 'ecs',
                'steps'           => [
                    [ 'tool' => 'ecs.color_schemes.list',            'args' => '(no args)' ],
                    [ 'tool' => 'session.open',                       'args' => 'name: "Apply color scheme", description: "Set <scheme_name> as global theme"' ],
                    [ 'tool' => 'ecs.color_schemes.activate_global', 'args' => 'name: "<scheme_name>"' ],
                ],
                'tip' => 'activate_global creates an AICOM-managed Custom Look with a general condition (always on). The previous active look remains but is overridden.',
            ],

            [
                'id'              => 'ecs_change_fonts',
                'title'           => 'Change the site font scheme (ECS)',
                'task_keywords'   => [ 'font scheme', 'ecs fonts', 'typography', 'change font', 'site fonts', 'font family' ],
                'required_scope'  => 'manage.elementor',
                'required_module' => 'ecs',
                'steps'           => [
                    [ 'tool' => 'ecs.font_schemes.list', 'args' => '(no args)' ],
                    [ 'tool' => 'ecs.font_schemes.get',  'args' => 'name: "<scheme_name>" — inspect current fonts' ],
                    [ 'tool' => 'session.open',           'args' => 'name: "Change fonts", description: "Update font scheme <name>"' ],
                    [ 'tool' => 'ecs.font_schemes.save', 'args' => 'id: <existing_id>, fonts: [{id, family, weight, ...}]' ],
                ],
            ],

            // ── Polylang ──────────────────────────────────────────────────────

            [
                'id'              => 'pll_view_languages',
                'title'           => 'List configured Polylang languages',
                'task_keywords'   => [ 'languages', 'polylang languages', 'what languages', 'site languages', 'translations available' ],
                'required_scope'  => 'read.polylang',
                'required_module' => 'polylang',
                'steps'           => [
                    [ 'tool' => 'pll.languages.list', 'args' => '(no args)' ],
                ],
                'tip' => 'Read-only — no session needed. Returns locale, slug, and default_locale for each language.',
            ],

            [
                'id'              => 'pll_translate_post',
                'title'           => 'Create a translated version of a post (Polylang)',
                'task_keywords'   => [ 'translate post', 'polylang', 'multilingual', 'translation', 'translated post', 'language version' ],
                'required_scope'  => 'manage.polylang',
                'required_module' => 'polylang',
                'steps'           => [
                    [ 'tool' => 'pll.languages.list',        'args' => '(no args) — get language slugs' ],
                    [ 'tool' => 'wp.posts.get',              'args' => 'id: <original_post_id> — read original content' ],
                    [ 'tool' => 'session.open',               'args' => 'name: "Translate post", description: "Create <lang> version of post <id>"' ],
                    [ 'tool' => 'wp.posts.create',           'args' => 'title: "<translated title>", content: "<translated content>", status: "draft"' ],
                    [ 'tool' => 'pll.post.set_language',     'args' => 'post_id: <new_id>, language: "<lang_slug>"' ],
                    [ 'tool' => 'pll.post.link_translation', 'args' => 'original_id: <original_id>, translation_id: <new_id>' ],
                ],
                'tip' => 'Always link the translation — without pll.post.link_translation, Polylang treats it as a standalone post, not a translation.',
            ],

            // ── Yoast SEO ─────────────────────────────────────────────────────

            [
                'id'              => 'yoast_update_post_seo',
                'title'           => 'Update Yoast SEO title and meta description for a post',
                'task_keywords'   => [ 'yoast', 'seo title', 'meta description', 'seo post', 'update seo', 'yoast seo' ],
                'required_scope'  => 'manage.yoast',
                'required_module' => 'yoast',
                'steps'           => [
                    [ 'tool' => 'wp.posts.get',  'args' => 'id: <post_id> — read current content' ],
                    [ 'tool' => 'session.open',   'args' => 'name: "Update SEO", description: "Set Yoast meta for post <id>"' ],
                    [ 'tool' => 'wp.meta.set',    'args' => 'post_id: <id>, key: "_yoast_wpseo_title", value: "<SEO title>"' ],
                    [ 'tool' => 'wp.meta.set',    'args' => 'post_id: <id>, key: "_yoast_wpseo_metadesc", value: "<meta description max 160 chars>"' ],
                ],
                'tip' => 'Yoast title variables like %%title%% %%sep%% %%sitename%% are supported in _yoast_wpseo_title. Keep meta description under 160 characters.',
            ],

            [
                'id'              => 'yoast_bulk_seo',
                'title'           => 'Bulk update Yoast SEO fields for multiple posts',
                'task_keywords'   => [ 'bulk seo', 'yoast bulk', 'all posts seo', 'mass update seo', 'seo all pages' ],
                'required_scope'  => 'manage.yoast',
                'required_module' => 'yoast',
                'steps'           => [
                    [ 'tool' => 'wp.posts.list', 'args' => 'post_type: "post", per_page: 20 (repeat with offset for pagination)' ],
                    [ 'tool' => 'session.open',   'args' => 'name: "Bulk SEO update", description: "Update Yoast meta for <N> posts"' ],
                    [ 'tool' => 'wp.meta.set',    'args' => 'post_id: <id>, key: "_yoast_wpseo_title", value: "<title>" — repeat per post' ],
                    [ 'tool' => 'wp.meta.set',    'args' => 'post_id: <id>, key: "_yoast_wpseo_metadesc", value: "<desc>" — repeat per post' ],
                ],
                'tip' => 'Process in batches of 20 posts. Call wp.posts.list with offset to page through all posts.',
            ],

            // ── Clautron ──────────────────────────────────────────────────────

            [
                'id'              => 'clautron_install',
                'title'           => 'Install a Clautron component from the catalog',
                'task_keywords'   => [ 'clautron', 'install component', 'add capability', 'clautron catalog', 'install clautron' ],
                'required_scope'  => 'manage.clautron',
                'required_module' => 'clautron',
                'steps'           => [
                    [ 'tool' => 'clautron.catalog.list',    'args' => '(no args) — see available components' ],
                    [ 'tool' => 'session.open',              'args' => 'name: "Install component", description: "Add <component_name>"' ],
                    [ 'tool' => 'clautron.catalog.install', 'args' => 'component: "<component_id>"' ],
                ],
                'tip' => 'After install, call clautron.capability.meta.get to see the activated capability and its configuration options.',
            ],

            [
                'id'              => 'clautron_blueprint',
                'title'           => 'Create a Clautron automation blueprint',
                'task_keywords'   => [ 'clautron blueprint', 'create blueprint', 'event automation', 'clautron automation', 'blueprint' ],
                'required_scope'  => 'manage.clautron',
                'required_module' => 'clautron',
                'steps'           => [
                    [ 'tool' => 'clautron.primitives.list',    'args' => '(no args) — see available primitives' ],
                    [ 'tool' => 'clautron.blueprint.examples', 'args' => '(no args) — see example blueprints for reference' ],
                    [ 'tool' => 'clautron.blueprint.validate', 'args' => 'blueprint: {draft JSON} — check for errors before creating' ],
                    [ 'tool' => 'session.open',                 'args' => 'name: "Create blueprint", description: "<what this blueprint does>"' ],
                    [ 'tool' => 'clautron.blueprint.create',   'args' => 'blueprint: {validated JSON}' ],
                    [ 'tool' => 'clautron.blueprint.smoke_test','args' => 'blueprint_id: <id> — verify the blueprint runs' ],
                ],
                'tip' => 'Always call blueprint.examples first — it shows the structure Clautron expects. Validate before creating to catch schema errors early.',
            ],

        ];
    }
}
