<?php
/**
 * On-the-fly Image Conversion
 * Serves WebP/AVIF to compatible browsers without changing original files.
 *
 * @package WSO\Engine
 */

namespace WSO\Engine;

if (!defined('ABSPATH')) {
    exit;
}

class On_The_Fly {

    private static ?On_The_Fly $instance = null;

    public static function instance(): On_The_Fly {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('wp_get_attachment_url', [$this, 'maybe_convert_url'], 10, 2);
        add_filter('wp_calculate_image_srcset', [$this, 'filter_srcset'], 10, 5);
        add_action('template_redirect', [$this, 'maybe_serve_converted'], 0);
    }

    /**
     * Check if browser supports WebP.
     */
    public function browser_supports_webp(): bool {
        return isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'image/webp');
    }

    /**
     * Check if browser supports AVIF.
     */
    public function browser_supports_avif(): bool {
        return isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'image/avif');
    }

    /**
     * Maybe convert attachment URL to WebP/AVIF based on browser support.
     */
    public function maybe_convert_url(string $url, int $attachment_id): string {
        if (is_admin()) {
            return $url;
        }

        if (!\WSO\Core\Settings::instance()->get('wso_on_the_fly', 0)) {
            return $url;
        }

        if (!$this->browser_supports_webp() && !$this->browser_supports_avif()) {
            return $url;
        }

        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) {
            return $url;
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['webp', 'avif'], true)) {
            return $url;
        }

        $settings = \WSO\Core\Settings::instance();
        $preferred = $this->browser_supports_avif() && $settings->get('wso_convert_avif', 0) ? 'avif' : 'webp';

        $info = pathinfo($file);
        $converted = $info['dirname'] . '/' . $info['filename'] . '.' . $preferred;

        if (file_exists($converted)) {
            return str_replace($info['basename'], basename($converted), $url);
        }

        // Try to convert on-the-fly
        $optimizer = Optimizer::instance();
        $driver = $optimizer->get_driver();
        $quality = (int) $settings->get('wso_quality', 75);

        if ($driver->convert($file, $converted, 'image/' . $preferred, $quality)) {
            return str_replace($info['basename'], basename($converted), $url);
        }

        return $url;
    }

    /**
     * Filter srcset to include WebP/AVIF versions.
     */
    public function filter_srcset(array $sources, array $size_array, string $image_src, array $image_meta, int $attachment_id): array {
        if (is_admin()) {
            return $sources;
        }

        if (!\WSO\Core\Settings::instance()->get('wso_on_the_fly', 0)) {
            return $sources;
        }

        if (!$this->browser_supports_webp() && !$this->browser_supports_avif()) {
            return $sources;
        }

        $settings = \WSO\Core\Settings::instance();
        $preferred = $this->browser_supports_avif() && $settings->get('wso_convert_avif', 0) ? 'avif' : 'webp';

        $new_sources = [];
        foreach ($sources as $source) {
            $new_sources[] = $source;

            $file = get_attached_file($attachment_id);
            if (!$file) {
                continue;
            }

            $info = pathinfo($file);
            $converted = $info['dirname'] . '/' . $info['filename'] . '.' . $preferred;

            if (file_exists($converted)) {
                $converted_url = str_replace($info['basename'], basename($converted), $source['url']);
                $new_sources[] = [
                    'url'        => $converted_url,
                    'descriptor' => $source['descriptor'],
                    'value'      => $source['value'],
                ];
            }
        }

        return $new_sources;
    }

    /**
     * Maybe serve converted image directly via rewrite.
     */
    public function maybe_serve_converted(): void {
        if (is_admin()) {
            return;
        }

        if (!\WSO\Core\Settings::instance()->get('wso_on_the_fly', 0)) {
            return;
        }

        if (!isset($_GET['wso_convert'])) {
            return;
        }

        $attachment_id = (int) $_GET['wso_convert'];
        $format = sanitize_key($_GET['format'] ?? 'webp');

        if (!in_array($format, ['webp', 'avif'], true)) {
            return;
        }

        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) {
            return;
        }

        $info = pathinfo($file);
        $converted = $info['dirname'] . '/' . $info['filename'] . '.' . $format;

        if (!file_exists($converted)) {
            $settings = \WSO\Core\Settings::instance();
            $optimizer = Optimizer::instance();
            $driver = $optimizer->get_driver();
            $quality = (int) $settings->get('wso_quality', 75);

            if (!$driver->convert($file, $converted, 'image/' . $format, $quality)) {
                return;
            }
        }

        $mime = 'image/' . $format;
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($converted));
        header('Cache-Control: public, max-age=31536000');
        readfile($converted);
        exit;
    }
}
