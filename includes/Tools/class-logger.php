<?php
/**
 * Logger Tool Class
 *
 * @package WSO\Tools
 */

namespace WSO\Tools;

use WSO\Core\Database;

if (!defined('ABSPATH')) {
    exit;
}

class Logger {

    /**
     * Log status constants.
     */
    public const STATUS_SUCCESS = 'success';
    public const STATUS_WARNING = 'warning';
    public const STATUS_ERROR   = 'error';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * Adds a log entry to database.
     *
     * @param array $data Log entry data.
     * @return bool
     */
    public static function log(array $data): bool {
        global $wpdb;

        $db = Database::instance();
        $table = $db->logs_table;

        $attachment_id  = (int) ($data['attachment_id'] ?? 0);
        $file_name      = sanitize_text_field($data['file_name'] ?? 'Unknown');
        $original_size  = max(0, (int) ($data['original_size'] ?? 0));
        $optimized_size = max(0, (int) ($data['optimized_size'] ?? 0));
        $saved_bytes    = $original_size - $optimized_size;
        $savings_pct    = $original_size > 0 ? round(($saved_bytes / $original_size) * 100, 2) : 0.0;
        $allowed_status = [self::STATUS_SUCCESS, self::STATUS_WARNING, self::STATUS_ERROR, self::STATUS_SKIPPED];
        $status         = sanitize_key($data['status'] ?? self::STATUS_SUCCESS);
        if (!in_array($status, $allowed_status, true)) {
            $status = self::STATUS_ERROR;
        }
        $message        = sanitize_text_field($data['message'] ?? '');

        // Check if table exists before inserting
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            $db->create_tables();
        }

        $result = $wpdb->insert(
            $table,
            [
                'attachment_id'   => $attachment_id,
                'file_name'       => $file_name,
                'original_size'   => $original_size,
                'optimized_size'  => $optimized_size,
                'saved_bytes'     => $saved_bytes,
                'savings_percent' => $savings_pct,
                'status'          => $status,
                'message'         => $message,
                'created_at'      => current_time('mysql'),
            ],
            ['%d', '%s', '%d', '%d', '%d', '%f', '%s', '%s', '%s']
        );

        return $result !== false;
    }

    /**
     * Gets paginated logs from database.
     *
     * @param int $limit Number of items.
     * @param int $offset Offset.
     * @param string $status Filter status.
     * @param string $search Search query.
     * @return array
     */
    public static function get_logs(int $limit = 20, int $offset = 0, string $status = '', string $search = ''): array {
        global $wpdb;
        $db = Database::instance();
        $table = $db->logs_table;
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $status = sanitize_key($status);

        $where = ['1=1'];
        $params = [];

        $allowed_status = ['success', 'warning', 'error', 'skipped'];
        if ('' !== $status && in_array($status, $allowed_status, true)) {
            $where[] = 'status = %s';
            $params[] = $status;
        }

        if (!empty($search)) {
            $where[] = '(file_name LIKE %s OR message LIKE %s)';
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $where_clause = implode(' AND ', $where);
        
        $query = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY id DESC LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        $results = $wpdb->get_results($wpdb->prepare($query, ...$params), ARRAY_A);
        return $results ?: [];
    }

    /**
     * Total log count query.
     *
     * @param string $status
     * @param string $search
     * @return int
     */
    public static function get_count(string $status = '', string $search = ''): int {
        global $wpdb;
        $db = Database::instance();
        $table = $db->logs_table;
        $status = sanitize_key($status);

        $where = ['1=1'];
        $params = [];

        $allowed_status = ['success', 'warning', 'error', 'skipped'];
        if ('' !== $status && in_array($status, $allowed_status, true)) {
            $where[] = 'status = %s';
            $params[] = $status;
        }

        if (!empty($search)) {
            $where[] = '(file_name LIKE %s OR message LIKE %s)';
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $where_clause = implode(' AND ', $where);
        $query = "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}";

        if (!empty($params)) {
            return (int) $wpdb->get_var($wpdb->prepare($query, ...$params));
        }

        return (int) $wpdb->get_var($query);
    }

    /**
     * Clears all log entries.
     *
     * @return bool
     */
    public static function clear(): bool {
        global $wpdb;
        $db = Database::instance();
        return $wpdb->query("TRUNCATE TABLE {$db->logs_table}") !== false;
    }
}
