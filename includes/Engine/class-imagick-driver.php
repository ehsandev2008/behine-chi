<?php
/**
 * Imagick Image Processing Driver
 *
 * @package WSO\Engine
 */

namespace WSO\Engine;

if (!defined('ABSPATH')) {
    exit;
}

class Imagick_Driver implements Image_Driver {

    /**
     * Checks if Imagick extension is loaded.
     *
     * @return bool
     */
    public function is_available(): bool {
        return extension_loaded('imagick') && class_exists('\Imagick');
    }

    /**
     * Checks if Imagick supports WebP.
     *
     * @return bool
     */
    public function supports_webp(): bool {
        if (!$this->is_available()) {
            return false;
        }
        $formats = \Imagick::queryFormats('WEBP');
        return !empty($formats);
    }

    /**
     * Checks if Imagick supports AVIF.
     *
     * @return bool
     */
    public function supports_avif(): bool {
        if (!$this->is_available()) {
            return false;
        }
        $formats = \Imagick::queryFormats('AVIF');
        return !empty($formats);
    }

    /**
     * Converts image using Imagick.
     *
     * @param string $source_file
     * @param string $target_file
     * @param string $mime_type
     * @param int $quality
     * @return bool
     */
    public function convert(string $source_file, string $target_file, string $mime_type, int $quality = 82): bool {
        $quality = max(1, min(100, $quality));
        if (!file_exists($source_file) || !$this->is_available()) {
            return false;
        }
        if (wp_normalize_path($source_file) === wp_normalize_path($target_file)) {
            return false;
        }

        $imagick = null;
        try {
            $imagick = new \Imagick();
            // Bound memory/disk so AVIF/WebP on shared hosts cannot OOM.
            try {
                $imagick->setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
                $imagick->setResourceLimit(\Imagick::RESOURCETYPE_MAP, 256 * 1024 * 1024);
                $imagick->setResourceLimit(\Imagick::RESOURCETYPE_DISK, 512 * 1024 * 1024);
                $imagick->setResourceLimit(\Imagick::RESOURCETYPE_AREA, 50000000);
            } catch (\Throwable $e) {}
            $imagick->readImage($source_file);
            // Flatten animation to first frame to avoid multi-MB outputs.
            try {
                if (method_exists($imagick, 'getNumberImages') && $imagick->getNumberImages() > 1) {
                    $imagick = $imagick->coalesceImages();
                    $imagick->setFirstIterator();
                }
            } catch (\Throwable $e) {}

            $format = null;
            if ($mime_type === 'image/webp') {
                $format = 'WEBP';
            } elseif ($mime_type === 'image/avif') {
                $format = 'AVIF';
            } elseif ($mime_type === 'image/jpeg') {
                $format = 'JPEG';
            } elseif ($mime_type === 'image/png') {
                $format = 'PNG';
            }

            if (!$format) {
                $imagick->clear();
                $imagick->destroy();
                return false;
            }

            $imagick->setImageFormat($format);
            $imagick->setImageCompressionQuality($quality);

            if ($format === 'WEBP') {
                // method 4 = balanced speed/size (method 6 timeouts on shared hosts).
                $imagick->setOption('webp:method', '4');
                $imagick->setOption('webp:lossless', 'false');
                $imagick->setOption('webp:image-hint', 'photo');
            } elseif ($format === 'JPEG') {
                $imagick->setInterlaceScheme(\Imagick::INTERLACE_PLANE);
                $imagick->stripImage();
            } elseif ($format === 'PNG') {
                try {
                    $imagick->setOption('png:compression-level', '6');
                } catch (\Throwable $e) {}
            }

            $tmp = $target_file . '.tmp';
            $written = $imagick->writeImage($tmp);
            $imagick->clear();
            $imagick->destroy();

            if (!$written || !file_exists($tmp) || filesize($tmp) <= 0) {
                @unlink($tmp);
                return false;
            }
            if (!@rename($tmp, $target_file)) {
                @unlink($tmp);
                return false;
            }
            return file_exists($target_file) && filesize($target_file) > 0;
        } catch (\Throwable $e) {
            if ($imagick instanceof \Imagick) {
                try { $imagick->clear(); $imagick->destroy(); } catch (\Throwable $ignored) {}
            }
            if (isset($tmp)) {
                @unlink($tmp);
            }
            return false;
        }
    }

