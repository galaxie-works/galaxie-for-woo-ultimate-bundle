<?php
/**
 * AI Chat for WordPress Chat History Manager
 *
 * Handles storage and retrieval of chat conversations
 * Tracks user questions and AI responses for analytics and training
 *
 * @package AICWP_Plugin
 * @since 1.3
 */

if (!defined('ABSPATH')) exit;

// Only declare if not already loaded
if (!class_exists('AICWP_Chat_History')) :

class AICWP_Chat_History {

    /**
     * Database table name
     */
    private static $table_name = 'aicwp_chat_history';

    /**
     * Detect if the database is MariaDB
     *
     * MariaDB has stricter UTF-8 handling in some configurations
     * and may fail on 4-byte UTF-8 characters (emojis) even with utf8mb4
     *
     * @return bool True if MariaDB, false if MySQL
     */
    public static function is_mariadb() {
        static $is_mariadb = null;

        if ($is_mariadb !== null) {
            return $is_mariadb;
        }

        global $wpdb;
        $db_version = $wpdb->get_var("SELECT VERSION()");
        $is_mariadb = (stripos($db_version, 'mariadb') !== false);

        return $is_mariadb;
    }

    /**
     * Sanitize text for database storage
     *
     * Applies different sanitization based on database type:
     * - MariaDB: Aggressive sanitization (strip emojis, 4-byte UTF-8, control chars)
     * - MySQL: Minimal sanitization (just remove NULL bytes, preserve emojis)
     *
     * @param string $text Text to sanitize
     * @return string Sanitized text safe for database storage
     */
    public static function sanitize_for_db($text) {
        if (empty($text)) {
            return $text;
        }

        if (self::is_mariadb()) {
            // MariaDB: Aggressive sanitization
            // Remove NULL bytes
            $text = str_replace("\0", '', $text);

            // Remove control characters except newlines and tabs
            $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

            // Remove 4-byte UTF-8 characters (emojis, rare symbols)
            // These can cause issues in MariaDB even with utf8mb4
            $text = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $text);

            // Also remove supplementary multilingual plane characters that may cause issues
            $text = preg_replace('/[\x{1F300}-\x{1F9FF}]/u', '', $text); // Misc Symbols & Pictographs, Emoticons, etc.
            $text = preg_replace('/[\x{2600}-\x{26FF}]/u', '', $text);   // Misc symbols
            $text = preg_replace('/[\x{2700}-\x{27BF}]/u', '', $text);   // Dingbats

        } else {
            // MySQL: Minimal sanitization - just remove NULL bytes
            $text = str_replace("\0", '', $text);
        }

