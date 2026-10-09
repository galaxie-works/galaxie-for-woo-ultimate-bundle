<?php
/**
 * AI Chat for WordPress - Webhook Tool for AI
 *
 * Adds the trigger_webhook_action tool that allows AI to trigger
 * webhooks with structured JSON data to external systems
 * like N8N, Zapier, or Make.
 * This feature is exclusive to Pro version.
 *
 * @package AICWP
 * @since 1.9.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Webhook_Tool {

    /**
     * Constructor
     */
    public function __construct() {
        if (!get_option('aicwp_webhook_enabled', 0)) {
            return;
        }

        // Add webhook tool to the list of AI tools
        add_filter('aicwp_chat_tools', array($this, 'add_webhook_tool'));

        // Add webhook tool instructions to system prompt
        add_filter('aicwp_chat_system_prompt_contact_tool', array($this, 'add_webhook_instructions'), 20, 2);

        // Handle webhook tool calls from messaging channels (WhatsApp, Telegram, etc.)
        if (get_option('aicwp_whatsapp_enabled', 0) || get_option('aicwp_telegram_enabled', 0)) {
            add_filter('aicwp_messaging_execute_tool', array($this, 'handle_messaging_tool_call'), 10, 4);
        }

        // Handle webhook tool calls from chat proxy (server-side execution)
        add_filter('aicwp_proxy_execute_tool', array($this, 'handle_proxy_tool_call'), 10, 4);

        // Strip sensitive tool details from frontend config
        add_filter('aicwp_frontend_tools', array($this, 'strip_frontend_tool_details'));
    }

    /**
     * Handle trigger_webhook_action calls from messaging channels.
     *
     * Bridges the messaging execute_tool filter (plain $args array) to
     * handle_webhook_trigger() which expects a WP_REST_Request.
     *
     * @param mixed  $result        Current result (null if unhandled).
     * @param string $function_name Tool name called by AI.
     * @param array  $args          Tool arguments from AI.
     * @param array  $context       Messaging channel context (user_hash, channel, etc.).
     * @return array|null Result array or null to pass through.
     */
    public function handle_messaging_tool_call($result, $function_name, $args, $context = null) {
        if ($function_name !== 'trigger_webhook_action') {
            return $result;
        }

        // Build a synthetic WP_REST_Request from the flat args array
        $request = new WP_REST_Request('POST', '/aicwp/v1/webhook-trigger');
        $request->set_param('action_id', isset($args['action_id']) ? $args['action_id'] : '');

        // All remaining args are data fields
        $data = $args;
        unset($data['action_id']);
        $request->set_param('data', $data);

        // Pass messaging user identifier for per-user rate limiting
        if (!empty($context['user_hash'])) {
            $request->set_param('_messaging_identifier', $context['user_hash']);
        }

        $response = $this->handle_webhook_trigger($request);

        if (is_wp_error($response)) {
            return array('error' => $response->get_error_message());
        }

        $data = ($response instanceof WP_REST_Response) ? $response->get_data() : $response;
        return is_array($data) ? $data : array('success' => true);
    }

    /**
     * Handle trigger_webhook_action calls from chat proxy.
     *
     * Executes the webhook server-side and returns a result for the
     * second AI API call made by chat_proxy.
     *
     * @param mixed  $result        Current result (null if unhandled).
     * @param string $function_name Tool name called by AI.
     * @param array  $args          Tool arguments from AI.
     * @param array  $context       Request context (request, session_id).
     * @return array|null Result array or null to pass through.
     */
    public function handle_proxy_tool_call($result, $function_name, $args, $context = null) {
        if ($function_name !== 'trigger_webhook_action') {
            return $result;
        }

        // Build synthetic WP_REST_Request for handle_webhook_trigger
        $request = new WP_REST_Request('POST', '/aicwp/v1/webhook-trigger');
        $request->set_param('action_id', isset($args['action_id']) ? $args['action_id'] : '');

        $data = $args;
        unset($data['action_id']);
        $request->set_param('data', $data);

        if (!empty($context['session_id'])) {
            $request->set_param('conversation_id', sanitize_text_field($context['session_id']));
        }

        $response = $this->handle_webhook_trigger($request);

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $data = ($response instanceof WP_REST_Response) ? $response->get_data() : $response;
        return is_array($data) ? $data : array('success' => true);
    }

    /**
     * Strip sensitive details from webhook tool before sending to frontend.
     *
     * Keeps the tool entry so tool_choice: "auto" is sent, but removes
     * action_id enum and descriptions to prevent unauthenticated enumeration.
     *
     * @param array $tools Tools array for frontend config.
     * @return array Modified tools array.
     */
    public function strip_frontend_tool_details($tools) {
        foreach ($tools as &$tool) {
            if (isset($tool['function']['name']) && $tool['function']['name'] === 'trigger_webhook_action') {
                $tool['function']['parameters'] = array(
                    'type' => 'object',
                    'properties' => array(
                        'action_id' => array('type' => 'string'),
                    ),
                    'required' => array('action_id'),
                );
            }
        }
        unset($tool);
        return $tools;
    }

    /**
     * Handle webhook trigger request
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function handle_webhook_trigger($request) {
        // Check if webhook feature is enabled
        if (!get_option('aicwp_webhook_enabled', 0)) {
            return new WP_Error(
                'webhook_disabled',
                __('Webhook actions are not enabled.', 'ai-chat-wp'),
                array('status' => 403)
            );
        }

        // Get webhook URL
        $webhook_url = get_option('aicwp_webhook_url', '');
        if (empty($webhook_url)) {
            return new WP_Error(
                'webhook_not_configured',
                __('Webhook URL is not configured.', 'ai-chat-wp'),
                array('status' => 500)
            );
        }

        // Get action ID and validate against configured actions
        $action_id = $request->get_param('action_id');
        $configured_actions = get_option('aicwp_webhook_actions', array());

        $matched_action = null;
        foreach ($configured_actions as $action) {
            if (isset($action['action_id']) && $action['action_id'] === $action_id) {
                $matched_action = $action;
                break;
            }
        }

        if (!$matched_action) {
            return new WP_Error(
                'invalid_action',
                __('Unknown webhook action.', 'ai-chat-wp'),
                array('status' => 400)
            );
        }

        // Rate limiting: max 5 webhook triggers per hour
        // Use per-user identifier for messaging channels, IP for web requests
        $messaging_id = $request->get_param('_messaging_identifier');
        $rate_limit_check = $this->check_webhook_rate_limit($messaging_id);
        if (is_wp_error($rate_limit_check)) {
            return $rate_limit_check;
        }

        // Build payload data - sanitize all data fields the AI sends
        $raw_data = $request->get_param('data');
        if (!is_array($raw_data)) {
            $raw_data = array();
        }

        $payload_data = array();
        foreach ($raw_data as $key => $value) {
            $key = sanitize_key($key);
            if (!empty($key) && is_scalar($value)) {
                $payload_data[$key] = sanitize_text_field(strval($value));
            }
        }

        // Add auto-captured fields
        $conversation_id = $request->get_param('conversation_id');
        if (!empty($conversation_id)) {
            $payload_data['conversation_id'] = $conversation_id;
        }
        $payload_data['current_page'] = esc_url(wp_get_referer() ?: home_url());
        if (is_user_logged_in()) {
            $payload_data['user_id'] = get_current_user_id();
        }

        // Build full payload
        $payload = array(
            'action'       => $action_id,
            'action_label' => isset($matched_action['label']) ? $matched_action['label'] : $action_id,
            'timestamp'    => current_time('c'),
            'site_url'     => home_url(),
            'data'         => $payload_data,
        );

        $json_payload = wp_json_encode($payload);

        // Build headers
        $headers = array(
            'Content-Type' => 'application/json',
        );

        // Add HMAC signature if secret is configured
        $webhook_secret = get_option('aicwp_webhook_secret', '');
        if (!empty($webhook_secret)) {
            $signature = hash_hmac('sha256', $json_payload, $webhook_secret);
            $headers['X-Webhook-Signature'] = 'sha256=' . $signature;
        }

        // Send webhook
        $response = wp_remote_post($webhook_url, array(
            'body'    => $json_payload,
            'headers' => $headers,
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            AICWP_Plugin::debug_log('Webhook trigger failed: ' . $response->get_error_message(), 'error');
            return new WP_Error(
                'webhook_failed',
                __('Failed to trigger webhook. Please try again later.', 'ai-chat-wp'),
                array('status' => 500)
            );
        }

        $response_code = wp_remote_retrieve_response_code($response);

        // Accept 2xx status codes
        if ($response_code < 200 || $response_code >= 300) {
            AICWP_Plugin::debug_log("Webhook returned HTTP {$response_code} for action: {$action_id}", 'error');
            return new WP_Error(
                'webhook_error',
                __('Webhook returned an error. Please try again later.', 'ai-chat-wp'),
                array('status' => 502)
            );
        }

        // Record rate limit
        $this->record_webhook_submission($messaging_id);

        AICWP_Plugin::debug_log("Webhook triggered successfully: {$action_id}", 'info');

        $action_label = isset($matched_action['label']) ? esc_html($matched_action['label']) : sanitize_key($action_id);

        // Capture response data from the remote server (max 5KB to prevent abuse)
        $response_body = wp_remote_retrieve_body($response);
        $remote_data = null;
        if (!empty($response_body)) {
            $response_body = substr($response_body, 0, 5120);
            $decoded = json_decode($response_body, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $remote_data = $this->sanitize_remote_data($decoded);
            } else {
                // Non-JSON response — pass as sanitized plain text
                $remote_data = array('response_text' => sanitize_text_field(substr($response_body, 0, 2000)));
            }
        }

        $result = array(
            'success' => true,
            'message' => sprintf(
                /* translators: %s: action label */
                __('Your "%s" request has been submitted successfully.', 'ai-chat-wp'),
                $action_label
            ),
        );

        if (!empty($remote_data)) {
            $result['remote_data'] = $remote_data;
        }

        return new WP_REST_Response($result, 200);
    }

    /**
     * Add trigger_webhook_action tool to AI tools array
     *
     * @param array $tools Existing tools array
     * @return array Modified tools array
     */
    public function add_webhook_tool($tools) {
        if (!get_option('aicwp_webhook_enabled', 0)) {
            return $tools;
        }

        $webhook_url = get_option('aicwp_webhook_url', '');
        if (empty($webhook_url)) {
            return $tools;
        }

        $actions = get_option('aicwp_webhook_actions', array());
        if (empty($actions) || !is_array($actions)) {
            return $tools;
        }

        // Build enum of action IDs and descriptions for the AI
        $action_enum = array();
        $action_descriptions = array();
        foreach ($actions as $action) {
            if (empty($action['action_id']) || empty($action['label'])) {
                continue;
            }
            $action_enum[] = $action['action_id'];
            $desc = $action['label'];
            if (!empty($action['description'])) {
                $desc .= ' - ' . $action['description'];
            }
            $action_descriptions[] = $desc;
        }

        if (empty($action_enum)) {
            return $tools;
        }

        // Build properties - action_id is always required
        $properties = array(
            'action_id' => array(
                'type' => 'string',
                'enum' => $action_enum,
                'description' => 'The action to trigger. Available actions: ' . implode('; ', $action_descriptions),
            ),
        );

        // Collect all unique fields across all actions - admin-defined, no hardcoded list
        $all_fields = array();
        foreach ($actions as $action) {
            if (!empty($action['fields']) && is_array($action['fields'])) {
                foreach ($action['fields'] as $field) {
                    $field = trim($field);
                    if (!empty($field) && !isset($all_fields[$field])) {
                        $all_fields[$field] = true;
                    }
                }
            }
        }

        // Add each admin-defined field as a string parameter
        foreach (array_keys($all_fields) as $field) {
            $properties[$field] = array(
                'type' => 'string',
                'description' => ucfirst(str_replace('_', ' ', $field)) . '. Must be provided by the user - never assume or fabricate.',
            );
        }

        $tools[] = array(
            'type' => 'function',
            'function' => array(
                'name' => 'trigger_webhook_action',
                'description' => 'Trigger a webhook action to send structured data to an external system. Use this ONLY when the user explicitly requests one of the available actions. You MUST collect all required data fields before calling this function. ALWAYS ask for confirmation before triggering.',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => array('action_id'),
                ),
            ),
        );

        return $tools;
    }

    /**
     * Add webhook tool instructions to system prompt
     *
     * @param string $prompt Current system prompt
     * @param bool $include_tools Whether tools are included
     * @return string Modified prompt
     */
    public function add_webhook_instructions($prompt, $include_tools) {
        if (!$include_tools) {
            return $prompt;
        }

        if (!get_option('aicwp_webhook_enabled', 0)) {
            return $prompt;
        }

        $actions = get_option('aicwp_webhook_actions', array());
        if (empty($actions) || !is_array($actions)) {
            return $prompt;
        }

        // Build action instructions
        $action_instructions = '';
        foreach ($actions as $action) {
            if (empty($action['action_id'])) {
                continue;
            }

            $label = !empty($action['label']) ? $action['label'] : $action['action_id'];
            $desc = !empty($action['description']) ? $action['description'] : '';
            $fields = !empty($action['fields']) && is_array($action['fields']) ? $action['fields'] : array();

            $action_instructions .= "- \"{$label}\" (action_id: {$action['action_id']}): {$desc}\n";
            if (!empty($fields)) {
                $action_instructions .= "  Required fields: " . implode(', ', $fields) . "\n";
            }
        }

        $prompt .= "
========================================
WEBHOOK ACTIONS TOOL (trigger_webhook_action):
========================================
You can trigger the following actions on behalf of users:

{$action_instructions}

RULES:
1. ONLY trigger when user EXPLICITLY requests an action listed above
2. Collect ALL required fields for the specific action before triggering
3. ALWAYS confirm with the user before triggering: \"Should I proceed with [action]?\"
4. Never assume or fabricate data - ask the user for each required field
5. If the user's request doesn't match any available action, let them know what actions are available
6. The remote system may return data — use it in your response to provide helpful context to the user

";

        // Append custom admin instructions if set
        $custom_instructions = get_option('aicwp_webhook_instructions', '');
        if (!empty($custom_instructions)) {
            $prompt .= "ADDITIONAL WEBHOOK INSTRUCTIONS FROM ADMIN:\n" . $custom_instructions . "\n\n";
        }

        return $prompt;
    }

    /**
     * Check webhook rate limit
     *
     * @param string $messaging_identifier Optional per-user identifier for messaging channels.
     * @return true|WP_Error
     */
    private function check_webhook_rate_limit($messaging_identifier = '') {
        if (!empty($messaging_identifier)) {
            $transient_key = 'aicwp_webhook_' . md5('msg_' . $messaging_identifier);
        } else {
            $ip = AICWP_Utility_Helper::get_client_ip_secure();
            $transient_key = 'aicwp_webhook_' . md5($ip);
        }
        $submissions = get_transient($transient_key);

        if ($submissions !== false && $submissions >= 5) {
            return new WP_Error(
                'rate_limit_exceeded',
                __('Too many requests. Please try again later.', 'ai-chat-wp'),
                array('status' => 429)
            );
        }

        return true;
    }

    /**
     * Record webhook submission for rate limiting
     *
     * @param string $messaging_identifier Optional per-user identifier for messaging channels.
     */
    private function record_webhook_submission($messaging_identifier = '') {
        if (!empty($messaging_identifier)) {
            $transient_key = 'aicwp_webhook_' . md5('msg_' . $messaging_identifier);
        } else {
            $ip = AICWP_Utility_Helper::get_client_ip_secure();
            $transient_key = 'aicwp_webhook_' . md5($ip);
        }
        $submissions = get_transient($transient_key);

        if ($submissions === false) {
            set_transient($transient_key, 1, HOUR_IN_SECONDS);
        } else {
            // Increment count without resetting the TTL window
            $timeout_key = '_transient_timeout_' . $transient_key;
            $timeout = get_option($timeout_key);
            $remaining = $timeout ? max((int) $timeout - time(), 60) : HOUR_IN_SECONDS;
            set_transient($transient_key, (int) $submissions + 1, $remaining);
        }
    }

    /**
     * Recursively sanitize remote webhook response data
     * Strips HTML/scripts, caps depth and string length to prevent abuse
     *
     * @param mixed $data Data to sanitize
     * @param int $depth Current recursion depth
     * @return mixed Sanitized data
     */
    private function sanitize_remote_data($data, $depth = 0) {
        // Prevent deeply nested payloads
        if ($depth > 5) {
            return null;
        }

        if (is_string($data)) {
            return sanitize_text_field(substr($data, 0, 2000));
        }

        if (is_numeric($data) || is_bool($data)) {
            return $data;
        }

        if (is_array($data)) {
            $clean = array();
            $count = 0;
            foreach ($data as $key => $value) {
                // Limit to 50 keys per level
                if (++$count > 50) {
                    break;
                }
                $clean_key = sanitize_key(substr((string) $key, 0, 100));
                $clean[$clean_key] = $this->sanitize_remote_data($value, $depth + 1);
            }
            return $clean;
        }

        return null;
    }

}
