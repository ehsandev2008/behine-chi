<?php
/**
 * Cache Plugin Compatibility
 * Integration with WP Rocket, W3 Total Cache, LiteSpeed Cache.
 *
 * @package WSO\Tools
 */

namespace WSO\Tools;

if (!defined('ABSPATH')) {
    exit;
}

class Cache_Compat {

    private static ?Cache_Compat $instance = null;

    public static function instance(): Cache_Compat {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Clear all known cache plugins.
     */
    public function clear_all_caches(): void {
        // WP Rocket
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        // W3 Total Cache
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }

        // LiteSpeed Cache
        if (class_exists('LiteSpeed_Cache_API')) {
            \LiteSpeed_Cache_API::purge_all();
        }

        // WP Super Cache
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }

        // WP Fastest Cache
        if (function_exists('wpfc_clear_all_cache')) {
            wpfc_clear_all_cache(true);
        }

        // Autoptimize
        if (class_exists('autoptimizeCache') && method_exists('autoptimizeCache', 'clearall')) {
            \autoptimizeCache::clearall();
        }

        // WP-Optimize / Cache Enabler (best-effort, no fatal when absent).
        if (function_exists('wpo_cache_flush')) {
            wpo_cache_flush();
        }
        if (class_exists('Cache_Enabler') && method_exists('Cache_Enabler', 'clear_total_cache')) {
            \Cache_Enabler::clear_total_cache();
        }

        // Clear WordPress object cache last (expensive; only in full purge).
        wp_cache_flush();
    }

    /**
     * Clear cache for a specific attachment.
     */
    public function clear_attachment_cache(int $attachment_id): void {
        $post = get_post($attachment_id);
        if (!$post) {
            return;
        }
        $url = wp_get_attachment_url($attachment_id);
        if (!$url) {
            return;
        }

        // WP Rocket - clear parent posts using this attachment (attachment ID itself is not a post cache key).
        if (function_exists('rocket_clean_post')) {
            $parents = get_posts([
                'post_type'      => 'any',
                'post_status'    => 'publish',
                'posts_per_page' => 5,
                'fields'         => 'ids',
                'meta_query'     => [
                    [
                        'key'     => '_thumbnail_id',
                        'value'   => $attachment_id,
                        'compare' => '=',
                    ],
                ],
            ]);
            foreach ($parents as $pid) {
                rocket_clean_post((int) $pid);
            }
        }

        // LiteSpeed - purge specific URL
        if (class_exists('LiteSpeed_Cache_API')) {
            \LiteSpeed_Cache_API::purge($url);
        }
    }
}
