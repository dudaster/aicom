<?php
/**
 * WooCommerce Ops module — products, categories, attributes, settings.
 * Dependency: WooCommerce must be active.
 */
class AICOM_Module_WooCommerce extends AICOM_Module_Base {

    public function get_module_name(): string {
        return 'woocommerce';
    }

    public function register_tools(): void {
        $dep = 'woocommerce';
        $rs  = 'manage.woocommerce';

        // ── Products ───────────────────────────────────────────────────────
        $this->register( 'wc.products.list', [
            'class'           => 'read',
            'required_scopes' => [ $rs . '.products' ],
            'dependency'      => $dep,
            'description'     => 'List WooCommerce products.',
            'input_schema'    => [
                'status'   => [ 'type' => 'string', 'default' => 'any' ],
                'per_page' => [ 'type' => 'integer', 'default' => 20 ],
                'paged'    => [ 'type' => 'integer', 'default' => 1 ],
                'search'   => [ 'type' => 'string' ],
            ],
            'handler'         => [ $this, 'handle_products_list' ],
        ] );

        $this->register( 'wc.products.get', [
            'class'           => 'read',
            'required_scopes' => [ $rs . '.products' ],
            'dependency'      => $dep,
            'description'     => 'Get a WooCommerce product by ID.',
            'input_schema'    => [
                'id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_products_get' ],
        ] );

        $this->register( 'wc.products.create', [
            'class'            => 'write',
            'required_scopes'  => [ $rs . '.products' ],
            'dependency'       => $dep,
            'supports_dry_run' => true,
            'description'      => 'Create a new WooCommerce product.',
            'input_schema'     => [
                'name'          => [ 'type' => 'string', 'required' => true ],
                'status'        => [ 'type' => 'string', 'default' => 'publish' ],
                'regular_price' => [ 'type' => 'string', 'description' => 'Price as string e.g. "29.99".' ],
                'description'   => [ 'type' => 'string' ],
                'sku'           => [ 'type' => 'string' ],
            ],
            'handler'          => [ $this, 'handle_products_create' ],
        ] );

        $this->register( 'wc.products.update', [
            'class'            => 'write',
            'required_scopes'  => [ $rs . '.products' ],
            'dependency'       => $dep,
            'supports_dry_run' => true,
            'description'      => 'Update an existing WooCommerce product.',
            'input_schema'     => [
                'id'            => [ 'type' => 'integer', 'required' => true ],
                'name'          => [ 'type' => 'string' ],
                'status'        => [ 'type' => 'string' ],
                'regular_price' => [ 'type' => 'string' ],
                'sale_price'    => [ 'type' => 'string' ],
                'description'   => [ 'type' => 'string' ],
                'sku'           => [ 'type' => 'string' ],
            ],
            'handler'          => [ $this, 'handle_products_update' ],
        ] );

        $this->register( 'wc.products.trash', [
            'class'           => 'destructive',
            'required_scopes' => [ $rs . '.products' ],
            'dependency'      => $dep,
            'description'     => 'Move a WooCommerce product to trash.',
            'input_schema'    => [
                'id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_products_trash' ],
        ] );

        $this->register( 'wc.products.restore', [
            'class'           => 'write',
            'required_scopes' => [ $rs . '.products' ],
            'dependency'      => $dep,
            'description'     => 'Restore a WooCommerce product from trash.',
            'input_schema'    => [
                'id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_products_restore' ],
        ] );

        $this->register( 'wc.products.delete', [
            'class'            => 'destructive',
            'required_scopes'  => [ $rs . '.products' ],
            'dependency'       => $dep,
            'destructive'      => true,
            'requires_confirm' => true,
            'description'      => 'Permanently delete a product (requires confirm=true).',
            'input_schema'     => [
                'id'      => [ 'type' => 'integer', 'required' => true ],
                'confirm' => [ 'type' => 'boolean', 'required' => true, 'description' => 'Must be true to execute.' ],
            ],
            'handler'          => [ $this, 'handle_products_delete' ],
        ] );

        $this->register( 'wc.products.set_price', [
            'class'           => 'admin_sensitive',
            'required_scopes' => [ $rs . '.products' ],
            'dependency'      => $dep,
            'description'     => 'Set regular_price and/or sale_price on a product.',
            'input_schema'    => [
                'id'            => [ 'type' => 'integer', 'required' => true ],
                'regular_price' => [ 'type' => 'string', 'required' => true ],
                'sale_price'    => [ 'type' => 'string' ],
            ],
            'handler'         => [ $this, 'handle_products_set_price' ],
        ] );

        $this->register( 'wc.products.set_stock', [
            'class'           => 'admin_sensitive',
            'required_scopes' => [ $rs . '.products' ],
            'dependency'      => $dep,
            'description'     => 'Set stock quantity and manage_stock on a product.',
            'input_schema'    => [
                'id'             => [ 'type' => 'integer', 'required' => true ],
                'manage_stock'   => [ 'type' => 'boolean' ],
                'stock_quantity' => [ 'type' => 'integer' ],
                'stock_status'   => [ 'type' => 'string', 'description' => 'instock | outofstock | onbackorder.' ],
            ],
            'handler'         => [ $this, 'handle_products_set_stock' ],
        ] );

        $this->register( 'wc.products.assign_terms', [
            'class'           => 'write',
            'required_scopes' => [ $rs . '.products' ],
            'dependency'      => $dep,
            'description'     => 'Assign taxonomy terms (categories, tags) to a product.',
            'input_schema'    => [
                'id'       => [ 'type' => 'integer', 'required' => true ],
                'taxonomy' => [ 'type' => 'string', 'required' => true, 'description' => 'product_cat or product_tag.' ],
                'term_ids' => [ 'type' => 'array', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_products_assign_terms' ],
        ] );

        // ── Categories ─────────────────────────────────────────────────────
        $this->register( 'wc.categories.list', [
            'class'           => 'read',
            'required_scopes' => [ $rs . '.products' ],
            'dependency'      => $dep,
            'description'     => 'List WooCommerce product categories.',
            'input_schema'    => [],
            'handler'         => [ $this, 'handle_categories_list' ],
        ] );

        $this->register( 'wc.categories.create', [
            'class'           => 'write',
            'required_scopes' => [ $rs . '.products' ],
            'dependency'      => $dep,
            'description'     => 'Create a WooCommerce product category.',
            'input_schema'    => [
                'name'        => [ 'type' => 'string', 'required' => true ],
                'description' => [ 'type' => 'string' ],
                'parent'      => [ 'type' => 'integer' ],
            ],
            'handler'         => [ $this, 'handle_categories_create' ],
        ] );

        $this->register( 'wc.categories.update', [
            'class'           => 'write',
            'required_scopes' => [ $rs . '.products' ],
            'dependency'      => $dep,
            'description'     => 'Update a WooCommerce product category.',
            'input_schema'    => [
                'term_id'     => [ 'type' => 'integer', 'required' => true ],
                'name'        => [ 'type' => 'string' ],
                'description' => [ 'type' => 'string' ],
            ],
            'handler'         => [ $this, 'handle_categories_update' ],
        ] );

        $this->register( 'wc.categories.delete', [
            'class'            => 'destructive',
            'required_scopes'  => [ $rs . '.products' ],
            'dependency'       => $dep,
            'requires_confirm' => true,
            'description'      => 'Delete a WooCommerce product category (requires confirm=true).',
            'input_schema'     => [
                'term_id' => [ 'type' => 'integer', 'required' => true ],
                'confirm' => [ 'type' => 'boolean', 'required' => true, 'description' => 'Must be true to execute.' ],
            ],
            'handler'          => [ $this, 'handle_categories_delete' ],
        ] );

        // ── Settings ───────────────────────────────────────────────────────
        $this->register( 'wc.settings.get', [
            'class'           => 'read',
            'required_scopes' => [ $rs . '.settings' ],
            'dependency'      => $dep,
            'description'     => 'Get a WooCommerce setting value (allowlist enforced).',
            'input_schema'    => [
                'key' => [ 'type' => 'string', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_settings_get' ],
        ] );

        $this->register( 'wc.settings.set', [
            'class'            => 'admin_sensitive',
            'required_scopes'  => [ $rs . '.settings' ],
            'dependency'       => $dep,
            'requires_confirm' => true,
            'description'      => 'Set a WooCommerce setting value (requires confirm=true, allowlist enforced).',
            'input_schema'     => [
                'key'     => [ 'type' => 'string', 'required' => true ],
                'value'   => [ 'description' => 'New value for the setting.' ],
                'confirm' => [ 'type' => 'boolean', 'required' => true, 'description' => 'Must be true to execute.' ],
            ],
            'handler'          => [ $this, 'handle_settings_set' ],
        ] );
    }

    // ── Product Handlers ──────────────────────────────────────────────────

    public function handle_products_list( array $args, array $key_record, bool $dry_run ): array {
        $query = new WP_Query( [
            'post_type'      => 'product',
            'post_status'    => sanitize_key( $args['status'] ?? 'publish' ),
            'posts_per_page' => min( (int) ( $args['posts_per_page'] ?? 20 ), 100 ),
            'paged'          => max( 1, (int) ( $args['paged'] ?? 1 ) ),
            's'              => sanitize_text_field( $args['search'] ?? '' ),
        ] );

        $products = [];
        foreach ( $query->posts as $post ) {
            $product    = wc_get_product( $post->ID );
            $products[] = $this->product_summary( $product );
        }

        return $this->ok( [ 'products' => $products, 'total' => (int) $query->found_posts ] );
    }

    public function handle_products_get( array $args, array $key_record, bool $dry_run ): array {
        $id = $this->require_int( $args, 'id' );
        if ( ! $id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter id is required', 'validation_failed' );
        }

        $product = wc_get_product( $id );
        if ( ! $product ) {
            return $this->err( 'NOT_FOUND', "Product $id not found", 'error', 404 );
        }

        return $this->ok(
            array_merge( $this->product_summary( $product ), [
                'description'       => $product->get_description(),
                'short_description' => $product->get_short_description(),
                'categories'        => $this->get_product_terms( $id, 'product_cat' ),
                'tags'              => $this->get_product_terms( $id, 'product_tag' ),
            ] ),
            [ 'target_type' => 'product', 'target_id' => $id ]
        );
    }

    public function handle_products_create( array $args, array $key_record, bool $dry_run ): array {
        $name = $this->require_string( $args, 'name' );
        if ( ! $name ) {
            return $this->err( 'MISSING_PARAM', 'Parameter name is required', 'validation_failed' );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_create_product' => $name ] );
        }

        $product = new WC_Product_Simple();
        $product->set_name( sanitize_text_field( $name ) );
        $product->set_status( sanitize_key( $args['status'] ?? 'draft' ) );
        if ( isset( $args['regular_price'] ) ) $product->set_regular_price( (string) $args['regular_price'] );
        if ( isset( $args['description'] ) )   $product->set_description( wp_kses_post( $args['description'] ) );
        if ( isset( $args['sku'] ) )           $product->set_sku( sanitize_text_field( $args['sku'] ) );
        $id = $product->save();

        return $this->ok(
            [ 'id' => $id, 'name' => $name ],
            [ 'target_type' => 'product', 'target_id' => $id, 'summary' => [ 'created' => true ] ]
        );
    }

    public function handle_products_update( array $args, array $key_record, bool $dry_run ): array {
        $id = $this->require_int( $args, 'id' );
        if ( ! $id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter id is required', 'validation_failed' );
        }

        $product = wc_get_product( $id );
        if ( ! $product ) {
            return $this->err( 'NOT_FOUND', "Product $id not found", 'error', 404 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_update_id' => $id ] );
        }

        if ( isset( $args['name'] ) )          $product->set_name( sanitize_text_field( $args['name'] ) );
        if ( isset( $args['status'] ) )        $product->set_status( sanitize_key( $args['status'] ) );
        if ( isset( $args['regular_price'] ) ) $product->set_regular_price( (string) $args['regular_price'] );
        if ( isset( $args['sale_price'] ) )    $product->set_sale_price( (string) $args['sale_price'] );
        if ( isset( $args['description'] ) )   $product->set_description( wp_kses_post( $args['description'] ) );
        if ( isset( $args['sku'] ) )           $product->set_sku( sanitize_text_field( $args['sku'] ) );
        $product->save();

        return $this->ok(
            [ 'id' => $id, 'updated' => true ],
            [ 'target_type' => 'product', 'target_id' => $id ]
        );
    }

    public function handle_products_trash( array $args, array $key_record, bool $dry_run ): array {
        $id = $this->require_int( $args, 'id' );
        if ( ! $id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter id is required', 'validation_failed' );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_trash_id' => $id ] );
        }

        wp_trash_post( $id );
        return $this->ok( [ 'id' => $id, 'trashed' => true ], [ 'target_type' => 'product', 'target_id' => $id ] );
    }

    public function handle_products_restore( array $args, array $key_record, bool $dry_run ): array {
        $id = $this->require_int( $args, 'id' );
        if ( ! $id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter id is required', 'validation_failed' );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_restore_id' => $id ] );
        }

        wp_untrash_post( $id );
        return $this->ok( [ 'id' => $id, 'restored' => true ], [ 'target_type' => 'product', 'target_id' => $id ] );
    }

    public function handle_products_delete( array $args, array $key_record, bool $dry_run ): array {
        $id = $this->require_int( $args, 'id' );
        if ( ! $id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter id is required', 'validation_failed' );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_delete_id' => $id ] );
        }

