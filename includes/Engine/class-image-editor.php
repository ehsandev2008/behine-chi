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

        $dst = imagecreatetruecolor($width, $height);
        imagecopyresampled($dst, $src, 0, 0, $x, $y, $width, $height, $width, $height);
        imagedestroy($src);

        $result = @imagejpeg($dst, $file_path, 90);
        imagedestroy($dst);
        return $result;
    }

    private function crop_png(string $file_path, int $x, int $y, int $width, int $height): bool {
        $src = @imagecreatefrompng($file_path);
        if (!$src) {
            return false;
        }

        $dst = imagecreatetruecolor($width, $height);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, $x, $y, $width, $height, $width, $height);
        imagedestroy($src);

        $result = @imagepng($dst, $file_path, 6);
        imagedestroy($dst);
        return $result;
    }

    private function crop_webp(string $file_path, int $x, int $y, int $width, int $height): bool {
        if (!function_exists('imagecreatefromwebp')) {
            return false;
        }

        $src = @imagecreatefromwebp($file_path);
        if (!$src) {
            return false;
        }

        $dst = imagecreatetruecolor($width, $height);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, $x, $y, $width, $height, $width, $height);
        imagedestroy($src);

        $result = @imagewebp($dst, $file_path, 90);
        imagedestroy($dst);
        return $result;
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

        $result = @imagejpeg($rotated, $file_path, 90);
        imagedestroy($rotated);
        return $result;
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

        $result = @imagepng($rotated, $file_path, 6);
        imagedestroy($rotated);
        return $result;
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

        $result = @imagewebp($rotated, $file_path, 90);
        imagedestroy($rotated);
        return $result;
    }

    private function flip_jpeg(string $file_path, string $direction): bool {
        $src = @imagecreatefromjpeg($file_path);
        if (!$src) {
            return false;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $dst = imagecreatetruecolor($w, $h);

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
        $result = @imagejpeg($dst, $file_path, 90);
        imagedestroy($dst);
        return $result;
    }

    private function flip_png(string $file_path, string $direction): bool {
        $src = @imagecreatefrompng($file_path);
        if (!$src) {
            return false;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $dst = imagecreatetruecolor($w, $h);
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
        $result = @imagepng($dst, $file_path, 6);
        imagedestroy($dst);
        return $result;
    }

    private function flip_webp(string $file_path, string $direction): bool {
        if (!function_exists('imagecreatefromwebp')) {
            return false;
        }

        $src = @imagecreatefromwebp($file_path);
        if (!$src) {
            return false;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $dst = imagecreatetruecolor($w, $h);
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
        $result = @imagewebp($dst, $file_path, 90);
        imagedestroy($dst);
        return $result;
    }
}
