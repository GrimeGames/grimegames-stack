<?php
/*
Plugin Name: GrimeGames eBay Webhooks Addon
Description: Real-time eBay notifications → WooCommerce updates (works with eBay Suite v3.8)
Author: GrimeGames
Version: 2.0 - DB-backed queue, retry logic
*/

defined('ABSPATH') || exit;

define('GG_WEBHOOK_MAX_ATTEMPTS', 3);

/* =========================
   DATABASE SETUP
   ========================= */

register_activation_hook(__FILE__, 'gg_webhook_install_tables');

function gg_webhook_install_tables() {
  global $wpdb;
  $charset = $wpdb->get_charset_collate();

  $queue_table = $wpdb->prefix . 'gg_webhook_queue';
  $sql_queue = "CREATE TABLE IF NOT EXISTS {$queue_table} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    data LONGTEXT NOT NULL,
    queued_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt DATETIME NULL,
    status ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
    result TEXT NULL,
    PRIMARY KEY (id),
    KEY status (status),
    KEY queued_at (queued_at)
  ) {$charset};";

  $log_table = $wpdb->prefix . 'gg_webhook_log';
  $sql_log = "CREATE TABLE IF NOT EXISTS {$log_table} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    level VARCHAR(10) NOT NULL,
    message VARCHAR(500) NOT NULL,
    context LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY level (level),
    KEY created_at (created_at)
  ) {$charset};";

  require_once ABSPATH . 'wp-admin/includes/upgrade.php';
  dbDelta($sql_queue);
  dbDelta($sql_log);

  // Migrate legacy wp_options data if present
  gg_webhook_migrate_options();
}

// Ensure tables exist even if activation hook was missed (e.g. manual upload)
add_action('plugins_loaded', function() {
  global $wpdb;
  $queue_table = $wpdb->prefix . 'gg_webhook_queue';
  if ($wpdb->get_var("SHOW TABLES LIKE '{$queue_table}'") !== $queue_table) {
    gg_webhook_install_tables();
  }
});

function gg_webhook_migrate_options() {
  // Migrate old wp_options log entries to DB
  $old_log = get_option('gg_webhook_log', []);
  if (!empty($old_log)) {
    foreach ($old_log as $entry) {
      gg_webhook_log(
        $entry['level'] ?? 'info',
        '[migrated] ' . ($entry['message'] ?? ''),
        $entry['context'] ?? []
      );
    }
    delete_option('gg_webhook_log');
  }
  // Old queue items are stale — discard
  delete_option('gg_webhook_queue');
}

/* =========================
   WEBHOOK RECEIVER ENDPOINT
   ========================= */

add_action('rest_api_init', function() {
  register_rest_route('gg/v1', '/ebay-webhook', [
    'methods'             => 'POST',
    'callback'            => 'gg_webhook_receiver',
    'permission_callback' => '__return_true',
  ]);

  register_rest_route('gg/v1', '/webhook-log', [
    'methods'             => 'GET',
    'callback'            => 'gg_webhook_get_log',
    'permission_callback' => 'is_user_logged_in',
  ]);
});

/* =========================
   WEBHOOK RECEIVER FUNCTION
   ========================= */

function gg_webhook_receiver($request) {
  $body    = $request->get_body();
  $headers = $request->get_headers();

  gg_webhook_log('info', 'Webhook received', [
    'body_preview' => substr($body, 0, 500),
    'timestamp'    => current_time('mysql'),
  ]);


  // Extract event type from SOAPAction header
  $soap_action = '';
  $event_type  = 'Unknown';

  if (isset($headers['soapaction']) && !empty($headers['soapaction'][0])) {
    $soap_action = trim($headers['soapaction'][0], '"');
    if (preg_match('/\/notification\/(.+)$/', $soap_action, $matches)) {
      $event_type = $matches[1];
    }
  }

  // Parse the SOAP XML payload
  $xml = @simplexml_load_string($body);

  if ($xml === false) {
    gg_webhook_log('error', 'Invalid XML payload', ['body' => substr($body, 0, 500)]);
    return new WP_Error('invalid_xml', 'Invalid XML', ['status' => 400]);
  }

  $xml->registerXPathNamespace('soapenv', 'http://schemas.xmlsoap.org/soap/envelope/');
  $xml->registerXPathNamespace('ebl', 'urn:ebay:apis:eBLBaseComponents');

  $body_nodes = $xml->xpath('//soapenv:Body/*');

  if (empty($body_nodes)) {
    gg_webhook_log('error', 'No SOAP body found', ['xml' => substr($body, 0, 500)]);
    return new WP_Error('invalid_soap', 'No SOAP body', ['status' => 400]);
  }

  $notification_xml = $body_nodes[0];
  $data = json_decode(json_encode($notification_xml), true);
  $data['NotificationEventName'] = $event_type;

  gg_webhook_log('info', "Event type detected: {$event_type}", [
    'soap_action'    => $soap_action,
    'data_structure' => array_keys($data),
  ]);

  // Queue for async processing
  gg_queue_webhook($data);

  gg_webhook_log('success', 'Webhook queued for processing', [
    'notification_type' => $event_type,
    'item_id'           => $data['Item']['ItemID'] ?? 'not found',
  ]);

  return [
    'status'    => 'received',
    'timestamp' => current_time('mysql'),
  ];
}

