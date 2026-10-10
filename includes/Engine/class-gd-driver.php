<?php
/**
 * GD Image Processing Driver
 *
 * @package WSO\Engine
 */

namespace WSO\Engine;

if (!defined('ABSPATH')) {
    exit;
}

class GD_Driver implements Image_Driver {

    /**
     * Checks if GD extension is loaded.
     *
     * @return bool
     */
    public function is_available(): bool {
        return extension_loaded('gd') && function_exists('gd_info');
    }

    /**
     * Checks if GD supports WebP.
     *
     * @return bool
     */
    public function supports_webp(): bool {
        if (!$this->is_available() || !function_exists('imagewebp')) {
            return false;
        }
        if (function_exists('gd_info')) {
            $info = gd_info();
            if (isset($info['WebP Support'])) {
                return (bool) $info['WebP Support'];
            }
        }
        return true;
    }

    /**
     * Checks if GD supports AVIF.
     *
     * @return bool
     */
    public function supports_avif(): bool {
        if (!$this->is_available() || !function_exists('imageavif')) {
            return false;
        }
        if (function_exists('gd_info')) {
            $info = gd_info();
            if (isset($info['AVIF Support'])) {
                return (bool) $info['AVIF Support'];
            }
        }
        return true;
    }

    /**
     * Converts source image to WebP or AVIF format.
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
        // Never overwrite source in-place; caller must use temp+rename.
        if (wp_normalize_path($source_file) === wp_normalize_path($target_file)) {
            return false;
        }

        $image_info = @getimagesize($source_file);
        if (!$image_info) {
            // Fallback for AVIF/other via string loader.
            $raw = @file_get_contents($source_file);
            if (false === $raw) {
                return false;
            }
            $image = @imagecreatefromstring($raw);
            unset($raw);
            if (!$image) {
                return false;
            }
            $source_mime = 'image/jpeg';
        } else {
            $source_mime = $image_info['mime'] ?? '';
            $image = $this->load_source($source_file, $source_mime);
        }

        if (!$image) {
            return false;
        }

        // Handle PNG transparency
        if ($source_mime === 'image/png') {
            if (function_exists('imagepalettetotruecolor')) {
                @imagepalettetotruecolor($image);
            }
            @imagealphablending($image, true);
            @imagesavealpha($image, true);
        }

        $success = false;
        $tmp = $target_file . '.tmp';
        if ($mime_type === 'image/webp' && $this->supports_webp()) {
            $success = @imagewebp($image, $tmp, $quality);
        } elseif ($mime_type === 'image/avif' && $this->supports_avif()) {
            // AVIF at same quality is much smaller; cap speed cost by slight quality trim.
            $avif_q = max(1, min(100, $quality));
            $success = @imageavif($image, $tmp, $avif_q);
        } elseif ($mime_type === 'image/jpeg') {
            $bg = imagecreatetruecolor(imagesx($image), imagesy($image));
            if ($bg) {
                $white = imagecolorallocate($bg, 255, 255, 255);
                imagefilledrectangle($bg, 0, 0, imagesx($image), imagesy($image), $white);
                imagecopy($bg, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
                imagedestroy($image);
                $image = $bg;
            }
            $success = @imagejpeg($image, $tmp, $quality);
        } elseif ($mime_type === 'image/png') {
            $success = @imagepng($image, $tmp, 6);
        }

        imagedestroy($image);
        if (!$success || !file_exists($tmp) || filesize($tmp) <= 0) {
            @unlink($tmp);
            return false;
        }
        // Atomic replace.
        if (!@rename($tmp, $target_file)) {
            @unlink($tmp);
            return false;
        }
        return file_exists($target_file) && filesize($target_file) > 0;
    }

    /**
     * Loads a GD source image by mime with string fallback.
     *
     * @param string $file File path.
     * @param string $mime Source mime.
     * @return mixed GD image resource or false.
     */
    private function load_source(string $file, string $mime) {
        $img = false;
        switch ($mime) {
            case 'image/jpeg':
                $img = @imagecreatefromjpeg($file);
                break;
            case 'image/png':
                $img = @imagecreatefrompng($file);
                break;
            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    $img = @imagecreatefromwebp($file);
                }
                break;
            case 'image/avif':
                if (function_exists('imagecreatefromavif')) {
                    $img = @imagecreatefromavif($file);
                }
                break;
            case 'image/gif':
                if (function_exists('imagecreatefromgif')) {
                    $img = @imagecreatefromgif($file);
                }
                break;
        }
        if (!$img) {
            $raw = @file_get_contents($file);
            if (false !== $raw) {
                $img = @imagecreatefromstring($raw);
            }
        }
        return $img;
    }

    /**
     * Resizes image down if dimensions exceed bounds.
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
        // Guard against decompression bombs (50MP cap).
        $info = @getimagesize($file);
        if (!$info) {
            return false;
        }

        $orig_w = $info[0];
        $orig_h = $info[1];
        $mime   = $info['mime'] ?? '';
        if ($orig_w <= 0 || $orig_h <= 0 || ($orig_w * $orig_h) > 50000000) {
            return false;
        }

        if ($orig_w <= $max_width && $orig_h <= $max_height) {
            return false;
        }

        // Calculate aspect ratio aspect
        $ratio = min($max_width / $orig_w, $max_height / $orig_h);
        $new_w = max(1, (int) round($orig_w * $ratio));
        $new_h = max(1, (int) round($orig_h * $ratio));

        $src_img = $this->load_source($file, $mime);

        if (!$src_img) {
            return false;
        }

        $dst_img = @imagecreatetruecolor($new_w, $new_h);
        if (!$dst_img) {
            imagedestroy($src_img);
            return false;
        }
        if ($mime === 'image/png' || $mime === 'image/webp') {
            imagealphablending($dst_img, false);
            imagesavealpha($dst_img, true);
        }

        imagecopyresampled($dst_img, $src_img, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h);
        imagedestroy($src_img);

        // Atomic write via temp file to avoid serving half-written images.
        $tmp = $file . '.tmp';
        $saved = false;
        if ($mime === 'image/jpeg') {
            $saved = @imagejpeg($dst_img, $tmp, $quality);
        } elseif ($mime === 'image/png') {
            $saved = @imagepng($dst_img, $tmp, 6);
        } elseif ($mime === 'image/webp') {
            $saved = function_exists('imagewebp') ? @imagewebp($dst_img, $tmp, $quality) : false;
        } else {
            imagedestroy($dst_img);
            return false;
        }

        imagedestroy($dst_img);
        if (!$saved || !file_exists($tmp) || filesize($tmp) <= 0) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Recompresses the original file in-place to the target quality (JPEG/WebP).
     * PNG is re-encoded at level 6 for size without extreme CPU cost.
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
        $info = @getimagesize($file);
        if (!$info) {
            return false;
        }
        $mime = $info['mime'] ?? '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return false;
        }
        if (($info[0] * $info[1]) > 50000000) {
            return false;
        }
        $src = $this->load_source($file, $mime);
        if (!$src) {
            return false;
        }
        if ($mime === 'image/png') {
            @imagealphablending($src, true);
            @imagesavealpha($src, true);
        }
        $tmp = $file . '.tmp';
        $saved = false;
        if ($mime === 'image/jpeg') {
            $saved = @imagejpeg($src, $tmp, $quality);
        } elseif ($mime === 'image/webp') {
            $saved = function_exists('imagewebp') ? @imagewebp($src, $tmp, $quality) : false;
        } else {
            $saved = @imagepng($src, $tmp, 6);
        }
        imagedestroy($src);
        if (!$saved || !file_exists($tmp)) {
            @unlink($tmp);
            return false;
        }
        // Only keep recompressed file if it is actually smaller.
        $old_size = filesize($file);
        $new_size = filesize($tmp);
        if ($new_size <= 0 || $new_size >= $old_size) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Strips EXIF metadata by re-encoding.
     *
     * @param string $file
     * @param int $quality Quality for re-encoding (respects wso_quality).
     * @return bool
     */
    public function strip_exif(string $file, int $quality = 82): bool {
        $quality = max(1, min(100, $quality));
        if (!file_exists($file) || !$this->is_available()) {
            return false;
        }

        $info = @getimagesize($file);
        if (!$info || $info['mime'] !== 'image/jpeg') {
            return false;
        }

        $img = @imagecreatefromjpeg($file);
        if (!$img) {
            return false;
        }

        $tmp = $file . '.tmp';
        $res = @imagejpeg($img, $tmp, $quality);
        imagedestroy($img);
        if (!$res || !file_exists($tmp) || filesize($tmp) <= 0) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }
}
