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

        // Clear WordPress object cache
        wp_cache_flush();
    }

    /**
     * Clear cache for a specific attachment.
     */
    public function clear_attachment_cache(int $attachment_id): void {
        $url = wp_get_attachment_url($attachment_id);
        if (!$url) {
            return;
        }

        // WP Rocket - clear specific URL
        if (function_exists('rocket_clean_post')) {
            rocket_clean_post($attachment_id);
        }

        // LiteSpeed - purge specific URL
        if (class_exists('LiteSpeed_Cache_API')) {
            \LiteSpeed_Cache_API::purge($url);
        }
    }
}