/* =========================
   WEBHOOK QUEUE (DB-backed)
   ========================= */

function gg_queue_webhook($data) {
  global $wpdb;
  $table = $wpdb->prefix . 'gg_webhook_queue';

  $wpdb->insert($table, [
    'data'      => wp_json_encode($data),
    'queued_at' => current_time('mysql'),
    'status'    => 'pending',
    'attempts'  => 0,
  ]);

  if ($wpdb->last_error) {
    gg_webhook_log('error', 'Failed to insert webhook into queue', ['error' => $wpdb->last_error]);
    return;
  }

  wp_schedule_single_event(time(), 'gg_process_webhook_queue');
}

/* =========================
   WEBHOOK PROCESSOR
   ========================= */

add_action('gg_process_webhook_queue', 'gg_process_webhook_queue_handler');

function gg_process_webhook_queue_handler() {
  global $wpdb;
  $table = $wpdb->prefix . 'gg_webhook_queue';

  // Claim up to 10 pending items
  $ids = $wpdb->get_col($wpdb->prepare(
    "SELECT id FROM {$table}
     WHERE status = 'pending'
       AND attempts < %d
     ORDER BY queued_at ASC
     LIMIT 10",
    GG_WEBHOOK_MAX_ATTEMPTS
  ));

  if (empty($ids)) {
    return;
  }

  $id_list = implode(',', array_map('intval', $ids));

  // Atomically claim rows — prevents race conditions if two processes run simultaneously
  $wpdb->query(
    "UPDATE {$table}
     SET status = 'processing', last_attempt = NOW(), attempts = attempts + 1
     WHERE id IN ({$id_list}) AND status = 'pending'"
  );

  // Only process rows we actually claimed
  $rows = $wpdb->get_results(
    "SELECT * FROM {$table} WHERE id IN ({$id_list}) AND status = 'processing'"
  );

  $processed_count = 0;

  foreach ($rows as $row) {
    $data   = json_decode($row->data, true);
    $result = gg_process_single_webhook($data);

    $success   = isset($result['status']) && in_array($result['status'], ['success', 'skipped', 'unhandled', 'duplicate']);
    $attempts  = (int) $row->attempts;

    if ($success) {
      $wpdb->update($table,
        ['status' => 'done', 'result' => wp_json_encode($result)],
        ['id' => $row->id]
      );
    } elseif ($attempts >= GG_WEBHOOK_MAX_ATTEMPTS) {
      $wpdb->update($table,
        ['status' => 'failed', 'result' => wp_json_encode($result)],
        ['id' => $row->id]
      );
      gg_webhook_log('error', "Webhook ID {$row->id} permanently failed after {$attempts} attempts", [
        'result' => $result,
      ]);
    } else {
      // Reset to pending for retry
      $wpdb->update($table,
        ['status' => 'pending', 'result' => wp_json_encode($result)],
        ['id' => $row->id]
      );
      gg_webhook_log('warning', "Webhook ID {$row->id} failed, will retry (attempt {$attempts})", [
        'result' => $result,
      ]);
    }

    $processed_count++;
  }

  // Prune done/failed rows older than 7 days
  $wpdb->query(
    "DELETE FROM {$table}
     WHERE status IN ('done', 'failed')
       AND queued_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
  );

  gg_webhook_log('info', "Processed {$processed_count} webhooks from queue");
}

/* =========================
   PROCESS SINGLE WEBHOOK
   — LOGIC UNCHANGED —
   ========================= */

function gg_process_single_webhook($data) {
  $event_type = isset($data['NotificationEventName']) ? $data['NotificationEventName'] : '';

  gg_webhook_log('info', "Processing webhook: {$event_type}", ['notification' => $data]);

  $item_id = '';

  if (isset($data['Item']['ItemID'])) {
    $item_id = (string) $data['Item']['ItemID'];
  }

  if (!$item_id && isset($data['Transaction']['Item']['ItemID'])) {
    $item_id = (string) $data['Transaction']['Item']['ItemID'];
    gg_webhook_log('info', "Found ItemID in Transaction structure: {$item_id}");
  }

  if (!$item_id) {
    gg_webhook_log('error', 'Could not extract item ID from notification', ['data' => $data]);
    return ['status' => 'error', 'message' => 'No item ID'];
  }

  $product_id = gg_find_product_by_ebay_id($item_id);

  if (!$product_id) {
    gg_webhook_log('warning', "No WooCommerce product found for eBay item {$item_id}");
    return ['status' => 'skipped', 'message' => 'Product not found'];
  }

  switch ($event_type) {
    case 'ItemRevised':
      return gg_handle_item_revised($product_id, $item_id, $data);

    case 'FixedPriceTransaction':
      // Only handle fixed price (Buy It Now) sales — auctions not supported
      return gg_handle_item_sold($product_id, $item_id, $data);

    case 'ItemClosed':
      return gg_handle_item_ended($product_id, $item_id, $data);

    case 'ItemOutOfStock':
      return gg_handle_out_of_stock($product_id, $item_id, $data);

    case 'AuctionCheckoutComplete':
    case 'ItemSold':
      // Auction events — ignored, all GrimeGames listings are fixed price
      gg_webhook_log('info', "Ignored auction event: {$event_type}");
      return ['status' => 'ignored', 'event' => $event_type];

    default:
      gg_webhook_log('info', "Unhandled event type: {$event_type}", ['data_keys' => array_keys($data)]);
      return ['status' => 'unhandled', 'event' => $event_type];
  }
}

