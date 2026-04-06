<?php
/*
Plugin Name: GrimeGames – Price Watch (v3.4) Title-First Set Code + Name+Rarity Fallback + eBay-first Beat −1%
Description: Compares WooCommerce prices against the lowest matching eBay price per Set Code + (optional) Rarity. Primary rule: listing TITLE must contain the exact same Set Code; only if none are found, fall back to Card Name + Rarity (strict). Rarity-first query phases, strict rarity normalisation (QCSE vs PSR, Stamp handling), UK+overseas (GBP), optional collection-only guard, variation skip when strict, Match Inspector with “Why”. v3.4 adds eBay-first “Beat −1%” (pushes price to eBay via Trading API, Woo left untouched so your eBay→Woo sync pulls it down), success-only verbose logs, batched inventory with rolling cursor, and tighter time budgets to prevent timeouts.
Author: GrimeGames
Version: 3.4.0
*/

defined('ABSPATH') || exit;

/* ===================== OPTION HELPERS ===================== */
if (!function_exists('gg_pw2_opt')) { function gg_pw2_opt($k,$d=''){ return get_option("gg_pw2_$k",$d); } }
if (!function_exists('gg_pw2_set')) { function gg_pw2_set($k,$v){ return update_option("gg_pw2_$k",$v); } }

/* ===================== DEBUG HELPERS ===================== */
if (!function_exists('gg_pw2_debug_enabled')) { function gg_pw2_debug_enabled(){ return gg_pw2_opt('debug_on','0')==='1'; } }
if (!function_exists('gg_pw2_log')) {
function gg_pw2_log($msg){
    if (!gg_pw2_debug_enabled()) return;
    $entry='['.wp_date('Y-m-d H:i:s').'] '.(is_scalar($msg)?$msg:wp_json_encode($msg));
    $log=gg_pw2_opt('debug_log',''); $log=$log?("$log\n$entry"):$entry;
    if (strlen($log)>65535) $log=substr($log,-65535);
    gg_pw2_set('debug_log',$log);
}}
if (!function_exists('gg_pw2_debug_clear')) { function gg_pw2_debug_clear(){ gg_pw2_set('debug_log',''); } }
if (!function_exists('gg_pw2_set_last_email_html')) { function gg_pw2_set_last_email_html($h){ gg_pw2_set('last_email_html',(string)$h); } }
if (!function_exists('gg_pw2_get_last_email_html')) { function gg_pw2_get_last_email_html(){ return gg_pw2_opt('last_email_html',''); } }
if (!function_exists('gg_pw2_set_match_inspector')) { function gg_pw2_set_match_inspector($h){ gg_pw2_set('match_inspector_html',(string)$h); } }
if (!function_exists('gg_pw2_get_match_inspector')) { function gg_pw2_get_match_inspector(){ return gg_pw2_opt('match_inspector_html',''); } }

/* ===================== ACTIVATION DEFAULTS ===================== */
register_activation_hook(__FILE__, function(){
    if (!get_option('gg_pw2_initialized')) {
        gg_pw2_set('enabled','0');
        gg_pw2_set('email_to', get_option('admin_email'));
        gg_pw2_set('seller_username','');
        gg_pw2_set('times_csv','08:00,15:00,20:00');
        gg_pw2_set('min_amt','0');
        gg_pw2_set('min_pct','0');
        gg_pw2_set('same_condition','1');
        gg_pw2_set('include_postage','1');
        gg_pw2_set('delivery_postcode','');
        gg_pw2_set('exclude_platinum','0'); // if ON, excludes PSR entirely
        gg_pw2_set('stamp_is_distinct','1');
        gg_pw2_set('allow_collection_only','0');
        gg_pw2_set('skip_variations_when_rarity','1');
        gg_pw2_set('require_rarity','1');   // locked ON internally; smart-relax per item
        gg_pw2_set('history','{}');
        gg_pw2_set('seen_inventory_count','0');
        gg_pw2_set('debug_on','0');
        gg_pw2_set('debug_log','');
        gg_pw2_set('last_email_html','');
        gg_pw2_set('match_inspector_html','');
        // batching defaults
        gg_pw2_set('batch_limit','120');
        gg_pw2_set('cursor_page','1');
        update_option('gg_pw2_initialized','yes');
    }
    if (!get_option('gg_pw2_postage_default_240')) {
        if (gg_pw2_opt('include_postage','')==='0') gg_pw2_set('include_postage','1');
        update_option('gg_pw2_postage_default_240','yes');
    }
});

/* ===================== ADMIN MENU ===================== */
add_action('admin_menu', function(){
    $parent='gg-ebay-suite';
    if (!isset($GLOBALS['admin_page_hooks'][$parent])) $parent = class_exists('WooCommerce') ? 'woocommerce' : false;
    $cb='gg_pw2_settings_page';
    if ($parent) add_submenu_page($parent,'Price Watch (v2/3/4)','Price Watch (v2/3/4)','manage_woocommerce','gg-price-watch-v2',$cb);
    else add_menu_page('Price Watch (v2/3/4)','Price Watch (v2/3/4)','manage_options','gg-price-watch-v2',$cb,'dashicons-chart-line',56);
},50);

/* ===================== RARITY/STAMP NORMALISATION ===================== */
if (!function_exists('gg_pw2_normalize_rarity')) {
function gg_pw2_normalize_rarity($raw){
    if ($raw===null) return '';
    $orig = (string)$raw;
    $s=' '.strtolower(trim($orig)).' ';
    $s=preg_replace('/[^a-z0-9\s]/',' ',$s);
    $s=preg_replace('/\s+/',' ',$s);

    $stamp = (bool)preg_match('/\b(emblazon(?:ed)?|stamp(?:ed)?|foil(?:\s+stamp)?|emboss(?:ed)?|emblem)\b/i',$orig);

    $is_collectors=false;
    if (preg_match('/\bcollector(?:s|\'s|s\')?\s+rare\b/i',$orig)) $is_collectors=true;
    if (!$is_collectors && preg_match('/\bcr\b/i',$orig) && preg_match('/\brare\b/i',$orig)) $is_collectors=true;
    if ($is_collectors) return 'collectors rare';

    $map = [
        'quarter century secret rare' => [
            '/\bquarter\s+century\b/i','/\b25th\b/i','/\b25\s*th\b/i','/\b25th\s+anniversary\b/i','/\bqcse\b/i'
        ],
        'platinum secret rare' => [
            '/\bplatinum\s+secret\b/i','/\bpsr\b/i'
        ],
        'prismatic secret rare' => [
            '/\bprismatic\s+secret\b/i','/\bpscr\b/i'
        ],
        'ultimate rare' => [
            '/\bultimate\s+rare\b/i','/\butr\b/i'
        ],
        'starlight rare' => [
            '/\bstarlight\s+rare\b/i','/\bstarlight\b/i','/\bstl\b/i'
        ],
        'ghost rare' => [
            '/\bghost\s+rare\b/i','/\bgr\b/i'
        ],
        'secret rare' => [
            '/\bsecret\s+rare\b/i','/\bscr\b/i','/\bsec\b/i'
        ],
        'ultra rare' => [
            '/\bultra\s+rare\b/i','/\bur\b/i'
        ],
        'super rare' => [
            '/\bsuper\s+rare\b/i','/\bsr\b/i'
        ],
        'gold rare' => [
            '/\bgold\s+rare\b/i'
        ],
        'platinum rare' => [
            '/\bplatinum\s+rare\b/i'
        ],
        'rare' => [
            '/\brare\b/i'
        ],
        'common' => [
            '/\bcommon\b/i','/\bc\b/i'
        ],
    ];

    foreach ($map as $canon=>$regexes){
        foreach ($regexes as $rx){
            if (preg_match($rx, $orig)) {
                if (gg_pw2_opt('stamp_is_distinct','1')==='1' && $stamp){
                    if ($canon==='secret rare') return 'secret rare stamp';
                    if ($canon==='ultra rare')  return 'ultra rare stamp';
                }
                return $canon;
            }
        }
    }
    return '';
}}
if (!function_exists('gg_pw2_extract_rarity_from_title')) {
function gg_pw2_extract_rarity_from_title($t){
    return $t ? gg_pw2_normalize_rarity($t) : '';
}}

/* ===================== CONDITION NORMALISATION ===================== */
if (!function_exists('gg_pw2_norm_condition')) {
function gg_pw2_norm_condition($s){
    $t=strtolower((string)$s); $t=preg_replace('/[^a-z0-9\s]/',' ',$t);
    if (preg_match('/\b(near\s*mint|nm|m\/nm|mint)\b/',$t)) return 'nm';
    if (preg_match('/\b(light(?:ly)?\s*played|lp)\b/',$t)) return 'lp';
    if (preg_match('/\b(moderate(?:ly)?\s*played|mp)\b/',$t)) return 'mp';
    if (preg_match('/\b(heavy(?:ily)?\s*played|hp)\b/',$t)) return 'hp';
    if (preg_match('/\b(damaged|poor)\b/',$t)) return 'dm';
    return $t?:'';
}}
if (!function_exists('gg_pw2_cond_match')) {
function gg_pw2_cond_match($required,$item_condition,$title){
    if (!$required) return true;
    $want=gg_pw2_norm_condition($required);
    $have=gg_pw2_norm_condition($item_condition);
    $ttl =gg_pw2_norm_condition($title);
    if ($have && $want===$have) return true;
    if ($ttl  && $want===$ttl ) return true;
    $rank=['nm'=>5,'lp'=>4,'mp'=>3,'hp'=>2,'dm'=>1,''=>0];
    return $rank[$have] >= $rank[$want];
}}

