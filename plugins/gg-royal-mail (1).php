<?php
/**
 * Plugin Name: GrimeGames Royal Mail Click & Drop
 * Description: Integrates WooCommerce with Royal Mail Click & Drop API.
 *              Auto-purchases tracked labels on order, saves PDF to order,
 *              injects tracking into order confirmation email.
 * Author: GrimeGames
 * Version: 2.0
 */

defined('ABSPATH') || exit;

// ============================================================
// CONFIGURATION — Managed via WooCommerce > Royal Mail settings
// ============================================================

define('GG_RM_API_BASE', 'https://api.parcel.royalmail.com/api/v1');

function gg_rm_api_key() {
    return get_option('gg_rm_api_key', '');
}

// Shipping method IDs
define('GG_RM_METHOD_2ND_CLASS',    'gg_rm_2nd_class');
define('GG_RM_METHOD_LARGE_LETTER', 'gg_rm_large_letter');
define('GG_RM_METHOD_TRACKED_48',   'gg_rm_tracked_48');
define('GG_RM_METHOD_TRACKED_24',   'gg_rm_tracked_24');
define('GG_RM_METHOD_SPECIAL',      'gg_rm_special');

// Royal Mail service codes for OBA accounts
define('GG_RM_SERVICE_2ND_CLASS',  'BPL2');  // 2nd Class (OBA)
define('GG_RM_SERVICE_TRACKED_48', 'TRS48'); // Royal Mail Tracked 48 (OBA)
define('GG_RM_SERVICE_TRACKED_24', 'TRN24'); // Royal Mail Tracked 24 (OBA)
define('GG_RM_SERVICE_SPECIAL',    'SD1');   // Special Delivery

// ============================================================
// 1. REGISTER SHIPPING METHODS
// ============================================================

add_filter('woocommerce_shipping_methods', function($methods) {
    $methods['gg_rm_2nd_class']    = 'GG_RM_Shipping_2nd_Class';
    $methods['gg_rm_large_letter'] = 'GG_RM_Shipping_Large_Letter';
    $methods['gg_rm_tracked_48']   = 'GG_RM_Shipping_Tracked_48';
    $methods['gg_rm_tracked_24']   = 'GG_RM_Shipping_Tracked_24';
    $methods['gg_rm_special']      = 'GG_RM_Shipping_Special';
    return $methods;
});

add_action('woocommerce_shipping_init', 'gg_rm_load_shipping_methods');
function gg_rm_load_shipping_methods() {

    class GG_RM_Shipping_2nd_Class extends WC_Shipping_Method {
        public function __construct($instance_id = 0) {
            $this->id                 = 'gg_rm_2nd_class';
            $this->instance_id        = absint($instance_id);
            $this->method_title       = '2nd Class Post';
            $this->method_description = 'Royal Mail 2nd Class (standard letter)';
            $this->supports           = ['shipping-zones', 'instance-settings'];
            $this->init();
        }
        public function init() {
            $this->init_form_fields();
            $this->init_settings();
            $this->title   = $this->get_option('title', '2nd Class Post');
            $this->enabled = $this->get_option('enabled', 'yes');
            add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
        }
        public function init_form_fields() {
            $this->instance_form_fields = [
                'title'   => ['title' => 'Title', 'type' => 'text', 'default' => '2nd Class Post'],
                'enabled' => ['title' => 'Enable', 'type' => 'checkbox', 'default' => 'yes'],
            ];
        }
        public function calculate_shipping($package = []) {
            $this->add_rate(['id' => $this->id, 'label' => $this->title, 'cost' => 0.95]);
        }
    }

    class GG_RM_Shipping_Large_Letter extends WC_Shipping_Method {
        public function __construct($instance_id = 0) {
            $this->id                 = 'gg_rm_large_letter';
            $this->instance_id        = absint($instance_id);
            $this->method_title       = '2nd Class Large Letter';
            $this->method_description = 'Royal Mail 2nd Class Large Letter';
            $this->supports           = ['shipping-zones', 'instance-settings'];
            $this->init();
        }
        public function init() {
            $this->init_form_fields();
            $this->init_settings();
            $this->title   = $this->get_option('title', '2nd Class Large Letter');
            $this->enabled = $this->get_option('enabled', 'yes');
            add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
        }
        public function init_form_fields() {
            $this->instance_form_fields = [
                'title'   => ['title' => 'Title', 'type' => 'text', 'default' => '2nd Class Large Letter'],
                'enabled' => ['title' => 'Enable', 'type' => 'checkbox', 'default' => 'yes'],
            ];
        }
        public function calculate_shipping($package = []) {
            $this->add_rate(['id' => $this->id, 'label' => $this->title, 'cost' => 1.60]);
        }
    }

    class GG_RM_Shipping_Tracked_48 extends WC_Shipping_Method {
        public function __construct($instance_id = 0) {
            $this->id                 = 'gg_rm_tracked_48';
            $this->instance_id        = absint($instance_id);
            $this->method_title       = 'Royal Mail Tracked 48';
            $this->method_description = 'Royal Mail Tracked 48 — tracking number emailed automatically';
            $this->supports           = ['shipping-zones', 'instance-settings'];
            $this->init();
        }
        public function init() {
            $this->init_form_fields();
            $this->init_settings();
            $this->title   = $this->get_option('title', 'Royal Mail Tracked 48');
            $this->enabled = $this->get_option('enabled', 'yes');
            add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
        }
        public function init_form_fields() {
            $this->instance_form_fields = [
                'title'   => ['title' => 'Title', 'type' => 'text', 'default' => 'Royal Mail Tracked 48'],
                'enabled' => ['title' => 'Enable', 'type' => 'checkbox', 'default' => 'yes'],
            ];
        }
        public function calculate_shipping($package = []) {
            $this->add_rate(['id' => $this->id, 'label' => $this->title, 'cost' => 3.00]);
        }
    }

    class GG_RM_Shipping_Tracked_24 extends WC_Shipping_Method {
        public function __construct($instance_id = 0) {
            $this->id                 = 'gg_rm_tracked_24';
            $this->instance_id        = absint($instance_id);
            $this->method_title       = 'Royal Mail Tracked 24';
            $this->method_description = 'Royal Mail Tracked 24 — tracking number emailed automatically';
            $this->supports           = ['shipping-zones', 'instance-settings'];
            $this->init();
        }
        public function init() {
            $this->init_form_fields();
            $this->init_settings();
            $this->title   = $this->get_option('title', 'Royal Mail Tracked 24');
            $this->enabled = $this->get_option('enabled', 'yes');
            add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
        }
        public function init_form_fields() {
            $this->instance_form_fields = [
                'title'   => ['title' => 'Title', 'type' => 'text', 'default' => 'Royal Mail Tracked 24'],
                'enabled' => ['title' => 'Enable', 'type' => 'checkbox', 'default' => 'yes'],
            ];
        }
        public function calculate_shipping($package = []) {
            $this->add_rate(['id' => $this->id, 'label' => $this->title, 'cost' => 3.70]);
        }
    }

    class GG_RM_Shipping_Special extends WC_Shipping_Method {
        public function __construct($instance_id = 0) {
            $this->id                 = 'gg_rm_special';
            $this->instance_id        = absint($instance_id);
            $this->method_title       = 'Special Delivery Guaranteed';
            $this->method_description = 'Royal Mail Special Delivery — guaranteed next day with tracking';
            $this->supports           = ['shipping-zones', 'instance-settings'];
            $this->init();
        }
        public function init() {
            $this->init_form_fields();
            $this->init_settings();
            $this->title   = $this->get_option('title', 'Special Delivery Guaranteed');
            $this->enabled = $this->get_option('enabled', 'yes');
            add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
        }
        public function init_form_fields() {
            $this->instance_form_fields = [
                'title'   => ['title' => 'Title', 'type' => 'text', 'default' => 'Special Delivery Guaranteed'],
                'enabled' => ['title' => 'Enable', 'type' => 'checkbox', 'default' => 'yes'],
            ];
        }
        public function calculate_shipping($package = []) {
            $this->add_rate(['id' => $this->id, 'label' => $this->title, 'cost' => 9.50]);
        }
    }
}

