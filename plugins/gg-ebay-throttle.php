<?php
/*
Plugin Name: GG eBay Throttle (Trading API)
Description: Throttles outbound HTTP requests to api.ebay.com Trading endpoints to avoid 518/backoff loops. Injects ~3s delay between requests and caps each manual run to ~20 Trading requests.
Version: 1.0
Author: GrimeGames
*/

if (!defined('ABSPATH')) exit;

class GG_Ebay_Throttle {
    const HOST = 'api.ebay.com';
    const GAP_SECONDS = 3;         // delay between Trading API calls
    const MAX_PER_RUN = 20;        // soft cap per request run
    const RUN_TTL = 300;           // seconds to remember per-run counters
    const KEY_LAST = 'gg_ebay_throttle_last_ts';
    const KEY_RUN_COUNT = 'gg_ebay_throttle_run_count';

    public function __construct() {
        add_filter('http_request_args', [$this, 'throttle_requests'], 10, 2);
        add_action('admin_notices', [$this, 'admin_notice']);
    }

    protected function is_ebay_trading($url) {
        if (empty($url)) return false;
        $host = parse_url($url, PHP_URL_HOST);
        if (stripos($host, self::HOST) === false) return false;
        // Trading API endpoints often include /ws/api.dll
        return true;
    }

    protected function get_run_key() {
        // A naïve run key based on current user + minute bucket.
        $user = get_current_user_id();
        $bucket = floor(time() / 60); // changes every minute
        return self::KEY_RUN_COUNT . '_' . $user . '_' . $bucket;
    }

    public function throttle_requests($args, $url) {
        if (!$this->is_ebay_trading($url)) return $args;

        // 1) Per-run cap
        $run_key = $this->get_run_key();
        $count = (int) get_transient($run_key);
        if ($count >= self::MAX_PER_RUN) {
            // Soft stop: short-circuit with a WP_Error so importer halts gracefully.
            return new WP_Error(
                'gg_ebay_throttle_cap',
                sprintf('GG Throttle: per-run cap of %d reached; halting to avoid 518.', self::MAX_PER_RUN)
            );
        }
        set_transient($run_key, $count + 1, self::RUN_TTL);

        // 2) Spacing between calls (simple process-wide gap using transients)
        $last = (int) get_transient(self::KEY_LAST);
        $now = time();
        $wait = $last + self::GAP_SECONDS - $now;
        if ($wait > 0 && $wait < 10) {
            // Sleep to enforce gap (only affects the request thread)
            sleep($wait);
        }
        // Stamp new "last" time
        set_transient(self::KEY_LAST, time(), self::RUN_TTL);

        // Also increase timeout to be safe
        if (empty($args['timeout']) || $args['timeout'] < 30) {
            $args['timeout'] = 30;
        }

        return $args;
    }

    public function admin_notice() {
        if (!current_user_can('manage_options')) return;
        if (!isset($_GET['page']) || $_GET['page'] !== 'ebay-importer') return;

        echo '<div class="notice notice-info is-dismissible"><p><strong>GG eBay Throttle:</strong> Active. '
           . 'Spacing Trading API calls by ~3s and capping requests per run to 20 to prevent 518/backoff.</p></div>';
    }
}

new GG_Ebay_Throttle();
