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

        $this->register( 'session.status', [
            'class'           => 'discovery',
            'required_scopes' => [],
            'description'     => 'Check whether a session is currently open for your API key. Returns session id, name, description, and how long it has been open. Call this before session.open to avoid SESSION_ALREADY_OPEN errors.',
            'input_schema'    => [],
            'handler'         => [ $this, 'handle_status' ],
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

        return $this->ok( array_merge(
            [
                'session_id' => (int) $result['id'],
                'name'       => $result['name'],
                'opened_at'  => $result['opened_at'],
            ],
            $this->capability_report( $key_record )
        ) );
    }

    /**
     * Available/missing scopes relevant to the site's active modules, so the
     * agent can tell upfront whether it can complete a task (e.g. a Polylang
     * translation workflow) before it starts and hits DENIED_SCOPE mid-way.
     * Scopes for inactive optional modules (WooCommerce, Elementor, Polylang,
     * Yoast, Clautron) are excluded from missing_scopes — they're not
     * actionable noise if the plugin isn't even installed.
     */
    private function capability_report( array $key_record ): array {
        $optional_module_keywords = [
            'woocommerce' => 'woocommerce',
            'elementor'   => 'elementor',
            'polylang'    => 'polylang',
            'yoast'       => 'yoast',
            'clautron'    => 'clautron',
        ];
        $active_modules = AICOM_Module_Detector::get_active_modules();

        $all_scopes = AICOM_Auth::scope_slugs();
        $key_scopes = json_decode( $key_record['scopes_json'] ?? '[]', true ) ?: [];

        $relevant_scopes = array_values( array_filter( $all_scopes, function ( $slug ) use ( $optional_module_keywords, $active_modules ) {
            foreach ( $optional_module_keywords as $needle => $module ) {
                if ( strpos( $slug, $needle ) !== false ) {
                    return in_array( $module, $active_modules, true );
                }
            }
            return true; // core scope — always relevant
        } ) );

        return [
            'available_scopes' => array_values( array_intersect( $all_scopes, $key_scopes ) ),
            'missing_scopes'   => array_values( array_diff( $relevant_scopes, $key_scopes ) ),
        ];
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

    public function handle_status( array $args, array $key_record, bool $dry_run ): array {
        $key_id  = (int) $key_record['id'];
        $session = AICOM_Sessions::get_active( $key_id );

        if ( ! $session ) {
            return $this->ok( [
                'active'  => false,
                'message' => 'No session open. Call session.open before any write operation.',
            ] );
        }

        $opened_at = strtotime( $session['opened_at'] ?? 'now' );
        $elapsed   = human_time_diff( $opened_at, time() );

        return $this->ok( [
            'active'      => true,
            'session_id'  => (int) $session['id'],
            'name'        => $session['name'],
            'description' => $session['description'] ?? '',
            'opened_at'   => $session['opened_at'],
            'open_for'    => $elapsed,
        ] );
    }
}