// ============================================================
// 2. HELPERS
// ============================================================

function gg_rm_is_tracked_method($method_id) {
    $tracked = ['gg_rm_tracked_48', 'gg_rm_tracked_24', 'gg_rm_special'];
    $base    = explode(':', $method_id)[0];
    return in_array($base, $tracked, true);
}

function gg_rm_get_service_code($method_id) {
    $base = explode(':', $method_id)[0];
    $map  = [
        'gg_rm_2nd_class'    => GG_RM_SERVICE_2ND_CLASS,
        'gg_rm_large_letter' => GG_RM_SERVICE_2ND_CLASS,
        'gg_rm_tracked_48'   => GG_RM_SERVICE_TRACKED_48,
        'gg_rm_tracked_24'   => GG_RM_SERVICE_TRACKED_24,
        'gg_rm_special'      => GG_RM_SERVICE_SPECIAL,
    ];
    return $map[$base] ?? null;
}

// ============================================================
// 3. AUTO-CREATE LABEL ON ORDER PLACEMENT (TRACKED ONLY)
// ============================================================

add_action('woocommerce_payment_complete', 'gg_rm_handle_new_order');
add_action('woocommerce_order_status_processing', 'gg_rm_handle_new_order');

function gg_rm_handle_new_order($order_id) {
    if (get_post_meta($order_id, '_gg_rm_pushed', true)) return;

    $order = wc_get_order($order_id);
    if (!$order) return;

    $shipping_methods = $order->get_shipping_methods();
    if (empty($shipping_methods)) return;

    $method    = array_values($shipping_methods)[0];
    $method_id = $method->get_method_id();

    if (!gg_rm_is_tracked_method($method_id)) {
        gg_rm_push_order($order_id, false);
        return;
    }

    gg_rm_push_order($order_id, true);
}

// ============================================================
// 4. PUSH ORDER TO CLICK & DROP API
// ============================================================

function gg_rm_push_order($order_id, $generate_label = false) {
    $order = wc_get_order($order_id);
    if (!$order) return ['success' => false, 'error' => 'Order not found'];

    $shipping_methods = $order->get_shipping_methods();
    $method           = !empty($shipping_methods) ? array_values($shipping_methods)[0] : null;
    $method_id        = $method ? $method->get_method_id() : '';
    $service_code     = gg_rm_get_service_code($method_id);

    $order_item = [
        'orderReference'      => (string) $order->get_order_number(),
        'recipient'           => [
            'address' => [
                'fullName'     => trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()),
                'companyName'  => $order->get_shipping_company() ?: '',
                'addressLine1' => $order->get_shipping_address_1(),
                'addressLine2' => $order->get_shipping_address_2() ?: '',
                'city'         => $order->get_shipping_city(),
                'county'       => $order->get_shipping_state() ?: '',
                'postcode'     => $order->get_shipping_postcode(),
                'countryCode'  => $order->get_shipping_country() ?: 'GB',
            ],
            'emailAddress' => $order->get_billing_email(),
            'phoneNumber'  => $order->get_billing_phone() ?: '',
        ],
        'orderDate'           => $order->get_date_created()->format('Y-m-d\TH:i:s\Z'),
        'subtotal'            => (float) $order->get_subtotal(),
        'shippingCostCharged' => (float) $order->get_shipping_total(),
        'otherCosts'          => 0,
        'total'               => (float) $order->get_total(),
        'currencyCode'        => $order->get_currency(),
        'packages'            => [
            [
                'weightInGrams'           => 100,
                'packageFormatIdentifier' => 'largeLetter',
                'contents'                => gg_rm_build_contents($order),
            ]
        ],
    ];

    // Always send serviceCode — all services now have correct OBA codes
    $postage = [
        'sendNotificationsTo' => 'recipient',
        'serviceCode'         => $service_code,
    ];
    $order_item['postageDetails'] = $postage;

    if ($generate_label) {
        $order_item['label'] = [
            'includeLabelInResponse' => true,
            'includeCN'              => false,
            'includeReturnsLabel'    => false,
        ];
    }

    $payload  = ['items' => [$order_item]];
    $response = gg_rm_api_post('/orders', $payload);

    // --- Debug panel info ---
    gg_rm_log("Order $order_id — API POST /orders | Payload: " . json_encode($payload));

    if (is_wp_error($response)) {
        $error = $response->get_error_message();
        gg_rm_log("Order $order_id push FAILED (WP_Error): $error");
        update_post_meta($order_id, '_gg_rm_error', $error);
        return ['success' => false, 'error' => $error];
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $body      = json_decode(wp_remote_retrieve_body($response), true);

    gg_rm_log("Order $order_id — API response HTTP $http_code | Body: " . wp_remote_retrieve_body($response));

    if ($http_code !== 200 && $http_code !== 201) {
        $error = isset($body['message']) ? $body['message'] : "HTTP $http_code";
        gg_rm_log("Order $order_id API error: $error");
        update_post_meta($order_id, '_gg_rm_error', $error);
        return ['success' => false, 'error' => $error];
    }

    // Extract Click & Drop order identifier
    // Royal Mail API returns results in createdOrders[], not items[] or root
    $order_identifier = null;
    $response_item    = null;
    $inline_tracking  = null;

    if (!empty($body['createdOrders'][0])) {
        $response_item    = $body['createdOrders'][0];
        $order_identifier = $response_item['orderIdentifier'] ?? null;
    } elseif (!empty($body['items'][0])) {
        $response_item    = $body['items'][0];
        $order_identifier = $response_item['orderIdentifier'] ?? null;
    } elseif (!empty($body[0])) {
        $response_item    = $body[0];
        $order_identifier = $response_item['orderIdentifier'] ?? null;
    }

    if ($order_identifier) {
        update_post_meta($order_id, '_gg_rm_order_id', $order_identifier);
        gg_rm_log("Order $order_id — Click & Drop identifier: $order_identifier");
    } else {
        gg_rm_log("Order $order_id — WARNING: No orderIdentifier in response. Full body: " . wp_remote_retrieve_body($response));
    }

    // Extract tracking number inline if present
    // Royal Mail returns it in createdOrders[0].packages[0].trackingNumber
    if (!empty($response_item['packages'][0]['trackingNumber'])) {
        $inline_tracking = $response_item['packages'][0]['trackingNumber'];
        gg_rm_log("Order $order_id — tracking number from inline response: $inline_tracking");
    }

    // Save label PDF if returned inline
    if ($generate_label && !empty($response_item['label'])) {
        $label_data = $response_item['label'];
        $pdf = base64_decode($label_data, true);
        if ($pdf === false) $pdf = $label_data;
        gg_rm_save_label_pdf($order_id, $pdf);
        gg_rm_log("Order $order_id — label PDF saved inline from push response.");
    }

    update_post_meta($order_id, '_gg_rm_pushed', current_time('mysql'));
    update_post_meta($order_id, '_gg_rm_status', $generate_label ? 'label_requested' : 'pushed');
    delete_post_meta($order_id, '_gg_rm_error');

    // If we got tracking inline, save it and notify immediately — no need to poll
    if ($inline_tracking) {
        gg_rm_save_tracking_and_notify($order_id, $inline_tracking);
        return ['success' => true, 'order_identifier' => $order_identifier, 'tracking' => $inline_tracking];
    }

    // Otherwise schedule async tracking fetch as fallback
    if ($generate_label && $order_identifier) {
        wp_schedule_single_event(time() + 30, 'gg_rm_retry_tracking', [$order_id, $order_identifier]);
        gg_rm_log("Order $order_id — tracking fetch scheduled in 30s.");
    }

    return ['success' => true, 'order_identifier' => $order_identifier];
}

