<?php
/**
 * Plugin Name: GG eBay Live Sync (Add-On to eBay Importer)
 * Description: Two-way inventory sync between WooCommerce ⇄ eBay (qty only). Separate add-on for your eBay Importer.
 * Version: 1.1.0
 * Author: GrimeGames + Signal
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

/* -------------------------------------------------------
 * CONSTANTS & OPTIONS
 * ----------------------------------------------------- */
const GG_EBAY_SYNC_OPT            = 'gg_ebay_sync_enabled';
const GG_EBAY_SYNC_INTERVAL_OPT   = 'gg_ebay_sync_interval_minutes';
const GG_EBAY_LAST_CHECKPOINT_OPT = 'gg_ebay_last_checkpoint_iso';
const GG_EBAY_LAST_RUN_OPT        = 'gg_ebay_sync_last_run';
const GG_EBAY_LOG_OPT             = 'gg_ebay_sync_log_enabled';
const GG_EBAY_ENV_OPT             = 'gg_ebay_env'; // 'prod' or 'sandbox'

const GG_EBAY_CLIENT_ID_OPT       = 'gg_ebay_client_id';
const GG_EBAY_CLIENT_SECRET_OPT   = 'gg_ebay_client_secret';
const GG_EBAY_REFRESH_TOKEN_OPT   = 'gg_ebay_refresh_token';
const GG_EBAY_ACCESS_TOKEN_OPT    = 'gg_ebay_access_token';
const GG_EBAY_ACCESS_EXP_OPT      = 'gg_ebay_access_expires_at'; // unix ts

const GG_EBAY_META_SKU            = '_gg_ebay_sku';
const GG_EBAY_META_OFFER_ID       = '_gg_ebay_offer_id';
const GG_EBAY_META_SYNC_ENABLED   = '_gg_ebay_sync_enabled';

/** Single canonical cron hook name (used everywhere) */
define('GG_EBAY_CRON_HOOK', 'gg_ebay_sync_cron_tick');

/* -------------------------------------------------------
 * SAFETY: Only run if WooCommerce is active
 * ----------------------------------------------------- */
add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) return;
    gg_ensure_sync_cron();
});

/* -------------------------------------------------------
 * CRON SCHEDULE: 5-min (or custom) interval
 * ----------------------------------------------------- */
add_filter('cron_schedules', function($s){
    $mins = max(1, (int) get_option(GG_EBAY_SYNC_INTERVAL_OPT, 5));
    $s['gg_every_five_minutes'] = [
        'interval' => $mins * 60,
        'display'  => sprintf(__('Every %d minutes (GG eBay Sync)','gg'), $mins)
    ];
    return $s;
});

/** Ensure our cron event exists (self-heals if removed) */
function gg_ensure_sync_cron(){
    if (!wp_next_scheduled(GG_EBAY_CRON_HOOK)) {
        wp_schedule_event(time() + 120, 'gg_every_five_minutes', GG_EBAY_CRON_HOOK);
    }
}

/* -------------------------------------------------------
 * ACTIVATION / DEACTIVATION
 * ----------------------------------------------------- */
register_activation_hook(__FILE__, function(){
    if (get_option(GG_EBAY_SYNC_INTERVAL_OPT, null) === null) update_option(GG_EBAY_SYNC_INTERVAL_OPT, 5);
    if (get_option(GG_EBAY_SYNC_OPT, null) === null)           update_option(GG_EBAY_SYNC_OPT, 1); // default ON
    gg_ensure_sync_cron();
});

register_deactivation_hook(__FILE__, function(){
    $ts = wp_next_scheduled(GG_EBAY_CRON_HOOK);
    if ($ts) wp_unschedule_event($ts, GG_EBAY_CRON_HOOK);
});

/* -------------------------------------------------------
 * ADMIN: submenu + settings + Run Now
 * ----------------------------------------------------- */
add_action('admin_menu', function(){
    add_submenu_page(
        'woocommerce',
        'eBay Live Sync',
        'eBay Live Sync',
        'manage_woocommerce',
        'gg-ebay-live-sync',
        'gg_ebay_sync_admin_page'
    );
});

