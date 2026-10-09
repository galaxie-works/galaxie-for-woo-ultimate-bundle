<?php
/**
 * Webhook Admin Settings
 *
 * Renders Webhook checkbox in the free plugin's Integrations section
 * and a configuration modal. All via hooks — zero modification to free plugin UI code.
 *
 * @package AICWP
 * @since 1.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Webhook_Admin {

    public function __construct() {
        // Checkbox in Integrations section (priority 5 = before WA/TG at default 10)
        add_action('aicwp_integrations_section', array($this, 'render_integration_checkbox'), 5);

        // Modal rendered inside aicwp-ui-admin-wrap
        add_action('aicwp_admin_modals', array($this, 'render_modal'));

        // Register settings in central registry
        add_filter('aicwp_settings_registry', array($this, 'register_settings'));

        // Enqueue webhook-specific admin JS
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

        // Exclude webhook checkbox from hidden fields (it's rendered in the form)
        add_filter('aicwp_hidden_fields_except', array($this, 'add_to_hidden_fields_except'));

        // AJAX handlers
        add_action('wp_ajax_aicwp_save_webhook_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_aicwp_test_webhook', array($this, 'ajax_test_webhook'));
    }

    /**
     * Exclude all webhook settings from hidden fields.
     *
     * The checkbox is rendered explicitly in the main form.
     * The other 4 (url, secret, actions, instructions) are only in the modal
     * and saved via their own AJAX — they must NOT appear as hidden inputs
     * or the main form save would overwrite them with empty/corrupt data
     * (especially `actions` which is a 3-level deep array that can't
     * round-trip through the 2-level hidden field serializer).
     */
    public function add_to_hidden_fields_except($fields) {
        $fields[] = 'aicwp_webhook_enabled';
        $fields[] = 'aicwp_webhook_url';
        $fields[] = 'aicwp_webhook_secret';
        $fields[] = 'aicwp_webhook_actions';
        $fields[] = 'aicwp_webhook_instructions';
        return $fields;
    }

    /**
     * Register webhook settings in the central settings registry
     */
    public function register_settings($registry) {
        $settings = array(
            'aicwp_webhook_enabled' => array(
                'type' => 'checkbox',
                'section' => 'ai-chat-config',
                'sanitize' => 'intval',
                'default' => 0,
                'description' => 'Allow AI to trigger webhooks to external systems'
            ),
            'aicwp_webhook_url' => array(
                'type' => 'text',
                'section' => 'ai-chat-config',
                'sanitize' => 'esc_url_raw',
                'default' => '',
                'description' => 'Webhook endpoint URL'
            ),
            'aicwp_webhook_secret' => array(
                'type' => 'text',
                'section' => 'ai-chat-config',
                'sanitize' => 'sanitize_text_field',
                'default' => '',
                'description' => 'Optional HMAC secret for webhook signature verification'
            ),
            'aicwp_webhook_actions' => array(
                'type' => 'array',
                'section' => 'ai-chat-config',
                'sanitize' => array('AICWP_Webhook_Admin', 'sanitize_webhook_actions'),
                'default' => array(),
                'description' => 'Admin-defined webhook actions'
            ),
            'aicwp_webhook_instructions' => array(
                'type' => 'textarea',
                'section' => 'ai-chat-config',
                'sanitize' => 'sanitize_textarea_field',
                'default' => '',
                'description' => 'Custom AI instructions for webhook tool'
            ),
        );

        return array_merge($registry, $settings);
    }

    /**
     * Enqueue webhook admin script
     */
    public function enqueue_assets($hook) {
        if (strpos($hook, 'ai-chat-wp') === false) {
            return;
        }

        wp_enqueue_script(
            'aicwp-ui-webhook-admin',
            AICWP_PLUGIN_URL . 'assets/js/webhook-admin.js',
            array('jquery', 'aicwp-ui-messaging-admin'),
            AICWP_VERSION,
            true
        );

        wp_localize_script('aicwp-ui-webhook-admin', 'aicwpWebhookAdmin', array(
            'save_action'  => 'aicwp_save_webhook_settings',
            'save_nonce'   => wp_create_nonce('webhook_settings'),
            'test_action'  => 'aicwp_test_webhook',
            'test_nonce'   => wp_create_nonce('aicwp_webhook_settings'),
            'requestFailed'           => __('Request failed. Please try again.', 'ai-chat-wp'),
            'actionName'              => __('Action Name', 'ai-chat-wp'),
            'webhookLabelPlaceholder' => __('e.g., Cancel Order', 'ai-chat-wp'),
            'aiInstructions'          => __('AI Instructions', 'ai-chat-wp'),
            'webhookDescPlaceholder'  => __('e.g., User wants to cancel their order. Collect their name, email, and order number.', 'ai-chat-wp'),
            'dataFields'              => __('Data Fields', 'ai-chat-wp'),
            'webhookFieldsPlaceholder' => __('e.g., name, email, order_number, reason', 'ai-chat-wp'),
            'webhookFieldsHelp'       => __('Comma-separated field names. AI will collect these from the user. Use snake_case (e.g., phone_number).', 'ai-chat-wp'),
            'remove'                  => __('Remove', 'ai-chat-wp'),
        ));
    }

    /**
     * Render webhook checkbox in the Integrations section
     */
    public function render_integration_checkbox() {
        $enabled = get_option('aicwp_webhook_enabled', 0);
        ?>
        <div class="aicwp-ui-form-group" style="display: flex; align-items: flex-start; justify-content: space-between; gap: 10px;">
            <label class="aicwp-ui-checkbox-label" style="flex: 1;">
                <input type="checkbox"
                       name="aicwp_webhook_enabled"
                       value="1"
                       <?php checked($enabled, 1); ?> />
                <span class="aicwp-ui-checkbox-custom"></span>
                <span class="aicwp-ui-checkbox-text">
                    <?php _e('Webhook Automation (e.g. N8N, Zapier, Make)', 'ai-chat-wp'); ?>
                    <small><?php _e('When enabled, AI can send structured data to external systems (N8N, Zapier, Make) when users explicitly request actions.', 'ai-chat-wp'); ?></small>
                </span>
            </label>
            <button type="button" class="aicwp-ui-button aicwp-ui-button-secondary" data-open-modal="webhook-config-modal" style="white-space: nowrap; margin-top: 3px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="margin-right: 5px; vertical-align: middle;">
                    <path d="M12 15a3 3 0 100-6 3 3 0 000 6z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <?php _e('Configure', 'ai-chat-wp'); ?>
            </button>
        </div>
        <?php
    }

    /**
     * Render configuration modal
     */
    public function render_modal() {
        ?>
        <!-- Webhook Configuration Modal -->
        <div id="webhook-config-modal" class="aicwp-ui-modal" style="display: none;">
            <div class="aicwp-ui-modal-overlay"></div>
            <div class="aicwp-ui-modal-content" style="max-width: 650px;">
                <div class="aicwp-ui-modal-header" style="flex-direction: row; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0;"><?php esc_html_e('Webhook Settings', 'ai-chat-wp'); ?></h3>
                    <button type="button" class="aicwp-modal-close">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </div>
                <div class="aicwp-ui-modal-body">
                    <!-- Webhook URL -->
                    <div class="aicwp-ui-form-group" style="margin-bottom: 15px;">
                        <label for="aicwp_webhook_url" class="aicwp-ui-label"><?php esc_html_e('Webhook URL', 'ai-chat-wp'); ?></label>
                        <input type="url" id="aicwp_webhook_url" class="aicwp-ui-input" value="<?php echo esc_attr(get_option('aicwp_webhook_url', '')); ?>" placeholder="https://your-n8n-instance.com/webhook/..." />
                        <button type="button" id="webhook-test-btn" class="aicwp-ui-button aicwp-ui-button-secondary">
                            <span class="button-text"><?php esc_html_e('Send Test', 'ai-chat-wp'); ?></span>
                            <span class="button-spinner" style="display: none;"><span class="aicwp-ui-spinner"></span></span>
                        </button>
                        <div id="webhook-test-result" class="aicwp-ui-api-test-result" style="display: none; margin-top: 8px;"></div>
                        <p class="aicwp-ui-help-text"><?php _e('The endpoint URL where webhook data will be sent (e.g., N8N, Zapier, Make webhook URL).', 'ai-chat-wp'); ?> <strong><?php esc_html_e('HTTP method must be set to POST.', 'ai-chat-wp'); ?></strong></p>
                    </div>

                    <!-- Secret Key -->
                    <div class="aicwp-ui-form-group" style="margin-bottom: 20px;">
                        <label for="aicwp_webhook_secret" class="aicwp-ui-label"><?php esc_html_e('Secret Key', 'ai-chat-wp'); ?> <span style="color: #999; font-weight: normal;">(<?php esc_html_e('optional', 'ai-chat-wp'); ?>)</span></label>
                        <input type="password" id="aicwp_webhook_secret" class="aicwp-ui-input" value="<?php echo esc_attr(get_option('aicwp_webhook_secret', '')); ?>" placeholder="your-secret-key" autocomplete="off" />
                        <p class="aicwp-ui-help-text"><?php esc_html_e('If set, an X-Webhook-Signature header (HMAC SHA-256) will be sent with each request for verification.', 'ai-chat-wp'); ?></p>
                    </div>

                    <!-- Webhook Tool Instructions for AI -->
                    <div class="aicwp-ui-form-group" style="margin-bottom: 20px;">
                        <label for="aicwp_webhook_instructions" class="aicwp-ui-label"><?php esc_html_e('Webhook Tool Instructions for AI', 'ai-chat-wp'); ?></label>
                        <textarea id="aicwp_webhook_instructions" class="aicwp-ui-input" rows="5" maxlength="1000" style="font-family: monospace; font-size: 12px;" placeholder="<?php esc_attr_e("e.g., Only trigger webhook actions when the user clearly asks for one.\nAlways ask for the user's email before triggering any action.\nIf the user seems unsure, explain what each action does first.", 'ai-chat-wp'); ?>"><?php echo esc_textarea(get_option('aicwp_webhook_instructions', '')); ?></textarea>
                        <p class="aicwp-ui-help-text"><?php esc_html_e('Additional rules for when and how AI should use webhook actions. These instructions are added to the AI system prompt.', 'ai-chat-wp'); ?></p>
                    </div>

                    <!-- Actions Section -->
                    <div style="border-top: 1px solid #e0e0e0; padding-top: 20px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                            <div>
                                <strong style="font-size: 15px;"><?php esc_html_e('Webhook Actions', 'ai-chat-wp'); ?></strong>
                                <p class="aicwp-ui-help-text" style="margin: 3px 0 0;"><?php esc_html_e('Define actions that the AI can trigger. Each action sends data to your webhook URL.', 'ai-chat-wp'); ?></p>
                            </div>
                            <button type="button" id="webhook-add-action" class="aicwp-ui-button aicwp-ui-button-secondary" style="white-space: nowrap;">
                                + <?php esc_html_e('Add Action', 'ai-chat-wp'); ?>
                            </button>
                        </div>

                        <div id="webhook-actions-container">
                            <?php
                            $webhook_actions = get_option('aicwp_webhook_actions', array());
                            if (!empty($webhook_actions) && is_array($webhook_actions)):
                                foreach ($webhook_actions as $idx => $action):
                                    $action_label = isset($action['label']) ? $action['label'] : '';
                                    $action_desc = isset($action['description']) ? $action['description'] : '';
                                    $action_fields = isset($action['fields']) && is_array($action['fields']) ? $action['fields'] : array();
                                    $fields_csv = implode(', ', $action_fields);
                            ?>
                            <div class="webhook-action-row">
                                <div class="webhook-action-header">
                                    <div>
                                        <label class="aicwp-ui-label"><?php esc_html_e('Action Name', 'ai-chat-wp'); ?></label>
                                        <input type="text" class="aicwp-ui-input webhook-action-label" value="<?php echo esc_attr($action_label); ?>" placeholder="<?php esc_attr_e('e.g., Cancel Order', 'ai-chat-wp'); ?>" />
                                    </div>
                                    <button type="button" class="aicwp-ui-button aicwp-ui-button-secondary webhook-remove-action" title="<?php esc_attr_e('Remove', 'ai-chat-wp'); ?>">
                                        <span class="remove-icon">&times;</span>
                                    </button>
                                </div>
                                <div class="webhook-action-group">
                                    <label class="aicwp-ui-label"><?php esc_html_e('AI Instructions', 'ai-chat-wp'); ?></label>
                                    <textarea class="aicwp-ui-input webhook-action-description" rows="2" maxlength="300" placeholder="<?php esc_attr_e('e.g., User wants to cancel their order. Collect their name, email, and order number.', 'ai-chat-wp'); ?>"><?php echo esc_textarea($action_desc); ?></textarea>
                                </div>
                                <div>
                                    <label class="aicwp-ui-label"><?php esc_html_e('Data Fields', 'ai-chat-wp'); ?></label>
                                    <input type="text" class="aicwp-ui-input webhook-action-fields" value="<?php echo esc_attr($fields_csv); ?>" placeholder="<?php esc_attr_e('e.g., name, email, order_number, reason', 'ai-chat-wp'); ?>" />
                                    <p class="aicwp-ui-help-text webhook-action-fields-help"><?php esc_html_e('Comma-separated field names. AI will collect these from the user. Use snake_case (e.g., phone_number).', 'ai-chat-wp'); ?></p>
                                </div>
                            </div>
                            <?php
                                endforeach;
                            endif;
                            ?>
                        </div>

                        <!-- Empty state -->
                        <div id="webhook-actions-empty" style="<?php echo (!empty($webhook_actions) && is_array($webhook_actions)) ? 'display: none;' : ''; ?> text-align: center; color: #999; border: 2px dashed #e0e0e0; border-radius: 6px; padding: 15px; margin-bottom: 10px; padding-bottom: 7px;">
                            <p><?php esc_html_e('No actions configured yet. Click "Add Action" to create your first webhook action.', 'ai-chat-wp'); ?></p>
                        </div>

                        <!-- Example JSON Payload -->
                        <div class="aicwp-ui-collapsible-section" style="margin-top: 10px;">
                            <div class="aicwp-ui-collapsible-header" data-section="webhook-example-payload">
                                <span class="aicwp-ui-collapsible-title"><?php esc_html_e('Example JSON payload sent to your webhook', 'ai-chat-wp'); ?></span>
                                <span class="aicwp-ui-collapsible-toggle">
                                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                                </span>
                            </div>
                            <div class="aicwp-ui-collapsible-content">
                                <pre style="background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 6px; font-size: 11px; line-height: 1.5; overflow-x: auto; white-space: pre;">{
  "action": "cancel_order",
  "action_label": "Cancel Order",
  "timestamp": "2025-01-15T14:30:00+00:00",
  "site_url": "https://yoursite.com",
  "test": false,
  "data": {
    "name": "John Doe",
    "email": "john@example.com",
    "order_number": "12345",
    "reason": "Changed my mind",
    "current_page": "https://yoursite.com/contact",
    "user_id": 42,
    "conversation_id": "abc123-def456"
  }
}</pre>
                                <p class="aicwp-ui-help-text" style="margin-top: 6px;"><?php _e('The <code>data</code> object contains the fields you defined plus auto-captured context. The "Send Test" button sends a payload with <code>"test": true</code> so you can distinguish test requests in your workflow.', 'ai-chat-wp'); ?></p>
                            </div>
                        </div>
                    </div>

                    <div id="webhook-save-result" class="aicwp-ui-result-message" style="display: none; margin-top: 15px; padding: 10px; border-radius: 4px; font-size: 13px;"></div>
                </div>
                <div class="aicwp-ui-modal-footer">
                    <button type="button" id="webhook-save-settings-btn" class="aicwp-ui-button aicwp-ui-button-primary">
                        <span class="button-text"><?php esc_html_e('Save Settings', 'ai-chat-wp'); ?></span>
                        <span class="button-spinner" style="display: none;">
                            <span class="aicwp-ui-spinner"></span>
                        </span>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Save webhook settings
     */
    public function ajax_save_settings() {
        if (!check_ajax_referer('webhook_settings', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed.', 'ai-chat-wp')));
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'ai-chat-wp')));
        }

        // Sanitize and save webhook URL
        $webhook_url = isset($_POST['webhook_url']) ? esc_url_raw(trim(wp_unslash($_POST['webhook_url']))) : '';
        $webhook_secret = isset($_POST['webhook_secret']) ? sanitize_text_field(wp_unslash($_POST['webhook_secret'])) : '';

        // Validate URL if provided
        if (!empty($webhook_url) && !filter_var($webhook_url, FILTER_VALIDATE_URL)) {
            wp_send_json_error(array('message' => __('Invalid webhook URL.', 'ai-chat-wp')));
            return;
        }

        // Sanitize actions array
        $raw_actions = isset($_POST['actions']) ? wp_unslash($_POST['actions']) : array();
        $actions = self::sanitize_webhook_actions($raw_actions);

        // Sanitize webhook instructions
        $webhook_instructions = isset($_POST['webhook_instructions']) ? sanitize_textarea_field(wp_unslash($_POST['webhook_instructions'])) : '';

        // Save settings
        update_option('aicwp_webhook_url', $webhook_url);
        update_option('aicwp_webhook_secret', $webhook_secret);
        update_option('aicwp_webhook_actions', $actions);
        update_option('aicwp_webhook_instructions', $webhook_instructions);

        wp_send_json_success(array(
            'message' => __('Webhook settings saved successfully!', 'ai-chat-wp')
        ));
    }

    /**
     * AJAX: Test webhook connection
     *
     * Lives here (not in class-webhook-tool.php) so it works even when
     * webhook is disabled — the tool constructor early-returns if webhook
     * isn't enabled, which would prevent the test button from working
     * when configuring for the first time.
     */
    public function ajax_test_webhook() {
        if (!check_ajax_referer('aicwp_webhook_settings', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed.', 'ai-chat-wp')));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'ai-chat-wp')));
            return;
        }

        $webhook_url = isset($_POST['webhook_url']) ? esc_url_raw(trim(wp_unslash($_POST['webhook_url']))) : '';
        if (empty($webhook_url) || !filter_var($webhook_url, FILTER_VALIDATE_URL)) {
            wp_send_json_error(array('message' => __('Please enter a valid webhook URL first.', 'ai-chat-wp')));
            return;
        }

        $webhook_secret = isset($_POST['webhook_secret']) ? sanitize_text_field(wp_unslash($_POST['webhook_secret'])) : '';

        // Build test payload matching the real webhook format
        $payload = array(
            'action'       => 'test_webhook',
            'action_label' => 'Test Webhook',
            'timestamp'    => current_time('c'),
            'site_url'     => home_url(),
            'test'         => true,
            'data'         => array(
                'name'            => 'John Doe',
                'email'           => 'john@example.com',
                'message'         => 'This is a test webhook from AI Chat for WordPress. If you see this, your webhook is working!',
                'current_page'    => home_url(),
                'user_id'         => get_current_user_id(),
                'conversation_id' => 'test-' . wp_generate_password(8, false),
            ),
        );

        $json_payload = wp_json_encode($payload);

        $headers = array(
            'Content-Type' => 'application/json',
        );

        if (!empty($webhook_secret)) {
            $signature = hash_hmac('sha256', $json_payload, $webhook_secret);
            $headers['X-Webhook-Signature'] = 'sha256=' . $signature;
        }

        $response = wp_remote_post($webhook_url, array(
            'body'    => $json_payload,
            'headers' => $headers,
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            wp_send_json_error(array(
                'message' => sprintf(
                    __('Connection failed: %s', 'ai-chat-wp'),
                    $response->get_error_message()
                )
            ));
            return;
        }

        $response_code = wp_remote_retrieve_response_code($response);

        if ($response_code >= 200 && $response_code < 300) {
            wp_send_json_success(array(
                'message' => sprintf(
                    __('Webhook received HTTP %d — connection successful!', 'ai-chat-wp'),
                    $response_code
                )
            ));
        } else {
            wp_send_json_error(array(
                'message' => sprintf(
                    __('Webhook returned HTTP %d. Please check your endpoint.', 'ai-chat-wp'),
                    $response_code
                )
            ));
        }
    }

    /**
     * Sanitize webhook actions array
     *
     * @param mixed $raw_actions Raw actions data
     * @return array Sanitized actions
     */
    public static function sanitize_webhook_actions($raw_actions) {
        if (!is_array($raw_actions)) {
            return array();
        }

        $sanitized = array();
        foreach ($raw_actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $label = isset($action['label']) ? sanitize_text_field($action['label']) : '';

            // Skip actions with no label
            if (empty($label)) {
                continue;
            }

            // Auto-generate action_id from label
            $action_id = sanitize_key(str_replace(' ', '_', strtolower($label)));

            // Sanitize fields - accept any field names the admin defines
            $fields = array();
            if (isset($action['fields']) && is_string($action['fields'])) {
                // Comma-separated string from the input
                $raw_fields = array_map('trim', explode(',', $action['fields']));
                foreach ($raw_fields as $field) {
                    // Convert spaces to underscores before sanitizing so "order number" becomes "order_number"
                    $field = sanitize_key(str_replace(' ', '_', $field));
                    if (!empty($field)) {
                        $fields[] = $field;
                    }
                }
            } elseif (isset($action['fields']) && is_array($action['fields'])) {
                foreach ($action['fields'] as $field) {
                    $field = sanitize_key(str_replace(' ', '_', $field));
                    if (!empty($field)) {
                        $fields[] = $field;
                    }
                }
            }

            // Enforce server-side max length on description (client-side maxlength is not reliable)
            $description = isset($action['description']) ? sanitize_textarea_field($action['description']) : '';
            if (mb_strlen($description) > 500) {
                $description = mb_substr($description, 0, 500);
            }

            $sanitized[] = array(
                'action_id'   => $action_id,
                'label'       => mb_substr($label, 0, 100),
                'description' => $description,
                'fields'      => $fields,
            );
        }

        return $sanitized;
    }

}
