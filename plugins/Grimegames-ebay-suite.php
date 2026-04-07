<?php
/*
Plugin Name: GrimeGames eBay Suite (v3.8 — RARITY-AWARE SNAPSHOT + IMAGE SYNC)
Description: eBay Importer + Sync (eBay→Woo ONLY). FIXED: Snapshot searches by set code + rarity. NEW: Syncs images if changed on eBay.
Author: GrimeGames
Version: 3.8
*/

defined('ABSPATH') || exit;

/* =========================
   BEHAVIOUR SWITCHES
   ========================= */
if (!defined('GG_DELETE_MODE'))     define('GG_DELETE_MODE','trash');
if (!defined('GG_GRACE_HOURS'))     define('GG_GRACE_HOURS', 0);
if (!defined('GG_DEDUPE_ON_SYNC'))  define('GG_DEDUPE_ON_SYNC', 1);

/* =========================
   SETTINGS / DEFAULTS
   ========================= */
function gg_get_option($k,$d=''){ return get_option($k,$d); }
function gg_set_option($k,$v){ return update_option($k,$v); }

function gg_defaults(){
  return array(
    'throttle'        => 0,
    'pagesize10'      => 0,
    'auto_image'      => 1,
    'disable_cron'    => 1,
    'max_per_run'     => 1000,
    'breaker_on_518'  => 1,
    'verbose_skips'   => 0,
    'price_sync_debug'=> 1,
    'sync_images'     => 1,
  );
}
function gg_settings(){ return array_merge(gg_defaults(), (array)get_option('gg_suite_settings',array())); }

/* =========================
   DIAGNOSTICS PAGE
   ========================= */
function gg_diagnostics_page() {
  if (!current_user_can('manage_options')) wp_die('Access denied');
  
  echo '<div class="wrap">';
  echo '<h1>🔍 eBay Suite Diagnostics</h1>';
  
  // Get all products
  $all_products = new WP_Query(array(
    'post_type' => 'product',
    'post_status' => 'any',
    'posts_per_page' => -1,
    'fields' => 'ids',
  ));
  
  $total = $all_products->found_posts;
  
  // Get products WITH eBay ID
  $with_ebay = new WP_Query(array(
    'post_type' => 'product',
    'post_status' => 'any',
    'posts_per_page' => -1,
    'fields' => 'ids',
    'meta_query' => array(
      array('key' => '_gg_ebay_item_id', 'compare' => 'EXISTS')
    ),
  ));
  
  $linked = $with_ebay->found_posts;
  $unlinked = $total - $linked;
  
  echo '<div style="background:#f0f0f1;padding:15px;border-radius:4px;margin:15px 0;">';
  echo '<h2 style="margin-top:0;">📊 Summary</h2>';
  echo '<table class="widefat" style="max-width:500px;"><tbody>';
  echo '<tr><td><strong>Total Products:</strong></td><td>' . $total . '</td></tr>';
  echo '<tr style="background:#d4edda;"><td><strong>✅ Linked to eBay:</strong></td><td>' . $linked . '</td></tr>';
  echo '<tr style="background:' . ($unlinked > 0 ? '#f8d7da' : '#d4edda') . ';"><td><strong>' . ($unlinked > 0 ? '❌' : '✅') . ' NOT Linked:</strong></td><td>' . $unlinked . '</td></tr>';
  echo '</tbody></table>';
  echo '</div>';
  
  if ($unlinked > 0) {
    echo '<div style="background:#fff3cd;border-left:4px solid #ffc107;padding:12px;margin:15px 0;">';
    echo '<strong>⚠️ Warning:</strong> ' . $unlinked . ' product(s) are missing the eBay Item ID link. These will be skipped during sync.';
    echo '</div>';
    
    // Get products WITHOUT eBay ID
    $without_ebay = new WP_Query(array(
      'post_type' => 'product',
      'post_status' => 'any',
      'posts_per_page' => 50,
      'meta_query' => array(
        array('key' => '_gg_ebay_item_id', 'compare' => 'NOT EXISTS')
      ),
    ));
    
    if ($without_ebay->have_posts()) {
      echo '<h2>❌ Products Missing eBay Link (showing first 50)</h2>';
      echo '<table class="widefat striped"><thead><tr>';
      echo '<th>ID</th><th>Title</th><th>Price</th><th>SKU</th><th>Status</th><th>Action</th>';
      echo '</tr></thead><tbody>';
      
      while ($without_ebay->have_posts()) {
        $without_ebay->the_post();
        $pid = get_the_ID();
        $product = wc_get_product($pid);
        $title = get_the_title();
        $price = $product ? $product->get_price() : 'N/A';
        $sku = $product ? $product->get_sku() : '';
        $status = get_post_status();
        
        echo '<tr>';
        echo '<td>' . $pid . '</td>';
        echo '<td><strong>' . esc_html($title) . '</strong></td>';
        echo '<td>£' . esc_html($price) . '</td>';
        echo '<td><code>' . esc_html($sku) . '</code></td>';
        echo '<td>' . $status . '</td>';
        echo '<td><a href="' . get_edit_post_link($pid) . '" class="button button-small">Edit</a></td>';
        echo '</tr>';
      }
      
      echo '</tbody></table>';
      wp_reset_postdata();
    }
  } else {
    echo '<div style="background:#d4edda;border-left:4px solid #28a745;padding:12px;margin:15px 0;">';
    echo '<strong>✅ Perfect!</strong> All products are properly linked to eBay.';
    echo '</div>';
  }
  
  echo '<hr style="margin:30px 0;">';
  echo '<h2>🔧 Tools</h2>';
  
  echo '<div style="background:#f0f0f1;padding:15px;border-radius:4px;margin:15px 0;">';
  echo '<h3 style="margin-top:0;">🔄 Manually Update Single Product</h3>';
  echo '<p>Force update a specific product by eBay Item ID (useful if sync is skipping it).</p>';
  echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
  wp_nonce_field('gg_manual_update_product');
  echo '<input type="hidden" name="action" value="gg_manual_update_product">';
  echo '<input type="text" name="ebay_item_id" placeholder="Enter eBay Item ID (e.g., 336403405810)" style="width:300px;" required> ';
  echo '<button class="button button-primary">Update This Product</button>';
  echo '</form>';
  echo '</div>';
  
  if ($unlinked > 0) {
    echo '<div style="background:#f0f0f1;padding:15px;border-radius:4px;margin:15px 0;">';
    echo '<h3 style="margin-top:0;">Auto-Link Products to eBay</h3>';
    echo '<p>This tool will search eBay for products matching your unlinked WooCommerce products and create the link.</p>';
    echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
    wp_nonce_field('gg_autolink_products');
    echo '<input type="hidden" name="action" value="gg_autolink_products">';
    echo '<button class="button button-secondary" onclick="return confirm(\'This will search eBay for ' . $unlinked . ' product(s). Continue?\')">🔗 Auto-Link Unlinked Products</button>';
    echo '</form>';
    echo '</div>';
  }
  
  echo '<p><a href="' . admin_url('admin.php?page=gg-ebay-suite') . '" class="button button-primary">← Back to eBay Suite</a></p>';
  
  echo '</div>';
}

/* =========================
   ADMIN MENU
   ========================= */
add_action('admin_menu', function () {
  add_menu_page('GrimeGames eBay Suite','eBay Suite','manage_options','gg-ebay-suite','gg_admin_page','dashicons-cart',58);
  add_submenu_page('gg-ebay-suite', 'Diagnostics', '🔍 Diagnostics', 'manage_options', 'gg-ebay-diagnostics', 'gg_diagnostics_page');
});

/* =========================
   OAUTH
   ========================= */
function gg_render_auth_settings(){
  if (!current_user_can('manage_options')) return;

  if (!empty($_POST['gg_oauth_save']) && check_admin_referer('gg_oauth_save')) {
    gg_set_option('ebay_client_id',     sanitize_text_field($_POST['ebay_client_id'] ?? ''));
    gg_set_option('ebay_client_secret', sanitize_text_field($_POST['ebay_client_secret'] ?? ''));
    gg_set_option('ebay_refresh_token', sanitize_text_field($_POST['ebay_refresh_token'] ?? ''));
    echo '<div class="updated"><p>OAuth saved.</p></div>';
  }

  $cid = esc_attr(gg_get_option('ebay_client_id'));
  $sec = esc_attr(gg_get_option('ebay_client_secret'));
  $rft = esc_attr(gg_get_option('ebay_refresh_token'));
  $exp = esc_html(gg_get_option('ebay_access_expires'));
  echo '<h2>OAuth</h2><form method="post"><table class="form-table">
    <tr><th>Client ID (App ID)</th><td><input class="regular-text" name="ebay_client_id" value="'.$cid.'"></td></tr>
    <tr><th>Client Secret</th><td><input class="regular-text" name="ebay_client_secret" value="'.$sec.'"></td></tr>
    <tr><th>Refresh Token</th><td><input class="large-text" name="ebay_refresh_token" value="'.$rft.'"></td></tr>
    <tr><th>Access Token Expires</th><td><code>'.$exp.'</code></td></tr>
  </table>';
  wp_nonce_field('gg_oauth_save');
  echo '<p><button class="button button-primary" name="gg_oauth_save" value="1">Save OAuth</button></p></form>';
}

/* =========================
   CONSTANTS
   ========================= */
define('EBAY_COMPATIBILITY_LEVEL', 967);
define('EBAY_API_ENDPOINT', 'https://api.ebay.com/ws/api.dll');
define('EBAY_SITE_ID', '3');

/* =========================
   TOKEN HELPERS
   ========================= */
function gg_xml($s){ return htmlspecialchars((string)$s, ENT_XML1|ENT_COMPAT, 'UTF-8'); }

function gg_token_user(){
  $access = gg_get_option('ebay_access_token');
  $exp    = (int) gg_get_option('ebay_access_expires',0);
  if ($access && $exp > time()+120) return $access;

  $cid=gg_get_option('ebay_client_id');
  $sec=gg_get_option('ebay_client_secret');
  $rft=gg_get_option('ebay_refresh_token');
  if (!$cid || !$sec || !$rft) return new WP_Error('oauth_config_missing','Missing client id/secret/refresh token');

  $auth = base64_encode($cid.':'.$sec);
  $resp = wp_remote_post('https://api.ebay.com/identity/v1/oauth2/token',array(
    'headers'=>array('Authorization'=>'Basic '.$auth,'Content-Type'=>'application/x-www-form-urlencoded'),
    'body'=>http_build_query(array('grant_type'=>'refresh_token','refresh_token'=>$rft,'scope'=>'https://api.ebay.com/oauth/api_scope')),
    'timeout'=>45,
  ));
  if (is_wp_error($resp)) return $resp;
  $json = json_decode(wp_remote_retrieve_body($resp), true);
  if (empty($json['access_token'])) return new WP_Error('oauth_refresh_failed', wp_remote_retrieve_body($resp));
  gg_set_option('ebay_access_token', $json['access_token']);
  gg_set_option('ebay_access_expires', time() + (int)($json['expires_in'] ?? 650));
  return $json['access_token'];
}

function gg_token_app(){
  $cached = get_transient('gg_app_tok');
  if ($cached) return $cached;
  $cid=gg_get_option('ebay_client_id'); $sec=gg_get_option('ebay_client_secret');
  if(!$cid||!$sec) return new WP_Error('oauth_app_missing','Missing client id/secret');
  $auth=base64_encode($cid.':'.$sec);
  $resp = wp_remote_post('https://api.ebay.com/identity/v1/oauth2/token',array(
    'headers'=>array('Authorization'=>'Basic '.$auth,'Content-Type'=>'application/x-www-form-urlencoded'),
    'body'=>'grant_type=client_credentials&scope='.rawurlencode('https://api.ebay.com/oauth/api_scope'),
    'timeout'=>30,
  ));
  if (is_wp_error($resp)) return $resp;
  $json=json_decode(wp_remote_retrieve_body($resp), true);
  if (empty($json['access_token'])) return new WP_Error('oauth_app_failed', wp_remote_retrieve_body($resp));
  $ttl=max(300,(int)($json['expires_in'] ?? 3600)-300);
  set_transient('gg_app_tok',$json['access_token'],$ttl);
  return $json['access_token'];
}

/* Auto headers */
add_filter('http_request_args', function($args,$url){
  $host=parse_url($url,PHP_URL_HOST);
  if (!$host || stripos($host,'api.ebay.com')===false) return $args;

  if (stripos($url,'/ws/api.dll')!==false){
    $tok=gg_token_user(); if (is_wp_error($tok)) { gg_report_add('errors','OAuth error: '.$tok->get_error_message()); return $args; }
    $args['headers'] = array_merge($args['headers'] ?? array(), ['X-EBAY-API-IAF-TOKEN'=>$tok]);
  } elseif (stripos($url,'/buy/browse')!==false){
    $app=gg_token_app(); if (is_wp_error($app)) { gg_report_add('errors','App token error: '.$app->get_error_message()); return $args; }
    $args['headers'] = array_merge($args['headers'] ?? array(), ['Authorization'=>'Bearer '.$app]);
  }
  return $args;
},20,2);

/* =========================
   HIDE SALE BADGE
   ========================= */
add_filter('woocommerce_sale_flash', '__return_empty_string', 99999, 3);
remove_action('woocommerce_before_shop_loop_item_title', 'woocommerce_show_product_loop_sale_flash', 10);
remove_action('woocommerce_before_single_product_summary', 'woocommerce_show_product_sale_flash', 10);

