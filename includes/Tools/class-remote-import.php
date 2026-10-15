<?php
/**
 * Remote Image Import (external <img> -> media library).
 *
 * @package WSO\Tools
 */

namespace WSO\Tools;

if (!defined('ABSPATH')) {
    exit;
}

class Remote_Import {

    private static ?Remote_Import $instance = null;

    public static function instance(): Remote_Import {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Find external image URLs in a post.
     *
     * @param int $post_id
     * @return string[]
     */
    public function find_remote_images(int $post_id): array {
        $post = get_post($post_id);
        if (!$post) {
            return [];
        }
        $home = wp_parse_url(home_url(), PHP_URL_HOST);
        preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', (string) $post->post_content, $m);
        $out = [];
        foreach (($m[1] ?? []) as $src) {
            $src = html_entity_decode($src, ENT_QUOTES);
            if ('' === $src || 0 === strpos($src, 'data:')) {
                continue;
            }
            $host = wp_parse_url($src, PHP_URL_HOST);
            if ($host && $home && strtolower($host) !== strtolower($home)) {
                $ext = strtolower(pathinfo((string) strtok($src, '?#'), PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'], true)) {
                    $out[] = $src;
                }
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Import remote images of a post into media library and rewrite content.
     *
     * @param int $post_id
     * @param int $limit
     * @return array{imported:int, failed:int, urls:array}
     */
    public function import_for_post(int $post_id, int $limit = 5): array {
        $remote = $this->find_remote_images($post_id);
        $remote = array_slice($remote, 0, max(1, min(20, $limit)));
        if (empty($remote)) {
            return ['imported' => 0, 'failed' => 0, 'urls' => []];
        }
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $post = get_post($post_id);
        $content = (string) $post->post_content;
        $imported = 0;
        $failed = 0;
        $map = [];
        foreach ($remote as $url) {
            $id = media_sideload_image($url, $post_id, null, 'id');
            if (is_wp_error($id) || !$id) {
                $failed++;
                continue;
            }
            $new_url = wp_get_attachment_url((int) $id);
            if ($new_url) {
                $map[$url] = $new_url;
                $imported++;
                // Auto-optimize the imported file.
                try {
                    \WSO\Engine\Optimizer::instance()->optimize_attachment((int) $id);
                } catch (\Throwable $e) {}
            } else {
                $failed++;
            }
        }
        if (!empty($map)) {
            $new_content = str_replace(array_keys($map), array_values($map), $content);
            if ($new_content !== $content) {
                wp_update_post(['ID' => $post_id, 'post_content' => $new_content]);
            }
        }
        return ['imported' => $imported, 'failed' => $failed, 'urls' => $map];
    }
}
