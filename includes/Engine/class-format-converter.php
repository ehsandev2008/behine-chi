<?php
/**
 * Format Converter
 * Convert existing images between formats (PNG to WebP, AVIF to WebP, etc.)
 *
 * @package WSO\Engine
 */

namespace WSO\Engine;

if (!defined('ABSPATH')) {
    exit;
}

class Format_Converter {

    private static ?Format_Converter $instance = null;

    public static function instance(): Format_Converter {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Convert an image from one format to another.
     *
     * @param string $source_path Source file path.
     * @param string $target_format Target format (webp, avif, png, jpg).
     * @param int $quality Quality (1-100).
     * @return array Result with success status and details.
     */
    public function convert(string $source_path, string $target_format, int $quality = 85): array {
        $quality = max(1, min(100, $quality));
        if (!file_exists($source_path)) {
            return ['success' => false, 'message' => 'فایل مبدأ یافت نشد.'];
        }

        $source_ext = strtolower(pathinfo($source_path, PATHINFO_EXTENSION));
        $target_format = strtolower($target_format);
        // Normalize jpg/jpeg aliases.
        if ('jpg' === $target_format) {
            $target_format = 'jpeg';
        }
        if ('jpg' === $source_ext) {
            $source_ext = 'jpeg';
        }

        if ($source_ext === $target_format) {
            return ['success' => false, 'message' => 'فرمت مبدأ و هدف یکسان است.'];
        }

        $info = pathinfo($source_path);
        $target_path = $info['dirname'] . '/' . $info['filename'] . '.' . $target_format;

        $optimizer = Optimizer::instance();
        $driver = $optimizer->get_driver();

        $mime_map = [
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'png'  => 'image/png',
            'jpeg' => 'image/jpeg',
        ];

        $target_mime = $mime_map[$target_format] ?? '';

        if (!$target_mime) {
            return ['success' => false, 'message' => 'فرمت هدف پشتیبانی نمی‌شود.'];
        }
        // Capability gate: do not attempt conversions the driver cannot do.
        if (('image/webp' === $target_mime && !$driver->supports_webp()) || ('image/avif' === $target_mime && !$driver->supports_avif())) {
            return ['success' => false, 'message' => 'درایور فعال از فرمت هدف پشتیبانی نمی‌کند.'];
        }

        if ($driver->convert($source_path, $target_path, $target_mime, $quality)) {
            $upload_dir = wp_upload_dir();
            $basedir = wp_normalize_path($upload_dir['basedir']);
            $norm = wp_normalize_path($target_path);
            if (str_starts_with($norm, $basedir)) {
                $target_url = $upload_dir['baseurl'] . substr($norm, strlen($basedir));
            } else {
                $target_url = str_replace(wp_normalize_path(ABSPATH), site_url('/'), $norm);
            }
            return [
                'success'      => true,
                'message'      => 'تبدیل با موفقیت انجام شد.',
                'target_path'  => $target_path,
                'target_url'   => $target_url,
            ];
        }

        return ['success' => false, 'message' => 'تبدیل با خطا مواجه شد.'];
    }

    /**
     * Convert all thumbnails of an attachment to a different format.
     */
    public function convert_all_thumbnails(int $attachment_id, string $target_format, int $quality = 85): array {
        $file = get_attached_file($attachment_id);
        if (!$file) {
            return ['success' => false, 'message' => 'فایل یافت نشد.'];
        }

        $meta = wp_get_attachment_metadata($attachment_id);
        if (empty($meta['sizes'])) {
            return ['success' => false, 'message' => 'هیچ بندانگشتی یافت نشد.'];
        }

        $base_dir = dirname($file);
        $converted = [];
        $failed = [];

        foreach ($meta['sizes'] as $size_key => $size_data) {
            if (empty($size_data['file'])) {
                continue;
            }

            $thumb_path = $base_dir . '/' . $size_data['file'];
            $result = $this->convert($thumb_path, $target_format, $quality);

            if ($result['success']) {
                $converted[$size_key] = $result['target_path'];
                // Update metadata
                $meta['sizes'][$size_key]['file'] = basename($result['target_path']);
                $meta['sizes'][$size_key]['mime-type'] = 'image/' . ('jpeg' === $target_format ? 'jpeg' : $target_format);
            } else {
                $failed[] = $size_key;
            }
        }

        if (!empty($converted)) {
            wp_update_attachment_metadata($attachment_id, $meta);
        }

        return [
            'success'   => !empty($converted),
            'converted' => $converted,
            'failed'    => $failed,
            'message'   => sprintf('تعداد %d بندانگشتی تبدیل شد.', count($converted)),
        ];
    }

    /**
     * Convert a full attachment (main file + all thumbnails) to target format.
     * Used by bulk "convert to WebP/AVIF" mode.
     */
    public function convert_attachment(int $attachment_id, string $target_format, int $quality = 85): array {
        $target_format = strtolower($target_format);
        if ('jpg' === $target_format) {
            $target_format = 'jpeg';
        }
        if (!in_array($target_format, ['webp', 'avif'], true)) {
            return ['success' => false, 'message' => 'فرمت هدف برای تبدیل همگانی فقط WebP یا AVIF است.'];
        }
        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) {
            return ['success' => false, 'message' => 'فایل تصویر یافت نشد.'];
        }
        $quality = max(1, min(100, $quality));

        $converted = [];
        $failed = [];

        // 1) Main file.
        $main_res = $this->convert($file, $target_format, $quality);
        $new_main_path = '';
        if (!empty($main_res['success'])) {
            $new_main_path = $main_res['target_path'];
            $converted['full'] = $new_main_path;
        } else {
            // Already in target format counts as success (nothing to do).
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (('jpeg' === $target_format && in_array($ext, ['jpg', 'jpeg'], true)) || $ext === $target_format) {
                $converted['full'] = $file;
            } else {
                $failed[] = 'full';
            }
        }

        // 2) Thumbnails.
        $meta = wp_get_attachment_metadata($attachment_id);
        $base_dir = dirname($file);
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $size_key => $size_data) {
                if (empty($size_data['file'])) {
                    continue;
                }
                $thumb_path = $base_dir . '/' . $size_data['file'];
                if (!file_exists($thumb_path)) {
                    continue;
                }
                $r = $this->convert($thumb_path, $target_format, $quality);
                if (!empty($r['success'])) {
                    $converted[$size_key] = $r['target_path'];
                    $meta['sizes'][$size_key]['file'] = basename($r['target_path']);
                    $meta['sizes'][$size_key]['mime-type'] = 'image/' . $target_format;
                } else {
                    $ext = strtolower(pathinfo($thumb_path, PATHINFO_EXTENSION));
                    if ($ext === $target_format || ('jpeg' === $target_format && 'jpg' === $ext)) {
                        $converted[$size_key] = $thumb_path;
                    } else {
                        $failed[] = (string) $size_key;
                    }
                }
            }
        }

        if ('' !== $new_main_path && file_exists($new_main_path)) {
            $upload_dir = wp_upload_dir();
            $basedir_norm = wp_normalize_path($upload_dir['basedir']);
            $conv_norm = wp_normalize_path($new_main_path);
            $relative_path = ltrim(substr($conv_norm, strlen($basedir_norm)), '/');
            if ('' === $relative_path) {
                $relative_path = ltrim(str_replace(wp_normalize_path($upload_dir['basedir']), '', $new_main_path), '/\\');
            }
            update_post_meta($attachment_id, '_wp_attached_file', $relative_path);
            if (is_array($meta)) {
                $meta['file'] = $relative_path;
            }
            global $wpdb;
            $wpdb->update(
                $wpdb->posts,
                ['post_mime_type' => 'image/' . $target_format],
                ['ID' => $attachment_id],
                ['%s'],
                ['%d']
            );
            update_post_meta($attachment_id, '_wso_optimized', 1);
            delete_transient('wso_upload_dir_scan');
        }

        if (is_array($meta)) {
            wp_update_attachment_metadata($attachment_id, $meta);
        }

        $ok = !empty($converted);
        return [
            'success'   => $ok,
            'status'    => $ok ? 'success' : 'error',
            'converted' => $converted,
            'failed'    => $failed,
            'message'   => $ok ? sprintf('تبدیل به %s انجام شد (%d فایل).', strtoupper($target_format), count($converted)) : 'تبدیل انجام نشد. ' . ($main_res['message'] ?? ''),
        ];
    }

    /**
     * Get list of supported conversion formats.
     */
    public function get_supported_formats(): array {
        $driver = null;
        try {
            $driver = Optimizer::instance()->get_driver();
        } catch (\Throwable $e) {}
        $formats = [];
        if (!$driver || $driver->supports_webp()) {
            $formats['webp'] = 'WebP';
        }
        if (!$driver || $driver->supports_avif()) {
            $formats['avif'] = 'AVIF';
        }
        $formats['png']  = 'PNG';
        $formats['jpeg'] = 'JPEG';
        return $formats;
    }
}
