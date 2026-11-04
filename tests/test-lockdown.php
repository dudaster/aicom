<?php
/**
 * AICOM-only mode (AICOM_Lockdown) REST gate: blocks administration from outside the site —
 * writes signed in by a non-cookie method — and nothing a visitor or the site's own pages do.
 * Live 2026-11-04: with the mode on, Contact Form 7 submissions (CF7 drops X-WP-Nonce on purpose)
 * and a signed-in customer's WooCommerce Store API cart were refused with 403.
 *
 * Calls the gate directly; changes no options. Run:
 *   docker compose exec -T wp php -d opcache.jit=0 /var/www/html/wp-content/plugins/aicom/tests/test-lockdown.php
 */

define( 'DOING_AJAX', true );
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require_once '/var/www/html/wp-load.php';

$pass = 0;
$fail = 0;

function ok( string $label, bool $cond, string $debug = '' ): void {
    global $pass, $fail;
    if ( $cond ) {
        echo "  [PASS] $label\n";
        $pass++;
    } else {
        echo "  [FAIL] $label" . ( $debug ? " — $debug" : '' ) . "\n";
        $fail++;
    }
}
function gate( string $method, string $route, string $nonce = '' ) {
    $req = new WP_REST_Request( $method, $route );
    if ( $nonce !== '' ) {
        $req->set_header( 'X-WP-Nonce', $nonce );
    }
    return AICOM_Lockdown::gate_rest_writes( null, rest_get_server(), $req );
}
function blocked( $r ): bool {
    return $r instanceof WP_Error && $r->get_error_code() === 'aicom_lockdown_rest_blocked';
}

$cf7   = '/contact-form-7/v1/contact-forms/123/feedback';
$cart  = '/wc/store/v1/cart/add-item';
$posts = '/wp/v2/posts';

echo "\n== Visitors ==\n";
wp_set_current_user( 0 );
unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
ok( 'reads pass', gate( 'GET', $posts ) === null );
ok( 'Contact Form 7 submission passes', gate( 'POST', $cf7 ) === null );
ok( 'WooCommerce Store API add-to-cart passes', gate( 'POST', $cart ) === null );
ok( 'anonymous comment passes', gate( 'POST', '/wp/v2/comments' ) === null );

echo "\n== Signed in, in the browser (login cookie) ==\n";
$cookie = wp_generate_auth_cookie( 1, time() + 3600, 'logged_in' );
$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
wp_set_current_user( 1 );
ok( 'signed-in customer\'s Store API cart passes without X-WP-Nonce', gate( 'POST', $cart ) === null );
ok( 'wp-admin / Gutenberg write with a valid nonce passes', gate( 'POST', $posts, wp_create_nonce( 'wp_rest' ) ) === null );

echo "\n== Signed in from outside (JWT, OAuth, Basic Auth plugins — no login cookie) ==\n";
unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
wp_set_current_user( 1 );
ok( 'write without a nonce is blocked (403)', blocked( $r = gate( 'POST', $posts ) ) && ( $r->get_error_data()['status'] ?? 0 ) === 403 );
ok( 'delete without a nonce is blocked', blocked( gate( 'DELETE', $posts . '/1' ) ) );
ok( 'a bogus nonce doesn\'t help', blocked( gate( 'POST', $posts, 'nope' ) ) );
ok( 'reads still pass', gate( 'GET', $posts ) === null );
ok( 'AICOM\'s own endpoint passes', gate( 'POST', '/aicom/v1/mcp' ) === null );
// Signed in from outside as user B while the request also carries user A's login cookie.
$other = (int) wp_insert_user( [ 'user_login' => 'aicom_lockdown_test_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'role' => 'editor' ] );
$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
wp_set_current_user( $other );
ok( 'someone else\'s login cookie doesn\'t let a different signed-in user through', $other > 1 && blocked( gate( 'POST', $posts ) ) );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $other );

echo "\n== Other filters ==\n";
$short = new WP_Error( 'other', 'x' );
ok( 'a result another filter already returned is kept', AICOM_Lockdown::gate_rest_writes( $short, rest_get_server(), new WP_REST_Request( 'POST', $posts ) ) === $short );

// Clean up the login session the cookie created.
$parsed = wp_parse_auth_cookie( $cookie, 'logged_in' );
WP_Session_Tokens::get_instance( 1 )->destroy( $parsed['token'] );
unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
wp_set_current_user( 0 );

echo "\n" . ( $fail ? "FAILED: $fail failed, $pass passed" : "OK: $pass passed, 0 failed" ) . "\n";
exit( $fail ? 1 : 0 );
