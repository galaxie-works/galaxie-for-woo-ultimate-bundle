<?php
/**
 * WhatsApp Handler via Twilio
 *
 * Handles incoming WhatsApp messages from Twilio webhook,
 * processes them through the base messaging channel's AI engine,
 * and sends responses back via Twilio API.
 *
 * @package AICWP
 * @since 1.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class AICWP_WhatsApp_Handler extends AICWP_Messaging_Channel {

    const API_NAMESPACE = 'aicwp/v1';

    private $account_sid;
    private $auth_token;
    private $from_number;

    public function __construct() {
        if (!get_option('aicwp_whatsapp_enabled', 0)) {
            return;
        }

        $this->account_sid = get_option('aicwp_whatsapp_account_sid', '');
        $this->auth_token  = get_option('aicwp_whatsapp_auth_token', '');
        $this->from_number = get_option('aicwp_whatsapp_from_number', '');

        if (empty($this->account_sid) || empty($this->auth_token) || empty($this->from_number)) {
            return;
        }

        add_action('rest_api_init', array($this, 'register_webhook'));
        $this->init_chat_history_hook();
    }

    protected function get_channel_name() {
        return 'whatsapp';
    }

    /**
     * Check if WhatsApp integration is enabled and configured
     */
    public function is_configured() {
        return get_option('aicwp_whatsapp_enabled', 0)
            && !empty($this->account_sid)
            && !empty($this->auth_token)
            && !empty($this->from_number);
    }

    /**
     * Register REST webhook endpoint
     */
    public function register_webhook() {
        register_rest_route(self::API_NAMESPACE, '/whatsapp-webhook', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'handle_webhook'),
            'permission_callback' => array($this, 'verify_twilio_request'),
        ));
    }

    // =========================================================================
    // Abstract implementations
    // =========================================================================

    protected function verify_request($request) {
        return $this->verify_twilio_request($request);
    }

    protected function extract_message($request) {
        $from = $request->get_param('From');
        $body = $request->get_param('Body');
        $wa_id = $request->get_param('WaId');
        $message_sid = $request->get_param('MessageSid');

        if (!$this->is_valid_whatsapp_number($from) || empty($body)) {
            return null;
        }

        // Prefer WaId for identifier; fall back to From to avoid hashing empty string
        $raw_identifier = !empty($wa_id) ? $wa_id : $from;
        $phone_hash = $this->hash_identifier($raw_identifier);

        return array(
            'message'     => sanitize_text_field($body),
            'sender'      => $from,
            'identifier'  => $phone_hash,
            'external_id' => sanitize_text_field($message_sid),
        );
    }

    protected function send_response($to, $text) {
        return $this->send_message($to, $text);
    }

    // =========================================================================
    // Webhook handling
    // =========================================================================

    /**
     * Verify Twilio request signature (HMAC-SHA1)
     */
    public function verify_twilio_request($request) {
        if (empty($this->auth_token)) {
            return new WP_Error('not_configured', __('WhatsApp integration is not configured.', 'ai-chat-wp'), array('status' => 503));
        }

        $signature = $request->get_header('X-Twilio-Signature');
        if (empty($signature)) {
            return new WP_Error('missing_signature', __('Missing Twilio signature.', 'ai-chat-wp'), array('status' => 401));
        }

        // Build URL from actual request — raw values only, no sanitization
        // Twilio signs the exact URL; any normalization would break HMAC verification
        $protocol = is_ssl() ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? wp_unslash($_SERVER['HTTP_HOST']) : '';
        $uri  = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';

        // Validate host format (never output, only used for HMAC computation)
        if (!preg_match('/^[a-zA-Z0-9.\-]+(?::\d+)?$/', $host)) {
            return new WP_Error('invalid_host', __('Invalid host in request.', 'ai-chat-wp'), array('status' => 400));
        }

        $url = $protocol . '://' . $host . $uri;

        // Build validation string: URL + sorted key/value pairs (no delimiters)
        $params = $request->get_body_params();
        ksort($params);
        $validation_string = $url;
        foreach ($params as $key => $value) {
            $validation_string .= $key . $value;
        }

        $calculated = base64_encode(hash_hmac('sha1', $validation_string, $this->auth_token, true));

        if (!hash_equals($signature, $calculated)) {
            return new WP_Error('invalid_signature', __('Invalid Twilio signature.', 'ai-chat-wp'), array('status' => 401));
        }

        return true;
    }

    /**
     * Handle incoming Twilio webhook
     */
    public function handle_webhook($request) {
        $extracted = $this->extract_message($request);

        if (!$extracted) {
            return $this->twiml_response();
        }

        // Per-user rate limit check (before any AI processing)
        $rate_check = $this->check_messaging_rate_limit($extracted['identifier']);
        if (!$rate_check['allowed']) {
            $this->send_response($extracted['sender'], $rate_check['error']);
            return $this->twiml_response();
        }

        // Send typing indicator immediately
        $message_sid = $request->get_param('MessageSid');
        if (!empty($message_sid)) {
            $this->send_typing_indicator($message_sid);
        }

        $conversation_id = 'wa_' . substr($extracted['identifier'], 0, 16);

        // Set context for chat history hook
        $this->current_context = array(
            'channel'     => 'whatsapp',
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

        // Enforce WhatsApp 4096 char limit
        if (mb_strlen($ai_response) > 4096) {
            $ai_response = mb_substr($ai_response, 0, 4066) . "\n\n[Message truncated]";
        }

        // Send and save
        $this->send_response($extracted['sender'], $ai_response);
        $this->save_to_history($conversation_id, $extracted['message'], $ai_response);

        $this->current_context = null;

        return $this->twiml_response();
    }

    // =========================================================================
    // Twilio API
    // =========================================================================

    /**
     * Send message via Twilio REST API
     */
    public function send_message($to, $message, $media_url = null) {
        $url = sprintf('https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json', $this->account_sid);

        $body = array(
            'To'   => $to,
            'From' => $this->from_number,
            'Body' => $message,
        );

        if ($media_url && $this->is_valid_media_url($media_url)) {
            $body['MediaUrl'] = $media_url;
        }

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->account_sid . ':' . $this->auth_token),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ),
            'body'    => $body,
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            error_log('[WhatsApp] Send error: ' . $response->get_error_message());
            return false;
        }

        if (wp_remote_retrieve_response_code($response) !== 201) {
            error_log('[WhatsApp] API error: ' . wp_remote_retrieve_body($response));
            return false;
        }

        return true;
    }

    /**
     * Send typing indicator via Twilio v2 API
     */
    private function send_typing_indicator($message_sid) {
        $response = wp_remote_post('https://messaging.twilio.com/v2/Indicators/Typing.json', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->account_sid . ':' . $this->auth_token),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ),
            'body'    => array('messageId' => $message_sid, 'channel' => 'whatsapp'),
            'timeout' => 5,
        ));

        return !is_wp_error($response) && in_array(wp_remote_retrieve_response_code($response), array(200, 201));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function twiml_response($message = null) {
        $twiml = '<?xml version="1.0" encoding="UTF-8"?><Response>';
        if ($message) {
            $twiml .= '<Message><Body>' . esc_xml($message) . '</Body></Message>';
        }
        $twiml .= '</Response>';

        return new WP_REST_Response($twiml, 200, array('Content-Type' => 'text/xml'));
    }

    private function is_valid_whatsapp_number($number) {
        return (bool) preg_match('/^whatsapp:\+\d{10,15}$/', $number);
    }

    private function is_valid_media_url($url) {
        if (strpos($url, 'https://') !== 0) {
            return false;
        }
        $ext = strtolower(pathinfo(wp_parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        return in_array($ext, array('jpg', 'jpeg', 'png', 'gif'), true);
    }

    /**
     * Handle multi-step inquiry flow
     */
    private function handle_inquiry_state($extracted, $conversation_id, $state) {
        $response = __('Thank you for your inquiry. The site owner has been notified.', 'ai-chat-wp');
        $this->send_response($extracted['sender'], $response);
        $this->clear_user_state($extracted['identifier']);
        $this->current_context = null;
        return $this->twiml_response();
    }
}