        $product = wc_get_product( $id );
        if ( ! $product ) {
            return $this->err( 'NOT_FOUND', "Product $id not found", 'error', 404 );
        }

        $product->delete( true );
        return $this->ok( [ 'id' => $id, 'deleted' => true ], [ 'target_type' => 'product', 'target_id' => $id ] );
    }

    public function handle_products_set_price( array $args, array $key_record, bool $dry_run ): array {
        $id = $this->require_int( $args, 'id' );
        if ( ! $id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter id is required', 'validation_failed' );
        }

        $product = wc_get_product( $id );
        if ( ! $product ) {
            return $this->err( 'NOT_FOUND', "Product $id not found", 'error', 404 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_update_price_on' => $id ] );
        }

        if ( isset( $args['regular_price'] ) ) {
            if ( ! is_numeric( $args['regular_price'] ) || (float) $args['regular_price'] < 0 ) {
                return $this->err( 'INVALID_PARAM', 'regular_price must be a non-negative number', 'validation_failed' );
            }
            $product->set_regular_price( (string) $args['regular_price'] );
        }
        if ( isset( $args['sale_price'] ) ) {
            if ( $args['sale_price'] !== '' && ( ! is_numeric( $args['sale_price'] ) || (float) $args['sale_price'] < 0 ) ) {
                return $this->err( 'INVALID_PARAM', 'sale_price must be a non-negative number or empty string', 'validation_failed' );
            }
            $product->set_sale_price( (string) $args['sale_price'] );
        }
        $product->save();

        return $this->ok(
            [ 'id' => $id, 'updated' => true ],
            [ 'target_type' => 'product', 'target_id' => $id, 'summary' => [ 'price_updated' => true ] ]
        );
    }

    public function handle_products_set_stock( array $args, array $key_record, bool $dry_run ): array {
        $id = $this->require_int( $args, 'id' );
        if ( ! $id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter id is required', 'validation_failed' );
        }

        $product = wc_get_product( $id );
        if ( ! $product ) {
            return $this->err( 'NOT_FOUND', "Product $id not found", 'error', 404 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_update_stock_on' => $id ] );
        }

        if ( isset( $args['manage_stock'] ) ) {
            $product->set_manage_stock( (bool) $args['manage_stock'] );
        }
        if ( isset( $args['stock_quantity'] ) ) {
            $qty = filter_var( $args['stock_quantity'], FILTER_VALIDATE_INT );
            if ( $qty === false ) {
                return $this->err( 'INVALID_PARAM', 'stock_quantity must be an integer', 'validation_failed' );
            }
            $product->set_stock_quantity( $qty );
        }
        if ( isset( $args['stock_status'] ) ) {
            $valid_statuses = [ 'instock', 'outofstock', 'onbackorder' ];
            if ( ! in_array( $args['stock_status'], $valid_statuses, true ) ) {
                return $this->err( 'INVALID_PARAM', 'stock_status must be one of: ' . implode( ', ', $valid_statuses ), 'validation_failed' );
            }
            $product->set_stock_status( $args['stock_status'] );
        }
        $product->save();

        return $this->ok(
            [ 'id' => $id, 'updated' => true ],
            [ 'target_type' => 'product', 'target_id' => $id, 'summary' => [ 'stock_updated' => true ] ]
        );
    }

    public function handle_products_assign_terms( array $args, array $key_record, bool $dry_run ): array {
        $id       = $this->require_int( $args, 'id' );
        $taxonomy = sanitize_key( $args['taxonomy'] ?? 'product_cat' );
        $term_ids = array_map( 'intval', (array) ( $args['term_ids'] ?? [] ) );

        if ( ! $id || empty( $term_ids ) ) {
            return $this->err( 'MISSING_PARAM', 'Parameters id and term_ids are required', 'validation_failed' );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_assign_terms_to' => $id ] );
        }

        wp_set_object_terms( $id, $term_ids, $taxonomy );
        return $this->ok( [ 'id' => $id, 'assigned' => $term_ids ], [ 'target_type' => 'product', 'target_id' => $id ] );
    }

    // ── Category Handlers ─────────────────────────────────────────────────

    public function handle_categories_list( array $args, array $key_record, bool $dry_run ): array {
        $terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 200 ] );
        if ( is_wp_error( $terms ) ) {
            return $this->err( 'WP_ERROR', $terms->get_error_message(), 'error', 500 );
        }

        $result = [];
        foreach ( $terms as $term ) {
            $result[] = [ 'id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'count' => $term->count, 'parent' => $term->parent ];
        }

        return $this->ok( [ 'categories' => $result ] );
    }

    public function handle_categories_create( array $args, array $key_record, bool $dry_run ): array {
        $name = $this->require_string( $args, 'name' );
        if ( ! $name ) {
            return $this->err( 'MISSING_PARAM', 'Parameter name is required', 'validation_failed' );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_create_category' => $name ] );
        }

        $result = wp_insert_term( sanitize_text_field( $name ), 'product_cat', [
            'description' => sanitize_text_field( $args['description'] ?? '' ),
            'parent'      => (int) ( $args['parent'] ?? 0 ),
        ] );

        if ( is_wp_error( $result ) ) {
            return $this->err( 'WP_ERROR', $result->get_error_message(), 'error', 500 );
        }

        return $this->ok( [ 'term_id' => $result['term_id'], 'name' => $name ], [ 'target_type' => 'product_category', 'target_id' => $result['term_id'] ] );
    }

    public function handle_categories_update( array $args, array $key_record, bool $dry_run ): array {
        $term_id = $this->require_int( $args, 'term_id' );
        if ( ! $term_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter term_id is required', 'validation_failed' );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_update_category' => $term_id ] );
        }

        $data = [];
        if ( isset( $args['name'] ) )        $data['name']        = sanitize_text_field( $args['name'] );
        if ( isset( $args['description'] ) ) $data['description'] = sanitize_text_field( $args['description'] );

        $result = wp_update_term( $term_id, 'product_cat', $data );
        if ( is_wp_error( $result ) ) {
            return $this->err( 'WP_ERROR', $result->get_error_message(), 'error', 500 );
        }

        return $this->ok( [ 'term_id' => $term_id, 'updated' => true ], [ 'target_type' => 'product_category', 'target_id' => $term_id ] );
    }

    public function handle_categories_delete( array $args, array $key_record, bool $dry_run ): array {
        $term_id = $this->require_int( $args, 'term_id' );
        if ( ! $term_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter term_id is required', 'validation_failed' );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_delete_category' => $term_id ] );
        }

        $result = wp_delete_term( $term_id, 'product_cat' );
        if ( is_wp_error( $result ) || ! $result ) {
            return $this->err( 'WP_ERROR', 'Failed to delete category', 'error', 500 );
        }

        return $this->ok( [ 'term_id' => $term_id, 'deleted' => true ], [ 'target_type' => 'product_category', 'target_id' => $term_id ] );
    }

    // ── Settings Handlers ─────────────────────────────────────────────────

    public function handle_settings_get( array $args, array $key_record, bool $dry_run ): array {
        $key = $this->require_string( $args, 'key' );
        if ( ! $key ) {
            return $this->err( 'MISSING_PARAM', 'Parameter key is required', 'validation_failed' );
        }

        if ( ! AICOM_Policy_Engine::check_option_allowlist( $key_record, $key ) ) {
            return $this->err( 'DENIED_ALLOWLIST', "Option not in allowlist: $key", 'denied_allowlist', 403 );
        }

        $value = get_option( $key );
        return $this->ok( [ 'key' => $key, 'value' => $value ] );
    }

    public function handle_settings_set( array $args, array $key_record, bool $dry_run ): array {
        $key   = $this->require_string( $args, 'key' );
        $value = $args['value'] ?? null;

        if ( ! $key ) {
            return $this->err( 'MISSING_PARAM', 'Parameter key is required', 'validation_failed' );
        }

        if ( ! AICOM_Policy_Engine::check_option_allowlist( $key_record, $key ) ) {
            return $this->err( 'DENIED_ALLOWLIST', "Option not in allowlist: $key", 'denied_allowlist', 403 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_set_option' => $key ] );
        }

        update_option( $key, $value );
        return $this->ok( [ 'key' => $key, 'updated' => true ], [ 'target_type' => 'wc_setting', 'target_id' => $key ] );
    }

    // ── Private Helpers ───────────────────────────────────────────────────

    private function product_summary( WC_Product $product ): array {
        return [
            'id'            => $product->get_id(),
            'name'          => $product->get_name(),
            'sku'           => $product->get_sku(),
            'status'        => $product->get_status(),
            'type'          => $product->get_type(),
            'regular_price' => $product->get_regular_price(),
            'sale_price'    => $product->get_sale_price(),
            'stock_status'  => $product->get_stock_status(),
            'stock_quantity'=> $product->get_stock_quantity(),
            'manage_stock'  => $product->get_manage_stock(),
        ];
    }

    private function get_product_terms( int $product_id, string $taxonomy ): array {
        $terms = wp_get_post_terms( $product_id, $taxonomy, [ 'fields' => 'id=>name' ] );
        return is_array( $terms ) ? $terms : [];
    }
}
