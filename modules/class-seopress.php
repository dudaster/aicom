<?php
/**
 * SEOPress module for AICOM.
 *
 * Exposes SEOPress (free) read/write operations to AI agents.
 * Requires SEOPress to be active. Meta keys and value formats (e.g. robots
 * checkboxes stored as 'yes' or deleted, never 'no') verified directly
 * against SEOPress's own source — its REST controllers under
 * src/Actions/Api/Metas/ and src/Actions/Api/TitleDescriptionMeta.php,
 * confirmed by its own Abilities API registrations (9.9.0+) which document
 * the exact same keys — rather than assumed from Yoast's conventions.
 *
 * Registered tools (9):
 *   seopress.status              — plugin version, pro flag
 *   seopress.post.get            — title, description, focus keyword, robots, canonical for a post
 *   seopress.post.set            — set SEOPress meta fields on a post
 *   seopress.post.social.get     — Open Graph + Twitter card meta for a post
 *   seopress.post.social.set     — set OG + Twitter meta for a post
 *   seopress.posts.bulk_get      — SEO summary for multiple posts (audit use case)
 *   seopress.term.get            — SEO meta for a taxonomy term
 *   seopress.term.set            — set SEO meta for a taxonomy term
 *   seopress.site.get            — global title/description templates per post type and taxonomy
 */
class AICOM_Module_SEOPress extends AICOM_Module_Base {

    // Unlike Yoast's single '_yoast_wpseo_' prefix + short suffixes, SEOPress
    // meta keys are already the full option name (e.g. '_seopress_titles_title') —
    // no shared prefix to factor out cleanly, so each key is spelled out below.

    public function get_module_name(): string {
        return 'seopress';
    }

