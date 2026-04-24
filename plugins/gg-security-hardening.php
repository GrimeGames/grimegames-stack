<?php
/*
Plugin Name: GrimeGames Security Hardening
Description: Closes common information-disclosure vectors discovered in external audit — REST API user enumeration, ?author=N redirect, WP version disclosure, publicly-readable debug.log and readme.html. Writes .htaccess block rules and deletes existing debug.log on activation. Debug at ?gg_sec_debug=1
Author: GrimeGames
Version: 1.0
Changelog:
  1.0 — Initial release. Addresses findings from external black-box audit 2026-04-24:
        (a) /wp-json/wp/v2/users exposing user 'matt'
        (b) /?author=N leaking username via redirect
        (c) /readme.html disclosing WP version
        (d) /wp-content/debug.log publicly readable (42MB, leaked server path /home/dcbedead/)
*/

defined('ABSPATH') || exit;


/* =========================
   DEBUG HELPER
   Visible panel via ?gg_sec_debug=1 + error_log for all actions.
   ========================= */
function gg_sec_log($msg, $data = null) {
    $line = '[GG Security] ' . $msg;
    if ($data !== null) $line .= ' ' . (is_scalar($data) ? $data : wp_json_encode($data));
    error_log($line);
    if (isset($_GET['gg_sec_debug']) && current_user_can('manage_options')) {
        $GLOBALS['gg_sec_debug_buffer'][] = esc_html($line);
    }
}

add_action('wp_footer', function() {
    if (!isset($_GET['gg_sec_debug']) || !current_user_can('manage_options')) return;
    $buffer = $GLOBALS['gg_sec_debug_buffer'] ?? [];
    echo '<div style="position:fixed;bottom:0;left:0;right:0;background:#000;color:#0f0;font-family:monospace;font-size:11px;padding:10px;z-index:999999;max-height:40vh;overflow:auto;border-top:2px solid #0f0;">';
    echo '<strong>GG Security Debug Panel</strong><br>';
    if (empty($buffer)) {
        echo 'No events this request. Hardening is passive — most actions fire on activation.';
    } else {
        foreach ($buffer as $line) echo $line . '<br>';
    }
    echo '</div>';
}, 9999);


/* =========================
   1. BLOCK REST API USER ENUMERATION
   /wp-json/wp/v2/users currently returns: {id:2, name:'Matt Johnson', slug:'matt'}
   Removing unauthenticated access while preserving admin-side use.
   ========================= */
add_filter('rest_endpoints', function($endpoints) {
    if (is_user_logged_in() && current_user_can('list_users')) {
        return $endpoints; // preserve for admins
    }
    if (isset($endpoints['/wp/v2/users'])) {
        unset($endpoints['/wp/v2/users']);
        gg_sec_log('Removed REST endpoint /wp/v2/users for unauthenticated request');
    }
    if (isset($endpoints['/wp/v2/users/(?P<id>[\d]+)'])) {
        unset($endpoints['/wp/v2/users/(?P<id>[\d]+)']);
    }
    return $endpoints;
});


/* =========================
   2. BLOCK ?author=N ENUMERATION
   Redirects any ?author= request on the frontend to the homepage, preventing username leakage via canonical redirect to /author/{slug}/.
   ========================= */
