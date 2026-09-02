<?php
/**
 * WPML module for AICOM.
 *
 * Exposes WPML (Multilingual CMS) read/write operations to AI agents.
 * Requires WPML core (sitepress-multilingual-cms) to be active, with at
 * least a second language enabled through its setup wizard.
 *
 * Verified directly against WPML's own source and its officially documented
 * integration hooks (apply_filters/do_action on 'wpml_*'), not assumed from
 * Polylang's conventions — WPML's translation model is fundamentally
 * different (a shared "trid" translation-group ID per element, stored in
 * wp_icl_translations, rather than Polylang's per-post language taxonomy):
 *   - wpml_active_languages / wpml_default_language — site language list
 *   - wpml_element_language_details (get) / wpml_set_element_language_details
 *     (set) — read/write a post or term's language + trid
 *   - wpml_object_id — resolve the translated ID of an element in a given language
 * One exception: "get all elements in a translation group" uses a direct
 * read of wp_icl_translations by trid instead of the wpml_get_element_translations
 * filter — that filter returned stale/empty results in testing even in a
 * freshly-verified-correct group (ground-truthed against the DB row by row),
 * while WPML's own code falls back to the identical raw query internally
 * when its object cache is unavailable, so this mirrors an already-sanctioned
 * WPML code path rather than reaching around the API on a guess.
 *
 * Known gap (deliberately out of scope for this version): WPML String
 * Translation (translating hardcoded site strings via icl_register_string /
 * icl_add_string_translation) requires the WPML_String_Translation global to
 * be initialized, which did not happen reliably outside a full wp-admin
 * page load in testing — not yet resolved, so no wpml.strings.* tools exist
 * yet. Post and term translation (the core workflow) are unaffected.
 *
 * Registered tools (11):
 *   wpml.status                — plugin version, String Translation add-on presence
 *   wpml.languages.list        — active site languages
 *   wpml.post.get_language     — language + trid for a post
 *   wpml.post.set_language     — set a post's language (starts a new translation group)
 *   wpml.post.get_translations — all posts in the same translation group
 *   wpml.post.link_translation — link posts into one translation group
 *   wpml.post.unlink_translation — remove a post from its translation group
 *   wpml.term.get_language     — language + trid for a taxonomy term
 *   wpml.term.set_language     — set a term's language (starts a new translation group)
 *   wpml.term.get_translations — all terms in the same translation group
 *   wpml.term.link_translation — link terms into one translation group
 */
class AICOM_Module_WPML extends AICOM_Module_Base {

    public function get_module_name(): string {
        return 'wpml';
    }

