<?php
/**
 * Database Management Class
 *
 * @package WSO\Core
 */

namespace WSO\Core;

if (!defined('ABSPATH')) {
    exit;
}

class Database {

    /**
     * Singleton instance.
     *
     * @var Database|null
     */
    private static ?Database $instance = null;

    /**
     * Queue table name.
     */
    public string $queue_table;

    /**
     * Logs table name.
     */
    public string $logs_table;

    /**
     * Notifications table name.
     */
    public string $notifications_table;

    /**
     * Returns the singleton instance.
     *
     * @return Database
     */
    public static function instance(): Database {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {
        global $wpdb;
        $this->queue_table = $wpdb->prefix . 'wso_queue';
        $this->logs_table  = $wpdb->prefix . 'wso_logs';
        $this->notifications_table = $wpdb->prefix . 'wso_notifications';
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
     * Creates or updates plugin database tables.
     *
     * @return void
     */
    public function create_tables(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql_queue = "CREATE TABLE {$this->queue_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            file_path varchar(512) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            error_message text NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY attachment_id (attachment_id)
        ) {$charset_collate};";

        $sql_logs = "CREATE TABLE {$this->logs_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            file_name varchar(255) NOT NULL,
            original_size bigint(20) NOT NULL DEFAULT 0,
            optimized_size bigint(20) NOT NULL DEFAULT 0,
            saved_bytes bigint(20) NOT NULL DEFAULT 0,
            savings_percent decimal(5,2) NOT NULL DEFAULT 0.00,
            status varchar(20) NOT NULL DEFAULT 'success',
            message text NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        $sql_notifications = "CREATE TABLE {$this->notifications_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            type varchar(20) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            is_read tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY is_read (is_read)
        ) {$charset_collate};";

        dbDelta($sql_queue);
        dbDelta($sql_logs);
        dbDelta($sql_notifications);

        update_option('wso_db_version', defined('WSO_VERSION') ? WSO_VERSION : '2.1.0');
    }

    /**
     * Runs table migration when plugin version changes (for updates without re-activation).
     *
     * @return void
     */
    public function maybe_migrate(): void {
        $stored = get_option('wso_db_version', '');
        $current = defined('WSO_VERSION') ? WSO_VERSION : '2.1.0';
        if ($stored !== $current) {
            $this->create_tables();
        }
    }
}
