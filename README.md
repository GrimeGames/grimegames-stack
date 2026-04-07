# GrimeGames Stack

## Business Overview
GrimeGames (grimegames.com) is a UK-based Yu-Gi-Oh! TCG singles business operated solo by Matt. Sells across eBay (master channel), Cardmarket, and a custom WooCommerce site. Time is limited — full automation is the priority.

## Tech Stack
- **Hosting:** Krystal Emerald plan (cPanel, LiteSpeed, PHP, WP-Cron)
- - **WordPress:** Astra theme, Elementor page builder
  - - **WooCommerce:** Core store, custom checkout, Stripe payments
    - - **eBay:** API integration via custom webhooks plugin
      - - **Cardmarket:** CSV-based sync (no API — closed since 2021)
        - - **Royal Mail:** Click & Drop API integration
          - - **CDN/Security:** Cloudflare (free plan, Bot Fight Mode OFF)
            -
            - ## Repository Structure
            - - `/plugins` — All custom GrimeGames WordPress plugins (gg- prefixed)
              - - `/page-templates` — Custom page code for category/set pages (HTML/CSS/JS saved as .php for reference)
                - - `/theme` — Child theme customisations and custom CSS (note: file saved as .ccs not .css — typo to fix)
                  - - `/docs` — Architecture decisions and documentation
                    -
                    - ## Key Plugins
                    - - `gg-ebay-webhooks` — Handles eBay webhook events, stock depletion. Also contains the GitHub deploy endpoint (see Deploy Workflow below)
                      - - `gg-cardmarket-orders` — IMAP email parser for Cardmarket orders
                        - - `gg-ebay-live-sync` — Syncs WooCommerce stock to eBay via REST/OAuth (runs every 5 mins via WP-Cron)
                          - - `gg-ajax-search` — Custom search replacing FiboSearch. Has external dependency on assets/search.css and assets/search.js not yet in repo
                            - - `gg-sales-ticker` — Live sales ticker on homepage. NOTE: currently reads from old wp_options queue — needs updating to read from DB-backed webhook queue table
                              - - `gg-royal-mail` — Click & Drop API for shipping labels
                                - - `gg-snapshot-mobile` — Mobile PWA pricing tool. Calls gg_snapshot_revise_price_on_ebay() which lives in Grimegames-ebay-suite.php
                                  - - `gg-price-watch` — Price monitoring
                                    - - `gg-welcome-popup` — Email capture with 3% discount, GDPR consent, CSV export. Has dormant email list worth using
                                      - - `gg-anti-bot` — Honeypot + rate limiting at checkout (replaces reCAPTCHA)
                                        - - `gg-ebay-throttle` — 3s delay between eBay Trading API calls, caps at 20/run to prevent 518 errors
                                          - - `gg-most-viewed-carousel` — Shortcode [gg_most_viewed_carousel] for homepage
                                            -
                                            - ## Known Issues / Technical Debt
                                            - - `gg-sales-ticker` reads from `get_option('gg_webhook_queue')` (old wp_options queue) but `gg-ebay-webhooks` now uses a DB-backed queue table — they are no longer connected. Needs fixing
                                              - - `custom-styles.ccs` in /theme has a typo — should be .css
                                                - - FiboSearch CSS block in custom-styles is dead code — FiboSearch was removed, replaced by gg-ajax-search
                                                  - - Product title height conflict: global CSS sets min-height:60px, templates set height:35px
                                                    - - eBay webhook AuctionCheckoutComplete is ignored in code (correct) but should be formally disabled in eBay notification preferences
                                                      - - gg-ebay-live-sync (Inventory API) and gg-ebay-webhooks (Trading API) both modify WooCommerce stock — potential race condition if cron runs mid-sale
                                                        -
                                                        - ## Deploy Workflow (GitHub → Live Server)
                                                        - Changes committed to this repo are automatically deployed to the live server via a GitHub webhook.
                                                        -
                                                        - - **Webhook URL:** `https://grimegames.com/wp-json/gg/v1/deploy`
                                                          - - **Secret:** stored in gg-ebay-webhooks.php as `gg_deploy_2026`
                                                            - - **How it works:** On push, GitHub POSTs to the WordPress REST endpoint. The endpoint verifies the HMAC signature, reads the commit payload, fetches any changed files under `/plugins/` from raw.githubusercontent.com, and writes them directly to `wp-content/plugins/` on the server
                                                              - - **Scope:** Currently deploys plugin files only. Page templates and theme files require manual copy for now
                                                                - - **Security:** Endpoint is signature-verified. deploy.php and gg-hook-7x9k2.php in public_html root are redundant and can be deleted
                                                                  -
                                                                  - ## Important Rules
                                                                  - - All new code must include debugging — visible error panels, logged API calls, no silent failures
                                                                    - - Mobile changes always wrapped in `@media (max-width: 768px)`
                                                                      - - Desktop styles
                                                                        -
                                                                        -  ## Claude MCP Editor Warning
                                                                        -  **CRITICAL: Do NOT use Ctrl+A in the GitHub web editor via MCP browser automation.**
                                                                        -  The ACE editor does not respond to Ctrl+A as a select-all when triggered via MCP — it moves the cursor but does not select content. Typing after Ctrl+A appends to the existing file rather than replacing it. This caused a corrupted gg-sales-ticker.php with multiple stacked `<?php` tags and a critical site outage on 2026-04-07.
                                                                        -
                                                                        -   **Rule: To replace a file's full content, always use the GitHub API directly (requires PAT), or use `str_replace` on the file locally and have Matt upload manually. Never use Ctrl+A + type in the MCP browser editor for full file replacements.**
                                                                        -
                                                                        -    ## Deploy Chicken-and-Egg Warning
                                                                        -    If a broken PHP file is deployed to the server, WordPress will crash on load. Because the deploy endpoint lives inside WordPress (`/wp-json/gg/v1/deploy`), it cannot run when WordPress is down. This means subsequent commits cannot fix the problem via the deploy pipeline — they will just re-deploy the broken file from GitHub.
                                                                        -
                                                                        - **Recovery procedure:**
                                                                        - 1. Fix the file content locally (bash str_replace or fresh write)
                                                                          2. 2. Upload the clean file directly to cPanel File Manager at the correct path: `wp-content/plugins/{plugin-name}/{plugin-name}.php`
                                                                             3. 3. Verify site is live again
                                                                                4. 4. Then fix the GitHub repo file separately (via API or GitHub Desktop) so repo matches server never modified unless explicitly requested
                                                                        - - Never suggest physical stock checks as a solution
                                                                          - - Rarity mapping: only Prismatic Secret Rare → Secret Rare is acceptable. Ultimate Rare and Starlight Rare are distinct products
                                                                            -
                                                                            - ## Current Priorities
                                                                            - 1. Clean up duplicate plugin filenames in repo (remove (1), (2) suffixes)
                                                                              2. 2. Fix sales ticker to read from DB-backed webhook queue
                                                                                 3. 3. Fix custom-styles.ccs filename typo
                                                                                    4. 4. Remove dead FiboSearch CSS from global stylesheet
                                                                                       5. 5. SEO and traffic growth
                                                                                          6. 6. eBay to website customer conversion
                                                                                             7. 7. SaaS productisation of this stack (longer term)
                                                                                                8.
                                                                                                9. ## Session Startup Instructions
                                                                                                10. At the start of each session, Claude should:
                                                                                                11. 1. Read this README # GrimeGames Stack