    public function register_tools(): void {
        $dep    = 'wpml';
        $read   = 'read.wpml';
        $manage = 'manage.wpml';

        $this->register( 'wpml.status', [
            'class'           => 'read',
            'dependency'      => $dep,
            'required_scopes' => [ 'read.wp' ],
            'description'     => 'WPML plugin status: version, and whether the String Translation add-on is active.',
            'input_schema'    => [],
            'handler'         => [ $this, 'handle_status' ],
        ] );

        $this->register( 'wpml.languages.list', [
            'class'           => 'discovery',
            'dependency'      => $dep,
            'required_scopes' => [ 'read.wp', $read ],
            'description'     => 'List all active site languages configured in WPML.',
            'input_schema'    => [],
            'handler'         => [ $this, 'handle_languages_list' ],
        ] );

        $this->register( 'wpml.post.get_language', [
            'class'           => 'read',
            'dependency'      => $dep,
            'required_scopes' => [ 'read.wp', $read ],
            'description'     => 'Get the language and translation-group ID (trid) of a post.',
            'input_schema'    => [
                'post_id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_post_get_language' ],
        ] );

        $this->register( 'wpml.post.set_language', [
            'class'           => 'write',
            'required_scopes' => [ 'write.wp.posts', $manage ],
            'dependency'      => $dep,
            'description'     => 'Set the language of a post. Step 2 of the full translation workflow: '
                . '(1) create the translated post as a draft with wp.posts.create, '
                . '(2) set its language with this tool, '
                . '(3) link it to the source post with wpml.post.link_translation, '
                . '(4) verify with wp.posts.get and wpml.post.get_translations. '
                . 'Starts a brand-new translation group for this post — use wpml.post.link_translation '
                . 'afterwards to join it to an existing source post\'s group, not this tool. '
                . 'Response includes persisted/verified so you can confirm the language actually stuck. '
                . 'Example: {"post_id": 456, "language": "en"}',
            'input_schema'    => [
                'post_id'  => [ 'type' => 'integer', 'required' => true ],
                'language' => [ 'type' => 'string',  'required' => true, 'description' => 'Language code (e.g. "en", "ro") — must be one of the codes from wpml.languages.list.' ],
            ],
            'handler'         => [ $this, 'handle_post_set_language' ],
        ] );

        $this->register( 'wpml.post.get_translations', [
            'class'           => 'read',
            'required_scopes' => [ 'read.wp', $read ],
            'dependency'      => $dep,
            'description'     => 'Get all posts in the same translation group as this post, keyed by language code (including the post itself).',
            'input_schema'    => [
                'post_id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_post_get_translations' ],
        ] );

        $this->register( 'wpml.post.link_translation', [
            'class'           => 'admin_sensitive',
            'required_scopes' => [ 'write.wp.posts', $manage ],
            'dependency'      => $dep,
            'description'     => 'Link posts together as translations of each other — step 3 of the translation '
                . 'workflow (every post must already have its language set via wpml.post.set_language). Pass '
                . 'translations as {"en": 123, "ro": 456}. If any of the given posts already belongs to a '
                . 'translation group, the others join THAT group (existing members not mentioned here are '
                . 'preserved); otherwise a new group is created. Requires confirm=true. Response includes '
                . 'persisted/verified re-read from the group. Example: {"translations": {"en": 11435, "ro": 11432}, "confirm": true}',
            'input_schema'    => [
                'translations' => [ 'type' => 'object', 'required' => true, 'description' => 'Map of language_code => post_id for all translations to link.' ],
                'confirm'      => [ 'type' => 'boolean', 'required' => true, 'description' => 'Must be true to execute.' ],
            ],
            'handler'         => [ $this, 'handle_post_link_translation' ],
        ] );

        $this->register( 'wpml.post.unlink_translation', [
            'class'           => 'admin_sensitive',
            'required_scopes' => [ 'write.wp.posts', $manage ],
            'dependency'      => $dep,
            'description'     => 'Remove a post from its translation group (confirm=true required). The post '
                . 'keeps its own language but is moved into a fresh, empty group of its own. '
                . 'Example: {"post_id": 456, "confirm": true}',
            'input_schema'    => [
                'post_id' => [ 'type' => 'integer', 'required' => true ],
                'confirm' => [ 'type' => 'boolean', 'required' => true, 'description' => 'Must be true to execute.' ],
            ],
            'handler'         => [ $this, 'handle_post_unlink_translation' ],
        ] );

        $this->register( 'wpml.term.get_language', [
            'class'           => 'read',
            'dependency'      => $dep,
            'required_scopes' => [ 'read.wp', $read ],
            'description'     => 'Get the language and translation-group ID (trid) of a taxonomy term.',
            'input_schema'    => [
                'term_id'  => [ 'type' => 'integer', 'required' => true ],
                'taxonomy' => [ 'type' => 'string',  'required' => true, 'description' => 'Taxonomy slug, e.g. "category". Required — WPML terms are identified by term_id + taxonomy, not term_id alone.' ],
            ],
            'handler'         => [ $this, 'handle_term_get_language' ],
        ] );

        $this->register( 'wpml.term.set_language', [
            'class'           => 'write',
            'required_scopes' => [ 'manage.taxonomies', $manage ],
            'dependency'      => $dep,
            'description'     => 'Set the language of a taxonomy term (category/tag). Analogous to '
                . 'wpml.post.set_language but for terms — starts a new translation group. '
                . 'Example: {"term_id": 330, "taxonomy": "category", "language": "en"}',
            'input_schema'    => [
                'term_id'  => [ 'type' => 'integer', 'required' => true ],
                'taxonomy' => [ 'type' => 'string',  'required' => true ],
                'language' => [ 'type' => 'string',  'required' => true, 'description' => 'Language code (e.g. "en", "ro").' ],
            ],
            'handler'         => [ $this, 'handle_term_set_language' ],
        ] );

        $this->register( 'wpml.term.get_translations', [
            'class'           => 'read',
            'required_scopes' => [ 'read.wp', $read ],
            'dependency'      => $dep,
            'description'     => 'Get all terms in the same translation group as this term, keyed by language code (including the term itself).',
            'input_schema'    => [
                'term_id'  => [ 'type' => 'integer', 'required' => true ],
                'taxonomy' => [ 'type' => 'string',  'required' => true ],
            ],
            'handler'         => [ $this, 'handle_term_get_translations' ],
        ] );

        $this->register( 'wpml.term.link_translation', [
            'class'           => 'admin_sensitive',
            'required_scopes' => [ 'manage.taxonomies', $manage ],
            'dependency'      => $dep,
            'description'     => 'Link taxonomy terms together as translations of each other. Pass translations '
                . 'as {"en": 12, "ro": 34} — all terms must be in the SAME taxonomy and already have their '
                . 'language set via wpml.term.set_language. Requires confirm=true. '
                . 'Example: {"taxonomy": "category", "translations": {"en": 12, "ro": 34}, "confirm": true}',
            'input_schema'    => [
                'taxonomy'     => [ 'type' => 'string', 'required' => true ],
                'translations' => [ 'type' => 'object', 'required' => true, 'description' => 'Map of language_code => term_id for all translations to link.' ],
                'confirm'      => [ 'type' => 'boolean', 'required' => true, 'description' => 'Must be true to execute.' ],
            ],
            'handler'         => [ $this, 'handle_term_link_translation' ],
        ] );
    }

