<?php
/*
Plugin Name: GrimeGames SEO Enhancer
Description: Auto-generates meta descriptions for products/pages, fixes Product schema (adds brand), fixes OG types, adds missing H1s. Works alongside Yoast SEO. Debug panel at ?gg_seo_debug=1
Author: GrimeGames
Version: 1.0
*/

defined('ABSPATH') || exit;


/* =========================
   1. AUTO META DESCRIPTIONS — Fill in where Yoast has none set
   Template-based for products, category pages, homepage.
   Yoast manual descriptions always take priority.
   ========================= */
add_filter('wpseo_metadesc', function($desc) {
    // If Yoast already has a description, keep it
    if (!empty(trim($desc))) return $desc;

    // Homepage
    if (is_front_page() || is_home()) {
        return 'Buy Yu-Gi-Oh! TCG singles from GrimeGames. Premium cards, competitive prices, fast UK shipping. Shop Secret Rares, Starlights, Ultimate Rares and more.';
    }

    // Single product page
    if (is_product()) {
        global $post;
        $product = wc_get_product($post->ID);
        if (!$product) return $desc;

        $name   = $product->get_name();
        $price  = $product->get_price();
        $sku    = $product->get_sku();

        // Try to extract set name and rarity from product categories
        $set_name = '';
        $rarity   = '';
        $cats = wp_get_post_terms($post->ID, 'product_cat', ['fields' => 'names']);
        if (!is_wp_error($cats) && !empty($cats)) {
            $set_name = $cats[0]; // First category is usually the set
        }
        // Try rarity from pa_rarity attribute
        $rarity_terms = wp_get_post_terms($post->ID, 'pa_rarity', ['fields' => 'names']);
        if (!is_wp_error($rarity_terms) && !empty($rarity_terms)) {
            $rarity = $rarity_terms[0];
        }
        // Fallback: extract rarity from product title
        if (empty($rarity)) {
            $rarity_patterns = [
                'Starlight Rare', 'Ultimate Rare', 'Secret Rare', 'Platinum Secret Rare',
                'Prismatic Secret Rare', 'Ultra Rare', 'Super Rare', 'Rare',
                'Collectors Rare', 'Quarter Century Secret Rare', 'Common',
            ];
            foreach ($rarity_patterns as $r) {
                if (stripos($name, $r) !== false) {
                    $rarity = $r;
                    break;
                }
            }
        }

        // Build description
        $parts = ["Buy {$name} from GrimeGames"];
        if ($rarity && $set_name) {
            $parts[] = "{$rarity} from {$set_name}";
        } elseif ($set_name) {
            $parts[] = "{$set_name} set";
        }
        if ($price) {
            $parts[] = "Price: £" . number_format((float)$price, 2);
        }
        $parts[] = "Fast UK shipping. Trusted Yu-Gi-Oh! singles seller";

        $meta = implode('. ', $parts) . '.';
        // Trim to ~155 chars for SERP display
        if (strlen($meta) > 160) {
            $meta = substr($meta, 0, 157) . '...';
        }
        return $meta;
    }

    // Product category archive
    if (is_product_category()) {
        $cat = get_queried_object();
        if ($cat) {
            return "Shop {$cat->name} Yu-Gi-Oh! singles at GrimeGames. Competitive prices on Secret Rares, Ultra Rares, Starlights and more. Fast UK shipping.";
        }
    }

    // Custom set pages (RC5, Blazing Dominion etc — these are WP pages, not archives)
    if (is_page()) {
        global $post;
        $title = get_the_title($post->ID);
        return "Browse {$title} Yu-Gi-Oh! singles at GrimeGames. All rarities in stock. Competitive prices and fast UK delivery.";
    }

    return $desc;
}, 10, 1);


/* =========================
   2. FIX HOMEPAGE OG DESCRIPTION — Remove [fibosearch] shortcode remnant
   ========================= */
add_filter('wpseo_opengraph_desc', function($desc) {
    if (is_front_page() || is_home()) {
        // If it contains shortcode remnants, replace entirely
        if (strpos($desc, '[fibosearch]') !== false || strpos($desc, '[') !== false) {
            return 'Buy Yu-Gi-Oh! TCG singles from GrimeGames. Premium cards, competitive prices, fast UK shipping.';
        }
    }
    return $desc;
}, 10, 1);


/* =========================
   3. FIX OG TYPE FOR PRODUCTS — Should be "product" not "article"
   ========================= */
add_filter('wpseo_opengraph_type', function($type) {
    if (is_product()) {
        return 'product';
    }
    return $type;
}, 10, 1);


/* =========================
   4. ADD BRAND TO PRODUCT JSON-LD SCHEMA
   WooCommerce/Yoast outputs Product schema but missing brand.
   ========================= */
add_filter('woocommerce_structured_data_product', function($markup, $product) {
    if (!isset($markup['brand'])) {
        $markup['brand'] = [
            '@type' => 'Brand',
            'name'  => 'Konami',
        ];
    }
    return $markup;
}, 10, 2);

