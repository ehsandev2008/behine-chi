<?php
/**
 * Exclusion Rules Helper
 * Skip files by name pattern, post type, or tiny size.
 *
 * @package WSO\Tools
 */

namespace WSO\Tools;

if (!defined('ABSPATH')) {
    exit;
}

class Exclusions {

    /**
     * Check if a file/attachment should be skipped.
     *
     * @param string $file_path Absolute path (may be empty for attachment-only checks).
     * @param int $attachment_id
     * @return array{excluded: bool, reason: string}
     */
    public static function is_excluded(string $file_path = '', int $attachment_id = 0): array {
        try {
            $settings = \WSO\Core\Settings::instance();
        } catch (\Throwable $e) {
            return ['excluded' => false, 'reason' => ''];
        }

        // 1) Tiny files.
        $below_kb = max(0, (int) $settings->get('wso_exclude_below_kb', 0));
        if ($below_kb > 0 && '' !== $file_path && file_exists($file_path)) {
            $kb = (int) filesize($file_path) / 1024;
            if ($kb < $below_kb) {
                return ['excluded' => true, 'reason' => sprintf('حجم فایل کمتر از %d کیلوبایت است و نادیده گرفته شد.', $below_kb)];
            }
        }

        // 2) Filename patterns (comma separated, * wildcard).
        $patterns_raw = trim((string) $settings->get('wso_exclude_patterns', ''));
        if ('' !== $patterns_raw) {
            $base = ('' !== $file_path) ? basename($file_path) : '';
            if ('' === $base && $attachment_id > 0) {
                $f = get_attached_file($attachment_id);
                $base = $f ? basename($f) : '';
            }
            if ('' !== $base) {
                $parts = array_filter(array_map('trim', explode(',', $patterns_raw)));
                foreach ($parts as $pat) {
                    if ('' === $pat) {
                        continue;
                    }
                    // fnmatch without path; case-insensitive.
                    if (function_exists('fnmatch')) {
                        if (@fnmatch($pat, $base, FNM_CASEFOLD)) {
                            return ['excluded' => true, 'reason' => sprintf('نام فایل با الگوی «%s» مطابق و نادیده گرفته شد.', $pat)];
                        }
                    } elseif (false !== stripos($base, trim($pat, '*'))) {
                        return ['excluded' => true, 'reason' => sprintf('نام فایل با الگوی «%s» مطابق و نادیده گرفته شد.', $pat)];
                    }
                }
            }
        }

        // 3) Post types (only for attachments; checks parent post type).
        $types_raw = trim((string) $settings->get('wso_exclude_post_types', ''));
        if ('' !== $types_raw && $attachment_id > 0) {
            $types = array_filter(array_map('trim', explode(',', strtolower($types_raw))));
            if (!empty($types)) {
                $parent_id = (int) wp_get_post_parent_id($attachment_id);
                if ($parent_id > 0) {
                    $pt = strtolower((string) get_post_type($parent_id));
                    if (in_array($pt, $types, true)) {
                        return ['excluded' => true, 'reason' => sprintf('نوع پست والد (%s) در لیست استثنا است.', $pt)];
                    }
                }
            }
        }

        return ['excluded' => false, 'reason' => ''];
    }

    /**
     * Effective quality for a given target and context.
     *
     * @param string $target webp|avif|jpeg
     * @param bool $is_thumb
     * @return int 10-100
     */
    public static function effective_quality(string $target = 'jpeg', bool $is_thumb = false): int {
        try {
            $settings = \WSO\Core\Settings::instance();
            $base = max(10, min(100, (int) $settings->get('wso_quality', 82)));
            if ($is_thumb) {
                $t = (int) $settings->get('wso_quality_thumb', 0);
                if ($t >= 10) {
                    return max(10, min(100, $t));
                }
            }
            if ('webp' === $target) {
                $q = (int) $settings->get('wso_quality_webp', 0);
                return $q >= 10 ? max(10, min(100, $q)) : $base;
            }
            if ('avif' === $target) {
                $q = (int) $settings->get('wso_quality_avif', 0);
                return $q >= 10 ? max(10, min(100, $q)) : max(10, $base - 7);
            }
            return $base;
        } catch (\Throwable $e) {
            return 82;
        }
    }
}
