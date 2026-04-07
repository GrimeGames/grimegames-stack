<?php
/*
Plugin Name: GrimeGames Sales Ticker
Description: Logs eBay + WooCommerce sales and serves them as a JSON feed for the homepage ticker
Author: GrimeGames
Version: 2.0 - Live sync with eBay webhook queue
*/

defined('ABSPATH') || exit;

/* =========================
   DB TABLE ON ACTIVATION
   ========================= */

register_activation_hook(__FILE__, 'gg_ticker_create_table');

function gg_ticker_create_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'gg_sales_ticker';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        item_title varchar(255) NOT NULL,
        sale_price decimal(10,2) NOT NULL,
        source varchar(20) NOT NULL DEFAULT 'ebay',
        sold_at datetime NOT NULL,
        PRIMARY KEY (id)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

// Also create table on init in case activation hook was missed
add_action('init', function() {
    global $wpdb;
    $table = $wpdb->prefix . 'gg_sales_ticker';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        gg_ticker_create_table();
    }
}, 5);

/* =========================
   LOG A SALE
   ========================= */

/**
 * Record a sale in the ticker table.
 *
 * @param string      $item_title  Display title for the ticker
 * @param float       $sale_price  Sale price in GBP
 * @param string      $source      'ebay' or 'website'
 * @param string|null $sold_at     MySQL datetime. Defaults to current time.
 */
function gg_log_sale($item_title, $sale_price, $source = 'ebay', $sold_at = null) {
    global $wpdb;
    $table = $wpdb->prefix . 'gg_sales_ticker';

    $item_title = sanitize_text_field(substr($item_title, 0, 255));
    $sale_price = round((float)$sale_price, 2);
    $source     = in_array($source, ['ebay', 'website']) ? $source : 'ebay';
    $sold_at    = $sold_at ?: current_time('mysql');

    $inserted = $wpdb->insert($table, [
        'item_title' => $item_title,
        'sale_price' => $sale_price,
        'source'     => $source,
        'sold_at'    => $sold_at,
    ], ['%s', '%f', '%s', '%s']);

    if ($inserted) {
        // Keep table tidy — only last 100 sales
        $wpdb->query("DELETE FROM {$table} WHERE id NOT IN (
            SELECT id FROM (SELECT id FROM {$table} ORDER BY sold_at DESC LIMIT 100) t
        )");
        // Bust the REST cache so the next request reflects the new sale immediately
        foreach ([5, 10, 20] as $n) {
            delete_transient('gg_ticker_feed_' . $n);
        }
    }

    return $inserted;
}

/* =========================
   REST ENDPOINT — JSON FEED
   ========================= */

add_action('rest_api_init', function() {
    register_rest_route('gg/v1', '/sales-ticker', [
        'methods'             => 'GET',
        'callback'            => 'gg_ticker_get_sales',
        'permission_callback' => '__return_true',
    ]);

    // Debug endpoint — admin only
    register_rest_route('gg/v1', '/ticker-debug', [
        'methods'             => 'GET',
        'callback'            => 'gg_ticker_debug',
        'permission_callback' => 'is_user_logged_in',
    ]);
});

function gg_ticker_get_sales($request) {
    $limit     = min(20, max(1, (int)($request->get_param('limit') ?? 5)));
    $cache_key = 'gg_ticker_feed_' . $limit;

    // Serve from cache if available (avoids a DB hit on every page load)
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return rest_ensure_response($cached);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'gg_sales_ticker';

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT item_title, sale_price, source, sold_at
         FROM {$table}
         ORDER BY sold_at DESC
         LIMIT %d",
        $limit
    ), ARRAY_A);

    if (empty($rows)) {
        // Don't cache the empty state — retry quickly until we have real data
        return rest_ensure_response([
            'sales'       => [],
            'placeholder' => true,
        ]);
    }

    $sales = array_map(function($row) {
        return [
            'title'    => $row['item_title'],
            'price'    => number_format((float)$row['sale_price'], 2),
            'source'   => $row['source'],
            'time_ago' => gg_ticker_time_ago($row['sold_at']),
        ];
    }, $rows);

    $response = [
        'sales'       => $sales,
        'placeholder' => false,
    ];

    // Cache for 2 minutes — cleared automatically when a new sale is logged
    set_transient($cache_key, $response, 2 * MINUTE_IN_SECONDS);

    return rest_ensure_response($response);
}

