<?php
defined( 'ABSPATH' ) || exit;

class AICOM_Module_Skills extends AICOM_Module_Base {

    public function get_module_name(): string { return 'skills'; }

    public function register_tools(): void {

        // ── Discovery ──────────────────────────────────────────────────────

        $this->register( 'skills.list', [
            'class'           => 'read',
            'required_scopes' => [ 'read.skills' ],
            'description'     => 'List saved Skills with optional filters. Returns id, slug, name, type, status, version.',
            'input_schema'    => [
                'status' => [ 'type' => 'string', 'description' => 'Filter by status: suggested|draft|active|archived|update_proposal' ],
                'type'   => [ 'type' => 'string', 'description' => 'Filter by type: simple|guided|advanced' ],
                'search' => [ 'type' => 'string', 'description' => 'Full-text search on name, description, slug' ],
                'limit'  => [ 'type' => 'integer', 'default' => 20 ],
                'offset' => [ 'type' => 'integer', 'default' => 0 ],
            ],
            'handler' => [ $this, 'handle_list' ],
        ] );

        $this->register( 'skills.get', [
            'class'           => 'read',
            'required_scopes' => [ 'read.skills' ],
            'description'     => 'Get a single Skill in full (including steps, rules, input_schema). Pass id or slug.',
            'input_schema'    => [
                'id'   => [ 'type' => 'integer', 'description' => 'Skill ID' ],
                'slug' => [ 'type' => 'string',  'description' => 'Skill slug' ],
            ],
            'handler' => [ $this, 'handle_get' ],
        ] );

        $this->register( 'skills.match', [
            'class'           => 'read',
            'required_scopes' => [ 'read.skills' ],
            'description'     => 'Find similar Skills for a given intent/context. Use before creating a new skill to avoid duplicates. Returns candidates with confidence scores and match_type: use_existing (>=0.85) / clarify (0.60-0.84) / create_new (<0.60).',
            'input_schema'    => [
                'name'        => [ 'type' => 'string',  'description' => 'Proposed skill name or intent description', 'required' => true ],
                'description' => [ 'type' => 'string',  'description' => 'Proposed skill description' ],
                'type'        => [ 'type' => 'string',  'description' => 'Proposed type: simple|guided|advanced' ],
                'tags'        => [ 'type' => 'array',   'description' => 'Tags array' ],
                'steps'       => [ 'type' => 'array',   'description' => 'Steps array with action/tool fields' ],
            ],
            'handler' => [ $this, 'handle_match' ],
        ] );

        $this->register( 'skills.compare', [
            'class'           => 'read',
            'required_scopes' => [ 'read.skills' ],
            'description'     => 'Compare two Skills or a Skill vs its update_proposal. Returns a diff of steps, rules, and name/description changes.',
            'input_schema'    => [
                'id_a' => [ 'type' => 'integer', 'required' => true, 'description' => 'First skill ID (original)' ],
                'id_b' => [ 'type' => 'integer', 'required' => true, 'description' => 'Second skill ID (proposal or new version)' ],
            ],
            'handler' => [ $this, 'handle_compare' ],
        ] );

        // ── Execution ──────────────────────────────────────────────────────

        $this->register( 'skills.run', [
            'class'           => 'read',
            'required_scopes' => [ 'read.skills' ],
            'description'     => 'Load a Skill definition for execution. Returns the full instructions, rules, and steps for the AI to follow. Logs the execution in the audit trail. Pass id or slug.',
            'input_schema'    => [
                'id'     => [ 'type' => 'integer', 'description' => 'Skill ID' ],
                'slug'   => [ 'type' => 'string',  'description' => 'Skill slug' ],
                'inputs' => [ 'type' => 'object',  'description' => 'Runtime input values matching the skill input_schema' ],
            ],
            'handler' => [ $this, 'handle_run' ],
        ] );

        // ── Management ─────────────────────────────────────────────────────

        $this->register( 'skills.create', [
            'class'            => 'write',
            'required_scopes'  => [ 'manage.skills' ],
            'description'      => 'Create a new Skill. Always saved as draft. After creation the AI should ask the user if they want to activate it.',
            'supports_dry_run' => true,
            'input_schema'     => [
                'slug'         => [ 'type' => 'string',  'required' => true,  'description' => 'Machine-readable ID, snake_case (e.g. add_news_article)' ],
                'name'         => [ 'type' => 'string',  'required' => true,  'description' => 'Human-readable name' ],
                'description'  => [ 'type' => 'string',  'description' => 'What this skill does' ],
                'type'         => [ 'type' => 'string',  'default' => 'simple', 'description' => 'simple|guided|advanced' ],
                'steps'        => [ 'type' => 'array',   'description' => 'Executable steps array' ],
                'rules'        => [ 'type' => 'object',  'description' => 'Structured rules object' ],
                'input_schema' => [ 'type' => 'object',  'description' => 'JSON Schema for skill inputs' ],
                'permissions'  => [ 'type' => 'object',  'description' => 'Required scopes/permissions' ],
                'tags'         => [ 'type' => 'array',   'description' => 'Tags for similarity matching' ],
            ],
            'handler' => [ $this, 'handle_create' ],
        ] );

        $this->register( 'skills.activate', [
            'class'           => 'write',
            'required_scopes' => [ 'manage.skills' ],
            'description'     => 'Set a Skill status to active. Call this after the user explicitly approves activation.',
            'input_schema'    => [
                'id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler' => [ $this, 'handle_activate' ],
        ] );

        $this->register( 'skills.update', [
            'class'            => 'write',
            'required_scopes'  => [ 'manage.skills' ],
            'description'      => 'Update an existing Skill. Saves a revision before applying changes.',
            'supports_dry_run' => true,
            'input_schema'     => [
                'id'           => [ 'type' => 'integer', 'required' => true ],
                'name'         => [ 'type' => 'string' ],
                'description'  => [ 'type' => 'string' ],
                'type'         => [ 'type' => 'string' ],
                'steps'        => [ 'type' => 'array' ],
                'rules'        => [ 'type' => 'object' ],
                'input_schema' => [ 'type' => 'object' ],
                'permissions'  => [ 'type' => 'object' ],
                'tags'         => [ 'type' => 'array' ],
            ],
            'handler' => [ $this, 'handle_update' ],
        ] );

        $this->register( 'skills.archive', [
            'class'           => 'write',
            'required_scopes' => [ 'manage.skills' ],
            'description'     => 'Archive a Skill (sets status to archived). The skill is retained for history but no longer active.',
            'input_schema'    => [
                'id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler' => [ $this, 'handle_archive' ],
        ] );

        $this->register( 'skills.delete', [
            'class'            => 'destructive',
            'required_scopes'  => [ 'manage.skills' ],
            'requires_confirm' => true,
            'destructive'      => true,
            'description'      => 'Permanently delete a Skill and all its revisions. Requires confirm=true.',
            'input_schema'     => [
                'id'      => [ 'type' => 'integer', 'required' => true ],
                'confirm' => [ 'type' => 'boolean', 'required' => true ],
            ],
            'handler' => [ $this, 'handle_delete' ],
        ] );

        // ── Import ─────────────────────────────────────────────────────────

        $this->register( 'skills.import', [
            'class'            => 'write',
            'required_scopes'  => [ 'manage.skills' ],
            'description'      => 'Import a locally-defined Skill definition onto the site. Saved with status=suggested so it appears in the admin Suggested tab for human review and approval.',
            'supports_dry_run' => true,
            'input_schema'     => [
                'slug'         => [ 'type' => 'string',  'required' => true ],
                'name'         => [ 'type' => 'string',  'required' => true ],
                'description'  => [ 'type' => 'string' ],
                'type'         => [ 'type' => 'string',  'default' => 'simple' ],
                'steps'        => [ 'type' => 'array' ],
                'rules'        => [ 'type' => 'object' ],
                'input_schema' => [ 'type' => 'object' ],
                'permissions'  => [ 'type' => 'object' ],
                'tags'         => [ 'type' => 'array' ],
            ],
            'handler' => [ $this, 'handle_import' ],
        ] );

        // ── AI Learning Layer ──────────────────────────────────────────────

        $this->register( 'skills.suggest_from_session', [
            'class'           => 'read',
            'required_scopes' => [ 'learn.skills' ],
            'description'     => 'Analyze the current session\'s audit log to identify repeatable workflows worth saving as a Skill. Returns structured session data and similar existing skills. Does NOT create anything in the database — the AI uses this data to decide whether to propose a new skill.',
            'input_schema'    => [
                'session_id' => [ 'type' => 'integer', 'description' => 'Session ID to analyze (defaults to current active session)' ],
            ],
            'handler' => [ $this, 'handle_suggest_from_session' ],
        ] );

        $this->register( 'skills.propose_update', [
            'class'            => 'write',
            'required_scopes'  => [ 'learn.skills' ],
            'description'      => 'Propose an update to an existing Skill based on a new workflow. Creates a copy with status=update_proposal linked to the original. The original skill remains unchanged until the user accepts the proposal from the admin UI.',
            'supports_dry_run' => true,
            'input_schema'     => [
                'skill_id'    => [ 'type' => 'integer', 'required' => true, 'description' => 'ID of the skill to update' ],
                'name'        => [ 'type' => 'string' ],
                'description' => [ 'type' => 'string' ],
                'steps'       => [ 'type' => 'array' ],
                'rules'       => [ 'type' => 'object' ],
                'tags'        => [ 'type' => 'array' ],
                'rationale'   => [ 'type' => 'string', 'description' => 'Why this update is being proposed' ],
            ],
            'handler' => [ $this, 'handle_propose_update' ],
        ] );

        // ── Settings ───────────────────────────────────────────────────────

        $this->register( 'skills.suggestions', [
            'class'            => 'admin_sensitive',
            'required_scopes'  => [ 'manage.skills' ],
            'supports_dry_run' => true,
            'description'      => 'Enable, disable, or check the status of automatic skill suggestions that appear when session.close detects a repeatable workflow. Use action=disable if the user does not want to be prompted about saving skills.',
            'input_schema'     => [
                'action' => [ 'type' => 'string', 'required' => true, 'description' => 'enable | disable | status' ],
            ],
            'handler' => [ $this, 'handle_suggestions_setting' ],
        ] );

        $this->register( 'aicom.recipes', [
            'class'           => 'discovery',
            'required_scopes' => [],
            'description'     => 'Returns step-by-step recipes for tasks you can perform with YOUR key\'s permissions. Always filtered to what your key can actually do. Pass a task keyword to filter (e.g. "create post", "translate", "woocommerce product"). Leave empty to list all available recipes for your key.',
            'input_schema'    => [
                'task' => [
                    'type'        => 'string',
                    'description' => 'Keyword describing what you want to do (e.g. "create post", "translate", "product", "seo"). Optional — omit to list all recipes available to your key.',
                ],
            ],
            'handler' => [ $this, 'handle_recipes' ],
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    public function handle_list( array $args, array $key_record, bool $dry_run ): array {
        $limit  = max( 1, min( 100, (int) ( $args['limit'] ?? 20 ) ) );
        $offset = max( 0, (int) ( $args['offset'] ?? 0 ) );

        $filters = array_filter( [
            'status' => $args['status'] ?? null,
            'type'   => $args['type']   ?? null,
            'search' => $args['search'] ?? null,
        ] );

        $result = AICOM_Skills::list( $filters, $limit, $offset );
        return $this->ok( $result );
    }

    public function handle_get( array $args, array $key_record, bool $dry_run ): array {
        $skill = $this->resolve_skill( $args );
        if ( is_array( $skill ) && isset( $skill['error'] ) ) {
            return $skill;
        }
        return $this->ok( $skill );
    }

    public function handle_match( array $args, array $key_record, bool $dry_run ): array {
        if ( empty( $args['name'] ) ) {
            return $this->err( 'MISSING_PARAM', 'name is required' );
        }

        $candidate = [
            'name'        => $args['name'],
            'description' => $args['description'] ?? '',
            'type'        => $args['type'] ?? 'simple',
            'tags'        => $args['tags'] ?? [],
            'steps'       => $args['steps'] ?? [],
        ];

        $matches = AICOM_Skills::find_similar( $candidate );

        $top_match_type = empty( $matches ) ? 'create_new' : $matches[0]['match_type'];

        return $this->ok( [
            'candidate'      => $candidate,
            'top_match_type' => $top_match_type,
            'matches'        => $matches,
            'recommendation' => $this->match_recommendation( $top_match_type, $matches ),
        ] );
    }

    public function handle_compare( array $args, array $key_record, bool $dry_run ): array {
        $id_a = $this->require_int( $args, 'id_a' );
        $id_b = $this->require_int( $args, 'id_b' );

        if ( $id_a === null || $id_b === null ) {
            return $this->err( 'MISSING_PARAM', 'id_a and id_b are required' );
        }

        $a = AICOM_Skills::get( $id_a );
        $b = AICOM_Skills::get( $id_b );

        if ( ! $a ) { return $this->err( 'NOT_FOUND', "Skill $id_a not found", 'not_found', 404 ); }
        if ( ! $b ) { return $this->err( 'NOT_FOUND', "Skill $id_b not found", 'not_found', 404 ); }

        $diff = [];
        foreach ( [ 'name', 'description', 'type', 'status' ] as $field ) {
            if ( $a[ $field ] !== $b[ $field ] ) {
                $diff[ $field ] = [ 'from' => $a[ $field ], 'to' => $b[ $field ] ];
            }
        }

        foreach ( [ 'steps', 'rules', 'tags', 'input_schema', 'permissions' ] as $field ) {
            $json_a = wp_json_encode( $a[ $field ] );
            $json_b = wp_json_encode( $b[ $field ] );
            if ( $json_a !== $json_b ) {
                $diff[ $field ] = [ 'from' => $a[ $field ], 'to' => $b[ $field ] ];
            }
        }

        return $this->ok( [
            'skill_a' => [ 'id' => $a['id'], 'name' => $a['name'], 'version' => $a['version'], 'status' => $a['status'] ],
            'skill_b' => [ 'id' => $b['id'], 'name' => $b['name'], 'version' => $b['version'], 'status' => $b['status'] ],
            'changes' => $diff,
            'has_changes' => ! empty( $diff ),
        ] );
    }

    public function handle_run( array $args, array $key_record, bool $dry_run ): array {
        $skill = $this->resolve_skill( $args );
        if ( is_array( $skill ) && isset( $skill['error'] ) ) {
            return $skill;
        }

        if ( $skill['status'] !== 'active' ) {
            return $this->err( 'SKILL_NOT_ACTIVE', "Skill \"{$skill['name']}\" is {$skill['status']} — only active skills can be run" );
        }

        return $this->ok(
            [
                'skill_id'    => $skill['id'],
                'slug'        => $skill['slug'],
                'name'        => $skill['name'],
                'description' => $skill['description'],
                'type'        => $skill['type'],
                'version'     => $skill['version'],
                'input_schema'=> $skill['input_schema'],
                'rules'       => $skill['rules'],
                'steps'       => $skill['steps'],
                'permissions' => $skill['permissions'],
                'inputs'      => $args['inputs'] ?? null,
            ],
            [
                'target_type' => 'skill',
                'target_id'   => (string) $skill['id'],
                // Deliberately no 'summary' here: unlike a write confirmation
                // ("created: true"), the full result IS the payload the model
                // needs to execute — steps/rules/input_schema/permissions.
                // With a summary set, content[0].text collapses to a one-line
                // label ("action: run_skill, slug: ...") and the actual
                // workflow only reaches structuredContent, which a client
                // only gets if it negotiated a protocol version that
                // supports it. A strict content-only client (e.g. a Pydantic
                // CallToolResult model with extra='ignore') would then see
                // the label and nothing else. Omitting summary makes
                // summarize_result() fall back to JSON-encoding the whole
                // result into content[0].text, so the workflow is always
                // there regardless of protocol version or client leniency.
            ]
        );
    }

    public function handle_create( array $args, array $key_record, bool $dry_run ): array {
        if ( empty( $args['slug'] ) || empty( $args['name'] ) ) {
            return $this->err( 'MISSING_PARAM', 'slug and name are required' );
        }

        $slug = sanitize_key( $args['slug'] );
        if ( AICOM_Skills::get_by_slug( $slug ) ) {
            return $this->err( 'DUPLICATE_SLUG', "A skill with slug \"$slug\" already exists. Use skills.update to modify it." );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'slug' => $slug, 'name' => $args['name'], 'would_create' => true ] );
        }

        $skill = AICOM_Skills::create( array_merge( $args, [ 'key_id' => $key_record['id'] ?? null, 'status' => 'draft' ] ) );

        if ( isset( $skill['error'] ) ) {
            return $this->err( 'CREATE_FAILED', $skill['error'] );
        }

        return $this->ok(
            array_merge( $skill, [
                'suggested_next' => 'The skill has been saved as draft. Ask the user: "Would you like me to activate this skill so it can be used?"',
            ] ),
            [
                'target_type' => 'skill',
                'target_id'   => (string) $skill['id'],
                'summary'     => [ 'action' => 'create_skill', 'slug' => $skill['slug'] ],
            ]
        );
    }

    public function handle_activate( array $args, array $key_record, bool $dry_run ): array {
        $id = $this->require_int( $args, 'id' );
        if ( $id === null ) { return $this->err( 'MISSING_PARAM', 'id required' ); }

        $skill = AICOM_Skills::get( $id );
        if ( ! $skill ) { return $this->err( 'NOT_FOUND', 'Skill not found', 'not_found', 404 ); }
        if ( $skill['status'] === 'archived' ) { return $this->err( 'INVALID_STATUS', 'Cannot activate an archived skill. Archive must be removed first.' ); }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'id' => $id, 'would_activate' => true ] );
        }

        AICOM_Skills::set_status( $id, 'active', $key_record['id'] ?? null );

        return $this->ok(
            [ 'id' => $id, 'slug' => $skill['slug'], 'name' => $skill['name'], 'status' => 'active' ],
            [
                'target_type' => 'skill',
                'target_id'   => (string) $id,
                'summary'     => [ 'action' => 'activate_skill', 'slug' => $skill['slug'] ],
            ]
        );
    }

