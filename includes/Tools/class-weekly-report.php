<?php
/**
 * Weekly performance report -> Notifications (+Telegram/Slack via Webhook).
 *
 * @package WSO\Tools
 */

namespace WSO\Tools;

if (!defined('ABSPATH')) {
    exit;
}

class Weekly_Report {

    private static ?Weekly_Report $instance = null;

    public static function instance(): Weekly_Report {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wso_weekly_report', [$this, 'send']);
    }

    public function schedule(): void {
        if (!wp_next_scheduled('wso_weekly_report')) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'weekly', 'wso_weekly_report');
        }
    }

    public function unschedule(): void {
        $ts = wp_next_scheduled('wso_weekly_report');
        if ($ts) {
            wp_unschedule_event($ts, 'wso_weekly_report');
        }
    }

    public function build_summary(): array {
        $stats = [];
        try {
            $stats = \WSO\Admin\Dashboard_Widgets::get_stats();
        } catch (\Throwable $e) {}
        $week_logs = 0;
        $week_saved = 0;
        try {
            global $wpdb;
            $db = \WSO\Core\Database::instance();
            $since = gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $row = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) c, COALESCE(SUM(saved_bytes),0) s FROM {$db->logs_table} WHERE created_at >= %s", $since), ARRAY_A);
            $week_logs = (int) ($row['c'] ?? 0);
            $week_saved = (int) ($row['s'] ?? 0);
        } catch (\Throwable $e) {}
        return [
            'total_images' => $stats['total_images'] ?? 0,
            'optimized'    => $stats['optimized_images'] ?? 0,
            'saved_total'  => $stats['saved_size'] ?? '—',
            'week_count'   => $week_logs,
            'week_saved'   => size_format($week_saved, 2),
        ];
    }

    public function send(): void {
        try {
            $settings = \WSO\Core\Settings::instance();
            if (!(int) $settings->get('wso_weekly_report', 0)) {
                return;
            }
            $s = $this->build_summary();
            $msg = sprintf(
                'گزارش هفتگی بهینه‌چی: %d تصویر این هفته بهینه شد (%s صرفه‌جویی). جمع کل: %s بهینه از %s.',
                $s['week_count'],
                $s['week_saved'],
                (string) $s['optimized'],
                (string) $s['total_images']
            );
            Notifications::instance()->add('info', $msg);
            try {
                Webhook::instance()->notify_bulk_complete((int) $s['week_count'], (int) $s['week_count'], 0, (string) $s['week_saved']);
            } catch (\Throwable $e) {}
        } catch (\Throwable $e) {}
    }
}
