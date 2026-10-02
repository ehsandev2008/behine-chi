<?php
/**
 * موتور اصلی بهینه‌سازی تصاویر
 * Main Image Optimizer Engine
 *
 * @package WSO\Engine
 */

namespace WSO\Engine;

use WSO\Core\Settings;
use WSO\Tools\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class Optimizer {

    /**
     * Singleton instance.
     *
     * @var Optimizer|null
     */
    private static ?Optimizer $instance = null;

    /**
     * Driver instance.
     *
     * @var Image_Driver
     */
    private Image_Driver $driver;

    /**
     * Returns the singleton instance.
     *
     * @return Optimizer
     */
    public static function instance(): Optimizer {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {
        $imagick = new Imagick_Driver();
        if ($imagick->is_available()) {
            $this->driver = $imagick;
        } else {
            $this->driver = new GD_Driver();
        }
    }

    /**
     * Gets the active image driver.
     *
     * @return Image_Driver
     */
    public function get_driver(): Image_Driver {
        return $this->driver;
    }

    /**
     * Optimizes a single file path safely with backup check.
     *
     * @param string $file_path Absolute path to image.
     * @param int $attachment_id Optional attachment ID.
     * @param bool $apply_watermark Whether to apply watermark (thumbnails pass false).
     * @return array Result summary with status, original_size, optimized_size, formats.
     */
    public function optimize_file(string $file_path, int $attachment_id = 0, bool $apply_watermark = true): array {
        $settings = Settings::instance();

        if (!$settings->get('wso_enable', 1)) {
            return [
                'status'  => Logger::STATUS_SKIPPED,
                'message' => 'بهینه‌سازی تصاویر در تنظیمات افزونه غیرفعال است.',
            ];
        }

        if (!file_exists($file_path)) {
            return [
                'status'  => Logger::STATUS_ERROR,
                'message' => 'فایل تصویر یافت نشد.',
            ];
        }

        $orig_size = filesize($file_path);
        $max_size_mb = (int) $settings->get('wso_max_size', 2);
        if ($orig_size > ($max_size_mb * 1024 * 1024)) {
            $msg = sprintf('حجم فایل بیشتر از حد مجاز تعیین شده (%d مگابایت) است و نادیده گرفته شد.', $max_size_mb);
            Logger::log([
                'attachment_id' => $attachment_id,
                'file_name'     => basename($file_path),
                'original_size' => $orig_size,
                'optimized_size'=> $orig_size,
                'status'        => Logger::STATUS_SKIPPED,
                'message'       => $msg,
            ]);
            return ['status' => Logger::STATUS_SKIPPED, 'message' => $msg];
        }

        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        $mime = '';
        $filetype = wp_check_filetype($file_path);
        if (!empty($filetype['type'])) {
            $mime = $filetype['type'];
        } elseif (function_exists('getimagesize') && $ext !== 'svg') {
            $img_info = @getimagesize($file_path);
            $mime = $img_info['mime'] ?? '';
        }

        if ($ext === 'svg' || $mime === 'image/svg+xml') {
            $mime = 'image/svg+xml';
        }

        $supported_mimes = ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'];
        if (!in_array($mime, $supported_mimes, true) && !in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'svg'], true)) {
            return [
                'status'  => Logger::STATUS_SKIPPED,
                'message' => 'فرمت تصویر پشتیبانی نمی‌شود (تنها JPEG، PNG، WebP و SVG قابل بهینه‌سازی هستند).',
            ];
        }

        // BACKUP INTEGRITY GUARANTEE: Backup original image BEFORE touching the file
        if ($settings->get('wso_backup_originals', 1)) {
            $backed_up = Backup_Manager::instance()->backup($file_path);
            if (!$backed_up) {
                $err_msg = 'خطا در ایجاد نسخه پشتیبان از تصویر اصلی؛ بهینه‌سازی برای جلوگیری از آسیب به فایل متوقف شد.';
                Logger::log([
                    'attachment_id' => $attachment_id,
                    'file_name'     => basename($file_path),
                    'original_size' => $orig_size,
                    'optimized_size'=> $orig_size,
                    'status'        => Logger::STATUS_ERROR,
                    'message'       => $err_msg,
                ]);
                return ['status' => Logger::STATUS_ERROR, 'message' => $err_msg];
            }
        }

        // SVG Optimization Branch (Native Pure-PHP SVG Optimizer)
        if ($ext === 'svg' || $mime === 'image/svg+xml') {
            if (!$settings->get('wso_optimize_svg', 1)) {
                return [
                    'status'  => Logger::STATUS_SKIPPED,
                    'message' => 'بهینه‌سازی فایل‌های SVG در تنظیمات غیرفعال است.',
                ];
            }

            $svg_res = SVG_Optimizer::instance()->optimize($file_path);
            $status  = $svg_res['success'] ? Logger::STATUS_SUCCESS : Logger::STATUS_ERROR;
            $msg     = $svg_res['message'];

            Logger::log([
                'attachment_id' => $attachment_id,
                'file_name'     => basename($file_path),
                'original_size' => $svg_res['original_size'],
                'optimized_size'=> $svg_res['optimized_size'],
                'status'        => $status,
                'message'       => $msg,
            ]);

            return [
                'status'          => $status,
                'message'         => $msg,
                'original_size'   => $svg_res['original_size'],
                'optimized_size'  => $svg_res['optimized_size'],
                'saved_bytes'     => $svg_res['saved_bytes'],
                'savings_percent' => $svg_res['savings_percent'],
                'formats'         => ['svg' => $file_path],
            ];
        }

        $quality = (int) $settings->get('wso_quality', 82);

        // Strip EXIF metadata
        if ($settings->get('wso_strip_exif', 1)) {
            $this->driver->strip_exif($file_path);
        }

        // Resize down if enabled
        $max_w = (int) $settings->get('wso_max_width', 2560);
        $max_h = (int) $settings->get('wso_max_height', 2560);
        if ($max_w > 0 && $max_h > 0) {
            $this->driver->resize($file_path, $max_w, $max_h, $quality);
        }

        // Apply watermark once per main file (never on SVG, never on thumbnails).
        $watermark_applied = false;
        if ($apply_watermark) {
            try {
                $wm_res = Watermark::instance()->apply($file_path, $attachment_id);
                $watermark_applied = !empty($wm_res['applied']);
            } catch (\Throwable $e) {
                $watermark_applied = false;
            }
        }

        $generated_formats = [];

        // Generate WebP
        if ($settings->get('wso_convert_webp', 1)) {
            $info = pathinfo($file_path);
            $webp_file = $info['dirname'] . '/' . $info['filename'] . '.webp';
            if ($this->driver->convert($file_path, $webp_file, 'image/webp', $quality)) {
                $generated_formats['webp'] = $webp_file;
            }
        }

        // Generate AVIF
        if ($settings->get('wso_convert_avif', 0)) {
            $info = pathinfo($file_path);
            $avif_file = $info['dirname'] . '/' . $info['filename'] . '.avif';
            if ($this->driver->convert($file_path, $avif_file, 'image/avif', $quality)) {
                $generated_formats['avif'] = $avif_file;
            }
        }

        $opt_size = filesize($file_path);
        if (!empty($generated_formats['webp']) && file_exists($generated_formats['webp'])) {
            $opt_size = min($opt_size, filesize($generated_formats['webp']));
        }
        if (!empty($generated_formats['avif']) && file_exists($generated_formats['avif'])) {
            $opt_size = min($opt_size, filesize($generated_formats['avif']));
        }

        $saved = max(0, $orig_size - $opt_size);
        $pct   = $orig_size > 0 ? round(($saved / $orig_size) * 100, 2) : 0.0;

        $msg = sprintf('بهینه‌سازی با موفقیت انجام شد! میزان صرفه‌جویی: %s (%s٪)', size_format($saved, 2), $pct);

        Logger::log([
            'attachment_id' => $attachment_id,
            'file_name'     => basename($file_path),
            'original_size' => $orig_size,
            'optimized_size'=> $opt_size,
            'status'        => Logger::STATUS_SUCCESS,
            'message'       => $msg,
        ]);

        return [
            'status'            => Logger::STATUS_SUCCESS,
            'message'           => $msg,
            'original_size'     => $orig_size,
            'optimized_size'    => $opt_size,
            'saved_bytes'       => $saved,
            'savings_percent'   => $pct,
            'formats'           => $generated_formats,
            'watermark_applied' => $watermark_applied,
        ];
    }

    /**
     * Resolves the trustworthy original file size for an attachment.
     *
     * Priority: backup file size (true pre-optimization bytes) > previously
     * stored original_size > current file size. This prevents re-optimize
     * runs from overwriting the original with an already-shrunk value.
     *
     * @param int $attachment_id Attachment ID.
     * @param string $file Current absolute file path.
     * @param int $current_size Filesize measured before this run.
     * @return int
     */
    private function resolve_true_original_size(int $attachment_id, string $file, int $current_size): int {
        $candidates = [$current_size];

        if ($attachment_id > 0) {
            $stored = get_post_meta($attachment_id, '_wso_opt_data', true);
            if (is_array($stored) && !empty($stored['original_size'])) {
                $candidates[] = (int) $stored['original_size'];
            }
        }

        // Backup holds the pristine pre-optimization bytes — most reliable source.
        try {
            $backup_path = Backup_Manager::instance()->get_backup_path($file);
            if (is_string($backup_path) && file_exists($backup_path) && filesize($backup_path) > 0) {
                $candidates[] = (int) filesize($backup_path);
            }
        } catch (\Throwable $e) {
            // Ignore backup lookup failures; fall back to other candidates.
        }

        $candidates = array_filter($candidates, static function ($v) {
            return is_numeric($v) && (int) $v > 0;
        });

        return empty($candidates) ? $current_size : max(array_map('intval', $candidates));
    }

    public function optimize_attachment(int $attachment_id): array {
        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) {
            return [
                'status'  => Logger::STATUS_ERROR,
                'message' => 'فایل تصویر متصل به رسانه یافت نشد.',
            ];
        }

        // Capture pre-run size BEFORE any in-place modification so re-runs can be reconciled.
        $pre_run_size = file_exists($file) ? (int) filesize($file) : 0;
        $true_original = $this->resolve_true_original_size($attachment_id, $file, $pre_run_size);

        $result = $this->optimize_file($file, $attachment_id);
        if ($result['status'] === Logger::STATUS_ERROR) {
            return $result;
        }

        // Reconcile sizes: original must never shrink to an already-optimized value.
        if (isset($result['original_size'])) {
            $result['original_size'] = max((int) $result['original_size'], $true_original);
            $opt = (int) ($result['optimized_size'] ?? $pre_run_size);
            // Guard: optimized can never exceed original; clamp on edge cases (e.g. failed shrink).
            if ($opt > $result['original_size'] && $true_original === $pre_run_size) {
                $opt = $result['original_size'];
                $result['optimized_size'] = $opt;
            }
            $result['saved_bytes']     = max(0, $result['original_size'] - $opt);
            $result['savings_percent'] = $result['original_size'] > 0
                ? round(($result['saved_bytes'] / $result['original_size']) * 100, 2)
                : 0.0;
            $result['optimized_size']  = $opt;
        }

        $base_dir = dirname($file);
        $meta = wp_get_attachment_metadata($attachment_id);
        $has_meta_updates = false;

        // Process thumbnail sub-sizes
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $size_key => $size_data) {
                if (!empty($size_data['file'])) {
                    $sub_file = $base_dir . '/' . $size_data['file'];
                    if (file_exists($sub_file)) {
                        // Thumbnails are never watermarked (avoids overflow + double-apply).
                        $sub_res = $this->optimize_file($sub_file, $attachment_id, false);
                        // If converted to avif/webp, update metadata filename
                        if (!empty($sub_res['formats']['avif']) && file_exists($sub_res['formats']['avif'])) {
                            $meta['sizes'][$size_key]['file'] = basename($sub_res['formats']['avif']);
                            $meta['sizes'][$size_key]['mime-type'] = 'image/avif';
                            $has_meta_updates = true;
                        } elseif (!empty($sub_res['formats']['webp']) && file_exists($sub_res['formats']['webp'])) {
                            $meta['sizes'][$size_key]['file'] = basename($sub_res['formats']['webp']);
                            $meta['sizes'][$size_key]['mime-type'] = 'image/webp';
                            $has_meta_updates = true;
                        }
                    }
                }
            }
        }

        // If main file was converted to AVIF or WebP, update main metadata
        $converted_file = '';
        $mime_type = '';
        if (!empty($result['formats']['avif']) && file_exists($result['formats']['avif'])) {
            $converted_file = $result['formats']['avif'];
            $mime_type = 'image/avif';
        } elseif (!empty($result['formats']['webp']) && file_exists($result['formats']['webp'])) {
            $converted_file = $result['formats']['webp'];
            $mime_type = 'image/webp';
        }

        if (!empty($converted_file)) {
            $upload_dir = wp_upload_dir();
            $relative_path = ltrim(str_replace($upload_dir['basedir'], '', $converted_file), '/\\');

            update_post_meta($attachment_id, '_wp_attached_file', $relative_path);
            
            if (is_array($meta)) {
                $meta['file'] = $relative_path;
                $has_meta_updates = true;
            }

            global $wpdb;
            $wpdb->update(
                $wpdb->posts,
                ['post_mime_type' => $mime_type],
                ['ID' => $attachment_id],
                ['%s'],
                ['%d']
            );
        }

        if ($has_meta_updates && is_array($meta)) {
            wp_update_attachment_metadata($attachment_id, $meta);
        }

        $result['optimized_at'] = current_time('mysql');
        $result['optimization_status'] = $result['status'];

        update_post_meta($attachment_id, '_wso_optimized', 1);
        update_post_meta($attachment_id, '_wso_opt_data', $result);

        return $result;
    }
}
