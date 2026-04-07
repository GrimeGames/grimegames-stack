<?php

/**
 * GrimeGames — AVIF Image Converter
 * ===================================
 * Converts all WordPress media images to AVIF format using PHP's
 * native GD library (no plugins, no paywalls, no external services).
 *
 * Features:
 * - Bulk converts existing images via background cron (50 per hour)
 * - Auto-converts new images on upload
 * - Serves AVIF to supported browsers, falls back to original
 * - Progress dashboard in WP Admin
 * - Safe: never deletes originals
 *
 * Paste into Code Snippets → Add New
 * Title: GrimeGames AVIF Converter
 */

defined('ABSPATH') || exit;

/* =========================================================
   SECTION 0 — CONSTANTS & CONFIG
   ========================================================= */
define('GG_AVIF_LOG_KEY',       'gg_avif_log');
define('GG_AVIF_PROGRESS_KEY',  'gg_avif_progress');
define('GG_AVIF_CRON_HOOK',     'gg_avif_bulk_cron');
define('GG_AVIF_BATCH_SIZE',    50);   // images per cron run
define('GG_AVIF_QUALITY',       72);   // AVIF quality 0-100 (72 = excellent quality/size balance)
define('GG_AVIF_SPEED',         6);    // AVIF encode speed 0-10 (6 = good balance)

/* =========================================================
   SECTION 1 — CRON SCHEDULE
   ========================================================= */
add_filter('cron_schedules', function($schedules) {
    $schedules['gg_avif_hourly'] = [
        'interval' => HOUR_IN_SECONDS,
        'display'  => 'Every Hour (GrimeGames AVIF Converter)',
    ];
    return $schedules;
});

add_action('init', function() {
    if (!wp_next_scheduled(GG_AVIF_CRON_HOOK)) {
        wp_schedule_event(time() + 60, 'gg_avif_hourly', GG_AVIF_CRON_HOOK);
        gg_avif_log('✅ AVIF bulk cron scheduled — first run in 60 seconds');
    }
});

/* =========================================================
   SECTION 2 — BULK CONVERSION CRON
   ========================================================= */
add_action(GG_AVIF_CRON_HOOK, 'gg_avif_bulk_convert');

function gg_avif_bulk_convert() {
    gg_avif_log('🔄 Bulk cron started');

    $converted = 0;
    $skipped   = 0;
    $failed    = 0;

    // Get a batch of image attachments that don't yet have AVIF versions
    $attachments = get_posts([
        'post_type'      => 'attachment',
        'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'posts_per_page' => GG_AVIF_BATCH_SIZE,
        'post_status'    => 'inherit',
        'meta_query'     => [
            [
                'key'     => '_gg_avif_converted',
                'compare' => 'NOT EXISTS',
            ],
        ],
        'fields' => 'ids',
    ]);

    if (empty($attachments)) {
        gg_avif_log('🎉 All images have been converted to AVIF — stopping cron');
        wp_clear_scheduled_hook(GG_AVIF_CRON_HOOK);
        update_option(GG_AVIF_PROGRESS_KEY, ['status' => 'complete', 'completed_at' => current_time('mysql')]);
        return;
    }

    foreach ($attachments as $attachment_id) {
        $result = gg_avif_convert_attachment($attachment_id);
        if ($result === true)       $converted++;
        elseif ($result === 'skip') $skipped++;
        else                        $failed++;
    }

    // Update progress
    $total_done  = gg_avif_count_converted();
    $total_all   = gg_avif_count_total();
    $pct         = $total_all > 0 ? round(($total_done / $total_all) * 100) : 0;

    update_option(GG_AVIF_PROGRESS_KEY, [
        'status'       => 'running',
        'total'        => $total_all,
        'converted'    => $total_done,
        'pct'          => $pct,
        'last_run'     => current_time('mysql'),
    ]);

    gg_avif_log("✅ Batch done — converted: {$converted}, skipped: {$skipped}, failed: {$failed} | Total progress: {$total_done}/{$total_all} ({$pct}%)");
}

/* =========================================================
   SECTION 3 — CONVERT SINGLE ATTACHMENT
   ========================================================= */