function gg_ebay_sync_admin_page(){
    if (!current_user_can('manage_woocommerce')) return;

    // Handle Save
    if (isset($_POST['gg_ebay_sync_save']) && check_admin_referer('gg_ebay_sync_save','gg_ebay_sync_nonce')) {
        update_option(GG_EBAY_SYNC_OPT, isset($_POST['gg_sync_enabled']) ? 1 : 0);
        update_option(GG_EBAY_SYNC_INTERVAL_OPT, max(1, (int) $_POST['gg_sync_interval']));
        update_option(GG_EBAY_LOG_OPT, isset($_POST['gg_sync_log']) ? 1 : 0);
        update_option(GG_EBAY_ENV_OPT, in_array($_POST['gg_env'], ['prod','sandbox'], true) ? $_POST['gg_env'] : 'prod');

        if (!empty($_POST['gg_client_id']))     update_option(GG_EBAY_CLIENT_ID_OPT, sanitize_text_field($_POST['gg_client_id']));
        if (!empty($_POST['gg_client_secret'])) update_option(GG_EBAY_CLIENT_SECRET_OPT, sanitize_text_field($_POST['gg_client_secret']));
        if (!empty($_POST['gg_refresh_token'])) update_option(GG_EBAY_REFRESH_TOKEN_OPT, sanitize_text_field($_POST['gg_refresh_token']));

        // Clear cached access token on creds change
        delete_option(GG_EBAY_ACCESS_TOKEN_OPT);
        delete_option(GG_EBAY_ACCESS_EXP_OPT);

        // Rebuild schedule if interval changed
        $ts = wp_next_scheduled(GG_EBAY_CRON_HOOK);
        if ($ts) wp_unschedule_event($ts, GG_EBAY_CRON_HOOK);
        gg_ensure_sync_cron();

        echo '<div class="updated"><p>Saved.</p></div>';
    }

    $enabled  = (int) get_option(GG_EBAY_SYNC_OPT, 1);
    $interval = (int) get_option(GG_EBAY_SYNC_INTERVAL_OPT, 5);
    $log_on   = (int) get_option(GG_EBAY_LOG_OPT, 1);
    $env      = get_option(GG_EBAY_ENV_OPT, 'prod');
    $last_cp  = esc_html(get_option(GG_EBAY_LAST_CHECKPOINT_OPT, '—'));
    $last_run = (int) get_option(GG_EBAY_LAST_RUN_OPT, 0);
    $next     = wp_next_scheduled(GG_EBAY_CRON_HOOK);

    echo '<div class="wrap"><h1>eBay Live Sync</h1>';

    if (isset($_GET['ggsync']) && $_GET['ggsync'] === 'done') {
        echo '<div class="updated"><p>Sync started. Check the Debug Log (tail) below for results.</p></div>';
    }

    echo '<p>🕒 <strong>Last sync:</strong> '.($last_run ? esc_html(date('Y-m-d H:i:s', $last_run)) : 'never')
       . ' &nbsp; | &nbsp; ⏭ <strong>Next run:</strong> '.($next ? esc_html(date('Y-m-d H:i:s', $next)) : 'not scheduled').'</p>';

    echo '<form method="post">';
    wp_nonce_field('gg_ebay_sync_save','gg_ebay_sync_nonce');
    echo '<table class="form-table">';
    echo '<tr><th>Enable two-way sync</th><td><input type="checkbox" name="gg_sync_enabled" '.checked($enabled,1,false).'></td></tr>';
    echo '<tr><th>Poll interval (minutes)</th><td><input type="number" name="gg_sync_interval" value="'.esc_attr($interval).'" min="1" max="30"></td></tr>';
    echo '<tr><th>Logging</th><td><input type="checkbox" name="gg_sync_log" '.checked($log_on,1,false).'> <em>Stores to uploads/gg-ebay-sync.log</em></td></tr>';
    echo '<tr><th>Environment</th><td><select name="gg_env"><option value="prod" '.selected($env,'prod',false).'>Production</option><option value="sandbox" '.selected($env,'sandbox',false).'>Sandbox</option></select></td></tr>';
    echo '<tr><th>Last checkpoint (eBay → Woo)</th><td>'.$last_cp.'</td></tr>';
    echo '<tr><th colspan="2">(Optional) OAuth creds used by this add-on</th></tr>';
    echo '<tr><th>Client ID</th><td><input type="text" name="gg_client_id" value="'.esc_attr(get_option(GG_EBAY_CLIENT_ID_OPT,'')).'" style="width:420px"></td></tr>';
    echo '<tr><th>Client Secret</th><td><input type="password" name="gg_client_secret" value="'.esc_attr(get_option(GG_EBAY_CLIENT_SECRET_OPT,'')).'" style="width:420px"></td></tr>';
    echo '<tr><th>Refresh Token</th><td><input type="text" name="gg_refresh_token" value="'.esc_attr(get_option(GG_EBAY_REFRESH_TOKEN_OPT,'')).'" style="width:420px"></td></tr>';
    echo '</table>';

    echo '<p><button class="button button-primary" name="gg_ebay_sync_save" value="1">Save</button> ';

    $run_url = wp_nonce_url(
        admin_url('admin-post.php?action=gg_ebay_sync_run'),
        'gg_ebay_sync_run'
    );
    echo '<a class="button" href="'.esc_url($run_url).'">Run Sync Now</a></p>';

    echo '</form>';

    echo '<h2>Debug Log (tail)</h2><pre style="max-height:300px;overflow:auto;background:#111;color:#0f0;padding:12px;">'.esc_html(gg_ebay_sync_tail_log()).'</pre>';
    echo '</div>';
}

