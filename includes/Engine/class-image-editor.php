<?php
/**
 * In-place Image Editor
 * Crop, rotate, and flip images before optimization.
 *
 * @package WSO\Engine
 */

namespace WSO\Engine;

if (!defined('ABSPATH')) {
    exit;
}

class Image_Editor {

    private static ?Image_Editor $instance = null;

    public static function instance(): Image_Editor {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Crop image to specified dimensions.
     */
    public function crop(string $file_path, int $x, int $y, int $width, int $height): bool {
        if (!file_exists($file_path)) {
            return false;
        }
        if ($width <= 0 || $height <= 0 || $width > 8000 || $height > 8000 || $x < 0 || $y < 0) {
            return false;
        }
        $size = @getimagesize($file_path);
        if (!$size || ($x + $width) > $size[0] || ($y + $height) > $size[1]) {
            return false;
        }
        // Backup before destructive edit so restore remains possible.
        try {
            Backup_Manager::instance()->backup($file_path);
        } catch (\Throwable $e) {}

        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

        if (in_array($ext, ['jpg', 'jpeg'], true)) {
            return $this->crop_jpeg($file_path, $x, $y, $width, $height);
        }

        if ($ext === 'png') {
            return $this->crop_png($file_path, $x, $y, $width, $height);
        }

        if ($ext === 'webp') {
            return $this->crop_webp($file_path, $x, $y, $width, $height);
        }

        return false;
    }

    /**
     * Rotate image by degrees.
     */
    public function rotate(string $file_path, int $degrees): bool {
        if (!file_exists($file_path)) {
            return false;
        }
        $degrees = $degrees % 360;
        try {
            Backup_Manager::instance()->backup($file_path);
        } catch (\Throwable $e) {}

        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

        if (in_array($ext, ['jpg', 'jpeg'], true)) {
            return $this->rotate_jpeg($file_path, $degrees);
        }

        if ($ext === 'png') {
            return $this->rotate_png($file_path, $degrees);
        }

        if ($ext === 'webp') {
            return $this->rotate_webp($file_path, $degrees);
        }

        return false;
    }

    /**
     * Flip image horizontally.
     */
    public function flip_horizontal(string $file_path): bool {
        if (!file_exists($file_path)) {
            return false;
        }

        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

        if (in_array($ext, ['jpg', 'jpeg'], true)) {
            return $this->flip_jpeg($file_path, 'horizontal');
        }

        if ($ext === 'png') {
            return $this->flip_png($file_path, 'horizontal');
        }

        if ($ext === 'webp') {
            return $this->flip_webp($file_path, 'horizontal');
        }

        return false;
    }

    /**
     * Flip image vertically.
     */
    public function flip_vertical(string $file_path): bool {
        if (!file_exists($file_path)) {
            return false;
        }

        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

        if (in_array($ext, ['jpg', 'jpeg'], true)) {
            return $this->flip_jpeg($file_path, 'vertical');
        }

        if ($ext === 'png') {
            return $this->flip_png($file_path, 'vertical');
        }

        if ($ext === 'webp') {
            return $this->flip_webp($file_path, 'vertical');
        }

        return false;
    }

    private function crop_jpeg(string $file_path, int $x, int $y, int $width, int $height): bool {
        $src = @imagecreatefromjpeg($file_path);
        if (!$src) {
            return false;
        }

        $dst = @imagecreatetruecolor($width, $height);
        if (!$dst) {
            imagedestroy($src);
            return false;
        }
        imagecopyresampled($dst, $src, 0, 0, $x, $y, $width, $height, $width, $height);
        imagedestroy($src);

        $quality = $this->current_quality();
        $tmp = $file_path . '.tmp';
        $result = @imagejpeg($dst, $tmp, $quality);
        imagedestroy($dst);
        if (!$result || !file_exists($tmp) || filesize($tmp) <= 0) {
            @unlink($tmp);
            return false;
        }
        return (bool) @rename($tmp, $file_path);
    }

    /**
     * Current quality from settings (respects wso_quality).
     *
     * @return int
     */
    private function current_quality(): int {
        try {
            return max(1, min(100, (int) \WSO\Core\Settings::instance()->get('wso_quality', 75)));
        } catch (\Throwable $e) {
            return 75;
        }
    }

    private function crop_png(string $file_path, int $x, int $y, int $width, int $height): bool {
        $src = @imagecreatefrompng($file_path);
        if (!$src) {
            return false;
        }

        $dst = @imagecreatetruecolor($width, $height);
        if (!$dst) {
            imagedestroy($src);
            return false;
        }
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, $x, $y, $width, $height, $width, $height);
        imagedestroy($src);

        $tmp = $file_path . '.tmp';
        $result = @imagepng($dst, $tmp, 6);
        imagedestroy($dst);
        if (!$result || !file_exists($tmp) || filesize($tmp) <= 0) {
            @unlink($tmp);
            return false;
        }
        return (bool) @rename($tmp, $file_path);
    }