function gg_avif_convert_attachment($attachment_id) {
    $file = get_attached_file($attachment_id);

    if (!$file || !file_exists($file)) {
        update_post_meta($attachment_id, '_gg_avif_converted', 'skip_missing');
        return 'skip';
    }

    $mime = mime_content_type($file);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
        update_post_meta($attachment_id, '_gg_avif_converted', 'skip_unsupported');
        return 'skip';
    }

    // Convert the main file
    $result = gg_avif_convert_file($file, $mime);
    if (!$result) {
        update_post_meta($attachment_id, '_gg_avif_converted', 'failed');
        gg_avif_log("❌ Failed to convert attachment ID {$attachment_id}: {$file}");
        return false;
    }

    // Convert all registered thumbnail sizes too
    $metadata = wp_get_attachment_metadata($attachment_id);
    if (!empty($metadata['sizes'])) {
        $upload_dir = trailingslashit(dirname($file));
        foreach ($metadata['sizes'] as $size => $size_data) {
            $thumb_file = $upload_dir . $size_data['file'];
            if (file_exists($thumb_file)) {
                $thumb_mime = mime_content_type($thumb_file);
                gg_avif_convert_file($thumb_file, $thumb_mime);
            }
        }
    }

    // Mark as converted
    update_post_meta($attachment_id, '_gg_avif_converted', current_time('mysql'));
    return true;
}

/* =========================================================
   SECTION 4 — CONVERT A SINGLE FILE TO AVIF
   ========================================================= */
function gg_avif_convert_file($source_path, $mime_type) {
    $avif_path = preg_replace('/\.(jpe?g|png|gif|webp)$/i', '.avif', $source_path);

    // Skip if AVIF already exists and is newer than source
    if (file_exists($avif_path) && filemtime($avif_path) >= filemtime($source_path)) {
        return true;
    }

    // Load image into GD
    $image = null;
    switch ($mime_type) {
        case 'image/jpeg': $image = @imagecreatefromjpeg($source_path); break;
        case 'image/png':  $image = @imagecreatefrompng($source_path);  break;
        case 'image/gif':  $image = @imagecreatefromgif($source_path);  break;
        case 'image/webp': $image = @imagecreatefromwebp($source_path); break;
    }

    if (!$image) return false;

    // Preserve transparency for PNG/GIF
    if (in_array($mime_type, ['image/png', 'image/gif'])) {
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);
    }

    // Convert and save as AVIF
    $result = @imageavif($image, $avif_path, GG_AVIF_QUALITY, GG_AVIF_SPEED);
    imagedestroy($image);

    if (!$result) return false;

    // Log size saving
    $original_size = filesize($source_path);
    $avif_size     = filesize($avif_path);
    if ($original_size > 0) {
        $saving_pct = round((1 - $avif_size / $original_size) * 100);
        if ($saving_pct > 0) {
            gg_avif_log("🖼️ " . basename($source_path) . " → AVIF | " . gg_avif_format_bytes($original_size) . " → " . gg_avif_format_bytes($avif_size) . " ({$saving_pct}% smaller)");
        }
    }

    return true;
}

/* =========================================================
   SECTION 5 — AUTO-CONVERT NEW UPLOADS
   ========================================================= */
add_action('add_attachment', function($attachment_id) {
    if (!wp_attachment_is_image($attachment_id)) return;

    // Schedule conversion 30 seconds after upload
    // (gives WP time to generate all thumbnail sizes first)
    wp_schedule_single_event(time() + 30, 'gg_avif_single_convert', [$attachment_id]);
    gg_avif_log("📸 New upload queued for AVIF conversion (ID: {$attachment_id})");
});

add_action('gg_avif_single_convert', function($attachment_id) {
    $result = gg_avif_convert_attachment($attachment_id);
    if ($result === true) {
        gg_avif_log("✅ New upload converted to AVIF (ID: {$attachment_id})");
    }
});

/* =========================================================
   SECTION 6 — SERVE AVIF VIA .HTACCESS REWRITE
   Updates .htaccess to serve .avif when browser supports it
   and an .avif version exists
   ========================================================= */
add_action('init', function() {
    if (get_option('gg_avif_htaccess_done')) return;
    gg_avif_update_htaccess();
});