add_action('wp_head', function() {
  ?>
  <style type="text/css">
  .ast-onsale-card, .ast-on-card-button.ast-onsale-card, span.ast-onsale-card,
  .onsale, span.onsale, .woocommerce span.onsale,
  .woocommerce ul.products li.product .onsale, .woocommerce div.product .onsale {
    display: none !important; visibility: hidden !important; opacity: 0 !important;
  }
  </style>
  <?php
}, 99999);

add_action('wp_footer', function() {
  ?>
  <script type="text/javascript">
  (function() {
    function removeAstraSaleBadges() {
      var astraBadges = document.querySelectorAll('.ast-onsale-card, .ast-on-card-button.ast-onsale-card, span.onsale');
      astraBadges.forEach(function(badge) { badge.remove(); });
    }
    removeAstraSaleBadges();
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', removeAstraSaleBadges);
    window.addEventListener('load', function() { setTimeout(removeAstraSaleBadges, 100); });
  })();
  </script>
  <?php
}, 99999);

/* =========================
   BACKOFF / THROTTLE
   ========================= */
if (!defined('GG_BACKOFF_UNTIL')) define('GG_BACKOFF_UNTIL','gg_ebay_backoff_until');
function gg_backoff_until(){ return (int) get_option(GG_BACKOFF_UNTIL,0); }
function gg_backoff_set_518(){ $until=time()+DAY_IN_SECONDS; update_option(GG_BACKOFF_UNTIL,$until); gg_report_add('notes','⏳ 518 breaker until '.date('Y-m-d H:i:s',$until)); return $until; }

add_action('http_api_debug', function($response,$type,$class,$args,$url){
  if (stripos($url,'ebay.com')===false) return;
  $code = is_wp_error($response) ? 'WP_Error' : wp_remote_retrieve_response_code($response);
  gg_report_add('tap', sprintf('%s %s → %s', $type, $url, (string)$code));
}, 10, 5);

function gg_trading_call($call,$xml){
  $s = gg_settings();
  $until = gg_backoff_until();
  if ($s['breaker_on_518'] && $until && time() < $until) {
    return new WP_Error('ebay_518_breaker','Breaker active until '.date('Y-m-d H:i:s',$until));
  }

  static $last=0,$count=0;
  if ($s['throttle']) {
    $count++; if ($count>20) return new WP_Error('gg_throttle_cap','Cap 20 reached');
    $gap = 3 - (time()-$last); if ($gap>0) sleep($gap); $last=time();
  }
  if ($s['pagesize10']) {
    $xml = preg_replace('#<EntriesPerPage>\d+</EntriesPerPage>#','<EntriesPerPage>10</EntriesPerPage>',$xml);
  }

  $headers = array(
    'X-EBAY-API-COMPATIBILITY-LEVEL'=>EBAY_COMPATIBILITY_LEVEL,
    'X-EBAY-API-CALL-NAME'=>$call,
    'X-EBAY-API-SITEID'=>EBAY_SITE_ID,
    'Content-Type'=>'text/xml'
  );
  $resp = wp_remote_post(EBAY_API_ENDPOINT, array('headers'=>$headers,'body'=>$xml,'timeout'=>45));
  if (is_wp_error($resp)) return $resp;

  $raw = wp_remote_retrieve_body($resp);
  if (strpos($raw,'<ErrorCode>518</ErrorCode>')!==false || stripos($raw,'usage limit')!==false){
    if ($s['breaker_on_518']) gg_backoff_set_518();
    return new WP_Error('ebay_518','Usage limit (518)');
  }

  $x = @simplexml_load_string($raw);
  if (!$x) return new WP_Error('xml_parse','Trading XML parse fail');

  $ack = (string)($x->Ack ?? '');
  if (in_array($ack, array('Success','Warning'))) return $resp;

  $errs = array();
  if (!empty($x->Errors)) {
    foreach ($x->Errors as $e) {
      $code  = (string)($e->ErrorCode ?? '');
      $short = (string)($e->ShortMessage ?? '');
      $long  = (string)($e->LongMessage ?? '');
      $one   = trim(($code ? "[$code] " : '').($short ?: 'Error').($long ? " — ".$long : ''));
      if ($one) $errs[] = $one;
    }
  }
  return new WP_Error('ebay_ack_failure', $errs ? implode(' | ', $errs) : 'Ack=Failure');
}

/* =========================
   DELTA REPORTER
   ========================= */
$GLOBALS['gg_report'] = [
  'started' => null, 'ended' => null,
  'counts'  => ['titles'=>0,'trash'=>0,'draft'=>0,'price'=>0,'errors'=>0,'price_debug'=>0,'images'=>0],
  'examples'=> ['titles'=>[],'trash'=>[],'draft'=>[],'price'=>[],'errors'=>[],'price_debug'=>[],'images'=>[]],
  'tap'=>[], 'notes'=>[]
];
function gg_report_boot(){ $GLOBALS['gg_report']['started']=current_time('mysql'); }
function gg_report_add($bucket,$line){
  $r=&$GLOBALS['gg_report'];
  if ($bucket==='tap'||$bucket==='notes'){ if(count($r[$bucket])<25) $r[$bucket][]=$line; return; }
  if (!isset($r['counts'][$bucket])) return;
  $r['counts'][$bucket]++; if (count($r['examples'][$bucket])<50) $r['examples'][$bucket][]=$line;
}
function gg_report_render($phase_tag=''){
  $r=&$GLOBALS['gg_report']; $r['ended']=current_time('mysql');
  $c=$r['counts']; $ex=$r['examples'];
  $lines=[];
  $lines[]="—— eBay Suite Sync Report — Delta Only ".($phase_tag?"[$phase_tag]":"")." ——";
  $lines[]="Started: {$r['started']}  Ended: {$r['ended']}";
  $lines[]=sprintf("Changes — Titles:%d  Trashed:%d  Drafted:%d  Price:%d  Images:%d  Errors:%d",
    $c['titles'],$c['trash'],$c['draft'],$c['price'],$c['images'],$c['errors']
  );
  foreach (['titles','trash','draft','price','images','errors','price_debug'] as $b){
    if ($c[$b]<1) continue;
    $label = ['titles'=>'Titles updated','trash'=>'Moved to trash','draft'=>'Moved to draft','price'=>'Price updated','images'=>'Images updated','errors'=>'Errors','price_debug'=>'Price Sync Debug'][$b];
    $lines[]="— {$label} (up to 50):"; foreach ($ex[$b] as $row) $lines[]="   • {$row}";
  }
  if ($r['tap'])   { $lines[]='— HTTP tap (up to 25):'; foreach($r['tap'] as $t) $lines[]='   • '.$t; }
  if ($r['notes']) { $lines[]='— Notes (up to 25):'; foreach($r['notes'] as $t) $lines[]='   • '.$t; }
  $out=implode("\n",$lines);
  
  set_transient('gg_sync_report', $out, 60);
  
  echo '<div class="notice notice-info"><pre style="white-space:pre-wrap;margin:0">'.esc_html($out).'</pre></div>';
  update_option('_gg_suite_last_report', $out);
  $GLOBALS['gg_report']['examples']=['titles'=>[],'trash'=>[],'draft'=>[],'price'=>[],'errors'=>[],'price_debug'=>[],'images'=>[]];
  $GLOBALS['gg_report']['tap']=[]; $GLOBALS['gg_report']['notes']=[];
}

/* =========================
   LISTINGS FETCH + ITEM
   ========================= */
function gg_get_active_listings($page=1){
  $xml = '<?xml version="1.0" encoding="utf-8"?>
    <GetMyeBaySellingRequest xmlns="urn:ebay:apis:eBLBaseComponents">
      <ActiveList><Include>true</Include><Pagination><EntriesPerPage>100</EntriesPerPage><PageNumber>'.$page.'</PageNumber></Pagination></ActiveList>
      <DetailLevel>ReturnAll</DetailLevel>
    </GetMyeBaySellingRequest>';
  $r = gg_trading_call('GetMyeBaySelling',$xml);
  if (is_wp_error($r)) { gg_report_add('errors','Trading error: '.$r->get_error_message()); return array(); }
  $raw=wp_remote_retrieve_body($r); $x=@simplexml_load_string($raw); if(!$x) return array();
  $x->registerXPathNamespace('e','urn:ebay:apis:eBLBaseComponents');
  $items=array();
  foreach ((array)$x->xpath('//e:ActiveList/e:ItemArray/e:Item') as $it){
    $qt = isset($it->Quantity)?(int)$it->Quantity:null;
    $qs = isset($it->SellingStatus->QuantitySold)?(int)$it->SellingStatus->QuantitySold:0;
    $qa = !is_null($qt)?max(0,$qt-$qs):null;
    
    $price = 0.0;
    if (isset($it->StartPrice)) {
      $price = (float)$it->StartPrice;
    } elseif (isset($it->SellingStatus->CurrentPrice)) {
      $price = (float)$it->SellingStatus->CurrentPrice;
    }
    
    $items[] = array(
      'item_id'  => (string)$it->ItemID,
      'title'    => (string)$it->Title,
      'price'    => $price,
      'start'    => isset($it->ListingDetails->StartTime)?(string)$it->ListingDetails->StartTime:'',
      'qty_avail'=> $qa,
    );
  }
  return $items;
}

function gg_get_item($item_id){
  $xml='<?xml version="1.0" encoding="utf-8"?>
    <GetItemRequest xmlns="urn:ebay:apis:eBLBaseComponents">
      <ItemID>'.gg_xml($item_id).'</ItemID>
      <DetailLevel>ReturnAll</DetailLevel>
    </GetItemRequest>';
  $r = gg_trading_call('GetItem',$xml);
  if (is_wp_error($r)) return array();
  $raw=wp_remote_retrieve_body($r); $x=@simplexml_load_string($raw);
  if(!$x||(!in_array((string)$x->Ack,array('Success','Warning')))) return array();

  $qt = isset($x->Item->Quantity)?(int)$x->Item->Quantity:null;
  $qs = isset($x->Item->SellingStatus->QuantitySold)?(int)$x->Item->SellingStatus->QuantitySold:0;
  $qa = !is_null($qt)?max(0,$qt-$qs):null;
  
  $price = 0.0;
  if (isset($x->Item->StartPrice)) {
    $price = (float)$x->Item->StartPrice;
  } elseif (isset($x->Item->SellingStatus->CurrentPrice)) {
    $price = (float)$x->Item->SellingStatus->CurrentPrice;
  }
  
  $img = (string)($x->Item->PictureDetails->GalleryURL ?? '');

  if (!$img){
    $app=gg_token_app();
    if(!is_wp_error($app)){
      $url='https://api.ebay.com/buy/browse/v1/item/get_item_by_legacy_id?legacy_item_id='.rawurlencode($item_id);
      $br=wp_remote_get($url,array('headers'=>array('Authorization'=>'Bearer '.$app),'timeout'=>30));
      if(!is_wp_error($br)){
        $j=json_decode(wp_remote_retrieve_body($br),true);
        if(!empty($j['image']['imageUrl'])) $img=$j['image']['imageUrl'];
        elseif(!empty($j['additionalImages'][0]['imageUrl'])) $img=$j['additionalImages'][0]['imageUrl'];
      }
    }
  }

  return array(
    'title'       => (string)$x->Item->Title,
    'price'       => $price,
    'description' => (string)$x->Item->Description,
    'image'       => $img,
    'qty_avail'   => $qa,
    'sku'         => isset($x->Item->SKU)?(string)$x->Item->SKU:'',
  );
}

/* =========================
   IMAGE ATTACH
   ========================= */
function gg_attach_image($post_id,$url){
  require_once ABSPATH.'wp-admin/includes/file.php';
  require_once ABSPATH.'wp-admin/includes/media.php';
  require_once ABSPATH.'wp-admin/includes/image.php';
  $tmp=download_url($url);
  if (is_wp_error($tmp)) return;
  $file=array('name'=>basename(parse_url($url,PHP_URL_PATH))?:'image.jpg','type'=>'image/jpeg','tmp_name'=>$tmp,'error'=>0,'size'=>@filesize($tmp));
  $id=media_handle_sideload($file,$post_id);
  if(!is_wp_error($id)) set_post_thumbnail($post_id,$id);
  @unlink($tmp);
}

/* =========================
   ADMIN PAGE
   ========================= */
