<?php
/**
 * AI Chat for WordPress - Chat History Chart
 *
 * Displays a visual graph for chat history showing conversations and messages
 * over the last 30 days.
 *
 * @package AICWP
 * @since 1.7.5
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Chat_History_Chart {

    /**
     * Cached stats to avoid duplicate database queries
     *
     * @var array|null
     */
    private $cached_stats = null;

    /**
     * Constructor
     */
    public function __construct() {
        // Hook into the new standalone chart card location (right column)
        add_action('aicwp_render_chart_card', array($this, 'render_chart_content'));

        // Legacy hook for backwards compatibility (no longer used in main plugin)
        add_action('aicwp_after_chat_history_stats', array($this, 'render_chart'));

        // Enqueue assets
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

        // AJAX endpoint for chart data
        add_action('wp_ajax_aicwp_get_chart_data', array($this, 'ajax_get_chart_data'));

        // Create/update monthly stats table once per version
        $table_version = get_option('aicwp_monthly_stats_table_version');
        if ($table_version !== '1.0') {
            $this->create_monthly_stats_table();
            update_option('aicwp_monthly_stats_table_version', '1.0', false);
        }

        // Register custom monthly cron schedule
        add_filter('cron_schedules', array($this, 'add_monthly_cron_schedule'));

        // Aggregate before cleanup runs (priority 5, cleanup is default 10)
        add_action('aicwp_cleanup_chat_history', array($this, 'aggregate_monthly_stats'), 5);

        // Also run on its own monthly schedule
        add_action('aicwp_aggregate_monthly_stats', array($this, 'aggregate_monthly_stats'));
        if (!wp_next_scheduled('aicwp_aggregate_monthly_stats')) {
            wp_schedule_event(time(), 'aicwp_ui_monthly', 'aicwp_aggregate_monthly_stats');
        }
    }

    /**
     * Register custom monthly cron schedule
     *
     * @param array $schedules Existing cron schedules
     * @return array
     */
    public function add_monthly_cron_schedule($schedules) {
        $schedules['aicwp_ui_monthly'] = array(
            'interval' => 30 * DAY_IN_SECONDS,
            'display'  => __('Once Monthly', 'ai-chat-wp'),
        );
        return $schedules;
    }

    /**
     * Get chat history table name
     *
     * @return string
     */
    private function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'aicwp_chat_history';
    }

    /**
     * Get contact messages table name
     *
     * @return string
     */
    private function get_contact_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'aicwp_contact_messages';
    }

    /**
     * Get daily stats for the last 30 days (backward compat wrapper)
     *
     * @return array Array with dates as keys and conversation/message counts
     */
    public function get_daily_stats() {
        return $this->get_stats_data('month');
    }

    /**
     * Get chart stats by period
     *
     * @param string $period 'month' (last 30 days, daily) or 'year' (current year, monthly)
     * @return array Array with labels, conversations, messages, emails
     */
    public function get_stats_data($period = 'month') {
        $cache_key = 'stats_' . $period;
        if ($this->cached_stats !== null && isset($this->cached_stats[$cache_key])) {
            return $this->cached_stats[$cache_key];
        }

        if (!current_user_can('manage_options')) {
            return array();
        }

        global $wpdb;
        $table_name = $this->get_table_name();

        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $wpdb->esc_like($table_name)
            )
        );
        if ($table_exists !== $table_name) {
            return array();
        }

        if ($period === 'year') {
            return $this->get_yearly_stats();
        }

        return $this->get_monthly_stats($table_name);
    }

    /**
     * Get daily stats for the last 30 days
     *
     * @param string $table_name Chat history table name
     * @return array
     */
    private function get_monthly_stats($table_name) {
        global $wpdb;

        $date_from = wp_date('Y-m-d', strtotime('-29 days'));

        $stats_query = $wpdb->get_results($wpdb->prepare(
            "SELECT
                DATE(created_at) as date,
                COUNT(DISTINCT conversation_id) as conversation_count,
                COUNT(*) as message_count
            FROM {$table_name}
            WHERE DATE(created_at) >= %s
            GROUP BY DATE(created_at)
            ORDER BY date ASC",
            $date_from
        ), ARRAY_A);

        $conversations_by_date = array();
        $messages_by_date = array();
        foreach ($stats_query as $row) {
            $conversations_by_date[$row['date']] = intval($row['conversation_count']);
            $messages_by_date[$row['date']] = intval($row['message_count']);
        }

        // Emails per day
        $emails_by_date = $this->get_emails_daily($date_from);

        // Build 30-day array
        $labels = array();
        $conversations = array();
        $messages = array();
        $emails = array();

        for ($i = 29; $i >= 0; $i--) {
            $date = wp_date('Y-m-d', strtotime("-{$i} days"));
            $labels[] = date_i18n('M j', strtotime($date));
            $conversations[] = isset($conversations_by_date[$date]) ? $conversations_by_date[$date] : 0;
            $messages[] = isset($messages_by_date[$date]) ? $messages_by_date[$date] : 0;
            $emails[] = isset($emails_by_date[$date]) ? $emails_by_date[$date] : 0;
        }

        $result = array(
            'labels' => $labels,
            'conversations' => $conversations,
            'messages' => $messages,
            'emails' => $emails,
        );

        if ($this->cached_stats === null) {
            $this->cached_stats = array();
        }
        $this->cached_stats['stats_month'] = $result;

        return $result;
    }

    /**
     * Get monthly stats for the current year from pre-aggregated table
     *
     * @return array
     */
    private function get_yearly_stats() {
        global $wpdb;

        $year = wp_date('Y');
        $year_start = $year . '-01';
        $stats_table = $this->get_monthly_stats_table_name();

        // Read from pre-aggregated monthly stats
        $stats_query = $wpdb->get_results($wpdb->prepare(
            "SELECT month, conversations, messages, emails
            FROM {$stats_table}
            WHERE month >= %s
            ORDER BY month ASC",
            $year_start
        ), ARRAY_A);

        $conversations_by_month = array();
        $messages_by_month = array();
        $emails_by_month = array();
        foreach ($stats_query as $row) {
            $conversations_by_month[$row['month']] = intval($row['conversations']);
            $messages_by_month[$row['month']] = intval($row['messages']);
            $emails_by_month[$row['month']] = intval($row['emails']);
        }

        // Build 12-month array (Jan through current month)
        $labels = array();
        $conversations = array();
        $messages = array();
        $emails = array();

        $current_month = intval(wp_date('n'));
        for ($m = 1; $m <= $current_month; $m++) {
            $month_key = $year . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
            $labels[] = date_i18n('M', mktime(0, 0, 0, $m, 1));
            $conversations[] = isset($conversations_by_month[$month_key]) ? $conversations_by_month[$month_key] : 0;
            $messages[] = isset($messages_by_month[$month_key]) ? $messages_by_month[$month_key] : 0;
            $emails[] = isset($emails_by_month[$month_key]) ? $emails_by_month[$month_key] : 0;
        }

        $result = array(
            'labels' => $labels,
            'conversations' => $conversations,
            'messages' => $messages,
            'emails' => $emails,
        );

        if ($this->cached_stats === null) {
            $this->cached_stats = array();
        }
        $this->cached_stats['stats_year'] = $result;

        return $result;
    }

    /**
     * Get daily email counts from contact messages table
     *
     * @param string $date_from Y-m-d start date
     * @return array Keyed by Y-m-d date
     */
    private function get_emails_daily($date_from) {
        global $wpdb;
        $contact_table = $this->get_contact_table_name();
        $contact_table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $wpdb->esc_like($contact_table)
            )
        );

        if ($contact_table_exists !== $contact_table) {
            return array();
        }

        $emails_query = $wpdb->get_results($wpdb->prepare(
            "SELECT
                DATE(created_at) as date,
                COUNT(*) as email_count
            FROM {$contact_table}
            WHERE DATE(created_at) >= %s AND email_sent = 1
            GROUP BY DATE(created_at)
            ORDER BY date ASC",
            $date_from
        ), ARRAY_A);

        $emails_by_date = array();
        foreach ($emails_query as $row) {
            $emails_by_date[$row['date']] = intval($row['email_count']);
        }

        return $emails_by_date;
    }

    /**
     * Get monthly stats table name
     *
     * @return string
     */
    private function get_monthly_stats_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'aicwp_monthly_stats';
    }

    /**
     * Create the monthly stats aggregation table
     * Safe to call repeatedly - dbDelta is idempotent
     */
    private function create_monthly_stats_table() {
        global $wpdb;
        $table_name = $this->get_monthly_stats_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            month char(7) NOT NULL,
            conversations int unsigned NOT NULL DEFAULT 0,
            messages int unsigned NOT NULL DEFAULT 0,
            emails int unsigned NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY month (month)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Aggregate current (and previous) month stats into the monthly_stats table.
     * Hooked to run before chat history cleanup (priority 5) and on its own monthly schedule.
     */
    public function aggregate_monthly_stats() {
        global $wpdb;

        $chat_table = $this->get_table_name();
        $contact_table = $this->get_contact_table_name();
        $stats_table = $this->get_monthly_stats_table_name();

        // Ensure table exists
        $this->create_monthly_stats_table();

        // Aggregate current month and previous month (in case previous wasn't captured)
        $months = array(
            wp_date('Y-m'),
            wp_date('Y-m', strtotime('first day of previous month')),
        );

        foreach ($months as $month) {
            $month_start = $month . '-01';
            // Calculate first day of next month for range
            $month_end = wp_date('Y-m-d', strtotime($month_start . ' +1 month'));

            // Conversations and messages
            $chat_stats = $wpdb->get_row($wpdb->prepare(
                "SELECT
                    COUNT(DISTINCT conversation_id) as conversation_count,
                    COUNT(*) as message_count
                FROM {$chat_table}
                WHERE created_at >= %s AND created_at < %s",
                $month_start,
                $month_end
            ), ARRAY_A);

            $conversations = intval($chat_stats['conversation_count'] ?? 0);
            $messages = intval($chat_stats['message_count'] ?? 0);

            // Emails
            $emails = 0;
            $contact_exists = $wpdb->get_var(
                $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($contact_table))
            );
            if ($contact_exists === $contact_table) {
                $emails = intval($wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$contact_table}
                    WHERE created_at >= %s AND created_at < %s AND email_sent = 1",
                    $month_start,
                    $month_end
                )));
            }

            // Upsert
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$stats_table} (month, conversations, messages, emails, updated_at)
                VALUES (%s, %d, %d, %d, %s)
                ON DUPLICATE KEY UPDATE
                    conversations = VALUES(conversations),
                    messages = VALUES(messages),
                    emails = VALUES(emails),
                    updated_at = VALUES(updated_at)",
                $month,
                $conversations,
                $messages,
                $emails,
                current_time('mysql')
            ));
        }
    }

    /**
     * Enqueue assets for the chart
     *
     * @param string $hook Current admin page hook
     */
    public function enqueue_assets($hook) {
        // Only load on AI Chat for WordPress admin page
        if ($hook !== 'toplevel_page_ai-chat-wp') {
            return;
        }

        // Only load on stats tab (where Chat History section is displayed)
        $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'settings';
        if ($active_tab !== 'stats') {
            return;
        }

        // Enqueue Chart.js from CDN (use unique handle to avoid conflicts)
        wp_enqueue_script(
            'ai-chat-chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
            array(),
            '4.4.1',
            true
        );

        // Enqueue our custom CSS
        wp_enqueue_style(
            'aicwp-history-chart',
            AICWP_PLUGIN_URL . 'assets/css/chat-history-chart.css',
            array(),
            AICWP_VERSION
        );

        // Enqueue our custom JS (depends on Chart.js)
        wp_enqueue_script(
            'aicwp-history-chart',
            AICWP_PLUGIN_URL . 'assets/js/chat-history-chart.js',
            array('jquery', 'ai-chat-chartjs'),
            AICWP_VERSION,
            true
        );

        // Get chart data
        $chart_data = $this->get_daily_stats();

        // Only localize if we have data
        if (empty($chart_data)) {
            return;
        }

        // Calculate if emails should be shown (only if total > 0)
        $emails_data = isset($chart_data['emails']) ? $chart_data['emails'] : array();
        $show_emails = array_sum($emails_data) > 0;

        // Localize script with AJAX config and initial chart data
        wp_localize_script('aicwp-history-chart', 'aiChatHistoryChartData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('aicwp_ui_chart_nonce'),
            'labels' => isset($chart_data['labels']) ? $chart_data['labels'] : array(),
            'conversations' => isset($chart_data['conversations']) ? $chart_data['conversations'] : array(),
            'messages' => isset($chart_data['messages']) ? $chart_data['messages'] : array(),
            'emails' => $emails_data,
            'showEmails' => $show_emails,
            'strings' => array(
                'conversations' => __('Conversations', 'ai-chat-wp'),
                'messages' => __('Messages', 'ai-chat-wp'),
                'emails' => __('Emails Sent', 'ai-chat-wp'),
            ),
        ));
    }

    /**
     * AJAX handler for fetching chart data by period
     */
    public function ajax_get_chart_data() {
        check_ajax_referer('aicwp_ui_chart_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'ai-chat-wp')));
        }

        $period = isset($_POST['period']) ? sanitize_text_field(wp_unslash($_POST['period'])) : 'month';
        if (!in_array($period, array('month', 'year'), true)) {
            $period = 'month';
        }

        $chart_data = $this->get_stats_data($period);

        if (empty($chart_data)) {
            wp_send_json_success(array(
                'labels' => array(),
                'conversations' => array(),
                'messages' => array(),
                'emails' => array(),
                'showEmails' => false,
            ));
        }

        $emails_data = isset($chart_data['emails']) ? $chart_data['emails'] : array();
        wp_send_json_success(array(
            'labels' => $chart_data['labels'],
            'conversations' => $chart_data['conversations'],
            'messages' => $chart_data['messages'],
            'emails' => $emails_data,
            'showEmails' => array_sum($emails_data) > 0,
        ));
    }

    /**
     * Render just the chart content (for standalone card in right column)
     * Used by the new aicwp_render_chart_card hook
     */
    public function render_chart_content() {
        // Verify user has permission to view statistics
        if (!current_user_can('manage_options')) {
            return;
        }

        // Get chart data to check if there's any data (uses cached result)
        $chart_data = $this->get_daily_stats();
        $has_data = !empty($chart_data['conversations']) && array_sum($chart_data['conversations']) > 0;
        $show_emails = !empty($chart_data['emails']) && array_sum($chart_data['emails']) > 0;
        ?>
        <div class="aicwp-ui-chart-container" style="min-height: 200px;">
            <?php if ($has_data): ?>
            <div class="aicwp-ui-chart-toolbar">
                <span class="aicwp-ui-position-toggle aicwp-ui-chart-period-toggle">
                    <button type="button" class="aicwp-ui-position-btn active" data-period="month"><?php esc_html_e('This month', 'ai-chat-wp'); ?></button>
                    <button type="button" class="aicwp-ui-position-btn" data-period="year"><?php esc_html_e('This year', 'ai-chat-wp'); ?></button>
                </span>
                <div class="aicwp-ui-chart-legend">
                    <span class="aicwp-ui-legend-item aicwp-ui-legend-conversations">
                        <span class="aicwp-ui-legend-color"></span>
                        <?php esc_html_e('Conversations', 'ai-chat-wp'); ?>
                    </span>
                    <span class="aicwp-ui-legend-item aicwp-ui-legend-messages">
                        <span class="aicwp-ui-legend-color"></span>
                        <?php esc_html_e('Messages', 'ai-chat-wp'); ?>
                    </span>
                    <?php if ($show_emails): ?>
                    <span class="aicwp-ui-legend-item aicwp-ui-legend-emails">
                        <span class="aicwp-ui-legend-color"></span>
                        <?php esc_html_e('Emails Sent', 'ai-chat-wp'); ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <canvas id="aicwp-ui-chat-history-chart"></canvas>
            <?php else: ?>
            <div class="aicwp-ui-chart-no-data" style="text-align: center; padding: 40px 20px; color: #666;">
                <p><?php esc_html_e('No activity data to display yet. The chart will appear once conversations are recorded.', 'ai-chat-wp'); ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the chart HTML (legacy - full wrapper)
     */
    public function render_chart() {
        // Verify user has permission to view statistics
        if (!current_user_can('manage_options')) {
            return;
        }

        // Get chart data to check if there's any data (uses cached result)
        $chart_data = $this->get_daily_stats();
        $has_data = !empty($chart_data['conversations']) && array_sum($chart_data['conversations']) > 0;
        $show_emails = !empty($chart_data['emails']) && array_sum($chart_data['emails']) > 0;
        ?>
        <div class="aicwp-ui-chat-history-chart-wrapper">
            <div class="aicwp-ui-chart-header">
                <h4><?php esc_html_e('Chat Activity', 'ai-chat-wp'); ?></h4>
                <div class="aicwp-ui-chart-legend">
                    <span class="aicwp-ui-legend-item aicwp-ui-legend-conversations">
                        <span class="aicwp-ui-legend-color"></span>
                        <?php esc_html_e('Conversations', 'ai-chat-wp'); ?>
                    </span>
                    <span class="aicwp-ui-legend-item aicwp-ui-legend-messages">
                        <span class="aicwp-ui-legend-color"></span>
                        <?php esc_html_e('Messages', 'ai-chat-wp'); ?>
                    </span>
                    <?php if ($show_emails): ?>
                    <span class="aicwp-ui-legend-item aicwp-ui-legend-emails">
                        <span class="aicwp-ui-legend-color"></span>
                        <?php esc_html_e('Emails Sent', 'ai-chat-wp'); ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="aicwp-ui-chart-container">
                <?php if ($has_data): ?>
                <canvas id="aicwp-ui-chat-history-chart"></canvas>
                <?php else: ?>
                <div class="aicwp-ui-chart-no-data">
                    <p><?php esc_html_e('No activity data to display yet. The chart will appear once conversations are recorded.', 'ai-chat-wp'); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
