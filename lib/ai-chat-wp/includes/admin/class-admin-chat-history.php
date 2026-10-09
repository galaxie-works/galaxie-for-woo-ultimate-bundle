<?php
/**
 * Admin Chat History Handler
 *
 * Handles chat history rendering and AJAX operations for the admin dashboard.
 *
 * @package AICWP
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class AICWP_Admin_Chat_History
 *
 * Manages chat history display and operations in the admin area.
 */
class AICWP_Admin_Chat_History {

    /**
     * Items per page for pagination
     */
    const PER_PAGE = 4;

    /**
     * Constructor - Register AJAX handlers
     */
    public function __construct() {
        add_action('wp_ajax_aicwp_load_chat_history', array($this, 'ajax_load'));
        add_action('wp_ajax_aicwp_clear_chat_history', array($this, 'ajax_clear'));
        add_action('wp_ajax_aicwp_delete_conversation', array($this, 'ajax_delete_conversation'));
        add_action('wp_ajax_aicwp_export_chat_history_csv', array($this, 'ajax_export_csv'));
    }

    /**
     * Render the complete chat history section
     *
     * @param bool $history_enabled Whether chat history is enabled
     */
    public function render_section($history_enabled) {
        $this->render_config_modal();

        if ($history_enabled) {
            $this->render_enabled_section();
        } else {
            $this->render_disabled_section();
        }
    }