function gg_admin_page(){
  if (!current_user_can('manage_options')) return;
  $s = gg_settings();

  echo '<div class="wrap"><h1>GrimeGames eBay Suite</h1>';

  echo '<div style="margin:8px 0 16px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
  echo '<form method="post" action="'.admin_url('admin-post.php').'" style="margin:0;">'
     . '<input type="hidden" name="action" value="gg_sync_now">'.wp_nonce_field('gg_sync_now','_wpnonce',true,false)
     . '<button class="button button-primary">🔁 Full Sync (eBay → Woo)</button>'
     . '</form>';
  echo '<form method="post" action="'.admin_url('admin-post.php').'" style="margin:0;">'
     . '<input type="hidden" name="action" value="gg_stock_sync_now">'.wp_nonce_field('gg_stock_sync_now','_wpnonce',true,false)
     . '<button class="button button-secondary">📦 Stock Only Sync</button>'
     . '</form>';
  echo '<span style="color:#666;font-size:12px;">Stock Only: fast, no images — use to correct quantities after eBay sales</span>';
  echo '</div>';

  gg_render_suite_settings();
  gg_render_auth_settings();

  if (!gg_get_option('ebay_access_token')) { echo '<p>❌ No access token yet. Save OAuth and authorize.</p></div>'; return; }

  $all_items = [];
  $ebay_page = 1;
  while (true) {
    $page_items = gg_get_active_listings($ebay_page);
    if (!$page_items) break;
    $all_items = array_merge($all_items, $page_items);
    if (count($page_items) < 100) break;
    $ebay_page++;
    if ($ebay_page > 50) break;
  }
  
  $items = array_filter($all_items, function($item) {
    $item_id = $item['item_id'] ?? '';
    if (!$item_id) return false;
    $existing = get_posts(array(
      'post_type' => 'product',
      'post_status' => array('publish', 'draft', 'pending', 'private'),
      'meta_query' => array(array('key' => '_gg_ebay_item_id', 'value' => $item_id, 'compare' => '=')),
      'fields' => 'ids',
      'posts_per_page' => 1,
      'no_found_rows' => true,
    ));
    if (!empty($existing)) return false;
    return true;
  });

  echo '<h2>Import Listings ('.count($items).' new)</h2>';

  usort($items, function($a,$b) {
    $aTs=!empty($a['start'])?strtotime($a['start']):0; 
    $bTs=!empty($b['start'])?strtotime($b['start']):0;
    return $bTs<=>$aTs;
  });

  $cats=get_terms(array('taxonomy'=>'product_cat','hide_empty'=>false));
  echo '<form method="post" action="'.admin_url('admin-post.php').'">';
  echo '<input type="hidden" name="action" value="gg_import_selected">'.wp_nonce_field('gg_import_selected','_wpnonce',true,false);
  
  echo '<div style="margin-bottom:15px;padding:12px;background:#f0f0f1;border-radius:4px;">';
  echo '<div style="margin-bottom:10px;">';
  echo '<label style="font-weight:600;"><input type="checkbox" id="gg_individual_cats" onchange="ggToggleIndividualCats(this.checked)"> Assign categories individually</label>';
  echo '</div>';
  echo '<div id="gg_mass_category" style="margin-bottom:8px;">';
  echo '<label style="font-weight:600;margin-right:8px;">Category for all selected:</label>';
  echo '<select name="woo_category" id="gg_mass_cat_select" required><option value="">Select Category</option>';
  foreach($cats as $c) echo '<option value="'.esc_attr($c->term_id).'">'.esc_html($c->name).'</option>';
  echo '</select>';
  echo '</div>';
  echo '<button class="button button-primary">Import Selected</button>';
  echo '</div>';

  echo '<ul style="list-style:none;padding:0;margin-top:12px;">';
  foreach($items as $it){
    $id=esc_attr($it['item_id']); $t=esc_html($it['title']); $p=number_format((float)$it['price'],2);
    $st=!empty($it['start'])?date('Y-m-d H:i',strtotime($it['start'])):'—';
    echo '<li style="margin-bottom:10px;padding:8px;background:#fff;border:1px solid #ddd;border-radius:4px;">';
    echo '<label style="display:flex;align-items:center;gap:8px;">';
    echo '<input type="checkbox" name="selected_items[]" value="'.$id.'"> ';
    echo '<div style="flex:1;"><strong>'.$t.'</strong> — £'.$p.' <span style="opacity:.7">• Listed: '.$st.'</span></div>';
    echo '</label>';
    echo '<div class="gg_individual_cat_row" style="display:none;margin-top:8px;padding-left:24px;">';
    echo '<label style="font-weight:600;margin-right:6px;">Category:</label>';
    echo '<select name="item_categories['.$id.']"><option value="">Use default</option>';
    foreach($cats as $c) echo '<option value="'.esc_attr($c->term_id).'">'.esc_html($c->name).'</option>';
    echo '</select>';
    echo '</div>';
    echo '</li>';
  }
  echo '</ul></form>';
  
  ?>
  <script>
  function ggToggleIndividualCats(enabled) {
    const massDiv = document.getElementById('gg_mass_category');
    const massSelect = document.getElementById('gg_mass_cat_select');
    const individualRows = document.querySelectorAll('.gg_individual_cat_row');
    if (enabled) {
      massDiv.style.display = 'none';
      massSelect.removeAttribute('required');
      individualRows.forEach(row => row.style.display = 'block');
    } else {
      massDiv.style.display = 'block';
      massSelect.setAttribute('required', 'required');
      individualRows.forEach(row => row.style.display = 'none');
    }
  }
  </script>
  <?php
  echo '</div>';
}

/* =========================
   SUITE SETTINGS
   ========================= */
function gg_render_suite_settings(){
  if (!current_user_can('manage_options')) return;

  if (!empty($_POST['gg_settings_save']) && check_admin_referer('gg_settings_save')) {
    $new = array(
      'throttle'       => !empty($_POST['throttle']) ? 1:0,
      'pagesize10'     => !empty($_POST['pagesize10']) ? 1:0,
      'auto_image'     => !empty($_POST['auto_image']) ? 1:0,
      'disable_cron'   => !empty($_POST['disable_cron']) ? 1:0,
      'max_per_run'    => max(0, intval($_POST['max_per_run'] ?? 0)),
      'breaker_on_518' => !empty($_POST['breaker_on_518']) ? 1:0,
      'verbose_skips'  => !empty($_POST['verbose_skips']) ? 1:0,
      'price_sync_debug' => !empty($_POST['price_sync_debug']) ? 1:0,
      'sync_images'    => !empty($_POST['sync_images']) ? 1:0,
    );
    update_option('gg_suite_settings',$new);
    echo '<div class="updated"><p>Settings saved.</p></div>';
  }

  $s = gg_settings();

  echo '<h2>Importer Settings</h2><form method="post"><table class="form-table">';
  echo '<tr><td><label><input type="checkbox" name="throttle" '.checked($s['throttle'],1,false).'> Throttle requests (3s gap, cap 20)</label></td></tr>';
  echo '<tr><td><label><input type="checkbox" name="pagesize10" '.checked($s['pagesize10'],1,false).'> Force EntriesPerPage=10</label></td></tr>';
  echo '<tr><td><label><input type="checkbox" name="auto_image" '.checked($s['auto_image'],1,false).'> Auto-attach images on import</label></td></tr>';
  echo '<tr><td><label><input type="checkbox" name="sync_images" '.checked($s['sync_images'],1,false).'> <strong>Sync images during eBay → Woo sync (update if changed)</strong></label></td></tr>';
  echo '<tr><td><label><input type="checkbox" name="disable_cron" '.checked($s['disable_cron'],1,false).'> Disable 5-min background cron</label></td></tr>';
  echo '<tr><td>Max items per run (0 = unlimited): <input type="number" name="max_per_run" min="0" max="10000" value="'.esc_attr($s['max_per_run']).'"></td></tr>';
  echo '<tr><td><label><input type="checkbox" name="breaker_on_518" '.checked($s['breaker_on_518'],1,false).'> Circuit break on 518 (pause 24h)</label></td></tr>';
  echo '<tr><td><label><input type="checkbox" name="verbose_skips" '.checked($s['verbose_skips'],1,false).'> Verbose "skipped increase" lines</label></td></tr>';
  echo '<tr><td><label><input type="checkbox" name="price_sync_debug" '.checked($s['price_sync_debug'],1,false).'> <strong>Show detailed price sync debug info</strong></label></td></tr>';
  echo '</table>'; wp_nonce_field('gg_settings_save');
  echo '<p><button class="button button-primary" name="gg_settings_save" value="1">Save Settings</button></p></form>';
}

/* IMPORT SELECTED */
add_action('admin_post_gg_import_selected', function(){
  if (!current_user_can('manage_options')) wp_die('Not allowed');
  check_admin_referer('gg_import_selected');

  $selected = (array)($_POST['selected_items'] ?? array());
  $cat_id   = (int)($_POST['woo_category'] ?? 0);
  $item_cats = (array)($_POST['item_categories'] ?? array());
  $s        = gg_settings();

  foreach ($selected as $item_id){
    $item_id=sanitize_text_field($item_id);
    $d=gg_get_item($item_id);
    if(!$d){ gg_report_add('errors',"GetItem empty for $item_id"); continue; }

    $title=$d['title'] ?: 'Untitled';
    $price=(float)($d['price'] ?? 0);
    $desc=$d['description']; $img=$d['image']; $qty = isset($d['qty_avail'])?max(0,(int)$d['qty_avail']):null;

    $sku=trim((string)($d['sku'] ?? ''));
    if($sku===''){ if(preg_match('/([A-Z]+-EN\d{3})/',$title,$m)) $sku=$m[1]; }

    $pid=wp_insert_post(array('post_title'=>$title,'post_content'=>$desc,'post_status'=>'publish','post_type'=>'product'));
    if (is_wp_error($pid) || !$pid){ gg_report_add('errors',"Failed to insert product for $item_id"); continue; }
    wp_set_object_terms($pid,'simple','product_type');
    
    $use_cat = isset($item_cats[$item_id]) && (int)$item_cats[$item_id] > 0 ? (int)$item_cats[$item_id] : $cat_id;
    if($use_cat) wp_set_post_terms($pid,array($use_cat),'product_cat');

    $reg = $price;
    $sale = max(0.01, round($reg * 0.95, 2));
    update_post_meta($pid,'_regular_price',$reg);
    update_post_meta($pid,'_sale_price',$sale);
    update_post_meta($pid,'_price',$sale);
    update_post_meta($pid,'_gg_import_discount',1);

    if($sku){ update_post_meta($pid,'_sku',$sku); update_post_meta($pid,'_set_code',$sku); }
    update_post_meta($pid,'_gg_ebay_item_id',$item_id);
    update_post_meta($pid,'_gg_ebay_last_price',$price);
    
    if($qty !== null){
      update_post_meta($pid,'_manage_stock','yes');
      if (function_exists('wc_update_product_stock')) wc_update_product_stock($pid,$qty,'set');
      update_post_meta($pid,'_stock_status',$qty>0?'instock':'outofstock');
    }
    if($img){
      update_post_meta($pid,'_gg_ebay_primary_image_url',esc_url_raw($img));
      if($s['auto_image']) gg_attach_image($pid,$img);
    }
    gg_report_add('notes',"Imported PID {$pid} from eBay {$item_id}");
  }

  wp_redirect(admin_url('admin.php?page=gg-ebay-suite&imported=1')); exit;
});

/* =========================
   SYNC RUNNER - WITH IMAGE SYNC
   ========================= */
function gg_sync_once(){
  $s         = gg_settings();
  $page      = 1;
  $active_ids= array();
  $now       = time();

  if (get_transient('gg_sync_lock')) { gg_report_add('errors','Sync skipped: another sync is running.'); return 0; }
  set_transient('gg_sync_lock', 1, 5 * MINUTE_IN_SECONDS);

  $updated = 0;
  
  // PHASE 1
  gg_report_add('notes', 'Phase 1: Collecting all active eBay listings...');
  $all_items = array();
  while (true) {
    $items = gg_get_active_listings($page);
    if (!$items) break;

    foreach ($items as $it) {
      if (empty($it['item_id'])) continue;
      $iid = (string)$it['item_id'];
      $active_ids[] = $iid;
      $all_items[$iid] = $it;
    }

    if (count($items) < 100) break;
    $page++;
    if ($page > 50) { gg_report_add('notes', 'Stopped at page 50 safety limit'); break; }
  }
  
  $active_ids = array_unique($active_ids);
  gg_report_add('notes', '✅ Collected ' . count($active_ids) . ' active eBay IDs');

  if (empty($active_ids)) {
    gg_report_add('errors', 'Sync aborted: No active eBay IDs collected.');
    update_option('gg_last_sync_time', time());
    delete_transient('gg_sync_lock');
    return 0;
  }

  // PHASE 2
  gg_report_add('notes', '✅ Phase 2: Syncing products with price + image sync...');
  $synced = 0;
  
  foreach ($all_items as $iid => $it) {
    if ($s['max_per_run'] > 0 && $synced >= $s['max_per_run']) { 
      gg_report_add('notes','Sync capped at '.$s['max_per_run'].' items'); 
      break; 
    }

    if (empty($it['item_id'])) continue;
    $iid = (string)$it['item_id'];

    $q = new WP_Query(array(
      'post_type' => 'product',
      'meta_key' => '_gg_ebay_item_id',
      'meta_value' => $iid,
      'fields' => 'ids',
      'posts_per_page' => 1,
      'no_found_rows' => true,
    ));
    
    if (!$q->have_posts()) continue;
    
    $synced++;
    $pid = (int) $q->posts[0];
    update_post_meta($pid, '_gg_last_seen_on_ebay', $now);

    // Title sync
    $eb_title = (string)($it['title'] ?? '');
    if ($eb_title !== '') {
      $cur = get_post($pid);
      if ($cur && $cur->post_title !== $eb_title) {
        wp_update_post(array('ID'=>$pid,'post_title'=>$eb_title));
        gg_report_add('titles', "PID {$pid} title updated");
      }
    }

    // Price sync
    if (array_key_exists('price',$it) && $it['price'] > 0) {
      $ebay_original_price = (float)$it['price'];
      $current_regular = (float)get_post_meta($pid, '_regular_price', true);
      $target_regular = $ebay_original_price;
      $target_sale = max(0.01, round($ebay_original_price * 0.95, 2));
      
      $price_mismatch = (abs($current_regular - $target_regular) > 0.01);
      
      if ($price_mismatch) {
        update_post_meta($pid, '_regular_price', $target_regular);
        update_post_meta($pid, '_sale_price', $target_sale);
        update_post_meta($pid, '_price', $target_sale);
        if (function_exists('wc_delete_product_transients')) wc_delete_product_transients($pid);
        gg_report_add('price', sprintf("✅ PID %d: £%.2f → £%.2f", $pid, $ebay_original_price, $target_regular));
        $updated++;
      }
    }

    // IMAGE SYNC - IMPROVED VERSION
    if ($s['sync_images']) {
      $full_item = gg_get_item($iid);
      if (!empty($full_item['image'])) {
        $ebay_img_url = esc_url_raw($full_item['image']);
        $stored_img_url = get_post_meta($pid, '_gg_ebay_primary_image_url', true);
        
        $normalize_url = function($url) {
          $url = preg_replace('/^https?:\/\//', '', $url);
          $url = preg_replace('/\/s-l\d+\./', '/s-l1600.', $url);
          return $url;
        };
        
        $ebay_normalized = $normalize_url($ebay_img_url);
        $stored_normalized = $normalize_url($stored_img_url);
        
        $current_thumb_id = get_post_thumbnail_id($pid);
        $needs_update = ($ebay_normalized !== $stored_normalized) || !$current_thumb_id;
        
        if ($needs_update) {
          if ($current_thumb_id) {
            wp_delete_attachment($current_thumb_id, true);
          }
          gg_attach_image($pid, $ebay_img_url);
          update_post_meta($pid, '_gg_ebay_primary_image_url', $ebay_img_url);
          $reason = !$current_thumb_id ? 'No thumbnail' : 'URL changed';
          gg_report_add('images', sprintf('PID %d: Image updated (%s)', $pid, $reason));
        }
      }
    }

    // Stock sync
    if (array_key_exists('qty_avail',$it) && $it['qty_avail'] !== null) {
      $qty = max(0,(int)$it['qty_avail']);
      update_post_meta($pid, '_manage_stock', 'yes');
      if (function_exists('wc_update_product_stock')) wc_update_product_stock($pid,$qty,'set');
      else update_post_meta($pid,'_stock',$qty);
      update_post_meta($pid,'_stock_status', $qty>0 ? 'instock' : 'outofstock');
    }
  }
  
  gg_report_add('notes', sprintf('✅ Phase 2 complete: Checked %d products, updated %d prices', $synced, $updated));

  // PHASE 3: Deletions
  $to_delete = array();
  $q = new WP_Query(array(
    'post_type' => 'product',
    'meta_key' => '_gg_ebay_item_id',
    'fields' => 'ids',
    'posts_per_page' => -1,
    'no_found_rows' => true,
  ));
  
  if ($q->have_posts()) {
    foreach ($q->posts as $pid) {
      $iid = get_post_meta($pid, '_gg_ebay_item_id', true);
      if (!$iid) continue;
      if (!in_array($iid, $active_ids, true)) {
        $last_seen = (int) get_post_meta($pid, '_gg_last_seen_on_ebay', true);
        $age_ok = (GG_GRACE_HOURS <= 0) ? true : ((time() - $last_seen) >= GG_GRACE_HOURS * HOUR_IN_SECONDS);
        if (!$age_ok) continue;
        $product = get_post($pid);
        $to_delete[] = array('id' => $pid, 'title' => $product->post_title, 'ebay_id' => $iid);
      }
    }
  }
  
  if (!empty($to_delete)) {
    set_transient('gg_pending_deletions', $to_delete, HOUR_IN_SECONDS);
    gg_report_add('notes', count($to_delete) . ' product(s) flagged for deletion');
  }

  if (defined('GG_DEDUPE_ON_SYNC') && GG_DEDUPE_ON_SYNC) gg_dedupe_products();
  update_option('gg_last_sync_time', time());
  delete_transient('gg_sync_lock');
  return $updated;
}

