<?php
defined( 'ABSPATH' ) || exit;

class AICOM_Module_A11y extends AICOM_Module_Base {

    public function get_module_name(): string { return 'a11y'; }

    public function register_tools(): void {
        $this->register( 'a11y.images_missing_alt', [
            'class'           => 'read',
            'required_scopes' => [ 'manage.a11y' ],
            'description'     => 'List media library images that have no alt text set.',
            'input_schema'    => [
                'limit'  => [ 'type' => 'integer', 'default' => 50 ],
                'offset' => [ 'type' => 'integer', 'default' => 0 ],
            ],
            'handler' => [ $this, 'handle_images_missing_alt' ],
        ] );

        $this->register( 'a11y.audit_post', [
            'class'           => 'read',
            'required_scopes' => [ 'manage.a11y' ],
            'description'     => 'Audit a post/page content for WCAG accessibility issues: heading hierarchy, image alt text, generic link text.',
            'input_schema'    => [
                'post_id' => [ 'type' => 'integer', 'required' => true ],
            ],
            'handler' => [ $this, 'handle_audit_post' ],
        ] );

        $this->register( 'a11y.set_image_alt', [
            'class'            => 'write',
            'required_scopes'  => [ 'manage.a11y' ],
            'description'      => 'Set alt text on a media library image. Pass empty string to mark as decorative (aria-hidden).',
            'supports_dry_run' => true,
            'input_schema'     => [
                'id'  => [ 'type' => 'integer', 'required' => true ],
                'alt' => [ 'type' => 'string',  'required' => true ],
            ],
            'handler' => [ $this, 'handle_set_image_alt' ],
        ] );

        $this->register( 'a11y.site_report', [
            'class'           => 'read',
            'required_scopes' => [ 'manage.a11y' ],
            'description'     => 'Summary report: total images vs images missing alt text across the site, with a preview of the top offenders.',
            'input_schema'    => [],
            'handler'         => [ $this, 'handle_site_report' ],
        ] );
    }

    // ── Handlers ───────────────────────────────────────────────────────────────

    public function handle_images_missing_alt( array $args, array $key_record, bool $dry_run ): array {
        $limit  = max( 1, min( 200, (int) ( $args['limit']  ?? 50 ) ) );
        $offset = max( 0, (int) ( $args['offset'] ?? 0 ) );

        $q = new WP_Query( [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'post_mime_type' => 'image',
            'meta_query'     => [
                'relation' => 'OR',
                [ 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ],
                [ 'key' => '_wp_attachment_image_alt', 'value'   => '', 'compare' => '=' ],
            ],
        ] );

        $items = [];
        foreach ( $q->posts as $p ) {
            $items[] = [
                'id'       => $p->ID,
                'title'    => $p->post_title,
                'filename' => basename( get_attached_file( $p->ID ) ?: '' ),
                'url'      => wp_get_attachment_url( $p->ID ),
            ];
        }

        return $this->ok( [
            'count'       => count( $items ),
            'total_found' => $q->found_posts,
            'items'       => $items,
        ] );
    }