/* ===================== SET CODE EXTRACTION (robust) ===================== */
if (!function_exists('gg_pw2_canon_set_code')) {
function gg_pw2_canon_set_code($pre,$lang,$num){
    $pre  = strtoupper(trim($pre));
    $lang = strtoupper(trim($lang));
    $num  = trim($num);
    return $pre.'-'.$lang.$num;
}}

if (!function_exists('gg_pw2_extract_set_codes_from_text')) {
function gg_pw2_extract_set_codes_from_text($text){
    $out = [];
    if (!$text) return $out;
    $s = (string)$text;

    $LANG = '(EN|EU|DE|FR|IT|PT|ES|SP|JP|KR)';

    // A) letters+digits prefix: RA04-EN015, MP23 EN123 (letters then 2–4 digits)
    $rxA = '/\b([A-Z]{2,6}\d{2,4})[-\s]?'.$LANG.'[-\s]?([0-9]{2,4})\b/i';
    // B) letters-only prefix: LOB-EN123, DPYG EN010
    $rxB = '/\b([A-Z]{2,6})[-\s]?'.$LANG.'[-\s]?([0-9]{2,4})\b/i';

    if (preg_match_all($rxA,$s,$m,PREG_SET_ORDER)){
        foreach($m as $hit){ $out[ gg_pw2_canon_set_code($hit[1],$hit[2],$hit[3]) ] = true; }
    }
    if (preg_match_all($rxB,$s,$n,PREG_SET_ORDER)){
        foreach($n as $hit){ $out[ gg_pw2_canon_set_code($hit[1],$hit[2],$hit[3]) ] = true; }
    }
    return array_keys($out);
}}

if (!function_exists('gg_pw2_extract_set_codes_from_meta')) {
function gg_pw2_extract_set_codes_from_meta($pid){
    $keys = ['_set_code','set_code','_card_code','card_code','_sku'];
    $out = [];
    foreach ($keys as $k){
        $val = get_post_meta($pid,$k,true);
        if ($val){ foreach(gg_pw2_extract_set_codes_from_text($val) as $c) $out[$c]=true; }
    }
    return array_keys($out);
}}
if (!function_exists('gg_pw2_extract_set_codes_from_attributes')) {
function gg_pw2_extract_set_codes_from_attributes($product){
    $out = [];
    if (!is_object($product) || !method_exists($product,'get_attributes')) return [];
    $pid = $product->get_id();
    foreach ($product->get_attributes() as $attr){
        if (is_object($attr) && method_exists($attr,'is_taxonomy') && $attr->is_taxonomy()){
            $names = function_exists('wc_get_product_terms')
                ? wc_get_product_terms($pid,$attr->get_name(),['fields'=>'names'])
                : [];
            foreach($names as $n){ foreach(gg_pw2_extract_set_codes_from_text($n) as $c) $out[$c]=true; }
        } else {
            $vals = is_object($attr) && method_exists($attr,'get_options') ? (array)$attr->get_options() : [];
            foreach($vals as $v){ foreach(gg_pw2_extract_set_codes_from_text($v) as $c) $out[$c]=true; }
        }
    }
    return array_keys($out);
}}
if (!function_exists('gg_pw2_find_product_set_codes')) {
function gg_pw2_find_product_set_codes($product){
    $out = [];
    $pid = is_object($product)?$product->get_id():0;
    $title = $pid ? get_the_title($pid) : '';
    foreach(gg_pw2_extract_set_codes_from_text($title) as $c) $out[$c]=true;

    if (is_object($product) && method_exists($product,'get_sku')){
        $sku = (string)$product->get_sku();
        foreach(gg_pw2_extract_set_codes_from_text($sku) as $c) $out[$c]=true;
    }
    if ($pid){
        foreach(gg_pw2_extract_set_codes_from_meta($pid) as $c) $out[$c]=true;
    }
    foreach(gg_pw2_extract_set_codes_from_attributes($product) as $c) $out[$c]=true;

    return array_keys($out);
}}

/* ===================== TITLE-FIRST + NAME HINT HELPERS ===================== */
if (!function_exists('gg_pw2_title_has_set_code')) {
function gg_pw2_title_has_set_code($required_code, $title){
    if (!$required_code || !$title) return false;
    $required = strtoupper(trim($required_code));
    foreach (gg_pw2_extract_set_codes_from_text($title) as $c){
        if (strtoupper($c) === $required) return true;
    }
    return false;
}}

if (!function_exists('gg_pw2_extract_card_name')) {
function gg_pw2_extract_card_name($s){
    $t = (string)$s;
    // remove set codes like RA04-EN123, RA04 EN123, MP22EN001 etc.
    $t = preg_replace('/\b[A-Z]{2,6}\d{2,4}[-\s]?(EN|EU|DE|FR|IT|PT|ES|SP|JP|KR)[-\s]?[0-9]{2,4}\b/i', ' ', $t);
    $t = preg_replace('/\b[A-Z]{2,6}[-\s]?(EN|EU|DE|FR|IT|PT|ES|SP|JP|KR)[-\s]?[0-9]{2,4}\b/i', ' ', $t);
    // remove edition & generic words
    $t = preg_replace('/\b(1st|first|unlimited)\s+edition\b/i',' ',$t);
    $t = preg_replace('/\b(yu-?gi-?oh|ygo|tcg|ocg|card|official)\b/i',' ',$t);
    // strip rarity words (we’ll add desired rarity back in query)
    $rar = 'quarter\s+century|platinum|prismatic|starlight|ultimate|ghost|collectors?|secret|ultra|super|gold|rare|common|stamp';
    $t = preg_replace('/\b('.$rar.')\b/i',' ',$t);
    // collapse punctuation/space
    $t = preg_replace('/[^\p{L}\p{N}\s]/u',' ',$t);
    $t = preg_replace('/\s+/',' ',trim($t));
    return mb_substr($t,0,80);
}}

if (!function_exists('gg_pw2_rarity_variants')) {
function gg_pw2_rarity_variants($want){
    $want = gg_pw2_normalize_rarity($want);
    switch($want){
        case 'starlight rare':              return ['"starlight rare"','starlight','stl'];
        case 'quarter century secret rare': return ['"quarter century secret rare"','"quarter century" "secret rare"','"25th" "secret rare"','qcse','"25th anniversary" "secret rare"'];
        case 'platinum secret rare':        return ['"platinum secret rare"','psr'];
        case 'ultimate rare':               return ['"ultimate rare"','utr'];
        case 'secret rare':                 return ['"secret rare"','scr','sec'];
        case 'ultra rare':                  return ['"ultra rare"','ur'];
        case 'super rare':                  return ['"super rare"','sr'];
        case 'collectors rare':             return ['"collector\'s rare"','"collectors rare"','collectors','cr'];
        default:                            return $want ? ['"'.$want.'"'] : [];
    }
}}

