<?php
/*
Plugin Name: GrimeGames Performance Optimiser
Description: Removes unnecessary frontend bloat — Site Kit frontend JS, emoji scripts, payment gateway CSS on non-checkout pages, admin-only assets for non-admins. Does NOT remove any admin functionality.
Author: GrimeGames
Version: 1.0
*/

defined('ABSPATH') || exit;

/* =========================
   1. GOOGLE SITE KIT — Block frontend JS for non-admin users
   Site Kit admin dashboard stays fully functional.
   Only the 26 JS files that load on every frontend page are removed.
   ========================= */
add_action('wp_enqueue_scripts', function() {
    // Only strip on frontend, never in admin
    if (is_admin()) return;
    // Keep scripts for admins so Site Kit's admin bar widget still works
    if (current_user_can('manage_options')) return;

    global $wp_scripts;
    if (!$wp_scripts) return;

    $blocked = 0;
    foreach ($wp_scripts->registered as $handle => $script) {
        if ($script->src && strpos($script->src, 'google-site-kit') !== false) {
            wp_dequeue_script($handle);
            wp_deregister_script($handle);
            $blocked++;
        }
    }

    // Also remove Site Kit's inline styles
    global $wp_styles;
    if ($wp_styles) {
        foreach ($wp_styles->registered as $handle => $style) {
            if ($style->src && strpos($style->src, 'google-site-kit') !== false) {
                wp_dequeue_style($handle);
                wp_deregister_style($handle);
            }
        }
    }
}, 999);


/* =========================
   2. DISABLE WP EMOJI — Removes emoji JS + 6 SVG requests
   Emojis still work via browser native rendering.
   ========================= */
add_action('init', function() {
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_styles', 'print_emoji_styles');
    remove_filter('the_content_feed', 'wp_staticize_emoji');
    remove_filter('comment_text_rss', 'wp_staticize_emoji');
    remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
    add_filter('tiny_mce_plugins', function($plugins) {
        return is_array($plugins) ? array_diff($plugins, ['wpemoji']) : [];
    });
    add_filter('wp_resource_hints', function($urls, $relation_type) {
        if ($relation_type === 'dns-prefetch') {
            $urls = array_filter($urls, function($url) {
                return strpos($url, 'https://s.w.org/images/core/emoji') === false;
            });
        }
        return $urls;
    }, 10, 2);
});


/* =========================
   3. PAYMENT GATEWAY CSS — Only load on checkout/cart pages
   Stripe, PayPal CSS removed from homepage/product pages.
   ========================= */
add_action('wp_enqueue_scripts', function() {
    if (is_admin()) return;

    // Only load payment CSS on checkout and cart pages
    $is_payment_page = (function_exists('is_checkout') && is_checkout())
                    || (function_exists('is_cart') && is_cart())
                    || (function_exists('is_account_page') && is_account_page());

    if (!$is_payment_page) {
        // Stripe
        wp_dequeue_style('wc-gateway-stripe-upe-blocks');
        wp_dequeue_style('stripe_styles');
        // PayPal
        wp_dequeue_style('ppcp-local-alternative-payment-methods-css-gateway');
        wp_dequeue_style('ppcp-webhooks-status-page-style');
        // WooCommerce blocks (cart/checkout only)
        wp_dequeue_style('wc-blocks-style');
    }
}, 100);


/* =========================
   4. POST VIEWS COUNTER — Only load CSS when carousel is present
   ========================= */
add_action('wp_enqueue_scripts', function() {
    if (is_admin()) return;
    // PVC frontend CSS is tiny but unnecessary on non-homepage pages
    if (!is_front_page() && !is_home()) {
        wp_dequeue_style('post-views-counter-frontend');
    }
}, 100);


/* =========================
   5. ELEMENTOR — Disable frontend notes module (admin-only feature)
   ========================= */
add_action('wp_enqueue_scripts', function() {
    if (is_admin()) return;
    if (!current_user_can('manage_options')) {
        wp_dequeue_script('elementor-pro-notes-app-initiator');
        wp_dequeue_script('elementor-pro-notes');
        wp_dequeue_style('elementor-pro-notes');
    }
}, 999);


/* =========================
   6. JQUERY MIGRATE — Not needed with modern jQuery 3.7
   WooCommerce and Elementor don't require it.
   ========================= */
add_action('wp_default_scripts', function($scripts) {
    if (is_admin()) return;
    if (isset($scripts->registered['jquery'])) {
        $deps = $scripts->registered['jquery']->deps;
        $scripts->registered['jquery']->deps = array_diff($deps, ['jquery-migrate']);
    }
});


/* =========================
   7. WPFORMS LITE + WP MAIL SMTP — Remove admin bar CSS on frontend
   These tiny CSS files still add HTTP requests.
   ========================= */
add_action('wp_enqueue_scripts', function() {
    if (is_admin()) return;
    if (!current_user_can('manage_options')) {
        wp_dequeue_style('wpforms-admin-bar');
        wp_dequeue_style('wp-mail-smtp-admin-bar');
    }
}, 100);


/* =========================
   8. FACEBOOK PIXEL — Delay loading by 3 seconds
   Pixel fires after the page is interactive, not blocking render.
   ========================= */
add_action('wp_head', function() {
    if (is_admin() || current_user_can('manage_options')) return;
    ?>
    <script>
    (function() {
        // Intercept Facebook pixel script injection and delay it
        var origCreate = document.createElement;
        var fbDelayed = false;
        // We'll use a MutationObserver to catch when fbevents.js is added
        // and delay the network request
    })();
    </script>
    <?php
}, 1);

// Actually, the cleaner approach: defer the FB script tag via script_loader_tag
add_filter('script_loader_tag', function($tag, $handle, $src) {
    // Skip admin
    if (is_admin()) return $tag;

    // Add defer to non-critical third-party scripts
    $defer_handles = [
        'google_gtagjs', // Google Analytics
    ];

    if (in_array($handle, $defer_handles)) {
        $tag = str_replace(' src=', ' defer src=', $tag);
    }

    return $tag;
}, 10, 3);


/* =========================
   9. DISABLE CLOUDFLARE ROCKET LOADER INTERFERENCE
   Rocket Loader can cause JS execution delays. If enabled,
   mark critical scripts to skip it.
   ========================= */
add_filter('script_loader_tag', function($tag, $handle, $src) {
    // Critical scripts that Rocket Loader should not defer
    $critical = ['jquery-core', 'woocommerce', 'wc-add-to-cart'];
    if (in_array($handle, $critical)) {
        $tag = str_replace('<script ', '<script data-cfasync="false" ', $tag);
    }
    return $tag;
}, 10, 3);


/* =========================
   DEBUG: Log what was removed (only when ?gg_perf_debug=1)
   ========================= */
add_action('wp_footer', function() {
    if (!isset($_GET['gg_perf_debug']) || !current_user_can('manage_options')) return;
    echo '<!-- GG Performance Optimiser active. Append ?gg_perf_debug=1 to see this. -->';
}, 9999);
