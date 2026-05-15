<?php
defined( 'ABSPATH' ) || exit;

class AICOM_Skills {

    const VALID_STATUSES = [ 'suggested', 'draft', 'active', 'archived', 'update_proposal' ];
    const VALID_TYPES    = [ 'simple', 'guided', 'advanced' ];

    // ── CRUD ──────────────────────────────────────────────────────────────────

    public static function create( array $data ): array {
        global $wpdb;

        $now  = current_time( 'mysql', true );
        $slug = sanitize_key( $data['slug'] ?? '' );

        if ( ! $slug ) {
            return [ 'error' => 'slug is required' ];
        }

        $type   = in_array( $data['type'] ?? '', self::VALID_TYPES, true ) ? $data['type'] : 'simple';
        $status = in_array( $data['status'] ?? '', self::VALID_STATUSES, true ) ? $data['status'] : 'draft';

        $row = [
            'slug'              => $slug,
            'name'              => sanitize_text_field( $data['name'] ?? $slug ),
            'description'       => sanitize_textarea_field( $data['description'] ?? '' ),
            'type'              => $type,
            'status'            => $status,
            'input_schema_json' => self::encode_json( $data['input_schema'] ?? null ),
            'rules_json'        => self::encode_json( $data['rules'] ?? null ),
            'steps_json'        => self::encode_json( $data['steps'] ?? null ),
            'permissions_json'  => self::encode_json( $data['permissions'] ?? null ),
            'tags_json'         => self::encode_json( $data['tags'] ?? null ),
            'logging_enabled'   => isset( $data['logging'] ) ? (int) (bool) $data['logging'] : 1,
            'rollback_enabled'  => isset( $data['rollback_enabled'] ) ? (int) (bool) $data['rollback_enabled'] : 0,
            'version'           => 1,
            'parent_skill_id'   => isset( $data['parent_skill_id'] ) ? (int) $data['parent_skill_id'] : null,
            'session_id'        => isset( $data['session_id'] ) ? (int) $data['session_id'] : null,
            'created_by_key_id' => isset( $data['key_id'] ) ? (int) $data['key_id'] : null,
            'created_at'        => $now,
            'updated_at'        => $now,
        ];

        $inserted = $wpdb->insert( $wpdb->prefix . 'aicom_skills', $row );
        if ( ! $inserted ) {
            return [ 'error' => 'Slug already exists or DB error: ' . $wpdb->last_error ];
        }

        $id = (int) $wpdb->insert_id;
        self::save_revision( $id, $data['key_id'] ?? null );

        return self::get( $id );
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aicom_skills WHERE id = %d", $id ),
            ARRAY_A
        );
        return $row ? self::decode_row( $row ) : null;
    }

    public static function get_by_slug( string $slug ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aicom_skills WHERE slug = %s", $slug ),
            ARRAY_A
        );
        return $row ? self::decode_row( $row ) : null;
    }

    public static function update( int $id, array $data, ?int $key_id = null ): bool {
        global $wpdb;

        $skill = self::get( $id );
        if ( ! $skill ) {
            return false;
        }

        // Save revision before update if skill is active or draft
        if ( in_array( $skill['status'], [ 'active', 'draft' ], true ) ) {
            self::save_revision( $id, $key_id );
        }

        $allowed = [
            'name', 'description', 'type', 'status',
            'input_schema', 'rules', 'steps', 'permissions', 'tags',
            'logging', 'rollback_enabled',
        ];

        $set = [ 'updated_at' => current_time( 'mysql', true ) ];

        foreach ( $allowed as $field ) {
            if ( ! array_key_exists( $field, $data ) ) {
                continue;
            }
            switch ( $field ) {
                case 'name':
                    $set['name'] = sanitize_text_field( $data['name'] );
                    break;
                case 'description':
                    $set['description'] = sanitize_textarea_field( $data['description'] );
                    break;
                case 'type':
                    if ( in_array( $data['type'], self::VALID_TYPES, true ) ) {
                        $set['type'] = $data['type'];
                    }
                    break;
                case 'status':
                    if ( in_array( $data['status'], self::VALID_STATUSES, true ) ) {
                        $set['status'] = $data['status'];
                    }
                    break;
                case 'input_schema':
                    $set['input_schema_json'] = self::encode_json( $data['input_schema'] );
                    break;
                case 'rules':
                    $set['rules_json'] = self::encode_json( $data['rules'] );
                    break;
                case 'steps':
                    $set['steps_json'] = self::encode_json( $data['steps'] );
                    break;
                case 'permissions':
                    $set['permissions_json'] = self::encode_json( $data['permissions'] );
                    break;
                case 'tags':
                    $set['tags_json'] = self::encode_json( $data['tags'] );
                    break;
                case 'logging':
                    $set['logging_enabled'] = (int) (bool) $data['logging'];
                    break;
                case 'rollback_enabled':
                    $set['rollback_enabled'] = (int) (bool) $data['rollback_enabled'];
                    break;
            }
        }

        // Increment version on content changes
        $content_fields = [ 'name', 'description', 'type', 'input_schema_json', 'rules_json', 'steps_json', 'permissions_json', 'tags_json' ];
        if ( array_intersect( array_keys( $set ), $content_fields ) ) {
            $set['version'] = (int) $skill['version'] + 1;
        }

        return (bool) $wpdb->update(
            $wpdb->prefix . 'aicom_skills',
            $set,
            [ 'id' => $id ]
        );
    }

    public static function set_status( int $id, string $status, ?int $key_id = null ): bool {
        global $wpdb;
        if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
            return false;
        }
        $set = [ 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ];
        if ( $status === 'archived' ) {
            $set['archived_at'] = current_time( 'mysql', true );
        }
        return (bool) $wpdb->update( $wpdb->prefix . 'aicom_skills', $set, [ 'id' => $id ] );
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'aicom_skill_revisions', [ 'skill_id' => $id ] );
        return (bool) $wpdb->delete( $wpdb->prefix . 'aicom_skills', [ 'id' => $id ] );
    }

    public static function list( array $filters = [], int $limit = 20, int $offset = 0 ): array {
        global $wpdb;
        $t     = $wpdb->prefix . 'aicom_skills';
        $where = [ '1=1' ];
        $args  = [];

        if ( ! empty( $filters['status'] ) ) {
            $where[] = 'status = %s';
            $args[]  = $filters['status'];
        }
        if ( ! empty( $filters['type'] ) ) {
            $where[] = 'type = %s';
            $args[]  = $filters['type'];
        }
        if ( ! empty( $filters['search'] ) ) {
            $like    = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
            $where[] = '(name LIKE %s OR description LIKE %s OR slug LIKE %s)';
            $args[]  = $like;
            $args[]  = $like;
            $args[]  = $like;
        }

        $where_sql = implode( ' AND ', $where );
        $total     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE $where_sql", ...$args ) );
        $rows      = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM $t WHERE $where_sql ORDER BY updated_at DESC LIMIT %d OFFSET %d", ...array_merge( $args, [ $limit, $offset ] ) ),
            ARRAY_A
        );

        return [
            'items' => array_map( [ self::class, 'decode_row' ], $rows ?: [] ),
            'total' => $total,
        ];
    }

    // ── Revisions ─────────────────────────────────────────────────────────────

    public static function save_revision( int $skill_id, ?int $key_id = null ): void {
        global $wpdb;
        $skill = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aicom_skills WHERE id = %d", $skill_id ),
            ARRAY_A
        );
        if ( ! $skill ) {
            return;
        }
        $wpdb->insert( $wpdb->prefix . 'aicom_skill_revisions', [
            'skill_id'          => $skill_id,
            'version'           => (int) $skill['version'],
            'snapshot_json'     => wp_json_encode( $skill ),
            'changed_by_key_id' => $key_id,
            'created_at'        => current_time( 'mysql', true ),
        ] );
    }

    public static function get_revisions( int $skill_id ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, skill_id, version, changed_by_key_id, created_at FROM {$wpdb->prefix}aicom_skill_revisions WHERE skill_id = %d ORDER BY version DESC",
                $skill_id
            ),
            ARRAY_A
        ) ?: [];
    }

    // ── Similarity Matching ───────────────────────────────────────────────────

    /**
     * Find similar skills for a candidate definition.
     * Returns array of [ 'skill' => [...], 'score' => 0.0-1.0, 'match_type' => string ]
     * sorted descending by score.
     */
    public static function find_similar( array $candidate ): array {
        $active = self::list( [ 'status' => 'active' ], 200, 0 );
        $draft  = self::list( [ 'status' => 'draft' ],  200, 0 );
        $all    = array_merge( $active['items'], $draft['items'] );

        if ( empty( $all ) ) {
            return [];
        }

        $candidate_intent = strtolower( ( $candidate['name'] ?? '' ) . ' ' . ( $candidate['description'] ?? '' ) );
        $candidate_tags   = array_map( 'strtolower', (array) ( $candidate['tags'] ?? [] ) );
        $candidate_steps  = self::extract_tool_names( $candidate['steps'] ?? [] );
        $candidate_type   = $candidate['type'] ?? 'simple';

        $results = [];
        foreach ( $all as $skill ) {
            $skill_intent = strtolower( $skill['name'] . ' ' . ( $skill['description'] ?? '' ) );
            $skill_tags   = array_map( 'strtolower', (array) ( $skill['tags'] ?? [] ) );
            $skill_steps  = self::extract_tool_names( $skill['steps'] ?? [] );
            $skill_type   = $skill['type'];

            // 1. Intent overlap via similar_text (0–1)
            similar_text( $candidate_intent, $skill_intent, $intent_pct );
            $intent_score = $intent_pct / 100;

            // 2. Tags Jaccard
            $tags_score = self::jaccard( $candidate_tags, $skill_tags );

            // 3. Steps tool-name Jaccard
            $steps_score = self::jaccard( $candidate_steps, $skill_steps );

            // 4. Type match
            $type_score = ( $candidate_type === $skill_type ) ? 1.0 : 0.0;

            $score = ( 0.30 * $intent_score )
                   + ( 0.25 * $tags_score )
                   + ( 0.25 * $steps_score )
                   + ( 0.20 * $type_score );

            $match_type = $score >= 0.85 ? 'use_existing'
                        : ( $score >= 0.60 ? 'clarify' : 'create_new' );

            $results[] = [
                'skill'      => $skill,
                'score'      => round( $score, 3 ),
                'match_type' => $match_type,
            ];
        }

        usort( $results, fn( $a, $b ) => $b['score'] <=> $a['score'] );
        return array_slice( $results, 0, 10 );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function jaccard( array $a, array $b ): float {
        if ( empty( $a ) && empty( $b ) ) {
            return 0.0;
        }
        $intersection = count( array_intersect( $a, $b ) );
        $union        = count( array_unique( array_merge( $a, $b ) ) );
        return $union > 0 ? $intersection / $union : 0.0;
    }

    private static function extract_tool_names( array $steps ): array {
        $names = [];
        foreach ( $steps as $step ) {
            if ( isset( $step['action'] ) ) {
                $names[] = strtolower( $step['action'] );
            } elseif ( isset( $step['tool'] ) ) {
                $names[] = strtolower( $step['tool'] );
            }
        }
        return array_unique( $names );
    }

    private static function encode_json( $value ): ?string {
        if ( $value === null ) {
            return null;
        }
        return is_string( $value ) ? $value : wp_json_encode( $value );
    }

    private static function decode_row( array $row ): array {
        foreach ( [ 'input_schema_json', 'rules_json', 'steps_json', 'permissions_json', 'tags_json' ] as $col ) {
            $key         = str_replace( '_json', '', $col );
            $row[ $key ] = $row[ $col ] ? json_decode( $row[ $col ], true ) : null;
            unset( $row[ $col ] );
        }
        $row['id']              = (int) $row['id'];
        $row['version']         = (int) $row['version'];
        $row['logging_enabled'] = (bool) $row['logging_enabled'];
        $row['rollback_enabled']= (bool) $row['rollback_enabled'];
        return $row;
    }
}
