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
}
