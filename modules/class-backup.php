<?php
/**
 * Backup and Restore Ops module.
 * Stores snapshots in wp_wpops_mcp_backups table.
 */
class WPOPS_Module_Backup extends WPOPS_Module_Base {

    public function get_module_name(): string {
        return 'backup';
    }

    public function register_tools(): void {
        $scope = 'manage.backups';

        $this->register( 'backup.list', [
            'class'           => 'read',
            'required_scopes' => [ $scope ],
            'description'     => 'List available backups with optional target_type filter.',
            'input_schema'    => [
                'target_type' => [ 'type' => 'string', 'description' => 'Filter by type: post, term, elementor_page.' ],
                'target_id'   => [ 'type' => 'string' ],
                'limit'       => [ 'type' => 'integer', 'default' => 20 ],
            ],
            'handler'         => [ $this, 'handle_list' ],
        ] );

        $this->register( 'backup.get_manifest', [
            'class'           => 'read',
            'required_scopes' => [ $scope ],
            'description'     => 'Get backup metadata/manifest by backup ID.',
            'input_schema'    => [
                'id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_get_manifest' ],
        ] );

        $this->register( 'backup.post.create', [
            'class'           => 'write',
            'required_scopes' => [ $scope ],
            'description'     => 'Create a backup of a post (content, meta, terms).',
            'input_schema'    => [
                'post_id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_post_create' ],
        ] );

        $this->register( 'backup.post.restore', [
            'class'            => 'destructive',
            'required_scopes'  => [ $scope ],
            'requires_confirm' => true,
            'description'      => 'Restore a post backup (requires confirm=true).',
            'input_schema'     => [
                'backup_id' => [ 'type' => 'integer', 'required' => true ],
                'confirm'   => [ 'type' => 'boolean', 'required' => true, 'description' => 'Must be true to execute.' ],
            ],
            'handler'          => [ $this, 'handle_post_restore' ],
        ] );

        $this->register( 'backup.term.create', [
            'class'           => 'write',
            'required_scopes' => [ $scope ],
            'description'     => 'Create a backup of a taxonomy term.',
            'input_schema'    => [
                'term_id'  => [ 'type' => 'integer', 'required' => true ],
                'taxonomy' => [ 'type' => 'string', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_term_create' ],
        ] );

        $this->register( 'backup.term.restore', [
            'class'            => 'destructive',
            'required_scopes'  => [ $scope ],
            'requires_confirm' => true,
            'description'      => 'Restore a term backup (requires confirm=true).',
            'input_schema'     => [
                'backup_id' => [ 'type' => 'integer', 'required' => true ],
                'confirm'   => [ 'type' => 'boolean', 'required' => true, 'description' => 'Must be true to execute.' ],
            ],
            'handler'          => [ $this, 'handle_term_restore' ],
        ] );

        $this->register( 'backup.delete', [
            'class'            => 'destructive',
            'required_scopes'  => [ $scope ],
            'requires_confirm' => true,
            'description'      => 'Delete a backup record from the database (requires confirm=true).',
            'input_schema'     => [
                'id'      => [ 'type' => 'integer', 'required' => true ],
                'confirm' => [ 'type' => 'boolean', 'required' => true, 'description' => 'Must be true to execute.' ],
            ],
            'handler'          => [ $this, 'handle_delete' ],
        ] );

        $this->register( 'backup.purge_old', [
            'class'            => 'admin_sensitive',
            'required_scopes'  => [ $scope ],
            'requires_confirm' => true,
            'description'      => 'Delete backups older than N days (requires confirm=true).',
            'input_schema'     => [
                'older_than_days' => [ 'type' => 'integer', 'default' => 30 ],
                'confirm'         => [ 'type' => 'boolean', 'required' => true, 'description' => 'Must be true to execute.' ],
            ],
            'handler'          => [ $this, 'handle_purge_old' ],
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────

    public function handle_list( array $args, array $key_record, bool $dry_run ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'wpops_mcp_backups';

        $where  = [ '1=1' ];
        $params = [];

        if ( ! empty( $args['target_type'] ) ) {
            $where[]  = 'target_type = %s';
            $params[] = sanitize_key( $args['target_type'] );
        }
        if ( ! empty( $args['target_id'] ) ) {
            $where[]  = 'target_id = %s';
            $params[] = (string) $args['target_id'];
        }

        $where_sql = implode( ' AND ', $where );
        $limit     = min( (int) ( $args['limit'] ?? 50 ), 200 );
        $params[]  = $limit;

        $sql  = "SELECT id, created_at, tool_name, target_type, target_id, manifest_json FROM $table WHERE $where_sql ORDER BY created_at DESC LIMIT %d";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );

        foreach ( $rows as &$row ) {
            $row['manifest'] = json_decode( $row['manifest_json'], true );
            unset( $row['manifest_json'] );
        }

        return $this->ok( [ 'backups' => $rows ?: [], 'count' => count( $rows ) ] );
    }

    public function handle_get_manifest( array $args, array $key_record, bool $dry_run ): array {
        global $wpdb;
        $id = $this->require_int( $args, 'id' );
        if ( ! $id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter id is required', 'validation_failed' );
        }

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT id, created_at, tool_name, target_type, target_id, manifest_json FROM {$wpdb->prefix}wpops_mcp_backups WHERE id = %d", $id ),
            ARRAY_A
        );

        if ( ! $row ) {
            return $this->err( 'NOT_FOUND', "Backup $id not found", 'error', 404 );
        }

        $row['manifest'] = json_decode( $row['manifest_json'], true );
        unset( $row['manifest_json'] );

        return $this->ok( $row );
    }

    public function handle_post_create( array $args, array $key_record, bool $dry_run ): array {
        global $wpdb;
        $post_id = $this->require_int( $args, 'post_id' );
        if ( ! $post_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter post_id is required', 'validation_failed' );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return $this->err( 'NOT_FOUND', "Post $post_id not found", 'error', 404 );
        }

        $meta  = get_post_meta( $post_id );
        $terms = [];
        foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
            $post_terms = wp_get_post_terms( $post_id, $taxonomy );
            if ( ! is_wp_error( $post_terms ) ) {
                $terms[ $taxonomy ] = array_map( fn( $t ) => $t->term_id, $post_terms );
            }
        }

        $payload = [
            'post'  => (array) $post,
            'meta'  => $meta,
            'terms' => $terms,
        ];

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_backup_post_id' => $post_id ] );
        }

        $wpdb->insert( $wpdb->prefix . 'wpops_mcp_backups', [
            'created_at'   => current_time( 'mysql', true ),
            'api_key_id'   => (int) $key_record['id'],
            'tool_name'    => 'backup.post.create',
            'target_type'  => 'post',
            'target_id'    => (string) $post_id,
            'manifest_json'=> wp_json_encode( [ 'post_id' => $post_id, 'post_type' => $post->post_type ] ),
            'payload_json' => wp_json_encode( $payload ),
        ] );

        $backup_id = (int) $wpdb->insert_id;

        return $this->ok(
            [ 'backup_id' => $backup_id, 'post_id' => $post_id ],
            [ 'target_type' => 'post', 'target_id' => $post_id, 'summary' => [ 'backup_created' => true ] ]
        );
    }

    public function handle_post_restore( array $args, array $key_record, bool $dry_run ): array {
        global $wpdb;
        $backup_id = $this->require_int( $args, 'backup_id' );
        if ( ! $backup_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter backup_id is required', 'validation_failed' );
        }

        $backup = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpops_mcp_backups WHERE id = %d AND target_type = 'post'", $backup_id ),
            ARRAY_A
        );

        if ( ! $backup ) {
            return $this->err( 'NOT_FOUND', "Backup $backup_id not found", 'error', 404 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_restore_backup_id' => $backup_id ] );
        }

        $payload = json_decode( $backup['payload_json'], true );
        if ( ! $payload ) {
            return $this->err( 'CORRUPT_BACKUP', 'Backup payload is invalid', 'error', 500 );
        }

        $post_id = (int) $backup['target_id'];

        // Restore post fields
        if ( ! empty( $payload['post'] ) ) {
            $post_data = $payload['post'];
            $post_data['ID'] = $post_id;
            unset( $post_data['post_modified'], $post_data['post_modified_gmt'] );
            wp_update_post( $post_data );
        }

        // Restore meta
        if ( ! empty( $payload['meta'] ) ) {
            foreach ( $payload['meta'] as $meta_key => $values ) {
                delete_post_meta( $post_id, $meta_key );
                foreach ( (array) $values as $val ) {
                    add_post_meta( $post_id, $meta_key, maybe_unserialize( $val ) );
                }
            }
        }

        // Restore terms
        if ( ! empty( $payload['terms'] ) ) {
            foreach ( $payload['terms'] as $taxonomy => $term_ids ) {
                wp_set_post_terms( $post_id, $term_ids, $taxonomy );
            }
        }

        return $this->ok(
            [ 'backup_id' => $backup_id, 'post_id' => $post_id, 'restored' => true ],
            [ 'target_type' => 'post', 'target_id' => $post_id ]
        );
    }

    public function handle_term_create( array $args, array $key_record, bool $dry_run ): array {
        global $wpdb;
        $term_id  = $this->require_int( $args, 'term_id' );
        $taxonomy = sanitize_key( $args['taxonomy'] ?? '' );

        if ( ! $term_id || ! $taxonomy ) {
            return $this->err( 'MISSING_PARAM', 'Parameters term_id and taxonomy are required', 'validation_failed' );
        }

        $term = get_term( $term_id, $taxonomy );
        if ( is_wp_error( $term ) || ! $term ) {
            return $this->err( 'NOT_FOUND', "Term $term_id not found", 'error', 404 );
        }

        $meta = get_term_meta( $term_id );

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_backup_term_id' => $term_id ] );
        }

        $wpdb->insert( $wpdb->prefix . 'wpops_mcp_backups', [
            'created_at'   => current_time( 'mysql', true ),
            'api_key_id'   => (int) $key_record['id'],
            'tool_name'    => 'backup.term.create',
            'target_type'  => 'term',
            'target_id'    => (string) $term_id,
            'manifest_json'=> wp_json_encode( [ 'term_id' => $term_id, 'taxonomy' => $taxonomy ] ),
            'payload_json' => wp_json_encode( [ 'term' => (array) $term, 'meta' => $meta ] ),
        ] );

        $backup_id = (int) $wpdb->insert_id;

        return $this->ok(
            [ 'backup_id' => $backup_id, 'term_id' => $term_id ],
            [ 'target_type' => 'term', 'target_id' => $term_id ]
        );
    }