    public function register_tools(): void {
        $dep   = 'seopress';
        $scope = 'manage.seopress';

        $this->register( 'seopress.status', [
            'class'           => 'read',
            'dependency'      => $dep,
            'required_scopes' => [ 'read.wp' ],
            'description'     => 'SEOPress plugin status: version, free vs pro.',
            'input_schema'    => [],
            'handler'         => [ $this, 'handle_status' ],
        ] );

        $this->register( 'seopress.post.get', [
            'class'           => 'read',
            'dependency'      => $dep,
            'required_scopes' => [ 'read.wp' ],
            'description'     => 'Get all SEOPress SEO meta for a post: title, meta description, focus keyword, robots directives (noindex/nofollow/nosnippet/noimageindex), canonical URL, primary category.',
            'input_schema'    => [
                'post_id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_post_get' ],
        ] );

        $this->register( 'seopress.post.set', [
            'class'            => 'write',
            'dependency'       => $dep,
            'required_scopes'  => [ $scope ],
            'supports_dry_run' => true,
            'description'      => 'Set SEOPress SEO meta on a post. Only provided fields are updated. Passing an empty string for title/metadesc/canonical clears it back to the site-wide template (matches SEOPress\'s own behavior). Accepted: title, metadesc, focuskw, canonical, noindex, nofollow, nosnippet, noimageindex, primary_category_id, freeze_modified_date.',
            'input_schema'     => [
                'post_id'              => [ 'type' => 'integer', 'required' => true ],
                'title'                => [ 'type' => 'string',  'description' => 'SEO title. Supports SEOPress tags: %%post_title%%, %%sep%%, %%sitetitle%%.' ],
                'metadesc'             => [ 'type' => 'string',  'description' => 'Meta description.' ],
                'focuskw'              => [ 'type' => 'string',  'description' => 'Target/focus keyword for content analysis.' ],
                'canonical'            => [ 'type' => 'string',  'description' => 'Canonical URL override.' ],
                'noindex'              => [ 'type' => 'boolean', 'description' => 'true = add noindex robots directive.' ],
                'nofollow'             => [ 'type' => 'boolean', 'description' => 'true = add nofollow robots directive.' ],
                'nosnippet'            => [ 'type' => 'boolean', 'description' => 'true = do not display a description snippet in search results.' ],
                'noimageindex'         => [ 'type' => 'boolean', 'description' => 'true = do not index images on this page.' ],
                'primary_category_id'  => [ 'type' => 'integer', 'description' => 'Term ID of the primary category (used in permalinks/breadcrumbs when a post has multiple categories). Only meaningful for post types with categories.' ],
                'freeze_modified_date' => [ 'type' => 'boolean', 'description' => 'true = do not update the last-modified date on save (for minor edits).' ],
            ],
            'handler'          => [ $this, 'handle_post_set' ],
        ] );

        $this->register( 'seopress.post.social.get', [
            'class'           => 'read',
            'dependency'      => $dep,
            'required_scopes' => [ 'read.wp' ],
            'description'     => 'Get Open Graph (Facebook) and Twitter card meta for a post.',
            'input_schema'    => [
                'post_id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_post_social_get' ],
        ] );

        $this->register( 'seopress.post.social.set', [
            'class'            => 'write',
            'dependency'       => $dep,
            'required_scopes'  => [ $scope ],
            'supports_dry_run' => true,
            'description'      => 'Set Open Graph (Facebook) and/or Twitter card meta for a post.',
            'input_schema'     => [
                'post_id'        => [ 'type' => 'integer', 'required' => true ],
                'fb_title'       => [ 'type' => 'string' ],
                'fb_description' => [ 'type' => 'string' ],
                'fb_image'       => [ 'type' => 'string', 'description' => 'Image URL.' ],
                'twitter_title'       => [ 'type' => 'string' ],
                'twitter_description' => [ 'type' => 'string' ],
                'twitter_image'       => [ 'type' => 'string', 'description' => 'Image URL.' ],
            ],
            'handler'          => [ $this, 'handle_post_social_set' ],
        ] );

        $this->register( 'seopress.posts.bulk_get', [
            'class'           => 'read',
            'dependency'      => $dep,
            'required_scopes' => [ 'read.wp' ],
            'description'     => 'Get SEO summary (title, metadesc, focuskw, noindex) for up to 50 posts in one call. Useful for SEO audits.',
            'input_schema'    => [
                'post_ids' => [ 'type' => 'array', 'required' => true, 'description' => 'Array of post IDs (max 50).' ],
            ],
            'handler'         => [ $this, 'handle_posts_bulk_get' ],
        ] );

        $this->register( 'seopress.term.get', [
            'class'           => 'read',
            'dependency'      => $dep,
            'required_scopes' => [ 'read.wp' ],
            'description'     => 'Get SEOPress SEO meta for a taxonomy term: title, description, focus keyword, canonical, noindex.',
            'input_schema'    => [
                'term_id'  => [ 'type' => 'integer', 'required' => true ],
                'taxonomy' => [ 'type' => 'string',  'required' => true ],
            ],
            'handler'         => [ $this, 'handle_term_get' ],
        ] );

        $this->register( 'seopress.term.set', [
            'class'            => 'write',
            'dependency'       => $dep,
            'required_scopes'  => [ $scope ],
            'supports_dry_run' => true,
            'description'      => 'Set SEOPress SEO meta for a taxonomy term.',
            'input_schema'     => [
                'term_id'   => [ 'type' => 'integer', 'required' => true ],
                'taxonomy'  => [ 'type' => 'string',  'required' => true ],
                'title'     => [ 'type' => 'string' ],
                'metadesc'  => [ 'type' => 'string' ],
                'focuskw'   => [ 'type' => 'string' ],
                'canonical' => [ 'type' => 'string' ],
                'noindex'   => [ 'type' => 'boolean' ],
            ],
            'handler'          => [ $this, 'handle_term_set' ],
        ] );

        $this->register( 'seopress.site.get', [
            'class'           => 'read',
            'dependency'      => $dep,
            'required_scopes' => [ 'read.wp' ],
            'description'     => 'Global SEOPress settings: SEO title/description templates per post type and taxonomy, title separator, Open Graph / Twitter card toggles.',
            'input_schema'    => [],
            'handler'         => [ $this, 'handle_site_get' ],
        ] );
    }

    // ── Handlers ─────────────────────────────────────────────────────────────

    public function handle_status( array $args, array $key_record, bool $dry_run ): array {
        $is_pro = defined( 'SEOPRESS_PRO_VERSION' );

        return $this->ok( [
            'active'      => true,
            'version'     => defined( 'SEOPRESS_VERSION' ) ? SEOPRESS_VERSION : null,
            'pro'         => $is_pro,
            'pro_version' => $is_pro ? SEOPRESS_PRO_VERSION : null,
        ] );
    }

