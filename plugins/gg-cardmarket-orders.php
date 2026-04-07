<?php
/**
 * Plugin Name: GrimeGames Cardmarket Orders
 * Description: Polls 1&1 mailbox for Cardmarket sale emails, queues orders in
 *              wp-admin, lets you select a shipping method and generate a Royal
 *              Mail Click & Drop label. Also reduces matching eBay stock on dispatch.
 * Author: GrimeGames
 * Version: 1.0
 */

defined('ABSPATH') || exit;

// ============================================================
// CONFIGURATION
// ============================================================

function gg_cm_imap_host()     { return get_option('gg_cm_imap_host',     'imap.ionos.co.uk'); }
function gg_cm_imap_port()     { return get_option('gg_cm_imap_port',     993); }
function gg_cm_imap_user()     { return get_option('gg_cm_imap_user',     ''); }
function gg_cm_imap_pass()     { return get_option('gg_cm_imap_pass',     ''); }
function gg_cm_imap_folder()   { return get_option('gg_cm_imap_folder',   'INBOX'); }

// DB table name for queued CM orders
function gg_cm_table() {
    global $wpdb;
    return $wpdb->prefix . 'gg_cm_orders';
}

// ============================================================
// 1. DATABASE — create table on activation
// ============================================================

register_activation_hook(__FILE__, 'gg_cm_create_table');
function gg_cm_create_table() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $table   = gg_cm_table();
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        cm_order_id      VARCHAR(50)  NOT NULL,
        buyer_name       VARCHAR(200) NOT NULL,
        address_line1    VARCHAR(200) NOT NULL,
        address_line2    VARCHAR(200) NOT NULL DEFAULT '',
        city             VARCHAR(100) NOT NULL,
        postcode         VARCHAR(20)  NOT NULL,
        country_code     VARCHAR(5)   NOT NULL DEFAULT 'GB',
        buyer_email      VARCHAR(200) NOT NULL DEFAULT '',
        items_json       LONGTEXT     NOT NULL,
        total_gbp        DECIMAL(10,2) NOT NULL DEFAULT 0,
        shipping_gbp     DECIMAL(10,2) NOT NULL DEFAULT 0,
        status           VARCHAR(30)  NOT NULL DEFAULT 'pending',
        shipping_method  VARCHAR(50)  NOT NULL DEFAULT '',
        rm_order_id      VARCHAR(200) NOT NULL DEFAULT '',
        tracking         VARCHAR(100) NOT NULL DEFAULT '',
        label_path       VARCHAR(500) NOT NULL DEFAULT '',
        raw_email        LONGTEXT     NOT NULL DEFAULT '',
        created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY cm_order_id (cm_order_id)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

// ============================================================
// 2. CRON — poll inbox every 5 minutes
// ============================================================

add_filter('cron_schedules', function($schedules) {
    $schedules['gg_cm_5min'] = ['interval' => 300, 'display' => 'Every 5 minutes'];
    return $schedules;
});

register_activation_hook(__FILE__, function() {
    if (!wp_next_scheduled('gg_cm_poll_inbox')) {
        wp_schedule_event(time(), 'gg_cm_5min', 'gg_cm_poll_inbox');
    }
});

register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('gg_cm_poll_inbox');
});

add_action('gg_cm_poll_inbox', 'gg_cm_do_poll');
function gg_cm_do_poll() {
    if (!function_exists('imap_open')) {
        gg_cm_log('ERROR: PHP IMAP extension not available.');
        return;
    }

    $host   = gg_cm_imap_host();
    $port   = gg_cm_imap_port();
    $user   = gg_cm_imap_user();
    $pass   = gg_cm_imap_pass();
    $folder = gg_cm_imap_folder();

    if (!$user || !$pass) {
        gg_cm_log('IMAP credentials not configured, skipping poll.');
        return;
    }

    $mailbox = "{" . $host . ":" . $port . "/imap/ssl/novalidate-cert}" . $folder;

    $conn = @imap_open($mailbox, $user, $pass, 0, 1);
    if (!$conn) {
        gg_cm_log('IMAP connect failed: ' . imap_last_error());
        return;
    }

    // Search for unread emails from Cardmarket
    $uids = imap_search($conn, 'UNSEEN FROM "noreply@cardmarket.com"', SE_UID);

    if (!$uids) {
        imap_close($conn);
        gg_cm_log('No new Cardmarket emails found.');
        return;
    }

    gg_cm_log('Found ' . count($uids) . ' new Cardmarket email(s).');

    foreach ($uids as $uid) {
        $header  = imap_fetchheader($conn, $uid, FT_UID);
        $body    = imap_fetchbody($conn, $uid, '1', FT_UID | FT_PEEK);

        // Decode quoted-printable or base64 body
        $struct = imap_fetchstructure($conn, $uid, FT_UID);
        if (!empty($struct->parts[0]->encoding)) {
            $enc = $struct->parts[0]->encoding;
        } elseif (!empty($struct->encoding)) {
            $enc = $struct->encoding;
        } else {
            $enc = 0;
        }
        if ($enc == 4) $body = quoted_printable_decode($body);
        if ($enc == 3) $body = base64_decode($body);

        // Only process payment confirmation emails
        if (strpos($body, 'has paid for shipment') === false) {
            imap_setflag_full($conn, $uid, '\\Seen', ST_UID);
            continue;
        }

        $parsed = gg_cm_parse_email($body);
        if (!$parsed) {
            gg_cm_log("UID $uid — failed to parse email body.");
            imap_setflag_full($conn, $uid, '\\Seen', ST_UID);
            continue;
        }

        $saved = gg_cm_save_order($parsed, $body);
        if ($saved) {
            gg_cm_log("UID $uid — order {$parsed['cm_order_id']} saved.");
            imap_setflag_full($conn, $uid, '\\Seen', ST_UID);
        } else {
            gg_cm_log("UID $uid — order {$parsed['cm_order_id']} already exists or save failed.");
            imap_setflag_full($conn, $uid, '\\Seen', ST_UID);
        }
    }

    imap_close($conn);
}

// ============================================================
// 3. EMAIL PARSER
// ============================================================

