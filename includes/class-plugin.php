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
     * Prevent cloning of the singleton.
     */
    private function __clone() {}

    /**
     * Prevent unserializing of the singleton.
     */
    public function __wakeup() {}

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
        add_action('admin_init', [$this, 'maybe_migrate_database']);

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

            if (class_exists('WSO\\Admin\\Media_Columns')) {
                Admin\Media_Columns::instance();
            }

            if (class_exists('WSO\\Admin\\Drag_Drop')) {
                Admin\Drag_Drop::instance();
            }

            if (class_exists('WSO\\Admin\\Before_After')) {
                Admin\Before_After::instance();
            }

            // Injects dynamic CSS Customizer & local fonts into the admin header
            add_action('admin_head', [$this, 'output_admin_head_css']);

            // Registers notifications badge count in the WordPress top admin bar
            add_action('admin_bar_menu', [$this, 'add_admin_bar_node'], 99);
        }

        // Automatic Alt Text must also run for uploads handled outside wp-admin
        // (REST API, frontend forms). The class itself stays idle when disabled.
        // Single instantiation covers both admin and frontend (singleton).
        if (class_exists('WSO\\Tools\\Auto_Alt')) {
            Tools\Auto_Alt::instance();
        }

        // Async queue processor must also load on frontend/cron requests so
        // WP-Cron and REST-triggered batches work outside wp-admin.
        if (class_exists('WSO\\Queue\\Async_Processor')) {
            Queue\Async_Processor::instance();
        }

        // Frontend + shared components (must run outside is_admin):
        // On-the-fly serves WebP/AVIF on the frontend, Preload/Lazy_Load
        // filter frontend output, REST_API registers rest_api_init,
        // Gutenberg registers blocks, Toolbar adds admin-bar nodes,
        // Auto_Scan/Auto_Alert handle WP-Cron events.
        if (class_exists('WSO\\Engine\\On_The_Fly')) {
            Engine\On_The_Fly::instance();
        }

        if (class_exists('WSO\\Tools\\REST_API')) {
            Tools\REST_API::instance();
        }

        if (class_exists('WSO\\Tools\\Preload')) {
            Tools\Preload::instance();
        }

        if (class_exists('WSO\\Tools\\Lazy_Load')) {
            Tools\Lazy_Load::instance();
        }

        if (class_exists('WSO\\Tools\\Toolbar')) {
            Tools\Toolbar::instance();
        }

        if (class_exists('WSO\\Tools\\Gutenberg')) {
            Tools\Gutenberg::instance();
        }

        if (class_exists('WSO\\Tools\\Auto_Scan')) {
            Tools\Auto_Scan::instance();
        }

        if (class_exists('WSO\\Tools\\Auto_Alert')) {
            Tools\Auto_Alert::instance();
        }

        // WP-CLI commands are loaded via explicit require (filename does
        // not match the autoloader convention for WP_CLI_Command).
        if (defined('WP_CLI') && WP_CLI) {
            $cli_file = (defined('WSO_PATH') ? WSO_PATH : plugin_dir_path(__FILE__)) . 'includes/Tools/class-wp-cli.php';
            if (file_exists($cli_file)) {
                require_once $cli_file;
            }
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
     * Outputs admin head CSS (fonts + customizer) via named callback so it is removable.
     *
     * @return void
     */
    public function output_admin_head_css(): void {
        if (class_exists('WSO\\Tools\\Font_Manager')) {
            \WSO\Tools\Font_Manager::instance()->generate_font_face_css();
        }
        if (class_exists('WSO\\Admin\\Admin_Menu')) {
            \WSO\Admin\Admin_Menu::instance()->output_customizer_css();
        }
    }

    /**
     * Adds notifications node to the admin bar via named callback so it is removable.
     *
     * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
     * @return void
     */
    public function add_admin_bar_node(\WP_Admin_Bar $wp_admin_bar): void {
        if (!class_exists('WSO\\Tools\\Notifications')) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        $unread = (int) \WSO\Tools\Notifications::instance()->get_unread_count();
        $badge = $unread > 0 ? ' <span class="ab-item-notification-badge">' . esc_html(number_format_i18n($unread)) . '</span>' : '';

        $wp_admin_bar->add_node([
            'id'    => 'wso-notifications-bar',
            'title' => esc_html__('بهینه چی', 'behinechi-optimizer') . $badge,
            'href'  => admin_url('upload.php?page=wso-settings#tab-notifications'),
            'meta'  => [
                'title' => esc_attr__('وضعیت و اعلان‌های افزونه بهینه چی', 'behinechi-optimizer'),
            ],
        ]);
    }

    /**
     * Migrates database tables when plugin version changes.
     *
     * @return void
     */
    public function maybe_migrate_database(): void {
        if (class_exists('WSO\\Core\\Database')) {
            Core\Database::instance()->maybe_migrate();
        }
    }

    /**
     * Plugin activation hook.
     *
     * @return void
     */
    public static function activate(): void {
        if (version_compare(PHP_VERSION, '7.4.0', '<')) {
            deactivate_plugins(plugin_basename(WSO_FILE));
            wp_die(esc_html__('افزونه بهینه چی به PHP نسخه 7.4 یا بالاتر نیاز دارد.', 'behinechi-optimizer'));
        }
        if (class_exists('WSO\\Core\\Database')) {
            Core\Database::instance()->create_tables();
        }

        if (class_exists('WSO\\Core\\Settings')) {
            Core\Settings::instance()->set_defaults();
        }

        // Schedule auto-scan cron
        if (class_exists('WSO\\Tools\\Auto_Scan')) {
            Tools\Auto_Scan::instance()->schedule();
        }

        // Schedule auto-alert cron
        if (class_exists('WSO\\Tools\\Auto_Alert')) {
            Tools\Auto_Alert::instance()->schedule();
        }

        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation hook.
     *
     * @return void
     */
    public static function deactivate(): void {
        // Unschedule auto-scan cron
        if (class_exists('WSO\\Tools\\Auto_Scan')) {
            Tools\Auto_Scan::instance()->unschedule();
        }

        // Unschedule auto-alert cron
        if (class_exists('WSO\\Tools\\Auto_Alert')) {
            Tools\Auto_Alert::instance()->unschedule();
        }

        flush_rewrite_rules();
    }
}