/* ===================== SETTINGS PAGE ===================== */
if (!function_exists('gg_pw2_settings_page')) {
function gg_pw2_settings_page(){
    if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) return;

    if (isset($_GET['beat_bulk']) && $_GET['beat_bulk']==='done'){
        $ok=(int)($_GET['ok']??0); $fail=(int)($_GET['fail']??0);
        echo '<div class="updated"><p>Bulk “−1%” complete — updated '.$ok.' item(s)'.($fail?(', '.$fail.' failed.'):'').'</p></div>';
    }
    if (isset($_GET['beat'])){
        if ($_GET['beat']==='ok')  echo '<div class="updated"><p>Price updated on eBay for that item.</p></div>';
        if ($_GET['beat']==='fail'){
            $err = isset($_GET['err']) ? esc_html($_GET['err']) : 'Unknown error';
            echo '<div class="notice notice-error"><p>Could not update price on eBay: '.$err.'</p></div>';
        }
    }

    if (!empty($_POST['gg_pw2_save']) && isset($_POST['gg_pw2_save_nonce']) && wp_verify_nonce($_POST['gg_pw2_save_nonce'],'gg_pw2_save_action')){
        gg_pw2_set('enabled',                 empty($_POST['enabled']) ? '0':'1');
        gg_pw2_set('email_to',                sanitize_email($_POST['email_to']??''));
        gg_pw2_set('seller_username',         sanitize_text_field($_POST['seller_username']??''));
        gg_pw2_set('times_csv',               sanitize_text_field($_POST['times_csv']??'08:00,15:00,20:00'));
        gg_pw2_set('min_amt',                 (string)max(0,(float)($_POST['min_amt']??0)));
        gg_pw2_set('min_pct',                 (string)max(0,(float)($_POST['min_pct']??0)));
        gg_pw2_set('same_condition',          empty($_POST['same_condition']) ? '0':'1');
        gg_pw2_set('include_postage',         empty($_POST['include_postage']) ? '0':'1');
        gg_pw2_set('delivery_postcode',       sanitize_text_field($_POST['delivery_postcode']??''));
        gg_pw2_set('allow_collection_only',   empty($_POST['allow_collection_only']) ? '0':'1');
        gg_pw2_set('exclude_platinum',        empty($_POST['exclude_platinum']) ? '0':'1');
        gg_pw2_set('stamp_is_distinct',       empty($_POST['stamp_is_distinct']) ? '0':'1');
        gg_pw2_set('skip_variations_when_rarity', empty($_POST['skip_variations_when_rarity']) ? '0':'1');
        gg_pw2_set('require_rarity','1'); // locked (smart relax for generic handled in code)
        gg_pw2_set('debug_on',                empty($_POST['debug_on']) ? '0':'1');
        gg_pw2_set('batch_limit',             (string)max(20,(int)($_POST['batch_limit']??120)));
        gg_pw2_reschedule_all();
        echo '<div class="updated"><p>Saved. Schedules refreshed.</p></div>';
    }

    if (!empty($_POST['gg_pw2_run_now']) && isset($_POST['gg_pw2_run_now_nonce']) && wp_verify_nonce($_POST['gg_pw2_run_now_nonce'],'gg_pw2_run_now_action')){
        gg_pw2_run('manual'); echo '<div class="updated"><p>Manual run executed.</p></div>';
    }
    if (!empty($_POST['gg_pw2_dry_run']) && isset($_POST['gg_pw2_dry_run_nonce']) && wp_verify_nonce($_POST['gg_pw2_dry_run_nonce'],'gg_pw2_dry_run_action')){
        gg_pw2_run('dry-run',true); echo '<div class="updated"><p>Dry run completed.</p></div>';
    }
    if (!empty($_POST['gg_pw2_clear_log']) && isset($_POST['gg_pw2_clear_log_nonce']) && wp_verify_nonce($_POST['gg_pw2_clear_log_nonce'],'gg_pw2_clear_log_action')){
        gg_pw2_debug_clear(); echo '<div class="updated"><p>Debug log cleared.</p></div>';
    }

    // Test box
    $test_output='';
    if (!empty($_POST['gg_pw2_test_code']) && isset($_POST['gg_pw2_test_nonce']) && wp_verify_nonce($_POST['gg_pw2_test_nonce'],'gg_pw2_test_action')){
        $code   = strtoupper(trim($_POST['test_set_code']??''));
        $price  = (float)($_POST['test_my_price']??0);
        $rarity = gg_pw2_normalize_rarity($_POST['test_my_rarity']??'');
        $seller = sanitize_text_field($_POST['test_exclude_seller']??gg_pw2_opt('seller_username',''));
        $cond   = sanitize_text_field($_POST['test_condition']??'');
        $incl   = !empty($_POST['test_include_postage']) || gg_pw2_opt('include_postage','1')==='1';

        $reqR = (gg_pw2_opt('require_rarity','1')==='1');
        if (in_array($rarity,['rare','common'],true)) $rarity='';

        if ($code && $price>0){
            $comp = gg_pw2_fetch_lowest_for_set(
                $code,
                $seller,
                ($cond ? $cond : null),
                $incl,
                ($reqR ? $rarity : ''),
                gg_pw2_opt('allow_collection_only','0') === '1',
                gg_pw2_opt('skip_variations_when_rarity','1') === '1'
            );

            if (is_array($comp) && isset($comp['price'])){
                $delta=$price-(float)$comp['price']; $pct=$price>0?($delta/$price*100):0;
                $test_output='<div class="notice notice-info"><p><strong>Test for '
                    .esc_html($code).'</strong>'.($rarity?' (rarity: <em>'.esc_html($rarity).'</em>)':' (rarity relaxed)')
                    .'<br>My: £'.number_format($price,2).' — Lowest: £'.number_format((float)$comp['price'],2)
                    .' by '.esc_html($comp['seller'])
                    .' (Δ £'.number_format($delta,2).', '.number_format($pct,1).'%) '
                    .'<a target="_blank" rel="noopener" href="'.esc_url($comp['url']).'">View</a></p></div>';
            } else {
                $test_output='<div class="notice notice-warning"><p>No competitor found for <strong>'.esc_html($code).'</strong>'
                    .($rarity?' with rarity <em>'.esc_html($rarity).'</em>':' (rarity relaxed)').'. Check Inspector below.</p></div>';
            }
        } else { $test_output='<div class="notice notice-error"><p>Enter Set Code + your price.</p></div>'; }
    }

    $next_runs=gg_pw2_preview_next_runs();
    $inv_count=(int)gg_pw2_opt('seen_inventory_count','0');
    $debug_log=gg_pw2_opt('debug_log','');
    $last_html=gg_pw2_get_last_email_html();
    $insp_html=gg_pw2_get_match_inspector();

    ?>
    <div class="wrap">
      <h1>Price Watch (v3.4)</h1>

      <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=gg-price-watch-v2')); ?>">
        <?php wp_nonce_field('gg_pw2_save_action','gg_pw2_save_nonce'); ?>
        <table class="form-table" role="presentation">
          <tr><th>Enable</th><td><label><input type="checkbox" name="enabled" <?php checked(gg_pw2_opt('enabled')==='1'); ?>> Turn on scheduled emails</label></td></tr>
          <tr><th>Recipient Email</th><td><input type="email" name="email_to" class="regular-text" required value="<?php echo esc_attr(gg_pw2_opt('email_to')); ?>"></td></tr>
          <tr><th>Your eBay Seller Username</th><td><input type="text" name="seller_username" class="regular-text" value="<?php echo esc_attr(gg_pw2_opt('seller_username','')); ?>"></td></tr>
          <tr><th>Times (Europe/London)</th><td><input type="text" name="times_csv" class="regular-text" value="<?php echo esc_attr(gg_pw2_opt('times_csv','08:00,15:00,20:00')); ?>"></td></tr>
          <tr><th>Alert Thresholds</th><td>£ <input type="number" step="0.01" min="0" name="min_amt" style="width:100px" value="<?php echo esc_attr(gg_pw2_opt('min_amt','0')); ?>"> or % <input type="number" step="0.1" min="0" name="min_pct" style="width:100px" value="<?php echo esc_attr(gg_pw2_opt('min_pct','0')); ?>"></td></tr>
          <tr><th>Matching Options</th><td>
              <label><input type="checkbox" name="same_condition"  <?php checked(gg_pw2_opt('same_condition')==='1'); ?>> Require same item condition</label><br>
              <label><input type="checkbox" name="include_postage" <?php checked(gg_pw2_opt('include_postage','1')==='1'); ?>> Compare item + cheapest postage</label><br>
              <label><input type="checkbox" name="allow_collection_only" <?php checked(gg_pw2_opt('allow_collection_only','0')==='1'); ?>> Allow collection-only when postage is missing</label><br>
              <span style="display:inline-block;padding:2px 6px;border:1px solid #ccc;border-radius:4px;background:#f7f7f7">Require same rarity (strict): <strong>LOCKED ON*</strong></span>
              <div style="color:#666;margin-top:4px">*</div><div style="display:inline;color:#666"> If your product rarity is only <em>generic</em> (“rare”/“common”), we <strong>relax rarity</strong> for that item so you still see the true cheapest by set code.</div><br>
              <label><input type="checkbox" name="skip_variations_when_rarity" <?php checked(gg_pw2_opt('skip_variations_when_rarity','1')==='1'); ?>> Skip multi-variation listings when requiring rarity</label>
          </td></tr>
          <tr><th>Delivery Postcode</th><td><input type="text" name="delivery_postcode" class="regular-text" placeholder="(optional) e.g. DY5 1TW" value="<?php echo esc_attr(gg_pw2_opt('delivery_postcode','')); ?>"></td></tr>
          <tr><th>Rarity Filters</th><td>
              <label><input type="checkbox" name="exclude_platinum" <?php checked(gg_pw2_opt('exclude_platinum','0')==='1'); ?>> Exclude Platinum Secret Rare / PSR</label><br>
              <label><input type="checkbox" name="stamp_is_distinct" <?php checked(gg_pw2_opt('stamp_is_distinct','1')==='1'); ?>> Treat “Secret/Ultra Rare Stamp” as distinct</label>
          </td></tr>
          <tr><th>Performance</th><td>
              Max items per run&nbsp;<input type="number" min="20" step="10" name="batch_limit" style="width:100px" value="<?php echo esc_attr(gg_pw2_opt('batch_limit','120')); ?>">
              <span style="color:#666;margin-left:6px">Batched with rolling cursor to avoid timeouts.</span>
          </td></tr>
          <tr><th>Debug Mode</th><td><label><input type="checkbox" name="debug_on" <?php checked(gg_pw2_debug_enabled()); ?>> Enable verbose logging</label></td></tr>
        </table>
        <p>
          <button class="button button-primary" name="gg_pw2_save" value="1">Save &amp; Reschedule</button>
          <?php wp_nonce_field('gg_pw2_run_now_action','gg_pw2_run_now_nonce'); ?>
          <button class="button" name="gg_pw2_run_now" value="1">Run Now (send email)</button>
          <?php wp_nonce_field('gg_pw2_dry_run_action','gg_pw2_dry_run_nonce'); ?>
          <button class="button" name="gg_pw2_dry_run" value="1">Dry Run (no email)</button>
        </p>
      </form>

      <h2>Debug &amp; Test</h2>
      <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=gg-price-watch-v2')); ?>" style="margin-bottom:8px">
        <?php wp_nonce_field('gg_pw2_test_action','gg_pw2_test_nonce'); ?>
        <p style="display:flex;gap:12px;flex-wrap:wrap">
          <label>Set Code&nbsp;<input type="text" name="test_set_code" placeholder="e.g. RA04-EN192"></label>
          <label>My Price £&nbsp;<input type="number" step="0.01" min="0" name="test_my_price"></label>
          <label>My Rarity&nbsp;<input type="text" name="test_my_rarity" placeholder="e.g. Ultimate Rare / Secret Rare"></label>
          <label>Exclude seller&nbsp;<input type="text" name="test_exclude_seller" value="<?php echo esc_attr(gg_pw2_opt('seller_username','')); ?>"></label>
          <label>Require condition&nbsp;<input type="text" name="test_condition" placeholder="(optional) e.g. NM"></label>
          <label><input type="checkbox" name="test_include_postage" <?php checked(gg_pw2_opt('include_postage','1')==='1'); ?>> Include cheapest postage</label>
        </p>
        <p><button class="button">Test Set Code</button></p>
      </form>
      <?php echo $test_output; ?>

      <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=gg-price-watch-v2')); ?>">
        <?php wp_nonce_field('gg_pw2_clear_log_action','gg_pw2_clear_log_nonce'); ?>
        <p><button class="button" name="gg_pw2_clear_log" value="1">Clear Debug Log</button></p>
      </form>
      <textarea readonly rows="10" style="width:100%;font-family:monospace;"><?php echo esc_textarea($debug_log ?: '(debug log empty)'); ?></textarea>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="gg-pw2-bulk" style="margin:12px 0">
        <input type="hidden" name="action" value="gg_pw2_beat_bulk">
        <?php wp_nonce_field('gg_pw2_beat_bulk'); ?>
        <button class="button button-primary">Beat selected −1% (push to eBay)</button>
        <span style="margin-left:8px;color:#666">Tip: use the checkbox in the header to select/deselect all.</span>
      </form>

      <h2>Last Email Preview</h2>
      <div style="padding:8px;border:1px solid #ddd;background:#fff;"><?php echo $last_html ? $last_html : '<em>(No email preview captured yet.)</em>'; ?></div>

      <p style="margin:12px 0"><button class="button button-primary" form="gg-pw2-bulk">Beat selected −1%</button></p>

      <h2>Match Inspector (last run)</h2>
      <div style="padding:8px;border:1px solid #ddd;background:#fff;max-height:520px;overflow:auto"><?php echo $insp_html ? $insp_html : '<em>(No match details yet. Run a Dry Run or Manual Run.)</em>'; ?></div>

      <h2>Diagnostics</h2>
      <ul>
        <li>Products seen last run: <strong><?php echo (int)$inv_count; ?></strong></li>
        <li>Next runs (Europe/London): <code><?php echo esc_html(implode(', ',$next_runs)); ?></code></li>
        <li>Cursor page: <code><?php echo esc_html(gg_pw2_opt('cursor_page','1')); ?></code></li>
      </ul>

      <script>
      document.addEventListener('click', function(e){
        if (e.target && e.target.id === 'ggpw2_chk_all') {
          var checked = e.target.checked;
          document.querySelectorAll('.ggpw2-chk').forEach(function(cb){ cb.checked = checked; });
        }
      }, false);
      </script>
    </div>
    <?php
}}

