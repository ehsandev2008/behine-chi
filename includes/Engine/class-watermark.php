<?php
/**
 * سیستم واترمارک سبک و حرفه‌ای (GD با پشتیبانی Imagick)
 * Lightweight Watermark Engine — no new dependencies.
 *
 * @package WSO\Engine
 */

namespace WSO\Engine;

use WSO\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

class Watermark {

    /**
     * Singleton instance.
     *
     * @var Watermark|null
     */
    private static ?Watermark $instance = null;

    /**
     * Post-meta key storing the applied watermark signature (prevents double-apply).
     */
    public const META_KEY = '_wso_wm_hash';

    /**
     * Allowed positions.
     *
     * @var array<string>
     */
    public const POSITIONS = [
        'top-left',
        'top-center',
        'top-right',
        'center-left',
        'center',
        'center-right',
        'bottom-left',
        'bottom-center',
        'bottom-right',
    ];

    /**
     * Returns the singleton instance.
     *
     * @return Watermark
     */
    public static function instance(): Watermark {
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
     * Checks whether watermarking is enabled and configured.
     *
     * @return bool
     */
    public function is_enabled(): bool {
        $settings = Settings::instance();
        if (!(bool) $settings->get('wso_watermark_enabled', 0)) {
            return false;
        }
        return '' !== $this->get_watermark_path();
    }

    /**
     * Resolves the absolute watermark image path from settings.
     *
     * Accepts an attachment ID (preferred, set by the media uploader) or a raw URL/path.
     *
     * @return string Absolute path or empty string.
     */
    public function get_watermark_path(): string {
        $settings = Settings::instance();
        $raw = $settings->get('wso_watermark_image', '');

        if (is_numeric($raw) && (int) $raw > 0) {
            $path = get_attached_file((int) $raw);
            return ($path && file_exists($path)) ? wp_normalize_path($path) : '';
        }

        if (is_string($raw) && '' !== trim($raw)) {
            $raw = trim($raw);
            // Allow absolute path inside uploads; reject anything outside ABSPATH.
            $norm = wp_normalize_path($raw);
            if (file_exists($norm) && is_file($norm)) {
                $real = realpath($norm);
                $check = $real ? wp_normalize_path($real) : $norm;
                if (str_starts_with($check, wp_normalize_path(ABSPATH))) {
                    return $check;
                }
            }
            // Allow site URL pointing into uploads.
            $upload_dir = wp_upload_dir();
            if (str_starts_with($norm, $upload_dir['baseurl'])) {
                $rel = ltrim(substr($norm, strlen($upload_dir['baseurl'])), '/');
                $abs = wp_normalize_path(trailingslashit($upload_dir['basedir']) . $rel);
                if (file_exists($abs) && is_file($abs)) {
                    return $abs;
                }
            }
        }

        return '';
    }

    /**
     * Builds the current watermark signature (settings fingerprint).
     *
     * Stored per attachment; when settings change, the hash changes and the
     * next optimization applies the new watermark exactly once.
     *
     * @return string
     */
    public function current_signature(): string {
        $settings = Settings::instance();
        $wm_path  = $this->get_watermark_path();
        $mtime    = ($wm_path && file_exists($wm_path)) ? (int) filemtime($wm_path) : 0;

        return md5(implode('|', [
            $wm_path,
            (string) $mtime,
            (string) $settings->get('wso_watermark_position', 'bottom-right'),
            (string) $settings->get('wso_watermark_opacity', 70),
            (string) $settings->get('wso_watermark_margin', 12),
        ]));
    }

    /**
     * Applies the watermark onto an image file in-place.
     *
     * Fail-safe: returns applied=false and leaves the file untouched on any
     * error, unsupported type, tiny image, or already-watermarked file.
     * SVG files are never watermarked.
     *
     * @param string $image_path Absolute path to target image.
     * @param int $attachment_id Optional attachment ID for double-apply guard.
     * @return array{applied: bool, reason: string}
     */
    public function apply(string $image_path, int $attachment_id = 0): array {
        if (!$this->is_enabled()) {
            return ['applied' => false, 'reason' => 'disabled'];
        }

        if (!file_exists($image_path) || !is_readable($image_path) || !is_writable($image_path)) {
            return ['applied' => false, 'reason' => 'unreadable'];
        }

        $ext = strtolower(pathinfo($image_path, PATHINFO_EXTENSION));
        if ('svg' === $ext) {
            return ['applied' => false, 'reason' => 'svg_skipped'];
        }
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'avif'], true)) {
            return ['applied' => false, 'reason' => 'unsupported'];
        }

        // Double-apply guard for media attachments.
        $signature = $this->current_signature();
        if ($attachment_id > 0) {
            $stored = get_post_meta($attachment_id, self::META_KEY, true);
            if (is_string($stored) && '' !== $stored && hash_equals($stored, $signature)) {
                return ['applied' => false, 'reason' => 'already_applied'];
            }
        }

        $wm_path = $this->get_watermark_path();
        if ('' === $wm_path) {
            return ['applied' => false, 'reason' => 'no_watermark'];
        }

        $wm_ext = strtolower(pathinfo($wm_path, PATHINFO_EXTENSION));
        if (!in_array($wm_ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
            return ['applied' => false, 'reason' => 'bad_watermark_type'];
        }

        $settings = Settings::instance();
        $position = (string) $settings->get('wso_watermark_position', 'bottom-right');
        if (!in_array($position, self::POSITIONS, true)) {
            $position = 'bottom-right';
        }
        $opacity = max(1, min(100, (int) $settings->get('wso_watermark_opacity', 70)));
        $margin  = max(0, min(200, (int) $settings->get('wso_watermark_margin', 12)));

        $ok = false;
        // Prefer Imagick when available (correct alpha dissolve); GD otherwise.
        if (extension_loaded('imagick') && class_exists('\Imagick')) {
            $ok = $this->apply_imagick($image_path, $wm_path, $position, $opacity, $margin);
        }
        if (!$ok) {
            $ok = $this->apply_gd($image_path, $wm_path, $position, $opacity, $margin);
        }

        if (!$ok) {
            return ['applied' => false, 'reason' => 'apply_failed'];
        }

        if ($attachment_id > 0) {
            update_post_meta($attachment_id, self::META_KEY, $signature);
        }

        return ['applied' => true, 'reason' => 'ok'];
    }

    /**
     * Calculates top-left coordinates for the watermark.
     *
     * @param int $base_w Base image width.
     * @param int $base_h Base image height.
     * @param int $wm_w Watermark width (after scaling).
     * @param int $wm_h Watermark height (after scaling).
     * @param string $position Position key.
     * @param int $margin Margin in px.
     * @return array{0: int, 1: int}
     */
    private function calc_position(int $base_w, int $base_h, int $wm_w, int $wm_h, string $position, int $margin): array {
        switch ($position) {
            case 'top-left':
                return [$margin, $margin];
            case 'top-center':
                return [(int) max($margin, ($base_w - $wm_w) / 2), $margin];
            case 'top-right':
                return [$base_w - $wm_w - $margin, $margin];
            case 'center-left':
                return [$margin, (int) max($margin, ($base_h - $wm_h) / 2)];
            case 'center':
                return [(int) max($margin, ($base_w - $wm_w) / 2), (int) max($margin, ($base_h - $wm_h) / 2)];
            case 'center-right':
                return [$base_w - $wm_w - $margin, (int) max($margin, ($base_h - $wm_h) / 2)];
            case 'bottom-left':
                return [$margin, $base_h - $wm_h - $margin];
            case 'bottom-center':
                return [(int) max($margin, ($base_w - $wm_w) / 2), $base_h - $wm_h - $margin];
            case 'bottom-right':
            default:
                return [$base_w - $wm_w - $margin, $base_h - $wm_h - $margin];
        }
    }

    /**
     * Scales watermark down when it exceeds 30% of the base image (aspect kept, never upscale).
     *
     * @param int $base_w Base width.
     * @param int $base_h Base height.
     * @param int $wm_w Watermark width (by reference).
     * @param int $wm_h Watermark height (by reference).
     * @return void
     */
    private function fit_watermark(int $base_w, int $base_h, int &$wm_w, int &$wm_h): void {
        $max_w = (int) floor($base_w * 0.3);
        $max_h = (int) floor($base_h * 0.3);
        if ($max_w < 1 || $max_h < 1) {
            return;
        }
        $ratio = min(1.0, $max_w / $wm_w, $max_h / $wm_h);
        if ($ratio < 1.0) {
            $wm_w = max(1, (int) floor($wm_w * $ratio));
            $wm_h = max(1, (int) floor($wm_h * $ratio));
        }
    }

    /**
     * Applies watermark via Imagick.
     *
     * @param string $image_path Target image.
     * @param string $wm_path Watermark image.
     * @param string $position Position key.
     * @param int $opacity 1-100.
     * @param int $margin Margin px.
     * @return bool
     */
    private function apply_imagick(string $image_path, string $wm_path, string $position, int $opacity, int $margin): bool {
        try {
            $base = new \Imagick($image_path);
            // Flatten multi-frame (animated webp/gif) guard: only first frame.
            if ($base->getNumberImages() > 1) {
                $base = $base->coalesceImages();
                $base->setFirstIterator();
            }
            $geo = $base->getImageGeometry();
            $base_w = (int) ($geo['width'] ?? 0);
            $base_h = (int) ($geo['height'] ?? 0);
            if ($base_w < 10 || $base_h < 10) {
                $base->clear();
                return false;
            }

            $wm = new \Imagick($wm_path);
            $wgeo = $wm->getImageGeometry();
            $wm_w = (int) ($wgeo['width'] ?? 0);
            $wm_h = (int) ($wgeo['height'] ?? 0);
            if ($wm_w < 1 || $wm_h < 1) {
                $base->clear();
                $wm->clear();
                return false;
            }

            $this->fit_watermark($base_w, $base_h, $wm_w, $wm_h);
            if ($wm_w !== (int) ($wgeo['width'] ?? 0) || $wm_h !== (int) ($wgeo['height'] ?? 0)) {
                $wm->resizeImage($wm_w, $wm_h, \Imagick::FILTER_LANCZOS, 1);
            }

            // Tiny-image guard: watermark + margins must fit.
            if ($wm_w + ($margin * 2) > $base_w || $wm_h + ($margin * 2) > $base_h) {
                $base->clear();
                $wm->clear();
                return false;
            }

            // Apply opacity to watermark alpha channel.
            // Ensure an alpha channel exists so JPEG watermarks (no alpha) also respect opacity.
            if ($opacity < 100) {
                try {
                    $wm->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);
                } catch (\Throwable $e) {}
                $wm->evaluateImage(\Imagick::EVALUATE_MULTIPLY, $opacity / 100, \Imagick::CHANNEL_ALPHA);
            }

            [$dx, $dy] = $this->calc_position($base_w, $base_h, $wm_w, $wm_h, $position, $margin);
            $dx = max(0, $dx);
            $dy = max(0, $dy);

            $base->compositeImage($wm, \Imagick::COMPOSITE_OVER, $dx, $dy);
            $format = strtolower($base->getImageFormat());
            $quality = 90;
            try {
                $quality = max(1, min(100, (int) Settings::instance()->get('wso_quality', 75)));
            } catch (\Throwable $e) {}
            if (in_array($format, ['jpeg', 'jpg'], true)) {
                $base->setImageCompressionQuality($quality);
            } elseif (in_array($format, ['webp', 'avif'], true)) {
                $base->setImageCompressionQuality($quality);
            }
            $tmp = $image_path . '.tmp';
            $res = $base->writeImage($tmp);

            $base->clear();
            $wm->clear();
            if (!$res || !file_exists($tmp) || filesize($tmp) <= 0) {
                @unlink($tmp);
                return false;
            }
            if (!@rename($tmp, $image_path)) {
                @unlink($tmp);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Loads a GD image resource by mime.
     *
     * @param string $path File path.
     * @return array{resource|null, string} [resource, mime]
     */
    private function gd_load(string $path): array {
        $info = @getimagesize($path);
        if (!$info) {
            // Try string loader for AVIF/GIF where getimagesize may fail on old PHP.
            $raw = @file_get_contents($path);
            if (false !== $raw) {
                $tmp_img = @imagecreatefromstring($raw);
                unset($raw);
                if ($tmp_img) {
                    return [$tmp_img, 'image/jpeg'];
                }
            }
            return [null, ''];
        }
        $mime = $info['mime'] ?? '';
        $img = false;
        switch ($mime) {
            case 'image/jpeg':
                $img = @imagecreatefromjpeg($path);
                break;
            case 'image/png':
                $img = @imagecreatefrompng($path);
                break;
            case 'image/webp':
                $img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false;
                break;
            case 'image/avif':
                if (function_exists('imagecreatefromavif')) {
                    $img = @imagecreatefromavif($path);
                } else {
                    $raw = @file_get_contents($path);
                    $img = (false !== $raw) ? @imagecreatefromstring($raw) : false;
                }
                break;
            case 'image/gif':
                $img = function_exists('imagecreatefromgif') ? @imagecreatefromgif($path) : false;
                break;
            default:
                $img = false;
        }
        if (!$img) {
            return [null, ''];
        }
        // Normalize mime for downstream save: AVIF/GIF bases are saved as JPEG/PNG.
        if ('image/avif' === $mime || 'image/gif' === $mime) {
            $mime = 'image/jpeg';
        }
        return [$img, $mime];
    }

    /**
     * Applies watermark via GD (no new dependencies).
     *
     * @param string $image_path Target image.
     * @param string $wm_path Watermark image.
     * @param string $position Position key.
     * @param int $opacity 1-100.
     * @param int $margin Margin px.
     * @return bool
     */
    private function apply_gd(string $image_path, string $wm_path, string $position, int $opacity, int $margin): bool {
        if (!extension_loaded('gd')) {
            return false;
        }

        [$base, $base_mime] = $this->gd_load($image_path);
        if (!$base) {
            return false;
        }
        [$wm, $wm_mime] = $this->gd_load($wm_path);
        if (!$wm) {
            imagedestroy($base);
            return false;
        }

        $base_w = imagesx($base);
        $base_h = imagesy($base);
        $wm_w   = imagesx($wm);
        $wm_h   = imagesy($wm);

        if ($base_w < 10 || $base_h < 10 || $wm_w < 1 || $wm_h < 1) {
            imagedestroy($base);
            imagedestroy($wm);
            return false;
        }

        $this->fit_watermark($base_w, $base_h, $wm_w, $wm_h);
        if ($wm_w !== imagesx($wm) || $wm_h !== imagesy($wm)) {
            $scaled = imagecreatetruecolor($wm_w, $wm_h);
            imagealphablending($scaled, false);
            imagesavealpha($scaled, true);
            $transparent = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
            imagefill($scaled, 0, 0, $transparent);
            imagecopyresampled($scaled, $wm, 0, 0, 0, 0, $wm_w, $wm_h, imagesx($wm), imagesy($wm));
            imagedestroy($wm);
            $wm = $scaled;
        }

        // Tiny-image guard.
        if ($wm_w + ($margin * 2) > $base_w || $wm_h + ($margin * 2) > $base_h) {
            imagedestroy($base);
            imagedestroy($wm);
            return false;
        }

        [$dx, $dy] = $this->calc_position($base_w, $base_h, $wm_w, $wm_h, $position, $margin);
        $dx = max(0, $dx);
        $dy = max(0, $dy);

        imagealphablending($base, true);
        if ($opacity >= 100) {
            imagesavealpha($base, 'image/png' === $base_mime || 'image/webp' === $base_mime);
            imagecopy($base, $wm, $dx, $dy, 0, 0, $wm_w, $wm_h);
        } else {
            // imagecopymerge flattens alpha but is light and predictable for opacity < 100.
            // For PNG watermarks with alpha on PNG/WebP bases, pre-blend via temporary
            // opacity-adjusted copy to keep edges acceptable.
            if (('image/png' === $wm_mime || 'image/webp' === $wm_mime)
                && ('image/png' === $base_mime || 'image/webp' === $base_mime)) {
                $this->gd_adjust_alpha($wm, $opacity);
                imagesavealpha($base, true);
                imagecopy($base, $wm, $dx, $dy, 0, 0, $wm_w, $wm_h);
            } else {
                imagecopymerge($base, $wm, $dx, $dy, 0, 0, $wm_w, $wm_h, $opacity);
            }
        }

        $saved = false;
        $quality = 75;
        try {
            $quality = max(1, min(100, (int) Settings::instance()->get('wso_quality', 75)));
        } catch (\Throwable $e) {}
        $tmp = $image_path . '.tmp';
        if ('image/jpeg' === $base_mime) {
            $saved = @imagejpeg($base, $tmp, $quality);
        } elseif ('image/png' === $base_mime) {
            $saved = @imagepng($base, $tmp, 6);
        } elseif ('image/webp' === $base_mime) {
            $saved = function_exists('imagewebp') ? @imagewebp($base, $tmp, $quality) : false;
        } else {
            $saved = false;
        }

        imagedestroy($base);
        imagedestroy($wm);

        if (!$saved || !file_exists($tmp) || filesize($tmp) <= 0) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $image_path)) {
            @unlink($tmp);
            return false;
        }
        return file_exists($image_path) && filesize($image_path) > 0;
    }

    /**
     * Adjusts per-pixel alpha of a truecolor GD image toward a global opacity.
     * Lightweight single pass; only used for PNG/WebP-on-PNG/WebP path.
     *
     * @param mixed $img GD image resource.
     * @param int $opacity 1-100.
     * @return void
     */
    private function gd_adjust_alpha($img, int $opacity): void {
        $w = imagesx($img);
        $h = imagesy($img);
        // Cap work: skip adjustment on very large watermarks (already fitted to 30%).
        if ($w * $h > 2000000) {
            return;
        }
        $factor = $opacity / 100;
        imagealphablending($img, false);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($img, $x, $y);
                $a = ($rgba >> 24) & 0x7F;
                // Blend existing alpha toward transparent by (1 - factor).
                $new_a = (int) min(127, $a + (127 - $a) * (1 - $factor));
                if ($new_a !== $a) {
                    $r = ($rgba >> 16) & 0xFF;
                    $g = ($rgba >> 8) & 0xFF;
                    $b = $rgba & 0xFF;
                    $c = imagecolorallocatealpha($img, $r, $g, $b, $new_a);
                    if (false !== $c) {
                        imagesetpixel($img, $x, $y, $c);
                    }
                }
            }
        }
        imagealphablending($img, true);
    }
}
