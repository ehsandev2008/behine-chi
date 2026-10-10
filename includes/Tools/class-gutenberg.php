<?php
/**
 * Gutenberg Block Support
 * Custom block for displaying optimized images.
 *
 * @package WSO\Tools
 */

namespace WSO\Tools;

if (!defined('ABSPATH')) {
    exit;
}

class Gutenberg {

    private static ?Gutenberg $instance = null;

    public static function instance(): Gutenberg {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'register_block']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
    }

    /**
     * Register the custom block.
     */
    public function register_block(): void {
        if (!function_exists('register_block_type')) {
            return;
        }

        register_block_type('wso/optimized-image', [
            'editor_script'   => 'wso-optimized-image-block',
            'editor_style'    => 'wso-optimized-image-block-editor',
            'style'           => 'wso-optimized-image-block',
            'render_callback' => [$this, 'render_block'],
            'attributes'      => [
                'attachmentId' => ['type' => 'number', 'default' => 0],
                'size'         => ['type' => 'string', 'default' => 'full'],
                'format'       => ['type' => 'string', 'default' => 'auto'],
                'lazy'         => ['type' => 'boolean', 'default' => true],
            ],
        ]);
    }

    /**
     * Enqueue editor assets.
     */
    public function enqueue_editor_assets(): void {
        wp_enqueue_script(
            'wso-optimized-image-block',
            WSO_URL . 'assets/js/gutenberg-block.js',
            ['wp-blocks', 'wp-element', 'wp-editor', 'wp-components'],
            WSO_VERSION,
            true
        );

        wp_enqueue_style(
            'wso-optimized-image-block-editor',
            WSO_URL . 'assets/css/gutenberg-block.css',
            ['wp-edit-blocks'],
            WSO_VERSION
        );
    }

    /**
     * Render the block on the frontend.
     */
    public function render_block(array $attributes): string {
        $attachment_id = (int) ($attributes['attachmentId'] ?? 0);
        $size = sanitize_key($attributes['size'] ?? 'full');
        $allowed_sizes = ['thumbnail', 'medium', 'medium_large', 'large', 'full'];
        if (!in_array($size, $allowed_sizes, true)) {
            $size = 'full';
        }
        $format = sanitize_key($attributes['format'] ?? 'auto');
        $allowed_formats = ['auto', 'original', 'webp', 'avif'];
        if (!in_array($format, $allowed_formats, true)) {
            $format = 'auto';
        }
        $lazy = (bool) ($attributes['lazy'] ?? true);

        if (!$attachment_id) {
            return '<p class="wso-block-empty">لطفاً یک تصویر انتخاب کنید.</p>';
        }

        // Prefer WP-sized image URL so requested size is respected.
        $sized = wp_get_attachment_image_src($attachment_id, $size);
        $fallback_file = get_attached_file($attachment_id);
        if (!$fallback_file || !file_exists($fallback_file)) {
            return '<p class="wso-block-empty">تصویر یافت نشد.</p>';
        }

        // Get optimized version if available
        $display_file = $fallback_file;
        $display_url = is_array($sized) ? $sized[0] : wp_get_attachment_url($attachment_id);
        if ($format !== 'original') {
            $info = pathinfo($fallback_file);
            $settings = \WSO\Core\Settings::instance();

            if ($format === 'auto') {
                $format = $settings->get('wso_convert_avif', 0) ? 'avif' : 'webp';
            }

            $candidate = '';
            if ($format === 'avif') {
                $avif = $info['dirname'] . '/' . $info['filename'] . '.avif';
                if (file_exists($avif)) {
                    $candidate = $avif;
                }
            } elseif ($format === 'webp') {
                $webp = $info['dirname'] . '/' . $info['filename'] . '.webp';
                if (file_exists($webp)) {
                    $candidate = $webp;
                }
            }
            if ('' !== $candidate) {
                $upload_dir = wp_upload_dir();
                $basedir = wp_normalize_path(trailingslashit($upload_dir['basedir']));
                $norm = wp_normalize_path($candidate);
                if (0 === strpos($norm, $basedir)) {
                    $display_url = trailingslashit($upload_dir['baseurl']) . ltrim(substr($norm, strlen($basedir)), '/');
                }
                $display_file = $candidate;
            } elseif (is_array($sized)) {
                $display_url = $sized[0];
            }
        } elseif (is_array($sized)) {
            $display_url = $sized[0];
        }

        $ext = strtolower(pathinfo($display_file, PATHINFO_EXTENSION));
        $mime_map = ['jpg' => 'jpeg', 'jpeg' => 'jpeg', 'png' => 'png', 'webp' => 'webp', 'avif' => 'avif', 'gif' => 'gif', 'svg' => 'svg+xml'];
        $mime_type = 'image/' . ($mime_map[$ext] ?? $ext);

        $loading_attr = $lazy ? 'loading="lazy" decoding="async"' : 'loading="eager"';
        $alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        if (!is_string($alt) || '' === trim($alt)) {
            $alt = get_the_title($attachment_id);
        }

        return sprintf(
            '<figure class="wso-optimized-image-block">' .
            '<img src="%s" alt="%s" %s />' .
            '</figure>',
            esc_url($display_url ? $display_url : wp_get_attachment_url($attachment_id)),
            esc_attr($alt),
            $loading_attr
        );
    }
}