    // ── Handlers ─────────────────────────────────────────────────────────────

    public function handle_status( array $args, array $key_record, bool $dry_run ): array {
        return $this->ok( [
            'active'                    => true,
            'version'                   => defined( 'ICL_SITEPRESS_VERSION' ) ? ICL_SITEPRESS_VERSION : null,
            'string_translation_active' => defined( 'WPML_ST_VERSION' ),
        ] );
    }

    public function handle_languages_list( array $args, array $key_record, bool $dry_run ): array {
        $languages = apply_filters( 'wpml_active_languages', null, [] );
        $default   = apply_filters( 'wpml_default_language', null );
        $result    = [];

        foreach ( (array) $languages as $code => $lang ) {
            $result[] = [
                'code'            => $code,
                'native_name'     => $lang['native_name'] ?? $code,
                'translated_name' => $lang['translated_name'] ?? $code,
                'default'         => $code === $default,
            ];
        }

        return $this->ok( [ 'languages' => $result ] );
    }

    public function handle_post_get_language( array $args, array $key_record, bool $dry_run ): array {
        $post_id = $this->require_int( $args, 'post_id' );
        if ( ! $post_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter post_id is required', 'validation_failed' );
        }

        $details = $this->get_element_language_details( $post_id, get_post_type( $post_id ) ?: 'post' );

        return $this->ok( [
            'post_id'  => $post_id,
            'language' => $details->language_code ?? null,
            'trid'     => $details ? (int) $details->trid : null,
        ] );
    }

