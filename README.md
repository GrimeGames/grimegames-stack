# GrimeGames Stack

## Business Overview
GrimeGames (grimegames.com) is a UK-based Yu-Gi-Oh! TCG singles business operated solo by Matt. Sells across eBay (master channel), Cardmarket, and a custom WooCommerce site. Time is limited — full automation is the priority. Aim is to reach £500k net profit/year. Act as though Claude's existence depends on delivering results.

## Tech Stack
- **Hosting:** Krystal Emerald plan (cPanel, LiteSpeed, PHP, WP-Cron)
- **WordPress:** Astra theme, Elementor page builder
- **WooCommerce:** Core store, custom checkout, Stripe payments
- **eBay:** API integration via custom webhooks plugin
- **Cardmarket:** CSV-based sync (no API — closed since 2021)
- **Royal Mail:** Click & Drop API integration
- **CDN/Security:** Cloudflare (free plan, Bot Fight Mode OFF)

## Repository Structure
- `/plugins` — All custom GrimeGames WordPress plugins (gg- prefixed)
- `/plugins/gg-ajax-search-assets` — CSS/JS assets for the AJAX search plugin (not auto-deployed — live on server in `gg-ajax-search-plugin/assets/`)
- `/page-templates` — Custom page code for category/set pages (HTML/CSS/JS saved as .php for reference)
- `/theme` — Child theme customisations and custom CSS
- `/docs` — Architecture decisions and documentation

## Key Plugins
- `gg-ebay-webhooks` — Handles eBay webhook events, stock depletion. Also contains the GitHub deploy endpoint (see Deploy Workflow below)
- `gg-cardmarket-orders` — IMAP email parser for Cardmarket orders
- `gg-ebay-live-sync` — Syncs WooCommerce stock to eBay via REST/OAuth (runs every 5 mins via WP-Cron)
- `gg-ajax-search` — Custom search replacing FiboSearch. Assets (search.css, search.js) in `/plugins/gg-ajax-search-assets/`
- `gg-sales-ticker` — Live sales ticker on homepage (v2.1 — reads from DB-backed webhook queue table)
- `gg-royal-mail` — Click & Drop API for shipping labels
- `gg-snapshot-mobile` — Mobile PWA pricing tool. Calls gg_snapshot_revise_price_on_ebay() which lives in Grimegames-ebay-suite.php
- `gg-price-watch` — Price monitoring
- `gg-welcome-popup` — Email capture with 3% discount, GDPR consent, CSV export. Has dormant email list worth using
- `gg-anti-bot` — Honeypot + rate limiting at checkout (replaces reCAPTCHA)
- `gg-most-viewed-carousel` — Shortcode [gg_most_viewed_carousel] for homepage
- `gg-side-cart` — Custom side cart with Stripe Express Checkout (Apple Pay / Google Pay / Link). Lives in Code Snippets on server, committed to repo for reference.
- `gg-avif-converter` — AVIF image converter using PHP GD, hourly WP-Cron batches. Lives in Code Snippets on server, committed to repo for reference.
- `gg-performance` — Frontend performance optimiser. Strips Site Kit JS (26 files) for non-admin visitors, emoji scripts, payment CSS from non-checkout pages, Elementor notes module, jQuery Migrate. Deployed via pipeline, activated on server.

