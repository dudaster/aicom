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
     * MCP tools/list response format.
     */
    public static function to_mcp_list( array $active_modules ): array {
        $tools = self::get_for_modules( $active_modules );

        return array_values( array_map( static function ( array $meta ): array {
            return [
                'name'        => $meta['tool_name'],
                'description' => $meta['description'],
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => $meta['input_schema'],
                ],
            ];
        }, $tools ) );
    }
}
