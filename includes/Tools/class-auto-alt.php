<?php
/**
 * سیستم متن جایگزین (Alt) خودکار تصاویر — سبک و سازگار با کتابخانه رسانه
 * Automatic Alt Text — filename-based, never overwrites manual alts by default.
 *
 * @package WSO\Tools
 */

namespace WSO\Tools;

use WSO\Core\Settings;

if (!defined('ABSPATH')) {
    exit;
}

class Auto_Alt {

    /**
     * Singleton instance.
     *
     * @var Auto_Alt|null
     */
    private static ?Auto_Alt $instance = null;

    /**
     * Returns the singleton instance.
     *
     * @return Auto_Alt
     */
    public static function instance(): Auto_Alt {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor: hooks only (lightweight, no work on unrelated requests).
     */
    private function __construct() {
        // New uploads: fill alt right after the attachment post is created.
        add_action('add_attachment', [$this, 'on_add_attachment']);
        // Opt-in bulk fill for existing images missing alt text.
        add_action('wp_ajax_wso_fill_missing_alts', [$this, 'handle_fill_missing_alts']);
    }

    /**
     * Checks whether auto alt is enabled in settings.
     *
     * @return bool
     */
    public function is_enabled(): bool {
        return (bool) Settings::instance()->get('wso_auto_alt_enabled', 0);
    }

    /**
     * Generates a human-readable alt text from a file name.
     *
     * Rules: drop extension, urldecode, dash/underscore/dot/plus → space,
     * collapse whitespace, strip unreadable symbols, trim, cap length.
     *
     * Example: my-new_product.image+01.jpg → "my new product image 01"
     *
     * @param string $filename File name or path.
     * @return string Generated alt (empty when nothing readable remains).
     */
    public function generate_from_filename(string $filename): string {
        $base = basename($filename);
        // Drop extension.
        $base = preg_replace('/\.[^.]{1,10}$/u', '', $base);
        if (null === $base) {
            return '';
        }
        // Decode URL-encoded names (e.g. uploads with %D9%81...).
        $base = urldecode($base);
        // WordPress-style sanitization of the raw token first.
        $base = sanitize_text_field($base);
        // Separators → single spaces (unicode-aware).
        $text = preg_replace('/[-_+.]+/u', ' ', $base);
        // Remove anything that is not a letter, number or space (keeps Persian/Arabic/Latin).
        $text = preg_replace('/[^\p{L}\p{N} ]+/u', ' ', (string) $text);
        $text = preg_replace('/\s+/u', ' ', (string) $text);
        $text = trim((string) $text);

        if ('' === $text) {
            return '';
        }

        // Avoid junk alts made of digits only (e.g. "IMG 20240101" keeps words, "12345" → skip).
        $digits_only = preg_replace('/\s+/u', '', $text);
        if ('' !== $digits_only && ctype_digit($digits_only)) {
            return '';
        }

        // Cap length for the alt attribute.
        if (function_exists('mb_strlen') && mb_strlen($text) > 140) {
            $text = trim(mb_substr($text, 0, 140));
        } elseif (strlen($text) > 140) {
            $text = trim(substr($text, 0, 140));
        }

        return $text;
    }

    /**
     * Fills alt text for a single attachment when appropriate.
     *
     * Preserves manually entered alts unless overwrite mode is enabled.
     *
     * @param int $attachment_id Attachment ID.
     * @return array{filled: bool, alt: string, reason: string}
     */
    public function maybe_fill(int $attachment_id): array {
        if (!$this->is_enabled()) {
            return ['filled' => false, 'alt' => '', 'reason' => 'disabled'];
        }

        $post = get_post($attachment_id);
        if (!$post || 'attachment' !== $post->post_type) {
            return ['filled' => false, 'alt' => '', 'reason' => 'not_attachment'];
        }

        $mime = get_post_mime_type($attachment_id);
        if (!is_string($mime) || !str_starts_with($mime, 'image/')) {
            return ['filled' => false, 'alt' => '', 'reason' => 'not_image'];
        }

        $overwrite = (bool) Settings::instance()->get('wso_auto_alt_overwrite', 0);
        $existing = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        if (!$overwrite && is_string($existing) && '' !== trim($existing)) {
            return ['filled' => false, 'alt' => $existing, 'reason' => 'preserved_manual'];
        }

        $file = get_attached_file($attachment_id);
        $alt = $this->generate_from_filename($file ? basename($file) : $post->post_title);
        if ('' === $alt) {
            return ['filled' => false, 'alt' => '', 'reason' => 'nothing_readable'];
        }

        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);

        return ['filled' => true, 'alt' => $alt, 'reason' => 'generated'];
    }

    /**
     * Fires on new uploads.
     *
     * @param int $attachment_id New attachment ID.
     * @return void
     */
    public function on_add_attachment(int $attachment_id): void {
        // Keep the hook ultra-light when the feature is off.
        if (!$this->is_enabled()) {
            return;
        }
        $this->maybe_fill($attachment_id);
    }

    /**
     * AJAX handler (opt-in): fills missing alts in small batches.
     *
     * POST params: limit (1-100, default 20), offset (default 0).
     * Only touches attachments with empty/missing alt; never overwrites manual alts
     * unless the overwrite setting is explicitly enabled.
     *
     * @return void
     */
    public function handle_fill_missing_alts(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'سطح دسترسی غیرمجاز است.'], 403);
        }
        check_ajax_referer('wso_admin_nonce', 'nonce');

        if (!$this->is_enabled()) {
            wp_send_json_error(['message' => 'قابلیت Alt خودکار در تنظیمات غیرفعال است. ابتدا آن را فعال کنید.'], 400);
        }

        $limit  = max(1, min(100, (int) ($_POST['limit'] ?? 20)));
        $offset = max(0, (int) ($_POST['offset'] ?? 0));

        $query = new \WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'     => '_wp_attachment_image_alt',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => '_wp_attachment_image_alt',
                    'value'   => '',
                    'compare' => '=',
                ],
            ],
        ]);

        $ids = $query->posts ?: [];
        $processed = count($ids);
        $filled = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            $res = $this->maybe_fill((int) $id);
            if (!empty($res['filled'])) {
                $filled++;
            } else {
                $skipped++;
            }
        }

        // If we got a full batch, there may be more — let the client know
        $has_more = ($processed >= $limit);

        wp_send_json_success([
            'message'   => sprintf('تعداد %d تصویر Alt دریافت کردند و %d مورد نادیده گرفته شد.', $filled, $skipped),
            'filled'    => $filled,
            'skipped'   => $skipped,
            'processed' => $processed,
            'has_more'  => $has_more,
        ]);
    }
}
