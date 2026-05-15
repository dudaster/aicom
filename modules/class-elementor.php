<?php
/**
 * Elementor Ops module — operates programmatically on _elementor_data postmeta.
 * Dependency: Elementor plugin must be active.
 */
class AICOM_Module_Elementor extends AICOM_Module_Base {

    public function get_module_name(): string {
        return 'elementor';
    }

    public function register_tools(): void {
        $dep = 'elementor';

        $this->register( 'elementor.page.get_tree', [
            'class'           => 'read',
            'required_scopes' => [ 'manage.elementor' ],
            'dependency'      => $dep,
            'description'     => 'Get Elementor page structure as parsed JSON tree.',
            'input_schema'    => [
                'post_id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_get_tree' ],
        ] );

        $this->register( 'elementor.page.get_texts', [
            'class'           => 'read',
            'required_scopes' => [ 'manage.elementor' ],
            'dependency'      => $dep,
            'description'     => 'Extract all text fields (title, editor, button_text, etc.) from Elementor widgets.',
            'input_schema'    => [
                'post_id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_get_texts' ],
        ] );

        $this->register( 'elementor.widget.update_field', [
            'class'            => 'write',
            'required_scopes'  => [ 'manage.elementor' ],
            'dependency'       => $dep,
            'supports_dry_run' => true,
            'description'      => 'Update a specific field on a specific widget by widget_id.',
            'input_schema'     => [
                'post_id'   => [ 'type' => 'integer', 'required' => true ],
                'widget_id' => [ 'type' => 'string',  'required' => true, 'description' => 'Widget ID from get_tree or get_texts.' ],
                'field'     => [ 'type' => 'string',  'required' => true, 'description' => 'Field name (e.g. "title", "editor", "button_text").' ],
                'value'     => [ 'description' => 'New value for the field.' ],
            ],
            'handler'          => [ $this, 'handle_update_field' ],
        ] );

        $this->register( 'elementor.page.bulk_update_texts', [
            'class'            => 'write',
            'required_scopes'  => [ 'manage.elementor' ],
            'dependency'       => $dep,
            'supports_dry_run' => true,
            'description'      => 'Batch-update multiple text fields across the page in one call.',
            'input_schema'     => [
                'post_id' => [ 'type' => 'integer', 'required' => true ],
                'updates' => [ 'type' => 'array', 'required' => true, 'description' => 'Array of {widget_id, field, text} objects. Use "text" key (matches get_texts output); "value" also accepted.' ],
            ],
            'handler'          => [ $this, 'handle_bulk_update_texts' ],
        ] );

        $this->register( 'elementor.page.backup', [
            'class'           => 'write',
            'required_scopes' => [ 'manage.elementor', 'manage.backups' ],
            'dependency'      => $dep,
            'description'     => 'Backup _elementor_data for a post to the backups table.',
            'input_schema'    => [
                'post_id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_backup' ],
        ] );

        $this->register( 'elementor.page.restore', [
            'class'            => 'destructive',
            'required_scopes'  => [ 'manage.elementor', 'manage.backups' ],
            'dependency'       => $dep,
            'requires_confirm' => true,
            'description'      => 'Restore _elementor_data from a backup. Use target_post_id to copy structure to a different page.',
            'input_schema'     => [
                'backup_id'      => [ 'type' => 'integer', 'required' => true, 'description' => 'Backup ID from elementor.page.backup.' ],
                'target_post_id' => [ 'type' => 'integer', 'description' => 'Optional: restore to a different post (for copying/translating). Defaults to original post.' ],
                'confirm'        => [ 'type' => 'boolean', 'required' => true, 'description' => 'Must be true to execute.' ],
            ],
            'handler'          => [ $this, 'handle_restore' ],
        ] );

        $this->register( 'elementor.page.validate', [
            'class'           => 'read',
            'required_scopes' => [ 'manage.elementor' ],
            'dependency'      => $dep,
            'description'     => 'Validate Elementor JSON structure for a post. Returns element_count.',
            'input_schema'    => [
                'post_id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_validate' ],
        ] );

        $this->register( 'elementor.page.regenerate_assets', [
            'class'           => 'admin_sensitive',
            'required_scopes' => [ 'manage.elementor' ],
            'dependency'      => $dep,
            'description'     => 'Regenerate Elementor CSS/assets for a post (clears cached CSS).',
            'input_schema'    => [
                'post_id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_regenerate_assets' ],
        ] );

        $this->register( 'elementor.page.create_from_template', [
            'class'            => 'write',
            'required_scopes'  => [ 'manage.elementor', 'write.wp.posts' ],
            'dependency'       => $dep,
            'supports_dry_run' => true,
            'description'      => 'Create a new page by copying the Elementor data from a source page or template. Sets _elementor_data, _elementor_edit_mode, and _wp_page_template in one call. Returns new post ID, slug, preview URL, and admin edit URL.',
            'input_schema'     => [
                'source_post_id' => [ 'type' => 'integer', 'required' => true, 'description' => 'ID of the page or template to copy Elementor data from.' ],
                'title'          => [ 'type' => 'string',  'required' => true ],
                'slug'           => [ 'type' => 'string',  'description' => 'URL slug. Defaults to sanitized title if omitted.' ],
                'status'         => [ 'type' => 'string',  'default' => 'draft', 'description' => 'Post status: draft (default), publish, private.' ],
            ],
            'handler'          => [ $this, 'handle_create_from_template' ],
        ] );

        $this->register( 'elementor.template.set_conditions', [
            'class'            => 'write',
            'required_scopes'  => [ 'manage.elementor' ],
            'dependency'       => $dep,
            'supports_dry_run' => true,
            'description'      => 'Set Theme Builder display conditions for an elementor_library template. Updates _elementor_conditions meta AND the global elementor_pro_theme_builder_conditions option, then clears the conditions cache. Equivalent to saving conditions via Elementor UI.',
            'input_schema'     => [
                'post_id'       => [ 'type' => 'integer', 'required' => true, 'description' => 'ID of the elementor_library post.' ],
                'conditions'    => [ 'type' => 'array',   'required' => true, 'description' => 'Array of condition strings, e.g. ["include/general"] or ["include/page/123", "exclude/page/456"].' ],
                'template_type' => [ 'type' => 'string',  'description' => 'Template type: header, footer, single, archive, etc. Optional if _elementor_template_type meta is already set.' ],
            ],
            'handler'          => [ $this, 'handle_set_conditions' ],
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────

    public function handle_get_tree( array $args, array $key_record, bool $dry_run ): array {
        $post_id = $this->require_int( $args, 'post_id' );
        if ( ! $post_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter post_id is required', 'validation_failed' );
        }

        $data = $this->get_elementor_data( $post_id );
        if ( $data === null ) {
            return $this->err( 'NOT_FOUND', "No Elementor data for post $post_id", 'error', 404 );
        }

        return $this->ok(
            [ 'post_id' => $post_id, 'tree' => $data ],
            [ 'target_type' => 'elementor_page', 'target_id' => $post_id ]
        );
    }

    public function handle_get_texts( array $args, array $key_record, bool $dry_run ): array {
        $post_id = $this->require_int( $args, 'post_id' );
        if ( ! $post_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter post_id is required', 'validation_failed' );
        }

        $data = $this->get_elementor_data( $post_id );
        if ( $data === null ) {
            return $this->err( 'NOT_FOUND', "No Elementor data for post $post_id", 'error', 404 );
        }

        $texts = [];
        $this->extract_texts( $data, $texts );

        return $this->ok( [ 'post_id' => $post_id, 'texts' => $texts, 'count' => count( $texts ) ] );
    }

    public function handle_update_field( array $args, array $key_record, bool $dry_run ): array {
        $post_id   = $this->require_int( $args, 'post_id' );
        $widget_id = $this->require_string( $args, 'widget_id' );
        $field     = $this->require_string( $args, 'field' );
        $value     = $args['value'] ?? null;

        if ( ! $post_id || ! $widget_id || ! $field ) {
            return $this->err( 'MISSING_PARAM', 'Parameters post_id, widget_id, and field are required', 'validation_failed' );
        }

        $data = $this->get_elementor_data( $post_id );
        if ( $data === null ) {
            return $this->err( 'NOT_FOUND', "No Elementor data for post $post_id", 'error', 404 );
        }

        $updated = false;
        $this->update_widget_field( $data, $widget_id, $field, $value, $updated );

        if ( ! $updated ) {
            return $this->err( 'NOT_FOUND', "Widget $widget_id not found in page tree", 'error', 404 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_update' => [ 'widget_id' => $widget_id, 'field' => $field ] ] );
        }

        $saved = $this->save_elementor_data( $post_id, $data );
        if ( ! $saved ) {
            return $this->err( 'ENCODE_ERROR', "Failed to JSON-encode Elementor data for post $post_id — data not saved", 'error' );
        }

        return $this->ok(
            [ 'post_id' => $post_id, 'widget_id' => $widget_id, 'field' => $field, 'updated' => true ],
            [ 'target_type' => 'elementor_widget', 'target_id' => $post_id ]
        );
    }

    public function handle_bulk_update_texts( array $args, array $key_record, bool $dry_run ): array {
        $post_id = $this->require_int( $args, 'post_id' );
        $updates = (array) ( $args['updates'] ?? [] ); // [['widget_id'=>..,'field'=>..,'value'=>..]]

        if ( ! $post_id || empty( $updates ) ) {
            return $this->err( 'MISSING_PARAM', 'Parameters post_id and updates[] are required', 'validation_failed' );
        }

        $data = $this->get_elementor_data( $post_id );
        if ( $data === null ) {
            return $this->err( 'NOT_FOUND', "No Elementor data for post $post_id", 'error', 404 );
        }

        $results = [];
        foreach ( $updates as $update ) {
            $widget_id = (string) ( $update['widget_id'] ?? '' );
            $field     = (string) ( $update['field'] ?? '' );
            // Accept both 'text' (matches get_texts output) and 'value' as key names.
            $value     = $update['text'] ?? $update['value'] ?? null;

            if ( ! $widget_id || ! $field ) {
                $results[] = [ 'widget_id' => $widget_id, 'success' => false, 'reason' => 'missing widget_id or field' ];
                continue;
            }

            $updated = false;
            $this->update_widget_field( $data, $widget_id, $field, $value, $updated );
            $results[] = [ 'widget_id' => $widget_id, 'field' => $field, 'success' => $updated ];
        }

        if ( ! $dry_run ) {
            $saved = $this->save_elementor_data( $post_id, $data );
            if ( ! $saved ) {
                return $this->err( 'ENCODE_ERROR', "Failed to JSON-encode Elementor data for post $post_id — data not saved", 'error' );
            }
        }

        return $this->ok(
            [ 'post_id' => $post_id, 'dry_run' => $dry_run, 'results' => $results ],
            [ 'target_type' => 'elementor_page', 'target_id' => $post_id, 'summary' => [ 'updates' => count( $updates ) ] ]
        );
    }

    public function handle_backup( array $args, array $key_record, bool $dry_run ): array {
        global $wpdb;
        $post_id = $this->require_int( $args, 'post_id' );
        if ( ! $post_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter post_id is required', 'validation_failed' );
        }

        $raw = get_post_meta( $post_id, '_elementor_data', true );
        if ( ! $raw ) {
            return $this->err( 'NOT_FOUND', "No Elementor data for post $post_id", 'error', 404 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_backup_post_id' => $post_id ] );
        }

        $wpdb->insert(
            $wpdb->prefix . 'aicom_backups',
            [
                'created_at'   => current_time( 'mysql', true ),
                'api_key_id'   => (int) $key_record['id'],
                'tool_name'    => 'elementor.page.backup',
                'target_type'  => 'elementor_page',
                'target_id'    => (string) $post_id,
                'manifest_json'=> wp_json_encode( [ 'post_id' => $post_id, 'meta_key' => '_elementor_data' ] ),
                'payload_json' => $raw,
            ]
        );

        $backup_id = (int) $wpdb->insert_id;

        return $this->ok(
            [ 'backup_id' => $backup_id, 'post_id' => $post_id ],
            [ 'target_type' => 'elementor_page', 'target_id' => $post_id ]
        );
    }

    public function handle_restore( array $args, array $key_record, bool $dry_run ): array {
        global $wpdb;
        $backup_id = $this->require_int( $args, 'backup_id' );
        if ( ! $backup_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter backup_id is required', 'validation_failed' );
        }

        $backup = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aicom_backups WHERE id = %d AND target_type = 'elementor_page'", $backup_id ),
            ARRAY_A
        );

        if ( ! $backup ) {
            return $this->err( 'NOT_FOUND', "Backup $backup_id not found", 'error', 404 );
        }

        // Optional: restore to a different post (e.g. copy structure to a translation page)
        $target_post_id = isset( $args['target_post_id'] ) ? (int) $args['target_post_id'] : (int) $backup['target_id'];

        $target_post = get_post( $target_post_id );
        if ( ! $target_post ) {
            return $this->err( 'NOT_FOUND', "Target post $target_post_id not found", 'error', 404 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_restore_backup_id' => $backup_id, 'target_post_id' => $target_post_id ] );
        }

        // wp_slash() counteracts update_post_meta's internal wp_unslash(),
        // preserving backslash sequences (e.g. escaped HTML attribute quotes in JSON).
        update_post_meta( $target_post_id, '_elementor_data', wp_slash( $backup['payload_json'] ) );
        update_post_meta( $target_post_id, '_elementor_edit_mode', 'builder' );

        return $this->ok(
            [ 'backup_id' => $backup_id, 'post_id' => $target_post_id, 'restored' => true ],
            [ 'target_type' => 'elementor_page', 'target_id' => $target_post_id ]
        );
    }

    public function handle_validate( array $args, array $key_record, bool $dry_run ): array {
        $post_id = $this->require_int( $args, 'post_id' );
        if ( ! $post_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter post_id is required', 'validation_failed' );
        }

        $raw = get_post_meta( $post_id, '_elementor_data', true );
        if ( ! $raw ) {
            return $this->ok( [ 'post_id' => $post_id, 'valid' => false, 'reason' => 'No _elementor_data found' ] );
        }

        $data = json_decode( $raw, true );
        $valid = json_last_error() === JSON_ERROR_NONE && is_array( $data );

        return $this->ok( [ 'post_id' => $post_id, 'valid' => $valid, 'element_count' => $valid ? count( $data ) : 0 ] );
    }

    public function handle_regenerate_assets( array $args, array $key_record, bool $dry_run ): array {
        $post_id = $this->require_int( $args, 'post_id' );
        if ( ! $post_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter post_id is required', 'validation_failed' );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_regenerate_for' => $post_id ] );
        }

        // Clear all Elementor caches for this post
        delete_post_meta( $post_id, '_elementor_css' );
        delete_post_meta( $post_id, '_elementor_element_cache' ); // Elementor Pro HTML cache
        delete_post_meta( $post_id, '_elementor_page_assets' );

        // If Elementor Pro is available, use its API to clear global file cache
        if ( class_exists( '\Elementor\Plugin' ) ) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }

        return $this->ok(
            [ 'post_id' => $post_id, 'regenerated' => true ],
            [ 'target_type' => 'elementor_page', 'target_id' => $post_id ]
        );
    }

    public function handle_create_from_template( array $args, array $key_record, bool $dry_run ): array {
        $source_id = $this->require_int( $args, 'source_post_id' );
        $title     = $this->require_string( $args, 'title' );

        if ( ! $source_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter source_post_id is required', 'validation_failed' );
        }
        if ( ! $title ) {
            return $this->err( 'MISSING_PARAM', 'Parameter title is required', 'validation_failed' );
        }

        if ( ! get_post( $source_id ) ) {
            return $this->err( 'NOT_FOUND', "Source post $source_id not found", 'error', 404 );
        }

        $elementor_data = get_post_meta( $source_id, '_elementor_data', true );
        if ( ! $elementor_data ) {
            return $this->err( 'INVALID_PARAM', "Source post $source_id has no Elementor data (_elementor_data is empty)", 'validation_failed' );
        }

        $edit_mode     = get_post_meta( $source_id, '_elementor_edit_mode', true ) ?: 'builder';
        $page_template = get_post_meta( $source_id, '_wp_page_template', true ) ?: 'default';
        $element_count = count( json_decode( $elementor_data, true ) ?: [] );

        if ( $dry_run ) {
            return $this->ok( [
                'dry_run'        => true,
                'source_post_id' => $source_id,
                'would_create'   => [ 'title' => $title, 'status' => $args['status'] ?? 'draft' ],
                'element_count'  => $element_count,
            ] );
        }

        $post_data = [
            'post_title'  => sanitize_text_field( $title ),
            'post_status' => sanitize_key( $args['status'] ?? 'draft' ),
            'post_type'   => 'page',
            'post_author' => (int) ( $key_record['created_by_user_id'] ?? 0 ),
        ];
        if ( ! empty( $args['slug'] ) ) {
            $post_data['post_name'] = sanitize_title( $args['slug'] );
        }

        $new_id = wp_insert_post( $post_data, true );
        if ( is_wp_error( $new_id ) ) {
            return $this->err( 'WP_ERROR', $new_id->get_error_message(), 'error', 500 );
        }

        update_post_meta( $new_id, '_elementor_data', $elementor_data );
        update_post_meta( $new_id, '_elementor_edit_mode', $edit_mode );
        update_post_meta( $new_id, '_wp_page_template', $page_template );
        delete_post_meta( $new_id, '_elementor_css' ); // force fresh CSS generation

        $new_post = get_post( $new_id );

        return $this->ok(
            [
                'id'            => $new_id,
                'title'         => $new_post->post_title,
                'slug'          => $new_post->post_name,
                'status'        => $new_post->post_status,
                'preview_url'   => get_preview_post_link( $new_id ),
                'edit_url'      => admin_url( "post.php?post=$new_id&action=elementor" ),
                'source_id'     => $source_id,
                'element_count' => $element_count,
            ],
            [ 'target_type' => 'post', 'target_id' => $new_id, 'summary' => [ 'created' => true ] ]
        );
    }

    public function handle_set_conditions( array $args, array $key_record, bool $dry_run ): array {
        $post_id       = $this->require_int( $args, 'post_id' );
        $conditions    = $args['conditions'] ?? null;
        $template_type = isset( $args['template_type'] ) ? sanitize_key( $args['template_type'] ) : '';

        if ( ! $post_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter post_id is required', 'validation_failed' );
        }
        if ( ! is_array( $conditions ) ) {
            return $this->err( 'MISSING_PARAM', 'Parameter conditions must be an array (e.g. ["include/general"])', 'validation_failed' );
        }

        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'elementor_library' ) {
            return $this->err( 'NOT_FOUND', "Post $post_id is not an elementor_library post", 'error', 404 );
        }

        // Resolve template_type from existing meta when not supplied
        if ( ! $template_type ) {
            $template_type = (string) get_post_meta( $post_id, '_elementor_template_type', true );
        }
        if ( ! $template_type ) {
            return $this->err( 'MISSING_PARAM', 'template_type is required (or set _elementor_template_type meta first)', 'validation_failed' );
        }

        if ( $dry_run ) {
            return $this->ok( [
                'dry_run'       => true,
                'would_set'     => [
                    'post_id'       => $post_id,
                    'template_type' => $template_type,
                    'conditions'    => $conditions,
                ],
            ] );
        }

        // Persist template type if it was supplied explicitly
        if ( isset( $args['template_type'] ) ) {
            update_post_meta( $post_id, '_elementor_template_type', $template_type );
        }
        update_post_meta( $post_id, '_elementor_conditions', $conditions );

        // Try Elementor Pro Conditions_Manager API (handles cache + option rebuild internally)
        $method = 'manual';
        if ( class_exists( '\ElementorPro\Plugin' ) ) {
            try {
                $tb = \ElementorPro\Plugin::instance()->modules_manager->get_modules( 'theme-builder' );
                if ( $tb && method_exists( $tb, 'get_conditions_manager' ) ) {
                    $cm = $tb->get_conditions_manager();
                    if ( $cm && method_exists( $cm, 'save_conditions' ) ) {
                        $cm->save_conditions( $post_id, $conditions );
                        $method = 'elementor_pro_api';
                    }
                }
            } catch ( \Throwable $e ) {
                // Fall through to manual rebuild
            }
        }

        if ( $method === 'manual' ) {
            $this->save_conditions_manual( $post_id, $template_type, $conditions );
        }

        return $this->ok(
            [
                'post_id'       => $post_id,
                'template_type' => $template_type,
                'conditions'    => $conditions,
                'method'        => $method,
            ],
            [ 'target_type' => 'elementor_template', 'target_id' => $post_id ]
        );
    }

    // ── Private Helpers ───────────────────────────────────────────────────

    private function get_elementor_data( int $post_id ): ?array {
        $raw = get_post_meta( $post_id, '_elementor_data', true );
        if ( ! $raw ) {
            return null;
        }
        $data = json_decode( $raw, true );
        return ( json_last_error() === JSON_ERROR_NONE && is_array( $data ) ) ? $data : null;
    }

    private function save_elementor_data( int $post_id, array $data ): bool {
        $encoded = wp_json_encode( $data );
        // Guard: if JSON encoding fails (e.g. invalid UTF-8), do NOT overwrite with empty value.
        if ( $encoded === false ) {
            return false;
        }
        // wp_slash() counteracts update_post_meta's internal wp_unslash(),
        // preserving backslash sequences in JSON (e.g. \", \n, \\).
        update_post_meta( $post_id, '_elementor_data', wp_slash( $encoded ) );
        update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
        // Clear all Elementor caches so the page re-renders with updated content.
        delete_post_meta( $post_id, '_elementor_css' );
        delete_post_meta( $post_id, '_elementor_element_cache' ); // Elementor Pro HTML cache
        delete_post_meta( $post_id, '_elementor_page_assets' );
        return true;
    }

    /**
     * Recursively extract texts from Elementor data tree.
     */
    private function extract_texts( array $elements, array &$texts, string $path = '' ): void {
        foreach ( $elements as $el ) {
            $id   = $el['id'] ?? 'unknown';
            $type = $el['widgetType'] ?? $el['elType'] ?? 'unknown';
            $settings = $el['settings'] ?? [];

            // Common text-bearing fields
            foreach ( [ 'title', 'text', 'editor', 'html', 'caption', 'description', 'button_text', 'link_text' ] as $field ) {
                if ( isset( $settings[ $field ] ) && is_string( $settings[ $field ] ) && $settings[ $field ] !== '' ) {
                    $texts[] = [
                        'widget_id'   => $id,
                        'widget_type' => $type,
                        'field'       => $field,
                        'text'        => $settings[ $field ],
                    ];
                }
            }

            // Recurse into children
            if ( ! empty( $el['elements'] ) ) {
                $this->extract_texts( $el['elements'], $texts, "$path/$id" );
            }
        }
    }

    /**
     * Recursively find widget by ID and update a field in settings.
     */
    private function update_widget_field( array &$elements, string $widget_id, string $field, $value, bool &$updated ): void {
        foreach ( $elements as &$el ) {
            if ( ( $el['id'] ?? '' ) === $widget_id ) {
                $el['settings'][ $field ] = $value;
                $updated = true;
                return;
            }
            if ( ! empty( $el['elements'] ) ) {
                $this->update_widget_field( $el['elements'], $widget_id, $field, $value, $updated );
                if ( $updated ) return;
            }
        }
    }

    /**
     * Manual conditions rebuild used when Elementor Pro API is unavailable.
     * Mirrors what Conditions_Manager::save_conditions() does internally:
     * removes the post from every type bucket, re-inserts under the correct
     * type, saves the option, and flushes all known cache locations.
     */
    private function save_conditions_manual( int $post_id, string $template_type, array $conditions ): void {
        $all = get_option( 'elementor_pro_theme_builder_conditions', [] );
        if ( ! is_array( $all ) ) {
            $all = [];
        }

        // Remove this template from every type bucket to prevent duplicates
        foreach ( $all as $type => &$map ) {
            if ( is_array( $map ) ) {
                unset( $map[ $post_id ] );
            }
        }
        unset( $map );

        // Insert under the resolved type
        if ( ! empty( $conditions ) ) {
            $all[ $template_type ][ $post_id ] = $conditions;
        }

        // Drop empty type buckets
        $all = array_filter( $all, fn( $v ) => ! empty( $v ) );

        update_option( 'elementor_pro_theme_builder_conditions', $all );

        // Clear all known conditions cache locations
        delete_option( 'elementor_pro_theme_builder_conditions_cache' );
        delete_transient( 'elementor_pro_theme_builder_conditions' );
        delete_transient( 'elementor_pro_theme_builder_conditions_cache' );

        if ( class_exists( '\Elementor\Plugin' ) ) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }
    }
}
