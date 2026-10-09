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
        $format = sanitize_key($attributes['format'] ?? 'auto');
        $lazy = (bool) ($attributes['lazy'] ?? true);

        if (!$attachment_id) {
            return '<p class="wso-block-empty">لطفاً یک تصویر انتخاب کنید.</p>';
        }

        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) {
            return '<p class="wso-block-empty">تصویر یافت نشد.</p>';
        }

        // Get optimized version if available
        $display_file = $file;
        if ($format !== 'original') {
            $info = pathinfo($file);
            $settings = \WSO\Core\Settings::instance();

            if ($format === 'auto') {
                $format = $settings->get('wso_convert_avif', 0) ? 'avif' : 'webp';
            }

            if ($format === 'avif') {
                $avif = $info['dirname'] . '/' . $info['filename'] . '.avif';
                if (file_exists($avif)) {
                    $display_file = $avif;
                }
            } elseif ($format === 'webp') {
                $webp = $info['dirname'] . '/' . $info['filename'] . '.webp';
                if (file_exists($webp)) {
                    $display_file = $webp;
                }
            }
        }

        $url = str_replace(ABSPATH, site_url('/'), $display_file);
        $ext = strtolower(pathinfo($display_file, PATHINFO_EXTENSION));

        $loading_attr = $lazy ? 'loading="lazy" decoding="async"' : 'loading="eager"';
        $mime_type = 'image/' . $ext;

        return sprintf(
            '<figure class="wso-optimized-image-block">' .
            '<img src="%s" alt="%s" type="%s" %s />' .
            '</figure>',
            esc_url($url),
            esc_attr(get_the_title($attachment_id)),
            esc_attr($mime_type),
            $loading_attr
        );
    }
}
