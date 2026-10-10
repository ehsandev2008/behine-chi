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

        // Validate file type by extension AND real content.
        $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/svg+xml'];
        $safe_name = sanitize_file_name($file['name']);
        $file_type = wp_check_filetype($safe_name);

        if (!in_array($file_type['type'], $allowed_types, true)) {
            wp_send_json_error(['message' => 'نوع فایل مجاز نیست.']);
        }
        // Size gate: respect wso_max_size and WP max upload.
        $max_mb = max(1, (int) \WSO\Core\Settings::instance()->get('wso_max_size', 2));
        $wp_max = wp_max_upload_size();
        $allowed_bytes = min($max_mb * 1024 * 1024, $wp_max > 0 ? $wp_max : PHP_INT_MAX);
        if (!empty($file['size']) && (int) $file['size'] > $allowed_bytes) {
            wp_send_json_error(['message' => 'حجم فایل بیشتر از حد مجاز است.']);
        }
        if (!empty($file['error']) && UPLOAD_ERR_OK !== (int) $file['error']) {
            wp_send_json_error(['message' => 'خطا در آپلود فایل.']);
        }
        // SVG hardening: validate + sanitize content before accepting.
        if ('image/svg+xml' === $file_type['type']) {
            $svg_raw = @file_get_contents($file['tmp_name']);
            if (false === $svg_raw || !\WSO\Engine\SVG_Optimizer::instance()->is_valid_svg($svg_raw)) {
                wp_send_json_error(['message' => 'فایل SVG نامعتبر است.']);
            }
            $clean = \WSO\Engine\SVG_Optimizer::instance()->minify_svg_content($svg_raw);
            if ('' === $clean || !\WSO\Engine\SVG_Optimizer::instance()->is_valid_svg($clean)) {
                wp_send_json_error(['message' => 'فایل SVG ناامن است.']);
            }
            @file_put_contents($file['tmp_name'], $clean);
        } else {
            // Verify raster content is a real image.
            $img_info = @getimagesize($file['tmp_name']);
            if (!$img_info || empty($img_info['mime']) || 0 !== strpos($img_info['mime'], 'image/')) {
                wp_send_json_error(['message' => 'فایل تصویری معتبر نیست.']);
            }
        }

        // Upload file via WP API (streams to disk; no full-memory copy).
        $upload = wp_upload_bits($safe_name, null, '');
        if (!empty($upload['error'])) {
            wp_send_json_error(['message' => $upload['error']]);
        }
        // Move uploaded temp into place in chunks.
        $src = @fopen($file['tmp_name'], 'rb');
        $dst = @fopen($upload['file'], 'wb');
        if (!$src || !$dst) {
            if ($src) {
                @fclose($src);
            }
            if ($dst) {
                @fclose($dst);
            }
            @unlink($upload['file']);
            wp_send_json_error(['message' => 'خطا در ذخیره فایل.']);
        }
        while (!feof($src)) {
            $chunk = fread($src, 1048576);
            if (false === $chunk) {
                break;
            }
            fwrite($dst, $chunk);
        }
        fclose($src);
        fclose($dst);

        // Create attachment
        $attachment = [
            'post_mime_type' => $file_type['type'],
            'post_title'     => sanitize_text_field(pathinfo($safe_name, PATHINFO_FILENAME)),
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
                'name' => $safe_name,
                'size' => size_format((int) ($file['size'] ?? 0), 2),
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