function gg_cm_parse_email($body) {
    // Normalise line endings
    $body = str_replace("\r\n", "\n", $body);

    // Order number
    if (!preg_match('/Shipment\s+(\d+)/', $body, $m)) return null;
    $cm_order_id = $m[1];

    // Buyer address block — between "Status: Paid" and "Tracking:"
    if (!preg_match('/Status:\s*Paid\s*\n\s*\n(.*?)\n\s*\nTracking:/s', $body, $m)) return null;
    $addr_lines = array_values(array_filter(array_map('trim', explode("\n", trim($m[1])))));

    if (count($addr_lines) < 3) return null;

    $buyer_name   = $addr_lines[0];
    $address_line1 = $addr_lines[1];
    $address_line2 = '';

    // Handle optional second address line
    // Line with postcode is: "SW1A 1AA London" or "TW8 8NH Brentford"
    $postcode_idx = null;
    for ($i = 2; $i < count($addr_lines); $i++) {
        if (preg_match('/^([A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2})\s+(.*)/i', $addr_lines[$i], $pm)) {
            $postcode_idx = $i;
            break;
        }
    }

    if ($postcode_idx === null) return null;

    if ($postcode_idx == 3) {
        $address_line2 = $addr_lines[2];
    }

    preg_match('/^([A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2})\s+(.*)/i', $addr_lines[$postcode_idx], $pm);
    $postcode = strtoupper(trim($pm[1]));
    $city     = trim($pm[2]);
    $country  = isset($addr_lines[$postcode_idx + 1]) ? trim($addr_lines[$postcode_idx + 1]) : 'United Kingdom';

    // Country code
    $country_map = [
        'united kingdom' => 'GB', 'germany' => 'DE', 'france' => 'FR',
        'netherlands' => 'NL', 'belgium' => 'BE', 'spain' => 'ES',
        'italy' => 'IT', 'austria' => 'AT', 'poland' => 'PL',
    ];
    $country_code = $country_map[strtolower($country)] ?? 'GB';

    // Totals
    $total_gbp    = 0;
    $shipping_gbp = 0;
    if (preg_match('/Total sale price:\s*([\d,]+(?:\.\d+)?)\s*GBP/i', $body, $tm)) {
        $total_gbp = (float) str_replace(',', '.', $tm[1]);
    }

    // Line items — between the +++ markers
    if (!preg_match('/\+{5,}\n(.*?)\n\+{5,}/s', $body, $cm)) return null;
    $contents_block = $cm[1];

    $items = [];
    foreach (explode("\n", $contents_block) as $line) {
        $line = trim($line);
        if (!$line) continue;

        // Shipping line
        if (preg_match('/^Shipping\s+([\d,]+)\s+GBP/i', $line, $sm)) {
            $shipping_gbp = (float) str_replace(',', '.', $sm[1]);
            continue;
        }

        // Item line: "Nx Card Name (rarity) (set) - ... price GBP"
        if (!preg_match('/^(\d+)x\s+(.+?)\s+([\d,]+)\s+GBP\s*$/i', $line, $lm)) continue;

        $qty      = (int) $lm[1];
        $raw_name = trim($lm[2]);
        $price    = (float) str_replace(',', '.', $lm[3]);

        // Extract card name (before first bracket or dash-rarity)
        $card_name = $raw_name;
        // Remove V.X rarity in brackets: "(V.4 - Platinum Secret Rare)"
        $card_name = preg_replace('/\s*\(V\.\d+[^)]*\)/', '', $card_name);
        // Remove set name in brackets: "(Quarter Century Stampede)"
        $card_name = preg_replace('/\s*\([^)]+\)$/', '', $card_name);
        // Remove trailing rarity abbreviation: "- SLR - English - NM"
        $card_name = preg_replace('/\s*-\s*[A-Z]{2,3}\s*-\s*English.*$/i', '', $card_name);
        $card_name = trim($card_name);

        // Resolve eBay Item ID at import time so label generation never needs name lookup
        $ebay_item_id = '';
        $woo_product_id = null;
        if ($card_name) {
            global $wpdb;
            $pattern = '%' . $wpdb->esc_like($card_name) . '%';
            $woo_product_id = $wpdb->get_var($wpdb->prepare("
                SELECT ID FROM {$wpdb->posts}
                WHERE post_type = 'product'
                AND post_status = 'publish'
                AND post_title LIKE %s
                LIMIT 1
            ", $pattern));
            if ($woo_product_id) {
                $ebay_item_id = get_post_meta($woo_product_id, '_gg_ebay_item_id', true) ?: '';
            }
        }

        $items[] = [
            'qty'           => $qty,
            'name'          => $card_name,
            'raw'           => $raw_name,
            'price_gbp'     => $price,
            'ebay_item_id'  => $ebay_item_id,
            'woo_product_id'=> $woo_product_id,
        ];
    }

    if (empty($items)) return null;

    return [
        'cm_order_id'   => $cm_order_id,
        'buyer_name'    => $buyer_name,
        'address_line1' => $address_line1,
        'address_line2' => $address_line2,
        'city'          => $city,
        'postcode'      => $postcode,
        'country_code'  => $country_code,
        'total_gbp'     => $total_gbp,
        'shipping_gbp'  => $shipping_gbp,
        'items'         => $items,
    ];
}

// ============================================================
// 4. SAVE ORDER TO DB
// ============================================================

function gg_cm_save_order($parsed, $raw_email) {
    global $wpdb;
    $table = gg_cm_table();

    // Don't duplicate
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table WHERE cm_order_id = %s", $parsed['cm_order_id']
    ));
    if ($exists) return false;

    return $wpdb->insert($table, [
        'cm_order_id'   => $parsed['cm_order_id'],
        'buyer_name'    => $parsed['buyer_name'],
        'address_line1' => $parsed['address_line1'],
        'address_line2' => $parsed['address_line2'],
        'city'          => $parsed['city'],
        'postcode'      => $parsed['postcode'],
        'country_code'  => $parsed['country_code'],
        'total_gbp'     => $parsed['total_gbp'],
        'shipping_gbp'  => $parsed['shipping_gbp'],
        'items_json'    => wp_json_encode($parsed['items']),
        'raw_email'     => $raw_email,
        'status'        => 'pending',
    ]);
}

// ============================================================
// 5. LABEL GENERATION — reuses Royal Mail plugin functions
// ============================================================

