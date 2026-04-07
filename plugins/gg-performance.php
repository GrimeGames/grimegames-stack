<?php
/*
Plugin Name: GrimeGames Performance Optimiser
Description: Strips frontend bloat for visitors — Site Kit JS, emoji, payment CSS/JS, WP block editor packages, deferred non-critical CSS, delayed Facebook Pixel, DNS prefetch. Admin functionality preserved. Debug at ?gg_perf_debug=1
Author: GrimeGames
Version: 3.0
*/

defined('ABSPATH') || exit;


/* =========================
   1. GOOGLE SITE KIT — Block frontend JS for non-admin users
   ========================= */
add_action('wp_enqueue_scripts', function() {
    if (is_admin()) return;
    if (current_user_can('manage_options')) return;

    global $wp_scripts;
    if (!$wp_scripts) return;

    foreach ($wp_scripts->registered as $handle => $script) {
        if ($script->src && strpos($script->src, 'google-site-kit') !== false) {
            wp_dequeue_script($handle);
            wp_deregister_script($handle);
        }
    }

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
   3. PAYMENT GATEWAY CSS + JS — Only load on checkout/cart pages
   Exact handle names from live site audit 2026-04-07.
   ========================= */
add_action('wp_enqueue_scripts', function() {
    if (is_admin()) return;

    $is_payment_page = (function_exists('is_checkout') && is_checkout())
                    || (function_exists('is_cart') && is_cart());

    if (!$is_payment_page) {
        // === PAYMENT CSS (exact handles from live audit) ===
        wp_dequeue_style('wc-stripe-blocks-checkout-style');
        wp_dequeue_style('stripelink_styles');
        wp_dequeue_style('wc_stripe_express_checkout_style');
        wp_dequeue_style('wc-stripe-upe-classic');
        wp_dequeue_style('ppcp-pwc-payment-method');
        wp_dequeue_style('wc-blocks-style');
        wp_dequeue_style('gateway');

        // === PAYMENT JS ===
        wp_dequeue_script('stripe');
        wp_dequeue_script('wc-stripe-upe-classic');
        wp_dequeue_script('wc_stripe_express_checkout');
        wp_dequeue_script('ppcp-smart-button');
        wp_dequeue_script('ppcp-fraudnet');
        wp_dequeue_script('wcpay-frontend-tracks');

        // === WP BLOCK EDITOR PACKAGES (React tree for payment buttons) ===
        wp_dequeue_script('react');
        wp_dequeue_script('react-dom');
        wp_dequeue_script('react-jsx-runtime');
        wp_dequeue_script('lodash');
        wp_dequeue_script('wp-data');
        wp_dequeue_script('wp-element');
        wp_dequeue_script('wp-compose');
        wp_dequeue_script('wp-deprecated');
        wp_dequeue_script('wp-dom');
        wp_dequeue_script('wp-dom-ready');
        wp_dequeue_script('wp-escape-html');
        wp_dequeue_script('wp-html-entities');
        wp_dequeue_script('wp-is-shallow-equal');
        wp_dequeue_script('wp-keycodes');
        wp_dequeue_script('wp-polyfill');
        wp_dequeue_script('wp-priority-queue');
        wp_dequeue_script('wp-private-apis');
        wp_dequeue_script('wp-redux-routine');
        wp_dequeue_script('wp-url');
        wp_dequeue_script('wp-api-fetch');
    }

    // Strip Stripe Link CSS from My Account too
    if (function_exists('is_account_page') && is_account_page()) {
        wp_dequeue_style('stripelink_styles');
        wp_dequeue_style('wc_stripe_express_checkout_style');
    }
}, 100);


/* =========================
   4. STRIP WC CHECKOUT JS FROM NON-CHECKOUT PAGES
   ========================= */
add_action('wp_enqueue_scripts', function() {
    if (is_admin()) return;
    if (function_exists('is_checkout') && is_checkout()) return;

    wp_dequeue_script('wc-checkout');
    wp_dequeue_script('wc-address-i18n');
    wp_dequeue_script('wc-country-select');
    wp_dequeue_script('wc-custom-place-order-button');
}, 100);


/* =========================
   5. POST VIEWS COUNTER — Only load CSS on homepage
   ========================= */
add_action('wp_enqueue_scripts', function() {
    if (is_admin()) return;
    if (!is_front_page() && !is_home()) {
        wp_dequeue_style('post-views-counter-frontend');
    }
}, 100);


/* =========================
   6. ELEMENTOR — Disable frontend notes module (admin-only)
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
   7. JQUERY MIGRATE — Not needed with jQuery 3.7
   ========================= */
add_action('wp_default_scripts', function($scripts) {
    if (is_admin()) return;
    if (isset($scripts->registered['jquery'])) {
        $deps = $scripts->registered['jquery']->deps;
        $scripts->registered['jquery']->deps = array_diff($deps, ['jquery-migrate']);
    }
});


/* =========================
   8. WPFORMS LITE + WP MAIL SMTP — Remove admin bar CSS
   ========================= */
add_action('wp_enqueue_scripts', function() {
    if (is_admin()) return;
    if (!current_user_can('manage_options')) {
        wp_dequeue_style('wpforms-admin-bar');
        wp_dequeue_style('wp-mail-smtp-admin-bar');
    }
}, 100);


/* =========================
   9. FACEBOOK PIXEL — Delay until interaction or 3 seconds
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
        var events = ['mouseover', 'keydown', 'touchstart', 'scroll'];
        events.forEach(function(e) {
            document.addEventListener(e, loadFBPixel, { once: true, passive: true });
        });
        setTimeout(loadFBPixel, 3000);
    })();
    </script>
    <?php
}, 1);

add_action('wp_enqueue_scripts', function() {
    if (is_admin() || current_user_can('manage_options')) return;
    wp_dequeue_script('facebook-for-woocommerce-pixel');
    wp_dequeue_script('facebook-jssdk');
}, 999);


/* =========================
   10. DEFER NON-CRITICAL CSS
   ========================= */
add_filter('style_loader_tag', function($html, $handle, $href, $media) {
    if (is_admin()) return $html;
    if (current_user_can('manage_options')) return $html;

    $defer_styles = [
        'elementor-icons',
        'elementor-common',
        'elementor-pro-notes',
        'google-site-kit',
        'dashicons',
        'post-views-counter-frontend',
        'wc-blocks-style',
        'photoswipe',
        'photoswipe-default-skin',
        'wp-block-library',
    ];

    foreach ($defer_styles as $pattern) {
        if (strpos($handle, $pattern) !== false) {
            $html = str_replace(
                "media='all'",
                "media='print' onload=\"this.media='all'\"",
                $html
            );
            if (strpos($html, 'onload') === false) {
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
   11. DNS PREFETCH + PRECONNECT
   ========================= */
add_action('wp_head', function() {
    if (is_admin()) return;
    echo '<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>' . "\n";
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
    echo '<link rel="dns-prefetch" href="//www.googletagmanager.com">' . "\n";
    echo '<link rel="dns-prefetch" href="//connect.facebook.net">' . "\n";
}, 1);


/* =========================
   12. ADD DEFER TO NON-CRITICAL SCRIPTS
   ========================= */
add_filter('script_loader_tag', function($tag, $handle, $src) {
    if (is_admin()) return $tag;

    // Critical — tell Rocket Loader not to defer
    $critical = ['jquery-core', 'woocommerce', 'wc-add-to-cart', 'wc-cart-fragments'];
    if (in_array($handle, $critical)) {
        $tag = str_replace('<script ', '<script data-cfasync="false" ', $tag);
        return $tag;
    }

    // Deferrable
    $deferrable = [
        'astra-theme-js',
        'astra-mobile-cart',
        'sourcebuster-js',
        'wc-order-attribution',
        'comment-reply',
        'wp-hooks',
        'wp-i18n',
    ];
    if (in_array($handle, $deferrable) && strpos($tag, 'defer') === false) {
        $tag = str_replace('<script ', '<script defer ', $tag);
    }

    return $tag;
}, 10, 3);


/* =========================
   13. REMOVE CART FRAGMENTS FROM NON-SHOP PAGES
   ========================= */
add_action('wp_enqueue_scripts', function() {
    if (is_admin()) return;
    if (is_shop() || is_product() || is_product_category() || is_product_tag()) return;
    if (function_exists('is_cart') && is_cart()) return;
    if (function_exists('is_checkout') && is_checkout()) return;
    if (is_front_page() || is_home()) return;

    wp_dequeue_script('wc-cart-fragments');
}, 100);


/* =========================
   14. REMOVE WP BLOCK LIBRARY CSS
   Elementor site doesn't use Gutenberg blocks on frontend.
   ========================= */
add_action('wp_enqueue_scripts', function() {
    if (is_admin()) return;
    wp_dequeue_style('wp-block-library');
    wp_dequeue_style('wp-block-library-theme');
    wp_dequeue_style('wc-blocks-vendors-style');
    wp_dequeue_style('global-styles');
}, 100);


/* =========================
   15. REMOVE COMMENT-REPLY JS
   ========================= */
add_action('wp_enqueue_scripts', function() {
    if (is_admin()) return;
    if (!is_singular() || !comments_open()) {
        wp_dequeue_script('comment-reply');
    }
}, 100);


/* =========================
   16. DISABLE SOURCEBUSTER + ORDER ATTRIBUTION on non-checkout
   ========================= */
add_action('wp_enqueue_scripts', function() {
    if (is_admin()) return;
    if (function_exists('is_checkout') && is_checkout()) return;

    wp_dequeue_script('sourcebuster-js');
    wp_dequeue_script('wc-order-attribution');
}, 100);


/* =========================
   DEBUG: Performance audit when ?gg_perf_debug=1
   ========================= */
add_action('wp_footer', function() {
    if (!isset($_GET['gg_perf_debug']) || !current_user_can('manage_options')) return;

    global $wp_scripts, $wp_styles;
    $scripts_list = [];
    $styles_list = [];

    if ($wp_scripts) {
        foreach ($wp_scripts->done as $handle) {
            $src = isset($wp_scripts->registered[$handle]) ? $wp_scripts->registered[$handle]->src : '(inline)';
            $scripts_list[] = "{$handle}: {$src}";
        }
    }
    if ($wp_styles) {
        foreach ($wp_styles->done as $handle) {
            $src = isset($wp_styles->registered[$handle]) ? $wp_styles->registered[$handle]->src : '(inline)';
            $styles_list[] = "{$handle}: {$src}";
        }
    }

    $sc = count($scripts_list);
    $stc = count($styles_list);

    echo '<div style="position:fixed;bottom:0;left:0;right:0;background:#1a1a2e;color:#0f0;font-family:monospace;font-size:11px;padding:15px;z-index:99999;max-height:400px;overflow:auto;border-top:2px solid #e94560;">';
    echo '<strong style="color:#e94560;">⚡ GG Performance v3.0 Debug</strong><br>';
    echo "Scripts loaded: {$sc} | Styles loaded: {$stc}<br>";
    echo '<details><summary style="color:#fff;cursor:pointer;">Scripts (' . $sc . ')</summary>';
    foreach ($scripts_list as $s) echo esc_html($s) . '<br>';
    echo '</details>';
    echo '<details><summary style="color:#fff;cursor:pointer;">Styles (' . $stc . ')</summary>';
    foreach ($styles_list as $s) echo esc_html($s) . '<br>';
    echo '</details>';
    echo '</div>';
}, 9999);