/* =========================
   FIND PRODUCT BY EBAY ID
   — LOGIC UNCHANGED —
   ========================= */

function gg_find_product_by_ebay_id($ebay_item_id) {
  $args = [
    'post_type'      => 'product',
    'post_status'    => 'any',
    'meta_key'       => '_gg_ebay_item_id',
    'meta_value'     => $ebay_item_id,
    'fields'         => 'ids',
    'posts_per_page' => 1,
    'no_found_rows'  => true,
  ];

  $query = new WP_Query($args);
  return $query->have_posts() ? $query->posts[0] : null;
}

/* =========================
   EVENT HANDLERS
   — LOGIC UNCHANGED —
   ========================= */

function gg_handle_item_revised($product_id, $ebay_item_id, $notification) {
  if (!function_exists('gg_get_item')) {
    gg_webhook_log('error', 'gg_get_item function not found - eBay Suite v3.8 required');
    return ['status' => 'error', 'message' => 'Missing eBay Suite'];
  }

  $item_data = gg_get_item($ebay_item_id);

  if (empty($item_data)) {
    gg_webhook_log('error', "Could not fetch eBay item {$ebay_item_id}");
    return ['status' => 'error', 'message' => 'eBay API error'];
  }

  // Update price (WooCommerce always 5% below eBay price)
  if (isset($item_data['price']) && $item_data['price'] > 0) {
    $ebay_price  = (float) $item_data['price'];
    $woo_regular = $ebay_price;
    $woo_sale    = max(0.01, round($ebay_price * 0.95, 2));

    update_post_meta($product_id, '_regular_price', $woo_regular);
    update_post_meta($product_id, '_sale_price', $woo_sale);
    update_post_meta($product_id, '_price', $woo_sale);

    gg_webhook_log('success', "Updated price for product {$product_id}: £{$woo_regular}");
  }

  // Update stock
  if (isset($item_data['qty_avail'])) {
    $qty = max(0, (int) $item_data['qty_avail']);
    update_post_meta($product_id, '_manage_stock', 'yes');

    if (function_exists('wc_update_product_stock')) {
      wc_update_product_stock($product_id, $qty, 'set');
    } else {
      update_post_meta($product_id, '_stock', $qty);
    }

    update_post_meta($product_id, '_stock_status', $qty > 0 ? 'instock' : 'outofstock');
    // Set lock so gg-ebay-live-sync skips this product for 60s (prevents race condition)
    set_transient('gg_stock_lock_' . $product_id, time(), 60);
    gg_webhook_log('success', "Updated stock for product {$product_id}: {$qty} (lock set for 60s)");
  }

  // Update title
  if (isset($item_data['title']) && $item_data['title']) {
    $current_post = get_post($product_id);
    if ($current_post && $current_post->post_title !== $item_data['title']) {
      wp_update_post(['ID' => $product_id, 'post_title' => $item_data['title']]);
      gg_webhook_log('success', "Updated title for product {$product_id}");
    }
  }

  if (function_exists('wc_delete_product_transients')) {
    wc_delete_product_transients($product_id);
  }

  return ['status' => 'success', 'product_id' => $product_id, 'action' => 'revised'];
}

