<?php
/**
 * Admin Toolbar Shortcut
 * Quick access to settings from WordPress toolbar.
 *
 * @package WSO\Tools
 */

namespace WSO\Tools;

if (!defined('ABSPATH')) {
    exit;
}

class Toolbar {

    private static ?Toolbar $instance = null;

    public static function instance(): Toolbar {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_bar_menu', [$this, 'add_toolbar_items'], 99);
    }

    /**
     * Add toolbar items (single owner of the top-level "بهینه چی" node).
     */
    public function add_toolbar_items(\WP_Admin_Bar $wp_admin_bar): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Remove legacy duplicate top-level node if some other hook added it.
        if ($wp_admin_bar->get_node('wso-notifications-bar')) {
            $wp_admin_bar->remove_node('wso-notifications-bar');
        }

        // Idempotency: some setups fire admin_bar_menu twice.
        if ($wp_admin_bar->get_node('wso-toolbar')) {
            return;
        }

        try {
            $stats = \WSO\Admin\Dashboard_Widgets::get_stats();
        } catch (\Throwable $e) {
            $stats = ['saved_size' => '—'];
        }

        $unread = 0;
        try {
            if (class_exists('WSO\\Tools\\Notifications')) {
                $unread = (int) \WSO\Tools\Notifications::instance()->get_unread_count();
            }
        } catch (\Throwable $e) {
            $unread = 0;
        }
        $badge = $unread > 0 ? ' <span class="ab-item-notification-badge">' . esc_html(number_format_i18n($unread)) . '</span>' : '';

        // Main menu item (the ONLY top-level "بهینه چی" node).
        $wp_admin_bar->add_node([
            'id'    => 'wso-toolbar',
            'title' => esc_html__('بهینه چی', 'behinechi-optimizer') . $badge,
            'href'  => admin_url('upload.php?page=wso-settings'),
            'meta'  => [
                'class' => 'wso-toolbar-menu',
                'title' => esc_attr__('وضعیت و اعلان‌های افزونه بهینه چی', 'behinechi-optimizer'),
            ],
        ]);

        // Dashboard
        $wp_admin_bar->add_node([
            'id'     => 'wso-toolbar-dashboard',
            'parent' => 'wso-toolbar',
            'title'  => 'پیشخوان',
            'href'   => admin_url('upload.php?page=wso-settings#tab-dashboard'),
        ]);

        // Health Check
        $wp_admin_bar->add_node([
            'id'     => 'wso-toolbar-health',
            'parent' => 'wso-toolbar',
            'title'  => 'سلامت سیستم',
            'href'   => admin_url('upload.php?page=wso-settings#tab-health'),
        ]);

        // Bulk Optimization
        $wp_admin_bar->add_node([
            'id'     => 'wso-toolbar-bulk',
            'parent' => 'wso-toolbar',
            'title'  => 'بهینه‌سازی همگانی',
            'href'   => admin_url('upload.php?page=wso-settings#tab-bulk'),
        ]);

        // Notifications center (merged here so no second top-level node is needed).
        $notif_title = 'مرکز اعلان‌ها';
        if ($unread > 0) {
            $notif_title .= ' (' . number_format_i18n($unread) . ')';
        }
        $wp_admin_bar->add_node([
            'id'     => 'wso-toolbar-notifications',
            'parent' => 'wso-toolbar',
            'title'  => esc_html($notif_title),
            'href'   => admin_url('upload.php?page=wso-settings#tab-notifications'),
        ]);

        // Quick Stats
        $saved = isset($stats['saved_size']) ? (string) $stats['saved_size'] : '—';
        $wp_admin_bar->add_node([
            'id'     => 'wso-toolbar-stats',
            'parent' => 'wso-toolbar',
            'title'  => sprintf('صرفه‌جویی: %s', esc_html($saved)),
            'href'   => admin_url('upload.php?page=wso-settings#tab-dashboard'),
            'meta'   => [
                'class' => 'wso-toolbar-stats',
            ],
        ]);
    }
}
