<?php
/**
 * مدیریت کش و پاکسازی به زبان فارسی
 * Cache & Cleanup Manager Class (100% Persian)
 *
 * @package WSO\Tools
 */

namespace WSO\Tools;

if (!defined('ABSPATH')) {
    exit;
}

class Cache_Manager {

    /**
     * Singleton instance.
     *
     * @var Cache_Manager|null
     */
    private static ?Cache_Manager $instance = null;

    /**
     * Returns the singleton instance.
     *
     * @return Cache_Manager
     */
    public static function instance(): Cache_Manager {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {
        add_action('wp_ajax_wso_clear_generated_cache', [$this, 'handle_clear_generated_cache']);
        add_action('wp_ajax_wso_clear_backups', [$this, 'handle_clear_backups']);
    }

    /**
     * Clears generated WebP and AVIF files across uploads directory.
     *
     * @return int Count of deleted files.
     */
    public function clear_generated_files(): int {
        $upload_dir = wp_upload_dir()['basedir'];
        $count = 0;

        if (!is_dir($upload_dir)) {
            return $count;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($upload_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $pathname = $item->getPathname();
                $ext = strtolower($item->getExtension());
                
                // Skip files in backup directory
                if (str_contains($pathname, '/wso-backups/')) {
                    continue;
                }
                
                // Only delete WebP/AVIF files that are NOT the main attached file
                // (i.e., they are generated variants, not originals)
                if (in_array($ext, ['webp', 'avif'], true)) {
                    // Check if this file is the main attachment file
                    $relative_path = ltrim(str_replace($upload_dir, '', $pathname), '/\\');
                    global $wpdb;
                    $is_main = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s",
                        $relative_path
                    ));
                    
                    if (!$is_main) {
                        if (@unlink($pathname)) {
                            $count++;
                        }
                    }
                }
            }
        }

        delete_transient('wso_upload_dir_scan');

        return $count;
    }

    /**
     * AJAX handler for clearing generated cache.
     *
     * @return void
     */
    public function handle_clear_generated_cache(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'سطح دسترسی غیرمجاز است.'], 403);
        }
        check_ajax_referer('wso_admin_nonce', 'nonce');

        $deleted = $this->clear_generated_files();

        wp_send_json_success([
            'message' => sprintf('تعداد %d فایل کش WebP و AVIF با موفقیت پاکسازی شد.', $deleted),
            'deleted' => $deleted,
        ]);
    }

    /**
     * AJAX handler for clearing backup directory.
     *
     * @return void
     */
    public function handle_clear_backups(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'سطح دسترسی غیرمجاز است.'], 403);
        }
        check_ajax_referer('wso_admin_nonce', 'nonce');

        $upload_dir = wp_upload_dir();
        $backup_dir = trailingslashit($upload_dir['basedir']) . 'wso-backups/';
        $count = 0;

        if (is_dir($backup_dir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($backup_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getPathname());
                } else {
                    $name = $file->getFilename();
                    if ($name !== '.htaccess' && $name !== 'index.php') {
                        if (@unlink($file->getPathname())) {
                            $count++;
                        }
                    }
                }
            }
        }

        wp_send_json_success([
            'message' => sprintf('تعداد %d فایل پشتیبان با موفقیت پاکسازی شد.', $count),
            'deleted' => $count,
        ]);
    }
}