    public function handle_post_get( array $args, array $key_record, bool $dry_run ): array {
        $post_id = $this->require_int( $args, 'post_id' );
        if ( ! $post_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter post_id is required', 'validation_failed' );
        }
        if ( ! get_post( $post_id ) ) {
            return $this->err( 'NOT_FOUND', "Post $post_id not found", 'error', 404 );
        }

        $get = fn( $key ) => get_post_meta( $post_id, $key, true );

        return $this->ok( [
            'post_id'              => $post_id,
            'title'                => $get( '_seopress_titles_title' ),
            'metadesc'             => $get( '_seopress_titles_desc' ),
            'focuskw'              => $get( '_seopress_analysis_target_kw' ),
            'canonical'            => $get( '_seopress_robots_canonical' ),
            'noindex'              => $get( '_seopress_robots_index' ) === 'yes',
            'nofollow'             => $get( '_seopress_robots_follow' ) === 'yes',
            'nosnippet'            => $get( '_seopress_robots_snippet' ) === 'yes',
            'noimageindex'         => $get( '_seopress_robots_imageindex' ) === 'yes',
            'primary_category_id'  => $get( '_seopress_robots_primary_cat' ) ?: null,
            'freeze_modified_date' => $get( '_seopress_robots_freeze_modified_date' ) === 'yes',
        ], [ 'target_type' => 'post', 'target_id' => $post_id ] );
    }

    public function handle_post_set( array $args, array $key_record, bool $dry_run ): array {
        $post_id = $this->require_int( $args, 'post_id' );
        if ( ! $post_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter post_id is required', 'validation_failed' );
        }
        if ( ! get_post( $post_id ) ) {
            return $this->err( 'NOT_FOUND', "Post $post_id not found", 'error', 404 );
        }

        // Text fields: SEOPress itself deletes the meta (falls back to the
        // site-wide template) when the value is empty, rather than storing
        // an empty string — mirrored here for consistent behavior.
        $text_field_map = [
            'title'     => [ '_seopress_titles_title', 'sanitize_text_field' ],
            'metadesc'  => [ '_seopress_titles_desc', 'sanitize_textarea_field' ],
            'focuskw'   => [ '_seopress_analysis_target_kw', 'sanitize_text_field' ],
            'canonical' => [ '_seopress_robots_canonical', 'sanitize_url' ],
        ];
        // Checkbox fields: SEOPress stores 'yes' when on, and DELETES the key
        // (not 'no') when off — a stored 'no' is never a value it writes itself.
        $checkbox_field_map = [
            'noindex'              => '_seopress_robots_index',
            'nofollow'             => '_seopress_robots_follow',
            'nosnippet'            => '_seopress_robots_snippet',
            'noimageindex'         => '_seopress_robots_imageindex',
            'freeze_modified_date' => '_seopress_robots_freeze_modified_date',
        ];
        $all_writable = array_merge( array_keys( $text_field_map ), array_keys( $checkbox_field_map ), [ 'primary_category_id' ] );

        if ( $dry_run ) {
            $would = array_values( array_intersect( array_keys( $args ), $all_writable ) );
            return $this->ok( [ 'dry_run' => true, 'would_update' => $would ] );
        }

        $updated = [];

        foreach ( $text_field_map as $input_key => [ $meta_key, $sanitizer ] ) {
            if ( ! isset( $args[ $input_key ] ) ) {
                continue;
            }
            $value = $sanitizer( $args[ $input_key ] );
            if ( $value === '' ) {
                delete_post_meta( $post_id, $meta_key );
            } else {
                update_post_meta( $post_id, $meta_key, $value );
            }
            $updated[] = $input_key;
        }

        foreach ( $checkbox_field_map as $input_key => $meta_key ) {
            if ( ! isset( $args[ $input_key ] ) ) {
                continue;
            }
            if ( $args[ $input_key ] ) {
                update_post_meta( $post_id, $meta_key, 'yes' );
            } else {
                delete_post_meta( $post_id, $meta_key );
            }
            $updated[] = $input_key;
        }

        if ( isset( $args['primary_category_id'] ) ) {
            $cat_id = (int) $args['primary_category_id'];
            if ( $cat_id > 0 ) {
                update_post_meta( $post_id, '_seopress_robots_primary_cat', $cat_id );
            } else {
                delete_post_meta( $post_id, '_seopress_robots_primary_cat' );
            }
            $updated[] = 'primary_category_id';
        }

        if ( empty( $updated ) ) {
            return $this->err( 'MISSING_PARAM', 'No valid fields provided. Accepted: ' . implode( ', ', $all_writable ), 'validation_failed' );
        }

        return $this->ok(
            [ 'post_id' => $post_id, 'updated' => $updated ],
            [ 'target_type' => 'post', 'target_id' => $post_id ]
        );
    }