add_action('template_redirect', function() {
    if (is_admin()) return;
    if (isset($_GET['author']) && $_GET['author'] !== '') {
        gg_sec_log('Blocked ?author=' . sanitize_text_field($_GET['author']) . ' enumeration attempt', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        wp_safe_redirect(home_url('/'), 301);
        exit;
    }
}, 1);


/* =========================
   3. REMOVE WP VERSION DISCLOSURE
   Strips generator meta tag and version from enqueued asset query strings.
   (readme.html/license.txt are blocked by .htaccess below.)
   ========================= */
remove_action('wp_head', 'wp_generator');
add_filter('the_generator', '__return_empty_string');

add_filter('style_loader_src', 'gg_sec_strip_version', 9999);
add_filter('script_loader_src', 'gg_sec_strip_version', 9999);
function gg_sec_strip_version($src) {
    if (strpos($src, 'ver=') !== false) {
        $src = remove_query_arg('ver', $src);
    }
    return $src;
}


/* =========================
   4. .HTACCESS HARDENING RULES
   Written on activation. Blocks: debug.log, readme.html, license.txt, wp-config samples, and hidden files at document root.
   Uses its own marker block — coexists with GG AVIF rules and WordPress core rules.
   ========================= */
register_activation_hook(__FILE__, 'gg_sec_activate');
function gg_sec_activate() {
    gg_sec_log('Activation hook running');

    // --- Write .htaccess rules ---
    $htaccess = ABSPATH . '.htaccess';
    if (!file_exists($htaccess)) {
        gg_sec_log('ERROR: .htaccess not found at ' . $htaccess);
    } elseif (!is_writable($htaccess)) {
        gg_sec_log('ERROR: .htaccess not writable');
    } else {
        $content = file_get_contents($htaccess);

        // Strip any prior version of our marker block
        $content = preg_replace('/# BEGIN GrimeGames Security.*?# END GrimeGames Security\s*/s', '', $content);

        $rules = <<<'HTACCESS'
# BEGIN GrimeGames Security
<FilesMatch "^(readme|license|wp-config-sample|changelog)\.(html|txt|md)$">
    Require all denied
</FilesMatch>
<FilesMatch "\.(log|bak|backup|old|save|swp|sql|zip|tar|tar\.gz)$">
    Require all denied
</FilesMatch>
<FilesMatch "^\.">
    Require all denied
</FilesMatch>
# Block direct access to debug.log anywhere under wp-content
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^wp-content/debug\.log$ - [F,L]
</IfModule>
# END GrimeGames Security


HTACCESS;

        $result = file_put_contents($htaccess, $rules . $content);
        if ($result === false) {
            gg_sec_log('ERROR: Failed to write .htaccess');
        } else {
            gg_sec_log('.htaccess hardening rules written', strlen($rules) . ' bytes');
        }
    }

    // --- Delete existing debug.log ---
    $debug_log = WP_CONTENT_DIR . '/debug.log';
    if (file_exists($debug_log)) {
        $size = filesize($debug_log);
        if (@unlink($debug_log)) {
            gg_sec_log('Deleted debug.log', $size . ' bytes reclaimed');
        } else {
            // Couldn't delete — truncate instead so it stops leaking history
            if (@file_put_contents($debug_log, '') !== false) {
                gg_sec_log('Could not delete debug.log, truncated instead', $size . ' bytes cleared');
            } else {
                gg_sec_log('ERROR: Could not delete or truncate debug.log');
            }
        }
    } else {
        gg_sec_log('debug.log not present — nothing to clean');
    }

    update_option('gg_sec_activated_at', time());
}


/* =========================
   5. ADMIN NOTICE — wp-config.php follow-up
   The plugin cannot modify wp-config.php (loaded before plugins). Nudges Matt to do the one manual step that completes the fix.
   ========================= */
add_action('admin_notices', function() {
    if (!current_user_can('manage_options')) return;
    if (get_option('gg_sec_wpconfig_dismissed')) return;
    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG === false) {
        update_option('gg_sec_wpconfig_dismissed', 1);
        return;
    }

    echo '<div class="notice notice-warning is-dismissible" style="border-left-color:#d63638;">';
    echo '<p><strong>GG Security Hardening:</strong> .htaccess rules are active, but to stop WordPress regenerating <code>wp-content/debug.log</code>, edit <code>wp-config.php</code> and change:</p>';
    echo '<pre style="background:#f0f0f1;padding:10px;">define(\'WP_DEBUG_LOG\', false);
define(\'WP_DEBUG_DISPLAY\', false);</pre>';
    echo '<p><a href="' . esc_url(add_query_arg('gg_sec_dismiss_wpconfig', 1)) . '">I\'ve done it — dismiss this notice</a></p>';
    echo '</div>';
});

add_action('admin_init', function() {
    if (isset($_GET['gg_sec_dismiss_wpconfig']) && current_user_can('manage_options')) {
        update_option('gg_sec_wpconfig_dismissed', 1);
        gg_sec_log('wp-config.php reminder dismissed by admin');
    }
});


/* =========================
   6. DEACTIVATION — clean up .htaccess rules only
   Leaves the dismissed-option so it doesn't re-nag on reactivation.
   ========================= */
register_deactivation_hook(__FILE__, function() {
    $htaccess = ABSPATH . '.htaccess';
    if (file_exists($htaccess) && is_writable($htaccess)) {
        $content = file_get_contents($htaccess);
        $new = preg_replace('/# BEGIN GrimeGames Security.*?# END GrimeGames Security\s*/s', '', $content);
        if ($new !== $content) {
            file_put_contents($htaccess, $new);
            gg_sec_log('Deactivation: removed .htaccess rules');
        }
    }
});