/* -------------------------------------------------------
 * Admin Flash Notice helper
 * ----------------------------------------------------- */
add_action('admin_notices', function () {
    if (!current_user_can('manage_woocommerce')) return;
    $msg = get_option('gg_ebay_sync_flash');
    if ($msg) {
        delete_option('gg_ebay_sync_flash');
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
    }
});

/* -------------------------------------------------------
 * LOGGING
 * ----------------------------------------------------- */
function gg_ebay_sync_log($msg){
    if (!get_option(GG_EBAY_LOG_OPT, 1)) return;
    $upload_dir = wp_upload_dir();
    $file = trailingslashit($upload_dir['basedir']).'gg-ebay-sync.log';
    $line = sprintf("[%s] %s\n", current_time('mysql'), $msg);
    @file_put_contents($file, $line, FILE_APPEND);
}
function gg_ebay_sync_tail_log($lines = 200){
    $upload_dir = wp_upload_dir();
    $file = trailingslashit($upload_dir['basedir']).'gg-ebay-sync.log';
    if (!file_exists($file)) return 'No log yet.';
    $data = @file($file);
    if (!$data) return '';
    return implode("", array_slice($data, -absint($lines)) );
}

/* -------------------------------------------------------
 * TOKEN MGMT — ALWAYS use the add-on credentials + refresh token
 * ----------------------------------------------------- */
function gg_ebay_get_access_token(){
    // Use cached access token if still valid
    $access = get_option(GG_EBAY_ACCESS_TOKEN_OPT);
    $exp    = (int) get_option(GG_EBAY_ACCESS_EXP_OPT, 0);
    if ($access && $exp && time() < ($exp - 300)) { return $access; }

    // Pull creds & refresh token from our settings page
    $client  = get_option(GG_EBAY_CLIENT_ID_OPT);
    $secret  = get_option(GG_EBAY_CLIENT_SECRET_OPT);
    $refresh = get_option(GG_EBAY_REFRESH_TOKEN_OPT);
    $env     = get_option(GG_EBAY_ENV_OPT,'prod');

    if (!$client || !$secret || !$refresh) {
        gg_ebay_sync_log('WARN token: missing client/secret/refresh; cannot mint access token.');
        return '';
    }

    $token_url = ($env === 'sandbox')
        ? 'https://api.sandbox.ebay.com/identity/v1/oauth2/token'
        : 'https://api.ebay.com/identity/v1/oauth2/token';

    $auth = base64_encode($client.':'.$secret);

    $args = [
        'headers' => [
            'Authorization' => 'Basic '.$auth,
            'Content-Type'  => 'application/x-www-form-urlencoded'
        ],
        'body' => http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh,
        ]),
        'timeout' => 60,
    ];

    $res = wp_remote_post($token_url, $args);
    if (is_wp_error($res)) {
        gg_ebay_sync_log('ERROR token: '.$res->get_error_message());
        return '';
    }

    $code = wp_remote_retrieve_response_code($res);
    $raw  = wp_remote_retrieve_body($res);
    $body = json_decode($raw, true);
    if ($body === null && json_last_error() !== JSON_ERROR_NONE) { $body = ['raw'=>$raw]; }

    if ($code >= 200 && $code < 300 && !empty($body['access_token'])) {
        update_option(GG_EBAY_ACCESS_TOKEN_OPT, $body['access_token']);
        update_option(GG_EBAY_ACCESS_EXP_OPT, time() + (int)($body['expires_in'] ?? 3600));
        gg_ebay_sync_log('INFO token: refreshed access token.');
        return $body['access_token'];
    }

    gg_ebay_sync_log('ERROR token: HTTP '.$code.' body '.wp_json_encode($body));
    return '';
}

