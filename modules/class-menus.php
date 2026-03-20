<?php
/**
 * WordPress Menus module — nav menus and menu items.
 * Uses WP core APIs only; no plugin dependency.
 *
 * Scope: manage.menus
 *
 * Tool classes:
 *   discovery       → wp.menus.list, wp.menus.locations.get
 *   read            → wp.menus.get
 *   write           → wp.menus.create, wp.menus.rename,
 *                      wp.menus.items.add, wp.menus.items.update
 *   destructive     → wp.menus.items.remove (confirm=true)
 *   admin_sensitive → wp.menus.delete (confirm=true),
 *                      wp.menus.locations.set (confirm=true)
 */
class AICOM_Module_Menus extends AICOM_Module_Base {

    public function get_module_name(): string {
        return 'menus';
    }

    public function register_tools(): void {
        $scope = 'manage.menus';

        $this->register( 'wp.menus.list', [
            'class'           => 'discovery',
            'required_scopes' => [ $scope ],
            'description'     => 'List all registered nav menus.',
            'handler'         => [ $this, 'handle_list' ],
        ] );

        $this->register( 'wp.menus.get', [
            'class'           => 'read',
            'required_scopes' => [ $scope ],
            'description'     => 'Get a nav menu by ID or slug, including all items.',
            'input_schema'    => [
                'menu_id' => [ 'type' => 'integer', 'description' => 'Menu term ID' ],
                'slug'    => [ 'type' => 'string',  'description' => 'Menu slug (alternative to menu_id)' ],
            ],
            'handler'         => [ $this, 'handle_get' ],
        ] );

        $this->register( 'wp.menus.create', [
            'class'            => 'write',
            'required_scopes'  => [ $scope ],
            'supports_dry_run' => true,
            'description'      => 'Create a new nav menu.',
            'input_schema'     => [
                'name' => [ 'type' => 'string', 'required' => true, 'description' => 'Menu name' ],
            ],
            'handler'          => [ $this, 'handle_create' ],
        ] );

        $this->register( 'wp.menus.rename', [
            'class'           => 'write',
            'required_scopes' => [ $scope ],
            'description'     => 'Rename an existing nav menu.',
            'input_schema'    => [
                'menu_id' => [ 'type' => 'integer', 'required' => true ],
                'name'    => [ 'type' => 'string',  'required' => true ],
            ],
            'handler'         => [ $this, 'handle_rename' ],
        ] );

        $this->register( 'wp.menus.delete', [
            'class'            => 'admin_sensitive',
            'required_scopes'  => [ $scope ],
            'destructive'      => true,
            'requires_confirm' => true,
            'description'      => 'Delete a nav menu and all its items (requires confirm=true).',
            'input_schema'     => [
                'menu_id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'          => [ $this, 'handle_delete' ],
        ] );

        $this->register( 'wp.menus.items.add', [
            'class'            => 'write',
            'required_scopes'  => [ $scope ],
            'supports_dry_run' => true,
            'description'      => 'Add an item to a nav menu (post, page, term, or custom URL).',
            'input_schema'     => [
                'menu_id'         => [ 'type' => 'integer', 'required' => true ],
                'type'            => [ 'type' => 'string',  'required' => true,  'description' => 'post_type | taxonomy | custom' ],
                'object'          => [ 'type' => 'string',  'description' => 'Post type slug or taxonomy slug' ],
                'object_id'       => [ 'type' => 'integer', 'description' => 'Post ID or term ID (not needed for custom type)' ],
                'title'           => [ 'type' => 'string',  'description' => 'Override label (optional for post_type/taxonomy)' ],
                'url'             => [ 'type' => 'string',  'description' => 'URL (required for custom type)' ],
                'parent_item_id'  => [ 'type' => 'integer', 'description' => 'Parent nav_menu_item post ID (0 = top level)' ],
                'position'        => [ 'type' => 'integer', 'description' => 'Menu order position (0 = append)' ],
                'target'          => [ 'type' => 'string',  'description' => '_blank or empty' ],
                'css_classes'     => [ 'type' => 'array',   'description' => 'Additional CSS classes' ],
            ],
            'handler'          => [ $this, 'handle_items_add' ],
        ] );

        $this->register( 'wp.menus.items.update', [
            'class'            => 'write',
            'required_scopes'  => [ $scope ],
            'supports_dry_run' => true,
            'description'      => 'Update an existing nav menu item.',
            'input_schema'     => [
                'menu_id'        => [ 'type' => 'integer', 'required' => true ],
                'item_id'        => [ 'type' => 'integer', 'required' => true, 'description' => 'nav_menu_item post ID' ],
                'title'          => [ 'type' => 'string' ],
                'url'            => [ 'type' => 'string' ],
                'parent_item_id' => [ 'type' => 'integer' ],
                'position'       => [ 'type' => 'integer' ],
                'target'         => [ 'type' => 'string' ],
                'css_classes'    => [ 'type' => 'array' ],
                'attr_title'     => [ 'type' => 'string' ],
            ],
            'handler'          => [ $this, 'handle_items_update' ],
        ] );

        $this->register( 'wp.menus.items.remove', [
            'class'            => 'destructive',
            'required_scopes'  => [ $scope ],
            'destructive'      => true,
            'requires_confirm' => true,
            'description'      => 'Remove an item from a nav menu (requires confirm=true).',
            'input_schema'     => [
                'item_id' => [ 'type' => 'integer', 'required' => true, 'description' => 'nav_menu_item post ID' ],
            ],
            'handler'          => [ $this, 'handle_items_remove' ],
        ] );

        $this->register( 'wp.menus.locations.get', [
            'class'           => 'discovery',
            'required_scopes' => [ $scope ],
            'description'     => 'Get all registered theme menu locations and their assigned menus.',
            'handler'         => [ $this, 'handle_locations_get' ],
        ] );

        $this->register( 'wp.menus.locations.set', [
            'class'            => 'admin_sensitive',
            'required_scopes'  => [ $scope ],
            'requires_confirm' => true,
            'description'      => 'Assign a nav menu to a theme location (requires confirm=true).',
            'input_schema'     => [
                'location' => [ 'type' => 'string',  'required' => true, 'description' => 'Theme location slug' ],
                'menu_id'  => [ 'type' => 'integer', 'required' => true, 'description' => 'Menu term ID (0 = unassign)' ],
            ],
            'handler'          => [ $this, 'handle_locations_set' ],
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────

    public function handle_list( array $args, array $key_record, bool $dry_run ): array {
        $menus = wp_get_nav_menus();

        $result = [];
        foreach ( $menus as $menu ) {
            $result[] = $this->menu_summary( $menu );
        }

        return $this->ok( [ 'menus' => $result, 'total' => count( $result ) ] );
    }

    public function handle_get( array $args, array $key_record, bool $dry_run ): array {
        $menu = $this->resolve_menu( $args );
        if ( is_array( $menu ) && isset( $menu['error'] ) ) {
            return $menu;
        }

        $raw_items = wp_get_nav_menu_items( $menu->term_id );
        if ( $raw_items === false ) {
            $raw_items = [];
        }

        $items = [];
        foreach ( $raw_items as $item ) {
            $item    = wp_setup_nav_menu_item( $item );
            $items[] = $this->item_summary( $item );
        }

        return $this->ok(
            array_merge( $this->menu_summary( $menu ), [ 'items' => $items ] ),
            [ 'target_type' => 'nav_menu', 'target_id' => $menu->term_id ]
        );
    }

    public function handle_create( array $args, array $key_record, bool $dry_run ): array {
        $name = $this->require_string( $args, 'name' );
        if ( ! $name ) {
            return $this->err( 'MISSING_PARAM', 'Parameter name is required', 'validation_failed' );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_create_menu' => $name ] );
        }

        $menu_id = wp_create_nav_menu( sanitize_text_field( $name ) );
        if ( is_wp_error( $menu_id ) ) {
            return $this->err( 'WP_ERROR', $menu_id->get_error_message(), 'error', 500 );
        }

        $menu = wp_get_nav_menu_object( $menu_id );

        return $this->ok(
            $this->menu_summary( $menu ),
            [ 'target_type' => 'nav_menu', 'target_id' => $menu_id, 'summary' => [ 'created' => true ] ]
        );
    }

    public function handle_rename( array $args, array $key_record, bool $dry_run ): array {
        $menu_id = $this->require_int( $args, 'menu_id' );
        $name    = $this->require_string( $args, 'name' );

        if ( ! $menu_id || ! $name ) {
            return $this->err( 'MISSING_PARAM', 'Parameters menu_id and name are required', 'validation_failed' );
        }

        $menu = wp_get_nav_menu_object( $menu_id );
        if ( ! $menu ) {
            return $this->err( 'NOT_FOUND', "Menu $menu_id not found", 'error', 404 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_rename_menu_id' => $menu_id, 'new_name' => $name ] );
        }

        $result = wp_update_nav_menu_object( $menu_id, [ 'menu-name' => sanitize_text_field( $name ) ] );
        if ( is_wp_error( $result ) ) {
            return $this->err( 'WP_ERROR', $result->get_error_message(), 'error', 500 );
        }

        return $this->ok(
            [ 'menu_id' => $menu_id, 'name' => $name, 'updated' => true ],
            [ 'target_type' => 'nav_menu', 'target_id' => $menu_id ]
        );
    }

    public function handle_delete( array $args, array $key_record, bool $dry_run ): array {
        $menu_id = $this->require_int( $args, 'menu_id' );
        if ( ! $menu_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter menu_id is required', 'validation_failed' );
        }

        $menu = wp_get_nav_menu_object( $menu_id );
        if ( ! $menu ) {
            return $this->err( 'NOT_FOUND', "Menu $menu_id not found", 'error', 404 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_delete_menu_id' => $menu_id ] );
        }

        $result = wp_delete_nav_menu( $menu_id );
        if ( is_wp_error( $result ) || $result === false ) {
            return $this->err( 'WP_ERROR', is_wp_error( $result ) ? $result->get_error_message() : 'Failed to delete menu', 'error', 500 );
        }

        return $this->ok(
            [ 'menu_id' => $menu_id, 'deleted' => true ],
            [ 'target_type' => 'nav_menu', 'target_id' => $menu_id ]
        );
    }

    public function handle_items_add( array $args, array $key_record, bool $dry_run ): array {
        $menu_id = $this->require_int( $args, 'menu_id' );
        $type    = sanitize_key( $args['type'] ?? '' );

        if ( ! $menu_id || ! $type ) {
            return $this->err( 'MISSING_PARAM', 'Parameters menu_id and type are required', 'validation_failed' );
        }

        if ( ! in_array( $type, [ 'post_type', 'taxonomy', 'custom' ], true ) ) {
            return $this->err( 'INVALID_PARAM', "type must be post_type, taxonomy, or custom", 'validation_failed' );
        }

        if ( ! wp_get_nav_menu_object( $menu_id ) ) {
            return $this->err( 'NOT_FOUND', "Menu $menu_id not found", 'error', 404 );
        }

        // Validate type-specific required params
        if ( $type !== 'custom' && empty( $args['object_id'] ) ) {
            return $this->err( 'MISSING_PARAM', 'Parameter object_id is required for post_type and taxonomy items', 'validation_failed' );
        }
        if ( $type === 'custom' && empty( $args['url'] ) ) {
            return $this->err( 'MISSING_PARAM', 'Parameter url is required for custom items', 'validation_failed' );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_add_item_to_menu' => $menu_id, 'type' => $type ] );
        }

        $item_data = $this->build_item_data( $args );
        $item_id   = wp_update_nav_menu_item( $menu_id, 0, $item_data );

        if ( is_wp_error( $item_id ) ) {
            return $this->err( 'WP_ERROR', $item_id->get_error_message(), 'error', 500 );
        }

        return $this->ok(
            [
                'item_id' => $item_id,
                'menu_id' => $menu_id,
                'type'    => $args['type'] ?? '',
                'title'   => sanitize_text_field( $args['title'] ?? '' ),
                'url'     => esc_url_raw( $args['url'] ?? '' ),
            ],
            [ 'target_type' => 'nav_menu_item', 'target_id' => $item_id, 'summary' => [ 'created' => true ] ]
        );
    }

    public function handle_items_update( array $args, array $key_record, bool $dry_run ): array {
        $menu_id = $this->require_int( $args, 'menu_id' );
        $item_id = $this->require_int( $args, 'item_id' );

        if ( ! $menu_id || ! $item_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameters menu_id and item_id are required', 'validation_failed' );
        }

        // Verify item exists and belongs to this menu
        $existing = get_post( $item_id );
        if ( ! $existing || $existing->post_type !== 'nav_menu_item' ) {
            return $this->err( 'NOT_FOUND', "Menu item $item_id not found", 'error', 404 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_update_item_id' => $item_id ] );
        }

        // Load current item data and merge updates
        $current   = wp_setup_nav_menu_item( $existing );
        $item_data = [
            'menu-item-object-id'  => $current->object_id ?? 0,
            'menu-item-object'     => $current->object ?? '',
            'menu-item-parent-id'  => $current->menu_item_parent ?? 0,
            'menu-item-position'   => $current->menu_order ?? 0,
            'menu-item-type'       => $current->type ?? 'custom',
            'menu-item-title'      => $current->title ?? '',
            'menu-item-url'        => $current->url ?? '',
            'menu-item-target'     => $current->target ?? '',
            'menu-item-attr-title' => $current->attr_title ?? '',
            'menu-item-classes'    => implode( ' ', (array) ( $current->classes ?? [] ) ),
            'menu-item-xfn'        => $current->xfn ?? '',
            'menu-item-status'     => 'publish',
        ];

        if ( isset( $args['title'] ) )          $item_data['menu-item-title']      = sanitize_text_field( $args['title'] );
        if ( isset( $args['url'] ) )            $item_data['menu-item-url']        = esc_url_raw( $args['url'] );
        if ( isset( $args['parent_item_id'] ) ) $item_data['menu-item-parent-id']  = (int) $args['parent_item_id'];
        if ( isset( $args['position'] ) )       $item_data['menu-item-position']   = (int) $args['position'];
        if ( isset( $args['target'] ) )         $item_data['menu-item-target']     = in_array( $args['target'], [ '_blank', '' ], true ) ? $args['target'] : '';
        if ( isset( $args['attr_title'] ) )     $item_data['menu-item-attr-title'] = sanitize_text_field( $args['attr_title'] );
        if ( isset( $args['css_classes'] ) )    $item_data['menu-item-classes']    = implode( ' ', array_map( 'sanitize_html_class', (array) $args['css_classes'] ) );

        $result = wp_update_nav_menu_item( $menu_id, $item_id, $item_data );
        if ( is_wp_error( $result ) ) {
            return $this->err( 'WP_ERROR', $result->get_error_message(), 'error', 500 );
        }

        return $this->ok(
            [ 'item_id' => $item_id, 'menu_id' => $menu_id, 'updated' => true ],
            [ 'target_type' => 'nav_menu_item', 'target_id' => $item_id ]
        );
    }

    public function handle_items_remove( array $args, array $key_record, bool $dry_run ): array {
        $item_id = $this->require_int( $args, 'item_id' );
        if ( ! $item_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter item_id is required', 'validation_failed' );
        }

        $existing = get_post( $item_id );
        if ( ! $existing || $existing->post_type !== 'nav_menu_item' ) {
            return $this->err( 'NOT_FOUND', "Menu item $item_id not found", 'error', 404 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_remove_item_id' => $item_id ] );
        }

        $result = wp_delete_post( $item_id, true );
        if ( ! $result ) {
            return $this->err( 'WP_ERROR', 'Failed to remove menu item', 'error', 500 );
        }

        return $this->ok(
            [ 'item_id' => $item_id, 'removed' => true ],
            [ 'target_type' => 'nav_menu_item', 'target_id' => $item_id ]
        );
    }

    public function handle_locations_get( array $args, array $key_record, bool $dry_run ): array {
        // All registered locations from the active theme
        $registered = get_registered_nav_menus();
        // Current assignments: location_slug → menu_id
        $assigned = get_nav_menu_locations();

        $result = [];
        foreach ( $registered as $slug => $description ) {
            $menu_id   = $assigned[ $slug ] ?? 0;
            $menu_name = '';

            if ( $menu_id ) {
                $menu_obj  = wp_get_nav_menu_object( $menu_id );
                $menu_name = $menu_obj ? $menu_obj->name : '';
            }

            $result[] = [
                'location'    => $slug,
                'description' => $description,
                'menu_id'     => $menu_id ?: null,
                'menu_name'   => $menu_name ?: null,
            ];
        }

        return $this->ok( [ 'locations' => $result, 'total' => count( $result ) ] );
    }

    public function handle_locations_set( array $args, array $key_record, bool $dry_run ): array {
        $location = sanitize_key( $args['location'] ?? '' );
        $menu_id  = isset( $args['menu_id'] ) ? (int) $args['menu_id'] : null;

        if ( ! $location || $menu_id === null ) {
            return $this->err( 'MISSING_PARAM', 'Parameters location and menu_id are required', 'validation_failed' );
        }

        // Verify location exists
        $registered = get_registered_nav_menus();
        if ( ! array_key_exists( $location, $registered ) ) {
            return $this->err( 'NOT_FOUND', "Theme location not registered: $location", 'error', 404 );
        }

        // Verify menu exists (if not unassigning)
        if ( $menu_id > 0 && ! wp_get_nav_menu_object( $menu_id ) ) {
            return $this->err( 'NOT_FOUND', "Menu $menu_id not found", 'error', 404 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_assign' => [ 'location' => $location, 'menu_id' => $menu_id ] ] );
        }

        $current_locations                = get_nav_menu_locations();
        $current_locations[ $location ]   = $menu_id > 0 ? $menu_id : 0;
        set_theme_mod( 'nav_menu_locations', $current_locations );

        $action = $menu_id > 0 ? 'assigned' : 'unassigned';

        return $this->ok(
            [ 'location' => $location, 'menu_id' => $menu_id ?: null, $action => true ],
            [ 'target_type' => 'nav_menu_location', 'target_id' => $location ]
        );
    }

    // ── Private Helpers ───────────────────────────────────────────────────

    /**
     * Resolve menu from args: accepts menu_id (int) or slug (string).
     * Returns WP_Term or an err() array.
     */
    private function resolve_menu( array $args ): WP_Term|array {
        $menu_id = $this->require_int( $args, 'menu_id' );
        $slug    = sanitize_text_field( $args['slug'] ?? '' );

        if ( ! $menu_id && ! $slug ) {
            return $this->err( 'MISSING_PARAM', 'Parameter menu_id or slug is required', 'validation_failed' );
        }

        $menu = $menu_id
            ? wp_get_nav_menu_object( $menu_id )
            : wp_get_nav_menu_object( $slug );

        if ( ! $menu ) {
            $ref = $menu_id ?: $slug;
            return $this->err( 'NOT_FOUND', "Menu not found: $ref", 'error', 404 );
        }

        return $menu;
    }

    private function menu_summary( WP_Term $menu ): array {
        return [
            'id'    => $menu->term_id,
            'name'  => $menu->name,
            'slug'  => $menu->slug,
            'count' => (int) $menu->count,
        ];
    }

    private function item_summary( WP_Post $item ): array {
        return [
            'id'             => $item->ID,
            'title'          => $item->title,
            'url'            => $item->url,
            'type'           => $item->type,
            'object'         => $item->object,
            'object_id'      => (int) $item->object_id,
            'parent_item_id' => (int) $item->menu_item_parent,
            'position'       => (int) $item->menu_order,
            'target'         => $item->target,
            'attr_title'     => $item->attr_title,
            'css_classes'    => array_filter( (array) $item->classes ),
        ];
    }

    /**
     * Build the menu-item-* args array from handler arguments.
     */
    private function build_item_data( array $args ): array {
        $type      = sanitize_key( $args['type'] );
        $object_id = (int) ( $args['object_id'] ?? 0 );
        $object    = sanitize_key( $args['object'] ?? '' );

        // For custom type, derive the default object if not given
        if ( $type === 'custom' && ! $object ) {
            $object = 'custom';
        }

        $data = [
            'menu-item-type'      => $type,
            'menu-item-object'    => $object,
            'menu-item-object-id' => $object_id,
            'menu-item-parent-id' => (int) ( $args['parent_item_id'] ?? 0 ),
            'menu-item-position'  => (int) ( $args['position'] ?? 0 ),
            'menu-item-url'       => esc_url_raw( $args['url'] ?? '' ),
            'menu-item-target'    => in_array( $args['target'] ?? '', [ '_blank', '' ], true ) ? ( $args['target'] ?? '' ) : '',
            'menu-item-classes'   => implode( ' ', array_map( 'sanitize_html_class', (array) ( $args['css_classes'] ?? [] ) ) ),
            'menu-item-xfn'       => sanitize_text_field( $args['xfn'] ?? '' ),
            'menu-item-status'    => 'publish',
        ];

        // Title override (optional for post_type/taxonomy, required for custom)
        if ( ! empty( $args['title'] ) ) {
            $data['menu-item-title'] = sanitize_text_field( $args['title'] );
        }

        return $data;
    }
}