// ============================================================
// 5. SAVE LABEL PDF TO ORDER
// ============================================================

function gg_rm_save_label_pdf($order_id, $pdf_data) {
    if (empty($pdf_data)) {
        gg_rm_log("Order $order_id — save_label_pdf called with empty data, skipping.");
        return false;
    }

    $upload_dir = wp_upload_dir();
    $label_dir  = $upload_dir['basedir'] . '/gg-rm-labels';

    if (!file_exists($label_dir)) {
        wp_mkdir_p($label_dir);
        // Protect directory from direct access
        file_put_contents($label_dir . '/.htaccess', 'deny from all');
    }

    $filename  = 'label-order-' . $order_id . '.pdf';
    $filepath  = $label_dir . '/' . $filename;

    $result = file_put_contents($filepath, $pdf_data);

    if ($result === false) {
        gg_rm_log("Order $order_id — FAILED to write label PDF to $filepath");
        return false;
    }

    update_post_meta($order_id, '_gg_rm_label_path', $filepath);
    gg_rm_log("Order $order_id — label PDF saved to $filepath (" . strlen($pdf_data) . " bytes)");
    return $filepath;
}

// ============================================================
// 6. FETCH TRACKING NUMBER & SAVE
// ============================================================

add_action('gg_rm_retry_tracking', 'gg_rm_retry_tracking_callback', 10, 2);
function gg_rm_retry_tracking_callback($order_id, $order_identifier) {
    if (get_post_meta($order_id, '_gg_rm_tracking', true)) {
        gg_rm_log("Order $order_id — tracking already saved, skipping retry.");
        return;
    }
    gg_rm_fetch_tracking_and_notify($order_id, $order_identifier);
}

function gg_rm_fetch_tracking_and_notify($order_id, $order_identifier) {
    gg_rm_log("Order $order_id — fetching tracking from Click & Drop (identifier: $order_identifier)");

    $response  = gg_rm_api_get('/orders/' . urlencode($order_identifier));

    if (is_wp_error($response)) {
        gg_rm_log("Order $order_id — tracking fetch WP_Error: " . $response->get_error_message());
        return;
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $body      = json_decode(wp_remote_retrieve_body($response), true);

    gg_rm_log("Order $order_id — tracking fetch HTTP $http_code | Body: " . wp_remote_retrieve_body($response));

    if ($http_code !== 200 || empty($body)) {
        gg_rm_log("Order $order_id — tracking fetch failed. HTTP $http_code");
        // Retry again in 2 minutes
        wp_schedule_single_event(time() + 120, 'gg_rm_retry_tracking', [$order_id, $order_identifier]);
        return;
    }

    // Extract tracking number
    $tracking = null;
    if (!empty($body['label']['trackingNumber'])) {
        $tracking = $body['label']['trackingNumber'];
    } elseif (!empty($body['shipments'][0]['trackingNumber'])) {
        $tracking = $body['shipments'][0]['trackingNumber'];
    } elseif (!empty($body['trackingNumber'])) {
        $tracking = $body['trackingNumber'];
    }

    gg_rm_log("Order $order_id — tracking number extracted: " . ($tracking ?: 'NONE'));

    if (!$tracking) {
        gg_rm_log("Order $order_id — no tracking yet, retrying in 2 minutes.");
        update_post_meta($order_id, '_gg_rm_status', 'awaiting_tracking');
        wp_schedule_single_event(time() + 120, 'gg_rm_retry_tracking', [$order_id, $order_identifier]);
        return;
    }

    // Try to save label PDF if not already saved
    $existing_label = get_post_meta($order_id, '_gg_rm_label_path', true);
    if (!$existing_label || !file_exists($existing_label)) {
        gg_rm_fetch_and_save_label($order_id, $order_identifier);
    }

    gg_rm_save_tracking_and_notify($order_id, $tracking);
}

function gg_rm_fetch_and_save_label($order_id, $order_identifier) {
    gg_rm_log("Order $order_id — fetching label PDF from Click & Drop.");

    $response = wp_remote_get(GG_RM_API_BASE . '/orders/' . urlencode($order_identifier) . '/label', [
        'timeout' => 30,
        'headers' => [
            'Authorization' => gg_rm_api_key(),
            'Accept'        => 'application/pdf',
        ],
    ]);

    if (is_wp_error($response)) {
        gg_rm_log("Order $order_id — label fetch WP_Error: " . $response->get_error_message());
        return;
    }

    $http_code = wp_remote_retrieve_response_code($response);
    gg_rm_log("Order $order_id — label fetch HTTP $http_code");

    $raw  = wp_remote_retrieve_body($response);
    $decoded = json_decode($raw, true);

    if ($decoded && !empty($decoded['label'])) {
        $pdf = base64_decode($decoded['label']);
    } else {
        $pdf = $raw;
    }

    if (strlen($pdf) > 100) {
        gg_rm_save_label_pdf($order_id, $pdf);
    } else {
        gg_rm_log("Order $order_id — label PDF suspiciously small (" . strlen($pdf) . " bytes), not saving.");
    }
}

function gg_rm_save_tracking_and_notify($order_id, $tracking) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    update_post_meta($order_id, '_gg_rm_tracking', $tracking);
    update_post_meta($order_id, '_gg_rm_status', 'tracking_received');

    $order->add_order_note("✓ Royal Mail tracking number: $tracking");
    gg_rm_log("Order $order_id — tracking saved: $tracking");

    // Send dispatch + tracking email to customer
    gg_rm_send_tracking_email($order, $tracking);

    $order->update_status('completed', 'Label generated and tracking sent to customer.');
}

