<?php
/**
 * Settings Management Class
 *
 * @package WSO\Core
 */

namespace WSO\Core;

if (!defined('ABSPATH')) {
    exit;
}

class Settings {

    /**
     * Singleton instance.
     *
     * @var Settings|null
     */
    private static ?Settings $instance = null;

    /**
     * Option key prefix.
     */
    public const PREFIX = 'wso_';

    /**
     * Default options map.
     *
     * @var array<string, mixed>
     */
    private array $defaults = [
        'wso_enable'           => 1,
        'wso_quality'          => 75,
        'wso_delete_original' => 0,
        'wso_max_size'         => 2, // MB
        'wso_convert_webp'     => 1,
        'wso_convert_avif'     => 0,
        'wso_strip_exif'       => 1,
        'wso_backup_originals' => 1,
        'wso_optimize_svg'     => 1,
        'wso_max_width'        => 2560,
        'wso_max_height'       => 2560,
        'wso_auto_optimize'    => 1,
        'wso_dark_mode'        => 0,
        // Watermark settings (all off/empty by default: zero behavior change until configured).
        'wso_watermark_enabled'  => 0,
        'wso_watermark_image'    => 0,
        'wso_watermark_position' => 'bottom-right',
        'wso_watermark_opacity'  => 70,
        'wso_watermark_margin'   => 12,
        // Automatic Alt Text (opt-in; never overwrites manual alts by default).
        'wso_auto_alt_enabled'   => 0,
        'wso_auto_alt_overwrite' => 0,
        'wso_webp_recompress_threshold' => 0,
        'wso_preload_webp' => 0,
        'wso_smart_lazy_load' => 0,
        'wso_auto_scan' => 0,
        'wso_on_the_fly' => 0,
        'wso_cloudinary_cloud' => '',
        'wso_cloudinary_key' => '',
        'wso_cloudinary_secret' => '',
        'wso_slack_webhook' => '',
        'wso_telegram_token' => '',
        'wso_telegram_chat' => '',
        'wso_api_key' => '',
        'wso_auto_alert' => 0,
        'wso_backup_alert_threshold' => 500,
        'wso_color_primary'        => '#3b82f6',
        'wso_color_primary_hover'  => '#2563eb',
        'wso_color_secondary'      => '#f1f5f9',
        'wso_color_secondary_text' => '#334155',
        'wso_color_success'        => '#10b981',
        'wso_color_warning'        => '#f59e0b',
        'wso_color_error'          => '#ef4444',
        'wso_color_bg'             => '#f8fafc',
        'wso_color_card'           => '#ffffff',
        'wso_color_bg_dark'        => '#0f172a',
        'wso_color_card_dark'      => '#1e293b',
        'wso_border_radius'        => '8px',
        'wso_shadow'               => '0 1px 3px rgba(0,0,0,0.1)',
        'wso_font_size'            => '14px',
        'wso_spacing'              => '20px',
        'wso_admin_font'           => 'Vazir',
    ];