function gg_handle_item_sold($product_id, $ebay_item_id, $notification) {
  // DEDUPLICATION: Check transaction ID to prevent duplicate processing
  $transaction_id = null;
  if (isset($notification['Transaction']['TransactionID'])) {
    $transaction_id = (string) $notification['Transaction']['TransactionID'];
  } elseif (isset($notification['Item']['TransactionID'])) {
    $transaction_id = (string) $notification['Item']['TransactionID'];
  }

  if ($transaction_id) {
    $processed_key     = 'gg_webhook_txn_' . md5($transaction_id);
    $already_processed = get_transient($processed_key);

    if ($already_processed) {
      gg_webhook_log('info', "Skipping duplicate transaction {$transaction_id}", [
        'product_id'        => $product_id,
        'ebay_item_id'      => $ebay_item_id,
        'notification_type' => $notification['NotificationEventName'] ?? 'unknown',
      ]);
      return ['status' => 'duplicate', 'transaction_id' => $transaction_id];
    }

    set_transient($processed_key, true, 24 * HOUR_IN_SECONDS);
  }

  // Quantity extraction — priority ordered, first match wins
  $qty_sold = 1;

  if (isset($notification['TransactionArray']['Transaction']['QuantityPurchased']) && (int) $notification['TransactionArray']['Transaction']['QuantityPurchased'] > 0) {
    // Primary path — FixedPriceTransaction payload wraps transaction in TransactionArray
    $qty_sold = (int) $notification['TransactionArray']['Transaction']['QuantityPurchased'];
  } elseif (isset($notification['Transaction']['QuantityPurchased']) && (int) $notification['Transaction']['QuantityPurchased'] > 0) {
    $qty_sold = (int) $notification['Transaction']['QuantityPurchased'];
  } elseif (isset($notification['QuantityPurchased']) && (int) $notification['QuantityPurchased'] > 0) {
    // Root-level QuantityPurchased
    $qty_sold = (int) $notification['QuantityPurchased'];
  } elseif (isset($notification['Item']['Transaction']['QuantityPurchased']) && (int) $notification['Item']['Transaction']['QuantityPurchased'] > 0) {
    $qty_sold = (int) $notification['Item']['Transaction']['QuantityPurchased'];
  }
  // Note: Item/SellingStatus/QuantitySold intentionally NOT used — it's a cumulative lifetime count, not this transaction's qty

  gg_webhook_log('info', "Item sold - reducing stock by {$qty_sold}", [
    'product_id'        => $product_id,
    'ebay_item_id'      => $ebay_item_id,
    'transaction_id'    => $transaction_id,
    'notification_type' => $notification['NotificationEventName'] ?? 'unknown',
  ]);

  if (function_exists('wc_update_product_stock')) {
    $new_stock = wc_update_product_stock($product_id, $qty_sold, 'decrease');

    // Set a transient lock so gg-ebay-live-sync skips this product for 60 seconds
    // This prevents the race condition where live-sync overwrites the stock we just set
    set_transient('gg_stock_lock_' . $product_id, time(), 60);

    gg_webhook_log('success', "Reduced stock for product {$product_id} by {$qty_sold} to {$new_stock} (lock set for 60s)");

    // Log to sales ticker
    if (function_exists('gg_log_sale')) {
      $sale_title = '';
      if (!empty($notification['Item']['Title'])) {
        $sale_title = (string) $notification['Item']['Title'];
      } elseif (!empty($notification['Transaction']['Item']['Title'])) {
        $sale_title = (string) $notification['Transaction']['Item']['Title'];
      } else {
        $sale_title = get_the_title($product_id);
      }

      $sale_price = 0.0;
      if (!empty($notification['Transaction']['TransactionPrice']['#text'])) {
        $sale_price = (float) $notification['Transaction']['TransactionPrice']['#text'];
      } elseif (!empty($notification['Transaction']['TransactionPrice'])) {
        $sale_price = (float) $notification['Transaction']['TransactionPrice'];
      } elseif (!empty($notification['Item']['SellingStatus']['CurrentPrice']['#text'])) {
        $sale_price = (float) $notification['Item']['SellingStatus']['CurrentPrice']['#text'];
      }

      if ($sale_title && $sale_price > 0) {
        for ($i = 0; $i < $qty_sold; $i++) {
          gg_log_sale($sale_title, $sale_price, 'ebay');
        }
        gg_webhook_log('success', "Logged eBay sale to ticker: {$sale_title} @ £{$sale_price}");
      }
    }

    return ['status' => 'success', 'product_id' => $product_id, 'action' => 'sold', 'qty_sold' => $qty_sold, 'new_stock' => $new_stock, 'transaction_id' => $transaction_id];
  }

  return ['status' => 'error', 'message' => 'WooCommerce not available'];
}

function gg_handle_item_ended($product_id, $ebay_item_id, $notification) {
  wp_trash_post($product_id);
  gg_webhook_log('success', "Trashed product {$product_id} (eBay listing ended: {$ebay_item_id})");
  return ['status' => 'success', 'product_id' => $product_id, 'action' => 'ended'];
}

function gg_handle_out_of_stock($product_id, $ebay_item_id, $notification) {
  update_post_meta($product_id, '_stock', 0);
  update_post_meta($product_id, '_stock_status', 'outofstock');

  if (function_exists('wc_update_product_stock')) {
    wc_update_product_stock($product_id, 0, 'set');
  }

  gg_webhook_log('success', "Set product {$product_id} to out of stock");
  return ['status' => 'success', 'product_id' => $product_id, 'action' => 'out_of_stock'];
}

/* =========================
   LOGGING SYSTEM (DB-backed)
   ========================= */