// ============================================================
// 7. INJECT TRACKING INTO WOOCOMMERCE ORDER EMAILS
// ============================================================

// Hook into WooCommerce email — adds tracking block above order table
add_action('woocommerce_email_before_order_table', 'gg_rm_inject_tracking_into_email', 10, 4);
function gg_rm_inject_tracking_into_email($order, $sent_to_admin, $plain_text, $email) {
    // Only inject into customer-facing emails, not admin copies
    if ($sent_to_admin) return;

    $tracking = get_post_meta($order->get_id(), '_gg_rm_tracking', true);
    if (!$tracking) return;

    $tracking_url = 'https://www.royalmail.com/track-your-item#/tracking-results/' . $tracking;

    if ($plain_text) {
        echo "\nYour Royal Mail tracking number: $tracking\n";
        echo "Track here: $tracking_url\n\n";
    } else {
        echo '
        <div style="background:#1a1a1a; border:1px solid #7B00FF; border-radius:8px; padding:20px; margin:20px 0; text-align:center;">
            <p style="color:#aaaaaa; margin:0 0 8px; font-size:14px;">Your Royal Mail tracking number</p>
            <p style="font-size:24px; font-weight:bold; color:#7B00FF; margin:0 0 16px; letter-spacing:2px;">' . esc_html($tracking) . '</p>
            <a href="' . esc_url($tracking_url) . '" 
               style="background:#7B00FF; color:#ffffff; padding:12px 28px; border-radius:50px; text-decoration:none; font-weight:bold; font-size:15px;">
                Track Your Order →
            </a>
        </div>';
    }
}

// ============================================================
// 8. DISPATCH EMAIL TO CUSTOMER (sent when tracking arrives)
// ============================================================

function gg_rm_send_tracking_email($order, $tracking) {
    $to        = $order->get_billing_email();
    $name      = $order->get_billing_first_name();
    $order_num = $order->get_order_number();

    $tracking_url = 'https://www.royalmail.com/track-your-item#/tracking-results/' . $tracking;
    $subject      = "Your GrimeGames order #{$order_num} has been dispatched! 📦";

    $message = "
    <html>
    <body style='font-family: Arial, sans-serif; background: #0a0a0a; color: #ffffff; padding: 20px;'>
        <div style='max-width: 600px; margin: 0 auto; background: #1a1a1a; border-radius: 12px; padding: 30px; border: 1px solid #7B00FF;'>
            <h1 style='color: #7B00FF; margin-bottom: 5px;'>♟ GrimeGames</h1>
            <h2 style='color: #ffffff;'>Your order is on its way!</h2>
            <p style='color: #cccccc;'>Hi {$name},</p>
            <p style='color: #cccccc;'>Great news — your order #{$order_num} has been dispatched via Royal Mail.</p>
            <div style='background: #2a2a2a; border-radius: 8px; padding: 20px; margin: 20px 0; text-align: center;'>
                <p style='color: #aaaaaa; margin: 0 0 8px;'>Your tracking number is:</p>
                <p style='font-size: 22px; font-weight: bold; color: #7B00FF; margin: 0; letter-spacing: 2px;'>{$tracking}</p>
            </div>
            <div style='text-align: center; margin: 25px 0;'>
                <a href='{$tracking_url}' style='background: #7B00FF; color: #ffffff; padding: 14px 30px; border-radius: 50px; text-decoration: none; font-weight: bold; font-size: 16px;'>
                    Track Your Order →
                </a>
            </div>
            <p style='color: #888888; font-size: 13px; margin-top: 30px; border-top: 1px solid #333; padding-top: 15px;'>
                If you have any questions, reply to this email or contact us through our website.<br>
                Thanks for shopping with GrimeGames! 🃏
            </p>
        </div>
    </body>
    </html>";

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: GrimeGames <noreply@grimegames.com>',
    ];

    $sent = wp_mail($to, $subject, $message, $headers);
    gg_rm_log("Dispatch email " . ($sent ? "sent" : "FAILED") . " to $to for order #$order_num — tracking: $tracking");
}

// ============================================================
// 9. API HELPERS
// ============================================================

function gg_rm_api_post($endpoint, $data) {
    return wp_remote_post(GG_RM_API_BASE . $endpoint, [
        'timeout' => 30,
        'headers' => [
            'Authorization' => gg_rm_api_key(),
            'Content-Type'  => 'application/json',
        ],
        'body' => json_encode($data),
    ]);
}

function gg_rm_api_get($endpoint) {
    return wp_remote_get(GG_RM_API_BASE . $endpoint, [
        'timeout' => 30,
        'headers' => [
            'Authorization' => gg_rm_api_key(),
            'Content-Type'  => 'application/json',
        ],
    ]);
}

function gg_rm_build_contents($order) {
    $contents = [];
    foreach ($order->get_items() as $item) {
        $contents[] = [
            'name'              => $item->get_name(),
            'unitValue'         => (float) $order->get_item_subtotal($item),
            'unitWeightInGrams' => 20,
            'quantity'          => $item->get_quantity(),
            'SKU'               => $item->get_product() ? $item->get_product()->get_sku() : '',
        ];
    }
    return $contents;
}

function gg_rm_log($message) {
    error_log('[GG Royal Mail] ' . $message);
    $log = get_option('gg_rm_log', []);
    array_unshift($log, ['time' => current_time('mysql'), 'message' => $message]);
    $log = array_slice($log, 0, 200);
    update_option('gg_rm_log', $log);
}

// ============================================================
// 10. ADMIN — ORDERS LIST COLUMN
// ============================================================

add_filter('manage_woocommerce_page_wc-orders_columns', 'gg_rm_add_orders_column');
add_filter('manage_edit-shop_order_columns', 'gg_rm_add_orders_column');
function gg_rm_add_orders_column($columns) {
    $new = [];
    foreach ($columns as $key => $label) {
        $new[$key] = $label;
        if ($key === 'order_status') {
            $new['gg_rm_label'] = '📦 Royal Mail';
        }
    }
    return $new;
}

