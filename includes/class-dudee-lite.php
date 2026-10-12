<?php
/**
 * Dudee lite: a small guide on AICOM's own admin pages.
 *
 * He introduces himself once, offers a short tour of the AICOM menu, and on the AICOMBase page reminds the
 * admin how to connect (that is where the full Dudee lives and can help much more). Everything runs in the
 * browser from assets/dudee-lite/ — nothing is sent anywhere. Only his own state is saved, per user, in
 * user meta: whether he said hello, how the tour went, and whether he was hidden.
 *
 * Off switches: the per-user "Don't show Dudee again" (brought back from Help), the AICOM_DUDEE constant
 * (define( 'AICOM_DUDEE', false ) in wp-config.php) and the aicom_dudee_enabled filter.
 */
defined( 'ABSPATH' ) || exit;

class AICOM_Dudee_Lite {

    const META   = 'aicom_dudee_lite';
    const NONCE  = 'aicom_dudee_lite';
    const HANDLE = 'aicom-dudee-lite';

    /** What the browser may ask to record, and how each changes the saved state. */
    const OPS = [ 'greeted', 'tour_done', 'tour_stopped', 'hide', 'show' ];

    public function __construct() {
        add_action( 'admin_enqueue_scripts',     [ $this, 'enqueue' ] );
        add_action( 'wp_ajax_aicom_dudee_lite',  [ $this, 'ajax' ] );
    }

    public static function enabled(): bool {
        $on = ! ( defined( 'AICOM_DUDEE' ) && ! AICOM_DUDEE );
        return (bool) apply_filters( 'aicom_dudee_enabled', $on );
    }

    public static function state( int $user_id = 0 ): array {
        $raw = get_user_meta( $user_id ?: get_current_user_id(), self::META, true );
        $raw = is_array( $raw ) ? $raw : [];
        return [
            'hidden'  => ! empty( $raw['hidden'] ),
            'greeted' => ! empty( $raw['greeted'] ),
            'tour'    => in_array( $raw['tour'] ?? '', [ 'done', 'stopped' ], true ) ? $raw['tour'] : '',
        ];
    }

    /** His face, as a small static icon (same artwork as the live widget, minus the animated bits). */
    private static function avatar_svg(): string {
        return '<svg viewBox="-14 -1 128 108" width="28" height="28" aria-hidden="true" focusable="false" style="vertical-align:middle;margin-right:8px;position:relative;top:-2px">'
            . '<circle cx="101" cy="42" r="10" fill="#0982fd" stroke="#0670dd" stroke-width="2"/>'
            . '<circle cx="-1" cy="58" r="10" fill="#0982fd" stroke="#0670dd" stroke-width="2"/>'
            . '<path d="M50 10C77 10 94 30 94 55C94 79 75 96 50 96C25 96 6 80 6 56C6 31 24 10 50 10Z" fill="#0982fd"/>'
            . '<ellipse cx="73" cy="24" rx="8" ry="5" transform="rotate(38 73 24)" fill="#fff"/>'
            . '<circle cx="83" cy="37" r="3" fill="#fff"/>'
            . '<rect x="19" y="45" width="62" height="31" rx="15.5" fill="#02287f"/>'
            . '<g fill="#fff"><rect x="33" y="52" width="9" height="16" rx="4.5"/><rect x="58" y="52" width="9" height="16" rx="4.5"/></g>'
            . '<g fill="none" stroke="#fff" stroke-width="4.5" stroke-linecap="round"><path d="M32 64q5.5-8 11 0"/><path d="M57 64q5.5-8 11 0"/></g>'
            . '</svg>';
    }