function gg_ticker_time_ago($datetime) {
    $now  = current_time('timestamp');
    $then = strtotime($datetime);
    $diff = max(0, $now - $then);

    if ($diff < 60)          return 'just now';
    if ($diff < 3600)        return floor($diff / 60) . ' min' . (floor($diff / 60) === 1 ? '' : 's') . ' ago';
    if ($diff < 86400)       return floor($diff / 3600) . ' hr' . (floor($diff / 3600) === 1 ? '' : 's') . ' ago';
    if ($diff < 86400 * 7)   return floor($diff / 86400) . ' day' . (floor($diff / 86400) === 1 ? '' : 's') . ' ago';
    return date('j M', $then);
}

/* =========================
   WOOCOMMERCE HOOK
   ========================= */

add_action('woocommerce_order_status_completed',  'gg_ticker_log_woo_sale', 20, 1);
add_action('woocommerce_order_status_processing', 'gg_ticker_log_woo_sale', 20, 1);

function gg_ticker_log_woo_sale($order_id) {
    if (get_post_meta($order_id, '_gg_ticker_logged', true)) {
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) return;

    foreach ($order->get_items() as $item) {
        $title = $item->get_name();
        $qty   = $item->get_quantity();
        $price = (float)$item->get_total() / max(1, $qty);

        for ($i = 0; $i < $qty; $i++) {
            gg_log_sale($title, $price, 'website');
        }
    }

    update_post_meta($order_id, '_gg_ticker_logged', time());
}

/* =================================================
   LIVE SYNC — reads gg_webhook_queue every 30 secs
   ================================================= */

add_action('init', 'gg_ticker_sync_from_webhook', 20);

/**
 * Scans the eBay webhook queue for processed sale events and logs
 * any we haven't seen before. Runs at most every 30 seconds.
 */
function gg_ticker_sync_from_webhook() {
    // Rate-limit: don't hammer this on every single page load
    if (get_transient('gg_ticker_sync_lock')) return;
    set_transient('gg_ticker_sync_lock', 1, 30);

    $queue = get_option('gg_webhook_queue', []);

    if (empty($queue)) {
        // No queue yet — try a one-time seed from the raw webhook log
        gg_ticker_seed_from_log();
        return;
    }

    $sale_events = ['ItemSold', 'FixedPriceTransaction'];
    $synced      = get_option('gg_ticker_synced_txns', []);
    $updated     = false;

    foreach ($queue as $item) {
        // Only pick up items the webhook plugin has already handled
        if (empty($item['processed'])) continue;

        $data  = $item['data'] ?? [];
        $event = $data['NotificationEventName'] ?? '';

        if (!in_array($event, $sale_events)) continue;

        // Stable unique key for this transaction
        $txn_id   = $data['Transaction']['TransactionID'] ?? '';
        $item_id  = $data['Item']['ItemID'] ?? ($data['Transaction']['Item']['ItemID'] ?? '');
        $queued   = $item['queued_at'] ?? 0;
        $sync_key = md5("{$txn_id}_{$item_id}_{$queued}");

        if (in_array($sync_key, $synced)) continue;

        $title   = gg_ticker_extract_title($data);
        $price   = gg_ticker_extract_price($data);
        $sold_at = $queued ? date('Y-m-d H:i:s', $queued) : current_time('mysql');

        if ($title) {
            gg_log_sale($title, max(0.01, $price), 'ebay', $sold_at);
            $synced[] = $sync_key;
            $updated  = true;
        }
    }

    if ($updated) {
        if (count($synced) > 300) {
            $synced = array_slice($synced, -300);
        }
        update_option('gg_ticker_synced_txns', $synced);
    }
}