add_action('manage_woocommerce_page_wc-orders_custom_column', 'gg_rm_render_orders_column', 10, 2);
add_action('manage_shop_order_posts_custom_column', 'gg_rm_render_orders_column', 10, 2);
function gg_rm_render_orders_column($column, $order_or_id) {
    if ($column !== 'gg_rm_label') return;

    $order_id = is_object($order_or_id) ? $order_or_id->get_id() : $order_or_id;
    $order    = wc_get_order($order_id);
    if (!$order) return;

    $pushed      = get_post_meta($order_id, '_gg_rm_pushed', true);
    $tracking    = get_post_meta($order_id, '_gg_rm_tracking', true);
    $error       = get_post_meta($order_id, '_gg_rm_error', true);
    $rm_id       = get_post_meta($order_id, '_gg_rm_order_id', true);
    $label_path  = get_post_meta($order_id, '_gg_rm_label_path', true);
    $label_saved = $label_path && file_exists($label_path);

    $shipping_methods = $order->get_shipping_methods();
    $method_id        = '';
    if (!empty($shipping_methods)) {
        $method    = array_values($shipping_methods)[0];
        $method_id = $method->get_method_id();
    }
    $is_tracked = gg_rm_is_tracked_method($method_id);
    $nonce      = wp_create_nonce('gg_rm_action_' . $order_id);

    echo '<div style="font-size:12px; line-height:1.6;">';

    if ($error) {
        echo '<span style="color:#ff4444;">⚠ ' . esc_html($error) . '</span><br>';
    }

    if ($tracking) {
        $tracking_url = 'https://www.royalmail.com/track-your-item#/tracking-results/' . $tracking;
        echo '<span style="color:#00cc44;">✓ Tracked</span><br>';
        echo '<a href="' . esc_url($tracking_url) . '" target="_blank" style="color:#7B00FF; font-weight:bold;">' . esc_html($tracking) . '</a><br>';
    } elseif ($pushed) {
        echo $is_tracked
            ? '<span style="color:#ffaa00;">⏳ Awaiting tracking</span><br>'
            : '<span style="color:#888;">✓ Pushed to C&D</span><br>';
    } else {
        echo '<span style="color:#888;">Not pushed</span><br>';
    }

    if ($label_saved) {
        echo '<span style="color:#00cc44; font-size:11px;">✓ Label saved</span><br>';
    } elseif ($pushed && $is_tracked) {
        echo '<span style="color:#888; font-size:11px;">⚠ No label file</span><br>';
    }

    // Action buttons
    if (!$pushed) {
        echo '<a href="' . admin_url('admin-post.php?action=gg_rm_push&order_id=' . $order_id . '&nonce=' . $nonce) . '" 
                 style="display:inline-block; margin-top:3px; padding:3px 8px; background:#7B00FF; color:#fff; border-radius:4px; text-decoration:none; font-size:11px;">
                 Push to C&D
              </a> ';
    }

    if ($pushed && $is_tracked && !$tracking && $rm_id) {
        echo '<a href="' . admin_url('admin-post.php?action=gg_rm_fetch_tracking&order_id=' . $order_id . '&nonce=' . $nonce) . '" 
                 style="display:inline-block; margin-top:3px; padding:3px 8px; background:#555; color:#fff; border-radius:4px; text-decoration:none; font-size:11px;">
                 Fetch Tracking
              </a> ';
    }

    if ($rm_id) {
        $label_url = admin_url('admin-post.php?action=gg_rm_print_label&order_id=' . $order_id . '&nonce=' . $nonce);
        echo '<a href="' . esc_url($label_url) . '" target="_blank"
                 style="display:inline-block; margin-top:3px; padding:3px 8px; background:#1a1a1a; color:#fff; border:1px solid #7B00FF; border-radius:4px; text-decoration:none; font-size:11px;">
                 🖨 Label
              </a>';
    }

    echo '</div>';
}

// ============================================================
// 11. ORDER DETAIL PAGE — ROYAL MAIL META BOX
// ============================================================

add_action('add_meta_boxes', function() {
    add_meta_box(
        'gg_rm_order_box',
        '📦 Royal Mail',
        'gg_rm_order_meta_box',
        ['shop_order', 'woocommerce_page_wc-orders'],
        'side',
        'default'
    );
});

function gg_rm_order_meta_box($post_or_order) {
    $order_id = is_object($post_or_order) && method_exists($post_or_order, 'get_id')
        ? $post_or_order->get_id()
        : (is_object($post_or_order) ? $post_or_order->ID : 0);

    $pushed     = get_post_meta($order_id, '_gg_rm_pushed', true);
    $tracking   = get_post_meta($order_id, '_gg_rm_tracking', true);
    $status     = get_post_meta($order_id, '_gg_rm_status', true);
    $error      = get_post_meta($order_id, '_gg_rm_error', true);
    $rm_id      = get_post_meta($order_id, '_gg_rm_order_id', true);
    $label_path = get_post_meta($order_id, '_gg_rm_label_path', true);
    $label_ok   = $label_path && file_exists($label_path);

    $nonce = wp_create_nonce('gg_rm_action_' . $order_id);

    echo '<div style="font-size:13px;">';

    if ($error) {
        echo '<p style="color:#ff4444; background:#2a1a1a; padding:8px; border-radius:4px;">⚠ ' . esc_html($error) . '</p>';
    }

    if ($tracking) {
        $tracking_url = 'https://www.royalmail.com/track-your-item#/tracking-results/' . $tracking;
        echo '<p><strong style="color:#00cc44;">✓ Tracking:</strong><br>';
        echo '<a href="' . esc_url($tracking_url) . '" target="_blank" style="color:#7B00FF; font-weight:bold; font-size:14px;">' . esc_html($tracking) . '</a></p>';
    } elseif ($pushed) {
        echo '<p><strong>Status:</strong> ' . esc_html($status ?: 'Pushed') . '</p>';
        echo '<p style="color:#888; font-size:11px;">Pushed: ' . esc_html($pushed) . '</p>';
    } else {
        echo '<p style="color:#888;">Not yet sent to Click & Drop.</p>';
    }

    // Label file status
    if ($label_ok) {
        $label_size = round(filesize($label_path) / 1024, 1);
        echo '<p style="color:#00cc44; font-size:12px;">✓ Label PDF saved (' . $label_size . ' KB)</p>';
    } elseif ($pushed) {
        echo '<p style="color:#ffaa00; font-size:12px;">⚠ No label file saved yet</p>';
    }

    echo '<div style="display:flex; flex-direction:column; gap:6px; margin-top:10px;">';

    if (!$pushed) {
        echo '<a href="' . admin_url('admin-post.php?action=gg_rm_push&order_id=' . $order_id . '&nonce=' . $nonce) . '" 
                 style="padding:7px 12px; background:#7B00FF; color:#fff; border-radius:5px; text-decoration:none; text-align:center;">
                 Push to Click & Drop
              </a>';
    }

    if ($pushed && !$tracking && $rm_id) {
        echo '<a href="' . admin_url('admin-post.php?action=gg_rm_fetch_tracking&order_id=' . $order_id . '&nonce=' . $nonce) . '" 
                 style="padding:7px 12px; background:#444; color:#fff; border-radius:5px; text-decoration:none; text-align:center;">
                 Fetch Tracking
              </a>';
    }

    if ($rm_id) {
        echo '<a href="' . admin_url('admin-post.php?action=gg_rm_print_label&order_id=' . $order_id . '&nonce=' . $nonce) . '" 
                 target="_blank"
                 style="padding:7px 12px; background:#1a1a1a; color:#fff; border:1px solid #7B00FF; border-radius:5px; text-decoration:none; text-align:center;">
                 🖨 Open / Print Label
              </a>';
    }

    echo '</div></div>';
}

