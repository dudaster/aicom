<?php
/**
 * Media and Files Ops module.
 * Covers WordPress media library and controlled file operations.
 */
class ACL_Module_Media extends ACL_Module_Base {

    // Blocked file extensions (executable / dangerous)
    const BLOCKED_EXTENSIONS = [ 'php', 'phar', 'sh', 'bash', 'exe', 'bat', 'cmd', 'msi', 'dll', 'so', 'py', 'rb', 'pl', 'cgi', 'htaccess', 'htpasswd' ];

    public function get_module_name(): string {
        return 'media';
    }

    public function register_tools(): void {
        $this->register( 'media.list', [
            'class'           => 'read',
            'required_scopes' => [ 'manage.media' ],
            'description'     => 'List media library items.',
            'input_schema'    => [
                'posts_per_page' => [ 'type' => 'integer', 'default' => 20 ],
                'paged'          => [ 'type' => 'integer', 'default' => 1 ],
                'search'         => [ 'type' => 'string' ],
            ],
            'handler'         => [ $this, 'handle_list' ],
        ] );

        $this->register( 'media.get', [
            'class'           => 'read',
            'required_scopes' => [ 'manage.media' ],
            'description'     => 'Get details for a media attachment.',
            'input_schema'    => [
                'id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_get' ],
        ] );

        $this->register( 'media.upload', [
            'class'            => 'write',
            'required_scopes'  => [ 'manage.media' ],
            'supports_dry_run' => true,
            'description'      => 'Upload a file from URL or base64 content to the media library.',
            'input_schema'     => [
                'url'            => [ 'type' => 'string', 'description' => 'Remote URL to download from. Required if base64_content is not provided.' ],
                'base64_content' => [ 'type' => 'string', 'description' => 'Base64-encoded file content. Required if url is not provided.' ],
                'filename'       => [ 'type' => 'string', 'required' => true ],
                'post_id'        => [ 'type' => 'integer' ],
            ],
            'handler'          => [ $this, 'handle_upload' ],
        ] );

        $this->register( 'media.update_meta', [
            'class'           => 'write',
            'required_scopes' => [ 'manage.media' ],
            'description'     => 'Update alt text, title, caption, or description of a media item.',
            'input_schema'    => [
                'id'          => [ 'type' => 'integer', 'required' => true ],
                'title'       => [ 'type' => 'string' ],
                'caption'     => [ 'type' => 'string' ],
                'description' => [ 'type' => 'string' ],
                'alt'         => [ 'type' => 'string' ],
            ],
            'handler'         => [ $this, 'handle_update_meta' ],
        ] );

        $this->register( 'media.attach_to_post', [
            'class'           => 'write',
            'required_scopes' => [ 'manage.media', 'write.wp.posts' ],
            'description'     => 'Attach a media item to a post.',
            'input_schema'    => [
                'id'      => [ 'type' => 'integer', 'required' => true ],
                'post_id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_attach_to_post' ],
        ] );

        $this->register( 'media.set_featured', [
            'class'           => 'write',
            'required_scopes' => [ 'manage.media', 'write.wp.posts' ],
            'description'     => 'Set a media item as the featured image for a post.',
            'input_schema'    => [
                'id'      => [ 'type' => 'integer', 'required' => true ],
                'post_id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler'         => [ $this, 'handle_set_featured' ],
        ] );

        $this->register( 'files.list', [
            'class'           => 'read',
            'required_scopes' => [ 'manage.files' ],
            'description'     => 'List files in an allowlisted directory.',
            'input_schema'    => [
                'path' => [ 'type' => 'string', 'required' => true, 'description' => 'Absolute path to the directory to list.' ],
            ],
            'handler'         => [ $this, 'handle_files_list' ],
        ] );

        $this->register( 'files.upload', [
            'class'           => 'write',
            'required_scopes' => [ 'manage.files' ],
            'description'     => 'Upload a file to an allowlisted directory.',
            'input_schema'    => [
                'path'           => [ 'type' => 'string', 'required' => true, 'description' => 'Absolute path to the target directory.' ],
                'filename'       => [ 'type' => 'string', 'required' => true ],
                'base64_content' => [ 'type' => 'string', 'required' => true, 'description' => 'Base64-encoded file content.' ],
            ],
            'handler'         => [ $this, 'handle_files_upload' ],
        ] );

        $this->register( 'files.delete', [
            'class'            => 'destructive',
            'required_scopes'  => [ 'manage.files' ],
            'requires_confirm' => true,
            'description'      => 'Delete a file from an allowlisted directory (requires confirm=true).',
            'input_schema'     => [
                'path'    => [ 'type' => 'string', 'required' => true, 'description' => 'Absolute path to the file to delete.' ],
                'confirm' => [ 'type' => 'boolean', 'required' => true, 'description' => 'Must be true to execute.' ],
            ],
            'handler'          => [ $this, 'handle_files_delete' ],
        ] );
    }

    // ── Media Handlers ────────────────────────────────────────────────────

    public function handle_list( array $args, array $key_record, bool $dry_run ): array {
        $query = new WP_Query( [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => min( (int) ( $args['posts_per_page'] ?? 20 ), 100 ),
            'paged'          => max( 1, (int) ( $args['paged'] ?? 1 ) ),
            's'              => sanitize_text_field( $args['search'] ?? '' ),
        ] );

        $items = [];
        foreach ( $query->posts as $post ) {
            $items[] = $this->attachment_summary( $post->ID );
        }

        return $this->ok( [ 'items' => $items, 'total' => (int) $query->found_posts ] );
    }

    public function handle_get( array $args, array $key_record, bool $dry_run ): array {
        $id = $this->require_int( $args, 'id' );
        if ( ! $id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter id is required', 'validation_failed' );
        }

        $post = get_post( $id );
        if ( ! $post || $post->post_type !== 'attachment' ) {
            return $this->err( 'NOT_FOUND', "Media $id not found", 'error', 404 );
        }

        return $this->ok(
            $this->attachment_summary( $id ),
            [ 'target_type' => 'attachment', 'target_id' => $id ]
        );
    }

    public function handle_upload( array $args, array $key_record, bool $dry_run ): array {
        $url     = $args['url'] ?? null;
        $content = $args['base64_content'] ?? null;
        $name    = $this->require_string( $args, 'filename' );

        if ( ! $url && ! $content ) {
            return $this->err( 'MISSING_PARAM', 'Parameter url or base64_content is required', 'validation_failed' );
        }

        if ( $name && $this->is_blocked_extension( $name ) ) {
            return $this->err( 'BLOCKED_EXTENSION', 'File extension is not allowed', 'validation_failed', 400 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_upload' => $name ] );
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = null;

        if ( $url ) {
            // Download from URL
            $tmp = download_url( $url );
            if ( is_wp_error( $tmp ) ) {
                return $this->err( 'DOWNLOAD_FAILED', $tmp->get_error_message(), 'error', 500 );
            }

            $file_array = [
                'name'     => $name ?: basename( $url ),
                'tmp_name' => $tmp,
            ];

            $attachment_id = media_handle_sideload( $file_array, (int) ( $args['post_id'] ?? 0 ) );
            @unlink( $tmp );
        } elseif ( $content ) {
            // Decode base64
            $decoded = base64_decode( $content, true );
            if ( $decoded === false ) {
                return $this->err( 'INVALID_BASE64', 'Invalid base64 content', 'validation_failed' );
            }

            $upload_dir = wp_upload_dir();
            $filename   = $name ?: 'upload_' . time();
            $filepath   = $upload_dir['path'] . '/' . sanitize_file_name( $filename );

            file_put_contents( $filepath, $decoded );

            $attachment_id = media_handle_sideload(
                [ 'name' => $filename, 'tmp_name' => $filepath ],
                (int) ( $args['post_id'] ?? 0 )
            );
        }

        if ( is_wp_error( $attachment_id ) ) {
            return $this->err( 'UPLOAD_FAILED', $attachment_id->get_error_message(), 'error', 500 );
        }

        return $this->ok(
            [ 'id' => $attachment_id, 'url' => wp_get_attachment_url( $attachment_id ) ],
            [ 'target_type' => 'attachment', 'target_id' => $attachment_id, 'summary' => [ 'uploaded' => true ] ]
        );
    }

    public function handle_update_meta( array $args, array $key_record, bool $dry_run ): array {
        $id = $this->require_int( $args, 'id' );
        if ( ! $id ) {
            return $this->err( 'MISSING_PARAM', 'Parameter id is required', 'validation_failed' );
        }

        $post = get_post( $id );
        if ( ! $post || $post->post_type !== 'attachment' ) {
            return $this->err( 'NOT_FOUND', "Media $id not found", 'error', 404 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_update_meta_for' => $id ] );
        }

        $post_data = [ 'ID' => $id ];
        if ( isset( $args['title'] ) )   $post_data['post_title'] = sanitize_text_field( $args['title'] );
        if ( isset( $args['caption'] ) ) $post_data['post_excerpt'] = sanitize_text_field( $args['caption'] );
        if ( isset( $args['description'] ) ) $post_data['post_content'] = sanitize_text_field( $args['description'] );

        if ( count( $post_data ) > 1 ) {
            wp_update_post( $post_data );
        }

        if ( isset( $args['alt'] ) ) {
            update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $args['alt'] ) );
        }

        return $this->ok(
            [ 'id' => $id, 'updated' => true ],
            [ 'target_type' => 'attachment', 'target_id' => $id ]
        );
    }

    public function handle_attach_to_post( array $args, array $key_record, bool $dry_run ): array {
        $id      = $this->require_int( $args, 'id' );
        $post_id = $this->require_int( $args, 'post_id' );

        if ( ! $id || ! $post_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameters id and post_id are required', 'validation_failed' );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_attach' => [ 'media_id' => $id, 'post_id' => $post_id ] ] );
        }

        wp_update_post( [ 'ID' => $id, 'post_parent' => $post_id ] );
        return $this->ok(
            [ 'id' => $id, 'post_id' => $post_id, 'attached' => true ],
            [ 'target_type' => 'attachment', 'target_id' => $id ]
        );
    }

