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
        $dep      = 'polylang';
        $read     = 'read.polylang';
        $manage   = 'manage.polylang';
        $settings = 'manage.polylang.settings';

        $this->register( 'pll.languages.list', [
            'class'           => 'discovery',
            'required_scopes' => [ 'read.wp', $read ],
            'dependency'      => $dep,
            'description'     => 'List all registered Polylang languages.',
            'input_schema'    => [],
            'handler'         => [ $this, 'handle_languages_list' ],
        ] );

        $this->register( 'pll.post.get_language', [
            'class'           => 'read',
            'required_scopes' => [ 'read.wp', $read ],
            'dependency'      => $dep,
            'description'     => 'Get the language of a post.',
            'input_schema'    => [
                'post_id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_post_get_language' ],
        ] );

        $this->register( 'pll.post.set_language', [
            'class'           => 'write',
            'required_scopes' => [ 'write.wp.posts', $manage ],
            'dependency'      => $dep,
            'description'     => 'Set the language of a post. Step 2 of the full translation workflow: '
                . '(1) create the translated post as a draft with wp.posts.create, '
                . '(2) set its language with this tool, '
                . '(3) link it to the source post with pll.post.link_translation, '
                . '(4) assign the translated category with wp.terms.assign_to_post, '
                . '(5) set the featured image with media.set_featured, '
                . '(6) verify with wp.posts.get and pll.post.get_translations. '
                . 'Response includes persisted/verified so you can confirm the language actually stuck. '
                . 'Example: {"post_id": 456, "language": "en"}',
            'input_schema'    => [
                'post_id'  => [ 'type' => 'integer', 'required' => true ],
                'language' => [ 'type' => 'string',  'required' => true, 'description' => 'Language slug (e.g. "en", "ro").' ],
            ],
            'handler'         => [ $this, 'handle_post_set_language' ],
        ] );

        $this->register( 'pll.post.get_translations', [
            'class'           => 'read',
            'required_scopes' => [ 'read.wp', $read ],
            'dependency'      => $dep,
            'description'     => 'Get all translations linked to a post.',
            'input_schema'    => [
                'post_id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_post_get_translations' ],
        ] );

        $this->register( 'pll.post.link_translation', [
            'class'           => 'admin_sensitive',
            'required_scopes' => [ 'write.wp.posts', $manage ],
            'dependency'      => $dep,
            'description'     => 'Link posts as translations — step 3 of the translation workflow (both posts must '
                . 'already have their language set via pll.post.set_language). Pass translations as '
                . '{"en": 123, "ro": 456, "uk": 789}. Existing group members not mentioned are preserved. '
                . 'Requires confirm=true. Response includes persisted/verified re-read from the group. '
                . 'Example: {"translations": {"ro": 11432, "en": 11435}, "confirm": true}',
            'input_schema'    => [
                'translations' => [ 'type' => 'object', 'required' => true, 'description' => 'Map of language_slug => post_id for all translations to link.' ],
                'confirm'      => [ 'type' => 'boolean', 'required' => true, 'description' => 'Must be true to execute.' ],
            ],
            'handler'         => [ $this, 'handle_post_link_translation' ],
        ] );

        $this->register( 'pll.post.unlink_translation', [
            'class'           => 'admin_sensitive',
            'required_scopes' => [ 'write.wp.posts', $manage ],
            'dependency'      => $dep,
            'description'     => 'Unlink a post from its translations group (confirm=true required). '
                . 'Example: {"post_id": 456, "confirm": true}',
            'input_schema'    => [
                'post_id' => [ 'type' => 'integer', 'required' => true ],
                'confirm' => [ 'type' => 'boolean', 'required' => true, 'description' => 'Must be true to execute.' ],
            ],
            'handler'         => [ $this, 'handle_post_unlink_translation' ],
        ] );

        $this->register( 'pll.term.get_language', [
            'class'           => 'read',
            'required_scopes' => [ 'read.wp', $read ],
            'dependency'      => $dep,
            'description'     => 'Get the language of a term.',
            'input_schema'    => [
                'term_id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_term_get_language' ],
        ] );

        $this->register( 'pll.term.set_language', [
            'class'           => 'write',
            'required_scopes' => [ 'manage.taxonomies', $settings ],
            'dependency'      => $dep,
            'description'     => 'Set the language of a term (category/tag). Analogous to pll.post.set_language but '
                . 'for taxonomy terms — set language first, then link with pll.term.link_translation. '
                . 'Response includes persisted/verified. Example: {"term_id": 330, "language": "en"}',
            'input_schema'    => [
                'term_id'  => [ 'type' => 'integer', 'required' => true ],
                'language' => [ 'type' => 'string',  'required' => true, 'description' => 'Language slug (e.g. "en", "ro").' ],
            ],
            'handler'         => [ $this, 'handle_term_set_language' ],
        ] );

        $this->register( 'pll.term.get_translations', [
            'class'           => 'read',
            'required_scopes' => [ 'read.wp', $read ],
            'dependency'      => $dep,
            'description'     => 'Get all term translations.',
            'input_schema'    => [
                'term_id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_term_get_translations' ],
        ] );

        $this->register( 'pll.strings.list', [
            'class'           => 'read',
            'required_scopes' => [ 'read.wp', $read ],
            'dependency'      => $dep,
            'description'     => 'List WordPress core strings with their current translations per language. Works on Polylang free and Pro.',
            'input_schema'    => [],
            'handler'         => [ $this, 'handle_strings_list' ],
        ] );

        $this->register( 'pll.string.get', [
            'class'           => 'read',
            'required_scopes' => [ 'read.wp', $read ],
            'dependency'      => $dep,
            'description'     => 'Get a WordPress core string and all its Polylang translations. Use wp_option (e.g. "blogdescription") or name+group.',
            'input_schema'    => [
                'wp_option' => [ 'type' => 'string', 'description' => 'WP option name: blogname, blogdescription, date_format, time_format.' ],
                'name'      => [ 'type' => 'string', 'description' => 'Polylang string name (alternative to wp_option).' ],
                'group'     => [ 'type' => 'string', 'description' => 'Polylang string group (required when using name).' ],
            ],
            'handler'         => [ $this, 'handle_string_get' ],
        ] );

        $this->register( 'pll.string.set', [
            'class'            => 'write',
            'required_scopes'  => [ 'write.wp.posts', $settings ],
            'supports_dry_run' => true,
            'dependency'       => $dep,
            'description'      => 'Set the Polylang translation of a string for a specific language. Works on free and Pro. '
                . 'Use wp_option for WordPress core strings. Response includes persisted/verified, re-read from the '
                . 'database (not the value just written) so a failed save is never reported as success. '
                . 'Example: {"wp_option": "blogdescription", "language": "ro", "translation": "Sit tradus"}',
            'input_schema'     => [
                'wp_option'   => [ 'type' => 'string', 'description' => 'WP option name to translate: blogname, blogdescription, date_format, time_format.' ],
                'name'        => [ 'type' => 'string', 'description' => 'Polylang string name (alternative to wp_option, requires pll_get_strings — Polylang Pro).' ],
                'group'       => [ 'type' => 'string', 'description' => 'String group (required when using name).' ],
                'language'    => [ 'type' => 'string', 'required' => true, 'description' => 'Language slug (e.g. "ro", "en", "uk").' ],
                'translation' => [ 'type' => 'string', 'required' => true, 'description' => 'Translated string value.' ],
            ],
            'handler'          => [ $this, 'handle_string_set' ],
        ] );

        $this->register( 'pll.term.link_translation', [
            'class'           => 'admin_sensitive',
            'required_scopes' => [ 'manage.taxonomies', $settings ],
            'dependency'      => $dep,
            'description'     => 'Link terms as translations (both terms must already have their language set via '
                . 'pll.term.set_language). Pass translations as {"en": 5, "ro": 8, "uk": 11}. Existing group members '
                . 'not mentioned are preserved. Requires confirm=true. Response includes persisted/verified. '
                . 'Example: {"translations": {"ro": 330, "en": 331}, "confirm": true}',
            'input_schema'    => [
                'translations' => [ 'type' => 'object', 'required' => true, 'description' => 'Map of language_slug => term_id for all translations to link.' ],
                'confirm'      => [ 'type' => 'boolean', 'required' => true, 'description' => 'Must be true to execute.' ],
            ],
            'handler'         => [ $this, 'handle_term_link_translation' ],
        ] );

        $this->register( 'pll.create_bilingual_pair', [
            'class'            => 'write',
            'required_scopes'  => [ 'write.wp.posts', $manage ],
            'dependency'       => $dep,
            'supports_dry_run' => true,
            'description'      => 'Atomic composite tool for the full translation workflow in one call. Two modes: '
                . '(1) pass source_post_id to translate an existing post — its language is read automatically via '
                . 'Polylang; or (2) omit source_post_id and pass source_language + source_post_title (+ optional '
                . 'source_post_content/excerpt/name) to create BOTH the source post and its translation from '
                . 'scratch in one call. Either way this creates the target draft, sets its language, links it to '
                . 'the source as a translation, optionally assigns a category and featured image, then re-reads '
                . 'and verifies every step. Never publishes — both posts are always created/left as drafts. If '
                . 'category_id or featured_media_id is given but the key lacks manage.taxonomies/manage.media, '
                . 'that specific step is skipped (not failed) and reported in pending_steps. '
                . 'Example (existing source): {"source_post_id": 11432, "target_language": "en", '
                . '"post_title": "...", "post_content": "...", "category_id": 330, "featured_media_id": 11433}. '
                . 'Example (from scratch): {"source_language": "ro", "source_post_title": "...", '
                . '"target_language": "en", "post_title": "..."}',
            'input_schema'     => [
                'source_post_id'      => [ 'type' => 'integer', 'description' => 'Existing post to translate from — its language is read automatically via Polylang. Omit this and use source_language + source_post_title instead to create both posts from scratch.' ],
                'source_language'     => [ 'type' => 'string',  'description' => 'Language slug for a NEW source post (e.g. "ro"). Only used, and required, when source_post_id is omitted.' ],
                'source_post_title'   => [ 'type' => 'string',  'description' => 'Title for the new source post. Required when source_post_id is omitted.' ],
                'source_post_content' => [ 'type' => 'string',  'description' => 'Content for the new source post (from-scratch mode only).' ],
                'source_post_excerpt' => [ 'type' => 'string',  'description' => 'Excerpt for the new source post (from-scratch mode only).' ],
                'source_post_name'    => [ 'type' => 'string',  'description' => 'URL slug for the new source post (from-scratch mode only).' ],
                'target_language'     => [ 'type' => 'string',  'required' => true, 'description' => 'Language slug for the target-language draft (e.g. "en", "ro").' ],
                'post_title'          => [ 'type' => 'string',  'required' => true, 'description' => 'Title for the target-language draft.' ],
                'post_content'        => [ 'type' => 'string' ],
                'post_excerpt'        => [ 'type' => 'string' ],
                'post_name'           => [ 'type' => 'string',  'description' => 'URL slug for the target-language draft.' ],
                'post_type'           => [ 'type' => 'string',  'description' => 'Post type for both posts. Defaults to the source post\'s existing type when source_post_id is given, or "post" in from-scratch mode.' ],
                'category_id'         => [ 'type' => 'integer', 'description' => 'Category term ID (in the target language) to assign to the target draft. Requires manage.taxonomies — skipped, not failed, if the key lacks it.' ],
                'featured_media_id'   => [ 'type' => 'integer', 'description' => 'Attachment ID to set as the featured image on the target draft. Requires manage.media — skipped, not failed, if the key lacks it.' ],
            ],
            'handler'          => [ $this, 'handle_create_bilingual_pair' ],
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

        // Read-after-write: Polylang can silently no-op (e.g. post type not
        // translatable) — confirm the language actually stuck.
        $persisted = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post_id ) : null;
        $verified  = $persisted === $lang;

        $data = [
            'post_id'   => $post_id,
            'language'  => $lang,
            'updated'   => $verified,
            'requested' => $lang,
            'persisted' => $persisted,
            'verified'  => $verified,
        ];
        if ( ! $verified ) {
            $data['warning'] = "Post language is '$persisted' after the write, not '$lang'.";
        }

        return $this->ok( $data, [ 'target_type' => 'post', 'target_id' => $post_id ] );
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
        $translations = $args['translations'] ?? null;

        if ( ! is_array( $translations ) || empty( $translations ) ) {
            return $this->err( 'MISSING_PARAM', 'Parameter translations must be an object mapping language slugs to post IDs.', 'validation_failed' );
        }

        // Sanitize and validate each entry
        $new_map = [];
        foreach ( $translations as $lang => $post_id ) {
            $lang    = sanitize_key( $lang );
            $post_id = (int) $post_id;
            if ( ! $lang || ! $post_id ) {
                return $this->err( 'INVALID_PARAM', "Invalid entry in translations: lang=$lang post_id=$post_id", 'validation_failed' );
            }
            $new_map[ $lang ] = $post_id;
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_link' => $new_map ] );
        }

        // Merge with existing translation groups so we never drop languages not mentioned
        $merged = $new_map;
        if ( function_exists( 'pll_get_post_translations' ) ) {
            foreach ( $new_map as $post_id ) {
                $existing = pll_get_post_translations( $post_id );
                foreach ( $existing as $existing_lang => $existing_id ) {
                    // New map takes precedence; only add languages not already specified
                    if ( ! isset( $merged[ $existing_lang ] ) ) {
                        $merged[ $existing_lang ] = $existing_id;
                    }
                }
            }
        }

        if ( function_exists( 'pll_save_post_translations' ) ) {
            pll_save_post_translations( $merged );
        }

        // Read-after-write: confirm the group actually persisted as requested —
        // Polylang can drop an entry if that post's language doesn't match the
        // slug it was linked under.
        $persisted = [];
        if ( function_exists( 'pll_get_post_translations' ) ) {
            $anchor_id = reset( $merged );
            $persisted = $anchor_id ? pll_get_post_translations( $anchor_id ) : [];
        }
        $verified = empty( array_diff_assoc( $new_map, $persisted ) );

        $data = [
            'linked'    => $verified,
            'group'     => $merged,
            'requested' => $new_map,
            'persisted' => $persisted,
            'verified'  => $verified,
        ];
        if ( ! $verified ) {
            $data['warning'] = 'Some requested translation links are not reflected after the write — check that each post is set to the matching language first.';
        }

        return $this->ok( $data, [ 'target_type' => 'pll_translation', 'target_id' => implode( '-', array_values( $merged ) ) ] );
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

        $persisted = function_exists( 'pll_get_post_translations' ) ? pll_get_post_translations( $post_id ) : [];
        $verified  = count( $persisted ) <= 1;

        $data = [
            'post_id'   => $post_id,
            'unlinked'  => $verified,
            'persisted' => $persisted,
            'verified'  => $verified,
        ];
        if ( ! $verified ) {
            $data['warning'] = 'Post is still linked to other translations after the write.';
        }

        return $this->ok( $data, [ 'target_type' => 'post', 'target_id' => $post_id ] );
    }

    // Returns a PLL_Language object by slug without relying on PLL()->model,
    // which may be null in REST API / fallback endpoint contexts.
    private function get_pll_language( string $slug ) {
        if ( ! function_exists( 'pll_languages_list' ) ) {
            return null;
        }
        foreach ( pll_languages_list( [ 'fields' => false ] ) as $lang ) {
            if ( isset( $lang->slug ) && $lang->slug === $slug ) {
                return $lang;
            }
        }
        return null;
    }

    // Known WordPress core strings that Polylang translates in both free and Pro.
    // Maps wp_option_name => [ 'name' => PLL string name, 'group' => PLL group ]
    private function wp_core_strings(): array {
        return [
            'blogname'        => [ 'name' => 'Site Title',   'group' => 'WordPress' ],
            'blogdescription' => [ 'name' => 'Site Tagline', 'group' => 'WordPress' ],
            'date_format'     => [ 'name' => 'Date Format',  'group' => 'WordPress' ],
            'time_format'     => [ 'name' => 'Time Format',  'group' => 'WordPress' ],
        ];
    }

    // Resolve the original string value from either wp_option or name+group (Pro).
    // Returns [ 'original' => string, 'label' => string ] or WP_Error string on failure.
    private function resolve_original( array $args ): array {
        $wp_option = sanitize_key( $args['wp_option'] ?? '' );

        if ( $wp_option ) {
            $map = $this->wp_core_strings();
            if ( ! isset( $map[ $wp_option ] ) ) {
                return [ 'error' => "wp_option '$wp_option' is not a supported core string. Supported: " . implode( ', ', array_keys( $map ) ) ];
            }
            $original = get_option( $wp_option );
            if ( $original === false ) {
                return [ 'error' => "WordPress option '$wp_option' not found" ];
            }
            return [ 'original' => (string) $original, 'label' => $map[ $wp_option ]['name'] . ' (' . $wp_option . ')' ];
        }

        // Fall back to pll_get_strings (Polylang Pro)
        $name  = sanitize_text_field( $args['name'] ?? '' );
        $group = sanitize_text_field( $args['group'] ?? '' );
        if ( ! $name || ! $group ) {
            return [ 'error' => 'Provide wp_option (e.g. "blogdescription") or name+group for Polylang Pro.' ];
        }
        if ( ! function_exists( 'pll_get_strings' ) ) {
            return [ 'error' => 'pll_get_strings() not available (Polylang Pro required for name+group lookup). Use wp_option instead.' ];
        }
        foreach ( pll_get_strings() as $s ) {
            if ( $s['name'] === $name && $s['group'] === $group ) {
                return [ 'original' => $s['string'], 'label' => "$group/$name" ];
            }
        }
        return [ 'error' => "String '$name' in group '$group' not found." ];
    }

    public function handle_strings_list( array $args, array $key_record, bool $dry_run ): array {
        if ( ! function_exists( 'pll_languages_list' ) ) {
            return $this->err( 'UNAVAILABLE', 'Polylang not initialised', 'error', 500 );
        }

        $lang_slugs = pll_languages_list( [ 'fields' => 'slug' ] );
        $result     = [];

        // Always include known WP core strings
        foreach ( $this->wp_core_strings() as $option => $meta ) {
            $original = get_option( $option );
            if ( $original === false ) {
                continue;
            }
            $translations = [];
            foreach ( $lang_slugs as $lang ) {
                $translations[ $lang ] = function_exists( 'pll_translate_string' )
                    ? pll_translate_string( (string) $original, $lang )
                    : '';
            }
            $result[] = [
                'wp_option'    => $option,
                'name'         => $meta['name'],
                'group'        => $meta['group'],
                'original'     => $original,
                'translations' => $translations,
            ];
        }

        // Append extra strings from Polylang Pro if available
        if ( function_exists( 'pll_get_strings' ) ) {
            $core_originals = array_map( 'get_option', array_keys( $this->wp_core_strings() ) );
            foreach ( pll_get_strings() as $s ) {
                if ( in_array( $s['string'], $core_originals, true ) ) {
                    continue; // already listed above
                }
                $translations = [];
                foreach ( $lang_slugs as $lang ) {
                    $translations[ $lang ] = pll_translate_string( $s['string'], $lang );
                }
                $result[] = [
                    'wp_option'    => null,
                    'name'         => $s['name'],
                    'group'        => $s['group'],
                    'original'     => $s['string'],
                    'translations' => $translations,
                ];
            }
        }

        return $this->ok( [ 'strings' => $result, 'total' => count( $result ) ] );
    }

    public function handle_string_get( array $args, array $key_record, bool $dry_run ): array {
        $resolved = $this->resolve_original( $args );
        if ( isset( $resolved['error'] ) ) {
            return $this->err( 'INVALID_PARAM', $resolved['error'], 'validation_failed', 400 );
        }

        $original   = $resolved['original'];
        $lang_slugs = pll_languages_list( [ 'fields' => 'slug' ] );

        $translations = [];
        foreach ( $lang_slugs as $lang ) {
            $translations[ $lang ] = function_exists( 'pll_translate_string' )
                ? pll_translate_string( $original, $lang )
                : '';
        }

        return $this->ok( [
            'label'        => $resolved['label'],
            'original'     => $original,
            'translations' => $translations,
        ] );
    }

    public function handle_string_set( array $args, array $key_record, bool $dry_run ): array {
        $language    = sanitize_key( $args['language'] ?? '' );
        $translation = $args['translation'] ?? null;

        if ( ! $language || $translation === null ) {
            return $this->err( 'MISSING_PARAM', 'Parameters language and translation are required', 'validation_failed' );
        }

        if ( ! AICOM_Policy_Engine::check_language_allowlist( $key_record, $language ) ) {
            return $this->err( 'DENIED_ALLOWLIST', "Language not in allowlist: $language", 'denied_allowlist', 403 );
        }

        $resolved = $this->resolve_original( $args );
        if ( isset( $resolved['error'] ) ) {
            return $this->err( 'INVALID_PARAM', $resolved['error'], 'validation_failed', 400 );
        }
        $original = $resolved['original'];

        if ( ! class_exists( 'PLL_MO' ) ) {
            return $this->err( 'UNAVAILABLE', 'PLL_MO class not available', 'error', 500 );
        }

        $lang_obj = $this->get_pll_language( $language );
        if ( ! $lang_obj ) {
            return $this->err( 'NOT_FOUND', "Language not found: $language", 'error', 404 );
        }

        if ( $dry_run ) {
            return $this->ok( [
                'dry_run'     => true,
                'label'       => $resolved['label'],
                'original'    => $original,
                'language'    => $language,
                'translation' => $translation,
            ] );
        }

        $mo = new PLL_MO();
        $mo->import_from_db( $lang_obj );
        $mo->add_entry( $mo->make_entry( $original, (string) $translation ) );
        $mo->export_to_db( $lang_obj );

        // Read-after-write: re-import a fresh MO from the DB (not the $mo
        // instance we just wrote, which would trivially "confirm" itself)
        // and look up what actually landed.
        $verify_mo = new PLL_MO();
        $verify_mo->import_from_db( $lang_obj );
        $persisted = $verify_mo->translate( $original );
        $verified  = $persisted === (string) $translation;

        $data = [
            'label'       => $resolved['label'],
            'original'    => $original,
            'language'    => $language,
            'translation' => $translation,
            'updated'     => $verified,
            'requested'   => (string) $translation,
            'persisted'   => $persisted,
            'verified'    => $verified,
        ];
        if ( ! $verified ) {
            $data['warning'] = 'Translation does not match what was requested after re-reading it from the database.';
        }

        return $this->ok( $data, [ 'target_type' => 'pll_string', 'target_id' => $resolved['label'] . '/' . $language ] );
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

        $persisted = function_exists( 'pll_get_term_language' ) ? pll_get_term_language( $term_id ) : null;
        $verified  = $persisted === $lang;

        $data = [
            'term_id'   => $term_id,
            'language'  => $lang,
            'updated'   => $verified,
            'requested' => $lang,
            'persisted' => $persisted,
            'verified'  => $verified,
        ];
        if ( ! $verified ) {
            $data['warning'] = "Term language is '$persisted' after the write, not '$lang'.";
        }

        return $this->ok( $data, [ 'target_type' => 'term', 'target_id' => $term_id ] );
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
        $translations = $args['translations'] ?? null;

        if ( ! is_array( $translations ) || empty( $translations ) ) {
            return $this->err( 'MISSING_PARAM', 'Parameter translations must be an object mapping language slugs to term IDs.', 'validation_failed' );
        }

        $new_map = [];
        foreach ( $translations as $lang => $term_id ) {
            $lang    = sanitize_key( $lang );
            $term_id = (int) $term_id;
            if ( ! $lang || ! $term_id ) {
                return $this->err( 'INVALID_PARAM', "Invalid entry in translations: lang=$lang term_id=$term_id", 'validation_failed' );
            }
            $new_map[ $lang ] = $term_id;
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_link' => $new_map ] );
        }

        // Merge with existing groups so we never drop languages not mentioned
        $merged = $new_map;
        if ( function_exists( 'pll_get_term_translations' ) ) {
            foreach ( $new_map as $term_id ) {
                $existing = pll_get_term_translations( $term_id );
                foreach ( $existing as $existing_lang => $existing_id ) {
                    if ( ! isset( $merged[ $existing_lang ] ) ) {
                        $merged[ $existing_lang ] = $existing_id;
                    }
                }
            }
        }

        if ( function_exists( 'pll_save_term_translations' ) ) {
            pll_save_term_translations( $merged );
        }

        $persisted = [];
        if ( function_exists( 'pll_get_term_translations' ) ) {
            $anchor_id = reset( $merged );
            $persisted = $anchor_id ? pll_get_term_translations( $anchor_id ) : [];
        }
        $verified = empty( array_diff_assoc( $new_map, $persisted ) );

        $data = [
            'linked'    => $verified,
            'group'     => $merged,
            'requested' => $new_map,
            'persisted' => $persisted,
            'verified'  => $verified,
        ];
        if ( ! $verified ) {
            $data['warning'] = 'Some requested translation links are not reflected after the write — check that each term is set to the matching language first.';
        }

        return $this->ok( $data, [ 'target_type' => 'pll_term_translation', 'target_id' => implode( '-', array_values( $merged ) ) ] );
    }

    public function handle_create_bilingual_pair( array $args, array $key_record, bool $dry_run ): array {
        $source_post_id   = $this->require_int( $args, 'source_post_id' );
        $source_language  = sanitize_key( $args['source_language'] ?? '' );
        $source_title     = $this->require_string( $args, 'source_post_title' );
        $target_language  = sanitize_key( $args['target_language'] ?? '' );
        $title            = $this->require_string( $args, 'post_title' );
        $from_scratch      = ! $source_post_id;

        if ( ! $target_language || ! $title ) {
            return $this->err( 'MISSING_PARAM', 'Parameters target_language and post_title are required', 'validation_failed' );
        }
        if ( $from_scratch && ( ! $source_language || ! $source_title ) ) {
            return $this->err( 'MISSING_PARAM', 'source_post_id was omitted — provide source_language and source_post_title to create the source post from scratch instead', 'validation_failed' );
        }

        if ( ! function_exists( 'pll_get_post_language' ) || ! function_exists( 'pll_set_post_language' ) || ! function_exists( 'pll_save_post_translations' ) ) {
            return $this->err( 'UNAVAILABLE', 'Required Polylang functions not available', 'error', 500 );
        }

        $post_type = sanitize_key( $args['post_type'] ?? '' );
        $source_post = null;

        if ( ! $from_scratch ) {
            $source_post = get_post( $source_post_id );
            if ( ! $source_post ) {
                return $this->err( 'NOT_FOUND', "Source post $source_post_id not found", 'error', 404 );
            }
            $source_language = pll_get_post_language( $source_post_id );
            if ( ! $source_language ) {
                return $this->err( 'INVALID_PARAM', "Source post $source_post_id has no Polylang language set — set one with pll.post.set_language first", 'validation_failed' );
            }
            if ( ! $post_type ) {
                $post_type = $source_post->post_type;
            }
        } elseif ( ! $post_type ) {
            $post_type = 'post';
        }

        if ( $source_language === $target_language ) {
            return $this->err( 'INVALID_PARAM', "target_language ($target_language) is the same as the source language ($source_language)", 'validation_failed' );
        }
        // Only check the source language's allowlist in from-scratch mode — that's the
        // only case this tool actually SETS it. For an existing source post, the language
        // was already established by a prior (separately-authorized) action; this tool is
        // just reading and referencing it, not assigning it, so it shouldn't be re-gated here.
        if ( $from_scratch && ! AICOM_Policy_Engine::check_language_allowlist( $key_record, $source_language ) ) {
            return $this->err( 'DENIED_ALLOWLIST', "Language not in allowlist: $source_language", 'denied_allowlist', 403 );
        }
        if ( ! AICOM_Policy_Engine::check_language_allowlist( $key_record, $target_language ) ) {
            return $this->err( 'DENIED_ALLOWLIST', "Language not in allowlist: $target_language", 'denied_allowlist', 403 );
        }

        if ( $dry_run ) {
            return $this->ok( [
                'dry_run'      => true,
                'would_create' => [
                    'mode'            => $from_scratch ? 'from_scratch' : 'from_existing_source',
                    'source_post_id'  => $source_post_id,
                    'source_language' => $source_language,
                    'target_language' => $target_language,
                    'post_type'       => $post_type,
                    'post_title'      => $title,
                ],
            ] );
        }

        $completed_steps = [];
        $pending_steps   = [];

        // Step 0 (from-scratch mode only): create the source post itself first.
        if ( $from_scratch ) {
            $source_data = [
                'post_title'   => sanitize_text_field( $source_title ),
                'post_content' => wp_kses_post( $args['source_post_content'] ?? '' ),
                'post_status'  => 'draft',
                'post_type'    => $post_type,
                'post_author'  => (int) ( $key_record['created_by_user_id'] ?? 0 ),
            ];
            if ( isset( $args['source_post_excerpt'] ) ) {
                $source_data['post_excerpt'] = sanitize_text_field( $args['source_post_excerpt'] );
            }
            if ( isset( $args['source_post_name'] ) ) {
                $source_data['post_name'] = sanitize_title( $args['source_post_name'] );
            }

            $source_post_id = wp_insert_post( $source_data, true );
            if ( is_wp_error( $source_post_id ) ) {
                return $this->err( 'WP_ERROR', 'Failed to create source post: ' . $source_post_id->get_error_message(), 'error', 500 );
            }
            $completed_steps[] = 'created_source_draft';

            pll_set_post_language( $source_post_id, $source_language );
            if ( pll_get_post_language( $source_post_id ) === $source_language ) {
                $completed_steps[] = 'set_source_language';
            } else {
                $pending_steps[] = [ 'step' => 'set_source_language', 'reason' => "Source language did not persist as $source_language" ];
            }
        }

        // Step 1: create the target-language draft — always draft, never publishes.
        $post_data = [
            'post_title'   => sanitize_text_field( $title ),
            'post_content' => wp_kses_post( $args['post_content'] ?? '' ),
            'post_status'  => 'draft',
            'post_type'    => $post_type,
            'post_author'  => (int) ( $key_record['created_by_user_id'] ?? 0 ),
        ];
        if ( isset( $args['post_excerpt'] ) ) {
            $post_data['post_excerpt'] = sanitize_text_field( $args['post_excerpt'] );
        }
        if ( isset( $args['post_name'] ) ) {
            $post_data['post_name'] = sanitize_title( $args['post_name'] );
        }

        $new_post_id = wp_insert_post( $post_data, true );
        if ( is_wp_error( $new_post_id ) ) {
            return $this->err( 'WP_ERROR', $new_post_id->get_error_message(), 'error', 500 );
        }
        $completed_steps[] = 'created_draft';

        // Step 2: set language
        pll_set_post_language( $new_post_id, $target_language );
        $persisted_language = pll_get_post_language( $new_post_id );
        if ( $persisted_language === $target_language ) {
            $completed_steps[] = 'set_language';
        } else {
            $pending_steps[] = [ 'step' => 'set_language', 'reason' => "Language did not persist as $target_language (got " . ( $persisted_language ?: 'none' ) . ')' ];
        }

        // Step 3: link translation — preserve any other languages already in the source's group.
        $translations = [ $source_language => $source_post_id, $target_language => $new_post_id ];
        if ( function_exists( 'pll_get_post_translations' ) ) {
            foreach ( pll_get_post_translations( $source_post_id ) as $lang => $pid ) {
                if ( ! isset( $translations[ $lang ] ) ) {
                    $translations[ $lang ] = $pid;
                }
            }
        }
        pll_save_post_translations( $translations );
        $persisted_translations = function_exists( 'pll_get_post_translations' ) ? pll_get_post_translations( $new_post_id ) : [];
        if ( ( $persisted_translations[ $source_language ] ?? null ) === $source_post_id ) {
            $completed_steps[] = 'linked_translation';
        } else {
            $pending_steps[] = [ 'step' => 'linked_translation', 'reason' => 'Translation link not confirmed after write' ];
        }

        // Step 4: assign category — optional, gracefully skipped (not failed) without manage.taxonomies.
        $category_result = null;
        if ( isset( $args['category_id'] ) ) {
            $category_id = (int) $args['category_id'];
            if ( ! empty( AICOM_Auth::missing_scopes( $key_record, [ 'manage.taxonomies' ] ) ) ) {
                $pending_steps[] = [ 'step' => 'assigned_category', 'reason' => 'INSUFFICIENT_SCOPE: manage.taxonomies not granted to this key' ];
            } else {
                wp_set_post_terms( $new_post_id, [ $category_id ], 'category', false );
                $persisted_terms  = wp_get_post_terms( $new_post_id, 'category', [ 'fields' => 'ids' ] );
                $persisted_terms  = is_wp_error( $persisted_terms ) ? [] : array_map( 'intval', $persisted_terms );
                $category_result  = [ 'requested' => $category_id, 'persisted' => $persisted_terms ];
                if ( in_array( $category_id, $persisted_terms, true ) ) {
                    $completed_steps[] = 'assigned_category';
                } else {
                    $pending_steps[] = [ 'step' => 'assigned_category', 'reason' => "Category $category_id not present after write — check the taxonomy allowlist or a Polylang category-language mapping" ];
                }
            }
        }

        // Step 5: featured image — optional, gracefully skipped (not failed) without manage.media.
        $featured_result = null;
        if ( isset( $args['featured_media_id'] ) ) {
            $media_id = (int) $args['featured_media_id'];
            if ( ! empty( AICOM_Auth::missing_scopes( $key_record, [ 'manage.media' ] ) ) ) {
                $pending_steps[] = [ 'step' => 'set_featured_image', 'reason' => 'INSUFFICIENT_SCOPE: manage.media not granted to this key' ];
            } else {
                set_post_thumbnail( $new_post_id, $media_id );
                $persisted_thumb = (int) get_post_thumbnail_id( $new_post_id );
                $featured_result = [ 'requested' => $media_id, 'persisted' => $persisted_thumb ];
                if ( $persisted_thumb === $media_id ) {
                    $completed_steps[] = 'set_featured_image';
                } else {
                    $pending_steps[] = [ 'step' => 'set_featured_image', 'reason' => "Featured image is $persisted_thumb after the write, not $media_id" ];
                }
            }
        }

        // Step 6: verify — always runs, reflects the true final state of everything above.
        $final_post = get_post( $new_post_id );
        $completed_steps[] = 'verified';

        $result = [
            'success'         => true,
            'partial'         => ! empty( $pending_steps ),
            'mode'            => $from_scratch ? 'from_scratch' : 'from_existing_source',
            'post_id'         => $new_post_id,
            'source_post_id'  => $source_post_id,
            'source_language' => $source_language,
            'target_language' => $target_language,
            'post_type'       => $post_type,
            'status'          => $final_post->post_status,
            'edit_url'        => get_edit_post_link( $new_post_id, 'raw' ),
            'view_url'        => get_permalink( $new_post_id ),
            'translations'    => $persisted_translations,
            'completed_steps' => $completed_steps,
            'pending_steps'   => $pending_steps,
            'verified'        => empty( $pending_steps ),
        ];
        if ( $from_scratch ) {
            $result['source_edit_url'] = get_edit_post_link( $source_post_id, 'raw' );
            $result['source_view_url'] = get_permalink( $source_post_id );
        }
        if ( $category_result !== null ) {
            $result['category'] = $category_result;
        }
        if ( $featured_result !== null ) {
            $result['featured_image'] = $featured_result;
        }

        return $this->ok(
            $result,
            [ 'target_type' => 'post', 'target_id' => $new_post_id, 'summary' => [ 'created_bilingual_pair' => true ] ]
        );
    }
}
