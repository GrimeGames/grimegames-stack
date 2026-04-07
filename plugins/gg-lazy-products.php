<?php
/*
Plugin Name: GrimeGames Lazy Products
Description: REST API endpoint for AJAX product loading on category pages. Returns lightweight JSON for client-side card rendering. Replaces server-side rendering of 800+ product cards.
Author: GrimeGames
Version: 1.0
*/

defined('ABSPATH') || exit;

/* =========================
   REST API ENDPOINT: /wp-json/gg/v1/products
   Returns lightweight JSON array of all products in a category.
   Used by the Singles/RC5 pages to load products via AJAX after initial 48.
   ========================= */
add_action('rest_api_init', function() {
    register_rest_route('gg/v1', '/products', [
        'methods'             => 'GET',
        'callback'            => 'gg_lazy_products_endpoint',
        'permission_callback' => '__return_true',
        'args' => [
            'category' => [
                'required' => false,
                'type'     => 'string',
                'default'  => 'singles',
            ],
            'offset' => [
                'required' => false,
                'type'     => 'integer',
                'default'  => 0,
            ],
            'limit' => [
                'required' => false,
                'type'     => 'integer',
                'default'  => 1000,
            ],
        ],
    ]);
});

function gg_lazy_products_endpoint($request) {
    $category = sanitize_text_field($request->get_param('category'));
    $offset   = (int) $request->get_param('offset');
    $limit    = (int) $request->get_param('limit');

    // Query products
    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'offset'         => $offset,
        'orderby'        => 'meta_value_num',
        'meta_key'       => 'total_sales',
        'order'          => 'DESC',
        'fields'         => 'ids', // Just IDs for performance
    ];

    // Add category filter
    if ($category) {
        $args['tax_query'] = [[
            'taxonomy' => 'product_cat',
            'field'    => 'slug',
            'terms'    => $category,
        ]];
    }

    $query = new WP_Query($args);
    $products = [];

    foreach ($query->posts as $product_id) {
        $product = wc_get_product($product_id);
        if (!$product) continue;

        // Get set code from SKU (e.g. "RA05-EN070 Starlight" -> "RA05")
        $sku = $product->get_sku();
        $set_code = '';
        if (preg_match('/^([A-Za-z0-9]{2,6})-/', $sku, $m)) {
            $set_code = strtoupper($m[1]);
        }

        // Get rarity from product categories or title
        $rarity = '';
        $rarity_terms = wp_get_post_terms($product_id, 'pa_rarity', ['fields' => 'names']);
        if (!is_wp_error($rarity_terms) && !empty($rarity_terms)) {
            $rarity = $rarity_terms[0];
        }
        if (empty($rarity)) {
            $name = $product->get_name();
            $patterns = [
                'Quarter Century Secret Rare', 'Prismatic Secret Rare',
                'Platinum Secret Rare', "Collector's Rare", 'Collectors Rare',
                'Starlight Rare', 'Ultimate Rare', 'Secret Rare',
                'Ultra Rare', 'Super Rare', 'Common', 'Rare',
            ];
            foreach ($patterns as $r) {
                if (stripos($name, $r) !== false) {
                    $rarity = $r;
                    break;
                }
            }
        }

        // Get thumbnail URL
        $thumb_id = $product->get_image_id();
        $thumb_url = '';
        if ($thumb_id) {
            $img_data = wp_get_attachment_image_src($thumb_id, 'woocommerce_thumbnail');
            if ($img_data) {
                $thumb_url = $img_data[0];
                // Check for AVIF version
                if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'image/avif') !== false) {
                    $avif_path = preg_replace('/\.(jpe?g|png|gif|webp)$/i', '.avif', ABSPATH . ltrim(preg_replace('#^https?://[^/]+#', '', $img_data[0]), '/'));
                    if (file_exists($avif_path)) {
                        $thumb_url = preg_replace('/\.(jpe?g|png|gif|webp)$/i', '.avif', $img_data[0]);
                    }
                }
            }
        }

        $products[] = [
            'id'      => $product_id,
            'name'    => $product->get_name(),
            'url'     => get_permalink($product_id),
            'img'     => $thumb_url,
            'price'   => $product->get_price(),
            'display' => $product->get_price_html(),
            'sku'     => $sku,
            'set'     => $set_code,
            'rarity'  => $rarity,
            'stock'   => $product->get_stock_quantity(),
            'instock' => $product->is_in_stock(),
        ];
    }

    return new WP_REST_Response([
        'total'    => $query->found_posts,
        'count'    => count($products),
        'offset'   => $offset,
        'products' => $products,
    ], 200);
}


/* =========================
   LIMIT INITIAL PRODUCTS ON SINGLES/CATEGORY PAGES
   Intercepts the WooCommerce product query on heavy pages
   to only render the first 48 products server-side.
   The rest are loaded via AJAX from /wp-json/gg/v1/products.
   ========================= */