add_action('admin_post_gg_manual_update_product', function() {
  if (!current_user_can('manage_options')) wp_die('Access denied');
  check_admin_referer('gg_manual_update_product');
  
  $ebay_item_id = sanitize_text_field($_POST['ebay_item_id'] ?? '');
  
  if (!$ebay_item_id) {
    set_transient('gg_manual_update_notice', array('type' => 'error', 'message' => 'Please enter an eBay Item ID'), 60);
    wp_safe_redirect(admin_url('admin.php?page=gg-ebay-diagnostics'));
    exit;
  }
  
  $item_data = gg_get_item($ebay_item_id);
  
  if (empty($item_data)) {
    set_transient('gg_manual_update_notice', array('type' => 'error', 'message' => "Could not fetch eBay item {$ebay_item_id} from API"), 60);
    wp_safe_redirect(admin_url('admin.php?page=gg-ebay-diagnostics'));
    exit;
  }
  
  $q = new WP_Query(array(
    'post_type' => 'product',
    'meta_key' => '_gg_ebay_item_id',
    'meta_value' => $ebay_item_id,
    'fields' => 'ids',
    'posts_per_page' => 1,
  ));
  
  if (!$q->have_posts()) {
    set_transient('gg_manual_update_notice', array('type' => 'error', 'message' => "No WooCommerce product found linked to eBay item {$ebay_item_id}"), 60);
    wp_safe_redirect(admin_url('admin.php?page=gg-ebay-diagnostics'));
    exit;
  }
  
  $pid = $q->posts[0];
  $ebay_price = (float)($item_data['price'] ?? 0);
  
  if ($ebay_price <= 0) {
    set_transient('gg_manual_update_notice', array('type' => 'error', 'message' => "eBay item {$ebay_item_id} has no valid price"), 60);
    wp_safe_redirect(admin_url('admin.php?page=gg-ebay-diagnostics'));
    exit;
  }
  
  $target_regular = $ebay_price;
  $target_sale = max(0.01, round($ebay_price * 0.95, 2));
  
  $old_regular = get_post_meta($pid, '_regular_price', true);
  
  update_post_meta($pid, '_regular_price', $target_regular);
  update_post_meta($pid, '_sale_price', $target_sale);
  update_post_meta($pid, '_price', $target_sale);
  update_post_meta($pid, '_gg_ebay_last_price', $ebay_price);
  
  if (function_exists('wc_delete_product_transients')) {
    wc_delete_product_transients($pid);
  }
  
  $message = sprintf(
    'Product #%d updated! eBay: £%.2f → Woo Regular: £%.2f (was £%.2f) / Sale: £%.2f',
    $pid, $ebay_price, $target_regular, (float)$old_regular, $target_sale
  );
  
  set_transient('gg_manual_update_notice', array('type' => 'success', 'message' => $message), 60);
  wp_safe_redirect(admin_url('admin.php?page=gg-ebay-diagnostics'));
  exit;
});

add_action('admin_notices', function() {
  if (($_GET['page'] ?? '') !== 'gg-ebay-diagnostics') return;
  $notice = get_transient('gg_manual_update_notice');
  if ($notice) {
    delete_transient('gg_manual_update_notice');
    $class = $notice['type'] === 'error' ? 'notice-error' : 'notice-success';
    echo '<div class="notice ' . $class . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
  }
  $notice = get_transient('gg_autolink_notice');
  if ($notice) {
    delete_transient('gg_autolink_notice');
    $class = $notice['type'] === 'error' ? 'notice-error' : 'notice-success';
    echo '<div class="notice ' . $class . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
  }
});

add_action('admin_post_gg_autolink_products', function() {
  if (!current_user_can('manage_options')) wp_die('Access denied');
  check_admin_referer('gg_autolink_products');
  
  $products = new WP_Query(array(
    'post_type' => 'product',
    'post_status' => 'any',
    'posts_per_page' => 50,
    'meta_query' => array(
      array('key' => '_gg_ebay_item_id', 'compare' => 'NOT EXISTS')
    ),
  ));
  
  $linked = 0;
  $failed = 0;
  
  $ebay_listings = array();
  $page = 1;
  while ($page <= 10) {
    $items = gg_get_active_listings($page);
    if (!$items || empty($items)) break;
    foreach ($items as $item) {
      if (!empty($item['item_id']) && !empty($item['title'])) {
        $ebay_listings[$item['item_id']] = array(
          'title' => strtolower($item['title']),
          'price' => $item['price'] ?? 0,
        );
      }
    }
    if (count($items) < 100) break;
    $page++;
  }
  
  if ($products->have_posts()) {
    while ($products->have_posts()) {
      $products->the_post();
      $pid = get_the_ID();
      $woo_title = strtolower(get_the_title());
      
      $best_match = null;
      $best_similarity = 0;
      
      foreach ($ebay_listings as $ebay_id => $ebay_data) {
        similar_text($woo_title, $ebay_data['title'], $similarity);
        if ($similarity > $best_similarity && $similarity > 80) {
          $best_similarity = $similarity;
          $best_match = $ebay_id;
        }
      }
      
      if ($best_match) {
        update_post_meta($pid, '_gg_ebay_item_id', $best_match);
        $linked++;
      } else {
        $failed++;
      }
    }
    wp_reset_postdata();
  }
  
  $message = "Auto-link complete: {$linked} linked, {$failed} failed (no match found)";
  set_transient('gg_autolink_notice', array('type' => 'success', 'message' => $message), 60);
  wp_safe_redirect(admin_url('admin.php?page=gg-ebay-diagnostics'));
  exit;
});

/* SYNC NOW BUTTON */

/* STOCK ONLY SYNC — skips image sync for speed */
add_action('admin_post_gg_stock_sync_now', function(){
  if (!current_user_can('manage_options')) wp_die('Not allowed');
  check_admin_referer('gg_stock_sync_now');

  echo '<div class="wrap"><h1>📦 Stock Only Sync Running...</h1>';
  echo '<div class="notice notice-info"><p><strong>🔄 Stock sync started at '.date('H:i:s').'</strong><br>Using existing sync engine — images skipped.</p></div>';
  flush();

  // Temporarily disable image sync then run the proven gg_sync_once()
  $s = gg_settings();
  $original_sync_images = $s['sync_images'];
  $s['sync_images'] = 0;
  update_option('gg_suite_settings', $s);

  // Clear sync lock in case a previous sync left it set
  delete_transient('gg_sync_lock');

  gg_report_boot();
  gg_report_add('notes', 'Running stock-only sync (via gg_sync_once, images disabled)');

  $updated = gg_sync_once();

  // Restore image sync setting
  $s['sync_images'] = $original_sync_images;
  update_option('gg_suite_settings', $s);

  echo '<div class="notice notice-success"><p><strong>✅ Stock sync complete at '.date('H:i:s').'</strong><br>';
  echo $updated . ' price(s) updated. Stock quantities corrected from eBay.</p></div>';
  flush();

  gg_report_render('Stock Only Sync');

  echo '<p><a href="'.admin_url('admin.php?page=gg-ebay-suite').'" class="button button-primary">← Back to eBay Suite</a></p>';
  echo '</div>';
});

add_action('admin_post_gg_sync_now', function(){
  if (!current_user_can('manage_options')) wp_die('Not allowed');
  check_admin_referer('gg_sync_now');

  echo '<div class="wrap"><h1>eBay Sync Running...</h1>';
  echo '<div class="notice notice-info"><p><strong>🔄 Sync started at '.date('H:i:s').'</strong></p></div>';
  flush();

  gg_report_boot();
  gg_report_add('notes','Running eBay → Woo sync v3.8');
  
  $before_count = wp_count_posts('product');
  echo '<div class="notice notice-info"><p>📦 Total products in WooCommerce: '.$before_count->publish.'</p></div>';
  flush();
  
  $pulled = gg_sync_once();
  
  echo '<div class="notice notice-success"><p><strong>✅ Sync completed at '.date('H:i:s').'</strong></p></div>';
  flush();
  
  gg_report_render('eBay → Woo v3.8');

  update_option('gg_last_sync_time', time());
  
  $pending = get_transient('gg_pending_deletions');
  if (!empty($pending)) {
    echo '<p><a href="'.admin_url('admin.php?page=gg-ebay-confirm-delete').'" class="button button-primary">Review Pending Deletions</a></p>';
    echo '<p><a href="'.admin_url('admin.php?page=gg-ebay-suite').'" class="button">Back to eBay Suite</a></p>';
    echo '</div>';
    exit;
  }
  
  echo '<p><a href="'.admin_url('admin.php?page=gg-ebay-suite').'" class="button button-primary">Back to eBay Suite</a></p>';
  echo '</div>';
  exit;
});

/* CRON */
add_filter('cron_schedules',function($s){ $s['every_five_minutes']=array('interval'=>300,'display'=>'Every 5 Minutes'); return $s; });
register_activation_hook(__FILE__,function(){ if(!wp_next_scheduled('gg_sync_cron')) wp_schedule_event(time()+60,'every_five_minutes','gg_sync_cron'); });
register_deactivation_hook(__FILE__,function(){ $ts=wp_next_scheduled('gg_sync_cron'); if($ts) wp_unschedule_event($ts,'gg_sync_cron'); });
add_action('gg_sync_cron',function(){
  $s=gg_settings(); if(!empty($s['disable_cron'])) return;
  gg_report_boot();
  gg_report_add('notes','Cron: eBay → Woo sync run');
  gg_sync_once();
  gg_report_render('Cron');
});

/* ADMIN NOTICES */
add_action('admin_notices',function(){
  if(!current_user_can('manage_options')) return;
  
  if (isset($_GET['page']) && $_GET['page'] === 'gg-ebay-suite') {
    $report = get_transient('gg_sync_report');
    if ($report) {
      echo '<div class="notice notice-info"><pre style="white-space:pre-wrap;margin:0;font-size:12px;">'.esc_html($report).'</pre></div>';
      delete_transient('gg_sync_report');
    }
  }
  
  $last=(int)get_option('gg_last_sync_time');
  if($last) echo '<div class="notice notice-success is-dismissible"><p>🕒 Last eBay→Woo sync: <code>'.esc_html(date('Y-m-d H:i:s',$last)).'</code></p></div>';
  $until=gg_backoff_until(); if($until && time()<$until) echo '<div class="notice notice-warning is-dismissible"><p>⏳ 518 breaker active until <code>'.esc_html(date('Y-m-d H:i:s',$until)).'</code></p></div>';
});

