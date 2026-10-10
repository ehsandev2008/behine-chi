<?php
/**
 * Notification Center Handler
 *
 * @package WSO\Tools
 */

namespace WSO\Tools;

use WSO\Core\Database;

if (!defined('ABSPATH')) {
    exit;
}

class Notifications {

    /**
     * Singleton instance.
     *
     * @var Notifications|null
     */
    private static ?Notifications $instance = null;

    /**
     * Returns the singleton instance.
     *
     * @return Notifications
     */
    public static function instance(): Notifications {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {}

    /**
     * Adds a new notification entry.
     *
     * @param string $type Message type (success, warning, error, info).
     * @param string $message Log and viewable message.
     * @return int Notification ID or 0 on failure.
     */
    public function add(string $type, string $message): int {
        global $wpdb;
        $db = Database::instance();
        $table = $db->notifications_table;

        $allowed = ['info', 'success', 'warning', 'error'];
        $type    = sanitize_key($type);
        if (!in_array($type, $allowed, true)) {
            $type = 'info';
        }
        $message = wp_kses_post(mb_substr($message, 0, 2000));

        // Ensure table exists
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            $db->create_tables();
        }

        $result = $wpdb->insert(
            $table,
            [
                'type'       => $type,
                'message'    => $message,
                'is_read'    => 0,
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%d', '%s']
        );

        return $result ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Gets notifications with filters.
     *
     * @param int $limit Max items.
     * @param int $offset Offset.
     * @param string $type Filter type (success, warning, etc.).
     * @param string $search Search query.
     * @return array
     */
    public function get_notifications(int $limit = 50, int $offset = 0, string $type = '', string $search = ''): array {
        global $wpdb;
        $db = Database::instance();
        $table = $db->notifications_table;
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $type = sanitize_key($type);

        $where = ['1=1'];
        $params = [];

        $allowed = ['info', 'success', 'warning', 'error'];
        if ('' !== $type && in_array($type, $allowed, true)) {
            $where[] = 'type = %s';
            $params[] = $type;
        }

        if (!empty($search)) {
            $where[] = 'message LIKE %s';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        $where_clause = implode(' AND ', $where);
        $query = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY id DESC LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        $results = $wpdb->get_results($wpdb->prepare($query, ...$params), ARRAY_A);
        return $results ?: [];
    }

    /**
     * Gets unread notifications count.
     *
     * @return int
     */
    public function get_unread_count(): int {
        global $wpdb;
        $db = Database::instance();
        $table = $db->notifications_table;

        // Ensure table exists before count to avoid crash
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            return 0;
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE is_read = 0");
    }

    /**
     * Marks a notification as read.
     *
     * @param int $id Notification ID.
     * @return bool
     */
    public function mark_as_read(int $id): bool {
        global $wpdb;
        $db = Database::instance();
        return $wpdb->update(
            $db->notifications_table,
            ['is_read' => 1],
            ['id' => $id],
            ['%d'],
            ['%d']
        ) !== false;
    }

    /**
     * Marks all notifications as read.
     *
     * @return bool
     */
    public function mark_all_read(): bool {
        global $wpdb;
        $db = Database::instance();
        return $wpdb->query("UPDATE {$db->notifications_table} SET is_read = 1 WHERE is_read = 0") !== false;
    }

    /**
     * Clears all notifications.
     *
     * @return bool
     */
    public function clear_all(): bool {
        global $wpdb;
        $db = Database::instance();
        return $wpdb->query("TRUNCATE TABLE {$db->notifications_table}") !== false;
    }
}