/* =================================================
   ONE-TIME HISTORICAL SEED — reads gg_webhook_log
   ================================================= */

/**
 * Called when the queue is empty. Walks gg_webhook_log for any
 * "Processing webhook: ItemSold/FixedPriceTransaction" entries and
 * backfills the ticker table. Only runs when the table is empty.
 */
<?php
   /*
   Plugin Name: GrimeGames Sales Ticker
   Description: Logs eBay + WooCommerce sales and serves them as a JSON feed for the homepage ticker
   Author: GrimeGames
   Version: 2.1 - Fixed to read from DB-backed webhook queue (not wp_options)
   */
   defined('ABSPATH') || exit;

/* ========================= DB TABLE ON ACTIVATION ========================= */
register_activation_hook(__FILE__, 'gg_ticker_create_table');
function gg_ticker_create_table() {
       global $wpdb;
       $table   = $wpdb->prefix . 'gg_sales_ticker';
       $charset = $wpdb->get_charset_collate();
       $sql     = "CREATE TABLE IF NOT EXISTS {$table} (
               id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                       item_title varchar(255) NOT NULL,
                               sale_price decimal(10,2) NOT NULL,
                                       source varchar(20) NOT NULL DEFAULT 'ebay',
                                               sold_at datetime NOT NULL,
                                                       PRIMARY KEY (id)
                                                           ) {$charset};";
       require_once ABSPATH . 'wp-admin/includes/upgrade.php';
       dbDelta($sql);
}

// Also create table on init in case activation hook was missed
add_action('init', function() {
       global $wpdb;
       $table = $wpdb->prefix . 'gg_sales_ticker';
       if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
                  gg_ticker_create_table();
       }
}, 5);

/* ========================= LOG A SALE ========================= */
function gg_log_sale($item_title, $sale_price, $source = 'ebay', $sold_at = null) {
       global $wpdb;
       $table      = $wpdb->prefix . 'gg_sales_ticker';
       $item_title = sanitize_text_field(substr($item_title, 0, 255));
       $sale_price = round((float)$sale_price, 2);
       $source     = in_array($source, ['ebay', 'website']) ? $source : 'ebay';
       $sold_at    = $sold_at ?: current_time('mysql');
   
       $inserted = $wpdb->insert($table, [
                                         'item_title' => $item_title,
                                         'sale_price' => $sale_price,
                                         'source'     => $source,
                                         'sold_at'    => $sold_at,
                                     ], ['%s', '%f', '%s', '%s']);
   
       if ($inserted) {
                  $wpdb->query("DELETE FROM {$table} WHERE id NOT IN (
                              SELECT id FROM (SELECT id FROM {$table} ORDER BY sold_at DESC LIMIT 100) t
                                      )");
                  foreach ([5, 10, 20] as $n) {
                                 delete_transient('gg_ticker_feed_' . $n);
                  }
       }
       return $inserted;
}

/* ========================= REST ENDPOINT — JSON FEED ========================= */
add_action('rest_api_init', function() {
       register_rest_route('gg/v1', '/sales-ticker', [
                                   'methods'             => 'GET',
                                   'callback'            => 'gg_ticker_get_sales',
                                   'permission_callback' => '__return_true',
                               ]);
       register_rest_route('gg/v1', '/ticker-debug', [
                                   'methods'             => 'GET',
                                   'callback'            => 'gg_ticker_debug',
                                   'permission_callback' => 'is_user_logged_in',
                               ]);
});

