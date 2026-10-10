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
     * Find unused images in the media library (paginated, conservative).
     * Never flags images used in content/thumbnails/options; unknown = used.
     *
     * @param int $limit Maximum number of results.
     * @return array List of unused attachments.
     */
    public function find_unused(int $limit = 50): array {
        global $wpdb;
        $limit = max(1, min(100, $limit));

        // Paginated ID fetch to avoid OOM (200 per page, up to 10 pages).
        $all_ids = [];
        $paged = 1;
        do {
            $args = [
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/svg+xml'],
                'posts_per_page' => 200,
                'paged'          => $paged,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ];
            $query = new \WP_Query($args);
            $page_ids = $query->posts ?: [];
            foreach ($page_ids as $pid) {
                $all_ids[] = (int) $pid;
            }
            wp_reset_postdata();
            $paged++;
        } while (!empty($page_ids) && $paged <= 10 && count($all_ids) < 2000);

        if (empty($all_ids)) {
            return [];
        }

        // Get all attachment URLs
        $placeholders = implode(',', array_fill(0, count($all_ids), '%d'));

        // Map filename stem -> attachment ID for content matching (builders store stems).
        $guid_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, guid FROM {$wpdb->posts} WHERE ID IN ({$placeholders})",
                ...$all_ids
            ),
            ARRAY_A
        );
        $stem_to_id = [];
        foreach ((array) $guid_rows as $row) {
            $stem = preg_replace('/\.[^.]+$/', '', basename((string) ($row['guid'] ?? '')));
            if ('' !== $stem) {
                $stem_to_id[$stem] = (int) $row['ID'];
            }
        }
        $used_from_content = [];
        if (!empty($stem_to_id)) {
            foreach (array_chunk(array_keys($stem_to_id), 20) as $chunk) {
                $like_parts = [];
                $like_params = [];
                foreach ($chunk as $stem) {
                    $like_parts[] = 'post_content LIKE %s';
                    $like_params[] = '%' . $wpdb->esc_like($stem) . '%';
                }
                $matched_contents = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT DISTINCT post_content FROM {$wpdb->posts} WHERE post_status IN ('publish','private','draft','pending') AND (" . implode(' OR ', $like_parts) . ") LIMIT 200",
                        ...$like_params
                    )
                );
                if (!empty($matched_contents)) {
                    $haystack = implode("\n", $matched_contents);
                    foreach ($chunk as $stem) {
                        if (false !== strpos($haystack, $stem) && isset($stem_to_id[$stem])) {
                            $used_from_content[] = $stem_to_id[$stem];
                        }
                    }
                }
            }
        }

        // Find attachments used as featured images (direct ID match).
        $used_as_thumbnail = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} 
                 WHERE meta_key = '_thumbnail_id' AND meta_value IN ({$placeholders})",
                ...$all_ids
            )
        );
        $used_thumbnail_ids = array_map('intval', $used_as_thumbnail ?: []);

        // Merge all used IDs (int-cast for strict compare).
        $used_ids = array_unique(array_merge($used_thumbnail_ids, $used_from_content));
        $used_map = array_flip($used_ids);

        // Find unused (conservative: any doubt = keep).
        $unused = [];
        foreach ($all_ids as $id) {
            $id = (int) $id;
            if (isset($used_map[$id])) {
                continue;
            }

            $file = get_attached_file($id);
            if (!$file || !file_exists($file)) {
                continue;
            }
            // Check if URL (or filename stem) is used anywhere; if so, keep.
            $url = wp_get_attachment_url($id);
            if ($url && $this->is_url_used($url)) {
                continue;
            }
            // Also check filename stem so converted webp/avif variants count.
            $stem = preg_replace('/\.[^.]+$/', '', basename($file));
            if ($stem && $this->is_url_used($stem)) {
                continue;
            }

            $unused[] = [
                'id'   => $id,
                'file' => $file,
                'url'  => $url,
                'size' => filesize($file),
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
        if ('' === trim($url)) {
            return false;
        }
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status IN ('publish','private','draft','pending')",
                '%' . $wpdb->esc_like($url) . '%'
            )
        );
        return (int) $count > 0;
    }

    /**
     * Delete unused images (capability must be checked by caller; double-checks type).
     */
    public function delete_unused(array $ids): array {
        if (!current_user_can('manage_options')) {
            return ['deleted' => 0, 'failed' => count($ids), 'freed_bytes' => 0, 'message' => 'سطح دسترسی غیرمجاز است.'];
        }
        $deleted = 0;
        $failed = 0;
        $freed_bytes = 0;

        $ids = array_values(array_filter(array_map('intval', $ids), static function ($id) {
            return $id > 0;
        }));
        $ids = array_slice($ids, 0, 100);
        foreach ($ids as $id) {
            $post = get_post($id);
            if (!$post || 'attachment' !== $post->post_type || 0 !== strpos((string) get_post_mime_type($id), 'image/')) {
                $failed++;
                continue;
            }
            $file = get_attached_file($id);
            $size = ($file && file_exists($file)) ? (int) filesize($file) : 0;

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
