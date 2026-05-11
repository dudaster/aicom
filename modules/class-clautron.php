<?php
/**
 * Clautron Ops module.
 *
 * Exposes Clautron's capability catalog, blueprint management, and primitive
 * registry to AI agents. Requires Clautron plugin to be active.
 *
 * Registered tools (15):
 *   clautron.catalog.list             — active capabilities + available templates
 *   clautron.catalog.install          — install a template by capability_id
 *   clautron.primitives.list          — full primitive catalog with schemas
 *   clautron.blueprint.examples       — demo blueprints as composition reference
 *   clautron.blueprint.list           — all blueprints with status
 *   clautron.blueprint.validate       — validate JSON before creating
 *   clautron.blueprint.create         — create a blueprint from JSON
 *   clautron.blueprint.compile        — compile (activate) a blueprint
 *   clautron.blueprint.smoke_test     — verify compiled blueprint is wired correctly
 *   clautron.capability.meta.get      — read capability meta fields for a post
 *   clautron.capability.meta.set      — write capability meta fields for a post
 *   clautron.events.types             — list all distinct event types in the store
 *   clautron.events.query             — read raw event rows with filters
 *   clautron.events.aggregate         — aggregate events (count/sum/avg by period)
 *   clautron.capability.ensure        — idempotent: activate capability, create if missing
 */
class AICOM_Module_Clautron extends AICOM_Module_Base {

    public function get_module_name(): string {
        return 'clautron';
    }