/* ===================== SCHEDULING ===================== */
add_action('init','gg_pw2_reschedule_all');
if (!function_exists('gg_pw2_reschedule_all')) {
function gg_pw2_reschedule_all(){
    $cr = function_exists('_get_cron_array') ? _get_cron_array() : [];
    if (is_array($cr)){
        foreach($cr as $ts=>$events){
            if (!empty($events['gg_pw2_run_scheduled'])){
                foreach($events['gg_pw2_run_scheduled'] as $sig=>$args){
                    $a=isset($args['args'])?$args['args']:[];
                    wp_unschedule_event($ts,'gg_pw2_run_scheduled',$a);
                }
            }
        }
    }
    if (gg_pw2_opt('enabled')!=='1') return;
    $tz=new DateTimeZone('Europe/London');
    $times=array_filter(array_map('trim',explode(',',gg_pw2_opt('times_csv','08:00,15:00,20:00'))));
    $now=new DateTime('now',$tz);
    foreach($times as $t){
        if(!preg_match('/^\d{2}:\d{2}$/',$t)) continue;
        $dt=new DateTime('today '.$t,$tz);
        if($dt<=$now)$dt->modify('+1 day');
        $utc=$dt->getTimestamp()-(int)$tz->getOffset($dt);
        wp_schedule_event($utc,'daily','gg_pw2_run_scheduled',['label'=>$t]);
    }
}}
add_action('gg_pw2_run_scheduled', function($args){ gg_pw2_run(isset($args['label'])?$args['label']:'scheduled'); });
if (!function_exists('gg_pw2_preview_next_runs')) {
function gg_pw2_preview_next_runs(){
    $tz=new DateTimeZone('Europe/London');
    $times=array_filter(array_map('trim',explode(',',gg_pw2_opt('times_csv','08:00,15:00,20:00'))));
    $out=[];
    foreach($times as $t){
        $dt=new DateTime('today '.$t,$tz);
        if ($dt<new DateTime('now',$tz)) $dt->modify('+1 day');
        $out[]=$dt->format('D j M H:i');
    }
    return $out;
}}