function gg_cm_generate_label($order_id, $shipping_method) {
    global $wpdb;
    $table = gg_cm_table();

    $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $order_id));
    if (!$order) return ['success' => false, 'error' => 'Order not found'];

    // Map our method slugs to RM service codes
    $service_codes = [
        '2nd_class'     => 'BPL2',
        'large_letter'  => 'BPL2',
        'tracked_48'    => 'TRS48',
        'tracked_24'    => 'TRN24',
        'special'       => 'SD1',
    ];

    $service_code = $service_codes[$shipping_method] ?? 'TRS48';

    // Map shipping method to package format identifier
    $package_formats = [
        '2nd_class'    => 'letter',
        'large_letter' => 'largeLetter',
        'tracked_48'   => 'largeLetter',
        'tracked_24'   => 'largeLetter',
        'special'      => 'largeLetter',
    ];

    $package_format = $package_formats[$shipping_method] ?? 'parcel';

    gg_cm_log("Generating label for CM order {$order->cm_order_id} via $service_code / format: $package_format");

    $items = json_decode($order->items_json, true);
    $contents = [];
    foreach ($items as $item) {
        $contents[] = [
            'name'              => $item['name'],
            'unitValue'         => $item['price_gbp'],
            'unitWeightInGrams' => 5,
            'quantity'          => $item['qty'],
            'SKU'               => '',
        ];
    }

    $payload = [
        'items' => [[
            'orderReference'      => 'CM-' . $order->cm_order_id,
            'recipient'           => [
                'address' => [
                    'fullName'     => $order->buyer_name,
                    'addressLine1' => $order->address_line1,
                    'addressLine2' => $order->address_line2,
                    'city'         => $order->city,
                    'postcode'     => $order->postcode,
                    'countryCode'  => $order->country_code,
                ],
                'emailAddress' => $order->buyer_email ?: '',
            ],
            'orderDate'           => gmdate('Y-m-d\TH:i:s\Z'),
            'subtotal'            => (float) $order->total_gbp - (float) $order->shipping_gbp,
            'shippingCostCharged' => (float) $order->shipping_gbp,
            'otherCosts'          => 0,
            'total'               => (float) $order->total_gbp,
            'currencyCode'        => 'GBP',
            'postageDetails'      => [
                'serviceCode'          => $service_code,
                'sendNotificationsTo'  => 'recipient',
            ],
            'label' => [
                'includeLabelInResponse' => true,
                'includeCN'              => false,
                'includeReturnsLabel'    => false,
            ],
            'packages' => [[
                'weightInGrams'           => 100,
                'packageFormatIdentifier' => $package_format,
                'contents'                => $contents,
            ]],
        ]]
    ];

    $response = wp_remote_post(GG_RM_API_BASE . '/orders', [
        'timeout' => 30,
        'headers' => [
            'Authorization' => gg_rm_api_key(),
            'Content-Type'  => 'application/json',
        ],
        'body' => json_encode($payload),
    ]);

    if (is_wp_error($response)) {
        $err = $response->get_error_message();
        gg_cm_log("CM order {$order->cm_order_id} — label FAILED (WP_Error): $err");
        return ['success' => false, 'error' => $err];
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $body      = json_decode(wp_remote_retrieve_body($response), true);

    gg_cm_log("CM order {$order->cm_order_id} — API HTTP $http_code | Body: " . wp_remote_retrieve_body($response));

    if ($http_code !== 200 && $http_code !== 201) {
        $err = $body['message'] ?? "HTTP $http_code";
        return ['success' => false, 'error' => $err];
    }

    $resp_item   = $body['createdOrders'][0] ?? $body['items'][0] ?? $body[0] ?? null;
    $rm_order_id = $resp_item['orderIdentifier'] ?? '';
    $tracking    = $resp_item['packages'][0]['trackingNumber']
                ?? $resp_item['trackingNumber']
                ?? '';

    // Save label PDF
    $label_path = '';
    $label_data = $resp_item['label'] ?? $resp_item['labelData'] ?? '';

    if (!empty($label_data)) {
        $pdf = base64_decode($label_data, true);
        if ($pdf === false) $pdf = $label_data;
    } elseif ($rm_order_id) {
        // Label not in push response — fetch it directly from Click & Drop
        gg_cm_log("CM order {$order->cm_order_id} — label not in push response, fetching from API.");
        $label_response = wp_remote_get(GG_RM_API_BASE . '/orders/' . urlencode($rm_order_id) . '/label', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => gg_rm_api_key(),
                'Accept'        => 'application/pdf',
            ],
        ]);
        if (!is_wp_error($label_response)) {
            $raw     = wp_remote_retrieve_body($label_response);
            $decoded = json_decode($raw, true);
            $pdf     = ($decoded && !empty($decoded['label'])) ? base64_decode($decoded['label']) : $raw;
        }
    }

    if (!empty($pdf) && strlen($pdf) > 100) {
        $upload_dir = wp_upload_dir();
        $label_dir  = $upload_dir['basedir'] . '/gg-rm-labels';
        if (!file_exists($label_dir)) {
            wp_mkdir_p($label_dir);
            file_put_contents($label_dir . '/.htaccess', 'deny from all');
        }
        $label_path = $label_dir . '/label-cm-' . $order->cm_order_id . '.pdf';
        $written = file_put_contents($label_path, $pdf);
        if ($written === false) {
            gg_cm_log("CM order {$order->cm_order_id} — FAILED to write label PDF to $label_path");
            $label_path = '';
        } else {
            gg_cm_log("CM order {$order->cm_order_id} — label PDF saved to $label_path ($written bytes)");
        }
    } else {
        gg_cm_log("CM order {$order->cm_order_id} — WARNING: no label PDF in response. Full body: " . json_encode($body));
    }

    // Update DB record
    $wpdb->update($table, [
        'status'          => 'label_generated',
        'shipping_method' => $shipping_method,
        'rm_order_id'     => $rm_order_id,
        'tracking'        => $tracking,
        'label_path'      => $label_path,
    ], ['id' => $order_id]);

    // Reduce eBay stock for each item
    foreach ($items as $item) {
        gg_cm_reduce_ebay_stock($item, $item['qty']);
    }

    return [
        'success'    => true,
        'tracking'   => $tracking,
        'label_path' => $label_path,
        'rm_order_id'=> $rm_order_id,
    ];
}

// ============================================================
// 6. EBAY STOCK REDUCTION
// ============================================================