/* =========================
   DE-DUPE
   ========================= */
if (!function_exists('gg_dedupe_products')) {
function gg_dedupe_products(){
  $groups = [];
  $paged  = 1;
  $per    = 200;

  do {
    $q = new WP_Query([
      'post_type'      => 'product',
      'post_status'    => 'any',
      'fields'         => 'ids',
      'posts_per_page' => $per,
      'paged'          => $paged,
      'no_found_rows'  => false,
      'meta_query'     => [[ 'key' => '_gg_ebay_item_id', 'compare' => 'EXISTS' ]],
    ]);
    if (!$q->have_posts()) break;

    foreach ($q->posts as $pid) {
      $iid = trim((string) get_post_meta($pid, '_gg_ebay_item_id', true));
      if ($iid === '') continue;
      $groups[$iid][] = (int)$pid;
    }
    $paged++;
  } while ($q->max_num_pages && $paged <= $q->max_num_pages);

  foreach ($groups as $iid => $ids) {
    if (count($ids) <= 1) continue;

    usort($ids, function($a,$b){
      $thA = get_post_thumbnail_id($a) ? 1 : 0;
      $thB = get_post_thumbnail_id($b) ? 1 : 0;
      if ($thA !== $thB) return $thB - $thA;

      $stA = (int) ( get_post_meta($a,'_stock',true) !== '' ? get_post_meta($a,'_stock',true) : 0 );
      $stB = (int) ( get_post_meta($b,'_stock',true) !== '' ? get_post_meta($b,'_stock',true) : 0 );
      if ($stA !== $stB) return $stB - $stA;

      $mA  = strtotime(get_post_field('post_modified',$a));
      $mB  = strtotime(get_post_field('post_modified',$b));
      if ($mA !== $mB) return $mB - $mA;

      return $b - $a;
    });

    $keep = array_shift($ids);
    foreach ($ids as $dup) {
      if (GG_DELETE_MODE === 'delete') { wp_delete_post($dup, true); gg_report_add('trash', "Deleted duplicate PID {$dup} (eBay {$iid}), kept PID {$keep}."); }
      else { wp_trash_post($dup); gg_report_add('trash', "Trashed duplicate PID {$dup} (eBay {$iid}), kept PID {$keep}."); }
    }
  }
}}

/* =========================
   DELETION CONFIRMATION
   ========================= */
add_action('admin_menu', function () {
  add_submenu_page(null,'Confirm Deletions','Confirm Deletions','manage_woocommerce','gg-ebay-confirm-delete','gg_confirm_delete_page');
});

function gg_confirm_delete_page() {
  if (!current_user_can('manage_woocommerce')) wp_die('Access denied');
  
  $pending = get_transient('gg_pending_deletions');
  
  if (empty($pending)) {
    echo '<div class="wrap"><h1>Product Deletion</h1>';
    echo '<div class="notice notice-success"><p>✅ No products flagged for deletion.</p></div>';
    echo '<a href="' . admin_url('admin.php?page=gg-ebay-suite') . '" class="button">Back to eBay Suite</a>';
    echo '</div>';
    return;
  }
  
  echo '<div class="wrap"><h1>⚠️ Confirm Product Deletions</h1>';
  echo '<div class="notice notice-warning"><p><strong>The following products are no longer on eBay and will be deleted from WooCommerce.</strong><br>';
  echo 'Uncheck any items you want to keep, then click "Delete Selected".</p></div>';
  
  echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
  wp_nonce_field('gg_confirm_delete');
  echo '<input type="hidden" name="action" value="gg_execute_delete">';
  
  echo '<div style="margin:15px 0;padding:10px;background:#f0f0f1;border-radius:4px;">';
  echo '<button type="submit" class="button button-primary" onclick="return confirm(\'Are you sure?\')">🗑️ Delete Selected</button> ';
  echo '<button type="button" class="button" onclick="ggSelectAllDel(true)">Select All</button> ';
  echo '<button type="button" class="button" onclick="ggSelectAllDel(false)">Deselect All</button> ';
  echo '<a href="' . admin_url('admin-post.php?action=gg_cancel_delete') . '" class="button">Cancel (Keep All)</a>';
  echo '<span id="gg-del-count" style="margin-left:15px;font-weight:500;">0 selected</span>';
  echo '</div>';
  
  echo '<table class="widefat striped"><thead><tr>';
  echo '<th style="width:30px;"><input type="checkbox" id="gg-select-all-del" onclick="ggSelectAllDel(this.checked)" checked></th>';
  echo '<th>Product ID</th><th>Product Title</th><th>eBay Item ID</th>';
  echo '</tr></thead><tbody>';
  
  foreach ($pending as $idx => $item) {
    echo '<tr>';
    echo '<td><input type="checkbox" class="gg-del-checkbox" name="delete_ids[]" value="' . esc_attr($item['id']) . '" onchange="ggUpdateDelCount()" checked></td>';
    echo '<td>' . esc_html($item['id']) . '</td>';
    echo '<td><strong>' . esc_html($item['title']) . '</strong></td>';
    echo '<td><code>' . esc_html($item['ebay_id']) . '</code></td>';
    echo '</tr>';
  }
  
  echo '</tbody></table>';
  
  echo '<div style="margin:15px 0;padding:10px;background:#f0f0f1;border-radius:4px;">';
  echo '<button type="submit" class="button button-primary" onclick="return confirm(\'Are you sure?\')">🗑️ Delete Selected</button> ';
  echo '<a href="' . admin_url('admin-post.php?action=gg_cancel_delete') . '" class="button">Cancel (Keep All)</a>';
  echo '</div></form>';
  
  ?>
  <script>
  function ggSelectAllDel(c) {
    document.querySelectorAll('.gg-del-checkbox').forEach(x => x.checked = c);
    document.getElementById('gg-select-all-del').checked = c;
    ggUpdateDelCount();
  }
  function ggUpdateDelCount() {
    var n = document.querySelectorAll('.gg-del-checkbox:checked').length;
    document.getElementById('gg-del-count').textContent = n + ' selected';
  }
  document.addEventListener('DOMContentLoaded', ggUpdateDelCount);
  </script>
  <?php
  echo '</div>';
}

add_action('admin_post_gg_execute_delete', function () {
  if (!current_user_can('manage_woocommerce')) wp_die('Access denied');
  check_admin_referer('gg_confirm_delete');
  
  $delete_ids = $_POST['delete_ids'] ?? [];
  $deleted = 0;
  
  foreach ($delete_ids as $pid) {
    $pid = (int) $pid;
    if ($pid <= 0) continue;
    if (!get_post_meta($pid, '_gg_ebay_item_id', true)) continue;
    if (GG_DELETE_MODE === 'delete') wp_delete_post($pid, true);
    else wp_trash_post($pid);
    $deleted++;
  }
  
  delete_transient('gg_pending_deletions');
  $mode = GG_DELETE_MODE === 'delete' ? 'deleted' : 'trashed';
  set_transient('gg_delete_notice', ['type' => 'success', 'message' => "Successfully {$mode} {$deleted} product(s)."], 60);
  wp_safe_redirect(admin_url('admin.php?page=gg-ebay-suite')); exit;
});

add_action('admin_post_gg_cancel_delete', function () {
  if (!current_user_can('manage_woocommerce')) wp_die('Access denied');
  delete_transient('gg_pending_deletions');
  set_transient('gg_delete_notice', ['type' => 'info', 'message' => 'Deletion cancelled. All products kept.'], 60);
  wp_safe_redirect(admin_url('admin.php?page=gg-ebay-suite')); exit;
});

add_action('admin_notices', function () {
  if (($_GET['page'] ?? '') !== 'gg-ebay-suite') return;
  $notice = get_transient('gg_delete_notice');
  if (!$notice) return;
  delete_transient('gg_delete_notice');
  $class = $notice['type'] === 'error' ? 'notice-error' : ($notice['type'] === 'info' ? 'notice-info' : ($notice['type'] === 'warning' ? 'notice-warning' : 'notice-success'));
  echo '<div class="notice ' . $class . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
});

/* =========================
   SNAPSHOT & PRICING ENGINE - WITH RARITY-AWARE SEARCH FIX
   ========================= */
define('GG_MY_EBAY_SELLER_NAME', 'grimegames');
define('GG_EBAY_MIN_PRICE', 0.99);

if (!function_exists('gg_snap_detect_rarity_bucket')) {
  function gg_snap_detect_rarity_bucket($title_raw) {
    $t = strtolower((string)$title_raw);
    $t = str_replace(['/', '\\', '_', ',', '.', '-', '|', ':', ';', '(', ')', '[', ']', '!', '?', '"', "'", '*', '+', '&'], ' ', $t);
    $t = preg_replace('/\s+/', ' ', $t); $t = trim($t);

    $has_any = function($needles) use ($t) {
      foreach ((array)$needles as $n) { if (strpos($t, $n) !== false) return true; }
      return false;
    };

    if (strpos($t, 'quarter century secret') !== false || strpos($t, 'qcscr') !== false || strpos($t, 'qcsr') !== false ||
      ((strpos($t, 'quarter century') !== false || strpos($t, '25th') !== false) && strpos($t, 'secret') !== false &&
       strpos($t, 'quarter century stampede') === false && strpos($t, 'qc stampede') === false)) {
      if (strpos($t, 'platinum') !== false) return 'platinum_secret';
      return 'quarter_century_secret';
    }
    if (strpos($t, 'platinum') !== false && (strpos($t, 'secret') !== false || strpos($t, 'scr') !== false)) return 'platinum_secret';
    if ((strpos($t, 'quarter century') !== false || strpos($t, '25th') !== false || strpos($t, 'qcse') !== false || strpos($t, 'qcr') !== false) &&
      strpos($t, 'quarter century stampede') === false && strpos($t, 'qc stampede') === false) return 'quarter_century';
    if (strpos($t, 'stamp') !== false && strpos($t, 'stampede') === false) {
      if (strpos($t, 'ultra') !== false) return 'ultra_stamp';
      if (strpos($t, 'secret') !== false || strpos($t, 'scr') !== false) return 'secret_stamp';
      return 'stamp_misc';
    }
    if ($has_any(['ghost rare', 'ghost r'])) return 'ghost';
    if (strpos($t, 'starlight') !== false) return 'starlight';
    if ($has_any(['ultimate rare', 'ulti rare', 'ultimate r'])) return 'ultimate';
    if (strpos($t, 'collector') !== false || preg_match('/\bcr\b/', $t)) return 'collectors';
    if (strpos($t, 'secret') !== false || strpos($t, 'prismatic') !== false || strpos($t, 'pscr') !== false || preg_match('/\bscr\b/', $t)) return 'secret';
    if (strpos($t, 'ultra') !== false || preg_match('/\bur\b/', $t)) return 'ultra';
    if (strpos($t, 'super') !== false || preg_match('/\bsr\b/', $t)) return 'super';
    if (strpos($t, 'rare') !== false) return 'rare';
    return 'common';
  }
}

if (!function_exists('gg_snap_is_variation_listing')) {
  function gg_snap_is_variation_listing($title, $item_data = []) {
    $t = strtolower((string)$title);
    $variation_indicators = [
      'choose your', 'pick your', 'select your', 'choose card', 'pick card', 'select card',
      'multi listing', 'multi-listing', 'multilisting', 'all rarities', 'any rarity', 'mixed rarities',
      'choose rarity', 'select rarity', 'pick rarity', 'full set', 'complete set', 'bundle', 'lot of ',
      'playset', 'play set', '3x ', 'x3 ', ' x 3', 'nostalgia set',
    ];
    foreach ($variation_indicators as $indicator) { if (strpos($t, $indicator) !== false) return true; }
    if (!empty($item_data) && isset($item_data['itemGroupType']) && $item_data['itemGroupType'] === 'SELLER_DEFINED_VARIATIONS') return true;
    return false;
  }
}

if (!function_exists('gg_snap_is_my_listing')) {
  function gg_snap_is_my_listing($item_data) {
    if (empty($item_data)) return false;
    $my_seller = strtolower(GG_MY_EBAY_SELLER_NAME);
    if (isset($item_data['seller']['username'])) { if (strtolower((string)$item_data['seller']['username']) === $my_seller) return true; }
    if (isset($item_data['sellerUsername'])) { if (strtolower((string)$item_data['sellerUsername']) === $my_seller) return true; }
    if (isset($item_data['url']) && stripos((string)$item_data['url'], $my_seller) !== false) return true;
    return false;
  }
}

if (!function_exists('gg_snap_extract_card_name')) {
  function gg_snap_extract_card_name($title) {
    $title = (string)$title;
    // Remove set code (e.g., BPRO-EN049)
    $title = preg_replace('/\b[A-Z]{2,6}[-\s]?(EN|EU|DE|FR|IT|PT|ES|SP|JP|KR)[-\s]?\d{2,4}\b/i', '', $title);
    // Remove rarity terms
    $rarities = ['quarter century secret', 'platinum secret', 'quarter century', 'starlight rare', 
                 'ghost rare', 'ultimate rare', 'collectors rare', 'secret rare', 'ultra rare', 
                 'super rare', 'rare', 'common', 'yugioh', 'yu-gi-oh', 'new', '1st edition', 
                 'unlimited', 'playset', 'mint', 'nm'];
    foreach ($rarities as $r) {
      $title = str_ireplace($r, '', $title);
    }
    // Clean up
    $title = preg_replace('/[^a-z0-9\s]/i', ' ', $title);
    $title = preg_replace('/\s+/', ' ', $title);
    $title = trim(strtolower($title));
    return $title;
  }
}

