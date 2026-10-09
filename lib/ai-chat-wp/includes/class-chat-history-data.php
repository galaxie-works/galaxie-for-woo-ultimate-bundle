<?php
/**
 * AI Chat for WordPress - Chat History Data Provider
 *
 * Provides actual data retrieval for chat history.
 * The free plugin only has empty filter returns - this class provides the real queries.
 *
 * @package AICWP
 * @since 1.7.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Chat_History_Data {

    /**
     * Constructor
     */
    public function __construct() {
        // Hook into all chat history data filters
        add_filter('aicwp_chat_history_stats', array($this, 'get_stats'), 10, 2);
        add_filter('aicwp_chat_history_stats_today', array($this, 'get_stats_today'), 10, 1);
        add_filter('aicwp_chat_history_recent_conversations', array($this, 'get_recent_conversations'), 10, 3);
        add_filter('aicwp_chat_history_conversation', array($this, 'get_conversation'), 10, 2);
        add_filter('aicwp_chat_history_popular_questions', array($this, 'get_popular_questions'), 10, 3);
        add_filter('aicwp_chat_history_all_records', array($this, 'get_all_records'), 10, 2);
    }

    /**
     * Get table name
     *
     * @return string
     */
    private function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'aicwp_chat_history';
    }

    /**
     * Get chat history statistics
     *
     * @param array $default Default empty stats
     * @param int $days Number of days
     * @return array Statistics data
     */
    public function get_stats($default, $days) {
        global $wpdb;
        $table_name = $this->get_table_name();
        $date_from = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        // Total conversations (unique conversation_ids)
        $total_conversations = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT conversation_id) FROM {$table_name} WHERE created_at >= %s",
            $date_from
        ));

        // Total user messages (each row = 1 user message + 1 AI response)
        $total_messages = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE created_at >= %s",
            $date_from
        ));

        // Average user messages per conversation
        $avg_per_conversation = $total_conversations > 0 ? round($total_messages / $total_conversations, 1) : 0;

        // Registered users vs guests
        $registered_users = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT conversation_id) FROM {$table_name} WHERE user_id IS NOT NULL AND created_at >= %s",
            $date_from
        ));

        $guest_users = $total_conversations - $registered_users;

        return array(
            'total_conversations' => intval($total_conversations),
            'total_messages' => intval($total_messages),
            'avg_per_conversation' => floatval($avg_per_conversation),
            'registered_users' => intval($registered_users),
            'guest_users' => intval($guest_users)
        );
    }

    /**
     * Get chat history statistics for today (from midnight)
     *
     * @param array $default Default empty stats
     * @return array Statistics data for today
     */
    public function get_stats_today($default) {
        global $wpdb;
        $table_name = $this->get_table_name();
        $today_midnight = date('Y-m-d 00:00:00');

        // Total conversations (unique conversation_ids) today
        $total_conversations = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT conversation_id) FROM {$table_name} WHERE created_at >= %s",
            $today_midnight
        ));

        // Total user messages today (each row = 1 user message)
        $total_messages = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE created_at >= %s",
            $today_midnight
        ));

        // Average user messages per conversation
        $avg_per_conversation = $total_conversations > 0 ? round($total_messages / $total_conversations, 1) : 0;

        return array(
            'total_conversations' => intval($total_conversations),
            'total_messages' => intval($total_messages),
            'avg_per_conversation' => floatval($avg_per_conversation)
        );
    }

    /**
     * Get recent conversations
     *
     * @param array $default Default empty array
     * @param int $limit Number of conversations
     * @param int $offset Pagination offset
     * @return array Array of conversation data
     */
    public function get_recent_conversations($default, $limit, $offset) {
        global $wpdb;
        $table_name = $this->get_table_name();

        // Include channel column if it exists (added by messaging migrations)
        $has_channel = AICWP_Messaging_Migrations::has_column('channel');
        $channel_select = $has_channel ? ", MAX(channel) as channel" : "";

        $conversations = $wpdb->get_results($wpdb->prepare(
            "SELECT
                conversation_id,
                MIN(created_at) as first_message_at,
                MAX(created_at) as last_message_at,
                COUNT(*) as message_count,
                MAX(user_id) as user_id,
                MAX(ip_address) as ip_address
                {$channel_select}
            FROM {$table_name}
            GROUP BY conversation_id
            ORDER BY last_message_at DESC
            LIMIT %d OFFSET %d",
            $limit,
            $offset
        ), ARRAY_A);

        return $conversations ? $conversations : array();
    }

    /**
     * Get all messages in a specific conversation
     *
     * @param array $default Default empty array
     * @param string $conversation_id Conversation identifier
     * @return array Array of messages
     */
    public function get_conversation($default, $conversation_id) {
        global $wpdb;
        $table_name = $this->get_table_name();

        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE conversation_id = %s ORDER BY created_at ASC",
            $conversation_id
        ), ARRAY_A);

        return $messages ? $messages : array();
    }

    /**
     * Get popular user questions
     *
     * @param array $default Default empty array
     * @param int $limit Number of questions
     * @param int $days Days to look back
     * @return array Array of popular questions with counts
     */
    public function get_popular_questions($default, $limit, $days) {
        global $wpdb;
        $table_name = $this->get_table_name();
        $date_from = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $questions = $wpdb->get_results($wpdb->prepare(
            "SELECT user_message, COUNT(*) as count
            FROM {$table_name}
            WHERE created_at >= %s
            GROUP BY user_message
            ORDER BY count DESC
            LIMIT %d",
            $date_from,
            $limit
        ), ARRAY_A);

        return $questions ? $questions : array();
    }

    /**
     * Get all records for export
     *
     * @param array $default Default empty array
     * @param int|null $days Optional days limit
     * @return array All chat history records
     */
    public function get_all_records($default, $days) {
        global $wpdb;
        $table_name = $this->get_table_name();

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") != $table_name) {
            return array();
        }

        if ($days !== null) {
            $date_from = date('Y-m-d H:i:s', strtotime("-{$days} days"));
            $records = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE created_at >= %s ORDER BY created_at DESC",
                $date_from
            ), ARRAY_A);
        } else {
            $records = $wpdb->get_results(
                "SELECT * FROM {$table_name} ORDER BY created_at DESC",
                ARRAY_A
            );
        }

        return $records ? $records : array();
    }
}