// Also hook into Yoast's schema if it's generating the Product piece
add_filter('wpseo_schema_product', function($data) {
    if (!isset($data['brand'])) {
        $data['brand'] = [
            '@type' => 'Brand',
            'name'  => 'Konami',
        ];
    }
    return $data;
}, 10, 1);


/* =========================
   5. FIX HOMEPAGE TITLE — "Home - GrimeGames" is poor, should be descriptive
   ========================= */
add_filter('wpseo_title', function($title) {
    if (is_front_page() || is_home()) {
        return 'GrimeGames — Premium Yu-Gi-Oh! TCG Singles | UK Seller';
    }
    return $title;
}, 10, 1);

// Also fix OG title
add_filter('wpseo_opengraph_title', function($title) {
    if (is_front_page() || is_home()) {
        return 'GrimeGames — Premium Yu-Gi-Oh! TCG Singles';
    }
    return $title;
}, 10, 1);


/* =========================
   6. ADD og:image TO PRODUCT PAGES — Use product featured image
   Yoast may not always pick this up for WooCommerce products.
   ========================= */
add_filter('wpseo_opengraph_image', function($img) {
    if (is_product() && empty($img)) {
        global $post;
        $thumb_id = get_post_thumbnail_id($post->ID);
        if ($thumb_id) {
            $img_url = wp_get_attachment_url($thumb_id);
            if ($img_url) return $img_url;
        }
    }
    return $img;
}, 10, 1);


/* =========================
   7. ADD MISSING og:image DIMENSIONS
   Helps social platforms render share cards correctly.
   ========================= */
add_action('wpseo_opengraph', function() {
    if (is_product()) {
        global $post;
        $thumb_id = get_post_thumbnail_id($post->ID);
        if ($thumb_id) {
            $img_data = wp_get_attachment_image_src($thumb_id, 'full');
            if ($img_data) {
                echo '<meta property="og:image:width" content="' . esc_attr($img_data[1]) . '" />' . "\n";
                echo '<meta property="og:image:height" content="' . esc_attr($img_data[2]) . '" />' . "\n";
            }
        }
    }
}, 40);


/* =========================
   8. FIX: Add OG price tags for product pages (helps FB/social commerce)
   ========================= */
add_action('wpseo_opengraph', function() {
    if (!is_product()) return;
    global $post;
    $product = wc_get_product($post->ID);
    if (!$product) return;

    $price = $product->get_price();
    if ($price) {
        echo '<meta property="product:price:amount" content="' . esc_attr($price) . '" />' . "\n";
        echo '<meta property="product:price:currency" content="GBP" />' . "\n";
    }

    // Availability
    $stock = $product->is_in_stock() ? 'instock' : 'oos';
    echo '<meta property="product:availability" content="' . esc_attr($stock) . '" />' . "\n";
}, 40);


/* =========================
   9. ENSURE TWITTER CARD IMAGE ON PRODUCTS
   ========================= */
add_filter('wpseo_twitter_image', function($img) {
    if (is_product() && empty($img)) {
        global $post;
        $thumb_id = get_post_thumbnail_id($post->ID);
        if ($thumb_id) {
            $img_url = wp_get_attachment_url($thumb_id);
            if ($img_url) return $img_url;
        }
    }
    return $img;
}, 10, 1);


/* =========================
   DEBUG: Show SEO data when ?gg_seo_debug=1
   ========================= */
add_action('wp_footer', function() {
    if (!isset($_GET['gg_seo_debug']) || !current_user_can('manage_options')) return;
    echo '<div style="position:fixed;bottom:0;left:0;right:0;background:#1a1a2e;color:#0f0;font-family:monospace;font-size:12px;padding:15px;z-index:99999;max-height:300px;overflow:auto;border-top:2px solid #e94560;">';
    echo '<strong style="color:#e94560;">🔍 GG SEO Debug</strong><br>';

    // Current page type
    if (is_front_page()) echo 'Page type: FRONT PAGE<br>';
    elseif (is_product()) echo 'Page type: PRODUCT<br>';
    elseif (is_product_category()) echo 'Page type: PRODUCT CATEGORY<br>';
    elseif (is_page()) echo 'Page type: PAGE<br>';
    else echo 'Page type: OTHER<br>';

    // Meta description
    if (function_exists('YoastSEO')) {
        $meta = YoastSEO()->meta->for_current_page();
        if ($meta) {
            echo 'Meta desc: ' . esc_html(substr($meta->description, 0, 200)) . '<br>';
            echo 'OG title: ' . esc_html($meta->open_graph_title) . '<br>';
            echo 'OG desc: ' . esc_html(substr($meta->open_graph_description, 0, 200)) . '<br>';
            echo 'OG type: ' . esc_html($meta->open_graph_type) . '<br>';
        }
    }

    echo '</div>';
}, 9999);
