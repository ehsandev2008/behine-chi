<?php
/**
 * Periodic Auto-Scan
 * WP Cron for automatic scanning and optimization.
 *
 * @package WSO\Tools
 */

namespace WSO\Tools;

if (!defined('ABSPATH')) {
    exit;
}

class Auto_Scan {

    private static ?Auto_Scan $instance = null;
    private string $cron_hook = 'wso_auto_scan';

    public static function instance(): Auto_Scan {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action($this->cron_hook, [$this, 'run_scan']);
        add_action('wso_auto_optimize', [$this, 'run_auto_optimize']);
    }

    /**
     * Schedule auto-scan cron.
     */
    public function schedule(): void {
        if (!wp_next_scheduled($this->cron_hook)) {
            wp_schedule_event(time(), 'daily', $this->cron_hook);
        }
    }

    /**
     * Unschedule auto-scan cron.
     */
    public function unschedule(): void {
        $timestamp = wp_next_scheduled($this->cron_hook);
        if ($timestamp) {
            wp_unschedule_event($timestamp, $this->cron_hook);
        }
    }

    /**
     * Run the auto-scan.
     */
    public function run_scan(): void {
        $settings = \WSO\Core\Settings::instance();

        if (!$settings->get('wso_auto_scan', 0)) {
            return;
        }

        // Find unoptimized images
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/webp'],
            'posts_per_page' => 10,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_wso_optimized',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ];

        $query = new \WP_Query($args);

        if (empty($query->posts)) {
            return;
        }

        $optimizer = \WSO\Engine\Optimizer::instance();
        $processed = 0;

        foreach ($query->posts as $attachment_id) {
            $optimizer->optimize_attachment((int) $attachment_id);
            $processed++;

            // Prevent memory leaks
            if ($processed % 5 === 0) {
                wp_cache_flush();
                gc_collect_cycles();
            }
        }

        // Log the scan
        if ($processed > 0) {
            Logger::log([
                'attachment_id' => 0,
                'file_name'     => 'Auto Scan',
                'original_size' => 0,
                'optimized_size'=> 0,
                'status'        => Logger::STATUS_SUCCESS,
                'message'       => sprintf('اسکن خودکار: %d تصویر بهینه‌سازی شد.', $processed),
            ]);
        }
    }

    /**
     * Run auto-optimize for a single attachment.
     */
    public function run_auto_optimize(int $attachment_id): void {
        $settings = \WSO\Core\Settings::instance();

        if (!$settings->get('wso_auto_optimize', 1)) {
            return;
        }

        $optimizer = \WSO\Engine\Optimizer::instance();
        $optimizer->optimize_attachment($attachment_id);
    }
}