function gg_webhook_log($level, $message, $context = []) {
  global $wpdb;
  $table = $wpdb->prefix . 'gg_webhook_log';

  $wpdb->insert($table, [
    'level'      => $level,
    'message'    => substr($message, 0, 500),
    'context'    => !empty($context) ? wp_json_encode($context) : null,
    'created_at' => current_time('mysql'),
  ]);

  // Prune log rows older than 30 days — runs on ~1-in-50 calls to avoid overhead
  if (mt_rand(1, 50) === 1) {
    $wpdb->query("DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
  }
}

function gg_webhook_get_log($request) {
  global $wpdb;
  $table = $wpdb->prefix . 'gg_webhook_log';

  $rows = $wpdb->get_results(
    "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 200"
  );

  return array_map(function($row) {
    return [
      'level'     => $row->level,
      'message'   => $row->message,
      'context'   => $row->context ? json_decode($row->context, true) : [],
      'timestamp' => $row->created_at,
    ];
  }, $rows);
}

/* =========================
   WOOCOMMERCE → EBAY SYNC
   — LOGIC UNCHANGED —
   ========================= */

add_action('woocommerce_order_status_processing', 'gg_sync_woo_order_to_ebay', 10, 1);
add_action('woocommerce_order_status_completed', 'gg_sync_woo_order_to_ebay', 10, 1);

function gg_sync_woo_order_to_ebay($order_id) {
  if (get_post_meta($order_id, '_gg_ebay_synced', true)) {
    return;
  }

  $order = wc_get_order($order_id);
  if (!$order) {
    return;
  }

  $synced_items = [];
  $errors       = [];

  foreach ($order->get_items() as $item) {
    $product_id         = $item->get_product_id();
    $quantity_purchased = $item->get_quantity();
    $ebay_item_id       = get_post_meta($product_id, '_gg_ebay_item_id', true);

    if (!$ebay_item_id) {
      continue;
    }

    $result = gg_reduce_ebay_stock($ebay_item_id, $quantity_purchased);

    if ($result === true) {
      $synced_items[] = $ebay_item_id;
      $order->add_order_note(sprintf('✅ Reduced eBay item %s quantity by %d', $ebay_item_id, $quantity_purchased));
      gg_webhook_log('success', "WooCommerce order #{$order_id}: Reduced eBay item {$ebay_item_id} by {$quantity_purchased}");
    } else {
      $errors[] = $ebay_item_id;
      $order->add_order_note(sprintf('❌ Failed to reduce eBay item %s quantity', $ebay_item_id));
      gg_webhook_log('error', "WooCommerce order #{$order_id}: Failed to reduce eBay item {$ebay_item_id}", [
        'error' => is_wp_error($result) ? $result->get_error_message() : 'Unknown error',
      ]);
    }
  }

  update_post_meta($order_id, '_gg_ebay_synced', time());

  if (!empty($synced_items)) {
    $order->add_order_note(sprintf('eBay sync complete: %d item(s) updated', count($synced_items)));
  }
}

function gg_reduce_ebay_stock($ebay_item_id, $qty_to_reduce) {
  if (!function_exists('gg_get_item')) {
    return new WP_Error('missing_function', 'gg_get_item function not found - eBay Suite required');
  }

  $item_data = gg_get_item($ebay_item_id);

  if (empty($item_data)) {
    return new WP_Error('ebay_fetch_failed', 'Could not fetch eBay item data');
  }

  $current_qty = isset($item_data['qty_avail']) ? (int) $item_data['qty_avail'] : 0;
  $new_qty     = max(0, $current_qty - $qty_to_reduce);

  gg_webhook_log('info', "Reducing eBay item {$ebay_item_id}: {$current_qty} → {$new_qty}");

  $xml = '<?xml version="1.0" encoding="utf-8"?>
    <ReviseInventoryStatusRequest xmlns="urn:ebay:apis:eBLBaseComponents">
      <InventoryStatus>
        <ItemID>' . gg_xml_escape($ebay_item_id) . '</ItemID>
        <Quantity>' . $new_qty . '</Quantity>
      </InventoryStatus>
    </ReviseInventoryStatusRequest>';

  if (!function_exists('gg_trading_call')) {
    return new WP_Error('missing_function', 'gg_trading_call function not found - eBay Suite required');
  }

  $response = gg_trading_call('ReviseInventoryStatus', $xml);

  if (is_wp_error($response)) {
    return $response;
  }

  $body = wp_remote_retrieve_body($response);

  if (strpos($body, '<Ack>Success</Ack>') !== false || strpos($body, '<Ack>Warning</Ack>') !== false) {
    gg_webhook_log('success', "Successfully reduced eBay item {$ebay_item_id} to {$new_qty}");
    return true;
  }

  preg_match('/<ShortMessage>(.*?)<\/ShortMessage>/is', $body, $match);
  $error_msg = $match[1] ?? 'Unknown eBay API error';

  return new WP_Error('ebay_api_error', $error_msg);
}

function gg_xml_escape($string) {
  if (function_exists('gg_xml')) {
    return gg_xml($string);
  }
  return htmlspecialchars((string) $string, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

/* =========================
   EBAY API SUBSCRIPTION
   — UNCHANGED —
   ========================= */

function gg_subscribe_to_ebay_notifications() {
  $access_token = get_option('ebay_access_token');

  if (!$access_token) {
    return new WP_Error('no_token', 'eBay OAuth token not found. Make sure eBay Suite is configured.');
  }

  $webhook_url = rest_url('gg/v1/ebay-webhook');
  $api_url     = 'https://api.ebay.com/ws/api.dll';

  $xml_request = '<?xml version="1.0" encoding="utf-8"?>
<SetNotificationPreferencesRequest xmlns="urn:ebay:apis:eBLBaseComponents">
  <RequesterCredentials>
    <eBayAuthToken>' . esc_xml($access_token) . '</eBayAuthToken>
  </RequesterCredentials>
  <ApplicationDeliveryPreferences>
    <AlertEmail>mailto://matt@grimegames.com</AlertEmail>
    <AlertEnable>Enable</AlertEnable>
    <ApplicationEnable>Enable</ApplicationEnable>
    <ApplicationURL>' . esc_xml($webhook_url) . '</ApplicationURL>
    <DeviceType>Platform</DeviceType>
  </ApplicationDeliveryPreferences>
  <UserDeliveryPreferenceArray>
    <NotificationEnable>
      <EventType>ItemRevised</EventType>
      <EventEnable>Enable</EventEnable>
    </NotificationEnable>
    <NotificationEnable>
      <EventType>ItemSold</EventType>
      <EventEnable>Enable</EventEnable>
    </NotificationEnable>
    <NotificationEnable>
      <EventType>FixedPriceTransaction</EventType>
      <EventEnable>Enable</EventEnable>
    </NotificationEnable>
    <NotificationEnable>
      <EventType>ItemOutOfStock</EventType>
      <EventEnable>Enable</EventEnable>
    </NotificationEnable>
    <NotificationEnable>
      <EventType>ItemClosed</EventType>
      <EventEnable>Enable</EventEnable>
    </NotificationEnable>
  </UserDeliveryPreferenceArray>
</SetNotificationPreferencesRequest>';

  $response = wp_remote_post($api_url, [
    'headers' => [
      'X-EBAY-API-COMPATIBILITY-LEVEL' => '967',
      'X-EBAY-API-CALL-NAME'           => 'SetNotificationPreferences',
      'X-EBAY-API-SITEID'              => '3',
      'Content-Type'                   => 'text/xml',
    ],
    'body'    => $xml_request,
    'timeout' => 30,
  ]);

  if (is_wp_error($response)) {
    gg_webhook_log('error', 'Failed to subscribe to eBay notifications', ['error' => $response->get_error_message()]);
    return $response;
  }

  $body = wp_remote_retrieve_body($response);
  $xml  = simplexml_load_string($body);

  if ($xml === false) {
    gg_webhook_log('error', 'Invalid XML response from eBay', ['response' => $body]);
    return new WP_Error('invalid_xml', 'Invalid XML response from eBay');
  }

  $ack = (string) $xml->Ack;

  if ($ack === 'Success' || $ack === 'Warning') {
    gg_webhook_log('success', 'Successfully subscribed to eBay notifications!', [
      'events'      => ['ItemRevised', 'ItemSold', 'FixedPriceTransaction', 'ItemOutOfStock', 'ItemClosed'],
      'webhook_url' => $webhook_url,
    ]);
    return true;
  }

  $error_msg = (string) ($xml->Errors->LongMessage ?? 'Unknown error');
  gg_webhook_log('error', 'eBay API error: ' . $error_msg, ['response' => $body]);
  return new WP_Error('ebay_api_error', $error_msg);
}

add_action('admin_post_gg_subscribe_ebay_notifications', function() {
  if (!current_user_can('manage_options')) wp_die('Access denied');
  check_admin_referer('gg_subscribe_ebay_notifications');

  $result = gg_subscribe_to_ebay_notifications();

  if (is_wp_error($result)) {
    wp_safe_redirect(admin_url('admin.php?page=gg-ebay-webhooks&subscribe_error=' . urlencode($result->get_error_message())));
  } else {
    wp_safe_redirect(admin_url('admin.php?page=gg-ebay-webhooks&subscribed=1'));
  }
  exit;
});

/* =========================
   ADMIN PAGE
   ========================= */

add_action('admin_menu', function() {
  add_submenu_page(
    'gg-ebay-suite',
    'eBay Webhooks',
    '⚡ Webhooks',
    'manage_options',
    'gg-ebay-webhooks',
    'gg_webhooks_admin_page'
  );
});

function gg_webhooks_admin_page() {
  global $wpdb;
  if (!current_user_can('manage_options')) wp_die('Access denied');

  $queue_table = $wpdb->prefix . 'gg_webhook_queue';
  $log_table   = $wpdb->prefix . 'gg_webhook_log';

  $pending_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table} WHERE status = 'pending'");
  $failed_count  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table} WHERE status = 'failed'");
  $done_count    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table} WHERE status = 'done'");

  echo '<div class="wrap">';
  echo '<h1>⚡ eBay Webhooks - Real-Time Sync</h1>';

  if (isset($_GET['subscribed'])) {
    echo '<div class="notice notice-success is-dismissible"><p><strong>✅ Successfully subscribed to eBay notifications!</strong></p></div>';
  }
  if (isset($_GET['subscribe_error'])) {
    echo '<div class="notice notice-error is-dismissible"><p><strong>❌ Subscription failed:</strong> ' . esc_html(urldecode($_GET['subscribe_error'])) . '</p></div>';
  }
  if (isset($_GET['cleared'])) {
    echo '<div class="notice notice-success is-dismissible"><p>Webhook logs and queue cleared.</p></div>';
  }

  // Webhook URL + subscribe button
  $webhook_url = rest_url('gg/v1/ebay-webhook');
  echo '<div style="background:#f0f0f1;padding:15px;border-radius:4px;margin:15px 0;">';
  echo '<h2 style="margin-top:0;">📡 Your Webhook Endpoint</h2>';
  echo '<p><strong>URL:</strong> <code style="background:#fff;padding:5px 10px;border-radius:3px;">' . esc_html($webhook_url) . '</code></p>';
  echo '<form method="post" action="' . admin_url('admin-post.php') . '" style="margin-top:15px;">';
  wp_nonce_field('gg_subscribe_ebay_notifications');
  echo '<input type="hidden" name="action" value="gg_subscribe_ebay_notifications">';
  echo '<button type="submit" class="button button-primary" style="font-size:14px;padding:8px 20px;">🔔 Re-Subscribe to eBay Notifications</button>';
  echo '<p style="margin-top:10px;color:#666;"><small>Subscribes to: ItemSold, FixedPriceTransaction, ItemRevised, ItemOutOfStock, ItemClosed</small></p>';
  echo '</form>';
  echo '</div>';

  // Queue stats
  echo '<div style="background:#e7f5ff;border-left:4px solid #2271b1;padding:15px;margin:15px 0;border-radius:4px;">';
  echo '<h3 style="margin-top:0;">📊 Queue Status</h3>';
  echo '<table style="width:100%;max-width:500px;"><tbody>';
  echo '<tr><td><strong>Pending:</strong></td><td>' . $pending_count . '</td></tr>';
  echo '<tr><td><strong>Completed (last 7 days):</strong></td><td>' . $done_count . '</td></tr>';
  echo '<tr><td><strong>Permanently failed:</strong></td><td><span style="color:' . ($failed_count > 0 ? '#d63638' : '#00a32a') . '">' . $failed_count . '</span></td></tr>';
  echo '</tbody></table>';

  if ($failed_count > 0) {
    $failed_rows = $wpdb->get_results("SELECT * FROM {$queue_table} WHERE status = 'failed' ORDER BY queued_at DESC LIMIT 10");
    echo '<p style="color:#d63638;margin-top:10px;"><strong>⚠️ Failed webhooks (last 10):</strong></p>';
    echo '<table class="widefat striped" style="font-size:11px;"><thead><tr><th>ID</th><th>Queued</th><th>Attempts</th><th>Result</th></tr></thead><tbody>';
    foreach ($failed_rows as $row) {
      echo '<tr>';
      echo '<td>' . esc_html($row->id) . '</td>';
      echo '<td>' . esc_html($row->queued_at) . '</td>';
      echo '<td>' . esc_html($row->attempts) . '</td>';
      echo '<td><details><summary>View</summary><pre style="font-size:10px;max-height:150px;overflow:auto;">' . esc_html($row->result) . '</pre></details></td>';
      echo '</tr>';
    }
    echo '</tbody></table>';
  }

  // Sync status
  echo '<h3 style="margin-top:15px;">🔄 Bi-Directional Sync Status</h3>';
  echo '<table style="width:100%;max-width:600px;"><tbody>';
  echo '<tr><td style="padding:8px 0;"><strong>eBay → WooCommerce:</strong></td><td>✅ <span style="color:#00a32a;">Active (Webhooks)</span></td></tr>';
  echo '<tr><td style="padding:8px 0;"><strong>WooCommerce → eBay:</strong></td><td>✅ <span style="color:#00a32a;">Active (Auto-reduce stock)</span></td></tr>';
  echo '</tbody></table>';
  echo '</div>';

  // Activity log
  $log = $wpdb->get_results("SELECT * FROM {$log_table} ORDER BY created_at DESC LIMIT 200");

  echo '<h2>📋 Recent Activity (Last ' . count($log) . ' events)</h2>';

  if (empty($log)) {
    echo '<p><em>No webhook activity yet. Waiting for eBay notifications...</em></p>';
  } else {
    echo '<table class="widefat striped" style="font-size:12px;"><thead><tr>';
    echo '<th style="width:150px;">Timestamp</th>';
    echo '<th style="width:80px;">Level</th>';
    echo '<th>Message</th>';
    echo '<th style="width:100px;">Details</th>';
    echo '</tr></thead><tbody>';

    foreach ($log as $entry) {
      $level_color = [
        'success' => '#00a32a',
        'info'    => '#2271b1',
        'warning' => '#dba617',
        'error'   => '#d63638',
      ][$entry->level] ?? '#000';

      echo '<tr>';
      echo '<td>' . esc_html($entry->created_at) . '</td>';
      echo '<td><span style="color:' . $level_color . ';font-weight:600;">' . strtoupper(esc_html($entry->level)) . '</span></td>';
      echo '<td>' . esc_html($entry->message) . '</td>';
      echo '<td>';
      if (!empty($entry->context)) {
        echo '<details><summary style="cursor:pointer;">View</summary>';
        echo '<pre style="font-size:10px;max-height:200px;overflow:auto;">' . esc_html(json_encode(json_decode($entry->context, true), JSON_PRETTY_PRINT)) . '</pre>';
        echo '</details>';
      }
      echo '</td>';
      echo '</tr>';
    }

    echo '</tbody></table>';
  }

  // Clear button
  echo '<form method="post" action="' . admin_url('admin-post.php') . '" style="margin-top:20px;">';
  wp_nonce_field('gg_clear_webhook_log');
  echo '<input type="hidden" name="action" value="gg_clear_webhook_log">';
  echo '<button class="button" onclick="return confirm(\'Clear all webhook logs and queue?\')">🗑️ Clear Log &amp; Queue</button>';
  echo '</form>';

  echo '</div>';
}

