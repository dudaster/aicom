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
            'description'     => 'Close the current session. Call this when you have finished your changes. If the response includes suggest_skill: true, reflect on what was done in this session: if the workflow looks repeatable and useful, call skills.match first to check whether a similar skill already exists, then ask the user: "Would you like me to save this as a reusable skill for next time?"',
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

        if ( get_option( 'aicom_skill_suggestions', '1' ) === '1' ) {
            $response['suggest_skill'] = true;
        }

        return $this->ok( $response );
    }
}