/* ===================== CORE JOB ===================== */
if (!function_exists('gg_pw2_run')) {
function gg_pw2_run($label='manual',$no_email=false){
    if (!class_exists('WooCommerce')) return;
    $email_to=gg_pw2_opt('email_to'); $exclude=gg_pw2_opt('seller_username','');
    $min_amt=(float)gg_pw2_opt('min_amt','0'); $min_pct=(float)gg_pw2_opt('min_pct','0');
    $same_cond=gg_pw2_opt('same_condition','1')==='1'; $incl_post=gg_pw2_opt('include_postage','1')==='1';
    $allow_coll=gg_pw2_opt('allow_collection_only','0')==='1';
    $req_rar = true; // locked ON globally; we may relax per-item when rarity is generic
    $skip_var=gg_pw2_opt('skip_variations_when_rarity','1')==='1';

    $batch_limit = (int)gg_pw2_opt('batch_limit','120');
    $start_page  = (int)gg_pw2_opt('cursor_page','1');
    $next_page   = $start_page;

    gg_pw2_log(["run"=>$label,"same_cond"=>$same_cond,"incl_post"=>$incl_post,"allow_coll"=>$allow_coll,"require_rarity"=>$req_rar,"skip_variations_when_rarity"=>$skip_var,"batch_limit"=>$batch_limit,"cursor_page"=>$start_page]);

    $items=gg_pw2_collect_inventory($req_rar, $batch_limit, $start_page, $next_page);
    gg_pw2_set('cursor_page', (string)$next_page);

    gg_pw2_set('seen_inventory_count',(string)count($items));
    if (!$items){
        if(!$no_email) gg_pw2_mail($email_to,"[Price Watch] No products with set codes","No publishable products with detectable set codes.");
        gg_pw2_set_last_email_html('<em>No products with set codes.</em>');
        gg_pw2_set_match_inspector('');
        return ['groups'=>[],'reason'=>'no-inventory'];
    }

    $history=json_decode(gg_pw2_opt('history','{}'),true); if(!is_array($history)) $history=[];
    $groups=[]; $insp=[];
    $insp[]='<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;width:100%;max-width:1100px;font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;font-size:13px"><thead><tr><th align="left">Set Code</th><th align="left">My Rarity</th><th align="left">Chosen</th><th align="left">Notes</th></tr></thead><tbody>';

    foreach($items as $it){
        $set=$it['set_code']; $my=$it['price']; $cond=$it['condition']; $myrar=$it['rarity'];

        $required_rarity = $req_rar ? $myrar : '';
        if (in_array($required_rarity,['rare','common'],true)) $required_rarity='';

        $comp = gg_pw2_fetch_lowest_for_set(
            $set,
            $exclude,
            ($same_cond ? $cond : null),
            $incl_post,
            $required_rarity,
            $allow_coll,
            $skip_var
        );

        // notes/inspector
        if (is_array($comp) && !empty($comp['_inspector'])){
            $notes='<div style="max-height:220px;overflow:auto"><table cellpadding="4" cellspacing="0" border="1" style="border-collapse:collapse;width:100%"><tr><th>#</th><th>Seller</th><th>£(+ship)</th><th>eBay Rarity</th><th>Title Rarity</th><th>Why</th><th>Verdict</th></tr>';
            foreach($comp['_inspector'] as $row){
                $why=isset($row['why'])?$row['why']:'';
                $notes.='<tr><td>'.(int)$row['i'].'</td><td>'.esc_html($row['seller']).'</td><td>£'.number_format((float)$row['cmp'],2).'</td><td>'.esc_html($row['rar_aspect']).'</td><td>'.esc_html($row['rar_title']).'</td><td>'.esc_html($why).'</td><td>'.esc_html($row['verdict']).'</td></tr>';
            }
            $notes.='</table></div>';
        } else $notes='<em>No eligible candidates after filters</em>';

        $chosen=(is_array($comp)&&isset($comp['price'],$comp['seller']))?('£'.number_format((float)$comp['price'],2).' by '.esc_html($comp['seller']).' — <a target="_blank" rel="noopener" href="'.esc_url($comp['url']??'').'" rel="nofollow">view</a>'):'<em>None</em>';
        $hdr_relax = ($required_rarity===''?' <em>(relaxed)</em>':'');
        $insp[]='<tr><td><strong>'.esc_html($set).'</strong></td><td>'.($myrar?esc_html($myrar):'<em>—</em>').$hdr_relax.'</td><td>'.$chosen.'</td><td>'.$notes.'</td></tr>';

        if (!is_array($comp) || !isset($comp['price'])) continue;
        $comp_price=(float)$comp['price']; if ($comp_price<=0) continue; if ($comp_price>=$my) continue;
        $delta=$my-$comp_price; $pct=$my>0?($delta/$my)*100:0;
        if ($min_amt>0 && $delta<$min_amt) continue; if ($min_pct>0 && $pct<$min_pct) continue;

        $prev = isset($history[$set]['lowest']) ? (float)$history[$set]['lowest'] : null;
        $moved_amt=$prev?($prev-$comp_price):0; $moved_pct=($prev && $prev>0)?(($prev-$comp_price)/$prev*100):0;
        if (!isset($groups[$set])) $groups[$set]=[];
        $groups[$set][]= [
            'pid'=>(int)$it['pid'],
            'title'=>$it['title'],
            'my_price'=>$my,
            'comp_price'=>$comp_price,
            'delta'=>$delta,
            'pct'=>$pct,
            'seller'=>$comp['seller'],
            'link'=>$comp['url'],
            'moved_amt'=>$moved_amt,
            'moved_pct'=>$moved_pct
        ];
        $history[$set]=['lowest'=>$comp_price,'ts'=>time()];
    }
    $insp[]='</tbody></table>';
    gg_pw2_set_match_inspector(implode('',$insp));
    gg_pw2_set('history',wp_json_encode($history));

    $hdr = sprintf('Only items where you’re <strong>not the lowest</strong>. Options: same condition %s, include postage %s%s, require rarity %s (relaxed for generic). Thresholds: £%s / %s%%. Batch: up to %d items this run (cursor page %d → %d).',
        $same_cond?'ON':'OFF',
        $incl_post?'ON':'OFF',
        $incl_post?(' (collection-only '.($allow_coll?'ALLOWED':'BLOCKED').')'):'',
        $req_rar?'ON (strict*)':'OFF',
        number_format($min_amt,2), number_format($min_pct,1),
        (int)$batch_limit, (int)$start_page, (int)$next_page
    );

    if (empty($groups)){
        $html='<p>'.$hdr.'</p><em>No undercuts — you’re lowest or tied across matched items.</em>';
        gg_pw2_set_last_email_html($html);
        if(!$no_email) gg_pw2_mail($email_to,"[Price Watch] No undercuts — ".wp_date('j M H:i'),$html,true);
        return ['groups'=>[],'reason'=>'no-undercuts'];
    }

    ob_start(); ?>
    <div style="font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;font-size:14px;line-height:1.5">
      <h2 style="margin:0 0 8px">eBay Price-Watch — <?php echo esc_html( wp_date('D j M Y, H:i', time(), new DateTimeZone('Europe/London')) ); ?> (<?php echo esc_html($label); ?>)</h2>
      <p style="margin:6px 0 12px"><?php echo $hdr; ?></p>
      <table role="presentation" cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;width:100%;max-width:1100px">
        <thead><tr>
          <th align="center" style="width:28px"><input type="checkbox" id="ggpw2_chk_all" title="Select all"></th>
          <th align="left"  style="width:120px">Set</th>
          <th align="left">Item</th>
          <th align="right" style="width:80px">My £</th>
          <th align="right" style="width:100px">Low £ (total)</th>
          <th align="right" style="width:70px">£Δ</th>
          <th align="right" style="width:70px">%Δ</th>
          <th align="left"  style="width:160px">Seller</th>
          <th align="left"  style="width:160px">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($groups as $set=>$rows): usort($rows,function($a,$b){ if($a['pct']==$b['pct'])return 0; return ($a['pct']<$b['pct'])?1:-1; }); $first=true; foreach($rows as $r): ?>
          <tr>
            <td align="center"><?php if(!empty($r['pid'])): ?><input type="checkbox" class="ggpw2-chk" form="gg-pw2-bulk" name="bulk[]" value="<?php echo (int)$r['pid'].'|'.esc_attr($r['comp_price']); ?>"><?php endif; ?></td>
            <td><?php echo $first?'<strong>'.esc_html($set).'</strong>':''; ?></td>
            <td><?php echo esc_html($r['title']); ?></td>
            <td align="right">£<?php echo number_format($r['my_price'],2); ?></td>
            <td align="right">£<?php echo number_format($r['comp_price'],2); ?></td>
            <td align="right">£<?php echo number_format($r['delta'],2); ?></td>
            <td align="right"><?php echo number_format($r['pct'],1); ?>%</td>
            <td><?php echo esc_html($r['seller']); ?></td>
            <td>
              <a href="<?php echo esc_url($r['link']); ?>" target="_blank" rel="noopener">View</a>
              <?php if(!empty($r['pid'])): ?>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin-left:6px">
                <input type="hidden" name="action" value="gg_pw2_beat_one"><?php wp_nonce_field('gg_pw2_beat_one'); ?>
                <input type="hidden" name="pid" value="<?php echo (int)$r['pid']; ?>"><input type="hidden" name="low" value="<?php echo esc_attr($r['comp_price']); ?>">
                <button class="button button-small" title="Push to eBay at 1% below lowest">−1% (eBay)</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php $first=false; endforeach; ?>
        <tr><td colspan="9" style="background:#f8f8f8;height:4px;padding:0"></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
    $html=ob_get_clean(); gg_pw2_set_last_email_html($html);
    if(!$no_email) gg_pw2_mail($email_to,"[eBay Price Watch] Undercuts — ".wp_date('j M H:i'),$html,true);
    return ['groups'=>$groups,'reason'=>'ok'];
}}