    /**
     * Returns the singleton instance.
     *
     * @return Settings
     */
    public static function instance(): Settings {
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
     * Prevent cloning of the singleton.
     */
    private function __clone() {}

    /**
     * Prevent unserializing of the singleton.
     */
    public function __wakeup() {}

    /**
     * Gets default options map.
     *
     * @return array<string, mixed>
     */
    public function get_defaults(): array {
        return $this->defaults;
    }

    /**
     * Gets an option value with fallback.
     *
     * @param string $key Option key.
     * @param mixed|null $default Fallback value.
     * @return mixed
     */
    public function get(string $key, $default = null) {
        $key = str_starts_with($key, self::PREFIX) ? $key : self::PREFIX . $key;
        $fallback = $default ?? ($this->defaults[$key] ?? null);
        return get_option($key, $fallback);
    }

    /**
     * Updates an option value.
     *
     * @param string $key Option key.
     * @param mixed $value New value.
     * @return bool
     */
    public function set(string $key, $value): bool {
        $key = str_starts_with($key, self::PREFIX) ? $key : self::PREFIX . $key;
        if (array_key_exists($key, $this->defaults)) {
            $value = $this->sanitize_value($key, $value);
        }
        return update_option($key, $value);
    }

    /**
     * Sets default options if not already initialized.
     *
     * @return void
     */
    public function set_defaults(): void {
        foreach ($this->defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }

    /**
     * Gets all plugin settings as key-value array.
     *
     * @return array<string, mixed>
     */
    public function get_all(): array {
        $all = [];
        foreach ($this->defaults as $key => $default) {
            $all[$key] = get_option($key, $default);
        }
        return $all;
    }

    /**
     * Registers settings with WordPress options API.
     *
     * @return void
     */
    public function register(): void {
        foreach (array_keys($this->defaults) as $key) {
            register_setting('wso_settings_group', $key, [
                'sanitize_callback' => [$this, 'sanitize_setting']
            ]);
        }
    }

    /**
     * Sanitizes a setting before saving.
     *
     * @param mixed $value
     * @return mixed
     */
    public function sanitize_setting($value) {
        if (is_numeric($value)) {
            return (int) $value;
        }
        if (is_string($value)) {
            return sanitize_text_field($value);
        }
        return $value;
    }

    /**
     * Sanitizes a value for a specific option key with range checks.
     *
     * @param string $key Option key (with prefix).
     * @param mixed $value Raw value.
     * @return mixed
     */
    public function sanitize_value(string $key, $value) {
        switch ($key) {
            case 'wso_quality':
                return max(1, min(100, (int) $value));
            case 'wso_max_size':
                return max(1, min(100, (int) $value));
            case 'wso_max_width':
            case 'wso_max_height':
                return max(0, min(8000, (int) $value));
            case 'wso_watermark_opacity':
                return max(1, min(100, (int) $value));
            case 'wso_watermark_margin':
                return max(0, min(200, (int) $value));
            case 'wso_webp_recompress_threshold':
                return max(0, min(50, (int) $value));
            case 'wso_backup_alert_threshold':
                return max(0, min(10000, (int) $value));
            case 'wso_watermark_image':
                return max(0, (int) $value);
            case 'wso_watermark_position':
                $allowed = ['top-left','top-center','top-right','center-left','center','center-right','bottom-left','bottom-center','bottom-right'];
                $v = sanitize_key((string) $value);
                return in_array($v, $allowed, true) ? $v : 'bottom-right';
            case 'wso_admin_font':
                $allowed_fonts = ['Vazir','IRANSansX','IRANYekanX','Vazirmatn','Tahoma'];
                $v = sanitize_text_field((string) $value);
                return in_array($v, $allowed_fonts, true) ? $v : 'Vazir';
            case 'wso_color_primary':
            case 'wso_color_primary_hover':
            case 'wso_color_secondary':
            case 'wso_color_secondary_text':
            case 'wso_color_success':
            case 'wso_color_warning':
            case 'wso_color_error':
            case 'wso_color_bg':
            case 'wso_color_card':
            case 'wso_color_bg_dark':
            case 'wso_color_card_dark':
                $v = sanitize_hex_color((string) $value);
                return $v ? $v : $this->defaults[$key];
            case 'wso_border_radius':
                $v = sanitize_text_field((string) $value);
                return preg_match('/^\d+(\.\d+)?(px|em|rem|%)$/', trim($v)) ? trim($v) : $this->defaults[$key];
            case 'wso_font_size':
            case 'wso_spacing':
                $v = sanitize_text_field((string) $value);
                return preg_match('/^\d+(\.\d+)?(px|em|rem|%)$/', trim($v)) ? trim($v) : $this->defaults[$key];
            case 'wso_shadow':
                $v = sanitize_text_field((string) $value);
                if (strlen($v) > 200 || preg_match('/[<>]/', $v)) {
                    return $this->defaults[$key];
                }
                return $v;
            case 'wso_slack_webhook':
                $v = esc_url_raw(trim((string) $value));
                return ('' === $v || str_starts_with($v, 'https://hooks.slack.com/')) ? $v : '';
            case 'wso_cloudinary_cloud':
            case 'wso_cloudinary_key':
            case 'wso_telegram_chat':
            case 'wso_api_key':
                return sanitize_text_field((string) $value);
            case 'wso_cloudinary_secret':
            case 'wso_telegram_token':
                return sanitize_text_field((string) $value);
            default:
                // Checkbox-style flags.
                if (is_numeric($value) && in_array($key, [
                    'wso_enable','wso_delete_original','wso_convert_webp','wso_convert_avif',
                    'wso_strip_exif','wso_backup_originals','wso_optimize_svg','wso_auto_optimize',
                    'wso_dark_mode','wso_watermark_enabled','wso_auto_alt_enabled','wso_auto_alt_overwrite',
                    'wso_preload_webp','wso_smart_lazy_load','wso_auto_scan','wso_on_the_fly','wso_auto_alert',
                ], true)) {
                    return ((int) $value) ? 1 : 0;
                }
                return $this->sanitize_setting($value);
        }
    }
}