    /**
     * Render the shared configure modal and its JS
     */
    private function render_config_modal() {
        $nonce = wp_create_nonce('aicwp_nonce');
        ?>
        <div id="chat-history-config-modal" class="aicwp-ui-modal" style="display: none;">
            <div class="aicwp-ui-modal-overlay"></div>
            <div class="aicwp-ui-modal-content aicwp-ui-audit-settings-modal-content">
                <form id="chat-history-config-form">
                    <div class="aicwp-ui-modal-header">
                        <h3 style="margin: 0;"><?php _e('Chat History Settings', 'ai-chat-wp'); ?></h3>
                        <button type="button" id="chat-history-modal-close" class="aicwp-modal-close">
                            <span class="dashicons dashicons-no-alt"></span>
                        </button>
                    </div>
                    <div class="aicwp-ui-modal-body">

                        <div class="aicwp-ui-form-group">
                            <label class="aicwp-ui-checkbox-label">
                                <input type="checkbox" name="aicwp_chat_history_enabled" value="1" <?php checked(get_option('aicwp_chat_history_enabled', 0), 1); ?> />
                                <span class="aicwp-ui-checkbox-custom"></span>
                                <span class="aicwp-ui-checkbox-text">
                                    <?php _e('Enable Chat History Tracking', 'ai-chat-wp'); ?>
                                    <small><?php _e('Save user questions and AI responses for analytics.', 'ai-chat-wp'); ?></small>
                                </span>
                            </label>
                        </div>

                        <div class="aicwp-ui-form-group">
                            <label class="aicwp-ui-label" for="chat-history-retention-days"><?php _e('Data Retention', 'ai-chat-wp'); ?></label>
                            <?php $retention = get_option('aicwp_chat_retention_days', 30); ?>
                            <select name="aicwp_chat_retention_days" id="chat-history-retention-days" class="aicwp-ui-input">
                                <option value="30" <?php selected($retention, 30); ?>><?php printf(__('Last %d days', 'ai-chat-wp'), 30); ?></option>
                                <option value="90" <?php selected($retention, 90); ?>><?php printf(__('Last %d days', 'ai-chat-wp'), 90); ?></option>
                                <option value="180" <?php selected($retention, 180); ?>><?php printf(__('Last %d days', 'ai-chat-wp'), 180); ?></option>
                                <option value="360" <?php selected($retention, 360); ?>><?php printf(__('Last %d days', 'ai-chat-wp'), 360); ?></option>
                            </select>
                            <p class="aicwp-ui-help-text"><?php _e('Conversations older than this will be automatically deleted by the weekly cleanup cron.', 'ai-chat-wp'); ?></p>
                        </div>

                        <?php if (get_option('aicwp_chat_history_enabled', 0)): ?>
                        <!-- Export Chat History CSV -->
                        <div style="margin-bottom: 15px; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 15px; background: #f8f9fa; border-radius: 6px;">
                            <div>
                                <strong style="font-size: 14px;"><?php _e('Export Chat History', 'ai-chat-wp'); ?></strong>
                                <p style="margin: 3px 0 0; font-size: 13px; color: #666;"><?php _e('Download all conversations as a CSV file.', 'ai-chat-wp'); ?></p>
                            </div>
                            <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=aicwp_export_chat_history_csv&nonce=' . $nonce)); ?>" class="aicwp-ui-button aicwp-ui-button-secondary" style="font-size: 13px; padding: 6px 14px; text-decoration: none; white-space: nowrap;">
                                <span class="dashicons dashicons-download" style="margin-top: 3px; margin-right: 3px;"></span>
                                <?php _e('Export CSV', 'ai-chat-wp'); ?>
                            </a>
                        </div>

                        <!-- Clear History -->
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 15px; background: #f8f9fa; border-radius: 6px;">
                            <div>
                                <strong style="font-size: 14px; color: #b32d2e;"><?php _e('Clear All History', 'ai-chat-wp'); ?></strong>
                                <p style="margin: 3px 0 0; font-size: 13px; color: #666;"><?php _e('Permanently delete all chat history records.', 'ai-chat-wp'); ?></p>
                            </div>
                            <button type="button" id="clear-chat-history" class="aicwp-ui-button aicwp-ui-button-secondary aicwp-ui-button-danger" style="font-size: 13px; padding: 6px 14px; white-space: nowrap;">
                                <?php _e('Clear History', 'ai-chat-wp'); ?>
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="aicwp-ui-modal-footer">
                        <button type="submit" class="aicwp-ui-button aicwp-ui-button-primary">
                            <?php _e('Save Settings', 'ai-chat-wp'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var $configModal = $('#chat-history-config-modal');

            $(document).on('click', '#chat-history-configure-btn', function(e) {
                e.preventDefault();
                $configModal.fadeIn(200);
            });

            function closeConfigModal() {
                $configModal.fadeOut(200);
            }
            $(document).on('click', '#chat-history-modal-close, #chat-history-config-modal .aicwp-ui-modal-overlay', closeConfigModal);

            // Save settings (form submit)
            $(document).on('submit', '#chat-history-config-form', function(e) {
                e.preventDefault();
                var $form = $(this);
                var $btn = $form.find('button[type="submit"]');

                $btn.prop('disabled', true).text('<?php echo esc_js(__('Saving...', 'ai-chat-wp')); ?>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aicwp_save_settings',
                        nonce: '<?php echo $nonce; ?>',
                        aicwp_chat_history_enabled: $('input[name="aicwp_chat_history_enabled"]').is(':checked') ? 1 : 0,
                        aicwp_chat_retention_days: $('#chat-history-retention-days').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data && response.data.message ? response.data.message : '<?php echo esc_js(__('Failed to save settings.', 'ai-chat-wp')); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('Failed to save settings.', 'ai-chat-wp')); ?>');
                    },
                    complete: function() {
                        $btn.prop('disabled', false).text('<?php echo esc_js(__('Save Settings', 'ai-chat-wp')); ?>');
                    }
                });
            });

            // Clear History button handler
            $(document).on('click', '#clear-chat-history', function(e) {
                e.preventDefault();

                if (!confirm('<?php echo esc_js(__('Are you sure you want to delete all chat history? This action cannot be undone.', 'ai-chat-wp')); ?>')) {
                    return;
                }

                var $button = $(this);
                var originalHtml = $button.html();

                $button.prop('disabled', true).html('<span class="aicwp-ui-spinner" style="margin-right: 6px;"></span> <?php echo esc_js(__('Clearing...', 'ai-chat-wp')); ?>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aicwp_clear_chat_history',
                        nonce: '<?php echo $nonce; ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data.message);
                            $button.prop('disabled', false).html(originalHtml);
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('Failed to clear chat history. Please try again.', 'ai-chat-wp')); ?>');
                        $button.prop('disabled', false).html(originalHtml);
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render section when chat history is enabled
     */
    private function render_enabled_section() {
        // Pagination
        $page = isset($_GET['history_page']) ? max(1, intval($_GET['history_page'])) : 1;
        $offset = ($page - 1) * self::PER_PAGE;

        // Get stats and conversations
        $history_stats_30d = null;
        $history_stats_today = null;
        $recent_conversations = array();
        $total_pages = 1;

        if (class_exists('AICWP_Chat_History')) {
            $history_stats_30d = AICWP_Chat_History::get_stats(30);
            $history_stats_today = AICWP_Chat_History::get_stats_today();
            $recent_conversations = AICWP_Chat_History::get_recent_conversations(self::PER_PAGE, $offset);

            // Get total count for pagination
            global $wpdb;
            $table_name = AICWP_Chat_History::get_table_name();
            $total_conversations = $wpdb->get_var("SELECT COUNT(DISTINCT conversation_id) FROM {$table_name}");
            $total_pages = ceil($total_conversations / self::PER_PAGE);
        }
        ?>
        <div class="aicwp-ui-card">
            <div class="aicwp-ui-card-header aicwp-ui-card-header-with-icon">
                <div class="aicwp-ui-card-icon aicwp-ui-card-icon-indigo">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path><path d="M12 7v5l4 2"></path></svg>
                </div>
                <div class="aicwp-ui-card-header-text">
                    <h3><?php printf(__('Chat History (Last %d Days)', 'ai-chat-wp'), get_option('aicwp_chat_retention_days', 30)); ?></h3>
                    <p><?php _e('Detailed conversation tracking', 'ai-chat-wp'); ?></p>
                </div>
            </div>
            <div class="aicwp-ui-card-body">
                <?php $this->render_stats_boxes($history_stats_30d, $history_stats_today); ?>
                <?php $this->render_conversations_list($recent_conversations, $page, $total_pages); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render section when chat history is disabled
     */
    private function render_disabled_section() {
        ?>
        <div class="aicwp-ui-card">
            <div class="aicwp-ui-card-header aicwp-ui-card-header-with-icon">
                <div class="aicwp-ui-card-icon aicwp-ui-card-icon-indigo">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path><path d="M12 7v5l4 2"></path></svg>
                </div>
                <div class="aicwp-ui-card-header-text">
                    <h3><?php _e('Chat History', 'ai-chat-wp'); ?></h3>
                    <p><?php _e('Detailed conversation tracking', 'ai-chat-wp'); ?></p>
                </div>
            </div>
            <div class="aicwp-ui-card-body">
                <div class="aicwp-ui-audit-empty-state">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#999" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                    <h3><?php _e('Chat history tracking is off', 'ai-chat-wp'); ?></h3>
                    <p><?php _e('Enable "Chat History Tracking" in the configure settings, have at least a few conversations, then come back here.', 'ai-chat-wp'); ?></p>
                    <button type="button" id="chat-history-configure-btn" class="aicwp-ui-button aicwp-ui-button-primary">
                        <?php _e('Configure', 'ai-chat-wp'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render stats boxes
     *
     * @param array|null $stats_30d Stats for last 30 days
     * @param array|null $stats_today Stats for today
     */
    private function render_stats_boxes($stats_30d, $stats_today) {
        if (!is_array($stats_30d) || empty($stats_30d)) {
            echo '<p>' . __('No chat history data available yet. Start using the AI chat to see statistics here.', 'ai-chat-wp') . '</p>';
            return;
        }
        ?>
        <div class="aicwp-ui-stats-boxes">
            <div class="aicwp-ui-stat-box aicwp-ui-stat-box-green">
                <div class="aicwp-ui-stat-number aicwp-ui-stat-number-green">
                    <?php echo number_format(isset($stats_30d['total_conversations']) ? intval($stats_30d['total_conversations']) : 0); ?>
                </div>
                <div class="aicwp-ui-stat-label aicwp-ui-stat-label-green"><?php _e('Conversations', 'ai-chat-wp'); ?></div>
                <div class="aicwp-ui-stat-today aicwp-ui-stat-today-green">
                    <?php printf(__('Today: %s', 'ai-chat-wp'), number_format(isset($stats_today['total_conversations']) ? intval($stats_today['total_conversations']) : 0)); ?>
                </div>
            </div>

            <div class="aicwp-ui-stat-box aicwp-ui-stat-box-blue">
                <div class="aicwp-ui-stat-number aicwp-ui-stat-number-blue">
                    <?php echo number_format(isset($stats_30d['total_messages']) ? intval($stats_30d['total_messages']) : 0); ?>
                </div>
                <div class="aicwp-ui-stat-label aicwp-ui-stat-label-blue"><?php _e('Messages', 'ai-chat-wp'); ?></div>
                <div class="aicwp-ui-stat-today aicwp-ui-stat-today-blue">
                    <?php printf(__('Today: %s', 'ai-chat-wp'), number_format(isset($stats_today['total_messages']) ? intval($stats_today['total_messages']) : 0)); ?>
                </div>
            </div>

            <div class="aicwp-ui-stat-box aicwp-ui-stat-box-orange">
                <div class="aicwp-ui-stat-number aicwp-ui-stat-number-orange">
                    <?php echo isset($stats_30d['avg_per_conversation']) ? floatval($stats_30d['avg_per_conversation']) : 0; ?>
                </div>
                <div class="aicwp-ui-stat-label aicwp-ui-stat-label-orange"><?php _e('Avg per Conversation', 'ai-chat-wp'); ?></div>
                <div class="aicwp-ui-stat-today aicwp-ui-stat-today-orange">
                    <?php printf(__('Today: %s', 'ai-chat-wp'), isset($stats_today['avg_per_conversation']) ? floatval($stats_today['avg_per_conversation']) : 0); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render conversations list with pagination
     *
     * @param array $conversations Recent conversations
     * @param int $page Current page
     * @param int $total_pages Total pages
     */
    private function render_conversations_list($conversations, $page, $total_pages) {
        if (empty($conversations)) {
            echo '<p style="padding: 20px; text-align: center; color: #666;">' . __('No conversations yet. Start using the AI chat to see history here.', 'ai-chat-wp') . '</p>';
            return;
        }
        ?>
        <div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div>
                        <h3 style="margin: 0;"><?php _e('Recent Conversations', 'ai-chat-wp'); ?></h3>
                        <p style="color: #666; margin: 5px 0 0 0;"><?php _e('Click on a conversation to view the full chat history', 'ai-chat-wp'); ?></p>
                    </div>
                </div>
                <div class="conversation-search-actions">
                    <input type="text" id="conversation-search-input" placeholder="<?php esc_attr_e('ID, IP or keyword', 'ai-chat-wp'); ?>" style="width: 200px; padding: 5px 10px; border: 1px solid #ddd; border-radius: 4px;">
                    <button type="button" id="conversation-search-btn" class="button button-small conversation-search-btn"><?php _e('Search', 'ai-chat-wp'); ?></button>
                    <button type="button" id="conversation-search-clear" class="button button-small conversation-search-clear" style="display: none;"><?php _e('Clear', 'ai-chat-wp'); ?></button>
                </div>
            </div>

            <div id="aicwp-history-conversations">
            <?php foreach ($conversations as $conv): ?>
                <?php
                $messages = AICWP_Chat_History::get_conversation($conv['conversation_id']);
                $user_info = $conv['user_id'] ? get_userdata($conv['user_id']) : null;
                $this->render_conversation_card($conv, $messages, $user_info);
                ?>
            <?php endforeach; ?>
            </div>

            <div id="aicwp-history-pagination">
                <?php $this->render_pagination($page, $total_pages); ?>
            </div>

            <div style="display: flex; gap: 10px; margin-top: 15px; flex-wrap: wrap;">
                <button type="button" id="chat-history-configure-btn" class="aicwp-ui-button aicwp-ui-button-secondary">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 4px;"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                    <?php _e('Configure', 'ai-chat-wp'); ?>
                </button>
            </div>

            <?php $this->render_javascript(); ?>
        </div>
        <?php
    }

    /**
     * Render pagination controls
     *
     * @param int $page Current page
     * @param int $total_pages Total pages
     */
    public function render_pagination($page, $total_pages) {
        if ($total_pages <= 1) {
            return;
        }

        $range = 2;
        $start = max(1, $page - $range);
        $end = min($total_pages, $page + $range);
        ?>
        <div class="aicwp-ui-pagination-nav">
            <?php if ($page > 1): ?>
                <button class="aicwp-ui-pagination-btn aicwp-history-page" data-page="<?php echo $page - 1; ?>">
                    <?php _e('Previous', 'ai-chat-wp'); ?>
                </button>
            <?php endif; ?>

            <div class="aicwp-ui-page-numbers">
                <?php if ($start > 1): ?>
                    <button class="aicwp-ui-pagination-btn aicwp-history-page" data-page="1">1</button>
                    <?php if ($start > 2): ?>
                        <span class="aicwp-ui-pagination-ellipsis">...</span>
                    <?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $start; $i <= $end; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="aicwp-ui-pagination-btn is-current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <button class="aicwp-ui-pagination-btn aicwp-history-page" data-page="<?php echo $i; ?>"><?php echo $i; ?></button>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($end < $total_pages): ?>
                    <?php if ($end < $total_pages - 1): ?>
                        <span class="aicwp-ui-pagination-ellipsis">...</span>
                    <?php endif; ?>
                    <button class="aicwp-ui-pagination-btn aicwp-history-page" data-page="<?php echo $total_pages; ?>"><?php echo $total_pages; ?></button>
                <?php endif; ?>
            </div>

            <?php if ($page < $total_pages): ?>
                <button class="aicwp-ui-pagination-btn aicwp-history-page" data-page="<?php echo $page + 1; ?>">
                    <?php _e('Next', 'ai-chat-wp'); ?>
                </button>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render a single conversation card
     *
     * @param array $conv Conversation data
     * @param array $messages Messages in the conversation
     * @param WP_User|null $user_info User info or null for guest
     */
    public function render_conversation_card($conv, $messages, $user_info) {
        ?>
        <div class="aicwp-ui-conversation-card" data-conversation-id="<?php echo esc_attr($conv['conversation_id']); ?>">
            <div class="aicwp-ui-conversation-header">
                <div class="aicwp-ui-conversation-id">
                    <strong><?php _e('Conversation ID:', 'ai-chat-wp'); ?></strong>
                    <code class="aicwp-ui-conversation-id-code"><?php echo esc_html($conv['conversation_id']); ?></code>
                    <?php do_action('aicwp_conversation_id_badge', $conv['conversation_id']); ?>
                    <button type="button" class="delete-conversation-btn" data-id="<?php echo esc_attr($conv['conversation_id']); ?>" title="<?php esc_attr_e('Delete this conversation', 'ai-chat-wp'); ?>" style="background: none; border: none; cursor: pointer; padding: 2px 6px; border-radius: 3px; color: #b32d2e; opacity: 0.6; transition: opacity 0.2s; margin-left: -5px;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"></path><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"></path><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"></path></svg>
                    </button>
                </div>
                <div class="aicwp-ui-conversation-meta">
                    <div style="font-size: 12px; color: #666;">
                        <?php if ($user_info): ?>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 3px;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg><?php echo esc_html($user_info->display_name); ?> (<?php echo esc_html($user_info->user_email); ?>)
                        <?php else: ?>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 3px;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg><?php _e('Guest User', 'ai-chat-wp'); ?>
                        <?php endif; ?>
                        <?php if (!empty($conv['ip_address'])): ?>
                            <?php
                            $geo = AICWP_Chat_History::get_country_from_ip($conv['ip_address']);
                            $tip_parts = array();
                            if ($geo) {
                                if (!empty($geo['country_name'])) $tip_parts[] = esc_attr($geo['country_name']);
                                if (!empty($geo['city'])) $tip_parts[] = esc_attr($geo['city']);
                                if (!empty($geo['region'])) $tip_parts[] = esc_attr($geo['region']);
                                if (!empty($geo['continent'])) $tip_parts[] = esc_attr($geo['continent']);
                            }
                            $tip_data = !empty($tip_parts) ? implode('|', $tip_parts) : '';
                            ?>
                            <span class="aicwp-ui-ip-geo" <?php if ($tip_data): ?>data-geo-tooltip="<?php echo $tip_data; ?>"<?php endif; ?>>
                                <?php if ($geo): ?>
                                    <img src="https://flagcdn.com/16x12/<?php echo esc_attr($geo['country_code']); ?>.png" alt="<?php echo esc_attr(strtoupper($geo['country_code'])); ?>" style="vertical-align: middle;" />
                                <?php endif; ?>
                                <span style="color: #999;"><?php echo esc_html($conv['ip_address']); ?></span>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size: 12px; color: #999;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 3px;"><circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path></svg><?php printf(__('Started: %s ago', 'ai-chat-wp'), human_time_diff(strtotime($conv['first_message_at']), current_time('timestamp'))); ?>
                    </div>
                </div>
            </div>

            <?php
            // Get cart events for this conversation from stored data
            $all_cart_events = get_option('aicwp_cart_events', array());
            $cart_products = array();
            if (isset($all_cart_events[$conv['conversation_id']])) {
                foreach ($all_cart_events[$conv['conversation_id']] as $event) {
                    $cart_products[] = $event['product_name'];
                }
            }
            ?>
            <div style="font-size: 13px; color: #666; margin-bottom: 10px; display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;">
                <span class="aicwp-ui-conversation-meta-info">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 3px;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg><?php echo $conv['message_count']; ?> <?php _e('messages', 'ai-chat-wp'); ?>
                    <?php if ($conv['first_message_at'] !== $conv['last_message_at']): ?>
                        &bull; <?php printf(__('last %s ago', 'ai-chat-wp'), human_time_diff(strtotime($conv['last_message_at']), current_time('timestamp'))); ?>
                    <?php endif; ?>
                    <?php if (!empty($cart_products)): ?>
                        &bull;
                        <span class="aicwp-ui-cart-indicator" data-cart-tooltip="<?php echo esc_attr(implode(', ', $cart_products)); ?>" style="color: #27ae60; cursor: help;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-right: 2px;"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg><?php printf(_n('%d product added', '%d products added', count($cart_products), 'ai-chat-wp'), count($cart_products)); ?>
                        </span>
                    <?php endif; ?>
                </span>
                <?php
                // Extension point: Conversation Auditor injects its "Analyze with AI" button here.
                do_action('aicwp_conversation_actions', $conv['conversation_id']);
                ?>
            </div>

            <details class="chat-history-details" style="margin-top: 10px;">
                <summary class="aicwp-ui-view-messages-summary">
                    <span><?php _e('View Messages', 'ai-chat-wp'); ?> (<?php echo count($messages); ?>)</span>
                    <span class="dashicons dashicons-arrow-down-alt2 aicwp-ui-view-messages-chevron"></span>
                </summary>
                <div class="chat-history-messages">
                    <?php
                    // Hook for displaying pre-chat field data before messages
                    do_action('aicwp_conversation_messages_before', $messages, $conv['conversation_id']);
                    ?>
                    <?php foreach ($messages as $msg): ?>
                        <div class="aicwp-ui-chat-msg aicwp-ui-chat-msg-user">
                            <div class="aicwp-ui-chat-msg-head">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                                <span class="aicwp-ui-chat-msg-name"><?php _e('User', 'ai-chat-wp'); ?></span>
                                <span class="aicwp-ui-chat-msg-time"><?php echo date_i18n('M j, ' . get_option('time_format'), strtotime($msg['created_at'])); ?></span>
                                <?php if (!empty($msg['page_url'])): ?>
                                    <span class="aicwp-ui-chat-msg-sep">&bull;</span>
                                    <a href="<?php echo esc_url($msg['page_url']); ?>"
                                       target="_blank"
                                       title="<?php echo esc_attr($msg['page_url']); ?>"
                                       class="aicwp-ui-chat-page-link">
                                        <span class="aicwp-ui-chat-page-link-text"><?php echo esc_html($this->get_page_title_from_url($msg['page_url'])); ?></span>
                                        <svg class="aicwp-ui-chat-page-link-icon" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                                    </a>
                                <?php endif; ?>
                            </div>
                            <div class="aicwp-ui-chat-msg-body">
                                <?php echo esc_html(trim($msg['user_message'])); ?>
                            </div>
                        </div>

                        <div class="aicwp-ui-chat-msg aicwp-ui-chat-msg-assistant">
                            <div class="aicwp-ui-chat-msg-head">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"></rect><circle cx="12" cy="5" r="2"></circle><path d="M12 7v4"></path><line x1="8" y1="16" x2="8" y2="16"></line><line x1="16" y1="16" x2="16" y2="16"></line></svg>
                                <span class="aicwp-ui-chat-msg-name"><?php _e('AI Assistant', 'ai-chat-wp'); ?></span>
                                <span class="aicwp-ui-chat-msg-time"><?php echo esc_html($msg['model_used']); ?></span>
                            </div>
                            <div class="aicwp-ui-chat-msg-body">
                                <?php echo nl2br(wp_kses($msg['assistant_message'], array('a' => array('href' => array(), 'title' => array(), 'target' => array(), 'rel' => array())))); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        </div>
        <?php
    }

    /**
     * Render JavaScript for chat history interactions
     */
    private function render_javascript() {
        $nonce = wp_create_nonce('aicwp_nonce');
        ?>
        <script>
        jQuery(document).ready(function($) {

            // Build geo tooltips from data attributes
            function initGeoTooltips() {
                $('.aicwp-ui-ip-geo[data-geo-tooltip]').not(':has(.aicwp-ui-geo-tooltip)').each(function() {
                    var $el = $(this);
                    var parts = $el.attr('data-geo-tooltip').split('|');
                    var labels = ['Country', 'City', 'Region', 'Continent'];
                    var rows = '';
                    for (var i = 0; i < parts.length; i++) {
                        if (parts[i]) {
                            rows += '<div class="aicwp-ui-geo-row"><span class="aicwp-ui-geo-label">' + labels[i] + ':</span> <span>' + $('<span>').text(parts[i]).html() + '</span></div>';
                        }
                    }
                    if (rows) {
                        $el.append('<div class="aicwp-ui-geo-tooltip">' + rows + '</div>');
                    }
                });
            }
            initGeoTooltips();

            // Build cart tooltips from data attributes
            $('.aicwp-ui-cart-indicator[data-cart-tooltip]').not(':has(.aicwp-ui-cart-tooltip)').each(function() {
                var $el = $(this);
                var products = $el.attr('data-cart-tooltip');
                if (products) {
                    var items = products.split(', ');
                    var rows = '';
                    for (var i = 0; i < items.length; i++) {
                        rows += '<div style="padding: 2px 0;">' + $('<span>').text(items[i]).html() + '</div>';
                    }
                    $el.css('position', 'relative');
                    $el.append('<div class="aicwp-ui-cart-tooltip" style="display:none; position:absolute; bottom:100%; left:50%; transform:translateX(-50%); background:#333; color:#fff; padding:8px 12px; border-radius:6px; font-size:12px; white-space:nowrap; z-index:100; margin-bottom:6px; box-shadow:0 2px 8px rgba(0,0,0,0.15);">' +
                        '<div style="font-weight:600; margin-bottom:4px; border-bottom:1px solid rgba(255,255,255,0.2); padding-bottom:4px;"><?php echo esc_js(__('Products added to cart:', 'ai-chat-wp')); ?></div>' +
                        rows + '</div>');
                    $el.on('mouseenter', function() { $(this).find('.aicwp-ui-cart-tooltip').fadeIn(150); });
                    $el.on('mouseleave', function() { $(this).find('.aicwp-ui-cart-tooltip').fadeOut(150); });
                }
            });

// Pagination click handler
            $(document).on('click', '.aicwp-history-page', function(e) {
                e.preventDefault();
                var page = $(this).data('page');
                var $container = $('#aicwp-history-conversations');
                var $pagination = $('#aicwp-history-pagination');

                $container.html('<p style="text-align: center; padding: 40px;"><span class="aicwp-ui-spinner"></span> Loading...</p>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aicwp_load_chat_history',
                        nonce: '<?php echo $nonce; ?>',
                        page: page
                    },
                    success: function(response) {
                        if (response.success) {
                            $container.html(response.data.conversations);
                            $pagination.html(response.data.pagination);
                            initGeoTooltips();
                            $('html, body').animate({
                                scrollTop: $container.offset().top - 100
                            }, 300);
                        } else {
                            $container.html('<p style="color: #d63638; text-align: center; padding: 20px;">' + response.data.message + '</p>');
                        }
                    },
                    error: function() {
                        $container.html('<p style="color: #d63638; text-align: center; padding: 20px;"><?php _e('Failed to load conversations. Please try again.', 'ai-chat-wp'); ?></p>');
                    }
                });
            });

            // Search conversations
            function searchConversations(searchTerm) {
                var $container = $('#aicwp-history-conversations');
                var $pagination = $('#aicwp-history-pagination');
                var $clearBtn = $('#conversation-search-clear');

                $container.html('<p style="text-align: center; padding: 40px;"><span class="aicwp-ui-spinner"></span> <?php _e('Searching...', 'ai-chat-wp'); ?></p>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aicwp_load_chat_history',
                        nonce: '<?php echo $nonce; ?>',
                        page: 1,
                        search: searchTerm
                    },
                    success: function(response) {
                        if (response.success) {
                            $container.html(response.data.conversations);
                            $pagination.html(response.data.pagination);
                            initGeoTooltips();
                            if (searchTerm) {
                                $clearBtn.show();
                            }
                        } else {
                            $container.html('<p style="color: #d63638; text-align: center; padding: 20px;">' + response.data.message + '</p>');
                        }
                    },
                    error: function() {
                        $container.html('<p style="color: #d63638; text-align: center; padding: 20px;"><?php _e('Failed to search. Please try again.', 'ai-chat-wp'); ?></p>');
                    }
                });
            }

            // Search button click
            $(document).on('click', '#conversation-search-btn', function(e) {
                e.preventDefault();
                var searchTerm = $('#conversation-search-input').val().trim();
                if (searchTerm) {
                    searchConversations(searchTerm);
                }
            });

            // Enter key in search input
            $(document).on('keypress', '#conversation-search-input', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    var searchTerm = $(this).val().trim();
                    if (searchTerm) {
                        searchConversations(searchTerm);
                    }
                }
            });

            // Clear search
            $(document).on('click', '#conversation-search-clear', function(e) {
                e.preventDefault();
                $('#conversation-search-input').val('');
                $(this).hide();
                searchConversations('');
            });

            // Delete single conversation button handler
            $(document).on('click', '.delete-conversation-btn', function(e) {
                e.preventDefault();
                e.stopPropagation();

                var $button = $(this);
                var conversationId = $button.data('id');
                var $card = $button.closest('[data-conversation-id]');

                if (!confirm('<?php _e('Delete this conversation?', 'ai-chat-wp'); ?>')) {
                    return;
                }

                $button.prop('disabled', true).css('opacity', '0.3');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'aicwp_delete_conversation',
                        conversation_id: conversationId,
                        nonce: '<?php echo $nonce; ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $card.slideUp(200, function() {
                                $(this).remove();
                                if ($('#aicwp-history-conversations').children().length === 0) {
                                    $('#aicwp-history-conversations').html('<p style="text-align: center; padding: 40px; color: #666;"><?php _e('No conversations found.', 'ai-chat-wp'); ?></p>');
                                }
                            });
                        } else {
                            alert(response.data.message || '<?php _e('Failed to delete conversation.', 'ai-chat-wp'); ?>');
                            $button.prop('disabled', false).css('opacity', '0.6');
                        }
                    },
                    error: function() {
                        alert('<?php _e('Failed to delete conversation. Please try again.', 'ai-chat-wp'); ?>');
                        $button.prop('disabled', false).css('opacity', '0.6');
                    }
                });
            });

            // Hover effect for delete button
            $(document).on('mouseenter', '.delete-conversation-btn', function() {
                $(this).css('opacity', '1');
            }).on('mouseleave', '.delete-conversation-btn', function() {
                $(this).css('opacity', '0.6');
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX handler for loading chat history with pagination
     */
    public function ajax_load() {
        if (!check_ajax_referer('aicwp_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed.', 'ai-chat-wp')));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'ai-chat-wp')));
            return;
        }

        if (!class_exists('AICWP_Chat_History')) {
            wp_send_json_error(array('message' => __('Chat history class not found.', 'ai-chat-wp')));
            return;
        }

        $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $offset = ($page - 1) * self::PER_PAGE;
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';

        global $wpdb;
        $table_name = AICWP_Chat_History::get_table_name();

        if (!empty($search)) {
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $recent_conversations = $wpdb->get_results($wpdb->prepare(
                "SELECT
                    conversation_id,
                    MIN(created_at) as first_message_at,
                    MAX(created_at) as last_message_at,
                    COUNT(*) as message_count,
                    user_id,
                    MAX(ip_address) as ip_address
                FROM {$table_name}
                WHERE conversation_id LIKE %s OR ip_address LIKE %s OR user_message LIKE %s OR assistant_message LIKE %s
                GROUP BY conversation_id
                ORDER BY last_message_at DESC
                LIMIT %d OFFSET %d",
                $search_like,
                $search_like,
                $search_like,
                $search_like,
                self::PER_PAGE,
                $offset
            ), ARRAY_A);

            $total_conversations = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT conversation_id) FROM {$table_name} WHERE conversation_id LIKE %s OR ip_address LIKE %s OR user_message LIKE %s OR assistant_message LIKE %s",
                $search_like,
                $search_like,
                $search_like,
                $search_like
            ));
        } else {
            $recent_conversations = AICWP_Chat_History::get_recent_conversations(self::PER_PAGE, $offset);
            $total_conversations = $wpdb->get_var("SELECT COUNT(DISTINCT conversation_id) FROM {$table_name}");
        }

        $total_pages = ceil($total_conversations / self::PER_PAGE);

        // Build conversations HTML
        ob_start();
        if (empty($recent_conversations)) {
            if (!empty($search)) {
                echo '<p style="text-align: center; padding: 40px; color: #666;">' . sprintf(__('No conversations found matching "%s"', 'ai-chat-wp'), esc_html($search)) . '</p>';
            } else {
                echo '<p style="text-align: center; padding: 40px; color: #666;">' . __('No conversations found.', 'ai-chat-wp') . '</p>';
            }
        }
        foreach ($recent_conversations as $conv) {
            $messages = AICWP_Chat_History::get_conversation($conv['conversation_id']);
            $user_info = $conv['user_id'] ? get_userdata($conv['user_id']) : null;
            $this->render_conversation_card($conv, $messages, $user_info);
        }
        $conversations_html = ob_get_clean();

        // Build pagination HTML
        ob_start();
        $this->render_pagination($page, $total_pages);
        $pagination_html = ob_get_clean();

        wp_send_json_success(array(
            'conversations' => $conversations_html,
            'pagination' => $pagination_html,
            'page' => $page,
            'total_pages' => $total_pages
        ));
    }

    /**
     * AJAX handler for clearing all chat history
     */
    public function ajax_clear() {
        if (!check_ajax_referer('aicwp_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed.', 'ai-chat-wp')));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'ai-chat-wp')));
            return;
        }

        if (!class_exists('AICWP_Chat_History')) {
            wp_send_json_error(array('message' => __('Chat history class not found.', 'ai-chat-wp')));
            return;
        }

        global $wpdb;
        $table_name = AICWP_Chat_History::get_table_name();

        $deleted = $wpdb->query("DELETE FROM {$table_name}");

        if ($deleted === false) {
            wp_send_json_error(array('message' => __('Failed to clear chat history.', 'ai-chat-wp')));
            return;
        }

        wp_send_json_success(array(
            'message' => sprintf(__('Successfully deleted %d chat records.', 'ai-chat-wp'), $deleted),
            'deleted' => $deleted
        ));
    }

    /**
     * AJAX handler for deleting a single conversation
     */
    public function ajax_delete_conversation() {
        if (!check_ajax_referer('aicwp_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed.', 'ai-chat-wp')));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'ai-chat-wp')));
            return;
        }

        $conversation_id = isset($_POST['conversation_id']) ? sanitize_text_field($_POST['conversation_id']) : '';

        if (empty($conversation_id)) {
            wp_send_json_error(array('message' => __('Conversation ID is required.', 'ai-chat-wp')));
            return;
        }

        if (!class_exists('AICWP_Chat_History')) {
            wp_send_json_error(array('message' => __('Chat history class not found.', 'ai-chat-wp')));
            return;
        }

        global $wpdb;
        $table_name = AICWP_Chat_History::get_table_name();

        $deleted = $wpdb->delete(
            $table_name,
            array('conversation_id' => $conversation_id),
            array('%s')
        );

        if ($deleted === false) {
            wp_send_json_error(array('message' => __('Failed to delete conversation.', 'ai-chat-wp')));
            return;
        }

        wp_send_json_success(array(
            'message' => sprintf(__('Deleted %d message(s) from conversation.', 'ai-chat-wp'), $deleted),
            'deleted' => $deleted
        ));
    }

    /**
     * AJAX handler for exporting chat history as CSV
     */
    public function ajax_export_csv() {
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'aicwp_nonce')) {
            wp_die(__('Security check failed.', 'ai-chat-wp'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'ai-chat-wp'));
        }

        if (!class_exists('AICWP_Chat_History')) {
            wp_die(__('Chat history class not found.', 'ai-chat-wp'));
        }

        $days = isset($_GET['days']) ? intval($_GET['days']) : null;

        AICWP_Chat_History::export_csv($days);
        exit;
    }

    /**
     * Get a display-friendly page title from URL
     * Attempts to resolve the URL to a post/page title, falls back to URL path
     *
     * @param string $url The page URL
     * @return string Display-friendly page name
     */
    private function get_page_title_from_url($url) {
        if (empty($url)) {
            return '';
        }

        // Parse the URL
        $parsed = wp_parse_url($url);
        $path = isset($parsed['path']) ? trim($parsed['path'], '/') : '';

        // Check for homepage
        if (empty($path) || $path === '/') {
            return __('Homepage', 'ai-chat-wp');
        }

        // Try to get post ID from URL (works for listings, posts, pages, products)
        $post_id = url_to_postid($url);
        if ($post_id > 0) {
            $post = get_post($post_id);
            if ($post) {
                return $post->post_title;
            }
        }

        // Fallback: extract last segment of URL path and clean it up
        $segments = explode('/', $path);
        $last_segment = end($segments);

        // Remove common URL patterns
        $last_segment = preg_replace('/\.(html?|php)$/i', '', $last_segment);

        // Convert hyphens/underscores to spaces and capitalize
        $title = str_replace(array('-', '_'), ' ', $last_segment);
        $title = ucwords($title);

        // If still empty, use a shortened URL
        if (empty($title)) {
            return '/' . $path;
        }

        return $title;
    }
}
