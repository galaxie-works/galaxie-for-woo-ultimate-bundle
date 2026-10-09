<?php
/**
 * Conversation Auditor - Pro Feature
 *
 * AI-driven analysis of chatbot conversation history. Generates per-conversation
 * summaries, data gaps, and weekly aggregate reports. Integrates
 * into the free plugin via hooks only - no scattered edits.
 *
 * @package AICWP
 * @since 2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Conversation_Auditor {

    private static $instance = null;

    const TABLE_NAME     = 'aicwp_conversation_analysis';
    const SCHEMA_VERSION = '1';
    const SCHEMA_OPTION  = 'aicwp_audit_schema_version';

    const CRON_HOOK = 'aicwp_audit_cron_run';

    // Hardcoded analysis models per provider (cheap, fast, reasoning-capable).
    const MODEL_OPENAI     = 'gpt-5.4-mini';
    const MODEL_OPENROUTER = 'openai/gpt-5.4-mini';
    const MODEL_GEMINI     = 'gemini-3-flash-preview';
    const MODEL_MISTRAL    = 'mistral-small-latest';

    // Max conversations analyzed per scheduled cron run (hardcoded cap on spend).
    const CRON_BATCH_LIMIT = 25;

    // Rough cost per 1K tokens (USD, display only).
    const MODEL_COSTS = array(
        'gpt-5.4-mini'         => 0.0002,
        'openai/gpt-5.4-mini'  => 0.0002,
        'gemini-3-flash'       => 0.0001,
        'mistral-small-latest' => 0.0002,
    );

    /** Static cache of per-conversation analysis state for row button rendering */
    private $row_state_cache = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->maybe_create_table();

        // Settings registration (via free plugin filter - no edits needed).
        add_filter('aicwp_settings_registry', array($this, 'register_settings'));
        add_filter('aicwp_sanitize_setting', array($this, 'sanitize_setting'), 10, 3);

        // UI injection hooks.
        add_action('aicwp_chat_history_analysis_tab', array($this, 'render_analysis_tab'));
        add_action('aicwp_conversation_actions', array($this, 'render_row_analyze_button'));
        add_action('admin_footer', array($this, 'render_admin_footer_modals'));

        // Assets.
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Cron.
        add_filter('cron_schedules', array($this, 'register_cron_schedules'));
        add_action('init', array($this, 'maybe_schedule_crons'));
        add_action(self::CRON_HOOK, array($this, 'cron_run_analysis'));
        add_action('update_option_aicwp_audit_enabled', array($this, 'on_settings_change'), 10, 2);
        add_action('update_option_aicwp_audit_interval', array($this, 'on_settings_change'), 10, 2);

        // AJAX handlers.
        add_action('wp_ajax_ai_chat_audit_analyze_single', array($this, 'ajax_analyze_single'));
        add_action('wp_ajax_ai_chat_audit_get_list',       array($this, 'ajax_get_list'));
        add_action('wp_ajax_ai_chat_audit_get_detail',     array($this, 'ajax_get_detail'));
        add_action('wp_ajax_ai_chat_audit_delete',         array($this, 'ajax_delete'));
        add_action('wp_ajax_ai_chat_audit_backlog_batch',  array($this, 'ajax_backlog_batch'));
        add_action('wp_ajax_ai_chat_audit_get_stats',      array($this, 'ajax_get_stats'));
        add_action('wp_ajax_ai_chat_audit_clear_all',     array($this, 'ajax_clear_all'));
        add_action('wp_ajax_ai_chat_audit_get_items',     array($this, 'ajax_get_items'));
    }

    // ============================================================
    // Schema & utilities
    // ============================================================

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    public static function get_chat_history_table() {
        global $wpdb;
        return $wpdb->prefix . 'aicwp_chat_history';
    }

    private function is_chat_history_enabled() {
        return (bool) get_option('aicwp_chat_history_enabled', 0);
    }

    private function maybe_create_table() {
        if (get_option(self::SCHEMA_OPTION) === self::SCHEMA_VERSION) {
            return;
        }
        if ($this->create_table()) {
            update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
        }
    }

    private function create_table() {
        global $wpdb;

        $table_name      = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            conversation_id varchar(50) NOT NULL,
            content_hash varchar(32) NOT NULL,
            title text NOT NULL,
            summary longtext NOT NULL,
            topics longtext NOT NULL,
            data_gaps longtext NOT NULL,
            weak_points longtext NOT NULL,
            sentiment varchar(20) NOT NULL DEFAULT 'neutral',
            suggested_action varchar(40) NOT NULL DEFAULT 'none',
            model_used varchar(64) NOT NULL,
            tokens_used int(10) unsigned NOT NULL DEFAULT 0,
            analyzed_at datetime DEFAULT CURRENT_TIMESTAMP,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY conversation_id (conversation_id),
            KEY content_hash (content_hash),
            KEY sentiment (sentiment),
            KEY analyzed_at (analyzed_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") != $table_name) {
            error_log('AI Chat for WordPress: Failed to create conversation_analysis table');
            return false;
        }
        return true;
    }

    // ============================================================
    // Settings
    // ============================================================

    public function register_settings($settings) {
        $audit_settings = array(
            'aicwp_audit_enabled' => array(
                'type'        => 'checkbox',
                'section'     => 'audit',
                'sanitize'    => 'intval',
                'default'     => 0,
                'description' => 'Enable AI-driven conversation analysis',
            ),
            'aicwp_audit_interval' => array(
                'type'        => 'select',
                'section'     => 'audit',
                'sanitize'    => 'sanitize_text_field',
                'default'     => '24',
                'description' => 'Analysis cron interval in hours (12/24/48)',
            ),
        );
        return array_merge($settings, $audit_settings);
    }

    public function sanitize_setting($value, $key, $original) {
        if ($key === 'aicwp_audit_interval') {
            $allowed = array('12', '24', '48');
            return in_array((string) $value, $allowed, true) ? (string) $value : '24';
        }
        return $value;
    }

    /**
     * Render the Audit settings modal.
     * Output in admin_footer on the Stats tab only. Triggered by the
     * "Configure" button inside the Conversation Audit card header.
     *
     * Uses a custom class (NOT aicwp-ui-ajax-form) so the generic form handler
     * doesn't intercept - we own submit behavior to close the modal on success.
     */
    public function render_settings_modal() {
        $enabled  = (int) get_option('aicwp_audit_enabled', 0);
        $interval = (string) get_option('aicwp_audit_interval', '24');
        $next_run = wp_next_scheduled(self::CRON_HOOK);
        $last_run = (int) get_option('aicwp_audit_last_run', 0);
        ?>
        <div id="ai-chat-audit-settings-modal" class="aicwp-ui-modal" style="display:none;">
            <div class="aicwp-ui-modal-overlay"></div>
            <div class="aicwp-ui-modal-content aicwp-ui-audit-settings-modal-content">
                <form id="ai-chat-audit-settings-form">
                    <div class="aicwp-ui-modal-header">
                        <h3><?php esc_html_e('Configure Chat Insights', 'ai-chat-wp'); ?></h3>
                        <button type="button" class="aicwp-modal-close" id="ai-chat-audit-settings-close">
                            <span class="dashicons dashicons-no-alt"></span>
                        </button>
                    </div>
                    <div class="aicwp-ui-modal-body">

                        <?php if (!$this->is_chat_history_enabled()): ?>
                        <div class="aicwp-ui-notice aicwp-ui-notice-warning" style="padding: 12px; background: #fff8e5; border-left: 4px solid #dba617; margin-bottom: 15px; border-radius: 4px;">
                            <?php esc_html_e('Chat history tracking must be enabled (AI Chat tab) for the audit feature to work.', 'ai-chat-wp'); ?>
                        </div>
                        <?php endif; ?>

                        <div class="aicwp-ui-form-group">
                            <label class="aicwp-ui-checkbox-label">
                                <input type="checkbox" name="aicwp_audit_enabled" value="1" <?php checked($enabled, 1); ?> />
                                <span class="aicwp-ui-checkbox-custom"></span>
                                <span class="aicwp-ui-checkbox-text">
                                    <?php esc_html_e('Enable Automatic Analysis', 'ai-chat-wp'); ?>
                                    <small><?php esc_html_e('Runs the scheduled analyzer on the interval below. Manual analysis always works regardless of this setting.', 'ai-chat-wp'); ?></small>
                                </span>
                            </label>
                        </div>

                        <div class="aicwp-ui-form-group">
                            <label class="aicwp-ui-label" for="aicwp_audit_interval"><?php esc_html_e('Analysis Interval', 'ai-chat-wp'); ?></label>
                            <select name="aicwp_audit_interval" id="aicwp_audit_interval" class="aicwp-ui-input">
                                <option value="12" <?php selected($interval, '12'); ?>><?php esc_html_e('Every 12 hours', 'ai-chat-wp'); ?></option>
                                <option value="24" <?php selected($interval, '24'); ?>><?php esc_html_e('Every 24 hours', 'ai-chat-wp'); ?></option>
                                <option value="48" <?php selected($interval, '48'); ?>><?php esc_html_e('Every 48 hours', 'ai-chat-wp'); ?></option>
                            </select>
                            <p class="aicwp-ui-help-text"><?php esc_html_e('How often the background analyzer runs.', 'ai-chat-wp'); ?></p>

                            <?php if ($enabled && $next_run): ?>
                            <div class="ai-chat-audit-cron-status">
                                <span class="ai-chat-audit-cron-dot"></span>
                                <?php
                                printf(
                                    /* translators: %s = relative time, e.g. "23 hours" */
                                    esc_html__('Next run: in %s', 'ai-chat-wp'),
                                    esc_html(human_time_diff(current_time('timestamp'), $next_run))
                                );
                                if ($last_run) {
                                    echo ' &middot; ';
                                    printf(
                                        /* translators: %s = relative time, e.g. "1 hour" */
                                        esc_html__('last run %s ago', 'ai-chat-wp'),
                                        esc_html(human_time_diff($last_run, current_time('timestamp')))
                                    );
                                }
                                ?>
                            </div>
                            <?php elseif ($enabled): ?>
                            <div class="ai-chat-audit-cron-status ai-chat-audit-cron-status-pending">
                                <?php esc_html_e('Cron not scheduled yet. Save settings to schedule the next run.', 'ai-chat-wp'); ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Analyze past conversations section (in-modal backlog) -->
                        <div class="ai-chat-audit-backlog-section">
                            <h4 class="ai-chat-audit-backlog-title"><?php esc_html_e('Analyze past conversations', 'ai-chat-wp'); ?></h4>
                            <p class="aicwp-ui-help-text">
                                <?php esc_html_e('Processes unanalyzed conversations from the selected range.', 'ai-chat-wp'); ?>
                            </p>

                            <div class="aicwp-ui-form-group" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                <select id="ai-chat-audit-backlog-range" class="aicwp-ui-input" style="max-width: 220px; flex: 0 0 auto;">
                                    <option value="1d"><?php esc_html_e('Last 24 hours', 'ai-chat-wp'); ?></option>
                                    <option value="3d"><?php esc_html_e('Last 3 days', 'ai-chat-wp'); ?></option>
                                    <option value="7d"><?php esc_html_e('Last 7 days', 'ai-chat-wp'); ?></option>
                                    <option value="14d" selected><?php esc_html_e('Last 14 days', 'ai-chat-wp'); ?></option>
                                    <option value="30d"><?php esc_html_e('Last 30 days', 'ai-chat-wp'); ?></option>
                                </select>
                                <button type="button" class="aicwp-ui-button aicwp-ui-button-secondary" id="ai-chat-audit-backlog-start">
                                    <?php esc_html_e('Start', 'ai-chat-wp'); ?>
                                </button>
                                <button type="button" class="aicwp-ui-button aicwp-ui-button-secondary" id="ai-chat-audit-backlog-stop" style="display:none; color: #b32d2e;">
                                    <?php esc_html_e('Stop', 'ai-chat-wp'); ?>
                                </button>
                            </div>

                            <div class="aicwp-ui-audit-backlog-progress" id="ai-chat-audit-backlog-progress" style="display:none;">
                                <div class="aicwp-ui-audit-progress-bar"><div class="aicwp-ui-audit-progress-fill"></div></div>
                                <div class="aicwp-ui-audit-progress-text"></div>
                            </div>
                        </div>

                        <div class="ai-chat-audit-clear-all">
                            <a href="#" id="ai-chat-audit-clear-all"><?php esc_html_e('Clear All Analysis', 'ai-chat-wp'); ?></a>
                        </div>

                    </div>
                    <div class="aicwp-ui-modal-footer">
                        <button type="submit" class="aicwp-ui-button aicwp-ui-button-primary">
                            <?php esc_html_e('Save Settings', 'ai-chat-wp'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    // ============================================================
    // Asset enqueue
    // ============================================================

    public function enqueue_admin_assets($hook) {
        if ($hook !== 'toplevel_page_ai-chat-wp') {
            return;
        }
        $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'settings';
        // Only load on Stats tab - audit UI + per-row button + settings modal all live here.
        if ($active_tab !== 'stats') {
            return;
        }
        wp_enqueue_style(
            'ai-chat-audit',
            AICWP_PLUGIN_URL . 'assets/css/admin-audit.css',
            array(),
            AICWP_VERSION
        );

        wp_enqueue_script(
            'ai-chat-audit',
            AICWP_PLUGIN_URL . 'assets/js/admin-audit.js',
            array('jquery'),
            AICWP_VERSION,
            true
        );

        wp_localize_script('ai-chat-audit', 'aiChatAuditData', array(
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('ai_chat_audit_nonce'),
            'settingsUrl' => admin_url('admin.php?page=ai-chat-wp&tab=ai-chat#audit-settings'),
            'i18n'        => array(
                'analyzing'         => __('Analyzing...', 'ai-chat-wp'),
                'analyze'           => __('Analyze this conversation', 'ai-chat-wp'),
                'viewAnalysis'      => __('View analysis', 'ai-chat-wp'),
                'analyzeFailed'     => __('Analysis failed', 'ai-chat-wp'),
                'loading'           => __('Loading...', 'ai-chat-wp'),
                'saving'            => __('Saving...', 'ai-chat-wp'),
                'noResults'         => __('No analyses yet. Click "Analyze backlog" to start.', 'ai-chat-wp'),
                'loadMore'          => __('Load more', 'ai-chat-wp'),
                /* translators: %d = number of items remaining to load */
                'loadMoreRemaining' => __('Load more (%d remaining)', 'ai-chat-wp'),
                'delete'            => __('Delete analysis', 'ai-chat-wp'),
                'confirmDelete'     => __('Delete this analysis? The conversation itself is not affected.', 'ai-chat-wp'),
                'viewConversation'  => __('View full conversation', 'ai-chat-wp'),
                'summary'           => __('Summary', 'ai-chat-wp'),
                'dataGaps'          => __('Data Gaps', 'ai-chat-wp'),
                'lowPriority'       => __('Low Priority', 'ai-chat-wp'),
                'mediumPriority'    => __('Medium Priority', 'ai-chat-wp'),
                'highPriority'      => __('High Priority', 'ai-chat-wp'),
                'positive'          => __('Positive', 'ai-chat-wp'),
                'negative'          => __('Negative', 'ai-chat-wp'),
                'batchStart'        => __('Start', 'ai-chat-wp'),
                'batchStop'         => __('Stop', 'ai-chat-wp'),
                'batchProgress'     => __('Processed %1$d of %2$d', 'ai-chat-wp'),
                'batchComplete'     => __('Done.', 'ai-chat-wp'),
                'batchError'        => __('Failed.', 'ai-chat-wp'),
                'noAnalysesYet'     => __('No conversations analyzed yet.', 'ai-chat-wp'),
                'emptyHelpText'     => __('Analysis runs automatically on the interval you configure. You can also process existing conversations in bulk.', 'ai-chat-wp'),
                'analyzePast'       => __('Analyze past conversations', 'ai-chat-wp'),
                'goToSettings'      => __('Go to settings', 'ai-chat-wp'),
                'gap'               => __('data gap', 'ai-chat-wp'),
                'gaps'              => __('data gaps', 'ai-chat-wp'),
                'messages'          => __('messages', 'ai-chat-wp'),
                'chatHistoryRequired' => __('Chat history tracking must be enabled before the audit feature can work.', 'ai-chat-wp'),
                'settingsSaved'     => __('Settings saved.', 'ai-chat-wp'),
                'saveFailed'        => __('Save failed.', 'ai-chat-wp'),
                'configure'         => __('Configure', 'ai-chat-wp'),
                'enabled'           => __('enabled', 'ai-chat-wp'),
                'disabled'          => __('disabled', 'ai-chat-wp'),
                'analyzeWithAI'     => __('Analyze with AI', 'ai-chat-wp'),
                'justNow'           => __('just now', 'ai-chat-wp'),
                'ago'               => __('ago', 'ai-chat-wp'),
                'notFound'          => __('Conversation not found in history.', 'ai-chat-wp'),
                'skipNotice'        => __('This conversation had no meaningful content to audit.', 'ai-chat-wp'),
                'confirmClearAll'   => __('Delete all analysis data? This cannot be undone.', 'ai-chat-wp'),
                'clearedAll'        => __('All analysis data cleared.', 'ai-chat-wp'),
                'processing'        => __('Processing...', 'ai-chat-wp'),
            ),
        ));
    }

    // ============================================================
    // Analysis tab + row button + modal rendering
    // ============================================================

    public function render_row_analyze_button($conversation_id) {
        if (!$conversation_id) {
            return;
        }

        // Prefetch row states lazily on first call.
        if ($this->row_state_cache === null) {
            $this->prefetch_row_states();
        }

        $state = isset($this->row_state_cache[$conversation_id]) ? $this->row_state_cache[$conversation_id] : 'not_analyzed';
        $label = $state === 'analyzed'
            ? __('View analysis', 'ai-chat-wp')
            : __('Analyze with AI', 'ai-chat-wp');
        ?>
        <button type="button"
                class="ai-chat-audit-analyze-btn aicwp-ui-audit-btn-<?php echo esc_attr($state); ?>"
                data-id="<?php echo esc_attr($conversation_id); ?>"
                data-state="<?php echo esc_attr($state); ?>">
            <svg class="aicwp-ui-audit-sparkle" width="14" height="14" viewBox="0 0 24 24" fill="<?php echo $state === 'analyzed' ? 'currentColor' : 'none'; ?>" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 3l2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5z"></path>
            </svg>
            <span class="ai-chat-audit-loader" aria-hidden="true"></span>
            <span class="ai-chat-audit-analyze-label"><?php echo esc_html($label); ?></span>
        </button>
        <?php
    }

    private function prefetch_row_states() {
        global $wpdb;
        $this->row_state_cache = array();

        $table = self::get_table_name();
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") != $table) {
            return;
        }

        $rows = $wpdb->get_results("SELECT conversation_id, content_hash FROM {$table}", ARRAY_A);
        if ($rows) {
            foreach ($rows as $row) {
                $this->row_state_cache[$row['conversation_id']] = 'analyzed';
            }
        }
    }

    public function render_analysis_tab() {
        if (!$this->is_chat_history_enabled()) {
            ?>
            <div class="aicwp-ui-card">
                <div class="aicwp-ui-card-body">
                    <div class="aicwp-ui-audit-empty-state">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#999" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                        <h3><?php esc_html_e('Chat Insights are off', 'ai-chat-wp'); ?></h3>
                        <p><?php esc_html_e('Enable "Chat History Tracking" in the Chat History tab configure settings, have at least a few conversations, then come back here.', 'ai-chat-wp'); ?></p>
                    </div>
                </div>
            </div>
            <?php
            return;
        }

        $stats = $this->get_dashboard_stats();
        ?>
        <div class="aicwp-ui-card aicwp-ui-card-toggleable ai-chat-audit-card" data-toggle-id="stats-conversation-audit">
                <div class="aicwp-ui-card-header aicwp-ui-card-header-with-icon">
                    <div class="aicwp-ui-card-icon aicwp-ui-card-icon-indigo">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5z"></path></svg>
                    </div>
                    <div class="aicwp-ui-card-header-text">
                        <h3><?php esc_html_e('Chat Insights', 'ai-chat-wp'); ?></h3>
                        <p><?php esc_html_e('AI analyzes chatbot conversations and highlights summaries, data gaps, and sentiment.', 'ai-chat-wp'); ?></p>
                    </div>
                    <span class="dashicons dashicons-arrow-down-alt2 aicwp-ui-card-toggle-icon"></span>
                </div>
                <div class="aicwp-ui-card-body">

                        <!-- Stat boxes (reuses existing aicwp-ui-stat-box pattern, 4 per row) -->
                        <div class="aicwp-ui-stats-boxes" id="ai-chat-audit-stat-strip">
                            <div class="aicwp-ui-stat-box aicwp-ui-stat-box-blue">
                                <div class="aicwp-ui-stat-number aicwp-ui-stat-number-blue" id="stat-analyzed"><?php echo esc_html(number_format_i18n($stats['analyzed'])); ?></div>
                                <div class="aicwp-ui-stat-label aicwp-ui-stat-label-blue"><?php esc_html_e('Analyzed', 'ai-chat-wp'); ?></div>
                            </div>
                            <div class="aicwp-ui-stat-box aicwp-ui-stat-box-orange" style="cursor: pointer;" data-stat-type="gaps">
                                <span class="aicwp-ui-audit-stat-info" data-tooltip="<?php esc_attr_e('Questions the chatbot could not answer because the information was missing from your content.', 'ai-chat-wp'); ?>" tabindex="0" aria-label="<?php esc_attr_e('More info', 'ai-chat-wp'); ?>">?</span>
                                <div class="aicwp-ui-stat-number aicwp-ui-stat-number-orange" id="stat-gaps"><?php echo esc_html(number_format_i18n($stats['data_gaps'])); ?></div>
                                <div class="aicwp-ui-stat-label aicwp-ui-stat-label-orange"><?php esc_html_e('Data gaps', 'ai-chat-wp'); ?></div>
                            </div>
                            <div class="aicwp-ui-stat-box aicwp-ui-stat-box-green">
                                <span class="aicwp-ui-audit-stat-info" data-tooltip="<?php esc_attr_e('Share of conversations where the user\'s tone was positive. Measures sentiment only, not answer quality.', 'ai-chat-wp'); ?>" tabindex="0" aria-label="<?php esc_attr_e('More info', 'ai-chat-wp'); ?>">?</span>
                                <div class="aicwp-ui-stat-number aicwp-ui-stat-number-green" id="stat-positive"><?php echo esc_html($stats['positive_pct']); ?>%</div>
                                <div class="aicwp-ui-stat-label aicwp-ui-stat-label-green"><?php esc_html_e('Positive', 'ai-chat-wp'); ?></div>
                            </div>
                        </div>

                        <!-- Filters -->
                        <div class="aicwp-ui-audit-filters" id="ai-chat-audit-filters">
                            <span class="aicwp-ui-position-toggle">
                                <button type="button" class="aicwp-ui-position-btn active" data-filter="sentiment" data-value=""><?php esc_html_e('All', 'ai-chat-wp'); ?></button>
                                <button type="button" class="aicwp-ui-position-btn" data-filter="sentiment" data-value="positive"><?php esc_html_e('Positive', 'ai-chat-wp'); ?></button>
                                <button type="button" class="aicwp-ui-position-btn" data-filter="sentiment" data-value="negative"><?php esc_html_e('Negative', 'ai-chat-wp'); ?></button>
                            </span>
                            <span class="aicwp-ui-position-toggle">
                                <button type="button" class="aicwp-ui-position-btn active" data-filter="gaps" data-value=""><?php esc_html_e('All', 'ai-chat-wp'); ?></button>
                                <button type="button" class="aicwp-ui-position-btn" data-filter="gaps" data-value="has_gaps"><?php esc_html_e('Data gaps', 'ai-chat-wp'); ?></button>
                                <button type="button" class="aicwp-ui-position-btn" data-filter="gaps" data-value="no_gaps"><?php esc_html_e('No data gaps', 'ai-chat-wp'); ?></button>
                            </span>
                        </div>

                        <!-- List -->
                        <div class="aicwp-ui-audit-list" id="ai-chat-audit-list">
                            <div class="aicwp-ui-audit-loading"><span class="aicwp-ui-spinner"></span></div>
                        </div>

                        <div class="aicwp-ui-pagination ai-chat-audit-load-more-wrap" id="ai-chat-audit-load-more-wrap" style="display:none;">
                            <button type="button" class="aicwp-ui-pagination-btn" id="ai-chat-audit-load-more">
                                <?php esc_html_e('Load more', 'ai-chat-wp'); ?>
                            </button>
                        </div>

                        <!-- Bottom action bar: auto analysis status (left) + Configure (right) -->
                        <div class="ai-chat-audit-bottom-actions">
                            <?php
                            $is_auto_on = (int) get_option('aicwp_audit_enabled', 0);
                            ?>
                            <span class="ai-chat-audit-auto-status">
                                <?php esc_html_e('Auto analysis:', 'ai-chat-wp'); ?>
                                <strong id="ai-chat-audit-auto-status-value" class="<?php echo $is_auto_on ? 'is-enabled' : 'is-disabled'; ?>">
                                    <?php echo $is_auto_on ? esc_html__('enabled', 'ai-chat-wp') : esc_html__('disabled', 'ai-chat-wp'); ?>
                                </strong>
                            </span>
                            <button type="button" class="aicwp-ui-button aicwp-ui-button-secondary" id="ai-chat-audit-configure-btn">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 5px;"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                                <?php esc_html_e('Configure', 'ai-chat-wp'); ?>
                            </button>
                        </div>

                </div>
            </div>
        <?php
    }

    /**
     * Render all modals in admin_footer (detail + settings).
     * Only emitted on the Stats tab.
     */
    public function render_admin_footer_modals() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'toplevel_page_ai-chat-wp') {
            return;
        }
        $tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'settings';
        if ($tab !== 'stats') {
            return;
        }
        ?>
        <!-- Detail modal -->
        <div id="ai-chat-audit-detail-modal" class="aicwp-ui-modal" style="display:none;">
            <div class="aicwp-ui-modal-overlay"></div>
            <div class="aicwp-ui-modal-content aicwp-ui-audit-modal-content">
                <div class="aicwp-ui-modal-header">
                    <h3 id="ai-chat-audit-modal-title"><?php esc_html_e('Conversation Analysis', 'ai-chat-wp'); ?></h3>
                    <button type="button" class="aicwp-modal-close" id="ai-chat-audit-modal-close">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </div>
                <div class="aicwp-ui-modal-body" id="ai-chat-audit-modal-body">
                    <div class="aicwp-ui-audit-loading"><?php esc_html_e('Loading...', 'ai-chat-wp'); ?></div>
                </div>
                <div class="aicwp-ui-modal-footer">
                    <button type="button" class="aicwp-ui-button aicwp-ui-button-secondary" id="ai-chat-audit-modal-view-conv">
                        <?php esc_html_e('View full conversation', 'ai-chat-wp'); ?>
                    </button>
                    <button type="button" class="aicwp-ui-button aicwp-ui-button-secondary" id="ai-chat-audit-modal-delete">
                        <?php esc_html_e('Delete analysis', 'ai-chat-wp'); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Item detail modal (stacks on top of items list modal) -->
        <div id="ai-chat-audit-item-detail-modal" class="aicwp-ui-modal aicwp-ui-modal-stacked" style="display:none;">
            <div class="aicwp-ui-modal-overlay"></div>
            <div class="aicwp-ui-modal-content aicwp-ui-audit-modal-content">
                <div class="aicwp-ui-modal-header">
                    <h3 id="ai-chat-audit-item-detail-modal-title"><?php esc_html_e('Conversation Analysis', 'ai-chat-wp'); ?></h3>
                    <button type="button" class="aicwp-modal-close" id="ai-chat-audit-item-detail-modal-close">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </div>
                <div class="aicwp-ui-modal-body" id="ai-chat-audit-item-detail-modal-body">
                    <div class="aicwp-ui-audit-loading"><span class="aicwp-ui-spinner"></span></div>
                </div>
                <div class="aicwp-ui-modal-footer">
                    <button type="button" class="aicwp-ui-button aicwp-ui-button-secondary" id="ai-chat-audit-item-detail-modal-view-conv">
                        <?php esc_html_e('View full conversation', 'ai-chat-wp'); ?>
                    </button>
                </div>
            </div>
        </div>

        <?php $this->render_settings_modal(); ?>
        <?php
    }

    // ============================================================
    // Analysis engine
    // ============================================================

    public function analyze_conversation($conversation_id, $force = false) {
        if (empty($conversation_id)) {
            return new WP_Error('no_id', __('Missing conversation id.', 'ai-chat-wp'));
        }

        $messages = apply_filters('aicwp_chat_history_conversation', array(), $conversation_id);
        if (!is_array($messages) || count($messages) < 1) {
            return new WP_Error('empty', __('Conversation is empty.', 'ai-chat-wp'));
        }

        $hash = $this->compute_content_hash($messages);

        if (!$force) {
            $existing = $this->get_analysis_by_conversation_id($conversation_id);
            if ($existing && $existing['content_hash'] === $hash) {
                return (int) $existing['id'];
            }
        }

        $provider = new AICWP_Provider();
        $payload  = $this->build_analysis_payload($messages, $provider);

        $response = wp_remote_post($provider->get_endpoint('chat'), array(
            'headers' => $provider->get_headers(),
            'body'    => wp_json_encode($payload),
            'timeout' => 60,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $body = wp_remote_retrieve_body($response);
            return new WP_Error('api_error', sprintf('HTTP %d: %s', $code, substr($body, 0, 300)));
        }

        $data   = json_decode(wp_remote_retrieve_body($response), true);
        $parsed = $this->parse_analysis_response($data);

        if (is_wp_error($parsed)) {
            return $parsed;
        }

        $row_id = $this->store_analysis($conversation_id, $hash, $parsed);
        $this->track_cost($data, $parsed['model_used']);

        return $row_id;
    }

    /**
     * Resolve a WordPress locale (e.g. "pl_PL", "fr_FR", "en_US") to an
     * English language name the AI will reliably understand. Falls back to
     * the raw locale code for locales not in the map.
     */
    private function locale_to_language_name($locale) {
        if (function_exists('locale_get_display_language')) {
            $name = locale_get_display_language($locale, 'en');
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        static $map = array(
            'en' => 'English',  'pl' => 'Polish',   'fr' => 'French',
            'de' => 'German',   'es' => 'Spanish',  'it' => 'Italian',
            'pt' => 'Portuguese','nl' => 'Dutch',   'sv' => 'Swedish',
            'da' => 'Danish',   'no' => 'Norwegian','fi' => 'Finnish',
            'ru' => 'Russian',  'uk' => 'Ukrainian','cs' => 'Czech',
            'sk' => 'Slovak',   'hu' => 'Hungarian','ro' => 'Romanian',
            'bg' => 'Bulgarian','el' => 'Greek',    'tr' => 'Turkish',
            'ar' => 'Arabic',   'he' => 'Hebrew',   'fa' => 'Persian',
            'hi' => 'Hindi',    'ja' => 'Japanese', 'ko' => 'Korean',
            'zh' => 'Chinese',  'vi' => 'Vietnamese','th' => 'Thai',
            'id' => 'Indonesian','ms' => 'Malay',   'tl' => 'Tagalog',
        );
        $short = substr($locale, 0, 2);
        return isset($map[$short]) ? $map[$short] : $locale;
    }

    private function get_audit_model() {
        $provider = get_option('aicwp_provider', 'openai');
        switch ($provider) {
            case 'openrouter':
                return self::MODEL_OPENROUTER;
            case 'gemini':
                return self::MODEL_GEMINI;
            case 'mistral':
                return self::MODEL_MISTRAL;
            default:
                return self::MODEL_OPENAI;
        }
    }

    private function build_analysis_payload($messages, $provider) {
        // Build individual turns so we can keep the most recent ones.
        $turns = array();
        foreach ($messages as $msg) {
            $u = isset($msg['user_message']) ? trim($msg['user_message']) : '';
            $a = isset($msg['assistant_message']) ? trim($msg['assistant_message']) : '';
            if ($u === '' && $a === '') continue;
            $turns[] = "USER: " . $u . "\nASSISTANT: " . $a . "\n---\n";
        }

        // Keep the tail of the conversation - recent turns reveal data gaps
        // better than early smalltalk.
        $convo = '';
        $max_chars = 16000;
        for ($i = count($turns) - 1; $i >= 0; $i--) {
            $candidate = $turns[$i] . $convo;
            if ($i < count($turns) - 1 && mb_strlen($candidate) > $max_chars) {
                $convo = "[...earlier messages omitted]\n" . $convo;
                break;
            }
            $convo = $candidate;
        }

        // Force the analysis output to match the WordPress admin language so
        // admins always see results in their own UI language, regardless of
        // what language the user and chatbot spoke.
        $locale    = get_locale();
        $lang_name = $this->locale_to_language_name($locale);

        $system = sprintf(
            'You are a strict conversation auditor for a website chatbot. Return ONLY valid JSON matching the schema below. Do not wrap in markdown code fences.

LANGUAGE: All text fields (title, summary, data_gaps questions) MUST be written in %1$s (WordPress locale: %2$s), regardless of the language the conversation itself used.

TRIVIAL CONVERSATIONS: If the conversation has no meaningful content to audit (pure greetings, test messages, single-message stubs, nonsense input, one-word exchanges), set "suggested_action": "skip" and fill all other fields with minimal valid values. Skipped conversations are hidden from the admin UI - this saves the admin from reviewing noise. Examples of skip-worthy: "hi" -> "hello", "test" -> "how can I help?", "asdfgh" -> "sorry, I did not understand".

When sentiment is "negative", include a concise note at the end of the summary explaining why.

CRITICAL RULES for data_gaps - be conservative, prefer empty arrays over speculation:

data_gaps = SPECIFIC questions the user actually asked that the bot FAILED to answer. Only report if:
  - The user explicitly asked about something and the bot ignored it, dodged it, or said "I don\'t know"
  - The user had to repeat themselves or leave because they did not get the information
Do NOT report:
  - Questions the bot could have proactively asked (e.g. budget, preferences) if the user never asked them
  - Missing "nice to have" context the user did not request
  - Anything the bot actually answered or offered to answer

If the user got what they wanted, data_gaps SHOULD usually be an empty array. Empty is better than fabricated.',
            $lang_name,
            $locale
        );

        $schema = '{
  "title": string (6-10 words describing what the conversation was about),
  "summary": string (2-3 sentences, factual, no nitpicks),
  "data_gaps": [{"question": string, "frequency_hint": "once"|"repeated"}] (empty array if none),
  "sentiment": "positive"|"negative",
  "suggested_action": "add_kb"|"improve_prompt"|"add_quick_button"|"none"|"skip"
}';

        $user = sprintf(
            "Conversation to analyze (the conversation may be in any language, but your output must be in %s):\n\n%s\n\nReturn JSON matching this schema exactly:\n%s",
            $lang_name,
            $convo,
            $schema
        );

        $model = $this->get_audit_model();

        $payload = array(
            'model'       => $model,
            'messages'    => array(
                array('role' => 'system', 'content' => $system),
                array('role' => 'user',   'content' => $user),
            ),
            'response_format' => array('type' => 'json_object'),
        );

        return $provider->normalize_chat_payload($payload, array(
            'max_tokens'  => 3000,
            'temperature' => 0.3,
            'reasoning'   => 'low',
        ));
    }

    private function parse_analysis_response($data) {
        if (empty($data['choices'][0]['message']['content'])) {
            return new WP_Error('no_content', 'Empty response from provider');
        }

        $content = $data['choices'][0]['message']['content'];
        // Strip markdown code fences if model ignored instruction.
        $content = preg_replace('/^```(?:json)?\s*|\s*```\s*$/m', '', trim($content));

        $json = json_decode($content, true);
        if (!is_array($json)) {
            return new WP_Error('parse_failed', 'JSON parse failed: ' . json_last_error_msg());
        }

        return array(
            'title'            => isset($json['title']) ? wp_strip_all_tags((string) $json['title']) : __('Untitled analysis', 'ai-chat-wp'),
            'summary'          => isset($json['summary']) ? wp_strip_all_tags((string) $json['summary']) : '',
            'data_gaps'        => $this->normalize_data_gaps(isset($json['data_gaps']) ? $json['data_gaps'] : array()),
            'sentiment'        => isset($json['sentiment']) && in_array($json['sentiment'], array('positive', 'negative'), true) ? $json['sentiment'] : 'positive',
            'suggested_action' => isset($json['suggested_action']) && in_array($json['suggested_action'], array('add_kb', 'improve_prompt', 'add_quick_button', 'none', 'skip'), true) ? $json['suggested_action'] : 'none',
            'model_used'       => isset($data['model']) ? sanitize_text_field($data['model']) : $this->get_audit_model(),
            'tokens_used'      => isset($data['usage']['total_tokens']) ? intval($data['usage']['total_tokens']) : 0,
        );
    }

    private function normalize_data_gaps($arr) {
        if (!is_array($arr)) return array();
        $out = array();
        foreach ($arr as $item) {
            if (!is_array($item) || empty($item['question'])) continue;
            $out[] = array(
                'question'       => wp_strip_all_tags((string) $item['question']),
                'frequency_hint' => isset($item['frequency_hint']) && in_array($item['frequency_hint'], array('once', 'repeated'), true) ? $item['frequency_hint'] : 'once',
            );
            if (count($out) >= 10) break;
        }
        return $out;
    }

    private function normalize_weak_points($arr) {
        if (!is_array($arr)) return array();
        $out = array();
        foreach ($arr as $item) {
            if (!is_array($item) || empty($item['issue'])) continue;
            $out[] = array(
                'issue'    => wp_strip_all_tags((string) $item['issue']),
                'severity' => isset($item['severity']) && in_array($item['severity'], array('low', 'medium', 'high'), true) ? $item['severity'] : 'low',
            );
            if (count($out) >= 10) break;
        }
        return $out;
    }

    private function compute_content_hash($messages) {
        $parts = array();
        foreach ($messages as $msg) {
            $u = isset($msg['user_message']) ? trim($msg['user_message']) : '';
            $a = isset($msg['assistant_message']) ? trim($msg['assistant_message']) : '';
            $parts[] = $u . '|' . $a;
        }
        return md5(implode("\n---\n", $parts));
    }

    private function store_analysis($conversation_id, $hash, $parsed) {
        global $wpdb;
        $wpdb->replace(
            self::get_table_name(),
            array(
                'conversation_id'  => $conversation_id,
                'content_hash'     => $hash,
                'title'            => $parsed['title'],
                'summary'          => $parsed['summary'],
                'topics'           => '[]', // deprecated column, kept NOT NULL for back-compat
                'data_gaps'        => wp_json_encode($parsed['data_gaps']),
                'weak_points'      => '[]', // deprecated column, kept NOT NULL for back-compat
                'sentiment'        => $parsed['sentiment'],
                'suggested_action' => $parsed['suggested_action'],
                'model_used'       => $parsed['model_used'],
                'tokens_used'      => $parsed['tokens_used'],
                'analyzed_at'      => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s')
        );
        return (int) $wpdb->insert_id;
    }

    private function get_analysis_by_conversation_id($conversation_id) {
        global $wpdb;
        $table = self::get_table_name();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE conversation_id = %s LIMIT 1",
            $conversation_id
        ), ARRAY_A);
    }

    private function delete_analysis_by_id($id) {
        global $wpdb;
        return $wpdb->delete(self::get_table_name(), array('id' => (int) $id), array('%d'));
    }

    // ============================================================
    // Cost tracking
    // ============================================================

    private function track_cost($raw_response, $model_used) {
        $tokens = isset($raw_response['usage']['total_tokens']) ? intval($raw_response['usage']['total_tokens']) : 0;
        if ($tokens <= 0) {
            return;
        }

        $per_1k   = isset(self::MODEL_COSTS[$model_used]) ? self::MODEL_COSTS[$model_used] : 0.0002;
        $cost_usd = ($tokens / 1000) * $per_1k;

        $history = get_option('aicwp_audit_cost_history', array());
        if (!is_array($history)) $history = array();
        $today = wp_date('Y-m-d');

        $found = false;
        foreach ($history as &$entry) {
            if (isset($entry['date']) && $entry['date'] === $today) {
                $entry['tokens']   = (isset($entry['tokens']) ? $entry['tokens'] : 0) + $tokens;
                $entry['cost_usd'] = (isset($entry['cost_usd']) ? $entry['cost_usd'] : 0) + $cost_usd;
                $entry['count']    = (isset($entry['count']) ? $entry['count'] : 0) + 1;
                $found = true;
                break;
            }
        }
        unset($entry);

        if (!$found) {
            $history[] = array(
                'date'     => $today,
                'tokens'   => $tokens,
                'cost_usd' => $cost_usd,
                'count'    => 1,
            );
        }

        // Keep only last 30 days.
        $cutoff  = strtotime('-30 days');
        $history = array_values(array_filter($history, function($e) use ($cutoff) {
            return isset($e['date']) && strtotime($e['date']) >= $cutoff;
        }));

        update_option('aicwp_audit_cost_history', $history, false);
        update_option('aicwp_audit_last_run', time(), false);
    }

    private function get_cost_disclosure() {
        $history = get_option('aicwp_audit_cost_history', array());
        $tokens  = 0;
        $cost    = 0.0;
        $count   = 0;
        if (is_array($history)) {
            foreach ($history as $entry) {
                $tokens += isset($entry['tokens']) ? intval($entry['tokens']) : 0;
                $cost   += isset($entry['cost_usd']) ? floatval($entry['cost_usd']) : 0.0;
                $count  += isset($entry['count']) ? intval($entry['count']) : 0;
            }
        }
        return array('tokens' => $tokens, 'cost_usd' => $cost, 'count' => $count);
    }

    private function get_dashboard_stats() {
        global $wpdb;
        $table = self::get_table_name();

        // All stats exclude 'skip' rows (trivial conversations stored for dedup only).
        $analyzed     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE suggested_action != 'skip'");
        $positive     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE suggested_action != 'skip' AND sentiment = 'positive'");
        $positive_pct = $analyzed > 0 ? round(($positive / $analyzed) * 100) : 0;

        $gap_rows = $wpdb->get_col("SELECT data_gaps FROM {$table} WHERE suggested_action != 'skip' AND data_gaps != '[]' AND data_gaps != ''");
        $gap_count = 0;
        if ($gap_rows) {
            foreach ($gap_rows as $json) {
                $arr = json_decode($json, true);
                if (is_array($arr)) $gap_count += count($arr);
            }
        }

        return array(
            'analyzed'     => $analyzed,
            'data_gaps'    => $gap_count,
            'positive_pct' => $positive_pct,
            'last_run'     => (int) get_option('aicwp_audit_last_run', 0),
            'next_run'     => wp_next_scheduled(self::CRON_HOOK),
        );
    }

    // ============================================================
    // AJAX handlers
    // ============================================================

    private function verify_ajax() {
        if (!check_ajax_referer('ai_chat_audit_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed.', 'ai-chat-wp')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'ai-chat-wp')));
        }
    }

    public function ajax_analyze_single() {
        $this->verify_ajax();
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $conversation_id = isset($_POST['conversation_id']) ? sanitize_text_field(wp_unslash($_POST['conversation_id'])) : '';
        $force           = !empty($_POST['force']);

        if (!$conversation_id) {
            wp_send_json_error(array('message' => __('Missing conversation id.', 'ai-chat-wp')));
        }

        // force=false: dedup on fresh content (no API call), re-analyze on stale content.
        $result = $this->analyze_conversation($conversation_id, $force);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        $row = $this->get_analysis_by_conversation_id($conversation_id);
        wp_send_json_success(array(
            'id'     => (int) $result,
            'row'    => $row ? $this->format_list_row($row) : null,
            'state'  => 'analyzed',
        ));
    }

    /**
     * Fetch a page of analysis rows with optional filters.
     */
    private function query_list($sentiment = '', $gaps = '', $offset = 0, $limit = 5) {
        global $wpdb;

        $table = self::get_table_name();
        $ch    = self::get_chat_history_table();

        $where  = array("a.suggested_action != 'skip'");
        $params = array();

        if ($sentiment && in_array($sentiment, array('positive', 'negative'), true)) {
            $where[]  = 'a.sentiment = %s';
            $params[] = $sentiment;
        }
        if ($gaps === 'has_gaps') {
            $where[] = "a.data_gaps != '[]' AND a.data_gaps != ''";
        } elseif ($gaps === 'no_gaps') {
            $where[] = "(a.data_gaps = '[]' OR a.data_gaps = '')";
        }

        $where_sql = implode(' AND ', $where);
        $params[]  = $limit;
        $params[]  = $offset;

        $sql = "SELECT a.*, (SELECT COUNT(*) FROM {$ch} WHERE conversation_id = a.conversation_id) AS message_count,
                       (SELECT MAX(created_at) FROM {$ch} WHERE conversation_id = a.conversation_id) AS last_message_at
                FROM {$table} a
                WHERE {$where_sql}
                ORDER BY a.analyzed_at DESC
                LIMIT %d OFFSET %d";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        $count_sql = "SELECT COUNT(*) FROM {$table} a WHERE {$where_sql}";
        $count_params = array_slice($params, 0, count($params) - 2);
        $total = (int) ($count_params ? $wpdb->get_var($wpdb->prepare($count_sql, $count_params)) : $wpdb->get_var($count_sql));

        $formatted = array();
        if ($rows) {
            foreach ($rows as $row) {
                $formatted[] = $this->format_list_row($row);
            }
        }

        $loaded    = $offset + count($formatted);
        $remaining = max(0, $total - $loaded);

        return array(
            'rows'      => $formatted,
            'total'     => $total,
            'remaining' => $remaining,
            'has_more'  => $remaining > 0,
        );
    }

    public function ajax_get_list() {
        $this->verify_ajax();

        $sentiment   = isset($_POST['sentiment']) ? sanitize_text_field(wp_unslash($_POST['sentiment'])) : '';
        $gaps        = isset($_POST['gaps']) ? sanitize_text_field(wp_unslash($_POST['gaps'])) : '';
        $offset      = isset($_POST['offset']) ? max(0, intval($_POST['offset'])) : 0;

        $data = $this->query_list($sentiment, $gaps, $offset);
        $data['stats'] = $this->get_dashboard_stats();

        wp_send_json_success($data);
    }

    private function format_list_row($row) {
        $data_gaps = json_decode($row['data_gaps'], true);
        if (!is_array($data_gaps)) $data_gaps = array();

        // analyzed_at is stored in WP local time via current_time('mysql').
        // Convert through get_gmt_from_date() first so strtotime() produces a
        // correct UTC unix timestamp regardless of the site/server timezone.
        $gmt_datetime  = get_gmt_from_date($row['analyzed_at']);
        $analyzed_ts   = (int) strtotime($gmt_datetime . ' UTC');

        return array(
            'id'               => (int) $row['id'],
            'conversation_id'  => $row['conversation_id'],
            'title'            => $row['title'],
            'sentiment'        => $row['sentiment'],
            'gap_count'        => count($data_gaps),
            'analyzed_at'      => $row['analyzed_at'],
            'analyzed_at_ts'   => $analyzed_ts,
            'message_count'    => isset($row['message_count']) ? (int) $row['message_count'] : 0,
        );
    }

    public function ajax_get_detail() {
        $this->verify_ajax();
        global $wpdb;

        $id              = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $conversation_id = isset($_POST['conversation_id']) ? sanitize_text_field(wp_unslash($_POST['conversation_id'])) : '';

        if (!$id && !$conversation_id) {
            wp_send_json_error(array('message' => __('Missing id.', 'ai-chat-wp')));
        }

        $table = self::get_table_name();
        if ($id) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
        } else {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE conversation_id = %s LIMIT 1", $conversation_id), ARRAY_A);
        }

        if (!$row) {
            wp_send_json_error(array('message' => __('Analysis not found.', 'ai-chat-wp')));
        }

        $data_gaps   = json_decode($row['data_gaps'], true);

        wp_send_json_success(array(
            'id'               => (int) $row['id'],
            'conversation_id'  => $row['conversation_id'],
            'title'            => $row['title'],
            'summary'          => $row['summary'],
            'data_gaps'        => is_array($data_gaps) ? $data_gaps : array(),
            'sentiment'        => $row['sentiment'],
            'suggested_action' => $row['suggested_action'],
            'model_used'       => $row['model_used'],
            'tokens_used'      => (int) $row['tokens_used'],
            'analyzed_at'      => $row['analyzed_at'],
        ));
    }

    public function ajax_delete() {
        $this->verify_ajax();
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if (!$id) {
            wp_send_json_error(array('message' => __('Missing id.', 'ai-chat-wp')));
        }

        $result = $this->delete_analysis_by_id($id);
        if ($result === false) {
            wp_send_json_error(array('message' => __('Delete failed.', 'ai-chat-wp')));
        }

        wp_send_json_success(array('id' => $id));
    }

    public function ajax_backlog_batch() {
        $this->verify_ajax();
        // Extend execution time - AI API calls can take 15-30s per conversation.
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        global $wpdb;

        $range     = isset($_POST['range']) ? sanitize_text_field(wp_unslash($_POST['range'])) : '14d';
        $offset    = isset($_POST['offset']) ? max(0, intval($_POST['offset'])) : 0;
        $batch     = 3; // Small batch for faster progress while keeping UI responsive.

        $ch    = self::get_chat_history_table();
        $table = self::get_table_name();

        $days_map    = array('1d' => 1, '3d' => 3, '7d' => 7, '14d' => 14, '30d' => 30);
        $days        = isset($days_map[$range]) ? $days_map[$range] : 14;
        $date_clause = $wpdb->prepare("ch.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)", $days);

        // Candidates: unique conversation_ids not yet analyzed (trivial ones get
        // stored with suggested_action='skip' so they dedup out of future runs).
        $candidates = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT ch.conversation_id
             FROM {$ch} ch
             LEFT JOIN {$table} a ON a.conversation_id = ch.conversation_id
             WHERE {$date_clause}
               AND a.id IS NULL
             GROUP BY ch.conversation_id
             ORDER BY MAX(ch.created_at) DESC
             LIMIT %d OFFSET %d",
            $batch,
            $offset
        ));

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT ch.conversation_id)
             FROM {$ch} ch
             LEFT JOIN {$table} a ON a.conversation_id = ch.conversation_id
             WHERE {$date_clause}
               AND a.id IS NULL"
        );

        $processed = 0;
        $errors    = array();

        if ($candidates) {
            foreach ($candidates as $cid) {
                $result = $this->analyze_conversation($cid, false);
                if (is_wp_error($result)) {
                    $errors[] = substr($result->get_error_message(), 0, 200);
                } else {
                    $processed++;
                }
            }
        }

        wp_send_json_success(array(
            'processed'   => $processed,
            'total'       => $total,
            'next_offset' => $offset, // LEFT JOIN filter means "next unanalyzed" so offset stays 0-based.
            'has_more'    => count($candidates) > 0 && ($total - $processed) > 0,
            'errors'      => $errors,
        ));
    }

    public function ajax_get_stats() {
        $this->verify_ajax();
        wp_send_json_success($this->get_dashboard_stats());
    }

    public function ajax_clear_all() {
        $this->verify_ajax();
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . self::get_table_name());
        delete_option('aicwp_audit_last_run');
        wp_send_json_success();
    }

    /**
     * AJAX handler: return aggregated data gaps across all conversations.
     * Supports pagination (50 per page) with has_more flag.
     */
    public function ajax_get_items() {
        $this->verify_ajax();

        $type   = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
        $offset = isset($_POST['offset']) ? max(0, intval($_POST['offset'])) : 0;
        $limit  = 50;

        if ($type !== 'gaps') {
            wp_send_json_error(array('message' => __('Invalid type.', 'ai-chat-wp')));
        }

        global $wpdb;
        $table  = self::get_table_name();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, conversation_id, data_gaps FROM {$table} WHERE suggested_action != 'skip' AND data_gaps != '[]' AND data_gaps != '' ORDER BY id DESC LIMIT %d OFFSET %d",
            $limit + 1,
            $offset
        ), ARRAY_A);

        $items    = array();
        $has_more = false;
        $count    = 0;

        foreach ($rows as $row) {
            $arr = json_decode($row['data_gaps'], true);
            if (!is_array($arr)) {
                continue;
            }
            foreach ($arr as $item) {
                if ($count >= $limit) {
                    $has_more = true;
                    break 2;
                }
                $item['analysis_id']     = (int) $row['id'];
                $item['conversation_id'] = $row['conversation_id'];
                $items[] = $item;
                $count++;
            }
        }

        wp_send_json_success(array(
            'items'    => $items,
            'offset'   => $offset + $count,
            'has_more' => $has_more,
        ));
    }

    // ============================================================
    // Cron
    // ============================================================

    public function register_cron_schedules($schedules) {
        if (!isset($schedules['aicwp_audit_12h'])) {
            $schedules['aicwp_audit_12h'] = array(
                'interval' => 12 * HOUR_IN_SECONDS,
                'display'  => __('Every 12 hours (AI audit)', 'ai-chat-wp'),
            );
        }
        if (!isset($schedules['aicwp_audit_24h'])) {
            $schedules['aicwp_audit_24h'] = array(
                'interval' => DAY_IN_SECONDS,
                'display'  => __('Every 24 hours (AI audit)', 'ai-chat-wp'),
            );
        }
        if (!isset($schedules['aicwp_audit_48h'])) {
            $schedules['aicwp_audit_48h'] = array(
                'interval' => 2 * DAY_IN_SECONDS,
                'display'  => __('Every 48 hours (AI audit)', 'ai-chat-wp'),
            );
        }
        return $schedules;
    }

    public function maybe_schedule_crons() {
        // Skip during cron runs - we're already inside one.
        if (wp_doing_cron()) {
            return;
        }
        $this->sync_cron_schedule();
    }

    private function sync_cron_schedule() {
        if (!get_option('aicwp_audit_enabled', 0)) {
            $this->clear_crons();
            return;
        }

        $interval      = (string) get_option('aicwp_audit_interval', '24');
        $schedule_name = 'aicwp_audit_' . $interval . 'h';

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 300, $schedule_name, self::CRON_HOOK);
        }
    }

    private function clear_crons() {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        while ($ts) {
            wp_unschedule_event($ts, self::CRON_HOOK);
            $ts = wp_next_scheduled(self::CRON_HOOK);
        }
    }

    public function on_settings_change($old, $new) {
        // Clear and re-sync immediately, even during AJAX save.
        $this->clear_crons();
        $this->sync_cron_schedule();
    }

    public function cron_run_analysis() {
        if (!get_option('aicwp_audit_enabled', 0)) return;
        if (!$this->is_chat_history_enabled()) return;

        global $wpdb;
        $ch    = self::get_chat_history_table();
        $table = self::get_table_name();

        // Only process conversations from the last 24 hours. Older conversations
        // are handled via the manual "Analyze past conversations" in Configure.
        $candidates = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT ch.conversation_id
             FROM {$ch} ch
             LEFT JOIN {$table} a ON a.conversation_id = ch.conversation_id
             WHERE a.id IS NULL
               AND ch.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
             GROUP BY ch.conversation_id
             ORDER BY MAX(ch.created_at) DESC
             LIMIT %d",
            self::CRON_BATCH_LIMIT
        ));

        if (!$candidates) {
            update_option('aicwp_audit_last_run', time(), false);
            return;
        }

        // Extend execution time defensively.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        foreach ($candidates as $cid) {
            $this->analyze_conversation($cid, false);
        }

        update_option('aicwp_audit_last_run', time(), false);
    }

}
