<?php
/**
 * Abstract Messaging Channel
 *
 * Base class for all messaging channels (WhatsApp, Telegram, etc.).
 * Handles AI chat logic, tool calling, conversation history, and user state.
 * Subclasses only implement channel-specific transport (verify, extract, send).
 *
 * @package AICWP
 * @since 1.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class AICWP_Messaging_Channel {

    /**
     * Channel name (e.g. 'whatsapp', 'telegram')
     */
    abstract protected function get_channel_name();

    /**
     * Verify incoming request authenticity
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    abstract protected function verify_request($request);

    /**
     * Extract user message and metadata from incoming request
     *
     * @param WP_REST_Request $request
     * @return array{message: string, sender: string, identifier: string, external_id: string}|null
     */
    abstract protected function extract_message($request);

    /**
     * Send a text response to the user
     *
     * @param string $to Recipient identifier
     * @param string $text Response text
     * @return bool
     */
    abstract protected function send_response($to, $text);

    /**
     * Get GDPR-safe user identifier from raw identifier
     *
     * @param string $raw Raw identifier (phone number, user ID, etc.)
     * @return string Hashed identifier
     */
    protected function hash_identifier($raw) {
        return hash('sha256', $raw);
    }

    /**
     * Context for the current request (set during handle_webhook, used by chat history filter)
     */
    protected $current_context = null;

    /**
     * Set up the chat history filter
     */
    protected function init_chat_history_hook() {
        add_filter('aicwp_chat_history_extra_data', array($this, 'filter_chat_history_data'), 10, 2);
    }

    /**
     * Filter callback: add channel fields to chat history insert
     */
    public function filter_chat_history_data($extra, $insert_data) {
        if (empty($this->current_context)) {
            return $extra;
        }

        if (!empty($this->current_context['channel']) && AICWP_Messaging_Migrations::has_column('channel')) {
            $extra['channel'] = array('value' => $this->current_context['channel'], 'format' => '%s');
        }
        if (!empty($this->current_context['external_id']) && AICWP_Messaging_Migrations::has_column('external_id')) {
            $extra['external_id'] = array('value' => $this->current_context['external_id'], 'format' => '%s');
        }
        if (!empty($this->current_context['user_hash']) && AICWP_Messaging_Migrations::has_column('phone_hash')) {
            $extra['phone_hash'] = array('value' => $this->current_context['user_hash'], 'format' => '%s');
        }

        return $extra;
    }

    /**
     * Process an incoming message through the AI with tool calling
     *
     * @param string $user_message User's text
     * @param string $conversation_id Conversation identifier
     * @return string AI response (plain text, formatted for channel)
     */
    protected function process_message($user_message, $conversation_id) {
        $history = $this->get_conversation_history($conversation_id);
        $messages = $this->build_messages_array($history, $user_message);
        $ai_response = $this->execute_chat_with_tools($messages);
        return $this->format_for_plaintext($ai_response);
    }

    /**
     * Save exchange to chat history via the free plugin
     */
    protected function save_to_history($conversation_id, $user_message, $ai_response, $user_id = null) {
        if (!class_exists('AICWP_Chat_History')) {
            return;
        }
        AICWP_Chat_History::save_exchange(
            $conversation_id,
            $user_message,
            $ai_response,
            get_option('aicwp_chat_model', 'gpt-4o-mini'),
            $user_id
        );
    }

    /**
     * Execute chat with server-side tool calling loop
     *
     * Uses free plugin's static methods for tools/prompt, makes AI API calls directly.
     *
     * @param array $messages OpenAI-format messages
     * @return string Final AI response content
     */
    private function execute_chat_with_tools($messages) {
        $max_iterations = 5;
        $model = get_option('aicwp_chat_model', 'gpt-4o-mini');
        $tools = class_exists('AICWP_Chat_API') ? AICWP_Chat_API::get_chat_tools() : array();

        $channel = $this->get_channel_name();

        for ($i = 0; $i < $max_iterations; $i++) {
            $response = $this->call_ai_api($messages, $model, $tools, ($i > 0));

            if (is_wp_error($response)) {
                error_log("[{$channel}] AI API error: " . $response->get_error_message());
                return __('Sorry, I could not process your request. Please try again later.', 'ai-chat-wp');
            }

            $assistant_msg = isset($response['choices'][0]['message']) ? $response['choices'][0]['message'] : null;

            if (!$assistant_msg) {
                return __('Sorry, I could not generate a response.', 'ai-chat-wp');
            }

            // No tool calls — return final text
            if (empty($assistant_msg['tool_calls'])) {
                return isset($assistant_msg['content']) ? $assistant_msg['content'] : '';
            }

            // Process tool calls
            $messages[] = $assistant_msg;

            foreach ($assistant_msg['tool_calls'] as $tool_call) {
                $fn = $tool_call['function']['name'];
                $args = json_decode($tool_call['function']['arguments'], true) ?: array();
                $result = $this->execute_tool($fn, $args);

                $messages[] = array(
                    'role'         => 'tool',
                    'tool_call_id' => $tool_call['id'],
                    'content'      => is_string($result) ? $result : wp_json_encode($result),
                );
            }
        }

        return __('Sorry, the request was too complex. Please try a simpler question.', 'ai-chat-wp');
    }

    /**
     * Make a direct AI API call using the free plugin's AI provider
     *
     * @param array  $messages Messages array
     * @param string $model    Model name
     * @param array  $tools    Tool definitions
     * @param bool   $skip_rate_limit Skip rate limiting for follow-up calls
     * @return array|WP_Error API response data or error
     */
    private function call_ai_api($messages, $model, $tools, $skip_rate_limit = false) {
        if (!class_exists('AICWP_Provider')) {
            return new WP_Error('missing_provider', 'AI provider class not available.');
        }

        $provider = new AICWP_Provider();
        $api_key = $provider->get_api_key();

        if (empty($api_key)) {
            return new WP_Error('no_api_key', 'AI API key is not configured.');
        }

        // Rate limiting (first call only)
        if (!$skip_rate_limit && class_exists('AICWP_Embedding_Manager')) {
            if (!AICWP_Embedding_Manager::try_acquire_rate_limit()) {
                return new WP_Error('rate_limit', 'Rate limit exceeded.');
            }
        }

        $payload = $provider->prepare_chat_payload($messages, $tools, 'auto');
        $payload['model'] = $model;

        // Normalize model-specific parameters (max_tokens key, temperature, reasoning)
        $max_tokens = intval(get_option('aicwp_chat_max_tokens', 2000));
        $payload = $provider->normalize_chat_payload($payload, array(
            'max_tokens' => $max_tokens,
        ));

        $response = wp_remote_post($provider->get_endpoint('chat'), array(
            'headers'     => $provider->get_headers(),
            'body'        => wp_json_encode($payload),
            'timeout'     => 60,
            'data_format' => 'body',
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $err_msg = isset($body['error']['message']) ? $body['error']['message'] : 'API error ' . $code;
            return new WP_Error('api_error', $err_msg);
        }

        return $body;
    }

    /**
     * Execute a tool function by name
     *
     * Delegates to the same integration classes the website chat uses.
     *
     * @param string $function_name Tool name
     * @param array  $args Tool arguments
     * @return mixed Result
     */
    private function execute_tool($function_name, $args) {
        switch ($function_name) {
            case 'search_universal_content':
                return $this->tool_search_universal_content($args);
            case 'search_products':
                return $this->tool_search_products($args);
            case 'get_product_details':
                return $this->tool_get_product_details($args);
            case 'check_order_status':
                return $this->tool_check_order_status($args);
            case 'send_contact_message':
                return $this->tool_send_contact_message($args);
            default:
                // Let Pro tools (webhooks, etc.) handle via filter
                // Pass current_context so tools can use per-user identifier for rate limiting
                $result = apply_filters('aicwp_messaging_execute_tool', null, $function_name, $args, $this->current_context);
                return $result !== null ? $result : array('error' => 'Unknown tool: ' . $function_name);
        }
    }

    // =========================================================================
    // Tool implementations — reuse existing integration classes
    // =========================================================================

    private function tool_search_universal_content($args) {
        $query = isset($args['query']) ? $args['query'] : '';
        if (empty($query) || !class_exists('AICWP_AI_Engine')) {
            return array('error' => 'query required or AI engine not available');
        }

        try {
            $provider = new AICWP_Provider();
            $api_key = $provider->get_api_key();
            if (empty($api_key)) {
                return array('error' => 'API key not configured');
            }

            $top_results = max(2, intval(get_option('aicwp_chat_rag_sources_limit', 5)));
            $post_types = AICWP_Chat_API::get_universal_search_post_types();

            $ai_engine = new AICWP_AI_Engine($api_key);
            $search_results = $ai_engine->search($query, $top_results, 0, implode(',', $post_types), false, array(), true);

            if (empty($search_results['listings'])) {
                return array('success' => true, 'total' => 0, 'content' => '');
            }

            $embedding_manager = new AICWP_Embedding_Manager($api_key);
            $chunk_mapping = isset($search_results['chunk_mapping']) ? $search_results['chunk_mapping'] : array();
            $sources = array();
            $context = '';
            $idx = 0;

            foreach ($search_results['listings'] as $result) {
                $post_id = $result['id'];
                $post = get_post($post_id);
                if (!$post) continue;

                $idx++;
                $has_content = false;

                if (isset($chunk_mapping[$post_id]) && !empty($chunk_mapping[$post_id])) {
                    $chunks = array();
                    foreach ($chunk_mapping[$post_id] as $ci) {
                        $cp = get_post($ci['chunk_id']);
                        if ($cp) {
                            $chunks[] = sprintf("[Chunk %d/%d]\n%s",
                                get_post_meta($ci['chunk_id'], '_chunk_number', true),
                                get_post_meta($ci['chunk_id'], '_chunk_total', true),
                                $cp->post_content
                            );
                        }
                    }
                    if (!empty($chunks)) {
                        $context .= "\n\n=== SOURCE {$idx}: " . get_the_title($post_id) . " ===\n";
                        $context .= "URL: " . get_permalink($post_id) . "\nType: " . ucfirst($post->post_type) . "\n";
                        $context .= "\nCONTENT:\n" . implode("\n\n---\n\n", $chunks) . "\n";
                        $context .= "=== END SOURCE {$idx} ===\n";
                        $has_content = true;
                    }
                } else {
                    $content = $embedding_manager->get_content_for_embedding($post_id);
                    if (!empty($content)) {
                        $context .= "\n\n=== SOURCE {$idx}: " . get_the_title($post_id) . " ===\n";
                        $context .= "URL: " . get_permalink($post_id) . "\nType: " . ucfirst($post->post_type) . "\n";
                        $context .= "\nCONTENT:\n{$content}\n";
                        $context .= "=== END SOURCE {$idx} ===\n";
                        $has_content = true;
                    }
                }

                if ($has_content) {
                    $sources[] = array(
                        'id'    => $post_id,
                        'title' => get_the_title($post_id),
                        'url'   => get_permalink($post_id),
                        'type'  => $post->post_type,
                    );
                }
            }

            return array('success' => true, 'total' => count($sources), 'sources' => $sources, 'content' => $context);
        } catch (Exception $e) {
            return array('error' => $e->getMessage());
        }
    }

    private function tool_search_products($args) {
        if (!class_exists('AICWP_WooCommerce_Integration')) {
            return array('error' => 'WooCommerce integration not available');
        }

        try {
            $request = new WP_REST_Request('POST', '/aicwp/v1/woocommerce-product-search');
            foreach (array('query', 'price_min', 'price_max', 'in_stock', 'on_sale', 'rating') as $p) {
                if (isset($args[$p])) $request->set_param($p, $args[$p]);
            }
            $request->set_param('per_page', 10);

            $integration = new AICWP_WooCommerce_Integration();
            $data = $integration->search_products($request)->get_data();

            if (empty($data['results'])) {
                return array('success' => true, 'total' => 0, 'results' => array());
            }

            $condensed = array();
            foreach ($data['results'] as $product) {
                $condensed[] = array(
                    'id'           => $product['id'],
                    'title'        => $product['title'],
                    'url'          => $product['url'],
                    'price'        => isset($product['price']['formatted']) ? $product['price']['formatted'] : '',
                    'stock_status' => isset($product['stock_status']) ? $product['stock_status'] : '',
                    'rating'       => isset($product['rating']['average']) ? $product['rating']['average'] : 0,
                );
            }

            return array('success' => true, 'total' => isset($data['total']) ? $data['total'] : count($condensed), 'results' => $condensed);
        } catch (Exception $e) {
            return array('error' => $e->getMessage());
        }
    }

    private function tool_get_product_details($args) {
        if (!class_exists('AICWP_WooCommerce_Integration')) {
            return array('error' => 'WooCommerce integration not available');
        }

        // Normalize: accept both product_ids (array) and product_id (single)
        $ids = array();
        if (!empty($args['product_ids']) && is_array($args['product_ids'])) {
            $ids = array_map('intval', $args['product_ids']);
        } elseif (!empty($args['product_id'])) {
            $ids = array(intval($args['product_id']));
        }

        if (empty($ids)) {
            return array('error' => 'product_id or product_ids required');
        }

        $ids = array_slice($ids, 0, 3);
        $integration = new AICWP_WooCommerce_Integration();
        $results = array();

        foreach ($ids as $product_id) {
            try {
                $request = new WP_REST_Request('POST', '/aicwp/v1/woocommerce-product-details');
                $request->set_param('product_id', $product_id);

                $data = $integration->get_product_details($request)->get_data();

                $results[] = array(
                    'success'    => !empty($data['success']),
                    'product_id' => $product_id,
                    'title'      => isset($data['title']) ? $data['title'] : '',
                    'url'        => isset($data['url']) ? $data['url'] : '',
                    'content'    => isset($data['structured_content']) ? $data['structured_content'] : '',
                );
            } catch (Exception $e) {
                $results[] = array('error' => $e->getMessage(), 'product_id' => $product_id);
            }
        }

        if (count($results) === 1) {
            return $results[0];
        }

        return array('success' => true, 'products' => $results);
    }

    private function tool_check_order_status($args) {
        if (empty($args['order_number']) || !class_exists('AICWP_WooCommerce_Integration')) {
            return array('error' => 'order_number required or WooCommerce integration not available');
        }

        try {
            $request = new WP_REST_Request('POST', '/aicwp/v1/woocommerce-order-status');
            $request->set_param('order_number', $args['order_number']);
            if (!empty($args['billing_email'])) {
                $request->set_param('billing_email', $args['billing_email']);
            }

            $data = (new AICWP_WooCommerce_Integration())->get_order_status($request)->get_data();

            return array(
                'success' => !empty($data['success']),
                'status'  => isset($data['status']) ? $data['status'] : '',
                'content' => isset($data['structured_content']) ? $data['structured_content'] : '',
            );
        } catch (Exception $e) {
            return array('error' => $e->getMessage());
        }
    }

    private function tool_send_contact_message($args) {
        $name    = isset($args['name']) ? $args['name'] : '';
        $email   = isset($args['email']) ? $args['email'] : '';
        $message = isset($args['message']) ? $args['message'] : '';

        // Delegate to free plugin's contact form handler (uses configurable recipient, subject, headers)
        if (class_exists('AICWP_Contact_Form')) {
            $result = AICWP_Contact_Form::submit_via_tool($name, $email, $message);
            if (!empty($result['success'])) {
                return array('success' => true, 'message' => $result['message']);
            }
            return array('error' => isset($result['message']) ? $result['message'] : __('Failed to send message.', 'ai-chat-wp'));
        }

        return array('error' => __('Contact form handler not available.', 'ai-chat-wp'));
    }

    // =========================================================================
    // Per-user rate limiting for messaging channels
    // =========================================================================

    /**
     * Check per-user rate limit for messaging channels
     *
     * Mirrors the 3-tier approach from AICWP_Chat_API::check_ip_rate_limit()
     * but keyed by user identifier (hashed chat_id / WaId) instead of IP address.
     * This is necessary because all Telegram/WhatsApp webhooks arrive from a handful
     * of platform server IPs, making IP-based rate limiting useless.
     *
     * No internal multiplier is applied — each incoming user message counts as 1,
     * since call_ai_api() already skips the global rate limit on tool-calling follow-ups.
     *
     * @param string $identifier Hashed user identifier (sha256 of chat_id or WaId)
     * @return array{allowed: bool, tier: string|null, error: string|null}
     */
    protected function check_messaging_rate_limit($identifier) {
        $transient_key = 'ai_chat_msg_' . substr($identifier, 0, 16);
        $now = time();

        $tier1_limit = intval(get_option('aicwp_chat_rate_limit_tier1', 10));  // per minute
        $tier2_limit = intval(get_option('aicwp_chat_rate_limit_tier2', 30));  // per 15 min
        $tier3_limit = intval(get_option('aicwp_chat_rate_limit_tier3', 100)); // per day

        $tier1_window = 60;    // 1 minute
        $tier2_window = 900;   // 15 minutes
        $tier3_window = 86400; // 24 hours

        $timestamps = get_transient($transient_key);
        if (!is_array($timestamps)) {
            $timestamps = array();
        }

        // Prune timestamps older than 24 hours
        $timestamps = array_filter($timestamps, function ($ts) use ($now, $tier3_window) {
            return ($now - $ts) < $tier3_window;
        });

        // Count requests per tier
        $tier1_count = count(array_filter($timestamps, function ($ts) use ($now, $tier1_window) {
            return ($now - $ts) < $tier1_window;
        }));

        $tier2_count = count(array_filter($timestamps, function ($ts) use ($now, $tier2_window) {
            return ($now - $ts) < $tier2_window;
        }));

        $tier3_count = count($timestamps);

        // Check limits (strictest first)
        if ($tier1_count >= $tier1_limit) {
            return array(
                'allowed' => false,
                'tier'    => 'tier1',
                'error'   => sprintf(
                    __('Rate limit exceeded: %d messages per minute. Please wait a moment.', 'ai-chat-wp'),
                    $tier1_limit
                ),
            );
        }

        if ($tier2_count >= $tier2_limit) {
            return array(
                'allowed' => false,
                'tier'    => 'tier2',
                'error'   => sprintf(
                    __('Rate limit exceeded: %d messages per 15 minutes. Please slow down.', 'ai-chat-wp'),
                    $tier2_limit
                ),
            );
        }

        if ($tier3_count >= $tier3_limit) {
            return array(
                'allowed' => false,
                'tier'    => 'tier3',
                'error'   => sprintf(
                    __('Daily limit reached: %d messages per day. Please try again tomorrow.', 'ai-chat-wp'),
                    $tier3_limit
                ),
            );
        }

        // Allowed — record this request
        $timestamps[] = $now;
        set_transient($transient_key, array_values($timestamps), $tier3_window);

        return array('allowed' => true, 'tier' => null, 'error' => null);
    }

    // =========================================================================
    // Shared helpers
    // =========================================================================

    /**
     * Build OpenAI messages array from conversation history + new message
     */
    private function build_messages_array($history, $new_message) {
        $messages = array();

        if (class_exists('AICWP_Chat_API')) {
            $system_prompt = AICWP_Chat_API::get_system_prompt(true);

            // Override: messaging channels use plain text, not HTML
            $channel = ucfirst($this->get_channel_name());
            $system_prompt .= "\n\nIMPORTANT: This conversation is via {$channel} messaging."
                . ' Use PLAIN TEXT only. No HTML tags, no markdown, no formatting symbols like * or _.'
                . ' Use line breaks for structure.';

            $messages[] = array('role' => 'system', 'content' => $system_prompt);
        }

        foreach ($history as $entry) {
            $messages[] = array('role' => 'user', 'content' => $entry->user_message);
            $messages[] = array('role' => 'assistant', 'content' => $entry->assistant_message);
        }

        // Inject language rule (browser language unavailable in messaging channels)
        $language_rule = $this->get_messaging_language_rule();
        $messages[] = array('role' => 'user', 'content' => $new_message . "\n\n" . $language_rule);

        return $messages;
    }

    /**
     * Get language rule for messaging channels
     *
     * Browser Accept-Language header is unavailable in webhook requests,
     * so we fall back to WordPress locale instead.
     */
    private function get_messaging_language_rule() {
        $force_language = get_option('aicwp_chat_force_language', '');

        // If forced/restricted language is set, reuse existing logic
        if (!empty($force_language) && class_exists('AICWP_Chat_API')) {
            return AICWP_Chat_API::get_language_rule_inline();
        }

        // No forced language — fall back to WordPress site locale
        $locale = get_locale();
        $lang_code = explode('_', $locale)[0];

        $wp_lang = '';
        if (function_exists('locale_get_display_language')) {
            $wp_lang = locale_get_display_language($locale, 'en');
        }
        if (empty($wp_lang)) {
            $wp_lang = $lang_code;
        }

        return "[LANGUAGE RULE: Respond in the same language as my message. If unsure, use: {$wp_lang}]";
    }

    /**
     * Get conversation history from the free plugin's chat history table
     */
    protected function get_conversation_history($conversation_id, $limit = 5) {
        global $wpdb;

        if (!class_exists('AICWP_Chat_History')) {
            return array();
        }

        $table = AICWP_Chat_History::get_table_name();

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return array();
        }

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT user_message, assistant_message FROM {$table}
             WHERE conversation_id = %s ORDER BY created_at DESC LIMIT %d",
            $conversation_id,
            $limit
        ));

        return array_reverse($results);
    }

    /**
     * Convert HTML to plain text suitable for messaging channels
     */
    protected function format_for_plaintext($html) {
        $text = preg_replace('/<strong>(.+?)<\/strong>/s', '*$1*', $html);
        $text = preg_replace('/<em>(.+?)<\/em>/s', '_$1_', $text);
        $text = preg_replace('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.+?)<\/a>/s', '$2 ($1)', $text);
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/p>/i', "\n\n", $text);
        $text = preg_replace('/<li>/i', "\n- ", $text);
        $text = wp_strip_all_tags($text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    // =========================================================================
    // User state management (multi-step flows)
    // =========================================================================

    protected function get_user_state($identifier, $platform = null) {
        global $wpdb;
        $platform = $platform ?: $this->get_channel_name();
        $table = AICWP_Messaging_Migrations::get_user_states_table();

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT state FROM {$table} WHERE identifier = %s AND platform = %s AND expires_at > NOW()",
            $identifier,
            $platform
        ));

        return $row ? json_decode($row->state, true) : null;
    }

    protected function set_user_state($identifier, $state, $ttl = 3600, $platform = null) {
        global $wpdb;
        $platform = $platform ?: $this->get_channel_name();

        $wpdb->replace(
            AICWP_Messaging_Migrations::get_user_states_table(),
            array(
                'identifier' => $identifier,
                'platform'   => $platform,
                'state'      => wp_json_encode($state),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttl),
            ),
            array('%s', '%s', '%s', '%s')
        );
    }

    protected function clear_user_state($identifier, $platform = null) {
        global $wpdb;
        $platform = $platform ?: $this->get_channel_name();

        $wpdb->delete(
            AICWP_Messaging_Migrations::get_user_states_table(),
            array('identifier' => $identifier, 'platform' => $platform),
            array('%s', '%s')
        );
    }
}
