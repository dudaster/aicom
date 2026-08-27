<?php
/**
 * Keyword search over AICOM's tool catalog (backs the `tools/search` MCP
 * tool, see modules/class-wp-core.php::handle_tools_search()).
 *
 * Built for weak/local models that phrase queries as full sentences with
 * vocabulary that doesn't literally appear in any tool's name or description
 * ("how do I add a photo to my page" -> media.set_featured). Plain substring
 * matching alone misses that class of query entirely, so this layers:
 *
 *   1. Stopword stripping   — drop connector words ("how", "do", "a", "to")
 *      so they don't dilute scoring.
 *   2. Literal matching     — exact word-boundary match beats a mid-word
 *      substring match; both beat nothing.
 *   3. Document-frequency damping — a token that appears on dozens of tools
 *      ("post", "set", "list") is nearly meaningless as a discriminator and
 *      is scored far below a token that appears on only one or two.
 *   4. CRUD-verb damping    — a small, explicit set of generic action verbs
 *      (get/set/list/create/update/add/make) gets an extra damping factor on
 *      top of (3): almost every tool has one of these, so on their own they
 *      should never outrank a specific noun match.
 *   5. Synonym expansion    — SYNONYMS maps vocabulary a weak model is likely
 *      to use ("photo", "shop", "trash") onto AICOM's actual domain words.
 *   6. Typo tolerance       — if a query word has zero literal presence
 *      anywhere in the scoped tool set, similar_text() against the corpus
 *      finds the closest real word and treats it as a low-confidence synonym.
 *
 * No PHP intl/mbstring dependency, no external index — recomputed per call
 * from the already-scoped tools/list output, which tops out around 100
 * tools, so this is cheap enough to not need caching.
 */
class AICOM_Tool_Search {

    private const STOPWORDS = [
        'a', 'an', 'the', 'to', 'of', 'for', 'on', 'in', 'is', 'are', 'was',
        'be', 'can', 'you', 'i', 'my', 'me', 'this', 'that', 'it', 'with',
        'and', 'or', 'what', 'which', 'does', 'do', 'how', 'want', 'need',
        'please', 'help', 'from', 'by', 'as', 'into', 'about', 'there', 'some',
        'like',
    ];

    // Generic CRUD verbs get an extra damping factor beyond plain
    // document-frequency weighting: almost every tool has a create/read/
    // update/list/set-shaped name or description, so alone they carry
    // near-zero discriminating power — a specific noun should always win.
    private const CRUD_VERBS       = [ 'get', 'set', 'list', 'create', 'update', 'add', 'make', 'manage' ];
    private const CRUD_VERB_DAMPING = 0.35;