add_action('pre_get_posts', function($query) {
    // Only on frontend, main query, for our specific heavy pages
    if (is_admin() || !$query->is_main_query()) return;

    // Identify pages that need lazy loading
    $lazy_pages = [332, 10289]; // Singles page, RC5 page
    
    // Also apply to product_cat archives with many products
    if ($query->is_tax('product_cat')) {
        // Will be handled below
    } elseif (!is_page($lazy_pages)) {
        return;
    }

    // Limit to 48 products for initial server render
    $query->set('posts_per_page', 48);
});

// For Elementor pages using [products] shortcode, hook into the shortcode query
add_filter('woocommerce_shortcode_products_query', function($query_args, $atts, $type) {
    // Check if we're on a page that should lazy-load
    if (is_page([332, 10289])) { // Singles + RC5
        $query_args['posts_per_page'] = 48;
    }
    return $query_args;
}, 10, 3);

// Also limit the Astra shop loop if it's rendering all products
add_filter('loop_shop_per_page', function($cols) {
    // Only limit on the specific heavy pages
    if (is_page([332, 10289])) {
        return 48;
    }
    return $cols;
});


/* =========================
   INJECT AJAX LOADER SCRIPT
   Adds the lazy loading JavaScript to pages with product grids.
   ========================= */
add_action('wp_footer', function() {
    if (is_admin()) return;
    // Only inject on pages that have lazy-loaded products
    if (!is_page([332, 10289]) && !is_product_category()) return;
    
    $category = 'singles'; // default
    if (is_page()) {
        $page_id = get_the_ID();
        if ($page_id === 332) $category = 'singles';
        elseif ($page_id === 10289) $category = 'rc05';
    }
    ?>
    <script id="gg-lazy-loader">
    (function() {
        var grid = document.querySelector("ul.products");
        if (!grid) return;

        // Detect category from URL
        var category = "<?php echo esc_js($category); ?>";
        var path = window.location.pathname;
        if (path.includes("rarity-collection")) category = "rc05";
        if (path.includes("blazing-dominion")) category = "blazing-dominion";
        if (path.includes("burst-protocol")) category = "burst-protocol";

        // Get IDs of products already in the DOM
        var existingIds = new Set();
        grid.querySelectorAll("li").forEach(function(li) {
            var btn = li.querySelector("[data-product_id]");
            if (btn) existingIds.add(parseInt(btn.getAttribute("data-product_id")));
        });

        console.log("GG Lazy: " + existingIds.size + " products already in DOM, fetching rest...");

        fetch("/wp-json/gg/v1/products?category=" + category + "&limit=1000", {
            headers: {"Accept": "application/json, image/avif"}
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.products) return;

            var fragment = document.createDocumentFragment();
            var added = 0;

            data.products.forEach(function(p) {
                if (existingIds.has(p.id)) return;

                var li = document.createElement("li");
                li.className = "ast-article-single desktop-align-left tablet-align-left mobile-align-left product type-product instock product_cat-singles has-post-thumbnail purchasable product-type-simple";
                li.setAttribute("data-deferred", "true");
                li.setAttribute("data-set", p.set || "");
                li.setAttribute("data-rarity", p.rarity || "");
                li.setAttribute("data-price", p.price || "0");
                li.style.display = "none";

                li.innerHTML =
                    '<div class="astra-shop-thumbnail-wrap">' +
                    '<a href="' + p.url + '" class="woocommerce-LoopProduct-link woocommerce-loop-product__link">' +
                    '<img loading="lazy" decoding="async" width="300" height="300" src="' + p.img + '" class="attachment-woocommerce_thumbnail size-woocommerce_thumbnail" alt="' + p.name.replace(/"/g, '&quot;') + '">' +
                    '</a></div>' +
                    '<div class="astra-shop-summary-wrap">' +
                    '<a href="' + p.url + '" class="ast-loop-product__link"><h2 class="woocommerce-loop-product__title">' + p.name + '</h2></a>' +
                    '<span class="price">' + p.display + '</span>' +
                    '<a href="?add-to-cart=' + p.id + '" data-quantity="1" class="ast-on-card-button ast-select-options-trigger product_type_simple add_to_cart_button ajax_add_to_cart" data-product_id="' + p.id + '" data-product_sku="' + (p.sku || '') + '" rel="nofollow">Add to basket</a>' +
                    '</div>';

                fragment.appendChild(li);
                added++;
            });

            grid.appendChild(fragment);

            // Trigger the existing progressive rendering system
            if (typeof ggResetProgressiveRender === "function") {
                ggResetProgressiveRender();
            }

            // Update product count if visible
            document.querySelectorAll("*").forEach(function(el) {
                if (el.childNodes.length === 1 && /^\d+ products$/.test(el.textContent.trim())) {
                    el.textContent = data.total + " products";
                }
            });

            console.log("GG Lazy: added " + added + " products via API (" + data.total + " total)");
        })
        .catch(function(err) {
            console.error("GG Lazy Loader error:", err);
        });
    })();
    </script>
    <?php
}, 99);
