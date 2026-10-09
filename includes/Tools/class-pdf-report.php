<?php
/**
 * PDF Report Generator
 * Generates downloadable PDF reports of optimization statistics.
 *
 * @package WSO\Tools
 */

namespace WSO\Tools;

if (!defined('ABSPATH')) {
    exit;
}

class PDF_Report {

    private static ?PDF_Report $instance = null;

    public static function instance(): PDF_Report {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Generate a PDF report.
     */
    public function generate(): void {
        $stats = \WSO\Admin\Dashboard_Widgets::get_stats();
        $chart = Progress_Chart::instance();
        $engine = $chart->get_engine_comparison();

        // Use TCPDF if available, otherwise fall back to HTML report
        if (class_exists('TCPDF')) {
            $this->generate_tcpdf($stats, $engine);
        } else {
            $this->generate_html($stats, $engine);
        }
    }

    /**
     * Generate HTML report (fallback).
     */
    private function generate_html(array $stats, array $engine): void {
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="wso-report-' . date('Y-m-d') . '.html"');
        ?>
        <!DOCTYPE html>
        <html dir="rtl" lang="fa">
        <head>
            <meta charset="UTF-8">
            <title>گزارش بهینه‌سازی بهینه چی</title>
            <style>
                body { font-family: Tahoma, sans-serif; direction: rtl; padding: 20px; }
                h1 { color: #3b82f6; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: right; }
                th { background: #f1f5f9; }
                .stat-box { display: inline-block; margin: 10px; padding: 15px; background: #f8fafc; border-radius: 8px; }
            </style>
        </head>
        <body>
            <h1>گزارش بهینه‌سازی بهینه چی</h1>
            <p>تاریخ صدور: <?php echo date_i18n('Y/m/d H:i'); ?></p>

            <h2>آمار کلی</h2>
            <div class="stat-box">کل تصاویر: <strong><?php echo $stats['total_images']; ?></strong></div>
            <div class="stat-box">بهینه‌شده: <strong><?php echo $stats['optimized_images']; ?></strong></div>
            <div class="stat-box">صرفه‌جویی: <strong><?php echo $stats['saved_size']; ?></strong></div>
            <div class="stat-box">WebP: <strong><?php echo $stats['webp_count']; ?></strong></div>
            <div class="stat-box">AVIF: <strong><?php echo $stats['avif_count']; ?></strong></div>

            <h2>مقایسه موتورها</h2>
            <table>
                <tr>
                    <th>موتور</th>
                    <th>تعداد</th>
                    <th>میانگین صرفه‌جویی</th>
                    <th>میانگین حجم اولیه</th>
                    <th>میانگین حجم بهینه</th>
                </tr>
                <tr>
                    <td>Imagick</td>
                    <td><?php echo $engine['imagick']['count']; ?></td>
                    <td><?php echo $engine['imagick']['avg_savings']; ?>٪</td>
                    <td><?php echo size_format($engine['imagick']['avg_original'], 2); ?></td>
                    <td><?php echo size_format($engine['imagick']['avg_optimized'], 2); ?></td>
                </tr>
                <tr>
                    <td>GD</td>
                    <td><?php echo $engine['gd']['count']; ?></td>
                    <td><?php echo $engine['gd']['avg_savings']; ?>٪</td>
                    <td><?php echo size_format($engine['gd']['avg_original'], 2); ?></td>
                    <td><?php echo size_format($engine['gd']['avg_optimized'], 2); ?></td>
                </tr>
            </table>
        </body>
        </html>
        <?php
        exit;
    }

    /**
     * Generate TCPDF report (if TCPDF is available).
     */
    private function generate_tcpdf(array $stats, array $engine): void {
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->AddPage();
        $pdf->SetFont('dejavusans', '', 12);

        $html = $this->get_report_html($stats, $engine);
        $pdf->writeHTML($html, true, false, true, false, '');

        $pdf->Output('wso-report-' . date('Y-m-d') . '.pdf', 'D');
        exit;
    }

    private function get_report_html(array $stats, array $engine): string {
        return '
        <h1 style="color:#3b82f6">گزارش بهینه‌سازی بهینه چی</h1>
        <p>تاریخ صدور: ' . date_i18n('Y/m/d H:i') . '</p>
        <hr>
        <h2>آمار کلی</h2>
        <table border="1" cellpadding="5">
            <tr><td>کل تصاویر</td><td><strong>' . $stats['total_images'] . '</strong></td></tr>
            <tr><td>بهینه‌شده</td><td><strong>' . $stats['optimized_images'] . '</strong></td></tr>
            <tr><td>صرفه‌جویی</td><td><strong>' . $stats['saved_size'] . '</strong></td></tr>
            <tr><td>WebP</td><td><strong>' . $stats['webp_count'] . '</strong></td></tr>
            <tr><td>AVIF</td><td><strong>' . $stats['avif_count'] . '</strong></td></tr>
        </table>';
    }
}