/* -------------------------------------------------------
 * HTTP helper
 * ----------------------------------------------------- */
function gg_ebay_api($method, $path, $query = [], $body = null){
    $env  = get_option(GG_EBAY_ENV_OPT,'prod');
    $host = ($env === 'sandbox') ? 'https://api.sandbox.ebay.com' : 'https://api.ebay.com';
    $token = gg_ebay_get_access_token();
    if (!$token) return [0, ['error' => 'no_token']];

    $url = $host.$path;
    if (!empty($query)) $url .= '?'.http_build_query($query);

    $args = [
        'method'  => strtoupper($method),
        'headers' => [
            'Authorization' => 'Bearer '.$token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json'
        ],
        'timeout' => 60
    ];
    if (!is_null($body)) $args['body'] = wp_json_encode($body);

    $res  = wp_remote_request($url, $args);
    if (is_wp_error($res)) return [0, ['error' => $res->get_error_message()]];

    $code = wp_remote_retrieve_response_code($res);
    $raw  = wp_remote_retrieve_body($res);
    $data = json_decode($raw, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) { $data = ['raw'=>$raw]; }

    return [$code, $data];
}

/* -------------------------------------------------------
 * WOO → EBAY: push on stock changes
 * DISABLED: gg-ebay-webhooks.php already handles Woo→eBay stock
 * sync via gg_sync_woo_order_to_ebay() using Trading API
 * ReviseInventoryStatus. Having both active causes DOUBLE
 * stock reduction on eBay (one via Trading API, one via
 * Inventory API). Only the cron-based offer scan remains active.
 * ----------------------------------------------------- */
/*
add_action('woocommerce_reduce_order_stock', function($order){
    if (!get_option(GG_EBAY_SYNC_OPT,0)) return;
    if (!$order || is_wp_error($order)) return;

    foreach ($order->get_items() as $item) {
        $product = $item->get_product(); if (!$product) continue;
        gg_ebay_sync_push_for_product($product, (int) $item->get_quantity());
    }
});

add_action('woocommerce_product_set_stock', function($product){
    if (!get_option(GG_EBAY_SYNC_OPT,0)) return;
    if (!$product) return;
    $prev = (int) get_transient('gg_prev_stock_'.$product->get_id());
    $curr = (int) $product->get_stock_quantity();
    set_transient('gg_prev_stock_'.$product->get_id(), $curr, 300);
    $delta = $prev - $curr;                     // positive when reduced
    if ($delta > 0) gg_ebay_sync_push_for_product($product, $delta);
}, 10, 1);
*/