    public function handle_post_social_get( array $args, array $key_record, bool $dry_run ): array {
        $post_id = $this->require_int( $args, 'post_id' );
        if ( ! $post_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter post_id is required', 'validation_failed' );
        }
        if ( ! get_post( $post_id ) ) {
            return $this->err( 'NOT_FOUND', "Post $post_id not found", 'error', 404 );
        }

        $get = fn( $key ) => get_post_meta( $post_id, $key, true );

        return $this->ok( [
            'post_id'             => $post_id,
            'fb_title'            => $get( '_seopress_social_fb_title' ),
            'fb_description'      => $get( '_seopress_social_fb_desc' ),
            'fb_image'            => $get( '_seopress_social_fb_img' ),
            'twitter_title'       => $get( '_seopress_social_twitter_title' ),
            'twitter_description' => $get( '_seopress_social_twitter_desc' ),
            'twitter_image'       => $get( '_seopress_social_twitter_img' ),
        ], [ 'target_type' => 'post', 'target_id' => $post_id ] );
    }

    public function handle_post_social_set( array $args, array $key_record, bool $dry_run ): array {
        $post_id = $this->require_int( $args, 'post_id' );
        if ( ! $post_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter post_id is required', 'validation_failed' );
        }
        if ( ! get_post( $post_id ) ) {
            return $this->err( 'NOT_FOUND', "Post $post_id not found", 'error', 404 );
        }

        $social_map = [
            'fb_title'            => [ '_seopress_social_fb_title', 'sanitize_text_field' ],
            'fb_description'      => [ '_seopress_social_fb_desc', 'sanitize_textarea_field' ],
            'fb_image'            => [ '_seopress_social_fb_img', 'sanitize_url' ],
            'twitter_title'       => [ '_seopress_social_twitter_title', 'sanitize_text_field' ],
            'twitter_description' => [ '_seopress_social_twitter_desc', 'sanitize_textarea_field' ],
            'twitter_image'       => [ '_seopress_social_twitter_img', 'sanitize_url' ],
        ];

        if ( $dry_run ) {
            $would = array_values( array_intersect( array_keys( $args ), array_keys( $social_map ) ) );
            return $this->ok( [ 'dry_run' => true, 'would_update' => $would ] );
        }

        $updated = [];
        foreach ( $social_map as $input_key => [ $meta_key, $sanitizer ] ) {
            if ( ! isset( $args[ $input_key ] ) ) {
                continue;
            }
            $value = $sanitizer( $args[ $input_key ] );
            if ( $value === '' ) {
                delete_post_meta( $post_id, $meta_key );
            } else {
                update_post_meta( $post_id, $meta_key, $value );
            }
            $updated[] = $input_key;
        }

        if ( empty( $updated ) ) {
            return $this->err( 'MISSING_PARAM', 'No social fields provided. Accepted: ' . implode( ', ', array_keys( $social_map ) ), 'validation_failed' );
        }

        return $this->ok(
            [ 'post_id' => $post_id, 'updated' => $updated ],
            [ 'target_type' => 'post', 'target_id' => $post_id ]
        );
    }

    public function handle_posts_bulk_get( array $args, array $key_record, bool $dry_run ): array {
        $post_ids = array_map( 'intval', (array) ( $args['post_ids'] ?? [] ) );
        if ( empty( $post_ids ) ) {
            return $this->err( 'MISSING_PARAM', 'Parameter post_ids must be a non-empty array', 'validation_failed' );
        }
        if ( count( $post_ids ) > 50 ) {
            return $this->err( 'INVALID_PARAM', 'Maximum 50 post IDs per call', 'validation_failed' );
        }

        $results = [];
        foreach ( $post_ids as $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post ) {
                $results[] = [ 'post_id' => $post_id, 'error' => 'not_found' ];
                continue;
            }
            $get       = fn( $key ) => get_post_meta( $post_id, $key, true );
            $results[] = [
                'post_id'     => $post_id,
                'post_title'  => $post->post_title,
                'post_status' => $post->post_status,
                'seo_title'   => $get( '_seopress_titles_title' ),
                'metadesc'    => $get( '_seopress_titles_desc' ),
                'focuskw'     => $get( '_seopress_analysis_target_kw' ),
                'noindex'     => $get( '_seopress_robots_index' ) === 'yes',
            ];
        }