    public function register_tools(): void {
        $dep    = 'clautron';
        $scopes = 'manage.clautron';

        // ── Discovery ──────────────────────────────────────────────────────────

        $this->register( 'clautron.catalog.list', [
            'class'           => 'discovery',
            'required_scopes' => [ 'read.wp' ],
            'dependency'      => $dep,
            'description'     => 'List all Clautron capabilities: active ones (compiled and running) and available templates (demo blueprints ready to install). Each entry includes an llm_guide field with instructions on how to interact with that capability. ALWAYS call this before creating any new capability — if a capability_id already exists, use clautron.capability.ensure instead of clautron.blueprint.create to avoid duplicates.',
            'input_schema'    => [],
            'handler'         => [ $this, 'handle_catalog_list' ],
        ] );

        $this->register( 'clautron.primitives.list', [
            'class'           => 'discovery',
            'required_scopes' => [ 'read.wp' ],
            'dependency'      => $dep,
            'description'     => 'List all registered Clautron primitives with their schemas, categories, phases (compile|runtime), and descriptions. Use this to understand what building blocks are available before composing a blueprint JSON.',
            'input_schema'    => [
                'category' => [ 'type' => 'string', 'description' => 'Filter by category: data, ui, wordpress, logic, http, communication, user, transform, media, taxonomy, workflow, frontend. Omit for all.' ],
                'phase'    => [ 'type' => 'string', 'description' => 'Filter by phase: compile or runtime. Omit for all.' ],
            ],
            'handler'         => [ $this, 'handle_primitives_list' ],
        ] );

        $this->register( 'clautron.blueprint.examples', [
            'class'           => 'discovery',
            'required_scopes' => [ 'read.wp' ],
            'dependency'      => $dep,
            'description'     => 'Return demo blueprints as composition reference. Shows how primitives are combined into working blueprints. Optionally filter by capability_id for a specific example.',
            'input_schema'    => [
                'capability_id' => [ 'type' => 'string', 'description' => 'Return only this specific demo (e.g. "seo", "content-completeness"). Omit for all demos.' ],
            ],
            'handler'         => [ $this, 'handle_blueprint_examples' ],
        ] );

        $this->register( 'clautron.blueprint.list', [
            'class'           => 'read',
            'required_scopes' => [ 'read.wp' ],
            'dependency'      => $dep,
            'description'     => 'List all blueprints stored on this site with their id, name, status, and object_type.',
            'input_schema'    => [
                'status' => [ 'type' => 'string', 'description' => 'Filter by status: draft, active, paused, archived. Omit for all.' ],
            ],
            'handler'         => [ $this, 'handle_blueprint_list' ],
        ] );

        // ── Provisioning ───────────────────────────────────────────────────────

        $this->register( 'clautron.catalog.install', [
            'class'            => 'write',
            'required_scopes'  => [ $scopes ],
            'supports_dry_run' => true,
            'dependency'       => $dep,
            'description'      => 'Install a Clautron capability from a demo template by capability_id (e.g. "seo", "content-completeness", "duplicate-post"). The template is imported and compiled in one step. Use clautron.catalog.list first to see available templates.',
            'input_schema'     => [
                'capability_id' => [ 'type' => 'string', 'required' => true, 'description' => 'The capability_id of the template to install.' ],
            ],
            'handler'          => [ $this, 'handle_catalog_install' ],
        ] );

        $this->register( 'clautron.blueprint.validate', [
            'class'           => 'discovery',
            'required_scopes' => [ 'read.wp' ],
            'dependency'      => $dep,
            'description'     => 'Validate a blueprint JSON string before creating it. Returns validation errors and warnings. Use this after composing a blueprint from primitives to catch schema or safety issues before saving.',
            'input_schema'    => [
                'blueprint_json' => [ 'type' => 'string', 'required' => true, 'description' => 'The full blueprint JSON as a string.' ],
            ],
            'handler'         => [ $this, 'handle_blueprint_validate' ],
        ] );

        $this->register( 'clautron.blueprint.create', [
            'class'            => 'write',
            'required_scopes'  => [ $scopes ],
            'supports_dry_run' => true,
            'dependency'       => $dep,
            'description'      => 'Create a new blueprint from a JSON string. Returns the new blueprint_id. The blueprint is saved as a draft — use clautron.blueprint.compile to activate it. WARNING: this tool is NOT idempotent. If you need to create a named capability, use clautron.capability.ensure instead — it checks whether a capability_id already exists before creating anything.',
            'input_schema'     => [
                'blueprint_json' => [ 'type' => 'string', 'required' => true, 'description' => 'Full blueprint JSON. Include capability_id and llm_guide fields. Must pass clautron.blueprint.validate first.' ],
            ],
            'handler'          => [ $this, 'handle_blueprint_create' ],
        ] );

        $this->register( 'clautron.blueprint.compile', [
            'class'            => 'write',
            'required_scopes'  => [ $scopes ],
            'supports_dry_run' => false,
            'dependency'       => $dep,
            'description'      => 'Compile (activate) a blueprint by id. Registers all compile-phase structures (metaboxes, columns, head injection, etc.) and makes the blueprint active. Returns compile errors if any.',
            'input_schema'     => [
                'blueprint_id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'          => [ $this, 'handle_blueprint_compile' ],
        ] );

        // ── Verification ───────────────────────────────────────────────────────

        $this->register( 'clautron.blueprint.smoke_test', [
            'class'           => 'read',
            'required_scopes' => [ 'read.wp' ],
            'dependency'      => $dep,
            'description'     => 'Run smoke tests on a compiled blueprint to verify its structures are correctly registered in WordPress (metaboxes, admin columns, wp_head hooks, post types, taxonomies, etc.). Returns passed/failed checks with a summary.',
            'input_schema'    => [
                'blueprint_id' => [ 'type' => 'integer', 'required' => true ],
                'post_id'      => [ 'type' => 'integer', 'description' => 'Optional post ID for post-type-specific checks.' ],
            ],
            'handler'         => [ $this, 'handle_blueprint_smoke_test' ],
        ] );

        // ── Capability meta ────────────────────────────────────────────────────

        $this->register( 'clautron.capability.meta.get', [
            'class'           => 'read',
            'required_scopes' => [ 'read.wp' ],
            'dependency'      => $dep,
            'description'     => 'Read the meta fields managed by an active Clautron capability for a specific post. Returns values with field descriptions from the capability\'s "provides" manifest.',
            'input_schema'    => [
                'capability_id' => [ 'type' => 'string',  'required' => true, 'description' => 'e.g. "seo", "content-completeness"' ],
                'post_id'       => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_capability_meta_get' ],
        ] );

        // ── Idempotent provisioning ────────────────────────────────────────────

        $this->register( 'clautron.capability.ensure', [
            'class'            => 'write',
            'required_scopes'  => [ $scopes ],
            'supports_dry_run' => true,
            'dependency'       => $dep,
            'description'      => 'Idempotent: activate a capability by capability_id. If it is already active, returns it immediately without creating anything. If it matches a demo template, installs the template. If neither, creates and compiles the provided blueprint_json. This is the PREFERRED way to provision capabilities — use this instead of blueprint.create + blueprint.compile to avoid duplicate blueprints.',
            'input_schema'     => [
                'capability_id'  => [ 'type' => 'string', 'required' => true, 'description' => 'Unique slug for this capability (e.g. "login-tracker", "order-monitor"). This is the idempotency key.' ],
                'blueprint_json' => [ 'type' => 'string', 'description' => 'Full blueprint JSON for custom capabilities not available as templates. Include llm_guide and provides fields. Required if capability_id is not a known template.' ],
            ],
            'handler'          => [ $this, 'handle_capability_ensure' ],
        ] );

        // ── Events (monitoring & analytics) ───────────────────────────────────

        $this->register( 'clautron.events.types', [
            'class'           => 'discovery',
            'required_scopes' => [ 'read.wp' ],
            'dependency'      => $dep,
            'description'     => 'List all distinct event_type values currently stored in clautron_events. Use this first to discover what monitoring capabilities are active on the site before querying or aggregating.',
            'input_schema'    => [],
            'handler'         => [ $this, 'handle_events_types' ],
        ] );

        $this->register( 'clautron.events.query', [
            'class'           => 'read',
            'required_scopes' => [ 'read.wp' ],
            'dependency'      => $dep,
            'description'     => 'Read raw event rows from the clautron_events store. Use to inspect individual occurrences before deciding whether to aggregate. Max 500 rows per call.',
            'input_schema'    => [
                'event_type'  => [ 'type' => 'string',  'required' => true,  'description' => 'Event type slug (from clautron.events.types).' ],
                'since'       => [ 'type' => 'string',  'description' => 'strtotime-compatible start (e.g. -7 days, -1 month). Default: -30 days.' ],
                'until'       => [ 'type' => 'string',  'description' => 'strtotime-compatible end. Default: now.' ],
                'object_type' => [ 'type' => 'string',  'description' => 'Filter by object category (post, user, order…).' ],
                'object_id'   => [ 'type' => 'integer', 'description' => 'Filter by object ID.' ],
                'text_value'  => [ 'type' => 'string',  'description' => 'Filter by exact text_value (URL, status…).' ],
                'limit'       => [ 'type' => 'integer', 'description' => 'Max rows to return (default 100, max 500).' ],
                'order'       => [ 'type' => 'string',  'description' => 'ASC or DESC. Default DESC.' ],
            ],
            'handler'         => [ $this, 'handle_events_query' ],
        ] );

        $this->register( 'clautron.events.aggregate', [
            'class'           => 'read',
            'required_scopes' => [ 'read.wp' ],
            'dependency'      => $dep,
            'description'     => 'Aggregate clautron_events data for trend analysis and reporting. Returns an array of {period, value} rows. Use to detect trends, compare periods, or summarise monitoring data before generating insights.',
            'input_schema'    => [
                'event_type'  => [ 'type' => 'string',  'required' => true ],
                'aggregate'   => [ 'type' => 'string',  'description' => 'count | sum | avg | min | max. Default: count.' ],
                'group_by'    => [ 'type' => 'string',  'description' => 'hour | day | week | month | object_id | text_value. Omit for a single scalar result.' ],
                'since'       => [ 'type' => 'string',  'description' => 'Start of period. Default: -30 days.' ],
                'until'       => [ 'type' => 'string',  'description' => 'End of period. Default: now.' ],
                'object_type' => [ 'type' => 'string',  'description' => 'Filter by object category.' ],
                'object_id'   => [ 'type' => 'integer', 'description' => 'Filter by object ID.' ],
            ],
            'handler'         => [ $this, 'handle_events_aggregate' ],
        ] );

        $this->register( 'clautron.capability.meta.set', [
            'class'            => 'write',
            'required_scopes'  => [ 'manage.meta', $scopes ],
            'supports_dry_run' => true,
            'dependency'       => $dep,
            'description'      => 'Write meta fields managed by an active Clautron capability for a specific post. Read-only fields (like computed scores) are automatically skipped. Returns which fields were updated vs skipped.',
            'input_schema'     => [
                'capability_id' => [ 'type' => 'string',  'required' => true ],
                'post_id'       => [ 'type' => 'integer', 'required' => true ],
                'fields'        => [ 'type' => 'object',  'required' => true, 'description' => 'Map of meta_key => value. Use clautron.capability.meta.get to see available keys.' ],
            ],
            'handler'          => [ $this, 'handle_capability_meta_set' ],
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    public function handle_catalog_list( array $args, array $key_record, bool $dry_run ): array {
        if ( ! class_exists( 'CLAUTRON_Catalog' ) ) {
            return $this->err( 'CLAUTRON_NOT_LOADED', 'CLAUTRON_Catalog class not available.', 'error' );
        }
        $catalog = CLAUTRON_Catalog::catalog();
        return $this->ok(
            [ 'catalog' => $catalog, 'count' => count( $catalog ) ],
            [ 'target_type' => 'clautron_catalog', 'target_id' => 'all' ]
        );
    }

    public function handle_primitives_list( array $args, array $key_record, bool $dry_run ): array {
        if ( ! class_exists( 'CLAUTRON_Primitive_Registry' ) ) {
            return $this->err( 'CLAUTRON_NOT_LOADED', 'CLAUTRON_Primitive_Registry not available.', 'error' );
        }

        $catalog  = CLAUTRON_Primitive_Registry::to_catalog();
        $category = $this->require_string( $args, 'category' );
        $phase    = $this->require_string( $args, 'phase' );

        if ( $category ) {
            $catalog = array_values( array_filter( $catalog, fn( $p ) => $p['category'] === $category ) );
        }
        if ( $phase ) {
            $catalog = array_values( array_filter( $catalog, fn( $p ) => $p['phase'] === $phase ) );
        }

        return $this->ok(
            [ 'primitives' => $catalog, 'count' => count( $catalog ) ],
            [ 'target_type' => 'clautron_primitives' ]
        );
    }

    public function handle_blueprint_examples( array $args, array $key_record, bool $dry_run ): array {
        $demo_dir      = defined( 'CLAUTRON_DIR' ) ? CLAUTRON_DIR . 'demo/' : null;
        $capability_id = $this->require_string( $args, 'capability_id' );

        if ( ! $demo_dir || ! is_dir( $demo_dir ) ) {
            return $this->err( 'NOT_FOUND', 'Clautron demo directory not found.', 'error', 404 );
        }

        $examples = [];
        foreach ( glob( $demo_dir . '*.json' ) ?: [] as $path ) {
            $data = json_decode( file_get_contents( $path ), true );
            if ( ! is_array( $data ) ) {
                continue;
            }
            if ( $capability_id && ( $data['capability_id'] ?? '' ) !== $capability_id ) {
                continue;
            }
            $examples[] = $data;
        }

        return $this->ok(
            [ 'examples' => $examples, 'count' => count( $examples ) ],
            [ 'target_type' => 'clautron_examples' ]
        );
    }

    public function handle_blueprint_list( array $args, array $key_record, bool $dry_run ): array {
        if ( ! class_exists( 'CLAUTRON_Blueprint_Store' ) ) {
            return $this->err( 'CLAUTRON_NOT_LOADED', 'CLAUTRON_Blueprint_Store not available.', 'error' );
        }

        $query_args = [ 'per_page' => 200 ];
        $status     = $this->require_string( $args, 'status' );
        if ( $status ) {
            $query_args['status'] = $status;
        }

        $rows = CLAUTRON_Blueprint_Store::query( $query_args );
        $list = array_map( static function ( $row ) {
            return [
                'id'          => (int) $row->id,
                'name'        => $row->name,
                'description' => $row->description ?? '',
                'status'      => $row->status,
                'object_type' => $row->object_type,
                'source'      => $row->source,
                'created_at'  => $row->created_at,
                'updated_at'  => $row->updated_at,
            ];
        }, $rows );

        return $this->ok(
            [ 'blueprints' => $list, 'count' => count( $list ) ],
            [ 'target_type' => 'clautron_blueprints' ]
        );
    }

    public function handle_catalog_install( array $args, array $key_record, bool $dry_run ): array {
        $capability_id = $this->require_string( $args, 'capability_id' );
        if ( ! $capability_id ) {
            return $this->err( 'MISSING_PARAM', 'capability_id is required.', 'validation_failed' );
        }
        if ( ! class_exists( 'CLAUTRON_Catalog' ) ) {
            return $this->err( 'CLAUTRON_NOT_LOADED', 'CLAUTRON_Catalog not available.', 'error' );
        }

        if ( $dry_run ) {
            $templates = CLAUTRON_Catalog::get_templates();
            $found     = ! empty( array_filter( $templates, fn( $t ) => $t['capability_id'] === $capability_id ) );
            return $this->ok( [
                'dry_run'        => true,
                'capability_id'  => $capability_id,
                'template_found' => $found,
            ] );
        }

        $result = CLAUTRON_Catalog::install( $capability_id );
        if ( ! ( $result['success'] ?? false ) ) {
            return $this->err( 'INSTALL_FAILED', $result['error'] ?? 'Unknown error.', 'error' );
        }

        return $this->ok( $result, [
            'target_type' => 'clautron_blueprint',
            'target_id'   => $result['blueprint_id'] ?? 0,
            'summary'     => [ 'action' => 'install', 'capability_id' => $capability_id ],
        ] );
    }

    public function handle_blueprint_validate( array $args, array $key_record, bool $dry_run ): array {
        $json = $args['blueprint_json'] ?? '';
        if ( ! $json ) {
            return $this->err( 'MISSING_PARAM', 'blueprint_json is required.', 'validation_failed' );
        }

        $data = json_decode( $json, true );
        if ( ! is_array( $data ) ) {
            return $this->err( 'INVALID_JSON', 'blueprint_json is not valid JSON: ' . json_last_error_msg(), 'validation_failed' );
        }
        if ( ! class_exists( 'CLAUTRON_Blueprint' ) || ! class_exists( 'CLAUTRON_Blueprint_Validator' ) ) {
            return $this->err( 'CLAUTRON_NOT_LOADED', 'Clautron validator not available.', 'error' );
        }

        $blueprint = new CLAUTRON_Blueprint( $data );
        $result    = CLAUTRON_Blueprint_Validator::validate( $blueprint );

        return $this->ok( [
            'valid'    => $result->is_valid(),
            'errors'   => $result->get_errors(),
            'warnings' => $result->get_warnings(),
        ] );
    }

    public function handle_blueprint_create( array $args, array $key_record, bool $dry_run ): array {
        $json = $args['blueprint_json'] ?? '';
        if ( ! $json ) {
            return $this->err( 'MISSING_PARAM', 'blueprint_json is required.', 'validation_failed' );
        }

        $data = json_decode( $json, true );
        if ( ! is_array( $data ) ) {
            return $this->err( 'INVALID_JSON', 'blueprint_json is not valid JSON.', 'validation_failed' );
        }
        if ( ! class_exists( 'CLAUTRON_Blueprint_Store' ) ) {
            return $this->err( 'CLAUTRON_NOT_LOADED', 'CLAUTRON_Blueprint_Store not available.', 'error' );
        }

        if ( $dry_run ) {
            // Validate only in dry-run mode.
            $blueprint = new CLAUTRON_Blueprint( $data );
            $result    = CLAUTRON_Blueprint_Validator::validate( $blueprint );
            return $this->ok( [
                'dry_run'  => true,
                'valid'    => $result->is_valid(),
                'errors'   => $result->get_errors(),
                'warnings' => $result->get_warnings(),
            ] );
        }

        // Ensure a fresh UUID.
        $data['id'] = wp_generate_uuid4();
        $json       = wp_json_encode( $data );

        $blueprint_id = CLAUTRON_Blueprint_Store::create( [
            'name'           => sanitize_text_field( $data['name'] ?? 'Unnamed Blueprint' ),
            'description'    => sanitize_textarea_field( $data['description'] ?? '' ),
            'object_type'    => $data['object_type'] ?? 'feature',
            'source'         => 'manual',
            'status'         => 'draft',
            'blueprint_json' => $json,
        ] );

        if ( ! $blueprint_id ) {
            return $this->err( 'CREATE_FAILED', 'Failed to save blueprint to database.', 'error' );
        }

        return $this->ok(
            [ 'blueprint_id' => $blueprint_id, 'created' => true, 'status' => 'draft' ],
            [ 'target_type' => 'clautron_blueprint', 'target_id' => $blueprint_id, 'summary' => [ 'action' => 'create' ] ]
        );
    }

    public function handle_blueprint_compile( array $args, array $key_record, bool $dry_run ): array {
        $blueprint_id = $this->require_int( $args, 'blueprint_id' );
        if ( ! $blueprint_id ) {
            return $this->err( 'MISSING_PARAM', 'blueprint_id is required.', 'validation_failed' );
        }
        if ( ! class_exists( 'CLAUTRON_Plugin' ) ) {
            return $this->err( 'CLAUTRON_NOT_LOADED', 'Clautron compiler not available.', 'error' );
        }

        $result = CLAUTRON_Plugin::make_compiler()->compile( $blueprint_id );
        $errors = $result->get_errors();

        if ( $errors ) {
            return $this->err( 'COMPILE_FAILED', implode( '; ', $errors ), 'error' );
        }

        return $this->ok(
            [ 'blueprint_id' => $blueprint_id, 'compiled' => true, 'warnings' => $result->get_warnings() ],
            [ 'target_type' => 'clautron_blueprint', 'target_id' => $blueprint_id, 'summary' => [ 'action' => 'compile' ] ]
        );
    }

    public function handle_blueprint_smoke_test( array $args, array $key_record, bool $dry_run ): array {
        $blueprint_id = $this->require_int( $args, 'blueprint_id' );
        $post_id      = (int) ( $args['post_id'] ?? 0 );

        if ( ! $blueprint_id ) {
            return $this->err( 'MISSING_PARAM', 'blueprint_id is required.', 'validation_failed' );
        }
        if ( ! class_exists( 'CLAUTRON_Smoke_Tester' ) ) {
            return $this->err( 'CLAUTRON_NOT_LOADED', 'CLAUTRON_Smoke_Tester not available.', 'error' );
        }

        $result = CLAUTRON_Smoke_Tester::test( $blueprint_id, $post_id );
        return $this->ok( $result, [ 'target_type' => 'clautron_blueprint', 'target_id' => $blueprint_id ] );
    }

    public function handle_capability_meta_get( array $args, array $key_record, bool $dry_run ): array {
        $capability_id = $this->require_string( $args, 'capability_id' );
        $post_id       = $this->require_int( $args, 'post_id' );

        if ( ! $capability_id ) return $this->err( 'MISSING_PARAM', 'capability_id is required.', 'validation_failed' );
        if ( ! $post_id )       return $this->err( 'MISSING_PARAM', 'post_id is required.', 'validation_failed' );
        if ( ! class_exists( 'CLAUTRON_Catalog' ) ) {
            return $this->err( 'CLAUTRON_NOT_LOADED', 'CLAUTRON_Catalog not available.', 'error' );
        }

        $result = CLAUTRON_Catalog::meta_get( $capability_id, $post_id );
        if ( isset( $result['error'] ) ) {
            return $this->err( 'CAPABILITY_NOT_FOUND', $result['error'], 'error', 404 );
        }

        return $this->ok(
            [ 'capability_id' => $capability_id, 'post_id' => $post_id, 'fields' => $result ],
            [ 'target_type' => 'clautron_meta', 'target_id' => $post_id ]
        );
    }

    public function handle_capability_meta_set( array $args, array $key_record, bool $dry_run ): array {
        $capability_id = $this->require_string( $args, 'capability_id' );
        $post_id       = $this->require_int( $args, 'post_id' );
        $fields        = $args['fields'] ?? [];

        if ( ! $capability_id ) return $this->err( 'MISSING_PARAM', 'capability_id is required.', 'validation_failed' );
        if ( ! $post_id )       return $this->err( 'MISSING_PARAM', 'post_id is required.', 'validation_failed' );
        if ( ! is_array( $fields ) || empty( $fields ) ) {
            return $this->err( 'MISSING_PARAM', 'fields must be a non-empty object.', 'validation_failed' );
        }
        if ( ! class_exists( 'CLAUTRON_Catalog' ) ) {
            return $this->err( 'CLAUTRON_NOT_LOADED', 'CLAUTRON_Catalog not available.', 'error' );
        }

        if ( $dry_run ) {
            return $this->ok( [
                'dry_run'       => true,
                'capability_id' => $capability_id,
                'post_id'       => $post_id,
                'would_write'   => array_keys( $fields ),
            ] );
        }

        $result = CLAUTRON_Catalog::meta_set( $capability_id, $post_id, $fields );
        if ( isset( $result['error'] ) ) {
            return $this->err( 'CAPABILITY_NOT_FOUND', $result['error'], 'error', 404 );
        }

        return $this->ok( $result, [
            'target_type' => 'clautron_meta',
            'target_id'   => $post_id,
            'summary'     => [ 'fields' => $result['updated'] ?? [] ],
        ] );
    }

    // ── Ensure handler ────────────────────────────────────────────────────────

    public function handle_capability_ensure( array $args, array $key_record, bool $dry_run ): array {
        $capability_id  = $this->require_string( $args, 'capability_id' );
        $blueprint_json = $args['blueprint_json'] ?? '';

        if ( ! $capability_id ) {
            return $this->err( 'MISSING_PARAM', 'capability_id is required.', 'validation_failed' );
        }
        if ( ! class_exists( 'CLAUTRON_Catalog' ) ) {
            return $this->err( 'CLAUTRON_NOT_LOADED', 'CLAUTRON_Catalog not available.', 'error' );
        }

        if ( $dry_run ) {
            $cap       = null;
            $active    = CLAUTRON_Catalog::get_active();
            foreach ( $active as $a ) {
                if ( $a['capability_id'] === $capability_id ) {
                    $cap = $a;
                    break;
                }
            }
            $in_templates = false;
            foreach ( CLAUTRON_Catalog::get_templates() as $t ) {
                if ( $t['capability_id'] === $capability_id ) {
                    $in_templates = true;
                    break;
                }
            }
            return $this->ok( [
                'dry_run'          => true,
                'capability_id'    => $capability_id,
                'already_active'   => ! is_null( $cap ),
                'blueprint_id'     => $cap ? $cap['blueprint_id'] : null,
                'template_exists'  => $in_templates,
                'would_create'     => ! $cap && ! $in_templates && (bool) $blueprint_json,
            ] );
        }

        $result = CLAUTRON_Catalog::ensure( $capability_id, $blueprint_json );

        if ( ! ( $result['success'] ?? false ) ) {
            return $this->err( 'ENSURE_FAILED', $result['error'] ?? 'Unknown error.', 'error' );
        }

        return $this->ok( $result, [
            'target_type' => 'clautron_blueprint',
            'target_id'   => $result['blueprint_id'] ?? 0,
            'summary'     => [ 'action' => 'ensure', 'status' => $result['status'] ?? '', 'capability_id' => $capability_id ],
        ] );
    }

    // ── Events handlers ───────────────────────────────────────────────────────

    public function handle_events_types( array $args, array $key_record, bool $dry_run ): array {
        if ( ! class_exists( 'CLAUTRON_Event_Store' ) ) {
            return $this->err( 'CLAUTRON_NOT_LOADED', 'CLAUTRON_Event_Store not available.', 'error' );
        }

        $types = CLAUTRON_Event_Store::get_distinct_types();
        return $this->ok(
            [ 'event_types' => $types, 'count' => count( $types ) ],
            [ 'target_type' => 'clautron_events' ]
        );
    }

    public function handle_events_query( array $args, array $key_record, bool $dry_run ): array {
        if ( ! class_exists( 'CLAUTRON_Event_Store' ) ) {
            return $this->err( 'CLAUTRON_NOT_LOADED', 'CLAUTRON_Event_Store not available.', 'error' );
        }

        $event_type = $this->require_string( $args, 'event_type' );
        if ( ! $event_type ) {
            return $this->err( 'MISSING_PARAM', 'event_type is required.', 'validation_failed' );
        }

        $query_args = [ 'event_type' => $event_type ];
        foreach ( [ 'since', 'until', 'object_type', 'text_value', 'order' ] as $key ) {
            if ( isset( $args[ $key ] ) && $args[ $key ] !== '' ) {
                $query_args[ $key ] = $args[ $key ];
            }
        }
        if ( ! empty( $args['object_id'] ) ) {
            $query_args['object_id'] = (int) $args['object_id'];
        }
        if ( ! empty( $args['limit'] ) ) {
            $query_args['limit'] = (int) $args['limit'];
        }

        $rows = CLAUTRON_Event_Store::query( $query_args );
        return $this->ok(
            [ 'rows' => $rows, 'count' => count( $rows ) ],
            [ 'target_type' => 'clautron_events' ]
        );
    }

    public function handle_events_aggregate( array $args, array $key_record, bool $dry_run ): array {
        if ( ! class_exists( 'CLAUTRON_Event_Store' ) ) {
            return $this->err( 'CLAUTRON_NOT_LOADED', 'CLAUTRON_Event_Store not available.', 'error' );
        }

        $event_type = $this->require_string( $args, 'event_type' );
        if ( ! $event_type ) {
            return $this->err( 'MISSING_PARAM', 'event_type is required.', 'validation_failed' );
        }

        $agg_args = [ 'event_type' => $event_type ];
        foreach ( [ 'aggregate', 'group_by', 'since', 'until', 'object_type' ] as $key ) {
            if ( isset( $args[ $key ] ) && $args[ $key ] !== '' ) {
                $agg_args[ $key ] = $args[ $key ];
            }
        }
        if ( ! empty( $args['object_id'] ) ) {
            $agg_args['object_id'] = (int) $args['object_id'];
        }

        $result = CLAUTRON_Event_Store::aggregate( $agg_args );
        return $this->ok(
            [ 'result' => $result, 'count' => count( $result ) ],
            [ 'target_type' => 'clautron_events' ]
        );
    }
}
