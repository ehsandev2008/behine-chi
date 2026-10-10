<?php
/**
 * Advanced Health Check Engine
 *
 * @package WSO\Tools
 */

namespace WSO\Tools;

use WSO\Core\Database;
use WSO\Core\Settings;
use WSO\Engine\Optimizer;
use WSO\Engine\Backup_Manager;

if (!defined('ABSPATH')) {
    exit;
}

class Health_Check {

    /**
     * Singleton instance.
     *
     * @var Health_Check|null
     */
    private static ?Health_Check $instance = null;

    /**
     * Returns the singleton instance.
     *
     * @return Health_Check
     */
    public static function instance(): Health_Check {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {}

    /**
     * Executes all environment scan checks.
     *
     * @return array
     */
    public function perform_scan(): array {
        $scan = [
            'server'      => $this->check_server(),
            'extensions'  => $this->check_extensions(),
            'formats'     => $this->check_formats(),
            'wordpress'   => $this->check_wordpress(),
            'integrity'   => $this->check_integrity(),
            'engine'      => $this->check_engine(),
        ];

        // Calculate score
        $score_data = $this->calculate_health_score($scan);
        $scan['score'] = $score_data['score'];
        $scan['status'] = $score_data['status'];
        $scan['status_text'] = $score_data['status_text'];

        return $scan;
    }

    /**
     * Inspects PHP server parameters.
     *
     * @return array
     */
    private function check_server(): array {
        $upload_dir = wp_upload_dir();
        $base_dir   = $upload_dir['basedir'];

        $disk_free = 'Unknown';
        $disk_bytes = function_exists('disk_free_space') ? @disk_free_space($base_dir) : false;
        if (false !== $disk_bytes && is_numeric($disk_bytes)) {
            $disk_free = size_format((float) $disk_bytes, 2);
        }

        return [
            'php_version' => [
                'label'   => 'نسخه PHP',
                'value'   => PHP_VERSION,
                'status'  => version_compare(PHP_VERSION, '7.4.0', '>=') ? 'success' : 'error',
                'desc'    => 'حداقل نسخه مورد نیاز PHP 7.4 می‌باشد.',
            ],
            'memory_limit' => [
                'label'   => 'حد حافظه (Memory Limit)',
                'value'   => ini_get('memory_limit'),
                'status'  => $this->parse_ini_bytes(ini_get('memory_limit')) >= $this->parse_ini_bytes('128M') ? 'success' : 'warning',
                'desc'    => 'مقدار پیشنهادی حداقل ۱۲۸ مگابایت است.',
            ],
            'max_execution_time' => [
                'label'   => 'حداکثر زمان اجرا (Max Execution Time)',
                'value'   => ini_get('max_execution_time') . ' ثانیه',
                'status'  => ((int) ini_get('max_execution_time') >= 30 || (int) ini_get('max_execution_time') === 0) ? 'success' : 'warning',
                'desc'    => 'مقدار پیشنهادی حداقل ۳۰ ثانیه می‌باشد.',
            ],
            'upload_max_filesize' => [
                'label'   => 'حداکثر حجم فایل آپلودی',
                'value'   => ini_get('upload_max_filesize'),
                'status'  => 'success',
                'desc'    => 'توسط تنظیمات سرور کنترل می‌شود.',
            ],
            'post_max_size' => [
                'label'   => 'حداکثر حجم داده ارسالی (Post Max Size)',
                'value'   => ini_get('post_max_size'),
                'status'  => 'success',
                'desc'    => 'توسط تنظیمات سرور کنترل می‌شود.',
            ],
            'disk_free_space' => [
                'label'   => 'فضای آزاد دیسک',
                'value'   => $disk_free,
                'status'  => $this->disk_status($disk_bytes),
                'desc'    => 'فضای خالی در پوشه آپلودها جهت ذخیره فایل‌های بهینه‌شده.',
            ],
            'folder_permissions' => [
                'label'   => 'دسترسی پوشه افزونه',
                'value'   => substr(sprintf('%o', fileperms(WSO_PATH)), -4),
                'status'  => is_writable(WSO_PATH) ? 'success' : 'error',
                'desc'    => 'پوشه افزونه باید قابل نوشتن و خواندن باشد.',
            ],
        ];
    }

    /**
     * Determines disk status from raw bytes (locale-independent).
     *
     * @param mixed $bytes Raw disk_free_space value or false.
     * @return string
     */
    private function disk_status($bytes): string {
        if (false === $bytes || !is_numeric($bytes)) {
            return 'warning';
        }
        $b = (float) $bytes;
        if ($b < 100 * 1024 * 1024) {
            return 'error';
        }
        if ($b < 500 * 1024 * 1024) {
            return 'warning';
        }
        return 'success';
    }

    /**
     * Verifies critical PHP extensions.
     *
     * @return array
     */
    private function check_extensions(): array {
        $extensions = [
            'gd'       => ['label' => 'GD Library', 'desc' => 'موتور پردازش تصویر استاندارد همراه PHP.'],
            'imagick'  => ['label' => 'Imagick', 'desc' => 'موتور پیشرفته پردازش تصویر با کیفیت بالا.'],
            'fileinfo' => ['label' => 'FileInfo', 'desc' => 'تشخیص نوع فایل‌ها و تصاویر جهت امنیت پردازش.'],
            'exif'     => ['label' => 'EXIF', 'desc' => 'حذف و بررسی متادیتای تصاویر.'],
            'mbstring' => ['label' => 'MBString', 'desc' => 'پشتیبانی از رشته‌های متنی چندبایتی فارسی.'],
            'json'     => ['label' => 'JSON', 'desc' => 'ارتباط‌های ناهمگام و درون‌ریزی تنظیمات.'],
            'zip'      => ['label' => 'ZIP', 'desc' => 'بسته‌بندی و بکاپ‌گیری فشرده.'],
            'openssl'  => ['label' => 'OpenSSL', 'desc' => 'اتصال امن و عملیات‌های رمزنگاری.'],
            'curl'     => ['label' => 'cURL', 'desc' => 'ارسال هدرها و بسته‌های اطلاعاتی.'],
            'dom'      => ['label' => 'DOM', 'desc' => 'پردازش کدهای ساختار یافته قالب.'],
            'xml'      => ['label' => 'XML Parser', 'desc' => 'تجزیه و تحلیل فایل‌های ساختار یافته.'],
        ];

        $results = [];
        foreach ($extensions as $ext => $info) {
            $loaded = extension_loaded($ext);
            
            // GD and Imagick are critical but at least one must be loaded.
            // Optional helpers (zip/openssl/curl/dom/xml) are warnings, not errors.
            $status = 'success';
            if (!$loaded) {
                if ($ext === 'imagick' || $ext === 'gd') {
                    $status = (extension_loaded('gd') || extension_loaded('imagick')) ? 'warning' : 'error';
                } elseif (in_array($ext, ['fileinfo', 'exif', 'mbstring', 'json'], true)) {
                    $status = 'error';
                } else {
                    $status = 'warning';
                }
            }

            $results[$ext] = [
                'label'  => $info['label'],
                'value'  => $loaded ? 'فعال' : 'غیرفعال',
                'status' => $status,
                'desc'   => $info['desc'],
            ];
        }

        return $results;
    }

    /**
     * Checks image format capabilities.
     *
     * @return array
     */
    private function check_formats(): array {
        $driver = Optimizer::instance()->get_driver();
        $is_imagick = (false !== strpos(get_class($driver), 'Imagick'));
        $engine_label = $is_imagick ? 'Imagick' : 'GD';

        $webp_supported = $driver->supports_webp();
        $avif_supported = $driver->supports_avif();

        return [
            'jpeg' => [
                'label'  => 'فرمت JPEG',
                'value'  => 'پشتیبانی می‌شود',
                'status' => 'success',
                'desc'   => 'پشتیبانی از پردازش فایل‌های JPG/JPEG توسط ' . $engine_label,
            ],
            'png' => [
                'label'  => 'فرمت PNG',
                'value'  => 'پشتیبانی می‌شود',
                'status' => 'success',
                'desc'   => 'پشتیبانی از پردازش فایل‌های PNG توسط ' . $engine_label,
            ],
            'gif' => [
                'label'  => 'فرمت GIF',
                'value'  => 'پشتیبانی می‌شود',
                'status' => 'success',
                'desc'   => 'پشتیبانی از خواندن فایل‌های GIF توسط ' . $engine_label,
            ],
            'webp' => [
                'label'  => 'فرمت WebP',
                'value'  => $webp_supported ? 'پشتیبانی می‌شود' : 'پشتیبانی نمی‌شود',
                'status' => $webp_supported ? 'success' : 'error',
                'desc'   => 'فرمت بهینه‌ساز پیش‌فرض افزونه مبتنی بر ' . $engine_label,
            ],
            'avif' => [
                'label'  => 'فرمت AVIF',
                'value'  => $avif_supported ? 'پشتیبانی می‌شود' : 'پشتیبانی نمی‌شود',
                'status' => $avif_supported ? 'success' : 'warning',
                'desc'   => 'فرمت نسل جدید با فشرده‌سازی بسیار بالا مبتنی بر ' . $engine_label,
            ],
        ];
    }

    /**
     * Inspects WordPress configurations.
     *
     * @return array
     */
    private function check_wordpress(): array {
        $upload_dir = wp_upload_dir();
        
        $wp_cron = true;
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            $wp_cron = false;
        }

        return [
            'wp_version' => [
                'label'  => 'نسخه وردپرس',
                'value'  => get_bloginfo('version'),
                'status' => version_compare(get_bloginfo('version'), '5.5', '>=') ? 'success' : 'warning',
                'desc'   => 'پیشنهاد می‌شود وردپرس نسخه ۵.۵ یا بالاتر باشد.',
            ],
            'uploads_dir' => [
                'label'  => 'مسیر آپلودها',
                'value'  => str_replace(ABSPATH, '', $upload_dir['basedir']),
                'status' => 'success',
                'desc'   => 'مسیر ذخیره‌سازی رسانه‌های وردپرس.',
            ],
            'uploads_writable' => [
                'label'  => 'خوانایی/نوشتار آپلودها',
                'value'  => is_writable($upload_dir['basedir']) ? 'قابل نوشتن' : 'غیر قابل نوشتن',
                'status' => is_writable($upload_dir['basedir']) ? 'success' : 'error',
                'desc'   => 'پوشه آپلودها جهت ذخیره و بهینه‌سازی باید کاملا قابل نوشتن باشد.',
            ],
            'wp_cron' => [
                'label'  => 'وردپرس کرون (WP Cron)',
                'value'  => $wp_cron ? 'فعال' : 'غیرفعال',
                'status' => $wp_cron ? 'success' : 'warning',
                'desc'   => 'وردپرس کرون جهت فرآیندهای صف در پس‌زمینه مفید است.',
            ],
            'multisite' => [
                'label'  => 'چندکاربره (Multisite)',
                'value'  => is_multisite() ? 'بله' : 'خیر',
                'status' => 'success',
                'desc'   => 'وضعیت شبکه بودن وردپرس.',
            ],
            'debug_mode' => [
                'label'  => 'حالت دیباگ (Debug Mode)',
                'value'  => (defined('WP_DEBUG') && WP_DEBUG) ? 'فعال' : 'خیر',
                'status' => 'success',
                'desc'   => 'نمایش خطاهای احتمالی وردپرس.',
            ],
        ];
    }

