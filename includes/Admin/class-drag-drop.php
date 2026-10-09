<?php
/**
 * Drag & Drop Upload
 * Direct upload in plugin panel with optimization preview.
 *
 * @package WSO\Admin
 */

namespace WSO\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class Drag_Drop {

    private static ?Drag_Drop $instance = null;

    public static function instance(): Drag_Drop {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_wso_drag_drop_upload', [$this, 'handle_upload']);
    }

    /**
     * Handle drag & drop file upload.
     */
    public function handle_upload(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'سطح دسترسی غیرمجاز است.'], 403);
        }

        check_ajax_referer('wso_admin_nonce', 'nonce');

        if (empty($_FILES['file'])) {
            wp_send_json_error(['message' => 'هیچ فایلی ارسال نشده است.']);
        }

        $file = $_FILES['file'];

        // Validate file type
        $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/svg+xml'];
        $file_type = wp_check_filetype($file['name']);

        if (!in_array($file_type['type'], $allowed_types, true)) {
            wp_send_json_error(['message' => 'نوع فایل مجاز نیست.']);
        }

        // Upload file
        $upload = wp_upload_bits($file['name'], null, file_get_contents($file['tmp_name']));

        if (!empty($upload['error'])) {
            wp_send_json_error(['message' => $upload['error']]);
        }

        // Create attachment
        $attachment = [
            'post_mime_type' => $file_type['type'],
            'post_title'     => sanitize_file_name(pathinfo($file['name'], PATHINFO_FILENAME)),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];

        $attachment_id = wp_insert_attachment($attachment, $upload['file']);

        if (is_wp_error($attachment_id)) {
            wp_send_json_error(['message' => $attachment_id->get_error_message()]);
        }

        // Generate metadata
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        wp_update_attachment_metadata($attachment_id, $metadata);

        // Auto-optimize if enabled
        $settings = \WSO\Core\Settings::instance();
        $optimization_result = null;

        if ($settings->get('wso_auto_optimize', 1)) {
            $optimization_result = \WSO\Engine\Optimizer::instance()->optimize_attachment($attachment_id);
        }

        // Clean up temp file
        @unlink($file['tmp_name']);

        wp_send_json_success([
            'message'    => 'فایل با موفقیت آپلود شد.',
            'attachment' => [
                'id'   => $attachment_id,
                'url'  => $upload['url'],
                'name' => $file['name'],
                'size' => size_format($file['size'], 2),
            ],
            'optimization' => $optimization_result ? [
                'status'          => $optimization_result['status'],
                'original_size'   => size_format($optimization_result['original_size'] ?? 0, 2),
                'optimized_size'  => size_format($optimization_result['optimized_size'] ?? 0, 2),
                'saved_bytes'     => size_format($optimization_result['saved_bytes'] ?? 0, 2),
                'savings_percent' => $optimization_result['savings_percent'] ?? 0,
            ] : null,
        ]);
    }
}
