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
        'wso_recompress_webp'  => 1,
        'wso_webp_min_size_kb' => 50, // KB threshold for optimizing existing WebP images
        // Watermark settings (all off/empty by default: zero behavior change until configured).
        'wso_watermark_enabled'  => 0,
        'wso_watermark_image'    => 0,
        'wso_watermark_position' => 'bottom-right',
        'wso_watermark_opacity'  => 70,
        'wso_watermark_margin'   => 12,
        // Automatic Alt Text (opt-in; never overwrites manual alts by default).
        'wso_auto_alt_enabled'   => 0,
        'wso_auto_alt_overwrite' => 0,
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
    public function get(string $key, mixed $default = null): mixed {
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
    public function set(string $key, mixed $value): bool {
        $key = str_starts_with($key, self::PREFIX) ? $key : self::PREFIX . $key;
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
    public function sanitize_setting(mixed $value): mixed {
        if (is_numeric($value)) {
            return (int) $value;
        }
        if (is_string($value)) {
            return sanitize_text_field($value);
        }
        return $value;
    }
}