function gg_ticker_get_sales($request) {
       $limit     = min(20, max(1, (int)($request->get_param('limit') ?? 5)));
       $cache_key = 'gg_ticker_feed_' . $limit;
       $cached    = get_transient($cache_key);
       if ($cached !== false) {
                  return rest_ensure_response($cached);
       }
   
       global $wpdb;
       $table = $wpdb->prefix . 'gg_sales_ticker';
       $rows  = $wpdb->get_results($wpdb->prepare(
                  "SELECT item_title, sale_price, source, sold_at FROM {$table} ORDER BY sold_at DESC LIMIT %d",
                  $limit
              ), ARRAY_A);
   
       if (empty($rows)) {
                  return rest_ensure_response(['sales' => [], 'placeholder' => true]);
       }
   
       $sales = array_map(function($row) {
                  return [
                                 'title'    => $row['item_title'],
                                 'price'    => number_format((float)$row['sale_price'], 2),
                                 'source'   => $row['source'],
                                 'time_ago' => gg_ticker_time_ago($row['sold_at']),
                             ];
       }, $rows);
   
       $response = ['sales' => $sales, 'placeholder' => false];
       set_transient($cache_key, $response, 2 * MINUTE_IN_SECONDS);
       return rest_ensure_response($response);
}

function gg_ticker_time_ago($datetime) {
       $diff = max(0, current_time('timestamp') - strtotime($datetime));
       if ($diff < 60)          return 'just now';
       if ($diff < 3600)        return floor($diff / 60) . ' min' . (floor($diff / 60) === 1 ? '' : 's') . ' ago';
       if ($diff < 86400)       return floor($diff / 3600) . ' hr' . (floor($diff / 3600) === 1 ? '' : 's') . ' ago';
       if ($diff < 86400 * 7)   return floor($diff / 86400) . ' day' . (floor($diff / 86400) === 1 ? '' : 's') . ' ago';
       return date('j M', strtotime($datetime));
}

/* ========================= WOOCOMMERCE HOOK ========================= */
add_action('woocommerce_order_status_completed', 'gg_ticker_log_woo_sale', 20, 1);
add_action('woocommerce_order_status_processing', 'gg_ticker_log_woo_sale', 20, 1);
function gg_ticker_log_woo_sale($order_id) {
       if (get_post_meta($order_id, '_gg_ticker_logged', true)) return;
       $order = wc_get_order($order_id);
       if (!$order) return;
       foreach ($order->get_items() as $item) {
                  $qty   = $item->get_quantity();
                  $price = (float)$item->get_total() / max(1, $qty);
                  for ($i = 0; $i < $qty; $i++) {
                                 gg_log_sale($item->get_name(), $price, 'website');
                  }
       }
       update_post_meta($order_id, '_gg_ticker_logged', time());
}

/* =================================================
   LIVE SYNC — reads DB-backed webhook queue table
      Fixed in v2.1: previously read from get_option('gg_webhook_queue')
         which was the old wp_options queue. gg-ebay-webhooks now uses
            the wp_gg_webhook_queue DB table instead.
            ================================================= */
add_action('init', 'gg_ticker_sync_from_webhook', 20);