## Business Overview
GrimeGames (grimegames.com) is a UK-based Yu-Gi-Oh! TCG singles business 
operated solo by Matt. Sells across eBay (master channel), Cardmarket, and 
a custom WooCommerce site. Time is limited — full automation is the priority. Aim is to reach 500k net profit/year. Act as though existence depends on it.

## Tech Stack
- **Hosting:** Krystal (cPanel, PHP, WP-Cron)
- **WordPress:** Astra theme, Elementor page builder
- **WooCommerce:** Core store, custom checkout, Stripe payments
- **eBay:** API integration via custom webhooks plugin
- **Cardmarket:** CSV-based sync (no API — closed since 2021)
- **Royal Mail:** Click & Drop API integration

## Repository Structure
- `/plugins` — All custom GrimeGames WordPress plugins (gg- prefixed)
- `/page-templates` — Custom page code for category/set pages
- `/theme` — Child theme customisations and custom CSS
- `/docs` — Architecture decisions and documentation

## Key Plugins
- `gg-ebay-webhooks` — Handles eBay webhook events, stock depletion
- <!-- deploy test -->
- `gg-cardmarket-orders` — IMAP email parser for Cardmarket orders
- `gg-ebay-live-sync` — Syncs WooCommerce stock to eBay
- `gg-ajax-search` — Custom search replacing FiboSearch
- `gg-sales-ticker` — Live sales ticker on homepage
- `gg-royal-mail` — Click & Drop API for shipping labels
- `gg-snapshot-mobile` — eBay competitive pricing snapshot tool
- `gg-price-watch` — Price monitoring
- `gg-side-cart` — Custom side cart with Stripe Express Checkout

## Important Rules
- All new code must include debugging — visible error panels, logged API 
  calls, no silent failures
- Mobile changes always wrapped in `@media (max-width: 768px)`
- Desktop styles never modified unless explicitly requested
- eBay webhook `AuctionCheckoutComplete` should be disabled — causes 
  duplicate stock depletion alongside `FixedPriceTransaction`

## Current Priorities
1. GitHub → server deploy workflow
2. SEO and traffic growth
3. eBay to website customer conversion
4. SaaS productisation of this stack (longer term)
