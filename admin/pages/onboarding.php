<?php
defined( 'ABSPATH' ) || exit;

( function () {

$step  = isset( $_GET['step'] ) ? (int) $_GET['step'] : 1;
if ( $step < 1 || $step > 2 ) { $step = 1; }

$skip_url       = admin_url( 'admin.php?page=aicom' );
$skip_form_url  = admin_url( 'admin-post.php' );

// Client cards. The wizard saves the chosen one to localStorage so the
// key-created modal pre-selects it at the end.
$clients = [
    'general' => [
        'name'        => __( 'General', 'aicom' ),
        'tag'         => __( 'Works with any MCP-aware agent', 'aicom' ),
        'recommended' => true,
    ],
    'claude-desktop' => [
        'name' => __( 'Claude Desktop', 'aicom' ),
        'tag'  => __( 'A desktop chat app', 'aicom' ),
    ],
    'claude-code' => [
        'name' => __( 'Claude Code', 'aicom' ),
        'tag'  => __( 'In your terminal', 'aicom' ),
    ],
    'cursor' => [
        'name' => __( 'Cursor IDE', 'aicom' ),
        'tag'  => __( 'AI code editor', 'aicom' ),
    ],
    'chatgpt' => [
        'name' => __( 'ChatGPT (Custom GPT)', 'aicom' ),
        'tag'  => __( 'OpenAI Actions', 'aicom' ),
    ],
    'generic' => [
        'name' => __( 'Something else', 'aicom' ),
        'tag'  => __( 'Any MCP-aware client', 'aicom' ),
    ],
];

// Presets we offer in step 2 — only the gentle three. Power users can build
// custom keys later on the Manage API Keys page.
$gentle_presets = array_intersect_key(
    AICOM_Admin::system_presets(),
    array_flip( [ 'read-only', 'content-assistant', 'elementor-editor' ] )
);

?>
<!DOCTYPE html><div class="wrap">
<div class="aicom-onboarding">

    <div class="aicom-onboarding-header">
        <img src="<?php echo esc_url( AICOM_URL . 'assets/branding/aicom-logo-primary.svg' ); ?>" alt="aicom" class="aicom-onboarding-logo">
        <p class="aicom-onboarding-progress">
            <span class="aicom-onboarding-pill <?php echo $step === 1 ? 'is-active' : 'is-done'; ?>">1</span>
            <span class="aicom-onboarding-pill-line"></span>
            <span class="aicom-onboarding-pill <?php echo $step === 2 ? 'is-active' : ''; ?>">2</span>
        </p>
    </div>

    <?php if ( $step === 1 ) : ?>

        <h1><?php esc_html_e( 'Welcome in.', 'aicom' ); ?> <em><?php esc_html_e( 'Two quick questions.', 'aicom' ); ?></em></h1>
        <p class="aicom-onboarding-lede"><?php esc_html_e( "AICOM is a quiet bridge between the AI you already pay for and the WordPress site you already love. Let's tell it who you are.", 'aicom' ); ?></p>

        <h2><?php esc_html_e( 'Which AI do you use?', 'aicom' ); ?></h2>
        <p class="aicom-onboarding-sub"><?php esc_html_e( "We'll show you the exact setup for that one — no guessing.", 'aicom' ); ?></p>

        <div class="aicom-onboarding-clients" id="aicom-onboarding-clients">
            <?php foreach ( $clients as $key => $c ) : ?>
                <button type="button" class="aicom-onboarding-client<?php echo ! empty( $c['recommended'] ) ? ' is-recommended' : ''; ?>" data-client="<?php echo esc_attr( $key ); ?>"<?php echo ! empty( $c['recommended'] ) ? ' data-recommended="1"' : ''; ?>>
                    <?php if ( ! empty( $c['recommended'] ) ) : ?>
                        <span class="aicom-onboarding-client-badge"><?php esc_html_e( 'Recommended', 'aicom' ); ?></span>
                    <?php endif; ?>
                    <span class="aicom-onboarding-client-name"><?php echo esc_html( $c['name'] ); ?></span>
                    <span class="aicom-onboarding-client-tag"><?php echo esc_html( $c['tag'] ); ?></span>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="aicom-onboarding-actions">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=aicom-onboarding&step=2' ) ); ?>" class="button button-primary button-hero aicom-onboarding-next" id="aicom-onboarding-next-1"><?php esc_html_e( 'Next →', 'aicom' ); ?></a>
            <form method="post" action="<?php echo esc_url( $skip_form_url ); ?>" style="display:inline">
                <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
                <input type="hidden" name="action" value="aicom_save" />
                <input type="hidden" name="aicom_action" value="skip_onboarding" />
                <button type="submit" class="aicom-onboarding-skip"><?php esc_html_e( "I'll set it up later — skip", 'aicom' ); ?></button>
            </form>
        </div>

    <?php else : ?>

        <h1><?php esc_html_e( 'What should it be able to do?', 'aicom' ); ?></h1>
        <p class="aicom-onboarding-lede"><?php esc_html_e( "Pick how much access your AI gets. You can always change this later or make more keys with different powers.", 'aicom' ); ?></p>

        <?php if ( isset( $_GET['error'] ) && $_GET['error'] === 'invalid_preset' ) : ?>
            <div class="aicom-onboarding-error"><?php esc_html_e( 'Please pick one of the options below.', 'aicom' ); ?></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( $skip_form_url ); ?>" class="aicom-onboarding-form">
            <?php wp_nonce_field( AICOM_Admin::NONCE_ACTION ); ?>
            <input type="hidden" name="action" value="aicom_save" />
            <input type="hidden" name="aicom_action" value="complete_onboarding" />

            <div class="aicom-preset-grid aicom-onboarding-preset-grid">
                <?php $i = 0; foreach ( $gentle_presets as $slug => $p ) : $i++; ?>
                    <label class="aicom-preset-card aicom-preset-risk-<?php echo esc_attr( $p['risk'] ); ?> aicom-onboarding-preset-label">
                        <input type="radio" name="preset" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $i === 1 ); ?> />
                        <span class="aicom-preset-name"><?php echo esc_html( $p['name'] ); ?></span>
                        <span class="aicom-preset-desc"><?php echo esc_html( $p['desc'] ); ?></span>
                        <span class="aicom-preset-count">
                            <?php
                            /* translators: %d: number of scopes */
                            printf( esc_html( _n( '%d permission', '%d permissions', count( $p['scopes'] ), 'aicom' ) ), count( $p['scopes'] ) );
                            ?>
                        </span>
                        <span class="aicom-risk-badge aicom-risk-<?php echo esc_attr( $p['risk'] ); ?>"><?php echo esc_html( strtoupper( $p['risk'] ) ); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <p class="aicom-onboarding-tip"><?php esc_html_e( "Tip — start safe. You can always make a more powerful key once you trust your agent.", 'aicom' ); ?></p>

            <div class="aicom-onboarding-lockdown">
                <label class="aicom-onboarding-lockdown-row">
                    <input type="checkbox" name="enable_lockdown" value="1" checked />
                    <span>
                        <strong><?php esc_html_e( 'Block external write paths — recommended', 'aicom' ); ?></strong>
                        <span class="aicom-onboarding-lockdown-desc">
                            <?php esc_html_e( "Closes Application Passwords, XML-RPC, and unsigned REST writes. Your AI agent must go through AICOM to change anything — every edit is scope-checked, session-tracked, and audited. wp-admin and Gutenberg keep working normally. You can flip this off any time on the Safety page.", 'aicom' ); ?>
                        </span>
                    </span>
                </label>
            </div>

            <div class="aicom-onboarding-actions">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=aicom-onboarding&step=1' ) ); ?>" class="aicom-onboarding-back">← <?php esc_html_e( 'Back', 'aicom' ); ?></a>
                <button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Generate my first key →', 'aicom' ); ?></button>
            </div>
        </form>

    <?php endif; ?>

</div>
</div>

<script>
(function () {
    // Step 1: save chosen client to localStorage so the key-created modal
    // (which already supports a per-client picker) lands on the right tab.
    var buttons = document.querySelectorAll('.aicom-onboarding-client');
    var nextBtn = document.getElementById('aicom-onboarding-next-1');

    // Restore previous pick if user navigates back; otherwise pre-select
    // the recommended option so users who tap Next without choosing still
    // get a sensible default ("General" works with any MCP-aware agent).
    var saved = null;
    try { saved = localStorage.getItem('aicom_pref_client'); } catch (e) {}
    var picked = false;
    if (saved) {
        buttons.forEach(function (b) {
            if (b.dataset.client === saved) { b.classList.add('is-active'); picked = true; }
        });
    }
    if (!picked) {
        buttons.forEach(function (b) {
            if (b.dataset.recommended === '1') {
                b.classList.add('is-active');
                try { localStorage.setItem('aicom_pref_client', b.dataset.client); } catch (e) {}
            }
        });
    }

    buttons.forEach(function (b) {
        b.addEventListener('click', function () {
            buttons.forEach(function (x) { x.classList.remove('is-active'); });
            b.classList.add('is-active');
            try { localStorage.setItem('aicom_pref_client', b.dataset.client); } catch (e) {}
        });
    });
})();
</script>
<?php
} )();
