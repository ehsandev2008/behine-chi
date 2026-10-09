<?php
/**
 * Unused File Cleaner
 * Detects and suggests removal of unused images.
 *
 * @package WSO\Tools
 */

namespace WSO\Tools;

if (!defined('ABSPATH')) {
    exit;
}

class Unused_Cleaner {

    private static ?Unused_Cleaner $instance = null;

    public static function instance(): Unused_Cleaner {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Find unused images in the media library.
     *
     * @param int $limit Maximum number of results.
     * @return array List of unused attachments.
     */
    public function find_unused(int $limit = 50): array {
        global $wpdb;

        // Get all image attachments
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/svg+xml'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ];

        $query = new \WP_Query($args);
        $all_ids = $query->posts;

        if (empty($all_ids)) {
            return [];
        }

        // Get all attachment URLs
        $placeholders = implode(',', array_fill(0, count($all_ids), '%d'));

        // Find attachments used in post content
        $used_in_content = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} 
                 WHERE meta_value IN (SELECT guid FROM {$wpdb->posts} WHERE ID IN ({$placeholders}))",
                ...$all_ids
            )
        );

        // Find attachments used as featured images
        $used_as_thumbnail = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} 
                 WHERE meta_key = '_thumbnail_id' AND meta_value IN ({$placeholders})",
                ...$all_ids
            )
        );

        // Find attachments used in options (widgets, customizer, etc.)
        $used_in_options = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT option_name FROM {$wpdb->options} 
                 WHERE option_value IN ({$placeholders})",
                ...$all_ids
            )
        );

        // Merge all used IDs
        $used_ids = array_unique(array_merge($used_in_content, $used_as_thumbnail));

        // Find unused
        $unused = [];
        foreach ($all_ids as $id) {
            if (in_array($id, $used_ids, true)) {
                continue;
            }

            // Check if it's a thumbnail of a used attachment
            $meta = wp_get_attachment_metadata($id);
            if (!empty($meta['sizes'])) {
                // This is a parent image, check if any size is used
                $parent_used = false;
                foreach ($meta['sizes'] as $size_data) {
                    if (!empty($size_data['file'])) {
                        // Check if this size is used in content
                        $size_url = wp_get_attachment_url($id);
                        if ($size_url && $this->is_url_used($size_url)) {
                            $parent_used = true;
                            break;
                        }
                    }
                }
                if ($parent_used) {
                    continue;
                }
            }

            $unused[] = [
                'id'   => $id,
                'file' => get_attached_file($id),
                'url'  => wp_get_attachment_url($id),
                'size' => filesize(get_attached_file($id)),
                'date' => get_the_date('Y-m-d', $id),
            ];

            if (count($unused) >= $limit) {
                break;
            }
        }

        return $unused;
    }

    /**
     * Check if a URL is used anywhere in content.
     */
    private function is_url_used(string $url): bool {
        global $wpdb;
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status = 'publish'",
                '%' . $wpdb->esc_like($url) . '%'
            )
        );
        return $count > 0;
    }

    /**
     * Delete unused images.
     */
    public function delete_unused(array $ids): array {
        $deleted = 0;
        $failed = 0;
        $freed_bytes = 0;

        foreach ($ids as $id) {
            $file = get_attached_file($id);
            $size = file_exists($file) ? filesize($file) : 0;

            if (wp_delete_attachment($id, true)) {
                $deleted++;
                $freed_bytes += $size;
            } else {
                $failed++;
            }
        }

        return [
            'deleted'     => $deleted,
            'failed'      => $failed,
            'freed_bytes' => $freed_bytes,
        ];
    }
}