    public function handle_set_featured( array $args, array $key_record, bool $dry_run ): array {
        $id      = $this->require_int( $args, 'id' );
        $post_id = $this->require_int( $args, 'post_id' );

        if ( ! $id || ! $post_id ) {
            return $this->err( 'MISSING_PARAM', 'Parameters id and post_id are required', 'validation_failed' );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_set_featured' => [ 'media_id' => $id, 'post_id' => $post_id ] ] );
        }

        set_post_thumbnail( $post_id, $id );
        return $this->ok(
            [ 'post_id' => $post_id, 'featured_image_id' => $id, 'updated' => true ],
            [ 'target_type' => 'post', 'target_id' => $post_id ]
        );
    }

    // ── File Handlers ─────────────────────────────────────────────────────

    public function handle_files_list( array $args, array $key_record, bool $dry_run ): array {
        $path = $this->require_string( $args, 'path' );
        if ( ! $path ) {
            return $this->err( 'MISSING_PARAM', 'Parameter path is required', 'validation_failed' );
        }

        if ( ! ACL_Policy_Engine::check_file_path_allowlist( $key_record, $path ) ) {
            return $this->err( 'DENIED_ALLOWLIST', 'Path not in allowlist', 'denied_allowlist', 403 );
        }

        if ( ! is_dir( $path ) ) {
            return $this->err( 'NOT_FOUND', "Directory not found: $path", 'error', 404 );
        }

        $files = scandir( $path );
        $result = [];
        foreach ( $files as $file ) {
            if ( $file === '.' || $file === '..' ) continue;
            $full = $path . '/' . $file;
            $result[] = [
                'name'     => $file,
                'is_dir'   => is_dir( $full ),
                'size'     => is_file( $full ) ? filesize( $full ) : null,
                'modified' => date( 'Y-m-d H:i:s', filemtime( $full ) ),
            ];
        }

        return $this->ok( [ 'path' => $path, 'files' => $result ] );
    }

