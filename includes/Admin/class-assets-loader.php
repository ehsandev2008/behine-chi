<?php
/**
 * بارگذاری فایل‌های استایل و اسکریپت فارسی
 * Assets Loader for Admin CSS/JS (100% Persian UI)
 *
 * @package WSO\Admin
 */

namespace WSO\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class Assets_Loader {

    /**
     * Singleton instance.
     *
     * @var Assets_Loader|null
     */
    private static ?Assets_Loader $instance = null;

    /**
     * Returns the singleton instance.
     *
     * @return Assets_Loader
     */
    public static function instance(): Assets_Loader {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Enqueues scripts and styles conditionally.
     *
     * @param string $hook_suffix Current admin page hook.
     * @return void
     */
    public function enqueue_assets(string $hook_suffix): void {
        $is_wso_page = str_contains($hook_suffix, 'wso-settings');
        $is_media_page = in_array($hook_suffix, ['upload.php', 'post.php'], true);

        if (!$is_wso_page && !$is_media_page) {
            return;
        }

        // Styles
        wp_enqueue_style(
            'wso-admin-css',
            WSO_URL . 'assets/css/admin.css',
            [],
            WSO_VERSION
        );

        wp_enqueue_style(
            'wso-media-css',
            WSO_URL . 'assets/css/media.css',
            [],
            WSO_VERSION
        );

        // Scripts
        wp_enqueue_script(
            'wso-admin-js',
            WSO_URL . 'assets/js/admin.js',
            ['jquery'],
            WSO_VERSION,
            true
        );

        wp_enqueue_script(
            'wso-bulk-optimizer-js',
            WSO_URL . 'assets/js/bulk-optimizer.js',
            ['jquery', 'wso-admin-js'],
            WSO_VERSION,
            true
        );

        wp_enqueue_script(
            'wso-scanner-js',
            WSO_URL . 'assets/js/scanner.js',
            ['jquery', 'wso-admin-js'],
            WSO_VERSION,
            true
        );

        wp_enqueue_script(
            'wso-media-js',
            WSO_URL . 'assets/js/media.js',
            ['jquery', 'wso-admin-js'],
            WSO_VERSION,
            true
        );

        // Watermark uploader + Alt bulk: settings page only (needs wp.media).
        if ($is_wso_page) {
            wp_enqueue_media();
            wp_enqueue_script(
                'wso-watermark-alt-js',
                WSO_URL . 'assets/js/watermark-alt.js',
                ['jquery', 'wso-admin-js'],
                WSO_VERSION,
                true
            );
        }

        // Localize script data in 100% Persian
        $localized = [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wso_admin_nonce'),
            'i18n'     => [
                'saving'                 => 'در حال ذخیره...',
                'saved'                  => 'تنظیمات ذخیره شد!',
                'error'                  => 'خطایی رخ داد. لطفا دوباره تلاش کنید.',
                'confirm_reset'          => 'آیا اطمینان دارید که می‌خواهید تمامی تنظیمات افزونه به حالت پیش‌فرض بازگردند؟',
                'confirm_clear_logs'     => 'آیا از پاکسازی تمامی گزارش‌های عملیات اطمینان دارید؟',
                'confirm_restore'        => 'آیا از بازگردانی تصویر اصلی اولیه اطمینان دارید؟',
                'confirm_restore_all'    => 'آیا مطمئن هستید که می‌خواهید تمامی تصاویر سایت را به وضعیت اصلی اولیه قبل از بهینه‌سازی بازگردانی کنید؟',
                'optimizing'             => 'در حال بهینه‌سازی...',
                'restoring'              => 'در حال بازگردانی...',
                'restoring_all'          => 'در حال بازگردانی تمامی تصاویر...',
            ],
        ];

        wp_localize_script('wso-admin-js', 'wsoData', $localized);
    }
}
