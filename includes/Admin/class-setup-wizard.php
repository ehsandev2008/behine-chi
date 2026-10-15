<?php
/**
 * Setup wizard notice + WP dashboard widget.
 *
 * @package WSO\Admin
 */

namespace WSO\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class Setup_Wizard {

    private static ?Setup_Wizard $instance = null;

    public static function instance(): Setup_Wizard {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_notices', [$this, 'maybe_notice']);
        add_action('wp_dashboard_setup', [$this, 'register_widget']);
        add_action('wp_ajax_wso_setup_quick', [$this, 'handle_quick']);
    }

    public function maybe_notice(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && false !== strpos((string) ($screen->id ?? ''), 'wso-settings')) {
            return;
        }
        try {
            $done = (int) \WSO\Core\Settings::instance()->get('wso_setup_done', 0);
        } catch (\Throwable $e) {
            return;
        }
        if ($done) {
            return;
        }
        echo '<div class="notice notice-info is-dismissible"><p><strong>بهینه‌چی:</strong> راه‌اندازی سریع (WebP، پشتیبان، بهینه‌سازی خودکار) را با یک کلیک فعال کنید. '
            . '<a href="' . esc_url(admin_url('upload.php?page=wso-settings')) . '">رفتن به تنظیمات</a> '
            . '<button type="button" class="button button-primary" id="wso-quick-setup" style="margin-right:8px;">راه‌اندازی سریع</button></p></div>';
        echo '<script>jQuery(document).on("click","#wso-quick-setup",function(e){e.preventDefault();var b=jQuery(this);b.prop("disabled",true);jQuery.post(ajaxurl,{action:"wso_setup_quick",nonce:"' . esc_js(wp_create_nonce('wso_admin_nonce')) . '"}).done(function(r){location.href="' . esc_js(admin_url('upload.php?page=wso-settings#tab-bulk')) . '";}).fail(function(){b.prop("disabled",false);});});</script>';
    }

    public function handle_quick(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'غیرمجاز'], 403);
        }
        check_ajax_referer('wso_admin_nonce', 'nonce');
        $s = \WSO\Core\Settings::instance();
        $s->set('wso_enable', 1);
        $s->set('wso_auto_optimize', 1);
        $s->set('wso_convert_webp', 1);
        $s->set('wso_backup_originals', 1);
        $s->set('wso_setup_done', 1);
        \WSO\Tools\Notifications::instance()->add('success', 'راه‌اندازی سریع بهینه‌چی انجام شد.');
        wp_send_json_success(['message' => 'انجام شد.']);
    }

    public function register_widget(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        wp_add_dashboard_widget('wso_dashboard_widget', 'بهینه‌چی | وضعیت تصاویر', [$this, 'render_widget']);
    }

    public function render_widget(): void {
        try {
            $stats = Dashboard_Widgets::get_stats();
        } catch (\Throwable $e) {
            echo '<p>خطا در دریافت آمار.</p>';
            return;
        }
        echo '<p>کل تصاویر: <strong>' . esc_html((string) ($stats['total_images'] ?? 0)) . '</strong></p>';
        echo '<p>بهینه‌شده: <strong>' . esc_html((string) ($stats['optimized_images'] ?? 0)) . '</strong></p>';
        echo '<p>صرفه‌جویی: <strong>' . esc_html((string) ($stats['saved_size'] ?? '—')) . '</strong></p>';
        echo '<p><a class="button button-primary" href="' . esc_url(admin_url('upload.php?page=wso-settings#tab-bulk')) . '">بهینه‌سازی همگانی</a> '
            . '<a class="button" href="' . esc_url(admin_url('upload.php?page=wso-settings#tab-health')) . '">سلامت سیستم</a></p>';
    }
}
