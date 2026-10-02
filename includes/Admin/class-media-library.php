<?php
/**
 * ادغام با کتابخانه رسانه به زبان فارسی
 * Media Library Integration (100% Persian UI)
 *
 * @package WSO\Admin
 */

namespace WSO\Admin;

use WSO\Engine\Optimizer;
use WSO\Engine\Backup_Manager;

if (!defined('ABSPATH')) {
    exit;
}

class Media_Library {

    /**
     * Singleton instance.
     *
     * @var Media_Library|null
     */
    private static ?Media_Library $instance = null;

    /**
     * Returns the singleton instance.
     *
     * @return Media_Library
     */
    public static function instance(): Media_Library {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {
        add_filter('manage_media_columns', [$this, 'add_column']);
        add_action('manage_media_custom_column', [$this, 'render_column'], 10, 2);
        add_filter('attachment_fields_to_edit', [$this, 'render_attachment_fields'], 10, 2);
        add_filter('wp_generate_attachment_metadata', [$this, 'save_upload_optimization_meta'], 20, 2);

        add_action('wp_ajax_wso_single_optimize', [$this, 'handle_single_optimize']);
        add_action('wp_ajax_wso_single_restore', [$this, 'handle_single_restore']);
    }

    /**
     * Automatically optimizes media attachment on upload if enabled, and records meta.
     *
     * @param array $metadata
     * @param int $attachment_id
     * @return array
     */
    public function save_upload_optimization_meta(array $metadata, int $attachment_id): array {
        $settings = \WSO\Core\Settings::instance();
        if (!$settings->get('wso_enable', 1) || !$settings->get('wso_auto_optimize', 1)) {
            return $metadata;
        }

        // Avoid infinite loop if already processed
        if (get_post_meta($attachment_id, '_wso_optimized', true)) {
            return $metadata;
        }

        $res = Optimizer::instance()->optimize_attachment($attachment_id);
        if ($res['status'] === \WSO\Tools\Logger::STATUS_SUCCESS) {
            $updated_meta = wp_get_attachment_metadata($attachment_id);
            if (is_array($updated_meta)) {
                return $updated_meta;
            }
        }

        return $metadata;
    }

    /**
     * Adds custom column to Media Library.
     *
     * @param array $columns
     * @return array
     */
    public function add_column(array $columns): array {
        $columns['wso_status'] = 'وضعیت بهینه‌سازی (بهینه چی)';
        return $columns;
    }

    /**
     * Renders WSO status column cell with clear size and savings details.
     *
     * @param string $column_name
     * @param int $post_id
     * @return void
     */
    public function render_column(string $column_name, int $post_id): void {
        if ($column_name !== 'wso_status') {
            return;
        }

        $file = get_attached_file($post_id);
        if (!$file || !file_exists($file)) {
            echo '<span class="wso-badge wso-badge-neutral">نامشخص</span>';
            return;
        }

        $is_opt = get_post_meta($post_id, '_wso_optimized', true);
        $data   = get_post_meta($post_id, '_wso_opt_data', true);
        $has_backup = Backup_Manager::instance()->has_backup($file);

        if ($is_opt && !empty($data)) {
            $orig_size = (int) ($data['original_size'] ?? 0);
            $opt_size  = (int) ($data['optimized_size'] ?? 0);
            $backup_path = Backup_Manager::instance()->get_backup_path($file);
            if (file_exists($backup_path) && filesize($backup_path) > 0) {
                $orig_size = max($orig_size, (int) filesize($backup_path));
            }
            if ($opt_size <= 0 && file_exists($file)) {
                $opt_size = (int) filesize($file);
            }
            $saved_pct = $orig_size > 0 ? round((max(0, $orig_size - $opt_size) / $orig_size) * 100, 2) : 0;
            $orig_fmt  = $orig_size > 0 ? size_format($orig_size, 1) : '-';
            $opt_fmt   = $opt_size > 0 ? size_format($opt_size, 1) : '-';
            $saved_fmt = size_format($data['saved_bytes'] ?? max(0, $orig_size - $opt_size), 1);

            echo '<div class="wso-column-cell" style="line-height:1.5;">';
            echo '<span class="wso-badge wso-badge-success" title="' . esc_attr(sprintf('میزان صرفه‌جویی: %s (%s٪)', $saved_fmt, $saved_pct)) . '">';
            echo esc_html(sprintf('بهینه‌شده ٪%s-', $saved_pct));
            echo '</span>';

            if ($orig_size > 0 && $opt_size > 0) {
                echo '<div style="font-size:11px; margin:4px 0; color:#475569; direction:ltr; text-align:right;">';
                echo '<span title="حجم اصلی">' . esc_html($orig_fmt) . '</span> &rarr; <strong style="color:#10b981;" title="حجم بهینه‌شده">' . esc_html($opt_fmt) . '</strong>';
                echo '</div>';
            }
            
            echo '<div class="wso-column-actions" style="margin-top:4px;">';
            echo '<button type="button" class="button button-small wso-btn-reoptimize" data-id="' . esc_attr($post_id) . '">بهینه‌سازی مجدد</button> ';
            if ($has_backup) {
                echo '<button type="button" class="button button-small wso-btn-restore" data-id="' . esc_attr($post_id) . '">بازگردانی اصلی</button>';
            }
            echo '</div>';
            echo '</div>';
        } else {
            $raw_size = filesize($file);
            echo '<div class="wso-column-cell">';
            echo '<span class="wso-badge wso-badge-warning">بهینه‌نشده</span>';
            if ($raw_size > 0) {
                echo '<div style="font-size:11px; margin:3px 0; color:#64748b; direction:ltr; text-align:right;">' . esc_html(size_format($raw_size, 1)) . '</div>';
            }
            echo '<div class="wso-column-actions" style="margin-top:4px;">';
            echo '<button type="button" class="button button-small wso-btn-optimize" data-id="' . esc_attr($post_id) . '">بهینه‌سازی</button>';
            echo '</div>';
            echo '</div>';
        }
    }

    /**
     * Enhances attachment edit modal fields with detailed size metrics in Persian.
     *
     * @param array $fields
     * @param \WP_Post $post
     * @return array
     */
    public function render_attachment_fields(array $fields, \WP_Post $post): array {
        $file = get_attached_file($post->ID);
        if (!$file) {
            return $fields;
        }

        $info = pathinfo($file);
        $ext = strtolower($info['extension'] ?? '');
        $is_opt = get_post_meta($post->ID, '_wso_optimized', true);
        $opt_data = get_post_meta($post->ID, '_wso_opt_data', true);

        // Determine correct original & optimized sizes
        $orig_size = 0;
        $opt_size  = 0;
        $saved_bytes = 0;
        $savings_percent = 0;

        if ($is_opt && !empty($opt_data)) {
            $orig_size       = (int) ($opt_data['original_size'] ?? 0);
            $opt_size        = (int) ($opt_data['optimized_size'] ?? (file_exists($file) ? filesize($file) : 0));
            // Trustworthy fallback: backup holds pristine original bytes; never show
            // an "original" smaller than the backup or smaller than current file
            // when optimization shrank it (protects against legacy overwritten meta).
            $backup_path = Backup_Manager::instance()->get_backup_path($file);
            if (file_exists($backup_path) && filesize($backup_path) > 0) {
                $orig_size = max($orig_size, (int) filesize($backup_path));
            }
            if (file_exists($file)) {
                $current = (int) filesize($file);
                if ($orig_size <= 0) {
                    $orig_size = $current;
                }
                if ($opt_size <= 0) {
                    $opt_size = $current;
                }
                // Legacy guard: if stored original equals current shrunk file but a
                // backup exists larger, backup already won above. Otherwise if stored
                // original < current (inconsistent), keep stored but recompute savings.
                if ($opt_size > $orig_size && $orig_size > 0) {
                    $opt_size = min($opt_size, $current);
                    if ($opt_size > $orig_size) {
                        $orig_size = $opt_size;
                    }
                }
            }
            $saved_bytes     = max(0, $orig_size - $opt_size);
            $savings_percent = $orig_size > 0 ? round(($saved_bytes / $orig_size) * 100, 2) : 0;
        } else {
            $orig_size = file_exists($file) ? (int) filesize($file) : 0;
            $opt_size  = $orig_size;
        }

        // Determine WebP/AVIF file paths based on extension
        $webp = '';
        $avif = '';
        if ($ext === 'webp') {
            $webp = $file;
            $avif = $info['dirname'] . '/' . $info['filename'] . '.avif';
        } elseif ($ext === 'avif') {
            $avif = $file;
            $webp = $info['dirname'] . '/' . $info['filename'] . '.webp';
        } elseif ($ext !== 'svg') {
            $webp = $info['dirname'] . '/' . $info['filename'] . '.webp';
            $avif = $info['dirname'] . '/' . $info['filename'] . '.avif';
        }

        $html = '<div class="wso-attachment-info" dir="rtl" style="background:#f8fafc; padding:12px; border-radius:6px; border:1px solid #e2e8f0; line-height:1.8;">';

        if ($orig_size > 0) {
            $html .= '<div><strong>حجم فایل اصلی اولیه:</strong> <span style="font-family:monospace;">' . size_format($orig_size, 2) . '</span></div>';
        }

        if ($is_opt && $opt_size > 0) {
            $html .= '<div><strong>حجم نهایی بهینه‌شده:</strong> <span style="font-family:monospace; color:#10b981; font-weight:bold;">' . size_format($opt_size, 2) . '</span></div>';
            if ($saved_bytes > 0) {
                $html .= '<div><strong>میزان کاهش حجم:</strong> <span style="color:#059669;">' . size_format($saved_bytes, 2) . ' (' . $savings_percent . '٪ صرفه‌جویی)</span></div>';
            }
        }

        if (!empty($webp) && file_exists($webp)) {
            $html .= '<div style="margin-top:4px;"><strong>حجم فایل WebP:</strong> <span style="font-family:monospace;">' . size_format(filesize($webp), 2) . '</span> <span class="wso-badge wso-badge-success" style="font-size:10px;">WebP موجود است</span></div>';
        }

        if (!empty($avif) && file_exists($avif)) {
            $html .= '<div style="margin-top:4px;"><strong>حجم فایل AVIF:</strong> <span style="font-family:monospace;">' . size_format(filesize($avif), 2) . '</span> <span class="wso-badge wso-badge-success" style="font-size:10px;">AVIF موجود است</span></div>';
        }

        if ($ext === 'svg') {
            $html .= '<div style="margin-top:4px;"><strong>نوع فایل:</strong> <span class="wso-badge wso-badge-primary">فرمت وکتور SVG</span></div>';
        }

        $html .= '<div style="margin-top:6px; padding-top:6px; border-top:1px dashed #cbd5e1;">';
        if ($is_opt) {
            $html .= '<strong>وضعیت:</strong> <span class="wso-text-success" style="font-weight:bold;">بهینه‌شده با موفقیت</span>';
        } else {
            $html .= '<strong>وضعیت:</strong> <span class="wso-text-muted">در انتظار بهینه‌سازی</span>';
        }
        $html .= '</div>';

        $html .= '</div>';

        $fields['wso_webp_info'] = [
            'label' => 'بهینه چی',
            'input' => 'html',
            'html'  => $html,
        ];

        return $fields;
    }

    /**
     * AJAX handler for optimizing single attachment.
     *
     * @return void
     */
    public function handle_single_optimize(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'سطح دسترسی غیرمجاز است.'], 403);
        }
        check_ajax_referer('wso_admin_nonce', 'nonce');

        $attachment_id = (int) ($_POST['attachment_id'] ?? 0);
        if (!$attachment_id) {
            wp_send_json_error(['message' => 'شناسه تصویر نامعتبر است.']);
        }

        $res = Optimizer::instance()->optimize_attachment($attachment_id);

        wp_send_json_success([
            'message' => $res['message'] ?? 'تصویر با موفقیت بهینه‌سازی شد!',
            'data'    => $res,
        ]);
    }

    /**
     * AJAX handler for restoring original file.
     *
     * @return void
     */
    public function handle_single_restore(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'سطح دسترسی غیرمجاز است.'], 403);
        }
        check_ajax_referer('wso_admin_nonce', 'nonce');

        $attachment_id = (int) ($_POST['attachment_id'] ?? 0);
        $file = get_attached_file($attachment_id);

        if (!$file || !file_exists($file)) {
            wp_send_json_error(['message' => 'فایل اصلی یافت نشد.']);
        }

        $restored = Backup_Manager::instance()->restore($file);
        if ($restored) {
            delete_post_meta($attachment_id, '_wso_optimized');
            delete_post_meta($attachment_id, '_wso_opt_data');
            delete_post_meta($attachment_id, \WSO\Engine\Watermark::META_KEY);

            wp_send_json_success([
                'message' => 'تصویر اصلی اولیه با موفقیت بازگردانی شد!',
            ]);
        }

        wp_send_json_error(['message' => 'بازگردانی تصویر ناموفق بود؛ نسخه پشتیبان موجود نیست.']);
    }
}
