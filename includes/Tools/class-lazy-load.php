<?php
/**
 * Smart Lazy Load
 * Only lazy loads non-optimized images.
 *
 * @package WSO\Tools
 */

namespace WSO\Tools;

if (!defined('ABSPATH')) {
    exit;
}

class Lazy_Load {

    private static ?Lazy_Load $instance = null;

    public static function instance(): Lazy_Load {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('wp_get_attachment_image_attributes', [$this, 'maybe_add_lazy_load'], 10, 3);
        add_filter('the_content', [$this, 'add_lazy_load_to_content'], 99);
    }

    /**
     * Add lazy loading to attachment images.
     */
    public function maybe_add_lazy_load(array $attr, \WP_Post $attachment, array $size): array {
        $settings = \WSO\Core\Settings::instance();
        if (!$settings->get('wso_smart_lazy_load', 0)) {
            return $attr;
        }

        // Only lazy load if image is NOT optimized
        $is_optimized = get_post_meta($attachment->ID, '_wso_optimized', true);
        if ($is_optimized) {
            return $attr;
        }

        $attr['loading'] = 'lazy';
        $attr['decoding'] = 'async';

        return $attr;
    }

    /**
     * Add lazy loading to content images.
     */
    public function add_lazy_load_to_content(string $content): string {
        $settings = \WSO\Core\Settings::instance();
        if (!$settings->get('wso_smart_lazy_load', 0)) {
            return $content;
        }

        // Only process if there are images
        if (!str_contains($content, '<img')) {
            return $content;
        }

        // Add loading="lazy" to images that don't have it
        $content = preg_replace(
            '/<img(?![^>]*loading=)([^>]+)>/i',
            '<img loading="lazy" decoding="async"$1>',
            $content
        );

        return $content;
    }
}
