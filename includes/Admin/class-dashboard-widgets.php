<?php
/**
 * Admin Dashboard Widgets & Metrics Calculator
 *
 * @package WSO\Admin
 */

namespace WSO\Admin;

use WSO\Core\Database;
use WSO\Queue\Queue_Manager;

if (!defined('ABSPATH')) {
    exit;
}

class Dashboard_Widgets {

    /**
     * Calculates comprehensive optimization statistics.
     *
     * @return array
     */
    public static function get_stats(): array {
        global $wpdb;
        $db = Database::instance();
        $logs_table = $db->logs_table;

        // Total media attachments
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ];
        $query = new \WP_Query($args);
        $total_images = count($query->posts);

        // Sum original and optimized sizes from logs table (deduplicated by file_name, MySQL ONLY_FULL_GROUP_BY safe)
        $sums = null;
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $logs_table)) === $logs_table) {
            $sums = $wpdb->get_row(
                "SELECT 
                    COUNT(*) as count,
                    SUM(t.orig_size) as total_original, 
                    SUM(t.opt_size) as total_optimized, 
                    SUM(t.saved) as total_saved 
                 FROM (
                    SELECT 
                        file_name, 
                        MAX(original_size) as orig_size, 
                        MIN(optimized_size) as opt_size, 
                        MAX(saved_bytes) as saved 
                    FROM {$logs_table} 
                    WHERE status = 'success' 
                    GROUP BY file_name
                 ) t",
                ARRAY_A
            );
        }

        $optimized_count = (int) ($sums['count'] ?? 0);
        $orig_size       = (int) ($sums['total_original'] ?? 0);
        $opt_size        = (int) ($sums['total_optimized'] ?? 0);
        $saved_bytes     = (int) ($sums['total_saved'] ?? 0);

        $space_saved_pct = $orig_size > 0 ? round(($saved_bytes / $orig_size) * 100, 1) : 0;

        // Cached WebP & AVIF count across upload folder (prevents freezing on huge media libraries)
        $folder_stats = get_transient('wso_dashboard_folder_stats');
        if (false === $folder_stats || !is_array($folder_stats)) {
            $upload_dir = wp_upload_dir()['basedir'];
            $webp_count  = 0;
            $avif_count  = 0;
            $cache_bytes = 0;

            if (is_dir($upload_dir)) {
                try {
                    $iterator = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($upload_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                        \RecursiveIteratorIterator::SELF_FIRST
                    );
                    $file_counter = 0;
                    foreach ($iterator as $file) {
                        if ($file_counter > 50000) {
                            break; // Guard against infinite or runaway directory trees
                        }
                        $file_counter++;

                        if ($file->isFile()) {
                            $pathname = wp_normalize_path($file->getPathname());
                            if (str_contains($pathname, '/wso-backups/')) {
                                continue;
                            }
                            $ext = strtolower($file->getExtension());
                            if ($ext === 'webp') {
                                $webp_count++;
                                $cache_bytes += $file->getSize();
                            } elseif ($ext === 'avif') {
                                $avif_count++;
                                $cache_bytes += $file->getSize();
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // Fail gracefully on file permission or path errors
                }
            }

            $folder_stats = [
                'webp_count'  => $webp_count,
                'avif_count'  => $avif_count,
                'cache_bytes' => $cache_bytes,
            ];
            set_transient('wso_dashboard_folder_stats', $folder_stats, HOUR_IN_SECONDS);
        } else {
            $webp_count  = (int) ($folder_stats['webp_count'] ?? 0);
            $avif_count  = (int) ($folder_stats['avif_count'] ?? 0);
            $cache_bytes = (int) ($folder_stats['cache_bytes'] ?? 0);
        }

        $queue_stats = Queue_Manager::instance()->get_stats();

        return [
            'total_images'     => $total_images,
            'optimized_images' => $optimized_count,
            'original_size'    => size_format($orig_size, 2),
            'optimized_size'   => size_format($opt_size, 2),
            'saved_size'       => size_format($saved_bytes, 2),
            'saved_bytes'      => $saved_bytes,
            'space_saved_pct'  => $space_saved_pct,
            'webp_count'       => $webp_count,
            'avif_count'       => $avif_count,
            'failed_count'     => $queue_stats['failed'] ?? 0,
            'pending_queue'    => $queue_stats['pending'] ?? 0,
            'cache_size'       => size_format($cache_bytes, 2),
        ];
    }
}
