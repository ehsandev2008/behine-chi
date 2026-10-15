<?php
/**
 * پردازشگر بسته‌ای ناهمگام
 * Async Batch Processor AJAX Handler (100% Persian)
 *
 * @package WSO\Queue
 */

namespace WSO\Queue;

use WSO\Engine\Optimizer;
use WSO\Tools\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class Async_Processor {

    /**
     * Singleton instance.
     *
     * @var Async_Processor|null
     */
    private static ?Async_Processor $instance = null;

    /**
     * Returns the singleton instance.
     *
     * @return Async_Processor
     */
    public static function instance(): Async_Processor {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {
        add_action('wp_ajax_wso_build_queue', [$this, 'handle_build_queue']);
        add_action('wp_ajax_wso_process_batch', [$this, 'handle_process_batch']);
        add_action('wp_ajax_wso_get_queue_status', [$this, 'handle_get_queue_status']);
        add_action('wp_ajax_wso_reset_queue', [$this, 'handle_reset_queue']);
    }

    /**
     * Verifies security permission & nonce.
     *
     * @return void
     */
    private function verify_security(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'سطح دسترسی غیرمجاز است.'], 403);
        }
        check_ajax_referer('wso_admin_nonce', 'nonce');
    }

    /**
     * Populates the queue from media library attachments.
     *
     * @return void
     */
    public function handle_build_queue(): void {
        $this->verify_security();

        if (!wso_enabled()) {
            wp_send_json_error(['message' => 'افزونه غیرفعال است و امکان ساخت صف وجود ندارد.'], 400);
        }

        $bulk_mode = sanitize_key($_POST['bulk_mode'] ?? 'optimize');
        $target_format = strtolower(sanitize_key($_POST['target_format'] ?? ''));
        if (!in_array($bulk_mode, ['optimize', 'convert'], true)) {
            $bulk_mode = 'optimize';
        }
        if (!in_array($target_format, ['webp', 'avif'], true)) {
            $target_format = 'webp';
        }

        // Capability gate for convert mode: driver must support the target.
        if ('convert' === $bulk_mode) {
            try {
                $drv = Optimizer::instance()->get_driver();
                $supported = ('avif' === $target_format) ? $drv->supports_avif() : $drv->supports_webp();
                if (!$supported) {
                    wp_send_json_error(['message' => sprintf('درایور فعال سرور از فرمت %s پشتیبانی نمی‌کند.', strtoupper($target_format))], 400);
                }
            } catch (\Throwable $e) {}
        } else {
            $target_format = '';
        }

        $manager = Queue_Manager::instance();
        $new_count = $manager->populate_media_library_queue(200, 50, $target_format);
        $stats = $manager->get_stats();
        $total_pending = $stats['pending'] ?? 0;

        wp_send_json_success([
            'message'       => sprintf('تعداد %d تصویر جدید به صف افزوده شد. مجموع تصاویر در انتظار: %d', $new_count, $total_pending),
            'count'         => $total_pending,
            'newly_added'   => $new_count,
            'total_pending' => $total_pending,
            'stats'         => $stats,
            'bulk_mode'     => $bulk_mode,
            'target_format' => $target_format,
        ]);
    }

    /**
     * Processes a single batch of queue items.
     * Hardened for shared hosts: time-boxed, per-item isolated, memory-cleaned.
     *
     * @return void
     */
    public function handle_process_batch(): void {
        $this->verify_security();

        if (!wso_enabled()) {
            wp_send_json_error(['message' => 'افزونه غیرفعال است و امکان پردازش وجود ندارد.'], 400);
        }

        if (class_exists('WSO\\Tools\\Scheduler')) {
            $gate = \WSO\Tools\Scheduler::bulk_allowed();
            if (empty($gate['allowed'])) {
                wp_send_json_error(['message' => (string) ($gate['reason'] ?? 'پردازش در این زمان مجاز نیست.'), 'retry' => true], 429);
            }
        }

        // Time-box each batch so slow AVIF encodes cannot hit PHP max_execution_time.
        @set_time_limit(120);
        @ini_set('max_execution_time', '120');
        $batch_start = microtime(true);
        $max_seconds = 25;

        $batch_size = (int) ($_POST['batch_size'] ?? 3);
        $batch_size = max(1, min(5, $batch_size));

        $bulk_mode = sanitize_key($_POST['bulk_mode'] ?? 'optimize');
        $target_format = strtolower(sanitize_key($_POST['target_format'] ?? 'webp'));
        if (!in_array($bulk_mode, ['optimize', 'convert'], true)) {
            $bulk_mode = 'optimize';
        }
        if (!in_array($target_format, ['webp', 'avif'], true)) {
            $target_format = 'webp';
        }
        // AVIF encode is CPU-heavy: use smaller batches to stay within time-box.
        if ('convert' === $bulk_mode && 'avif' === $target_format) {
            $batch_size = min($batch_size, 2);
        }
        $quality = max(1, min(100, (int) ($_POST['quality'] ?? (int) \WSO\Core\Settings::instance()->get('wso_quality', 82))));

        $manager   = Queue_Manager::instance();
        $optimizer = Optimizer::instance();

        $items = $manager->get_pending_batch($batch_size);

        if (empty($items)) {
            $stats = $manager->get_stats();
            wp_send_json_success([
                'completed' => (0 === (int) ($stats['pending'] ?? 0)),
                'message'   => 'عملیات صف بهینه‌سازی همگانی پایان یافت.',
                'processed' => 0,
                'stats'     => $stats,
            ]);
        }

        $processed_results = [];
        foreach ($items as $item) {
            // Stop before timeout: leave rest pending for the next request.
            if ((microtime(true) - $batch_start) > $max_seconds) {
                break;
            }
            $manager->update_status((int) $item['id'], 'processing');

            $attachment_id = (int) $item['attachment_id'];
            $file_path     = wp_normalize_path((string) $item['file_path']);

            try {
                // Revalidate file at processing time (TOCTOU guard).
                if ($attachment_id > 0) {
                    $current = get_attached_file($attachment_id);
                    if (!$current || !file_exists($current)) {
                        throw new \Exception('فایل رسانه یافت نشد.');
                    }
                    if ('convert' === $bulk_mode) {
                        $converter = \WSO\Engine\Format_Converter::instance();
                        $res = $converter->convert_attachment($attachment_id, $target_format, $quality);
                    } else {
                        $res = $optimizer->optimize_attachment($attachment_id);
                    }
                } else {
                    if (!file_exists($file_path) || !is_file($file_path) || !is_readable($file_path)) {
                        throw new \Exception('فایل صف یافت نشد.');
                    }
                    if ('convert' === $bulk_mode) {
                        $converter = \WSO\Engine\Format_Converter::instance();
                        $res = $converter->convert($file_path, $target_format, $quality);
                        if (!empty($res['success'])) {
                            $res['status'] = Logger::STATUS_SUCCESS;
                        } else {
                            $res['status'] = Logger::STATUS_ERROR;
                        }
                    } else {
                        $res = $optimizer->optimize_file($file_path);
                    }
                }
            } catch (\Throwable $e) {
                $res = ['status' => Logger::STATUS_ERROR, 'message' => $e->getMessage()];
            }

            $status = $res['status'] ?? Logger::STATUS_ERROR;
            $msg    = isset($res['message']) ? substr(sanitize_text_field((string) $res['message']), 0, 500) : '';

            $manager->update_status((int) $item['id'], $status, $msg);
            $processed_results[] = [
                'id'      => (int) $item['id'],
                'file'    => basename($file_path),
                'status'  => $status,
                'message' => $msg,
            ];
            // Free image memory between items.
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            wp_cache_flush();
        }

        $stats = $manager->get_stats();
        $completed = (0 === (int) ($stats['pending'] ?? 0));

        wp_send_json_success([
            'completed' => $completed,
            'processed' => count($processed_results),
            'results'   => $processed_results,
            'stats'     => $stats,
        ]);
    }

    /**
     * Gets current queue status.
     *
     * @return void
     */
    public function handle_get_queue_status(): void {
        $this->verify_security();
        $manager = Queue_Manager::instance();
        wp_send_json_success([
            'stats' => $manager->get_stats(),
        ]);
    }

    /**
     * Resets/clears queue.
     *
     * @return void
     */
    public function handle_reset_queue(): void {
        $this->verify_security();
        $manager = Queue_Manager::instance();
        $manager->clear();
        wp_send_json_success([
            'message' => 'صف با موفقیت بازنشانی شد.',
            'stats'   => $manager->get_stats(),
        ]);
    }
}
