<?php
/**
 * درون‌ریزی و برون‌بری تنظیمات به زبان فارسی
 * Exporter & Importer Tool Class (100% Persian)
 *
 * @package WSO\Tools
 */

namespace WSO\Tools;

use WSO\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

class Exporter_Importer {

    /**
     * Singleton instance.
     *
     * @var Exporter_Importer|null
     */
    private static ?Exporter_Importer $instance = null;

    /**
     * Returns the singleton instance.
     *
     * @return Exporter_Importer
     */
    public static function instance(): Exporter_Importer {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {
        add_action('wp_ajax_wso_export_settings', [$this, 'handle_export_settings']);
        add_action('wp_ajax_wso_import_settings', [$this, 'handle_import_settings']);
        add_action('wp_ajax_wso_reset_settings', [$this, 'handle_reset_settings']);
    }

    /**
     * AJAX handler for exporting settings as JSON.
     *
     * @return void
     */
    public function handle_export_settings(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'سطح دسترسی غیرمجاز است.'], 403);
        }
        check_ajax_referer('wso_admin_nonce', 'nonce');

        $settings = Settings::instance()->get_all();
        $export = [
            'plugin'     => 'بهینه چی',
            'version'    => WSO_VERSION,
            'exported_at'=> current_time('mysql'),
            'settings'   => $settings,
        ];

        wp_send_json_success([
            'filename' => 'wso-settings-' . date('Y-m-d') . '.json',
            'json'     => wp_json_encode($export, JSON_PRETTY_PRINT),
        ]);
    }

    /**
     * AJAX handler for importing settings from JSON string.
     *
     * @return void
     */
    public function handle_import_settings(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'سطح دسترسی غیرمجاز است.'], 403);
        }
        check_ajax_referer('wso_admin_nonce', 'nonce');

        $json_raw = wp_unslash($_POST['json_data'] ?? '');
        if (empty($json_raw)) {
            wp_send_json_error(['message' => 'هیچ داده تنظیماتی ارسال نشده است.']);
        }
        // Size cap: 200KB max to prevent DoS via huge payloads.
        if (strlen($json_raw) > 200 * 1024) {
            wp_send_json_error(['message' => 'حجم داده تنظیمات بیش از حد مجاز است.']);
        }

        $decoded = json_decode($json_raw, true);
        if (!$decoded || empty($decoded['settings']) || !is_array($decoded['settings'])) {
            wp_send_json_error(['message' => 'فرمت کد تنظیمات واردشده نامعتبر است.']);
        }

        $settings_obj = Settings::instance();
        $defaults = $settings_obj->get_defaults();
        foreach ($decoded['settings'] as $key => $val) {
            if (str_starts_with($key, Settings::PREFIX) && array_key_exists($key, $defaults)) {
                // set() applies per-key sanitization internally.
                $settings_obj->set($key, $val);
            }
        }

        wp_send_json_success([
            'message' => 'تنظیمات با موفقیت درون‌ریزی و اعمال شد!',
            'settings'=> $settings_obj->get_all(),
        ]);
    }

    /**
     * AJAX handler for resetting settings to defaults.
     *
     * @return void
     */
    public function handle_reset_settings(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'سطح دسترسی غیرمجاز است.'], 403);
        }
        check_ajax_referer('wso_admin_nonce', 'nonce');

        $settings_obj = Settings::instance();
        $defaults = $settings_obj->get_defaults();

        foreach ($defaults as $key => $val) {
            $settings_obj->set($key, $val);
        }

        wp_send_json_success([
            'message' => 'تمامی تنظیمات افزونه به حالت پیش‌فرض کارخانه بازنشانی شد.',
            'settings'=> $settings_obj->get_all(),
        ]);
    }
}
