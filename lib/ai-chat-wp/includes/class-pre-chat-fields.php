<?php
/**
 * AI Chat for WordPress - Pre-Chat Required Fields
 *
 * Requires visitors to fill out custom fields before starting a chat conversation.
 * Field data is stored alongside chat history and displayed in admin conversation view.
 *
 * @package AICWP
 * @since 1.8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Pre_Chat_Fields {

    /**
     * Constructor
     */
    public function __construct() {
        // Render admin settings UI in Access & Privacy section
        add_action('aicwp_chat_access_privacy_settings', array($this, 'render_admin_settings'));

        // Render pre-chat form in frontend (shortcode + floating widget)
        add_action('aicwp_chat_pre_chat_form', array($this, 'render_pre_chat_form'));

        // Register setting
        add_filter('aicwp_settings_registry', array($this, 'register_settings'));

        // Sanitize settings
        add_filter('aicwp_sanitize_setting', array($this, 'sanitize_settings'), 10, 3);

        // Add to hidden fields exception list
        add_filter('aicwp_hidden_fields_except', array($this, 'add_to_hidden_fields_except'));

        // Add pre-chat fields config to frontend JS
        add_filter('aicwp_chat_js_config', array($this, 'add_js_config'));

        // Display pre-chat data in admin chat history
        add_action('aicwp_conversation_messages_before', array($this, 'render_pre_chat_data_in_history'), 10, 2);

        // Store pre-chat data alongside chat history
        add_filter('aicwp_chat_history_extra_data', array($this, 'add_pre_chat_data_to_history'), 10, 2);

        // Ensure pre_chat_data column exists
        add_action('admin_init', array($this, 'maybe_add_column'));
        add_action('plugins_loaded', array($this, 'maybe_add_column'), 20);
    }

    /**
     * Register settings in the settings registry
     *
     * @param array $registry Settings registry
     * @return array Modified registry
     */
    public function register_settings($registry) {
        $registry['aicwp_chat_pre_chat_fields_enabled'] = array(
            'type' => 'checkbox',
            'section' => 'ai-chat-config',
            'sanitize' => 'absint',
            'default' => 0,
            'description' => 'Require filling fields before starting the chat'
        );
        $registry['aicwp_chat_pre_chat_headline'] = array(
            'type' => 'text',
            'section' => 'ai-chat-config',
            'sanitize' => 'sanitize_text_field',
            'default' => '',
            'description' => 'Pre-chat form headline'
        );
        $registry['aicwp_chat_pre_chat_fields'] = array(
            'type' => 'array',
            'section' => 'ai-chat-config',
            'sanitize' => 'sanitize_pre_chat_fields',
            'default' => array(),
            'description' => 'Custom fields required before chat'
        );
        return $registry;
    }

    /**
     * Sanitize pre-chat fields array
     *
     * @param mixed $value Sanitized value (passed through)
     * @param string $key Setting key
     * @param mixed $raw_value Raw value from POST
     * @return mixed Sanitized value
     */
    public function sanitize_settings($value, $key, $raw_value) {
        if ($key !== 'aicwp_chat_pre_chat_fields') {
            return $value;
        }

        if (!is_array($raw_value)) {
            return array();
        }

        $sanitized = array();
        foreach ($raw_value as $field_entry) {
            if (!empty($field_entry['label'])) {
                $sanitized[] = array(
                    'label' => sanitize_text_field(trim($field_entry['label'])),
                );
            }
        }
        return $sanitized;
    }

    /**
     * Add to hidden fields exception list
     *
     * @param array $fields Fields to exclude from hidden fields
     * @return array Modified fields array
     */
    public function add_to_hidden_fields_except($fields) {
        $fields[] = 'aicwp_chat_pre_chat_fields_enabled';
        $fields[] = 'aicwp_chat_pre_chat_headline';
        $fields[] = 'aicwp_chat_pre_chat_fields';
        return $fields;
    }

    /**
     * Add pre-chat fields config to frontend JS config
     *
     * @param array $config JS config array
     * @return array Modified config
     */
    public function add_js_config($config) {
        $enabled = get_option('aicwp_chat_pre_chat_fields_enabled', 0);
        $fields = get_option('aicwp_chat_pre_chat_fields', array());

        if ($enabled && !empty($fields)) {
            $config['preChatFields'] = array();
            foreach ($fields as $field) {
                if (!empty($field['label'])) {
                    $config['preChatFields'][] = array(
                        'label' => $field['label'],
                    );
                }
            }
            $headline = get_option('aicwp_chat_pre_chat_headline', '');
            if (!empty($headline)) {
                $config['preChatHeadline'] = $headline;
            }
        }

        return $config;
    }

    /**
     * Render admin settings in Access & Privacy section
     */
    public function render_admin_settings() {
        $enabled = get_option('aicwp_chat_pre_chat_fields_enabled', 0);
        $fields = get_option('aicwp_chat_pre_chat_fields', array());
        if (empty($fields)) {
            $fields = array(array('label' => ''));
        }
        ?>
        <!-- Pre-Chat Form -->
        <div class="aicwp-ui-form-group">
            <label class="aicwp-ui-checkbox-label">
                <input type="checkbox" id="aicwp_chat_pre_chat_fields_enabled" name="aicwp_chat_pre_chat_fields_enabled" value="1" <?php checked($enabled, 1); ?> />
                <span class="aicwp-ui-checkbox-custom"></span>
                <span class="aicwp-ui-checkbox-text">
                    <?php _e('Enable Pre-Chat Form', 'ai-chat-wp'); ?>
                    <small><?php _e('Collect visitor information before the chat starts. The submitted data will be visible in chat history.', 'ai-chat-wp'); ?></small>
                </span>
            </label>
        </div>

        <div id="aicwp-pre-chat-fields-wrapper" style="display: <?php echo $enabled ? 'block' : 'none'; ?>; margin-top: 10px; border: 1px solid #e0e0e0; border-radius: 5px; padding: 20px;">
            <label class="aicwp-ui-label">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 18px; height: 18px; vertical-align: text-bottom; margin-right: 4px; display: inline-block;"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15a2.25 2.25 0 0 1 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z" /></svg><?php _e('Form Fields', 'ai-chat-wp'); ?>
            </label>
            <p class="aicwp-ui-help-text" style="margin-bottom: 15px;">
                <?php _e('Add the fields visitors must fill out before chatting.', 'ai-chat-wp'); ?>
            </p>

            <div class="aicwp-ui-form-group" style="margin-bottom: 15px;">
                <label for="aicwp_chat_pre_chat_headline" class="aicwp-ui-label" style="font-size: 13px;"><?php _e('Form Headline', 'ai-chat-wp'); ?></label>
                <input type="text"
                       id="aicwp_chat_pre_chat_headline"
                       name="aicwp_chat_pre_chat_headline"
                       value="<?php echo esc_attr(get_option('aicwp_chat_pre_chat_headline', '')); ?>"
                       placeholder="<?php esc_attr_e('e.g., Please introduce yourself', 'ai-chat-wp'); ?>"
                       class="aicwp-ui-input"
                       style="max-width: 400px;" />
                <p class="aicwp-ui-help-text"><?php _e('Leave blank for no headline.', 'ai-chat-wp'); ?></p>
            </div>

            <div id="aicwp-pre-chat-fields-container">
                <?php foreach ($fields as $index => $entry) :
                    $label_value = isset($entry['label']) ? $entry['label'] : '';
                ?>
                <div class="aicwp-pre-chat-field-row" style="display: flex; gap: 10px; margin-bottom: 10px; align-items: center;">
                    <input type="text"
                           name="aicwp_chat_pre_chat_fields[<?php echo absint($index); ?>][label]"
                           value="<?php echo esc_attr($label_value); ?>"
                           placeholder="<?php esc_attr_e('e.g., Full Name, Email, Phone Number', 'ai-chat-wp'); ?>"
                           class="aicwp-ui-input aicwp-pre-chat-field-input"
                           style="flex: 1; max-width: 300px;" />
                    <button type="button" class="aicwp-ui-button aicwp-ui-button-secondary aicwp-remove-pre-chat-field" title="<?php esc_attr_e('Remove', 'ai-chat-wp'); ?>">
                        <span class="remove-icon">&times;</span>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>

            <button type="button" id="aicwp-add-pre-chat-field" class="aicwp-ui-button aicwp-ui-button-secondary">
                <?php _e('+ Add Field', 'ai-chat-wp'); ?>
            </button>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Toggle fields wrapper visibility
            $('#aicwp_chat_pre_chat_fields_enabled').on('change', function() {
                $('#aicwp-pre-chat-fields-wrapper').toggle(this.checked);
            });

            // Add new field row
            $('#aicwp-add-pre-chat-field').on('click', function() {
                var container = $('#aicwp-pre-chat-fields-container');
                var index = container.find('.aicwp-pre-chat-field-row').length;
                var row = '<div class="aicwp-pre-chat-field-row" style="display: flex; gap: 10px; margin-bottom: 10px; align-items: center;">' +
                    '<input type="text"' +
                    ' name="aicwp_chat_pre_chat_fields[' + index + '][label]"' +
                    ' value=""' +
                    ' placeholder="<?php echo esc_js(__('e.g., Full Name, Email, Phone Number', 'ai-chat-wp')); ?>"' +
                    ' class="aicwp-ui-input aicwp-pre-chat-field-input"' +
                    ' style="flex: 1; max-width: 300px;" />' +
                    '<button type="button" class="aicwp-ui-button aicwp-ui-button-secondary aicwp-remove-pre-chat-field" title="<?php echo esc_js(__('Remove', 'ai-chat-wp')); ?>">' +
                    '<span class="remove-icon">&times;</span>' +
                    '</button>' +
                    '</div>';
                container.append(row);
            });

            // Remove field row
            $(document).on('click', '.aicwp-remove-pre-chat-field', function() {
                var container = $('#aicwp-pre-chat-fields-container');
                if (container.find('.aicwp-pre-chat-field-row').length > 1) {
                    $(this).closest('.aicwp-pre-chat-field-row').remove();
                    // Reindex remaining rows
                    container.find('.aicwp-pre-chat-field-row').each(function(i) {
                        $(this).find('input').attr('name', 'aicwp_chat_pre_chat_fields[' + i + '][label]');
                    });
                } else {
                    // Clear the input instead of removing last row
                    $(this).closest('.aicwp-pre-chat-field-row').find('input').val('');
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Render the pre-chat form in frontend
     * Called via do_action('aicwp_chat_pre_chat_form')
     */
    public function render_pre_chat_form() {
        $enabled = get_option('aicwp_chat_pre_chat_fields_enabled', 0);
        $fields = get_option('aicwp_chat_pre_chat_fields', array());

        if (!$enabled || empty($fields)) {
            return;
        }

        $headline = get_option('aicwp_chat_pre_chat_headline', '');
        ?>
        <div class="aicwp-pre-chat-form" style="display: none;">
            <form class="aicwp-pre-chat-form-body aicwp-contact-form-body">
                <?php if (!empty($headline)) : ?>
                    <div class="aicwp-pre-chat-headline" style="font-size: 14px; font-weight: 600; color: #333; margin-bottom: 12px;"><?php echo esc_html($headline); ?></div>
                <?php endif; ?>
                <?php foreach ($fields as $index => $field) :
                    if (empty($field['label'])) continue;
                    $field_id = 'aicwp-pre-chat-field-' . absint($index);
                ?>
                <div class="aicwp-contact-form-field">
                    <label for="<?php echo esc_attr($field_id); ?>"><?php echo esc_html($field['label']); ?> <span class="required">*</span></label>
                    <input type="text" id="<?php echo esc_attr($field_id); ?>" name="pre_chat_field_<?php echo absint($index); ?>" data-field-label="<?php echo esc_attr($field['label']); ?>" required minlength="2" maxlength="200" />
                </div>
                <?php endforeach; ?>
                <div class="aicwp-contact-form-actions">
                    <button type="submit" class="aicwp-contact-form-submit aicwp-pre-chat-submit">
                        <span class="button-text"><?php _e('Start Chat', 'ai-chat-wp'); ?></span>
                    </button>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Add pre_chat_data column to chat history table if it doesn't exist
     */
    public function maybe_add_column() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'aicwp_chat_history';

        // Check if table exists first
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") != $table_name) {
            return;
        }

        // Check if column already exists (cached)
        static $checked = false;
        if ($checked) return;
        $checked = true;

        $column = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'pre_chat_data'");
        if (empty($column)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN pre_chat_data text DEFAULT NULL AFTER page_url");
        }
    }

    /**
     * Add pre-chat data to chat history insert
     *
     * @param array $extra Extra data array
     * @param array $insert_data Current insert data
     * @return array Modified extra data
     */
    public function add_pre_chat_data_to_history($extra, $insert_data) {
        // Get pre-chat data from custom header (sent only with first message)
        $pre_chat_data = isset($_SERVER['HTTP_X_PRE_CHAT_DATA']) ? $_SERVER['HTTP_X_PRE_CHAT_DATA'] : '';

        if (!empty($pre_chat_data)) {
            $decoded = json_decode(stripslashes($pre_chat_data), true);
            if (is_array($decoded)) {
                // Sanitize each field with server-side limits
                $sanitized = array();
                $max_fields = 10;
                $max_length = 200;
                foreach ($decoded as $item) {
                    if (count($sanitized) >= $max_fields) {
                        break;
                    }
                    if (isset($item['label'], $item['value'])) {
                        $sanitized[] = array(
                            'label' => mb_substr(sanitize_text_field($item['label']), 0, $max_length),
                            'value' => mb_substr(sanitize_text_field($item['value']), 0, $max_length),
                        );
                    }
                }
                if (!empty($sanitized)) {
                    // Check if column exists (cached per request)
                    static $column_exists = null;
                    if ($column_exists === null) {
                        global $wpdb;
                        $table_name = $wpdb->prefix . 'aicwp_chat_history';
                        $column_exists = !empty($wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'pre_chat_data'"));
                    }
                    if ($column_exists) {
                        $extra['pre_chat_data'] = array(
                            'value' => wp_json_encode($sanitized),
                            'format' => '%s',
                        );
                    }
                }
            }
        }

        return $extra;
    }

    /**
     * Render pre-chat data in admin chat history (before first message)
     *
     * @param array $messages All messages in the conversation
     * @param string $conversation_id Conversation ID
     */
    public function render_pre_chat_data_in_history($messages, $conversation_id) {
        if (empty($messages)) {
            return;
        }

        // Check first message for pre_chat_data
        $first_message = $messages[0];
        if (empty($first_message['pre_chat_data'])) {
            return;
        }

        $pre_chat_data = json_decode($first_message['pre_chat_data'], true);
        if (empty($pre_chat_data) || !is_array($pre_chat_data)) {
            return;
        }

        ?>
        <div style="margin-bottom: 15px; padding: 12px; background: #e8f4ff; border-radius: 4px;">
            <div style="font-weight: bold; color: #1976d2; margin-bottom: 8px; font-size: 12px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 4px;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg><?php _e('User Details', 'ai-chat-wp'); ?>
            </div>
            <?php foreach ($pre_chat_data as $field) : ?>
                <div style="font-size: 13px; color: #333; margin-bottom: 4px;">
                    <strong><?php echo esc_html($field['label']); ?>:</strong>
                    <?php echo esc_html($field['value']); ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }
}