    /** A "bring Dudee back" paragraph, for Help and the AICOMBase page; prints nothing unless he was hidden. */
    public static function show_link( string $label, string $class = '' ): void {
        if ( ! self::enabled() || ! current_user_can( AICOM_Admin::CAPABILITY ) || ! self::state()['hidden'] ) {
            return;
        }
        printf(
            '<p class="%s" data-aicom-dudee-show>%s<a href="#">%s</a></p>',
            esc_attr( $class ),
            self::avatar_svg(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static, hand-written SVG, no user input
            esc_html( $label )
        );
    }

    public function enqueue( string $hook ): void {
        if ( ! self::enabled() || ! current_user_can( AICOM_Admin::CAPABILITY ) ) {
            return;
        }
        $page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which page, not an action
        // Only AICOM's own pages, never the rest of wp-admin; not on the full-screen first-run wizard.
        if ( strpos( $hook, 'aicom' ) === false || strpos( $page, 'aicom' ) !== 0 || $page === 'aicom-onboarding' ) {
            return;
        }

        $js_path = AICOM_DIR . 'assets/dudee-lite/dudee-lite.js';
        wp_enqueue_script( self::HANDLE, AICOM_URL . 'assets/dudee-lite/dudee-lite.js', [], (string) filemtime( $js_path ), true );
        wp_localize_script( self::HANDLE, 'AICOM_DUDEE', [
            'ajax'  => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( self::NONCE ),
            'page'  => $page,
            'state' => self::state(),
            'base'  => [
                'status'  => AICOM_Base_State::status(),
                'page'    => admin_url( 'admin.php?page=' . AICOM_Base_Admin::PAGE ),
                'app'     => AICOM_Base_State::base_url() . '/app',
                'just'    => sanitize_key( wp_unslash( $_GET['base_msg'] ?? '' ) ) === 'synced', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ],
            't'     => self::strings(),
        ] );
    }

    public function ajax(): void {
        if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
            wp_send_json_error( 'bad_nonce', 403 );
        }
        if ( ! current_user_can( AICOM_Admin::CAPABILITY ) ) {
            wp_send_json_error( 'unauthorized', 403 );
        }
        $op = sanitize_key( wp_unslash( $_POST['op'] ?? '' ) );
        if ( ! in_array( $op, self::OPS, true ) ) {
            wp_send_json_error( 'bad_op', 400 );
        }

        $s = self::state();
        switch ( $op ) {
            case 'greeted':      $s['greeted'] = true; break;
            case 'tour_done':    $s['tour'] = 'done'; $s['greeted'] = true; break;
            case 'tour_stopped': $s['tour'] = $s['tour'] === 'done' ? 'done' : 'stopped'; $s['greeted'] = true; break;
            case 'hide':         $s['hidden'] = true; $s['greeted'] = true; break;
            case 'show':         $s['hidden'] = false; break;
        }
        update_user_meta( get_current_user_id(), self::META, $s );
        wp_send_json_success( $s );
    }

