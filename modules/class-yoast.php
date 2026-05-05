<?php
/**
 * Yoast SEO module for AICOM.
 *
 * Exposes Yoast SEO read/write operations to AI agents.
 * Requires Yoast SEO (free or premium) to be active.
 *
 * Registered tools (9):
 *   yoast.status              — plugin version, premium flag
 *   yoast.post.get            — all Yoast meta for a post (title, desc, robots, schema, scores)
 *   yoast.post.set            — set Yoast meta fields on a post
 *   yoast.post.social.get     — Open Graph + Twitter card meta for a post
 *   yoast.post.social.set     — set OG + Twitter meta for a post
 *   yoast.posts.bulk_get      — SEO summary for multiple posts (audit use case)
 *   yoast.term.get            — SEO meta for a taxonomy term
 *   yoast.term.set            — set SEO meta for a taxonomy term
 *   yoast.site.get            — global settings: title/desc templates, social profiles
 */
class AICOM_Module_Yoast extends AICOM_Module_Base {

    const META_PREFIX = '_yoast_wpseo_';

    public function get_module_name(): string {
        return 'yoast';
    }

    public function register_tools(): void {
        $dep   = 'yoast';
        $scope = 'manage.yoast';

        $this->register( 'yoast.status', [
            'class'           => 'read',
            'dependency'      => $dep,
            'required_scopes' => [ 'read.wp' ],
            'description'     => 'Yoast SEO plugin status: version, free vs premium.',
            'input_schema'    => [],
            'handler'         => [ $this, 'handle_status' ],
        ] );

        $this->register( 'yoast.post.get', [
            'class'           => 'read',
            'dependency'      => $dep,
            'required_scopes' => [ 'read.wp' ],
            'description'     => 'Get all Yoast SEO meta for a post: title, meta description, focus keyphrase, robots, canonical, schema type, cornerstone flag, SEO and readability scores.',
            'input_schema'    => [
                'post_id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_post_get' ],
        ] );

        $this->register( 'yoast.post.set', [
            'class'            => 'write',
            'dependency'       => $dep,
            'required_scopes'  => [ $scope ],
            'supports_dry_run' => true,
            'description'      => 'Set Yoast SEO meta on a post. Only provided fields are updated. Accepted: title, metadesc, focuskw, canonical, noindex, nofollow, breadcrumb_title, schema_page_type, schema_article_type, is_cornerstone.',
            'input_schema'     => [
                'post_id'             => [ 'type' => 'integer', 'required' => true ],
                'title'               => [ 'type' => 'string',  'description' => 'SEO title. Supports Yoast vars: %%title%%, %%sep%%, %%sitename%%.' ],
                'metadesc'            => [ 'type' => 'string',  'description' => 'Meta description (recommended 120-156 chars).' ],
                'focuskw'             => [ 'type' => 'string',  'description' => 'Primary focus keyphrase.' ],
                'canonical'           => [ 'type' => 'string',  'description' => 'Canonical URL override.' ],
                'noindex'             => [ 'type' => 'boolean', 'description' => 'true = add noindex robots directive.' ],
                'nofollow'            => [ 'type' => 'boolean', 'description' => 'true = add nofollow robots directive.' ],
                'breadcrumb_title'    => [ 'type' => 'string',  'description' => 'Custom breadcrumb title.' ],
                'schema_page_type'    => [ 'type' => 'string',  'description' => 'Schema.org page type (e.g. WebPage, ItemPage, FAQPage).' ],
                'schema_article_type' => [ 'type' => 'string',  'description' => 'Schema.org article type (e.g. Article, NewsArticle, BlogPosting).' ],
                'is_cornerstone'      => [ 'type' => 'boolean', 'description' => 'Mark as cornerstone content.' ],
            ],
            'handler'          => [ $this, 'handle_post_set' ],
        ] );

        $this->register( 'yoast.post.social.get', [
            'class'           => 'read',
            'dependency'      => $dep,
            'required_scopes' => [ 'read.wp' ],
            'description'     => 'Get Open Graph and Twitter card meta for a post.',
            'input_schema'    => [
                'post_id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_post_social_get' ],
        ] );

        $this->register( 'yoast.post.social.set', [
            'class'            => 'write',
            'dependency'       => $dep,
            'required_scopes'  => [ $scope ],
            'supports_dry_run' => true,
            'description'      => 'Set Open Graph and/or Twitter card meta for a post.',
            'input_schema'     => [
                'post_id'             => [ 'type' => 'integer', 'required' => true ],
                'og_title'            => [ 'type' => 'string' ],
                'og_description'      => [ 'type' => 'string' ],
                'og_image'            => [ 'type' => 'string', 'description' => 'Image URL.' ],
                'twitter_title'       => [ 'type' => 'string' ],
                'twitter_description' => [ 'type' => 'string' ],
                'twitter_image'       => [ 'type' => 'string', 'description' => 'Image URL.' ],
            ],
            'handler'          => [ $this, 'handle_post_social_set' ],
        ] );

        $this->register( 'yoast.posts.bulk_get', [
            'class'           => 'read',
            'dependency'      => $dep,
            'required_scopes' => [ 'read.wp' ],
            'description'     => 'Get SEO summary (title, metadesc, focuskw, noindex, scores) for up to 50 posts in one call. Useful for SEO audits.',
            'input_schema'    => [
                'post_ids' => [ 'type' => 'array', 'required' => true, 'description' => 'Array of post IDs (max 50).' ],
            ],
            'handler'         => [ $this, 'handle_posts_bulk_get' ],
        ] );

        $this->register( 'yoast.term.get', [
            'class'           => 'read',
            'dependency'      => $dep,
            'required_scopes' => [ 'read.wp' ],
            'description'     => 'Get Yoast SEO meta for a taxonomy term: title, description, focus keyphrase, robots, canonical, breadcrumb title.',
            'input_schema'    => [
                'term_id'  => [ 'type' => 'integer', 'required' => true ],
                'taxonomy' => [ 'type' => 'string',  'required' => true ],
            ],
            'handler'         => [ $this, 'handle_term_get' ],
        ] );

        $this->register( 'yoast.term.set', [
            'class'            => 'write',
            'dependency'       => $dep,
            'required_scopes'  => [ $scope ],
            'supports_dry_run' => true,
            'description'      => 'Set Yoast SEO meta for a taxonomy term.',
            'input_schema'     => [
                'term_id'  => [ 'type' => 'integer', 'required' => true ],
                'taxonomy' => [ 'type' => 'string',  'required' => true ],
                'title'    => [ 'type' => 'string' ],
                'metadesc' => [ 'type' => 'string' ],
                'focuskw'  => [ 'type' => 'string' ],
                'canonical' => [ 'type' => 'string' ],
                'noindex'  => [ 'type' => 'boolean' ],
                'bctitle'  => [ 'type' => 'string', 'description' => 'Custom breadcrumb title for this term.' ],
            ],
            'handler'          => [ $this, 'handle_term_set' ],
        ] );

        $this->register( 'yoast.site.get', [
            'class'           => 'read',
            'dependency'      => $dep,
            'required_scopes' => [ 'read.wp' ],
            'description'     => 'Global Yoast SEO settings: SEO title/desc templates per post type and taxonomy, social profiles, OG default image, breadcrumbs toggle.',
            'input_schema'    => [],
            'handler'         => [ $this, 'handle_site_get' ],
        ] );
    }

