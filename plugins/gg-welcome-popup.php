<?php
/*
Plugin Name: GrimeGames Welcome Popup
Description: Welcome popup with automatic 3% discount code for new visitors
Version: 1.0
Author: GrimeGames
*/

defined('ABSPATH') || exit;

/* =========================
   DATABASE SETUP
   ========================= */

register_activation_hook(__FILE__, 'gg_welcome_popup_install');

function gg_welcome_popup_install() {
  global $wpdb;
  $table = $wpdb->prefix . 'gg_welcome_signups';
  $charset = $wpdb->get_charset_collate();
  
  $sql = "CREATE TABLE IF NOT EXISTS $table (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    email varchar(255) NOT NULL,
    coupon_code varchar(50) NOT NULL,
    marketing_consent tinyint(1) DEFAULT 0,
    created_at datetime NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY email (email),
    KEY coupon_code (coupon_code),
    KEY marketing_consent (marketing_consent)
  ) $charset;";
  
  require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
  dbDelta($sql);
}

/* =========================
   FRONTEND POPUP
   ========================= */

add_action('wp_footer', 'gg_welcome_popup_html');

function gg_welcome_popup_html() {
  // Don't show on checkout or cart pages
  if (is_checkout() || is_cart()) {
    return;
  }
  
  // Don't show if user already signed up (check cookie)
  if (isset($_COOKIE['gg_welcome_signed_up'])) {
    return;
  }
  
  ?>
  <style>
    #ggWelcomePopup {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.8);
      z-index: 999999;
      align-items: center;
      justify-content: center;
      animation: ggFadeIn 0.3s ease;
    }
    
    #ggWelcomePopup.active {
      display: flex;
    }
    
    @keyframes ggFadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }
    
    .gg-popup-content {
      background: white;
      border-radius: 16px;
      max-width: 500px;
      width: 90%;
      padding: 40px 30px;
      position: relative;
      animation: ggSlideUp 0.4s ease;
      box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    }
    
    @keyframes ggSlideUp {
      from { 
        opacity: 0;
        transform: translateY(50px);
      }
      to { 
        opacity: 1;
        transform: translateY(0);
      }
    }
    
    .gg-popup-close {
      position: absolute;
      top: 15px;
      right: 15px;
      background: transparent;
      border: none;
      font-size: 28px;
      cursor: pointer;
      color: #999;
      line-height: 1;
      padding: 5px 10px;
      transition: color 0.2s;
    }
    
    .gg-popup-close:hover {
      color: #333;
    }
    
    .gg-popup-title {
      font-size: 28px;
      font-weight: bold;
      color: #1a202c;
      margin: 0 0 12px 0;
      text-align: center;
    }
    
    .gg-popup-subtitle {
      font-size: 16px;
      color: #667eea;
      font-weight: 600;
      text-align: center;
      margin: 0 0 20px 0;
    }
    
    .gg-popup-message {
      font-size: 15px;
      color: #4a5568;
      line-height: 1.6;
      text-align: center;
      margin: 0 0 30px 0;
    }
    
    .gg-popup-email-input {
      width: 100%;
      padding: 14px 18px;
      border: 2px solid #e2e8f0;
      border-radius: 10px;
      font-size: 16px;
      margin-bottom: 16px;
      transition: border-color 0.2s;
      box-sizing: border-box;
    }
    
    .gg-popup-email-input:focus {
      outline: none;
      border-color: #667eea;
    }
    
    .gg-popup-submit {
      width: 100%;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      border: none;
      border-radius: 10px;
      padding: 16px;
      font-size: 17px;
      font-weight: 600;
      cursor: pointer;
      transition: transform 0.2s, box-shadow 0.2s;
      box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
    }
    
    .gg-popup-submit:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
    }
    
    .gg-popup-submit:active {
      transform: translateY(0);
    }
    
    .gg-popup-submit:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none;
    }
    
    .gg-popup-success {
      display: none;
      text-align: center;
    }
    
    .gg-popup-success.active {
      display: block;
    }
    
    .gg-success-icon {
      font-size: 64px;
      margin-bottom: 20px;
    }
    
    .gg-success-title {
      font-size: 24px;
      font-weight: bold;
      color: #1a202c;
      margin: 0 0 12px 0;
    }
    
    .gg-success-message {
      font-size: 15px;
      color: #4a5568;
      line-height: 1.6;
      margin: 0 0 20px 0;
    }
    
    .gg-coupon-code-display {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      padding: 20px;
      border-radius: 12px;
      margin: 20px 0;
    }
    
    .gg-coupon-label {
      font-size: 12px;
      opacity: 0.9;
      margin-bottom: 8px;
    }
    
    .gg-coupon-code {
      font-size: 32px;
      font-weight: bold;
      letter-spacing: 3px;
      font-family: 'Courier New', monospace;
    }
    
    .gg-popup-no-thanks {
      text-align: center;
      margin-top: 16px;
    }
    
    .gg-popup-no-thanks button {
      background: transparent;
      border: none;
      color: #999;
      font-size: 14px;
      cursor: pointer;
      text-decoration: underline;
    }
    
    .gg-popup-no-thanks button:hover {
      color: #666;
    }
    
    @media (max-width: 600px) {
      .gg-popup-content {
        padding: 30px 20px;
      }
      
      .gg-popup-title {
        font-size: 24px;
      }
      
      .gg-coupon-code {
        font-size: 24px;
      }
    }
  </style>
  
  <div id="ggWelcomePopup">
    <div class="gg-popup-content">
      <button class="gg-popup-close" onclick="ggClosePopup()">&times;</button>
      
      <div id="ggPopupForm">
        <h2 class="gg-popup-title">Welcome! 👋</h2>
        <p class="gg-popup-subtitle">Thank you for supporting us while we're small!</p>
        <p class="gg-popup-message">
          As a thank you, we're offering you an additional <strong>3% off</strong> anything you buy off the website! 
          Enter your email and we'll send you your unique discount code.
        </p>
        
        <input 
          type="email" 
          id="ggPopupEmail" 
          class="gg-popup-email-input" 
          placeholder="Enter your email address"
          required
        >
        
        <label style="display: flex; align-items: flex-start; gap: 10px; margin-bottom: 20px; font-size: 13px; color: #4a5568; cursor: pointer;">
          <input 
            type="checkbox" 
            id="ggPopupMarketing" 
            style="margin-top: 2px; width: 18px; height: 18px; cursor: pointer;"
          >
          <span>I'd like to receive marketing emails about new products, special offers, and exclusive deals. You can unsubscribe at any time.</span>
        </label>
        
        <button class="gg-popup-submit" onclick="ggSubmitPopup()">
          Get My 3% Discount Code 🎁
        </button>
        
        <div class="gg-popup-no-thanks">
          <button onclick="ggClosePopup()">No thanks, I'll pay full price</button>
        </div>
      </div>
      
      <div id="ggPopupSuccess" class="gg-popup-success">
        <div class="gg-success-icon">🎉</div>
        <h3 class="gg-success-title">Check Your Email!</h3>
        <p class="gg-success-message">
          We've sent your unique discount code to your inbox. 
          Use it at checkout to save 3% on your order!
        </p>
        
        <div class="gg-coupon-code-display">
          <div class="gg-coupon-label">Your Discount Code:</div>
          <div class="gg-coupon-code" id="ggCouponCodeDisplay"></div>
        </div>
        
        <button class="gg-popup-submit" onclick="ggClosePopup()">
          Start Shopping! 🛒
        </button>
      </div>
    </div>
  </div>
  
  <script>
  // Show popup after 2 seconds
  setTimeout(function() {
    document.getElementById('ggWelcomePopup').classList.add('active');
  }, 2000);
  
  function ggClosePopup() {
    document.getElementById('ggWelcomePopup').classList.remove('active');
    
    // Set cookie so it doesn't show again
    document.cookie = 'gg_welcome_closed=1; max-age=2592000; path=/'; // 30 days
  }
  
  function ggSubmitPopup() {
    const emailInput = document.getElementById('ggPopupEmail');
    const email = emailInput.value.trim();
    const marketingConsent = document.getElementById('ggPopupMarketing').checked ? '1' : '0';
    
    // Validate email
    if (!email) {
      alert('Please enter your email address');
      return;
    }
    
    if (!email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
      alert('Please enter a valid email address');
      return;
    }
    
    const button = document.querySelector('.gg-popup-submit');
    button.disabled = true;
    button.textContent = 'Processing...';
    
    // Submit via AJAX
    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: 'action=gg_welcome_signup&email=' + encodeURIComponent(email) + '&marketing_consent=' + marketingConsent + '&nonce=<?php echo wp_create_nonce('gg_welcome_signup'); ?>'
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        // Show success message
        document.getElementById('ggPopupForm').style.display = 'none';
        document.getElementById('ggPopupSuccess').classList.add('active');
        document.getElementById('ggCouponCodeDisplay').textContent = data.data.coupon_code;
        
        // Set cookie so popup doesn't show again
        document.cookie = 'gg_welcome_signed_up=1; max-age=31536000; path=/'; // 1 year
      } else {
        alert('Error: ' + data.data);
        button.disabled = false;
        button.textContent = 'Get My 3% Discount Code 🎁';
      }
    })
    .catch(error => {
      alert('An error occurred. Please try again.');
      button.disabled = false;
      button.textContent = 'Get My 3% Discount Code 🎁';
    });
  }
  
  // Allow Enter key to submit
  document.getElementById('ggPopupEmail').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
      ggSubmitPopup();
    }
  });
  </script>
  <?php
}

