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
            @file_put_contents($htaccess, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
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

        // Determine the restored file's extension and update WordPress metadata
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        $attachment_id = 0;

        // Find attachment ID from file path
        global $wpdb;
        $upload_dir = wp_upload_dir();
        $basedir_norm = wp_normalize_path(trailingslashit($upload_dir['basedir']));
        $file_norm = wp_normalize_path($file_path);
        $relative_path = '';
        if (str_starts_with($file_norm, $basedir_norm)) {
            $relative_path = ltrim(substr($file_norm, strlen($basedir_norm)), '/');
        } else {
            $relative_path = ltrim(str_replace([$upload_dir['basedir'], '\\'], ['', '/'], $file_path), '/');
        }
        
        if (!empty($relative_path)) {
            $attachment_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
                $relative_path
            ));
        }

        if ($attachment_id > 0) {
            // Map extension to MIME type
            $mime_map = [
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png'  => 'image/png',
                'webp' => 'image/webp',
                'avif' => 'image/avif',
                'svg'  => 'image/svg+xml',
            ];
            $new_mime = $mime_map[$ext] ?? 'image/jpeg';

            // Update post MIME type
            $wpdb->update(
                $wpdb->posts,
                ['post_mime_type' => $new_mime],
                ['ID' => $attachment_id],
                ['%s'],
                ['%d']
            );

            // Update attachment metadata file reference
            $meta = wp_get_attachment_metadata($attachment_id);
            if (is_array($meta)) {
                $meta['file'] = $relative_path;
                wp_update_attachment_metadata($attachment_id, $meta);
            }

            // Clear optimization flags so the image can be re-optimized
            delete_post_meta($attachment_id, '_wso_optimized');
            delete_post_meta($attachment_id, '_wso_opt_data');
            delete_post_meta($attachment_id, '_wso_wm_hash');
        }

        delete_transient('wso_upload_dir_scan');

        return (bool) $restored;
    }

    /**
     * Bulk restores all backed-up images to pre-optimization state.
     *
     * @return int Number of restored files.
     */
    public function restore_all(): int {
        global $wpdb;
        $upload_dir = wp_upload_dir()['basedir'];
        $upload_dir = wp_normalize_path(trailingslashit($upload_dir));
        $backup_base = wp_normalize_path(trailingslashit($this->backup_dir));
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

                $src_norm = wp_normalize_path($item->getPathname());
                if (!str_starts_with($src_norm, $backup_base)) {
                    continue;
                }
                $relative = ltrim(substr($src_norm, strlen($backup_base)), '/');
                // Prevent directory traversal in stored names.
                if ('' === $relative || str_contains($relative, '..')) {
                    continue;
                }
                $original_target = $upload_dir . $relative;

                if (@copy($item->getPathname(), $original_target)) {
                    $count++;

                    // Remove associated WebP and AVIF
                    $info = pathinfo($original_target);
                    $webp = $info['dirname'] . '/' . $info['filename'] . '.webp';
                    $avif = $info['dirname'] . '/' . $info['filename'] . '.avif';

                    if (file_exists($webp)) @unlink($webp);
                    if (file_exists($avif)) @unlink($avif);

                    // Update WordPress attachment metadata
                    $ext = strtolower(pathinfo($original_target, PATHINFO_EXTENSION));
                    $attachment_id = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
                        $relative
                    ));

                    if ($attachment_id > 0) {
                        $mime_map = [
                            'jpg'  => 'image/jpeg',
                            'jpeg' => 'image/jpeg',
                            'png'  => 'image/png',
                            'webp' => 'image/webp',
                            'avif' => 'image/avif',
                            'svg'  => 'image/svg+xml',
                        ];
                        $new_mime = $mime_map[$ext] ?? 'image/jpeg';

                        $wpdb->update(
                            $wpdb->posts,
                            ['post_mime_type' => $new_mime],
                            ['ID' => $attachment_id],
                            ['%s'],
                            ['%d']
                        );

                        $meta = wp_get_attachment_metadata($attachment_id);
                        if (is_array($meta)) {
                            $meta['file'] = $relative;
                            wp_update_attachment_metadata($attachment_id, $meta);
                        }
                    }
                }
            }
        }

        // Clean attachment postmeta
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
