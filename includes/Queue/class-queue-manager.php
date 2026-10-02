<?php
/**
 * Bulk Queue Manager
 *
 * @package WSO\Queue
 */

namespace WSO\Queue;

use WSO\Core\Database;

if (!defined('ABSPATH')) {
    exit;
}

class Queue_Manager {

    /**
     * Singleton instance.
     *
     * @var Queue_Manager|null
     */
    private static ?Queue_Manager $instance = null;

    /**
     * Returns the singleton instance.
     *
     * @return Queue_Manager
     */
    public static function instance(): Queue_Manager {
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
     * Populates the queue table with un-optimized media library attachments.
     * Preserves custom scanned items without truncating the table.
     *
     * @return int Number of newly items queued.
     */
    public function populate_media_library_queue(bool $force = false): int {
        global $wpdb;
        $db = Database::instance();
        $table = $db->queue_table;

        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ];

        if (!$force) {
            $args['meta_query'] = [
                [
                    'key'     => '_wso_optimized',
                    'compare' => 'NOT EXISTS',
                ],
            ];
        }

        if ($force) {
            // When forcing re-optimization, reset all non-processing items back to pending
            $wpdb->query("UPDATE {$table} SET status = 'pending', error_message = NULL WHERE status != 'processing'");
        }

        $query = new \WP_Query($args);
        $attachment_ids = $query->posts;

        // Get currently active attachment IDs in the queue to avoid duplication
        $existing_queued = $wpdb->get_col(
            "SELECT attachment_id FROM {$table} WHERE attachment_id > 0 AND status IN ('pending', 'processing')"
        );
        $existing_map = array_flip($existing_queued ?: []);

        $count = 0;
        foreach ($attachment_ids as $id) {
            if (isset($existing_map[$id])) {
                continue;
            }

            $file = get_attached_file($id);
            if ($file && file_exists($file)) {
                $file = wp_normalize_path($file);
                $wpdb->insert(
                    $table,
                    [
                        'attachment_id' => $id,
                        'file_path'     => $file,
                        'status'        => 'pending',
                        'created_at'    => current_time('mysql'),
                        'updated_at'    => current_time('mysql'),
                    ],
                    ['%d', '%s', '%s', '%s', '%s']
                );
                $count++;
            }
        }

        return $count;
    }

    /**
     * Returns normalized set of all queued file paths (any status) to prevent duplicates.
     *
     * @return array<string, bool> Map of normalized path => true.
     */
    public function get_all_queued_paths_map(): array {
        global $wpdb;
        $db = Database::instance();
        $table = $db->queue_table;

        $paths = $wpdb->get_col("SELECT file_path FROM {$table}");
        if (empty($paths)) {
            return [];
        }

        $map = [];
        foreach ($paths as $p) {
            $map[wp_normalize_path($p)] = true;
        }
        return $map;
    }

    /**
     * Populates the queue table with custom file paths (from folder scanner).
     *
     * Deduplicates against ALL statuses (pending/processing/completed/failed/skipped),
     * skips missing files and unsupported extensions, and never breaks the queue
     * on a single bad entry.
     *
     * @param array $file_paths List of absolute file paths.
     * @return array{queued: int, skipped_existing: int, skipped_invalid: int}
     */
    public function populate_custom_paths(array $file_paths): array {
        global $wpdb;
        $db = Database::instance();
        $table = $db->queue_table;

        $existing_map = $this->get_all_queued_paths_map();
        // Also dedupe media-library attachment paths already queued.
        $existing_attachments = $wpdb->get_col(
            "SELECT file_path FROM {$table} WHERE attachment_id > 0"
        );
        if (!empty($existing_attachments)) {
            foreach ($existing_attachments as $p) {
                $existing_map[wp_normalize_path($p)] = true;
            }
        }

        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'avif', 'svg'];
        $queued = 0;
        $skipped_existing = 0;
        $skipped_invalid = 0;

        foreach ($file_paths as $file) {
            $norm_path = wp_normalize_path((string) $file);

            if (isset($existing_map[$norm_path])) {
                $skipped_existing++;
                continue;
            }

            if (!file_exists($norm_path) || !is_file($norm_path) || !is_readable($norm_path)) {
                $skipped_invalid++;
                continue;
            }

            $ext = strtolower(pathinfo($norm_path, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) {
                $skipped_invalid++;
                continue;
            }

            $inserted = $wpdb->insert(
                $table,
                [
                    'attachment_id' => 0,
                    'file_path'     => $norm_path,
                    'status'        => 'pending',
                    'created_at'    => current_time('mysql'),
                    'updated_at'    => current_time('mysql'),
                ],
                ['%d', '%s', '%s', '%s', '%s']
            );

            if (false !== $inserted) {
                $existing_map[$norm_path] = true;
                $queued++;
            } else {
                $skipped_invalid++;
            }
        }

        return [
            'queued'           => $queued,
            'skipped_existing' => $skipped_existing,
            'skipped_invalid'  => $skipped_invalid,
        ];
    }

    /**
     * Gets next batch of pending queue items.
     *
     * @param int $limit
     * @return array
     */
    public function get_pending_batch(int $limit = 5): array {
        global $wpdb;
        $db = Database::instance();
        $table = $db->queue_table;

        $results = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE status = 'pending' ORDER BY id ASC LIMIT %d", $limit),
            ARRAY_A
        );

        return $results ?: [];
    }

    /**
     * Updates queue item status.
     *
     * @param int $id
     * @param string $status
     * @param string|null $error_msg
     * @return bool
     */
    public function update_status(int $id, string $status, ?string $error_msg = null): bool {
        global $wpdb;
        $db = Database::instance();

        return $wpdb->update(
            $db->queue_table,
            [
                'status'        => $status,
                'error_message' => $error_msg,
                'updated_at'    => current_time('mysql'),
            ],
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%d']
        ) !== false;
    }

    /**
     * Gets queue stats.
     *
     * @return array Total, pending, processing, completed, failed counts.
     */
    public function get_stats(): array {
        global $wpdb;
        $db = Database::instance();
        $table = $db->queue_table;

        $results = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$table} GROUP BY status",
            ARRAY_A
        );

        $stats = [
            'total'      => 0,
            'pending'    => 0,
            'processing' => 0,
            'completed'  => 0,
            'failed'     => 0,
            'skipped'    => 0,
        ];

        if ($results) {
            foreach ($results as $row) {
                $status = $row['status'];
                $count  = (int) $row['count'];
                if ($status === 'success') {
                    $status = 'completed';
                } elseif ($status === 'error') {
                    $status = 'failed';
                }
                if (isset($stats[$status])) {
                    $stats[$status] += $count;
                }
                $stats['total'] += $count;
            }
        }

        return $stats;
    }

    /**
     * Resets/clears the entire queue.
     *
     * @return bool
     */
    public function clear(): bool {
        global $wpdb;
        $db = Database::instance();
        return $wpdb->query("TRUNCATE TABLE {$db->queue_table}") !== false;
    }
}