function gg_ebay_sync_push_for_product($product, $qty_delta){
    $pid = $product->get_id();
    $enabled = get_post_meta($pid, GG_EBAY_META_SYNC_ENABLED, true);
    if ($enabled === '0') { gg_ebay_sync_log("SKIP push: product $pid sync disabled"); return; }

    $offerId = get_post_meta($pid, GG_EBAY_META_OFFER_ID, true);
    $sku     = $product->get_sku() ?: get_post_meta($pid, GG_EBAY_META_SKU, true);
    if (!$offerId && $sku) {
        list($c,$d) = gg_ebay_api('GET','/sell/inventory/v1/offer', ['sku' => $sku]);
        if ($c>=200 && $c<300 && !empty($d['offers'][0]['offerId'])) {
            $offerId = $d['offers'][0]['offerId'];
            update_post_meta($pid, GG_EBAY_META_OFFER_ID, $offerId);
        }
    }
    if (!$offerId) { gg_ebay_sync_log("WARN push: missing offerId for product $pid (sku=$sku)"); return; }

    // read current qty
    list($co,$od) = gg_ebay_api('GET', "/sell/inventory/v1/offer/$offerId", []);
    if ($co<200 || $co>=300) { gg_ebay_sync_log("ERROR push: read offer $offerId http=$co body=".wp_json_encode($od)); return; }
    $currentQty = (int) ($od['availableQuantity'] ?? 0);
    $newQty = max(0, $currentQty - (int)$qty_delta);

    $payload = [ 'requests' => [[ 'offerId' => $offerId, 'quantity' => $newQty ]] ];
    list($cu,$ud) = gg_ebay_api('POST','/sell/inventory/v1/bulk_update_price_quantity', [], $payload);

    if ($cu>=200 && $cu<300) {
        gg_ebay_sync_log("OK push: offer=$offerId qty {$currentQty}→{$newQty} pid=$pid sku=$sku");
    } else {
        gg_ebay_sync_log("ERROR push: offer=$offerId http=$cu body=".wp_json_encode($ud));
        // retry once using fresh value
        list($cr,$rd) = gg_ebay_api('GET', "/sell/inventory/v1/offer/$offerId", []);
        $fresh = (int) ($rd['availableQuantity'] ?? $newQty);
        $retry = max(0, $fresh - (int)$qty_delta);
        if ($cr>=200 && $cr<300) {
            list($c2,$d2) = gg_ebay_api('POST','/sell/inventory/v1/bulk_update_price_quantity', [], [ 'requests' => [[ 'offerId' => $offerId, 'quantity' => $retry ]] ]);
            if ($c2>=200 && $c2<300) gg_ebay_sync_log("OK push-retry: offer=$offerId qty {$fresh}→{$retry}");
            else gg_ebay_sync_log("FAIL push-retry: offer=$offerId http=$c2 body=".wp_json_encode($d2));
        }
    }
}

/* -------------------------------------------------------
 * EBAY → WOO: CRON runner (orders delta + offers scan)
 * ----------------------------------------------------- */
add_action(GG_EBAY_CRON_HOOK, function() {
    if (!get_option(GG_EBAY_SYNC_OPT, 0)) return;

    update_option(GG_EBAY_LAST_RUN_OPT, time());

    gg_ebay_sync_orders_delta();
    gg_ebay_sync_offer_scan();

    update_option(GG_EBAY_LAST_RUN_OPT, time());
});

function gg_ebay_sync_orders_delta(){
    $last = get_option(GG_EBAY_LAST_CHECKPOINT_OPT, '');
    $from = $last ? $last : gmdate('Y-m-d\TH:i:s\Z', time() - 86400);
    $to   = gmdate('Y-m-d\TH:i:s\Z');

    $filter = sprintf('lastmodifieddate:[%s..%s]', $from, $to);
    list($c,$d) = gg_ebay_api('GET','/sell/fulfillment/v1/order', [ 'filter' => $filter, 'limit' => 100 ]);
    if ($c<200 || $c>=300) { gg_ebay_sync_log('ERROR pull-orders: http='.$c.' body='.wp_json_encode($d)); return; }

    if (empty($d['orders'])) { update_option(GG_EBAY_LAST_CHECKPOINT_OPT, $to); return; }

    foreach ($d['orders'] as $order) {
        if (empty($order['lineItems'])) continue;
        foreach ($order['lineItems'] as $li) {
            $sku = $li['sku'] ?? '';
            $qty = (int) ($li['quantity'] ?? 0);
            if (!$sku || !$qty) continue;

            $product_id = wc_get_product_id_by_sku($sku);
            if (!$product_id) { gg_ebay_sync_log("WARN pull-orders: no product for sku=$sku"); continue; }

            $product = wc_get_product($product_id);
            if (!$product || !$product->managing_stock()) { gg_ebay_sync_log("SKIP pull-orders: stock not managed pid=$product_id sku=$sku"); continue; }

            $curr = (int) $product->get_stock_quantity();
            $new  = max(0, $curr - $qty);
            $product->set_stock_quantity($new);
            $product->save();
            gg_ebay_sync_log("OK pull-orders: sku=$sku pid=$product_id qty {$curr}→{$new}");
        }
    }

    update_option(GG_EBAY_LAST_CHECKPOINT_OPT, $to);
}

