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
