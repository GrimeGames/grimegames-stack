<?php
/**
 * Plugin Name: GrimeGames Anti-Bot Protection
 * Description: Honeypot + rate limiting protection for WooCommerce checkout (replaces reCAPTCHA)
 * Version: 1.0
 * Author: GrimeGames
 */

defined('ABSPATH') || exit;

// Add invisible honeypot field to checkout
add_action('woocommerce_after_order_notes', 'gg_add_checkout_honeypot_field');
function gg_add_checkout_honeypot_field($checkout) {
    echo '<div class="gg-honeypot" style="position:absolute;left:-9999px;opacity:0;pointer-events:none;" aria-hidden="true">';
    woocommerce_form_field('website_url', array(
        'type' => 'text',
        'class' => array('form-row-wide'),
        'label' => 'Website',
        'placeholder' => 'Your website',
        'required' => false,
    ), $checkout->get_value('website_url'));
    echo '</div>';
}

// Validate honeypot - block if filled (bots fill all fields)
add_action('woocommerce_checkout_process', 'gg_validate_checkout_honeypot');
function gg_validate_checkout_honeypot() {
    if (!empty($_POST['website_url'])) {
        // Bot detected! Honeypot was filled
        wc_add_notice(__('Error processing checkout. Please try again.'), 'error');
        
        // Log the attempt (optional)
        error_log('GG Anti-Bot: Bot blocked at checkout - IP: ' . $_SERVER['REMOTE_ADDR']);
    }
}

// Rate limiting - max 5 checkout attempts per IP per hour
add_action('woocommerce_checkout_process', 'gg_checkout_rate_limit');
function gg_checkout_rate_limit() {
    $ip = $_SERVER['REMOTE_ADDR'];
    $transient_key = 'gg_checkout_attempts_' . md5($ip);
    
    $attempts = get_transient($transient_key);
    
    if ($attempts === false) {
        // First attempt
        set_transient($transient_key, 1, HOUR_IN_SECONDS);
    } else {
        if ($attempts >= 5) {
            // Too many attempts
            wc_add_notice(__('Too many checkout attempts. Please try again in an hour.'), 'error');
            error_log('GG Anti-Bot: Rate limit hit - IP: ' . $ip);
            return;
        }
        set_transient($transient_key, $attempts + 1, HOUR_IN_SECONDS);
    }
}

// Block known bot user agents
add_action('init', 'gg_block_bot_user_agents');
function gg_block_bot_user_agents() {
    if (!is_admin() && (is_checkout() || is_cart())) {
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        
        $bot_patterns = array(
            'bot', 'crawl', 'spider', 'scrape', 'curl', 'wget', 'python', 'java',
        );
        
        foreach ($bot_patterns as $pattern) {
            if (stripos($user_agent, $pattern) !== false) {
                error_log('GG Anti-Bot: Bot blocked - User Agent: ' . $user_agent . ' - IP: ' . $_SERVER['REMOTE_ADDR']);
                wp_die('Access denied', 'Forbidden', array('response' => 403));
            }
        }
    }
}

// Admin notice on plugin activation
register_activation_hook(__FILE__, 'gg_anti_bot_activation');
function gg_anti_bot_activation() {
    set_transient('gg_anti_bot_activated', true, 30);
}

add_action('admin_notices', 'gg_anti_bot_activation_notice');
function gg_anti_bot_activation_notice() {
    if (get_transient('gg_anti_bot_activated')) {
        delete_transient('gg_anti_bot_activated');
        ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>GrimeGames Anti-Bot Protection activated!</strong> Your checkout is now protected with honeypot + rate limiting. Make sure to disable any reCAPTCHA on the checkout page.</p>
        </div>
        <?php
    }
}