function gg_ticker_sync_from_webhook() {
       if (get_transient('gg_ticker_sync_lock')) return;
       set_transient('gg_ticker_sync_lock', 1, 30);
   
       global $wpdb;
       $queue_table = $wpdb->prefix . 'gg_webhook_queue';
   
       // Check the DB queue table exists
       if ($wpdb->get_var("SHOW TABLES LIKE '{$queue_table}'") !== $queue_table) {
                  error_log('GG Ticker: webhook queue table not found — skipping sync');
                  return;
       }

   // DEPLOY TEST v1.1: subfolder path fix verification
   // DEPLOY TEST: 2026-04-07 clean filename deploy verification
       $sale_events = ['ItemSold', 'FixedPriceTransaction'];
       $synced      = get_option('gg_ticker_synced_txns', []);
       $updated     = false;
   
       // Read done rows from the last 24 hours only to keep it fast
       $rows = $wpdb->get_results(
                  "SELECT id, data FROM {$queue_table}
                           WHERE status = 'done'
                                    AND queued_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                                             ORDER BY queued_at DESC
                                                      LIMIT 50",
                  ARRAY_A
              );
   
       foreach ($rows as $row) {
                  $data  = json_decode($row['data'], true);
                  $event = $data['NotificationEventName'] ?? '';
          
                  if (!in_array($event, $sale_events)) continue;
          
                  $txn_id   = $data['Transaction']['TransactionID'] ?? '';
                  $item_id  = $data['Item']['ItemID'] ?? ($data['Transaction']['Item']['ItemID'] ?? '');
                  $sync_key = md5("{$txn_id}_{$item_id}_{$row['id']}");
          
                  if (in_array($sync_key, $synced)) continue;
          
                  $title   = gg_ticker_extract_title($data);
                  $price   = gg_ticker_extract_price($data);
                  $sold_at = current_time('mysql');
          
                  if ($title) {
                                 gg_log_sale($title, max(0.01, $price), 'ebay', $sold_at);
                                 $synced[] = $sync_key;
                                 $updated  = true;
                                 error_log("GG Ticker: Logged sale from DB queue — {$title} @ £{$price}");
                  }
       }
   
       if ($updated) {
                  if (count($synced) > 300) {
                                 $synced = array_slice($synced, -300);
                  }
                  update_option('gg_ticker_synced_txns', $synced);
       }
}

/* ========================= DATA EXTRACTION HELPERS ========================= */
function gg_ticker_extract_title($data) {
       if (!empty($data['Item']['Title']) && is_string($data['Item']['Title'])) {
                  return sanitize_text_field($data['Item']['Title']);
       }
       if (!empty($data['Transaction']['Item']['Title']) && is_string($data['Transaction']['Item']['Title'])) {
                  return sanitize_text_field($data['Transaction']['Item']['Title']);
       }
       $item_id = $data['Item']['ItemID'] ?? ($data['Transaction']['Item']['ItemID'] ?? '');
       if ($item_id && function_exists('gg_find_product_by_ebay_id')) {
                  $product_id = gg_find_product_by_ebay_id((string)$item_id);
                  if ($product_id) {
                                 $post = get_post($product_id);
                                 if ($post) return $post->post_title;
                  }
       }
       return '';
}

function gg_ticker_extract_price($data) {
       $price = gg_ticker_cast_price($data['Transaction']['TransactionPrice'] ?? null);
       if ($price > 0) return $price;
       $price = gg_ticker_cast_price($data['Item']['BuyItNowPrice'] ?? null);
       if ($price > 0) return $price;
       $price = gg_ticker_cast_price($data['Item']['StartPrice'] ?? null);
       if ($price > 0) return $price;
       $item_id = $data['Item']['ItemID'] ?? '';
       if ($item_id && function_exists('gg_find_product_by_ebay_id') && function_exists('wc_get_product')) {
                  $product_id = gg_find_product_by_ebay_id((string)$item_id);
                  if ($product_id) {
                                 $product = wc_get_product($product_id);
                                 if ($product) {
                                                    $p = (float)$product->get_price();
                                                    if ($p > 0) return $p;
                                 }
                  }
       }
       return 0;
}

function gg_ticker_cast_price($raw) {
       if ($raw === null) return 0;
       if (is_numeric($raw)) return (float)$raw;
       if (is_string($raw) && $raw !== '') return (float)$raw;
       if (is_array($raw)) {
                  foreach ($raw as $key => $val) {
                                 if (is_numeric($key) && is_numeric($val)) return (float)$val;
                                 if (in_array($key, ['_', '#text', '@text'], true) && is_numeric($val)) return (float)$val;
                  }
                  foreach ($raw as $key => $val) {
                                 if ($key !== '@attributes' && is_numeric($val)) return (float)$val;
                  }
       }
       return 0;
}

