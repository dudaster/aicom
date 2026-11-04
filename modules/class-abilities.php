<?php
/**
 * Abilities bridge module.
 *
 * Publishes WordPress Abilities (core Abilities API) as AICOM tools, so a
 * plugin that describes its own operations as abilities needs no AICOM module
 * of its own: its tools appear, change and disappear with the plugin, and
 * AICOM only keeps what is its job (keys, scopes, sessions, lock, policy,
 * audit). Nothing in here names a specific plugin.
 *
 * Which abilities: meta.public === true and a namespace listed in the
 * `aicom_ability_namespaces` option (default: clautron). Opt-in per namespace,
 * because publishing an ability makes it callable by AI agents.
 *
 * Ability → tool:
 *   name        "ns/a-b-c" → "ns.a.b.c"; meta.tool_name wins when it starts
 *               with "ns." (keeps an established id stable)
 *   class       annotations.readonly → read; annotations.destructive →
 *               destructive; otherwise write
 *   scopes      meta.scopes, else read.wp (read-only) / manage.<ns>. A scope
 *               AICOM doesn't know refuses the ability rather than publishing
 *               it unguarded — a plugin can't invent its own permission.
 *   dependency  the namespace, so the tool follows Module_Detector like any
 *               hand-written module's tools
 *   dry_run     supported when the input schema has a dry_run property
 *   reversible  meta.reversible === true (its undo step is always complete);
 *               the inventory then lists the tool as undoable up front
 *
 * Undo: when a call changes state the ability may return
 * `undo: { ability, input, complete }`. The bridge records it in the session's
 * backups (target_type ability_undo) and Restore session runs it, newest
 * first like every other backup. Only an ability in the same namespace is
 * accepted as the undo step, and it runs the same authorised way as a call.
 *
 * At call time, meta.scopes_resolver (a read-only ability in the same
 * namespace, same input) can name extra scopes the key must hold for this
 * particular input — e.g. those a capability's manifest declares. Then the
 * ability runs with permission granted through core's
 * wp_ability_permission_result filter for that one ability only: AICOM keys
 * carry no WordPress user, and by the time a handler runs the router has
 * already authorised the call.
 *
 * Tools registered here replace same-named tools of a hand-written module for
 * the same namespace (the legacy Clautron module): a plugin that starts
 * shipping abilities takes over its own tool surface without an AICOM
 * release, and with an older version of that plugin the legacy tools stay.
 *
 * Needs WordPress 7.1+ (wp_ability_permission_result).
 */
class AICOM_Module_Abilities extends AICOM_Module_Base {

    const OPTION             = 'aicom_ability_namespaces';
    const DEFAULT_NAMESPACES = [ 'clautron' ];

    public function get_module_name(): string {
        return 'abilities';
    }

    public function register_tools(): void {
        if ( ! function_exists( 'wp_get_abilities' ) || version_compare( (string) get_bloginfo( 'version' ), '7.1-alpha', '<' ) ) {
            return;
        }
        // The abilities registry refuses to load before init; AICOM looks tools
        // up per request, always after init.
        add_action( 'init', [ $this, 'publish' ], 100 );
    }

    public function publish(): void {
        $namespaces = self::namespaces();
        $known      = AICOM_Auth::scope_slugs();

        foreach ( wp_get_abilities() as $ability ) {
            $meta = self::tool_meta( $ability, $namespaces, $known );
            if ( ! $meta ) {
                continue;
            }
            $existing = AICOM_Tool_Registry::get( $meta['tool_name'] );
            if ( $existing && $existing['module'] !== $meta['module'] ) {
                continue; // never shadow a tool another module owns
            }
            $ability_name    = $ability->get_name();
            $tool_name       = $meta['tool_name'];
            $meta['handler'] = fn( array $args, array $key_record, bool $dry_run ): array => $this->run( $ability_name, $args, $key_record, $dry_run, $tool_name );
            AICOM_Tool_Registry::register( $meta['tool_name'], $meta );
        }
    }

    /** @return string[] */
    public static function namespaces(): array {
        $saved = get_option( self::OPTION, self::DEFAULT_NAMESPACES );
        return array_values( array_filter( array_map( 'sanitize_key', (array) $saved ) ) );
    }

    // ── Mapping (pure) ────────────────────────────────────────────────────

