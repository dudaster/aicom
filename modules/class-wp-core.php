<?php
/**
 * WordPress Core Ops module.
 * Covers: site info, post types, taxonomies, posts, terms, meta, options.
 * 22 tools total.
 */
class AICOM_Module_WP_Core extends AICOM_Module_Base {

    public function get_module_name(): string {
        return 'wp_core';
    }

    public function register_tools(): void {
        // ── Discovery / Public ─────────────────────────────────────────────
        $this->register( 'server.status', [
            'class'           => 'public',
            'required_scopes' => [],
            'description'     => 'Returns server status, lock state, and active modules.',
            'handler'         => [ $this, 'handle_server_status' ],
        ] );

        $this->register( 'tools/list', [
            'class'           => 'discovery',
            'required_scopes' => [],
            'description'     => 'Lists all available tools for this API key.',
            'handler'         => [ $this, 'handle_tools_list' ],
        ] );

        $this->register( 'wp.site.info', [
            'class'           => 'discovery',
            'required_scopes' => [ 'read.wp' ],
            'description'     => 'Returns site name, URL, WP version, timezone, active theme.',
            'handler'         => [ $this, 'handle_site_info' ],
        ] );

        $this->register( 'wp.post_types.list', [
            'class'           => 'discovery',
            'required_scopes' => [ 'read.wp' ],
            'description'     => 'Lists all registered public post types.',
            'handler'         => [ $this, 'handle_post_types_list' ],
        ] );

        $this->register( 'wp.taxonomies.list', [
            'class'           => 'discovery',
            'required_scopes' => [ 'read.wp' ],
            'description'     => 'Lists all registered taxonomies.',
            'handler'         => [ $this, 'handle_taxonomies_list' ],
        ] );

        // ── Read ───────────────────────────────────────────────────────────
        $this->register( 'wp.posts.list', [
            'class'           => 'read',
            'required_scopes' => [ 'read.wp' ],
            'description'     => 'List posts/pages/CPT with filters (post_type, status, search, lang).',
            'input_schema'    => [
                'post_type'      => [ 'type' => 'string', 'default' => 'post' ],
                'status'         => [ 'type' => 'string', 'default' => 'any' ],
                'search'         => [ 'type' => 'string' ],
                'lang'           => [ 'type' => 'string' ],
                'posts_per_page' => [ 'type' => 'integer', 'default' => 20 ],
                'paged'          => [ 'type' => 'integer', 'default' => 1 ],
            ],
            'handler'         => [ $this, 'handle_posts_list' ],
        ] );

        $this->register( 'wp.posts.get', [
            'class'           => 'read',
            'required_scopes' => [ 'read.wp' ],
            'description'     => 'Get full post data by ID.',
            'input_schema'    => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
            'handler'         => [ $this, 'handle_posts_get' ],
        ] );

        $this->register( 'wp.terms.list', [
            'class'           => 'read',
            'required_scopes' => [ 'read.wp' ],
            'description'     => 'List terms for a taxonomy.',
            'input_schema'    => [ 'taxonomy' => [ 'type' => 'string', 'required' => true ] ],
            'handler'         => [ $this, 'handle_terms_list' ],
        ] );

        $this->register( 'wp.terms.get', [
            'class'           => 'read',
            'required_scopes' => [ 'read.wp' ],
            'description'     => 'Get a term by ID.',
            'input_schema'    => [
                'term_id'  => [ 'type' => 'integer', 'required' => true ],
                'taxonomy' => [ 'type' => 'string', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_terms_get' ],
        ] );

        $this->register( 'wp.meta.get', [
            'class'           => 'read',
            'required_scopes' => [ 'read.wp', 'manage.meta' ],
            'description'     => 'Get post meta by post_id and optional meta_key.',
            'input_schema'    => [
                'post_id'  => [ 'type' => 'integer', 'required' => true ],
                'meta_key' => [ 'type' => 'string' ],
            ],
            'handler'         => [ $this, 'handle_meta_get' ],
        ] );

        $this->register( 'wp.options.get', [
            'class'           => 'read',
            'required_scopes' => [ 'manage.wordpress.settings' ],
            'description'     => 'Get a WordPress option (allowlist enforced).',
            'input_schema'    => [ 'option_name' => [ 'type' => 'string', 'required' => true ] ],
            'handler'         => [ $this, 'handle_options_get' ],
        ] );

        // ── Write ──────────────────────────────────────────────────────────
        $this->register( 'wp.posts.create', [
            'class'            => 'write',
            'required_scopes'  => [ 'write.wp.posts' ],
            'supports_dry_run' => true,
            'description'      => 'Create a new post. post_author defaults to the user associated with the API key.',
            'input_schema'     => [
                'post_type'    => [ 'type' => 'string',  'default' => 'post' ],
                'post_title'   => [ 'type' => 'string',  'required' => true ],
                'post_content' => [ 'type' => 'string' ],
                'post_excerpt' => [ 'type' => 'string' ],
                'post_status'  => [ 'type' => 'string',  'default' => 'draft' ],
                'post_name'    => [ 'type' => 'string',  'description' => 'URL slug. For drafts WordPress leaves the slug empty unless explicitly set here.' ],
                'post_author'  => [ 'type' => 'integer', 'description' => 'Author user ID. Defaults to the user who created the API key.' ],
                'post_date'    => [ 'type' => 'string',  'description' => 'Publish date in YYYY-MM-DD HH:MM:SS or ISO 8601 format. Uses site timezone.' ],
            ],
            'handler'          => [ $this, 'handle_posts_create' ],
        ] );

        $this->register( 'wp.posts.update', [
            'class'            => 'write',
            'required_scopes'  => [ 'write.wp.posts' ],
            'supports_dry_run' => true,
            'description'      => 'Update an existing post.',
            'input_schema'     => [
                'id'           => [ 'type' => 'integer', 'required' => true ],
                'post_title'   => [ 'type' => 'string' ],
                'post_content' => [ 'type' => 'string' ],
                'post_status'  => [ 'type' => 'string' ],
                'post_excerpt' => [ 'type' => 'string' ],
                'post_name'    => [ 'type' => 'string',  'description' => 'URL slug.' ],
                'post_author'  => [ 'type' => 'integer' ],
                'post_date'    => [ 'type' => 'string',  'description' => 'Publish date in YYYY-MM-DD HH:MM:SS or ISO 8601 format. Uses site timezone.' ],
            ],
            'handler'          => [ $this, 'handle_posts_update' ],
        ] );

        $this->register( 'wp.posts.preview_url', [
            'class'           => 'read',
            'required_scopes' => [ 'read.wp' ],
            'description'     => 'Get a preview URL for any post or page (works for drafts, private, and published). Returns the WordPress preview link and the admin edit URL.',
            'input_schema'    => [
                'post_id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_posts_preview_url' ],
        ] );

        $this->register( 'wp.terms.create', [
            'class'           => 'write',
            'required_scopes' => [ 'manage.taxonomies' ],
            'description'     => 'Create a new term in a taxonomy.',
            'input_schema'    => [
                'name'        => [ 'type' => 'string',  'required' => true ],
                'taxonomy'    => [ 'type' => 'string',  'required' => true ],
                'description' => [ 'type' => 'string' ],
                'slug'        => [ 'type' => 'string' ],
                'parent'      => [ 'type' => 'integer' ],
            ],
            'handler'         => [ $this, 'handle_terms_create' ],
        ] );

        $this->register( 'wp.terms.update', [
            'class'           => 'write',
            'required_scopes' => [ 'manage.taxonomies' ],
            'description'     => 'Update a term.',
            'input_schema'    => [
                'term_id'     => [ 'type' => 'integer', 'required' => true ],
                'taxonomy'    => [ 'type' => 'string',  'required' => true ],
                'name'        => [ 'type' => 'string' ],
                'slug'        => [ 'type' => 'string' ],
                'description' => [ 'type' => 'string' ],
                'parent'      => [ 'type' => 'integer' ],
            ],
            'handler'         => [ $this, 'handle_terms_update' ],
        ] );

        $this->register( 'wp.terms.assign_to_post', [
            'class'           => 'write',
            'required_scopes' => [ 'manage.taxonomies', 'write.wp.posts' ],
            'description'     => 'Assign terms to a post.',
            'input_schema'    => [
                'post_id'  => [ 'type' => 'integer', 'required' => true ],
                'term_ids' => [ 'type' => 'array',   'required' => true, 'description' => 'Array of term IDs to assign.' ],
                'taxonomy' => [ 'type' => 'string',  'required' => true ],
                'append'   => [ 'type' => 'boolean', 'default' => true,  'description' => 'If false, replaces existing terms.' ],
            ],
            'handler'         => [ $this, 'handle_terms_assign_to_post' ],
        ] );

        $this->register( 'wp.terms.remove_from_post', [
            'class'           => 'write',
            'required_scopes' => [ 'manage.taxonomies', 'write.wp.posts' ],
            'description'     => 'Remove all terms of a taxonomy from a post.',
            'input_schema'    => [
                'post_id'  => [ 'type' => 'integer', 'required' => true ],
                'taxonomy' => [ 'type' => 'string',  'required' => true ],
            ],
            'handler'         => [ $this, 'handle_terms_remove_from_post' ],
        ] );

        $this->register( 'wp.meta.set', [
            'class'           => 'write',
            'required_scopes' => [ 'manage.meta' ],
            'description'     => 'Set post meta value (allowlist enforced).',
            'input_schema'    => [
                'post_id'    => [ 'type' => 'integer', 'required' => true ],
                'meta_key'   => [ 'type' => 'string',  'required' => true ],
                'meta_value' => [ 'description' => 'Value to store (string, number, array).' ],
            ],
            'handler'         => [ $this, 'handle_meta_set' ],
        ] );

        $this->register( 'wp.meta.set_many', [
            'class'           => 'write',
            'required_scopes' => [ 'manage.meta' ],
            'description'     => 'Set multiple post meta values in one call (allowlist enforced per key).',
            'input_schema'    => [
                'post_id' => [ 'type' => 'integer', 'required' => true ],
                'meta'    => [ 'required' => true, 'description' => 'Object of meta_key → meta_value pairs. E.g. {"_yoast_title": "...", "_acf_field": "..."}' ],
            ],
            'handler'         => [ $this, 'handle_meta_set_many' ],
        ] );

        $this->register( 'wp.options.set', [
            'class'            => 'admin_sensitive',
            'required_scopes'  => [ 'manage.wordpress.settings' ],
            'requires_confirm' => true,
            'description'      => 'Set a WordPress option (allowlist enforced, confirm=true required).',
            'input_schema'     => [
                'option_name'  => [ 'type' => 'string', 'required' => true ],
                'option_value' => [ 'description' => 'New value for the option.' ],
                'confirm'      => [ 'type' => 'boolean', 'required' => true, 'description' => 'Must be true to execute.' ],
            ],
            'handler'          => [ $this, 'handle_options_set' ],
        ] );

        // ── Plugins ───────────────────────────────────────────────────────
        $this->register( 'wp.plugins.list', [
            'class'           => 'read',
            'required_scopes' => [ 'manage.plugins' ],
            'description'     => 'List all installed plugins with version, status and available update info. Set force_refresh=true to bypass the 12-hour update cache.',
            'input_schema'    => [
                'force_refresh' => [ 'type' => 'boolean', 'description' => 'Force a fresh check against wordpress.org update API (slower).' ],
            ],
            'handler'         => [ $this, 'handle_plugins_list' ],
        ] );

        $this->register( 'wp.plugins.update_all', [
            'class'            => 'admin_sensitive',
            'required_scopes'  => [ 'manage.plugins' ],
            'supports_dry_run' => true,
            'requires_confirm' => true,
            'description'      => 'Update all plugins that have available updates. Pass include[] to limit to specific plugin files. Requires direct filesystem access (no FTP). Confirm=true required.',
            'input_schema'     => [
                'include' => [ 'type' => 'array',   'description' => 'Optional list of plugin file paths (e.g. ["akismet/akismet.php"]) to restrict which plugins are updated. Defaults to all available updates.' ],
                'confirm' => [ 'type' => 'boolean', 'required' => true, 'description' => 'Must be true to execute.' ],
            ],
            'handler'          => [ $this, 'handle_plugins_update_all' ],
        ] );

        // ── Destructive ────────────────────────────────────────────────────
        $this->register( 'wp.posts.trash', [
            'class'           => 'destructive',
            'required_scopes' => [ 'delete.wp.posts' ],
            'description'     => 'Move a post to trash.',
            'input_schema'    => [
                'id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_posts_trash' ],
        ] );

        $this->register( 'wp.posts.restore', [
            'class'           => 'write',
            'required_scopes' => [ 'write.wp.posts' ],
            'description'     => 'Restore a post from trash.',
            'input_schema'    => [
                'id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_posts_restore' ],
        ] );

        $this->register( 'wp.posts.delete', [
            'class'            => 'destructive',
            'required_scopes'  => [ 'delete.wp.posts' ],
            'destructive'      => true,
            'requires_confirm' => true,
            'description'      => 'Permanently delete a post (requires confirm=true).',
            'input_schema'     => [
                'id'      => [ 'type' => 'integer', 'required' => true ],
                'force'   => [ 'type' => 'boolean', 'default' => true ],
                'confirm' => [ 'type' => 'boolean', 'required' => true, 'description' => 'Must be true to execute.' ],
            ],
            'handler'          => [ $this, 'handle_posts_delete' ],
        ] );

        $this->register( 'wp.terms.delete', [
            'class'            => 'destructive',
            'required_scopes'  => [ 'manage.taxonomies' ],
            'destructive'      => true,
            'requires_confirm' => true,
            'description'      => 'Permanently delete a term (requires confirm=true).',
            'input_schema'     => [
                'term_id'  => [ 'type' => 'integer', 'required' => true ],
                'taxonomy' => [ 'type' => 'string',  'required' => true ],
                'confirm'  => [ 'type' => 'boolean', 'required' => true, 'description' => 'Must be true to execute.' ],
            ],
            'handler'          => [ $this, 'handle_terms_delete' ],
        ] );

        $this->register( 'wp.meta.delete', [
            'class'           => 'destructive',
            'required_scopes' => [ 'manage.meta' ],
            'description'     => 'Delete a post meta key.',
            'input_schema'    => [
                'post_id'  => [ 'type' => 'integer', 'required' => true ],
                'meta_key' => [ 'type' => 'string',  'required' => true ],
            ],
            'handler'         => [ $this, 'handle_meta_delete' ],
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────

    public function handle_server_status( array $args, array $key_record, bool $dry_run ): array {
        return $this->ok( [
            'server' => [
                'name'              => 'AICOM - AI Commander for WordPress',
                'version'           => AICOM_VERSION,
                'wordpress_version' => get_bloginfo( 'version' ),
                'php_version'       => PHP_VERSION,
                'site_url'          => get_site_url(),
            ],
            'lock'    => AICOM_Lock_Manager::get_state(),
            'modules' => AICOM_Module_Detector::get_module_status_map(),
        ] );
    }

    public function handle_tools_list( array $args, array $key_record, bool $dry_run ): array {
        $active      = AICOM_Module_Detector::get_active_modules();
        $lock_status = AICOM_Lock_Manager::get_effective_lock();
        $active_session = AICOM_Sessions::get_active( (int) $key_record['id'] );

        $session_note = $active_session
            ? 'You have an active session: "' . $active_session['name'] . '" (ID ' . $active_session['id'] . '). You may proceed with write/destructive tools.'
            : 'IMPORTANT: You do NOT have an active session. Before calling any write, destructive, or admin_sensitive tool you MUST first call session.open. Provide a clear name (e.g. "Update homepage hero text") and a description of what you plan to do and why (e.g. "Rewriting the hero headline and subtext to match the new brand guidelines"). This is required — not optional. Read-only and discovery tools work without a session.';

        $lock_note = match ( $lock_status ) {
            'hard_locked' => 'SAFETY: The site is HARD LOCKED — only public/discovery tools are allowed.',
            'soft_locked' => 'SAFETY: The site is SOFT LOCKED — read and discovery tools only; all write operations are blocked.',
            default       => '',
        };

        $instructions = trim( implode( "\n\n", array_filter( [ $session_note, $lock_note ] ) ) );

        return $this->ok( [
            'instructions' => $instructions,
            'tools'        => AICOM_Tool_Registry::to_mcp_list( $active ),
        ] );
    }

    public function handle_site_info( array $args, array $key_record, bool $dry_run ): array {
        $theme = wp_get_theme();
        return $this->ok( [
            'name'             => get_bloginfo( 'name' ),
            'description'      => get_bloginfo( 'description' ),
            'url'              => get_site_url(),
            'admin_email'      => get_bloginfo( 'admin_email' ),
            'wordpress_version'=> get_bloginfo( 'version' ),
            'timezone'         => get_option( 'timezone_string' ) ?: 'UTC',
            'language'         => get_bloginfo( 'language' ),
            'active_theme'     => [
                'name'    => $theme->get( 'Name' ),
                'version' => $theme->get( 'Version' ),
            ],
        ] );
    }

    public function handle_post_types_list( array $args, array $key_record, bool $dry_run ): array {
        $post_types = get_post_types( [ 'public' => true ], 'objects' );
        $result     = [];
        foreach ( $post_types as $slug => $pt ) {
            $result[] = [
                'slug'        => $slug,
                'label'       => $pt->label,
                'hierarchical'=> $pt->hierarchical,
                'has_archive' => $pt->has_archive,
            ];
        }
        return $this->ok( [ 'post_types' => $result ] );
    }

    public function handle_taxonomies_list( array $args, array $key_record, bool $dry_run ): array {
        $taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );
        $result     = [];
        foreach ( $taxonomies as $slug => $tax ) {
            $result[] = [
                'slug'        => $slug,
                'label'       => $tax->label,
                'hierarchical'=> $tax->hierarchical,
                'object_type' => $tax->object_type,
            ];
        }
        return $this->ok( [ 'taxonomies' => $result ] );
    }

    public function handle_posts_list( array $args, array $key_record, bool $dry_run ): array {
        $post_type = sanitize_key( $args['post_type'] ?? 'post' );

        if ( ! AICOM_Policy_Engine::check_post_type_allowlist( $key_record, $post_type ) ) {
            return $this->err( 'DENIED_ALLOWLIST', "Post type not in allowlist: $post_type", 'denied_allowlist', 403 );
        }

        $query_args = [
            'post_type'      => $post_type,
            'post_status'    => sanitize_text_field( $args['status'] ?? 'any' ),
            'posts_per_page' => min( (int) ( $args['posts_per_page'] ?? 20 ), 100 ),
            'paged'          => max( 1, (int) ( $args['paged'] ?? 1 ) ),
        ];

        if ( ! empty( $args['search'] ) ) {
            $query_args['s'] = sanitize_text_field( $args['search'] );
        }

        // Polylang language filter
        if ( ! empty( $args['lang'] ) && AICOM_Module_Detector::is_polylang_active() ) {
            $query_args['lang'] = sanitize_key( $args['lang'] );
        }

        $query = new WP_Query( $query_args );
        $posts = [];

        foreach ( $query->posts as $post ) {
            $posts[] = [
                'id'           => $post->ID,
                'title'        => $post->post_title,
                'status'       => $post->post_status,
                'date'         => $post->post_date,
                'modified'     => $post->post_modified,
                'slug'         => $post->post_name,
                'post_type'    => $post->post_type,
                'author'       => (int) $post->post_author,
                'comment_count'=> (int) $post->comment_count,
            ];
        }

        return $this->ok( [
            'posts'       => $posts,
            'total'       => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
        ] );
    }

    public function handle_posts_get( array $args, array $key_record, bool $dry_run ): array {
        $id = $this->require_int( $args, 'id' );
        if ( ! $id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter id is required', 'validation_failed' );
        }

        $post = get_post( $id );
        if ( ! $post ) {
            return $this->err( 'NOT_FOUND', "Post $id not found", 'error', 404 );
        }

        if ( ! AICOM_Policy_Engine::check_post_type_allowlist( $key_record, $post->post_type ) ) {
            return $this->err( 'DENIED_ALLOWLIST', 'Post type not in allowlist', 'denied_allowlist', 403 );
        }

        $taxonomies = get_object_taxonomies( $post->post_type );
        $terms_map  = [];
        foreach ( $taxonomies as $tax ) {
            $tax_terms = get_the_terms( $post->ID, $tax );
            if ( $tax_terms && ! is_wp_error( $tax_terms ) ) {
                $terms_map[ $tax ] = array_values( array_map( fn( $t ) => [
                    'id'   => $t->term_id,
                    'name' => $t->name,
                    'slug' => $t->slug,
                ], $tax_terms ) );
            }
        }

        return $this->ok( [
            'id'             => $post->ID,
            'title'          => $post->post_title,
            'content'        => $post->post_content,
            'excerpt'        => $post->post_excerpt,
            'status'         => $post->post_status,
            'post_type'      => $post->post_type,
            'slug'           => $post->post_name,
            'date'           => $post->post_date,
            'modified'       => $post->post_modified,
            'author'         => (int) $post->post_author,
            'parent'         => (int) $post->post_parent,
            'menu_order'     => (int) $post->menu_order,
            'comment_status' => $post->comment_status,
            'terms'          => $terms_map,
        ], [ 'target_type' => 'post', 'target_id' => $id ] );
    }

    public function handle_posts_create( array $args, array $key_record, bool $dry_run ): array {
        $title = $this->require_string( $args, 'post_title' );
        if ( ! $title ) {
            return $this->err( 'MISSING_PARAM', 'Parameter post_title is required', 'validation_failed' );
        }

        $post_type = sanitize_key( $args['post_type'] ?? 'post' );
        if ( ! AICOM_Policy_Engine::check_post_type_allowlist( $key_record, $post_type ) ) {
            return $this->err( 'DENIED_ALLOWLIST', "Post type not in allowlist: $post_type", 'denied_allowlist', 403 );
        }

        if ( $dry_run ) {
            return $this->ok( [
                'dry_run' => true,
                'would_create' => [ 'post_type' => $post_type, 'post_title' => $title ],
            ] );
        }

        $data = [
            'post_title'   => sanitize_text_field( $title ),
            'post_content' => wp_kses_post( $args['post_content'] ?? '' ),
            'post_status'  => sanitize_key( $args['post_status'] ?? 'draft' ),
            'post_type'    => $post_type,
        ];

        if ( isset( $args['post_excerpt'] ) ) {
            $data['post_excerpt'] = sanitize_text_field( $args['post_excerpt'] );
        }
        if ( isset( $args['post_name'] ) ) {
            $data['post_name'] = sanitize_title( $args['post_name'] );
        }
        // Default author to the user who owns the API key; avoids author=0 on REST requests
        $data['post_author'] = isset( $args['post_author'] )
            ? (int) $args['post_author']
            : (int) ( $key_record['created_by_user_id'] ?? 0 );

        if ( isset( $args['post_date'] ) ) {
            $date = $this->normalize_post_date( $args['post_date'] );
            if ( $date === null ) {
                return $this->err( 'INVALID_PARAM', 'post_date must be YYYY-MM-DD HH:MM:SS or ISO 8601 (e.g. 2026-04-28T10:00:00)', 'validation_failed' );
            }
            $data['post_date'] = $date;
        }

        $id = wp_insert_post( $data, true );

        if ( is_wp_error( $id ) ) {
            return $this->err( 'WP_ERROR', $id->get_error_message(), 'error', 500 );
        }

        return $this->ok(
            [ 'id' => $id, 'post_type' => $post_type, 'post_title' => get_the_title( $id ) ],
            [ 'target_type' => 'post', 'target_id' => $id, 'summary' => [ 'created' => true ] ]
        );
    }

    public function handle_posts_update( array $args, array $key_record, bool $dry_run ): array {
        $id = $this->require_int( $args, 'id' );
        if ( ! $id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter id is required', 'validation_failed' );
        }

        $post = get_post( $id );
        if ( ! $post ) {
            return $this->err( 'NOT_FOUND', "Post $id not found", 'error', 404 );
        }

        if ( ! AICOM_Policy_Engine::check_post_type_allowlist( $key_record, $post->post_type ) ) {
            return $this->err( 'DENIED_ALLOWLIST', 'Post type not in allowlist', 'denied_allowlist', 403 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_update_id' => $id ] );
        }

        $data = [ 'ID' => $id ];
        if ( isset( $args['post_title'] ) )    $data['post_title']   = sanitize_text_field( $args['post_title'] );
        if ( isset( $args['post_content'] ) )  $data['post_content'] = wp_kses_post( $args['post_content'] );
        if ( isset( $args['post_status'] ) )   $data['post_status']  = sanitize_key( $args['post_status'] );
        if ( isset( $args['post_excerpt'] ) )  $data['post_excerpt'] = sanitize_text_field( $args['post_excerpt'] );
        if ( isset( $args['post_name'] ) )     $data['post_name']    = sanitize_title( $args['post_name'] );
        if ( isset( $args['post_author'] ) )   $data['post_author']  = (int) $args['post_author'];

        if ( isset( $args['post_date'] ) ) {
            $date = $this->normalize_post_date( $args['post_date'] );
            if ( $date === null ) {
                return $this->err( 'INVALID_PARAM', 'post_date must be YYYY-MM-DD HH:MM:SS or ISO 8601 (e.g. 2026-04-28T10:00:00)', 'validation_failed' );
            }
            $data['post_date']     = $date;
            $data['post_date_gmt'] = get_gmt_from_date( $date );
        }

        $result = wp_update_post( $data, true );
        if ( is_wp_error( $result ) ) {
            return $this->err( 'WP_ERROR', $result->get_error_message(), 'error', 500 );
        }

        return $this->ok(
            [ 'id' => $id, 'updated' => true ],
            [ 'target_type' => 'post', 'target_id' => $id, 'summary' => array_keys( $data ) ]
        );
    }

    public function handle_posts_preview_url( array $args, array $key_record, bool $dry_run ): array {
        $post_id = $this->require_int( $args, 'post_id' );
        if ( ! $post_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter post_id is required', 'validation_failed' );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return $this->err( 'NOT_FOUND', "Post $post_id not found", 'error', 404 );
        }

        $is_published = $post->post_status === 'publish';
        $preview_url  = $is_published ? get_permalink( $post_id ) : get_preview_post_link( $post_id );

        return $this->ok( [
            'post_id'        => $post_id,
            'post_status'    => $post->post_status,
            'preview_url'    => $preview_url,
            'admin_edit_url' => admin_url( "post.php?post=$post_id&action=edit" ),
        ], [ 'target_type' => 'post', 'target_id' => $post_id ] );
    }

    public function handle_posts_trash( array $args, array $key_record, bool $dry_run ): array {
        $id = $this->require_int( $args, 'id' );
        if ( ! $id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter id is required', 'validation_failed' );
        }

        $post = get_post( $id );
        if ( ! $post ) {
            return $this->err( 'NOT_FOUND', "Post $id not found", 'error', 404 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_trash_id' => $id ] );
        }

        $result = wp_trash_post( $id );
        if ( ! $result ) {
            return $this->err( 'WP_ERROR', 'Failed to trash post', 'error', 500 );
        }

        return $this->ok(
            [ 'id' => $id, 'trashed' => true ],
            [ 'target_type' => 'post', 'target_id' => $id ]
        );
    }

    public function handle_posts_restore( array $args, array $key_record, bool $dry_run ): array {
        $id = $this->require_int( $args, 'id' );
        if ( ! $id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter id is required', 'validation_failed' );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_restore_id' => $id ] );
        }

        $result = wp_untrash_post( $id );
        if ( ! $result ) {
            return $this->err( 'WP_ERROR', 'Failed to restore post', 'error', 500 );
        }

        return $this->ok(
            [ 'id' => $id, 'restored' => true ],
            [ 'target_type' => 'post', 'target_id' => $id ]
        );
    }

    public function handle_posts_delete( array $args, array $key_record, bool $dry_run ): array {
        $id = $this->require_int( $args, 'id' );
        if ( ! $id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter id is required', 'validation_failed' );
        }

        $post = get_post( $id );
        if ( ! $post ) {
            return $this->err( 'NOT_FOUND', "Post $id not found", 'error', 404 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_delete_id' => $id ] );
        }

        $result = wp_delete_post( $id, true ); // force_delete = true
        if ( ! $result ) {
            return $this->err( 'WP_ERROR', 'Failed to permanently delete post', 'error', 500 );
        }

        return $this->ok(
            [ 'id' => $id, 'deleted' => true ],
            [ 'target_type' => 'post', 'target_id' => $id ]
        );
    }

    public function handle_terms_list( array $args, array $key_record, bool $dry_run ): array {
        $taxonomy = sanitize_key( $args['taxonomy'] ?? '' );
        if ( ! $taxonomy ) {
            return $this->err( 'MISSING_PARAM', 'Parameter taxonomy is required', 'validation_failed' );
        }

        if ( ! AICOM_Policy_Engine::check_taxonomy_allowlist( $key_record, $taxonomy ) ) {
            return $this->err( 'DENIED_ALLOWLIST', "Taxonomy not in allowlist: $taxonomy", 'denied_allowlist', 403 );
        }

        $terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 200 ] );
        if ( is_wp_error( $terms ) ) {
            return $this->err( 'WP_ERROR', $terms->get_error_message(), 'error', 500 );
        }

        $result = [];
        foreach ( $terms as $term ) {
            $result[] = [
                'id'          => $term->term_id,
                'name'        => $term->name,
                'slug'        => $term->slug,
                'description' => $term->description,
                'count'       => $term->count,
                'parent'      => $term->parent,
            ];
        }

        return $this->ok( [ 'taxonomy' => $taxonomy, 'terms' => $result, 'total' => count( $result ) ] );
    }

    public function handle_terms_get( array $args, array $key_record, bool $dry_run ): array {
        $term_id  = $this->require_int( $args, 'term_id' );
        $taxonomy = sanitize_key( $args['taxonomy'] ?? '' );

        if ( ! $term_id || ! $taxonomy ) {
            return $this->err( 'MISSING_PARAM', 'Parameters term_id and taxonomy are required', 'validation_failed' );
        }

        $term = get_term( $term_id, $taxonomy );
        if ( is_wp_error( $term ) || ! $term ) {
            return $this->err( 'NOT_FOUND', "Term $term_id not found in $taxonomy", 'error', 404 );
        }

        return $this->ok( [
            'id'          => $term->term_id,
            'name'        => $term->name,
            'slug'        => $term->slug,
            'description' => $term->description,
            'count'       => $term->count,
            'parent'      => $term->parent,
            'taxonomy'    => $taxonomy,
        ], [ 'target_type' => 'term', 'target_id' => $term_id ] );
    }

    public function handle_terms_create( array $args, array $key_record, bool $dry_run ): array {
        $name     = $this->require_string( $args, 'name' );
        $taxonomy = sanitize_key( $args['taxonomy'] ?? '' );

        if ( ! $name || ! $taxonomy ) {
            return $this->err( 'MISSING_PARAM', 'Parameters name and taxonomy are required', 'validation_failed' );
        }

        if ( ! AICOM_Policy_Engine::check_taxonomy_allowlist( $key_record, $taxonomy ) ) {
            return $this->err( 'DENIED_ALLOWLIST', "Taxonomy not in allowlist: $taxonomy", 'denied_allowlist', 403 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_create' => [ 'name' => $name, 'taxonomy' => $taxonomy ] ] );
        }

        $term_data = [
            'description' => sanitize_text_field( $args['description'] ?? '' ),
            'slug'        => sanitize_title( $args['slug'] ?? $name ),
            'parent'      => (int) ( $args['parent'] ?? 0 ),
        ];

        $result = wp_insert_term( sanitize_text_field( $name ), $taxonomy, $term_data );
        if ( is_wp_error( $result ) ) {
            return $this->err( 'WP_ERROR', $result->get_error_message(), 'error', 500 );
        }

        return $this->ok(
            [ 'term_id' => $result['term_id'], 'taxonomy' => $taxonomy, 'name' => $name ],
            [ 'target_type' => 'term', 'target_id' => $result['term_id'] ]
        );
    }

    public function handle_terms_update( array $args, array $key_record, bool $dry_run ): array {
        $term_id  = $this->require_int( $args, 'term_id' );
        $taxonomy = sanitize_key( $args['taxonomy'] ?? '' );

        if ( ! $term_id || ! $taxonomy ) {
            return $this->err( 'MISSING_PARAM', 'Parameters term_id and taxonomy are required', 'validation_failed' );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_update_term_id' => $term_id ] );
        }

        $term_data = [];
        if ( isset( $args['name'] ) )        $term_data['name']        = sanitize_text_field( $args['name'] );
        if ( isset( $args['slug'] ) )        $term_data['slug']        = sanitize_title( $args['slug'] );
        if ( isset( $args['description'] ) ) $term_data['description'] = sanitize_text_field( $args['description'] );
        if ( isset( $args['parent'] ) )      $term_data['parent']      = (int) $args['parent'];

        $result = wp_update_term( $term_id, $taxonomy, $term_data );
        if ( is_wp_error( $result ) ) {
            return $this->err( 'WP_ERROR', $result->get_error_message(), 'error', 500 );
        }

        return $this->ok(
            [ 'term_id' => $term_id, 'updated' => true ],
            [ 'target_type' => 'term', 'target_id' => $term_id ]
        );
    }

    public function handle_terms_delete( array $args, array $key_record, bool $dry_run ): array {
        $term_id  = $this->require_int( $args, 'term_id' );
        $taxonomy = sanitize_key( $args['taxonomy'] ?? '' );

        if ( ! $term_id || ! $taxonomy ) {
            return $this->err( 'MISSING_PARAM', 'Parameters term_id and taxonomy are required', 'validation_failed' );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_delete_term_id' => $term_id ] );
        }

        $result = wp_delete_term( $term_id, $taxonomy );
        if ( is_wp_error( $result ) || $result === false ) {
            return $this->err( 'WP_ERROR', is_wp_error( $result ) ? $result->get_error_message() : 'Failed to delete term', 'error', 500 );
        }

        return $this->ok(
            [ 'term_id' => $term_id, 'deleted' => true ],
            [ 'target_type' => 'term', 'target_id' => $term_id ]
        );
    }

    public function handle_terms_assign_to_post( array $args, array $key_record, bool $dry_run ): array {
        $post_id  = $this->require_int( $args, 'post_id' );
        $term_ids = array_map( 'intval', (array) ( $args['term_ids'] ?? [] ) );
        $taxonomy = sanitize_key( $args['taxonomy'] ?? '' );

        if ( ! $post_id || empty( $term_ids ) || ! $taxonomy ) {
            return $this->err( 'MISSING_PARAM', 'Parameters post_id, term_ids, and taxonomy are required', 'validation_failed' );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_assign' => [ 'post_id' => $post_id, 'term_ids' => $term_ids ] ] );
        }

        $result = wp_set_post_terms( $post_id, $term_ids, $taxonomy, ! empty( $args['append'] ) );
        if ( is_wp_error( $result ) ) {
            return $this->err( 'WP_ERROR', $result->get_error_message(), 'error', 500 );
        }

        return $this->ok(
            [ 'post_id' => $post_id, 'assigned' => $term_ids ],
            [ 'target_type' => 'post', 'target_id' => $post_id ]
        );
    }

    public function handle_terms_remove_from_post( array $args, array $key_record, bool $dry_run ): array {
        $post_id  = $this->require_int( $args, 'post_id' );
        $taxonomy = sanitize_key( $args['taxonomy'] ?? '' );

        if ( ! $post_id || ! $taxonomy ) {
            return $this->err( 'MISSING_PARAM', 'Parameters post_id and taxonomy are required', 'validation_failed' );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_remove_terms_from' => $post_id ] );
        }

        wp_set_post_terms( $post_id, [], $taxonomy );
        return $this->ok(
            [ 'post_id' => $post_id, 'terms_removed' => true ],
            [ 'target_type' => 'post', 'target_id' => $post_id ]
        );
    }

    public function handle_meta_get( array $args, array $key_record, bool $dry_run ): array {
        $post_id  = $this->require_int( $args, 'post_id' );
        $meta_key = $args['meta_key'] ?? null;

        if ( ! $post_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter post_id is required', 'validation_failed' );
        }

        if ( $meta_key ) {
            if ( ! AICOM_Policy_Engine::check_meta_key_allowlist( $key_record, $meta_key ) ) {
                return $this->err( 'DENIED_ALLOWLIST', "Meta key not in allowlist: $meta_key", 'denied_allowlist', 403 );
            }
            $value = get_post_meta( $post_id, $meta_key, false );
            return $this->ok( [ 'post_id' => $post_id, 'meta_key' => $meta_key, 'values' => $value ] );
        }

        // Return all meta (respects allowlist if set)
        $all_meta = get_post_meta( $post_id );
        return $this->ok( [ 'post_id' => $post_id, 'meta' => $all_meta ] );
    }

    public function handle_meta_set( array $args, array $key_record, bool $dry_run ): array {
        $post_id   = $this->require_int( $args, 'post_id' );
        $meta_key  = $this->require_string( $args, 'meta_key' );
        $meta_value = $args['meta_value'] ?? null;

        if ( ! $post_id || ! $meta_key ) {
            return $this->err( 'MISSING_PARAM', 'Parameters post_id and meta_key are required', 'validation_failed' );
        }

        if ( ! AICOM_Policy_Engine::check_meta_key_allowlist( $key_record, $meta_key ) ) {
            return $this->err( 'DENIED_ALLOWLIST', "Meta key not in allowlist: $meta_key", 'denied_allowlist', 403 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_set' => [ 'post_id' => $post_id, 'meta_key' => $meta_key ] ] );
        }

        // wp_slash() counteracts update_post_meta's internal wp_unslash(), preserving backslashes in JSON strings
        update_post_meta( $post_id, $meta_key, is_string( $meta_value ) ? wp_slash( $meta_value ) : $meta_value );

        return $this->ok(
            [ 'post_id' => $post_id, 'meta_key' => $meta_key, 'updated' => true ],
            [ 'target_type' => 'post_meta', 'target_id' => $post_id ]
        );
    }

    public function handle_meta_set_many( array $args, array $key_record, bool $dry_run ): array {
        $post_id = $this->require_int( $args, 'post_id' );
        $meta    = $args['meta'] ?? null;

        if ( ! $post_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter post_id is required', 'validation_failed' );
        }
        if ( ! is_array( $meta ) || empty( $meta ) ) {
            return $this->err( 'MISSING_PARAM', 'Parameter meta must be a non-empty object of key→value pairs', 'validation_failed' );
        }

        $denied = [];
        foreach ( array_keys( $meta ) as $key ) {
            if ( ! AICOM_Policy_Engine::check_meta_key_allowlist( $key_record, $key ) ) {
                $denied[] = $key;
            }
        }
        if ( $denied ) {
            return $this->err( 'DENIED_ALLOWLIST', 'Meta keys not in allowlist: ' . implode( ', ', $denied ), 'denied_allowlist', 403 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_set' => array_keys( $meta ) ] );
        }

        foreach ( $meta as $key => $value ) {
            update_post_meta( $post_id, $key, is_string( $value ) ? wp_slash( $value ) : $value );
        }

        return $this->ok(
            [ 'post_id' => $post_id, 'updated' => array_keys( $meta ) ],
            [ 'target_type' => 'post_meta', 'target_id' => $post_id ]
        );
    }

    public function handle_meta_delete( array $args, array $key_record, bool $dry_run ): array {
        $post_id  = $this->require_int( $args, 'post_id' );
        $meta_key = $this->require_string( $args, 'meta_key' );

        if ( ! $post_id || ! $meta_key ) {
            return $this->err( 'MISSING_PARAM', 'Parameters post_id and meta_key are required', 'validation_failed' );
        }

        if ( ! AICOM_Policy_Engine::check_meta_key_allowlist( $key_record, $meta_key ) ) {
            return $this->err( 'DENIED_ALLOWLIST', "Meta key not in allowlist: $meta_key", 'denied_allowlist', 403 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_delete_meta_key' => $meta_key ] );
        }

        delete_post_meta( $post_id, $meta_key );
        return $this->ok(
            [ 'post_id' => $post_id, 'meta_key' => $meta_key, 'deleted' => true ],
            [ 'target_type' => 'post_meta', 'target_id' => $post_id ]
        );
    }

    public function handle_options_get( array $args, array $key_record, bool $dry_run ): array {
        $option_name = $this->require_string( $args, 'option_name' );
        if ( ! $option_name ) {
            return $this->err( 'MISSING_PARAM', 'Parameter option_name is required', 'validation_failed' );
        }

        if ( ! AICOM_Policy_Engine::check_option_allowlist( $key_record, $option_name ) ) {
            return $this->err( 'DENIED_ALLOWLIST', "Option not in allowlist: $option_name", 'denied_allowlist', 403 );
        }

        $value = get_option( $option_name );
        return $this->ok( [ 'option_name' => $option_name, 'value' => $value ] );
    }

    public function handle_options_set( array $args, array $key_record, bool $dry_run ): array {
        $option_name  = $this->require_string( $args, 'option_name' );
        $option_value = $args['option_value'] ?? null;

        if ( ! $option_name ) {
            return $this->err( 'MISSING_PARAM', 'Parameter option_name is required', 'validation_failed' );
        }

        if ( ! AICOM_Policy_Engine::check_option_allowlist( $key_record, $option_name ) ) {
            return $this->err( 'DENIED_ALLOWLIST', "Option not in allowlist: $option_name", 'denied_allowlist', 403 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_set_option' => $option_name ] );
        }

        update_option( $option_name, $option_value );
        return $this->ok(
            [ 'option_name' => $option_name, 'updated' => true ],
            [ 'target_type' => 'option', 'target_id' => $option_name ]
        );
    }

    public function handle_plugins_list( array $args, array $key_record, bool $dry_run ): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if ( ! empty( $args['force_refresh'] ) ) {
            delete_site_transient( 'update_plugins' );
            wp_update_plugins();
        }

        $all_plugins    = get_plugins();
        $active_plugins = (array) get_option( 'active_plugins', [] );
        $update_cache   = get_site_transient( 'update_plugins' );
        $available      = isset( $update_cache->response ) && is_array( $update_cache->response )
                          ? $update_cache->response : [];

        $plugins = [];
        foreach ( $all_plugins as $file => $data ) {
            $has_update  = isset( $available[ $file ] );
            $plugins[] = [
                'file'             => $file,
                'name'             => $data['Name'],
                'version'          => $data['Version'],
                'new_version'      => $has_update ? ( $available[ $file ]->new_version ?? null ) : null,
                'update_available' => $has_update,
                'status'           => in_array( $file, $active_plugins, true ) ? 'active' : 'inactive',
                'author'           => wp_strip_all_tags( $data['Author'] ?? '' ),
            ];
        }

        $update_count = count( array_filter( $plugins, fn( $p ) => $p['update_available'] ) );

        return $this->ok( [
            'plugins'           => $plugins,
            'total'             => count( $plugins ),
            'updates_available' => $update_count,
            'cache_refreshed'   => ! empty( $args['force_refresh'] ),
        ] );
    }

    public function handle_plugins_update_all( array $args, array $key_record, bool $dry_run ): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $update_cache = get_site_transient( 'update_plugins' );
        $available    = isset( $update_cache->response ) && is_array( $update_cache->response )
                        ? $update_cache->response : [];

        if ( empty( $available ) ) {
            return $this->ok( [
                'updated'       => [],
                'errors'        => [],
                'total_updated' => 0,
                'message'       => 'No plugin updates available.',
            ] );
        }

        // Restrict to specific plugin files if caller provided an include list
        $include = isset( $args['include'] ) && is_array( $args['include'] ) ? $args['include'] : [];
        if ( ! empty( $include ) ) {
            $available = array_filter( $available, fn( $file ) => in_array( $file, $include, true ), ARRAY_FILTER_USE_KEY );
        }

        $all_plugins = get_plugins();

        if ( $dry_run ) {
            $would_update = [];
            foreach ( $available as $file => $update_data ) {
                $would_update[] = [
                    'file' => $file,
                    'name' => $all_plugins[ $file ]['Name'] ?? $file,
                    'from' => $all_plugins[ $file ]['Version'] ?? '?',
                    'to'   => $update_data->new_version ?? '?',
                ];
            }
            return $this->ok( [
                'dry_run'      => true,
                'would_update' => $would_update,
                'count'        => count( $would_update ),
            ] );
        }

        if ( ! class_exists( 'Plugin_Upgrader' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }
        // WP_Filesystem must be initialised for the upgrader to write files
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();

        $results     = [];
        $error_count = 0;

        foreach ( $available as $file => $update_data ) {
            $from = $all_plugins[ $file ]['Version'] ?? '?';
            $to   = $update_data->new_version ?? '?';
            $name = $all_plugins[ $file ]['Name'] ?? $file;

            // Automatic_Upgrader_Skin runs silently — same skin used by WP background auto-updates
            $upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
            $result   = $upgrader->upgrade( $file );

            $success = $result === true;
            if ( ! $success ) {
                $error_count++;
            }

            $results[] = [
                'file'    => $file,
                'name'    => $name,
                'from'    => $from,
                'to'      => $to,
                'success' => $success,
                'error'   => $success ? null : ( is_wp_error( $result ) ? $result->get_error_message() : (string) $result ),
            ];
        }

        wp_clean_plugins_cache( true );

        return $this->ok(
            [
                'updated'       => array_values( array_filter( $results, fn( $r ) => $r['success'] ) ),
                'errors'        => array_values( array_filter( $results, fn( $r ) => ! $r['success'] ) ),
                'total_updated' => count( $results ) - $error_count,
                'total_errors'  => $error_count,
            ],
            [ 'target_type' => 'plugins', 'target_id' => 0, 'summary' => [ 'updated' => count( $results ) - $error_count ] ]
        );
    }

    /**
     * Normalize a date string to MySQL format (YYYY-MM-DD HH:MM:SS).
     * Accepts MySQL format directly or ISO 8601 with T separator.
     * Returns null if the value cannot be parsed as a valid date.
     */
    private function normalize_post_date( string $raw ): ?string {
        $raw = trim( $raw );

        // Normalize ISO 8601 T separator → space
        $normalized = str_replace( 'T', ' ', $raw );
        // Strip timezone suffix (Z, +HH:MM, -HH:MM) — WP stores local time
        $normalized = preg_replace( '/[\+\-]\d{2}:\d{2}$|Z$/', '', $normalized );
        $normalized = trim( $normalized );

        // Validate with DateTime — rejects invalid dates like 2026-02-30
        $dt = \DateTime::createFromFormat( 'Y-m-d H:i:s', $normalized );
        if ( $dt && $dt->format( 'Y-m-d H:i:s' ) === $normalized ) {
            return $normalized;
        }

        // Accept date-only input, default to midnight
        $dt = \DateTime::createFromFormat( 'Y-m-d', $normalized );
        if ( $dt && $dt->format( 'Y-m-d' ) === $normalized ) {
            return $normalized . ' 00:00:00';
        }

        return null;
    }
}
