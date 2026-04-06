<?php
/*
Plugin Name: GrimeGames Tournament Poster
Description: Monitors YGO tournament results from Konami's RSS feed and auto-generates Facebook posts cross-referenced with your live WooCommerce stock.
Author: GrimeGames
Version: 1.1 - Fixed keyword matching, added reset & reprocess
*/

defined('ABSPATH') || exit;

/* =========================
   CONSTANTS
   ========================= */

// RSS URL candidates — tried in order until one works
define('GG_TP_RSS_URLS', [
    'https://yugiohblog.konami.com/feed/',
    'https://yugiohblog.konami.com/?feed=rss2',
    'https://yugiohblog.konami.com/feed/rss/',
    'https://yugiohblog.konami.com/feed/atom/',
]);
// Scrape pages — tried in order, results merged and deduplicated.
// We use the MAIN blog page (and pagination) rather than year-based archive
// URLs because WordPress yearly archives like /2026/ may not exist until
// that year's posts have been indexed, causing 404s for brand-new years.
// The main page always shows the very latest posts regardless of year.
define('GG_TP_SCRAPE_URLS', [
    'https://yugiohblog.konami.com/',           // Main blog — always the freshest posts
    'https://yugiohblog.konami.com/page/2/',    // Page 2 — slightly older posts
    'https://yugiohblog.konami.com/page/3/',    // Page 3 — older still
    'https://yugiohblog.konami.com/tag/deck-lists/',              // Tag archive
    'https://yugiohblog.konami.com/' . (date('Y') - 1) . '/',    // Previous-year archive
]);
// Keep single constant for backwards compatibility
define('GG_TP_BLOG_URL', 'https://yugiohblog.konami.com/tag/deck-lists/');
define('GG_TP_YGOAPI',        'https://db.ygoprodeck.com/api/v7/');
define('GG_TP_SEEN_OPTION',   'gg_tp_seen_guids');
define('GG_TP_POSTS_OPTION',  'gg_tp_generated_posts');

/* =========================
   SCHEDULING
   ========================= */

register_activation_hook(__FILE__, 'gg_tp_activate');
register_deactivation_hook(__FILE__, 'gg_tp_deactivate');

function gg_tp_activate() {
    if (!wp_next_scheduled('gg_tp_rss_cron')) {
        // Check every 6 hours — tournaments are usually reported within hours
        wp_schedule_event(time(), 'twicedaily', 'gg_tp_rss_cron');
    }
}

function gg_tp_deactivate() {
    wp_clear_scheduled_hook('gg_tp_rss_cron');
}

add_action('gg_tp_rss_cron', 'gg_tp_check_rss');

/* =========================
   RSS MONITORING
   ========================= */

/**
 * Fetches Konami's event blog RSS, finds new tournament result posts,
 * and triggers post generation for each one.
 *
 * @return int Number of new tournament posts processed.
 */
function gg_tp_check_rss() {
    // Extend PHP execution time — processing multiple tournament pages
    // with full HTML fetches can take 60–120 seconds on shared hosting.
    if (function_exists('set_time_limit')) {
        @set_time_limit(300); // 5 minutes
    }

    $items = gg_tp_fetch_rss_items();

    if ($items === null) {
        return 0;
    }

    $seen  = get_option(GG_TP_SEEN_OPTION, []);
    $count = 0;

    foreach ($items as $item) {
        $guid = $item['guid'];

        // Skip posts we've already successfully processed
        if (!$guid || in_array($guid, $seen, true)) continue;

        $title   = $item['title'];
        $content = $item['content'];
        $link    = $item['link'];
        $date    = $item['date'];

        // Only process posts that look like tournament results.
        // Non-matching posts are NOT added to $seen so improved
        // keywords will catch them on the next run.
        if (!gg_tp_is_result_post($title)) {
            continue;
        }

        // Skip non-TCG formats — Speed Duel, Rush Duel, Duel Links etc.
        if (preg_match('/speed\s+duel|rush\s+duel|duel\s+links|goat\s+format/i', $title)) {
            gg_tp_log("Skipping non-TCG event: {$title}");
            $seen[] = $guid; // mark seen so we don't check again
            continue;
        }

        // Skip posts older than 12 months — check URL for year or use date field.
        // Konami URLs contain the year: /2024/ycs/... or /2025/championships/...
        $current_year  = (int)date('Y');
        $previous_year = $current_year - 1;
        $url_year_ok   = (
            strpos($link, '/' . $current_year . '/') !== false ||
            strpos($link, '/' . $previous_year . '/') !== false
        );
        $date_year     = $date ? (int)date('Y', strtotime($date)) : 0;
        $date_year_ok  = ($date_year === 0 || $date_year >= $previous_year);

        if (!$url_year_ok && !$date_year_ok) {
            gg_tp_log("Skipping old post ({$date}): {$title}");
            $seen[] = $guid; // mark seen so we don't keep re-checking
            continue;
        }

        // Scraper returns items with empty content — only titles and URLs.
        // Fetch the post page ONCE here and pass the raw HTML directly to the
        // processor.  gg_tp_parse_tournament_page() then uses it without
        // re-fetching, avoiding the double-request that caused timeouts.
        $preloaded_html = '';
        if (!empty($link)) {
            gg_tp_log("Fetching post page for '{$title}': {$link}");
            $post_resp = wp_remote_get($link, [
                'timeout'   => 30,
                'sslverify' => false,
                'headers'   => ['User-Agent' => 'Mozilla/5.0 (compatible; GrimeGames/1.0; +https://grimegames.com)'],
            ]);
            if (!is_wp_error($post_resp) && wp_remote_retrieve_response_code($post_resp) === 200) {
                $preloaded_html = wp_remote_retrieve_body($post_resp);
                gg_tp_log("Fetched " . strlen($preloaded_html) . " bytes for '{$title}'");
            } else {
                $fetch_err = is_wp_error($post_resp) ? $post_resp->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($post_resp);
                gg_tp_log("Could not fetch post page for '{$title}': {$fetch_err}");
            }
        }

        gg_tp_process_post($title, $preloaded_html, $link, $date);

        // Mark as seen only after successful processing
        $seen[] = $guid;
        $count++;
    }

    if (count($seen) > 300) $seen = array_slice($seen, -300);
    update_option(GG_TP_SEEN_OPTION, $seen);

    return $count;
}

/**
 * Fetches and parses Konami's tournament post list.
 *
 * Strategy (tried in order, all results stored for debug):
 *   A. Try each known RSS URL variant directly
 *   B. Try each RSS URL via rss2json.com proxy
 *   C. Scrape the deck-lists tag page HTML as a last resort
 *
 * Returns an array of normalised item arrays, or null on total failure.
 * Stores a full debug log in 'gg_tp_last_error' for admin display.
 */