    /**
     * Tool registry entry for an ability, or null when it must not be
     * published. Handler not included; publish() binds it.
     */
    public static function tool_meta( WP_Ability $ability, array $namespaces, array $known_scopes ): ?array {
        $meta = $ability->get_meta();
        if ( true !== ( $meta['public'] ?? false ) ) {
            return null;
        }
        [ $ns, $local ] = explode( '/', $ability->get_name(), 2 );
        if ( ! in_array( $ns, $namespaces, true ) ) {
            return null;
        }

        $annotations = (array) ( $meta['annotations'] ?? [] );
        $readonly    = ! empty( $annotations['readonly'] );
        $class       = $readonly ? 'read' : ( ! empty( $annotations['destructive'] ) ? 'destructive' : 'write' );
        $scopes      = isset( $meta['scopes'] ) ? array_values( array_map( 'strval', (array) $meta['scopes'] ) ) : [ $readonly ? 'read.wp' : "manage.$ns" ];
        if ( ! $scopes || array_diff( $scopes, $known_scopes ) ) {
            return null;
        }

        $schema = (array) $ability->get_input_schema();

        return [
            'tool_name'        => self::tool_name( $ns, $local, $meta['tool_name'] ?? null ),
            'module'           => $ns,
            'dependency'       => $ns,
            'class'            => $class,
            'required_scopes'  => $scopes,
            'destructive'      => 'destructive' === $class,
            'reversible'       => ! $readonly && true === ( $meta['reversible'] ?? null ),
            'supports_dry_run' => isset( $schema['properties']['dry_run'] ),
            'description'      => (string) $ability->get_description(),
            'input_schema'     => self::input_schema( $schema ),
            'ability'          => $ability->get_name(),
        ];
    }

    public static function tool_name( string $ns, string $local, mixed $override ): string {
        if ( is_string( $override ) && str_starts_with( $override, "$ns." ) && preg_match( '/^[a-z0-9_.]+$/', $override ) ) {
            return $override;
        }
        return $ns . '.' . str_replace( '-', '.', $local );
    }

    /**
     * JSON Schema object → AICOM's flat input_schema (property => definition,
     * `required` as a per-property flag). dry_run is left to the router, which
     * adds its own for every tool that supports it.
     */
    public static function input_schema( array $schema ): array {
        $required = (array) ( $schema['required'] ?? [] );
        $out      = [];
        foreach ( (array) ( $schema['properties'] ?? [] ) as $prop => $def ) {
            if ( 'dry_run' === $prop ) {
                continue;
            }
            $out[ $prop ] = (array) $def + ( in_array( $prop, $required, true ) ? [ 'required' => true ] : [] );
        }
        return $out;
    }

    /** Tool arguments → ability input: only declared properties, dry_run from the router. */
    public static function ability_input( WP_Ability $ability, array $args, bool $dry_run ): ?array {
        $schema = (array) $ability->get_input_schema();
        if ( ! $schema ) {
            return null;
        }
        $props = (array) ( $schema['properties'] ?? [] );
        $input = array_intersect_key( $args, $props );
        if ( isset( $props['dry_run'] ) ) {
            $input['dry_run'] = $dry_run;
        }
        return $input;
    }

    // ── Execution ─────────────────────────────────────────────────────────

    public function run( string $ability_name, array $args, array $key_record, bool $dry_run, string $tool_name = '' ): array {
        $ability = wp_get_ability( $ability_name );
        if ( ! $ability ) {
            return $this->err( 'ABILITY_UNAVAILABLE', "$ability_name is no longer registered on this site.", 'error', 404 );
        }
        $input = self::ability_input( $ability, $args, $dry_run );

        $extra = $this->resolved_scopes( $ability, $input );
        if ( is_wp_error( $extra ) ) {
            return $this->from_wp_error( $extra );
        }
        $missing = AICOM_Auth::missing_scopes( $key_record, $extra );
        if ( $missing ) {
            return $this->err( 'DENIED_CAPABILITY_SCOPE', 'This action requires scopes the API key does not have: ' . implode( ', ', $missing ), 'denied_scope', 403 );
        }

        $result = self::execute_authorised( $ability, $input );
        if ( is_wp_error( $result ) ) {
            return $this->from_wp_error( $result );
        }

        $data = is_array( $result ) ? $result : [ 'result' => $result ];

        $undo = $dry_run ? null : self::valid_undo( $ability_name, $data['undo'] ?? null );
        if ( $undo ) {
            try {
                AICOM_Module_Backup::record_ability_undo( $tool_name ?: $ability_name, $undo, $key_record );
            } catch ( Throwable $e ) {
                // Same rule as the router's snapshots: the change already happened, report it anyway.
            }
        }

        return $this->ok( $data, [
            'target_type' => $ability_name,
            'target_id'   => $data['blueprint_id'] ?? $input['capability_id'] ?? $input['blueprint_id'] ?? $input['post_id'] ?? '',
            'summary'     => [ 'ability' => $ability_name, 'dry_run' => $dry_run ],
        ] );
    }

