<?php
/**
 * Selective EXIF Editor
 * Removes only GPS and camera info while preserving ICC color profiles.
 *
 * @package WSO\Engine
 */

namespace WSO\Engine;

if (!defined('ABSPATH')) {
    exit;
}

class EXIF_Editor {

    private static ?EXIF_Editor $instance = null;

    public static function instance(): EXIF_Editor {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Strip only GPS data from image.
     */
    public function strip_gps(string $file_path): bool {
        if (!file_exists($file_path)) {
            return false;
        }

        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

        if (in_array($ext, ['jpg', 'jpeg'], true)) {
            return $this->strip_gps_jpeg($file_path);
        }

        return false;
    }

    /**
     * Strip camera make/model from image.
     */
    public function strip_camera_info(string $file_path): bool {
        if (!file_exists($file_path)) {
            return false;
        }

        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

        if (in_array($ext, ['jpg', 'jpeg'], true)) {
            return $this->strip_camera_jpeg($file_path);
        }

        return false;
    }

    /**
     * Strip GPS data from JPEG.
     */
    private function strip_gps_jpeg(string $file_path): bool {
        if (!extension_loaded('imagick')) {
            return false;
        }

        try {
            $imagick = new \Imagick($file_path);

            // Remove only GPS-related EXIF data
            $imagick->setImageProperty('exif:GPSLatitude', '');
            $imagick->setImageProperty('exif:GPSLongitude', '');
            $imagick->setImageProperty('exif:GPSAltitude', '');
            $imagick->setImageProperty('exif:GPSLatitudeRef', '');
            $imagick->setImageProperty('exif:GPSLongitudeRef', '');
            $imagick->setImageProperty('exif:GPSAltitudeRef', '');
            $imagick->setImageProperty('exif:GPSTimeStamp', '');
            $imagick->setImageProperty('exif:GPSDateStamp', '');
            $imagick->setImageProperty('exif:GPSProcessingMethod', '');
            $imagick->setImageProperty('exif:GPSAreaInformation', '');
            $imagick->setImageProperty('exif:GPSDifferential', '');
            $imagick->setImageProperty('exif:GPSHPositioningError', '');

            $imagick->writeImage($file_path);
            $imagick->clear();
            $imagick->destroy();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Strip camera make/model from JPEG.
     */
    private function strip_camera_jpeg(string $file_path): bool {
        if (!extension_loaded('imagick')) {
            return false;
        }

        try {
            $imagick = new \Imagick($file_path);

            // Remove camera info but keep ICC profile
            $imagick->setImageProperty('exif:Make', '');
            $imagick->setImageProperty('exif:Model', '');
            $imagick->setImageProperty('exif:CameraOwnerName', '');
            $imagick->setImageProperty('exif:BodySerialNumber', '');
            $imagick->setImageProperty('exif:LensMake', '');
            $imagick->setImageProperty('exif:LensModel', '');
            $imagick->setImageProperty('exif:LensSerialNumber', '');
            $imagick->setImageProperty('exif:UserComment', '');

            $imagick->writeImage($file_path);
            $imagick->clear();
            $imagick->destroy();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get EXIF data summary for an image.
     */
    public function get_exif_summary(string $file_path): array {
        if (!file_exists($file_path) || !function_exists('exif_read_data')) {
            return [];
        }

        $exif = @exif_read_data($file_path, 'ANY_TAG', true);
        if (!$exif) {
            return [];
        }

        $summary = [];

        if (isset($exif['GPS'])) {
            $summary['gps'] = true;
        }
        if (isset($exif['IFD0']['Make'])) {
            $summary['camera_make'] = $exif['IFD0']['Make'];
        }
        if (isset($exif['IFD0']['Model'])) {
            $summary['camera_model'] = $exif['IFD0']['Model'];
        }
        if (isset($exif['IFD0']['DateTime'])) {
            $summary['datetime'] = $exif['IFD0']['DateTime'];
        }

        return $summary;
    }
}