/* ========================= BACKFILL + DEBUG ENDPOINTS ========================= */
add_action('rest_api_init', function() {
       register_rest_route('gg/v1', '/ticker-backfill', [
                                   'methods'             => 'GET',
                                   'callback'            => 'gg_ticker_backfill_woo',
                                   'permission_callback' => function($request) {
                                                  if (current_user_can('manage_options')) return true;
                                                  return $request->get_param('key') === 'gg_backfill_2026';
                                   },
                               ]);
});

function gg_ticker_backfill_woo() {
       $orders  = wc_get_orders([
                                        'status'       => ['processing', 'completed'],
                                        'date_created' => '>' . date('Y-m-d', strtotime('-30 days')),
                                        'limit'        => 100,
                                        'orderby'      => 'date',
                                        'order'        => 'DESC',
                                    ]);
       $logged  = 0;
       $skipped = 0;
       $details = [];
       foreach ($orders as $order) {
                  $order_id = $order->get_id();
                  if (get_post_meta($order_id, '_gg_ticker_logged', true)) { $skipped++; continue; }
                  foreach ($order->get_items() as $item) {
                                 $qty     = $item->get_quantity();
                                 $price   = (float)$item->get_total() / max(1, $qty);
                                 $sold_at = $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : current_time('mysql');
                                 for ($i = 0; $i < $qty; $i++) { gg_log_sale($item->get_name(), $price, 'website', $sold_at); $logged++; }
                                 $details[] = ['order_id' => $order_id, 'title' => $item->get_name(), 'qty' => $qty, 'price' => $price, 'date' => $sold_at];
                  }
                  update_post_meta($order_id, '_gg_ticker_logged', time());
       }
       return ['backfilled' => $logged, 'skipped' => $skipped, 'orders_scanned' => count($orders), 'items' => $details];
}

function gg_ticker_debug() {
       global $wpdb;
       $table       = $wpdb->prefix . 'gg_sales_ticker';
       $queue_table = $wpdb->prefix . 'gg_webhook_queue';
       $ticker_rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY sold_at DESC LIMIT 10", ARRAY_A);
       $queue_count = $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table} WHERE status = 'done'") ?? 'table not found';
       return [
                  'ticker_rows'      => $ticker_rows,
                  'queue_done_count' => $queue_count,
                  'synced_count'     => count(get_option('gg_ticker_synced_txns', [])),
                  'sync_lock_ttl'    => get_transient('gg_ticker_sync_lock') ? 'locked (30s)' : 'free',
                  'version'          => '2.1 - DB queue fix',
              ];
}function gg_ticker_seed_from_log() {
    global $wpdb;
    $table = $wpdb->prefix . 'gg_sales_ticker';

    $count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    if ($count > 0) return; // Already have data, skip

    $log         = get_option('gg_webhook_log', []);
    $sale_events = ['ItemSold', 'FixedPriceTransaction'];
    $seeded      = 0;

    // Reverse so we process newest first; sold_at will reflect actual log timestamp
    foreach (array_reverse($log) as $entry) {
        if ($seeded >= 50) break;

        $message = $entry['message'] ?? '';
        if (strpos($message, 'Processing webhook:') === false) continue;

        $notification = $entry['context']['notification'] ?? [];
        $event        = $notification['NotificationEventName'] ?? '';

        if (!in_array($event, $sale_events)) continue;

        $title   = gg_ticker_extract_title($notification);
        $price   = gg_ticker_extract_price($notification);
        $sold_at = $entry['timestamp'] ?? current_time('mysql');

        if ($title) {
            gg_log_sale($title, max(0.01, $price), 'ebay', $sold_at);
            $seeded++;
        }
    }
}

/* =================================================
   DATA EXTRACTION HELPERS
   ================================================= */

/**
 * Pull a clean title out of an eBay Trading API notification array.
 * Falls back to the WooCommerce product title via eBay item ID lookup
 * if the webhook plugin's gg_find_product_by_ebay_id() is available.
 */