/* =========================
   AJAX HANDLER
   ========================= */

add_action('wp_ajax_gg_welcome_signup', 'gg_welcome_signup_ajax');
add_action('wp_ajax_nopriv_gg_welcome_signup', 'gg_welcome_signup_ajax');

function gg_welcome_signup_ajax() {
  check_ajax_referer('gg_welcome_signup', 'nonce');
  
  $email = sanitize_email($_POST['email'] ?? '');
  $marketing_consent = intval($_POST['marketing_consent'] ?? 0);
  
  if (!$email || !is_email($email)) {
    wp_send_json_error('Invalid email address');
  }
  
  global $wpdb;
  $table = $wpdb->prefix . 'gg_welcome_signups';
  
  // Check if email already exists
  $existing = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM $table WHERE email = %s",
    $email
  ));
  
  if ($existing) {
    // Update marketing consent if they check the box
    if ($marketing_consent) {
      $wpdb->update(
        $table,
        ['marketing_consent' => 1],
        ['email' => $email]
      );
    }
    
    // Return existing coupon code
    wp_send_json_success([
      'coupon_code' => $existing->coupon_code,
      'message' => 'Welcome back! Here\'s your discount code.',
    ]);
  }
  
  // Generate unique coupon code
  $coupon_code = gg_generate_welcome_coupon($email);
  
  // Save to database
  $wpdb->insert($table, [
    'email' => $email,
    'coupon_code' => $coupon_code,
    'marketing_consent' => $marketing_consent,
    'created_at' => current_time('mysql'),
  ]);
  
  // Send email
  gg_send_welcome_email($email, $coupon_code);
  
  wp_send_json_success([
    'coupon_code' => $coupon_code,
    'message' => 'Check your email!',
  ]);
}