    public function handle_files_upload( array $args, array $key_record, bool $dry_run ): array {
        $path    = $this->require_string( $args, 'path' );
        $name    = $this->require_string( $args, 'filename' );
        $content = $args['base64_content'] ?? null;

        if ( ! $path || ! $name || ! $content ) {
            return $this->err( 'MISSING_PARAM', 'Parameters path, filename, and base64_content are required', 'validation_failed' );
        }

        if ( ! ACL_Policy_Engine::check_file_path_allowlist( $key_record, $path ) ) {
            return $this->err( 'DENIED_ALLOWLIST', 'Path not in allowlist', 'denied_allowlist', 403 );
        }

        if ( $this->is_blocked_extension( $name ) ) {
            return $this->err( 'BLOCKED_EXTENSION', 'File extension is not allowed', 'validation_failed', 400 );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_upload' => "$path/$name" ] );
        }

        $decoded = base64_decode( $content, true );
        if ( $decoded === false ) {
            return $this->err( 'INVALID_BASE64', 'Invalid base64 content', 'validation_failed' );
        }

        $filepath = rtrim( $path, '/' ) . '/' . sanitize_file_name( $name );
        $written  = file_put_contents( $filepath, $decoded );

        if ( $written === false ) {
            return $this->err( 'WRITE_FAILED', 'Failed to write file', 'error', 500 );
        }

        return $this->ok(
            [ 'path' => $filepath, 'size' => $written, 'uploaded' => true ],
            [ 'target_type' => 'file', 'target_id' => $filepath, 'summary' => [ 'bytes' => $written ] ]
        );
    }

