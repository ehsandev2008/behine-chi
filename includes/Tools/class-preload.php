<?php
/**
 * WebP Preload
 * Adds preload headers for WebP files to <head>.
 *
 * @package WSO\Tools
 */

namespace WSO\Tools;

if (!defined('ABSPATH')) {
    exit;
}

class Preload {

    private static ?Preload $instance = null;

    public static function instance(): Preload {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_head', [$this, 'output_preload_links'], 1);
    }

    /**
     * Output preload links for WebP/AVIF images.
     */
    public function output_preload_links(): void {
        if (is_admin()) {
            return;
        }

        $settings = \WSO\Core\Settings::instance();
        if (!$settings->get('wso_preload_webp', 0)) {
            return;
        }

        // Get featured image for singular posts
        if (is_singular()) {
            $thumbnail_id = get_post_thumbnail_id();
            if ($thumbnail_id) {
                $this->preload_attachment($thumbnail_id);
            }
        }

        // Get content images (first 5)
        global $post;
        if (!empty($post->post_content)) {
            preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $post->post_content, $matches);
            if (!empty($matches[1])) {
                $count = 0;
                foreach ($matches[1] as $src) {
                    if ($count >= 5) {
                        break;
                    }
                    $this->preload_image_url($src);
                    $count++;
                }
            }
        }
    }

    /**
     * Preload a specific attachment.
     */
    private function preload_attachment(int $attachment_id): void {
        $file = get_attached_file($attachment_id);
        if (!$file) {
            return;
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['webp', 'avif'], true)) {
            $url = wp_get_attachment_url($attachment_id);
            if ($url) {
                echo '<link rel="preload" as="image" href="' . esc_url($url) . '" type="image/' . $ext . '">' . "\n";
            }
            return;
        }

        // Try to find WebP/AVIF version
        $info = pathinfo($file);
        $settings = \WSO\Core\Settings::instance();
        $upload_dir = wp_upload_dir();
        $basedir = wp_normalize_path($upload_dir['basedir']);

        if ($settings->get('wso_convert_avif', 0)) {
            $avif = $info['dirname'] . '/' . $info['filename'] . '.avif';
            if (file_exists($avif)) {
                $norm = wp_normalize_path($avif);
                $url = str_starts_with($norm, $basedir) ? $upload_dir['baseurl'] . substr($norm, strlen($basedir)) : wp_get_attachment_url($attachment_id);
                echo '<link rel="preload" as="image" href="' . esc_url($url) . '" type="image/avif">' . "\n";
                return;
            }
        }

        if ($settings->get('wso_convert_webp', 1)) {
            $webp = $info['dirname'] . '/' . $info['filename'] . '.webp';
            if (file_exists($webp)) {
                $norm = wp_normalize_path($webp);
                $url = str_starts_with($norm, $basedir) ? $upload_dir['baseurl'] . substr($norm, strlen($basedir)) : wp_get_attachment_url($attachment_id);
                echo '<link rel="preload" as="image" href="' . esc_url($url) . '" type="image/webp">' . "\n";
            }
        }
    }

    /**
     * Preload an image URL.
     */
    private function preload_image_url(string $url): void {
        $ext = strtolower(pathinfo($url, PATHINFO_EXTENSION));
        if (in_array($ext, ['webp', 'avif'], true)) {
            echo '<link rel="preload" as="image" href="' . esc_url($url) . '" type="image/' . $ext . '">' . "\n";
        }
    }
}