if (!function_exists('gg_snap_card_names_match')) {
  function gg_snap_card_names_match($title1, $title2, $threshold = 0.6) {
    $name1 = gg_snap_extract_card_name($title1);
    $name2 = gg_snap_extract_card_name($title2);
    
    if ($name1 === '' || $name2 === '') return false;
    
    // Extract main words (3+ chars)
    $words1 = array_filter(explode(' ', $name1), function($w) { return strlen($w) >= 3; });
    $words2 = array_filter(explode(' ', $name2), function($w) { return strlen($w) >= 3; });
    
    if (empty($words1) || empty($words2)) return false;
    
    // Count matching words
    $matches = count(array_intersect($words1, $words2));
    $total = min(count($words1), count($words2));
    
    return $total > 0 && ($matches / $total) >= $threshold;
  }
}

if (!function_exists('gg_snap_pick_best_competitor')) {
  function gg_snap_pick_best_competitor($my_item_id, $my_title, array $candidates, &$debug_log = null) {
    $my_bucket  = gg_snap_detect_rarity_bucket($my_title);
    $my_item_id = (string)$my_item_id;
    if (!$my_bucket) $my_bucket = 'common';
    if (empty($candidates)) return null;

    $debug = (stripos($my_title, 'elfnote') !== false || stripos($my_title, 'lucina') !== false);
    if ($debug && $debug_log !== null) {
      $debug_log[] = "=== PICK BEST COMPETITOR DEBUG ===";
      $debug_log[] = "My Bucket: $my_bucket";
      $debug_log[] = "Total Candidates: " . count($candidates);
    }

    $best_match = null; $best_match_price = INF;
    $filtered_count = 0;
    $all_filters = []; // Track ALL filter reasons
    
    if ($debug && $debug_log !== null) {
      $debug_log[] = "=== EVALUATING ALL " . count($candidates) . " CANDIDATES ===";
    }
    
    foreach ($candidates as $c) {
      $cid   = isset($c['item_id'])  ? (string)$c['item_id']  : '';
      $title = isset($c['title'])    ? (string)$c['title']    : '';
      $price = isset($c['price'])    ? (float)$c['price']     : 0.0;
      $curr  = isset($c['currency']) ? (string)$c['currency'] : '';
      $url   = isset($c['url'])      ? (string)$c['url']      : '';
      $ship  = isset($c['shipping']) ? (float)$c['shipping']  : 0.0;

      $skip_reason = '';
      
      if ($debug && $debug_log !== null) {
        $debug_log[] = sprintf("Checking: %s - £%.2f (ID: %s)", substr($title, 0, 60), $price, substr($cid, 0, 12));
      }
      
      if ($cid === '' || $price <= 0) { $skip_reason = 'No ID or price'; }
      elseif ($curr && strtoupper($curr) !== 'GBP') { $skip_reason = 'Not GBP'; }
      elseif ($cid === $my_item_id) { $skip_reason = 'Same item ID'; }
      elseif (gg_snap_is_my_listing($c)) { $skip_reason = 'My listing'; }
      elseif (gg_snap_is_variation_listing($title, $c)) { $skip_reason = 'Variation listing'; }
      else {
        $comp_bucket = gg_snap_detect_rarity_bucket($title);
        
        // Normalize quarter century variants (they're all the same rarity)
        if ($comp_bucket === 'quarter_century' || $comp_bucket === 'quarter_century_secret') {
          $comp_bucket_normalized = 'quarter_century';
        } else {
          $comp_bucket_normalized = $comp_bucket;
        }
        
        $my_bucket_normalized = $my_bucket;
        if ($my_bucket === 'quarter_century' || $my_bucket === 'quarter_century_secret') {
          $my_bucket_normalized = 'quarter_century';
        }
        
        if ($comp_bucket_normalized !== $my_bucket_normalized) { 
          $skip_reason = "Rarity mismatch (comp=$comp_bucket_normalized vs my=$my_bucket_normalized)"; 
        } elseif (!gg_snap_card_names_match($my_title, $title)) {
          $skip_reason = "Card name mismatch";
          if ($debug && $debug_log !== null) {
            $my_clean = gg_snap_extract_card_name($my_title);
            $comp_clean = gg_snap_extract_card_name($title);
            $debug_log[] = "CARD NAME MISMATCH DETAIL:";
            $debug_log[] = "  My cleaned: '$my_clean'";
            $debug_log[] = "  Comp cleaned: '$comp_clean'";
            $debug_log[] = "  Comp full title: '$title'";
            $debug_log[] = "  Comp price: £$price";
          }
        } else {
          $total = $price + max(0.0, $ship);
          if ($total < $best_match_price) {
            $best_match_price = $total;
            $best_match = [
              'item_id' => $cid, 'title' => $title, 'price' => $price, 'shipping' => $ship,
              'price_total' => $total, 'currency' => $curr, 'url' => $url,
              'bucket' => $comp_bucket, 'my_bucket' => $my_bucket,
            ];
            if ($debug && $debug_log !== null) $debug_log[] = "✓ NEW BEST: £$total - $title";
          }
        }
      }
      
      if ($skip_reason && $debug && $debug_log !== null) {
        $filtered_count++;
        if ($filtered_count <= 5) {
          $debug_log[] = "✗ FILTERED ($skip_reason): $title - £$price";
        }
      }
    }
    
    if ($debug && $debug_log !== null) {
      $debug_log[] = "Filtered out: $filtered_count candidates";
      if ($best_match) {
        $debug_log[] = "WINNER: " . $best_match['title'] . " - £" . $best_match['price_total'];
      } else {
        $debug_log[] = "NO WINNER - all filtered out";
      }
    }
    
    return $best_match;
  }
}

if (!function_exists('gg_snapshot_extract_set_codes_from_text')) {
  function gg_snapshot_extract_set_codes_from_text($text) {
    $text = (string)$text; if ($text === '') return [];
    $out  = []; $LANG = '(EN|EU|DE|FR|IT|PT|ES|SP|JP|KR)';
    $rxA = '/\b([A-Z]{2,6}\d{2,4})[-\s]?' . $LANG . '[-\s]?([0-9]{2,4})\b/i';
    $rxB = '/\b([A-Z]{2,6})[-\s]?' . $LANG . '[-\s]?([0-9]{2,4})\b/i';
    if (preg_match_all($rxA, $text, $m, PREG_SET_ORDER)) {
      foreach ($m as $hit) { $out[strtoupper($hit[1]) . '-' . strtoupper($hit[2]) . $hit[3]] = true; }
    }
    if (preg_match_all($rxB, $text, $n, PREG_SET_ORDER)) {
      foreach ($n as $hit) { $out[strtoupper($hit[1]) . '-' . strtoupper($hit[2]) . $hit[3]] = true; }
    }
    return array_keys($out);
  }
}

if (!function_exists('gg_get_all_active_listings_snapshot')) {
  function gg_get_all_active_listings_snapshot() {
    $all = []; $page = 1; $guard = 0;
    if (!function_exists('gg_get_active_listings')) return $all;
    while (true) {
      $items = gg_get_active_listings($page);
      if (!is_array($items) || empty($items)) break;
      foreach ($items as $it) {
        if (empty($it['item_id'])) continue;
        $id = (string)$it['item_id'];
        $all[$id] = [
          'item_id' => $id, 'title' => isset($it['title']) ? (string)$it['title'] : '',
          'price' => isset($it['price']) ? (float)$it['price'] : 0.0,
          'start' => isset($it['start']) ? (string)$it['start'] : '',
          'qty_avail' => array_key_exists('qty_avail', $it) ? $it['qty_avail'] : null,
        ];
      }
      if (count($items) < 100) break; $page++; $guard++; if ($guard > 50) break;
    }
    return $all;
  }
}

/* =========================
   RARITY-AWARE SEARCH - THE FIX!
   ========================= */
if (!function_exists('gg_snapshot_ebay_search')) {
  function gg_snapshot_ebay_search($search_query, $app_token) {
    $url = add_query_arg([
        'q' => $search_query,
        'limit' => 200,
        'filter' => 'priceCurrency:GBP,itemLocationCountry:GB',
        'sort' => 'price',
        'deliveryCountry' => 'GB',
    ], 'https://api.ebay.com/buy/browse/v1/item_summary/search');

    $resp = wp_remote_get($url, ['timeout' => 30, 'headers' => [
        'Authorization' => 'Bearer ' . $app_token,
        'X-EBAY-C-MARKETPLACE-ID' => 'EBAY_GB',
        'Content-Type' => 'application/json',
    ]]);

    if (is_wp_error($resp)) {
      gg_report_add('errors', 'eBay search WP_Error: ' . $resp->get_error_message() . ' | Query: ' . $search_query);
      return [];
    }
    $http_code = wp_remote_retrieve_response_code($resp);
    if ($http_code !== 200) {
      $body_preview = substr(wp_remote_retrieve_body($resp), 0, 300);
      gg_report_add('errors', 'eBay search HTTP ' . $http_code . ' | Query: ' . $search_query . ' | Response: ' . $body_preview);
      
      // Store the error so the snapshot page can show it
      $api_errors = get_option('gg_snapshot_api_errors', []);
      $api_errors[] = [
        'time' => current_time('mysql'),
        'http_code' => $http_code,
        'query' => $search_query,
        'body' => $body_preview,
      ];
      // Keep last 50 errors only
      if (count($api_errors) > 50) $api_errors = array_slice($api_errors, -50);
      update_option('gg_snapshot_api_errors', $api_errors);
      
      return [];
    }
    
    $body = wp_remote_retrieve_body($resp);
    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['itemSummaries'])) return [];

    $candidates = [];
    foreach ($data['itemSummaries'] as $row) {
      $item_id = isset($row['itemId']) ? (string)$row['itemId'] : '';
      $title   = isset($row['title']) ? (string)$row['title'] : '';
      $price   = isset($row['price']['value']) ? (float)$row['price']['value'] : 0.0;
      $curr    = isset($row['price']['currency']) ? (string)$row['price']['currency'] : '';
      $url     = isset($row['itemWebUrl']) ? (string)$row['itemWebUrl'] : '';
      
      if ($item_id === '' || $price <= 0 || $title === '') continue;
      
      $ship = 0.0;
      if (!empty($row['shippingOptions']) && is_array($row['shippingOptions'])) {
        foreach ($row['shippingOptions'] as $opt) {
          if (isset($opt['shippingCostType']) && $opt['shippingCostType'] === 'FREE') {
            $ship = 0.0;
            break;
          }
          if (isset($opt['shippingCost']['value'])) {
            $val = (float)$opt['shippingCost']['value'];
            if ($val == 0) {
              $ship = 0.0;
              break;
            }
            if ($ship == 0 || $val < $ship) {
              $ship = $val;
            }
          }
        }
      }

      $candidates[] = [
        'item_id' => $item_id,
        'title' => $title,
        'price' => $price,
        'currency' => $curr,
        'shipping' => $ship,
        'url' => $url,
        'itemGroupType' => isset($row['itemGroupType']) ? $row['itemGroupType'] : null,
        'seller' => isset($row['seller']) ? $row['seller'] : null,
        'itemLocation' => isset($row['itemLocation']['country']) ? $row['itemLocation']['country'] : null,
      ];
    }
    
    return $candidates;
  }
}

if (!function_exists('gg_snapshot_find_cheapest_for_code')) {
  function gg_snapshot_find_cheapest_for_code($set_code, $my_item_id, $my_title, &$debug_log = null) {
    $set_code   = trim((string)$set_code);
    $my_item_id = (string)$my_item_id;
    $my_title   = (string)$my_title;
    if ($set_code === '') return null;

    $app = gg_token_app();
    if (is_wp_error($app)) {
      gg_report_add('errors', 'App token failed for ' . $set_code . ': ' . $app->get_error_message());
      $api_errors = get_option('gg_snapshot_api_errors', []);
      $api_errors[] = [
        'time' => current_time('mysql'),
        'http_code' => 'TOKEN_FAIL',
        'query' => $set_code,
        'body' => $app->get_error_message(),
      ];
      if (count($api_errors) > 50) $api_errors = array_slice($api_errors, -50);
      update_option('gg_snapshot_api_errors', $api_errors);
      return null;
    }
    
    $my_bucket = gg_snap_detect_rarity_bucket($my_title);
    
    $debug = ($set_code === 'BPRO-EN010' || $set_code === 'BPRO-EN049' || $set_code === 'RA04-EN192' || stripos($my_title, 'elfnote') !== false || stripos($my_title, 'lucina') !== false || stripos($my_title, 'typhoon') !== false || stripos($my_title, 'varuroom') !== false || stripos($my_title, 'timaeus') !== false);
    if ($debug && $debug_log !== null) {
      $debug_log[] = "=== FIND CHEAPEST DEBUG ===";
      $debug_log[] = "Set Code: $set_code";
      $debug_log[] = "My Item ID: $my_item_id";
      $debug_log[] = "My Title: $my_title";
      $debug_log[] = "My Bucket: $my_bucket";
    }
    
    $rarity_terms = [
      'quarter_century_secret' => 'quarter',
      'platinum_secret' => 'platinum',
      'quarter_century' => 'quarter',
      'ultra_stamp' => 'stamp ultra',
      'secret_stamp' => 'stamp secret',
      'stamp_misc' => 'stamp',
      'ghost' => 'ghost',
      'starlight' => 'starlight',
      'ultimate' => 'ultimate',
      'collectors' => 'collectors',
      'secret' => 'secret',
      'ultra' => 'ultra',
      'super' => 'super',
      'rare' => 'rare',
    ];
    
    $search_query = $set_code;
    $rarity_search_term = isset($rarity_terms[$my_bucket]) ? $rarity_terms[$my_bucket] : '';
    
    // PRIMARY SEARCH: Set code + rarity
    $candidates = [];
    if ($rarity_search_term !== '' && $my_bucket !== 'common') {
      $search_query = $set_code . ' ' . $rarity_search_term;
      if ($debug && $debug_log !== null) $debug_log[] = "PRIMARY SEARCH: $search_query";
      $candidates = gg_snapshot_ebay_search($search_query, $app);
      if ($debug && $debug_log !== null) $debug_log[] = "Primary results: " . count($candidates);
    }
    
    // FALLBACK SEARCH: Just set code (always run to ensure we get maximum results)
    $search_query = $set_code;
    if ($debug && $debug_log !== null) $debug_log[] = "FALLBACK SEARCH: $search_query";
    $fallback_candidates = gg_snapshot_ebay_search($search_query, $app);
    if ($debug && $debug_log !== null) $debug_log[] = "Fallback results: " . count($fallback_candidates);
    
    if (!empty($fallback_candidates)) {
      $existing_ids = array_column($candidates, 'item_id');
      foreach ($fallback_candidates as $fc) {
        if (!in_array($fc['item_id'], $existing_ids)) {
          $candidates[] = $fc;
        }
      }
    }
    if ($debug && $debug_log !== null) $debug_log[] = "Total candidates after merge: " . count($candidates);
    
    if (empty($candidates)) {
      if ($debug && $debug_log !== null) $debug_log[] = "NO CANDIDATES FOUND!";
      return null;
    }
    
    $result = gg_snap_pick_best_competitor($my_item_id, $my_title, $candidates, $debug_log);
    if ($debug && $debug_log !== null) {
      if ($result) {
        $debug_log[] = "BEST COMPETITOR SELECTED!";
        $debug_log[] = "  Title: " . $result['title'];
        $debug_log[] = "  Price: £" . $result['price_total'];
      } else {
        $debug_log[] = "NO VALID COMPETITOR (all filtered out)";
      }
    }
    return $result;
  }
}

