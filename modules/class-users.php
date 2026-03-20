<?php
/**
 * Users and Roles Ops module.
 * Includes anti-lockout guards to prevent removing the last admin.
 */
class ACL_Module_Users extends ACL_Module_Base {

    public function get_module_name(): string {
        return 'users';
    }

    public function register_tools(): void {
        // ── Users ──────────────────────────────────────────────────────────
        $this->register( 'wp.users.list', [
            'class'           => 'read',
            'required_scopes' => [ 'read.users' ],
            'description'     => 'List WordPress users with optional role filter.',
            'input_schema'    => [
                'number' => [ 'type' => 'integer', 'default' => 20 ],
                'role'   => [ 'type' => 'string' ],
                'search' => [ 'type' => 'string' ],
            ],
            'handler'         => [ $this, 'handle_users_list' ],
        ] );

        $this->register( 'wp.users.get', [
            'class'           => 'read',
            'required_scopes' => [ 'read.users' ],
            'description'     => 'Get a user by ID.',
            'input_schema'    => [
                'id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_users_get' ],
        ] );

        $this->register( 'wp.users.create', [
            'class'            => 'admin_sensitive',
            'required_scopes'  => [ 'manage.users' ],
            'supports_dry_run' => true,
            'description'      => 'Create a new WordPress user.',
            'input_schema'     => [
                'user_login'   => [ 'type' => 'string', 'required' => true ],
                'user_email'   => [ 'type' => 'string', 'required' => true ],
                'user_pass'    => [ 'type' => 'string' ],
                'display_name' => [ 'type' => 'string' ],
                'role'         => [ 'type' => 'string', 'default' => 'subscriber' ],
            ],
            'handler'          => [ $this, 'handle_users_create' ],
        ] );

        $this->register( 'wp.users.update', [
            'class'           => 'admin_sensitive',
            'required_scopes' => [ 'manage.users' ],
            'description'     => 'Update an existing user (email, display_name, role).',
            'input_schema'    => [
                'id'           => [ 'type' => 'integer', 'required' => true ],
                'user_email'   => [ 'type' => 'string' ],
                'display_name' => [ 'type' => 'string' ],
                'first_name'   => [ 'type' => 'string' ],
                'last_name'    => [ 'type' => 'string' ],
            ],
            'handler'         => [ $this, 'handle_users_update' ],
        ] );

        $this->register( 'wp.users.delete', [
            'class'            => 'destructive',
            'required_scopes'  => [ 'delete.users' ],
            'destructive'      => true,
            'requires_confirm' => true,
            'description'      => 'Delete a user (requires confirm=true; anti-lockout guard active).',
            'input_schema'     => [
                'id'          => [ 'type' => 'integer', 'required' => true ],
                'reassign_to' => [ 'type' => 'integer', 'description' => 'User ID to reassign posts to.' ],
                'confirm'     => [ 'type' => 'boolean', 'required' => true, 'description' => 'Must be true to execute.' ],
            ],
            'handler'          => [ $this, 'handle_users_delete' ],
        ] );

        $this->register( 'wp.users.set_role', [
            'class'            => 'admin_sensitive',
            'required_scopes'  => [ 'manage.users' ],
            'requires_confirm' => true,
            'description'      => 'Set a user\'s role (requires confirm=true; anti-lockout guard active).',
            'input_schema'     => [
                'id'      => [ 'type' => 'integer', 'required' => true ],
                'role'    => [ 'type' => 'string', 'required' => true ],
                'confirm' => [ 'type' => 'boolean', 'required' => true, 'description' => 'Must be true to execute.' ],
            ],
            'handler'          => [ $this, 'handle_users_set_role' ],
        ] );

        $this->register( 'wp.users.add_role', [
            'class'           => 'admin_sensitive',
            'required_scopes' => [ 'manage.users' ],
            'description'     => 'Add an additional role to a user.',
            'input_schema'    => [
                'id'   => [ 'type' => 'integer', 'required' => true ],
                'role' => [ 'type' => 'string', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_users_add_role' ],
        ] );

        $this->register( 'wp.users.remove_role', [
            'class'            => 'admin_sensitive',
            'required_scopes'  => [ 'manage.users' ],
            'requires_confirm' => true,
            'description'      => 'Remove a role from a user (requires confirm=true; anti-lockout guard active).',
            'input_schema'     => [
                'id'      => [ 'type' => 'integer', 'required' => true ],
                'role'    => [ 'type' => 'string', 'required' => true ],
                'confirm' => [ 'type' => 'boolean', 'required' => true, 'description' => 'Must be true to execute.' ],
            ],
            'handler'          => [ $this, 'handle_users_remove_role' ],
        ] );

        // ── Roles ──────────────────────────────────────────────────────────
        $this->register( 'wp.roles.list', [
            'class'           => 'discovery',
            'required_scopes' => [ 'manage.roles' ],
            'description'     => 'List all registered roles and their capabilities.',
            'input_schema'    => [],
            'handler'         => [ $this, 'handle_roles_list' ],
        ] );

        $this->register( 'wp.roles.create', [
            'class'           => 'admin_sensitive',
            'required_scopes' => [ 'manage.roles' ],
            'description'     => 'Create a custom role with specified capabilities.',
            'input_schema'    => [
                'slug'         => [ 'type' => 'string', 'required' => true ],
                'name'         => [ 'type' => 'string', 'required' => true ],
                'capabilities' => [ 'type' => 'array', 'description' => 'Map of cap => true.' ],
            ],
            'handler'         => [ $this, 'handle_roles_create' ],
        ] );

        $this->register( 'wp.roles.update_caps', [
            'class'            => 'admin_sensitive',
            'required_scopes'  => [ 'manage.roles' ],
            'requires_confirm' => true,
            'description'      => 'Add or remove capabilities on a role (requires confirm=true).',
            'input_schema'     => [
                'slug'        => [ 'type' => 'string', 'required' => true ],
                'add_caps'    => [ 'type' => 'array' ],
                'remove_caps' => [ 'type' => 'array' ],
                'confirm'     => [ 'type' => 'boolean', 'required' => true, 'description' => 'Must be true to execute.' ],
            ],
            'handler'          => [ $this, 'handle_roles_update_caps' ],
        ] );

        $this->register( 'wp.roles.delete', [
            'class'            => 'destructive',
            'required_scopes'  => [ 'manage.roles' ],
            'destructive'      => true,
            'requires_confirm' => true,
            'description'      => 'Delete a custom role (requires confirm=true; cannot delete built-in roles).',
            'input_schema'     => [
                'slug'    => [ 'type' => 'string', 'required' => true ],
                'confirm' => [ 'type' => 'boolean', 'required' => true, 'description' => 'Must be true to execute.' ],
            ],
            'handler'          => [ $this, 'handle_roles_delete' ],
        ] );
    }

    // ── User Handlers ─────────────────────────────────────────────────────

    public function handle_users_list( array $args, array $key_record, bool $dry_run ): array {
        $query_args = [
            'number' => min( (int) ( $args['number'] ?? 20 ), 200 ),
        ];

        if ( ! empty( $args['role'] ) ) {
            $query_args['role'] = sanitize_key( $args['role'] );
        }
        if ( ! empty( $args['search'] ) ) {
            $query_args['search'] = '*' . sanitize_text_field( $args['search'] ) . '*';
        }

        $users = get_users( $query_args );
        $result = [];

        foreach ( $users as $user ) {
            $result[] = $this->user_summary( $user );
        }

        return $this->ok( [ 'users' => $result, 'total' => count( $result ) ] );
    }

    public function handle_users_get( array $args, array $key_record, bool $dry_run ): array {
        $id = $this->require_int( $args, 'id' );
        if ( ! $id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter id is required', 'validation_failed' );
        }

        $user = get_user_by( 'ID', $id );
        if ( ! $user ) {
            return $this->err( 'NOT_FOUND', "User $id not found", 'error', 404 );
        }

        return $this->ok(
            array_merge( $this->user_summary( $user ), [
                'caps'           => $user->allcaps,
                'registered'     => $user->user_registered,
            ] ),
            [ 'target_type' => 'user', 'target_id' => $id ]
        );
    }

    public function handle_users_create( array $args, array $key_record, bool $dry_run ): array {
        $username = $this->require_string( $args, 'user_login' );
        $email    = $this->require_string( $args, 'user_email' );

        if ( ! $username || ! $email ) {
            return $this->err( 'MISSING_PARAM', 'Parameters user_login and user_email are required', 'validation_failed' );
        }

        if ( ! is_email( $email ) ) {
            return $this->err( 'INVALID_EMAIL', 'Invalid email address', 'validation_failed' );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_create_user' => $username ] );
        }

        $user_data = [
            'user_login'   => sanitize_user( $username ),
            'user_email'   => sanitize_email( $email ),
            'user_pass'    => $args['user_pass'] ?? wp_generate_password( 16 ),
            'display_name' => sanitize_text_field( $args['display_name'] ?? $username ),
            'role'         => sanitize_key( $args['role'] ?? 'subscriber' ),
        ];

        $id = wp_insert_user( $user_data );
        if ( is_wp_error( $id ) ) {
            return $this->err( 'WP_ERROR', $id->get_error_message(), 'error', 500 );
        }

        return $this->ok(
            [ 'id' => $id, 'user_login' => $username ],
            [ 'target_type' => 'user', 'target_id' => $id, 'summary' => [ 'created' => true ] ]
        );
    }

    public function handle_users_update( array $args, array $key_record, bool $dry_run ): array {
        $id = $this->require_int( $args, 'id' );
        if ( ! $id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter id is required', 'validation_failed' );
        }

        if ( ! get_user_by( 'ID', $id ) ) {
            return $this->err( 'NOT_FOUND', "User $id not found", 'error', 404 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_update_user_id' => $id ] );
        }

        $data = [ 'ID' => $id ];
        if ( isset( $args['user_email'] ) )   $data['user_email']   = sanitize_email( $args['user_email'] );
        if ( isset( $args['display_name'] ) ) $data['display_name'] = sanitize_text_field( $args['display_name'] );
        if ( isset( $args['first_name'] ) )   $data['first_name']   = sanitize_text_field( $args['first_name'] );
        if ( isset( $args['last_name'] ) )    $data['last_name']    = sanitize_text_field( $args['last_name'] );

        $result = wp_update_user( $data );
        if ( is_wp_error( $result ) ) {
            return $this->err( 'WP_ERROR', $result->get_error_message(), 'error', 500 );
        }

        return $this->ok(
            [ 'id' => $id, 'updated' => true ],
            [ 'target_type' => 'user', 'target_id' => $id ]
        );
    }

    public function handle_users_delete( array $args, array $key_record, bool $dry_run ): array {
        $id = $this->require_int( $args, 'id' );
        if ( ! $id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter id is required', 'validation_failed' );
        }

        $user = get_user_by( 'ID', $id );
        if ( ! $user ) {
            return $this->err( 'NOT_FOUND', "User $id not found", 'error', 404 );
        }

        // Anti-lockout guard: cannot delete the last administrator
        if ( $this->is_last_admin( $id ) ) {
            return $this->err( 'ANTI_LOCKOUT', 'Cannot delete the last administrator account', 'validation_failed', 409 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_delete_user_id' => $id ] );
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';
        $reassign_to = $this->require_int( $args, 'reassign_to' ) ?: 1;
        wp_delete_user( $id, $reassign_to );

        return $this->ok(
            [ 'id' => $id, 'deleted' => true ],
            [ 'target_type' => 'user', 'target_id' => $id ]
        );
    }

    public function handle_users_set_role( array $args, array $key_record, bool $dry_run ): array {
        $id   = $this->require_int( $args, 'id' );
        $role = sanitize_key( $args['role'] ?? '' );

        if ( ! $id || ! $role ) {
            return $this->err( 'MISSING_PARAM', 'Parameters id and role are required', 'validation_failed' );
        }

        $user = get_user_by( 'ID', $id );
        if ( ! $user ) {
            return $this->err( 'NOT_FOUND', "User $id not found", 'error', 404 );
        }

        // Anti-lockout: if removing admin role from last admin
        if ( $role !== 'administrator' && $this->is_last_admin( $id ) ) {
            return $this->err( 'ANTI_LOCKOUT', 'Cannot remove administrator role from the last admin', 'validation_failed', 409 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_set_role' => $role ] );
        }

        $user->set_role( $role );
        return $this->ok(
            [ 'id' => $id, 'role' => $role, 'updated' => true ],
            [ 'target_type' => 'user', 'target_id' => $id ]
        );
    }

    public function handle_users_add_role( array $args, array $key_record, bool $dry_run ): array {
        $id   = $this->require_int( $args, 'id' );
        $role = sanitize_key( $args['role'] ?? '' );

        if ( ! $id || ! $role ) {
            return $this->err( 'MISSING_PARAM', 'Parameters id and role are required', 'validation_failed' );
        }

        $user = get_user_by( 'ID', $id );
        if ( ! $user ) {
            return $this->err( 'NOT_FOUND', "User $id not found", 'error', 404 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_add_role' => $role ] );
        }

        $user->add_role( $role );
        return $this->ok(
            [ 'id' => $id, 'role_added' => $role ],
            [ 'target_type' => 'user', 'target_id' => $id ]
        );
    }

    public function handle_users_remove_role( array $args, array $key_record, bool $dry_run ): array {
        $id   = $this->require_int( $args, 'id' );
        $role = sanitize_key( $args['role'] ?? '' );

        if ( ! $id || ! $role ) {
            return $this->err( 'MISSING_PARAM', 'Parameters id and role are required', 'validation_failed' );
        }

        $user = get_user_by( 'ID', $id );
        if ( ! $user ) {
            return $this->err( 'NOT_FOUND', "User $id not found", 'error', 404 );
        }

        if ( $role === 'administrator' && $this->is_last_admin( $id ) ) {
            return $this->err( 'ANTI_LOCKOUT', 'Cannot remove administrator role from the last admin', 'validation_failed', 409 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_remove_role' => $role ] );
        }

        $user->remove_role( $role );
        return $this->ok(
            [ 'id' => $id, 'role_removed' => $role ],
            [ 'target_type' => 'user', 'target_id' => $id ]
        );
    }

    // ── Role Handlers ─────────────────────────────────────────────────────

    public function handle_roles_list( array $args, array $key_record, bool $dry_run ): array {
        global $wp_roles;
        $roles  = $wp_roles->roles;
        $result = [];

        foreach ( $roles as $slug => $data ) {
            $result[] = [
                'slug'         => $slug,
                'name'         => $data['name'],
                'capabilities' => array_keys( array_filter( $data['capabilities'] ) ),
            ];
        }

        return $this->ok( [ 'roles' => $result ] );
    }

    public function handle_roles_create( array $args, array $key_record, bool $dry_run ): array {
        $slug  = sanitize_key( $args['slug'] ?? '' );
        $name  = $this->require_string( $args, 'name' );
        $caps  = (array) ( $args['capabilities'] ?? [] );

        if ( ! $slug || ! $name ) {
            return $this->err( 'MISSING_PARAM', 'Parameters slug and name are required', 'validation_failed' );
        }

        if ( get_role( $slug ) ) {
            return $this->err( 'ROLE_EXISTS', "Role $slug already exists", 'validation_failed' );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_create_role' => $slug ] );
        }

        $capabilities = [];
        foreach ( $caps as $cap ) {
            $capabilities[ sanitize_key( $cap ) ] = true;
        }

        add_role( $slug, sanitize_text_field( $name ), $capabilities );
        return $this->ok(
            [ 'slug' => $slug, 'created' => true ],
            [ 'target_type' => 'role', 'target_id' => $slug ]
        );
    }

    public function handle_roles_update_caps( array $args, array $key_record, bool $dry_run ): array {
        $slug    = sanitize_key( $args['slug'] ?? '' );
        $add     = (array) ( $args['add_caps'] ?? [] );
        $remove  = (array) ( $args['remove_caps'] ?? [] );

        if ( ! $slug ) {
            return $this->err( 'MISSING_PARAM', 'Parameter slug is required', 'validation_failed' );
        }

        $role = get_role( $slug );
        if ( ! $role ) {
            return $this->err( 'NOT_FOUND', "Role $slug not found", 'error', 404 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_update_caps_on_role' => $slug ] );
        }

        foreach ( $add as $cap ) {
            $role->add_cap( sanitize_key( $cap ) );
        }
        foreach ( $remove as $cap ) {
            $role->remove_cap( sanitize_key( $cap ) );
        }

        return $this->ok(
            [ 'slug' => $slug, 'caps_added' => $add, 'caps_removed' => $remove ],
            [ 'target_type' => 'role', 'target_id' => $slug ]
        );
    }

    public function handle_roles_delete( array $args, array $key_record, bool $dry_run ): array {
        $slug = sanitize_key( $args['slug'] ?? '' );
        if ( ! $slug ) {
            return $this->err( 'MISSING_PARAM', 'Parameter slug is required', 'validation_failed' );
        }

        // Cannot delete built-in roles
        $builtin = [ 'administrator', 'editor', 'author', 'contributor', 'subscriber' ];
        if ( in_array( $slug, $builtin, true ) ) {
            return $this->err( 'PROTECTED_ROLE', "Cannot delete built-in role: $slug", 'validation_failed', 409 );
        }

        if ( ! get_role( $slug ) ) {
            return $this->err( 'NOT_FOUND', "Role $slug not found", 'error', 404 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_delete_role' => $slug ] );
        }

        remove_role( $slug );
        return $this->ok(
            [ 'slug' => $slug, 'deleted' => true ],
            [ 'target_type' => 'role', 'target_id' => $slug ]
        );
    }

    // ── Private Helpers ───────────────────────────────────────────────────

    private function user_summary( WP_User $user ): array {
        return [
            'id'           => $user->ID,
            'user_login'   => $user->user_login,
            'display_name' => $user->display_name,
            'user_email'   => $user->user_email,
            'roles'        => $user->roles,
        ];
    }

    /**
     * Returns true if this user is the ONLY administrator remaining.
     */
    private function is_last_admin( int $user_id ): bool {
        $admins = get_users( [ 'role' => 'administrator', 'fields' => 'ID', 'number' => 2 ] );
        return count( $admins ) === 1 && (int) $admins[0] === $user_id;
    }
}