        return $text;
    }

    /**
     * Get full table name with WordPress prefix
     *
     * @return string
     */
    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::$table_name;
    }

    /**
     * Create chat history database table
     * Called on first enable or plugin activation
     */
    public static function create_table() {
        global $wpdb;

        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        // Use LONGTEXT for message columns to handle large AI responses
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            conversation_id varchar(50) NOT NULL,
            session_id varchar(100) NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            page_url varchar(500) DEFAULT NULL,
            user_message longtext NOT NULL,
            assistant_message longtext NOT NULL,
            model_used varchar(50) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY conversation_id (conversation_id),
            KEY session_id (session_id),
            KEY user_id (user_id),
            KEY ip_address (ip_address),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Verify table was created
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") != $table_name) {
            error_log('AI Chat for WordPress: Failed to create chat history table');
            return false;
        }

        // Auto-upgrade existing TEXT columns to LONGTEXT
        self::maybe_upgrade_columns();

        return true;
    }

    /**
     * Upgrade existing TEXT columns to LONGTEXT and add new columns
     * Called automatically when create_table() runs
     * Safe to run multiple times - only upgrades if needed
     */
    public static function maybe_upgrade_columns() {
        global $wpdb;

        $table_name = self::get_table_name();

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") != $table_name) {
            return false;
        }

        // Get current column types
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table_name}");
        $column_names = wp_list_pluck($columns, 'Field');

        foreach ($columns as $column) {
            // Check user_message and assistant_message columns
            if (in_array($column->Field, array('user_message', 'assistant_message'))) {
                // If column is TEXT (not LONGTEXT), upgrade it
                if (stripos($column->Type, 'longtext') === false && stripos($column->Type, 'text') !== false) {
                    $wpdb->query("ALTER TABLE {$table_name} MODIFY {$column->Field} LONGTEXT NOT NULL");
                    error_log("AI Chat for WordPress: Upgraded {$column->Field} column to LONGTEXT");
                }
            }
        }

        // Add ip_address column if it doesn't exist
        if (!in_array('ip_address', $column_names)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN ip_address varchar(45) DEFAULT NULL AFTER user_id");
            $wpdb->query("ALTER TABLE {$table_name} ADD KEY ip_address (ip_address)");
            error_log("AI Chat for WordPress: Added ip_address column to chat history table");
        }

        // Add page_url column if it doesn't exist (tracks which page chat was used on)
        if (!in_array('page_url', $column_names)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN page_url varchar(500) DEFAULT NULL AFTER ip_address");
            error_log("AI Chat for WordPress: Added page_url column to chat history table");
        }

        return true;
    }

    /**
     * Check if ip_address column exists in table
     * Result is cached for performance
     *
     * @return bool
     */
    public static function has_ip_column() {
        static $has_column = null;

        if ($has_column !== null) {
            return $has_column;
        }

        global $wpdb;
        $table_name = self::get_table_name();

        // Check if table exists first
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") != $table_name) {
            $has_column = false;
            return $has_column;
        }

        // Check for ip_address column
        $column = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'ip_address'");
        $has_column = !empty($column);

        return $has_column;
    }

    /**
     * Check if page_url column exists in table
     * Result is cached for performance
     *
     * @return bool
     */
    public static function has_page_url_column() {
        static $has_column = null;

        if ($has_column !== null) {
            return $has_column;
        }

        global $wpdb;
        $table_name = self::get_table_name();

        // Check if table exists first
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") != $table_name) {
            $has_column = false;
            return $has_column;
        }

        // Check for page_url column
        $column = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'page_url'");
        $has_column = !empty($column);

        return $has_column;
    }

    /**
     * Get geolocation data from IP address (cached)
     *
     * @param string $ip IP address
     * @return array|null Array with 'country_code', 'country_name', 'city', 'region', 'continent' or null
     */
    public static function get_country_from_ip($ip) {
        // Skip for localhost
        if (in_array($ip, array('127.0.0.1', '::1', 'localhost'))) {
            return null;
        }

        // Check cache first
        $cache_key = 'aicwp_ip_geo_' . md5($ip);
        $geo = get_transient($cache_key);
        if ($geo !== false) {
            return !empty($geo['country_code']) ? $geo : null;
        }

        // Use freeipapi.com (free, HTTPS, no key required, 60 requests/minute)
        $response = wp_remote_get("https://free.freeipapi.com/api/json/{$ip}", array(
            'timeout' => 3,
            'sslverify' => true
        ));

        if (is_wp_error($response)) {
            set_transient($cache_key, array('country_code' => ''), HOUR_IN_SECONDS);
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        $geo = array(
            'country_code' => isset($body['countryCode']) ? strtolower($body['countryCode']) : '',
            'country_name' => isset($body['countryName']) ? $body['countryName'] : '',
            'city'         => isset($body['cityName']) ? $body['cityName'] : '',
            'region'       => isset($body['regionName']) ? $body['regionName'] : '',
            'continent'    => isset($body['continent']) ? $body['continent'] : '',
        );

        // Cache for 30 days - IP geolocation rarely changes
        set_transient($cache_key, $geo, MONTH_IN_SECONDS);

        return !empty($geo['country_code']) ? $geo : null;
    }

    /**
     * Save a chat exchange (user question + AI answer)
     *
     * @param string $session_id Browser session identifier
     * @param string $user_message User's question
     * @param string $assistant_message AI's text answer
     * @param string $model_used OpenAI model name
     * @param int|null $user_id WordPress user ID (NULL for guests)
     * @param string|null $page_url URL of the page where chat occurred (optional, for analytics)
     * @return int|false Insert ID on success, false on failure
     */
    public static function save_exchange($session_id, $user_message, $assistant_message, $model_used, $user_id = null, $page_url = null) {
        global $wpdb;

        // Check if chat history tracking is enabled
        if (!get_option('aicwp_chat_history_enabled', 0)) {
            return false;
        }

        // Validate required parameters
        if (empty($session_id) || empty($user_message) || empty($assistant_message) || empty($model_used)) {
            error_log('AI Chat History: Missing required parameters');
            return false;
        }

        $table_name = self::get_table_name();

        // Use session_id as conversation_id for grouping
        $conversation_id = $session_id;

        // Get current user ID if not provided
        if ($user_id === null && is_user_logged_in()) {
            $user_id = get_current_user_id();
        }

        // Handle multimodal content (array) - extract text for storage
        if (is_array($user_message)) {
            $text_parts = array();
            foreach ($user_message as $part) {
                if (isset($part['type']) && $part['type'] === 'text' && isset($part['text'])) {
                    $text_parts[] = $part['text'];
                } elseif (isset($part['type']) && $part['type'] === 'image_url') {
                    $text_parts[] = '[Image attached]';
                }
            }
            $user_message = implode(' ', $text_parts);
        }

        // Sanitize messages for database storage (handles MariaDB/MySQL differences)
        $sanitized_user_message = self::sanitize_for_db(wp_kses_post($user_message));
        $sanitized_assistant_message = self::sanitize_for_db(wp_kses_post($assistant_message));

        // SECURITY: Limit stored message length to prevent database bloat from input spam
        $max_log_length = 2000;
        if (mb_strlen($sanitized_user_message) > $max_log_length) {
            $sanitized_user_message = mb_substr($sanitized_user_message, 0, $max_log_length) . '... [truncated]';
        }

        // Build insert data array
        $insert_data = array(
            'conversation_id' => $conversation_id,
            'session_id' => $session_id,
            'user_id' => $user_id,
            'user_message' => $sanitized_user_message,
            'assistant_message' => $sanitized_assistant_message,
            'model_used' => sanitize_text_field($model_used),
            'created_at' => current_time('mysql')
        );
        $insert_format = array('%s', '%s', '%d', '%s', '%s', '%s', '%s');

        // Add IP address if column exists (backward compatibility)
        if (self::has_ip_column()) {
            $insert_data['ip_address'] = AICWP_Utility_Helper::get_client_ip_secure();
            $insert_format[] = '%s';
        }

        // Add page URL if provided and column exists (tracks which page chat was used on)
        // XSS Protection: esc_url_raw() sanitizes the URL, removing javascript: and other dangerous protocols
        if (!empty($page_url) && self::has_page_url_column()) {
            // Sanitize URL - removes javascript:, data:, and other dangerous protocols
            $sanitized_url = esc_url_raw($page_url);
            // Additional validation: must be a valid URL format
            if (filter_var($sanitized_url, FILTER_VALIDATE_URL)) {
                // Limit length to prevent database issues
                $insert_data['page_url'] = mb_substr($sanitized_url, 0, 500);
                $insert_format[] = '%s';
            }
        }

        // Allow extensions to add extra fields (e.g., channel, phone_hash, external_id)
        $extra = apply_filters('aicwp_chat_history_extra_data', array(), $insert_data);
        if (!empty($extra) && is_array($extra)) {
            foreach ($extra as $col => $meta) {
                if (isset($meta['value'], $meta['format'])) {
                    $insert_data[$col] = $meta['value'];
                    $insert_format[] = $meta['format'];
                }
            }
        }

        $result = $wpdb->insert($table_name, $insert_data, $insert_format);

        if ($result === false) {
            error_log('AI Chat History: Database insert failed - ' . $wpdb->last_error);
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Get chat history statistics
     * Actual data retrieval is provided via filter
     *
     * @param int $days Number of days to include (default: 30)
     * @return array Statistics data
     */
    public static function get_stats($days = 30) {
        // Default empty stats - actual data provided via filter
        $default_stats = array(
            'total_conversations' => 0,
            'total_messages' => 0,
            'avg_per_conversation' => 0,
            'registered_users' => 0,
            'guest_users' => 0
        );

        // Real data is provided via filter
        return apply_filters('aicwp_chat_history_stats', $default_stats, $days);
    }

    /**
     * Get chat history statistics for today (from midnight)
     * Actual data retrieval is provided via filter
     *
     * @return array Statistics data for today
     */
    public static function get_stats_today() {
        // Default empty stats - actual data provided via filter
        $default_stats = array(
            'total_conversations' => 0,
            'total_messages' => 0,
            'avg_per_conversation' => 0
        );

        // Real data is provided via filter
        return apply_filters('aicwp_chat_history_stats_today', $default_stats);
    }

    /**
     * Get recent conversations
     * Actual data retrieval is provided via filter
     *
     * @param int $limit Number of conversations to retrieve
     * @param int $offset Offset for pagination
     * @return array Array of conversation data
     */
    public static function get_recent_conversations($limit = 20, $offset = 0) {
        // Real data is provided via filter
        return apply_filters('aicwp_chat_history_recent_conversations', array(), $limit, $offset);
    }

    /**
     * Get all messages in a specific conversation
     * Actual data retrieval is provided via filter
     *
     * @param string $conversation_id Conversation identifier
     * @return array Array of messages
     */
    public static function get_conversation($conversation_id) {
        // Real data is provided via filter
        return apply_filters('aicwp_chat_history_conversation', array(), $conversation_id);
    }

    /**
     * Delete old chat history records
     *
     * @param int $days Delete records older than this many days
     * @return int|false Number of rows deleted, or false on error
     */
    public static function cleanup_old_records($days = 90) {
        global $wpdb;

        $table_name = self::get_table_name();
        $date_threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name} WHERE created_at < %s",
            $date_threshold
        ));

        return $deleted;
    }

    /**
     * Get popular user questions (for insights)
     * Actual data retrieval is provided via filter
     *
     * @param int $limit Number of questions to return
     * @param int $days Days to look back
     * @return array Array of popular questions with counts
     */
    public static function get_popular_questions($limit = 10, $days = 30) {
        // Real data is provided via filter
        return apply_filters('aicwp_chat_history_popular_questions', array(), $limit, $days);
    }

    /**
     * Drop the chat history table
     * Used for cleanup or uninstall
     */
    public static function drop_table() {
        global $wpdb;
        $table_name = self::get_table_name();
        $wpdb->query("DROP TABLE IF EXISTS {$table_name}");
    }

    /**
     * Get all records for export
     * Actual data retrieval is provided via filter
     *
     * @param int|null $days Optional: limit to records from last N days
     * @return array All chat history records
     */
    public static function get_all_records($days = null) {
        // Real data is provided via filter
        return apply_filters('aicwp_chat_history_all_records', array(), $days);
    }

    /**
     * Export chat history as CSV
     *
     * Outputs CSV directly to browser for download
     *
     * @param int|null $days Optional: limit to records from last N days
     */
    public static function export_csv($days = null) {
        $records = self::get_all_records($days);

        // Set headers for CSV download
        $filename = 'chat-history-' . date('Y-m-d');
        if ($days !== null) {
            $filename .= '-last-' . $days . '-days';
        }
        $filename .= '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Open output stream
        $output = fopen('php://output', 'w');

        // Add UTF-8 BOM for Excel compatibility
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Write CSV header
        fputcsv($output, array(
            __('ID', 'ai-chat-wp'),
            __('Conversation ID', 'ai-chat-wp'),
            __('Session ID', 'ai-chat-wp'),
            __('User ID', 'ai-chat-wp'),
            __('Username', 'ai-chat-wp'),
            __('IP Address', 'ai-chat-wp'),
            __('Page URL', 'ai-chat-wp'),
            __('User Message', 'ai-chat-wp'),
            __('Assistant Message', 'ai-chat-wp'),
            __('Model', 'ai-chat-wp'),
            __('Created At', 'ai-chat-wp')
        ));

        // Cache user data to avoid repeated queries
        $user_cache = array();

        // Write data rows
        foreach ($records as $record) {
            // Get username if user_id exists
            $username = '';
            if (!empty($record['user_id'])) {
                $user_id = intval($record['user_id']);
                if (!isset($user_cache[$user_id])) {
                    $user = get_userdata($user_id);
                    $user_cache[$user_id] = $user ? $user->user_login : '';
                }
                $username = $user_cache[$user_id];
            }

            fputcsv($output, array(
                isset($record['id']) ? $record['id'] : '',
                isset($record['conversation_id']) ? $record['conversation_id'] : '',
                isset($record['session_id']) ? $record['session_id'] : '',
                isset($record['user_id']) ? $record['user_id'] : '',
                $username,
                isset($record['ip_address']) ? $record['ip_address'] : '',
                isset($record['page_url']) ? $record['page_url'] : '',
                isset($record['user_message']) ? $record['user_message'] : '',
                isset($record['assistant_message']) ? $record['assistant_message'] : '',
                isset($record['model_used']) ? $record['model_used'] : '',
                isset($record['created_at']) ? $record['created_at'] : ''
            ));
        }

        fclose($output);
    }
}

endif; // class_exists check