    public function handle_term_restore( array $args, array $key_record, bool $dry_run ): array {
        global $wpdb;
        $backup_id = $this->require_int( $args, 'backup_id' );
        if ( ! $backup_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter backup_id is required', 'validation_failed' );
        }

        $backup = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpops_mcp_backups WHERE id = %d AND target_type = 'term'", $backup_id ),
            ARRAY_A
        );

        if ( ! $backup ) {
            return $this->err( 'NOT_FOUND', "Backup $backup_id not found", 'error', 404 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_restore_term_backup_id' => $backup_id ] );
        }

        $payload  = json_decode( $backup['payload_json'], true );
        $manifest = json_decode( $backup['manifest_json'], true );

        if ( ! $payload || ! $manifest ) {
            return $this->err( 'CORRUPT_BACKUP', 'Backup payload is invalid', 'error', 500 );
        }

        $term_id  = (int) $backup['target_id'];
        $taxonomy = $manifest['taxonomy'];

        if ( ! empty( $payload['term'] ) ) {
            $t = $payload['term'];
            wp_update_term( $term_id, $taxonomy, [
                'name'        => $t['name'] ?? '',
                'slug'        => $t['slug'] ?? '',
                'description' => $t['description'] ?? '',
                'parent'      => $t['parent'] ?? 0,
            ] );
        }

        if ( ! empty( $payload['meta'] ) ) {
            foreach ( $payload['meta'] as $meta_key => $values ) {
                delete_term_meta( $term_id, $meta_key );
                foreach ( (array) $values as $val ) {
                    add_term_meta( $term_id, $meta_key, maybe_unserialize( $val ) );
                }
            }
        }

        return $this->ok(
            [ 'backup_id' => $backup_id, 'term_id' => $term_id, 'restored' => true ],
            [ 'target_type' => 'term', 'target_id' => $term_id ]
        );
    }

