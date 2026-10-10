<?php
/**
 * Selective Thumbnail Restorer
 * Restore only specific thumbnail sizes from backup.
 *
 * @package WSO\Engine
 */

namespace WSO\Engine;

if (!defined('ABSPATH')) {
    exit;
}

class Thumbnail_Restorer {

    private static ?Thumbnail_Restorer $instance = null;

    public static function instance(): Thumbnail_Restorer {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Restore only specific thumbnail sizes.
     *
     * @param int $attachment_id Attachment ID.
     * @param array $sizes Array of size keys to restore (e.g., ['thumbnail', 'medium']).
     * @return array Result with success status and details.
     */
    public function restore_sizes(int $attachment_id, array $sizes): array {
        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) {
            return ['success' => false, 'message' => 'فایل اصلی یافت نشد.'];
        }

        $backup_manager = Backup_Manager::instance();
        $meta = wp_get_attachment_metadata($attachment_id);

        if (empty($meta['sizes']) || !is_array($meta['sizes'])) {
            return ['success' => false, 'message' => 'هیچ بندانگشتی یافت نشد.'];
        }

        $base_dir = dirname($file);
        $restored = [];
        $failed = [];

        foreach ($sizes as $size_key) {
            if (empty($meta['sizes'][$size_key]['file'])) {
                $failed[] = $size_key;
                continue;
            }

            $thumb_file = $base_dir . '/' . $meta['sizes'][$size_key]['file'];
            // Current meta may point to a converted .webp/.avif name while the
            // backup was taken under the original .jpg/.png name. Try candidates.
            $candidates = [$thumb_file];
            $ti = pathinfo($thumb_file);
            foreach (['jpg', 'jpeg', 'png', 'webp'] as $orig_ext) {
                $alt = $ti['dirname'] . '/' . $ti['filename'] . '.' . $orig_ext;
                if (!in_array($alt, $candidates, true)) {
                    $candidates[] = $alt;
                }
            }
            $restored_one = false;
            foreach ($candidates as $candidate_current) {
                $thumb_backup = $backup_manager->get_backup_path($candidate_current);
                // Also try backup under the current (converted) name.
                $backup_candidates = [$thumb_backup];
                $bi = pathinfo($thumb_backup);
                foreach (['jpg', 'jpeg', 'png'] as $be) {
                    $balt = $bi['dirname'] . '/' . $bi['filename'] . '.' . $be;
                    if (!in_array($balt, $backup_candidates, true)) {
                        $backup_candidates[] = $balt;
                    }
                }
                foreach ($backup_candidates as $backup_file) {
                    if (file_exists($backup_file) && filesize($backup_file) > 0) {
                        // Restore to the ORIGINAL filename (backup basename), then
                        // point meta back to it so future optimizes find it.
                        $restore_target = $base_dir . '/' . basename($backup_file);
                        // If meta currently points to a converted name, also clean it.
                        $converted_current = $thumb_file;
                        if (@copy($backup_file, $restore_target)) {
                            if (wp_normalize_path($converted_current) !== wp_normalize_path($restore_target) && file_exists($converted_current)) {
                                @unlink($converted_current);
                            }
                            $meta['sizes'][$size_key]['file'] = basename($restore_target);
                            $ext = strtolower(pathinfo($restore_target, PATHINFO_EXTENSION));
                            $mime_map = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'avif' => 'image/avif'];
                            $meta['sizes'][$size_key]['mime-type'] = $mime_map[$ext] ?? 'image/jpeg';
                            $restored[] = $size_key;
                            $restored_one = true;
                        } else {
                            continue 2;
                        }
                        break 2;
                    }
                }
            }
            if (!$restored_one) {
                $failed[] = $size_key;
            }
        }

        // Update metadata
        if (!empty($restored)) {
            wp_update_attachment_metadata($attachment_id, $meta);
        }

        return [
            'success'   => !empty($restored),
            'restored'  => $restored,
            'failed'    => $failed,
            'message'   => sprintf('تعداد %d بندانگشتی بازگردانی شد.', count($restored)),
        ];
    }

    /**
     * Get list of available thumbnail sizes for an attachment.
     */
    public function get_available_sizes(int $attachment_id): array {
        $meta = wp_get_attachment_metadata($attachment_id);

        if (empty($meta['sizes']) || !is_array($meta['sizes'])) {
            return [];
        }

        $sizes = [];
        foreach ($meta['sizes'] as $key => $data) {
            $sizes[$key] = [
                'label'  => $this->get_size_label($key),
                'width'  => $data['width'] ?? 0,
                'height' => $data['height'] ?? 0,
                'file'   => $data['file'] ?? '',
            ];
        }

        return $sizes;
    }

    /**
     * Get human-readable label for a size key.
     */
    private function get_size_label(string $key): string {
        $labels = [
            'thumbnail'    => 'تصویر بندانگشتی',
            'medium'       => 'متوسط',
            'medium_large' => 'متوسط بزرگ',
            'large'        => 'بزرگ',
            'full'         => 'اصلی',
        ];

        return $labels[$key] ?? $key;
    }
}
