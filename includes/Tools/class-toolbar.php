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
     * Add toolbar items.
     */
    public function add_toolbar_items(\WP_Admin_Bar $wp_admin_bar): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        try {
            $stats = \WSO\Admin\Dashboard_Widgets::get_stats();
        } catch (\Throwable $e) {
            $stats = ['saved_size' => '—'];
        }

        // Main menu item
        $wp_admin_bar->add_node([
            'id'    => 'wso-toolbar',
            'title' => 'بهینه چی',
            'href'  => admin_url('upload.php?page=wso-settings'),
            'meta'  => [
                'class' => 'wso-toolbar-menu',
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