function gg_avif_update_htaccess() {
    $htaccess_file = ABSPATH . '.htaccess';
    if (!file_exists($htaccess_file) || !is_writable($htaccess_file)) {
        gg_avif_log('⚠️ .htaccess not writable — manual rewrite rules needed');
        return false;
    }

    $rules = '
# BEGIN GrimeGames AVIF
<IfModule mod_rewrite.c>
    RewriteEngine On
    # Serve AVIF if browser supports it and .avif file exists
    RewriteCond %{HTTP_ACCEPT} image/avif
    RewriteCond %{REQUEST_FILENAME} ^(.+)\.(jpe?g|png|gif|webp)$ [NC]
    RewriteCond %1.avif -f
    RewriteRule ^(.+)\.(jpe?g|png|gif|webp)$ $1.avif [T=image/avif,L]
</IfModule>
<IfModule mod_headers.c>
    <FilesMatch "\.(avif)$">
        Header set Cache-Control "max-age=31536000, public"
        Header set Vary "Accept"
    </FilesMatch>
</IfModule>
# END GrimeGames AVIF
';

    $current = file_get_contents($htaccess_file);

    // Don't add if already present
    if (strpos($current, '# BEGIN GrimeGames AVIF') !== false) {
        update_option('gg_avif_htaccess_done', true);
        return true;
    }

    // Insert after the first line (before WordPress rules)
    $new_content = $rules . "\n" . $current;
    $written = file_put_contents($htaccess_file, $new_content);

    if ($written) {
        update_option('gg_avif_htaccess_done', true);
        gg_avif_log('✅ .htaccess updated — AVIF rewrite rules added');
        return true;
    }

    gg_avif_log('❌ Failed to write .htaccess');
    return false;
}

/* =========================================================
   SECTION 7 — ADMIN DASHBOARD PAGE
   ========================================================= */
add_action('admin_menu', function() {
    add_submenu_page(
        'tools.php',
        'AVIF Converter',
        '🖼️ AVIF Converter',
        'manage_options',
        'gg-avif-converter',
        'gg_avif_admin_page'
    );
});

