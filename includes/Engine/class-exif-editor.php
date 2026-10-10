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
     * Strip GPS data from JPEG (truly removes tags; preserves ICC profile).
     */
    private function strip_gps_jpeg(string $file_path): bool {
        if (!extension_loaded('imagick') || !class_exists('\Imagick')) {
            return false;
        }

        try {
            $imagick = new \Imagick($file_path);
            // Preserve ICC so colors do not shift.
            $icc = null;
            try {
                $icc = $imagick->getImageProfiles('icc', true);
            } catch (\Throwable $e) {}

            // True removal: strip all profiles then re-add only ICC.
            $imagick->stripImage();
            if (!empty($icc['icc'])) {
                try {
                    $imagick->profileImage('icc', $icc['icc']);
                } catch (\Throwable $e) {}
            }

            $tmp = $file_path . '.tmp';
            $ok = $imagick->writeImage($tmp);
            $imagick->clear();
            $imagick->destroy();
            if (!$ok || !file_exists($tmp) || filesize($tmp) <= 0) {
                @unlink($tmp);
                return false;
            }
            // Verify GPS is actually gone before claiming success.
            if ($this->has_gps($tmp)) {
                @unlink($tmp);
                return false;
            }
            if (!@rename($tmp, $file_path)) {
                @unlink($tmp);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            if (isset($tmp)) {
                @unlink($tmp);
            }
            return false;
        }
    }

    /**
     * Checks whether a file still contains GPS EXIF.
     *
     * @param string $file File path.
     * @return bool
     */
    private function has_gps(string $file): bool {
        if (!function_exists('exif_read_data')) {
            return false;
        }
        $exif = @exif_read_data($file, 'ANY_TAG', true);
        return !empty($exif['GPS']);
    }

    /**
     * Strip camera make/model from JPEG (truly removes tags; preserves ICC).
     */
    private function strip_camera_jpeg(string $file_path): bool {
        if (!extension_loaded('imagick') || !class_exists('\Imagick')) {
            return false;
        }

        try {
            $imagick = new \Imagick($file_path);

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

            $tmp = $file_path . '.tmp';
            $ok = $imagick->writeImage($tmp);
            $imagick->clear();
            $imagick->destroy();
            if (!$ok || !file_exists($tmp) || filesize($tmp) <= 0) {
                @unlink($tmp);
                return false;
            }
            if ($this->has_camera_info($tmp)) {
                @unlink($tmp);
                return false;
            }
            if (!@rename($tmp, $file_path)) {
                @unlink($tmp);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            if (isset($tmp)) {
                @unlink($tmp);
            }
            return false;
        }
    }

    /**
     * Checks whether camera make/model EXIF remains.
     *
     * @param string $file File path.
     * @return bool
     */
    private function has_camera_info(string $file): bool {
        if (!function_exists('exif_read_data')) {
            return false;
        }
        $exif = @exif_read_data($file, 'ANY_TAG', true);
        return !empty($exif['IFD0']['Make']) || !empty($exif['IFD0']['Model']);
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
