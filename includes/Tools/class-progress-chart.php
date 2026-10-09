<?php
/**
 * Time-based Progress Chart
 * Generates chart data for optimization progress over time.
 *
 * @package WSO\Tools
 */

namespace WSO\Tools;

if (!defined('ABSPATH')) {
    exit;
}

class Progress_Chart {

    private static ?Progress_Chart $instance = null;

    public static function instance(): Progress_Chart {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Get optimization progress data for charts.
     *
     * @param string $period 'week', 'month', 'year'
     * @return array Chart data with labels and datasets.
     */
    public function get_chart_data(string $period = 'month'): array {
        global $wpdb;
        $db = \WSO\Core\Database::instance();
        $table = $db->logs_table;

        $interval = match ($period) {
            'week'  => '7 DAY',
            'month' => '30 DAY',
            'year'  => '1 YEAR',
            default => '30 DAY',
        };

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT 
                    DATE(created_at) as date,
                    COUNT(*) as count,
                    SUM(original_size) as total_original,
                    SUM(optimized_size) as total_optimized,
                    SUM(saved_bytes) as total_saved,
                    savings_percent
                 FROM {$table}
                 WHERE status = 'success'
                   AND created_at >= DATE_SUB(NOW(), INTERVAL {$interval})
                 GROUP BY DATE(created_at)
                 ORDER BY date ASC"
            ),
            ARRAY_A
        );

        $labels = [];
        $data_optimized = [];
        $data_saved = [];
        $data_count = [];

        foreach ($results as $row) {
            $labels[] = date_i18n('Y/m/d', strtotime($row['date']));
            $data_optimized[] = (int) $row['total_optimized'];
            $data_saved[] = (int) $row['total_saved'];
            $data_count[] = (int) $row['count'];
        }

        return [
            'labels'     => $labels,
            'optimized'  => $data_optimized,
            'saved'      => $data_saved,
            'count'      => $data_count,
            'period'     => $period,
        ];
    }

    /**
     * Get engine comparison data (Imagick vs GD).
     * Driver is stored in postmeta _wso_opt_data (PHP-serialized), not in
     * the logs table, so we aggregate from attachments instead of using
     * JSON_EXTRACT on a non-existent column.
     */
    public function get_engine_comparison(): array {
        $comparison = [
            'imagick' => ['count' => 0, 'avg_savings' => 0, 'avg_original' => 0, 'avg_optimized' => 0],
            'gd'      => ['count' => 0, 'avg_savings' => 0, 'avg_original' => 0, 'avg_optimized' => 0],
        ];

        $query = new \WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/webp'],
            'posts_per_page' => 200,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => '_wso_optimized',
                    'value'   => '1',
                    'compare' => '=',
                ],
            ],
        ]);

        $totals = [
            'imagick' => ['count' => 0, 'savings' => 0.0, 'orig' => 0, 'opt' => 0],
            'gd'      => ['count' => 0, 'savings' => 0.0, 'orig' => 0, 'opt' => 0],
        ];

        foreach ((array) $query->posts as $attachment_id) {
            $data = get_post_meta((int) $attachment_id, '_wso_opt_data', true);
            if (!is_array($data)) {
                continue;
            }
            $driver = strtolower((string) ($data['driver'] ?? ''));
            $bucket = str_contains($driver, 'imagick') ? 'imagick' : (str_contains($driver, 'gd') ? 'gd' : '');
            if ('' === $bucket) {
                continue;
            }
            $totals[$bucket]['count']++;
            $totals[$bucket]['savings'] += (float) ($data['savings_percent'] ?? 0);
            $totals[$bucket]['orig']    += (int) ($data['original_size'] ?? 0);
            $totals[$bucket]['opt']     += (int) ($data['optimized_size'] ?? 0);
        }

        foreach (['imagick', 'gd'] as $bucket) {
            $c = $totals[$bucket]['count'];
            if ($c > 0) {
                $comparison[$bucket] = [
                    'count'         => $c,
                    'avg_savings'   => round($totals[$bucket]['savings'] / $c, 2),
                    'avg_original'  => (int) round($totals[$bucket]['orig'] / $c),
                    'avg_optimized' => (int) round($totals[$bucket]['opt'] / $c),
                ];
            }
        }

        return $comparison;
    }
}