/* ===================== DATA GATHERING (batched, variation-aware) ===================== */
if (!function_exists('gg_pw2_collect_inventory')) {
function gg_pw2_collect_inventory($need_rarity, $batch_limit = 120, $start_page = 1, &$next_page = null){
    $per        = 200;
    $want       = max(1, (int)$batch_limit);
    $page       = max(1, (int)$start_page);
    $collected  = 0;
    $out        = [];

    $push_line = function($p_obj,$parent_title) use (&$out,&$collected,$want){
        if (!$p_obj || $collected >= $want) return;
        $line_id = $p_obj->get_id();

        $price = 0.0;
        if (function_exists('wc_get_price_including_tax')){
            $price = (float) wc_get_price_including_tax($p_obj);
        }
        if ($price <= 0) $price = (float) $p_obj->get_price();
        if ($price <= 0) return;

        $codes = gg_pw2_find_product_set_codes($p_obj);
        if (!$codes && $parent_title){
            foreach(gg_pw2_extract_set_codes_from_text($parent_title) as $c) $codes[]=$c;
        }
        if (!$codes){ gg_pw2_log("skip PID {$line_id} — no set code"); return; }

        $cond  = get_post_meta($line_id,'_card_condition',true);
        $rar   = gg_pw2_extract_product_rarity($p_obj);
        $title = get_the_title($line_id) ?: $parent_title;

        foreach($codes as $set){
            if ($collected >= $want) break;
            $out[] = [
                'pid'       => $line_id,
                'title'     => $title,
                'price'     => $price,
                'set_code'  => strtoupper($set),
                'condition' => $cond?:'',
                'rarity'    => $rar
            ];
            $collected++;
        }
    };

    while ($collected < $want){
        $q = new WP_Query([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $per,
            'paged'          => $page,
            'fields'         => 'ids',
            'no_found_rows'  => false
        ]);

        if (!$q->have_posts()){
            $page = 1;
            $next_page = 1;
            break;
        }

        foreach($q->posts as $pid){
            if ($collected >= $want) break;
            $product = function_exists('wc_get_product') ? wc_get_product($pid) : null;
            if (!$product) continue;

            if ($product->is_type('variable')){
                $parent_title = get_the_title($pid);
                $parent_price = (float)$product->get_price();
                if ($parent_price > 0){ $push_line($product,$parent_title); }

                $child_ids = method_exists($product,'get_children') ? (array)$product->get_children() : [];
                foreach($child_ids as $vid){
                    if ($collected >= $want) break;
                    $v = wc_get_product($vid);
                    if ($v) $push_line($v,$parent_title);
                }
            } else {
                $push_line($product, get_the_title($pid));
            }
        }

        if ($collected >= $want){
            $next_page = $page + 1;
            break;
        }

        $page++;
        $next_page = $page;

        if (!empty($q->max_num_pages) && $page > (int)$q->max_num_pages){
            $next_page = 1;
            break;
        }
    }

    gg_pw2_set('seen_inventory_count',(string)count($out));
    return $out;
}}
if (!function_exists('gg_pw2_extract_product_rarity')) {
function gg_pw2_extract_product_rarity($product){
    $pid = is_object($product) ? $product->get_id() : 0;

    $cands = [
        get_post_meta($pid,'_gg_rarity',true),
        get_post_meta($pid,'_rarity',true),
        get_post_meta($pid,'rarity',true),
    ];
    if (is_object($product) && method_exists($product,'get_attributes')){
        foreach ($product->get_attributes() as $key=>$attr){
            $k = is_string($key) ? strtolower($key) : '';
            if (strpos($k,'rarity') !== false){
                $val = is_object($attr) && method_exists($attr,'get_options')
                    ? implode(' ', (array) $attr->get_options())
                    : (string)$attr;
                if ($val) $cands[] = $val;
            }
        }
    }
    $cands[] = get_the_title($pid);

    $found = [];
    foreach ($cands as $raw){
        $n = gg_pw2_normalize_rarity($raw);
        if ($n) $found[$n] = true;
    }
    if (!$found) return '';

    $rank = [
        'quarter century secret rare' => 120,
        'platinum secret rare'        => 115,
        'prismatic secret rare'       => 110,
        'starlight rare'              => 105,
        'ultimate rare'               => 104,
        'ghost rare'                  => 103,
        'collectors rare'             => 102,
        'secret rare stamp'           => 101,
        'ultra rare stamp'            => 100,
        'secret rare'                 =>  90,
        'ultra rare'                  =>  80,
        'super rare'                  =>  70,
        'gold rare'                   =>  60,
        'rare'                        =>  50,
        'common'                      =>  10,
    ];
    $best = ''; $bestScore = -1;
    foreach (array_keys($found) as $n){
        $score = isset($rank[$n]) ? $rank[$n] : 1;
        if ($score > $bestScore){ $best = $n; $bestScore = $score; }
    }
    return $best;
}}

/* ===================== EBAY BROWSE HELPERS ===================== */
if (!function_exists('gg_pw2_browse_page')) {
function gg_pw2_browse_page($set_code_or_query, $token, $offset, $limit = 40, $q_extra = '') {
    $q = '"' . $set_code_or_query . '"';
    if ($q_extra) $q .= ' ' . $q_extra;

    $qp = [
        'q'               => $q,
        'limit'           => $limit,
        'offset'          => max(0, (int)$offset),
        'filter'          => 'buyingOptions:{FIXED_PRICE},priceCurrency:GBP',
        'sort'            => 'priceWithShipping',
        'deliveryCountry' => 'GB'
    ];
    $pc = trim(gg_pw2_opt('delivery_postcode', ''));
    if ($pc !== '') $qp['postalCode'] = $pc;

    $endpoint = add_query_arg($qp, 'https://api.ebay.com/buy/browse/v1/item_summary/search');

    $cache_key = 'gg_pw2_browse_' . md5($endpoint);
    if ($c = get_transient($cache_key)) return $c;

    $resp = wp_remote_get($endpoint, [
        'headers' => [
            'Authorization'            => 'Bearer ' . $token,
            'Content-Type'             => 'application/json',
            'X-EBAY-C-MARKETPLACE-ID'  => 'EBAY_GB',
        ],
        'timeout' => 10,
    ]);

    if (is_wp_error($resp)) { gg_pw2_log("HTTP error @browse {$set_code_or_query} off={$offset}: " . $resp->get_error_message()); return null; }
    $code = wp_remote_retrieve_response_code($resp);
    if ($code < 200 || $code >= 300) { gg_pw2_log("HTTP {$code} @browse {$set_code_or_query} off={$offset}"); return null; }

    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if (is_array($data)) set_transient($cache_key, $data, 10 * MINUTE_IN_SECONDS);
    return $data;
}}
if (!function_exists('gg_pw2_fetch_item_detail')) {
function gg_pw2_fetch_item_detail($item_id,$token){
    $k='gg_pw2_item_'.$item_id; if($c=get_transient($k)) return $c;
    $url='https://api.ebay.com/buy/browse/v1/item/'.$item_id;
    $resp=wp_remote_get($url,[
        'headers'=>[
            'Authorization'=>'Bearer '.$token,
            'Content-Type'=>'application/json',
            'X-EBAY-C-MARKETPLACE-ID'=>'EBAY_GB'
        ],
        'timeout'=>8
    ]);
    if (is_wp_error($resp)) return null;
    $code=wp_remote_retrieve_response_code($resp);
    if($code<200||$code>=300) return null;
    $data=json_decode(wp_remote_retrieve_body($resp),true);
    if($data) set_transient($k,$data,30*MINUTE_IN_SECONDS);
    return is_array($data)?$data:null;
}}

