<?php
/*
Plugin Name: GG Fix AVIF htaccess (one-shot)
Description: Writes correct AVIF rewrite rules to .htaccess. Deactivate and delete after use.
Version: 1.0
*/
defined('ABSPATH') || exit;

// Run immediately on activation
register_activation_hook(__FILE__, function() {
    $htaccess = ABSPATH . '.htaccess';
    if (!file_exists($htaccess) || !is_writable($htaccess)) return;
    
    $content = file_get_contents($htaccess);
    
    // Remove old broken rules
    $content = preg_replace('/# BEGIN GrimeGames AVIF.*?# END GrimeGames AVIF\s*/s', '', $content);
    
    // Add correct rules at the top
    $new_rules = '# BEGIN GrimeGames AVIF
<IfModule mod_rewrite.c>
    RewriteEngine On
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
    
    file_put_contents($htaccess, $new_rules . $content);
    update_option('gg_avif_htaccess_done', true);
});
