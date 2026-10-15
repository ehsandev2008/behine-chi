<?php
/**
 * Off-peak Scheduler + Throttle gate for bulk processing.
 *
 * @package WSO\Tools
 */

namespace WSO\Tools;

if (!defined('ABSPATH')) {
    exit;
}

class Scheduler {

    /**
     * Is bulk processing allowed right now?
     *
     * @return array{allowed: bool, reason: string}
     */
    public static function bulk_allowed(): array {
        try {
            $settings = \WSO\Core\Settings::instance();
            if (!(int) $settings->get('wso_offpeak_enabled', 0)) {
                return self::check_throttle();
            }
            $start = max(0, min(23, (int) $settings->get('wso_offpeak_start', 1)));
            $end = max(0, min(23, (int) $settings->get('wso_offpeak_end', 6)));
            $hour = (int) current_time('G');
            $in_window = ($start <= $end) ? ($hour >= $start && $hour < $end) : ($hour >= $start || $hour < $end);
            if (!$in_window) {
                return ['allowed' => false, 'reason' => sprintf('خارج از پنجره کم‌فشار (%d تا %d). پردازش در ساعات خلوت انجام می‌شود.', $start, $end)];
            }
            return self::check_throttle();
        } catch (\Throwable $e) {
            return ['allowed' => true, 'reason' => ''];
        }
    }

    private static function check_throttle(): array {
        try {
            $settings = \WSO\Core\Settings::instance();
            $per_min = max(0, (int) $settings->get('wso_throttle_per_minute', 0));
            if ($per_min <= 0) {
                return ['allowed' => true, 'reason' => ''];
            }
            $key = 'wso_throttle_' . gmdate('YmdHi');
            $count = (int) get_transient($key);
            if ($count >= $per_min) {
                return ['allowed' => false, 'reason' => 'سقف توان (throttle) این دقیقه پر شده؛ چند لحظه بعد تلاش کنید.'];
            }
            set_transient($key, $count + 1, 90);
            return ['allowed' => true, 'reason' => ''];
        } catch (\Throwable $e) {
            return ['allowed' => true, 'reason' => ''];
        }
    }
}