        return $this->ok( [ 'posts' => $results, 'count' => count( $results ) ] );
    }

    public function handle_term_get( array $args, array $key_record, bool $dry_run ): array {
        $term_id  = $this->require_int( $args, 'term_id' );
        $taxonomy = sanitize_key( $args['taxonomy'] ?? '' );

        if ( ! $term_id || ! $taxonomy ) {
            return $this->err( 'MISSING_PARAM', 'Parameters term_id and taxonomy are required', 'validation_failed' );
        }

        $term = get_term( $term_id, $taxonomy );
        if ( is_wp_error( $term ) || ! $term ) {
            return $this->err( 'NOT_FOUND', "Term $term_id not found in $taxonomy", 'error', 404 );
        }

        $get = fn( $key ) => get_term_meta( $term_id, $key, true );

        return $this->ok( [
            'term_id'   => $term_id,
            'taxonomy'  => $taxonomy,
            'term_name' => $term->name,
            'title'     => $get( '_seopress_titles_title' ),
            'metadesc'  => $get( '_seopress_titles_desc' ),
            'focuskw'   => $get( '_seopress_analysis_target_kw' ),
            'canonical' => $get( '_seopress_robots_canonical' ),
            'noindex'   => $get( '_seopress_robots_index' ) === 'yes',
        ], [ 'target_type' => 'term', 'target_id' => $term_id ] );
    }

    public function handle_term_set( array $args, array $key_record, bool $dry_run ): array {
        $term_id  = $this->require_int( $args, 'term_id' );
        $taxonomy = sanitize_key( $args['taxonomy'] ?? '' );

        if ( ! $term_id || ! $taxonomy ) {
            return $this->err( 'MISSING_PARAM', 'Parameters term_id and taxonomy are required', 'validation_failed' );
        }

        $term = get_term( $term_id, $taxonomy );
        if ( is_wp_error( $term ) || ! $term ) {
            return $this->err( 'NOT_FOUND', "Term $term_id not found in $taxonomy", 'error', 404 );
        }

        $text_field_map = [
            'title'     => [ '_seopress_titles_title', 'sanitize_text_field' ],
            'metadesc'  => [ '_seopress_titles_desc', 'sanitize_textarea_field' ],
            'focuskw'   => [ '_seopress_analysis_target_kw', 'sanitize_text_field' ],
            'canonical' => [ '_seopress_robots_canonical', 'sanitize_url' ],
        ];
        $all_writable = array_merge( array_keys( $text_field_map ), [ 'noindex' ] );

        if ( $dry_run ) {
            $would = array_values( array_intersect( array_keys( $args ), $all_writable ) );
            return $this->ok( [ 'dry_run' => true, 'would_update' => $would ] );
        }

        $updated = [];

        foreach ( $text_field_map as $input_key => [ $meta_key, $sanitizer ] ) {
            if ( ! isset( $args[ $input_key ] ) ) {
                continue;
            }
            $value = $sanitizer( $args[ $input_key ] );
            if ( $value === '' ) {
                delete_term_meta( $term_id, $meta_key );
            } else {
                update_term_meta( $term_id, $meta_key, $value );
            }
            $updated[] = $input_key;
        }

        if ( isset( $args['noindex'] ) ) {
            if ( $args['noindex'] ) {
                update_term_meta( $term_id, '_seopress_robots_index', 'yes' );
            } else {
                delete_term_meta( $term_id, '_seopress_robots_index' );
            }
            $updated[] = 'noindex';
        }

        if ( empty( $updated ) ) {
            return $this->err( 'MISSING_PARAM', 'No valid fields provided. Accepted: ' . implode( ', ', $all_writable ), 'validation_failed' );
        }

        return $this->ok(
            [ 'term_id' => $term_id, 'taxonomy' => $taxonomy, 'updated' => $updated ],
            [ 'target_type' => 'term', 'target_id' => $term_id ]
        );
    }

    public function handle_site_get( array $args, array $key_record, bool $dry_run ): array {
        $titles = (array) get_option( 'seopress_titles_option_name', [] );
        $social = (array) get_option( 'seopress_social_option_name', [] );

        return $this->ok( [
            'separator'              => $titles['seopress_titles_sep'] ?? '',
            'home_title'             => $titles['seopress_titles_home_site_title'] ?? '',
            'home_description'       => $titles['seopress_titles_home_site_desc'] ?? '',
            'post_type_templates'    => $titles['seopress_titles_single_titles'] ?? new stdClass(),
            'taxonomy_templates'     => $titles['seopress_titles_tax_titles'] ?? new stdClass(),
            'open_graph_enabled'     => ( $social['seopress_social_facebook_og'] ?? '' ) === '1',
            'twitter_card_enabled'   => ( $social['seopress_social_twitter_card'] ?? '' ) === '1',
        ] );
    }
}
