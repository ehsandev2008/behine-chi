<?php
/*
Plugin Name: بهینه چی | افزونه حرفه‌ای بهینه‌سازی تصاویر وردپرس
Plugin URI: https://sir-developer.ir/
Description: افزونه حرفهای بهینهسازی تصاویر وردپرس برای کاهش حجم تصاویر، افزایش سرعت سایت و پشتیبانی از فرمتهای مدرن WebP و AVIF.
Version: 2.1.0
Requires at least: 5.5
Requires PHP: 7.4
Author: Ehsan.dev
Author URI: https://sir-developer.ir/
License: GPLv2 or later
Text Domain: behinechi-optimizer
Domain Path: /languages
*/

if (!defined('ABSPATH')) {
    exit;
}

// PHP 7.4 compatibility polyfills (str_contains/str_starts_with are PHP 8+).
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return '' === $needle || false !== strpos($haystack, $needle);
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return '' === $needle || 0 === strncmp($haystack, $needle, strlen($needle));
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        if ('' === $needle) {
            return true;
        }
        return substr($haystack, -strlen($needle)) === $needle;
    }
}

// Plugin Constants
define('WSO_VERSION', '2.1.0');
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
   NOTE: Upload optimization runs via wp_generate_attachment_metadata
   (see WSO\Admin\Media_Library::save_upload_optimization_meta) so the
   main file AND all thumbnails are processed exactly once. A previous
   wp_handle_upload filter caused double optimization and wrong
   before/after size calculation, so it was removed.
   ========================================================================== */

/**
 * Force WordPress Image Editor to output WebP or AVIF format when enabled.
 * Checks driver support first so servers without AVIF/WebP never break.
 */
function wso_filter_image_editor_output_format($formats) {
    if (!wso_enabled()) {
        return $formats;
    }
    $driver = null;
    if (class_exists('WSO\\Engine\\Optimizer')) {
        try {
            $driver = WSO\Engine\Optimizer::instance()->get_driver();
        } catch (Throwable $e) {
            $driver = null;
        }
    }
    $want_avif = (bool) wso_opt('wso_convert_avif', 0);
    $want_webp = (bool) wso_opt('wso_convert_webp', 1);
    if ($want_avif && (!$driver || !$driver->supports_avif())) {
        $want_avif = false;
    }
    if ($want_webp && (!$driver || !$driver->supports_webp())) {
        $want_webp = false;
    }
    if ($want_avif) {
        $formats['image/jpeg'] = 'image/avif';
        $formats['image/png']  = 'image/avif';
    } elseif ($want_webp) {
        $formats['image/jpeg'] = 'image/webp';
        $formats['image/png']  = 'image/webp';
    }
    return $formats;
}
add_filter('image_editor_output_format', 'wso_filter_image_editor_output_format');