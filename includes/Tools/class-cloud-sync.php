<?php
/**
 * Cloudinary/Imagify Integration
 * Cloud conversion as fallback when local processing fails.
 *
 * @package WSO\Tools
 */

namespace WSO\Tools;

if (!defined('ABSPATH')) {
    exit;
}

class Cloud_Sync {

    private static ?Cloud_Sync $instance = null;

    public static function instance(): Cloud_Sync {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Upload to Cloudinary for conversion.
     */
    public function upload_cloudinary(string $file_path, string $public_id = ''): array {
        $settings = \WSO\Core\Settings::instance();
        $cloud_name = $settings->get('wso_cloudinary_cloud', '');
        $api_key = $settings->get('wso_cloudinary_key', '');
        $api_secret = $settings->get('wso_cloudinary_secret', '');

        if (empty($cloud_name) || empty($api_key) || empty($api_secret)) {
            return ['success' => false, 'message' => 'تنظیمات Cloudinary کامل نیست.'];
        }

        if (empty($public_id)) {
            $public_id = pathinfo($file_path, PATHINFO_FILENAME);
        }

        $url = "https://api.cloudinary.com/v1_1/{$cloud_name}/image/upload";

        $post_data = [
            'file'       => new \CURLFile($file_path),
            'public_id'  => $public_id,
            'api_key'    => $api_key,
            'timestamp'  => time(),
        ];

        $post_data['signature'] = $this->generate_signature($post_data, $api_secret);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 200) {
            $data = json_decode($response, true);
            if (!empty($data['secure_url'])) {
                return [
                    'success' => true,
                    'url'     => $data['secure_url'],
                    'format'  => $data['format'] ?? 'webp',
                ];
            }
        }

        return ['success' => false, 'message' => 'آپلود به Cloudinary ناموفق بود.'];
    }

    /**
     * Generate Cloudinary API signature.
     */
    private function generate_signature(array $params, string $api_secret): string {
        unset($params['file']);
        ksort($params);
        $to_sign = '';
        foreach ($params as $key => $value) {
            $to_sign .= $key . '=' . $value . '&';
        }
        $to_sign = rtrim($to_sign, '&');
        return sha1($to_sign . $api_secret);
    }
}
