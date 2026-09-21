<?php
/**
 * Capability + tool inventory for AICOMBase (PROTOCOL §4 `/site/capabilities`, PRD §50/§52).
 *
 * Derived live from AICOM_Tool_Registry: tools are grouped into capability
 * domains, each carrying the real required scopes, risk, reversibility and
 * input schema. Only tools usable on THIS site are listed (dependency active).
 * A hash of the inventory lets AICOMBase know when it must re-sync.
 */
defined( 'ABSPATH' ) || exit;

class AICOM_Base_Capabilities {

    const OPT_CACHE = 'aicom_base_caps_cache'; // [fingerprint, hash]

    /** Longest-prefix map: tool name prefix → [capability id, domain, display name]. */
    private static function groups(): array {
        return [
            'wp.posts.'        => [ 'content',       'Content',     'Posts & pages' ],
            'wp.terms.'        => [ 'content',       'Content',     'Posts & pages' ],
            'wp.meta.'         => [ 'content',       'Content',     'Posts & pages' ],
            'wp.post_types.'   => [ 'content',       'Content',     'Posts & pages' ],
            'wp.taxonomies.'   => [ 'content',       'Content',     'Posts & pages' ],
            'wp.site.'         => [ 'content',       'Content',     'Posts & pages' ],
            'wp.menus.'        => [ 'menus',         'Content',     'Navigation menus' ],
            'media.'           => [ 'media',         'Media',       'Media library' ],
            'files.'           => [ 'files',         'Media',       'File system' ],
            'wp.options.'      => [ 'site_settings', 'Site',        'Site settings' ],
            'wp.plugins.'      => [ 'plugins',       'Site',        'Plugin management' ],
            'wp.users.'        => [ 'users',         'Users',       'Users & roles' ],
            'wp.roles.'        => [ 'users',         'Users',       'Users & roles' ],
            'backup.'          => [ 'backups',       'Safety',      'Snapshots & restore' ],
            'a11y.'            => [ 'accessibility', 'Compliance',  'Accessibility fixes' ],
            'skills.'          => [ 'workflows',     'Automation',  'Saved workflows' ],
            'aicom.'           => [ 'workflows',     'Automation',  'Saved workflows' ],
            'session.'         => [ 'core',          'Core',        'AICOM core' ],
            'server.'          => [ 'core',          'Core',        'AICOM core' ],
            'site.'            => [ 'core',          'Core',        'AICOM core' ],
            'tools/'           => [ 'core',          'Core',        'AICOM core' ],
            'wc.'              => [ 'woocommerce',   'Commerce',    'WooCommerce' ],
            'pll.'             => [ 'polylang',      'Languages',   'Polylang' ],
            'wpml.'            => [ 'wpml',          'Languages',   'WPML' ],
            'yoast.'           => [ 'yoast',         'SEO',         'Yoast SEO' ],
            'seopress.'        => [ 'seopress',      'SEO',         'SEOPress' ],
            'elementor.'       => [ 'elementor',     'Design',      'Elementor' ],
            'ecs.'             => [ 'ecs',           'Design',      'Elementor Custom Skin' ],
            'clautron.'        => [ 'clautron',      'Automation',  'Clautron blueprints' ],
        ];
    }

    /** Tools whose target is snapshotted by the Tool Router before writing (mirror of AUTO_BACKUP_MAP). */
    private static function snapshot_tools(): array {
        return [
            'wp.posts.update', 'wp.posts.trash', 'wp.posts.delete', 'wp.terms.update', 'wp.terms.delete',
            'elementor.widget.update_field', 'elementor.page.bulk_update_texts', 'elementor.template.set_conditions',
            'pll.create_bilingual_pair', 'wp.posts.create', 'wp.posts.restore',
        ];
    }

    private static function group_for( string $tool, string $module ): array {
        $best = null;
        $len  = 0;
        foreach ( self::groups() as $prefix => $g ) {
            if ( strpos( $tool, $prefix ) === 0 && strlen( $prefix ) > $len ) {
                $best = $g;
                $len  = strlen( $prefix );
            }
        }
        return $best ?: [ sanitize_key( $module ) ?: 'other', 'Other', ucfirst( $module ) ];
    }

    public static function capability_id( string $tool, string $module ): string {
        return self::group_for( $tool, $module )[0];
    }

    // ── risk helpers ──────────────────────────────────────────────────────

    public static function risk( array $meta ): string {
        $rank  = [ 'low' => 0, 'med' => 1, 'high' => 2 ];
        $risk  = 'low';
        if ( $meta['class'] === 'write' ) {
            $risk = 'med';
        } elseif ( in_array( $meta['class'], [ 'destructive', 'admin_sensitive' ], true ) ) {
            $risk = 'high';
        }
        $flat = AICOM_Auth::scope_flat();
        foreach ( (array) $meta['required_scopes'] as $s ) {
            $r = $flat[ $s ][1] ?? 'low';
            $r = $r === 'critical' ? 'high' : $r;
            if ( ( $rank[ $r ] ?? 0 ) > $rank[ $risk ] ) {
                $risk = $r;
            }
        }
        return $risk;
    }

