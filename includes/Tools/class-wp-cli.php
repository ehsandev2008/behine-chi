<?php
/**
 * WP-CLI Commands for Behine Chi
 *
 * @package WSO\Tools
 */

namespace WSO\Tools;

if (!defined('ABSPATH')) {
    exit;
}

if (defined('WP_CLI') && WP_CLI) {

    class WP_CLI_Command extends \WP_CLI_Command {

        /**
         * Optimize all images in the media library.
         *
         * ## OPTIONS
         *
         * [--batch-size=<number>]
         * : Number of images per batch.
         * ---
         * default: 5
         * ---
         *
         * [--format=<format>]
         * : Target format (webp, avif, both).
         * ---
         * default: webp
         * ---
         *
         * [--force]
         * : Re-optimize already optimized images.
         *
         * ## EXAMPLES
         *
         *     wso optimize --all
         *     wso optimize --all --batch-size=10 --format=avif
         *     wso optimize --all --force
         *
         * @param array $args
         * @param array $assoc_args
         */
        public function optimize(array $args, array $assoc_args): void {
            $batch_size = max(1, min(20, (int) ($assoc_args['batch-size'] ?? 5)));
            $format = sanitize_key($assoc_args['format'] ?? 'webp');
            $allowed_formats = ['webp', 'avif', 'both'];
            if (!in_array($format, $allowed_formats, true)) {
                \WP_CLI::warning('فرمت نامعتبر؛ از webp استفاده می‌شود.');
                $format = 'webp';
            }
            $force = isset($assoc_args['force']);
            // Apply requested format to settings for this run (restored afterwards).
            $settings = \WSO\Core\Settings::instance();
            $orig_webp = $settings->get('wso_convert_webp', 1);
            $orig_avif = $settings->get('wso_convert_avif', 0);
            if ('webp' === $format) {
                $settings->set('wso_convert_webp', 1);
                $settings->set('wso_convert_avif', 0);
            } elseif ('avif' === $format) {
                $settings->set('wso_convert_avif', 1);
            } elseif ('both' === $format) {
                $settings->set('wso_convert_webp', 1);
                $settings->set('wso_convert_avif', 1);
            }

            $optimizer = \WSO\Engine\Optimizer::instance();

            \WP_CLI::log('شروع بهینه‌سازی دسته‌ای تصاویر...');

            $total = 0;
            $offset = 0;
            $page_size = 200;
            $ids_all = [];
            do {
                $query = new \WP_Query([
                    'post_type'      => 'attachment',
                    'post_status'    => 'inherit',
                    'post_mime_type' => ['image/jpeg', 'image/png', 'image/webp'],
                    'posts_per_page' => $page_size,
                    'offset'         => $offset,
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                ]);
                $page_ids = $query->posts ?: [];
                foreach ($page_ids as $pid) {
                    $ids_all[] = (int) $pid;
                }
                $offset += $page_size;
                wp_reset_postdata();
            } while (!empty($page_ids) && count($ids_all) < 20000);

            $total = count($ids_all);
            \WP_CLI::log('تعداد کل تصاویر: ' . $total);

            if ($total === 0) {
                \WP_CLI::warning('هیچ تصویری یافت نشد.');
                return;
            }

            $progress = \WP_CLI\Utils\make_progress_bar('پردازش تصاویر', $total);

            $processed = 0;
            $success = 0;
            $failed = 0;
            $skipped = 0;

            foreach ($ids_all as $attachment_id) {
                if (!$force && get_post_meta($attachment_id, '_wso_optimized', true)) {
                    $skipped++;
                    $progress->tick();
                    continue;
                }

                $result = $optimizer->optimize_attachment((int) $attachment_id);

                if ($result['status'] === \WSO\Tools\Logger::STATUS_SUCCESS) {
                    $success++;
                } elseif ($result['status'] === \WSO\Tools\Logger::STATUS_SKIPPED) {
                    $skipped++;
                } else {
                    $failed++;
                }

                $processed++;
                $progress->tick();

                // Prevent memory leaks
                if ($processed % $batch_size === 0) {
                    wp_cache_flush();
                    gc_collect_cycles();
                }
            }

            $progress->finish();

            // Restore original format settings.
            $settings->set('wso_convert_webp', $orig_webp);
            $settings->set('wso_convert_avif', $orig_avif);

            \WP_CLI::success("پردازش کامل شد!");
            \WP_CLI::log('موفق: ' . $success);
            \WP_CLI::log('ناموفق: ' . $failed);
            \WP_CLI::log('نادیده گرفته: ' . $skipped);
        }

        /**
         * Get optimization statistics.
         *
         * ## EXAMPLES
         *
         *     wso stats
         */
        public function stats(array $args, array $assoc_args): void {
            $stats = \WSO\Admin\Dashboard_Widgets::get_stats();

            \WP_CLI::log('=== آمار بهینه‌سازی بهینه چی ===');
            \WP_CLI::log('کل تصاویر: ' . $stats['total_images']);
            \WP_CLI::log('تصاویر بهینه‌شده: ' . $stats['optimized_images']);
            \WP_CLI::log('فضای صرفه‌جویی‌شده: ' . $stats['saved_size']);
            \WP_CLI::log('فایل‌های WebP: ' . $stats['webp_count']);
            \WP_CLI::log('فایل‌های AVIF: ' . $stats['avif_count']);
            \WP_CLI::log('حجم کش: ' . $stats['cache_size']);
        }

        /**
         * Clear all generated cache files.
         *
         * ## EXAMPLES
         *
         *     wso clear-cache
         */
        public function clear_cache(array $args, array $assoc_args): void {
            $cache_manager = \WSO\Tools\Cache_Manager::instance();
            $deleted = $cache_manager->clear_generated_files();

            \WP_CLI::success('تعداد ' . $deleted . ' فایل کش حذف شد.');
        }

        /**
         * Run health check.
         *
         * ## EXAMPLES
         *
         *     wso health
         */
        public function health(array $args, array $assoc_args): void {
            $health = \WSO\Tools\Health_Check::instance();
            $report = $health->perform_scan();

            \WP_CLI::log('=== گزارش سلامت سیستم ===');
            \WP_CLI::log('امتیاز کلی: ' . $report['score'] . '%');
            \WP_CLI::log('وضعیت: ' . $report['status_text']);

        foreach ($report as $category => $items) {
                if (!is_array($items)) {
                    continue;
                }
                if (in_array($category, ['score', 'status', 'status_text'], true)) {
                    continue;
                }
                \WP_CLI::log('--- ' . $category . ' ---');
                foreach ($items as $key => $check) {
                    if (!is_array($check)) {
                        continue;
                    }
                    $status_icon = ($check['status'] ?? '') === 'success' ? '✓' : ((($check['status'] ?? '') === 'warning') ? '!' : '✗');
                    \WP_CLI::log($status_icon . ' ' . ($check['label'] ?? $key) . ': ' . ($check['value'] ?? ''));
                }
            }
        }
    }

    \WP_CLI::add_command('wso', 'WSO\Tools\WP_CLI_Command');
}
