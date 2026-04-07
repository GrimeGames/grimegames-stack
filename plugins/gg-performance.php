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
        wp_deregister_style('wc-stripe-blocks-checkout-style');
        wp_dequeue_style('stripelink_styles');
        wp_deregister_style('stripelink_styles');
        wp_dequeue_style('wc_stripe_express_checkout_style');
        wp_deregister_style('wc_stripe_express_checkout_style');
        wp_dequeue_style('wc-stripe-upe-classic');
        wp_deregister_style('wc-stripe-upe-classic');
        wp_dequeue_style('ppcp-pwc-payment-method');
        wp_deregister_style('ppcp-pwc-payment-method');
        wp_dequeue_style('wc-blocks-style');
        wp_deregister_style('wc-blocks-style');
        wp_dequeue_style('gateway');
        wp_deregister_style('gateway');

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
}, 9999);

// Fallback: deregister payment CSS to prevent re-enqueuing by gateway plugins
add_action('wp_print_styles', function() {
    if (is_admin()) return;
    $is_payment_page = (function_exists('is_checkout') && is_checkout())
                    || (function_exists('is_cart') && is_cart());
    if (!$is_payment_page) {
        $kill_css = [
            'wc-stripe-blocks-checkout-style', 'stripelink_styles',
            'wc_stripe_express_checkout_style', 'wc-stripe-upe-classic',
            'ppcp-pwc-payment-method', 'wc-blocks-style', 'gateway',
        ];
        foreach ($kill_css as $handle) {
            wp_dequeue_style($handle);
            wp_deregister_style($handle);
        }
    }
}, 9999);

// Fallback: also dequeue payment/React JS right before footer output
add_action('wp_print_footer_scripts', function() {
    if (is_admin()) return;
    $is_payment_page = (function_exists('is_checkout') && is_checkout())
                    || (function_exists('is_cart') && is_cart());
    if ($is_payment_page) return;

    $kill = [
        'stripe', 'wc-stripe-upe-classic', 'wc_stripe_express_checkout',
        'ppcp-smart-button', 'ppcp-fraudnet', 'wcpay-frontend-tracks',
        'react', 'react-dom', 'react-jsx-runtime', 'lodash',
        'wp-data', 'wp-element', 'wp-compose', 'wp-deprecated', 'wp-dom',
        'wp-dom-ready', 'wp-escape-html', 'wp-html-entities',
        'wp-is-shallow-equal', 'wp-keycodes', 'wp-polyfill',
        'wp-priority-queue', 'wp-private-apis', 'wp-redux-routine',
        'wp-url', 'wp-api-fetch',
    ];
    foreach ($kill as $handle) {
        wp_dequeue_script($handle);
    }
}, 1);


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
}, 9999);


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


/* =========================
   17. AVIF HTML REWRITING
   LiteSpeed serves static files directly, bypassing .htaccess rewrites.
   Instead, rewrite image URLs in the HTML output to point to .avif files
   when the browser supports AVIF and the .avif file exists on disk.
   ========================= */