    // ── Handlers ─────────────────────────────────────────────────────────────

    public function handle_status( array $args, array $key_record, bool $dry_run ): array {
        $is_premium = class_exists( 'WPSEO_Premium' );

        return $this->ok( [
            'active'          => true,
            'version'         => defined( 'WPSEO_VERSION' ) ? WPSEO_VERSION : null,
            'premium'         => $is_premium,
            'premium_version' => $is_premium && defined( 'WPSEO_PREMIUM_VERSION' ) ? WPSEO_PREMIUM_VERSION : null,
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

        $get = fn( $key ) => WPSEO_Meta::get_value( $key, $post_id );

        return $this->ok( [
            'post_id'             => $post_id,
            'title'               => $get( 'title' ),
            'metadesc'            => $get( 'metadesc' ),
            'focuskw'             => $get( 'focuskw' ),
            'canonical'           => $get( 'canonical' ),
            'noindex'             => $get( 'meta-robots-noindex' ) === '1',
            'nofollow'            => $get( 'meta-robots-nofollow' ) === '1',
            'robots_adv'          => $get( 'meta-robots-adv' ),
            'breadcrumb_title'    => $get( 'bctitle' ),
            'schema_page_type'    => $get( 'schema_page_type' ),
            'schema_article_type' => $get( 'schema_article_type' ),
            'is_cornerstone'      => (bool) $get( 'is_cornerstone' ),
            'seo_score'           => (int) $get( 'linkdex' ),
            'readability_score'   => (int) $get( 'content_score' ),
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

        $field_map = [
            'title'               => 'title',
            'metadesc'            => 'metadesc',
            'focuskw'             => 'focuskw',
            'canonical'           => 'canonical',
            'breadcrumb_title'    => 'bctitle',
            'schema_page_type'    => 'schema_page_type',
            'schema_article_type' => 'schema_article_type',
        ];
        $bool_fields = [ 'noindex' => 'meta-robots-noindex', 'nofollow' => 'meta-robots-nofollow', 'is_cornerstone' => 'is_cornerstone' ];
        $all_writable = array_merge( array_keys( $field_map ), array_keys( $bool_fields ) );

        if ( $dry_run ) {
            $would = array_values( array_intersect( array_keys( $args ), $all_writable ) );
            return $this->ok( [ 'dry_run' => true, 'would_update' => $would ] );
        }

        $updated = [];

        foreach ( $field_map as $input_key => $yoast_key ) {
            if ( isset( $args[ $input_key ] ) ) {
                update_post_meta( $post_id, self::META_PREFIX . $yoast_key, sanitize_text_field( $args[ $input_key ] ) );
                $updated[] = $input_key;
            }
        }

        foreach ( $bool_fields as $input_key => $yoast_key ) {
            if ( isset( $args[ $input_key ] ) ) {
                update_post_meta( $post_id, self::META_PREFIX . $yoast_key, $args[ $input_key ] ? '1' : '0' );
                $updated[] = $input_key;
            }
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

        $get = fn( $key ) => WPSEO_Meta::get_value( $key, $post_id );

        return $this->ok( [
            'post_id'             => $post_id,
            'og_title'            => $get( 'opengraph-title' ),
            'og_description'      => $get( 'opengraph-description' ),
            'og_image'            => $get( 'opengraph-image' ),
            'twitter_title'       => $get( 'twitter-title' ),
            'twitter_description' => $get( 'twitter-description' ),
            'twitter_image'       => $get( 'twitter-image' ),
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
            'og_title'            => 'opengraph-title',
            'og_description'      => 'opengraph-description',
            'og_image'            => 'opengraph-image',
            'twitter_title'       => 'twitter-title',
            'twitter_description' => 'twitter-description',
            'twitter_image'       => 'twitter-image',
        ];

        if ( $dry_run ) {
            $would = array_values( array_intersect( array_keys( $args ), array_keys( $social_map ) ) );
            return $this->ok( [ 'dry_run' => true, 'would_update' => $would ] );
        }

        $updated = [];
        foreach ( $social_map as $input_key => $yoast_key ) {
            if ( isset( $args[ $input_key ] ) ) {
                update_post_meta( $post_id, self::META_PREFIX . $yoast_key, sanitize_text_field( $args[ $input_key ] ) );
                $updated[] = $input_key;
            }
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
            $get       = fn( $key ) => WPSEO_Meta::get_value( $key, $post_id );
            $results[] = [
                'post_id'           => $post_id,
                'post_title'        => $post->post_title,
                'post_status'       => $post->post_status,
                'seo_title'         => $get( 'title' ),
                'metadesc'          => $get( 'metadesc' ),
                'focuskw'           => $get( 'focuskw' ),
                'noindex'           => $get( 'meta-robots-noindex' ) === '1',
                'seo_score'         => (int) $get( 'linkdex' ),
                'readability_score' => (int) $get( 'content_score' ),
                'is_cornerstone'    => (bool) $get( 'is_cornerstone' ),
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

        $get = fn( $key ) => WPSEO_Taxonomy_Meta::get_term_meta( $term, $taxonomy, $key );

        return $this->ok( [
            'term_id'   => $term_id,
            'taxonomy'  => $taxonomy,
            'term_name' => $term->name,
            'title'     => $get( 'wpseo_title' ),
            'metadesc'  => $get( 'wpseo_desc' ),
            'focuskw'   => $get( 'wpseo_focuskw' ),
            'canonical' => $get( 'wpseo_canonical' ),
            'noindex'   => $get( 'wpseo_noindex' ) === 'noindex',
            'bctitle'   => $get( 'wpseo_bctitle' ),
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

        $field_map = [
            'title'    => 'wpseo_title',
            'metadesc' => 'wpseo_desc',
            'focuskw'  => 'wpseo_focuskw',
            'canonical' => 'wpseo_canonical',
            'bctitle'  => 'wpseo_bctitle',
        ];

        $all_writable = array_merge( array_keys( $field_map ), [ 'noindex' ] );

        if ( $dry_run ) {
            $would = array_values( array_intersect( array_keys( $args ), $all_writable ) );
            return $this->ok( [ 'dry_run' => true, 'would_update' => $would ] );
        }

        $updated      = [];
        $all_tax_meta = (array) get_option( 'wpseo_taxonomy_meta', [] );

        foreach ( $field_map as $input_key => $yoast_key ) {
            if ( isset( $args[ $input_key ] ) ) {
                $all_tax_meta[ $taxonomy ][ $term_id ][ $yoast_key ] = sanitize_text_field( $args[ $input_key ] );
                $updated[] = $input_key;
            }
        }

        if ( isset( $args['noindex'] ) ) {
            $all_tax_meta[ $taxonomy ][ $term_id ]['wpseo_noindex'] = $args['noindex'] ? 'noindex' : 'index';
            $updated[] = 'noindex';
        }

        if ( empty( $updated ) ) {
            return $this->err( 'MISSING_PARAM', 'No valid fields provided. Accepted: ' . implode( ', ', $all_writable ), 'validation_failed' );
        }

        update_option( 'wpseo_taxonomy_meta', $all_tax_meta );

        return $this->ok(
            [ 'term_id' => $term_id, 'taxonomy' => $taxonomy, 'updated' => $updated ],
            [ 'target_type' => 'term', 'target_id' => $term_id ]
        );
    }

    public function handle_site_get( array $args, array $key_record, bool $dry_run ): array {
        $titles  = (array) get_option( 'wpseo_titles', [] );
        $social  = (array) get_option( 'wpseo_social', [] );

        $post_types = get_post_types( [ 'public' => true ], 'names' );
        $templates  = [];
        foreach ( $post_types as $pt ) {
            $templates[ $pt ] = [
                'title'    => $titles[ "title-$pt" ] ?? '',
                'metadesc' => $titles[ "metadesc-$pt" ] ?? '',
                'noindex'  => ! empty( $titles[ "noindex-$pt" ] ),
            ];
        }

        $taxonomies    = get_taxonomies( [ 'public' => true ], 'names' );
        $tax_templates = [];
        foreach ( $taxonomies as $tax ) {
            $tax_templates[ $tax ] = [
                'title'    => $titles[ "title-tax-$tax" ] ?? '',
                'metadesc' => $titles[ "metadesc-tax-$tax" ] ?? '',
            ];
        }

        return $this->ok( [
            'post_type_templates' => $templates,
            'taxonomy_templates'  => $tax_templates,
            'social_profiles'     => [
                'facebook'  => $social['facebook_site'] ?? '',
                'twitter'   => $social['twitter_site'] ?? '',
                'instagram' => $social['instagram_url'] ?? '',
                'linkedin'  => $social['linkedin_url'] ?? '',
                'youtube'   => $social['youtube_url'] ?? '',
            ],
            'og_default_image'   => $social['og_default_image'] ?? '',
            'breadcrumbs_enabled' => ! empty( $titles['breadcrumbs-enable'] ),
        ] );
    }
}