function gg_ticker_extract_title($data) {
    // Standard Trading API: Item/Title
    if (!empty($data['Item']['Title']) && is_string($data['Item']['Title'])) {
        return sanitize_text_field($data['Item']['Title']);
    }

    // Some notifications nest title under Transaction/Item
    if (!empty($data['Transaction']['Item']['Title']) && is_string($data['Transaction']['Item']['Title'])) {
        return sanitize_text_field($data['Transaction']['Item']['Title']);
    }

    // Last resort: look up WooCommerce product by eBay ItemID
    $item_id = $data['Item']['ItemID'] ?? ($data['Transaction']['Item']['ItemID'] ?? '');
    if ($item_id && function_exists('gg_find_product_by_ebay_id')) {
        $product_id = gg_find_product_by_ebay_id((string)$item_id);
        if ($product_id) {
            $post = get_post($product_id);
            if ($post) return $post->post_title;
        }
    }

    return '';
}

/**
 * Pull a sale price out of an eBay Trading API notification array.
 *
 * eBay SOAP XML → SimpleXML → json_encode → json_decode can produce
 * price values as a plain string, a numeric string, or an array when
 * the XML element also carries attributes (e.g. currencyID="GBP").
 * This helper handles all three shapes.
 */
function gg_ticker_extract_price($data) {
    // Primary: Transaction/TransactionPrice
    $price = gg_ticker_cast_price($data['Transaction']['TransactionPrice'] ?? null);
    if ($price > 0) return $price;

    // Alternative: Item/BuyItNowPrice
    $price = gg_ticker_cast_price($data['Item']['BuyItNowPrice'] ?? null);
    if ($price > 0) return $price;

    // Alternative: Item/StartPrice
    $price = gg_ticker_cast_price($data['Item']['StartPrice'] ?? null);
    if ($price > 0) return $price;

    // Fallback: WooCommerce product price
    $item_id = $data['Item']['ItemID'] ?? '';
    if ($item_id && function_exists('gg_find_product_by_ebay_id') && function_exists('wc_get_product')) {
        $product_id = gg_find_product_by_ebay_id((string)$item_id);
        if ($product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                $p = (float)$product->get_price();
                if ($p > 0) return $p;
            }
        }
    }

    return 0;
}

/**
 * Cast a raw eBay price value to float.
 * Handles string, numeric, and SimpleXML-derived arrays.
 */
function gg_ticker_cast_price($raw) {
    if ($raw === null) return 0;

    if (is_numeric($raw)) return (float)$raw;
    if (is_string($raw) && $raw !== '') return (float)$raw;

    if (is_array($raw)) {
        // SimpleXML with attributes: text content may be under a numeric key,
        // '_', '#text', or '@text' key.
        foreach ($raw as $key => $val) {
            if (is_numeric($key) && is_numeric($val)) return (float)$val;
            if (in_array($key, ['_', '#text', '@text'], true) && is_numeric($val)) return (float)$val;
        }
        // Broader fallback: first non-attribute numeric value
        foreach ($raw as $key => $val) {
            if ($key !== '@attributes' && is_numeric($val)) return (float)$val;
        }
    }

    return 0;
}

/* =========================
   DEBUG ENDPOINT (admin)
   ========================= */

/* Register the backfill endpoint alongside the existing routes */
add_action('rest_api_init', function() {
    register_rest_route('gg/v1', '/ticker-backfill', [
        'methods'             => 'GET',
        'callback'            => 'gg_ticker_backfill_woo',
        'permission_callback' => function($request) {
            // Allow if logged in OR if secret key matches
            if (current_user_can('manage_options')) return true;
            return $request->get_param('key') === 'gg_backfill_2026';
        },
    ]);
});

/**
 * One-time backfill: scans recent WooCommerce orders (processing + completed)
 * from the last 30 days and logs any that weren't already ticker-logged.
 *
 * Hit /wp-json/gg/v1/ticker-backfill while logged in as admin.
 * Safe to run multiple times — skips orders already logged via _gg_ticker_logged meta.
 */