add_action('admin_post_gg_clear_webhook_log', function() {
  global $wpdb;
  if (!current_user_can('manage_options')) wp_die('Access denied');
  check_admin_referer('gg_clear_webhook_log');

  $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}gg_webhook_log");
  $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}gg_webhook_queue");

  wp_safe_redirect(admin_url('admin.php?page=gg-ebay-webhooks&cleared=1'));
  exit;
});

add_action('admin_notices', function() {
  if (isset($_GET['page']) && $_GET['page'] === 'gg-ebay-webhooks' && isset($_GET['cleared'])) {
    echo '<div class="notice notice-success is-dismissible"><p>Webhook logs and queue cleared.</p></div>';
  }
});

/* =========================
   GITHUB DEPLOY ENDPOINT
   v1.1 - Writes to correct plugin subfolder
   ========================= */

add_action('rest_api_init', function() {
  register_rest_route('gg/v1', '/deploy', [
    'methods'             => 'POST',
    'callback'            => 'gg_github_deploy',
    'permission_callback' => '__return_true',
  ]);
});

function gg_github_deploy($request) {
  $secret    = get_option('gg_deploy_secret', '');
  if (empty($secret)) {
    error_log('GG Deploy: No deploy secret configured in wp_options (key: gg_deploy_secret)');
    return new WP_REST_Response(['error' => 'Deploy secret not configured'], 500);
  }
  $payload   = $request->get_body();
  $signature = $request->get_header('x_hub_signature_256');
  $expected  = 'sha256=' . hash_hmac('sha256', $payload, $secret);

  if (!hash_equals($expected, $signature ?? '')) {
    error_log('GG Deploy: Signature mismatch — unauthorised request blocked');
    return new WP_REST_Response(['error' => 'Forbidden'], 403);
  }

  $data    = json_decode($payload, true);
  $commits = $data['commits'] ?? [];
  $updated = [];

  foreach ($commits as $commit) {
    $files = array_merge(
      $commit['added']    ?? [],
      $commit['modified'] ?? []
    );

    foreach ($files as $file) {
      // Only deploy files from the plugins/ folder
      if (strpos($file, 'plugins/') !== 0) {
        continue;
      }

      $filename    = basename($file);
      $plugin_name = str_replace('.php', '', $filename);
      $plugin_dir  = WP_CONTENT_DIR . '/plugins/' . $plugin_name . '/';

      // Create subfolder if it doesn't exist
      if (!is_dir($plugin_dir)) {
        wp_mkdir_p($plugin_dir);
      }

      // Use GitHub API with optional PAT for private repo support
      $url     = 'https://raw.githubusercontent.com/GrimeGames/grimegames-stack/main/' . $file;
      $gh_args = ['timeout' => 30, 'sslverify' => true];
      $gh_pat  = get_option('gg_github_pat', '');
      if ($gh_pat) {
        $gh_args['headers'] = ['Authorization' => 'token ' . $gh_pat];
      }
      $gh_resp = wp_remote_get($url, $gh_args);
      $content = (!is_wp_error($gh_resp) && wp_remote_retrieve_response_code($gh_resp) === 200)
        ? wp_remote_retrieve_body($gh_resp)
        : false;

      if ($content !== false) {
        file_put_contents($plugin_dir . $filename, $content);
        $updated[] = $filename;
        error_log("GG Deploy: Updated {$plugin_name}/{$filename}");
      } else {
        $err_msg = is_wp_error($gh_resp) ? $gh_resp->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($gh_resp);
        error_log("GG Deploy: Failed to fetch {$url} — {$err_msg}");
      }
    }
  }

  return new WP_REST_Response([
    'status' => 'ok',
    'files'  => $updated,
  ], 200);
}
