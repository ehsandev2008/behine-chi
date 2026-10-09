<?php
/**
 * Sortable Columns & Advanced Filtering for Media Library
 *
 * @package WSO\Admin
 */

namespace WSO\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class Media_Columns {

    private static ?Media_Columns $instance = null;

    public static function instance(): Media_Columns {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('manage_media_columns', [$this, 'add_sortable_columns']);
        add_action('manage_media_custom_column', [$this, 'render_sortable_column'], 10, 2);
        add_filter('manage_upload_sortable_columns', [$this, 'register_sortable']);
        add_action('pre_get_posts', [$this, 'handle_sorting']);
        add_action('restrict_manage_posts', [$this, 'add_filters']);
        add_filter('parse_query', [$this, 'handle_filters']);
    }

    /**
     * Add sortable columns to media library.
     */
    public function add_sortable_columns(array $columns): array {
        $columns['wso_size'] = 'حجم فایل';
        $columns['wso_format'] = 'فرمت';
        $columns['wso_engine'] = 'موتور';
        return $columns;
    }

    /**
     * Register sortable columns.
     */
    public function register_sortable(array $sortable): array {
        $sortable['wso_size'] = 'wso_size';
        $sortable['wso_format'] = 'wso_format';
        return $sortable;
    }

    /**
     * Render sortable column content.
     */
    public function render_sortable_column(string $column_name, int $post_id): void {
        if ($column_name === 'wso_size') {
            $file = get_attached_file($post_id);
            if ($file && file_exists($file)) {
                echo esc_html(size_format(filesize($file), 1));
            } else {
                echo '-';
            }
        }

        if ($column_name === 'wso_format') {
            $mime = get_post_mime_type($post_id);
            echo esc_html(str_replace('image/', '', $mime));
        }

        if ($column_name === 'wso_engine') {
            $data = get_post_meta($post_id, '_wso_opt_data', true);
            if (is_array($data) && !empty($data['driver'])) {
                echo esc_html($data['driver'] === 'imagick' ? 'Imagick' : 'GD');
            } else {
                echo '-';
            }
        }
    }

    /**
     * Handle sorting in query.
     */
    public function handle_sorting(\WP_Query $query): void {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        $orderby = $query->get('orderby');

        if ($orderby === 'wso_size') {
            $query->set('orderby', 'meta_value_num');
            $query->set('meta_key', '_wso_opt_data');
        } elseif ($orderby === 'wso_format') {
            $query->set('orderby', 'meta_value');
            $query->set('meta_key', '_wp_attached_file');
        }
    }

    /**
     * Add filter dropdowns.
     */
    public function add_filters(): void {
        global $pagenow;

        if ($pagenow !== 'upload.php') {
            return;
        }

        // Format filter
        $current_format = sanitize_text_field($_GET['wso_filter_format'] ?? '');
        ?>
        <select name="wso_filter_format" id="wso_filter_format">
            <option value="">همه فرمت‌ها</option>
            <option value="jpeg" <?php selected($current_format, 'jpeg'); ?>>JPEG</option>
            <option value="png" <?php selected($current_format, 'png'); ?>>PNG</option>
            <option value="webp" <?php selected($current_format, 'webp'); ?>>WebP</option>
            <option value="avif" <?php selected($current_format, 'avif'); ?>>AVIF</option>
            <option value="svg" <?php selected($current_format, 'svg'); ?>>SVG</option>
        </select>
        <?php

        // Status filter
        $current_status = sanitize_text_field($_GET['wso_filter_status'] ?? '');
        ?>
        <select name="wso_filter_status" id="wso_filter_status">
            <option value="">همه وضعیت‌ها</option>
            <option value="optimized" <?php selected($current_status, 'optimized'); ?>>بهینه‌شده</option>
            <option value="not_optimized" <?php selected($current_status, 'not_optimized'); ?>>بهینه‌نشده</option>
            <option value="has_backup" <?php selected($current_status, 'has_backup'); ?>>دارای بکاپ</option>
        </select>
        <?php

        // Engine filter
        $current_engine = sanitize_text_field($_GET['wso_filter_engine'] ?? '');
        ?>
        <select name="wso_filter_engine" id="wso_filter_engine">
            <option value="">همه موتورها</option>
            <option value="imagick" <?php selected($current_engine, 'imagick'); ?>>Imagick</option>
            <option value="gd" <?php selected($current_engine, 'gd'); ?>>GD</option>
        </select>
        <?php
    }

    /**
     * Handle filters in query.
     */
    public function handle_filters(\WP_Query $query): void {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        $meta_query = $query->get('meta_query') ?: [];

        // Format filter
        $format = sanitize_text_field($_GET['wso_filter_format'] ?? '');
        if ($format) {
            $meta_query[] = [
                'key'     => '_wp_attached_file',
                'value'   => '.' . $format,
                'compare' => 'LIKE',
            ];
        }

        // Status filter
        $status = sanitize_text_field($_GET['wso_filter_status'] ?? '');
        if ($status === 'optimized') {
            $meta_query[] = [
                'key'     => '_wso_optimized',
                'value'   => '1',
                'compare' => '=',
            ];
        } elseif ($status === 'not_optimized') {
            $meta_query[] = [
                'key'     => '_wso_optimized',
                'compare' => 'NOT EXISTS',
            ];
        } elseif ($status === 'has_backup') {
            $meta_query[] = [
                'key'     => '_wso_opt_data',
                'compare' => 'EXISTS',
            ];
        }

        // Engine filter
        $engine = sanitize_text_field($_GET['wso_filter_engine'] ?? '');
        if ($engine) {
            $meta_query[] = [
                'key'     => '_wso_opt_data',
                'value'   => '"driver";s:7:"' . $engine . '"',
                'compare' => 'LIKE',
            ];
        }

        if (!empty($meta_query)) {
            $query->set('meta_query', $meta_query);
        }
    }
}