/* =========================
   COUPON GENERATION
   ========================= */

function gg_generate_welcome_coupon($email) {
  // Generate unique code
  $prefix = 'WELCOME3';
  $suffix = strtoupper(substr(md5($email . time()), 0, 6));
  $coupon_code = $prefix . '-' . $suffix;
  
  // Create WooCommerce coupon
  $coupon = new WC_Coupon();
  $coupon->set_code($coupon_code);
  $coupon->set_discount_type('percent');
  $coupon->set_amount(3);
  $coupon->set_individual_use(true);  // Can't stack with other coupons
  $coupon->set_usage_limit(1);  // Only 1 use total
  $coupon->set_usage_limit_per_user(1);  // Only 1 use per customer
  // REMOVED: set_email_restrictions - No email restriction, just usage limits
  $coupon->set_description('Welcome discount - 3% off - One time use');
  $coupon->save();
  
  return $coupon_code;
}

/* =========================
   FORCE CART RECALCULATION
   ========================= */

// Force WooCommerce to recalculate totals when WELCOME3 coupons are applied
add_action('woocommerce_applied_coupon', 'gg_force_cart_recalculation');

function gg_force_cart_recalculation($coupon_code) {
  // Only for our welcome coupons
  if (strpos($coupon_code, 'WELCOME3-') === 0) {
    WC()->cart->calculate_totals();
  }
}

