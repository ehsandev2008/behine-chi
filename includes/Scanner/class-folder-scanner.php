<?php
/**
 * اسکنر پوشه‌ها به زبان فارسی
 * Folder Scanner Class (100% Persian)
 *
 * @package WSO\Scanner
 */

namespace WSO\Scanner;

use WSO\Queue\Queue_Manager;

if (!defined('ABSPATH')) {
    exit;
}

class Folder_Scanner {

    /**
     * Singleton instance.
     *
     * @var Folder_Scanner|null
     */
    private static ?Folder_Scanner $instance = null;

    /**
     * Allowed folder types for scanning.
     *
     * @var array<string>
     */
    private const ALLOWED_TYPES = ['uploads', 'themes', 'plugins', 'custom'];

    /**
     * Supported image extensions.
     *
     * @var array<string>
     */
    private const ALLOWED_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'];

    /**
     * Max files returned per scan response (prevents timeouts on huge dirs).
     */
    private const SCAN_LIMIT = 200;

    /**
     * Returns the singleton instance.
     *
     * @return Folder_Scanner
     */
    public static function instance(): Folder_Scanner {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {
        add_action('wp_ajax_wso_scan_folder', [$this, 'handle_scan_folder']);
        add_action('wp_ajax_wso_queue_scanned_folder', [$this, 'handle_queue_scanned_folder']);
    }

    /**
     * Resolves and validates the scan target directory.
     *
     * Security: folder_type is allow-listed; custom paths must resolve via
     * realpath() to a location inside ABSPATH (prevents path traversal and
     * access outside the WordPress install).
     *
     * @param string $folder_type One of uploads|themes|plugins|custom.
     * @param string $custom_path Raw custom path input.
     * @return string|\WP_Error Normalized absolute dir or error.
     */
    public function resolve_target_dir(string $folder_type, string $custom_path) {
        $folder_type = in_array($folder_type, self::ALLOWED_TYPES, true) ? $folder_type : 'uploads';

        if ('uploads' === $folder_type) {
            $dir = wp_upload_dir()['basedir'];
        } elseif ('themes' === $folder_type) {
            $dir = get_theme_root();
        } elseif ('plugins' === $folder_type) {
            $dir = WP_PLUGIN_DIR;
        } else {
            $custom_path = trim(wp_unslash($custom_path));
            if ('' === $custom_path) {
                return new \WP_Error('wso_bad_path', 'مسیر سفارشی خالی است.');
            }
            // Block null bytes and traversal attempts early.
            if (str_contains($custom_path, "\0") || preg_match('/\.\.(\/|\\\\)/', $custom_path)) {
                return new \WP_Error('wso_bad_path', 'مسیر سفارشی نامعتبر است.');
            }
            $dir = $custom_path;
        }

        $dir = wp_normalize_path($dir);
        $real = realpath($dir);
        if (false === $real) {
            return new \WP_Error('wso_no_dir', 'پوشه انتخاب‌شده در سرور وجود ندارد.');
        }
        $real = wp_normalize_path($real);

        // Custom paths must stay inside the WordPress installation.
        $abspath = wp_normalize_path(ABSPATH);
        if ('custom' === $folder_type && !str_starts_with(trailingslashit($real), trailingslashit($abspath))) {
            return new \WP_Error('wso_forbidden', 'مسیر سفارشی باید داخل پوشه وردپرس باشد.');
        }

        if (!is_dir($real) || !is_readable($real)) {
            return new \WP_Error('wso_no_dir', 'پوشه انتخاب‌شده قابل خواندن نیست.');
        }

        return $real;
    }

    /**
     * Checks whether an absolute file path is inside an allowed scan root.
     *
     * @param string $file_path Absolute file path.
     * @return bool
     */
    public function is_path_allowed(string $file_path): bool {
        $norm = wp_normalize_path($file_path);
        $real = realpath($norm);
        $check = $real ? wp_normalize_path($real) : $norm;

        $roots = [
            wp_normalize_path(wp_upload_dir()['basedir']),
            wp_normalize_path(get_theme_root()),
            wp_normalize_path(WP_PLUGIN_DIR),
            wp_normalize_path(ABSPATH),
        ];

        foreach ($roots as $root) {
            if (str_starts_with($check, trailingslashit($root))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively scans directory for image files (capped).
     *
     * Skips the wso-backups directory so backups are never re-queued.
     *
     * @param string $dir_path Absolute directory path.
     * @param array $extensions Supported extensions.
     * @param int $limit Max files to collect.
     * @return array{files: array<int,string>, total_found: int, truncated: bool}
     */
    public function scan_directory(string $dir_path, array $extensions = self::ALLOWED_EXTS, int $limit = self::SCAN_LIMIT): array {
        $images = [];
        $total  = 0;
        $dir_path = wp_normalize_path($dir_path);
        if (!is_dir($dir_path) || !is_readable($dir_path)) {
            return ['files' => [], 'total_found' => 0, 'truncated' => false];
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir_path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $item) {
                if (!$item->isFile()) {
                    continue;
                }
                $pathname = wp_normalize_path($item->getPathname());
                // Never queue our own backups.
                if (str_contains($pathname, '/wso-backups/')) {
                    continue;
                }
                $ext = strtolower($item->getExtension());
                if (in_array($ext, $extensions, true)) {
                    $total++;
                    if (count($images) < $limit) {
                        $images[] = $pathname;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Handle any file system permission exception gracefully
        }

        return [
            'files'       => $images,
            'total_found' => $total,
            'truncated'   => $total > count($images),
        ];
    }

    /**
     * AJAX handler for scanning target folder.
     *
     * @return void
     */
    public function handle_scan_folder(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'سطح دسترسی غیرمجاز است.'], 403);
        }
        check_ajax_referer('wso_admin_nonce', 'nonce');

        $folder_type = sanitize_key($_POST['folder_type'] ?? 'uploads');
        $custom_path = isset($_POST['custom_path']) ? sanitize_text_field(wp_unslash($_POST['custom_path'])) : '';

        $target = $this->resolve_target_dir($folder_type, $custom_path);
        if ($target instanceof \WP_Error) {
            wp_send_json_error(['message' => $target->get_error_message()], 400);
        }

        $result = $this->scan_directory($target);

        wp_send_json_success([
            'folder'      => $target,
            'folder_type' => $folder_type,
            'count'       => $result['total_found'],
            'files'       => $result['files'],
            'truncated'   => $result['truncated'],
            'message'     => $result['truncated']
                ? sprintf('تعداد %d تصویر یافت شد (نمایش %d مورد اول).', $result['total_found'], count($result['files']))
                : sprintf('تعداد %d تصویر یافت شد.', $result['total_found']),
        ]);
    }

    /**
     * AJAX handler for adding scanned images to bulk queue.
     *
     * Accepts either:
     *  - files[]: explicit user-selected absolute paths (preferred, matches UI checkboxes), or
     *  - folder_type/custom_path: legacy re-scan of the folder (capped).
     *
     * Every path is validated server-side (existence, extension, allowed root).
     * Duplicates (any status) are skipped and reported.
     *
     * @return void
     */
    public function handle_queue_scanned_folder(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'سطح دسترسی غیرمجاز است.'], 403);
        }
        check_ajax_referer('wso_admin_nonce', 'nonce');

        $files = [];

        // Preferred path: explicit selection from the scan results UI.
        if (isset($_POST['files']) && is_array($_POST['files'])) {
            $raw = array_slice(array_map(static function ($v) {
                return is_string($v) ? sanitize_text_field(wp_unslash($v)) : '';
            }, $_POST['files']), 0, self::SCAN_LIMIT);

            foreach ($raw as $candidate) {
                if ('' === $candidate) {
                    continue;
                }
                $norm = wp_normalize_path($candidate);
                if (!$this->is_path_allowed($norm)) {
                    continue;
                }
                $real = realpath($norm);
                $resolved = $real ? wp_normalize_path($real) : $norm;
                if (!file_exists($resolved) || !is_file($resolved)) {
                    continue;
                }
                $ext = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
                if (!in_array($ext, self::ALLOWED_EXTS, true)) {
                    continue;
                }
                // MIME sanity check (SVG has no getimagesize; rely on extension + readability).
                if ('svg' !== $ext) {
                    $type = wp_check_filetype($resolved);
                    if (empty($type['type']) || !str_starts_with($type['type'], 'image/')) {
                        continue;
                    }
                }
                $files[] = $resolved;
            }

            if (empty($files)) {
                wp_send_json_error(['message' => 'هیچ فایل معتبر و مجوزی برای افزودن به صف انتخاب نشده است.'], 400);
            }
        } else {
            // Legacy fallback: re-scan folder (capped to prevent timeouts).
            $folder_type = sanitize_key($_POST['folder_type'] ?? 'uploads');
            $custom_path = isset($_POST['custom_path']) ? sanitize_text_field(wp_unslash($_POST['custom_path'])) : '';

            $target = $this->resolve_target_dir($folder_type, $custom_path);
            if ($target instanceof \WP_Error) {
                wp_send_json_error(['message' => $target->get_error_message()], 400);
            }

            $result = $this->scan_directory($target);
            $files  = $result['files'];
        }

        $outcome = Queue_Manager::instance()->populate_custom_paths($files);

        wp_send_json_success([
            'message'          => sprintf(
                'تعداد %d تصویر به صف بهینه‌سازی اضافه شد. (%d تکراری نادیده گرفته شد، %d نامعتبر بود.)',
                $outcome['queued'],
                $outcome['skipped_existing'],
                $outcome['skipped_invalid']
            ),
            'count'            => $outcome['queued'],
            'queued'           => $outcome['queued'],
            'skipped_existing' => $outcome['skipped_existing'],
            'skipped_invalid'  => $outcome['skipped_invalid'],
            'stats'            => Queue_Manager::instance()->get_stats(),
        ]);
    }
}