    public function handle_delete( array $args, array $key_record, bool $dry_run ): array {
        global $wpdb;
        $id = $this->require_int( $args, 'id' );
        if ( ! $id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter id is required', 'validation_failed' );
        }

        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wpops_mcp_backups WHERE id = %d", $id ) );
        if ( ! $exists ) {
            return $this->err( 'NOT_FOUND', "Backup $id not found", 'error', 404 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_delete_backup_id' => $id ] );
        }

        $wpdb->delete( $wpdb->prefix . 'wpops_mcp_backups', [ 'id' => $id ] );
        return $this->ok( [ 'id' => $id, 'deleted' => true ] );
    }

    public function handle_purge_old( array $args, array $key_record, bool $dry_run ): array {
        global $wpdb;
        $days = max( 1, (int) ( $args['older_than_days'] ?? 30 ) );
        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-$days days" ) );

        if ( $dry_run ) {
            $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpops_mcp_backups WHERE created_at < %s", $cutoff ) );
            return $this->ok( [ 'dry_run' => true, 'would_delete_count' => $count ] );
        }

        $deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wpops_mcp_backups WHERE created_at < %s", $cutoff ) );
        return $this->ok(
            [ 'deleted_count' => (int) $deleted, 'cutoff_date' => $cutoff ],
            [ 'target_type' => 'backup_purge', 'target_id' => $cutoff, 'summary' => [ 'deleted' => $deleted ] ]
        );
    }
}
