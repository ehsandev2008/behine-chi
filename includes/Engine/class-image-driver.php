<?php
/**
 * رابط موتور پردازش تصویر
 * Image Driver Interface
 *
 * @package WSO\Engine
 */

namespace WSO\Engine;

if (!defined('ABSPATH')) {
    exit;
}

if (!interface_exists('WSO\\Engine\\Image_Driver')) {
    interface Image_Driver {

        /**
         * Checks if the driver is supported on the server environment.
         *
         * @return bool
         */
        public function is_available(): bool;

        /**
         * Checks if WebP format is supported.
         *
         * @return bool
         */
        public function supports_webp(): bool;

        /**
         * Checks if AVIF format is supported.
         *
         * @return bool
         */
        public function supports_avif(): bool;

        /**
         * Converts image to specified target format.
         *
         * @param string $source_file Source file path.
         * @param string $target_file Target output path.
         * @param string $mime_type Target MIME type ('image/webp' or 'image/avif').
         * @param int $quality Compression quality (1-100).
         * @return bool
         */
        public function convert(string $source_file, string $target_file, string $mime_type, int $quality = 82): bool;

        /**
         * Resizes image down if it exceeds max width/height dimensions.
         *
         * @param string $file File path.
         * @param int $max_width Maximum width.
         * @param int $max_height Maximum height.
         * @param int $quality Quality setting.
         * @return bool True if resized, false otherwise.
         */
        public function resize(string $file, int $max_width, int $max_height, int $quality = 82): bool;

        /**
         * Strips EXIF metadata from image.
         *
         * @param string $file File path.
         * @param int $quality Quality setting for re-encoding.
         * @return bool True if resized, false otherwise.
         */
        public function strip_exif(string $file, int $quality = 82): bool;
    }
}