// ============================================================
// 12. ADMIN ACTION HANDLERS
// ============================================================

// Manual push
add_action('admin_post_gg_rm_push', function() {
    $order_id = intval($_GET['order_id'] ?? 0);
    if (!wp_verify_nonce($_GET['nonce'] ?? '', 'gg_rm_action_' . $order_id)) wp_die('Invalid nonce');
    if (!current_user_can('manage_woocommerce')) wp_die('Unauthorized');

    $order = wc_get_order($order_id);
    if (!$order) wp_die('Order not found');

    $shipping_methods = $order->get_shipping_methods();
    $method_id        = '';
    if (!empty($shipping_methods)) {
        $method    = array_values($shipping_methods)[0];
        $method_id = $method->get_method_id();
    }
    $is_tracked = gg_rm_is_tracked_method($method_id);

    delete_post_meta($order_id, '_gg_rm_pushed');
    $result = gg_rm_push_order($order_id, $is_tracked);

    $redirect = wp_get_referer() ?: admin_url('edit.php?post_type=shop_order');
    wp_redirect(add_query_arg('gg_rm_msg', $result['success'] ? 'pushed' : 'error', $redirect));
    exit;
});

// Manual fetch tracking
add_action('admin_post_gg_rm_fetch_tracking', function() {
    $order_id = intval($_GET['order_id'] ?? 0);
    if (!wp_verify_nonce($_GET['nonce'] ?? '', 'gg_rm_action_' . $order_id)) wp_die('Invalid nonce');
    if (!current_user_can('manage_woocommerce')) wp_die('Unauthorized');

    $rm_id = get_post_meta($order_id, '_gg_rm_order_id', true);
    if ($rm_id) gg_rm_fetch_tracking_and_notify($order_id, $rm_id);

    $redirect = wp_get_referer() ?: admin_url('edit.php?post_type=shop_order');
    wp_redirect(add_query_arg('gg_rm_msg', 'tracking_fetched', $redirect));
    exit;
});

