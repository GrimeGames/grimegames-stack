<?php
/**
 * Plugin Name: GG eBay Image Puller (Add-on)
 * Description: Downloads an eBay image URL to Media Library and sets it as the product thumbnail. Safe, standalone add-on.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) exit;

function gg_attach_ebay_image_to_product( $product_id, $raw_url ) {
  if ( empty($product_id) || empty($raw_url) ) return new WP_Error('gg_img_empty','Missing product or URL');

  // Normalise URL (https) and basic sanitise
  $url = esc_url_raw( str_replace('http://', 'https://', trim($raw_url) ) );

  // Fetch with robust headers + redirects
  $args = [
    'timeout'     => 20,
    'redirection' => 5,
    'headers'     => [
      'User-Agent'      => 'Mozilla/5.0 (WordPress Importer; +https://grimegames.com)',
      'Accept'          => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
      'Accept-Language' => 'en-GB,en;q=0.9',
      'Referer'         => 'https://www.ebay.com/',
    ],
    'sslverify'   => true,
  ];
  $response = wp_remote_get( $url, $args );
  if ( is_wp_error($response) ) return $response;

  $code = wp_remote_retrieve_response_code( $response );
  if ( $code !== 200 ) return new WP_Error('gg_img_http', 'HTTP '.$code.' fetching image');

  $body = wp_remote_retrieve_body( $response );
  if ( empty($body) ) return new WP_Error('gg_img_empty_body','Empty image body');

  $ctype = wp_remote_retrieve_header( $response, 'content-type' );
  $ext = '.jpg';
  if ( stripos($ctype,'webp') !== false ) $ext = '.webp';
  elseif ( stripos($ctype,'png') !== false ) $ext = '.png';
  elseif ( stripos($ctype,'jpeg') !== false || stripos($ctype,'jpg') !== false ) $ext = '.jpg';

  $filename = 'ebay-' . $product_id . '-' . wp_hash( $url ) . $ext;

  // Write to temp
  $tmp = wp_tempnam( $filename );
  if ( ! $tmp ) return new WP_Error('gg_img_tmp','Could not create temp file');
  file_put_contents( $tmp, $body );

  // Sideload
  if ( ! function_exists('media_handle_sideload') ) {
    require_once ABSPATH.'wp-admin/includes/image.php';
    require_once ABSPATH.'wp-admin/includes/file.php';
    require_once ABSPATH.'wp-admin/includes/media.php';
  }
  $file = [
    'name'     => $filename,
    'type'     => $ctype ?: 'image/jpeg',
    'tmp_name' => $tmp,
    'error'    => 0,
    'size'     => filesize($tmp),
  ];
  $attach_id = media_handle_sideload( $file, $product_id );
  if ( is_wp_error($attach_id) ) return $attach_id;

  set_post_thumbnail( $product_id, $attach_id );
  return $attach_id;
}

/**
 * Public hook: call this from the importer after creating a product.
 * Expects: $product_id (int), $primary_image_url (string)
 */
add_action('gg_importer_after_product_created', function($product_id, $primary_image_url){
  // Skip if already has thumbnail
  if ( has_post_thumbnail($product_id) ) return;

  $res = gg_attach_ebay_image_to_product($product_id, $primary_image_url);
  if ( is_wp_error($res) ) {
    error_log('GG image import failed for product '.$product_id.': '.$res->get_error_message());
  }
}, 10, 2);