if (!function_exists('gg_calculate_target_price')) {
  function gg_calculate_target_price($comp_price, $my_price) {
    $min_price = GG_EBAY_MIN_PRICE;
    $target = round($comp_price * 0.99, 2);
    if ($target < $min_price) return false;
    if ($my_price <= $target) return false;
    return $target;
  }
}

if (!function_exists('gg_build_snapshot_now')) {
  function gg_build_snapshot_now() {
    $debug_log = [];
    $items = gg_get_all_active_listings_snapshot();
    if (empty($items)) {
      update_option('gg_price_snapshot_v1', ['generated_at' => time(), 'items' => [], 'codes' => []]);
      return;
    }

    $debug_log[] = "=== SNAPSHOT BUILD DEBUG ===";
    $debug_log[] = "Total items collected: " . count($items);
    
    $codes = [];
    $debug_target = 'BPRO-EN010';
    foreach ($items as $id => &$row) {
      $title = isset($row['title']) ? (string)$row['title'] : '';
      $found = gg_snapshot_extract_set_codes_from_text($title);
      $code  = $found ? $found[0] : '';
      $row['set_code'] = $code ?: null;
      $row['rarity_bucket'] = gg_snap_detect_rarity_bucket($title);
      
      if (stripos($title, 'elfnote') !== false || stripos($title, 'lucina') !== false) {
        $debug_log[] = "FOUND ELFNOTE/LUCINA: Item ID: $id";
        $debug_log[] = "  Title: $title";
        $debug_log[] = "  Extracted codes: " . json_encode($found);
        $debug_log[] = "  Selected code: $code";
        $debug_log[] = "  Rarity: " . $row['rarity_bucket'];
      }
      
      if ($code) {
        // KEY FIX: Store items by code AND rarity to handle multiple rarities of same card
        $rarity = $row['rarity_bucket'];
        $key = $code . '___' . $rarity;
        if (!isset($codes[$key])) {
          $codes[$key] = [
            'set_code' => $code,
            'rarity' => $rarity,
            'items' => [],
            'competitor' => null
          ];
        }
        $codes[$key]['items'][] = $id;
      }
    }
    unset($row);

    foreach ($codes as $key => &$info) {
      $first_id = $info['items'][0] ?? null;
      if (!$first_id || empty($items[$first_id])) { $info['competitor'] = null; continue; }
      $my_title = (string)$items[$first_id]['title'];
      $my_price = (float)($items[$first_id]['price'] ?? 0);
      $code = $info['set_code'];
      
      if ($code === 'BPRO-EN010' || stripos($my_title, 'elfnote') !== false || stripos($my_title, 'lucina') !== false) {
        $debug_log[] = "=== SEARCHING COMPETITORS FOR: $code (Rarity: {$info['rarity']}) ===";
        $debug_log[] = "My Item ID: $first_id";
        $debug_log[] = "My Title: $my_title";
      }
      
      $comp = gg_snapshot_find_cheapest_for_code($code, $first_id, $my_title, $debug_log);
      $info['competitor'] = $comp ?: null;
      
      if ($code === 'BPRO-EN010' || stripos($my_title, 'elfnote') !== false || stripos($my_title, 'lucina') !== false) {
        if ($comp) {
          $debug_log[] = "COMPETITOR FOUND!";
          $debug_log[] = "  Comp Title: " . $comp['title'];
          $debug_log[] = "  Comp Price: £" . $comp['price_total'];
        } else {
          $debug_log[] = "NO COMPETITOR FOUND!";
        }
      }
    }
    unset($info);

    update_option('gg_price_snapshot_v1', ['generated_at' => time(), 'items' => $items, 'codes' => $codes]);
    update_option('gg_snapshot_debug_log', $debug_log);
  }
}

/* =========================
   CHUNKED SNAPSHOT AJAX
   (Avoids Cloudflare 524 timeout)
   ========================= */

// Phase 1: Collect listings, extract codes, save to transient
add_action('wp_ajax_gg_snapshot_phase1', function() {
  check_ajax_referer('gg_snapshot_chunked');
  if (!current_user_can('manage_woocommerce')) wp_send_json_error('Access denied');

  $items = gg_get_all_active_listings_snapshot();
  if (empty($items)) {
    update_option('gg_price_snapshot_v1', ['generated_at' => time(), 'items' => [], 'codes' => []]);
    wp_send_json_success(['total_codes' => 0, 'total_items' => 0, 'no_code_count' => 0, 'no_code_items' => []]);
  }

  $codes = [];
  $no_code_items = [];
  foreach ($items as $id => &$row) {
    $title = isset($row['title']) ? (string)$row['title'] : '';
    $found = gg_snapshot_extract_set_codes_from_text($title);
    $code  = $found ? $found[0] : '';
    $row['set_code'] = $code ?: null;
    $row['rarity_bucket'] = gg_snap_detect_rarity_bucket($title);
    if (!$code) $no_code_items[] = ['item_id' => $id, 'title' => $title];
    if ($code) {
      $rarity = $row['rarity_bucket'];
      $key = $code . '___' . $rarity;
      if (!isset($codes[$key])) {
        $codes[$key] = ['set_code' => $code, 'rarity' => $rarity, 'items' => [], 'competitor' => null];
      }
      $codes[$key]['items'][] = $id;
    }
  }
  unset($row);

  set_transient('gg_snapshot_chunked_items', $items, 3600);
  set_transient('gg_snapshot_chunked_codes', $codes, 3600);
  $code_keys = array_keys($codes);
  set_transient('gg_snapshot_chunked_keys', $code_keys, 3600);
  set_transient('gg_snapshot_chunked_debug', [], 3600);

  wp_send_json_success([
    'total_items' => count($items),
    'total_codes' => count($code_keys),
    'no_code_items' => $no_code_items,
    'no_code_count' => count($no_code_items),
  ]);
});

// Phase 2: Search competitors for a batch of codes
add_action('wp_ajax_gg_snapshot_phase2', function() {
  check_ajax_referer('gg_snapshot_chunked');
  if (!current_user_can('manage_woocommerce')) wp_send_json_error('Access denied');

  $offset    = max(0, (int)($_POST['offset'] ?? 0));
  $batch_size = min(20, max(1, (int)($_POST['batch_size'] ?? 5)));

  $items     = get_transient('gg_snapshot_chunked_items');
  $codes     = get_transient('gg_snapshot_chunked_codes');
  $code_keys = get_transient('gg_snapshot_chunked_keys');
  $debug_log = get_transient('gg_snapshot_chunked_debug') ?: [];

  if (!$items || !$codes || !$code_keys) {
    wp_send_json_error('No chunked snapshot in progress. Run Phase 1 first.');
  }

  $batch = array_slice($code_keys, $offset, $batch_size);
  $processed = 0;

  foreach ($batch as $key) {
    if (!isset($codes[$key])) continue;
    $info = &$codes[$key];
    $first_id = $info['items'][0] ?? null;
    if (!$first_id || empty($items[$first_id])) { $info['competitor'] = null; $processed++; continue; }
    $comp = gg_snapshot_find_cheapest_for_code($info['set_code'], $first_id, (string)$items[$first_id]['title'], $debug_log);
    $info['competitor'] = $comp ?: null;
    $processed++;
  }
  unset($info);

  set_transient('gg_snapshot_chunked_codes', $codes, 3600);
  set_transient('gg_snapshot_chunked_debug', $debug_log, 3600);

  $new_offset = $offset + $batch_size;
  $done = $new_offset >= count($code_keys);

  if ($done) {
    update_option('gg_price_snapshot_v1', ['generated_at' => time(), 'items' => $items, 'codes' => $codes]);
    update_option('gg_snapshot_debug_log', $debug_log);
    delete_transient('gg_snapshot_chunked_items');
    delete_transient('gg_snapshot_chunked_codes');
    delete_transient('gg_snapshot_chunked_keys');
    delete_transient('gg_snapshot_chunked_debug');
  }

  wp_send_json_success([
    'processed' => $processed,
    'offset' => $new_offset,
    'total' => count($code_keys),
    'done' => $done,
  ]);
});

