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

        $force = !empty($_POST['force']);
        $manager = Queue_Manager::instance();
        $new_count = $manager->populate_media_library_queue($force);
        $stats = $manager->get_stats();
        $total_pending = $stats['pending'] ?? 0;

        wp_send_json_success([
            'message'       => sprintf('تعداد %d تصویر جدید به صف افزوده شد. مجموع تصاویر در انتظار: %d', $new_count, $total_pending),
            'count'         => $total_pending,
            'newly_added'   => $new_count,
            'total_pending' => $total_pending,
            'stats'         => $stats,
        ]);
    }

    /**
     * Processes a single batch of queue items.
     *
     * @return void
     */
    public function handle_process_batch(): void {
        $this->verify_security();

        if (!wso_enabled()) {
            wp_send_json_error(['message' => 'افزونه غیرفعال است و امکان پردازش وجود ندارد.'], 400);
        }

        $batch_size = (int) ($_POST['batch_size'] ?? 5);
        $batch_size = max(1, min(20, $batch_size));

        $manager   = Queue_Manager::instance();
        $optimizer = Optimizer::instance();

        $items = $manager->get_pending_batch($batch_size);

        if (empty($items)) {
            wp_send_json_success([
                'completed' => true,
                'message'   => 'عملیات صف بهینه‌سازی همگانی پایان یافت.',
                'processed' => 0,
                'stats'     => $manager->get_stats(),
            ]);
        }

        $processed_results = [];
        foreach ($items as $item) {
            $manager->update_status($item['id'], 'processing');

            $attachment_id = (int) $item['attachment_id'];
            $file_path     = $item['file_path'];

            if ($attachment_id > 0) {
                $res = $optimizer->optimize_attachment($attachment_id);
            } else {
                $res = $optimizer->optimize_file($file_path);
            }

            $status = $res['status'] ?? Logger::STATUS_ERROR;
            $msg    = $res['message'] ?? '';

            // Map Logger status constants to queue statuses
            $queue_status = match ($status) {
                Logger::STATUS_SUCCESS => 'completed',
                Logger::STATUS_SKIPPED => 'skipped',
                default                => 'failed',
            };

            $manager->update_status($item['id'], $queue_status, $msg);
            $processed_results[] = [
                'id'      => $item['id'],
                'file'    => basename($file_path),
                'status'  => $status,
                'message' => $msg,
            ];
        }

        wp_send_json_success([
            'completed' => false,
            'processed' => count($processed_results),
            'results'   => $processed_results,
            'stats'     => $manager->get_stats(),
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
