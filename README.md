# GrimeGames Stack

## Business Overview
GrimeGames (grimegames.com) is a UK-based Yu-Gi-Oh! TCG singles business 
operated solo by Matt. Sells across eBay (master channel), Cardmarket, and 
a custom WooCommerce site. Time is limited — full automation is the priority.

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
