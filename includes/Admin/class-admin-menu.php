<?php
/**
 * Admin Settings Menu & Panel Renderer
 *
 * @package WSO\Admin
 */

namespace WSO\Admin;

use WSO\Core\Settings;
use WSO\Engine\Optimizer;
use WSO\Tools\Font_Manager;
use WSO\Tools\Health_Check;
use WSO\Tools\Notifications;

if (!defined('ABSPATH')) {
    exit;
}

class Admin_Menu {

    /**
     * Singleton instance.
     *
     * @var Admin_Menu|null
     */
    private static ?Admin_Menu $instance = null;

    /**
     * Returns the singleton instance.
     *
     * @return Admin_Menu
     */
    public static function instance(): Admin_Menu {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    /**
     * Registers settings with WordPress Options API.
     *
     * @return void
     */
    public function register_settings(): void {
        Settings::instance()->register();
    }

    /**
     * Registers sub-menu page under Media (upload.php).
     *
     * @return void
     */
    public function register_menu(): void {
        add_submenu_page(
            'upload.php',
            __('بهینه چی | افزونه حرفه‌ای بهینه‌سازی تصاویر وردپرس', 'behinechi-optimizer'),
            __('بهینه چی', 'behinechi-optimizer'),
            'manage_options',
            'wso-settings',
            [$this, 'render_page']
        );
    }

    /**
     * Outputs custom CSS variables based on customizer choices.
     *
     * @return void
     */
    public function output_customizer_css(): void {
        $settings = Settings::instance();
        
        $primary    = $settings->get('wso_color_primary', '#3b82f6');
        $primary_h  = $settings->get('wso_color_primary_hover', '#2563eb');
        $secondary  = $settings->get('wso_color_secondary', '#f1f5f9');
        $sec_text   = $settings->get('wso_color_secondary_text', '#334155');
        $success    = $settings->get('wso_color_success', '#10b981');
        $warning    = $settings->get('wso_color_warning', '#f59e0b');
        $error      = $settings->get('wso_color_error', '#ef4444');
        
        $bg_light   = $settings->get('wso_color_bg', '#f8fafc');
        $card_light = $settings->get('wso_color_card', '#ffffff');
        
        $radius     = $settings->get('wso_border_radius', '8px');
        $shadow     = $settings->get('wso_shadow', '0 1px 3px rgba(0,0,0,0.1)');
        $font_size  = $settings->get('wso_font_size', '14px');
        $spacing    = $settings->get('wso_spacing', '20px');
        $active_font= $settings->get('wso_admin_font', 'Vazir');

        echo "<!-- WebP Smart Optimizer Customizer Styles -->\n<style type='text/css'>\n";
        echo "body .wso-wrap {\n";
        echo "  --wso-primary: " . esc_attr($primary) . ";\n";
        echo "  --wso-primary-hover: " . esc_attr($primary_h) . ";\n";
        echo "  --wso-secondary: " . esc_attr($secondary) . ";\n";
        echo "  --wso-secondary-text: " . esc_attr($sec_text) . ";\n";
        echo "  --wso-success: " . esc_attr($success) . ";\n";
        echo "  --wso-warning: " . esc_attr($warning) . ";\n";
        echo "  --wso-danger: " . esc_attr($error) . ";\n";
        echo "  --wso-bg-main: " . esc_attr($bg_light) . ";\n";
        echo "  --wso-bg-card: " . esc_attr($card_light) . ";\n";
        echo "  --wso-bg-header: " . esc_attr($card_light) . ";\n";
        echo "  --wso-radius: " . esc_attr($radius) . ";\n";
        echo "  --wso-card-shadow: " . esc_attr($shadow) . ";\n";
        echo "  --wso-font-size: " . esc_attr($font_size) . ";\n";
        echo "  --wso-spacing: " . esc_attr($spacing) . ";\n";
        echo "  --wso-font-family: '" . esc_attr($active_font) . "', -apple-system, BlinkMacSystemFont, sans-serif;\n";
        echo "}\n";
        echo "</style>\n";
    }

    /**
     * Renders the complete modern Settings Panel HTML.
     *
     * @return void
     */
    public function render_page(): void {
        $settings = Settings::instance();
        $stats    = Dashboard_Widgets::get_stats();
        $driver   = Optimizer::instance()->get_driver();
        $is_dark  = false;
        $enabled  = (bool) $settings->get('wso_enable', 1);

        $available_fonts = Font_Manager::instance()->get_available_fonts();
        ?>
        <div class="wso-wrap" id="wso-app" dir="rtl">
            
            <!-- STICKY TOP HEADER -->
            <header class="wso-header">
                <div class="wso-header-brand">
                    <div class="wso-logo-icon" style="padding: 0; background: transparent; overflow: hidden; display: flex; align-items: center; justify-content: center; border-radius: 8px;">
                        <img src="<?php echo esc_url(WSO_URL . 'assets/image/icon.webp'); ?>" alt="بهینه چی" style="width: 100%; height: 100%; object-fit: cover;" />
                    </div>
                    <div>
                        <h1 class="wso-title">بهینه چی <span class="wso-version-badge">نسخه <?php echo esc_html(WSO_VERSION); ?> Pro</span></h1>
                        <p class="wso-subtitle">افزونه حرفه‌ای بهینه‌سازی تصاویر وردپرس برای کاهش حجم تصاویر، افزایش سرعت سایت و پشتیبانی از فرمت‌های مدرن WebP و AVIF</p>
                    </div>
                </div>

                <div class="wso-header-actions">
                    <div class="wso-search-box">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                        <input type="text" id="wso-settings-search" placeholder="جستجوی تنظیمات..." />
                    </div>

                    <!-- Notification bell with badge -->
                    <button type="button" class="wso-btn-icon-toggle wso-nav-badge-trigger" id="wso-btn-open-notify" title="اعلان‌ها" onclick="jQuery('a[data-tab=tab-notifications]').click();">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                        <span class="wso-unread-badge" id="wso-badge-count" style="display:none;">0</span>
                    </button>

                    <button type="button" class="wso-btn wso-btn-primary" id="wso-save-btn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                        <span>ذخیره تغییرات</span>
                    </button>
                </div>
            </header>

            <!-- INACTIVE BANNER INDICATOR -->
            <?php if (!$enabled) : ?>
                <div class="wso-alert wso-alert-danger wso-inactive-banner" style="margin: 15px 24px 0 24px; padding: 12px 18px; border-radius: var(--wso-radius); display: flex; align-items: center; gap: 12px; background: rgba(239, 68, 68, 0.1); border: 1px solid var(--wso-danger); color: var(--wso-danger);">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                    <span><strong>افزونه در حال حاضر غیرفعال است!</strong> فرآیندهای بهینه‌سازی خودکار و بهینه‌سازی همگانی موقتاً متوقف شده‌اند. برای فعال‌سازی، سوئیچ اصلی را در تب تنظیمات فعال نمایید.</span>
                </div>
            <?php endif; ?>

            <form id="wso-settings-form" method="post">
                <input type="hidden" name="wso_dark_mode" id="wso_dark_mode_input" value="0" />

                <div class="wso-container">
                    
                    <!-- SIDEBAR NAVIGATION -->
                    <aside class="wso-sidebar">
                        <nav class="wso-nav">
                            <a href="#tab-dashboard" class="wso-nav-item active" data-tab="tab-dashboard">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                                <span>پیشخوان و آمار</span>
                            </a>
                            <a href="#tab-health" class="wso-nav-item" data-tab="tab-health">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"></path></svg>
                                <span>سلامت سیستم</span>
                            </a>
                            <a href="#tab-optimization" class="wso-nav-item" data-tab="tab-optimization">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>
                                <span>تنظیمات بهینه‌سازی</span>
                            </a>
                            <a href="#tab-compression" class="wso-nav-item" data-tab="tab-compression">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="8" y1="12" x2="16" y2="12"></line></svg>
                                <span>فشرده‌سازی و ابعاد</span>
                            </a>
                            <a href="#tab-conversion" class="wso-nav-item" data-tab="tab-conversion">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"></path></svg>
                                <span>تبدیل به WebP و AVIF</span>
                            </a>
                            <a href="#tab-watermark" class="wso-nav-item" data-tab="tab-watermark">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l2.4 7.2H22l-6.2 4.5 2.4 7.3L12 16.5 5.8 21l2.4-7.3L2 9.2h7.6z"></path></svg>
                                <span>واترمارک</span>
                            </a>
                            <a href="#tab-alt" class="wso-nav-item" data-tab="tab-alt">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7V4h16v3M9 20h6M12 4v16"></path></svg>
                                <span>متن جایگزین خودکار</span>
                            </a>
                            <a href="#tab-bulk" class="wso-nav-item" data-tab="tab-bulk">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                                <span>بهینه‌سازی همگانی</span>
                            </a>
                            <a href="#tab-scanner" class="wso-nav-item" data-tab="tab-scanner">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                                <span>اسکنر پوشه‌ها</span>
                            </a>
                            <a href="#tab-backup" class="wso-nav-item" data-tab="tab-backup">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2.5 2v6h6M21.5 22v-6h-6M22 11.5A10 10 0 0 0 3.2 7.2M2 12.5a10 10 0 0 0 18.8 4.3"></path></svg>
                                <span>پشتیبان‌گیری</span>
                            </a>
                            <a href="#tab-design" class="wso-nav-item" data-tab="tab-design">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2C6.47715 2 2 6.47715 2 12C2 17.5228 6.47715 22 12 22Z"></path><path d="M12 6V12L16 14"></path></svg>
                                <span>شخصی‌سازی ظاهر</span>
                            </a>
                            <a href="#tab-fonts" class="wso-nav-item" data-tab="tab-fonts">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 7 4 4 20 4 20 7"></polyline><line x1="9" y1="20" x2="15" y2="20"></line><line x1="12" y1="4" x2="12" y2="20"></line></svg>
                                <span>مدیریت فونت‌ها</span>
                            </a>
                            <a href="#tab-notifications" class="wso-nav-item" data-tab="tab-notifications">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 17H2v-2l2-2V9c0-3.1 2.03-5.78 5-6.52V2c0-.83.67-1.5 1.5-1.5s1.5.67 1.5 1.5v.48c2.97.74 5 3.42 5 6.52v4l2 2v2z"></path><path d="M12 23a2 2 0 0 1-2-2h4a2 2 0 0 1-2 2z"></path></svg>
                                <span>مرکز اعلان‌ها</span>
                            </a>
                            <a href="#tab-logs" class="wso-nav-item" data-tab="tab-logs">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                                <span>گزارشات عملیات</span>
                            </a>
                            <a href="#tab-tools" class="wso-nav-item" data-tab="tab-tools">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path></svg>
                                <span>ابزارها و کش</span>
                            </a>
                            <a href="#tab-import-export" class="wso-nav-item" data-tab="tab-import-export">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                                <span>انتقال تنظیمات</span>
                            </a>
                            <a href="#tab-docs" class="wso-nav-item" data-tab="tab-docs">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
                                <span>برندینگ و مستندات</span>
                            </a>
                        </nav>
                    </aside>

                    <!-- TAB CONTENT PANELS -->
                    <main class="wso-content">

                        <!-- TAB 1: DASHBOARD -->
                        <div id="tab-dashboard" class="wso-tab-pane active">
                            <h2 class="wso-pane-title">خلاصه وضعیت و آمار بهینه‌سازی</h2>
                            
                            <div class="wso-grid wso-grid-4">
                                <div class="wso-card wso-stat-card">
                                    <div class="wso-stat-icon wso-icon-blue">
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                                    </div>
                                    <div class="wso-stat-content">
                                        <span class="wso-stat-label">کل تصاویر رسانه</span>
                                        <h3 class="wso-stat-value" id="stat-total-images"><?php echo esc_html($stats['total_images']); ?></h3>
                                    </div>
                                </div>

                                <div class="wso-card wso-stat-card">
                                    <div class="wso-stat-icon wso-icon-green">
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                    </div>
                                    <div class="wso-stat-content">
                                        <span class="wso-stat-label">تصاویر موفق بهینه‌شده</span>
                                        <h3 class="wso-stat-value" id="stat-optimized-images"><?php echo esc_html($stats['optimized_images']); ?></h3>
                                    </div>
                                </div>

                                <div class="wso-card wso-stat-card">
                                    <div class="wso-stat-icon wso-icon-purple">
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path></svg>
                                    </div>
                                    <div class="wso-stat-content">
                                        <span class="wso-stat-label">فضای صرفه‌جویی‌شده</span>
                                        <h3 class="wso-stat-value" id="stat-saved-size"><?php echo esc_html($stats['saved_size']); ?> <small>(<?php echo esc_html($stats['space_saved_pct']); ?>٪)</small></h3>
                                    </div>
                                </div>

                                <div class="wso-card wso-stat-card">
                                    <div class="wso-stat-icon wso-icon-orange">
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                                    </div>
                                    <div class="wso-stat-content">
                                        <span class="wso-stat-label">فرمت‌های نسل جدید</span>
                                        <h3 class="wso-stat-value" id="stat-webp-count"><?php echo esc_html($stats['webp_count'] + $stats['avif_count']); ?> <small>(<?php echo esc_html($stats['webp_count']); ?> WebP / <?php echo esc_html($stats['avif_count']); ?> AVIF)</small></h3>
                                    </div>
                                </div>
                            </div>

                            <div class="wso-grid wso-grid-2 wso-mt-4">
                                <div class="wso-card">
                                    <h3 class="wso-card-title">اطلاعات فشرده‌سازی</h3>
                                    <p><strong>حجم اولیه کل تصاویر:</strong> <span id="stat-orig-size"><?php echo esc_html($stats['original_size']); ?></span></p>
                                    <p><strong>حجم کل پس از بهینه‌سازی:</strong> <span id="stat-opt-size"><?php echo esc_html($stats['optimized_size']); ?></span></p>
                                    <p><strong>حجم فایل‌های کش و فرمت‌های جدید:</strong> <span id="stat-cache-size"><?php echo esc_html($stats['cache_size']); ?></span></p>
                                    <p><strong>موتور فعال کتابخانه سرور:</strong> <span><?php echo str_contains(get_class($driver), 'Imagick') ? 'Imagick (پیشرفته)' : 'GD Library (استاندارد)'; ?></span></p>
                                </div>

                                <div class="wso-card" style="display: flex; flex-direction: column; justify-content: center; gap: 12px;">
                                    <h3 class="wso-card-title">اقدامات سریع پیشخوان</h3>
                                    <button type="button" class="wso-btn wso-btn-primary" onclick="jQuery('a[data-tab=tab-bulk]').click();">
                                        صفحه بهینه‌سازی همگانی رسانه‌ها
                                    </button>
                                    <button type="button" class="wso-btn wso-btn-secondary" onclick="jQuery('a[data-tab=tab-health]').click();">
                                        بررسی سلامت و تشخیص مشکلات سرور
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 2: HEALTH CHECK -->
                        <div id="tab-health" class="wso-tab-pane">
                            <div class="wso-health-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                                <h2 class="wso-pane-title" style="margin:0;">اسکن و سلامت سیستم</h2>
                                <button type="button" class="wso-btn wso-btn-primary" id="wso-btn-recheck-health">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"></path></svg>
                                    <span>بررسی مجدد</span>
                                </button>
                            </div>

                            <div class="wso-grid wso-grid-2">
                                <div class="wso-card" style="text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                                    <h3 class="wso-card-title">امتیاز سلامت سیستم</h3>
                                    <div class="wso-health-score-container" style="position: relative; width: 140px; height: 140px; margin: 15px 0;">
                                        <svg class="wso-health-svg" viewBox="0 0 36 36" style="width: 100%; height: 100%;">
                                            <path class="wso-circle-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="var(--wso-border-color)" stroke-width="3"></path>
                                            <path class="wso-circle" id="wso-health-circle" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="var(--wso-success)" stroke-width="3" stroke-dasharray="100, 100" style="transition: stroke-dasharray 0.5s ease-in-out;"></path>
                                        </svg>
                                        <div class="wso-health-score-text" id="wso-health-score-num" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-size: 28px; font-weight: 800;">100%</div>
                                    </div>
                                    <span class="wso-badge wso-badge-success" id="wso-health-score-label" style="font-size: 14px; padding: 4px 12px;">سالم و بدون خطا</span>
                                </div>

                                <div class="wso-card" style="display: flex; flex-direction: column; justify-content: center; gap: 10px;">
                                    <h3 class="wso-card-title">عیب‌یابی فعال سیستم</h3>
                                    <p class="wso-text-muted" style="font-size:13px; line-height:1.7;">افزونه بهینه چی به صورت خودکار و مستمر بستر هاست و سرور شما را بررسی می‌کند تا از پایداری بهینه‌سازی تصاویر اطمینان حاصل شود. در صورت بروز هرگونه هشدار یا خطا، موضوع را از طریق پشتیبانی هاست خود پیگیری فرمایید.</p>
                                    <p style="font-size:13px; margin:5px 0;"><strong>نوع درایور مورد استفاده:</strong> <span><?php echo str_contains(get_class($driver), 'Imagick') ? 'کتابخانه پیشرفته Imagick' : 'کتابخانه استاندارد GD'; ?></span></p>
                                </div>
                            </div>

                            <div class="wso-card wso-mt-4">
                                <h3 class="wso-card-title" style="border-bottom:1px solid var(--wso-border-color); padding-bottom:8px;">گزارش جامع وضعیت منابع</h3>
                                <div class="wso-health-report" id="wso-health-report-box">
                                    <div class="wso-loading-skeleton" style="height:150px; background:linear-gradient(90deg, var(--wso-secondary) 25%, var(--wso-border-color) 50%, var(--wso-secondary) 75%); background-size:200% 100%; animation:loading-skeleton 1.5s infinite;"></div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 3: OPTIMIZATION -->
                        <div id="tab-optimization" class="wso-tab-pane">
                            <h2 class="wso-pane-title">تنظیمات اصلی و عمومی بهینه‌سازی</h2>

                            <div class="wso-card">
                                <div class="wso-form-group">
                                    <label class="wso-toggle-label">
                                        <input type="checkbox" name="wso_enable" value="1" <?php checked(1, $settings->get('wso_enable', 1)); ?>>
                                        <span class="wso-toggle-slider"></span>
                                        <span class="wso-toggle-text">
                                            <strong>فعال‌سازی سراسری فرآیند بهینه‌سازی تصاویر (کلید اصلی)</strong>
                                            <small>با غیرفعال‌سازی این گزینه، تمامی اقدامات بهینه‌سازی خودکار، تبدیل فرمت و کارهای پس‌زمینه متوقف خواهد شد.</small>
                                        </span>
                                    </label>
                                </div>

                                <div class="wso-form-group wso-mt-4">
                                    <label class="wso-toggle-label">
                                        <input type="checkbox" name="wso_auto_optimize" value="1" <?php checked(1, $settings->get('wso_auto_optimize', 1)); ?>>
                                        <span class="wso-toggle-slider"></span>
                                        <span class="wso-toggle-text">
                                            <strong>بهینه‌سازی خودکار تصاویر به محض آپلود</strong>
                                            <small>هنگامی که تصویر جدیدی به کتابخانه رسانه وردپرس اضافه شود، بلافاصله فرآیند بهینه‌سازی شروع می‌شود.</small>
                                        </span>
                                    </label>
                                </div>

                                <div class="wso-form-group wso-mt-4">
                                    <label class="wso-toggle-label">
                                        <input type="checkbox" name="wso_backup_originals" value="1" <?php checked(1, $settings->get('wso_backup_originals', 1)); ?>>
                                        <span class="wso-toggle-slider"></span>
                                        <span class="wso-toggle-text">
                                            <strong>پشتیبان‌گیری خودکار از تصویر اصلی پیش از فشرده‌سازی</strong>
                                            <small>جهت پیشگیری از تخریب تصاویر، یک کپی از فایل اصلی در مسیر wso-backups ذخیره می‌گردد.</small>
                                        </span>
                                    </label>
                                </div>

                                <div class="wso-form-group wso-mt-4">
                                    <label class="wso-toggle-label">
                                        <input type="checkbox" name="wso_optimize_svg" value="1" <?php checked(1, $settings->get('wso_optimize_svg', 1)); ?>>
                                        <span class="wso-toggle-slider"></span>
                                        <span class="wso-toggle-text">
                                            <strong>بهینه‌سازی و فشرده‌سازی فایل‌های وکتور SVG</strong>
                                            <small>پاکسازی کدهای اضافی، متادیتاهای نرم‌افزارهای طراحی و مینیفای کردن مسیرهای برداری SVG به صورت بومی و بدون وابستگی به CDN.</small>
                                        </span>
                                    </label>
                                </div>

                                <div class="wso-field-row wso-mt-4">
                                    <label for="wso_max_size"><strong>حداکثر حجم مجاز تصویر برای پردازش (مگابایت)</strong></label>
                                    <input type="number" id="wso_max_size" name="wso_max_size" value="<?php echo esc_attr($settings->get('wso_max_size', 2)); ?>" min="1" max="50" class="wso-input-small" />
                                    <p class="wso-field-help">تصاویر سنگین‌تر از این مقدار جهت جلوگیری از مصرف زیاد منابع سرور نادیده گرفته خواهند شد.</p>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 4: COMPRESSION -->
                        <div id="tab-compression" class="wso-tab-pane">
                            <h2 class="wso-pane-title">کیفیت فشرده‌سازی و ابعاد تصویر</h2>

                            <div class="wso-card">
                                <div class="wso-field-row">
                                    <label for="wso_quality"><strong>کیفیت خروجی تصاویر (۱۰ تا ۱۰۰)</strong></label>
                                    <div class="wso-slider-wrapper">
                                        <input type="range" id="wso_quality" name="wso_quality" value="<?php echo esc_attr($settings->get('wso_quality', 82)); ?>" min="10" max="100" oninput="jQuery('#wso_quality_val').text(this.value);" />
                                        <span id="wso_quality_val" class="wso-badge wso-badge-primary"><?php echo esc_html($settings->get('wso_quality', 82)); ?></span>
                                    </div>
                                    <p class="wso-field-help">بهترین کیفیت فشرده‌سازی پیشنهادی بین ۸۰ تا ۸۵ است تا تعادل در حجم و کیفیت حفظ شود.</p>
                                </div>

                                <div class="wso-form-group wso-mt-4">
                                    <label class="wso-toggle-label">
                                        <input type="checkbox" name="wso_strip_exif" value="1" <?php checked(1, $settings->get('wso_strip_exif', 1)); ?>>
                                        <span class="wso-toggle-slider"></span>
                                        <span class="wso-toggle-text">
                                            <strong>حذف فراداده‌ها و اطلاعات EXIF تصاویر</strong>
                                            <small>پاکسازی کدهای اضافی دوربین، لوکیشن و تگ‌ها جهت سبک‌تر شدن حجم تصاویر.</small>
                                        </span>
                                    </label>
                                </div>

                                <hr class="wso-divider" />

                                <h3 class="wso-card-title">تغییر اندازه خودکار ابعاد بزرگ</h3>
                                <div class="wso-grid wso-grid-2">
                                    <div class="wso-field-row">
                                        <label for="wso_max_width"><strong>حداکثر عرض مجاز (پیکسل)</strong></label>
                                        <input type="number" id="wso_max_width" name="wso_max_width" value="<?php echo esc_attr($settings->get('wso_max_width', 2560)); ?>" class="wso-input-small" />
                                    </div>
                                    <div class="wso-field-row">
                                        <label for="wso_max_height"><strong>حداکثر ارتفاع مجاز (پیکسل)</strong></label>
                                        <input type="number" id="wso_max_height" name="wso_max_height" value="<?php echo esc_attr($settings->get('wso_max_height', 2560)); ?>" class="wso-input-small" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 5: WEBP & AVIF CONVERSION -->
                        <div id="tab-conversion" class="wso-tab-pane">
                            <h2 class="wso-pane-title">تبدیل به فرمت‌های نسل جدید WebP و AVIF</h2>

                            <div class="wso-card">
                                <div class="wso-form-group">
                                    <label class="wso-toggle-label">
                                        <input type="checkbox" name="wso_convert_webp" value="1" <?php checked(1, $settings->get('wso_convert_webp', 1)); ?>>
                                        <span class="wso-toggle-slider"></span>
                                        <span class="wso-toggle-text">
                                            <strong>تولید نسخه WebP تصاویر</strong>
                                            <small>ساخت خودکار فایل .webp در کنار فایل اصلی با حجم به مراتب کمتر.</small>
                                        </span>
                                    </label>
                                </div>

                                <div class="wso-form-group wso-mt-4">
                                    <label class="wso-toggle-label">
                                        <input type="checkbox" name="wso_convert_avif" value="1" <?php checked(1, $settings->get('wso_convert_avif', 0)); ?>>
                                        <span class="wso-toggle-slider"></span>
                                        <span class="wso-toggle-text">
                                            <strong>تولید نسخه AVIF تصاویر (بسیار پیشرفته)</strong>
                                            <small>فرمت پیشرو با ۵۰٪ حجم کمتر از JPEG. نیاز به نسخه جدید کتابخانه پردازش تصویر سرور دارد.</small>
                                        </span>
                                    </label>
                                </div>

                                <div class="wso-form-group wso-mt-4">
                                    <label class="wso-toggle-label">
                                        <input type="checkbox" name="wso_delete_original" value="1" <?php checked(1, $settings->get('wso_delete_original', 0)); ?>>
                                        <span class="wso-toggle-slider"></span>
                                        <span class="wso-toggle-text">
                                            <strong>حذف کامل فایل‌های اصلی پس از تبدیل موفق</strong>
                                            <small>در صورت فعال‌سازی، بعد از تولید فایل نسل جدید، اصل تصاویر JPG/PNG برای بهینه‌سازی فضا حذف خواهند شد.</small>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- TAB: WATERMARK -->
                        <div id="tab-watermark" class="wso-tab-pane">
                            <h2 class="wso-pane-title">واترمارک تصاویر</h2>

                            <div class="wso-card">
                                <div class="wso-form-group">
                                    <label class="wso-toggle-label">
                                        <input type="checkbox" name="wso_watermark_enabled" value="1" <?php checked(1, $settings->get('wso_watermark_enabled', 0)); ?>>
                                        <span class="wso-toggle-slider"></span>
                                        <span class="wso-toggle-text">
                                            <strong>فعال‌سازی واترمارک هنگام بهینه‌سازی</strong>
                                            <small>در صورت فعال بودن، تصویر واترمارک روی تصاویر JPG، JPEG، PNG و WebP (فایل اصلی، نه بندانگشتی‌ها) قرار می‌گیرد. فایل‌های SVG واترمارک نمی‌شوند.</small>
                                        </span>
                                    </label>
                                </div>

                                <div class="wso-field-row wso-mt-4">
                                    <label><strong>تصویر واترمارک</strong></label>
                                    <?php
                                    $wm_id  = (int) $settings->get('wso_watermark_image', 0);
                                    $wm_url = $wm_id > 0 ? wp_get_attachment_url($wm_id) : '';
                                    ?>
                                    <input type="hidden" id="wso_watermark_image" name="wso_watermark_image" value="<?php echo esc_attr($wm_id); ?>" />
                                    <div id="wso-wm-preview" style="margin:8px 0;">
                                        <?php if ($wm_url) : ?>
                                            <img src="<?php echo esc_url($wm_url); ?>" alt="پیش‌نمایش واترمارک" style="max-width:180px;max-height:100px;border:1px solid var(--wso-border-color,#e2e8f0);border-radius:6px;background:#fff;padding:4px;" />
                                        <?php else : ?>
                                            <span class="wso-text-muted" style="font-size:12px;">هنوز تصویری انتخاب نشده است.</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="display:flex;gap:8px;">
                                        <button type="button" class="wso-btn wso-btn-secondary" id="wso-wm-select">انتخاب تصویر واترمارک</button>
                                        <button type="button" class="wso-btn wso-btn-danger" id="wso-wm-remove">حذف واترمارک</button>
                                    </div>
                                    <p class="wso-field-help">بهترین نتیجه با فایل PNG شفاف به دست می‌آید. واترمارک به‌صورت خودکار متناسب با ابعاد تصویر کوچک می‌شود.</p>
                                </div>

                                <div class="wso-field-row wso-mt-4">
                                    <label for="wso_watermark_position"><strong>موقعیت واترمارک</strong></label>
                                    <?php $wm_pos = $settings->get('wso_watermark_position', 'bottom-right'); ?>
                                    <select id="wso_watermark_position" name="wso_watermark_position" class="wso-select">
                                        <option value="top-left" <?php selected('top-left', $wm_pos); ?>>بالا چپ</option>
                                        <option value="top-center" <?php selected('top-center', $wm_pos); ?>>بالا وسط</option>
                                        <option value="top-right" <?php selected('top-right', $wm_pos); ?>>بالا راست</option>
                                        <option value="center-left" <?php selected('center-left', $wm_pos); ?>>وسط چپ</option>
                                        <option value="center" <?php selected('center', $wm_pos); ?>>وسط</option>
                                        <option value="center-right" <?php selected('center-right', $wm_pos); ?>>وسط راست</option>
                                        <option value="bottom-left" <?php selected('bottom-left', $wm_pos); ?>>پایین چپ</option>
                                        <option value="bottom-center" <?php selected('bottom-center', $wm_pos); ?>>پایین وسط</option>
                                        <option value="bottom-right" <?php selected('bottom-right', $wm_pos); ?>>پایین راست</option>
                                    </select>
                                </div>

                                <div class="wso-grid wso-grid-2 wso-mt-4">
                                    <div class="wso-field-row">
                                        <label for="wso_watermark_opacity"><strong>شفافیت (۱ تا ۱۰۰)</strong></label>
                                        <div class="wso-slider-wrapper">
                                            <input type="range" id="wso_watermark_opacity" name="wso_watermark_opacity" value="<?php echo esc_attr($settings->get('wso_watermark_opacity', 70)); ?>" min="1" max="100" oninput="jQuery('#wso_watermark_opacity_val').text(this.value);" />
                                            <span id="wso_watermark_opacity_val" class="wso-badge wso-badge-primary"><?php echo esc_html($settings->get('wso_watermark_opacity', 70)); ?></span>
                                        </div>
                                    </div>
                                    <div class="wso-field-row">
                                        <label for="wso_watermark_margin"><strong>فاصله از لبه (پیکسل)</strong></label>
                                        <input type="number" id="wso_watermark_margin" name="wso_watermark_margin" value="<?php echo esc_attr($settings->get('wso_watermark_margin', 12)); ?>" min="0" max="200" class="wso-input-small" />
                                    </div>
                                </div>

                                <p class="wso-field-help wso-mt-4">نکته: واترمارک روی هر تصویر فقط یک‌بار اعمال می‌شود و در بهینه‌سازی مجدد تکرار نمی‌گردد. تصاویر کوچک‌تر از واترمارک، واترمارک دریافت نمی‌کنند.</p>
                            </div>
                        </div>

                        <!-- TAB: AUTO ALT TEXT -->
                        <div id="tab-alt" class="wso-tab-pane">
                            <h2 class="wso-pane-title">متن جایگزین (Alt) خودکار</h2>

                            <div class="wso-card">
                                <div class="wso-form-group">
                                    <label class="wso-toggle-label">
                                        <input type="checkbox" name="wso_auto_alt_enabled" value="1" <?php checked(1, $settings->get('wso_auto_alt_enabled', 0)); ?>>
                                        <span class="wso-toggle-slider"></span>
                                        <span class="wso-toggle-text">
                                            <strong>تولید خودکار Alt از نام فایل</strong>
                                            <small>مثال: my-new-product-image.jpg ← ‏«my new product image». خط تیره و زیرخط به فاصله تبدیل و پسوند حذف می‌شود.</small>
                                        </span>
                                    </label>
                                </div>

                                <div class="wso-form-group wso-mt-4">
                                    <label class="wso-toggle-label">
                                        <input type="checkbox" name="wso_auto_alt_overwrite" value="1" <?php checked(1, $settings->get('wso_auto_alt_overwrite', 0)); ?>>
                                        <span class="wso-toggle-slider"></span>
                                        <span class="wso-toggle-text">
                                            <strong>بازنویسی Altهای دستی موجود</strong>
                                            <small>به‌صورت پیش‌فرض خاموش است؛ یعنی Altهایی که خودتان وارد کرده‌اید حفظ می‌شوند و فقط تصاویر بدون Alt تکمیل می‌گردند.</small>
                                        </span>
                                    </label>
                                </div>

                                <hr class="wso-divider" />

                                <h3 class="wso-card-title">تکمیل Alt تصاویر موجود (اختیاری)</h3>
                                <p class="wso-text-muted" style="font-size:12px;">این عملیات فقط با کلیک شما و به‌صورت بسته‌های کوچک اجرا می‌شود؛ هیچ پردازش سنگین خودکاری روی هزاران تصویر انجام نمی‌گردد.</p>
                                <div class="wso-mt-2" style="display:flex;gap:10px;align-items:center;">
                                    <button type="button" class="wso-btn wso-btn-secondary" id="wso-fill-missing-alts">تکمیل Alt تصاویر بدون Alt (۲۰ تایی)</button>
                                    <span id="wso-alt-bulk-status" class="wso-text-muted" style="font-size:12px;"></span>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 6: BULK OPTIMIZATION -->
                        <div id="tab-bulk" class="wso-tab-pane">
                            <h2 class="wso-pane-title">صفحه مدیریت بهینه‌سازی همگانی تصاویر</h2>

                            <div class="wso-card">
                                <p>شما می‌توانید کل تصاویر آپلود شده در رسانه وردپرس را به صورت یکجا و در قالب بسته‌های کوچک بدون ایجاد فشار در منابع سرور پردازش کنید.</p>

                                <div class="wso-bulk-controls" style="display:flex; gap:10px; margin: 15px 0;">
                                    <button type="button" class="wso-btn wso-btn-primary" id="wso-start-bulk">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
                                        <span>شروع پردازش دسته جمعی</span>
                                    </button>
                                    <button type="button" class="wso-btn wso-btn-secondary" id="wso-pause-bulk" style="display:none;">
                                        توقف موقت
                                    </button>
                                    <button type="button" class="wso-btn wso-btn-danger" id="wso-reset-bulk">
                                        پاکسازی و ریست صف
                                    </button>
                                </div>

                                <div class="wso-progress-container wso-mt-4" style="display:none;" id="wso-progress-box">
                                    <div class="wso-progress-bar-bg">
                                        <div class="wso-progress-bar-fill" id="wso-progress-fill" style="width: 0%;"></div>
                                    </div>
                                    <div class="wso-progress-status">
                                        <span id="wso-progress-text">۰ / ۰ تصویر</span>
                                        <span id="wso-progress-percent">۰٪</span>
                                    </div>
                                </div>

                                <div class="wso-live-log-container wso-mt-4">
                                    <h4>لاگ و پیشرفت زنده صف</h4>
                                    <div class="wso-log-feed" id="wso-log-feed">
                                        <div class="wso-log-entry info">سیستم صف بهینه‌سازی همگانی آماده به کار است.</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 7: FOLDER SCANNER -->
                        <div id="tab-scanner" class="wso-tab-pane">
                            <h2 class="wso-pane-title">اسکنر پوشه‌های فرعی و پوسته</h2>

                            <div class="wso-card">
                                <p>بهینه‌سازی تصاویر خارج از رسانه نظیر گرافیک‌ها، بنرهای پوسته یا افزونه‌ها.</p>

                                <div class="wso-field-row">
                                    <label for="wso_scan_folder_type"><strong>انتخاب محل اسکن:</strong></label>
                                    <select id="wso_scan_folder_type" class="wso-select">
                                        <option value="uploads">پوشه آپلودها (uploads)</option>
                                        <option value="themes">پوشه پوسته‌ها (themes)</option>
                                        <option value="plugins">پوشه افزونه‌ها (plugins)</option>
                                        <option value="custom">مسیر مطلق سفارشی سرور</option>
                                    </select>
                                </div>

                                <div class="wso-field-row wso-mt-2" id="wso-custom-path-row" style="display:none;">
                                    <input type="text" id="wso_custom_scan_path" placeholder="مسیر مطلق سرور مانند: /var/www/html/wp-content/uploads/banners" class="wso-input-full" />
                                </div>

                                <div class="wso-mt-4">
                                    <button type="button" class="wso-btn wso-btn-primary" id="wso-run-scanner">
                                        شروع اسکن پوشه
                                    </button>
                                    <button type="button" class="wso-btn wso-btn-secondary" id="wso-queue-scanned" style="display:none;">
                                        افزودن موارد یافت شده به صف بهینه‌سازی
                                    </button>
                                </div>

                                <div class="wso-mt-4" id="wso-scanner-results" style="display:none;">
                                    <h4>نتایج اسکن فایل‌ها</h4>
                                    <div class="wso-scanner-file-list" id="wso-scanner-file-list"></div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 8: BACKUP & RESTORE -->
                        <div id="tab-backup" class="wso-tab-pane">
                            <h2 class="wso-pane-title">پشتیبان‌گیری و بازگردانی کلی تصاویر</h2>

                            <div class="wso-card">
                                <h3>بازگردانی کلیه تصاویر سایت به حالت اصلی</h3>
                                <p>با این کار، فایل‌های اصلی که قبل از عملیات فشرده‌سازی در پوشه wso-backups پشتیبان‌گیری شده بودند جایگزین خواهند شد و فایل‌های WebP/AVIF تولید شده حذف خواهند شد.</p>
                                
                                <div class="wso-mt-4">
                                    <button type="button" class="wso-btn wso-btn-danger" id="wso-btn-restore-all-backups">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2.5 2v6h6M21.5 22v-6h-6M22 11.5A10 10 0 0 0 3.2 7.2M2 12.5a10 10 0 0 0 18.8 4.3"></path></svg>
                                        <span>بازگردانی همگانی تصاویر به حالت اولیه</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 9: DESIGN CUSTOMIZER -->
                        <div id="tab-design" class="wso-tab-pane">
                            <h2 class="wso-pane-title">شخصی‌سازی رنگ‌ها و ظاهر افزونه (Theme Customizer)</h2>

                            <div class="wso-card">
                                <h3 class="wso-card-title">پالت رنگی اصلی</h3>
                                <div class="wso-grid wso-grid-2">
                                    <div class="wso-field-row">
                                        <label for="wso_color_primary">رنگ دکمه‌ها و المان‌های کلیدی (Primary):</label>
                                        <input type="color" id="wso_color_primary" name="wso_color_primary" value="<?php echo esc_attr($settings->get('wso_color_primary', '#3b82f6')); ?>" class="wso-color-picker" />
                                    </div>
                                    <div class="wso-field-row">
                                        <label for="wso_color_primary_hover">رنگ کلیدهای فعال هنگام هاور (Hover):</label>
                                        <input type="color" id="wso_color_primary_hover" name="wso_color_primary_hover" value="<?php echo esc_attr($settings->get('wso_color_primary_hover', '#2563eb')); ?>" class="wso-color-picker" />
                                    </div>
                                    <div class="wso-field-row">
                                        <label for="wso_color_secondary">رنگ پس‌زمینه جانبی (Secondary):</label>
                                        <input type="color" id="wso_color_secondary" name="wso_color_secondary" value="<?php echo esc_attr($settings->get('wso_color_secondary', '#f1f5f9')); ?>" class="wso-color-picker" />
                                    </div>
                                    <div class="wso-field-row">
                                        <label for="wso_color_secondary_text">رنگ متون دکمه‌های جانبی:</label>
                                        <input type="color" id="wso_color_secondary_text" name="wso_color_secondary_text" value="<?php echo esc_attr($settings->get('wso_color_secondary_text', '#334155')); ?>" class="wso-color-picker" />
                                    </div>
                                    <div class="wso-field-row">
                                        <label for="wso_color_success">رنگ موفقیت (Success):</label>
                                        <input type="color" id="wso_color_success" name="wso_color_success" value="<?php echo esc_attr($settings->get('wso_color_success', '#10b981')); ?>" class="wso-color-picker" />
                                    </div>
                                    <div class="wso-field-row">
                                        <label for="wso_color_warning">رنگ هشدار (Warning):</label>
                                        <input type="color" id="wso_color_warning" name="wso_color_warning" value="<?php echo esc_attr($settings->get('wso_color_warning', '#f59e0b')); ?>" class="wso-color-picker" />
                                    </div>
                                    <div class="wso-field-row">
                                        <label for="wso_color_error">رنگ خطا (Error):</label>
                                        <input type="color" id="wso_color_error" name="wso_color_error" value="<?php echo esc_attr($settings->get('wso_color_error', '#ef4444')); ?>" class="wso-color-picker" />
                                    </div>
                                </div>

                                <hr class="wso-divider" />

                                <h3 class="wso-card-title">رنگ‌های بدنه و کارت‌ها (حالت روشن)</h3>
                                <div class="wso-grid wso-grid-2">
                                    <div class="wso-field-row">
                                        <label for="wso_color_bg">رنگ پس‌زمینه بدنه (Body BG):</label>
                                        <input type="color" id="wso_color_bg" name="wso_color_bg" value="<?php echo esc_attr($settings->get('wso_color_bg', '#f8fafc')); ?>" class="wso-color-picker" />
                                    </div>
                                    <div class="wso-field-row">
                                        <label for="wso_color_card">رنگ پس‌زمینه کارت‌ها (Card BG):</label>
                                        <input type="color" id="wso_color_card" name="wso_color_card" value="<?php echo esc_attr($settings->get('wso_color_card', '#ffffff')); ?>" class="wso-color-picker" />
                                    </div>
                                </div>

                                <hr class="wso-divider" />

                                <h3 class="wso-card-title">ساختار، ابعاد و حواشی</h3>
                                <div class="wso-grid wso-grid-2">
                                    <div class="wso-field-row">
                                        <label for="wso_border_radius">شعاع حاشیه (Border Radius):</label>
                                        <input type="text" id="wso_border_radius" name="wso_border_radius" value="<?php echo esc_attr($settings->get('wso_border_radius', '8px')); ?>" class="wso-input-small" />
                                    </div>
                                    <div class="wso-field-row">
                                        <label for="wso_font_size">سایز متن پایه (Font Size):</label>
                                        <input type="text" id="wso_font_size" name="wso_font_size" value="<?php echo esc_attr($settings->get('wso_font_size', '14px')); ?>" class="wso-input-small" />
                                    </div>
                                    <div class="wso-field-row">
                                        <label for="wso_spacing">فاصله‌گذاری داخلی (Padding/Spacing):</label>
                                        <input type="text" id="wso_spacing" name="wso_spacing" value="<?php echo esc_attr($settings->get('wso_spacing', '20px')); ?>" class="wso-input-small" />
                                    </div>
                                    <div class="wso-field-row">
                                        <label for="wso_shadow">سایه باکس کارت‌ها (Box Shadow):</label>
                                        <input type="text" id="wso_shadow" name="wso_shadow" value="<?php echo esc_attr($settings->get('wso_shadow', '0 1px 3px rgba(0,0,0,0.1)')); ?>" class="wso-input-medium" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 10: FONT MANAGER -->
                        <div id="tab-fonts" class="wso-tab-pane">
                            <h2 class="wso-pane-title">مدیریت فونت‌های فارسی محلی (آفلاین)</h2>

                            <div class="wso-card">
                                <h3 class="wso-card-title">انتخاب فونت پیش‌فرض پنل افزونه</h3>
                                <p class="wso-text-muted" style="margin-bottom:15px; font-size:12px;">شما می‌توانید از فونت‌های فارسی موجود در پوشه assets/fonts یکی را برای اعمال در کل پنل افزونه انتخاب کنید.</p>

                                <div class="wso-field-row" style="display:flex; align-items:center; gap:20px;">
                                    <div>
                                        <label for="wso_admin_font" style="display:block; margin-bottom:5px;"><strong>انتخاب فونت فعال:</strong></label>
                                        <select id="wso_admin_font" name="wso_admin_font" class="wso-select" style="min-width: 200px;">
                                            <?php if (!empty($available_fonts)) : ?>
                                                <?php foreach (array_keys($available_fonts) as $family) : ?>
                                                    <option value="<?php echo esc_attr($family); ?>" <?php selected($family, $settings->get('wso_admin_font', 'Vazir')); ?>><?php echo esc_html($family); ?></option>
                                                <?php endforeach; ?>
                                            <?php else : ?>
                                                <option value="system">فونت‌های پیش‌فرض سیستم</option>
                                            <?php endif; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 11: NOTIFICATIONS CENTER -->
                        <div id="tab-notifications" class="wso-tab-pane">
                            <div class="wso-notifications-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                                <h2 class="wso-pane-title" style="margin:0;">مرکز اعلان‌های افزونه</h2>
                                <div style="display:flex; gap:10px;">
                                    <button type="button" class="wso-btn wso-btn-secondary" id="wso-btn-notify-mark-read">
                                        خواندن همه
                                    </button>
                                    <button type="button" class="wso-btn wso-btn-danger" id="wso-btn-notify-clear">
                                        پاکسازی همه اعلان‌ها
                                    </button>
                                </div>
                            </div>

                            <div class="wso-card">
                                <div class="wso-table-filters" style="display:flex; gap:10px; margin-bottom: 15px;">
                                    <select id="wso-notify-filter-type" class="wso-select">
                                        <option value="">همه اعلان‌ها</option>
                                        <option value="success">موفقیت‌آمیز</option>
                                        <option value="info">اطلاعات</option>
                                        <option value="warning">هشدار</option>
                                        <option value="error">خطا</option>
                                    </select>
                                    <input type="text" id="wso-notify-search" placeholder="جستجو در اعلان‌ها..." class="wso-input-medium" />
                                </div>

                                <div class="wso-notifications-list" id="wso-notifications-feed" style="display:flex; flex-direction:column; gap:10px;">
                                    <div class="wso-text-center py-4">در حال دریافت لیست اعلان‌ها...</div>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 12: LOGS -->
                        <div id="tab-logs" class="wso-tab-pane">
                            <h2 class="wso-pane-title">گزارش‌های عملیات بهینه‌سازی تصاویر</h2>

                            <div class="wso-card">
                                <div class="wso-table-filters">
                                    <select id="wso-log-filter-status" class="wso-select">
                                        <option value="">همه وضعیت‌ها</option>
                                        <option value="success">موفقیت‌آمیز</option>
                                        <option value="warning">هشدار</option>
                                        <option value="error">خطا</option>
                                        <option value="skipped">نادیده گرفته‌شده</option>
                                    </select>

                                    <input type="text" id="wso-log-search" placeholder="جستجو در گزارش‌ها..." class="wso-input-medium" />

                                    <button type="button" class="wso-btn wso-btn-danger" id="wso-clear-logs-btn">
                                        پاکسازی کل تاریخچه لاگ
                                    </button>
                                </div>

                                <div class="wso-table-wrapper wso-mt-4">
                                    <table class="wso-table">
                                        <thead>
                                            <tr>
                                                <th>شناسه</th>
                                                <th>نام فایل تصویر</th>
                                                <th>حجم اولیه</th>
                                                <th>حجم فشرده</th>
                                                <th>میزان فشرده‌سازی</th>
                                                <th>وضعیت</th>
                                                <th>توضیحات خطا / جزئیات</th>
                                                <th>تاریخ</th>
                                            </tr>
                                        </thead>
                                        <tbody id="wso-logs-table-body">
                                            <tr><td colspan="8" class="wso-text-center">در حال بارگذاری گزارشات...</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- TAB 13: TOOLS & CACHE -->
                        <div id="tab-tools" class="wso-tab-pane">
                            <h2 class="wso-pane-title">ابزارهای پاکسازی کش و نگهداری</h2>

                            <div class="wso-card">
                                <h3>پاکسازی کل کش فایل‌های WebP و AVIF</h3>
                                <p>تمامی نسخه‌های شبیه‌سازی و تولید شده با پسوند webp و avif از پوشه آپلودها حذف می‌شوند.</p>
                                <button type="button" class="wso-btn wso-btn-secondary" id="wso-btn-clear-cache">
                                    پاکسازی کامل کش فرمت‌های جدید
                                </button>

                                <hr class="wso-divider" />

                                <h3>حذف همیشگی تصاویر پشتیبان (wso-backups)</h3>
                                <p>نسخه‌های کپی شده اصلی تصاویر آپلود شده به صورت دائمی از هاست حذف می‌شوند (آزادکننده فضا).</p>
                                <button type="button" class="wso-btn wso-btn-danger" id="wso-btn-clear-backups">
                                    پاکسازی فایل‌های پشتیبان اصلی
                                </button>
                            </div>
                        </div>

                        <!-- TAB 14: IMPORT / EXPORT -->
                        <div id="tab-import-export" class="wso-tab-pane">
                            <h2 class="wso-pane-title">درون‌ریزی و برون‌بری پیکربندی افزونه</h2>

                            <div class="wso-card">
                                <h3>برون‌بری تنظیمات (دانلود JSON)</h3>
                                <p>پیکربندی و تمپلیت رنگ‌های شخصی‌سازی شده خود را دانلود و در سایت‌های دیگر بارگذاری کنید.</p>
                                <button type="button" class="wso-btn wso-btn-secondary" id="wso-btn-export-settings">
                                    دریافت فایل پشتیبان تنظیمات
                                </button>

                                <hr class="wso-divider" />

                                <h3>درون‌ریزی تنظیمات</h3>
                                <p>کد تنظیمات دانلود شده قبلی را در کادر زیر قرار داده و دکمه اعمال را بزنید.</p>
                                <textarea id="wso-import-json-text" class="wso-textarea" placeholder="{ ... }"></textarea>
                                <br />
                                <button type="button" class="wso-btn wso-btn-primary wso-mt-2" id="wso-btn-import-settings">
                                    درون‌ریزی و اعمال پیکربندی جدید
                                </button>

                                <hr class="wso-divider" />

                                <h3>بازنشانی تنظیمات اولیه کارخانه (Factory Reset)</h3>
                                <button type="button" class="wso-btn wso-btn-danger" id="wso-btn-reset-settings">
                                    بازنشانی کل تنظیمات افزونه
                                </button>
                            </div>
                        </div>

                        <!-- TAB 15: DOCS & BRANDING -->
                        <div id="tab-docs" class="wso-tab-pane">
                            <h2 class="wso-pane-title">برندینگ و مستندات افزونه بهینه چی</h2>

                            <div class="wso-grid wso-grid-2">
                                <div class="wso-card" style="text-align:center;">
                                    <div class="wso-brand-banner" style="margin-bottom:15px; overflow:hidden; border-radius:var(--wso-radius); height:120px;">
                                        <img src="<?php echo esc_url(WSO_URL . 'assets/image/banner.webp'); ?>" alt="بنر بهینه چی" style="width:100%; height:100%; object-fit:cover; display:block;" />
                                    </div>
                                    <div style="display:flex; justify-content:center; gap:20px; align-items:center;">
                                        <div class="wso-logo-box" style="width:70px; height:70px; border: 1px solid var(--wso-border-color); border-radius: 12px; overflow:hidden; display:flex; align-items:center; justify-content:center;">
                                            <img src="<?php echo esc_url(WSO_URL . 'assets/image/icon.webp'); ?>" alt="آیکون بهینه چی" style="width:100%; height:100%; object-fit:cover; display:block;" />
                                        </div>
                                        <div style="text-align:right;">
                                            <h4 style="margin:0; font-size:16px;">برندینگ نسخه تجاری (Commercial Brand)</h4>
                                            <p style="margin:5px 0 0 0; font-size:12px; color:var(--wso-text-muted);">پشتیبانی از قالب‌های RTL و سیستم‌های آفلاین</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="wso-card">
                                    <h3 class="wso-card-title">اطلاعات نگارش</h3>
                                    <p><strong>نام افزونه:</strong> بهینه چی</p>
                                    <p><strong>نسخه تجاری:</strong> <?php echo esc_html(WSO_VERSION); ?> Pro</p>
                                    <p><strong>سازنده و توسعه دهنده:</strong> <a href="http://sir-developer.ir/" target="_blank" style="color:var(--wso-primary); text-decoration:none; font-weight:bold;">Ehsan.dev</a></p>
                                    <p><strong>نوع لایسنس:</strong> GPLv2 تجاری آفلاین</p>
                                </div>
                            </div>

                            <div class="wso-card wso-mt-4">
                                <h3 class="wso-card-title">مستندات و راهنمای راه‌اندازی</h3>
                                <p>این افزونه با بازتعریف کدهای پردازش تصویر در وردپرس به صورت ۱۰۰٪ آفلاین و بدون نیاز به سرورهای خارجی (سرویس‌های ابری تبدیل فرمت) تصاویر شما را فشرده می‌کند.</p>
                                <p><strong>نکات کلیدی:</strong></p>
                                <ul>
                                    <li>اگر سرور شما از فرمت AVIF پشتیبانی نمی‌کند، لطفاً تیک آن را بردارید تا با هشدارهای کتابخانه مواجه نشوید.</li>
                                    <li>بکاپ‌گیری از تصاویر، حجم هاست شما را افزایش می‌دهد. در صورت تمایل می‌توانید در سرورهای با دیسک محدود آن را غیرفعال کرده یا به صورت دوره‌ای کش‌ها را در بخش ابزارها تخلیه نمایید.</li>
                                    <li>فونت‌های استفاده شده در این افزونه به صورت محلی در مسیر <code>assets/fonts</code> قرار دارند. شما می‌توانید با کپی کردن فونت جدید به این مسیر، آن را به لیست فونت‌ساز افزونه بیفزایید.</li>
                                </ul>
                            </div>
                        </div>

                    </main>
                </div>
            </form>

            <!-- CUSTOM CONFIRM OVERLAY MODAL -->
            <div id="wso-confirm-modal" class="wso-confirm-overlay" style="display:none;">
                <div class="wso-confirm-box">
                    <h3 id="wso-confirm-title">تایید عملیات</h3>
                    <p id="wso-confirm-message">آیا از انجام این عملیات اطمینان دارید؟</p>
                    <div class="wso-confirm-buttons">
                        <button type="button" class="wso-btn wso-btn-danger" id="wso-confirm-yes">تایید</button>
                        <button type="button" class="wso-btn wso-btn-secondary" id="wso-confirm-no">انصراف</button>
                    </div>
                </div>
            </div>

            <!-- TOAST CONTAINER -->
            <div class="wso-toast-container" id="wso-toast-container"></div>
        </div>
        <?php
    }
}