    // Maps vocabulary a weak/local model is likely to use onto the actual
    // words that appear in AICOM's tool names/descriptions, grounded in the
    // real registry text (checked against every module at the time this was
    // written) rather than guessed. Query words not in this map still work
    // via direct substring matching against name/description — this only
    // covers cases where the user's word literally never appears on a tool.
    private const SYNONYMS = [
        // media / images
        'picture'       => [ 'image', 'media', 'featured' ],
        'photo'         => [ 'image', 'media', 'featured' ],
        'pic'           => [ 'image', 'media', 'featured' ],
        'thumbnail'     => [ 'featured', 'image' ],
        'cover'         => [ 'featured', 'image' ],
        'upload'        => [ 'media', 'upload' ],
        'file'          => [ 'media', 'files' ],
        'attachment'    => [ 'media' ],
        'alt'           => [ 'alt', 'a11y' ],
        'alttext'       => [ 'alt', 'a11y' ],
        'accessibility' => [ 'a11y' ],

        // content
        'article'  => [ 'post' ],
        'blog'     => [ 'post' ],
        'blogpost' => [ 'post' ],
        'page'     => [ 'post' ],
        'category' => [ 'term', 'taxonomy', 'categories' ],
        'tag'      => [ 'term', 'taxonomy' ],
        'remove'   => [ 'delete', 'trash' ],
        'erase'    => [ 'delete' ],
        'text'     => [ 'content', 'title' ],
        'summary'  => [ 'excerpt' ],

        // edit verbs
        'change' => [ 'update', 'set' ],
        'give'   => [ 'assign', 'set' ],
        'modify' => [ 'update' ],
        'edit'   => [ 'update' ],
        'show'   => [ 'list', 'get' ],
        'view'   => [ 'get', 'list' ],
        'see'    => [ 'list', 'get' ],
        'find'   => [ 'list', 'get', 'search' ],
        'check'  => [ 'status', 'get' ],
        'turn'   => [ 'activate', 'enable', 'toggle' ],
        'enable' => [ 'activate' ],
        'hide'   => [ 'draft', 'noindex' ],
        'undo'   => [ 'restore' ],
        'move'   => [ 'trash', 'move' ],

        // translation
        'translate'    => [ 'translation', 'polylang', 'language' ],
        'translation'  => [ 'polylang', 'language' ],
        'language'     => [ 'polylang', 'language' ],
        'multilingual' => [ 'polylang', 'language' ],

        // seo
        'metadescription' => [ 'metadesc', 'yoast' ],
        'keyword'         => [ 'focuskw', 'yoast' ],
        'keyphrase'       => [ 'focuskw', 'yoast' ],
        'graph'           => [ 'social', 'twitter' ],
        'facebook'        => [ 'social', 'og' ],

        // shop
        'shop'      => [ 'woocommerce', 'product' ],
        'store'     => [ 'woocommerce', 'product' ],
        'ecommerce' => [ 'woocommerce' ],
        'inventory' => [ 'stock' ],
        'checkout'  => [ 'woocommerce', 'order' ],
        'cart'      => [ 'woocommerce' ],
        // AICOM has no dedicated coupon tool — fall back to the closest
        // real capability (products/settings) instead of matching nothing.
        'coupon'    => [ 'woocommerce', 'product' ],

        // users
        'permission' => [ 'roles', 'scope' ],
        'author'     => [ 'users' ],
        'admin'      => [ 'users', 'roles' ],

        // menus
        'navigation' => [ 'menus' ],
        'nav'        => [ 'menus' ],

        // settings
        'setting' => [ 'options', 'settings' ],
        'option'  => [ 'options' ],

        // backup
        'snapshot' => [ 'backup' ],

        // design (ECS)
        'design' => [ 'ecs', 'style' ],
        'style'  => [ 'ecs' ],
        'colour' => [ 'color', 'ecs' ],
        'font'   => [ 'ecs', 'font' ],
        'css'    => [ 'ecs', 'css' ],
        'logo'   => [ 'ecs', 'logo' ],

        // elementor
        'builder' => [ 'elementor' ],
        'section' => [ 'elementor', 'widget' ],

        // skills / recipes
        'skill'    => [ 'skills' ],
        'recipe'   => [ 'recipes' ],
        'workflow' => [ 'recipes', 'skills' ],
        'howto'    => [ 'recipes' ],

        // site-wide
        'website'  => [ 'site' ],
        'homepage' => [ 'site' ],
    ];

    // Below this similar_text() percentage, a query word is not considered a
    // typo of a real vocabulary word — kept high to avoid false "corrections"
    // (e.g. "pale" -> "page") stealing weight from words that would have
    // matched fine as literal substrings anyway.
    private const FUZZY_MATCH_THRESHOLD = 78.0;