/* =========================
   EMAIL NOTIFICATION
   ========================= */

function gg_send_welcome_email($email, $coupon_code) {
  $subject = 'Welcome to GrimeGames! Here\'s your 3% discount code 🎁';
  
  $message = '
  <html>
  <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
      <h2 style="color: #667eea;">Welcome to GrimeGames! 👋</h2>
      
      <p>Thank you for supporting us when we\'re small!</p>
      
      <p>As promised, here\'s your exclusive <strong>3% discount code</strong>:</p>
      
      <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 12px; text-align: center; margin: 30px 0;">
        <p style="margin: 0 0 10px 0; font-size: 14px; opacity: 0.9;">Your Discount Code:</p>
        <h1 style="margin: 0; font-size: 36px; letter-spacing: 3px; font-family: \'Courier New\', monospace;">' . esc_html($coupon_code) . '</h1>
        <p style="margin: 10px 0 0 0; font-size: 18px; font-weight: bold;">3% OFF</p>
      </div>
      
      <p><strong>How to use:</strong></p>
      <ol>
        <li>Browse our collection of Yu-Gi-Oh cards</li>
        <li>Add items to your cart</li>
        <li>Enter code <strong>' . esc_html($coupon_code) . '</strong> at checkout</li>
        <li>Enjoy your 3% discount!</li>
      </ol>
      
      <p style="margin-top: 30px;">
        <a href="' . home_url() . '" style="background: #667eea; color: white; padding: 14px 28px; text-decoration: none; border-radius: 6px; display: inline-block; font-weight: bold;">Start Shopping</a>
      </p>
      
      <p style="margin-top: 30px; font-size: 12px; color: #999;">
        This code is unique to you and can be used once on any order.
      </p>
    </div>
  </body>
  </html>
  ';
  
  $headers = ['Content-Type: text/html; charset=UTF-8'];
  
  wp_mail($email, $subject, $message, $headers);
}

/* =========================
   ADMIN PAGE
   ========================= */

add_action('admin_menu', function() {
  add_submenu_page(
    'woocommerce',
    'Welcome Signups',
    '👋 Welcome Signups',
    'manage_woocommerce',
    'gg-welcome-signups',
    'gg_welcome_signups_admin_page'
  );
});

/* =========================
   CSV EXPORT FOR MARKETING
   ========================= */

add_action('wp_ajax_gg_export_marketing_emails', 'gg_export_marketing_emails');

function gg_export_marketing_emails() {
  if (!current_user_can('manage_woocommerce')) {
    wp_die('Access denied');
  }
  
  global $wpdb;
  $table = $wpdb->prefix . 'gg_welcome_signups';
  
  // Get only emails that have consented to marketing
  $emails = $wpdb->get_results(
    "SELECT email, created_at FROM $table WHERE marketing_consent = 1 ORDER BY created_at DESC"
  );
  
  // Set headers for CSV download
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=grimegames-marketing-emails-' . date('Y-m-d') . '.csv');
  
  // Output CSV
  $output = fopen('php://output', 'w');
  
  // Header row
  fputcsv($output, ['Email', 'Signup Date']);
  
  // Data rows
  foreach ($emails as $row) {
    fputcsv($output, [
      $row->email,
      date('Y-m-d H:i:s', strtotime($row->created_at))
    ]);
  }
  
  fclose($output);
  exit;
}

