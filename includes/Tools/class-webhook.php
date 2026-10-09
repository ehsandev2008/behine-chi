<?php
/**
 * Webhook Notifications
 * Slack/Telegram notifications after bulk optimization.
 *
 * @package WSO\Tools
 */

namespace WSO\Tools;

if (!defined('ABSPATH')) {
    exit;
}

class Webhook {

    private static ?Webhook $instance = null;

    public static function instance(): Webhook {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Send notification to Slack.
     */
    public function send_slack(string $message, array $blocks = []): bool {
        $settings = \WSO\Core\Settings::instance();
        $webhook_url = $settings->get('wso_slack_webhook', '');

        if (empty($webhook_url)) {
            return false;
        }

        $payload = [
            'text'   => $message,
            'blocks' => $blocks,
        ];

        $ch = curl_init($webhook_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $http_code === 200;
    }

    /**
     * Send notification to Telegram.
     */
    public function send_telegram(string $message): bool {
        $settings = \WSO\Core\Settings::instance();
        $bot_token = $settings->get('wso_telegram_token', '');
        $chat_id = $settings->get('wso_telegram_chat', '');

        if (empty($bot_token) || empty($chat_id)) {
            return false;
        }

        $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";

        $payload = [
            'chat_id'    => $chat_id,
            'text'       => $message,
            'parse_mode' => 'HTML',
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $http_code === 200;
    }

    /**
     * Send bulk optimization complete notification.
     */
    public function notify_bulk_complete(int $processed, int $success, int $failed, string $saved_size): void {
        $message = sprintf(
            "✅ بهینه‌سازی دسته‌ای کامل شد!\n📊 پردازش: %d\n✅ موفق: %d\n❌ ناموفق: %d\n💾 صرفه‌جویی: %s",
            $processed,
            $success,
            $failed,
            $saved_size
        );

        $this->send_slack($message);
        $this->send_telegram($message);
    }

    /**
     * Send error notification.
     */
    public function notify_error(string $error_message): void {
        $message = sprintf("❌ خطا در بهینه‌سازی: %s", $error_message);
        $this->send_slack($message);
        $this->send_telegram($message);
    }
}