function gg_tp_fetch_rss_items() {
    $errors = [];

    // ── A. Try RSS URL variants directly ──────────────────────────────────
    foreach (GG_TP_RSS_URLS as $rss_url) {
        $label    = 'Direct (' . $rss_url . ')';
        $response = wp_remote_get($rss_url, [
            'timeout'   => 20,
            'sslverify' => false,
            'headers'   => ['User-Agent' => 'GrimeGames TournamentPoster/1.1 (grimegames.com)'],
        ]);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $items = gg_tp_parse_rss_xml(wp_remote_retrieve_body($response));
            if ($items !== null) {
                update_option('gg_tp_last_error', "✓ Success via: {$rss_url}");
                update_option('gg_tp_working_rss', $rss_url);
                return $items;
            }
            $errors[] = "{$label}: 200 OK but XML parse failed";
        } else {
            $code     = is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($response);
            $errors[] = "{$label}: {$code}";
        }
    }

    // ── B. Try RSS URL variants via rss2json.com proxy ────────────────────
    foreach (GG_TP_RSS_URLS as $rss_url) {
        $label     = 'rss2json (' . basename($rss_url) . ')';
        $proxy_url = 'https://api.rss2json.com/v1/api.json?rss_url=' . urlencode($rss_url) . '&count=20';
        $response  = wp_remote_get($proxy_url, [
            'timeout'   => 20,
            'sslverify' => false,
        ]);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($data['items'])) {
                update_option('gg_tp_last_error', "✓ Success via rss2json proxy: {$rss_url}");
                update_option('gg_tp_working_rss', 'rss2json:' . $rss_url);
                return gg_tp_normalise_rss2json($data['items']);
            }
            $errors[] = "{$label}: " . ($data['message'] ?? 'empty items');
        } else {
            $code     = is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($response);
            $errors[] = "{$label}: {$code}";
        }
    }

    // ── C. Scrape multiple pages — current year, previous year, tag archive ──
    //    Merge all results so we get the freshest posts from /2026/ AND the
    //    tagged archive from /tag/deck-lists/ in a single pass.
    $all_scraped = [];
    $seen_guids  = [];

    foreach (GG_TP_SCRAPE_URLS as $scrape_url) {
        $response = wp_remote_get($scrape_url, [
            'timeout'   => 20,
            'sslverify' => false,
            'headers'   => ['User-Agent' => 'GrimeGames TournamentPoster/1.1 (grimegames.com)'],
        ]);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $scraped = gg_tp_scrape_blog_html(wp_remote_retrieve_body($response));
            if (!empty($scraped)) {
                foreach ($scraped as $item) {
                    if (!isset($seen_guids[$item['guid']])) {
                        $seen_guids[$item['guid']] = true;
                        $all_scraped[] = $item;
                    }
                }
                $errors[] = '✓ Scraped ' . count($scraped) . ' items from ' . $scrape_url;
            } else {
                $errors[] = 'Scrape (' . $scrape_url . '): no posts found';
            }
        } else {
            $code     = is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($response);
            $errors[] = 'Scrape (' . $scrape_url . '): ' . $code;
        }
    }

    if (!empty($all_scraped)) {
        $summary = count($all_scraped) . ' posts from ' . count(GG_TP_SCRAPE_URLS) . ' pages';
        update_option('gg_tp_last_error', '✓ Success via HTML scrape — ' . $summary);
        update_option('gg_tp_working_rss', 'scrape');
        return $all_scraped;
    }

    $error_msg = implode("\n", $errors);
    error_log('GG Tournament Poster — all methods failed:' . "\n" . $error_msg);
    update_option('gg_tp_last_error', $error_msg);
    return null;
}

/**
 * Parse raw RSS/Atom XML body into normalised item arrays.
 */
function gg_tp_parse_rss_xml($body) {
    if (empty($body)) return null;
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body);
    libxml_clear_errors();
    if (!$xml) return null;

    $items = [];

    // RSS 2.0
    if (isset($xml->channel->item)) {
        foreach ($xml->channel->item as $item) {
            $ns       = $item->children('http://purl.org/rss/1.0/modules/content/');
            $items[]  = [
                'guid'    => (string)($item->guid ?? $item->link ?? ''),
                'title'   => html_entity_decode(strip_tags((string)$item->title), ENT_QUOTES, 'UTF-8'),
                'content' => (string)($ns->encoded ?? $item->description ?? ''),
                'link'    => (string)$item->link,
                'date'    => date('Y-m-d H:i:s', strtotime((string)$item->pubDate ?: 'now')),
            ];
        }
    }

    // Atom feed
    if (empty($items) && isset($xml->entry)) {
        $xml->registerXPathNamespace('atom', 'http://www.w3.org/2005/Atom');
        foreach ($xml->entry as $entry) {
            $link    = '';
            foreach ($entry->link as $l) {
                if ((string)$l['rel'] === 'alternate' || empty($link)) {
                    $link = (string)$l['href'];
                }
            }
            $items[] = [
                'guid'    => (string)($entry->id ?? $link),
                'title'   => html_entity_decode(strip_tags((string)$entry->title), ENT_QUOTES, 'UTF-8'),
                'content' => (string)($entry->content ?? $entry->summary ?? ''),
                'link'    => $link,
                'date'    => date('Y-m-d H:i:s', strtotime((string)$entry->updated ?: 'now')),
            ];
        }
    }

    return $items ?: null;
}

/**
 * Scrape post titles and links from Konami blog HTML.
 * Tries multiple XPath strategies then falls back to domain-based link scan.
 * Also saves a raw HTML snippet to gg_tp_scrape_debug for admin inspection.
 */
function gg_tp_scrape_blog_html($html) {
    if (empty($html)) return null;

    // Save first 3000 chars of raw HTML for admin debugging
    update_option('gg_tp_scrape_debug', substr($html, 0, 3000));

    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);
    $items = [];
    $seen  = [];

    // Strategy 1: headings inside <article> tags (most WordPress themes)
    $queries = [
        '//article//h1/a',
        '//article//h2/a',
        '//article//h3/a',
        // Standard WP class-based heading selectors
        '//h1[contains(@class,"entry-title")]/a',
        '//h2[contains(@class,"entry-title")]/a',
        '//h3[contains(@class,"entry-title")]/a',
        '//h1[contains(@class,"post-title")]/a',
        '//h2[contains(@class,"post-title")]/a',
        '//h2[contains(@class,"entry")]/a',
        '//h2[contains(@class,"post")]/a',
        // Block theme support
        '//*[contains(@class,"wp-block-post-title")]/a',
        '//*[contains(@class,"wp-block-post-title")]',
        // Any heading anywhere with a link
        '//h1/a | //h2/a | //h3/a',
    ];

    foreach ($queries as $q) {
        $nodes = $xpath->query($q);
        if (!$nodes || $nodes->length === 0) continue;

        foreach ($nodes as $node) {
            $title = trim($node->textContent);
            $link  = $node->getAttribute('href');
            if (!$link && $node->parentNode) {
                $link = $node->parentNode->getAttribute('href');
            }
            if ($title && $link && !isset($seen[$link])) {
                $seen[$link] = true;
                $items[] = [
                    'guid'    => $link,
                    'title'   => html_entity_decode($title, ENT_QUOTES, 'UTF-8'),
                    'content' => '',
                    'link'    => $link,
                    'date'    => current_time('mysql'),
                ];
            }
        }

        if (!empty($items)) break; // stop at the first strategy that finds something
    }

    // Strategy 2: fallback — any <a> pointing to yugiohblog.konami.com with meaningful text
    if (empty($items)) {
        $all_links = $xpath->query('//a[@href]');
        if ($all_links) {
            foreach ($all_links as $node) {
                $href  = $node->getAttribute('href');
                $title = trim($node->textContent);
                // Must link to the Konami blog, have text, and not be nav/utility links
                if (
                    strpos($href, 'yugiohblog.konami.com') !== false &&
                    strlen($title) > 20 &&
                    !isset($seen[$href])
                ) {
                    $seen[$href] = true;
                    $items[] = [
                        'guid'    => $href,
                        'title'   => html_entity_decode($title, ENT_QUOTES, 'UTF-8'),
                        'content' => '',
                        'link'    => $href,
                        'date'    => current_time('mysql'),
                    ];
                }
            }
        }
    }

    return $items ?: null;
}

/**
 * Normalise rss2json.com API response items into the standard format.
 */
