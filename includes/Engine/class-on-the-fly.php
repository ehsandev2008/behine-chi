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
        return isset($_SERVER['HTTP_ACCEPT']) && false !== strpos((string) $_SERVER['HTTP_ACCEPT'], 'image/webp');
    }

    /**
     * Check if browser supports AVIF.
     */
    public function browser_supports_avif(): bool {
        return isset($_SERVER['HTTP_ACCEPT']) && false !== strpos((string) $_SERVER['HTTP_ACCEPT'], 'image/avif');
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

        // Try to convert on-the-fly (only if driver supports it; never on huge files).
        $optimizer = Optimizer::instance();
        $driver = $optimizer->get_driver();
        if (('avif' === $preferred && !$driver->supports_avif()) || ('webp' === $preferred && !$driver->supports_webp())) {
            return $url;
        }
        if (filesize($file) > 5 * 1024 * 1024) {
            return $url;
        }
        $quality = max(1, min(100, (int) $settings->get('wso_quality', 75)));

        // Lock to avoid concurrent encodes of the same file.
        $lock = $converted . '.lock';
        if (file_exists($lock) && (time() - (int) @filemtime($lock)) < 120) {
            return $url;
        }
        @touch($lock);
        $ok = $driver->convert($file, $converted, 'image/' . $preferred, $quality);
        @unlink($lock);
        if ($ok) {
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

        $optimizer = Optimizer::instance();
        $driver = $optimizer->get_driver();
        if (('avif' === $preferred && !$driver->supports_avif()) || ('webp' === $preferred && !$driver->supports_webp())) {
            return $sources;
        }

        $main_file = get_attached_file($attachment_id);
        $upload_dir = wp_upload_dir();
        $basedir = wp_normalize_path(trailingslashit($upload_dir['basedir']));
        $baseurl = trailingslashit($upload_dir['baseurl']);

        $new_sources = [];
        foreach ($sources as $width_key => $source) {
            $new_sources[$width_key] = $source;

            // Resolve the actual file for THIS srcset entry from image_meta when possible.
            $meta_file = '';
            if (!empty($image_meta['sizes'])) {
                foreach ($image_meta['sizes'] as $size_data) {
                    if (!empty($size_data['file']) && !empty($source['url']) && false !== strpos($source['url'], basename($size_data['file']))) {
                        $meta_file = $size_data['file'];
                        break;
                    }
                }
            }
            $candidate_base = $main_file;
            if ('' !== $meta_file && $main_file) {
                $candidate_base = dirname($main_file) . '/' . basename($meta_file);
            }
            if (!$candidate_base || !file_exists($candidate_base)) {
                continue;
            }

            $info = pathinfo($candidate_base);
            $converted = $info['dirname'] . '/' . $info['filename'] . '.' . $preferred;

            if (file_exists($converted)) {
                $norm = wp_normalize_path($converted);
                $converted_url = (0 === strpos($norm, $basedir)) ? $baseurl . ltrim(substr($norm, strlen($basedir)), '/') : str_replace($info['basename'], basename($converted), $source['url']);
                // Avoid duplicate entries.
                $exists = false;
                foreach ($new_sources as $existing) {
                    if (($existing['url'] ?? '') === $converted_url) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $new_sources[] = [
                        'url'        => $converted_url,
                        'descriptor' => $source['descriptor'],
                        'value'      => $source['value'],
                    ];
                }
            }
        }

        return $new_sources;
    }

    /**
     * Maybe serve converted image directly via rewrite.
     * Gated: only when on-the-fly is enabled AND driver supports the format,
     * with per-IP rate limiting to prevent disk-fill DoS by ID enumeration.
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
        if ($attachment_id <= 0) {
            return;
        }
        $format = sanitize_key($_GET['format'] ?? 'webp');

        if (!in_array($format, ['webp', 'avif'], true)) {
            return;
        }

        // Simple rate limit: max 20 conversions per IP per 5 minutes.
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $rate_key = 'wso_otf_' . md5($ip);
        $count = (int) get_transient($rate_key);
        if ($count >= 20) {
            status_header(429);
            exit;
        }

        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) {
            return;
        }
        if (filesize($file) > 5 * 1024 * 1024) {
            return;
        }

        $info = pathinfo($file);
        $converted = $info['dirname'] . '/' . $info['filename'] . '.' . $format;

        if (!file_exists($converted)) {
            $settings = \WSO\Core\Settings::instance();
            $optimizer = Optimizer::instance();
            $driver = $optimizer->get_driver();
            if (('avif' === $format && !$driver->supports_avif()) || ('webp' === $format && !$driver->supports_webp())) {
                return;
            }
            $quality = max(1, min(100, (int) $settings->get('wso_quality', 75)));

            $lock = $converted . '.lock';
            if (file_exists($lock) && (time() - (int) @filemtime($lock)) < 120) {
                return;
            }
            @touch($lock);
            $ok = $driver->convert($file, $converted, 'image/' . $format, $quality);
            @unlink($lock);
            if (!$ok) {
                return;
            }
            set_transient($rate_key, $count + 1, 5 * MINUTE_IN_SECONDS);
        }

        $mime = 'image/' . $format;
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($converted));
        header('Cache-Control: public, max-age=31536000');
        readfile($converted);
        exit;
    }
}