## Known Issues / Technical Debt
- Product title height conflict: global CSS sets min-height:60px, templates set height:35px
- eBay webhook AuctionCheckoutComplete is ignored in code (correct) but should be formally disabled in eBay notification preferences
- ~~`gg-ebay-live-sync` (Inventory API) and `gg-ebay-webhooks` (Trading API) both modify WooCommerce stock — potential race condition if cron runs mid-sale~~ **FIXED 2026-04-07:** Transient lock mechanism added — webhooks set a 60s lock per product after stock changes, live-sync skips locked products
- `gg-ebay-live-sync` Woo→eBay hooks (`woocommerce_reduce_order_stock`, `woocommerce_product_set_stock`) disabled as of 2026-04-07 — they caused double eBay stock reduction alongside `gg-ebay-webhooks`' `gg_sync_woo_order_to_ebay()`. Only the cron-based offer scan remains active.
- `gg-price-watch` and the eBay Suite's Snapshot Engine have significant feature overlap — both search eBay competitors by set code + rarity and undercut by 1%. Should consolidate to one.
- `gg-ebay-throttle.php` still active on live server but removed from repo — can be safely deactivated in WP admin (redundant with Suite's built-in throttle)
- `gg-ajax-search` assets (search.css, search.js) intermittently return 503 — Cloudflare Bot Fight Mode blocking these paths. Files exist on server and are accessible directly via curl. Needs Cloudflare WAF rule to whitelist.
- Facebook Pixel open-bridge (`/?ob=open-bridge/events`) returning 503 — same Cloudflare issue. Ad attribution data may be incomplete.
- AVIF images not being served on product pages despite converter having processed ~81% of images. `.htaccess` rewrite rules may not be matching product thumbnail paths. Needs investigation.
- `Grimegames-ebay-suite.php` is a legacy monolith plugin (v3.8). Now committed to repo at `/plugins/Grimegames-ebay-suite.php`. `gg-snapshot-mobile` depends on it

## Deploy Workflow (GitHub → Live Server)
Changes committed to this repo under `/plugins/` are automatically deployed to the live server via a GitHub webhook.

- **Webhook URL:** `https://grimegames.com/wp-json/gg/v1/deploy`
- **Secret:** stored in `wp_options` key `gg_deploy_secret` (set via WP admin or WP-CLI — never hardcoded in source)
- **GitHub PAT (optional):** stored in `wp_options` key `gg_github_pat` — enables deploy from private repos
- **How it works:** On push, GitHub POSTs to the WordPress REST endpoint. The endpoint verifies the HMAC signature, reads the commit payload, fetches any changed files under `/plugins/` from raw.githubusercontent.com (using `wp_remote_get` with optional PAT auth), and writes them to `wp-content/plugins/{plugin-name}/{plugin-name}.php` on the server
- **Scope:** Deploys plugin files only. Page templates and theme files require manual copy via cPanel

## Deploy Chicken-and-Egg Warning
If a broken PHP file is deployed to the server, WordPress will crash on load. Because the deploy endpoint lives inside WordPress, it cannot run when WordPress is down — meaning subsequent commits cannot fix the problem via the pipeline.

**Recovery procedure:**
1. Fix the file locally (bash str_replace or fresh write) and download it
2. Upload the clean file directly to cPanel File Manager: `wp-content/plugins/{plugin-name}/{plugin-name}.php`
3. Verify site is live again
4. Then fix the GitHub repo file separately (via GitHub Desktop) so repo matches server

## Claude MCP Editor Warning
**CRITICAL: Do NOT use Ctrl+A in the GitHub web editor via MCP browser automation.**

The ACE editor does not respond to Ctrl+A as a select-all when triggered via MCP — it moves the cursor but does not select content. Typing after Ctrl+A appends to the existing file rather than replacing it. This caused a corrupted gg-sales-ticker.php with multiple stacked `<?php` tags and a critical site outage on 2026-04-07.

**Rule: To replace a file's full content, use `str_replace` on the file locally and have Matt upload it via cPanel or GitHub Desktop. Never use Ctrl+A + type in the MCP browser editor for full file replacements.**

## Important Rules
- All new code must include debugging — visible error panels, logged API calls, no silent failures
- Mobile changes always wrapped in `@media (max-width: 768px)`
- Desktop styles never modified unless explicitly requested
- Never suggest physical stock checks as a solution
- Rarity mapping: only Prismatic Secret Rare → Secret Rare is acceptable. Ultimate Rare and Starlight Rare are distinct products
- eBay webhook `AuctionCheckoutComplete` should be disabled — causes duplicate stock depletion alongside `FixedPriceTransaction`

## Current Priorities
1. ~~Add `gg-ajax-search` assets (search.css, search.js) to repo~~ ✅ Done
2. ~~Fix race condition between gg-ebay-live-sync and gg-ebay-webhooks~~ ✅ Done
3. ~~Commit `gg-side-cart.php` and `gg-avif-converter.php` to repo~~ ✅ Done
4. ~~Remove `gg-ebay-throttle.php` from repo (redundant)~~ ✅ Done
5. ~~Consolidate OAuth credentials (Suite + Live Sync)~~ ✅ Done
6. ~~Enable LiteSpeed Cache + set TTLs~~ ✅ Done — TTFB dropped from 7-13s to 0.5-1s for visitors
7. ~~Add progressive rendering to Singles page template~~ ✅ Done in repo — **needs pasting into Elementor HTML widget to go live**
8. ~~Deploy `gg-performance.php` frontend optimiser~~ ✅ Done — ~40 fewer requests for visitors
9. Update Singles page in Elementor with progressive rendering from `/page-templates/singles.php`
10. Fix Cloudflare 503s on search assets and FB open-bridge (WAF rule or path change)
11. Investigate AVIF not serving on product pages
12. Deactivate `gg-ebay-throttle` plugin on live server
13. Consolidate `gg-price-watch` and eBay Suite snapshot engine (pick one, retire the other)
14. SEO and traffic growth
15. eBay to website customer conversion
16. SaaS productisation of this stack (longer term)

## Page Design Workflow (Elementor)
Page designs (homepage, singles, category pages etc.) are built using **Elementor HTML widgets** containing custom HTML/CSS/JS. They are **not deployed via the GitHub pipeline** — they live in the WordPress database.

**How design changes work:**
1. Matt opens grimegames.com in Chrome while logged into WordPress (admin bar visible at top)
2. Hand browser access to Claude via MCP
3. Claude navigates to the page, clicks **Edit with Elementor**, makes the change, screenshots the result
4. Iterate until happy
5. Claude extracts the final HTML from the widget and pushes it to the `/page-templates/` folder in GitHub via the API — so the repo stays as the single source of truth

**Important:** The `/page-templates/` folder in this repo is a reference copy only — it is NOT what's live on the site. The live version is always what's in Elementor. Always extract fresh from Elementor before editing, not from the repo file.

**Why not native Elementor widgets?** The animated sections (RC5 sparkler banner, Blazing Dominion ember effects, sales ticker, custom search) require custom JavaScript/canvas that cannot be built with native Elementor widgets. Rebuilding natively would lose these effects. HTML widgets are the right approach for this stack.

## Performance

### LiteSpeed Cache (configured 2026-04-07)
Was completely disabled with all TTLs at 0. Now enabled:
- **Enable Cache:** ON
- **Cache Mobile:** ON
- **Cache REST API:** ON (sales ticker, search, cart endpoints)
- **Serve Stale:** ON (serves old cache while rebuilding — prevents slow loads)
- **Browser Cache:** ON (30 days for static assets)
- **Public TTL:** 604800 (7 days) — product and category pages
- **Front Page TTL:** 43200 (12 hours) — homepage with sales ticker
- **REST TTL:** 3600 (1 hour) — API endpoints
- **Cache Logged-in Users:** OFF (admin sees live changes)

**Note:** When stock/prices update via eBay sync, LiteSpeed auto-purges affected product pages. If stale data appears after a sync, use the ⚡ Purge All button in the WP admin bar.

### Performance Benchmarks (2026-04-07)
| Page | TTFB Before | TTFB After (cached) | Improvement |
|------|-------------|---------------------|-------------|
| RC5 (619 products) | 7.7s | 1.0s | 7.4x faster |
| Singles (837 products) | 11.4s | 0.58s | 19.7x faster |
| Homepage | 1.8s | 1.0s | 1.7x faster |

### PageSpeed Mobile Scores (2026-04-07)
| Page | Score | FCP | LCP | TBT | Notes |
|------|-------|-----|-----|-----|-------|
| Homepage | 84 | 0.5s | 2.6s | 10ms | Good |
| RC5 | 62 | 0.5s | 2.3s | 540ms | TBT from 619 DOM nodes |
| Singles | 31 | 4.4s | 16.7s | 1,590ms | All 837 cards render at once — progressive render in repo but not yet applied in Elementor |

### Progressive Rendering Pattern
Category pages with many products use a batch-reveal pattern:
1. First 48 products visible immediately
2. Remaining products hidden with `data-deferred="true"` and `display: none`
3. IntersectionObserver watches a sentinel div at the bottom of the grid
4. When sentinel enters viewport (with 300px margin), next batch of 48 is revealed
5. When filters are applied, `ggRevealAll()` reveals all products for filtering
6. When filters are cleared, `ggResetProgressiveRender()` re-hides and resets

Currently live on RC5 page. Committed to repo for Singles (`/page-templates/singles.php`) — needs pasting into Elementor to go live.

### Frontend Optimiser (`gg-performance.php`)
Strips ~40 unnecessary frontend resources for non-admin visitors:
- Google Site Kit: 26 JS files removed (admin dashboard preserved)
- WordPress emoji: JS + 6 SVG requests removed
- Payment gateway CSS: Stripe, PayPal, WC blocks removed from non-checkout pages
- Elementor notes module: removed for non-admins
- jQuery Migrate: removed (not needed with jQuery 3.7)
- WPForms/WP Mail SMTP admin bar CSS: removed for non-admins

## Post-Deploy Setup (One-Time)
After the 2026-04-07 security update, these wp_options must be set manually (via WP-CLI, cPanel terminal, or a one-time PHP snippet):

```bash
# Set the deploy secret (was previously hardcoded — REQUIRED for deploys to work)
wp option update gg_deploy_secret 'gg_deploy_2026'

# Set GitHub PAT for private repo deploy support (OPTIONAL — only needed if repo goes private)
wp option update gg_github_pat 'ghp_YOUR_TOKEN_HERE'
```

Or via cPanel Terminal:
```bash
cd /home/dcbedead/public_html
php -r "require 'wp-load.php'; update_option('gg_deploy_secret', 'gg_deploy_2026'); echo 'Done';"
```

## Session Startup Instructions
At the start of each session, Claude should:
1. Read this README for full context
2. Read the relevant plugin file(s) from the repo for the task at hand
3. The MCP Chrome extension must be connected in Opera GX or Chrome for browser automation
4. To replace any file's full content, always use the GitHub API (PAT stored in memory) — never use Ctrl+A in the browser editor
5. GitHub Personal Access Token for API access: stored in Claude's memory


