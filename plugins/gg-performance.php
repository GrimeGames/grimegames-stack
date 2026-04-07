<?php
/*
Plugin Name: GrimeGames Performance Optimiser
Description: Strips frontend bloat for visitors — Site Kit JS, emoji, payment CSS, deferred non-critical CSS, delayed Facebook Pixel, DNS prefetch. Admin functionality preserved.
Author: GrimeGames
Version: 2.0
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
   8. FACEBOOK PIXEL — Delay loading until user interaction or 3 seconds
   Pixel fires after the page is interactive, not blocking initial render.
   ========================= */
add_action('wp_head', function() {
    if (is_admin() || current_user_can('manage_options')) return;
    ?>
    <script>
    (function() {
        var fbLoaded = false;
        function loadFBPixel() {
            if (fbLoaded) return;
            fbLoaded = true;
            !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
            n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
            n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
            t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
            document,'script','https://connect.facebook.net/en_US/fbevents.js');
            fbq('init', '1402118877864570');
            fbq('track', 'PageView');
        }
        // Load on first interaction or after 3 seconds, whichever is first
        var events = ['mouseover', 'keydown', 'touchstart', 'scroll'];
        events.forEach(function(e) {
            document.addEventListener(e, loadFBPixel, { once: true, passive: true });
        });
        setTimeout(loadFBPixel, 3000);
    })();
    </script>
    <?php
}, 1);

// Block the default FB pixel script if a plugin adds it (prevent double-loading)
add_action('wp_enqueue_scripts', function() {
    if (is_admin() || current_user_can('manage_options')) return;
    wp_dequeue_script('facebook-for-woocommerce-pixel');
    wp_dequeue_script('facebook-jssdk');
}, 999);


/* =========================
   9. DEFER NON-CRITICAL CSS
   Loads non-essential CSS asynchronously using the print/onload trick.
   Critical CSS (above-the-fold) loads normally.
   ========================= */
add_filter('style_loader_tag', function($html, $handle, $href, $media) {
    if (is_admin()) return $html;
    if (current_user_can('manage_options')) return $html;

    // CSS that can be deferred (not needed for above-fold rendering)
    $defer_styles = [
        'elementor-icons',           // Icon font CSS - only needed when icons render
        'elementor-common',          // Elementor admin-facing styles
        'elementor-pro-notes',       // Elementor notes (admin only)
        'google-site-kit',           // Site Kit admin bar styles
        'dashicons',                 // WordPress admin dashicons
        'admin-bar',                 // Admin bar (still loads for logged-in but rarely needed on paint)
        'post-views-counter-frontend', // Post views CSS
        'wc-gateway-stripe-upe-blocks', // Stripe checkout CSS
        'ppcp-local-alternative-payment-methods', // PayPal checkout CSS
        'wc-blocks-style',           // WooCommerce blocks CSS
    ];

    foreach ($defer_styles as $pattern) {
        if (strpos($handle, $pattern) !== false) {
            // Replace with async loading: loads as print media (non-blocking), then switches to all
            $html = str_replace(
                "media='all'",
                "media='print' onload=\"this.media='all'\"",
                $html
            );
            // Also handle cases where media attribute isn't 'all'
            if (strpos($html, "onload") === false) {
                $html = str_replace(
                    '/>',
                    "media='print' onload=\"this.media='all'\" />",
                    $html
                );
            }
            break;
        }
    }

    return $html;
}, 10, 4);


/* =========================
   10. DNS PREFETCH + PRECONNECT for third-party origins
   Reduces connection setup time for external resources.
   ========================= */
add_action('wp_head', function() {
    if (is_admin()) return;
    echo '<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>' . "\n";
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
    echo '<link rel="dns-prefetch" href="//www.googletagmanager.com">' . "\n";
    echo '<link rel="dns-prefetch" href="//connect.facebook.net">' . "\n";
}, 1);


/* =========================
   11. ADD DEFER/ASYNC to non-critical scripts
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