    /**
     * Checks files/folders integrity of the plugin.
     *
     * @return array
     */
    private function check_integrity(): array {
        $upload_dir = wp_upload_dir();
        
        $backup_dir = trailingslashit($upload_dir['basedir']) . 'wso-backups/';
        $cache_dir  = trailingslashit($upload_dir['basedir']) . 'wso-cache/';
        $tmp_dir    = trailingslashit($upload_dir['basedir']) . 'wso-tmp/';

        return [
            'folder_fonts' => [
                'label'  => 'پوشه فونت‌های محلی',
                'value'  => is_dir(WSO_PATH . 'assets/fonts') ? 'موجود' : 'ناموجود',
                'status' => is_dir(WSO_PATH . 'assets/fonts') ? 'success' : 'error',
                'desc'   => 'پوشه فونت‌ها در آدرس assets/fonts/ جهت بارگذاری فونت‌های محلی.',
            ],
            'folder_backup' => [
                'label'  => 'پوشه نسخه پشتیبان',
                'value'  => is_dir($backup_dir) ? 'موجود و قابل نوشتن' : 'ناموجود یا قفل‌شده',
                'status' => (is_dir($backup_dir) && is_writable($backup_dir)) ? 'success' : 'error',
                'desc'   => 'محل نگهداری فایل‌های اصلی تصاویر قبل از بهینه‌سازی.',
            ],
            'folder_cache' => [
                'label'  => 'پوشه کش فرمت‌ها',
                'value'  => is_dir($cache_dir) ? 'موجود' : 'ناموجود',
                'status' => is_dir($cache_dir) ? 'success' : 'warning',
                'desc'   => 'مسیر wso-cache در آپلودها جهت ذخیره فایل‌های کش.',
            ],
            'folder_tmp' => [
                'label'  => 'پوشه موقت افزونه',
                'value'  => is_dir($tmp_dir) ? 'موجود' : 'ناموجود',
                'status' => is_dir($tmp_dir) ? 'success' : 'warning',
                'desc'   => 'مسیر wso-tmp در آپلودها جهت ساخت فایل‌های تبدیل‌شده موقت.',
            ],
        ];
    }