    public function handle_files_delete( array $args, array $key_record, bool $dry_run ): array {
        $filepath = $this->require_string( $args, 'path' );
        if ( ! $filepath ) {
            return $this->err( 'MISSING_PARAM', 'Parameter path is required', 'validation_failed' );
        }

        if ( ! ACL_Policy_Engine::check_file_path_allowlist( $key_record, $filepath ) ) {
            return $this->err( 'DENIED_ALLOWLIST', 'Path not in allowlist', 'denied_allowlist', 403 );
        }

        if ( ! file_exists( $filepath ) ) {
            return $this->err( 'NOT_FOUND', "File not found: $filepath", 'error', 404 );
        }

        if ( is_dir( $filepath ) ) {
            return $this->err( 'IS_DIRECTORY', 'Cannot delete a directory via this tool', 'validation_failed' );
        }

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'would_delete_file' => $filepath ] );
        }

        $success = unlink( $filepath );
        if ( ! $success ) {
            return $this->err( 'DELETE_FAILED', "Failed to delete: $filepath", 'error', 500 );
        }

        return $this->ok(
            [ 'path' => $filepath, 'deleted' => true ],
            [ 'target_type' => 'file', 'target_id' => $filepath ]
        );
    }

    // ── Private Helpers ───────────────────────────────────────────────────

    private function attachment_summary( int $id ): array {
        $post = get_post( $id );
        return [
            'id'          => $id,
            'title'       => $post->post_title ?? '',
            'filename'    => basename( get_attached_file( $id ) ),
            'url'         => wp_get_attachment_url( $id ),
            'mime_type'   => get_post_mime_type( $id ),
            'alt'         => get_post_meta( $id, '_wp_attachment_image_alt', true ),
            'caption'     => $post->post_excerpt ?? '',
            'description' => $post->post_content ?? '',
            'date'        => $post->post_date ?? '',
        ];
    }

    private function is_blocked_extension( string $filename ): bool {
        $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
        return in_array( $ext, self::BLOCKED_EXTENSIONS, true );
    }
}