/* ===================== LOWEST (TITLE-FIRST, THEN NAME+RARITY FALLBACK) ===================== */
if (!function_exists('gg_pw2_fetch_lowest_for_set')) {
function gg_pw2_fetch_lowest_for_set(
    $set_code,
    $exclude_seller='',
    $required_condition=null,
    $include_postage=false,
    $required_rarity='',
    $allow_collection_only=false,
    $skip_variations_when_rarity=true
){
    $token = ( function_exists('gg_token_app') ? gg_token_app() : ( function_exists('gg_get_option') ? gg_get_option('ebay_access_token','') : get_option('ebay_access_token','') ) );
    if (is_wp_error($token) || empty($token)){ gg_pw2_log("Browse token missing/err for {$set_code}"); return null; }

    // Tight budgets to prevent timeouts
    $page_limit   = 40;
    $max_pages    = ($required_rarity !== '') ? 3 : 2; // per phase
    $max_details  = 6;   // tightened from 8 for perf
    $time_budget  = 20;
    $t0           = microtime(true);

    $inspector    = [];
    $details_used = 0;
    $best         = null;

    // ---------- Build PHASES (rarity-first then plain set code) ----------
    $phases = [];
    if ($required_rarity !== ''){
        $want = gg_pw2_normalize_rarity($required_rarity);
        $variants = gg_pw2_rarity_variants($want);
        foreach ($variants as $v){ $phases[] = ['label'=>'rarity-first', 'q_extra'=>$v]; }
    }
    $phases[] = ['label'=>'setcode-only', 'q_extra'=>''];

    // ---------- PHASES: REQUIRE TITLE TO CONTAIN EXACT SET CODE ----------
    foreach ($phases as $phase){
        for ($page=0, $offset=0; $page<$max_pages; $page++, $offset+=$page_limit){
            if (microtime(true) - $t0 > $time_budget){ gg_pw2_log("time budget hit for {$set_code} (phase {$phase['label']} p{$page})"); break 2; }

            $data  = gg_pw2_browse_page($set_code, $token, $offset, $page_limit, $phase['q_extra']);
            $items = (is_array($data) && !empty($data['itemSummaries'])) ? $data['itemSummaries'] : [];
            gg_pw2_log("Browse {$phase['label']} p{$page} for {$set_code}: ".count($items)." items (offset={$offset})");
            if (!$items) break;

            $examined = 0;

            foreach ($items as $it){
                if (microtime(true) - $t0 > $time_budget){ gg_pw2_log("time budget hit in-loop for {$set_code}"); break 3; }
                $examined++;

                $seller = $it['seller']['username'] ?? '';
                $title  = $it['title'] ?? '';
                $pcu    = strtoupper(trim($it['price']['currency'] ?? ''));
                $pv     = (float)($it['price']['value'] ?? 0.0);
                $iid    = $it['itemId'] ?? '';

                $reason = '';

                // HARD GATE: Title must contain exact same set code
                if (!gg_pw2_title_has_set_code($set_code, $title)) $reason = 'title_no_setcode';

                // quick excludes
                if (!$reason && $exclude_seller && strcasecmp($seller,$exclude_seller)===0) $reason = 'excluded_seller';
                if (!$reason && $pcu && $pcu!=='GBP') $reason='not_gbp';
                if (!$reason && $pv<=0) $reason='zero_price';

                // Condition
                if (!$reason && $required_condition){
                    $ic = isset($it['condition']) ? (string)$it['condition'] : '';
                    if (!gg_pw2_cond_match($required_condition, $ic, $title)) $reason = 'cond_mismatch';
                }

                // Multi-variation skip when strict rarity is on
                if (!$reason && $required_rarity!=='' && $skip_variations_when_rarity){
                    if (!empty($it['priceRange']) || !empty($it['variationAvailability'])) $reason = 'multi_variation_skip';
                }

                // Shipping (+ collection-only guard if postage is required)
                $ship = 0.0; $had_quote = false;
                if (!$reason && $include_postage){
                    $minShip = null;
                    $opts = isset($it['shippingOptions']) && is_array($it['shippingOptions']) ? $it['shippingOptions'] : [];
                    foreach ($opts as $opt){
                        if (isset($opt['shippingCost']['value'])){
                            $val = (float)$opt['shippingCost']['value']; $had_quote = true;
                            if ($val >= 0 && ($minShip===null || $val < $minShip)) $minShip = $val;
                        }
                        if (isset($opt['shippingServices']) && is_array($opt['shippingServices'])){
                            foreach ($opt['shippingServices'] as $svc){
                                if (isset($svc['shippingCost']['value'])){
                                    $val = (float)$svc['shippingCost']['value']; $had_quote = true;
                                    if ($val >= 0 && ($minShip===null || $val < $minShip)) $minShip = $val;
                                }
                            }
                        }
                    }
                    if ($minShip!==null) $ship = (float)$minShip;
                }
                $cmp = $pv + $ship;

                // STRICT rarity: title → details fallback (capped)
                $rar_title   = gg_pw2_extract_rarity_from_title($title);
                $rar_aspect  = '';
                $ebay_rarity = $rar_title;
                if (!$reason && $required_rarity!==''){
                    $want = gg_pw2_normalize_rarity($required_rarity);
                    if ($want==='quarter century secret rare' && preg_match('/\b(psr|platinum)\b/i',$title)) $reason = 'psr_noise';
                    if (!$reason && $want==='collectors rare'){
                        $hasUltra     = preg_match('/\bultra\s+rare\b/i',$title);
                        $hasCollector = preg_match('/collector(?:s|\'s|s\')?\s+rare/i', $title) || preg_match('/\bcr\b/i',$title);
                        if ($hasUltra && !$hasCollector) $reason = 'cr_ultra_conflict';
                    }
                    if (!$reason && gg_pw2_opt('exclude_platinum','0')==='1' && preg_match('/\b(PLATINUM|PSR)\b/i',$title)) $reason = 'exclude_platinum_title';

                    if (!$reason && !$ebay_rarity && $iid && $details_used < $max_details && $examined <= 5){
                        $detail = gg_pw2_fetch_item_detail($iid, $token); $details_used++;
                        if ($detail && isset($detail['itemSpecifics']) && is_array($detail['itemSpecifics'])){
                            foreach ($detail['itemSpecifics'] as $spec){
                                if (!empty($spec['name']) && strtolower($spec['name'])==='rarity'){
                                    $val = isset($spec['values']) && is_array($spec['values']) ? implode(' ', $spec['values']) : (string)($spec['value'] ?? '');
                                    $rar_aspect = gg_pw2_normalize_rarity($val);
                                    break;
                                }
                            }
                        }
                        $ebay_rarity = $rar_aspect ?: $rar_title;
                    }

                    if (!$reason){
                        if (!$ebay_rarity)               $reason = 'rarity_unknown';
                        elseif ($want !== $ebay_rarity)  $reason = 'rarity_mismatch';
                        if (!$reason && gg_pw2_opt('exclude_platinum','0')==='1' && $ebay_rarity==='platinum secret rare')
                            $reason = 'exclude_platinum_rarity';
                    }
                }

                // If postage required but no quote and collection-only blocked
                if (!$reason && $include_postage && !$had_quote && !$allow_collection_only) $reason = 'no_postage_quote';

                // Build inspector row
                $row = [
                    'i'          => $examined,
                    'seller'     => $seller ?: '—',
                    'cmp'        => $cmp,
                    'rar_aspect' => $rar_aspect ?: '—',
                    'rar_title'  => $rar_title ?: '—',
                    'why'        => $reason ?: 'ok',
                    'verdict'    => $reason ? 'REJECT' : 'ACCEPT',
                ];
                $inspector[] = $row;

                if ($reason) continue;

                // Track lowest
                if ($best === null || $cmp < $best['cmp']){
                    $best = [
                        'cmp'      => $cmp,
                        'price'    => $cmp,
                        'seller'   => $seller ?: '',
                        'url'      => $it['itemWebUrl'] ?? ($it['itemHref'] ?? ''),
                        '_iid'     => $iid,
                    ];
                }

                if ($examined <= 3){
                    $why = ($required_rarity!=='') ? ('want='.$required_rarity.' got=' . ($ebay_rarity?:'—')) : '';
                    gg_pw2_log("  item {$examined} £{$pv} + ship £{$ship} cmp=£{$cmp} seller={$seller} {$why}");
                }
            } // items
        } // pages

        if (!empty($best)) break; // stop early if found in this phase
    } // phases

    // ---------- FALLBACK: NO TITLE HITS → NAME + RARITY ----------
    if (!$best && $required_rarity!==''){
        $name_hint = ''; // keep empty unless you wire product title at call site
        $variants = gg_pw2_rarity_variants($required_rarity);
        if (!empty($variants)){
            foreach ($variants as $v){
                if (microtime(true) - $t0 > $time_budget) break;
                $data = gg_pw2_browse_page($name_hint ?: $set_code, $token, 0, 40, $v);
                $items = (is_array($data) && !empty($data['itemSummaries'])) ? $data['itemSummaries'] : [];
                if (!$items) continue;

                foreach ($items as $it){
                    if (microtime(true) - $t0 > $time_budget) break 2;

                    $seller = $it['seller']['username'] ?? '';
                    $title  = $it['title'] ?? '';
                    $pcu    = strtoupper(trim($it['price']['currency'] ?? ''));
                    $pv     = (float)($it['price']['value'] ?? 0.0);
                    $iid    = $it['itemId'] ?? '';
                    if ($exclude_seller && strcasecmp($seller,$exclude_seller)===0) { $inspector[]=['i'=>0,'seller'=>$seller?:'—','cmp'=>$pv,'rar_aspect'=>'—','rar_title'=>'—','why'=>'fallback_excluded_seller','verdict'=>'REJECT']; continue; }
                    if ($pcu && $pcu!=='GBP') { $inspector[]=['i'=>0,'seller'=>$seller?:'—','cmp'=>$pv,'rar_aspect'=>'—','rar_title'=>'—','why'=>'fallback_not_gbp','verdict'=>'REJECT']; continue; }
                    if ($pv<=0) { $inspector[]=['i'=>0,'seller'=>$seller?:'—','cmp'=>0,'rar_aspect'=>'—','rar_title'=>'—','why'=>'fallback_zero_price','verdict'=>'REJECT']; continue; }

                    // Strict rarity again
                    $rar_title  = gg_pw2_extract_rarity_from_title($title);
                    $rar_aspect = '';
                    $ebay_rarity = $rar_title;
                    if (!$ebay_rarity && $iid && $details_used < $max_details){
                        $detail = gg_pw2_fetch_item_detail($iid,$token); $details_used++;
                        if ($detail && isset($detail['itemSpecifics'])){
                            foreach ($detail['itemSpecifics'] as $spec){
                                if (!empty($spec['name']) && strtolower($spec['name'])==='rarity'){
                                    $val = isset($spec['values']) && is_array($spec['values']) ? implode(' ', $spec['values']) : (string)($spec['value'] ?? '');
                                    $rar_aspect = gg_pw2_normalize_rarity($val);
                                    break;
                                }
                            }
                        }
                        $ebay_rarity = $rar_aspect ?: $rar_title;
                    }
                    if (!$ebay_rarity || gg_pw2_normalize_rarity($required_rarity)!==$ebay_rarity){
                        $inspector[] = ['i'=>0,'seller'=>$seller?:'—','cmp'=>$pv,'rar_aspect'=>$rar_aspect?:'—','rar_title'=>$rar_title?:'—','why'=>'fallback_rarity_mismatch','verdict'=>'REJECT'];
                        continue;
                    }

                    // Shipping/condition same rules
                    $ship=0.0; $had_quote=false;
                    if ($include_postage){
                        $minShip=null; $opts = $it['shippingOptions'] ?? [];
                        foreach ((array)$opts as $opt){
                            if (isset($opt['shippingCost']['value'])){ $val=(float)$opt['shippingCost']['value']; $had_quote=true; if ($val>=0 && ($minShip===null || $val<$minShip)) $minShip=$val; }
                            foreach ((array)($opt['shippingServices'] ?? []) as $svc){
                                if (isset($svc['shippingCost']['value'])){ $val=(float)$svc['shippingCost']['value']; $had_quote=true; if ($val>=0 && ($minShip===null || $val<$minShip)) $minShip=$val; }
                            }
                        }
                        if ($minShip!==null) $ship=(float)$minShip;
                        if (!$had_quote && !$allow_collection_only){ $inspector[]=['i'=>0,'seller'=>$seller?:'—','cmp'=>$pv,'rar_aspect'=>$rar_aspect?:'—','rar_title'=>$rar_title?:'—','why'=>'fallback_no_postage','verdict'=>'REJECT']; continue; }
                    }
                    $cmp = $pv + $ship;

                    $inspector[] = ['i'=>0,'seller'=>$seller?:'—','cmp'=>$cmp,'rar_aspect'=>$rar_aspect?:'—','rar_title'=>$rar_title?:'—','why'=>'fallback_ok','verdict'=>'ACCEPT'];
                    if ($best===null || $cmp < $best['cmp']){
                        $best = [
                            'cmp'=>$cmp,'price'=>$cmp,'seller'=>$seller?:'',
                            'url'=>$it['itemWebUrl'] ?? ($it['itemHref'] ?? ''),
                            '_iid'=>$it['itemId'] ?? ''
                        ];
                    }
                }
                if ($best) break; // stop after first successful variant
            }
        }
    }

    if (!empty($best)) { $best['_inspector']=$inspector; return $best; }
    return (count($inspector) ? ['_inspector'=>$inspector] : null);
}}

