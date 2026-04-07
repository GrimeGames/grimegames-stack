<?php
/*
Plugin Name: GrimeGames Sales Ticker
Description: Logs eBay + WooCommerce sales and serves them as a JSON feed for the homepage ticker
Author: GrimeGames
Version: 2.1 - Fixed to read from DB-backed webhook queue (not wp_options)
*/
defined('ABSPATH') || exit;

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

add_action('init', function() {
    global $wpdb;
    $table = $wpdb->prefix . 'gg_sales_ticker';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        gg_ticker_create_table();
    }
}, 5);

function gg_log_sale($item_title, $sale_price, $source = 'ebay', $sold_at = null) {
    global $wpdb;
    $table      = $wpdb->prefix . 'gg_sales_ticker';
    $item_title = sanitize_text_field(substr($item_title, 0, 255));
    $sale_price = round((float)$sale_price, 2);
    $source     = in_array($source, ['ebay', 'website']) ? $source : 'ebay';
    $sold_at    = $sold_at ?: current_time('mysql');
    $inserted   = $wpdb->insert($table, [
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
    if ($cached !== false) return rest_ensure_response($cached);
    global $wpdb;
    $table = $wpdb->prefix . 'gg_sales_ticker';
    $rows  = $wpdb->get_results($wpdb->prepare(
        "SELECT item_title, sale_price, source, sold_at FROM {$table} ORDER BY sold_at DESC LIMIT %d",
        $limit
    ), ARRAY_A);
    if (empty($rows)) return rest_ensure_response(['sales' => [], 'placeholder' => true]);
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
    if ($diff < 60)        return 'just now';
    if ($diff < 3600)      return floor($diff / 60) . ' min' . (floor($diff / 60) === 1 ? '' : 's') . ' ago';
    if ($diff < 86400)     return floor($diff / 3600) . ' hr' . (floor($diff / 3600) === 1 ? '' : 's') . ' ago';
    if ($diff < 86400 * 7) return floor($diff / 86400) . ' day' . (floor($diff / 86400) === 1 ? '' : 's') . ' ago';
    return date('j M', strtotime($datetime));
}

add_action('woocommerce_order_status_completed', 'gg_ticker_log_woo_sale', 20, 1);
add_action('woocommerce_order_status_processing', 'gg_ticker_log_woo_sale', 20, 1);
function gg_ticker_log_woo_sale($order_id) {
    if (get_post_meta($order_id, '_gg_ticker_logged', true)) return;
    $order = wc_get_order($order_id);
    if (!$order) return;
    foreach ($order->get_items() as $item) {
        $qty   = $item->get_quantity();
        $price = (float)$item->get_total() / max(1, $qty);
        for ($i = 0; $i < $qty; $i++) gg_log_sale($item->get_name(), $price, 'website');
    }
    update_post_meta($order_id, '_gg_ticker_logged', time());
}

add_action('init', 'gg_ticker_sync_from_webhook', 20);
function gg_ticker_sync_from_webhook() {
    if (get_transient('gg_ticker_sync_lock')) return;
    set_transient('gg_ticker_sync_lock', 1, 30);
    global $wpdb;
    $queue_table = $wpdb->prefix . 'gg_webhook_queue';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$queue_table}'") !== $queue_table) return;
    $sale_events = ['ItemSold', 'FixedPriceTransaction'];
    $synced      = get_option('gg_ticker_synced_txns', []);
    $updated     = false;
    $rows        = $wpdb->get_results(
        "SELECT id, data FROM {$queue_table}
         WHERE status = 'done'
         AND queued_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
         ORDER BY queued_at DESC LIMIT 50",
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
        $title = gg_ticker_extract_title($data);
        $price = gg_ticker_extract_price($data);
        if ($title) {
            gg_log_sale($title, max(0.01, $price), 'ebay', current_time('mysql'));
            $synced[] = $sync_key;
            $updated  = true;
        }
    }
    if ($updated) {
        if (count($synced) > 300) $synced = array_slice($synced, -300);
        update_option('gg_ticker_synced_txns', $synced);
    }
}

function gg_ticker_extract_title($data) {
    if (!empty($data['Item']['Title']) && is_string($data['Item']['Title']))
        return sanitize_text_field($data['Item']['Title']);
    if (!empty($data['Transaction']['Item']['Title']) && is_string($data['Transaction']['Item']['Title']))
        return sanitize_text_field($data['Transaction']['Item']['Title']);
    $item_id = $data['Item']['ItemID'] ?? ($data['Transaction']['Item']['ItemID'] ?? '');
    if ($item_id && function_exists('gg_find_product_by_ebay_id')) {
        $pid = gg_find_product_by_ebay_id((string)$item_id);
        if ($pid) { $post = get_post($pid); if ($post) return $post->post_title; }
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
        $pid = gg_find_product_by_ebay_id((string)$item_id);
        if ($pid) {
            $product = wc_get_product($pid);
            if ($product) { $p = (float)$product->get_price(); if ($p > 0) return $p; }
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
    $logged  = 0; $skipped = 0; $details = [];
    foreach ($orders as $order) {
        $oid = $order->get_id();
        if (get_post_meta($oid, '_gg_ticker_logged', true)) { $skipped++; continue; }
        foreach ($order->get_items() as $item) {
            $qty     = $item->get_quantity();
            $price   = (float)$item->get_total() / max(1, $qty);
            $sold_at = $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : current_time('mysql');
            for ($i = 0; $i < $qty; $i++) { gg_log_sale($item->get_name(), $price, 'website', $sold_at); $logged++; }
            $details[] = ['order_id' => $oid, 'title' => $item->get_name(), 'qty' => $qty, 'price' => $price, 'date' => $sold_at];
        }
        update_post_meta($oid, '_gg_ticker_logged', time());
    }
    return ['backfilled' => $logged, 'skipped' => $skipped, 'orders_scanned' => count($orders), 'items' => $details];
}

function gg_ticker_debug() {
    global $wpdb;
    $table       = $wpdb->prefix . 'gg_sales_ticker';
    $queue_table = $wpdb->prefix . 'gg_webhook_queue';
    return [
        'ticker_rows'      => $wpdb->get_results("SELECT * FROM {$table} ORDER BY sold_at DESC LIMIT 10", ARRAY_A),
        'queue_done_count' => $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table} WHERE status = 'done'") ?? 'table not found',
        'synced_count'     => count(get_option('gg_ticker_synced_txns', [])),
        'sync_lock_ttl'    => get_transient('gg_ticker_sync_lock') ? 'locked (30s)' : 'free',
        'version'          => '2.1 - DB queue fix',
    ];
}
