<?php
/**
 * Maintenance: backup retention, quota, log retention.
 *
 * @package WSO\Tools
 */

namespace WSO\Tools;

if (!defined('ABSPATH')) {
    exit;
}

class Maintenance {

    private static ?Maintenance $instance = null;

    public static function instance(): Maintenance {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wso_daily_maintenance', [$this, 'run']);
    }

    public function schedule(): void {
        if (!wp_next_scheduled('wso_daily_maintenance')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'wso_daily_maintenance');
        }
    }

    public function unschedule(): void {
        $ts = wp_next_scheduled('wso_daily_maintenance');
        if ($ts) {
            wp_unschedule_event($ts, 'wso_daily_maintenance');
        }
    }

    public function run(): void {
        $this->prune_backups();
        $this->prune_logs();
    }

    /**
     * Delete backups older than retention days + enforce quota (oldest first).
     *
     * @return array{deleted:int, freed:int}
     */
    public function prune_backups(): array {
        $deleted = 0;
        $freed = 0;
        try {
            $settings = \WSO\Core\Settings::instance();
            $retention = max(0, (int) $settings->get('wso_backup_retention_days', 0));
            $quota_mb = max(0, (int) $settings->get('wso_backup_quota_mb', 0));
            $upload_dir = wp_upload_dir();
            $base = trailingslashit($upload_dir['basedir']) . 'wso-backups/';
            if (!is_dir($base)) {
                return ['deleted' => 0, 'freed' => 0];
            }
            $files = [];
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($it as $item) {
                if (!$item->isFile()) {
                    continue;
                }
                $fn = $item->getFilename();
                if ('.htaccess' === $fn || 'index.php' === $fn) {
                    continue;
                }
                $files[] = ['path' => $item->getPathname(), 'mtime' => (int) $item->getMTime(), 'size' => (int) $item->getSize()];
            }
            // Retention.
            if ($retention > 0) {
                $cut = time() - $retention * DAY_IN_SECONDS;
                foreach ($files as $k => $f) {
                    if ($f['mtime'] < $cut) {
                        $sz = $f['size'];
                        if (@unlink($f['path'])) {
                            $deleted++;
                            $freed += $sz;
                        }
                        unset($files[$k]);
                    }
                }
                $files = array_values($files);
            }
            // Quota.
            if ($quota_mb > 0 && !empty($files)) {
                $total = array_sum(array_column($files, 'size'));
                $limit = $quota_mb * 1024 * 1024;
                if ($total > $limit) {
                    usort($files, static function ($a, $b) {
                        return $a['mtime'] <=> $b['mtime'];
                    });
                    foreach ($files as $f) {
                        if ($total <= $limit) {
                            break;
                        }
                        if (@unlink($f['path'])) {
                            $deleted++;
                            $freed += $f['size'];
                            $total -= $f['size'];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {}
        return ['deleted' => $deleted, 'freed' => $freed];
    }

    /**
     * Delete old log rows.
     *
     * @return int
     */
    public function prune_logs(): int {
        try {
            $settings = \WSO\Core\Settings::instance();
            $days = max(7, (int) $settings->get('wso_log_retention_days', 90));
            global $wpdb;
            $db = \WSO\Core\Database::instance();
            $cut = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $r = $wpdb->query($wpdb->prepare("DELETE FROM {$db->logs_table} WHERE created_at < %s LIMIT 5000", $cut));
            return is_int($r) ? $r : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function backup_dir_size(): int {
        try {
            $upload_dir = wp_upload_dir();
            $base = trailingslashit($upload_dir['basedir']) . 'wso-backups/';
            if (!is_dir($base)) {
                return 0;
            }
            $size = 0;
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($it as $item) {
                if ($item->isFile()) {
                    $size += (int) $item->getSize();
                }
            }
            return $size;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
