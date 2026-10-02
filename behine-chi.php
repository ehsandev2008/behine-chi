<?php
/*
Plugin Name: بهینه چی | افزونه حرفه‌ای بهینه‌سازی تصاویر وردپرس
Plugin URI: http://sir-developer.ir/
Description: افزونه حرفهای بهینهسازی تصاویر وردپرس برای کاهش حجم تصاویر، افزایش سرعت سایت و پشتیبانی از فرمتهای مدرن WebP و AVIF.
Version: 1.0.0
Author: Ehsan.dev
Author URI: http://sir-developer.ir/
License: GPLv2 or later
Text Domain: behinechi-optimizer
Domain Path: /languages
*/

if (!defined('ABSPATH')) {
    exit;
}

// Plugin Constants
define('WSO_VERSION', '2.0.0');
define('WSO_FILE', __FILE__);
define('WSO_PATH', plugin_dir_path(__FILE__));
define('WSO_URL', plugin_dir_url(__FILE__));

// Require Autoloader
require_once WSO_PATH . 'includes/class-autoloader.php';

// Register Autoloader & Bootstrap Plugin Container
WSO\Autoloader::register();
WSO\Plugin::instance();

// Activation / Deactivation Hooks
register_activation_hook(__FILE__, ['WSO\\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['WSO\\Plugin', 'deactivate']);

/* ==========================================================================
   BACKWARD COMPATIBLE GLOBAL FUNCTIONS
   ========================================================================== */

if (!function_exists('wso_opt')) {
    /**
     * Helper to get plugin option.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function wso_opt($key, $default = null) {
        return WSO\Core\Settings::instance()->get($key, $default);
    }
}

if (!function_exists('wso_enabled')) {
    /**
     * Checks if optimization is enabled.
     *
     * @return bool
     */
    function wso_enabled() {
        return (bool) wso_opt('wso_enable', 1);
    }
}

if (!function_exists('wso_quality')) {
    /**
     * Gets WebP quality option.
     *
     * @return int
     */
    function wso_quality() {
        return (int) wso_opt('wso_quality', 75);
    }
}

if (!function_exists('wso_max_size')) {
    /**
     * Gets maximum conversion size in MB.
     *
     * @return int
     */
    function wso_max_size() {
        return (int) wso_opt('wso_max_size', 2);
    }
}

/* ==========================================================================
   WORDPRESS INTEGRATION HOOKS (PRESERVING BACKWARD COMPATIBILITY)
   ========================================================================== */

/**
 * Handle upload conversion before WordPress finishes processing.
 */
add_filter('wp_handle_upload', function ($upload) {
    if (!wso_enabled()) {
        return $upload;
    }

    $file = $upload['file'] ?? null;
    if (!$file || !file_exists($file)) {
        return $upload;
    }

    // Auto-optimize upload
    $result = WSO\Engine\Optimizer::instance()->optimize_file($file);

    if (!empty($result['formats']['avif']) && file_exists($result['formats']['avif'])) {
        $avif_file = $result['formats']['avif'];
        $upload['file'] = $avif_file;
        $upload['url']  = str_replace(basename($upload['url']), basename($avif_file), $upload['url']);
        $upload['type'] = 'image/avif';
    } elseif (!empty($result['formats']['webp']) && file_exists($result['formats']['webp'])) {
        $webp_file = $result['formats']['webp'];
        $upload['file'] = $webp_file;
        $upload['url']  = str_replace(basename($upload['url']), basename($webp_file), $upload['url']);
        $upload['type'] = 'image/webp';
    }

    return $upload;
}, 20);

/**
 * Force WordPress Image Editor to output WebP or AVIF format when enabled.
 */
add_filter('image_editor_output_format', function ($formats) {
    if (wso_enabled()) {
        if (wso_opt('wso_convert_avif', 0)) {
            $formats['image/jpeg'] = 'image/avif';
            $formats['image/png']  = 'image/avif';
        } elseif (wso_opt('wso_convert_webp', 1)) {
            $formats['image/jpeg'] = 'image/webp';
            $formats['image/png']  = 'image/webp';
        }
    }
    return $formats;
});