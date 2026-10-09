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
            $thumb_backup = $backup_manager->get_backup_path($thumb_file);

            if (file_exists($thumb_backup) && filesize($thumb_backup) > 0) {
                if (@copy($thumb_backup, $thumb_file)) {
                    $restored[] = $size_key;
                } else {
                    $failed[] = $size_key;
                }
            } else {
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
