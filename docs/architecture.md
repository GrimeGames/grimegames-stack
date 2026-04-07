# GrimeGames — Architecture Reference

## Database Tables

### `wp_gg_webhook_queue`
Created by `gg-ebay-webhooks`. Stores incoming eBay webhook events for async processing.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT UNSIGNED | Auto-increment PK |
| data | LONGTEXT | JSON-encoded eBay notification payload |
| queued_at | DATETIME | When the event was received |
| attempts | TINYINT UNSIGNED | Retry count (max 3, defined as GG_WEBHOOK_MAX_ATTEMPTS) |
| last_attempt | DATETIME | Timestamp of last processing attempt |
| status | ENUM | `pending`, `processing`, `done`, `failed` |
| result | TEXT | JSON result from last processing attempt |

Indexes: `status`, `queued_at`. Rows pruned after 7 days.

### `wp_gg_webhook_log`
Created by `gg-ebay-webhooks`. Structured activity log.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT UNSIGNED | Auto-increment PK |
| level | VARCHAR(10) | `info`, `success`, `warning`, `error` |
| message | VARCHAR(500) | Human-readable message |
| context | LONGTEXT | JSON-encoded context data |
| created_at | DATETIME | Log entry timestamp |

Rows pruned after 30 days (runs on ~1 in 50 writes to avoid overhead).

### `wp_gg_sales_ticker`
Created by `gg-sales-ticker`. Stores recent sales for the homepage ticker.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT UNSIGNED | Auto-increment PK |
| item_title | VARCHAR(255) | Display title |
| sale_price | DECIMAL(10,2) | Sale price in GBP |
| source | VARCHAR(20) | `ebay` or `website` |
| sold_at | DATETIME | Sale timestamp |

Capped at 100 rows (oldest pruned on insert).

---

## WooCommerce Product Meta Keys

| Meta Key | Set By | Purpose |
|----------|--------|---------|
| `_gg_ebay_item_id` | gg-ebay-webhooks, gg-cardmarket-orders | eBay item ID for the listing |
| `_gg_ebay_synced` | gg-ebay-webhooks | Timestamp of last WooCommerce→eBay stock sync |
| `_gg_ebay_offer_id` | gg-ebay-live-sync | eBay Inventory API offer ID |
| `_gg_ebay_sku` | gg-ebay-live-sync | eBay SKU (used by Inventory API) |
| `_gg_ebay_sync_enabled` | gg-ebay-live-sync | Whether live sync is active for this product |
| `_gg_ticker_logged` | gg-sales-ticker | Timestamp — prevents double-logging WooCommerce orders to ticker |

---

## wp_options Keys (Credentials & Config)

| Option Key | Stored By | Contains |
|------------|-----------|----------|
| `ebay_access_token` | Grimegames-ebay-suite | eBay OAuth access token |
| `ebay_refresh_token` | Grimegames-ebay-suite | eBay OAuth refresh token |
| `ebay_access_expires` | Grimegames-ebay-suite | Token expiry timestamp |
| `ebay_client_id` | Grimegames-ebay-suite | eBay app Client ID |
| `ebay_client_secret` | Grimegames-ebay-suite | eBay app Client Secret |
| `gg_suite_settings` | Grimegames-ebay-suite | Sync behaviour flags (throttle, auto_image, etc.) |
| `gg_cm_imap_host` | gg-cardmarket-orders | IMAP host (imap.ionos.co.uk) |
| `gg_cm_imap_user` | gg-cardmarket-orders | IMAP username |
| `gg_cm_imap_pass` | gg-cardmarket-orders | IMAP password |
| `gg_cm_imap_port` | gg-cardmarket-orders | IMAP port |
| `gg_cm_imap_folder` | gg-cardmarket-orders | IMAP folder to watch |
| `gg_rm_api_key` | gg-royal-mail | Royal Mail Click & Drop API key |
| `gg_ticker_synced_txns` | gg-sales-ticker | Array of already-synced transaction IDs (dedup) |
| `gg_last_sync_time` | Grimegames-ebay-suite | Last full eBay sync timestamp |
| `gg_price_snapshot_v1` | Grimegames-ebay-suite | Cached competitor price snapshot data |
| `gg_snapshot_api_errors` | Grimegames-ebay-suite | Snapshot API error log |
| `gg_snapshot_debug_log` | Grimegames-ebay-suite | Snapshot debug log |

---

## eBay API Calls in Use

### Trading API (via `gg_trading_call()` in Grimegames-ebay-suite.php)
| Call | Used By | Purpose |
|------|---------|---------|
| `GetMyeBaySelling` | gg-ebay-live-sync | Fetch active listings |
| `GetItem` | Grimegames-ebay-suite | Fetch individual item details |
| `ReviseFixedPriceItem` | Grimegames-ebay-suite (snapshot) | Update listing price |
| `ReviseInventoryStatus` | gg-ebay-webhooks | Update listing quantity (WooCommerce→eBay on order) |