    public function handle_update( array $args, array $key_record, bool $dry_run ): array {
        $id = $this->require_int( $args, 'id' );
        if ( $id === null ) { return $this->err( 'MISSING_PARAM', 'id required' ); }

        $skill = AICOM_Skills::get( $id );
        if ( ! $skill ) { return $this->err( 'NOT_FOUND', 'Skill not found', 'not_found', 404 ); }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'id' => $id, 'would_update' => true ] );
        }

        $updated = AICOM_Skills::update( $id, $args, $key_record['id'] ?? null );

        return $this->ok(
            [ 'id' => $id, 'updated' => $updated ],
            [
                'target_type' => 'skill',
                'target_id'   => (string) $id,
                'summary'     => [ 'action' => 'update_skill', 'slug' => $skill['slug'] ],
            ]
        );
    }

    public function handle_archive( array $args, array $key_record, bool $dry_run ): array {
        $id = $this->require_int( $args, 'id' );
        if ( $id === null ) { return $this->err( 'MISSING_PARAM', 'id required' ); }

        $skill = AICOM_Skills::get( $id );
        if ( ! $skill ) { return $this->err( 'NOT_FOUND', 'Skill not found', 'not_found', 404 ); }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'id' => $id, 'would_archive' => true ] );
        }

        AICOM_Skills::set_status( $id, 'archived', $key_record['id'] ?? null );

        return $this->ok(
            [ 'id' => $id, 'slug' => $skill['slug'], 'status' => 'archived' ],
            [
                'target_type' => 'skill',
                'target_id'   => (string) $id,
                'summary'     => [ 'action' => 'archive_skill', 'slug' => $skill['slug'] ],
            ]
        );
    }

    public function handle_delete( array $args, array $key_record, bool $dry_run ): array {
        $id = $this->require_int( $args, 'id' );
        if ( $id === null ) { return $this->err( 'MISSING_PARAM', 'id required' ); }

        $skill = AICOM_Skills::get( $id );
        if ( ! $skill ) { return $this->err( 'NOT_FOUND', 'Skill not found', 'not_found', 404 ); }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'id' => $id, 'would_delete' => true ] );
        }

        AICOM_Skills::delete( $id );

        return $this->ok(
            [ 'id' => $id, 'slug' => $skill['slug'], 'deleted' => true ],
            [
                'target_type' => 'skill',
                'target_id'   => (string) $id,
                'summary'     => [ 'action' => 'delete_skill', 'slug' => $skill['slug'] ],
            ]
        );
    }

    public function handle_import( array $args, array $key_record, bool $dry_run ): array {
        if ( empty( $args['slug'] ) || empty( $args['name'] ) ) {
            return $this->err( 'MISSING_PARAM', 'slug and name are required' );
        }

        $slug = sanitize_key( $args['slug'] );
        if ( AICOM_Skills::get_by_slug( $slug ) ) {
            return $this->err( 'DUPLICATE_SLUG', "A skill with slug \"$slug\" already exists. Use skills.propose_update to suggest changes to an existing skill." );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'slug' => $slug, 'would_import' => true, 'status' => 'suggested' ] );
        }

        $skill = AICOM_Skills::create( array_merge( $args, [
            'key_id' => $key_record['id'] ?? null,
            'status' => 'suggested',
        ] ) );

        if ( isset( $skill['error'] ) ) {
            return $this->err( 'IMPORT_FAILED', $skill['error'] );
        }

        return $this->ok(
            array_merge( $skill, [
                'note' => 'Skill imported as "suggested". Open the AICOM admin → Skills → Suggested tab to review and activate it.',
            ] ),
            [
                'target_type' => 'skill',
                'target_id'   => (string) $skill['id'],
                'summary'     => [ 'action' => 'import_skill', 'slug' => $skill['slug'] ],
            ]
        );
    }

    public function handle_suggest_from_session( array $args, array $key_record, bool $dry_run ): array {
        global $wpdb;

        $session_id = isset( $args['session_id'] ) ? (int) $args['session_id'] : null;

        if ( ! $session_id ) {
            $active = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}aicom_sessions WHERE api_key_id = %d AND status = 'open' ORDER BY opened_at DESC LIMIT 1",
                    (int) ( $key_record['id'] ?? 0 )
                ),
                ARRAY_A
            );
            $session_id = $active ? (int) $active['id'] : null;
        }

        if ( ! $session_id ) {
            return $this->err( 'NO_SESSION', 'No active session found. Open a session first or pass session_id.' );
        }

        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT tool_name, module, tool_class, target_type, status, params_json, result_summary_json
                 FROM {$wpdb->prefix}aicom_logs
                 WHERE session_id = %d AND status = 'success'
                 ORDER BY created_at ASC",
                $session_id
            ),
            ARRAY_A
        ) ?: [];

        $tool_sequence = array_column( $logs, 'tool_name' );
        $modules_used  = array_values( array_unique( array_column( $logs, 'module' ) ) );
        $targets       = array_values( array_unique( array_filter( array_column( $logs, 'target_type' ) ) ) );

        // Build candidate for similarity matching
        $candidate = [
            'name'  => 'Session workflow',
            'steps' => array_map( fn( $t ) => [ 'action' => $t ], $tool_sequence ),
            'tags'  => array_merge( $modules_used, $targets ),
            'type'  => 'simple',
        ];

        $similar = AICOM_Skills::find_similar( $candidate );

        return $this->ok( [
            'session_id'     => $session_id,
            'tool_sequence'  => $tool_sequence,
            'modules_used'   => $modules_used,
            'targets'        => $targets,
            'total_actions'  => count( $logs ),
            'similar_skills' => $similar,
            'recommendation' => empty( $similar ) || $similar[0]['score'] < 0.60
                ? 'This workflow looks like a new reusable pattern. Consider calling skills.create to save it.'
                : ( $similar[0]['score'] >= 0.85
                    ? "Very similar to existing skill \"{$similar[0]['skill']['name']}\" (score: {$similar[0]['score']}). Consider using or updating that skill instead."
                    : "Partially similar to \"{$similar[0]['skill']['name']}\" (score: {$similar[0]['score']}). Ask the user whether to create a new skill or update the existing one." ),
        ] );
    }

    public function handle_propose_update( array $args, array $key_record, bool $dry_run ): array {
        $skill_id = $this->require_int( $args, 'skill_id' );
        if ( $skill_id === null ) { return $this->err( 'MISSING_PARAM', 'skill_id required' ); }

        $original = AICOM_Skills::get( $skill_id );
        if ( ! $original ) { return $this->err( 'NOT_FOUND', 'Skill not found', 'not_found', 404 ); }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'skill_id' => $skill_id, 'would_propose' => true ] );
        }

        $proposal_data = array_merge( $original, [
            'slug'           => $original['slug'] . '_proposal_' . time(),
            'status'         => 'update_proposal',
            'parent_skill_id'=> $skill_id,
            'key_id'         => $key_record['id'] ?? null,
            'session_id'     => $args['session_id'] ?? null,
        ] );

        // Apply proposed changes
        foreach ( [ 'name', 'description', 'steps', 'rules', 'tags' ] as $field ) {
            if ( array_key_exists( $field, $args ) ) {
                $proposal_data[ $field ] = $args[ $field ];
            }
        }

        $proposal = AICOM_Skills::create( $proposal_data );
        if ( isset( $proposal['error'] ) ) {
            return $this->err( 'PROPOSE_FAILED', $proposal['error'] );
        }

        return $this->ok(
            [
                'proposal_id'    => $proposal['id'],
                'original_id'    => $skill_id,
                'original_name'  => $original['name'],
                'rationale'      => $args['rationale'] ?? null,
                'note'           => 'Update proposal saved. Open AICOM admin → Skills → Proposals tab to review and accept or reject it.',
            ],
            [
                'target_type' => 'skill',
                'target_id'   => (string) $proposal['id'],
                'summary'     => [ 'action' => 'propose_skill_update', 'slug' => $original['slug'] ],
            ]
        );
    }

    public function handle_recipes( array $args, array $key_record ): array {
        $task    = trim( (string) ( $args['task'] ?? '' ) );
        $recipes = $task
            ? AICOM_Recipes::get_for_key( $task, $key_record )
            : AICOM_Recipes::all_for_key( $key_record );

        if ( empty( $recipes ) ) {
            return $this->ok( [
                'recipes' => [],
                'hint'    => 'No recipe matched your key\'s permissions. Try: create post, upload media, backup, alt text.',
            ] );
        }
        return $this->ok( [ 'recipes' => $recipes ] );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function resolve_skill( array $args ): array {
        if ( ! empty( $args['id'] ) ) {
            $skill = AICOM_Skills::get( (int) $args['id'] );
        } elseif ( ! empty( $args['slug'] ) ) {
            $skill = AICOM_Skills::get_by_slug( sanitize_key( $args['slug'] ) );
        } else {
            return $this->err( 'MISSING_PARAM', 'Pass id or slug' );
        }

        if ( ! $skill ) {
            return $this->err( 'NOT_FOUND', 'Skill not found', 'not_found', 404 );
        }

        return $skill;
    }

    private function match_recommendation( string $match_type, array $matches ): string {
        if ( empty( $matches ) ) {
            return 'No similar skills found. You can safely create a new skill.';
        }
        if ( $match_type === 'create_new' ) {
            $top = $matches[0];
            return "No strong match found. Closest is \"{$top['skill']['name']}\" (score: {$top['score']}) — not similar enough. You can safely create a new skill.";
        }
        $top = $matches[0];
        if ( $match_type === 'use_existing' ) {
            return "Use existing skill \"{$top['skill']['name']}\" (id: {$top['skill']['id']}, score: {$top['score']}). Call skills.run to load it.";
        }
        return "Partial match with \"{$top['skill']['name']}\" (score: {$top['score']}). Ask the user whether to use the existing skill, update it (skills.propose_update), or create a new one.";
    }

    public function handle_suggestions_setting( array $args, array $key_record, bool $dry_run ): array {
        $action = strtolower( trim( (string) ( $args['action'] ?? '' ) ) );
        if ( ! in_array( $action, [ 'enable', 'disable', 'status' ], true ) ) {
            return $this->err( 'INVALID_PARAM', 'action must be enable, disable, or status', 'validation_failed' );
        }

        $current = get_option( 'aicom_skill_suggestions', '1' ) === '1';

        if ( $action === 'status' ) {
            return $this->ok( [
                'enabled' => $current,
                'message' => $current
                    ? 'Skill suggestions are enabled. session.close will suggest saving repeatable workflows.'
                    : 'Skill suggestions are disabled. session.close will not analyze or suggest skills.',
            ] );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_set_enabled' => $action === 'enable' ] );
        }

        update_option( 'aicom_skill_suggestions', $action === 'enable' ? '1' : '0' );

        return $this->ok( [
            'enabled' => $action === 'enable',
            'message' => $action === 'enable'
                ? 'Skill suggestions enabled. session.close will now analyze workflows and suggest saving repeatable ones.'
                : 'Skill suggestions disabled. session.close will no longer suggest saving skills.',
        ] );
    }
}
