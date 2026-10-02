<?php
/**
 * Main Plugin Container for WebP Smart Optimizer
 *
 * @package WSO
 */

namespace WSO;

if (!defined('ABSPATH')) {
    exit;
}

class Plugin {

    /**
     * Singleton instance.
     *
     * @var Plugin|null
     */
    private static ?Plugin $instance = null;

    /**
     * Settings instance.
     *
     * @var Core\Settings|null
     */
    public ?Core\Settings $settings = null;

    /**
     * Database instance.
     *
     * @var Core\Database|null
     */
    public ?Core\Database $database = null;

    /**
     * Optimizer engine instance.
     *
     * @var Engine\Optimizer|null
     */
    public ?Engine\Optimizer $optimizer = null;

    /**
     * Backup Manager instance.
     *
     * @var Engine\Backup_Manager|null
     */
    public ?Engine\Backup_Manager $backup_manager = null;

    /**
     * Returns the singleton instance.
     *
     * @return Plugin
     */
    public static function instance(): Plugin {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor to enforce singleton.
     */
    private function __construct() {
        $this->init_components();
        $this->init_hooks();
    }

    /**
     * Initialize core components.
     *
     * @return void
     */
    private function init_components(): void {
        if (class_exists('WSO\\Core\\Settings')) {
            $this->settings = Core\Settings::instance();
        }

        if (class_exists('WSO\\Core\\Database')) {
            $this->database = Core\Database::instance();
        }

        if (class_exists('WSO\\Engine\\Backup_Manager')) {
            $this->backup_manager = Engine\Backup_Manager::instance();
        }

        if (class_exists('WSO\\Engine\\Optimizer')) {
            $this->optimizer = Engine\Optimizer::instance();
        }
    }

    /**
     * Initialize WordPress hooks.
     *
     * @return void
     */
    private function init_hooks(): void {
        add_action('init', [$this, 'load_textdomain']);

        if (is_admin()) {
            if (class_exists('WSO\\Admin\\Admin_Menu')) {
                Admin\Admin_Menu::instance();
            }

            if (class_exists('WSO\\Admin\\Assets_Loader')) {
                Admin\Assets_Loader::instance();
            }

            if (class_exists('WSO\\Admin\\Ajax_Handler')) {
                Admin\Ajax_Handler::instance();
            }

            if (class_exists('WSO\\Admin\\Media_Library')) {
                Admin\Media_Library::instance();
            }

            if (class_exists('WSO\\Queue\\Async_Processor')) {
                Queue\Async_Processor::instance();
            }

            if (class_exists('WSO\\Scanner\\Folder_Scanner')) {
                Scanner\Folder_Scanner::instance();
            }

            if (class_exists('WSO\\Tools\\Cache_Manager')) {
                Tools\Cache_Manager::instance();
            }

            if (class_exists('WSO\\Tools\\Exporter_Importer')) {
                Tools\Exporter_Importer::instance();
            }

            if (class_exists('WSO\\Tools\\Auto_Alt')) {
                Tools\Auto_Alt::instance();
            }

            // Injects dynamic CSS Customizer & local fonts into the admin header
            add_action('admin_head', function(): void {
                if (class_exists('WSO\\Tools\\Font_Manager')) {
                    \WSO\Tools\Font_Manager::instance()->generate_font_face_css();
                }
                if (class_exists('WSO\\Admin\\Admin_Menu')) {
                    \WSO\Admin\Admin_Menu::instance()->output_customizer_css();
                }
            });

            // Registers notifications badge count in the WordPress top admin bar
            add_action('admin_bar_menu', function(\WP_Admin_Bar $wp_admin_bar): void {
                if (class_exists('WSO\\Tools\\Notifications')) {
                    $unread = \WSO\Tools\Notifications::instance()->get_unread_count();
                    $badge = $unread > 0 ? ' <span class="ab-item-notification-badge" style="background:#ef4444;color:#fff;border-radius:10px;padding:1px 6px;font-size:10px;margin-right:6px;font-weight:bold;">' . $unread . '</span>' : '';
                    
                    $wp_admin_bar->add_node([
                        'id'    => 'wso-notifications-bar',
                        'title' => 'بهینه چی' . $badge,
                        'href'  => admin_url('upload.php?page=wso-settings#tab-notifications'),
                        'meta'  => [
                            'title' => 'وضعیت و اعلان‌های افزونه بهینه چی',
                        ],
                    ]);
                }
            }, 99);
        }

        // Automatic Alt Text must also run for uploads handled outside wp-admin
        // (REST API, frontend forms). The class itself stays idle when disabled.
        if (class_exists('WSO\\Tools\\Auto_Alt')) {
            Tools\Auto_Alt::instance();
        }
    }

    /**
     * Load textdomain for i18n support.
     *
     * @return void
     */
    public function load_textdomain(): void {
        load_plugin_textdomain('behinechi-optimizer', false, dirname(plugin_basename(WSO_FILE)) . '/languages');
    }

    /**
     * Plugin activation hook.
     *
     * @return void
     */
    public static function activate(): void {
        if (class_exists('WSO\\Core\\Database')) {
            Core\Database::instance()->create_tables();
        }

        if (class_exists('WSO\\Core\\Settings')) {
            Core\Settings::instance()->set_defaults();
        }

        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation hook.
     *
     * @return void
     */
    public static function deactivate(): void {
        flush_rewrite_rules();
    }
}