    /**
     * Resizes image down using Imagick.
     *
     * @param string $file
     * @param int $max_width
     * @param int $max_height
     * @param int $quality
     * @return bool
     */
    public function resize(string $file, int $max_width, int $max_height, int $quality = 82): bool {
        $quality = max(1, min(100, $quality));
        if (!file_exists($file) || !$this->is_available() || $max_width <= 0 || $max_height <= 0) {
            return false;
        }

        $imagick = null;
        try {
            $imagick = new \Imagick();
            try {
                $imagick->setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
                $imagick->setResourceLimit(\Imagick::RESOURCETYPE_AREA, 50000000);
            } catch (\Throwable $e) {}
            $imagick->readImage($file);
            $geo = $imagick->getImageGeometry();
            $orig_w = $geo['width'] ?? 0;
            $orig_h = $geo['height'] ?? 0;

            if ($orig_w <= $max_width && $orig_h <= $max_height) {
                $imagick->clear();
                $imagick->destroy();
                return false;
            }
            if ($orig_w <= 0 || $orig_h <= 0 || ($orig_w * $orig_h) > 50000000) {
                $imagick->clear();
                $imagick->destroy();
                return false;
            }

            $imagick->resizeImage($max_width, $max_height, \Imagick::FILTER_LANCZOS, 1, true);
            $imagick->setImageCompressionQuality($quality);
            $tmp = $file . '.tmp';
            $res = $imagick->writeImage($tmp);

            $imagick->clear();
            $imagick->destroy();

            if (!$res || !file_exists($tmp) || filesize($tmp) <= 0) {
                @unlink($tmp);
                return false;
            }
            if (!@rename($tmp, $file)) {
                @unlink($tmp);
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            if ($imagick instanceof \Imagick) {
                try { $imagick->clear(); $imagick->destroy(); } catch (\Throwable $ignored) {}
            }
            if (isset($tmp)) {
                @unlink($tmp);
            }
            return false;
        }
    }

    /**
     * Recompresses original in-place when it saves bytes (JPEG/WebP/PNG).
     *
     * @param string $file File path.
     * @param int $quality Quality 1-100.
     * @return bool
     */
    public function recompress(string $file, int $quality = 82): bool {
        $quality = max(1, min(100, $quality));
        if (!file_exists($file) || !$this->is_available()) {
            return false;
        }
        $imagick = null;
        try {
            $imagick = new \Imagick();
            try {
                $imagick->setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
                $imagick->setResourceLimit(\Imagick::RESOURCETYPE_AREA, 50000000);
            } catch (\Throwable $e) {}
            $imagick->readImage($file);
            $geo = $imagick->getImageGeometry();
            if ((($geo['width'] ?? 0) * ($geo['height'] ?? 0)) > 50000000) {
                $imagick->clear();
                $imagick->destroy();
                return false;
            }
            $format = strtoupper($imagick->getImageFormat());
            if (!in_array($format, ['JPEG', 'JPG', 'WEBP', 'PNG'], true)) {
                $imagick->clear();
                $imagick->destroy();
                return false;
            }
            $imagick->setImageCompressionQuality($quality);
            if ($format === 'WEBP') {
                $imagick->setOption('webp:method', '4');
                $imagick->setOption('webp:lossless', 'false');
            }
            $tmp = $file . '.tmp';
            $res = $imagick->writeImage($tmp);
            $imagick->clear();
            $imagick->destroy();
            if (!$res || !file_exists($tmp)) {
                @unlink($tmp);
                return false;
            }
            $old = filesize($file);
            $new = filesize($tmp);
            if ($new <= 0 || $new >= $old) {
                @unlink($tmp);
                return false;
            }
            if (!@rename($tmp, $file)) {
                @unlink($tmp);
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            if ($imagick instanceof \Imagick) {
                try { $imagick->clear(); $imagick->destroy(); } catch (\Throwable $ignored) {}
            }
            if (isset($tmp)) {
                @unlink($tmp);
            }
            return false;
        }
    }

    /**
     * Strips EXIF metadata using Imagick.
     *
     * @param string $file
     * @param int $quality Quality preserved on rewrite.
     * @return bool
     */
    public function strip_exif(string $file, int $quality = 82): bool {
        $quality = max(1, min(100, $quality));
        if (!file_exists($file) || !$this->is_available()) {
            return false;
        }

        $imagick = null;
        try {
            $imagick = new \Imagick($file);
            // Preserve ICC color profile so colors do not shift.
            $icc = null;
            try {
                $icc = $imagick->getImageProfiles('icc', true);
            } catch (\Throwable $e) {}
            $imagick->stripImage();
            if (!empty($icc['icc'])) {
                try {
                    $imagick->profileImage('icc', $icc['icc']);
                } catch (\Throwable $e) {}
            }
            $imagick->setImageCompressionQuality($quality);
            $tmp = $file . '.tmp';
            $res = $imagick->writeImage($tmp);

            $imagick->clear();
            $imagick->destroy();

            if (!$res || !file_exists($tmp) || filesize($tmp) <= 0) {
                @unlink($tmp);
                return false;
            }
            if (!@rename($tmp, $file)) {
                @unlink($tmp);
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            if ($imagick instanceof \Imagick) {
                try { $imagick->clear(); $imagick->destroy(); } catch (\Throwable $ignored) {}
            }
            if (isset($tmp)) {
                @unlink($tmp);
            }
            return false;
        }
    }
}