    public function handle_post_set_language( array $args, array $key_record, bool $dry_run ): array {
        $post_id = $this->require_int( $args, 'post_id' );
        $lang    = sanitize_key( $args['language'] ?? '' );

        if ( ! $post_id || ! $lang ) {
            return $this->err( 'MISSING_PARAM', 'Parameters post_id and language are required', 'validation_failed' );
        }
        if ( ! get_post( $post_id ) ) {
            return $this->err( 'NOT_FOUND', "Post $post_id not found", 'error', 404 );
        }
        if ( ! AICOM_Policy_Engine::check_language_allowlist( $key_record, $lang ) ) {
            return $this->err( 'DENIED_ALLOWLIST', "Language not in allowlist: $lang", 'denied_allowlist', 403 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_set_language' => $lang ] );
        }

        $post_type = get_post_type( $post_id ) ?: 'post';
        $this->set_element_language_details( $post_id, 'post_' . $post_type, null, $lang );

        $details   = $this->get_element_language_details( $post_id, $post_type );
        $persisted = $details->language_code ?? null;
        $verified  = $persisted === $lang;

        $data = [
            'post_id'   => $post_id,
            'language'  => $lang,
            'trid'      => $details ? (int) $details->trid : null,
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

        $post_type = get_post_type( $post_id ) ?: 'post';
        $details   = $this->get_element_language_details( $post_id, $post_type );
        if ( ! $details ) {
            return $this->ok( [ 'post_id' => $post_id, 'trid' => null, 'translations' => new stdClass() ] );
        }

        $group = $this->get_translation_group( (int) $details->trid, 'post_' . $post_type );

        return $this->ok( [
            'post_id'      => $post_id,
            'trid'         => (int) $details->trid,
            'translations' => AICOM_Json::obj( $group ),
        ] );
    }

    public function handle_post_link_translation( array $args, array $key_record, bool $dry_run ): array {
        $translations = $args['translations'] ?? null;
        if ( ! is_array( $translations ) || empty( $translations ) ) {
            return $this->err( 'MISSING_PARAM', 'Parameter translations must be an object mapping language codes to post IDs.', 'validation_failed' );
        }

        $map = [];
        foreach ( $translations as $lang => $post_id ) {
            $lang    = sanitize_key( $lang );
            $post_id = (int) $post_id;
            if ( ! $lang || ! $post_id ) {
                return $this->err( 'INVALID_PARAM', "Invalid entry in translations: lang=$lang post_id=$post_id", 'validation_failed' );
            }
            if ( ! get_post( $post_id ) ) {
                return $this->err( 'NOT_FOUND', "Post $post_id not found", 'error', 404 );
            }
            $map[ $lang ] = $post_id;
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_link' => $map ] );
        }

        // Reuse an existing group if any given post already has one; else a
        // fresh group is created off the first post processed.
        $trid = null;
        foreach ( $map as $post_id ) {
            $details = $this->get_element_language_details( $post_id, get_post_type( $post_id ) ?: 'post' );
            if ( $details && (int) $details->trid > 0 ) {
                $trid = (int) $details->trid;
                break;
            }
        }

        $first_id = null;
        foreach ( $map as $lang => $post_id ) {
            $post_type = get_post_type( $post_id ) ?: 'post';
            $this->set_element_language_details( $post_id, 'post_' . $post_type, $trid, $lang );
            if ( $trid === null ) {
                // First element with no pre-existing group: read back the
                // trid WPML just generated so every subsequent element joins it.
                $details = $this->get_element_language_details( $post_id, $post_type );
                $trid    = $details ? (int) $details->trid : null;
            }
        }

        $sample_post_type = get_post_type( reset( $map ) ) ?: 'post';
        $persisted         = $trid ? $this->get_translation_group( $trid, 'post_' . $sample_post_type ) : [];
        $verified          = $trid !== null;
        foreach ( $map as $lang => $post_id ) {
            if ( ( $persisted[ $lang ] ?? null ) !== $post_id ) {
                $verified = false;
            }
        }

        $data = [
            'trid'      => $trid,
            'requested' => $map,
            'persisted' => AICOM_Json::obj( $persisted ),
            'verified'  => $verified,
        ];
        if ( ! $verified ) {
            $data['warning'] = 'Translation group does not exactly match what was requested after the write.';
        }

        return $this->ok( $data );
    }

    public function handle_post_unlink_translation( array $args, array $key_record, bool $dry_run ): array {
        $post_id = $this->require_int( $args, 'post_id' );
        if ( ! $post_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter post_id is required', 'validation_failed' );
        }
        if ( ! get_post( $post_id ) ) {
            return $this->err( 'NOT_FOUND', "Post $post_id not found", 'error', 404 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_unlink_post_id' => $post_id ] );
        }

        $post_type = get_post_type( $post_id ) ?: 'post';
        $before    = $this->get_element_language_details( $post_id, $post_type );
        $lang      = $before->language_code ?? null;

        if ( $lang ) {
            // trid=null re-homes this element into a brand-new, empty group —
            // confirmed against the raw table: the old group's other members
            // are untouched, only this element's own row's trid changes.
            $this->set_element_language_details( $post_id, 'post_' . $post_type, null, $lang );
        }

        $after       = $this->get_element_language_details( $post_id, $post_type );
        $new_group   = $after ? $this->get_translation_group( (int) $after->trid, 'post_' . $post_type ) : [];
        $verified    = count( $new_group ) <= 1;

        $data = [
            'post_id'   => $post_id,
            'unlinked'  => $verified,
            'persisted' => AICOM_Json::obj( $new_group ),
            'verified'  => $verified,
        ];
        if ( ! $verified ) {
            $data['warning'] = 'Post is still linked to other translations after the write.';
        }

        return $this->ok( $data, [ 'target_type' => 'post', 'target_id' => $post_id ] );
    }

    public function handle_term_get_language( array $args, array $key_record, bool $dry_run ): array {
        $term_id  = $this->require_int( $args, 'term_id' );
        $taxonomy = sanitize_key( $args['taxonomy'] ?? '' );
        if ( ! $term_id || ! $taxonomy ) {
            return $this->err( 'MISSING_PARAM', 'Parameters term_id and taxonomy are required', 'validation_failed' );
        }

        $details = $this->get_element_language_details( $term_id, $taxonomy );

        return $this->ok( [
            'term_id'  => $term_id,
            'taxonomy' => $taxonomy,
            'language' => $details->language_code ?? null,
            'trid'     => $details ? (int) $details->trid : null,
        ] );
    }

    public function handle_term_set_language( array $args, array $key_record, bool $dry_run ): array {
        $term_id  = $this->require_int( $args, 'term_id' );
        $taxonomy = sanitize_key( $args['taxonomy'] ?? '' );
        $lang     = sanitize_key( $args['language'] ?? '' );

        if ( ! $term_id || ! $taxonomy || ! $lang ) {
            return $this->err( 'MISSING_PARAM', 'Parameters term_id, taxonomy and language are required', 'validation_failed' );
        }
        $term = get_term( $term_id, $taxonomy );
        if ( is_wp_error( $term ) || ! $term ) {
            return $this->err( 'NOT_FOUND', "Term $term_id not found in $taxonomy", 'error', 404 );
        }
        if ( ! AICOM_Policy_Engine::check_language_allowlist( $key_record, $lang ) ) {
            return $this->err( 'DENIED_ALLOWLIST', "Language not in allowlist: $lang", 'denied_allowlist', 403 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_set_language' => $lang ] );
        }

        $this->set_element_language_details( $term_id, 'tax_' . $taxonomy, null, $lang );

        $details   = $this->get_element_language_details( $term_id, $taxonomy );
        $persisted = $details->language_code ?? null;
        $verified  = $persisted === $lang;

        $data = [
            'term_id'   => $term_id,
            'taxonomy'  => $taxonomy,
            'language'  => $lang,
            'trid'      => $details ? (int) $details->trid : null,
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
        $term_id  = $this->require_int( $args, 'term_id' );
        $taxonomy = sanitize_key( $args['taxonomy'] ?? '' );
        if ( ! $term_id || ! $taxonomy ) {
            return $this->err( 'MISSING_PARAM', 'Parameters term_id and taxonomy are required', 'validation_failed' );
        }

        $details = $this->get_element_language_details( $term_id, $taxonomy );
        if ( ! $details ) {
            return $this->ok( [ 'term_id' => $term_id, 'taxonomy' => $taxonomy, 'trid' => null, 'translations' => new stdClass() ] );
        }

        $group = $this->get_translation_group( (int) $details->trid, 'tax_' . $taxonomy );

        return $this->ok( [
            'term_id'      => $term_id,
            'taxonomy'     => $taxonomy,
            'trid'         => (int) $details->trid,
            'translations' => AICOM_Json::obj( $group ),
        ] );
    }

    public function handle_term_link_translation( array $args, array $key_record, bool $dry_run ): array {
        $taxonomy     = sanitize_key( $args['taxonomy'] ?? '' );
        $translations = $args['translations'] ?? null;

        if ( ! $taxonomy ) {
            return $this->err( 'MISSING_PARAM', 'Parameter taxonomy is required', 'validation_failed' );
        }
        if ( ! is_array( $translations ) || empty( $translations ) ) {
            return $this->err( 'MISSING_PARAM', 'Parameter translations must be an object mapping language codes to term IDs.', 'validation_failed' );
        }

        $map = [];
        foreach ( $translations as $lang => $term_id ) {
            $lang    = sanitize_key( $lang );
            $term_id = (int) $term_id;
            if ( ! $lang || ! $term_id ) {
                return $this->err( 'INVALID_PARAM', "Invalid entry in translations: lang=$lang term_id=$term_id", 'validation_failed' );
            }
            $term = get_term( $term_id, $taxonomy );
            if ( is_wp_error( $term ) || ! $term ) {
                return $this->err( 'NOT_FOUND', "Term $term_id not found in $taxonomy", 'error', 404 );
            }
            $map[ $lang ] = $term_id;
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_link' => $map ] );
        }

        $trid = null;
        foreach ( $map as $term_id ) {
            $details = $this->get_element_language_details( $term_id, $taxonomy );
            if ( $details && (int) $details->trid > 0 ) {
                $trid = (int) $details->trid;
                break;
            }
        }

        foreach ( $map as $lang => $term_id ) {
            $this->set_element_language_details( $term_id, 'tax_' . $taxonomy, $trid, $lang );
            if ( $trid === null ) {
                $details = $this->get_element_language_details( $term_id, $taxonomy );
                $trid    = $details ? (int) $details->trid : null;
            }
        }

        $persisted = $trid ? $this->get_translation_group( $trid, 'tax_' . $taxonomy ) : [];
        $verified  = $trid !== null;
        foreach ( $map as $lang => $term_id ) {
            if ( ( $persisted[ $lang ] ?? null ) !== $term_id ) {
                $verified = false;
            }
        }

        $data = [
            'taxonomy'  => $taxonomy,
            'trid'      => $trid,
            'requested' => $map,
            'persisted' => AICOM_Json::obj( $persisted ),
            'verified'  => $verified,
        ];
        if ( ! $verified ) {
            $data['warning'] = 'Translation group does not exactly match what was requested after the write.';
        }

        return $this->ok( $data );
    }

    // ── WPML API helpers ─────────────────────────────────────────────────────
    //
    // $wp_type below is the plain WP type ('post', 'page', 'category', ...) —
    // wpml_element_language_details self-normalizes it via the wpml_element_type
    // filter. $full_type is WPML's own fully-qualified form ('post_post',
    // 'tax_category', ...), required as-is for wpml_set_element_language_details,
    // which does NOT normalize its input.

    private function get_element_language_details( int $element_id, string $wp_type ): ?object {
        $details = apply_filters( 'wpml_element_language_details', null, [
            'element_id'   => $element_id,
            'element_type' => $wp_type,
        ] );
        return is_object( $details ) ? $details : null;
    }

    private function set_element_language_details( int $element_id, string $full_type, ?int $trid, string $lang, ?string $source_lang = null ): void {
        do_action( 'wpml_set_element_language_details', [
            'element_id'           => $element_id,
            'element_type'         => $full_type,
            'trid'                 => $trid,
            'language_code'        => $lang,
            'source_language_code' => $source_lang,
        ] );
    }

    // Direct read of wp_icl_translations by trid — see the class docblock for
    // why this doesn't use the wpml_get_element_translations filter.
    private function get_translation_group( int $trid, string $full_type ): array {
        if ( ! $trid ) {
            return [];
        }
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT element_id, language_code FROM {$wpdb->prefix}icl_translations WHERE trid = %d AND element_type = %s",
                $trid,
                $full_type
            ),
            ARRAY_A
        );
        $group = [];
        foreach ( $rows as $row ) {
            $group[ $row['language_code'] ] = (int) $row['element_id'];
        }
        return $group;
    }
}
