<?php
/**
 * Polylang Ops module.
 * Dependency: Polylang must be active (pll_languages_list function exists).
 */
class AICOM_Module_Polylang extends AICOM_Module_Base {

    public function get_module_name(): string {
        return 'polylang';
    }

    public function register_tools(): void {
        $dep    = 'polylang';
        $scopes = 'manage.polylang';

        $this->register( 'pll.languages.list', [
            'class'           => 'discovery',
            'required_scopes' => [ 'read.wp', $scopes ],
            'dependency'      => $dep,
            'description'     => 'List all registered Polylang languages.',
            'input_schema'    => [],
            'handler'         => [ $this, 'handle_languages_list' ],
        ] );

        $this->register( 'pll.post.get_language', [
            'class'           => 'read',
            'required_scopes' => [ 'read.wp', $scopes ],
            'dependency'      => $dep,
            'description'     => 'Get the language of a post.',
            'input_schema'    => [
                'post_id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_post_get_language' ],
        ] );

        $this->register( 'pll.post.set_language', [
            'class'           => 'write',
            'required_scopes' => [ 'write.wp.posts', $scopes ],
            'dependency'      => $dep,
            'description'     => 'Set the language of a post.',
            'input_schema'    => [
                'post_id'  => [ 'type' => 'integer', 'required' => true ],
                'language' => [ 'type' => 'string',  'required' => true, 'description' => 'Language slug (e.g. "en", "ro").' ],
            ],
            'handler'         => [ $this, 'handle_post_set_language' ],
        ] );

        $this->register( 'pll.post.get_translations', [
            'class'           => 'read',
            'required_scopes' => [ 'read.wp', $scopes ],
            'dependency'      => $dep,
            'description'     => 'Get all translations linked to a post.',
            'input_schema'    => [
                'post_id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_post_get_translations' ],
        ] );

        $this->register( 'pll.post.link_translation', [
            'class'           => 'admin_sensitive',
            'required_scopes' => [ 'write.wp.posts', $scopes ],
            'dependency'      => $dep,
            'description'     => 'Link two posts as translations of each other (confirm=true required).',
            'input_schema'    => [
                'post_id_1' => [ 'type' => 'integer', 'required' => true, 'description' => 'ID of first post.' ],
                'post_id_2' => [ 'type' => 'integer', 'required' => true, 'description' => 'ID of second post.' ],
                'lang_1'    => [ 'type' => 'string',  'required' => true, 'description' => 'Language slug of post_id_1 (e.g. "en").' ],
                'lang_2'    => [ 'type' => 'string',  'required' => true, 'description' => 'Language slug of post_id_2 (e.g. "ro").' ],
                'confirm'   => [ 'type' => 'boolean', 'required' => true, 'description' => 'Must be true to execute.' ],
            ],
            'handler'         => [ $this, 'handle_post_link_translation' ],
        ] );

        $this->register( 'pll.post.unlink_translation', [
            'class'           => 'admin_sensitive',
            'required_scopes' => [ 'write.wp.posts', $scopes ],
            'dependency'      => $dep,
            'description'     => 'Unlink a post from its translations group (confirm=true required).',
            'input_schema'    => [
                'post_id' => [ 'type' => 'integer', 'required' => true ],
                'confirm' => [ 'type' => 'boolean', 'required' => true, 'description' => 'Must be true to execute.' ],
            ],
            'handler'         => [ $this, 'handle_post_unlink_translation' ],
        ] );

        $this->register( 'pll.term.get_language', [
            'class'           => 'read',
            'required_scopes' => [ 'read.wp', $scopes ],
            'dependency'      => $dep,
            'description'     => 'Get the language of a term.',
            'input_schema'    => [
                'term_id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_term_get_language' ],
        ] );

        $this->register( 'pll.term.set_language', [
            'class'           => 'write',
            'required_scopes' => [ 'manage.taxonomies', $scopes ],
            'dependency'      => $dep,
            'description'     => 'Set the language of a term.',
            'input_schema'    => [
                'term_id'  => [ 'type' => 'integer', 'required' => true ],
                'language' => [ 'type' => 'string',  'required' => true, 'description' => 'Language slug (e.g. "en", "ro").' ],
            ],
            'handler'         => [ $this, 'handle_term_set_language' ],
        ] );

        $this->register( 'pll.term.get_translations', [
            'class'           => 'read',
            'required_scopes' => [ 'read.wp', $scopes ],
            'dependency'      => $dep,
            'description'     => 'Get all term translations.',
            'input_schema'    => [
                'term_id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_term_get_translations' ],
        ] );

        $this->register( 'pll.term.link_translation', [
            'class'           => 'admin_sensitive',
            'required_scopes' => [ 'manage.taxonomies', $scopes ],
            'dependency'      => $dep,
            'description'     => 'Link two terms as translations of each other (confirm=true required).',
            'input_schema'    => [
                'term_id_1' => [ 'type' => 'integer', 'required' => true, 'description' => 'ID of first term.' ],
                'term_id_2' => [ 'type' => 'integer', 'required' => true, 'description' => 'ID of second term.' ],
                'lang_1'    => [ 'type' => 'string',  'required' => true, 'description' => 'Language slug of term_id_1.' ],
                'lang_2'    => [ 'type' => 'string',  'required' => true, 'description' => 'Language slug of term_id_2.' ],
                'confirm'   => [ 'type' => 'boolean', 'required' => true, 'description' => 'Must be true to execute.' ],
            ],
            'handler'         => [ $this, 'handle_term_link_translation' ],
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────

    public function handle_languages_list( array $args, array $key_record, bool $dry_run ): array {
        $languages = pll_languages_list( [ 'fields' => false ] );
        $result    = [];

        foreach ( $languages as $lang ) {
            $result[] = [
                'slug'    => $lang->slug,
                'name'    => $lang->name,
                'locale'  => $lang->locale,
                'flag'    => $lang->flag_url ?? '',
                'default' => ( function_exists( 'pll_default_language' ) && pll_default_language() === $lang->slug ),
            ];
        }

        return $this->ok( [ 'languages' => $result ] );
    }

    public function handle_post_get_language( array $args, array $key_record, bool $dry_run ): array {
        $post_id = $this->require_int( $args, 'post_id' );
        if ( ! $post_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter post_id is required', 'validation_failed' );
        }

        $lang = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post_id ) : null;

        return $this->ok( [ 'post_id' => $post_id, 'language' => $lang ] );
    }

    public function handle_post_set_language( array $args, array $key_record, bool $dry_run ): array {
        $post_id = $this->require_int( $args, 'post_id' );
        $lang    = sanitize_key( $args['language'] ?? '' );

        if ( ! $post_id || ! $lang ) {
            return $this->err( 'MISSING_PARAM', 'Parameters post_id and language are required', 'validation_failed' );
        }

        if ( ! AICOM_Policy_Engine::check_language_allowlist( $key_record, $lang ) ) {
            return $this->err( 'DENIED_ALLOWLIST', "Language not in allowlist: $lang", 'denied_allowlist', 403 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_set_language' => $lang ] );
        }

        if ( function_exists( 'pll_set_post_language' ) ) {
            pll_set_post_language( $post_id, $lang );
        }

        return $this->ok(
            [ 'post_id' => $post_id, 'language' => $lang, 'updated' => true ],
            [ 'target_type' => 'post', 'target_id' => $post_id ]
        );
    }

    public function handle_post_get_translations( array $args, array $key_record, bool $dry_run ): array {
        $post_id = $this->require_int( $args, 'post_id' );
        if ( ! $post_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter post_id is required', 'validation_failed' );
        }

        $translations = function_exists( 'pll_get_post_translations' ) ? pll_get_post_translations( $post_id ) : [];

        return $this->ok( [ 'post_id' => $post_id, 'translations' => $translations ] );
    }

    public function handle_post_link_translation( array $args, array $key_record, bool $dry_run ): array {
        $post_id_1 = $this->require_int( $args, 'post_id_1' );
        $post_id_2 = $this->require_int( $args, 'post_id_2' );
        $lang_1    = sanitize_key( $args['lang_1'] ?? '' );
        $lang_2    = sanitize_key( $args['lang_2'] ?? '' );

        if ( ! $post_id_1 || ! $post_id_2 || ! $lang_1 || ! $lang_2 ) {
            return $this->err( 'MISSING_PARAM', 'Parameters post_id_1, post_id_2, lang_1, lang_2 are required', 'validation_failed' );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_link' => [ $post_id_1 => $lang_1, $post_id_2 => $lang_2 ] ] );
        }

        if ( function_exists( 'pll_save_post_translations' ) ) {
            pll_save_post_translations( [ $lang_1 => $post_id_1, $lang_2 => $post_id_2 ] );
        }

        return $this->ok(
            [ 'linked' => true, 'posts' => [ $post_id_1, $post_id_2 ] ],
            [ 'target_type' => 'pll_translation', 'target_id' => "$post_id_1-$post_id_2" ]
        );
    }

    public function handle_post_unlink_translation( array $args, array $key_record, bool $dry_run ): array {
        $post_id = $this->require_int( $args, 'post_id' );
        if ( ! $post_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter post_id is required', 'validation_failed' );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_unlink_post_id' => $post_id ] );
        }

        // Remove from translation group by saving only itself
        if ( function_exists( 'pll_get_post_language' ) && function_exists( 'pll_save_post_translations' ) ) {
            $lang = pll_get_post_language( $post_id );
            if ( $lang ) {
                pll_save_post_translations( [ $lang => $post_id ] );
            }
        }

        return $this->ok(
            [ 'post_id' => $post_id, 'unlinked' => true ],
            [ 'target_type' => 'post', 'target_id' => $post_id ]
        );
    }

    public function handle_term_get_language( array $args, array $key_record, bool $dry_run ): array {
        $term_id = $this->require_int( $args, 'term_id' );
        if ( ! $term_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter term_id is required', 'validation_failed' );
        }

        $lang = function_exists( 'pll_get_term_language' ) ? pll_get_term_language( $term_id ) : null;

        return $this->ok( [ 'term_id' => $term_id, 'language' => $lang ] );
    }

    public function handle_term_set_language( array $args, array $key_record, bool $dry_run ): array {
        $term_id = $this->require_int( $args, 'term_id' );
        $lang    = sanitize_key( $args['language'] ?? '' );

        if ( ! $term_id || ! $lang ) {
            return $this->err( 'MISSING_PARAM', 'Parameters term_id and language are required', 'validation_failed' );
        }

        if ( ! AICOM_Policy_Engine::check_language_allowlist( $key_record, $lang ) ) {
            return $this->err( 'DENIED_ALLOWLIST', "Language not in allowlist: $lang", 'denied_allowlist', 403 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_set_language' => $lang ] );
        }

        if ( function_exists( 'pll_set_term_language' ) ) {
            pll_set_term_language( $term_id, $lang );
        }

        return $this->ok(
            [ 'term_id' => $term_id, 'language' => $lang, 'updated' => true ],
            [ 'target_type' => 'term', 'target_id' => $term_id ]
        );
    }

    public function handle_term_get_translations( array $args, array $key_record, bool $dry_run ): array {
        $term_id = $this->require_int( $args, 'term_id' );
        if ( ! $term_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter term_id is required', 'validation_failed' );
        }

        $translations = function_exists( 'pll_get_term_translations' ) ? pll_get_term_translations( $term_id ) : [];

        return $this->ok( [ 'term_id' => $term_id, 'translations' => $translations ] );
    }

    public function handle_term_link_translation( array $args, array $key_record, bool $dry_run ): array {
        $term_id_1 = $this->require_int( $args, 'term_id_1' );
        $term_id_2 = $this->require_int( $args, 'term_id_2' );
        $lang_1    = sanitize_key( $args['lang_1'] ?? '' );
        $lang_2    = sanitize_key( $args['lang_2'] ?? '' );

        if ( ! $term_id_1 || ! $term_id_2 || ! $lang_1 || ! $lang_2 ) {
            return $this->err( 'MISSING_PARAM', 'Parameters term_id_1, term_id_2, lang_1, lang_2 are required', 'validation_failed' );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_link_terms' => [ $term_id_1, $term_id_2 ] ] );
        }

        if ( function_exists( 'pll_save_term_translations' ) ) {
            pll_save_term_translations( [ $lang_1 => $term_id_1, $lang_2 => $term_id_2 ] );
        }

        return $this->ok(
            [ 'linked' => true, 'terms' => [ $term_id_1, $term_id_2 ] ],
            [ 'target_type' => 'pll_term_translation', 'target_id' => "$term_id_1-$term_id_2" ]
        );
    }
}
