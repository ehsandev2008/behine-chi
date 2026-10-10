<?php
/**
 * Auto Alerts
 * Server performance and disk space monitoring.
 *
 * @package WSO\Tools
 */

namespace WSO\Tools;

if (!defined('ABSPATH')) {
    exit;
}

class Auto_Alert {

    private static ?Auto_Alert $instance = null;

    public static function instance(): Auto_Alert {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wso_daily_health_check', [$this, 'check_and_notify']);
    }

    /**
     * Schedule daily health check.
     */
    public function schedule(): void {
        if (!wp_next_scheduled('wso_daily_health_check')) {
            wp_schedule_event(time(), 'daily', 'wso_daily_health_check');
        }
    }

    /**
     * Unschedule daily health check.
     */
    public function unschedule(): void {
        $timestamp = wp_next_scheduled('wso_daily_health_check');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'wso_daily_health_check');
        }
    }

    /**
     * Check system health and send alerts if needed.
     */
    public function check_and_notify(): void {
        $settings = \WSO\Core\Settings::instance();
        if (!$settings->get('wso_auto_alert', 0)) {
            return;
        }

        $alerts = [];

        // Check disk space
        $upload_dir = wp_upload_dir()['basedir'];
        $disk_free = @disk_free_space($upload_dir);
        $disk_total = @disk_total_space($upload_dir);

        if ($disk_free !== false && $disk_total !== false) {
            $free_percent = round(($disk_free / $disk_total) * 100, 1);
            if ($free_percent < 10) {
                $alerts[] = sprintf(
                    'فضای دیسک رو به اتماست! فقط %s٪ فضا باقی مانده (%s آزاد).',
                    $free_percent,
                    size_format($disk_free, 2)
                );
            } elseif ($free_percent < 20) {
                $alerts[] = sprintf(
                    'هشدار فضای دیسک: %s٪ فضا باقی مانده (%s آزاد).',
                    $free_percent,
                    size_format($disk_free, 2)
                );
            }
        }

        // Check memory limit
        $memory_limit = ini_get('memory_limit');
        $memory_bytes = $this->parse_memory_limit($memory_limit);
        if ($memory_bytes > 0 && $memory_bytes < 128 * 1024 * 1024) {
            $alerts[] = sprintf(
                'محدودیت حافظه PHP پایین است: %s. حداقل 128MB توصیه می‌شود.',
                $memory_limit
            );
        }

        // Check backup directory size
        $backup_size = $this->get_backup_size();
        $backup_threshold = (int) $settings->get('wso_backup_alert_threshold', 500);
        if ($backup_threshold > 0 && $backup_size > ($backup_threshold * 1024 * 1024)) {
            $alerts[] = sprintf(
                'حجم پوشه بکاپ از %s مگابایت گذشته است (فعلی: %s). پاکسازی بکاپ‌ها را پیشنهاد می‌کنیم.',
                $backup_threshold,
                size_format($backup_size, 2)
            );
        }

        // Check failed queue items
        $queue_stats = \WSO\Queue\Queue_Manager::instance()->get_stats();
        if (($queue_stats['failed'] ?? 0) > 10) {
            $alerts[] = sprintf(
                '%d تصویر در صف به دلیل خطا شکست خورده‌اند. لطفاً لاگ‌ها را بررسی کنید.',
                (int) $queue_stats['failed']
            );
        }

        // Send alerts
        if (!empty($alerts)) {
            foreach ($alerts as $alert) {
                Notifications::instance()->add('warning', $alert);
            }

            // Send webhook notification (both channels when configured).
            $webhook = Webhook::instance();
            $text = "⚠️ هشدارهای بهینه چی:\n" . implode("\n", $alerts);
            $webhook->send_slack($text);
            $webhook->send_telegram(strip_tags($text));
        }
    }

    /**
     * Parse memory limit string to bytes.
     */
    private function parse_memory_limit(string $limit): int {
        $limit = trim($limit);
        if ('' === $limit || '-1' === $limit) {
            return 0;
        }
        $last = strtolower($limit[strlen($limit) - 1]);
        if (!ctype_alpha($last)) {
            return (int) $limit;
        }
        $val = (int) $limit;
        $bytes = $val;
        switch ($last) {
            case 'g':
                $bytes *= 1024;
                // fall-through
            case 'm':
                $bytes *= 1024;
                // fall-through
            case 'k':
                $bytes *= 1024;
                break;
            default:
                $bytes = $val;
                break;
        }
        return $bytes;
    }

    /**
     * Get total size of backup directory.
     */
    private function get_backup_size(): int {
        $upload_dir = wp_upload_dir();
        $backup_dir = trailingslashit($upload_dir['basedir']) . 'wso-backups/';

        if (!is_dir($backup_dir)) {
            return 0;
        }

        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($backup_dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }
}