    public function handle_audit_post( array $args, array $key_record, bool $dry_run ): array {
        $post_id = $this->require_int( $args, 'post_id' );
        if ( $post_id === null ) {
            return $this->err( 'MISSING_PARAM', 'post_id required' );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return $this->err( 'NOT_FOUND', 'Post not found', 'not_found', 404 );
        }

        $content = apply_filters( 'the_content', $post->post_content );
        $issues  = [];

        if ( ! empty( $content ) ) {
            $doc = new DOMDocument();
            libxml_use_internal_errors( true );
            $doc->loadHTML( '<?xml encoding="UTF-8">' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
            libxml_clear_errors();

            // Heading hierarchy
            $headings = [];
            foreach ( [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ] as $tag ) {
                foreach ( $doc->getElementsByTagName( $tag ) as $el ) {
                    $headings[] = [ 'level' => (int) $tag[1], 'text' => trim( $el->textContent ) ];
                }
            }

            $levels = array_column( $headings, 'level' );
            if ( ! in_array( 1, $levels, true ) ) {
                $issues[] = [
                    'type'     => 'missing_h1',
                    'severity' => 'error',
                    'message'  => 'No H1 heading found in content.',
                ];
            }

            $prev = 0;
            foreach ( $headings as $h ) {
                if ( $prev > 0 && $h['level'] > $prev + 1 ) {
                    $issues[] = [
                        'type'     => 'heading_skip',
                        'severity' => 'error',
                        'message'  => "Heading jumps from H{$prev} to H{$h['level']}: \"{$h['text']}\"",
                    ];
                }
                $prev = $h['level'];
            }

            // Images without alt text in content
            foreach ( $doc->getElementsByTagName( 'img' ) as $img ) {
                $has_alt     = $img->hasAttribute( 'alt' );
                $alt_empty   = $has_alt && $img->getAttribute( 'alt' ) === '';
                $aria_hidden = $img->getAttribute( 'aria-hidden' ) === 'true';

                if ( ! $has_alt || ( $alt_empty && ! $aria_hidden ) ) {
                    $issues[] = [
                        'type'     => 'img_missing_alt',
                        'severity' => 'warning',
                        'message'  => 'Image missing alt text.',
                        'context'  => $img->getAttribute( 'src' ),
                    ];
                }
            }

            // Generic link text
            $generic = [ 'click here', 'here', 'read more', 'link', 'more', 'this', 'learn more' ];
            foreach ( $doc->getElementsByTagName( 'a' ) as $link ) {
                $text = strtolower( trim( $link->textContent ) );
                if ( in_array( $text, $generic, true ) ) {
                    $issues[] = [
                        'type'     => 'generic_link_text',
                        'severity' => 'warning',
                        'message'  => "Link text \"{$text}\" is not descriptive.",
                        'context'  => $link->getAttribute( 'href' ),
                    ];
                }
            }
        }

        $errors   = count( array_filter( $issues, fn( $i ) => $i['severity'] === 'error' ) );
        $warnings = count( array_filter( $issues, fn( $i ) => $i['severity'] === 'warning' ) );
        $score    = max( 0, 100 - ( $errors * 20 ) - ( $warnings * 5 ) );

        return $this->ok( [
            'post_id' => $post_id,
            'title'   => $post->post_title,
            'issues'  => $issues,
            'summary' => [ 'errors' => $errors, 'warnings' => $warnings ],
            'score'   => $score,
        ] );
    }

    public function handle_set_image_alt( array $args, array $key_record, bool $dry_run ): array {
        $id = $this->require_int( $args, 'id' );
        if ( $id === null ) {
            return $this->err( 'MISSING_PARAM', 'id required' );
        }
        if ( ! array_key_exists( 'alt', $args ) ) {
            return $this->err( 'MISSING_PARAM', 'alt required (pass empty string to mark image as decorative)' );
        }

        $post = get_post( $id );
        if ( ! $post || $post->post_type !== 'attachment' ) {
            return $this->err( 'NOT_FOUND', 'Attachment not found', 'not_found', 404 );
        }

        $alt_new  = sanitize_text_field( wp_unslash( (string) $args['alt'] ) );
        $alt_prev = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );

        if ( ! $dry_run ) {
            update_post_meta( $id, '_wp_attachment_image_alt', $alt_new );
        }

        return $this->ok(
            [ 'id' => $id, 'alt' => $alt_new, 'previous_alt' => $alt_prev, 'changed' => $alt_new !== $alt_prev, 'dry_run' => $dry_run ],
            [ 'target_type' => 'attachment', 'target_id' => $id, 'summary' => [ 'action' => 'set_image_alt', 'alt' => $alt_new ] ]
        );
    }

    public function handle_site_report( array $args, array $key_record, bool $dry_run ): array {
        $total_q = new WP_Query( [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ] );
        $total = (int) $total_q->found_posts;

        $missing_q = new WP_Query( [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => 10,
            'meta_query'     => [
                'relation' => 'OR',
                [ 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ],
                [ 'key' => '_wp_attachment_image_alt', 'value'   => '', 'compare' => '=' ],
            ],
        ] );
        $missing = (int) $missing_q->found_posts;
        $pct     = $total > 0 ? round( $missing / $total * 100, 1 ) . '%' : '0%';

        $top = [];
        foreach ( $missing_q->posts as $p ) {
            $top[] = [
                'id'    => $p->ID,
                'title' => $p->post_title,
                'url'   => wp_get_attachment_url( $p->ID ),
            ];
        }

        return $this->ok( [
            'images'      => [ 'total' => $total, 'missing_alt' => $missing, 'missing_alt_pct' => $pct ],
            'top_missing' => $top,
        ] );
    }
}