function gg_ebay_sync_offer_scan(){
    // Broader scan + rich logging so we can see what's happening
    $per_page = 100;
    $total_processed = 0;

    // Helper to process a single WC product (simple OR variation)
    $process_product = function($p) use (&$total_processed) {
        $pid  = $p->get_id();
        $sku  = $p->get_sku();
        if (!$sku) {
            // Fallback to our custom meta if SKU isn’t on the product
            $sku = get_post_meta($pid, GG_EBAY_META_SKU, true);
        }

        if (!$sku) {
            gg_ebay_sync_log("SKIP scan: no SKU pid=$pid");
            return;
        }

        // Resolve or fetch offerId once
        $offerId = get_post_meta($pid, GG_EBAY_META_OFFER_ID, true);
        if (!$offerId) {
            list($c,$d) = gg_ebay_api('GET','/sell/inventory/v1/offer', ['sku'=>$sku]);
            if ($c>=200 && $c<300 && !empty($d['offers'][0]['offerId'])) {
                $offerId = $d['offers'][0]['offerId'];
                update_post_meta($pid, GG_EBAY_META_OFFER_ID, $offerId);
                gg_ebay_sync_log("INFO scan: linked offerId pid=$pid sku=$sku offer=$offerId");
            } else {
                gg_ebay_sync_log("SKIP scan: no offer for pid=$pid sku=$sku http=$c");
                return;
            }
        }

        // Read eBay qty for the offer
        list($co,$od) = gg_ebay_api('GET', "/sell/inventory/v1/offer/$offerId", []);
        if ($co<200 || $co>=300) {
            gg_ebay_sync_log("WARN scan: offer read http=$co pid=$pid sku=$sku");
            return;
        }

        $ebayQty = (int) ($od['availableQuantity'] ?? 0);

        // Only align if Woo manages stock
        if (!$p->managing_stock()) {
            gg_ebay_sync_log("SKIP scan: stock not managed pid=$pid sku=$sku");
            return;
        }

        // RACE CONDITION FIX: Skip products recently updated by gg-ebay-webhooks
        // The webhook handler sets a 60s transient lock after stock changes
        if (get_transient('gg_stock_lock_' . $pid)) {
            gg_ebay_sync_log("SKIP scan: stock locked by webhook (recent sale) pid=$pid sku=$sku");
            return;
        }

        $wooQty = (int) $p->get_stock_quantity();
        if ($wooQty !== $ebayQty) {
            $p->set_stock_quantity($ebayQty);
            $p->save();
            gg_ebay_sync_log("OK scan: align pid=$pid sku=$sku {$wooQty}→{$ebayQty}");
        } else {
            gg_ebay_sync_log("INFO scan: already aligned pid=$pid sku=$sku qty=$wooQty");
        }

        $total_processed++;
    };

    // 1) Scan published simple/variable products (parents)
    $paged = 1;
    do {
        $args = [
            'status' => 'publish',
            'limit'  => $per_page,
            'page'   => $paged,
            // return objects (default); we want SKU etc.
        ];
        $prods = wc_get_products($args);
        $count = is_array($prods) ? count($prods) : 0;
        gg_ebay_sync_log("SCAN (parents) page=$paged found=$count");
        if (empty($prods)) break;

        foreach ($prods as $p) {
            $process_product($p);
        }
        $paged++;
    } while (!empty($prods));

    // 2) Scan published VARIATIONS (SKUs often live here)
    $paged = 1;
    do {
        $vargs = [
            'status' => 'publish',
            'type'   => 'variation',
            'limit'  => $per_page,
            'page'   => $paged,
        ];
        $vars = wc_get_products($vargs);
        $vcount = is_array($vars) ? count($vars) : 0;
        gg_ebay_sync_log("SCAN (variations) page=$paged found=$vcount");
        if (empty($vars)) break;

        foreach ($vars as $v) {
            $process_product($v);
        }
        $paged++;
    } while (!empty($vars));

    gg_ebay_sync_log("INFO scan: processed $total_processed products");
}


/* -------------------------------------------------------
 * Admin endpoint: Run Sync Now (orders delta + offer scan)
 * ----------------------------------------------------- */
add_action('admin_post_gg_ebay_sync_run', 'gg_ebay_sync_run_now');
function gg_ebay_sync_run_now(){
    if (!current_user_can('manage_woocommerce')) { wp_die('Insufficient permissions.'); }
    check_admin_referer('gg_ebay_sync_run');

    gg_ebay_sync_orders_delta();
    gg_ebay_sync_offer_scan();

    wp_safe_redirect( add_query_arg(
        array('page'=>'gg-ebay-live-sync','ggsync'=>'done','_'=>time()),
        admin_url('admin.php')
    ));
    exit;
}