    // ── inventory ─────────────────────────────────────────────────────────

    /** @return array<int,array> capability objects per PROTOCOL §4. */
    public static function build(): array {
        $caps  = [];
        $rank  = [ 'low' => 0, 'med' => 1, 'high' => 2 ];
        $names = AICOM_Tool_Registry::get_all();
        ksort( $names );

        foreach ( $names as $name => $meta ) {
            if ( $meta['dependency'] !== null && ! AICOM_Module_Detector::is_dependency_active( $meta['dependency'] ) ) {
                continue;
            }
            [ $cid, $domain, $cname ] = self::group_for( $name, (string) $meta['module'] );

            $is_read     = in_array( $meta['class'], AICOM_Tool_Registry::READ_ONLY_CLASSES, true );
            $reversible  = $is_read || in_array( $name, self::snapshot_tools(), true );
            $verification = $is_read ? 'none' : 'readback';
            $risk        = self::risk( $meta );

            $schema = [ 'type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false ];
            $entry  = AICOM_Tool_Registry::describe( $name, [ 'woocommerce', 'elementor', 'polylang', 'ecs', 'ecs_pro', 'clautron', 'yoast', 'seopress', 'wpml' ] );
            if ( $entry && isset( $entry['inputSchema'] ) ) {
                $schema = $entry['inputSchema'];
            }

            $scopes = array_values( array_unique( array_map( 'strval', (array) $meta['required_scopes'] ) ) );
            if ( ! isset( $caps[ $cid ] ) ) {
                $caps[ $cid ] = [
                    'id'              => $cid,
                    'domain'          => $domain,
                    'name'            => $cname,
                    'version'         => AICOM_VERSION,
                    'risk'            => 'low',
                    'reversible'      => true,
                    'verification'    => 'none',
                    'required_scopes' => [],
                    'tools'           => [],
                ];
            }
            $c =& $caps[ $cid ];
            if ( $rank[ $risk ] > $rank[ $c['risk'] ] ) {
                $c['risk'] = $risk;
            }
            $c['reversible'] = $c['reversible'] && $reversible;
            if ( $verification !== 'none' ) {
                $c['verification'] = $verification;
            }
            $c['required_scopes'] = array_values( array_unique( array_merge( $c['required_scopes'], $scopes ) ) );
            $c['tools'][]         = [
                'id'              => $name,
                'name'            => $name,
                'description'     => (string) $meta['description'],
                'category'        => (string) $meta['class'],
                'risk'            => $risk,
                'required_scopes' => $scopes,
                'input_schema'    => $schema,
                'output_schema'   => null,
                'reversible'      => $reversible,
                'verification'    => $verification,
            ];
            unset( $c );
        }
        ksort( $caps );
        foreach ( $caps as &$c ) {
            sort( $c['required_scopes'] );
        }
        unset( $c );
        return array_values( $caps );
    }

    /** Canonical hash of an inventory (sha256 over its JSON). */
    public static function hash_of( array $caps ): string {
        return hash( 'sha256', (string) wp_json_encode( $caps, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    }

    /** Cheap fingerprint of everything the inventory depends on. */
    private static function fingerprint(): string {
        $active = (array) get_option( 'active_plugins', [] );
        sort( $active );
        return md5( AICOM_VERSION . '|' . implode( ',', $active ) . '|' . count( AICOM_Tool_Registry::get_all() ) . '|' . get_locale() );
    }

    /**
     * Current capabilities_hash for heartbeats. Recomputed only when the plugin set /
     * AICOM version changes; the full inventory is never rebuilt on a normal tick.
     */
    public static function current_hash( bool $force = false ): string {
        $fp    = self::fingerprint();
        $cache = get_option( self::OPT_CACHE, [] );
        if ( ! $force && is_array( $cache ) && ( $cache['fp'] ?? '' ) === $fp && ! empty( $cache['hash'] ) ) {
            return (string) $cache['hash'];
        }
        $hash = self::hash_of( self::build() );
        update_option( self::OPT_CACHE, [ 'fp' => $fp, 'hash' => $hash ], false );
        return $hash;
    }

    /** Upload the full inventory. @return array client result */
    public static function upload(): array {
        $caps = self::build();
        $hash = self::hash_of( $caps );
        $res  = AICOM_Base_Client::site_request( 'POST', '/api/v1/site/capabilities', [
            'capabilities_hash' => $hash,
            'capabilities'      => $caps,
        ], [ 'timeout' => 30 ] );
        if ( $res['ok'] ) {
            update_option( self::OPT_CACHE, [ 'fp' => self::fingerprint(), 'hash' => $hash ], false );
            AICOM_Base_State::update( [ 'caps_needed' => false, 'caps_hash_sent' => $hash, 'caps_uploaded_at' => time() ] );
        }
        return $res;
    }
}