/* =========================
   ADMIN PAGE
   ========================= */

function gg_welcome_signups_admin_page() {
  global $wpdb;
  $table = $wpdb->prefix . 'gg_welcome_signups';
  
  $total_signups = $wpdb->get_var("SELECT COUNT(*) FROM $table");
  $marketing_signups = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE marketing_consent = 1");
  
  // Get usage stats
  $coupons_used = 0;
  $signups = $wpdb->get_results("SELECT coupon_code FROM $table");
  
  foreach ($signups as $signup) {
    $coupon = new WC_Coupon($signup->coupon_code);
    if ($coupon->get_usage_count() > 0) {
      $coupons_used++;
    }
  }
  
  $conversion_rate = $total_signups > 0 ? round(($coupons_used / $total_signups) * 100, 1) : 0;
  
  ?>
  <div class="wrap">
    <h1>👋 Welcome Popup Signups</h1>
    
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin: 20px 0;">
      <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <h3 style="margin: 0 0 10px 0; color: #666;">Total Signups</h3>
        <div style="font-size: 36px; font-weight: bold; color: #667eea;"><?php echo $total_signups; ?></div>
      </div>
      
      <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <h3 style="margin: 0 0 10px 0; color: #666;">Marketing Consent</h3>
        <div style="font-size: 36px; font-weight: bold; color: #764ba2;"><?php echo $marketing_signups; ?></div>
      </div>
      
      <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <h3 style="margin: 0 0 10px 0; color: #666;">Codes Used</h3>
        <div style="font-size: 36px; font-weight: bold; color: #48bb78;"><?php echo $coupons_used; ?></div>
      </div>
      
      <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <h3 style="margin: 0 0 10px 0; color: #666;">Conversion Rate</h3>
        <div style="font-size: 36px; font-weight: bold; color: #f59e0b;"><?php echo $conversion_rate; ?>%</div>
      </div>
    </div>
    
    <div style="margin: 20px 0;">
      <a href="<?php echo admin_url('admin-ajax.php?action=gg_export_marketing_emails'); ?>" class="button button-primary">
        📧 Export Marketing Emails (CSV)
      </a>
      <p style="font-size: 12px; color: #666; margin-top: 8px;">
        Exports only emails that have consented to marketing communications (GDPR compliant)
      </p>
    </div>
    
    <h2>Recent Signups</h2>
    
    <?php
    $recent = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC LIMIT 100");
    
    if ($recent):
    ?>
      <table class="wp-list-table widefat fixed striped">
        <thead>
          <tr>
            <th>Email</th>
            <th>Coupon Code</th>
            <th>Marketing Consent</th>
            <th>Signed Up</th>
            <th>Usage</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $row): 
            $coupon = new WC_Coupon($row->coupon_code);
            $usage_count = $coupon->get_usage_count();
          ?>
            <tr>
              <td><?php echo esc_html($row->email); ?></td>
              <td><code><?php echo esc_html($row->coupon_code); ?></code></td>
              <td>
                <?php if ($row->marketing_consent): ?>
                  <span style="color: #48bb78;">✅ Yes</span>
                <?php else: ?>
                  <span style="color: #999;">❌ No</span>
                <?php endif; ?>
              </td>
              <td><?php echo date('M j, Y g:i a', strtotime($row->created_at)); ?></td>
              <td>
                <?php if ($usage_count > 0): ?>
                  <span style="color: #48bb78;">✅ Used</span>
                <?php else: ?>
                  <span style="color: #999;">Not used yet</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p>No signups yet.</p>
    <?php endif; ?>
  </div>
  <?php
}