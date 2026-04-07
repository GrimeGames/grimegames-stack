<?php
/*
Plugin Name: GrimeGames AJAX Search
Description: Fast REST API-based product search for the GrimeGames storefront. Single optimised SQL query with SKU + title matching, in-stock filter, and rarity extraction.
Author: GrimeGames
Version: 1.1
*/

defined('ABSPATH') || exit;

// ============================================================
// REST API ENDPOINT
// ============================================================

add_action('rest_api_init', function() {
    register_rest_route('gg/v1', '/search', array(
        'methods'             => 'GET',
        'callback'            => 'gg_ajax_search_handler',
        'permission_callback' => '__return_true',
        'args' => array(
            'q' => array(
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => function($param) {
                    return is_string($param) && strlen($param) <= 100;
                },
            ),
            'limit' => array(
                'default'           => 8,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'validate_callback' => function($param) {
                    return $param >= 1 && $param <= 20;
                },
            ),
        ),
    ));
});

// ============================================================
// SEARCH HANDLER
// ============================================================

function gg_ajax_search_handler($request) {
    $query = $request->get_param('q');
    $limit = $request->get_param('limit');

    if (strlen($query) < 2) {
        return new WP_REST_Response(array(
            'query'   => $query,
            'results' => array(),
            'total'   => 0,
        ), 200);
    }

    global $wpdb;
    $search_term = '%' . $wpdb->esc_like($query) . '%';

    $product_ids = $wpdb->get_col($wpdb->prepare("
        SELECT DISTINCT p.ID
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} stock ON p.ID = stock.post_id
            AND stock.meta_key = '_stock_status'
            AND stock.meta_value = 'instock'
        LEFT JOIN {$wpdb->postmeta} sku ON p.ID = sku.post_id
            AND sku.meta_key = '_sku'
        WHERE p.post_type = 'product'
        AND p.post_status = 'publish'
        AND (
            p.post_title LIKE %s
            OR (sku.meta_value LIKE %s)
        )
        ORDER BY
            CASE WHEN p.post_title LIKE %s THEN 0 ELSE 1 END,
            p.post_title ASC
        LIMIT %d
    ", $search_term, $search_term, $search_term, $limit));

    $results = array();

    foreach ($product_ids as $product_id) {
        $product = wc_get_product($product_id);
        if (!$product) continue;

        $rarity = gg_ajax_extract_rarity($product);

        $stock_quantity = $product->get_stock_quantity();
        if ($stock_quantity && $stock_quantity <= 3) {
            $stock_text = 'Nearly Gone (' . $stock_quantity . ' left)';
        } else {
            $stock_text = 'In Stock';
        }

        $results[] = array(
            'id'           => $product->get_id(),
            'title'        => $product->get_name(),
            'sku'          => $product->get_sku(),
            'price'        => $product->get_price(),
            'price_html'   => $product->get_price_html(),
            'url'          => get_permalink($product->get_id()),
            'image'        => wp_get_attachment_image_url($product->get_image_id(), 'thumbnail'),
            'rarity'       => $rarity,
            'stock'        => $stock_text,
            'stock_status' => 'instock',
        );
    }

    return new WP_REST_Response(array(
        'query'   => $query,
        'results' => $results,
        'total'   => count($results),
    ), 200);
}

// ============================================================
// RARITY EXTRACTION
// ============================================================

function gg_ajax_extract_rarity($product) {
    $title = $product->get_name();

    $rarities = array(
        'Quarter Century Secret Rare' => array('Quarter Century Secret', 'QCSR', '¼ Century Secret'),
        'Prismatic Secret Rare'        => array('Prismatic Secret', 'PSR'),
        'Platinum Secret Rare'         => array('Platinum Secret', 'PLSR'),
        'Starlight Rare'               => array('Starlight Rare', 'Starlight', 'SLR'),
        "Collector's Rare"             => array("Collector's Rare", 'Collector', 'CR'),
        'Ghost Rare'                   => array('Ghost Rare', 'GR'),
        'Ultimate Rare'                => array('Ultimate Rare', 'Ultimate', 'UtR'),
        'Secret Rare'                  => array('Secret Rare', 'Secret', 'ScR'),
        'Ultra Rare'                   => array('Ultra Rare', 'Ultra', 'UR'),
        'Super Rare'                   => array('Super Rare', 'Super', 'SR'),
        'Rare'                         => array('\bRare\b'),
        'Common'                       => array('\bCommon\b', '\bC\b'),
    );

    foreach ($rarities as $rarity_name => $patterns) {
        foreach ($patterns as $pattern) {
            if (preg_match('/' . $pattern . '/i', $title)) {
                return $rarity_name;
            }
        }
    }

    $categories = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'names'));
    if (!is_wp_error($categories)) {
        foreach ($categories as $cat) {
            foreach ($rarities as $rarity_name => $patterns) {
                foreach ($patterns as $pattern) {
                    if (preg_match('/' . $pattern . '/i', $cat)) {
                        return $rarity_name;
                    }
                }
            }
        }
    }

    return '';
}

// ============================================================
// ENQUEUE FRONTEND ASSETS
// ============================================================

add_action('wp_enqueue_scripts', function() {
    $plugin_url = plugin_dir_url(__FILE__);

    wp_enqueue_style(
        'gg-ajax-search',
        $plugin_url . 'assets/search.css',
        array(),
        '1.1'
    );

    wp_enqueue_script(
        'gg-ajax-search',
        $plugin_url . 'assets/search.js',
        array('jquery'),
        '1.1',
        true
    );

    wp_localize_script('gg-ajax-search', 'ggSearch', array(
        'restUrl' => rest_url('gg/v1/search'),
        'nonce'   => wp_create_nonce('wp_rest'),
    ));
});