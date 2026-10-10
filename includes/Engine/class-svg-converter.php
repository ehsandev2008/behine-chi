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
        $format = strtolower($format);
        if (!in_array($format, ['webp', 'avif'], true)) {
            return false;
        }
        try {
            // Validate SVG first to block XXE/SSRF payloads.
            $raw_check = @file_get_contents($svg_path);
            if (false === $raw_check || !SVG_Optimizer::instance()->is_valid_svg($raw_check)) {
                return false;
            }
            unset($raw_check);
            if (filesize($svg_path) > 5 * 1024 * 1024) {
                return false;
            }
            $imagick = new \Imagick();
            try {
                $imagick->setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
                $imagick->setResourceLimit(\Imagick::RESOURCETYPE_AREA, 25000000);
            } catch (\Throwable $e) {}
            $imagick->setBackgroundColor(new \ImagickPixel('transparent'));
            // Cap raster resolution so huge SVGs do not OOM.
            try {
                $imagick->setResolution(96, 96);
            } catch (\Throwable $e) {}

            // Read SVG
            $svg_content = @file_get_contents($svg_path);
            if (false === $svg_content) {
                $imagick->clear();
                $imagick->destroy();
                return false;
            }
            $imagick->readImageBlob($svg_content);
            unset($svg_content);

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
     * GD cannot rasterize SVG; this always fails safely without warnings.
     */
    private function convert_gd(string $svg_path, string $target_file, string $format, int $width, int $height): bool {
        // GD has no SVG rasterizer. Do not attempt imagecreatefromstring on XML
        // (it only wastes memory and logs warnings). Imagick path above is the
        // only supported route; return false so callers fall back cleanly.
        return false;
    }
}