    /**
     * Inspects active optimizer components.
     *
     * @return array
     */
    private function check_engine(): array {
        global $wpdb;
        $db = Database::instance();
        
        $queue_table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $db->queue_table)) === $db->queue_table;
        $logs_table_exists  = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $db->logs_table)) === $db->logs_table;

        $driver = Optimizer::instance()->get_driver();
        $is_imagick = (false !== strpos(get_class($driver), 'Imagick'));

        return [
            'queue_table' => [
                'label'  => 'جدول صف بهینه‌سازی',
                'value'  => $queue_table_exists ? 'آماده' : 'مفقود',
                'status' => $queue_table_exists ? 'success' : 'error',
                'desc'   => 'جدول wso_queue در دیتابیس جهت مدیریت پردازش دسته جمعی تصاویر.',
            ],
            'logs_table' => [
                'label'  => 'جدول گزارشات افزونه',
                'value'  => $logs_table_exists ? 'آماده' : 'مفقود',
                'status' => $logs_table_exists ? 'success' : 'error',
                'desc'   => 'جدول wso_logs در دیتابیس جهت نگهداری تاریخچه حجم‌های بهینه‌شده.',
            ],
            'active_driver' => [
                'label'  => 'موتور پردازشگر فعال',
                'value'  => $is_imagick ? 'Imagick Driver' : 'GD Driver',
                'status' => 'success',
                'desc'   => 'در صورت نصب بودن Imagick به طور خودکار به عنوان درایور ارجح انتخاب می‌شود.',
            ],
            'webp_generator' => [
                'label'  => 'واحد تولید WebP',
                'value'  => $driver->supports_webp() ? 'سالم (Healthy)' : 'خراب (Unhealthy)',
                'status' => $driver->supports_webp() ? 'success' : 'error',
                'desc'   => 'بررسی صحت عملکرد کتابخانه‌های سرور در تبدیل فرمت به WebP.',
            ],
            'avif_generator' => [
                'label'  => 'واحد تولید AVIF',
                'value'  => $driver->supports_avif() ? 'سالم (Healthy)' : 'پشتیبانی نمی‌شود',
                'status' => $driver->supports_avif() ? 'success' : 'warning',
                'desc'   => 'نیاز به نسخه جدید افزونه‌های سرور (به ویژه Imagick 7+ و libheif) دارد.',
            ],
        ];
    }

    /**
     * Calculates the health score percent and severity.
     *
     * @param array $scan Complete scan categories.
     * @return array Score percentage and state.
     */
    private function calculate_health_score(array $scan): array {
        $total_checks = 0;
        $failed_critical = 0;
        $failed_warnings = 0;

        foreach ($scan as $category => $items) {
            foreach ($items as $key => $check) {
                $total_checks++;
                if ($check['status'] === 'error') {
                    $failed_critical++;
                } elseif ($check['status'] === 'warning') {
                    $failed_warnings++;
                }
            }
        }

        // Critical failures cost 25 points each, Warnings cost 8 points each
        $score = 100 - ($failed_critical * 25) - ($failed_warnings * 8);
        $score = max(0, min(100, $score));

        $status = 'success';
        $status_text = 'عالی';

        if ($score >= 90) {
            $status = 'success';
            $status_text = 'عالی';
        } elseif ($score >= 70) {
            $status = 'warning';
            $status_text = 'متوسط';
        } else {
            $status = 'error';
            $status_text = 'بحرانی';
        }

        return [
            'score'       => $score,
            'status'      => $status,
            'status_text' => $status_text,
        ];
    }

    /**
     * Executes dynamic repair routines.
     *
     * @param string $fix_action Type of repair.
     * @return bool
     */
    public function repair(string $fix_action): bool {
        $allowed = ['create_folders', 'clear_cache', 'rebuild_config', 'reset_permissions'];
        if (!in_array($fix_action, $allowed, true)) {
            return false;
        }
        $upload_dir = wp_upload_dir();
        
        switch ($fix_action) {
            case 'create_folders':
                $backup_dir = trailingslashit($upload_dir['basedir']) . 'wso-backups/';
                $cache_dir  = trailingslashit($upload_dir['basedir']) . 'wso-cache/';
                $tmp_dir    = trailingslashit($upload_dir['basedir']) . 'wso-tmp/';

                foreach ([$backup_dir, $cache_dir, $tmp_dir] as $dir) {
                    if (!file_exists($dir)) {
                        wp_mkdir_p($dir);
                    }
                    // Try to write security files
                    @file_put_contents($dir . '.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
                    @file_put_contents($dir . 'index.php', "<?php // silence\n");
                }
                Notifications::instance()->add('success', 'پوشه‌های اختصاصی افزونه (wso-backups, wso-cache, wso-tmp) با موفقیت ساخته شدند.');
                return true;

            case 'clear_cache':
                $upload_dir_base = $upload_dir['basedir'];
                $cache_dir = trailingslashit($upload_dir_base) . 'wso-cache/';
                $tmp_dir   = trailingslashit($upload_dir_base) . 'wso-tmp/';

                // Clear these directory files
                foreach ([$cache_dir, $tmp_dir] as $dir) {
                    if (is_dir($dir)) {
                        $files = glob($dir . '*');
                        foreach ($files as $file) {
                            if (is_file($file) && !in_array(basename($file), ['.htaccess', 'index.php'], true)) {
                                @unlink($file);
                            }
                        }
                    }
                }
                Notifications::instance()->add('success', 'فایل‌های کش موقت افزونه با موفقیت پاکسازی و احیا گردیدند.');
                return true;

            case 'rebuild_config':
                // Reset settings defaults
                $settings = Settings::instance();
                $defaults = $settings->get_defaults();
                foreach ($defaults as $key => $val) {
                    $settings->set($key, $val);
                }
                Notifications::instance()->add('warning', 'پیکربندی افزونه به حالت پیش‌فرض کارخانه بازنشانی شد.');
                return true;

            case 'reset_permissions':
                $backup_dir = trailingslashit($upload_dir['basedir']) . 'wso-backups/';
                $cache_dir  = trailingslashit($upload_dir['basedir']) . 'wso-cache/';
                $tmp_dir    = trailingslashit($upload_dir['basedir']) . 'wso-tmp/';

                foreach ([$backup_dir, $cache_dir, $tmp_dir] as $dir) {
                    if (file_exists($dir)) {
                        @chmod($dir, 0755);
                    }
                }
                Notifications::instance()->add('success', 'مجوزهای دسترسی پوشه‌های افزونه در سرور مجدداً تنظیم شد.');
                return true;
        }

        return false;
    }

    /**
     * Parses byte format strings to numeric bytes value.
     *
     * @param string $val
     * @return int
     */
    private function parse_ini_bytes(string $val): int {
        $val  = trim($val);
        if ('' === $val) {
            return 0;
        }
        // -1 / 0 means unlimited in PHP ini.
        if ('-1' === $val) {
            return PHP_INT_MAX;
        }
        $last = strtolower($val[strlen($val) - 1]);
        if (!ctype_alpha($last)) {
            return (int) $val;
        }
        $num  = (int) $val;
        $bytes = $num;
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
                $bytes = $num;
                break;
        }
        return $bytes;
    }
    }
}
