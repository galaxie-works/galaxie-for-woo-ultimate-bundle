<?php
/**
 * Messaging Migrations
 *
 * Database schema changes for multi-channel messaging (WhatsApp, Telegram, etc.).
 * Adds columns to the free plugin's chat history table and creates the user states table.
 *
 * @package AICWP
 * @since 1.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Messaging_Migrations {

    const VERSION_KEY = 'aicwp_messaging_db_version';
    const CURRENT_VERSION = '1.0.0';
    const USER_STATES_TABLE = 'aicwp_user_states';

    /**
     * Initialize migrations
     */
    public static function init() {
        if (self::needs_migration()) {
            self::run_migrations();
        }
    }

    /**
     * Check if migrations need to run
     */
    public static function needs_migration() {
        return version_compare(get_option(self::VERSION_KEY, '0.0.0'), self::CURRENT_VERSION, '<');
    }

    /**
     * Run all migrations
     */
    public static function run_migrations() {
        self::add_chat_history_columns();
        self::create_user_states_table();
        update_option(self::VERSION_KEY, self::CURRENT_VERSION);
    }

    /**
     * Add messaging columns to the free plugin's chat history table
     */
    private static function add_chat_history_columns() {
        global $wpdb;

        if (!class_exists('AICWP_Chat_History')) {
            return;
        }

        $table_name = AICWP_Chat_History::get_table_name();

        // Validate table name before any SQL usage
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table_name)) {
            return;
        }

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return;
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}");

        if (!in_array('channel', $columns)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN channel VARCHAR(20) DEFAULT 'web'");
            $wpdb->query("ALTER TABLE {$table_name} ADD INDEX idx_channel (channel)");
        }

        if (!in_array('external_id', $columns)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN external_id VARCHAR(100) NULL");
        }

        if (!in_array('phone_hash', $columns)) {
            $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN phone_hash VARCHAR(64) NULL");
            $wpdb->query("ALTER TABLE {$table_name} ADD INDEX idx_phone_hash (phone_hash)");
        }
    }

    /**
     * Create user states table for multi-step conversations
     */
    private static function create_user_states_table() {
        global $wpdb;

        $table_name = self::get_user_states_table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            identifier VARCHAR(64) NOT NULL,
            platform VARCHAR(20) NOT NULL,
            state JSON NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_user_platform (identifier, platform),
            KEY idx_expires (expires_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Get user states table name
     */
    public static function get_user_states_table() {
        global $wpdb;
        return $wpdb->prefix . self::USER_STATES_TABLE;
    }

    /**
     * Check if a column exists in the chat history table
     */
    public static function has_column($column_name) {
        global $wpdb;

        if (!class_exists('AICWP_Chat_History')) {
            return false;
        }

        $table_name = AICWP_Chat_History::get_table_name();
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}");

        return in_array($column_name, $columns);
    }

    /**
     * Drop all Pro messaging tables (for uninstall)
     */
    public static function drop_tables() {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS " . self::get_user_states_table());
        delete_option(self::VERSION_KEY);
    }
}
