<?php
/**
 * SVG to WebP/AVIF Converter
 * Rasterizes SVG files for browser compatibility.
 *
 * @package WSO\Engine
 */

namespace WSO\Engine;

if (!defined('ABSPATH')) {
    exit;
}

class SVG_Converter {

    private static ?SVG_Converter $instance = null;

    public static function instance(): SVG_Converter {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Convert SVG to WebP or AVIF.
     */
    public function convert(string $svg_path, string $target_format = 'webp', int $width = 0, int $height = 0): bool {
        if (!file_exists($svg_path)) {
            return false;
        }

        $ext = strtolower(pathinfo($svg_path, PATHINFO_EXTENSION));
        if ($ext !== 'svg') {
            return false;
        }

        $info = pathinfo($svg_path);
        $target_file = $info['dirname'] . '/' . $info['filename'] . '.' . $target_format;

        // Try Imagick first (best SVG support)
        if (extension_loaded('imagick') && class_exists('\Imagick')) {
            return $this->convert_imagick($svg_path, $target_file, $target_format, $width, $height);
        }

        // Fallback to GD (limited SVG support)
        return $this->convert_gd($svg_path, $target_file, $target_format, $width, $height);
    }

    /**
     * Convert using Imagick.
     */
    private function convert_imagick(string $svg_path, string $target_file, string $format, int $width, int $height): bool {
        try {
            $imagick = new \Imagick();
            $imagick->setBackgroundColor(new \ImagickPixel('transparent'));

            // Read SVG
            $svg_content = file_get_contents($svg_path);
            $imagick->readImageBlob($svg_content);

            // Set dimensions
            $orig_w = $imagick->getImageWidth();
            $orig_h = $imagick->getImageHeight();

            if ($width > 0 && $height > 0) {
                $imagick->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1);
            } elseif ($width > 0) {
                $imagick->resizeImage($width, 0, \Imagick::FILTER_LANCZOS, 1);
            } elseif ($height > 0) {
                $imagick->resizeImage(0, $height, \Imagick::FILTER_LANCZOS, 1);
            }

            // Set format
            $imagick->setImageFormat(strtoupper($format));
            $imagick->setImageCompressionQuality(85);

            $imagick->writeImage($target_file);
            $imagick->clear();
            $imagick->destroy();

            return file_exists($target_file) && filesize($target_file) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Convert using GD (limited SVG support).
     */
    private function convert_gd(string $svg_path, string $target_file, string $format, int $width, int $height): bool {
        if (!extension_loaded('gd')) {
            return false;
        }

        // GD has limited SVG support - try to use it
        $image = @imagecreatefromstring(file_get_contents($svg_path));
        if (!$image) {
            return false;
        }

        $orig_w = imagesx($image);
        $orig_h = imagesy($image);

        if ($width > 0 && $height > 0) {
            $new_w = $width;
            $new_h = $height;
        } elseif ($width > 0) {
            $new_w = $width;
            $new_h = (int) round($orig_h * ($width / $orig_w));
        } elseif ($height > 0) {
            $new_h = $height;
            $new_w = (int) round($orig_w * ($height / $orig_h));
        } else {
            $new_w = $orig_w;
            $new_h = $orig_h;
        }

        $resized = imagecreatetruecolor($new_w, $new_h);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefill($resized, 0, 0, $transparent);

        imagecopyresampled($resized, $image, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h);
        imagedestroy($image);

        $result = false;
        if ($format === 'webp' && function_exists('imagewebp')) {
            $result = @imagewebp($resized, $target_file, 85);
        }

        imagedestroy($resized);
        return $result && file_exists($target_file) && filesize($target_file) > 0;
    }
}
