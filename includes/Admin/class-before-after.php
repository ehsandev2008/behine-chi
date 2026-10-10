<?php
/**
 * Before/After Comparison
 * Slider comparison of images before and after optimization.
 *
 * @package WSO\Admin
 */

namespace WSO\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class Before_After {

    private static ?Before_After $instance = null;

    public static function instance(): Before_After {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_wso_get_comparison', [$this, 'get_comparison_data']);
    }

    /**
     * Get comparison data for an attachment.
     */
    public function get_comparison_data(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'سطح دسترسی غیرمجاز است.'], 403);
        }
        check_ajax_referer('wso_admin_nonce', 'nonce');

        $attachment_id = (int) ($_POST['attachment_id'] ?? 0);
        if (!$attachment_id) {
            wp_send_json_error(['message' => 'شناسه تصویر نامعتبر است.']);
        }

        $file = get_attached_file($attachment_id);
        $backup_manager = \WSO\Engine\Backup_Manager::instance();

        // Get original (backup) file
        $original_file = $backup_manager->get_backup_path($file);
        $has_backup = file_exists($original_file) && filesize($original_file) > 0;

        // Get current file
        $current_file = $file;
        $has_current = file_exists($current_file);

        if (!$has_backup && !$has_current) {
            wp_send_json_error(['message' => 'فایلی برای مقایسه یافت نشد.']);
        }

        wp_send_json_success([
            'has_comparison' => true,
            'original'       => [
                'url'  => $has_backup ? $this->path_to_url($original_file) : '',
                'size' => $has_backup ? filesize($original_file) : 0,
            ],
            'optimized'      => [
                'url'  => $has_current ? wp_get_attachment_url($attachment_id) : '',
                'size' => $has_current ? filesize($current_file) : 0,
            ],
            'savings'        => $has_backup && $has_current && filesize($original_file) > 0
                ? round((1 - filesize($current_file) / filesize($original_file)) * 100, 2)
                : 0,
        ]);
    }

    /**
     * Convert an absolute server path inside uploads to its URL.
     */
    private function path_to_url(string $path): string {
        $upload_dir = wp_upload_dir();
        $basedir = wp_normalize_path($upload_dir['basedir']);
        $baseurl = $upload_dir['baseurl'];
        $norm = wp_normalize_path($path);
        if (str_starts_with($norm, $basedir)) {
            return $baseurl . substr($norm, strlen($basedir));
        }
        return str_replace(wp_normalize_path(ABSPATH), site_url('/'), $norm);
    }

    /**
     * Render comparison slider HTML.
     */
    public function render_comparison_slider(int $attachment_id): string {
        $file = get_attached_file($attachment_id);
        $backup_manager = \WSO\Engine\Backup_Manager::instance();
        $original_file = $backup_manager->get_backup_path($file);
        $has_backup = file_exists($original_file) && filesize($original_file) > 0;

        if (!$has_backup) {
            return '<p class="text-muted">نسخه پشتیبان برای مقایسه موجود نیست.</p>';
        }

        $original_url = $this->path_to_url($original_file);
        $optimized_url = wp_get_attachment_url($attachment_id);

        ob_start();
        ?>
        <div class="wso-comparison-slider" data-attachment-id="<?php echo esc_attr($attachment_id); ?>">
            <div class="wso-comparison-container" style="position: relative; overflow: hidden; border-radius: 8px;">
                <img src="<?php echo esc_url($optimized_url); ?>" alt="بهینه‌شده" style="display: block; width: 100%;" />
                <div class="wso-comparison-overlay" style="position: absolute; top: 0; left: 0; width: 50%; overflow: hidden; border-right: 2px solid #fff;">
                    <img src="<?php echo esc_url($original_url); ?>" alt="اصلی" style="display: block; width: 100%; max-width: none;" />
                </div>
                <div class="wso-comparison-handle" style="position: absolute; top: 0; left: 50%; width: 4px; height: 100%; background: #fff; cursor: ew-resize; transform: translateX(-50%);"></div>
                <div class="wso-comparison-label wso-label-before" style="position: absolute; top: 10px; left: 10px; background: rgba(0,0,0,0.7); color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11px;">قبل</div>
                <div class="wso-comparison-label wso-label-after" style="position: absolute; top: 10px; right: 10px; background: rgba(0,0,0,0.7); color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11px;">بعد</div>
            </div>
            <div class="wso-comparison-info" style="margin-top: 10px; display: flex; justify-content: space-between; font-size: 12px;">
                <span>حجم اصلی: <strong><?php echo esc_html(size_format(filesize($original_file), 2)); ?></strong></span>
                <span>حجم بهینه: <strong><?php echo esc_html(size_format(filesize($file), 2)); ?></strong></span>
                <span>صرفه‌جویی: <strong style="color: #10b981;"><?php echo esc_html(round((1 - filesize($file) / filesize($original_file)) * 100, 2)); ?>٪</strong></span>
            </div>
        </div>
        <script>
        (function($) {
            $(document).ready(function() {
                $('.wso-comparison-container').on('mousemove', function(e) {
                    var $container = $(this);
                    var offset = $container.offset();
                    var x = e.pageX - offset.left;
                    var pct = Math.max(0, Math.min(100, (x / $container.width()) * 100));
                    $container.find('.wso-comparison-overlay').css('width', pct + '%');
                    $container.find('.wso-comparison-handle').css('left', pct + '%');
                });
            });
        })(jQuery);
        </script>
        <?php
        return ob_get_clean();
    }
}
