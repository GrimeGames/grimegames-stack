<?php
/*
Plugin Name: GrimeGames Most Viewed Carousel
Description: Auto-updating carousel showing most viewed products
Version: 1.0
Author: GrimeGames
*/

defined('ABSPATH') || exit;

// Shortcode: [gg_most_viewed_carousel]
add_shortcode('gg_most_viewed_carousel', 'gg_render_most_viewed_carousel');

function gg_render_most_viewed_carousel() {
    ob_start();
    
    // Get most viewed products from last 7 days
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => 8,
        'meta_key' => 'pvc_views',  // Post Views Counter meta key
        'orderby' => 'meta_value_num',
        'order' => 'DESC',
        'post_status' => 'publish',
        'meta_query' => array(
            array(
                'key'     => '_stock_status',
                'value'   => 'instock',
                'compare' => '=',
            ),
        ),
    );
    
    $most_viewed = new WP_Query($args);
    
    if (!$most_viewed->have_posts()) {
        // Fallback to recent products if no view data yet
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => 8,
            'orderby' => 'date',
            'order' => 'DESC',
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key'     => '_stock_status',
                    'value'   => 'instock',
                    'compare' => '=',
                ),
            ),
        );
        $most_viewed = new WP_Query($args);
    }
    
    ?>
    <div class="carousel-track" id="carouselTrack">
        <?php
        if ($most_viewed->have_posts()) :
            while ($most_viewed->have_posts()) : $most_viewed->the_post();
                $product = wc_get_product(get_the_ID());
                if ($product) :
        ?>
        <a href="<?php echo esc_url(get_permalink()); ?>" class="carousel-card">
            <div class="card-image">
                <?php echo $product->get_image('large'); ?>
            </div>
            <div class="card-info">
                <h3><?php echo esc_html(get_the_title()); ?></h3>
                <p class="card-price">£<?php echo esc_html($product->get_price()); ?></p>
            </div>
        </a>
        <?php
                endif;
            endwhile;
            wp_reset_postdata();
        endif;
        ?>
    </div>
    <?php
    
    return ob_get_clean();
}