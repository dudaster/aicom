<?php
/**
 * Tool Registry: single source of truth for all tool metadata.
 *
 * Each entry describes:
 *   tool_name        string   e.g. 'wp.posts.update'
 *   module           string   slug matching Module_Detector module keys
 *   class            string   public|discovery|read|write|destructive|admin_sensitive
 *   required_scopes  array    scope strings that the API key must possess
 *   destructive      bool
 *   admin_sensitive  bool
 *   supports_dry_run bool
 *   requires_confirm bool     caller must pass arguments.confirm = true
 *   dependency       ?string  null|'woocommerce'|'elementor'|'polylang'
 *   handler          callable [$module_instance, 'method_name']
 *   description      string   Human-readable for tools/list
 *   input_schema     array    JSON-Schema-like parameter definitions
 */
class AICOM_Tool_Registry {

    /** @var array<string, array> */
    private static array $tools = [];

    // ── Registration ──────────────────────────────────────────────────────

    public static function register( string $tool_name, array $meta ): void {
        $defaults = [
            'tool_name'        => $tool_name,
            'module'           => 'unknown',
            'class'            => 'read',
            'required_scopes'  => [],
            'destructive'      => false,
            'admin_sensitive'  => false,
            'supports_dry_run' => false,
            'requires_confirm' => false,
            'dependency'       => null,
            'handler'          => null,
            'description'      => '',
            'input_schema'     => [],
        ];

        self::$tools[ $tool_name ] = array_merge( $defaults, $meta, [ 'tool_name' => $tool_name ] );
    }

    // ── Lookup ────────────────────────────────────────────────────────────

    public static function get( string $tool_name ): ?array {
        return self::$tools[ $tool_name ] ?? null;
    }

    public static function exists( string $tool_name ): bool {
        return isset( self::$tools[ $tool_name ] );
    }

    public static function get_all(): array {
        return self::$tools;
    }

    /**
     * Filter to only tools whose dependency is in $active_modules.
     * Tools with dependency=null are always included.
     */
    public static function get_for_modules( array $active_modules ): array {
        return array_filter(
            self::$tools,
            static function ( array $meta ) use ( $active_modules ): bool {
                return $meta['dependency'] === null
                    || in_array( $meta['dependency'], $active_modules, true );
            }
        );
    }

    /**
     * MCP tools/list response format. When $key_record is given, tools the
     * key can't actually call (missing required scope, after implied-scope
     * expansion) are excluded entirely — a key without read.skills never
     * needed to see skills.list only to fail calling it, and every key gets
     * a shorter, cheaper-to-load tool list scoped to what it can really do.
     * Pass an empty $key_record (the default) to get the unfiltered list.
     */
    public static function to_mcp_list( array $active_modules, array $key_record = [] ): array {
        $tools = self::get_for_modules( $active_modules );

        if ( ! empty( $key_record ) ) {
            $tools = array_filter( $tools, static function ( array $meta ) use ( $key_record ): bool {
                return empty( AICOM_Auth::missing_scopes( $key_record, $meta['required_scopes'] ) );
            } );
        }

        $write_classes = [ 'write', 'destructive', 'admin_sensitive' ];

        return array_values( array_map( static function ( array $meta ) use ( $write_classes ): array {
            $props = [];
            foreach ( $meta['input_schema'] as $param => $def ) {
                $prop = $def;
                // Remove internal-only keys not part of JSON Schema
                unset( $prop['required'] );
                $props[ $param ] = $prop;
            }

            // dry_run and idempotency_key are accepted by the router for every
            // eligible tool (Step 9d) regardless of whether a tool's own
            // input_schema happens to mention them — but every schema also
            // declares additionalProperties:false, so a client that validates
            // its own outgoing request against the schema (not just the
            // response) would refuse to send either one if it's undocumented.
            // Inject them centrally here instead of hand-editing every write
            // tool's registration, so the schema always matches what the
            // router actually accepts.
            if ( $meta['supports_dry_run'] && ! isset( $props['dry_run'] ) ) {
                $props['dry_run'] = [ 'type' => 'boolean', 'description' => 'Validate and simulate without writing any data.' ];
            }
            if ( in_array( $meta['class'], $write_classes, true ) && ! isset( $props['idempotency_key'] ) ) {
                $props['idempotency_key'] = [ 'type' => 'string', 'description' => 'Optional. Repeating this exact call with the same key returns the original result instead of repeating the action — safe to retry after a dropped connection or timeout.' ];
            }

            $required = array_keys( array_filter(
                $meta['input_schema'],
                static fn( $d ) => ! empty( $d['required'] )
            ) );

            // Force an object even when empty: PHP's json_encode has no way to
            // tell an empty associative array from an empty list, and defaults
            // to emitting "[]" for either. A tool with no parameters
            // (input_schema = []) would then produce "properties": [] instead
            // of {} on the wire — a strict JSON-Schema client (Pydantic
            // dict_type validation) rejects the entire tools/list response
            // over exactly this, even though only the zero-parameter tools
            // are technically malformed. Same fix already applied to the
            // OpenAPI schema generator (class-schema-generator.php) for the
            // same reason — mirrored here for the MCP tools/list path.
            $schema = [
                'type'                 => 'object',
                'properties'           => empty( $props ) ? new stdClass() : $props,
                'additionalProperties' => false,
            ];
            if ( $required ) {
                $schema['required'] = $required;
            }

            // 'class' (AICOM's own public/discovery/read/write/destructive/admin_sensitive
            // taxonomy) is NOT part of the MCP Tool schema — a strict client validating
            // ListToolsResult with an extra='forbid' Pydantic model rejects the entire
            // list over one unexpected field. Map it onto the spec's own ToolAnnotations
            // hints instead, which exist for exactly this purpose. The router strips this
            // 'annotations' key entirely for protocol versions that don't support it yet
            // (e.g. 2024-11-05), so nothing version-specific reaches an older client either.
            $read_only_classes = [ 'public', 'discovery', 'read' ];
            $annotations = [
                'readOnlyHint'    => in_array( $meta['class'], $read_only_classes, true ),
                'destructiveHint' => $meta['class'] === 'destructive',
            ];

            return [
                'name'        => $meta['tool_name'],
                'description' => $meta['description'],
                'inputSchema' => $schema,
                'annotations' => $annotations,
            ];
        }, $tools ) );
    }
}
