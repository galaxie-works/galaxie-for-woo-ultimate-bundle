<?php
/**
 * AI Chat for WordPress Contact Messages Logger
 *
 * Handles storage and retrieval of contact form messages
 * sent via AI chat tool or quick button
 *
 * @package AICWP_Plugin
 * @since 1.6.0
 */

if (!defined('ABSPATH')) exit;

class AICWP_Contact_Messages {

    /**
     * Database table name (without prefix)
     */
    private static $table_name = 'aicwp_contact_messages';

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
     * Create contact messages database table
     * Called on plugin activation or first use
     */
    public static function create_table() {
        global $wpdb;

        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sender_name varchar(255) NOT NULL,
            sender_email varchar(255) NOT NULL,
            message text NOT NULL,
            source enum('button','llm') NOT NULL DEFAULT 'button',
            conversation_id varchar(50) DEFAULT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            page_url varchar(2083) DEFAULT NULL,
            email_sent tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY source (source),
            KEY sender_email (sender_email),
            KEY user_id (user_id),
            KEY conversation_id (conversation_id),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Verify table was created
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") != $table_name) {
            error_log('AI Chat for WordPress: Failed to create contact messages table');
            return false;
        }

        // Run migrations for existing tables
        self::maybe_upgrade_table();

        return true;
    }

    /**
     * Check and upgrade table schema if needed
     * Adds missing columns for existing installations
     * Only runs once per schema version
     */
    public static function maybe_upgrade_table() {
        $schema_version = 2; // Increment when schema changes
        $current_version = get_option('aicwp_contact_messages_schema', 0);

        // Already up to date
        if ($current_version >= $schema_version) {
            return;
        }

        global $wpdb;
        $table_name = self::get_table_name();

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return;
        }

        // Check if conversation_id column exists
        $column_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'conversation_id'",
            DB_NAME,
            $table_name
        ));

        if (!$column_exists) {
            // Add conversation_id column
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN conversation_id varchar(50) DEFAULT NULL AFTER source");
            $wpdb->query("ALTER TABLE {$table_name} ADD KEY conversation_id (conversation_id)");
            error_log('AI Chat for WordPress: Added conversation_id column to contact messages table');
        }

        // Check if ip_address column exists and remove it (no longer used)
        $ip_column_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'ip_address'",
            DB_NAME,
            $table_name
        ));

        if ($ip_column_exists) {
            $wpdb->query("ALTER TABLE {$table_name} DROP COLUMN ip_address");
            error_log('AI Chat for WordPress: Removed ip_address column from contact messages table');
        }

        // Mark as upgraded
        update_option('aicwp_contact_messages_schema', $schema_version);
    }

    /**
     * Log a contact form submission
     *
     * @param string $name Sender's name
     * @param string $email Sender's email
     * @param string $message Message content
     * @param string $source Source of submission: 'button' or 'llm'
     * @param bool $email_sent Whether the email was successfully sent
     * @param string|null $conversation_id Conversation ID for LLM messages
     * @return int|false Insert ID on success, false on failure
     */
    public static function log_message($name, $email, $message, $source = 'button', $email_sent = true, $conversation_id = null) {
        global $wpdb;

        // Validate required parameters
        if (empty($name) || empty($email) || empty($message)) {
            return false;
        }

        // Validate source
        $valid_sources = array('button', 'llm');
        if (!in_array($source, $valid_sources, true)) {
            $source = 'button';
        }

        // Sanitize inputs
        $sanitized_name = sanitize_text_field($name);
        $sanitized_email = sanitize_email($email);
        $sanitized_message = sanitize_textarea_field($message);

        // Additional validation
        if (!is_email($sanitized_email)) {
            return false;
        }

        // Sanitize conversation_id if provided
        if (!empty($conversation_id)) {
            $conversation_id = sanitize_text_field($conversation_id);
            // Limit length to 50 chars
            if (strlen($conversation_id) > 50) {
                $conversation_id = substr($conversation_id, 0, 50);
            }
        }

        // Get user ID if logged in
        $user_id = is_user_logged_in() ? get_current_user_id() : null;

        // Get referring page URL
        $page_url = wp_get_referer();
        if ($page_url) {
            $page_url = esc_url_raw($page_url);
            // Limit URL length
            if (strlen($page_url) > 2083) {
                $page_url = substr($page_url, 0, 2083);
            }
        }

        $table_name = self::get_table_name();

        // Check if conversation_id column exists (handles old schema)
        $has_conversation_col = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'conversation_id'",
            DB_NAME,
            $table_name
        ));

        // Build insert data based on schema
        if ($has_conversation_col) {
            $data = array(
                'sender_name' => $sanitized_name,
                'sender_email' => $sanitized_email,
                'message' => $sanitized_message,
                'source' => $source,
                'conversation_id' => $conversation_id,
                'user_id' => $user_id,
                'page_url' => $page_url,
                'email_sent' => $email_sent ? 1 : 0,
                'created_at' => current_time('mysql')
            );
            $format = array('%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s');
        } else {
            $data = array(
                'sender_name' => $sanitized_name,
                'sender_email' => $sanitized_email,
                'message' => $sanitized_message,
                'source' => $source,
                'user_id' => $user_id,
                'page_url' => $page_url,
                'email_sent' => $email_sent ? 1 : 0,
                'created_at' => current_time('mysql')
            );
            $format = array('%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s');
        }

        $result = $wpdb->insert($table_name, $data, $format);

        if ($result === false) {
            error_log('AI Chat Contact Messages: Database insert failed - ' . $wpdb->last_error);
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Get contact messages with pagination
     *
     * @param int $limit Number of messages to retrieve
     * @param int $offset Offset for pagination
     * @param string|null $source Filter by source ('button', 'llm', or null for all)
     * @return array Array of message data
     */
    public static function get_messages($limit = 20, $offset = 0, $source = null) {
        global $wpdb;

        $table_name = self::get_table_name();

        $where = '';
        $params = array();

        if ($source && in_array($source, array('button', 'llm'), true)) {
            $where = 'WHERE source = %s';
            $params[] = $source;
        }

        $params[] = $limit;
        $params[] = $offset;

        $sql = "SELECT * FROM {$table_name} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";

        $messages = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        return $messages ?: array();
    }

    /**
     * Get single message by ID
     *
     * @param int $id Message ID
     * @return array|null Message data or null
     */
    public static function get_message($id) {
        global $wpdb;

        $table_name = self::get_table_name();

        $message = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            absint($id)
        ), ARRAY_A);

        return $message;
    }

    /**
     * Get total count of messages
     *
     * @param string|null $source Filter by source
     * @return int Total count
     */
    public static function get_total_count($source = null) {
        global $wpdb;

        $table_name = self::get_table_name();

        if ($source && in_array($source, array('button', 'llm'), true)) {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE source = %s",
                $source
            ));
        } else {
            $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        }

        return intval($count);
    }

    /**
     * Get statistics for contact messages
     *
     * @param int $days Number of days to include (default: 30)
     * @return array Statistics data
     */
    public static function get_stats($days = 30) {
        global $wpdb;

        $table_name = self::get_table_name();
        $date_from = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        // Total messages
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE created_at >= %s",
            $date_from
        ));

        // By source
        $by_source = $wpdb->get_results($wpdb->prepare(
            "SELECT source, COUNT(*) as count FROM {$table_name} WHERE created_at >= %s GROUP BY source",
            $date_from
        ), ARRAY_A);

        $llm_count = 0;
        $button_count = 0;
        foreach ($by_source as $row) {
            if ($row['source'] === 'llm') {
                $llm_count = intval($row['count']);
            } elseif ($row['source'] === 'button') {
                $button_count = intval($row['count']);
            }
        }

        // Failed emails
        $failed_emails = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE created_at >= %s AND email_sent = 0",
            $date_from
        ));

        return array(
            'total' => intval($total),
            'from_llm' => $llm_count,
            'from_button' => $button_count,
            'failed_emails' => intval($failed_emails)
        );
    }

    /**
     * Delete a message by ID
     *
     * @param int $id Message ID
     * @return bool True on success, false on failure
     */
    public static function delete_message($id) {
        global $wpdb;

        $table_name = self::get_table_name();

        $result = $wpdb->delete(
            $table_name,
            array('id' => absint($id)),
            array('%d')
        );

        return $result !== false;
    }

    /**
     * Delete old messages for cleanup
     *
     * @param int $days Delete messages older than this many days
     * @return int|false Number of rows deleted, or false on error
     */
    public static function cleanup_old_messages($days = 365) {
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
     * Check if table exists
     *
     * @return bool
     */
    public static function table_exists() {
        global $wpdb;
        $table_name = self::get_table_name();
        return $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
    }

    /**
     * Drop the contact messages table
     * Used for cleanup or uninstall
     */
    public static function drop_table() {
        global $wpdb;
        $table_name = self::get_table_name();
        $wpdb->query("DROP TABLE IF EXISTS {$table_name}");
    }
}