function gg_tp_normalise_rss2json($raw_items) {
    return array_map(function($item) {
        return [
            'guid'    => $item['guid']    ?? $item['link'] ?? '',
            'title'   => html_entity_decode(strip_tags($item['title'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'content' => $item['content'] ?? $item['description'] ?? '',
            'link'    => $item['link']    ?? '',
            'date'    => date('Y-m-d H:i:s', strtotime($item['pubDate'] ?? 'now')),
        ];
    }, $raw_items);
}

/**
 * Determines whether a post title looks like a TCG tournament result post with deck lists.
 * Must match at least one strong signal AND not be a non-TCG format.
 */
function gg_tp_is_result_post($title) {
    $title_lower = strtolower($title);

    // Hard exclusions — non-TCG or experimental formats we don't want
    if (preg_match('/speed\s+duel|rush\s+duel|duel\s+links|goat\s+format|genesys/i', $title)) {
        return false;
    }

    // Strong signals — these are specific enough on their own
    $strong = [
        'top 32', 'top 16', 'top 64', 'top 4',
        'deck list', 'deck lists', 'decklist', 'decklists',
        'deck breakdown',
    ];
    foreach ($strong as $s) {
        if (strpos($title_lower, $s) !== false) return true;
    }

    // Weaker signals — only count if combined with an event type
    $event_types   = ['ycs', 'regional', 'national', 'wcq', 'world championship', 'extravaganza'];
    $result_words  = ['winner', 'champion', 'top 8', 'results', 'coverage'];

    $has_event  = false;
    $has_result = false;
    foreach ($event_types as $e) {
        if (strpos($title_lower, $e) !== false) { $has_event = true; break; }
    }
    foreach ($result_words as $r) {
        if (strpos($title_lower, $r) !== false) { $has_result = true; break; }
    }

    return $has_event && $has_result;
}

/* =========================
   PROCESS A TOURNAMENT POST
   ========================= */

function gg_tp_process_post($title, $preloaded_html, $link, $date) {
    gg_tp_log("Processing: '{$title}'");

    // Parse the tournament page — pass preloaded HTML so the parser
    // skips the network fetch (already done by gg_tp_check_rss).
    $tournament = gg_tp_parse_tournament_page($link, $preloaded_html, true);

    if (empty($tournament['card_frequency']) && empty($tournament['winner'])) {
        gg_tp_log("No useful tournament data found for: {$title}");
        return;
    }

    // Match the most-played cards directly to WooCommerce stock
    $top_cards     = array_keys(array_slice($tournament['card_frequency'], 0, 30, true));
    $stock_matches = gg_tp_match_stock($top_cards);

    // Generate and save
    $post_text = gg_tp_generate_post($title, $tournament, $stock_matches, $link);
    gg_tp_save_post($title, $post_text, $date, $tournament, $stock_matches);
}

/* =========================
   TOURNAMENT PAGE PARSER
   ========================= */

/**
 * Fetches an individual Konami tournament post and extracts structured data.
 *
 * ACTUAL Konami page format (confirmed from raw page inspection):
 *   - Players listed as:  "Firstname Lastname – N"  (name THEN dash THEN placing number)
 *   - Cards listed as:    "3 Card Name"  (quantity FIRST, then card name)
 *   - Section headers:    "Main Deck: 41", "Monster Cards: 22", "Extra Deck: 15", "Side Deck: 15"
 *   - No explicit deck type label — inferred from most-used archetype word in main deck
 *   - No separate breakdown summary — built from all player decklists
 */
/**
 * @param string $url           The tournament post URL.
 * @param string $preloaded_html If non-empty AND $skip_fetch is true, use this
 *                               HTML directly instead of fetching the URL.
 *                               (Avoids a double-fetch when called from check_rss.)
 * @param bool   $skip_fetch    When true and $preloaded_html is non-empty, skip
 *                               the wp_remote_get call entirely.
 */
function gg_tp_parse_tournament_page($url, $preloaded_html = '', $skip_fetch = false) {
    $result = [
        'winner'        => '',
        'winner_deck'   => '',
        'runner_up'     => '',
        'runner_up_deck'=> '',
        'top8_decks'    => [],
        'deck_breakdown'=> [],
        'card_frequency'=> [],
    ];

    // Use preloaded HTML if the caller already fetched the page; otherwise fetch now.
    if ($skip_fetch && !empty($preloaded_html)) {
        $html = $preloaded_html;
        gg_tp_log("parse_tournament_page: using preloaded HTML (" . strlen($html) . " bytes) for {$url}");
    } else {
        $response = wp_remote_get($url, [
            'timeout'   => 30,
            'sslverify' => false,
            'headers'   => ['User-Agent' => 'Mozilla/5.0 (compatible; GrimeGames/1.0; +https://grimegames.com)'],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            gg_tp_log("Could not fetch tournament page: {$url}");
            $html = '';
        } else {
            $html = wp_remote_retrieve_body($response);
        }
    }

    // Step 1: Insert newlines at block-level elements BEFORE stripping tags.
    // wp_strip_all_tags / strip_tags does NOT add newlines for <div>, <p>, <h2> etc.
    // Without this, "Player Name – 1" inside an <h2> tag would merge with the
    // next line of text and never be detected by the line-by-line parser.
    if ($html) {
        // Replace opening AND closing block tags with a newline
        $html = preg_replace(
            '/<\s*\/?\s*(p|div|h[1-6]|li|ul|ol|tr|td|th|br|hr|section|article|header|footer|aside|figure|figcaption|blockquote)\b[^>]*>/i',
            "\n",
            $html
        );
    }

    // Step 2: Strip remaining tags, then decode HTML entities — critical for cards like
    // "Ash Blossom &amp; Joyous Spring" → "Ash Blossom & Joyous Spring"
    $text = $html ? html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8') : $fallback_text;
    if (empty(trim($text))) return $result;

    $lines = preg_split('/[\r\n]+/', $text);

    // -----------------------------------------------------------------------
    // Generic staple first-words — appear in every deck, useless for deck ID
    // -----------------------------------------------------------------------
    $generic_prefixes = [
        'Ash', 'Droll', 'Nibiru', 'Ghost', 'Infinite', 'Called', 'Pot',
        'Lightning', 'Dark', 'Crossout', 'Bystial', 'Triple', 'Terraforming',
        'Foolish', 'Gold', 'Harpie', 'Forbidden', 'Brilliant', 'Evenly',
        'Artifact', 'Solemn', 'Torrential', 'Mystical', 'Monster', 'Effect',
        'Tuner', 'Quick', 'Continuous', 'Equip', 'Field', 'Counter',
        'Mulcharmy', 'Maxx', 'Thrust', 'Talent', 'Droplet', 'Burial',
    ];

    // -----------------------------------------------------------------------
    // PASS 1: Parse player sections
    //   Each player section starts with "Player Name – N" (N = placing 1–64)
    //   We track which section we're in (main/extra/side) and collect cards
    // -----------------------------------------------------------------------
    $players         = [];   // placing_number => [ name, deck_cards (main only) ]
    $current_placing = null;
    $in_main_deck    = false;
    $card_freq       = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        // ---- Section header detection ----
        if (preg_match('/^Main\s+Deck\s*:/i', $line)) {
            $in_main_deck = true;
            continue;
        }
        if (preg_match('/^(Extra\s+Deck|Side\s+Deck)\s*:/i', $line)) {
            $in_main_deck = false;
            continue;
        }
        if (preg_match('/^(Monster|Spell|Trap)\s+Cards?\s*:/i', $line)) {
            continue; // sub-header, stay in current section
        }

        // ---- Player placement line: "John Smith – 1" or "Jane Doe - 2" ----
        // Handles:
        //   • em-dash (–, U+2013), en-dash (‒), figure dash, horizontal bar, plain hyphen
        //   • Unicode letters in names (accented chars, etc.) via \p{L}
        //   • Ordinals: "1st", "2nd", "3rd", "64th" as well as bare numbers
        if (preg_match(
            '/^([\p{Lu}][\p{L}\s\.\'\-]{4,60}?)\s*[\x{2010}-\x{2015}\x{2212}\-\x{2013}\x{2014}]+\s*(\d{1,2})(?:st|nd|rd|th)?\s*$/u',
            $line, $m
        )) {
            $name    = trim($m[1]);
            $placing = (int)$m[2];

            // Word count — use preg_split for unicode safety
            $word_arr = array_filter(preg_split('/\s+/u', $name));
            $words    = count($word_arr);

            // Sanity: 2–6 words, valid placing range, not a structural term
            if ($words >= 2 && $words <= 6 && $placing >= 1 && $placing <= 64
                && !preg_match('/^(Main|Extra|Side|Monster|Spell|Trap|Top|Round|Event|Place|YCS|WCQ|Regional)/i', $name)
            ) {
                $current_placing   = $placing;
                $in_main_deck      = false;
                $players[$placing] = ['name' => $name, 'deck_cards' => []];
                continue;
            }
        }

        // ---- Card line: "3 Card Name" (quantity first) ----
        // Allows: letters, digits, spaces, hyphens, apostrophes, &, !, @, <>, /
        if (preg_match('/^([1-3])\s+([A-Z][A-Za-z0-9\s\-\'\!\&\@\<\>\/\:\,\.]{2,60}?)\s*$/u', $line, $m)) {
            $qty  = (int)$m[1];
            $card = trim($m[2]);

            // Skip anything that looks like a section header
            if (preg_match('/^(Main|Extra|Side|Monster|Spell|Trap|Top|Round|Event|Place)/i', $card)) continue;
            if (strlen($card) < 3) continue;

            // Global card frequency (all decks including extra/side for "most played" stat)
            $card_freq[$card] = ($card_freq[$card] ?? 0) + $qty;

            // Main-deck cards only for deck type inference
            if ($in_main_deck && $current_placing !== null) {
                $players[$current_placing]['deck_cards'][] = $card;
            }
            continue;
        }
    }

    gg_tp_log("Players found: " . count($players) . " | Cards distinct: " . count($card_freq));

    // -----------------------------------------------------------------------
    // PASS 2: Infer deck type per player from most-used archetype prefix
    //   Strategy:
    //   1. Count the first word of every main-deck card, skipping generic staples.
    //   2. The most-common non-generic first word becomes the archetype "root".
    //   3. Then check if a 2-word prefix (e.g. "Radiant Typhoon") appears on
    //      more than half the cards that share the 1-word root. If yes, use
    //      the 2-word version for a more readable deck name ("Radiant Typhoon"
    //      beats "Radiant", "Gem-Knight" stays as-is since it's one hyphenated
    //      word, "Maliss" stays since the 2nd word is usually a card code).
    // -----------------------------------------------------------------------
    foreach ($players as $placing => &$player) {
        $prefix_counts = [];

        foreach ($player['deck_cards'] as $card) {
            $words      = explode(' ', $card);
            // Strip possessive 's — use regex, NOT rtrim().
            // rtrim('Maliss', "'s") strips ALL trailing s/apostrophe chars one
            // at a time, so 'Maliss' → 'Malis' → 'Mali'. The regex only removes
            // the exact possessive suffix ('s or curly-apostrophe s) at the end.
            $first_word = preg_replace("/(?:'|\x{2019})s$/u", '', $words[0]);

            if (!in_array($first_word, $generic_prefixes, true) && strlen($first_word) > 2) {
                $prefix_counts[$first_word] = ($prefix_counts[$first_word] ?? 0) + 1;
            }
        }

        if (empty($prefix_counts)) {
            $player['deck_name'] = '';
            continue;
        }

        arsort($prefix_counts);
        $root_word  = array_key_first($prefix_counts);
        $root_count = $prefix_counts[$root_word];

        // Try to extend to a 2-word deck name.
        // For each main-deck card that starts with $root_word, tally the 2-word prefix.
        $two_word_counts = [];
        foreach ($player['deck_cards'] as $card) {
            $words = explode(' ', $card);
            $fw    = preg_replace("/(?:'|\x{2019})s$/u", '', $words[0]); // safe possessive strip
            if ($fw === $root_word && isset($words[1])) {
                $second = $words[1];
                // Only use it if the second word looks like an archetype word:
                // - starts with uppercase
                // - not a set/card code (no digits after first char)
                // - not an English-only function/article word (YGO-specific words like
                //   Soul, Dragon, Knight, Spirit, etc. are all valid archetype parts)
                if (preg_match('/^[A-Z][A-Za-z]{2,}$/', $second)
                    && !in_array($second, ['The', 'And', 'For', 'With', 'From', 'Into', 'Its', 'That'], true)
                ) {
                    $two_word_counts[$second] = ($two_word_counts[$second] ?? 0) + 1;
                }
            }
        }

        // Use 2-word name if the top second-word appears on >50% of root-word cards
        $deck_name = $root_word;
        if (!empty($two_word_counts)) {
            arsort($two_word_counts);
            $best_second       = array_key_first($two_word_counts);
            $best_second_count = $two_word_counts[$best_second];
            if ($best_second_count >= ($root_count * 0.5)) {
                $deck_name = $root_word . ' ' . $best_second;
            }
        }

        $player['deck_name'] = $deck_name;
    }
    unset($player);

    // -----------------------------------------------------------------------
    // PASS 3: Set winner, runner up, top 8, and build deck breakdown
    // -----------------------------------------------------------------------
    if (isset($players[1])) {
        $result['winner']      = $players[1]['name'];
        $result['winner_deck'] = $players[1]['deck_name'];
    }
    if (isset($players[2])) {
        $result['runner_up']      = $players[2]['name'];
        $result['runner_up_deck'] = $players[2]['deck_name'];
    }

    // Top 8 — deck names for placings 1–8
    for ($i = 1; $i <= 8; $i++) {
        if (!empty($players[$i]['deck_name'])) {
            $result['top8_decks'][] = $players[$i]['deck_name'];
        }
    }

    // Deck breakdown — count players per inferred deck type (all placings)
    $deck_counts = [];
    foreach ($players as $player) {
        $deck = $player['deck_name'];
        if ($deck) {
            $deck_counts[$deck] = ($deck_counts[$deck] ?? 0) + 1;
        }
    }
    arsort($deck_counts);
    $result['deck_breakdown'] = $deck_counts;

    // -----------------------------------------------------------------------
    // PASS 4: Card frequency — already built in Pass 1, just sort it
    // -----------------------------------------------------------------------
    arsort($card_freq);
    $result['card_frequency'] = $card_freq;

    // Debug logging
    gg_tp_log("Winner: {$result['winner']} ({$result['winner_deck']}) | Runner up: {$result['runner_up']} ({$result['runner_up_deck']})");
    if (!empty($deck_counts)) {
        $top_decks = array_slice($deck_counts, 0, 5, true);
        gg_tp_log("Deck breakdown: " . implode(', ', array_map(function($k,$v){ return "{$k}({$v})"; }, array_keys($top_decks), $top_decks)));
    }
    if (!empty($card_freq)) {
        $top_cards = array_slice($card_freq, 0, 5, true);
        gg_tp_log("Top cards: " . implode(', ', array_map(function($k,$v){ return "{$k}({$v})"; }, array_keys($top_cards), $top_cards)));
    }

    return $result;
}

/* =========================
   ARCHETYPE EXTRACTION
   ========================= */

/* =========================
   YGOPRODECK CARD LOOKUP
   (kept for admin Test Archetype tool)
   ========================= */

/**
 * Returns all card names in a given archetype, cached per archetype for 12h.
 */
function gg_tp_get_archetype_cards($archetype) {
    $cache_key = 'gg_tp_cards_' . md5($archetype);
    $cached    = get_transient($cache_key);
    if ($cached !== false) return $cached;

    $resp = wp_remote_get(GG_TP_YGOAPI . 'cardinfo.php?' . http_build_query([
        'archetype' => $archetype,
    ]), [
        'timeout' => 20,
        'headers' => ['User-Agent' => 'GrimeGames TournamentPoster/1.0 (grimegames.com)'],
    ]);

    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) return [];

    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if (empty($body['data'])) return [];

    $names = array_column($body['data'], 'name');
    set_transient($cache_key, $names, 12 * HOUR_IN_SECONDS);

    return $names;
}

/* =========================
   WOOCOMMERCE STOCK MATCHING
   ========================= */

/**
 * Given a list of card names from YGOPRODeck, find matching in-stock
 * WooCommerce products. Returns up to 5, ordered by price descending.
 */
function gg_tp_match_stock($card_names) {
    global $wpdb;

    if (empty($card_names)) return [];

    // Build WHERE clause: post_title LIKE '%Card Name%' OR ...
    $likes  = [];
    $values = [];

    foreach ($card_names as $name) {
        $likes[]  = "p.post_title LIKE %s";
        $values[] = '%' . $wpdb->esc_like($name) . '%';
    }

    $where = implode(' OR ', $likes);

    // Also match using stock_status in case stock isn't numerically managed.
    // NOTE: CAST(NULL AS UNSIGNED) in MySQL returns 0 on some versions and 18446744... on others.
    // Use COALESCE + GREATEST to clamp: if meta_value is missing/negative, treat as 0.
    $sql = $wpdb->prepare(
        "SELECT DISTINCT
            p.ID,
            p.post_title,
            COALESCE(CAST(price_meta.meta_value AS DECIMAL(10,2)), 0.00) AS price,
            GREATEST(0, COALESCE(CAST(stock_meta.meta_value AS SIGNED), 0)) AS qty
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} price_meta
            ON p.ID = price_meta.post_id AND price_meta.meta_key = '_price'
         LEFT JOIN {$wpdb->postmeta} stock_meta
            ON p.ID = stock_meta.post_id AND stock_meta.meta_key = '_stock'
         LEFT JOIN {$wpdb->postmeta} status_meta
            ON p.ID = status_meta.post_id AND status_meta.meta_key = '_stock_status'
         WHERE p.post_type   = 'product'
           AND p.post_status = 'publish'
           AND (
               COALESCE(CAST(stock_meta.meta_value AS SIGNED), 0) > 0
               OR status_meta.meta_value = 'instock'
           )
           AND ({$where})
         ORDER BY price DESC
         LIMIT 10",
        $values
    );

    return $wpdb->get_results($sql, ARRAY_A);
}

/* =========================
   POST GENERATION
   ========================= */

function gg_tp_generate_post($raw_title, $tournament, $stock_matches, $source_link) {
    $event  = gg_tp_clean_title($raw_title);
    $lines  = [];

    // ---- Header ----
    $lines[] = "🏆 {$event} Results!";
    $lines[] = "";

    // ---- Winner & Runner Up ----
    if (!empty($tournament['winner'])) {
        $winner_deck = $tournament['winner_deck'] ? " playing {$tournament['winner_deck']}" : '';
        $lines[] = "🥇 Winner: {$tournament['winner']}{$winner_deck}";
    }
    if (!empty($tournament['runner_up'])) {
        $ru_deck = $tournament['runner_up_deck'] ? " playing {$tournament['runner_up_deck']}" : '';
        $lines[] = "🥈 Runner Up: {$tournament['runner_up']}{$ru_deck}";
    }
    if (!empty($tournament['winner']) || !empty($tournament['runner_up'])) {
        $lines[] = "";
    }

    // ---- Top 8 Decks ----
    $top8 = array_slice($tournament['top8_decks'], 0, 8);
    if (!empty($top8)) {
        $lines[] = "🎴 Top 8 Decks:";
        $lines[] = implode(', ', $top8);
        $lines[] = "";
    }

    // ---- Top 64 Breakdown ----
    $breakdown = array_slice($tournament['deck_breakdown'], 0, 10, true);
    if (!empty($breakdown)) {
        $lines[] = "📊 Deck Breakdown (Top " . array_sum($tournament['deck_breakdown']) . " players):";
        $parts = [];
        foreach ($breakdown as $deck => $count) {
            $parts[] = "{$count}x {$deck}";
        }
        $lines[] = implode(' | ', $parts);
        $lines[] = "";
    }

    // ---- Most Popular Cards ----
    $top_cards = array_slice($tournament['card_frequency'], 0, 8, true);
    if (!empty($top_cards)) {
        $lines[] = "🃏 Most played cards in the tournament:";
        $card_parts = [];
        foreach ($top_cards as $card => $count) {
            $card_parts[] = $card . " (" . $count . " copies)";
        }
        $lines[] = implode(', ', $card_parts);
        $lines[] = "";
    }

    // ---- GrimeGames Stock ----
    if (!empty($stock_matches)) {
        $lines[] = "✅ Cards from this tournament available at GrimeGames right now:";
        $lines[] = "";
        foreach (array_slice($stock_matches, 0, 8) as $p) {
            $price   = $p['price'] ? '£' . number_format((float)$p['price'], 2) : '';
            $qty     = $p['qty']   ? " ({$p['qty']} in stock)" : '';
            $lines[] = "  • {$p['post_title']}" . ($price ? " — {$price}" : '') . $qty;
        }
        $lines[] = "";
        $lines[] = "📦 Shop all singles → grimegames.com/singles";
    } else {
        $lines[] = "📦 Shop singles → grimegames.com/singles";
    }

    $lines[] = "";
    $lines[] = "#YuGiOh #YGO #TCG #YCS #GrimeGames";

    if ($source_link) {
        $lines[] = "";
        $lines[] = "📰 Full results: {$source_link}";
    }

    return implode("\n", $lines);
}

/**
 * Strip common suffixes from Konami post titles to get a clean event name.
 * "YCS Charlotte Top 32 Decklists" → "YCS Charlotte"
 */
function gg_tp_clean_title($title) {
    $title = preg_replace('/\s+(top\s+\d+|decklists?|results?|coverage|winner|champion|breakdown).*/i', '', $title);
    return trim($title) ?: $title;
}

/* =========================
   STORAGE
   ========================= */

function gg_tp_save_post($source_title, $text, $event_date, $tournament, $stock_matches) {
    $posts = get_option(GG_TP_POSTS_OPTION, []);

    // Summarise top decks for the admin list view
    $top_decks = !empty($tournament['deck_breakdown'])
        ? array_keys(array_slice($tournament['deck_breakdown'], 0, 5, true))
        : $tournament['top8_decks'] ?? [];

    array_unshift($posts, [
        'source_title'  => $source_title,
        'text'          => $text,
        'event_date'    => $event_date,
        'generated_at'  => current_time('mysql'),
        'archetypes'    => $top_decks,       // used for the admin summary column
        'stock_matches' => count($stock_matches),
        'posted'        => false,
        'posted_at'     => null,
    ]);

    if (count($posts) > 50) $posts = array_slice($posts, 0, 50);
    update_option(GG_TP_POSTS_OPTION, $posts);
}

function gg_tp_log($msg) {
    error_log('GG Tournament Poster: ' . $msg);
}

/* =========================
   ADMIN PAGE
   ========================= */

add_action('admin_menu', function() {
    add_menu_page(
        'Tournament Posts',
        '🏆 Tournament Posts',
        'manage_options',
        'gg-tournament-posts',
        'gg_tp_admin_page',
        'dashicons-megaphone',
        26
    );
});

function gg_tp_admin_page() {
    // Handle actions
    if (isset($_POST['gg_tp_action']) && check_admin_referer('gg_tp_nonce')) {
        $action = sanitize_text_field($_POST['gg_tp_action']);

        if ($action === 'check_rss') {
            $found = gg_tp_check_rss();
            echo '<div class="notice notice-success is-dismissible"><p>RSS check complete — <strong>' . (int)$found . '</strong> new tournament post(s) found and processed.</p></div>';
        }

        if ($action === 'reset_seen') {
            // Clear both the "seen" list AND previously generated posts so we
            // start completely fresh — no stale duplicates in the queue.
            update_option(GG_TP_SEEN_OPTION, []);
            update_option(GG_TP_POSTS_OPTION, []);
            $found = gg_tp_check_rss();
            echo '<div class="notice notice-success is-dismissible"><p>Full reset done — <strong>' . (int)$found . '</strong> tournament post(s) generated from latest blog posts.</p></div>';
        }

        if ($action === 'clear_posts') {
            update_option(GG_TP_POSTS_OPTION, []);
            echo '<div class="notice notice-success is-dismissible"><p>All generated posts cleared.</p></div>';
        }

        if ($action === 'show_rss') {
            // Run fetch so we get fresh debug info, then report per-source counts
            $last_err  = get_option('gg_tp_last_error', '');

            // Directly test each scrape URL so we can show per-source counts
            echo '<div class="notice notice-info">';
            echo '<p><strong>Scrape source diagnostics:</strong></p>';
            echo '<table style="width:100%;border-collapse:collapse;margin-bottom:12px;">';
            echo '<tr style="background:#f0f0f0;"><th style="padding:8px;text-align:left;border:1px solid #ddd;">URL</th><th style="padding:8px;border:1px solid #ddd;white-space:nowrap;">HTTP</th><th style="padding:8px;border:1px solid #ddd;">Posts found</th><th style="padding:8px;border:1px solid #ddd;">Tournament matches</th></tr>';
            foreach (GG_TP_SCRAPE_URLS as $su) {
                $sr = wp_remote_get($su, ['timeout' => 15, 'sslverify' => false, 'headers' => ['User-Agent' => 'Mozilla/5.0']]);
                if (is_wp_error($sr)) {
                    echo '<tr><td style="padding:8px;border:1px solid #ddd;">' . esc_html($su) . '</td><td style="padding:8px;border:1px solid #ddd;color:red;">Error</td><td colspan="2" style="padding:8px;border:1px solid #ddd;color:red;">' . esc_html($sr->get_error_message()) . '</td></tr>';
                    continue;
                }
                $code  = wp_remote_retrieve_response_code($sr);
                $found = $code === 200 ? gg_tp_scrape_blog_html(wp_remote_retrieve_body($sr)) : [];
                $found = $found ?: [];
                $match = count(array_filter($found, function($i){ return gg_tp_is_result_post($i['title']); }));
                $ok    = $code === 200 ? '<span style="color:green;">' . $code . ' OK</span>' : '<span style="color:red;">' . $code . '</span>';
                echo '<tr><td style="padding:8px;border:1px solid #ddd;">' . esc_html($su) . '</td><td style="padding:8px;text-align:center;border:1px solid #ddd;">' . $ok . '</td><td style="padding:8px;text-align:center;border:1px solid #ddd;">' . count($found) . '</td><td style="padding:8px;text-align:center;border:1px solid #ddd;' . ($match > 0 ? 'color:green;font-weight:700;' : '') . '">' . $match . '</td></tr>';
            }
            echo '</table>';

            // Now run the real fetch to get the merged de-duped list
            $items = gg_tp_fetch_rss_items();
            $working = get_option('gg_tp_working_rss', '');
            if ($working) echo '<p style="color:green;margin:0 0 8px;">✓ <strong>Active source:</strong> ' . esc_html($working) . '</p>';

            if ($items === null) {
                echo '<p style="color:red;"><strong>Could not fetch from any source.</strong> Debug log:</p>';
                if ($last_err) echo '<pre style="font-size:12px;background:#fff3f3;padding:10px;border-radius:4px;white-space:pre-wrap;">' . esc_html($last_err) . '</pre>';
            } else {
                $seen_guids = get_option(GG_TP_SEEN_OPTION, []);
                echo '<p><strong>' . count($items) . ' total posts found</strong> (merged &amp; deduped across all sources):</p>';
                echo '<table style="width:100%;border-collapse:collapse;margin-top:8px;">';
                echo '<tr style="background:#f0f0f0;"><th style="padding:8px;text-align:left;border:1px solid #ddd;">Title</th><th style="padding:8px;border:1px solid #ddd;">Tournament?</th><th style="padding:8px;border:1px solid #ddd;">Already seen?</th></tr>';
                foreach (array_slice($items, 0, 30) as $item) {
                    $t       = $item['title'];
                    $matches = gg_tp_is_result_post($t);
                    $already = in_array($item['guid'], $seen_guids, true);
                    $bg      = $matches ? ($already ? '#fffbe6' : '#f0fff0') : '#fff';
                    $flag    = $matches ? '<span style="color:green;font-weight:700;">✓ YES</span>' : '<span style="color:#999;">No</span>';
                    $seen_flag = $already ? '<span style="color:#999;">Already done</span>' : '<span style="color:#0073aa;">New</span>';
                    echo '<tr style="background:' . $bg . '"><td style="padding:8px;border:1px solid #ddd;"><a href="' . esc_url($item['link']) . '" target="_blank">' . esc_html($t) . '</a></td><td style="padding:8px;text-align:center;border:1px solid #ddd;">' . $flag . '</td><td style="padding:8px;text-align:center;border:1px solid #ddd;">' . $seen_flag . '</td></tr>';
                }
                echo '</table>';
            }
            echo '</div>';
        }

        if ($action === 'test_archetype') {
            $archetype = sanitize_text_field($_POST['test_archetype'] ?? '');
            if ($archetype) {
                $cards   = gg_tp_get_archetype_cards($archetype);
                $matches = gg_tp_match_stock($cards);
                echo '<div class="notice notice-info"><p><strong>Test: ' . esc_html($archetype) . '</strong> — YGOPRODeck returned ' . count($cards) . ' cards. Found <strong>' . count($matches) . '</strong> in-stock WooCommerce matches.</p>';
                if (!empty($matches)) {
                    echo '<ul style="margin-top:8px;">';
                    foreach ($matches as $m) {
                        echo '<li>' . esc_html($m['post_title']) . ' — £' . esc_html(number_format((float)$m['price'], 2)) . ' (' . (int)$m['qty'] . ' in stock)</li>';
                    }
                    echo '</ul>';
                }
                echo '</div>';
            }
        }

        if ($action === 'force_process_url') {
            $force_url = esc_url_raw(trim($_POST['force_process_url'] ?? ''));
            if ($force_url) {
                $resp = wp_remote_get($force_url, [
                    'timeout'   => 30,
                    'sslverify' => false,
                    'headers'   => ['User-Agent' => 'Mozilla/5.0 (compatible; GrimeGames/1.0; +https://grimegames.com)'],
                ]);

                if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
                    $err = is_wp_error($resp) ? $resp->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($resp);
                    echo '<div class="notice notice-error is-dismissible"><p>Could not fetch URL: ' . esc_html($err) . '</p></div>';
                } else {
                    $raw_html   = wp_remote_retrieve_body($resp);
                    $tournament = gg_tp_parse_tournament_page($force_url, $raw_html, true);

                    if (empty($tournament['card_frequency']) && empty($tournament['winner'])) {
                        echo '<div class="notice notice-warning is-dismissible"><p>Fetched the page but could not extract any tournament data. Use the Parse test tool below to debug.</p></div>';
                    } else {
                        // Derive a readable title from the URL slug
                        $slug  = trim(parse_url($force_url, PHP_URL_PATH), '/');
                        $slug  = basename($slug);
                        $title = ucwords(str_replace(['-', '_'], ' ', $slug));

                        $top_cards     = array_keys(array_slice($tournament['card_frequency'], 0, 30, true));
                        $stock_matches = gg_tp_match_stock($top_cards);
                        $post_text     = gg_tp_generate_post($title, $tournament, $stock_matches, $force_url);
                        gg_tp_save_post($title, $post_text, current_time('mysql'), $tournament, $stock_matches);

                        // Mark as seen so the cron doesn't duplicate it
                        $seen_list   = get_option(GG_TP_SEEN_OPTION, []);
                        $seen_list[] = $force_url;
                        update_option(GG_TP_SEEN_OPTION, $seen_list);

                        $winner_str = $tournament['winner']
                            ? $tournament['winner'] . ' (' . $tournament['winner_deck'] . ')'
                            : 'not detected';
                        echo '<div class="notice notice-success is-dismissible"><p>✅ Post generated! <strong>Winner:</strong> ' . esc_html($winner_str)
                            . ' &nbsp;|&nbsp; <strong>Cards:</strong> ' . count($tournament['card_frequency'])
                            . ' &nbsp;|&nbsp; <strong>Stock matches:</strong> ' . count($stock_matches) . '</p></div>';
                    }
                }
            }
        }

        if ($action === 'test_parse_url') {
            $test_url = esc_url_raw(trim($_POST['test_parse_url'] ?? ''));
            if ($test_url) {
                $tournament = gg_tp_parse_tournament_page($test_url);

                echo '<div class="notice notice-info">';
                echo '<p><strong>Parse results for:</strong> ' . esc_html($test_url) . '</p>';
                echo '<table style="width:100%;border-collapse:collapse;margin-top:8px;">';
                $rows = [
                    'Winner'         => $tournament['winner'] ?: '<em style="color:#999">not found</em>',
                    'Winner Deck'    => $tournament['winner_deck'] ?: '<em style="color:#999">not found</em>',
                    'Runner Up'      => $tournament['runner_up'] ?: '<em style="color:#999">not found</em>',
                    'Runner Up Deck' => $tournament['runner_up_deck'] ?: '<em style="color:#999">not found</em>',
                    'Top 8 Decks'    => !empty($tournament['top8_decks']) ? implode(', ', array_map('esc_html', $tournament['top8_decks'])) : '<em style="color:#999">not found</em>',
                    'Deck Breakdown' => !empty($tournament['deck_breakdown'])
                        ? esc_html(implode(' | ', array_map(function($k, $v) { return "{$v}x {$k}"; }, array_keys($tournament['deck_breakdown']), $tournament['deck_breakdown'])))
                        : '<em style="color:#999">not found</em>',
                    'Cards found'    => count($tournament['card_frequency']) . ' distinct cards',
                    'Top 10 cards'   => !empty($tournament['card_frequency'])
                        ? esc_html(implode(', ', array_map(function($k, $v) { return "{$k} ({$v})"; }, array_keys(array_slice($tournament['card_frequency'], 0, 10, true)), array_slice($tournament['card_frequency'], 0, 10, true))))
                        : '<em style="color:#999">none</em>',
                ];
                foreach ($rows as $label => $value) {
                    echo '<tr><td style="padding:6px 10px;border:1px solid #ddd;font-weight:600;width:160px;">' . esc_html($label) . '</td>';
                    echo '<td style="padding:6px 10px;border:1px solid #ddd;">' . $value . '</td></tr>';
                }
                echo '</table>';

                // ----------------------------------------------------------------
                // Debug section: re-fetch page and show useful diagnostic info
                // ----------------------------------------------------------------
                $raw_resp = wp_remote_get($test_url, [
                    'timeout'   => 25,
                    'sslverify' => false,
                    'headers'   => ['User-Agent' => 'Mozilla/5.0 (compatible; GrimeGames/1.0; +https://grimegames.com)'],
                ]);
                if (!is_wp_error($raw_resp) && wp_remote_retrieve_response_code($raw_resp) === 200) {
                    $raw_html = wp_remote_retrieve_body($raw_resp);

                    // Apply same block-element newline trick used by the parser
                    $debug_html = preg_replace(
                        '/<\s*\/?\s*(p|div|h[1-6]|li|ul|ol|tr|td|th|br|hr|section|article|header|footer|aside|figure|figcaption|blockquote)\b[^>]*>/i',
                        "\n",
                        $raw_html
                    );
                    $debug_text = html_entity_decode(wp_strip_all_tags($debug_html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $debug_lines = preg_split('/[\r\n]+/', $debug_text);
                    $debug_lines = array_filter(array_map('trim', $debug_lines));

                    // Find lines that look like they COULD be player placement lines.
                    // Must: end with dash+number, start with an uppercase letter (not a digit/card qty).
                    // This filters out card lines like "1 Maliss <C> MTP-07" which start with digits.
                    $candidate_lines = [];
                    foreach ($debug_lines as $dl) {
                        if (preg_match('/^[\p{Lu}]/u', $dl)
                            && preg_match('/[\x{2010}-\x{2015}\x{2212}\-\x{2013}\x{2014}]\s*\d{1,2}(?:st|nd|rd|th)?\s*$/u', $dl)
                        ) {
                            $candidate_lines[] = $dl;
                        }
                    }

                    // Show candidate player lines
                    echo '<details style="margin-top:12px;" open>';
                    echo '<summary style="cursor:pointer;font-weight:600;color:#c0392b;">🧑‍💼 Candidate player lines (' . count($candidate_lines) . ' found) — these should match "Name – 1" format</summary>';
                    if (empty($candidate_lines)) {
                        echo '<p style="color:#c0392b;padding:8px;">⚠️ No candidate player lines found! The page may use a different format, or block-element newlines are still not working.</p>';
                    } else {
                        echo '<pre style="font-size:11px;background:#fff8e1;padding:10px;border-radius:4px;white-space:pre-wrap;overflow:auto;max-height:300px;margin-top:8px;">';
                        foreach (array_slice($candidate_lines, 0, 30) as $cl) {
                            // Hex-encode any unusual chars for inspection
                            $display = esc_html(mb_substr($cl, 0, 120));
                            echo $display . "\n";
                        }
                        echo '</pre>';
                    }
                    echo '</details>';

                    // Show first 60 non-empty lines from decoded text (to see actual structure)
                    $first_lines = array_values($debug_lines);
                    echo '<details style="margin-top:8px;">';
                    echo '<summary style="cursor:pointer;font-weight:600;color:#0073aa;">📄 First 60 non-empty lines of parsed text (after block-newline fix)</summary>';
                    echo '<pre style="font-size:11px;background:#f5f5f5;padding:10px;border-radius:4px;white-space:pre-wrap;overflow:auto;max-height:400px;margin-top:8px;">';
                    foreach (array_slice($first_lines, 0, 60) as $ln_i => $ln) {
                        echo esc_html(str_pad($ln_i, 3, ' ', STR_PAD_LEFT) . ': ' . mb_substr($ln, 0, 120)) . "\n";
                    }
                    echo '</pre>';
                    echo '</details>';

                    // Show raw snippet (original, no block fix) for comparison
                    $raw_text = wp_strip_all_tags($raw_html);
                    $raw_text = preg_replace('/[ \t]+/', ' ', $raw_text);
                    $raw_text = preg_replace('/\n{3,}/', "\n\n", $raw_text);
                    $offset   = min(2000, (int)(strlen($raw_text) * 0.1));
                    $snippet  = substr($raw_text, $offset, 3000);
                    echo '<details style="margin-top:8px;">';
                    echo '<summary style="cursor:pointer;font-weight:600;color:#888;">🔬 Raw page text WITHOUT block fix (chars ' . $offset . '–' . ($offset + 3000) . ') — for comparison</summary>';
                    echo '<pre style="font-size:11px;background:#f5f5f5;padding:10px;border-radius:4px;white-space:pre-wrap;overflow:auto;max-height:400px;margin-top:8px;">' . esc_html($snippet) . '</pre>';
                    echo '</details>';
                }
                echo '</div>';
            }
        }

        if ($action === 'mark_posted') {
            $idx   = (int)$_POST['post_index'];
            $posts = get_option(GG_TP_POSTS_OPTION, []);
            if (isset($posts[$idx])) {
                $posts[$idx]['posted']    = true;
                $posts[$idx]['posted_at'] = current_time('mysql');
                update_option(GG_TP_POSTS_OPTION, $posts);
                echo '<div class="notice notice-success is-dismissible"><p>Post marked as published.</p></div>';
            }
        }

        if ($action === 'delete_post') {
            $idx   = (int)$_POST['post_index'];
            $posts = get_option(GG_TP_POSTS_OPTION, []);
            array_splice($posts, $idx, 1);
            update_option(GG_TP_POSTS_OPTION, $posts);
        }
    }

    $posts    = get_option(GG_TP_POSTS_OPTION, []);
    $next_run = wp_next_scheduled('gg_tp_rss_cron');
    $pending  = count(array_filter($posts, function($p) { return !$p['posted']; }));
    ?>
    <div class="wrap">
        <h1>🏆 GrimeGames Tournament Posts</h1>
        <p style="color:#666;">Monitors <a href="<?= GG_TP_BLOG_URL ?>" target="_blank">Konami's event blog</a> for new YCS/Regional results, extracts top decks via <a href="https://ygoprodeck.com" target="_blank">YGOPRODeck</a>, and cross-references with your live WooCommerce stock.</p>

        <div style="display:flex;gap:20px;flex-wrap:wrap;margin:20px 0;">
            <div style="background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:16px 24px;min-width:160px;text-align:center;">
                <div style="font-size:28px;font-weight:700;color:#7B00FF;"><?= $pending ?></div>
                <div style="color:#666;font-size:13px;">Posts awaiting review</div>
            </div>
            <div style="background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:16px 24px;min-width:160px;text-align:center;">
                <div style="font-size:28px;font-weight:700;color:#333;"><?= count($posts) ?></div>
                <div style="color:#666;font-size:13px;">Total generated</div>
            </div>
            <div style="background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:16px 24px;min-width:200px;text-align:center;">
                <div style="font-size:14px;font-weight:600;color:#333;"><?= $next_run ? date('d M Y \a\t H:i', $next_run) : 'Not scheduled' ?></div>
                <div style="color:#666;font-size:13px;">Next scheduled RSS check</div>
            </div>
        </div>

        <!-- Manual Controls -->
        <div style="background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:20px;margin-bottom:20px;">
            <h2 style="margin-top:0;">Controls</h2>
            <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;">

                <form method="post" style="display:inline;">
                    <?php wp_nonce_field('gg_tp_nonce'); ?>
                    <input type="hidden" name="gg_tp_action" value="check_rss">
                    <button type="submit" class="button button-primary">🔄 Check RSS Now</button>
                    <p style="margin:4px 0 0;color:#666;font-size:12px;">Runs immediately, same as the scheduled job.</p>
                </form>

                <form method="post" style="display:inline;" onsubmit="return confirm('Full reset: clears ALL generated posts and all history, then re-scans from scratch. Continue?')">
                    <?php wp_nonce_field('gg_tp_nonce'); ?>
                    <input type="hidden" name="gg_tp_action" value="reset_seen">
                    <button type="submit" class="button">♻️ Full Reset &amp; Reprocess</button>
                    <p style="margin:4px 0 0;color:#666;font-size:12px;">Clears all posts &amp; history, then re-generates everything fresh.</p>
                </form>

                <form method="post" style="display:inline;" onsubmit="return confirm('Delete all generated posts? (History/seen list is kept.)')">
                    <?php wp_nonce_field('gg_tp_nonce'); ?>
                    <input type="hidden" name="gg_tp_action" value="clear_posts">
                    <button type="submit" class="button">🗑 Clear All Posts</button>
                    <p style="margin:4px 0 0;color:#666;font-size:12px;">Removes generated post copy only — does not re-scan.</p>
                </form>

                <form method="post" style="display:inline;">
                    <?php wp_nonce_field('gg_tp_nonce'); ?>
                    <input type="hidden" name="gg_tp_action" value="show_rss">
                    <button type="submit" class="button">🔍 Show RSS Contents</button>
                    <p style="margin:4px 0 0;color:#666;font-size:12px;">Shows all current RSS posts and whether they match tournament keywords.</p>
                </form>

                <form method="post" style="display:inline-flex;flex-direction:column;gap:6px;">
                    <?php wp_nonce_field('gg_tp_nonce'); ?>
                    <input type="hidden" name="gg_tp_action" value="test_archetype">
                    <label style="font-weight:600;font-size:13px;">Test Stock Match for Archetype:</label>
                    <div style="display:flex;gap:6px;">
                        <input type="text" name="test_archetype" class="regular-text" placeholder="e.g. Snake-Eye, Yubel, Ryzeal">
                        <button type="submit" class="button">Test</button>
                    </div>
                    <p style="margin:0;color:#666;font-size:12px;">Checks YGOPRODeck for the archetype's card list and shows which are in your WooCommerce stock.</p>
                </form>

            </div>

            <!-- Force Process URL — bypass the scraper for a specific post -->
            <div style="background:#e8f5e9;border:1px solid #a5d6a7;border-radius:6px;padding:16px;margin-top:16px;">
                <strong style="font-size:13px;">⚡ Force Generate Post from URL</strong>
                <p style="margin:4px 0 10px;color:#555;font-size:12px;">Paste any Konami deck-list post URL to immediately parse it and add it to your posts queue — bypasses the scraper entirely. Use this whenever a tournament isn't picked up automatically.</p>
                <form method="post" style="display:flex;gap:6px;align-items:flex-start;">
                    <?php wp_nonce_field('gg_tp_nonce'); ?>
                    <input type="hidden" name="gg_tp_action" value="force_process_url">
                    <input type="url" name="force_process_url" style="flex:1;" class="regular-text" placeholder="https://yugiohblog.konami.com/2026/02/300th-ycs-virginia-deck-lists/">
                    <button type="submit" class="button button-primary">⚡ Generate Post</button>
                </form>
            </div>

            <!-- Parse URL debugger -->
            <div style="background:#fff8e1;border:1px solid #ffe082;border-radius:6px;padding:16px;margin-top:16px;">
                <strong style="font-size:13px;">🔬 Test Tournament Page Parser</strong>
                <p style="margin:4px 0 10px;color:#666;font-size:12px;">Paste a Konami tournament post URL to see exactly what the parser extracts. Use the raw text snippet to tune the parser if fields are missing.</p>
                <form method="post" style="display:flex;gap:6px;align-items:flex-start;">
                    <?php wp_nonce_field('gg_tp_nonce'); ?>
                    <input type="hidden" name="gg_tp_action" value="test_parse_url">
                    <input type="url" name="test_parse_url" style="flex:1;" class="regular-text" placeholder="https://yugiohblog.konami.com/2025/ycs/...">
                    <button type="submit" class="button button-primary">Parse</button>
                </form>
            </div>
        </div>

        <!-- Generated Posts -->
        <h2>Generated Posts</h2>

        <?php if (empty($posts)): ?>
            <div style="background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:30px;text-align:center;color:#666;">
                <p style="font-size:16px;">No posts generated yet.</p>
                <p>Click <strong>Check RSS Now</strong> above to scan Konami's blog for recent tournament results.</p>
            </div>
        <?php else: ?>
            <?php foreach ($posts as $idx => $post): ?>
                <?php $is_posted = !empty($post['posted']); ?>
                <div style="background:#fff;border:1px solid <?= $is_posted ? '#b7e1b7' : '#e0e0e0' ?>;border-radius:6px;padding:20px;margin-bottom:16px;">

                    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
                        <div>
                            <strong style="font-size:15px;"><?= esc_html($post['source_title']) ?></strong>
                            <?php if (!empty($post['archetypes'])): ?>
                                <span style="margin-left:10px;color:#7B00FF;font-size:12px;">
                                    <?= esc_html(implode(', ', $post['archetypes'])) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div style="color:#999;font-size:12px;text-align:right;">
                            Generated: <?= esc_html($post['generated_at']) ?><br>
                            <?= (int)$post['stock_matches'] ?> archetype(s) matched stock
                            <?php if ($is_posted): ?>
                                <br><span style="color:green;">✓ Posted <?= esc_html($post['posted_at']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <pre style="background:#f9f9f9;border-left:4px solid #7B00FF;padding:16px;white-space:pre-wrap;font-family:inherit;font-size:14px;line-height:1.6;border-radius:0 4px 4px 0;margin:0 0 12px;"><?= esc_html($post['text']) ?></pre>

                    <div style="display:flex;gap:8px;">
                        <?php if (!$is_posted): ?>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field('gg_tp_nonce'); ?>
                                <input type="hidden" name="gg_tp_action" value="mark_posted">
                                <input type="hidden" name="post_index" value="<?= $idx ?>">
                                <button type="submit" class="button button-primary">✓ Mark as Posted</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this generated post?')">
                            <?php wp_nonce_field('gg_tp_nonce'); ?>
                            <input type="hidden" name="gg_tp_action" value="delete_post">
                            <input type="hidden" name="post_index" value="<?= $idx ?>">
                            <button type="submit" class="button">🗑 Delete</button>
                        </form>
                    </div>

                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
}