function gg_ticker_backfill_woo() {
    $args = [
        'status'       => ['processing', 'completed'],
        'date_created' => '>' . date('Y-m-d', strtotime('-30 days')),
        'limit'        => 100,
        'orderby'      => 'date',
        'order'        => 'DESC',
    ];

    $orders  = wc_get_orders($args);
    $logged  = 0;
    $skipped = 0;
    $details = [];

    foreach ($orders as $order) {
        $order_id = $order->get_id();

        // Skip if already logged
        if (get_post_meta($order_id, '_gg_ticker_logged', true)) {
            $skipped++;
            continue;
        }

        foreach ($order->get_items() as $item) {
            $title = $item->get_name();
            $qty   = $item->get_quantity();
            $price = (float)$item->get_total() / max(1, $qty);

            $sold_at = $order->get_date_created()
                ? $order->get_date_created()->date('Y-m-d H:i:s')
                : current_time('mysql');

            for ($i = 0; $i < $qty; $i++) {
                gg_log_sale($title, $price, 'website', $sold_at);
                $logged++;
            }

            $details[] = [
                'order_id' => $order_id,
                'title'    => $title,
                'qty'      => $qty,
                'price'    => $price,
                'date'     => $sold_at,
            ];
        }

        update_post_meta($order_id, '_gg_ticker_logged', time());
    }

    return [
        'backfilled'     => $logged,
        'skipped'        => $skipped,
        'orders_scanned' => count($orders),
        'items'          => $details,
    ];
}

function gg_ticker_debug() {
    global $wpdb;
    $table = $wpdb->prefix . 'gg_sales_ticker';

    $queue       = get_option('gg_webhook_queue', []);
    $log         = get_option('gg_webhook_log',   []);
    $sale_events = ['ItemSold', 'FixedPriceTransaction'];

    $queue_sales = [];
    foreach ($queue as $item) {
        $data  = $item['data'] ?? [];
        $event = $data['NotificationEventName'] ?? 'unknown';
        if (!in_array($event, $sale_events)) continue;

        $queue_sales[] = [
            'event'           => $event,
            'processed'       => !empty($item['processed']),
            'item_id'         => $data['Item']['ItemID'] ?? 'n/a',
            'title_raw'       => $data['Item']['Title'] ?? 'n/a',
            'price_raw'       => $data['Transaction']['TransactionPrice'] ?? 'n/a',
            'queued_at'       => isset($item['queued_at']) ? date('Y-m-d H:i:s', $item['queued_at']) : 'n/a',
            'extracted_title' => gg_ticker_extract_title($data),
            'extracted_price' => gg_ticker_extract_price($data),
        ];
    }

    $log_sales = [];
    foreach (array_reverse($log) as $entry) {
        if (strpos($entry['message'] ?? '', 'Processing webhook:') === false) continue;
        $notification = $entry['context']['notification'] ?? [];
        $event        = $notification['NotificationEventName'] ?? '';
        if (!in_array($event, $sale_events)) continue;

        $log_sales[] = [
            'event'           => $event,
            'timestamp'       => $entry['timestamp'] ?? 'n/a',
            'extracted_title' => gg_ticker_extract_title($notification),
            'extracted_price' => gg_ticker_extract_price($notification),
        ];
    }

    $ticker_rows = $wpdb->get_results(
        "SELECT * FROM {$table} ORDER BY sold_at DESC LIMIT 10",
        ARRAY_A
    );

    return [
        'queue_total'   => count($queue),
        'queue_sales'   => $queue_sales,
        'log_total'     => count($log),
        'log_sales'     => $log_sales,
        'ticker_rows'   => $ticker_rows,
        'synced_count'  => count(get_option('gg_ticker_synced_txns', [])),
        'sync_lock_ttl' => get_transient('gg_ticker_sync_lock') ? 'locked (30s)' : 'free',
    ];
}
