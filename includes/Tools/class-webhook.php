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
        $webhook_url = esc_url_raw((string) $settings->get('wso_slack_webhook', ''));

        if ('' === $webhook_url || 0 !== strpos($webhook_url, 'https://hooks.slack.com/')) {
            return false;
        }

        $payload = [
            'text'   => $message,
            'blocks' => $blocks,
        ];

        $response = wp_remote_post($webhook_url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
            'timeout' => 10,
        ]);
        if (is_wp_error($response)) {
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code($response);

        return 200 === $code;
    }

    /**
     * Send notification to Telegram.
     */
    public function send_telegram(string $message): bool {
        $settings = \WSO\Core\Settings::instance();
        $bot_token = sanitize_text_field((string) $settings->get('wso_telegram_token', ''));
        $chat_id = sanitize_text_field((string) $settings->get('wso_telegram_chat', ''));

        if ('' === $bot_token || '' === $chat_id || preg_match('/\s/', $bot_token)) {
            return false;
        }

        $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";

        $payload = [
            'chat_id'    => $chat_id,
            'text'       => $message,
            'parse_mode' => 'HTML',
        ];

        $response = wp_remote_post($url, [
            'body'    => $payload,
            'timeout' => 10,
        ]);
        if (is_wp_error($response)) {
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code($response);

        return 200 === $code;
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
