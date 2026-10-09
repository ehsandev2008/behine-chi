<?php
/**
 * کنترل‌کننده درخواست‌های AJAX به زبان فارسی
 * Admin AJAX Controller Handler (100% Persian)
 *
 * @package WSO\Admin
 */

namespace WSO\Admin;

use WSO\Core\Settings;
use WSO\Tools\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class Ajax_Handler {

    /**
     * Singleton instance.
     *
     * @var Ajax_Handler|null
     */
    private static ?Ajax_Handler $instance = null;

    /**
     * Returns the singleton instance.
     *
     * @return Ajax_Handler
     */
    public static function instance(): Ajax_Handler {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {
        add_action('wp_ajax_wso_save_settings', [$this, 'handle_save_settings']);
        add_action('wp_ajax_wso_get_logs', [$this, 'handle_get_logs']);
        add_action('wp_ajax_wso_clear_logs', [$this, 'handle_clear_logs']);
        add_action('wp_ajax_wso_get_stats', [$this, 'handle_get_stats']);

        // Notifications routes
        add_action('wp_ajax_wso_get_notifications', [$this, 'handle_get_notifications']);
        add_action('wp_ajax_wso_mark_notification_read', [$this, 'handle_mark_notification_read']);
        add_action('wp_ajax_wso_mark_all_notifications_read', [$this, 'handle_mark_all_notifications_read']);
        add_action('wp_ajax_wso_clear_notifications', [$this, 'handle_clear_notifications']);

        // Health Check routes
        add_action('wp_ajax_wso_run_health_check', [$this, 'handle_run_health_check']);
        add_action('wp_ajax_wso_run_health_fix', [$this, 'handle_run_health_fix']);

        // Progress chart data
        add_action('wp_ajax_wso_get_chart_data', [$this, 'handle_get_chart_data']);
        add_action('wp_ajax_wso_get_engine_comparison', [$this, 'handle_get_engine_comparison']);

        // Unused files
        add_action('wp_ajax_wso_find_unused', [$this, 'handle_find_unused']);
        add_action('wp_ajax_wso_delete_unused', [$this, 'handle_delete_unused']);

        // PDF Report
        add_action('wp_ajax_wso_download_report', [$this, 'handle_download_report']);

        // Format conversion
        add_action('wp_ajax_wso_convert_format', [$this, 'handle_convert_format']);

        // Auto alerts
        add_action('wp_ajax_wso_run_auto_alert', [$this, 'handle_run_auto_alert']);
    }

    /**
     * Verifies security nonces and capability.
     *
     * @return void
     */
    private function verify_security(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'سطح دسترسی غیرمجاز است.'], 403);
        }
        check_ajax_referer('wso_admin_nonce', 'nonce');
    }

    /**
     * AJAX handler for saving settings asynchronously.
     *
     * @return void
     */
    public function handle_save_settings(): void {
        $this->verify_security();

        $form_data = $_POST['settings'] ?? [];
        if (is_string($form_data)) {
            parse_str($form_data, $form_data);
        }

        $settings_obj = Settings::instance();
        
        $checkbox_fields = [
            'wso_enable',
            'wso_delete_original',
            'wso_convert_webp',
            'wso_convert_avif',
            'wso_strip_exif',
            'wso_backup_originals',
            'wso_optimize_svg',
            'wso_auto_optimize',
            'wso_watermark_enabled',
            'wso_auto_alt_enabled',
            'wso_auto_alt_overwrite',
            'wso_preload_webp',
            'wso_smart_lazy_load',
            'wso_auto_scan',
            'wso_on_the_fly',
            'wso_auto_alert'
        ];

        foreach ($checkbox_fields as $field) {
            $val = isset($form_data[$field]) ? 1 : 0;
            $settings_obj->set($field, $val);
        }

        // Dark mode comes from hidden input (toggled by JS), not a checkbox.
        // Read its actual value so save persists the toggle state.
        if (isset($form_data['wso_dark_mode'])) {
            $settings_obj->set('wso_dark_mode', (int) $form_data['wso_dark_mode'] ? 1 : 0);
        }

        $number_fields = [
            'wso_quality',
            'wso_max_size',
            'wso_max_width',
            'wso_max_height',
            'wso_watermark_opacity',
            'wso_watermark_margin',
            'wso_watermark_image',
            'wso_webp_recompress_threshold',
            'wso_backup_alert_threshold'
        ];

        foreach ($number_fields as $field) {
            if (isset($form_data[$field])) {
                $val = (int) $form_data[$field];
                if ('wso_watermark_opacity' === $field) {
                    $val = max(1, min(100, $val));
                } elseif ('wso_watermark_margin' === $field) {
                    $val = max(0, min(200, $val));
                } elseif ('wso_webp_recompress_threshold' === $field) {
                    $val = max(0, min(50, $val));
                } elseif ('wso_backup_alert_threshold' === $field) {
                    $val = max(0, min(10000, $val));
                } elseif ('wso_watermark_image' === $field) {
                    $val = max(0, $val);
                    // Validate that the ID belongs to a real image attachment.
                    if ($val > 0) {
                        $mime = get_post_mime_type($val);
                        if (!is_string($mime) || !str_starts_with($mime, 'image/')) {
                            continue;
                        }
                    }
                }
                $settings_obj->set($field, $val);
            }
        }

        if (isset($form_data['wso_watermark_position'])) {
            $pos = sanitize_key($form_data['wso_watermark_position']);
            $allowed = [
                'top-left', 'top-center', 'top-right',
                'center-left', 'center', 'center-right',
                'bottom-left', 'bottom-center', 'bottom-right',
            ];
            if (in_array($pos, $allowed, true)) {
                $settings_obj->set('wso_watermark_position', $pos);
            }
        }

        $text_fields = [
            'wso_color_primary',
            'wso_color_primary_hover',
            'wso_color_secondary',
            'wso_color_secondary_text',
            'wso_color_success',
            'wso_color_warning',
            'wso_color_error',
            'wso_color_bg',
            'wso_color_card',
            'wso_color_bg_dark',
            'wso_color_card_dark',
            'wso_border_radius',
            'wso_shadow',
            'wso_font_size',
            'wso_spacing',
            'wso_admin_font',
            'wso_cloudinary_cloud',
            'wso_cloudinary_key',
            'wso_cloudinary_secret',
            'wso_slack_webhook',
            'wso_telegram_token',
            'wso_telegram_chat',
            'wso_api_key'
        ];

        foreach ($text_fields as $field) {
            if (isset($form_data[$field])) {
                $settings_obj->set($field, sanitize_text_field($form_data[$field]));
            }
        }

        // Add a notification about settings update
        \WSO\Tools\Notifications::instance()->add('success', 'تنظیمات افزونه با موفقیت به روز رسانی شد.');

        wp_send_json_success([
            'message'  => 'تنظیمات با موفقیت ذخیره گردید!',
            'settings' => $settings_obj->get_all(),
        ]);
    }

    /**
     * AJAX handler for retrieving logs table items.
     *
     * @return void
     */
    public function handle_get_logs(): void {
        $this->verify_security();

        $page   = max(1, (int) ($_POST['page'] ?? 1));
        $limit  = max(1, min(100, (int) ($_POST['limit'] ?? 20)));
        $status = sanitize_text_field($_POST['status'] ?? '');
        $search = sanitize_text_field($_POST['search'] ?? '');

        $offset = ($page - 1) * $limit;

        $logs  = Logger::get_logs($limit, $offset, $status, $search);
        $total = Logger::get_count($status, $search);

        wp_send_json_success([
            'logs'       => $logs,
            'total'      => $total,
            'page'       => $page,
            'total_pages'=> ceil($total / $limit),
        ]);
    }

    /**
     * AJAX handler for clearing all logs.
     *
     * @return void
     */
    public function handle_clear_logs(): void {
        $this->verify_security();
        Logger::clear();
        wp_send_json_success([
            'message' => 'تمامی گزارش‌های عملیات با موفقیت پاکسازی شدند.',
        ]);
    }

    /**
     * AJAX handler for retrieving live dashboard stats.
     *
     * @return void
     */
    public function handle_get_stats(): void {
        $this->verify_security();
        $stats = Dashboard_Widgets::get_stats();
        wp_send_json_success(['stats' => $stats]);
    }

    /**
     * AJAX handler for retrieving notifications.
     *
     * @return void
     */
    public function handle_get_notifications(): void {
        $this->verify_security();
        $limit  = max(1, min(100, (int) ($_POST['limit'] ?? 20)));
        $page   = max(1, (int) ($_POST['page'] ?? 1));
        $offset = ($page - 1) * $limit;
        $type   = sanitize_text_field($_POST['type'] ?? '');
        $search = sanitize_text_field($_POST['search'] ?? '');

        $notifications_mgr = \WSO\Tools\Notifications::instance();
        $list = $notifications_mgr->get_notifications($limit, $offset, $type, $search);
        $unread = $notifications_mgr->get_unread_count();

        wp_send_json_success([
            'notifications' => $list,
            'unread_count'  => $unread,
        ]);
    }

    /**
     * AJAX handler for marking single notification as read.
     *
     * @return void
     */
    public function handle_mark_notification_read(): void {
        $this->verify_security();
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            \WSO\Tools\Notifications::instance()->mark_as_read($id);
            wp_send_json_success(['message' => 'اعلان خوانده شد.']);
        }
        wp_send_json_error(['message' => 'شناسه اعلان نامعتبر است.']);
    }

    /**
     * AJAX handler for marking all notifications as read.
     *
     * @return void
     */
    public function handle_mark_all_notifications_read(): void {
        $this->verify_security();
        \WSO\Tools\Notifications::instance()->mark_all_read();
        wp_send_json_success(['message' => 'تمامی اعلان‌ها به عنوان خوانده شده علامت‌گذاری شدند.']);
    }

    /**
     * AJAX handler for clearing all notifications.
     *
     * @return void
     */
    public function handle_clear_notifications(): void {
        $this->verify_security();
        \WSO\Tools\Notifications::instance()->clear_all();
        wp_send_json_success(['message' => 'تمامی اعلان‌ها با موفقیت پاکسازی شدند.']);
    }

    /**
     * AJAX handler for running advanced health scan report.
     *
     * @return void
     */
    public function handle_run_health_check(): void {
        $this->verify_security();
        $report = \WSO\Tools\Health_Check::instance()->perform_scan();
        wp_send_json_success($report);
    }

    /**
     * AJAX handler for executing health check repairs.
     *
     * @return void
     */
    public function handle_run_health_fix(): void {
        $this->verify_security();
        $fix_action = sanitize_key($_POST['fix_action'] ?? '');
        if (empty($fix_action)) {
            wp_send_json_error(['message' => 'نوع تعمیر مشخص نشده است.']);
        }

        $success = \WSO\Tools\Health_Check::instance()->repair($fix_action);
        if ($success) {
            wp_send_json_success(['message' => 'عملیات تعمیر با موفقیت انجام شد.']);
        } else {
            wp_send_json_error(['message' => 'عملیات تعمیر با خطا مواجه شد.']);
        }
    }

    /**
     * AJAX handler for chart data.
     */
    public function handle_get_chart_data(): void {
        $this->verify_security();
        $period = sanitize_key($_POST['period'] ?? 'month');
        $chart = \WSO\Tools\Progress_Chart::instance();
        $data = $chart->get_chart_data($period);
        wp_send_json_success($data);
    }

    /**
     * AJAX handler for engine comparison.
     */
    public function handle_get_engine_comparison(): void {
        $this->verify_security();
        $chart = \WSO\Tools\Progress_Chart::instance();
        $data = $chart->get_engine_comparison();
        wp_send_json_success($data);
    }

    /**
     * AJAX handler for finding unused images.
     */
    public function handle_find_unused(): void {
        $this->verify_security();
        $limit = max(1, min(100, (int) ($_POST['limit'] ?? 50)));
        $cleaner = \WSO\Tools\Unused_Cleaner::instance();
        $unused = $cleaner->find_unused($limit);
        wp_send_json_success(['unused' => $unused, 'count' => count($unused)]);
    }

    /**
     * AJAX handler for deleting unused images.
     */
    public function handle_delete_unused(): void {
        $this->verify_security();
        $ids = array_map('intval', $_POST['ids'] ?? []);
        $cleaner = \WSO\Tools\Unused_Cleaner::instance();
        $result = $cleaner->delete_unused($ids);
        wp_send_json_success($result);
    }

    /**
     * AJAX handler for downloading PDF report.
     */
    public function handle_download_report(): void {
        $this->verify_security();
        \WSO\Tools\PDF_Report::instance()->generate();
    }

    /**
     * AJAX handler for format conversion.
     */
    public function handle_convert_format(): void {
        $this->verify_security();
        $attachment_id = (int) ($_POST['attachment_id'] ?? 0);
        $target_format = sanitize_key($_POST['target_format'] ?? 'webp');
        $quality = max(1, min(100, (int) ($_POST['quality'] ?? 85)));

        $converter = \WSO\Engine\Format_Converter::instance();
        $result = $converter->convert_all_thumbnails($attachment_id, $target_format, $quality);

        wp_send_json_success($result);
    }

    /**
     * AJAX handler for running auto alerts.
     */
    public function handle_run_auto_alert(): void {
        $this->verify_security();
        \WSO\Tools\Auto_Alert::instance()->check_and_notify();
        wp_send_json_success(['message' => 'بررسی هشدارها انجام شد.']);
    }
}
