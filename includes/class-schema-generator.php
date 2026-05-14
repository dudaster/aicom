<?php
defined( 'ABSPATH' ) || exit;

class AICOM_Schema_Generator {

    /**
     * Generate an OpenAPI 3.0 schema from all registered AICOM tools.
     *
     * @param array|null $allowed_scopes  When non-null, only tools whose required_scopes
     *                                    intersect this list are included (scope-filtered view).
     */
    public static function generate( ?array $allowed_scopes = null ): array {
        $tools = AICOM_Tool_Registry::get_all();
        $paths = [];

        foreach ( $tools as $name => $meta ) {
            // Skip internal meta-tools that aren't callable via REST (contain slashes)
            if ( strpos( $name, '/' ) !== false ) {
                continue;
            }

            if ( $allowed_scopes !== null ) {
                $required = $meta['required_scopes'] ?? [];
                if ( $required && ! array_intersect( $required, $allowed_scopes ) ) {
                    continue;
                }
            }

            $operation_id = str_replace( [ '.', '-' ], '_', $name );
            $properties   = [];
            $required_params = [];

            foreach ( $meta['input_schema'] ?? [] as $param => $schema ) {
                $prop = [ 'type' => $schema['type'] ?? 'string' ];
                if ( isset( $schema['description'] ) ) {
                    $prop['description'] = $schema['description'];
                }
                if ( isset( $schema['default'] ) ) {
                    $prop['default'] = $schema['default'];
                }
                if ( isset( $schema['enum'] ) ) {
                    $prop['enum'] = $schema['enum'];
                }
                $properties[ $param ] = $prop;

                if ( ! empty( $schema['required'] ) ) {
                    $required_params[] = $param;
                }
            }

            $request_schema = [ 'type' => 'object', 'properties' => $properties ];
            if ( $required_params ) {
                $request_schema['required'] = $required_params;
            }

            $description = $meta['description'] ?? $name;
            if ( ! empty( $meta['supports_dry_run'] ) ) {
                $description .= ' Supports dry_run (pass dry_run: true to preview without changes).';
            }

            $paths[ '/wp-json/aicom/v1/tools/' . $name ] = [
                'post' => [
                    'operationId' => $operation_id,
                    'summary'     => $meta['description'] ?? $name,
                    'description' => $description,
                    'tags'        => [ $meta['module'] ?? 'core' ],
                    'requestBody' => [
                        'required' => ! empty( $required_params ),
                        'content'  => [
                            'application/json' => [
                                'schema' => $request_schema,
                            ],
                        ],
                    ],
                    'responses' => [
                        '200' => [ 'description' => 'Tool result (errors also return HTTP 200; check body for error field)' ],
                    ],
                ],
            ];
        }

        return [
            'openapi' => '3.0.0',
            'info'    => [
                'title'       => 'AICOM - AI Commander',
                'version'     => AICOM_VERSION,
                'description' => 'WordPress MCP server. Manage content, media, users, WooCommerce, Elementor, Yoast SEO, accessibility, and more via AI agents. Import this schema into ChatGPT Custom GPT Actions, then set Authentication → API Key → Bearer with your AICOM key.',
            ],
            'servers'    => [ [ 'url' => get_site_url() ] ],
            'security'   => [ [ 'bearerAuth' => [] ] ],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type'        => 'http',
                        'scheme'      => 'bearer',
                        'description' => 'AICOM API key (format: aicom_XXXXXXXX_...)',
                    ],
                ],
            ],
            'paths' => $paths,
        ];
    }
}