add_action('template_redirect', function() {
    if (is_admin()) return;
    // Only rewrite if browser sends Accept: image/avif
    if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'image/avif') === false) return;

    ob_start(function($html) {
        if (empty($html)) return $html;

        // Rewrite src="...uploads/...jpg" and srcset entries to .avif
        $html = preg_replace_callback(
            '#((?:src|srcset)\s*=\s*["\'])([^"\']*?/wp-content/uploads/[^"\']*?)\.(jpe?g|png|gif|webp)(["\'\s,])#i',
            function($matches) {
                $prefix    = $matches[1];
                $path      = $matches[2];
                $ext       = $matches[3];
                $suffix    = $matches[4];

                // Build filesystem path to check if .avif exists
                $rel_path  = preg_replace('#^https?://[^/]+#', '', $path);
                $avif_file = ABSPATH . ltrim($rel_path, '/') . '.avif';

                if (file_exists($avif_file)) {
                    return $prefix . $path . '.avif' . $suffix;
                }
                return $matches[0];
            },
            $html
        );

        // === STRIP DEFERRED PRODUCTS FROM HTML ===
        // Remove products with data-deferred="true" from the server-rendered HTML.
        // These will be loaded via AJAX from /wp-json/gg/v1/products instead.
        // This reduces the Singles page from 11.6MB / 235K DOM nodes to ~600KB / 15K nodes.
        if (strpos($html, 'data-deferred="true"') !== false) {
            // Remove all <li> elements with data-deferred="true"
            $html = preg_replace(
                '/<li\b[^>]*data-deferred="true"[^>]*>.*?<\/li>/s',
                '',
                $html
            );

            // Update the product count display (currently shows "48 products")
            // We'll update it via JS once all products are loaded

            // Inject the AJAX lazy loader script before </body>
            $loader_script = '
<script id="gg-lazy-loader">
(function() {
    var grid = document.querySelector("ul.products");
    if (!grid) return;

    var sentinel = document.getElementById("gg-sentinel");
    var countEl = null;
    // Find the product count display
    document.querySelectorAll("*").forEach(function(el) {
        if (el.childNodes.length === 1 && el.textContent.match(/^\d+ products$/)) {
            countEl = el;
        }
    });

    var category = "singles";
    // Detect category from body classes or URL
    if (window.location.pathname.includes("rarity-collection")) category = "rc05";
    if (window.location.pathname.includes("blazing-dominion")) category = "blazing-dominion";
    if (window.location.pathname.includes("burst-protocol")) category = "burst-protocol";

    // Fetch all products from API
    fetch("/wp-json/gg/v1/products?category=" + category + "&limit=1000", {
        headers: {"Accept": "application/json, image/avif"}
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.products) return;

        // Get IDs of products already in the DOM (the initial 48)
        var existingIds = new Set();
        grid.querySelectorAll("li[data-rarity]").forEach(function(li) {
            var cartBtn = li.querySelector("[data-product_id]");
            if (cartBtn) existingIds.add(parseInt(cartBtn.getAttribute("data-product_id")));
        });

        // Build and append remaining products
        var fragment = document.createDocumentFragment();
        var added = 0;
        data.products.forEach(function(p) {
            if (existingIds.has(p.id)) return;

            var li = document.createElement("li");
            li.className = "ast-article-single product type-product instock product_cat-singles has-post-thumbnail purchasable product-type-simple";
            li.setAttribute("data-set", p.set || "");
            li.setAttribute("data-rarity", p.rarity || "");
            li.setAttribute("data-price", p.price || "0");
            li.setAttribute("data-deferred", "true");
            li.style.display = "none";

            li.innerHTML = \'<div class="astra-shop-thumbnail-wrap">\' +
                \'<a href="\' + p.url + \'" class="woocommerce-LoopProduct-link woocommerce-loop-product__link">\' +
                \'<img loading="lazy" decoding="async" width="300" height="300" src="\' + p.img + \'" class="attachment-woocommerce_thumbnail size-woocommerce_thumbnail" alt="\' + p.name.replace(/"/g, "&quot;") + \'">\' +
                \'</a></div>\' +
                \'<div class="astra-shop-summary-wrap">\' +
                \'<a href="\' + p.url + \'" class="ast-loop-product__link"><h2 class="woocommerce-loop-product__title">\' + p.name + \'</h2></a>\' +
                \'<span class="price">\' + p.display + \'</span>\' +
                \'<a href="?add-to-cart=\' + p.id + \'" data-quantity="1" class="ast-on-card-button ast-select-options-trigger product_type_simple add_to_cart_button ajax_add_to_cart" data-product_id="\' + p.id + \'" data-product_sku="\' + (p.sku || "") + \'" rel="nofollow">Add to basket</a>\' +
                \'</div>\';

            fragment.appendChild(li);
            added++;
        });

        grid.appendChild(fragment);

        // Update count
        if (countEl) {
            countEl.textContent = data.total + " products";
        }

        // Re-initialize progressive rendering if ggResetProgressiveRender exists
        if (typeof ggResetProgressiveRender === "function") {
            ggResetProgressiveRender();
        }

        console.log("GG Lazy Loader: added " + added + " products via API (" + data.total + " total)");
    })
    .catch(function(err) {
        console.error("GG Lazy Loader error:", err);
    });
})();
</script>';

            $html = str_replace('</body>', $loader_script . '</body>', $html);
        }

        return $html;
    });
}, 1);