if (!function_exists('gg_snapshot_admin_page')) {
  function gg_snapshot_admin_page() {
    if (!current_user_can('manage_woocommerce')) wp_die('Access denied');
    $snapshot  = get_option('gg_price_snapshot_v1', null);
    $generated = is_array($snapshot) && !empty($snapshot['generated_at']) ? (int)$snapshot['generated_at'] : null;

    echo '<div class="wrap">';
    echo '<h1>Snapshot & Pricing Engine (eBay → eBay)</h1>';
    echo '<p>This tool compares your listings against competitors with the <strong>same rarity</strong>. ';
    echo 'Minimum price is <strong>£' . number_format(GG_EBAY_MIN_PRICE, 2) . '</strong> (eBay limit).</p>';

    // Display API errors if any
    $api_errors = get_option('gg_snapshot_api_errors', []);
    if (!empty($api_errors)) {
      $latest = end($api_errors);
      $error_count = count($api_errors);
      echo '<div style="background:#f8d7da;border-left:4px solid #dc3545;padding:15px;margin:15px 0;border-radius:4px;">';
      echo '<h3 style="margin-top:0;color:#721c24;">⚠️ eBay API Errors Detected (' . $error_count . ' recent)</h3>';
      echo '<p style="color:#721c24;">The eBay API is returning errors — competitor searches may be failing silently. This is usually caused by rate limiting (too many API calls).</p>';
      echo '<p style="color:#721c24;">Latest error: <strong>HTTP ' . esc_html($latest['http_code']) . '</strong> at ' . esc_html($latest['time']) . '</p>';
      echo '<details style="margin-top:10px;">';
      echo '<summary style="cursor:pointer;font-weight:600;padding:5px;background:#fff;border:1px solid #ddd;border-radius:3px;color:#333;">View last ' . $error_count . ' errors</summary>';
      echo '<pre style="background:#fff;padding:10px;margin-top:10px;border:1px solid #ddd;border-radius:3px;max-height:300px;overflow-y:auto;font-size:11px;color:#333;">';
      foreach (array_reverse($api_errors) as $err) {
        echo esc_html($err['time'] . ' | HTTP ' . $err['http_code'] . ' | ' . $err['query']) . "\n";
        echo '  ' . esc_html($err['body']) . "\n\n";
      }
      echo '</pre>';
      echo '</details>';
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px;">';
      wp_nonce_field('gg_clear_api_errors');
      echo '<input type="hidden" name="action" value="gg_clear_api_errors">';
      echo '<button class="button button-small">Clear Errors</button>';
      echo '</form>';
      echo '</div>';
    }

    // Display debug log if available
    $debug_log = get_option('gg_snapshot_debug_log', []);
    if (!empty($debug_log)) {
      echo '<div style="background:#fff3cd;border-left:4px solid #ffc107;padding:15px;margin:15px 0;border-radius:4px;">';
      echo '<h3 style="margin-top:0;">🔍 Debug Information</h3>';
      echo '<details style="margin-top:10px;">';
      echo '<summary style="cursor:pointer;font-weight:600;padding:5px;background:#fff;border:1px solid #ddd;border-radius:3px;">Click to view debug log (' . count($debug_log) . ' lines)</summary>';
      echo '<pre style="background:#f5f5f5;padding:10px;margin-top:10px;border:1px solid #ddd;border-radius:3px;max-height:500px;overflow-y:auto;font-size:11px;line-height:1.4;">';
      foreach ($debug_log as $line) {
        if (strpos($line, '✓') !== false) {
          echo '<span style="color:#00a32a;">' . esc_html($line) . '</span>' . "\n";
        } elseif (strpos($line, '✗') !== false || strpos($line, 'NO ') !== false || strpos($line, 'FILTERED') !== false) {
          echo '<span style="color:#d63638;">' . esc_html($line) . '</span>' . "\n";
        } elseif (strpos($line, '===') !== false) {
          echo '<span style="font-weight:600;color:#2271b1;">' . esc_html($line) . '</span>' . "\n";
        } else {
          echo esc_html($line) . "\n";
        }
      }
      echo '</pre>';
      echo '</details>';
      echo '</div>';
    }

    echo '<div style="margin:12px 0;">';
    echo '<button id="gg-build-btn" class="button button-primary" onclick="ggBuildChunked()">🔄 Build Snapshot Now</button>';
    if ($generated) echo ' <span id="gg-last-built" style="margin-left:10px;opacity:.8;">Last: <code>' . esc_html(date('Y-m-d H:i:s', $generated)) . '</code></span>';
    echo '<div id="gg-progress-wrap" style="display:none;margin-top:10px;max-width:500px;">';
    echo '<div style="background:#e2e8f0;border-radius:6px;height:8px;overflow:hidden;"><div id="gg-progress-bar" style="height:100%;width:0%;background:linear-gradient(90deg,#667eea,#764ba2);border-radius:6px;transition:width .3s;"></div></div>';
    echo '<div id="gg-progress-text" style="font-size:12px;color:#4a5568;margin-top:4px;">Starting...</div>';
    echo '</div>';
    echo '</div>';
    ?>
    <script>
    var ggChunkedNonce = '<?php echo wp_create_nonce("gg_snapshot_chunked"); ?>';
    var ggAjaxUrl = '<?php echo admin_url("admin-ajax.php"); ?>';

    async function ggBuildChunked() {
      var btn = document.getElementById('gg-build-btn');
      var wrap = document.getElementById('gg-progress-wrap');
      var bar = document.getElementById('gg-progress-bar');
      var txt = document.getElementById('gg-progress-text');
      btn.disabled = true; btn.textContent = 'Building...';
      wrap.style.display = 'block'; bar.style.width = '0%'; txt.textContent = 'Phase 1: Collecting listings...';

      try {
        var r1 = await fetch(ggAjaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
          body:'action=gg_snapshot_phase1&_wpnonce='+ggChunkedNonce});
        var d1 = await r1.json();
        if (!d1.success) { alert('Phase 1 error: '+(d1.data||'Unknown')); ggResetBtn(); return; }

        var total = d1.data.total_codes;
        if (total === 0) { txt.textContent = 'No items found.'; bar.style.width = '100%'; setTimeout(function(){location.reload();},1000); return; }
        txt.textContent = d1.data.total_items+' items, '+total+' codes. Searching competitors...';
        bar.style.width = '5%';

        var offset = 0, batchSize = 5;
        while (true) {
          if (offset >= total) break;
          var r2 = await fetch(ggAjaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'action=gg_snapshot_phase2&_wpnonce='+ggChunkedNonce+'&offset='+offset+'&batch_size='+batchSize});
          var d2 = await r2.json();
          if (!d2.success) { alert('Phase 2 error: '+(d2.data||'Unknown')); ggResetBtn(); return; }
          offset = d2.data.offset;
          var pct = 5 + Math.round((Math.min(offset,total) / total) * 95);
          bar.style.width = Math.min(pct,100)+'%';
          txt.textContent = 'Searching: '+Math.min(offset,total)+' / '+total+' codes...';
          if (d2.data.done) break;
        }

        bar.style.width = '100%'; txt.textContent = 'Done! Reloading...';
        setTimeout(function(){ location.reload(); }, 800);
      } catch(e) { alert('Error: '+e.message); ggResetBtn(); }
    }
    function ggResetBtn(){ var b=document.getElementById('gg-build-btn'); b.disabled=false; b.textContent='🔄 Build Snapshot Now'; }
    </script>
    <?php

    if (!$snapshot || empty($snapshot['items'])) { echo '<p><em>No snapshot data yet.</em></p></div>'; return; }

    $items = $snapshot['items'] ?? [];
    $codes = $snapshot['codes'] ?? [];

    $rows = [];
    foreach ($items as $id => $row) {
      $set_code = $row['set_code'] ?? null;
      $rarity = $row['rarity_bucket'] ?? null;
      if (!$set_code || !$rarity) continue;
      
      $key = $set_code . '___' . $rarity;
      if (empty($codes[$key]['competitor'])) continue;

      $comp = $codes[$key]['competitor'];
      $my_price = (float)($row['price'] ?? 0);
      $comp_price = (float)($comp['price_total'] ?? 0);
      if ($my_price <= 0 || $comp_price <= 0) continue;

      $my_bucket = gg_snap_detect_rarity_bucket($row['title'] ?? '');
      $comp_bucket = gg_snap_detect_rarity_bucket($comp['title'] ?? '');
      if ($my_bucket !== $comp_bucket) continue;

      $target = gg_calculate_target_price($comp_price, $my_price);
      if ($target === false) continue;
      if ($my_price <= $comp_price) continue;

      $rows[] = [
        'item_id' => $id, 'title' => (string)($row['title'] ?? ''), 'set_code' => $set_code,
        'my_price' => $my_price, 'my_bucket' => $my_bucket, 'comp_price' => $comp_price,
        'comp_title' => (string)($comp['title'] ?? ''), 'comp_url' => (string)($comp['url'] ?? ''),
        'comp_ship' => (float)($comp['shipping'] ?? 0), 'comp_base' => (float)($comp['price'] ?? 0),
        'comp_bucket' => $comp_bucket, 'diff' => $my_price - $comp_price, 'target' => $target,
      ];
    }

    if (empty($rows)) { echo '<p><strong>Good news:</strong> No undercuts found with matching rarities.</p></div>'; return; }

    usort($rows, function($a, $b) { return $b['diff'] <=> $a['diff']; });

    echo '<h2>Competitor Undercuts (' . count($rows) . ' items)</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('gg_beat_bulk');
    echo '<input type="hidden" name="action" value="gg_beat_bulk">';

    echo '<div style="margin:15px 0;padding:10px;background:#f0f0f1;border-radius:4px;">';
    echo '<button type="submit" class="button button-primary">⚡ Beat All Selected by 1%</button> ';
    echo '<button type="button" class="button" onclick="ggSelectAll(true)">Select All</button> ';
    echo '<button type="button" class="button" onclick="ggSelectAll(false)">Deselect All</button>';
    echo '<span id="gg-selected-count" style="margin-left:15px;font-weight:500;">0 selected</span>';
    echo '</div>';

    echo '<table class="widefat striped" style="font-size:12px;"><thead><tr>';
    echo '<th style="width:30px;"><input type="checkbox" id="gg-select-all" onclick="ggSelectAll(this.checked)"></th>';
    echo '<th>Set Code</th><th>My Item</th><th>Rarity</th><th style="text-align:right;">My £</th>';
    echo '<th>Competitor</th><th style="text-align:right;">Comp £</th><th style="text-align:right;">Diff</th><th style="text-align:right;">Target</th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $idx => $r) {
      echo '<tr>';
      echo '<td><input type="checkbox" class="gg-item-checkbox" name="items[' . $idx . '][item_id]" value="' . esc_attr($r['item_id']) . '" onchange="ggUpdateCount()">';
      echo '<input type="hidden" name="items[' . $idx . '][target_price]" value="' . esc_attr($r['target']) . '"></td>';
      echo '<td><code style="font-size:10px;">' . esc_html($r['set_code']) . '</code></td>';
      echo '<td><strong>' . esc_html($r['title']) . '</strong><br><span style="opacity:.5;font-size:10px;">ID: ' . esc_html($r['item_id']) . '</span></td>';
      echo '<td><span style="background:#d4edda;padding:2px 6px;border-radius:3px;font-size:10px;">' . esc_html($r['my_bucket']) . '</span></td>';
      echo '<td style="text-align:right;">£' . number_format($r['my_price'], 2) . '</td>';
      echo '<td>';
      if ($r['comp_url']) echo '<a href="' . esc_url($r['comp_url']) . '" target="_blank" style="font-size:11px;">' . esc_html($r['comp_title']) . '</a>';
      else echo '<span style="font-size:11px;">' . esc_html($r['comp_title']) . '</span>';
      echo '</td>';
      echo '<td style="text-align:right;font-size:11px;">£' . number_format($r['comp_base'], 2) . '+£' . number_format($r['comp_ship'], 2) . '=<strong>£' . number_format($r['comp_price'], 2) . '</strong></td>';
      echo '<td style="text-align:right;color:#d63638;">-£' . number_format($r['diff'], 2) . '</td>';
      echo '<td style="text-align:right;color:#00a32a;font-weight:500;">£' . number_format($r['target'], 2) . '</td>';
      echo '</tr>';
    }

    echo '</tbody></table>';
    echo '<div style="margin:15px 0;padding:10px;background:#f0f0f1;border-radius:4px;">';
    echo '<button type="submit" class="button button-primary">⚡ Beat All Selected by 1%</button> ';
    echo '<button type="button" class="button" onclick="ggSelectAll(true)">Select All</button> ';
    echo '<button type="button" class="button" onclick="ggSelectAll(false)">Deselect All</button>';
    echo '</div></form>';
    
    ?>
    <script>
    function ggSelectAll(c){document.querySelectorAll('.gg-item-checkbox').forEach(x=>x.checked=c);document.getElementById('gg-select-all').checked=c;ggUpdateCount();}
    function ggUpdateCount(){var n=document.querySelectorAll('.gg-item-checkbox:checked').length;document.getElementById('gg-selected-count').textContent=n+' selected';}
    document.addEventListener('DOMContentLoaded',ggUpdateCount);
    </script>
    <?php
    echo '</div>';
  }
}

add_action('admin_menu', function () {
  add_submenu_page('gg-ebay-suite', 'Snapshot & Pricing Engine', 'Snapshot & Pricing Engine', 'manage_woocommerce', 'gg-ebay-snapshot', 'gg_snapshot_admin_page');
});

add_action('admin_post_gg_build_snapshot_now', function () {
  if (!current_user_can('manage_woocommerce')) wp_die('Access denied');
  check_admin_referer('gg_build_snapshot');
  gg_build_snapshot_now();
  set_transient('gg_snapshot_notice', ['type' => 'success', 'message' => 'Snapshot rebuilt.'], 60);
  wp_safe_redirect(admin_url('admin.php?page=gg-ebay-snapshot')); exit;
});

add_action('admin_post_gg_clear_api_errors', function () {
  if (!current_user_can('manage_woocommerce')) wp_die('Access denied');
  check_admin_referer('gg_clear_api_errors');
  delete_option('gg_snapshot_api_errors');
  set_transient('gg_snapshot_notice', ['type' => 'success', 'message' => 'API errors cleared.'], 60);
  wp_safe_redirect(admin_url('admin.php?page=gg-ebay-snapshot')); exit;
});

if (!function_exists('gg_snapshot_revise_price_on_ebay')) {
  function gg_snapshot_revise_price_on_ebay($item_id, $target_price) {
    $item_id = trim((string)$item_id); $target_price = (float)$target_price;
    if ($target_price < GG_EBAY_MIN_PRICE) $target_price = GG_EBAY_MIN_PRICE;
    if ($item_id === '' || $target_price <= 0) return new WP_Error('bad_input', 'Missing item id or target price');

    $xml = '<?xml version="1.0" encoding="utf-8"?>'
         . '<ReviseFixedPriceItemRequest xmlns="urn:ebay:apis:eBLBaseComponents">'
         . '<Item><ItemID>' . gg_xml($item_id) . '</ItemID>'
         . '<StartPrice>' . number_format($target_price, 2, '.', '') . '</StartPrice>'
         . '</Item></ReviseFixedPriceItemRequest>';

    $r = gg_trading_call('ReviseFixedPriceItem', $xml);
    if (is_wp_error($r)) return $r;
    $body = wp_remote_retrieve_body($r);
    if (strpos($body, '<Ack>Success</Ack>') !== false || strpos($body, '<Ack>Warning</Ack>') !== false) return true;
    preg_match('/<ShortMessage>(.*?)<\/ShortMessage>/is', $body, $m);
    return new WP_Error('ebay_error', $m[1] ?? 'Unknown error');
  }
}

add_action('admin_post_gg_beat_bulk', function () {
  if (!current_user_can('manage_woocommerce')) wp_die('Access denied');
  check_admin_referer('gg_beat_bulk');
  $items = $_POST['items'] ?? [];
  $success = $fail = 0;
  foreach ($items as $item) {
    if (empty($item['item_id'])) continue;
    $res = gg_snapshot_revise_price_on_ebay(sanitize_text_field($item['item_id']), (float)($item['target_price'] ?? 0));
    if (is_wp_error($res)) $fail++; else $success++;
    usleep(250000);
  }
  $msg = $success > 0 ? "Updated {$success} listing(s)." : "No updates.";
  if ($fail > 0) $msg .= " {$fail} failed.";
  set_transient('gg_snapshot_notice', ['type' => $fail > 0 ? 'warning' : 'success', 'message' => $msg], 60);
  wp_safe_redirect(admin_url('admin.php?page=gg-ebay-snapshot')); exit;
});

add_action('admin_notices', function () {
  if (($_GET['page'] ?? '') !== 'gg-ebay-snapshot') return;
  $notice = get_transient('gg_snapshot_notice');
  if (!$notice) return;
  delete_transient('gg_snapshot_notice');
  $class = $notice['type'] === 'error' ? 'notice-error' : ($notice['type'] === 'warning' ? 'notice-warning' : 'notice-success');
  echo '<div class="notice ' . $class . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
});