    /**
     * Returns full MCP tool objects (not a stripped-down summary) so a
     * matched tool can be called immediately without a follow-up tools/list
     * round-trip — the point of search is fewer tools in context, not less
     * detail per tool. Scoped to what $key_record can actually call, same
     * as AICOM_Tool_Registry::to_mcp_list().
     */
    public static function search( string $query, array $active_modules, array $key_record, int $limit = 10 ): array {
        $tools = AICOM_Tool_Registry::to_mcp_list( $active_modules, $key_record );

        $query = strtolower( trim( $query ) );
        $limit = max( 1, min( 25, $limit ) );

        if ( $query === '' ) {
            return array_slice( $tools, 0, $limit );
        }

        $words = self::extract_words( $query );
        [ $vocabulary, $doc_freq ] = self::build_corpus( $tools );
        $expansions = self::expand_words( $words, $vocabulary );
        $phrase     = implode( ' ', $words );

        $scored = [];
        foreach ( $tools as $tool ) {
            $score = self::score_tool( $tool, $words, $phrase, $expansions, $doc_freq );
            if ( $score > 0 ) {
                $scored[] = [ 'tool' => $tool, 'score' => $score ];
            }
        }

        usort( $scored, static fn( array $a, array $b ): int => $b['score'] <=> $a['score'] );

        return array_map( static fn( array $s ) => $s['tool'], array_slice( $scored, 0, $limit ) );
    }

    // ── Query parsing ───────────────────────────────────────────────────────

    private static function tokenize( string $text ): array {
        return array_values( array_filter( preg_split( '/[^a-z0-9]+/', strtolower( $text ) ) ) );
    }

    /**
     * Splits the query into significant words, stripping STOPWORDS. Falls
     * back to the unfiltered split if the whole query was stopwords (e.g. a
     * query of just "?") rather than matching nothing.
     */
    private static function extract_words( string $query ): array {
        $raw  = self::tokenize( $query );
        $kept = array_values( array_diff( $raw, self::STOPWORDS ) );
        return $kept ?: $raw;
    }

    // ── Corpus stats ────────────────────────────────────────────────────────

    /**
     * Returns [vocabulary, doc_freq]: the full set of literal tokens across
     * the scoped tool set, and how many distinct tools each token appears on.
     */
    private static function build_corpus( array $tools ): array {
        $vocabulary = [];
        $doc_freq   = [];
        foreach ( $tools as $tool ) {
            $seen = array_unique( self::tokenize( $tool['name'] . ' ' . $tool['description'] ) );
            foreach ( $seen as $tok ) {
                $vocabulary[ $tok ] = true;
                $doc_freq[ $tok ]   = ( $doc_freq[ $tok ] ?? 0 ) + 1;
            }
        }
        return [ $vocabulary, $doc_freq ];
    }

    /**
     * Inverse-document-frequency-style weight: 1.0 for a token unique to one
     * tool, decaying toward a small floor for a token shared by dozens.
     * CRUD verbs get an additional fixed penalty on top (see CRUD_VERBS).
     *
     * A token absent from doc_freq entirely is NOT treated as maximally
     * rare (df=1 would say "trust this fully") — it means the token never
     * appears as a real word anywhere in scope, so any score it's driving
     * only got there via the str_contains() substring fallback (e.g. query
     * word "change" substring-matching the unrelated corpus word "changes"
     * in session.open's description). That's a coincidence, not a match on
     * a real term, so it's capped low regardless of how "rare" it looks.
     */
    private static function term_weight( string $token, array $doc_freq ): float {
        $weight = isset( $doc_freq[ $token ] )
            ? max( 0.05, min( 1.0, 1.0 / $doc_freq[ $token ] ) )
            : 0.25;
        if ( in_array( $token, self::CRUD_VERBS, true ) ) {
            $weight *= self::CRUD_VERB_DAMPING;
        }
        return $weight;
    }

    // ── Query expansion ─────────────────────────────────────────────────────

    /**
     * For each query word: look up SYNONYMS, and — only if the word has no
     * curated synonym AND zero literal presence anywhere in the scoped tool
     * set — fall back to the closest fuzzy vocabulary match (typo
     * tolerance). Fuzzy matching is skipped whenever a SYNONYMS entry
     * exists, even if the word itself isn't literal anywhere: a curated
     * mapping is deliberate ("change" -> update/set) and letting
     * similar_text() also chase a same-length real word (e.g. "change" ~
     * "changes", found only in unrelated session/skills descriptions) would
     * silently inject an unrelated tool into every such query.
     */
    private static function expand_words( array $words, array $vocabulary ): array {
        $expansions = [];
        foreach ( $words as $word ) {
            $extra = self::synonyms_for( $word );

            if ( ! $extra && ! isset( $vocabulary[ $word ] ) ) {
                $fuzzy = self::fuzzy_match( $word, $vocabulary );
                if ( $fuzzy !== null ) {
                    $extra[] = $fuzzy;
                }
            }

            if ( $extra ) {
                $expansions[ $word ] = array_unique( $extra );
            }
        }
        return $expansions;
    }

