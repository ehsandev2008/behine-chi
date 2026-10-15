<?php
/**
 * Picture Delivery + Server Rewrite Helpers
 * Wraps frontend <img> in <picture> with WebP/AVIF sources when files exist.
 *
 * @package WSO\Tools
 */

namespace WSO\Tools;

if (!defined('ABSPATH')) {
    exit;
}

class Picture_Delivery {

    private static ?Picture_Delivery $instance = null;

    public static function instance(): Picture_Delivery {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('the_content', [$this, 'filter_content'], 99);
        add_filter('post_thumbnail_html', [$this, 'filter_html'], 99, 2);
        add_filter('wp_get_attachment_image', [$this, 'filter_html'], 99, 2);
    }

    private function is_enabled(): bool {
        try {
            return (bool) \WSO\Core\Settings::instance()->get('wso_picture_delivery', 0);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param string $html
     * @return string
     */
    public function filter_html($html) {
        if (!$this->is_enabled() || is_admin() || !is_string($html) || '' === $html) {
            return $html;
        }
        if (false !== strpos($html, '<picture') || false !== strpos($html, 'data-wso-picture')) {
            return $html;
        }
        return (string) preg_replace_callback(
            '/<img\b[^>]*>/i',
            [$this, 'wrap_img_callback'],
            $html
        );
    }

    /**
     * @param string $content
     * @return string
     */
    public function filter_content($content) {
        if (!$this->is_enabled() || is_admin() || !is_string($content) || '' === $content) {
            return $content;
        }
        if (false === strpos($content, '<img')) {
            return $content;
        }
        return (string) preg_replace_callback(
            '/<img\b[^>]*>/i',
            [$this, 'wrap_img_callback'],
            $content
        );
    }

    /**
     * @param array $m
     * @return string
     */
    public function wrap_img_callback($m): string {
        $img = $m[0];
        if (false !== strpos($img, 'data-wso-picture')) {
            return $img;
        }
        if (!preg_match('/src=["\']([^"\']+)["\']/i', $img, $src_m)) {
            return $img;
        }
        $src = html_entity_decode($src_m[1], ENT_QUOTES);
        $local = $this->url_to_local_variants($src);
        if (empty($local['webp']) && empty($local['avif'])) {
            return $img;
        }
        $srcset = '';
        if (preg_match('/srcset=["\']([^"\']+)["\']/i', $img, $ss_m)) {
            $srcset = $ss_m[1];
        }
        $sources = '';
        if (!empty($local['avif'])) {
            $sources .= '<source type="image/avif" srcset="' . esc_url($local['avif']['url']) . '"' . ('' !== $srcset ? ' data-wso-srcset="1"' : '') . '>';
        }
        if (!empty($local['webp'])) {
            $sources .= '<source type="image/webp" srcset="' . esc_url($local['webp']['url']) . '">';
        }
        $flagged = preg_replace('/<img\b/i', '<img data-wso-picture="1"', $img, 1);
        if (!is_string($flagged) || '' === $flagged) {
            $flagged = $img;
        }
        return '<picture>' . $sources . $flagged . '</picture>';
    }

    /**
     * Resolve local WebP/AVIF variants for a frontend URL.
     *
     * @param string $src
     * @return array{webp?: array{url:string}, avif?: array{url:string}}
     */
    public function url_to_local_variants(string $src): array {
        $out = [];
        try {
            $upload_dir = wp_upload_dir();
            $baseurl = trailingslashit($upload_dir['baseurl']);
            $basedir = wp_normalize_path(trailingslashit($upload_dir['basedir']));
            $path = strtok($src, '?#');
            if (!is_string($path) || '' === $path) {
                return $out;
            }
            // Only handle uploads URLs (absolute or relative).
            if (0 !== strpos($path, $baseurl) && false === strpos($path, '/wp-content/uploads/')) {
                return $out;
            }
            $rel = $path;
            if (0 === strpos($path, $baseurl)) {
                $rel = substr($path, strlen($baseurl));
            } else {
                $pos = strpos($path, '/wp-content/uploads/');
                $rel = ltrim(substr($path, $pos + strlen('/wp-content/uploads/')), '/');
            }
            $rel = ltrim($rel, '/');
            if ('' === $rel || false !== strpos($rel, '..')) {
                return $out;
            }
            $abs = $basedir . $rel;
            if (!file_exists($abs)) {
                return $out;
            }
            $info = pathinfo($abs);
            if (empty($info['dirname']) || empty($info['filename'])) {
                return $out;
            }
            $webp_abs = $info['dirname'] . '/' . $info['filename'] . '.webp';
            $avif_abs = $info['dirname'] . '/' . $info['filename'] . '.avif';
            if (file_exists($webp_abs)) {
                $norm = wp_normalize_path($webp_abs);
                $url = (0 === strpos($norm, $basedir)) ? $baseurl . ltrim(substr($norm, strlen($basedir)), '/') : $src;
                $out['webp'] = ['url' => $url];
            }
            if (file_exists($avif_abs)) {
                $norm = wp_normalize_path($avif_abs);
                $url = (0 === strpos($norm, $basedir)) ? $baseurl . ltrim(substr($norm, strlen($basedir)), '/') : $src;
                $out['avif'] = ['url' => $url];
            }
        } catch (\Throwable $e) {}
        return $out;
    }

    public function get_htaccess_rules(): string {
        return "<IfModule mod_rewrite.c>\n"
            . "RewriteEngine On\n"
            . "# Behine-Chi: serve WebP/AVIF when browser supports it\n"
            . "RewriteCond %{HTTP_ACCEPT} image/avif\n"
            . "RewriteCond %{REQUEST_FILENAME} \\.(jpe?g|png)$ [NC]\n"
            . "RewriteCond %{REQUEST_FILENAME}.avif -f\n"
            . "RewriteRule ^(.+)$ $1.avif [T=image/avif,E=WSO_AVIF:1,L]\n"
            . "RewriteCond %{HTTP_ACCEPT} image/webp\n"
            . "RewriteCond %{REQUEST_FILENAME} \\.(jpe?g|png)$ [NC]\n"
            . "RewriteCond %{REQUEST_FILENAME}.webp -f\n"
            . "RewriteRule ^(.+)$ $1.webp [T=image/webp,E=WSO_WEBP:1,L]\n"
            . "</IfModule>\n"
            . "<IfModule mod_headers.c>\n"
            . "Header append Vary Accept env=WSO_WEBP\n"
            . "Header append Vary Accept env=WSO_AVIF\n"
            . "</IfModule>\n";
    }

    public function get_nginx_snippet(): string {
        return "# Behine-Chi nginx snippet (inside server/location for uploads):\n"
            . "map \$http_accept \$wso_suffix {\n"
            . "  default \"\";\n"
            . "  \"~*avif\" \".avif\";\n"
            . "  \"~*webp\" \".webp\";\n"
            . "}\n"
            . "# usage:\n"
            . "# location ~* ^/wp-content/uploads/(.+|)\\.(jpe?g|png)$ {\n"
            . "#   add_header Vary Accept;\n"
            . "#   try_files \$uri\$wso_suffix \$uri =404;\n"
            . "# }\n";
    }
}
