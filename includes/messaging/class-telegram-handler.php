<?php
/**
 * Telegram Bot Handler
 *
 * Handles incoming Telegram messages via webhook,
 * processes them through the base messaging channel's AI engine,
 * and sends responses back via Telegram Bot API.
 *
 * @package AICWP
 * @since 1.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Telegram_Handler extends AICWP_Messaging_Channel {

    const API_NAMESPACE = 'aicwp/v1';

    private $bot_token;
    private $secret_token;

    public function __construct() {
        if (!get_option('aicwp_telegram_enabled', 0)) {
            return;
        }

        $this->bot_token    = get_option('aicwp_telegram_bot_token', '');
        $this->secret_token = get_option('aicwp_telegram_secret_token', '');

        if (empty($this->bot_token)) {
            return;
        }

        add_action('rest_api_init', array($this, 'register_webhook'));
        $this->init_chat_history_hook();
    }

    protected function get_channel_name() {
        return 'telegram';
    }

    /**
     * Check if Telegram integration is enabled and configured
     */
    public function is_configured() {
        return get_option('aicwp_telegram_enabled', 0)
            && !empty($this->bot_token)
            && !empty($this->secret_token);
    }

    /**
     * Register REST webhook endpoint
     */
    public function register_webhook() {
        register_rest_route(self::API_NAMESPACE, '/telegram-webhook', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_webhook'),
            'permission_callback' => array($this, 'verify_telegram_request'),
        ));
    }

    // =========================================================================
    // Abstract implementations
    // =========================================================================

    protected function verify_request($request) {
        return $this->verify_telegram_request($request);
    }

    protected function extract_message($request) {
        $body = $request->get_json_params();

        if (empty($body['message']['text']) || empty($body['message']['chat']['id'])) {
            return null;
        }

        $chat_id = $body['message']['chat']['id'];

        // Telegram chat IDs are integers (negative for groups, positive for users)
        if (!is_numeric($chat_id)) {
            error_log('[Telegram] Invalid chat ID received');
            return null;
        }

        $chat_id    = strval($chat_id);
        $text       = $body['message']['text'];
        $message_id = isset($body['message']['message_id']) ? strval($body['message']['message_id']) : '';
        $identifier = $this->hash_identifier($chat_id);

        return array(
            'message'     => sanitize_textarea_field($text),
            'sender'      => $chat_id,
            'identifier'  => $identifier,
            'external_id' => sanitize_text_field($message_id),
        );
    }

    protected function send_response($to, $text) {
        return $this->send_message($to, $text);
    }

    // =========================================================================
    // Webhook handling
    // =========================================================================

    /**
     * Verify Telegram webhook request via secret token header
     */
    public function verify_telegram_request($request) {
        if (empty($this->secret_token)) {
            return new WP_Error('not_configured', __('Telegram integration is not configured.', 'ai-chat-wp'), array('status' => 503));
        }

        $header_token = $request->get_header('X-Telegram-Bot-Api-Secret-Token');
        if (empty($header_token)) {
            return new WP_Error('missing_token', __('Missing Telegram secret token.', 'ai-chat-wp'), array('status' => 401));
        }

        if (!hash_equals($this->secret_token, $header_token)) {
            return new WP_Error('invalid_token', __('Invalid Telegram secret token.', 'ai-chat-wp'), array('status' => 401));
        }

        return true;
    }

    /**
     * Handle incoming Telegram webhook
     */
    public function handle_webhook($request) {
        $extracted = $this->extract_message($request);

        if (!$extracted) {
            return new WP_REST_Response(array('ok' => true), 200);
        }

        // Per-user rate limit check (before any AI processing)
        $rate_check = $this->check_messaging_rate_limit($extracted['identifier']);
        if (!$rate_check['allowed']) {
            $this->send_response($extracted['sender'], $rate_check['error']);
            return new WP_REST_Response(array('ok' => true), 200);
        }

        // Send typing indicator immediately
        $this->send_typing_indicator($extracted['sender']);

        $conversation_id = 'tg_' . substr($extracted['identifier'], 0, 16);

        // Set context for chat history hook
        $this->current_context = array(
            'channel'     => 'telegram',
            'external_id' => $extracted['external_id'],
            'user_hash'   => $extracted['identifier'],
        );

        // Check multi-step state
        $state = $this->get_user_state($extracted['identifier']);
        if ($state && isset($state['action']) && $state['action'] === 'inquiry') {
            return $this->handle_inquiry_state($extracted, $conversation_id, $state);
        }

        // Process through AI
        $ai_response = $this->process_message($extracted['message'], $conversation_id);

        // Enforce Telegram 4096 char limit
        if (mb_strlen($ai_response) > 4096) {
            $ai_response = mb_substr($ai_response, 0, 4066) . "\n\n[Message truncated]";
        }

        // Send and save
        $this->send_response($extracted['sender'], $ai_response);
        $this->save_to_history($conversation_id, $extracted['message'], $ai_response);

        $this->current_context = null;

        return new WP_REST_Response(array('ok' => true), 200);
    }

    // =========================================================================
    // Telegram Bot API
    // =========================================================================

    /**
     * Send message via Telegram Bot API
     */
    public function send_message($chat_id, $message) {
        $response = wp_remote_post($this->tg_api_url('sendMessage'), array(
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array(
                'chat_id' => $chat_id,
                'text'    => $message,
            )),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            error_log('[Telegram] Send error: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $error_body = json_decode(wp_remote_retrieve_body($response), true);
            $error_desc = isset($error_body['description']) ? $error_body['description'] : 'HTTP ' . $code;
            error_log('[Telegram] API error: ' . $error_desc);
            return false;
        }

        return true;
    }

    /**
     * Send typing indicator via Telegram Bot API
     */
    private function send_typing_indicator($chat_id) {
        $response = wp_remote_post($this->tg_api_url('sendChatAction'), array(
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array(
                'chat_id' => $chat_id,
                'action'  => 'typing',
            )),
            'timeout' => 5,
        ));

        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build Telegram Bot API URL for a given method
     */
    private function tg_api_url($method) {
        return 'https://api.telegram.org/bot' . $this->bot_token . '/' . $method;
    }

    /**
     * Handle multi-step inquiry flow
     */
    private function handle_inquiry_state($extracted, $conversation_id, $state) {
        $response = __('Thank you for your inquiry. The site owner has been notified.', 'ai-chat-wp');
        $this->send_response($extracted['sender'], $response);
        $this->clear_user_state($extracted['identifier']);
        $this->current_context = null;
        return new WP_REST_Response(array('ok' => true), 200);
    }
}
