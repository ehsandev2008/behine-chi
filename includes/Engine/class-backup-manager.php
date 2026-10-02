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
     * Resolves existing backup path for a file, checking candidate extensions
     * in case the working file was converted to WebP or AVIF.
     *
     * @param string $file_path
     * @return string|null
     */
    public function find_backup_path(string $file_path): ?string {
        $exact = $this->get_backup_path($file_path);
        if (file_exists($exact) && filesize($exact) > 0) {
            return $exact;
        }

        $info = pathinfo($exact);
        $base_name = $info['dirname'] . '/' . $info['filename'];
        $candidates = ['jpg', 'jpeg', 'png', 'webp', 'avif', 'svg'];
        foreach ($candidates as $ext) {
            $candidate_path = $base_name . '.' . $ext;
            if (file_exists($candidate_path) && filesize($candidate_path) > 0) {
                return $candidate_path;
            }
        }

        return null;
    }

    /**
     * Checks if backup exists for a file.
     *
     * @param string $file_path
     * @return bool
     */
    public function has_backup(string $file_path): bool {
        return null !== $this->find_backup_path($file_path);
    }

    /**
     * Restores a single file from its backup.
     *
     * @param string $file_path
     * @return string|bool Restored file path on success, false on failure.
     */
    public function restore(string $file_path) {
        $backup_path = $this->find_backup_path($file_path);
        if (!$backup_path || !file_exists($backup_path) || filesize($backup_path) === 0) {
            return false;
        }

        $backup_ext = strtolower(pathinfo($backup_path, PATHINFO_EXTENSION));
        $file_info = pathinfo($file_path);
        $target_path = $file_info['dirname'] . '/' . $file_info['filename'] . '.' . $backup_ext;

        $restored = @copy($backup_path, $target_path);
        if (!$restored) {
            return false;
        }

        // Clean up WebP and AVIF variants if original wasn't of that type
        $webp = $file_info['dirname'] . '/' . $file_info['filename'] . '.webp';
        $avif = $file_info['dirname'] . '/' . $file_info['filename'] . '.avif';

        if ($backup_ext !== 'webp' && file_exists($webp)) {
            @unlink($webp);
        }
        if ($backup_ext !== 'avif' && file_exists($avif)) {
            @unlink($avif);
        }

        return $target_path;
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

        // Revert attachment records in database before deleting meta
        global $wpdb;
        $optimized_attachments = $wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wso_optimized' AND meta_value = '1'");
        if (!empty($optimized_attachments)) {
            if (file_exists(ABSPATH . 'wp-admin/includes/image.php')) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }
            $basedir = wp_upload_dir()['basedir'];

            foreach ($optimized_attachments as $att_id) {
                $att_id = (int) $att_id;
                $current_file = get_attached_file($att_id);
                if (!$current_file) {
                    continue;
                }

                $ext = strtolower(pathinfo($current_file, PATHINFO_EXTENSION));
                if (in_array($ext, ['webp', 'avif'], true)) {
                    $base = pathinfo($current_file, PATHINFO_DIRNAME) . '/' . pathinfo($current_file, PATHINFO_FILENAME);
                    foreach (['jpg', 'jpeg', 'png', 'svg'] as $orig_ext) {
                        $candidate = $base . '.' . $orig_ext;
                        if (file_exists($candidate)) {
                            $rel = ltrim(str_replace($basedir, '', $candidate), '/\\');
                            update_post_meta($att_id, '_wp_attached_file', $rel);
                            $type = wp_check_filetype($candidate);
                            if (!empty($type['type'])) {
                                $wpdb->update($wpdb->posts, ['post_mime_type' => $type['type']], ['ID' => $att_id]);
                            }
                            if (function_exists('wp_generate_attachment_metadata')) {
                                $new_meta = wp_generate_attachment_metadata($att_id, $candidate);
                                if (is_array($new_meta)) {
                                    wp_update_attachment_metadata($att_id, $new_meta);
                                }
                            }
                            break;
                        }
                    }
                }
            }
        }

        // Clean attachment postmeta
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_wso_optimized', '_wso_opt_data', '_wso_wm_hash')");

        // Clear cached stats transient
        delete_transient('wso_dashboard_folder_stats');

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
