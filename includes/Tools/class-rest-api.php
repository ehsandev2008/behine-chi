<?php
/**
 * REST API Endpoints
 * External management via REST API.
 *
 * @package WSO\Tools
 */

namespace WSO\Tools;

if (!defined('ABSPATH')) {
    exit;
}

class REST_API {

    private static ?REST_API $instance = null;
    private string $namespace = 'wso/v1';

    public static function instance(): REST_API {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register REST API routes.
     */
    public function register_routes(): void {
        // Get stats
        register_rest_route($this->namespace, '/stats', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_stats'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Optimize single attachment
        register_rest_route($this->namespace, '/optimize/(?P<id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [$this, 'optimize_attachment'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Get queue status
        register_rest_route($this->namespace, '/queue', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_queue_status'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Build queue
        register_rest_route($this->namespace, '/queue/build', [
            'methods'             => 'POST',
            'callback'            => [$this, 'build_queue'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Process batch
        register_rest_route($this->namespace, '/queue/process', [
            'methods'             => 'POST',
            'callback'            => [$this, 'process_batch'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Get logs
        register_rest_route($this->namespace, '/logs', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_logs'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Health check
        register_rest_route($this->namespace, '/health', [
            'methods'             => 'GET',
            'callback'            => [$this, 'health_check'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    /**
     * Check API permission.
     */
    public function check_permission(\WP_REST_Request $request): bool {
        $api_key = $request->get_header('X-WSO-API-Key');
        $stored_key = \WSO\Core\Settings::instance()->get('wso_api_key', '');

        if (empty($stored_key)) {
            return current_user_can('manage_options');
        }

        return hash_equals($stored_key, $api_key);
    }

    /**
     * Get optimization stats.
     */
    public function get_stats(\WP_REST_Request $request): \WP_REST_Response {
        $stats = \WSO\Admin\Dashboard_Widgets::get_stats();
        return new \WP_REST_Response(['success' => true, 'data' => $stats], 200);
    }

    /**
     * Optimize a single attachment.
     */
    public function optimize_attachment(\WP_REST_Request $request): \WP_REST_Response {
        $attachment_id = (int) $request['id'];
        $result = \WSO\Engine\Optimizer::instance()->optimize_attachment($attachment_id);

        return new \WP_REST_Response([
            'success' => $result['status'] === Logger::STATUS_SUCCESS,
            'data'    => $result,
        ], 200);
    }

    /**
     * Get queue status.
     */
    public function get_queue_status(\WP_REST_Request $request): \WP_REST_Response {
        $stats = \WSO\Queue\Queue_Manager::instance()->get_stats();
        return new \WP_REST_Response(['success' => true, 'data' => $stats], 200);
    }

    /**
     * Build optimization queue.
     */
    public function build_queue(\WP_REST_Request $request): \WP_REST_Response {
        $count = \WSO\Queue\Queue_Manager::instance()->populate_media_library_queue();
        return new \WP_REST_Response([
            'success' => true,
            'message' => sprintf('تعداد %d تصویر به صف اضافه شد.', $count),
            'count'   => $count,
        ], 200);
    }

    /**
     * Process a batch from the queue.
     */
    public function process_batch(\WP_REST_Request $request): \WP_REST_Response {
        $batch_size = (int) $request->get_param('batch_size', 5);
        $batch_size = max(1, min(20, $batch_size));

        $manager = \WSO\Queue\Queue_Manager::instance();
        $optimizer = \WSO\Engine\Optimizer::instance();
        $items = $manager->get_pending_batch($batch_size);

        if (empty($items)) {
            return new \WP_REST_Response([
                'success'   => true,
                'completed' => true,
                'message'   => 'صف خالی است.',
            ], 200);
        }

        $results = [];
        foreach ($items as $item) {
            $manager->update_status($item['id'], 'processing');

            $attachment_id = (int) $item['attachment_id'];
            $file_path = $item['file_path'];

            if ($attachment_id > 0) {
                $res = $optimizer->optimize_attachment($attachment_id);
            } else {
                $res = $optimizer->optimize_file($file_path);
            }

            $manager->update_status($item['id'], $res['status'], $res['message'] ?? '');
            $results[] = [
                'id'      => $item['id'],
                'status'  => $res['status'],
                'message' => $res['message'] ?? '',
            ];
        }

        return new \WP_REST_Response([
            'success'   => true,
            'completed' => false,
            'processed' => count($results),
            'results'   => $results,
            'stats'     => $manager->get_stats(),
        ], 200);
    }

    /**
     * Get optimization logs.
     */
    public function get_logs(\WP_REST_Request $request): \WP_REST_Response {
        $page = (int) $request->get_param('page', 1);
        $limit = (int) $request->get_param('limit', 20);
        $status = sanitize_text_field($request->get_param('status', ''));

        $logs = Logger::get_logs($limit, ($page - 1) * $limit, $status);
        $total = Logger::get_count($status);

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'logs'  => $logs,
                'total' => $total,
                'page'  => $page,
            ],
        ], 200);
    }

    /**
     * Health check endpoint.
     */
    public function health_check(\WP_REST_Request $request): \WP_REST_Response {
        $report = Health_Check::instance()->perform_scan();
        return new \WP_REST_Response(['success' => true, 'data' => $report], 200);
    }
}