// Print / serve label PDF
add_action('admin_post_gg_rm_print_label', function() {
    $order_id = intval($_GET['order_id'] ?? 0);
    if (!wp_verify_nonce($_GET['nonce'] ?? '', 'gg_rm_action_' . $order_id)) wp_die('Invalid nonce');
    if (!current_user_can('manage_woocommerce')) wp_die('Unauthorized');

    // Try saved file first
    $label_path = get_post_meta($order_id, '_gg_rm_label_path', true);
    if ($label_path && file_exists($label_path)) {
        $pdf = file_get_contents($label_path);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="label-order-' . $order_id . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    // Fall back to live API fetch
    $rm_id = get_post_meta($order_id, '_gg_rm_order_id', true);
    if (!$rm_id) wp_die('No Click & Drop order ID found.');

    $response = wp_remote_get(GG_RM_API_BASE . '/orders/' . urlencode($rm_id) . '/label', [
        'timeout' => 30,
        'headers' => [
            'Authorization' => gg_rm_api_key(),
            'Accept'        => 'application/pdf',
        ],
    ]);

    if (is_wp_error($response)) wp_die('Could not fetch label: ' . $response->get_error_message());

    $raw     = wp_remote_retrieve_body($response);
    $decoded = json_decode($raw, true);
    $pdf     = ($decoded && !empty($decoded['label'])) ? base64_decode($decoded['label']) : $raw;

    // Save it for next time
    gg_rm_save_label_pdf($order_id, $pdf);

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="label-order-' . $order_id . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
});

// Admin notices
add_action('admin_notices', function() {
    $msg = $_GET['gg_rm_msg'] ?? '';
    if (!$msg) return;
    $messages = [
        'pushed'           => ['color' => 'green', 'text' => '✓ Order pushed to Click & Drop successfully.'],
        'error'            => ['color' => 'red',   'text' => '⚠ Failed to push order. Check the Royal Mail log.'],
        'tracking_fetched' => ['color' => 'blue',  'text' => '✓ Tracking fetch attempted. Refresh to see result.'],
    ];
    if (isset($messages[$msg])) {
        $m = $messages[$msg];
        echo '<div class="notice" style="border-left-color:' . $m['color'] . ';"><p>' . $m['text'] . '</p></div>';
    }
});

// ============================================================
// 13. WooCommerce ORDER ACTIONS (mobile app)
// ============================================================

add_filter('woocommerce_order_actions', 'gg_rm_register_order_action');
function gg_rm_register_order_action($actions) {
    global $theorder;
    $order_id = $theorder ? $theorder->get_id() : 0;
    $rm_id    = $order_id ? get_post_meta($order_id, '_gg_rm_order_id', true) : false;

    $actions['gg_rm_push_action'] = '📦 Push to Click & Drop';
    if ($rm_id) {
        $actions['gg_rm_print_action'] = '🖨 Print Label (Royal Mail)';
    }
    return $actions;
}

add_action('woocommerce_order_action_gg_rm_push_action', 'gg_rm_handle_push_action');
function gg_rm_handle_push_action($order) {
    $order_id  = $order->get_id();
    delete_post_meta($order_id, '_gg_rm_pushed');
    $shipping_methods = $order->get_shipping_methods();
    $method_id = '';
    if (!empty($shipping_methods)) {
        $method    = array_values($shipping_methods)[0];
        $method_id = $method->get_method_id();
    }
    gg_rm_push_order($order_id, gg_rm_is_tracked_method($method_id));
}

add_action('woocommerce_order_action_gg_rm_print_action', 'gg_rm_handle_print_action');
function gg_rm_handle_print_action($order) {
    $order_id  = $order->get_id();
    $rm_id     = get_post_meta($order_id, '_gg_rm_order_id', true);
    if (!$rm_id) return;
    $nonce     = wp_create_nonce('gg_rm_action_' . $order_id);
    $label_url = admin_url('admin-post.php?action=gg_rm_print_label&order_id=' . $order_id . '&nonce=' . $nonce);
    wp_redirect($label_url);
    exit;
}

// ============================================================
// 14. ADMIN SETTINGS PAGE
// ============================================================

add_action('admin_menu', function() {
    add_submenu_page(
        'woocommerce',
        'Royal Mail',
        '📦 Royal Mail',
        'manage_woocommerce',
        'gg-royal-mail',
        'gg_rm_admin_page'
    );
});

function gg_rm_admin_page() {
    // Save API key
    if (isset($_POST['gg_rm_save_key']) && check_admin_referer('gg_rm_save_key')) {
        $key = sanitize_text_field($_POST['gg_rm_api_key'] ?? '');
        update_option('gg_rm_api_key', $key);
        echo '<div class="notice notice-success"><p>✓ API key saved.</p></div>';
    }

    // Test connection
    $connection_result = null;
    if (isset($_POST['gg_rm_test']) && check_admin_referer('gg_rm_test')) {
        $response  = gg_rm_api_get('/version');
        $http_code = wp_remote_retrieve_response_code($response);
        $body      = wp_remote_retrieve_body($response);
        $connection_result = ($http_code === 200) ? 'success' : 'failed';
        gg_rm_log("Connection test: HTTP $http_code | Body: $body");
    }

    $log     = get_option('gg_rm_log', []);
    $api_key = get_option('gg_rm_api_key', '');

    ?>
    <div class="wrap" style="font-family: -apple-system, sans-serif;">
        <h1 style="color:#7B00FF;">📦 GrimeGames Royal Mail</h1>

        <!-- API Key Settings -->
        <div style="background:#1a1a1a; color:#fff; padding:20px; border-radius:8px; margin-bottom:20px; border:1px solid #333;">
            <h2 style="margin-top:0;">API Settings</h2>
            <form method="post">
                <?php wp_nonce_field('gg_rm_save_key'); ?>
                <table class="form-table" style="color:#fff;">
                    <tr>
                        <th style="color:#888;">Click & Drop API Key</th>
                        <td>
                            <input type="password" name="gg_rm_api_key" value="<?php echo esc_attr($api_key); ?>"
                                   style="width:400px; background:#0a0a0a; color:#fff; border:1px solid #555; padding:6px 10px; border-radius:4px;" />
                            <p style="color:#888; font-size:12px; margin-top:5px;">
                                Found in Click & Drop → Settings → Integrations → your API integration.
                            </p>
                        </td>
                    </tr>
                </table>
                <button type="submit" name="gg_rm_save_key" style="background:#7B00FF; color:#fff; padding:8px 20px; border:none; border-radius:6px; cursor:pointer;">
                    Save API Key
                </button>
            </form>

            <!-- Connection Test -->
            <div style="margin-top:20px; border-top:1px solid #333; padding-top:15px;">
                <form method="post">
                    <?php wp_nonce_field('gg_rm_test'); ?>
                    <button type="submit" name="gg_rm_test" style="background:#333; color:#fff; padding:8px 20px; border:none; border-radius:6px; cursor:pointer;">
                        Test Connection
                    </button>
                </form>
                <?php if ($connection_result === 'success'): ?>
                    <p style="color:#00cc44; margin-top:10px;">✓ Connected to Click & Drop successfully!</p>
                <?php elseif ($connection_result === 'failed'): ?>
                    <p style="color:#ff4444; margin-top:10px;">✗ Connection failed. Check your API key and see the log below.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Service Codes Lookup -->
        <div style="background:#1a1a1a; color:#fff; padding:20px; border-radius:8px; margin-bottom:20px; border:1px solid #333;">
            <h2 style="margin-top:0;">Available Service Codes <span style="font-size:13px; color:#888;">(from your OBA account)</span></h2>
            <?php
            $sc_response  = gg_rm_api_get('/servicecodes');
            $sc_http      = wp_remote_retrieve_response_code($sc_response);
            $sc_body      = json_decode(wp_remote_retrieve_body($sc_response), true);
            gg_rm_log("Service codes lookup: HTTP $sc_http | Body: " . wp_remote_retrieve_body($sc_response));

            if ($sc_http === 200 && !empty($sc_body)) {
                echo '<table style="width:100%; border-collapse:collapse;">';
                echo '<tr style="border-bottom:1px solid #333;">
                        <th style="text-align:left; padding:8px; color:#888;">Code</th>
                        <th style="text-align:left; padding:8px; color:#888;">Name</th>
                        <th style="text-align:left; padding:8px; color:#888;">Tracked</th>
                      </tr>';
                foreach ($sc_body as $sc) {
                    $code    = esc_html($sc['code'] ?? $sc['serviceCode'] ?? '—');
                    $name    = esc_html($sc['name'] ?? $sc['serviceName'] ?? '—');
                    $tracked = !empty($sc['tracked']) ? '<span style="color:#00cc44;">✓</span>' : '—';
                    echo "<tr style='border-bottom:1px solid #222;'>
                            <td style='padding:8px; color:#7B00FF; font-weight:bold; font-family:monospace;'>$code</td>
                            <td style='padding:8px;'>$name</td>
                            <td style='padding:8px;'>$tracked</td>
                          </tr>";
                }
                echo '</table>';
            } else {
                echo '<p style="color:#888;">Raw response (HTTP ' . $sc_http . '):</p>';
                echo '<pre style="background:#0a0a0a; padding:10px; border-radius:4px; color:#ccc; font-size:11px; overflow-x:auto;">' . esc_html(wp_remote_retrieve_body($sc_response)) . '</pre>';
            }
            ?>
            <p style="color:#888; font-size:12px; margin-top:10px;">Use the correct code from above in the plugin's service code constants.</p>
        </div>

        <!-- Shipping Rates -->
        <div style="background:#1a1a1a; color:#fff; padding:20px; border-radius:8px; margin-bottom:20px; border:1px solid #333;">
            <h2 style="margin-top:0;">Configured Shipping Rates</h2>
            <table style="width:100%; border-collapse:collapse;">
                <tr style="border-bottom:1px solid #333;">
                    <th style="text-align:left; padding:8px; color:#888;">Service</th>
                    <th style="text-align:left; padding:8px; color:#888;">Price</th>
                    <th style="text-align:left; padding:8px; color:#888;">Auto Label</th>
                    <th style="text-align:left; padding:8px; color:#888;">Tracking Email</th>
                </tr>
                <?php
                $rates = [
                    ['2nd Class Post',        '£0.95',  false],
                    ['2nd Class Large Letter', '£1.60',  false],
                    ['Tracked 48',             '£3.00',  true],
                    ['Tracked 24',             '£3.70',  true],
                    ['Special Delivery',       '£9.50',  true],
                ];
                foreach ($rates as [$name, $price, $tracked]): ?>
                <tr style="border-bottom:1px solid #222;">
                    <td style="padding:8px;"><?php echo $name; ?></td>
                    <td style="padding:8px;"><?php echo $price; ?></td>
                    <td style="padding:8px; color:<?php echo $tracked ? '#00cc44' : '#888'; ?>;"><?php echo $tracked ? '✓ Yes' : 'No'; ?></td>
                    <td style="padding:8px; color:<?php echo $tracked ? '#00cc44' : '#888'; ?>;"><?php echo $tracked ? '✓ Yes' : 'No'; ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <!-- Activity Log -->
        <div style="background:#1a1a1a; color:#fff; padding:20px; border-radius:8px; border:1px solid #333;">
            <h2 style="margin-top:0;">Activity Log <span style="font-size:13px; color:#888;">(last 200 entries)</span></h2>
            <?php if (empty($log)): ?>
                <p style="color:#888;">No activity yet.</p>
            <?php else: ?>
                <div style="max-height:400px; overflow-y:auto; background:#0a0a0a; padding:15px; border-radius:6px; font-family:monospace; font-size:12px;">
                    <?php foreach ($log as $entry): ?>
                        <div style="padding:3px 0; border-bottom:1px solid #1a1a1a; color:#ccc;">
                            <span style="color:#7B00FF;">[<?php echo esc_html($entry['time']); ?>]</span>
                            <?php echo esc_html($entry['message']); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <form method="post" style="margin-top:10px;">
                    <?php wp_nonce_field('gg_rm_clear_log'); ?>
                    <button type="submit" name="gg_rm_clear_log" style="background:#333; color:#fff; padding:6px 14px; border:none; border-radius:4px; cursor:pointer; font-size:12px;">
                        Clear Log
                    </button>
                </form>
            <?php endif; ?>

            <?php
            if (isset($_POST['gg_rm_clear_log']) && check_admin_referer('gg_rm_clear_log')) {
                update_option('gg_rm_log', []);
                echo '<script>location.reload();</script>';
            }
            ?>
        </div>
    </div>
    <?php
}
// ============================================================
// 15. MOBILE LABEL PRINT PAGE
// ============================================================

add_action('admin_menu', function() {
    add_submenu_page(
        'woocommerce',
        'Print Labels',
        '🖨 Print Labels',
        'manage_woocommerce',
        'gg-rm-print',
        'gg_rm_print_page'
    );
});

function gg_rm_print_page() {
    if (!current_user_can('manage_woocommerce')) wp_die('Unauthorized');

    $days = isset($_GET['days']) ? max(1, intval($_GET['days'])) : 7;

    // Get orders with a label saved in the last N days
    $orders = wc_get_orders([
        'limit'        => 100,
        'orderby'      => 'date',
        'order'        => 'DESC',
        'date_created' => '>' . (time() - ($days * DAY_IN_SECONDS)),
        'meta_key'     => '_gg_rm_label_path',
        'meta_compare' => 'EXISTS',
    ]);

    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>🖨 Print Labels — GrimeGames</title>
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body { background: #0a0a0a; color: #fff; font-family: -apple-system, sans-serif; padding: 16px; }
            h1 { color: #7B00FF; font-size: 22px; margin-bottom: 4px; }
            .subtitle { color: #666; font-size: 13px; margin-bottom: 20px; }
            .filter { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
            .filter a { padding: 6px 14px; border-radius: 20px; font-size: 13px; text-decoration: none; border: 1px solid #333; color: #aaa; }
            .filter a.active { background: #7B00FF; border-color: #7B00FF; color: #fff; }
            .order-card { background: #1a1a1a; border: 1px solid #2a2a2a; border-radius: 12px; padding: 16px; margin-bottom: 12px; }
            .order-card.has-label { border-color: #7B00FF33; }
            .order-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
            .order-num { font-size: 18px; font-weight: bold; color: #7B00FF; }
            .order-date { font-size: 12px; color: #555; }
            .order-name { font-size: 15px; color: #fff; margin-bottom: 4px; }
            .order-items { font-size: 12px; color: #888; margin-bottom: 10px; }
            .tracking { font-size: 13px; color: #00cc44; margin-bottom: 12px; font-family: monospace; }
            .no-tracking { font-size: 13px; color: #888; margin-bottom: 12px; }
            .btn { display: block; width: 100%; padding: 14px; border-radius: 10px; text-align: center; font-size: 16px; font-weight: bold; text-decoration: none; margin-bottom: 8px; }
            .btn-print { background: #7B00FF; color: #fff; }
            .btn-fetch { background: #1a1a1a; color: #aaa; border: 1px solid #333; font-size: 14px; padding: 10px; }
            .no-orders { text-align: center; padding: 40px 20px; color: #555; }
            .badge { font-size: 11px; padding: 3px 8px; border-radius: 10px; background: #00cc4422; color: #00cc44; }
            .badge.warn { background: #ffaa0022; color: #ffaa00; }
        </style>
    </head>
    <body>
        <h1>🖨 Print Labels</h1>
        <p class="subtitle">Tap to open label PDF — print or share from your browser</p>

        <div class="filter">
            <?php foreach ([1 => 'Today', 2 => '2 days', 7 => '7 days', 30 => '30 days'] as $d => $label): ?>
                <a href="?page=gg-rm-print&days=<?php echo $d; ?>" class="<?php echo $days == $d ? 'active' : ''; ?>"><?php echo $label; ?></a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($orders)): ?>
            <div class="no-orders">
                <p style="font-size:40px;margin-bottom:10px;">📭</p>
                <p>No orders with labels in the last <?php echo $days; ?> day<?php echo $days > 1 ? 's' : ''; ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($orders as $order):
                $order_id    = $order->get_id();
                $label_path  = get_post_meta($order_id, '_gg_rm_label_path', true);
                $tracking    = get_post_meta($order_id, '_gg_rm_tracking', true);
                $rm_id       = get_post_meta($order_id, '_gg_rm_order_id', true);
                $has_label   = $label_path && file_exists($label_path);
                $nonce       = wp_create_nonce('gg_rm_action_' . $order_id);
                $print_url   = admin_url('admin-post.php?action=gg_rm_print_label&order_id=' . $order_id . '&nonce=' . $nonce);
                $fetch_url   = admin_url('admin-post.php?action=gg_rm_fetch_tracking&order_id=' . $order_id . '&nonce=' . $nonce);

                // Get item summary
                $items = [];
                foreach ($order->get_items() as $item) {
                    $items[] = $item->get_quantity() . 'x ' . $item->get_name();
                }
                $items_str = implode(', ', array_slice($items, 0, 2));
                if (count($items) > 2) $items_str .= ' +' . (count($items) - 2) . ' more';
            ?>
            <div class="order-card <?php echo $has_label ? 'has-label' : ''; ?>">
                <div class="order-top">
                    <span class="order-num">#<?php echo $order->get_order_number(); ?></span>
                    <span class="order-date"><?php echo $order->get_date_created()->date('d M, H:i'); ?></span>
                </div>
                <div class="order-name"><?php echo esc_html($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()); ?></div>
                <div class="order-items"><?php echo esc_html($items_str); ?></div>

                <?php if ($tracking): ?>
                    <div class="tracking">📦 <?php echo esc_html($tracking); ?></div>
                <?php else: ?>
                    <div class="no-tracking">⏳ No tracking yet</div>
                <?php endif; ?>

                <?php if ($has_label): ?>
                    <a href="<?php echo esc_url($print_url); ?>" target="_blank" class="btn btn-print">🖨 Open Label PDF</a>
                <?php else: ?>
                    <div style="padding:12px;background:#111;border-radius:8px;text-align:center;color:#555;margin-bottom:8px;font-size:14px;">No label file saved</div>
                <?php endif; ?>

                <?php if (!$tracking && $rm_id): ?>
                    <a href="<?php echo esc_url($fetch_url); ?>" class="btn btn-fetch">↻ Fetch Tracking</a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </body>
    </html>
    <?php
}