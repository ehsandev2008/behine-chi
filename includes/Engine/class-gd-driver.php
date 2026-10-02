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
        return $this->is_available() && function_exists('imagewebp');
    }

    /**
     * Checks if GD supports AVIF.
     *
     * @return bool
     */
    public function supports_avif(): bool {
        return $this->is_available() && function_exists('imageavif');
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
        if (!file_exists($source_file) || !$this->is_available()) {
            return false;
        }

        $image_info = getimagesize($source_file);
        if (!$image_info) {
            return false;
        }

        $source_mime = $image_info['mime'] ?? '';
        $image = match ($source_mime) {
            'image/jpeg' => @imagecreatefromjpeg($source_file),
            'image/png'  => @imagecreatefrompng($source_file),
            'image/webp' => @imagecreatefromwebp($source_file),
            default      => false,
        };

        if (!$image) {
            return false;
        }

        // Handle PNG transparency
        if ($source_mime === 'image/png') {
            imagepalettetotruecolor($image);
            imagealphablending($image, true);
            imagesavealpha($image, true);
        }

        $success = false;
        if ($mime_type === 'image/webp' && $this->supports_webp()) {
            $success = @imagewebp($image, $target_file, $quality);
        } elseif ($mime_type === 'image/avif' && $this->supports_avif()) {
            $success = @imageavif($image, $target_file, $quality);
        }

        imagedestroy($image);
        return $success && file_exists($target_file) && filesize($target_file) > 0;
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
        if (!file_exists($file) || !$this->is_available() || $max_width <= 0 || $max_height <= 0) {
            return false;
        }

        $info = getimagesize($file);
        if (!$info) {
            return false;
        }

        $orig_w = $info[0];
        $orig_h = $info[1];
        $mime   = $info['mime'];

        if ($orig_w <= $max_width && $orig_h <= $max_height) {
            return false;
        }

        // Calculate aspect ratio aspect
        $ratio = min($max_width / $orig_w, $max_height / $orig_h);
        $new_w = (int) round($orig_w * $ratio);
        $new_h = (int) round($orig_h * $ratio);

        $src_img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($file),
            'image/png'  => @imagecreatefrompng($file),
            'image/webp' => @imagecreatefromwebp($file),
            default      => false,
        };

        if (!$src_img) {
            return false;
        }

        $dst_img = imagecreatetruecolor($new_w, $new_h);
        if ($mime === 'image/png' || $mime === 'image/webp') {
            imagealphablending($dst_img, false);
            imagesavealpha($dst_img, true);
        }

        imagecopyresampled($dst_img, $src_img, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h);
        imagedestroy($src_img);

        $saved = match ($mime) {
            'image/jpeg' => @imagejpeg($dst_img, $file, $quality),
            'image/png'  => @imagepng($dst_img, $file, 9),
            'image/webp' => @imagewebp($dst_img, $file, $quality),
            default      => false,
        };

        imagedestroy($dst_img);
        return (bool) $saved;
    }

    /**
     * Strips EXIF metadata by re-encoding.
     *
     * @param string $file
     * @return bool
     */
    public function strip_exif(string $file): bool {
        if (!file_exists($file) || !$this->is_available()) {
            return false;
        }

        $info = getimagesize($file);
        if (!$info || $info['mime'] !== 'image/jpeg') {
            return false;
        }

        $img = @imagecreatefromjpeg($file);
        if (!$img) {
            return false;
        }

        $res = @imagejpeg($img, $file, 90);
        imagedestroy($img);
        return (bool) $res;
    }
}