    /**
     * Extra scopes from meta.scopes_resolver. Only a read-only ability in the
     * same namespace is trusted as a resolver.
     *
     * @return string[]|WP_Error
     */
    private function resolved_scopes( WP_Ability $ability, ?array $input ): array|WP_Error {
        $resolver_name = (string) ( $ability->get_meta()['scopes_resolver'] ?? '' );
        if ( '' === $resolver_name || explode( '/', $resolver_name )[0] !== explode( '/', $ability->get_name() )[0] ) {
            return [];
        }
        $resolver = wp_get_ability( $resolver_name );
        if ( ! $resolver || empty( $resolver->get_meta()['annotations']['readonly'] ) ) {
            return [];
        }
        $resolver_input = $resolver->get_input_schema()
            ? array_intersect_key( (array) $input, (array) ( $resolver->get_input_schema()['properties'] ?? [] ) )
            : null;
        $out = self::execute_authorised( $resolver, $resolver_input );
        return is_wp_error( $out ) ? $out : array_values( array_filter( (array) ( $out['scopes'] ?? [] ), 'is_string' ) );
    }

    /**
     * The undo step an ability returned, normalised, or null when there is
     * none or it can't be trusted: it must name a registered ability in the
     * same namespace as the one that made the change.
     *
     * @return array{ability: string, input: array, complete: bool}|null
     */
    public static function valid_undo( string $ability_name, mixed $undo ): ?array {
        if ( ! is_array( $undo ) || ! is_string( $undo['ability'] ?? null ) || ! is_array( $undo['input'] ?? null ) ) {
            return null;
        }
        $namespace = explode( '/', $ability_name )[0];
        if ( explode( '/', $undo['ability'] )[0] !== $namespace || ! wp_get_ability( $undo['ability'] ) ) {
            return null;
        }
        return [ 'ability' => $undo['ability'], 'input' => $undo['input'], 'complete' => true === ( $undo['complete'] ?? null ) ];
    }

    /**
     * Restore session: run a recorded undo step. Re-checked at run time, since
     * the namespace may have been opted out or the plugin removed since.
     */
    public static function run_undo( array $payload ): bool {
        if ( ! function_exists( 'wp_get_ability' ) || ! is_string( $payload['ability'] ?? null ) ) {
            return false;
        }
        $ability = wp_get_ability( $payload['ability'] );
        if ( ! $ability || ! in_array( explode( '/', $payload['ability'] )[0], self::namespaces(), true ) ) {
            return false;
        }
        $input = self::ability_input( $ability, (array) ( $payload['input'] ?? [] ), false );
        return ! is_wp_error( self::execute_authorised( $ability, $input ) );
    }

    /** Run one ability with permission granted for exactly that ability, for exactly this call. */
    private static function execute_authorised( WP_Ability $ability, ?array $input ): mixed {
        $name  = $ability->get_name();
        $grant = static fn( $permission, string $ability_name ) => $ability_name === $name ? true : $permission;
        add_filter( 'wp_ability_permission_result', $grant, 10, 2 );
        try {
            return $ability->execute( $input );
        } finally {
            remove_filter( 'wp_ability_permission_result', $grant, 10 );
        }
    }

    /**
     * WP_Error → AICOM error. data.status is the HTTP code; data.details
     * (e.g. a Capability Contract envelope) is kept alongside the error.
     */
    private function from_wp_error( WP_Error $error ): array {
        $data   = (array) $error->get_error_data();
        $http   = (int) ( $data['status'] ?? 400 );
        $status = in_array( $http, [ 400, 422 ], true ) ? 'validation_failed' : ( 403 === $http ? 'denied_scope' : 'error' );
        $err    = $this->err( strtoupper( (string) $error->get_error_code() ), $error->get_error_message(), $status, $http );
        return is_array( $data['details'] ?? null ) ? array_merge( $data['details'], $err ) : $err;
    }
}
