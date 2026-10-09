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
        if (!file_exists($source_path)) {
            return ['success' => false, 'message' => 'فایل مبدأ یافت نشد.'];
        }

        $source_ext = strtolower(pathinfo($source_path, PATHINFO_EXTENSION));
        $target_format = strtolower($target_format);

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
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
        ];

        $target_mime = $mime_map[$target_format] ?? '';

        if (!$target_mime) {
            return ['success' => false, 'message' => 'فرمت هدف پشتیبانی نمی‌شود.'];
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
                $meta['sizes'][$size_key]['mime-type'] = 'image/' . $target_format;
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
     * Get list of supported conversion formats.
     */
    public function get_supported_formats(): array {
        return [
            'webp' => 'WebP',
            'avif' => 'AVIF',
            'png'  => 'PNG',
            'jpg'  => 'JPEG',
        ];
    }
}
