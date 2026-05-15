<?php
/**
 * Session module: session.open and session.close tools.
 * Agents must open a named session before executing any write operations.
 */
class AICOM_Module_Session extends AICOM_Module_Base {

    public function get_module_name(): string {
        return 'session';
    }

    public function register_tools(): void {
        $this->register( 'session.open', [
            'class'           => 'write',
            'required_scopes' => [],
            'description'     => 'Open a named session before making any changes. REQUIRED before any write, destructive, or admin_sensitive tool. Always provide both name and description — name is a short label (e.g. "Update homepage hero"), description explains what you plan to do and why (e.g. "Rewriting hero headline to match new brand guidelines"). Call session.close() when done.',
            'input_schema'    => [
                'name'        => [ 'type' => 'string', 'required' => true,  'description' => 'Short label for the session, e.g. "Update homepage hero section". Be specific — this appears in the admin audit log.' ],
                'description' => [ 'type' => 'string', 'required' => false, 'description' => 'Explain what you plan to do and why, e.g. "Rewriting the hero headline and subtext to match the new brand guidelines approved by the client." Strongly recommended.' ],
            ],
            'handler'         => [ $this, 'handle_open' ],
        ] );

        $this->register( 'session.close', [
            'class'           => 'write',
            'required_scopes' => [],
            'description'     => 'Close the current session. Call this when you have finished your changes.',
            'input_schema'    => [],
            'handler'         => [ $this, 'handle_close' ],
        ] );
    }

    // ── Handlers ──────────────────────────────────────────────────────────

    public function handle_open( array $args, array $key_record, bool $dry_run ): array {
        $name = $this->require_string( $args, 'name' );
        if ( $name === null ) {
            return $this->err( 'MISSING_PARAM', 'Parameter name is required', 'validation_failed' );
        }

        $desc   = (string) ( $args['description'] ?? '' );
        $key_id = (int) $key_record['id'];

        if ( $dry_run ) {
            return $this->ok( [ 'dry_run' => true, 'name' => $name ] );
        }

        $result = AICOM_Sessions::open( $key_id, $key_record['label'], $name, $desc );

        if ( $result === 'SESSION_ALREADY_OPEN' ) {
            return $this->err(
                'SESSION_ALREADY_OPEN',
                'A session is already open for this key. Call session.close() before opening a new one.',
                'validation_failed'
            );
        }

        return $this->ok( [
            'session_id' => (int) $result['id'],
            'name'       => $result['name'],
            'opened_at'  => $result['opened_at'],
        ] );
    }

    public function handle_close( array $args, array $key_record, bool $dry_run ): array {
        $key_id = (int) $key_record['id'];

        if ( $dry_run ) {
            $active = AICOM_Sessions::get_active( $key_id );
            return $this->ok( [ 'dry_run' => true, 'has_active_session' => (bool) $active ] );
        }

        $closed = AICOM_Sessions::close( $key_id );

        if ( ! $closed ) {
            return $this->err( 'NO_ACTIVE_SESSION', 'No active session to close.', 'validation_failed' );
        }

        $response = [
            'session_id' => (int) $closed['id'],
            'name'       => $closed['name'],
            'closed'     => true,
            'closed_at'  => $closed['closed_at'],
        ];

        $suggestion = $this->analyze_session_for_skill( (int) $closed['id'] );
        if ( $suggestion ) {
            $response['skill_suggestion'] = $suggestion;
        }

        return $this->ok( $response );
    }

    private function analyze_session_for_skill( int $session_id ): ?array {
        global $wpdb;

        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT tool_name, module, tool_class FROM {$wpdb->prefix}aicom_logs
                 WHERE session_id = %d AND status = 'success'
                 AND tool_name NOT IN ('session.open','session.close','skills.list','skills.get','skills.match','skills.run','skills.create','skills.suggest_from_session')
                 ORDER BY created_at ASC",
                $session_id
            ),
            ARRAY_A
        ) ?: [];

        // Only suggest if session had at least 2 meaningful write/destructive actions
        $write_actions = array_filter( $logs, fn( $l ) => in_array( $l['tool_class'], [ 'write', 'destructive', 'admin_sensitive' ], true ) );
        if ( count( $write_actions ) < 2 ) {
            return null;
        }

        $tool_sequence = array_column( $logs, 'tool_name' );
        $modules_used  = array_values( array_unique( array_column( $logs, 'module' ) ) );

        $candidate = [
            'name'  => 'Session workflow',
            'steps' => array_map( fn( $t ) => [ 'action' => $t ], $tool_sequence ),
            'tags'  => $modules_used,
            'type'  => 'simple',
        ];

        $similar = AICOM_Skills::find_similar( $candidate );
        $top     = $similar[0] ?? null;

        if ( $top && $top['score'] >= 0.85 ) {
            return [
                'should_suggest'  => false,
                'match_type'      => 'use_existing',
                'message'         => "This workflow matches the existing skill \"{$top['skill']['name']}\" (score: {$top['score']}). Consider using skills.run next time.",
                'matched_skill'   => [ 'id' => $top['skill']['id'], 'name' => $top['skill']['name'], 'slug' => $top['skill']['slug'] ],
            ];
        }

        if ( $top && $top['score'] >= 0.60 ) {
            return [
                'should_suggest'  => true,
                'match_type'      => 'clarify',
                'message'         => "This workflow is similar to \"{$top['skill']['name']}\" (score: {$top['score']}). Ask the user: would you like to save this as a new skill, or update the existing one?",
                'tool_sequence'   => $tool_sequence,
                'similar_skill'   => [ 'id' => $top['skill']['id'], 'name' => $top['skill']['name'], 'slug' => $top['skill']['slug'] ],
            ];
        }

        return [
            'should_suggest' => true,
            'match_type'     => 'create_new',
            'message'        => 'This looks like a repeatable workflow. Ask the user: "I noticed a repeatable pattern in this session — would you like me to save it as a skill for future use?"',
            'tool_sequence'  => $tool_sequence,
            'similar_skills' => array_slice( $similar, 0, 3 ),
        ];
    }
}