function gg_avif_admin_page() {
    // Handle manual actions
    if (isset($_POST['gg_avif_action']) && check_admin_referer('gg_avif_action')) {
        switch ($_POST['gg_avif_action']) {
            case 'run_now':
                gg_avif_bulk_convert();
                echo '<div class="notice notice-success"><p>✅ Batch conversion run complete.</p></div>';
                break;
            case 'reset':
                global $wpdb;
                $wpdb->delete($wpdb->postmeta, ['meta_key' => '_gg_avif_converted']);
                delete_option(GG_AVIF_PROGRESS_KEY);
                wp_clear_scheduled_hook(GG_AVIF_CRON_HOOK);
                wp_schedule_event(time() + 60, 'gg_avif_hourly', GG_AVIF_CRON_HOOK);
                echo '<div class="notice notice-success"><p>✅ Reset complete — conversion will restart.</p></div>';
                break;
            case 'update_htaccess':
                delete_option('gg_avif_htaccess_done');
                gg_avif_update_htaccess();
                echo '<div class="notice notice-success"><p>✅ .htaccess updated.</p></div>';
                break;
        }
    }

    $progress   = get_option(GG_AVIF_PROGRESS_KEY, []);
    $total      = gg_avif_count_total();
    $converted  = gg_avif_count_converted();
    $remaining  = $total - $converted;
    $pct        = $total > 0 ? round(($converted / $total) * 100) : 0;
    $next_run   = wp_next_scheduled(GG_AVIF_CRON_HOOK);
    $log        = gg_avif_get_log(20);
    $bar_colour = $pct >= 100 ? '#00c853' : '#A855F7';
    ?>
    <div style="max-width:900px;margin:20px auto;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">

        <!-- Header -->
        <div style="background:linear-gradient(135deg,#0d0d1a,#1a0d2e);border:1px solid rgba(123,104,238,.3);border-radius:14px;padding:28px 32px;margin-bottom:20px;">
            <div style="display:flex;align-items:center;gap:14px;margin-bottom:20px;">
                <span style="font-size:32px;">🖼️</span>
                <div>
                    <h1 style="margin:0;color:#A855F7;font-size:22px;font-weight:800;">GrimeGames AVIF Converter</h1>
                    <p style="margin:4px 0 0;color:rgba(255,255,255,.5);font-size:13px;">Converting your images to AVIF using PHP GD — no plugins, no paywalls</p>
                </div>
                <span style="margin-left:auto;background:<?php echo $pct >= 100 ? '#00c853' : 'rgba(123,104,238,.25)'; ?>;color:<?php echo $pct >= 100 ? '#000' : '#A855F7'; ?>;padding:6px 14px;border-radius:20px;font-size:12px;font-weight:700;">
                    <?php echo $pct >= 100 ? '✅ COMPLETE' : '⚙️ RUNNING'; ?>
                </span>
            </div>

            <!-- Progress Bar -->
            <div style="background:rgba(255,255,255,.06);border-radius:8px;height:12px;margin-bottom:16px;overflow:hidden;">
                <div style="background:linear-gradient(90deg,#7B68EE,#A855F7);width:<?php echo $pct; ?>%;height:100%;border-radius:8px;transition:width .5s;"></div>
            </div>

            <!-- Stats -->
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;">
                <?php
                $stats = [
                    ['📊', 'Total Images', number_format($total)],
                    ['✅', 'Converted',    number_format($converted)],
                    ['⏳', 'Remaining',    number_format($remaining)],
                    ['📈', 'Progress',     $pct . '%'],
                ];
                foreach ($stats as [$icon, $label, $value]): ?>
                <div style="background:rgba(255,255,255,.04);border:1px solid rgba(123,104,238,.15);border-radius:10px;padding:14px;text-align:center;">
                    <div style="font-size:20px;margin-bottom:4px;"><?php echo $icon; ?></div>
                    <div style="font-size:22px;font-weight:800;color:#fff;"><?php echo $value; ?></div>
                    <div style="font-size:11px;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.5px;"><?php echo $label; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Controls -->
        <div style="background:rgba(255,255,255,.03);border:1px solid rgba(123,104,238,.15);border-radius:14px;padding:20px 24px;margin-bottom:20px;">
            <h3 style="margin:0 0 14px;color:#fff;font-size:14px;text-transform:uppercase;letter-spacing:.5px;">Controls</h3>
            <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;">
                <?php wp_nonce_field('gg_avif_action'); ?>

                <button name="gg_avif_action" value="run_now" type="submit"
                    style="background:linear-gradient(135deg,#7B68EE,#A855F7);border:none;border-radius:8px;color:#fff;padding:10px 20px;font-weight:700;cursor:pointer;font-size:13px;">
                    ▶ Run Batch Now
                </button>

                <button name="gg_avif_action" value="update_htaccess" type="submit"
                    style="background:rgba(255,255,255,.06);border:1px solid rgba(123,104,238,.25);border-radius:8px;color:#fff;padding:10px 20px;font-weight:600;cursor:pointer;font-size:13px;">
                    🔧 Update .htaccess
                </button>

                <button name="gg_avif_action" value="reset" type="submit"
                    onclick="return confirm('Reset all conversion progress?')"
                    style="background:rgba(229,57,53,.08);border:1px solid rgba(229,57,53,.2);border-radius:8px;color:#e53935;padding:10px 20px;font-weight:600;cursor:pointer;font-size:13px;">
                    🔄 Reset & Restart
                </button>

                <div style="margin-left:auto;color:rgba(255,255,255,.4);font-size:12px;align-self:center;">
                    Next auto-run: <?php echo $next_run ? human_time_diff($next_run) . ' from now' : 'not scheduled'; ?>
                </div>
            </form>
        </div>

        <!-- Log -->
        <div style="background:#050508;border:1px solid rgba(123,104,238,.15);border-radius:14px;padding:20px 24px;">
            <h3 style="margin:0 0 14px;color:#fff;font-size:14px;text-transform:uppercase;letter-spacing:.5px;">Recent Activity</h3>
            <div style="font-family:monospace;font-size:12px;max-height:400px;overflow-y:auto;">
                <?php if (empty($log)): ?>
                    <p style="color:rgba(255,255,255,.3);">No activity yet — first run scheduled in ~60 seconds.</p>
                <?php else: ?>
                    <?php foreach (array_reverse($log) as $entry): ?>
                        <div style="padding:5px 0;border-bottom:1px solid rgba(255,255,255,.04);color:rgba(255,255,255,.7);">
                            <span style="color:rgba(255,255,255,.25);margin-right:10px;"><?php echo esc_html($entry['time']); ?></span>
                            <?php echo esc_html($entry['msg']); ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </div>
    <?php
}

/* =========================================================
   SECTION 8 — HELPER FUNCTIONS
   ========================================================= */
function gg_avif_count_total() {
    $q = new WP_Query([
        'post_type'      => 'attachment',
        'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'post_status'    => 'inherit',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ]);
    return $q->found_posts;
}

function gg_avif_count_converted() {
    global $wpdb;
    return (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
         WHERE meta_key = '_gg_avif_converted'
         AND meta_value NOT IN ('failed','skip_missing','skip_unsupported')"
    );
}

function gg_avif_format_bytes($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . 'MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1) . 'KB';
    return $bytes . 'B';
}

function gg_avif_log($message) {
    $log = get_option(GG_AVIF_LOG_KEY, []);
    $log[] = ['time' => current_time('H:i:s'), 'msg' => $message];
    // Keep last 100 entries
    if (count($log) > 100) $log = array_slice($log, -100);
    update_option(GG_AVIF_LOG_KEY, $log);
}

function gg_avif_get_log($limit = 20) {
    $log = get_option(GG_AVIF_LOG_KEY, []);
    return array_slice($log, -$limit);
}