<?php
defined( 'ABSPATH' ) || exit;

( function () {

$mcp_url     = get_site_url() . '/wp-json/aicom/v1/mcp';
$fallback    = get_site_url() . '/?aicom=1';
$schema_url  = get_site_url() . '/wp-json/aicom/v1/schema';
$keys_url    = admin_url( 'admin.php?page=aicom-api-keys' );
$safety_url  = admin_url( 'admin.php?page=aicom-safety' );
$backups_url = admin_url( 'admin.php?page=aicom-backups' );

// ── Recipe library ────────────────────────────────────────────────────────
// Each card the user can pick. The data is also serialised into JS below for
// the live-update of the output panel.
$recipes = [
    'writing' => [
        'kicker' => __( 'a long-postponed post', 'aicom' ),
        'title'  => __( 'Write something fresh', 'aicom' ),
        'story'  => __( 'On a slow Tuesday afternoon, you ask for a starter guide to be drafted and tucked into the right category — checklist at the end, friendly tone — saved as a draft for you to look over later.', 'aicom' ),
        'perms'  => [
            __( 'Read posts and pages', 'aicom' ),
            __( 'Create and edit posts', 'aicom' ),
            __( 'Edit page settings (categories, tags)', 'aicom' ),
        ],
        'prompt' => __( 'Draft me a 600-word starter guide about indoor plants for beginners. Friendly tone, ending with a five-point checklist. Save it as a draft under the Gardening category, and let me know when it\'s ready.', 'aicom' ),
    ],
    'seo' => [
        'kicker' => __( 'three years of forgotten captions', 'aicom' ),
        'title'  => __( 'Tidy up the SEO', 'aicom' ),
        'story'  => __( 'You ask for a quiet audit of the last twenty posts — anything with a short title or missing description — and the helper writes new ones, applies them, and shows you a summary.', 'aicom' ),
        'perms'  => [
            __( 'Read posts and pages', 'aicom' ),
            __( 'Read SEO fields (Yoast)', 'aicom' ),
            __( 'Update SEO fields (Yoast)', 'aicom' ),
        ],
        'prompt' => __( 'Have a look at our last twenty published posts. Find anything with a missing meta description or a title under thirty characters. Write better ones, apply them, and show me a short summary of what changed.', 'aicom' ),
    ],
    'a11y' => [
        'kicker' => __( 'the tab you keep meaning to open', 'aicom' ),
        'title'  => __( 'Help the screen readers', 'aicom' ),
        'story'  => __( 'You ask for a quick site report on images without descriptions, then have the ten most-viewed ones described kindly. Before and after, side by side, for your taste.', 'aicom' ),
        'perms'  => [
            __( 'Read posts and pages', 'aicom' ),
            __( 'Run accessibility audits', 'aicom' ),
            __( 'Manage the media library', 'aicom' ),
        ],
        'prompt' => __( 'Run a site-wide accessibility report. Find the images that are missing descriptions. Write thoughtful descriptions for the ten most-viewed ones, show me before and after, then save the changes.', 'aicom' ),
    ],
    'elementor' => [
        'kicker' => __( 'a new page, three sentences', 'aicom' ),
        'title'  => __( 'Spin up a new page', 'aicom' ),
        'story'  => __( 'You ask for a copy of an existing page, with one headline swapped — and a new landing exists by the time the kettle is full.', 'aicom' ),
        'perms'  => [
            __( 'Read posts and pages', 'aicom' ),
            __( 'Create and edit posts', 'aicom' ),
            __( 'Edit Elementor pages', 'aicom' ),
        ],
        'prompt' => __( 'Make a copy of the Services page. Call the copy Pricing. Change the big headline to "Plans that grow with you." Leave everything else exactly as it was. Save it as a draft.', 'aicom' ),
    ],
    'shop' => [
        'kicker' => __( 'the shelf that quietly emptied', 'aicom' ),
        'title'  => __( 'Mind the shop', 'aicom' ),
        'story'  => __( 'You ask for a list of products that have been out of stock for a month or more, each one quietly moved to draft with a note.', 'aicom' ),
        'perms'  => [
            __( 'Read posts and pages', 'aicom' ),
            __( 'Manage WooCommerce products', 'aicom' ),
        ],
        'prompt' => __( 'List products that have been out of stock for more than thirty days. For each one, change the status to draft and add a short note explaining why.', 'aicom' ),
    ],
    'backup' => [
        'kicker' => __( 'a just-in-case net', 'aicom' ),
        'title'  => __( 'Take a snapshot first', 'aicom' ),
        'story'  => __( 'Before you let your helper change anything, you ask for snapshots of the pages that matter. So you can sleep — and ship.', 'aicom' ),
        'perms'  => [
            __( 'Read posts and pages', 'aicom' ),
            __( 'Take and restore snapshots', 'aicom' ),
        ],
        'prompt' => __( 'Before you touch anything: take a snapshot of the home page and our three landing pages. Save the session as "Before launch" so I can roll back the whole thing later if I need to.', 'aicom' ),
    ],
    'skill' => [
        'kicker' => __( 'watch, then remember', 'aicom' ),
        'title'  => __( 'Save a routine for later', 'aicom' ),
        'story'  => __( 'You walk your helper through a workflow once — and ask them to save the steps as a recipe you can call back next week with one sentence.', 'aicom' ),
        'perms'  => [
            __( 'Read posts and pages', 'aicom' ),
            __( 'Create and update skills', 'aicom' ),
        ],
        'prompt' => __( 'Every Monday I publish a product review. Watch what I do this morning, then save the workflow as a skill called Weekly product review. Next time, when I ask for it by name, you\'ll know exactly what to do.', 'aicom' ),
    ],
    'undo' => [
        'kicker' => __( 'a one-line rewind', 'aicom' ),
        'title'  => __( 'Undo an afternoon\'s work', 'aicom' ),
        'story'  => __( 'You ask for everything from a particular session to be put back as it was. Calm returns. The audit log keeps the receipts.', 'aicom' ),
        'perms'  => [
            __( 'Read posts and pages', 'aicom' ),
            __( 'Take and restore snapshots', 'aicom' ),
        ],
        'prompt' => __( 'Yesterday\'s session called "Bulk SEO update" went a little too far. Restore everything from that session to where it was before. Show me a list of what you put back.', 'aicom' ),
    ],
];

// ── Helper library (AI clients) ──────────────────────────────────────────
// Same card→output pattern as recipes. Each card represents one AI client.
$helpers = [
    'claude-code' => [
        'name'    => __( 'Claude Code', 'aicom' ),
        'where'   => __( 'in your terminal', 'aicom' ),
        'intro'   => __( 'In a terminal, inside any project folder — paste this and swap in your pass.', 'aicom' ),
        'snippet' => 'claude mcp add aicom ' . $mcp_url . " \\\n  --transport http \\\n  --header \"Authorization: Bearer YOUR_KEY_HERE\"",
        'after'   => __( 'Then in any chat: "Show me my last ten published posts via aicom."', 'aicom' ),
    ],
    'claude-desktop' => [
        'name'    => __( 'Claude Desktop', 'aicom' ),
        'where'   => __( 'in your config file', 'aicom' ),
        'intro'   => __( 'Open the config file (on a Mac, that\'s ~/Library/Application Support/Claude/claude_desktop_config.json) and paste this in. Restart the app.', 'aicom' ),
        'snippet' => "{\n  \"mcpServers\": {\n    \"aicom\": {\n      \"url\": \"" . $mcp_url . "\",\n      \"headers\": {\n        \"Authorization\": \"Bearer YOUR_KEY_HERE\"\n      }\n    }\n  }\n}",
        'after'   => '',
    ],
    'cursor' => [
        'name'    => __( 'Cursor IDE', 'aicom' ),
        'where'   => __( 'in .cursor/mcp.json', 'aicom' ),
        'intro'   => __( 'Create .cursor/mcp.json in your project (or ~/.cursor/mcp.json for every project) and paste this in. Reload Cursor.', 'aicom' ),
        'snippet' => "{\n  \"mcpServers\": {\n    \"aicom\": {\n      \"url\": \"" . $mcp_url . "\",\n      \"type\": \"http\",\n      \"headers\": {\n        \"Authorization\": \"Bearer YOUR_KEY_HERE\"\n      }\n    }\n  }\n}",
        'after'   => '',
    ],
    'chatgpt' => [
        'name'    => __( 'ChatGPT', 'aicom' ),
        'where'   => __( 'Custom GPT · Actions', 'aicom' ),
        'intro'   => __( 'In the Custom GPT builder, add an Action and point it at this URL. For Authentication, pick API Key → Bearer and paste your pass.', 'aicom' ),
        'snippet' => $schema_url,
        'after'   => __( 'All 170+ tools are discovered for you automatically.', 'aicom' ),
    ],
    'n8n' => [
        'name'    => __( 'n8n, Make, Zapier', 'aicom' ),
        'where'   => __( 'plain HTTP node', 'aicom' ),
        'intro'   => __( 'A plain HTTP node does the job — paste this template into it and swap in your pass.', 'aicom' ),
        'snippet' => 'POST ' . $mcp_url . "\nContent-Type: application/json\nAuthorization: Bearer YOUR_KEY_HERE\n\n{\n  \"jsonrpc\": \"2.0\",\n  \"method\": \"wp.posts.list\",\n  \"params\": { \"per_page\": 5 },\n  \"id\": 1\n}",
        'after'   => '',
    ],
    'openapi' => [
        'name'    => __( 'Other OpenAPI helpers', 'aicom' ),
        'where'   => __( 'Dify, Flowise, LangChain…', 'aicom' ),
        'intro'   => __( 'Point your tool at this OpenAPI schema URL with Bearer auth, and all the tools are discovered for you.', 'aicom' ),
        'snippet' => $schema_url,
        'after'   => __( 'Works with Dify, Flowise, LangChain, Semantic Kernel, Microsoft Copilot Studio — anything fluent in OpenAPI 3.0.', 'aicom' ),
    ],
];

// Serialise for JS
$recipes_json = wp_json_encode( $recipes );
$helpers_json = wp_json_encode( $helpers );

?>
<?php include AICOM_DIR . 'admin/partials/layout-top.php'; ?>

    <div class="aicom-help-page">

        <!-- ── Hero ─────────────────────────────────────────────────────── -->
        <section class="aicom-help-hero">
            <p class="aicom-help-kicker">
                <span class="aicom-help-rule"></span>
                <?php esc_html_e( 'A short letter to start', 'aicom' ); ?>
                <span class="aicom-help-rule"></span>
            </p>
            <h1>
                <?php esc_html_e( 'Welcome in.', 'aicom' ); ?>
                <em><?php esc_html_e( 'Pull up a chair.', 'aicom' ); ?></em>
            </h1>
            <p class="aicom-help-hero-lead">
                <?php esc_html_e( 'AICOM is a quiet bridge between the AI you already pay for and the website you love. Your helper does the typing; you keep an eye on things from the kitchen. Pick a thing you\'d like done below — we\'ll write the words for you to pass along.', 'aicom' ); ?>
            </p>
            <p class="aicom-help-signed">
                <?php esc_html_e( '— a short read, maybe four minutes', 'aicom' ); ?>
            </p>
        </section>

        <div class="aicom-help-fleuron" aria-hidden="true">&#10086;</div>

        <!-- ── Three steps ──────────────────────────────────────────────── -->
        <section class="aicom-help-recipe">

            <div class="aicom-help-recipe-step">
                <div class="aicom-help-recipe-num">i.</div>
                <h3><?php esc_html_e( 'Make a visitor\'s pass.', 'aicom' ); ?></h3>
                <p><?php
                    printf(
                        wp_kses(
                            /* translators: %s = link to API Keys page */
                            __( 'Stop by <a href="%s">the keys drawer</a> and pick a starter set. Tick the permissions we suggest just below. Copy the pass the moment it appears — it won\'t show its face twice.', 'aicom' ),
                            [ 'a' => [ 'href' => true ] ]
                        ),
                        esc_url( $keys_url )
                    );
                ?></p>
            </div>

            <div class="aicom-help-recipe-step">
                <div class="aicom-help-recipe-num">ii.</div>
                <h3><?php esc_html_e( 'Show them inside.', 'aicom' ); ?></h3>
                <p><?php esc_html_e( 'You don\'t have to write a single line yourself. Further down, pick which AI helper you use — we have the exact text already written for it. Tap copy, paste it where your helper expects, done.', 'aicom' ); ?></p>
            </div>

            <div class="aicom-help-recipe-step">
                <div class="aicom-help-recipe-num">iii.</div>
                <h3><?php esc_html_e( 'Tell them what\'s on your mind.', 'aicom' ); ?></h3>
                <p><?php esc_html_e( 'No magic words to learn. Use the little builder below: tap a card, copy the sentence it writes for you, and paste it into your helper. They take it from there.', 'aicom' ); ?></p>
            </div>

        </section>

        <div class="aicom-help-fleuron" aria-hidden="true">&#10086;</div>

        <!-- ── Interactive Builder ──────────────────────────────────────── -->
        <section class="aicom-help-builder">
            <div class="aicom-help-section-head">
                <p class="aicom-help-kicker">
                    <span class="aicom-help-rule"></span>
                    <?php esc_html_e( 'The little builder', 'aicom' ); ?>
                    <span class="aicom-help-rule"></span>
                </p>
                <h2><?php esc_html_e( 'Pick a thing you\'d like done. We\'ll write it for you.', 'aicom' ); ?></h2>
                <p><?php esc_html_e( 'Tap a card. We\'ll show you which permissions to tick when you make the pass, and we\'ll write a sentence you can copy straight into your helper\'s chat window.', 'aicom' ); ?></p>
            </div>

            <div class="aicom-help-recipes" id="aicom-help-recipes" role="tablist">
                <?php $i = 0; foreach ( $recipes as $key => $r ) : $i++; ?>
                    <button type="button"
                            class="aicom-help-recipe-card<?php echo $i === 1 ? ' is-active' : ''; ?>"
                            data-recipe="<?php echo esc_attr( $key ); ?>"
                            role="tab"
                            aria-selected="<?php echo $i === 1 ? 'true' : 'false'; ?>">
                        <span class="aicom-help-recipe-card-cat"><?php echo esc_html( $r['kicker'] ); ?></span>
                        <span class="aicom-help-recipe-card-title"><?php echo esc_html( $r['title'] ); ?></span>
                        <span class="aicom-help-recipe-card-story"><?php echo esc_html( $r['story'] ); ?></span>
                        <span class="aicom-help-recipe-card-cue"><?php esc_html_e( 'tap to use this →', 'aicom' ); ?></span>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="aicom-help-output" id="aicom-help-output" aria-live="polite">

                <div class="aicom-help-output-col aicom-help-output-perms">
                    <p class="aicom-help-output-label"><?php esc_html_e( 'When you make the pass, tick these', 'aicom' ); ?></p>
                    <ul id="aicom-help-perms-list"></ul>
                    <a href="<?php echo esc_url( $keys_url ); ?>" class="aicom-help-output-link">
                        <?php esc_html_e( 'Open the keys drawer →', 'aicom' ); ?>
                    </a>
                </div>

                <div class="aicom-help-output-col aicom-help-output-prompt">
                    <p class="aicom-help-output-label"><?php esc_html_e( 'And paste this into your helper', 'aicom' ); ?></p>
                    <blockquote id="aicom-help-prompt-text"></blockquote>
                    <button type="button" class="aicom-help-copy-btn" id="aicom-help-copy">
                        <span class="aicom-help-copy-label"><?php esc_html_e( 'Copy', 'aicom' ); ?></span>
                    </button>
                </div>

            </div>
        </section>

        <div class="aicom-help-fleuron" aria-hidden="true">&#10086;</div>

        <!-- ── Wiring ──────────────────────────────────────────────────── -->
        <section>
            <div class="aicom-help-section-head">
                <p class="aicom-help-kicker">
                    <span class="aicom-help-rule"></span>
                    <?php esc_html_e( 'The wiring', 'aicom' ); ?>
                    <span class="aicom-help-rule"></span>
                </p>
                <h2><?php esc_html_e( 'Telling your helper where the door is.', 'aicom' ); ?></h2>
                <p><?php esc_html_e( 'No typing required. Find your helper in the list below, tap copy, and paste the text exactly where it asks. We\'ve written it all for you — the right address, the right greeting, the right shape. The two notes underneath are just there for reference if you\'re curious.', 'aicom' ); ?></p>
            </div>

            <div class="aicom-help-notes">
                <div class="aicom-help-note-card">
                    <span class="aicom-help-note-label"><?php esc_html_e( 'The address', 'aicom' ); ?></span>
                    <code><?php echo esc_html( $mcp_url ); ?></code>
                </div>
                <div class="aicom-help-note-card">
                    <span class="aicom-help-note-label"><?php esc_html_e( 'The greeting', 'aicom' ); ?></span>
                    <code><?php esc_html_e( 'Authorization: Bearer <your-key>', 'aicom' ); ?></code>
                </div>
            </div>

            <p class="aicom-help-aside"><?php
                printf(
                    /* translators: %s = fallback URL */
                    wp_kses(
                        __( 'If the address above gives trouble (some hosts rewrite things in odd ways), this one is a faithful backup: <code>%s</code>', 'aicom' ),
                        [ 'code' => [] ]
                    ),
                    esc_html( $fallback )
                );
            ?></p>

            <div class="aicom-help-helpers" id="aicom-help-helpers" role="tablist">
                <?php $hi = 0; foreach ( $helpers as $key => $h ) : $hi++; ?>
                    <button type="button"
                            class="aicom-help-helper-card<?php echo $hi === 1 ? ' is-active' : ''; ?>"
                            data-helper="<?php echo esc_attr( $key ); ?>"
                            role="tab"
                            aria-selected="<?php echo $hi === 1 ? 'true' : 'false'; ?>">
                        <span class="aicom-help-helper-name"><?php echo esc_html( $h['name'] ); ?></span>
                        <span class="aicom-help-helper-where"><?php echo esc_html( $h['where'] ); ?></span>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="aicom-help-output aicom-help-output--wiring" id="aicom-help-wiring-output" aria-live="polite">

                <div class="aicom-help-output-col aicom-help-output-intro">
                    <p class="aicom-help-output-label"><?php esc_html_e( 'Where this goes', 'aicom' ); ?></p>
                    <p class="aicom-help-helper-intro" id="aicom-help-helper-intro"></p>
                    <p class="aicom-help-helper-after" id="aicom-help-helper-after"></p>
                </div>

                <div class="aicom-help-output-col aicom-help-output-prompt">
                    <p class="aicom-help-output-label"><?php esc_html_e( 'Paste-ready text', 'aicom' ); ?></p>
                    <div class="aicom-help-snippet">
                        <pre id="aicom-help-helper-snippet"></pre>
                        <button type="button" class="aicom-help-snippet-copy" id="aicom-help-helper-copy"><?php esc_html_e( 'Copy', 'aicom' ); ?></button>
                    </div>
                </div>

            </div>
        </section>

        <div class="aicom-help-fleuron" aria-hidden="true">&#10086;</div>

        <!-- ── Quiet questions ──────────────────────────────────────────── -->
        <section>
            <div class="aicom-help-section-head">
                <p class="aicom-help-kicker">
                    <span class="aicom-help-rule"></span>
                    <?php esc_html_e( 'Quiet questions', 'aicom' ); ?>
                    <span class="aicom-help-rule"></span>
                </p>
                <h2><?php esc_html_e( 'Things folks have asked us.', 'aicom' ); ?></h2>
            </div>

            <div class="aicom-help-thread">

                <details class="aicom-help-q">
                    <summary><?php esc_html_e( 'Will this cost me a bit every time it does something?', 'aicom' ); ?></summary>
                    <p><?php esc_html_e( 'No. AICOM is free. Your helper uses the AI subscription you already pay for — whatever Claude or ChatGPT charges you each month — and nothing extra slips in on top.', 'aicom' ); ?></p>
                </details>

                <details class="aicom-help-q">
                    <summary><?php esc_html_e( 'Is it really safe to let a helper into the site?', 'aicom' ); ?></summary>
                    <p><?php esc_html_e( 'Safer than giving someone your password. Every pass has only the powers you grant. You can freeze the whole site with one click. Working Hours can lock helpers out at night. Everything they do is written down and reversible. You are always in charge.', 'aicom' ); ?></p>
                </details>

                <details class="aicom-help-q">
                    <summary><?php esc_html_e( 'I shared a pass and now I\'d like it back.', 'aicom' ); ?></summary>
                    <p><?php
                        printf(
                            /* translators: %s = API Keys URL */
                            wp_kses(
                                __( 'Open <a href="%s">the keys drawer</a>, find the row, click the small action menu and choose <strong>Revoke</strong>. Gone in a blink. To pause it instead, pick <strong>Suspend</strong> — that one is reversible.', 'aicom' ),
                                [ 'a' => [ 'href' => true ], 'strong' => [] ]
                            ),
                            esc_url( $keys_url )
                        );
                    ?></p>
                </details>

                <details class="aicom-help-q">
                    <summary><?php esc_html_e( 'My helper keeps getting 401 or 403. What\'s wrong?', 'aicom' ); ?></summary>
                    <p><?php
                        echo wp_kses(
                            __( '<strong>401</strong> usually means the pass isn\'t reaching us. Some web servers strip the greeting out — adding <code>SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1</code> to the site\'s <code>.htaccess</code> sets things right. <strong>403</strong> means the pass arrived but isn\'t allowed to do what you\'re asking — check what you ticked when you made it, the IP list, and whether the site is locked. The audit log tells you which.', 'aicom' ),
                            [ 'strong' => [], 'code' => [] ]
                        );
                    ?></p>
                </details>

                <details class="aicom-help-q">
                    <summary><?php esc_html_e( 'Can I keep a few different passes for different things?', 'aicom' ); ?></summary>
                    <p><?php esc_html_e( 'Of course. One that only reads. One that can write content. One for proper admin work. Each can have its own expiry, its own IP lock, its own dry-run flag. Mix as you like.', 'aicom' ); ?></p>
                </details>

                <details class="aicom-help-q">
                    <summary><?php esc_html_e( 'My helper says "no active session". What\'s that?', 'aicom' ); ?></summary>
                    <p><?php esc_html_e( 'Anything that changes things wants to be wrapped in a named session — that\'s what lets you undo a whole afternoon\'s work with one button later. Just tell your helper "Open a session called X, then go ahead." Read-only tasks don\'t need a session.', 'aicom' ); ?></p>
                </details>

                <details class="aicom-help-q">
                    <summary><?php esc_html_e( 'How do I tidy up old snapshots?', 'aicom' ); ?></summary>
                    <p><?php
                        printf(
                            /* translators: %s = Backups URL */
                            wp_kses(
                                __( '<a href="%s">Snapshots → Cleanup settings</a>. Set a maximum age (in days), a maximum size (in MB), or both. A daily routine sweeps the older ones away. You can also delete individual snapshots by hand on the Backup Snapshots tab.', 'aicom' ),
                                [ 'a' => [ 'href' => true ] ]
                            ),
                            esc_url( $backups_url )
                        );
                    ?></p>
                </details>

                <details class="aicom-help-q">
                    <summary><?php esc_html_e( 'Sessions, skills — what\'s the difference, really?', 'aicom' ); ?></summary>
                    <p><?php esc_html_e( 'A session is one afternoon of work. A skill is a recipe you can ask for again. Skills usually start their life inside a session: "I liked what we just did. Save it for next time."', 'aicom' ); ?></p>
                </details>

            </div>
        </section>

        <div class="aicom-help-fleuron" aria-hidden="true">&#10086;</div>

        <!-- ── Closing ──────────────────────────────────────────────────── -->
        <section class="aicom-help-closing">
            <h2><?php esc_html_e( 'And if something ever feels off —', 'aicom' ); ?></h2>
            <p><?php
                printf(
                    /* translators: %s = Safety URL */
                    wp_kses(
                        __( 'the <a href="%s">Safety</a> page is your kill switch. Three clicks and the whole place freezes. Nothing destructive can happen without your explicit yes.', 'aicom' ),
                        [ 'a' => [ 'href' => true ] ]
                    ),
                    esc_url( $safety_url )
                );
            ?></p>
            <p class="aicom-help-closing-signoff"><?php esc_html_e( 'Now — try a sentence and see.', 'aicom' ); ?></p>
        </section>

    </div>

    <script>
    (function () {
        var recipes = <?php echo $recipes_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already JSON-encoded, will be parsed as JS object ?>;
        var cards = document.querySelectorAll('.aicom-help-recipe-card');
        var permsList = document.getElementById('aicom-help-perms-list');
        var promptEl = document.getElementById('aicom-help-prompt-text');
        var copyBtn = document.getElementById('aicom-help-copy');
        var copyLabel = copyBtn ? copyBtn.querySelector('.aicom-help-copy-label') : null;
        var defaultCopyText = copyLabel ? copyLabel.textContent : 'Copy';

        var outputEl = document.getElementById('aicom-help-output');
        var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        function render(key, scroll) {
            var r = recipes[key];
            if (!r) return;
            cards.forEach(function (c) {
                var on = c.dataset.recipe === key;
                c.classList.toggle('is-active', on);
                c.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            permsList.innerHTML = r.perms.map(function (p) {
                return '<li>' + p + '</li>';
            }).join('');
            promptEl.textContent = '“' + r.prompt + '”';
            if (scroll && outputEl) {
                outputEl.scrollIntoView({
                    behavior: reducedMotion ? 'auto' : 'smooth',
                    block: 'center'
                });
            }
        }

        cards.forEach(function (card) {
            card.addEventListener('click', function () {
                render(card.dataset.recipe, true);
            });
        });

        // Generic copy helper — swaps button label, falls back to execCommand.
        function copyText(button, text, labelEl) {
            var originalText = labelEl ? labelEl.textContent : button.textContent;
            var setDone = function () {
                button.classList.add('is-copied');
                if (labelEl) { labelEl.textContent = '✓ Copied'; }
                else { button.textContent = '✓ Copied'; }
                setTimeout(function () {
                    button.classList.remove('is-copied');
                    if (labelEl) { labelEl.textContent = originalText; }
                    else { button.textContent = originalText; }
                }, 1600);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(setDone).catch(function () {
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); } catch (e) {}
                    document.body.removeChild(ta);
                    setDone();
                });
            }
        }

        // Prompt builder copy
        if (copyBtn && copyLabel) {
            copyBtn.addEventListener('click', function () {
                var text = promptEl.textContent.replace(/^“|”$/g, '');
                copyText(copyBtn, text, copyLabel);
            });
        }

        // ── Helper picker (wiring section) ────────────────────────────
        var helpers = <?php echo $helpers_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already JSON-encoded ?>;
        var helperCards = document.querySelectorAll('.aicom-help-helper-card');
        var helperIntro = document.getElementById('aicom-help-helper-intro');
        var helperAfter = document.getElementById('aicom-help-helper-after');
        var helperSnippet = document.getElementById('aicom-help-helper-snippet');
        var helperCopyBtn = document.getElementById('aicom-help-helper-copy');
        var helperOutput = document.getElementById('aicom-help-wiring-output');

        function renderHelper(key, scroll) {
            var h = helpers[key];
            if (!h) return;
            helperCards.forEach(function (c) {
                var on = c.dataset.helper === key;
                c.classList.toggle('is-active', on);
                c.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            helperIntro.textContent = h.intro || '';
            helperAfter.textContent = h.after || '';
            helperAfter.style.display = h.after ? '' : 'none';
            helperSnippet.textContent = h.snippet || '';
            if (scroll && helperOutput) {
                helperOutput.scrollIntoView({
                    behavior: reducedMotion ? 'auto' : 'smooth',
                    block: 'center'
                });
            }
        }

        helperCards.forEach(function (card) {
            card.addEventListener('click', function () {
                renderHelper(card.dataset.helper, true);
            });
        });

        if (helperCopyBtn) {
            helperCopyBtn.addEventListener('click', function () {
                copyText(helperCopyBtn, helperSnippet.textContent, null);
            });
        }

        // Snippet copy buttons elsewhere (if any remain)
        document.querySelectorAll('.aicom-help-snippet-copy[data-copy-target]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var targetId = btn.dataset.copyTarget;
                var target = targetId ? document.getElementById(targetId) : null;
                if (!target) return;
                copyText(btn, target.textContent, null);
            });
        });

        // First card pre-selected
        var first = document.querySelector('.aicom-help-recipe-card.is-active');
        if (first) render(first.dataset.recipe);

        var firstHelper = document.querySelector('.aicom-help-helper-card.is-active');
        if (firstHelper) renderHelper(firstHelper.dataset.helper);
    })();
    </script>

<?php include AICOM_DIR . 'admin/partials/layout-bottom.php'; ?>
<?php
} )();