/* ===================== EMAIL ===================== */
if (!function_exists('gg_pw2_mail')) {
function gg_pw2_mail($to,$subject,$html,$is_html=true){
    if (!$to) return false;
    $headers=[];
    if ($is_html){ $headers[]='Content-Type: text/html; charset=UTF-8'; }
    $body = $is_html ? $html : wp_strip_all_tags((string)$html);
    return wp_mail($to,$subject,$body,$headers);
}}

/* ===================== EBAY PUSH HELPERS (PRICE) ===================== */
if (!function_exists('gg_pw2_xml')) {
function gg_pw2_xml($s){ return htmlspecialchars((string)$s, ENT_XML1 | ENT_COMPAT, 'UTF-8'); }
}

/* Fallback Trading call if Suite’s gg_trading_call() is unavailable (do not touch Suite) */
if (!function_exists('gg_pw2_trading_call')) {
function gg_pw2_trading_call($call, $xml){
  // Try to get a valid user access token
  if (function_exists('gg_token_user')) {
    $tok = gg_token_user();
    if (is_wp_error($tok)) return $tok;
  } else {
    $tok = get_option('ebay_access_token','');
    if (!$tok) return new WP_Error('oauth_missing','No eBay access token available');
  }

  $headers = array(
    'X-EBAY-API-COMPATIBILITY-LEVEL' => 967,
    'X-EBAY-API-CALL-NAME'           => $call,
    'X-EBAY-API-SITEID'              => '3',
    'X-EBAY-API-IAF-TOKEN'           => $tok,
    'Content-Type'                   => 'text/xml',
  );
  $resp = wp_remote_post('https://api.ebay.com/ws/api.dll', array(
    'headers'=>$headers,
    'body'=>$xml,
    'timeout'=>45,
  ));
  if (is_wp_error($resp)) return $resp;

  $raw = wp_remote_retrieve_body($resp);
  if (!$raw) return new WP_Error('empty', 'Empty response from eBay');
  if (strpos($raw,'<Ack>Success</Ack>') !== false || strpos($raw,'<Ack>Warning</Ack>') !== false) return $resp;

  // Pull error message if present
  $code = null; $short = null; $long = null;
  if (preg_match('/<ErrorCode>(\d+)<\/ErrorCode>/i',$raw,$m)) $code=$m[1];
  if (preg_match('/<ShortMessage>(.*?)<\/ShortMessage>/is',$raw,$m)) $short=trim($m[1]);
  if (preg_match('/<LongMessage>(.*?)<\/LongMessage>/is',$raw,$m))  $long =trim($m[1]);
  $msg = ($short ?: 'Ack=Failure').($long ? ' — '.$long : '').($code ? ' [Code '.$code.']' : '');
  return new WP_Error('ebay_failure',$msg);
}
}

/**
 * Push a new price to eBay for a Woo product that has _gg_ebay_item_id
 * IMPORTANT: This does NOT change Woo price; eBay→Woo sync will later pull it down.
 */
if (!function_exists('gg_pw2_push_price_to_ebay')) {
function gg_pw2_push_price_to_ebay($pid, $new_price){
  $iid = trim((string) get_post_meta($pid, '_gg_ebay_item_id', true));
  if ($iid === '') return new WP_Error('no_item_id','No _gg_ebay_item_id on product '.$pid);

  // Build ReviseInventoryStatus request with StartPrice
  $xml = '<?xml version="1.0" encoding="utf-8"?>'
       . '<ReviseInventoryStatusRequest xmlns="urn:ebay:apis:eBLBaseComponents">'
       .   '<InventoryStatus>'
       .     '<ItemID>'.gg_pw2_xml($iid).'</ItemID>'
       .     '<StartPrice>'.number_format((float)$new_price, 2, '.', '').'</StartPrice>'
       .   '</InventoryStatus>'
       . '</ReviseInventoryStatusRequest>';

  // Prefer Suite’s trading call (adds breaker/limits/logs); else fallback
  if (function_exists('gg_trading_call')) {
    $resp = gg_trading_call('ReviseInventoryStatus', $xml);
  } else {
    $resp = gg_pw2_trading_call('ReviseInventoryStatus', $xml);
  }
  if (is_wp_error($resp)) return $resp;

  $raw = wp_remote_retrieve_body($resp);
  if (strpos($raw,'<Ack>Success</Ack>') !== false || strpos($raw,'<Ack>Warning</Ack>') !== false) {
    return true;
  }

  // Defensive error pull
  $code = null; $short = null; $long = null;
  if (preg_match('/<ErrorCode>(\d+)<\/ErrorCode>/i',$raw,$m)) $code=$m[1];
  if (preg_match('/<ShortMessage>(.*?)<\/ShortMessage>/is',$raw,$m)) $short=trim($m[1]);
  if (preg_match('/<LongMessage>(.*?)<\/LongMessage>/is',$raw,$m))  $long =trim($m[1]);
  $msg = ($short ?: 'Ack not Success').($long ? ' — '.$long : '').($code ? ' [Code '.$code.']' : '');
  return new WP_Error('ebay_price_push_failed', $msg);
}
}

/* ===================== ACTIONS: BEAT ONE / BULK (eBay-first, success-only logs) ===================== */
add_action('admin_post_gg_pw2_beat_one', function(){
    if (!current_user_can('manage_woocommerce')) wp_die('Forbidden');
    check_admin_referer('gg_pw2_beat_one');

    $pid = isset($_POST['pid']) ? (int)$_POST['pid'] : 0;
    $low = isset($_POST['low']) ? (float)$_POST['low'] : 0.0;

    if ($pid && $low > 0){
        $target = round($low * 0.99, 2);
        // tiny nudge if rounding equals current Woo price (we don't change Woo, but avoids equality when pulled back)
        if (function_exists('wc_get_product') && ($p = wc_get_product($pid))){
            $cur = (float)$p->get_price();
            if (abs($target - $cur) < 0.001) $target = max(0, $target - 0.01);
        }

        $push = gg_pw2_push_price_to_ebay($pid, $target);
        if (is_wp_error($push)) {
            $err = $push->get_error_message();
            // Success-only verbose logs (so do NOT log failure here)
            wp_safe_redirect(add_query_arg(['beat'=>'fail','err'=>rawurlencode($err)], admin_url('admin.php?page=gg-price-watch-v2'))); exit;
        } else {
            gg_pw2_log('Beat-1% push OK for PID '.$pid.' → £'.$target); // success only
            wp_safe_redirect(add_query_arg('beat','ok', admin_url('admin.php?page=gg-price-watch-v2'))); exit;
        }
    }
    wp_safe_redirect(add_query_arg('beat','fail', admin_url('admin.php?page=gg-price-watch-v2'))); exit;
});

add_action('admin_post_gg_pw2_beat_bulk', function(){
    if (!current_user_can('manage_woocommerce')) wp_die('Forbidden');
    check_admin_referer('gg_pw2_beat_bulk');

    $pairs = isset($_POST['bulk']) ? (array)$_POST['bulk'] : [];
    $ok=0; $fail=0;

    if ($pairs){
        foreach ($pairs as $pair){
            if (strpos($pair,'|') === false) { $fail++; continue; }
            list($pid,$low) = explode('|',$pair,2);
            $pid=(int)$pid; $low=(float)$low;
            if ($pid<=0 || $low<=0){ $fail++; continue; }

            $target = round($low * 0.99, 2);
            if (function_exists('wc_get_product') && ($p = wc_get_product($pid))){
                $cur = (float)$p->get_price();
                if (abs($target - $cur) < 0.001) $target = max(0, $target - 0.01);
            }

            $push = gg_pw2_push_price_to_ebay($pid, $target);
            if (is_wp_error($push)) {
                $fail++;
                // success-only verbose logs (no failure entry)
            } else {
                $ok++;
                gg_pw2_log('Beat-1% push OK (bulk) PID '.$pid.' → £'.$target); // success only
            }
        }
    }

    wp_safe_redirect(add_query_arg(['beat_bulk'=>'done','ok'=>$ok,'fail'=>$fail], admin_url('admin.php?page=gg-price-watch-v2'))); exit;
});

/* ===================== END ===================== */
