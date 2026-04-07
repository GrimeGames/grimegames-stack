<?php
/*
Plugin Name: GrimeGames Snapshot Mobile PWA
Description: Mobile-optimized snapshot & pricing interface with PWA support
Author: GrimeGames
Version: 1.0
*/

defined('ABSPATH') || exit;

/* =========================
   ADMIN MENU
   ========================= */

add_action('admin_menu', function() {
  add_submenu_page(
    'gg-ebay-suite',
    'Mobile Snapshot',
    '📱 Mobile Snapshot',
    'manage_woocommerce',
    'gg-mobile-snapshot',
    'gg_mobile_snapshot_page'
  );
}, 100);

/* =========================
   MOBILE SNAPSHOT PAGE
   ========================= */

function gg_mobile_snapshot_page() {
  if (!current_user_can('manage_woocommerce')) {
    wp_die('Access denied');
  }
  
  ?>
  <!DOCTYPE html>
  <html>
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="theme-color" content="#6B46C1">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="GrimeGames">
    <title>GrimeGames Snapshot</title>
    <link rel="manifest" href="<?php echo admin_url('admin-ajax.php?action=gg_pwa_manifest'); ?>">
    <style>
      * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
      }
      
      body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        min-height: 100vh;
        padding: 0;
        overflow-x: hidden;
        -webkit-font-smoothing: antialiased;
      }
      
      .app-container {
        max-width: 600px;
        margin: 0 auto;
        padding: 20px;
        padding-bottom: 100px;
      }
      
      .header {
        background: white;
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 20px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
      }
      
      .header h1 {
        font-size: 28px;
        color: #1a202c;
        margin-bottom: 8px;
      }
      
      .header p {
        color: #718096;
        font-size: 14px;
      }
      
      .build-button {
        width: 100%;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border: none;
        border-radius: 12px;
        padding: 18px;
        font-size: 18px;
        font-weight: 600;
        cursor: pointer;
        box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        transition: transform 0.2s, box-shadow 0.2s;
      }
      
      .build-button:active {
        transform: scale(0.98);
      }
      
      .build-button:disabled {
        opacity: 0.6;
        cursor: not-allowed;
      }
      
      .progress-container {
        background: white;
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 20px;
        display: none;
        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
      }
      
      .progress-container.active {
        display: block;
      }
      
      .progress-bar {
        height: 8px;
        background: #e2e8f0;
        border-radius: 10px;
        overflow: hidden;
        margin: 16px 0;
      }
      
      .progress-fill {
        height: 100%;
        background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
        border-radius: 10px;
        transition: width 0.3s ease;
        width: 0%;
      }
      
      .progress-text {
        text-align: center;
        color: #4a5568;
        font-size: 14px;
        margin-top: 8px;
      }
      
      .results-container {
        display: none;
      }
      
      .results-container.active {
        display: block;
      }
      
      .result-card {
        background: white;
        border-radius: 16px;
        padding: 24px;
        margin-bottom: 16px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        animation: slideIn 0.3s ease;
      }
      
      @keyframes slideIn {
        from {
          opacity: 0;
          transform: translateY(20px);
        }
        to {
          opacity: 1;
          transform: translateY(0);
        }
      }
      
      .card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 16px;
      }
      
      .card-title {
        font-size: 16px;
        font-weight: 600;
        color: #1a202c;
        margin-bottom: 4px;
      }
      
      .card-code {
        font-size: 12px;
        color: #718096;
        font-family: 'Courier New', monospace;
      }
      
      .rarity-badge {
        background: #f0f4f8;
        color: #667eea;
        padding: 6px 12px;
        border-radius: 8px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
      }
      
      .price-comparison {
        display: flex;
        gap: 16px;
        margin: 16px 0;
        padding: 16px;
        background: #f7fafc;
        border-radius: 12px;
      }
      
      .price-item {
        flex: 1;
      }
      
      .price-label {
        font-size: 11px;
        color: #718096;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 4px;
      }
      
      .price-value {
        font-size: 24px;
        font-weight: 700;
        color: #1a202c;
      }
      
      .price-diff {
        margin-top: 8px;
        padding: 8px;
        background: #fed7d7;
        color: #c53030;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        text-align: center;
      }
      
      .target-price {
        background: #c6f6d5;
        color: #22543d;
        padding: 12px;
        border-radius: 12px;
        text-align: center;
        margin: 16px 0;
      }
      
      .target-price-label {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        opacity: 0.8;
      }
      
      .target-price-value {
        font-size: 28px;
        font-weight: 700;
        margin-top: 4px;
      }
      
      .card-actions {
        display: flex;
        gap: 12px;
        margin-top: 20px;
      }
      
      .action-button {
        flex: 1;
        border: none;
        border-radius: 12px;
        padding: 16px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: transform 0.2s;
      }
      
      .action-button:active {
        transform: scale(0.95);
      }
      
      .beat-button {
        background: #48bb78;
        color: white;
      }
      
      .skip-button {
        background: #e2e8f0;
        color: #4a5568;
      }
      
      .summary {
        background: white;
        border-radius: 16px;
        padding: 24px;
        text-align: center;
        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
      }
      
      .summary-icon {
        font-size: 64px;
        margin-bottom: 16px;
      }
      
      .summary-title {
        font-size: 24px;
        font-weight: 700;
        color: #1a202c;
        margin-bottom: 8px;
      }
      
      .summary-text {
        color: #718096;
        margin-bottom: 24px;
      }
      
      .install-prompt {
        background: white;
        border-radius: 16px;
        padding: 20px;
        margin-bottom: 20px;
        display: none;
        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
      }
      
      .install-prompt.active {
        display: block;
      }
      
      .install-prompt h3 {
        font-size: 18px;
        margin-bottom: 8px;
        color: #1a202c;
      }
      
      .install-prompt p {
        font-size: 14px;
        color: #718096;
        margin-bottom: 16px;
      }
      
      .install-button {
        width: 100%;
        background: #667eea;
        color: white;
        border: none;
        border-radius: 12px;
        padding: 14px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
      }
      
      .hidden {
        display: none;
      }
    </style>
  </head>
  <body>
    <div class="app-container">
      <!-- Install Prompt -->
      <div class="install-prompt" id="installPrompt">
        <h3>📱 Install GrimeGames App</h3>
        <p>Add this to your home screen for quick access!</p>
        <button class="install-button" id="installButton">Install App</button>
      </div>
      
      <!-- Header -->
      <div class="header">
        <h1>📊 Snapshot</h1>
        <p>Beat competitor prices in real-time</p>
        <button class="build-button" id="buildButton" style="margin-top: 16px;">
          🔄 Build Snapshot
        </button>
      </div>
      
      <!-- Progress -->
      <div class="progress-container" id="progressContainer">
        <h3 style="font-size: 18px; color: #1a202c; margin-bottom: 16px;">Building Snapshot...</h3>
        <div class="progress-bar">
          <div class="progress-fill" id="progressFill"></div>
        </div>
        <div class="progress-text" id="progressText">Starting...</div>
      </div>
      
      <!-- Results -->
      <div class="results-container" id="resultsContainer"></div>
    </div>
    
    <script>
      let deferredPrompt;
      let currentItems = [];
      let currentIndex = 0;
      let beatenCount = 0;
      let skippedCount = 0;
      
      // PWA Install Prompt
      window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
        document.getElementById('installPrompt').classList.add('active');
      });
      
      document.getElementById('installButton').addEventListener('click', async () => {
        if (deferredPrompt) {
          deferredPrompt.prompt();
          const { outcome } = await deferredPrompt.userChoice;
          deferredPrompt = null;
          document.getElementById('installPrompt').classList.remove('active');
        }
      });
      
      let wakeLock = null;
      
      async function requestWakeLock() {
        try { if ('wakeLock' in navigator) wakeLock = await navigator.wakeLock.request('screen'); } catch(e) {}
      }
      async function releaseWakeLock() {
        if (wakeLock) { try { await wakeLock.release(); } catch(e) {} wakeLock = null; }
      }
      
      // Build Snapshot
      document.getElementById('buildButton').addEventListener('click', buildSnapshot);
      
      async function buildSnapshot() {
        document.getElementById('buildButton').disabled = true;
        document.getElementById('buildButton').textContent = 'Building...';
        document.getElementById('progressContainer').classList.add('active');
        document.getElementById('resultsContainer').classList.remove('active');
        
        await requestWakeLock();
        
        var ajaxUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
        var chunkedNonce = '<?php echo wp_create_nonce('gg_snapshot_chunked'); ?>';
        var readNonce = '<?php echo wp_create_nonce('gg_build_snapshot_ajax'); ?>';
        
        try {
          // Phase 1: Collect listings
          document.getElementById('progressText').textContent = 'Collecting listings...';
          document.getElementById('progressFill').style.width = '5%';
          
          const r1 = await fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=gg_snapshot_phase1&_wpnonce=' + chunkedNonce
          });
          const d1 = await r1.json();
          
          if (!d1.success) {
            alert('Phase 1 error: ' + (d1.data || 'Unknown'));
            resetUI();
            return;
          }
          
          var total = d1.data.total_codes;
          if (total === 0) {
            document.getElementById('progressFill').style.width = '100%';
            document.getElementById('progressText').textContent = 'No items found.';
            setTimeout(() => { releaseWakeLock(); showNoResults(); }, 500);
            return;
          }
          
          document.getElementById('progressText').textContent = d1.data.total_items + ' items, ' + total + ' codes. Searching...';
          document.getElementById('progressFill').style.width = '10%';
          
          // Phase 2: Search competitors in batches
          var offset = 0;
          var batchSize = 5;
          
          while (true) {
            if (offset >= total) break;
            
            const r2 = await fetch(ajaxUrl, {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: 'action=gg_snapshot_phase2&_wpnonce=' + chunkedNonce + '&offset=' + offset + '&batch_size=' + batchSize
            });
            const d2 = await r2.json();
            
            if (!d2.success) {
              alert('Phase 2 error: ' + (d2.data || 'Unknown'));
              resetUI();
              return;
            }
            
            offset = d2.data.offset;
            var pct = 10 + Math.round((Math.min(offset, total) / total) * 85);
            document.getElementById('progressFill').style.width = Math.min(pct, 95) + '%';
            document.getElementById('progressText').textContent = 'Searching: ' + Math.min(offset, total) + ' / ' + total + ' codes...';
            
            if (d2.data.done) break;
          }
          
          // Read results from saved snapshot
          document.getElementById('progressText').textContent = 'Loading results...';
          document.getElementById('progressFill').style.width = '98%';
          
          const r3 = await fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=gg_build_snapshot_ajax&_wpnonce=' + readNonce
          });
          const data = await r3.json();
          
          if (data.success) {
            currentItems = data.data.undercuts || [];
            currentIndex = 0;
            beatenCount = 0;
            skippedCount = 0;
            
            document.getElementById('progressFill').style.width = '100%';
            document.getElementById('progressText').textContent = 'Found ' + currentItems.length + ' undercuts';
            
            setTimeout(() => {
              releaseWakeLock();
              document.getElementById('progressContainer').classList.remove('active');
              if (currentItems.length > 0) {
                showResults();
              } else {
                showNoResults();
              }
            }, 500);
          } else {
            alert('Error loading results: ' + (data.data || 'Unknown'));
            resetUI();
          }
        } catch (error) {
          alert('Error: ' + error.message);
          resetUI();
        }
      }
      
      function showResults() {
        document.getElementById('resultsContainer').classList.add('active');
        showNextItem();
      }
      
      function showNextItem() {
        if (currentIndex >= currentItems.length) {
          showSummary();
          return;
        }
        
        const item = currentItems[currentIndex];
        const html = `
          <div class="result-card">
            <div class="card-header">
              <div>
                <div class="card-title">${escapeHtml(item.title)}</div>
                <div class="card-code">${escapeHtml(item.set_code)}</div>
              </div>
              <div class="rarity-badge">${escapeHtml(item.my_bucket)}</div>
            </div>
            
            <div class="price-comparison">
              <div class="price-item">
                <div class="price-label">Your Price</div>
                <div class="price-value">£${item.my_price.toFixed(2)}</div>
              </div>
              <div class="price-item">
                <div class="price-label">Competitor</div>
                <div class="price-value">£${item.comp_price.toFixed(2)}</div>
              </div>
            </div>
            
            <div class="price-diff">
              You're £${item.diff.toFixed(2)} more expensive
            </div>
            
            <div class="target-price">
              <div class="target-price-label">Beat to</div>
              <div class="target-price-value">£${item.target.toFixed(2)}</div>
              <div style="font-size: 12px; margin-top: 4px; opacity: 0.8;">(1% less than competitor)</div>
            </div>
            
            <div class="card-actions">
              <button class="action-button beat-button" onclick="beatItem('${item.item_id}', ${item.target})">
                ✅ Beat It
              </button>
              <button class="action-button skip-button" onclick="skipItem()">
                Skip
              </button>
            </div>
            
            <div style="text-align: center; margin-top: 16px; color: #718096; font-size: 14px;">
              ${currentIndex + 1} of ${currentItems.length}
            </div>
          </div>
        `;
        
        document.getElementById('resultsContainer').innerHTML = html;
      }
      
      async function beatItem(itemId, targetPrice) {
        const button = event.target;
        button.disabled = true;
        button.textContent = 'Updating...';
        
        try {
          const response = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=gg_beat_item_ajax&item_id=${itemId}&target_price=${targetPrice}&_wpnonce=<?php echo wp_create_nonce('gg_beat_item_ajax'); ?>`
          });
          
          const data = await response.json();
          
          if (data.success) {
            beatenCount++;
            currentIndex++;
            showNextItem();
          } else {
            alert('Error: ' + (data.data || 'Failed to update price'));
            button.disabled = false;
            button.textContent = '✅ Beat It';
          }
        } catch (error) {
          alert('Error: ' + error.message);
          button.disabled = false;
          button.textContent = '✅ Beat It';
        }
      }
      
      function skipItem() {
        skippedCount++;
        currentIndex++;
        showNextItem();
      }
      
      function showNoResults() {
        document.getElementById('resultsContainer').innerHTML = `
          <div class="summary">
            <div class="summary-icon">✅</div>
            <div class="summary-title">All Good!</div>
            <div class="summary-text">No competitors are undercutting you right now.</div>
            <button class="build-button" onclick="resetUI()">Build Again</button>
          </div>
        `;
        document.getElementById('resultsContainer').classList.add('active');
      }
      
      function showSummary() {
        document.getElementById('resultsContainer').innerHTML = `
          <div class="summary">
            <div class="summary-icon">🎉</div>
            <div class="summary-title">Complete!</div>
            <div class="summary-text">
              Updated ${beatenCount} item${beatenCount !== 1 ? 's' : ''}<br>
              Skipped ${skippedCount} item${skippedCount !== 1 ? 's' : ''}
            </div>
            <button class="build-button" onclick="resetUI()">Build New Snapshot</button>
          </div>
        `;
      }
      
      function resetUI() {
        releaseWakeLock();
        document.getElementById('buildButton').disabled = false;
        document.getElementById('buildButton').textContent = '🔄 Build Snapshot';
        document.getElementById('progressContainer').classList.remove('active');
        document.getElementById('resultsContainer').classList.remove('active');
        document.getElementById('progressFill').style.width = '0%';
      }
      
      function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
      }
    </script>
  </body>
  </html>
  <?php
  exit;
}

/* =========================
   AJAX HANDLERS
   ========================= */

// Build snapshot AJAX
add_action('wp_ajax_gg_build_snapshot_ajax', 'gg_build_snapshot_ajax');

function gg_build_snapshot_ajax() {
  check_ajax_referer('gg_build_snapshot_ajax');
  
  if (!current_user_can('manage_woocommerce')) {
    wp_send_json_error('Access denied');
  }
  
  // Read the already-built snapshot (built by chunked Phase 1+2)
  $snapshot = get_option('gg_price_snapshot_v1', []);
  
  if (empty($snapshot)) {
    wp_send_json_error('No snapshot data');
  }
  
  $items = $snapshot['items'] ?? [];
  $codes = $snapshot['codes'] ?? [];
  
  // Build undercuts array
  $undercuts = [];
  
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
    
    if ($my_price <= $comp_price) continue;
    
    $target = round($comp_price * 0.99, 2);
    if ($target < 0.99) continue;
    
    $undercuts[] = [
      'item_id' => $id,
      'title' => (string)($row['title'] ?? ''),
      'set_code' => $set_code,
      'my_price' => $my_price,
      'my_bucket' => (string)($row['rarity_bucket'] ?? ''),
      'comp_price' => $comp_price,
      'diff' => $my_price - $comp_price,
      'target' => $target,
    ];
  }
  
  // Sort by biggest difference
  usort($undercuts, function($a, $b) {
    return $b['diff'] <=> $a['diff'];
  });
  
  wp_send_json_success([
    'undercuts' => $undercuts,
    'total' => count($undercuts),
  ]);
}

// Beat item AJAX
add_action('wp_ajax_gg_beat_item_ajax', 'gg_beat_item_ajax');

function gg_beat_item_ajax() {
  check_ajax_referer('gg_beat_item_ajax');
  
  if (!current_user_can('manage_woocommerce')) {
    wp_send_json_error('Access denied');
  }
  
  $item_id = sanitize_text_field($_POST['item_id'] ?? '');
  $target_price = floatval($_POST['target_price'] ?? 0);
  
  if (!$item_id || $target_price <= 0) {
    wp_send_json_error('Invalid parameters');
  }
  
  // Call existing snapshot function
  if (!function_exists('gg_snapshot_revise_price_on_ebay')) {
    wp_send_json_error('Function not found');
  }
  
  $result = gg_snapshot_revise_price_on_ebay($item_id, $target_price);
  
  if (is_wp_error($result)) {
    wp_send_json_error($result->get_error_message());
  }
  
  wp_send_json_success([
    'item_id' => $item_id,
    'new_price' => $target_price,
  ]);
}

/* =========================
   PWA MANIFEST
   ========================= */

add_action('wp_ajax_gg_pwa_manifest', 'gg_pwa_manifest');
add_action('wp_ajax_nopriv_gg_pwa_manifest', 'gg_pwa_manifest');

function gg_pwa_manifest() {
  header('Content-Type: application/json');
  
  $manifest = [
    'name' => 'GrimeGames Snapshot',
    'short_name' => 'GrimeGames',
    'description' => 'Mobile snapshot and pricing tool',
    'start_url' => admin_url('admin.php?page=gg-mobile-snapshot'),
    'display' => 'standalone',
    'background_color' => '#6B46C1',
    'theme_color' => '#6B46C1',
    'orientation' => 'portrait',
    'icons' => [
      [
        'src' => 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><rect width="512" height="512" rx="115" fill="#6B46C1"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-family="Arial" font-size="280" fill="white" font-weight="bold">GG</text></svg>'),
        'sizes' => '512x512',
        'type' => 'image/svg+xml',
        'purpose' => 'any maskable'
      ]
    ]
  ];
  
  echo json_encode($manifest);
  exit;
}

/* =========================
   SERVICE WORKER
   ========================= */

add_action('wp_ajax_gg_pwa_sw', 'gg_pwa_service_worker');
add_action('wp_ajax_nopriv_gg_pwa_sw', 'gg_pwa_service_worker');

function gg_pwa_service_worker() {
  header('Content-Type: application/javascript');
  header('Service-Worker-Allowed: /');
  
  ?>
  const CACHE_NAME = 'grimegames-v1';
  
  self.addEventListener('install', (event) => {
    self.skipWaiting();
  });
  
  self.addEventListener('activate', (event) => {
    event.waitUntil(clients.claim());
  });
  
  self.addEventListener('fetch', (event) => {
    // Let the browser handle all requests normally
    return;
  });
  <?php
  
  exit;
}