    /** Everything he says. Translated like the rest of AICOM. */
    private static function strings(): array {
        return [
            'name'        => __( 'Dudee', 'aicom' ),
            'open'        => __( 'Ask Dudee', 'aicom' ),
            'close'       => __( 'Not now', 'aicom' ),
            'greet'       => __( "Hi, I'm Dudee! I can show you around AICOM in under a minute. Want the tour?", 'aicom' ),
            'tourYes'     => __( 'Yes, show me', 'aicom' ),
            'notNow'      => __( 'Not now', 'aicom' ),
            'never'       => __( "Don't show Dudee again", 'aicom' ),
            'hidden'      => __( 'OK, I\'ll stay out of the way. You can bring me back from Help.', 'aicom' ),
            'menu'        => __( 'How can I help?', 'aicom' ),
            'menuTour'    => __( 'Show me around AICOM', 'aicom' ),
            'menuConnect' => __( 'Connect to AICOMBase', 'aicom' ),
            'menuOpen'    => __( 'Open AICOMBase', 'aicom' ),
            'menuWhy'     => __( 'What can you do in AICOMBase?', 'aicom' ),
            'next'        => __( 'Next', 'aicom' ),
            'stop'        => __( 'Stop the tour', 'aicom' ),
            /* translators: 1: current step, 2: number of steps */
            'stepOf'      => __( '%1$d of %2$d', 'aicom' ),
            'tour'        => [
                'aicom'            => __( 'Start here: pick what your AI may do, and AICOM makes a key with just those rights.', 'aicom' ),
                'aicom-api-keys'   => __( 'All your AI keys live here. You can pause, change or revoke any of them at any time.', 'aicom' ),
                'aicom-audit-logs' => __( 'Everything an AI did on your site, step by step: which key, what, and when.', 'aicom' ),
                'aicom-safety'     => __( 'Your locks. One click stops every AI change, and you can set working hours.', 'aicom' ),
                'aicom-backups'    => __( 'Before an AI changes something, AICOM keeps a snapshot, so you can undo what it did.', 'aicom' ),
                'aicom-help'       => __( 'Ready-made prompts, and how to connect Claude, ChatGPT and other AI agents.', 'aicom' ),
            ],
            /* Per-page walkthrough: starts automatically once the admin actually
             * lands on that page (by clicking the link Dudee was pointing at).
             * 'at' must match the exact text of a real heading/card title on
             * that page — see pageSpot() in dudee-lite.js. After the last step,
             * leads straight into the next cross-page tour stop — the whole
             * tour (page-to-page and within-page) only ends when the admin
             * explicitly stops it. */
            'page'        => [
                'aicom'            => [
                    [ 'at' => __( 'What kind of power do you want to give your AI Agent?', 'aicom' ), 'text' => __( "Pick a ready-made role and AICOM mints a key with just those permissions. You still confirm before it's created.", 'aicom' ) ],
                    [ 'at' => __( "Today's Activity", 'aicom' ), 'text' => __( "A live count of what your AI has done today: total calls, successes, and anything blocked.", 'aicom' ) ],
                    [ 'at' => __( 'Lock Controls', 'aicom' ), 'text' => __( 'Freeze all writes, or lock down to read-only, right from here.', 'aicom' ) ],
                    [ 'at' => __( 'Active Modules', 'aicom' ), 'text' => __( 'Which integrations — WooCommerce, Elementor, SEO, translations — are live and ready to use.', 'aicom' ) ],
                ],
                'aicom-api-keys'   => [
                    [ 'at' => __( 'Generate New Key', 'aicom' ), 'text' => __( 'Create a new key here: pick a ready-made role or build custom scopes, then generate it in one step.', 'aicom' ) ],
                    [ 'at' => __( 'Connection Info', 'aicom' ), 'text' => __( 'The endpoint URL your AI agent connects to.', 'aicom' ) ],
                    [ 'at' => __( 'Existing Keys', 'aicom' ), 'text' => __( 'Every key you\'ve created — pause, rotate, or revoke any of them instantly.', 'aicom' ) ],
                ],
                'aicom-audit-logs' => [
                    [ 'at' => __( 'Activity', 'aicom' ), 'text' => __( 'A day-by-day view of everything your AI did, colour-coded by risk.', 'aicom' ) ],
                    [ 'at' => __( 'Filter', 'aicom' ), 'text' => __( 'Narrow the log down by key, tool, or date range.', 'aicom' ) ],
                    [ 'at' => __( 'Log Entries', 'aicom' ), 'text' => __( 'Every single call: which key, what it did, and when — with a one-click restore for any session.', 'aicom' ) ],
                ],
                'aicom-safety'     => [
                    [ 'at' => __( 'Current Lock Status', 'aicom' ), 'text' => __( 'Whether the site is locked right now, and why.', 'aicom' ) ],
                    [ 'at' => __( 'Lock Controls', 'aicom' ), 'text' => __( 'Soft Lock stops writes; Hard Lock stops everything except status checks.', 'aicom' ) ],
                    [ 'at' => __( 'Working Hours Schedule', 'aicom' ), 'text' => __( 'Auto-lock outside the hours and days you set.', 'aicom' ) ],
                    [ 'at' => __( 'Lock Permission Matrix', 'aicom' ), 'text' => __( 'The exact table of what each lock state allows.', 'aicom' ) ],
                ],
                'aicom-backups'    => [
                    [ 'at' => __( 'Overview', 'aicom' ), 'text' => __( 'How many snapshots exist, and how much space they use.', 'aicom' ) ],
                    [ 'at' => __( 'Sessions with Snapshots', 'aicom' ), 'text' => __( 'Restore everything one AI session touched, in one click.', 'aicom' ) ],
                    [ 'at' => __( 'Cleanup Settings', 'aicom' ), 'text' => __( 'Auto-delete old snapshots by age or total size.', 'aicom' ) ],
                ],
                'aicom-help'       => [
                    [ 'at' => __( 'Make a visitor\'s pass.', 'aicom' ), 'text' => __( 'How to create an API key for your AI agent.', 'aicom' ) ],
                    [ 'at' => __( 'Show them inside.', 'aicom' ), 'text' => __( 'How to connect Claude, ChatGPT, or another AI agent to this site.', 'aicom' ) ],
                    [ 'at' => __( 'Things folks have asked us.', 'aicom' ), 'text' => __( 'Answers to the most common questions.', 'aicom' ) ],
                ],
            ],
            'pitch'       => __( "Here I can do much more. Connect this site to AICOMBase and I'll tell you when it goes down or has a security problem, explain what your AI did and why something was stopped, and walk you through anything, step by step.", 'aicom' ),
            'pitchAsk'    => __( 'Want me to show you how to connect?', 'aicom' ),
            'pitchDone'   => __( 'This site is connected to AICOMBase. That is where I can help you the most, so look for me there.', 'aicom' ),
            'showHow'     => __( 'Show me how', 'aicom' ),
            'later'       => __( 'Maybe later', 'aicom' ),
            'thanks'      => __( 'Thanks', 'aicom' ),
            'remind'      => __( 'Want to connect this site to AICOMBase? I can show you how. It takes about a minute.', 'aicom' ),
            'whatGet'     => __( 'What do I get?', 'aicom' ),
            'connect1'    => __( 'Press this button. AICOMBase opens in a new tab: sign in there, or create a free account, then confirm the connection.', 'aicom' ),
            'connect2'    => __( "I'm waiting for you to confirm in the AICOMBase tab. This page updates by itself once it's done. If the tab didn't open, use this button.", 'aicom' ),
            'connected'   => __( 'Done, this site is connected! From now on I can help you in AICOMBase: alerts, explanations and step-by-step guides.', 'aicom' ),
            'gotIt'       => __( 'Got it', 'aicom' ),
        ];
    }
}