    private function crop_webp(string $file_path, int $x, int $y, int $width, int $height): bool {
        if (!function_exists('imagecreatefromwebp')) {
            return false;
        }

        $src = @imagecreatefromwebp($file_path);
        if (!$src) {
            return false;
        }

        $dst = @imagecreatetruecolor($width, $height);
        if (!$dst) {
            imagedestroy($src);
            return false;
        }
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, $x, $y, $width, $height, $width, $height);
        imagedestroy($src);

        $tmp = $file_path . '.tmp';
        $result = @imagewebp($dst, $tmp, $this->current_quality());
        imagedestroy($dst);
        if (!$result || !file_exists($tmp) || filesize($tmp) <= 0) {
            @unlink($tmp);
            return false;
        }
        return (bool) @rename($tmp, $file_path);
    }

    private function rotate_jpeg(string $file_path, int $degrees): bool {
        $src = @imagecreatefromjpeg($file_path);
        if (!$src) {
            return false;
        }

        $rotated = imagerotate($src, -$degrees, 0);
        imagedestroy($src);

        if (!$rotated) {
            return false;
        }

        $tmp = $file_path . '.tmp';
        $result = @imagejpeg($rotated, $tmp, $this->current_quality());
        imagedestroy($rotated);
        if (!$result || !file_exists($tmp) || filesize($tmp) <= 0) {
            @unlink($tmp);
            return false;
        }
        return (bool) @rename($tmp, $file_path);
    }

    private function rotate_png(string $file_path, int $degrees): bool {
        $src = @imagecreatefrompng($file_path);
        if (!$src) {
            return false;
        }

        $transparent = imagecolorallocatealpha($src, 0, 0, 0, 127);
        $rotated = imagerotate($src, -$degrees, $transparent);
        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);
        imagedestroy($src);

        if (!$rotated) {
            return false;
        }

        $tmp = $file_path . '.tmp';
        $result = @imagepng($rotated, $tmp, 6);
        imagedestroy($rotated);
        if (!$result || !file_exists($tmp) || filesize($tmp) <= 0) {
            @unlink($tmp);
            return false;
        }
        return (bool) @rename($tmp, $file_path);
    }

    private function rotate_webp(string $file_path, int $degrees): bool {
        if (!function_exists('imagecreatefromwebp')) {
            return false;
        }

        $src = @imagecreatefromwebp($file_path);
        if (!$src) {
            return false;
        }

        $transparent = imagecolorallocatealpha($src, 0, 0, 0, 127);
        $rotated = imagerotate($src, -$degrees, $transparent);
        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);
        imagedestroy($src);

        if (!$rotated) {
            return false;
        }

        $tmp = $file_path . '.tmp';
        $result = @imagewebp($rotated, $tmp, $this->current_quality());
        imagedestroy($rotated);
        if (!$result || !file_exists($tmp) || filesize($tmp) <= 0) {
            @unlink($tmp);
            return false;
        }
        return (bool) @rename($tmp, $file_path);
    }

    /**
     * Fast flip helper using imageflip() when available.
     */
    private function fast_flip($src, string $direction): bool {
        if (function_exists('imageflip')) {
            $mode = ('horizontal' === $direction) ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL;
            return (bool) @imageflip($src, $mode);
        }
        return false;
    }

    private function flip_jpeg(string $file_path, string $direction): bool {
        $src = @imagecreatefromjpeg($file_path);
        if (!$src) {
            return false;
        }

        if ($this->fast_flip($src, $direction)) {
            $tmp = $file_path . '.tmp';
            $result = @imagejpeg($src, $tmp, $this->current_quality());
            imagedestroy($src);
            if (!$result || !file_exists($tmp) || filesize($tmp) <= 0) {
                @unlink($tmp);
                return false;
            }
            return (bool) @rename($tmp, $file_path);
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $dst = @imagecreatetruecolor($w, $h);
        if (!$dst) {
            imagedestroy($src);
            return false;
        }

        if ($direction === 'horizontal') {
            for ($x = 0; $x < $w; $x++) {
                imagecopy($dst, $src, $w - $x - 1, 0, $x, 0, 1, $h);
            }
        } else {
            for ($y = 0; $y < $h; $y++) {
                imagecopy($dst, $src, 0, $h - $y - 1, 0, $y, $w, 1);
            }
        }

        imagedestroy($src);
        $tmp = $file_path . '.tmp';
        $result = @imagejpeg($dst, $tmp, $this->current_quality());
        imagedestroy($dst);
        if (!$result || !file_exists($tmp) || filesize($tmp) <= 0) {
            @unlink($tmp);
            return false;
        }
        return (bool) @rename($tmp, $file_path);
    }

    private function flip_png(string $file_path, string $direction): bool {
        $src = @imagecreatefrompng($file_path);
        if (!$src) {
            return false;
        }

        if ($this->fast_flip($src, $direction)) {
            @imagealphablending($src, false);
            @imagesavealpha($src, true);
            $tmp = $file_path . '.tmp';
            $result = @imagepng($src, $tmp, 6);
            imagedestroy($src);
            if (!$result || !file_exists($tmp) || filesize($tmp) <= 0) {
                @unlink($tmp);
                return false;
            }
            return (bool) @rename($tmp, $file_path);
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $dst = @imagecreatetruecolor($w, $h);
        if (!$dst) {
            imagedestroy($src);
            return false;
        }
        imagealphablending($dst, false);
        imagesavealpha($dst, true);

        if ($direction === 'horizontal') {
            for ($x = 0; $x < $w; $x++) {
                imagecopy($dst, $src, $w - $x - 1, 0, $x, 0, 1, $h);
            }
        } else {
            for ($y = 0; $y < $h; $y++) {
                imagecopy($dst, $src, 0, $h - $y - 1, 0, $y, $w, 1);
            }
        }

        imagedestroy($src);
        $tmp = $file_path . '.tmp';
        $result = @imagepng($dst, $tmp, 6);
        imagedestroy($dst);
        if (!$result || !file_exists($tmp) || filesize($tmp) <= 0) {
            @unlink($tmp);
            return false;
        }
        return (bool) @rename($tmp, $file_path);
    }

    private function flip_webp(string $file_path, string $direction): bool {
        if (!function_exists('imagecreatefromwebp')) {
            return false;
        }

        $src = @imagecreatefromwebp($file_path);
        if (!$src) {
            return false;
        }

        if ($this->fast_flip($src, $direction)) {
            @imagealphablending($src, false);
            @imagesavealpha($src, true);
            $tmp = $file_path . '.tmp';
            $result = @imagewebp($src, $tmp, $this->current_quality());
            imagedestroy($src);
            if (!$result || !file_exists($tmp) || filesize($tmp) <= 0) {
                @unlink($tmp);
                return false;
            }
            return (bool) @rename($tmp, $file_path);
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $dst = @imagecreatetruecolor($w, $h);
        if (!$dst) {
            imagedestroy($src);
            return false;
        }
        imagealphablending($dst, false);
        imagesavealpha($dst, true);

        if ($direction === 'horizontal') {
            for ($x = 0; $x < $w; $x++) {
                imagecopy($dst, $src, $w - $x - 1, 0, $x, 0, 1, $h);
            }
        } else {
            for ($y = 0; $y < $h; $y++) {
                imagecopy($dst, $src, 0, $h - $y - 1, 0, $y, $w, 1);
            }
        }

        imagedestroy($src);
        $tmp = $file_path . '.tmp';
        $result = @imagewebp($dst, $tmp, $this->current_quality());
        imagedestroy($dst);
        if (!$result || !file_exists($tmp) || filesize($tmp) <= 0) {
            @unlink($tmp);
            return false;
        }
        return (bool) @rename($tmp, $file_path);
    }
}