    /**
     * SYNONYMS is keyed on singular forms; a plural query word ("coupons",
     * "categories") would otherwise miss its entry entirely even though the
     * intent is identical. Tries the word as-is, then a light singularized
     * form — not real stemming, just enough for regular English plurals.
     */
    private static function synonyms_for( string $word ): array {
        if ( isset( self::SYNONYMS[ $word ] ) ) {
            return self::SYNONYMS[ $word ];
        }
        return self::SYNONYMS[ self::singularize( $word ) ] ?? [];
    }

    private static function singularize( string $word ): string {
        if ( strlen( $word ) > 3 && str_ends_with( $word, 'ies' ) ) {
            return substr( $word, 0, -3 ) . 'y';
        }
        if ( strlen( $word ) > 2 && str_ends_with( $word, 'es' ) ) {
            return substr( $word, 0, -2 );
        }
        if ( strlen( $word ) > 1 && str_ends_with( $word, 's' ) ) {
            return substr( $word, 0, -1 );
        }
        return $word;
    }

    private static function fuzzy_match( string $word, array $vocabulary ): ?string {
        $best_word = null;
        $best_pct  = 0.0;
        foreach ( array_keys( $vocabulary ) as $vocab_word ) {
            if ( abs( strlen( $vocab_word ) - strlen( $word ) ) > 3 ) {
                continue; // cheap pre-filter, similar_text() is O(n*m)
            }
            similar_text( $word, $vocab_word, $pct );
            if ( $pct > $best_pct ) {
                $best_pct  = $pct;
                $best_word = $vocab_word;
            }
        }
        return ( $best_word !== null && $best_pct >= self::FUZZY_MATCH_THRESHOLD ) ? $best_word : null;
    }

    // ── Scoring ─────────────────────────────────────────────────────────────

    private static function score_tool( array $tool, array $words, string $phrase, array $expansions, array $doc_freq ): float {
        $name        = strtolower( $tool['name'] );
        $desc        = strtolower( $tool['description'] );
        $name_tokens = self::tokenize( $name );
        $desc_tokens = self::tokenize( $desc );
        $score       = 0.0;

        // Exact multi-word phrase match is rare and always meaningful —
        // undamped, and enough to outrank any single-word combination.
        if ( count( $words ) > 1 && str_contains( $name, $phrase ) ) {
            $score += 12;
        }
        if ( count( $words ) > 1 && str_contains( $desc, $phrase ) ) {
            $score += 6;
        }

        foreach ( $words as $word ) {
            $w = self::term_weight( $word, $doc_freq );
            if ( in_array( $word, $name_tokens, true ) ) {
                $score += 5 * $w;
            } elseif ( str_contains( $name, $word ) ) {
                $score += 2 * $w;
            }
            if ( in_array( $word, $desc_tokens, true ) ) {
                $score += 2 * $w;
            } elseif ( str_contains( $desc, $word ) ) {
                $score += 1 * $w;
            }

            foreach ( $expansions[ $word ] ?? [] as $syn ) {
                $sw = self::term_weight( $syn, $doc_freq );
                if ( in_array( $syn, $name_tokens, true ) ) {
                    $score += 3 * $sw;
                } elseif ( str_contains( $name, $syn ) ) {
                    $score += 1 * $sw;
                }
                if ( in_array( $syn, $desc_tokens, true ) ) {
                    $score += 1 * $sw;
                }
            }
        }

        return $score;
    }
}
