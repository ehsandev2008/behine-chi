<?php
/**
 * مدیریت پشتیبان‌گیری از تصاویر اصلی
 * Backup Manager for Original Images
 *
 * @package WSO\Engine
 */

namespace WSO\Engine;

if (!defined('ABSPATH')) {
    exit;
}

class Backup_Manager {

    /**
     * Singleton instance.
     *
     * @var Backup_Manager|null
     */
    private static ?Backup_Manager $instance = null;

    /**
     * Backup directory path.
     */
    private string $backup_dir;

    /**
     * Returns the singleton instance.
     *
     * @return Backup_Manager
     */
    public static function instance(): Backup_Manager {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {
        $upload_dir = wp_upload_dir();
        $this->backup_dir = trailingslashit($upload_dir['basedir']) . 'wso-backups/';
        $this->ensure_backup_dir();

        add_action('wp_ajax_wso_restore_all_backups', [$this, 'handle_restore_all_backups']);
    }

    /**
     * Ensures backup directory exists and is protected.
     *
     * @return void
     */
    private function ensure_backup_dir(): void {
        if (!file_exists($this->backup_dir)) {
            wp_mkdir_p($this->backup_dir);
        }

        $htaccess = $this->backup_dir . '.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "deny from all\n");
        }

        $index = $this->backup_dir . 'index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php // سکوت طلاست\n");
        }
    }

    /**
     * Gets relative backup target path for a file.
     *
     * @param string $file_path
     * @return string
     */
    public function get_backup_path(string $file_path): string {
        $upload_dir = wp_upload_dir();
        $base_dir   = wp_normalize_path(trailingslashit($upload_dir['basedir']));
        $file_path  = wp_normalize_path($file_path);

        if (str_starts_with($file_path, $base_dir)) {
            $relative = substr($file_path, strlen($base_dir));
        } else {
            $relative = ltrim(str_replace([':', '/', '\\'], '_', $file_path), '_');
        }

        return wp_normalize_path($this->backup_dir . $relative);
    }

    /**
     * Safe backup of an original file before any modification.
     * Returns true if backup already exists or was successfully created.
     *
     * @param string $file_path
     * @return bool
     */
    public function backup(string $file_path): bool {
        if (!file_exists($file_path)) {
            return false;
        }

        $backup_path = $this->get_backup_path($file_path);
        if (file_exists($backup_path) && filesize($backup_path) > 0) {
            return true;
        }

        $dir = dirname($backup_path);
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        $copied = @copy($file_path, $backup_path);
        return $copied && file_exists($backup_path) && filesize($backup_path) > 0;
    }

    /**
     * Checks if backup exists for a file.
     *
     * @param string $file_path
     * @return bool
     */
    public function has_backup(string $file_path): bool {
        $path = $this->get_backup_path($file_path);
        return file_exists($path) && filesize($path) > 0;
    }

    /**
     * Restores a single file from its backup.
     *
     * @param string $file_path
     * @return bool
     */
    public function restore(string $file_path): bool {
        $backup_path = $this->get_backup_path($file_path);
        if (!file_exists($backup_path) || filesize($backup_path) === 0) {
            return false;
        }

        $restored = @copy($backup_path, $file_path);

        // Also clean up WebP and AVIF generated variants
        $info = pathinfo($file_path);
        $webp = $info['dirname'] . '/' . $info['filename'] . '.webp';
        $avif = $info['dirname'] . '/' . $info['filename'] . '.avif';

        if (file_exists($webp)) {
            @unlink($webp);
        }
        if (file_exists($avif)) {
            @unlink($avif);
        }

        return (bool) $restored;
    }

    /**
     * Bulk restores all backed-up images to pre-optimization state.
     *
     * @return int Number of restored files.
     */
    public function restore_all(): int {
        $upload_dir = wp_upload_dir()['basedir'];
        $count = 0;

        if (!is_dir($this->backup_dir)) {
            return $count;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->backup_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $filename = $item->getFilename();
                if ($filename === '.htaccess' || $filename === 'index.php') {
                    continue;
                }

                $relative = substr($item->getPathname(), strlen($this->backup_dir));
                $original_target = trailingslashit($upload_dir) . $relative;

                if (@copy($item->getPathname(), $original_target)) {
                    $count++;

                    // Remove associated WebP and AVIF
                    $info = pathinfo($original_target);
                    $webp = $info['dirname'] . '/' . $info['filename'] . '.webp';
                    $avif = $info['dirname'] . '/' . $info['filename'] . '.avif';

                    if (file_exists($webp)) @unlink($webp);
                    if (file_exists($avif)) @unlink($avif);
                }
            }
        }

        // Clean attachment postmeta
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_wso_optimized', '_wso_opt_data', '_wso_wm_hash')");

        return $count;
    }

    /**
     * AJAX handler for restoring all images to original state.
     *
     * @return void
     */
    public function handle_restore_all_backups(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'سطح دسترسی غیرمجاز است.'], 403);
        }
        check_ajax_referer('wso_admin_nonce', 'nonce');

        $restored_count = $this->restore_all();

        wp_send_json_success([
            'message'  => sprintf('تعداد %d تصویر با موفقیت به حالت اولیه پیش از بهینه‌سازی بازگردانی شدند.', $restored_count),
            'count'    => $restored_count,
        ]);
    }
}