### Inventory API (REST, via `ebay_api()` in gg-ebay-live-sync)
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/sell/inventory/v1/offer` | Fetch offer details by SKU |
| POST | `/sell/inventory/v1/bulk_update_price_quantity` | Bulk stock/price update |
| GET | `/sell/fulfillment/v1/order` | Fetch recent eBay orders |

### Notification API
eBay pushes SOAP XML webhooks to `https://grimegames.com/wp-json/gg/v1/ebay-webhook`.

**Subscribed events (sent by eBay):** `FixedPriceTransaction`, `ItemRevised`, `ItemOutOfStock`, `ItemClosed`, `ItemSold`

**Processed events (acted on):** `FixedPriceTransaction` (stock deduction), `ItemRevised` (price/stock update), `ItemOutOfStock`, `ItemClosed`

**Ignored events (received but discarded):** `ItemSold` — subscribed but explicitly ignored in code as all GrimeGames listings are fixed price. Returns `status: ignored`.

**Not subscribed:** `AuctionCheckoutComplete` — not in the subscription XML. Handled gracefully if eBay sends it anyway (returns `status: ignored`). This was identified as a source of duplicate stock deductions in the past — keep it unsubscribed.

---

## Grimegames-ebay-suite.php

This is a legacy monolith plugin (~v3.8) that lives on the server but is **not yet committed to this repo**. It is the backbone of the eBay integration and exposes functions used by other plugins.

**Location on server:** `wp-content/plugins/Grimegames-ebay-suite/Grimegames-ebay-suite.php`

**Key functions exposed (used by other plugins):**

| Function | Used By | Purpose |
|----------|---------|---------|
| `gg_trading_call($call, $xml)` | gg-ebay-webhooks, gg-ebay-live-sync | Makes an authenticated eBay Trading API call |
| `gg_get_item($item_id)` | gg-ebay-webhooks | Fetches full item data from eBay |
| `gg_xml($s)` | gg-ebay-webhooks | XML-escapes a string for API payloads |
| `gg_token_user()` | suite internals | Returns current user OAuth token |
| `gg_token_app()` | suite internals | Returns current app OAuth token |
| `gg_snapshot_revise_price_on_ebay()` | gg-snapshot-mobile (self) | Revises price on eBay — defined in gg-snapshot-mobile.php itself with a `function_exists` guard, NOT in ebay-suite |

**TODO:** This file is now committed to the repo at `/plugins/Grimegames-ebay-suite.php`. Until, any plugin that calls its functions will silently fail if the suite plugin is deactivated.

---

## Race Condition — gg-ebay-live-sync vs gg-ebay-webhooks — RESOLVED

Both plugins modify WooCommerce stock independently:

- `gg-ebay-webhooks` reduces stock in real-time when a `FixedPriceTransaction` webhook arrives
- `gg-ebay-live-sync` runs every 5 minutes via WP-Cron and aligns WooCommerce stock with eBay offer quantities

**Risk (was):** If a webhook arrives and reduces stock to 0, but the cron fires immediately after and reads the WooCommerce stock before the webhook has written it, it could push a stale (non-zero) quantity back to eBay.

**Mitigation (implemented 2026-04-07):**

1. **Transient lock:** `gg-ebay-webhooks` sets `set_transient('gg_stock_lock_' . $product_id, time(), 60)` after both `gg_handle_item_sold()` and `gg_handle_item_revised()` stock changes.
2. **Lock check:** `gg-ebay-live-sync`'s `gg_ebay_sync_offer_scan()` checks `get_transient('gg_stock_lock_' . $pid)` and skips any locked product with log message `"SKIP scan: stock locked by webhook"`.
3. **Duplicate hook removal:** `gg-ebay-live-sync`'s `woocommerce_reduce_order_stock` and `woocommerce_product_set_stock` hooks were disabled (commented out). These caused double eBay stock reduction because `gg-ebay-webhooks` already handles Woo→eBay via `gg_sync_woo_order_to_ebay()` using Trading API `ReviseInventoryStatus`. Only the cron-based offer scan in live-sync remains active.

---

## wp_options Keys (Deploy & GitHub)

| Option Key | Set By | Contains |
|------------|--------|----------|
| `gg_deploy_secret` | Manual (one-time setup) | HMAC secret for GitHub webhook deploy verification |
| `gg_github_pat` | Manual (optional) | GitHub Personal Access Token for private repo file fetches during deploy |

---

## WooCommerce Product Conventions

- **SKU format:** eBay item ID is stored in `_gg_ebay_item_id` post meta — not in the WooCommerce SKU field
- **Rarity/set filtering:** Handled via JavaScript on category pages using `data-rarity` and `data-set` HTML attributes — not stored in WooCommerce taxonomies
- **Stock management:** All products use `_manage_stock = yes`
- **Pricing:** WooCommerce price is kept 5% below eBay price (set by `gg-ebay-webhooks` on `ItemRevised` events)
- **Individual listings per rarity:** Variable products are NOT used — each rarity variant is a separate WooCommerce product. This is required for the JS filter architecture on category pages.