function gg_cm_reduce_ebay_stock($item, $qty) {
    // $item is the full item array from items_json — use pre-resolved IDs if available
    $card_name      = is_array($item) ? ($item['name'] ?? '') : $item;
    $ebay_item_id   = is_array($item) ? ($item['ebay_item_id'] ?? '') : '';
    $woo_product_id = is_array($item) ? ($item['woo_product_id'] ?? null) : null;

    // If we don't have the eBay Item ID stored, fall back to name lookup
    if (!$ebay_item_id || !$woo_product_id) {
        global $wpdb;

        // Clean the card name
        $clean = $card_name;
        $clean = preg_replace('/\s*\(V\.\d+[^)]*\)?/', '', $clean);
        $clean = preg_replace('/\s*\([^)]+\)/', '', $clean);
        $clean = preg_replace('/\s*\([^)]*$/', '', $clean);
        $clean = preg_replace('/\s*-\s*[A-Z]{1,4}\s*-\s*English.*$/i', '', $clean);
        $clean = preg_replace('/\s*-\s*[A-Z]{2,4}\.{3}$/', '', $clean);
        $clean = rtrim(trim($clean), '.,- ');

        if (strlen($clean) < 3) {
            gg_cm_log("eBay stock reduction — name too short: '$clean'");
            return;
        }

        $pattern = '%' . $wpdb->esc_like($clean) . '%';
        $woo_product_id = $wpdb->get_var($wpdb->prepare("
            SELECT ID FROM {$wpdb->posts}
            WHERE post_type = 'product'
            AND post_status = 'publish'
            AND post_title LIKE %s
            LIMIT 1
        ", $pattern));

        if (!$woo_product_id) {
            gg_cm_log("eBay stock reduction — no product found for: $clean (original: $card_name)");
            return;
        }

        $ebay_item_id = get_post_meta($woo_product_id, '_gg_ebay_item_id', true) ?: '';

        if (!$ebay_item_id) {
            gg_cm_log("eBay stock reduction — no eBay Item ID on product for: $clean");
            return;
        }
    }

    $product = wc_get_product($woo_product_id);
    if (!$product) {
        gg_cm_log("eBay stock reduction — could not load WooCommerce product ID $woo_product_id");
        return;
    }

    $current_stock = (int) $product->get_stock_quantity();
    $new_stock     = max(0, $current_stock - $qty);

    if (!function_exists('gg_trading_call')) {
        gg_cm_log("eBay stock reduction — gg_trading_call() not available");
        return;
    }

    $xml = '<?xml version="1.0" encoding="utf-8"?>
        <ReviseInventoryStatusRequest xmlns="urn:ebay:apis:eBLBaseComponents">
            <InventoryStatus>
                <ItemID>' . esc_xml($ebay_item_id) . '</ItemID>
                <Quantity>' . $new_stock . '</Quantity>
            </InventoryStatus>
        </ReviseInventoryStatusRequest>';

    $response = gg_trading_call('ReviseInventoryStatus', $xml);

    if (is_wp_error($response)) {
        gg_cm_log("eBay stock reduction FAILED ❌ — $card_name — " . $response->get_error_message());
        return;
    }

    $body = wp_remote_retrieve_body($response);
    if (strpos($body, '<Ack>Success</Ack>') !== false || strpos($body, '<Ack>Warning</Ack>') !== false) {
        wc_update_product_stock($woo_product_id, $qty, 'decrease');
        gg_cm_log("eBay stock reduced ✅ — $card_name (eBay ID: $ebay_item_id) — {$current_stock} → {$new_stock}");
    } else {
        preg_match('/<ShortMessage>(.*?)<\/ShortMessage>/is', $body, $match);
        $error = $match[1] ?? 'Unknown eBay API error';
        gg_cm_log("eBay stock reduction FAILED ❌ — $card_name — $error");
    }
}

// ============================================================
// 7. ADMIN PAGE
// ============================================================

add_action('admin_menu', function() {
    add_submenu_page(
        'woocommerce',
        'Cardmarket Orders',
        '📦 CM Orders',
        'manage_woocommerce',
        'gg-cm-orders',
        'gg_cm_admin_page'
    );
});

function gg_cm_admin_page() {
    global $wpdb;
    $table = gg_cm_table();

    // Handle label generation
    if (isset($_POST['gg_cm_generate']) && check_admin_referer('gg_cm_generate')) {
        $order_id        = intval($_POST['order_id']);
        $shipping_method = sanitize_text_field($_POST['shipping_method']);
        $result          = gg_cm_generate_label($order_id, $shipping_method);
        if ($result['success']) {
            echo '<div class="notice notice-success"><p>✅ Label generated! Tracking: <strong>' . esc_html($result['tracking']) . '</strong></p></div>';
        } else {
            echo '<div class="notice notice-error"><p>❌ Label failed: ' . esc_html($result['error']) . '</p></div>';
        }
    }

    // Handle re-fire eBay stock reduction
    if (isset($_POST['gg_cm_refire_stock']) && check_admin_referer('gg_cm_refire_stock')) {
        $order_id = intval($_POST['order_id']);
        $order    = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $order_id));
        if ($order) {
            $items   = json_decode($order->items_json, true);
            $success = 0;
            $failed  = 0;
            foreach ($items as $item) {
                gg_cm_reduce_ebay_stock($item, $item['qty']);
            }
            gg_cm_log("Re-fire eBay stock for CM order {$order->cm_order_id} — processed " . count($items) . " line(s).");
            echo '<div class="notice notice-success"><p>✅ eBay stock re-fired for order ' . esc_html($order->cm_order_id) . ' — check the log in CM Settings for results.</p></div>';
        }
    }

    // Handle poll
    if (isset($_POST['gg_cm_poll_now']) && check_admin_referer('gg_cm_poll_now')) {
        gg_cm_do_poll();
        echo '<div class="notice notice-info"><p>📬 Inbox polled.</p></div>';
    }

    // Handle manual email paste
    if (isset($_POST['gg_cm_manual_email']) && check_admin_referer('gg_cm_manual_email')) {
        $raw    = stripslashes($_POST['email_body']);
        $parsed = gg_cm_parse_email($raw);
        if ($parsed) {
            $saved = gg_cm_save_order($parsed, $raw);
            echo $saved
                ? '<div class="notice notice-success"><p>✅ Order ' . esc_html($parsed['cm_order_id']) . ' imported.</p></div>'
                : '<div class="notice notice-warning"><p>⚠️ Order already exists or could not be saved.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>❌ Could not parse email. Check the format.</p></div>';
        }
    }

    $orders        = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC LIMIT 100");
    $pending_count = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'pending'");

    ?>
    <style>
    /* ── Mobile-first CM Orders page ── */
    #gg-cm-wrap { max-width:100%; padding:8px; box-sizing:border-box; }
    #gg-cm-wrap h1 { font-size:1.4em; margin-bottom:10px; }

    .gg-cm-stats { display:flex; gap:10px; margin-bottom:14px; flex-wrap:wrap; }
    .gg-cm-stat  { flex:1; min-width:120px; background:#1a1a1a; border-radius:10px;
                   padding:12px 16px; color:#fff; text-align:center; }
    .gg-cm-stat strong { display:block; font-size:1.8em; line-height:1.1; }
    .gg-cm-stat span   { font-size:0.75em; color:#aaa; }

    .gg-cm-actions { display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap; }
    .gg-cm-actions form { margin:0; }
    .gg-cm-btn { display:inline-block; padding:10px 18px; border-radius:8px; font-size:15px;
                 font-weight:bold; border:none; cursor:pointer; text-decoration:none; }
    .gg-cm-btn-secondary { background:#f3f4f6; color:#333; border:1px solid #ddd; }
    .gg-cm-btn-primary   { background:#7B00FF; color:#fff; }
    .gg-cm-btn-print     { background:#0ea5e9; color:#fff; }
    .gg-cm-btn-track     { background:#22c55e; color:#fff; }

    /* Order cards */
    .gg-cm-card {
        background:#fff; border:1px solid #e5e7eb; border-radius:12px;
        margin-bottom:14px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.06);
    }
    .gg-cm-card-header {
        display:flex; justify-content:space-between; align-items:flex-start;
        padding:14px 14px 10px; border-bottom:1px solid #f3f4f6;
    }
    .gg-cm-card-id   { font-weight:bold; font-size:1em; color:#1a1a1a; }
    .gg-cm-card-date { font-size:0.75em; color:#888; margin-top:2px; }
    .gg-cm-badge {
        padding:4px 10px; border-radius:20px; font-size:0.7em;
        font-weight:bold; text-transform:uppercase; white-space:nowrap;
    }
    .gg-cm-card-body  { padding:12px 14px; }
    .gg-cm-card-row   { display:flex; justify-content:space-between; margin-bottom:6px;
                        font-size:0.9em; }
    .gg-cm-card-label { color:#888; }
    .gg-cm-card-value { font-weight:600; text-align:right; max-width:65%; }

    /* Items list inside card */
    .gg-cm-items-toggle { width:100%; background:none; border:none; padding:10px 14px;
                          text-align:left; cursor:pointer; font-size:0.85em; color:#7B00FF;
                          font-weight:bold; border-top:1px solid #f3f4f6; }
    .gg-cm-items-list   { display:none; padding:0 14px 12px; }
    .gg-cm-items-list.open { display:block; }
    .gg-cm-item-row { display:flex; justify-content:space-between; padding:4px 0;
                      border-bottom:1px solid #f9f9f9; font-size:0.82em; }
    .gg-cm-item-row:last-child { border-bottom:none; }

    /* Label/ship action area */
    .gg-cm-card-action { padding:12px 14px; background:#f9fafb; border-top:1px solid #f3f4f6; }
    .gg-cm-ship-row    { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .gg-cm-select      { flex:1; min-width:160px; padding:10px 12px; border-radius:8px;
                         border:1px solid #ddd; font-size:15px; background:#fff; }
    .gg-cm-gen-btn     { padding:10px 16px; border-radius:8px; background:#7B00FF;
                         color:#fff; font-weight:bold; font-size:15px; border:none; cursor:pointer; }

    /* Paste email panel */
    .gg-cm-paste-panel { background:#f9f9f9; border:1px solid #e5e7eb; border-radius:10px;
                         padding:14px; margin-bottom:16px; }
    .gg-cm-paste-panel textarea { width:100%; box-sizing:border-box; font-size:12px;
                                   font-family:monospace; border-radius:6px; border:1px solid #ddd;
                                   padding:8px; }

    @media (min-width:640px) {
        #gg-cm-wrap { padding:16px; }
        .gg-cm-stat strong { font-size:2.2em; }
    }
    </style>

    <div id="gg-cm-wrap">
        <h1>📦 Cardmarket Orders</h1>

        <!-- Stats -->
        <div class="gg-cm-stats">
            <div class="gg-cm-stat" style="border:1px solid #7B00FF;">
                <strong style="color:#A855F7"><?php echo count($orders); ?></strong>
                <span>Total orders</span>
            </div>
            <div class="gg-cm-stat" style="border:1px solid #f59e0b;">
                <strong style="color:#f59e0b"><?php echo $pending_count; ?></strong>
                <span>Awaiting label</span>
            </div>
        </div>

        <!-- Action buttons -->
        <div class="gg-cm-actions">
            <form method="post">
                <?php wp_nonce_field('gg_cm_poll_now'); ?>
                <button type="submit" name="gg_cm_poll_now" class="gg-cm-btn gg-cm-btn-secondary">
                    📬 Poll Inbox
                </button>
            </form>
            <button class="gg-cm-btn gg-cm-btn-secondary"
                    onclick="document.getElementById('gg-cm-paste').classList.toggle('open');this.textContent=document.getElementById('gg-cm-paste').classList.contains('open')?'✕ Cancel':'📋 Paste Email'">
                📋 Paste Email
            </button>
        </div>

        <!-- Paste email panel (hidden by default) -->
        <div id="gg-cm-paste" class="gg-cm-paste-panel" style="display:none;">
            <form method="post">
                <?php wp_nonce_field('gg_cm_manual_email'); ?>
                <label style="font-weight:bold;font-size:0.9em;display:block;margin-bottom:6px;">
                    Paste full Cardmarket sale email:
                </label>
                <textarea name="email_body" rows="10"
                    placeholder="Paste the full Cardmarket sale email here..."></textarea>
                <br>
                <button type="submit" name="gg_cm_manual_email" class="gg-cm-btn gg-cm-btn-primary" style="margin-top:8px;">
                    Import Order
                </button>
            </form>
        </div>
        <script>
        (function(){
            var panel = document.getElementById('gg-cm-paste');
            if(panel) panel.style.display = panel.classList.contains('open') ? 'block' : 'none';
            document.querySelectorAll('[onclick*="gg-cm-paste"]').forEach(function(btn){
                btn.addEventListener('click',function(){
                    var p = document.getElementById('gg-cm-paste');
                    var open = p.style.display === 'block';
                    p.style.display = open ? 'none' : 'block';
                    btn.textContent = open ? '📋 Paste Email' : '✕ Cancel';
                });
            });
        })();
        </script>

        <!-- Order cards -->
        <?php if (empty($orders)): ?>
            <p style="color:#888;margin-top:20px;">No Cardmarket orders yet. Poll the inbox or paste an email manually.</p>
        <?php else: ?>
            <?php foreach ($orders as $order):
                $items      = json_decode($order->items_json, true);
                $item_count = array_sum(array_column($items, 'qty'));

                $status_styles = [
                    'pending'         => 'background:#fef3c7;color:#92400e;',
                    'label_generated' => 'background:#dcfce7;color:#166534;',
                    'dispatched'      => 'background:#e0e7ff;color:#3730a3;',
                ];
                $status_style = $status_styles[$order->status] ?? 'background:#f3f4f6;color:#555;';

                $label_url = '';
                if ($order->label_path) {
                    $label_url = admin_url('admin-ajax.php?action=gg_cm_serve_label&order_id=' . $order->id . '&_wpnonce=' . wp_create_nonce('gg_cm_label_' . $order->id));
                } elseif ($order->status === 'label_generated') {
                    $upload_dir   = wp_upload_dir();
                    $guessed_path = $upload_dir['basedir'] . '/gg-rm-labels/label-cm-' . $order->cm_order_id . '.pdf';
                    if (file_exists($guessed_path)) {
                        $wpdb->update(gg_cm_table(), ['label_path' => $guessed_path], ['id' => $order->id]);
                        $label_url = admin_url('admin-ajax.php?action=gg_cm_serve_label&order_id=' . $order->id . '&_wpnonce=' . wp_create_nonce('gg_cm_label_' . $order->id));
                    }
                }
            ?>
            <div class="gg-cm-card">

                <!-- Card header -->
                <div class="gg-cm-card-header">
                    <div>
                        <div class="gg-cm-card-id">#<?php echo esc_html($order->cm_order_id); ?></div>
                        <div class="gg-cm-card-date"><?php echo esc_html(date('d/m/y H:i', strtotime($order->created_at))); ?></div>
                    </div>
                    <span class="gg-cm-badge" style="<?php echo $status_style; ?>">
                        <?php echo esc_html($order->status === 'label_generated' ? 'Label Ready' : ucfirst($order->status)); ?>
                    </span>
                </div>

                <!-- Card body -->
                <div class="gg-cm-card-body">
                    <div class="gg-cm-card-row">
                        <span class="gg-cm-card-label">Buyer</span>
                        <span class="gg-cm-card-value"><?php echo esc_html($order->buyer_name); ?></span>
                    </div>
                    <div class="gg-cm-card-row">
                        <span class="gg-cm-card-label">Address</span>
                        <span class="gg-cm-card-value">
                            <?php echo esc_html($order->address_line1); ?><br>
                            <?php if ($order->address_line2) echo esc_html($order->address_line2) . '<br>'; ?>
                            <?php echo esc_html($order->postcode . ' ' . $order->city); ?>
                        </span>
                    </div>
                    <div class="gg-cm-card-row">
                        <span class="gg-cm-card-label">Cards</span>
                        <span class="gg-cm-card-value"><?php echo $item_count; ?> cards (<?php echo count($items); ?> lines)</span>
                    </div>
                    <div class="gg-cm-card-row">
                        <span class="gg-cm-card-label">Total</span>
                        <span class="gg-cm-card-value">£<?php echo number_format($order->total_gbp, 2); ?></span>
                    </div>
                    <?php if ($order->tracking): ?>
                    <div class="gg-cm-card-row">
                        <span class="gg-cm-card-label">Tracking</span>
                        <span class="gg-cm-card-value">
                            <div style="display:flex;align-items:center;gap:8px;">
                                <input type="text" readonly
                                       value="<?php echo esc_attr($order->tracking); ?>"
                                       style="font-family:monospace;font-size:0.85em;padding:4px 8px;border:1px solid #7B00FF;border-radius:4px;background:#f9f0ff;color:#333;width:160px;cursor:pointer;"
                                       onclick="this.select();"
                                       title="Click to select">
                                <button onclick="navigator.clipboard.writeText('<?php echo esc_js($order->tracking); ?>').then(function(){var b=this;b.textContent='✅ Copied!';setTimeout(function(){b.textContent='📋 Copy';},2000);}.bind(this));"
                                        style="font-size:11px;padding:4px 8px;border:1px solid #7B00FF;border-radius:4px;background:#7B00FF;color:#fff;cursor:pointer;white-space:nowrap;">
                                    📋 Copy
                                </button>
                            </div>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Items toggle -->
                <button class="gg-cm-items-toggle"
                        onclick="var l=this.nextElementSibling;l.classList.toggle('open');this.textContent=l.classList.contains('open')?'▲ Hide cards':'▼ View <?php echo $item_count; ?> cards';">
                    ▼ View <?php echo $item_count; ?> cards
                </button>
                <div class="gg-cm-items-list">
                    <?php foreach ($items as $item): ?>
                    <div class="gg-cm-item-row">
                        <span><strong><?php echo $item['qty']; ?>x</strong> <?php echo esc_html($item['name']); ?></span>
                        <span style="color:#888;white-space:nowrap;margin-left:8px;">
                            £<?php echo number_format($item['price_gbp'] * $item['qty'], 2); ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Action area -->
                <div class="gg-cm-card-action">
                    <?php if ($order->status === 'pending'): ?>
                        <form method="post">
                            <?php wp_nonce_field('gg_cm_generate'); ?>
                            <input type="hidden" name="order_id" value="<?php echo $order->id; ?>">
                            <div class="gg-cm-ship-row">
                                <select name="shipping_method" class="gg-cm-select">
                                    <option value="2nd_class">✉️ 2nd Class</option>
                                    <option value="large_letter">📮 Large Letter</option>
                                    <option value="tracked_48" selected>📦 Tracked 48</option>
                                    <option value="tracked_24">🚀 Tracked 24</option>
                                    <option value="special">⭐ Special Delivery</option>
                                </select>
                                <button type="submit" name="gg_cm_generate" class="gg-cm-gen-btn">
                                    Generate Label
                                </button>
                            </div>
                        </form>

                    <?php elseif ($label_url): ?>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                            <a href="<?php echo esc_url($label_url); ?>" target="_blank"
                               class="gg-cm-btn gg-cm-btn-print">🖨 Print Label</a>
                            <?php if ($order->tracking): ?>
                            <a href="https://www.royalmail.com/track-your-item#/tracking-results/<?php echo esc_attr($order->tracking); ?>"
                               target="_blank" class="gg-cm-btn gg-cm-btn-track">📍 Track</a>
                            <?php endif; ?>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field('gg_cm_refire_stock'); ?>
                                <input type="hidden" name="order_id" value="<?php echo $order->id; ?>">
                                <button type="submit" name="gg_cm_refire_stock"
                                        class="gg-cm-btn gg-cm-btn-secondary"
                                        style="font-size:0.75em;"
                                        onclick="return confirm('Re-fire eBay stock reduction for this order? Only do this if stock was not reduced the first time.');">
                                    🔄 Re-fire eBay Stock
                                </button>
                            </form>
                        </div>

                    <?php else: ?>
                        <?php
                        // Label generated but no path — show debug info and regenerate option
                        $upload_dir   = wp_upload_dir();
                        $guessed_path = $upload_dir['basedir'] . '/gg-rm-labels/label-cm-' . $order->cm_order_id . '.pdf';
                        $file_exists  = file_exists($guessed_path);
                        ?>
                        <?php if ($file_exists): ?>
                            <?php
                            // File exists but path not in DB — save it and show button
                            $wpdb->update(gg_cm_table(), ['label_path' => $guessed_path], ['id' => $order->id]);
                            $fixed_url = admin_url('admin-ajax.php?action=gg_cm_serve_label&order_id=' . $order->id . '&_wpnonce=' . wp_create_nonce('gg_cm_label_' . $order->id));
                            ?>
                            <a href="<?php echo esc_url($fixed_url); ?>" target="_blank"
                               class="gg-cm-btn gg-cm-btn-print">🖨 Print Label</a>
                        <?php else: ?>
                            <div style="font-size:0.8em;color:#888;margin-bottom:6px;">
                                Label path: <code><?php echo esc_html($order->label_path ?: 'not saved'); ?></code><br>
                                Expected: <code><?php echo esc_html($guessed_path); ?></code><br>
                                File exists: <?php echo $file_exists ? '✅' : '❌'; ?>
                            </div>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field('gg_cm_generate'); ?>
                                <input type="hidden" name="order_id" value="<?php echo $order->id; ?>">
                                <input type="hidden" name="shipping_method" value="<?php echo esc_attr($order->shipping_method ?: 'tracked_48'); ?>">
                                <button type="submit" name="gg_cm_generate" class="gg-cm-btn gg-cm-btn-secondary"
                                        style="font-size:0.8em;">
                                    🔄 Regenerate Label
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
}

// ============================================================
// 8. SERVE LABEL PDF (bypasses .htaccess deny)
// ============================================================

add_action('wp_ajax_gg_cm_serve_label', function() {
    global $wpdb;
    $order_id = intval($_GET['order_id'] ?? 0);
    if (!check_ajax_referer('gg_cm_label_' . $order_id, '_wpnonce', false)) {
        wp_die('Unauthorised', 403);
    }
    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . gg_cm_table() . " WHERE id = %d", $order_id
    ));
    if (!$order || !$order->label_path || !file_exists($order->label_path)) {
        wp_die('Label not found', 404);
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="label-cm-' . $order->cm_order_id . '.pdf"');
    readfile($order->label_path);
    exit;
});

// ============================================================
// 9. SETTINGS PAGE
// ============================================================

add_action('admin_menu', function() {
    add_submenu_page(
        'woocommerce',
        'Cardmarket Settings',
        'CM Settings',
        'manage_woocommerce',
        'gg-cm-settings',
        'gg_cm_settings_page'
    );
});

function gg_cm_settings_page() {
    if (isset($_POST['gg_cm_save_settings']) && check_admin_referer('gg_cm_settings')) {
        update_option('gg_cm_imap_host',   sanitize_text_field($_POST['imap_host']));
        update_option('gg_cm_imap_port',   intval($_POST['imap_port']));
        update_option('gg_cm_imap_user',   sanitize_text_field($_POST['imap_user']));
        update_option('gg_cm_imap_pass',   sanitize_text_field($_POST['imap_pass']));
        update_option('gg_cm_imap_folder', sanitize_text_field($_POST['imap_folder']));
        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }

    // IMAP connection test
    $test_result = null;
    if (isset($_POST['gg_cm_test_imap']) && check_admin_referer('gg_cm_test_imap')) {
        $test_result = gg_cm_test_imap_connection();
    }

    // Clear log
    if (isset($_POST['gg_cm_clear_log']) && check_admin_referer('gg_cm_clear_log')) {
        delete_option('gg_cm_log');
        echo '<div class="notice notice-success"><p>Log cleared.</p></div>';
    }

    $log = get_option('gg_cm_log', []);
    ?>
    <div class="wrap">
        <h1>Cardmarket IMAP Settings</h1>

        <!-- Settings form -->
        <form method="post">
            <?php wp_nonce_field('gg_cm_settings'); ?>
            <table class="form-table">
                <tr><th>IMAP Host</th><td>
                    <input type="text" name="imap_host" value="<?php echo esc_attr(gg_cm_imap_host()); ?>" class="regular-text">
                    <p class="description">For 1&1: <code>imap.1and1.com</code></p>
                </td></tr>
                <tr><th>IMAP Port</th><td>
                    <input type="number" name="imap_port" value="<?php echo esc_attr(gg_cm_imap_port()); ?>" class="small-text">
                    <p class="description">Usually <code>993</code> (SSL)</p>
                </td></tr>
                <tr><th>Email Address</th><td>
                    <input type="text" name="imap_user" value="<?php echo esc_attr(gg_cm_imap_user()); ?>" class="regular-text">
                </td></tr>
                <tr><th>Password</th><td>
                    <input type="password" name="imap_pass" value="<?php echo esc_attr(gg_cm_imap_pass()); ?>" class="regular-text">
                </td></tr>
                <tr><th>Folder</th><td>
                    <input type="text" name="imap_folder" value="<?php echo esc_attr(gg_cm_imap_folder()); ?>" class="regular-text">
                    <p class="description">Usually <code>INBOX</code></p>
                </td></tr>
            </table>
            <?php submit_button('Save Settings', 'primary', 'gg_cm_save_settings'); ?>
        </form>

        <hr>

        <!-- Connection test -->
        <h2>🔌 Connection Test</h2>
        <p>Tests that the IMAP credentials above can connect and counts Cardmarket emails.</p>

        <?php if ($test_result): ?>
            <?php if ($test_result['success']): ?>
            <div style="background:#f0fdf4;border:1px solid #22c55e;border-radius:6px;padding:16px;margin-bottom:16px;">
                <strong style="color:#16a34a">✅ Connected successfully</strong><br>
                <table style="margin-top:8px;border-collapse:collapse;font-size:13px;">
                    <tr><td style="padding:3px 12px 3px 0;color:#555">Mailbox:</td>
                        <td><strong><?php echo esc_html($test_result['mailbox']); ?></strong></td></tr>
                    <tr><td style="padding:3px 12px 3px 0;color:#555">Total messages:</td>
                        <td><strong><?php echo esc_html($test_result['total_msgs']); ?></strong></td></tr>
                    <tr><td style="padding:3px 12px 3px 0;color:#555">Unread messages:</td>
                        <td><strong><?php echo esc_html($test_result['unread_msgs']); ?></strong></td></tr>
                    <tr><td style="padding:3px 12px 3px 0;color:#555">Unread from Cardmarket:</td>
                        <td><strong style="color:<?php echo $test_result['cm_unread'] > 0 ? '#7B00FF' : '#555'; ?>">
                            <?php echo esc_html($test_result['cm_unread']); ?>
                        </strong></td></tr>
                    <tr><td style="padding:3px 12px 3px 0;color:#555">All Cardmarket (read+unread):</td>
                        <td><strong><?php echo esc_html($test_result['cm_all']); ?></strong></td></tr>
                    <?php if (!empty($test_result['recent_subjects'])): ?>
                    <tr><td style="padding:3px 12px 3px 0;color:#555;vertical-align:top">Recent CM subjects:</td>
                        <td><?php foreach ($test_result['recent_subjects'] as $subj): ?>
                            <div style="font-family:monospace;font-size:11px;color:#333;margin-bottom:2px;">
                                <?php echo esc_html($subj); ?>
                            </div>
                        <?php endforeach; ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
            <?php else: ?>
            <div style="background:#fef2f2;border:1px solid #ef4444;border-radius:6px;padding:16px;margin-bottom:16px;">
                <strong style="color:#dc2626">❌ Connection failed</strong><br>
                <code style="display:block;margin-top:8px;font-size:12px;color:#991b1b;">
                    <?php echo esc_html($test_result['error']); ?>
                </code>
                <p style="margin-top:8px;font-size:12px;color:#666;">
                    Common causes: wrong password, wrong host, IMAP not enabled on your 1&1 account,
                    or server blocking the connection. Check your 1&1 webmail settings to confirm IMAP access is enabled.
                </p>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('gg_cm_test_imap'); ?>
            <button type="submit" name="gg_cm_test_imap" class="button button-secondary">
                🔌 Test IMAP Connection
            </button>
        </form>

        <hr>

        <!-- Log viewer -->
        <h2>📋 Activity Log</h2>
        <?php if (empty($log)): ?>
            <p style="color:#888">No log entries yet.</p>
        <?php else: ?>
        <div style="background:#1a1a1a;border-radius:6px;padding:12px;max-height:400px;overflow-y:auto;font-family:monospace;font-size:12px;color:#ccc;">
            <?php foreach (array_reverse($log) as $entry): ?>
                <div style="padding:3px 0;border-bottom:1px solid #2a2a2a;">
                    <span style="color:#6366f1"><?php echo esc_html($entry['time']); ?></span>
                    &nbsp;<?php echo esc_html($entry['msg']); ?>
                </div>
            <?php endforeach; ?>
        </div>
        <form method="post" style="margin-top:8px;">
            <?php wp_nonce_field('gg_cm_clear_log'); ?>
            <button type="submit" name="gg_cm_clear_log" class="button button-small button-secondary">
                🗑 Clear Log
            </button>
        </form>
        <?php endif; ?>
    </div>
    <?php
}

function gg_cm_test_imap_connection() {
    if (!function_exists('imap_open')) {
        return ['success' => false, 'error' => 'PHP IMAP extension is not installed on this server. Contact your host to enable it.'];
    }

    $host   = gg_cm_imap_host();
    $port   = gg_cm_imap_port();
    $user   = gg_cm_imap_user();
    $pass   = gg_cm_imap_pass();
    $folder = gg_cm_imap_folder();

    if (!$user || !$pass) {
        return ['success' => false, 'error' => 'Email address and password are required. Please save your settings first.'];
    }

    $mailbox = "{" . $host . ":" . $port . "/imap/ssl/novalidate-cert}" . $folder;

    $conn = @imap_open($mailbox, $user, $pass, 0, 1);
    if (!$conn) {
        $err = imap_last_error();
        // Also grab full alerts for more detail
        $alerts = imap_alerts();
        $detail = $err;
        if ($alerts) $detail .= ' | Alerts: ' . implode(' | ', $alerts);
        return ['success' => false, 'error' => $detail];
    }

    $check       = imap_check($conn);
    $total_msgs  = $check ? $check->Nmsgs : 0;
    $unread_msgs = imap_num_recent($conn);

    // Count unread Cardmarket emails
    $cm_unread_uids = @imap_search($conn, 'UNSEEN FROM "noreply@cardmarket.com"', SE_UID) ?: [];
    $cm_all_uids    = @imap_search($conn, 'FROM "noreply@cardmarket.com"', SE_UID) ?: [];

    // Get subject lines of last 5 CM emails for verification
    $recent_subjects = [];
    $recent_uids = array_slice(array_reverse($cm_all_uids), 0, 5);
    foreach ($recent_uids as $uid) {
        $header = imap_headerinfo($conn, imap_msgno($conn, $uid));
        if ($header && !empty($header->subject)) {
            $decoded = imap_mime_header_decode($header->subject);
            $subject = '';
            foreach ($decoded as $part) $subject .= $part->text;
            $recent_subjects[] = $subject;
        }
    }

    imap_close($conn);

    return [
        'success'         => true,
        'mailbox'         => $user . ' / ' . $folder,
        'total_msgs'      => $total_msgs,
        'unread_msgs'     => $unread_msgs,
        'cm_unread'       => count($cm_unread_uids),
        'cm_all'          => count($cm_all_uids),
        'recent_subjects' => $recent_subjects,
    ];
}

// ============================================================
// 10. LOGGING
// ============================================================

function gg_cm_log($message) {
    error_log('[GG CM Orders] ' . $message);
    $log   = get_option('gg_cm_log', []);
    $log[] = ['time' => current_time('mysql'), 'msg' => $message];
    if (count($log) > 200) $log = array_slice($log, -200);
    update_option('gg_cm_log', $log, false);
}