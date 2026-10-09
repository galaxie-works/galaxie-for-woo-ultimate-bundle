<?php
/**
 * One-time migration from the plugin's previous identity.
 *
 * Earlier releases (published under another name) stored settings, database
 * tables and cron hooks under different prefixes. This copies that data to the
 * current names the first time the plugin runs. It is non-destructive: the old
 * options and tables are left untouched, so the previous plugin keeps working
 * if it is ever reactivated. This is the only file that references the old names.
 *
 * @package AICWP
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Legacy_Migration {

    /**
     * Set once the migration has run.
     */
    const DONE_OPTION = 'aicwp_legacy_migration_done';

    /**
     * Previous plugin files. They are deactivated because they would keep
     * running alongside this plugin (duplicate shortcodes, widgets and cron).
     */
    const LEGACY_PLUGINS = array(
        'ai-chat-search/ai-chat-search.php',
        'ai-chat-search-pro/ai-chat-search-pro.php',
    );

    /**
     * Option name prefixes, old => new. Most specific first.
     */
    const OPTION_PREFIXES = array(
        'listeo_ai_search_'   => 'aicwp_',
        'listeo_ai_'          => 'aicwp_',
        'ai_chat_search_pro_' => 'aicwp_',
        'ai_chat_search_'     => 'aicwp_',
        'airs_'               => 'aicwp_ui_',
    );

    /**
     * Old options that are not carried over: licensing, trial gateway,
     * remote translation bookkeeping and vendor banners.
     */
    const SKIP_PATTERNS = array(
        '/license/',
        '/trial/',
        '/discount/',
        '/translation_(version|auto_attempted)$/',
        '/^ai_chat_search_pro_activated$/',
    );

    /**
     * Database tables (without $wpdb->prefix), old => new.
     */
    const TABLES = array(
        'listeo_ai_embeddings'       => 'aicwp_embeddings',
        'listeo_ai_chat_history'     => 'aicwp_chat_history',
        'listeo_ai_contact_messages' => 'aicwp_contact_messages',
        'listeo_ai_monthly_stats'    => 'aicwp_monthly_stats',
    );

    /**
     * Cron hook prefixes of the previous plugin.
     */
    const CRON_PREFIXES = array('listeo_ai_', 'ai_chat_search_');

    /**
     * Run once (activation, and admin_init as a fallback).
     */
    public static function maybe_run() {
        if (get_option(self::DONE_OPTION)) {
            return;
        }
        self::run();
    }

    /**
     * Perform the migration.
     */
    public static function run() {
        self::deactivate_legacy_plugins();
        self::migrate_options();
        self::migrate_tables();
        self::clear_legacy_cron();

        update_option(self::DONE_OPTION, time(), false);
    }

    /**
     * Map an old option name to its new name, or null if it should be skipped.
     *
     * @param string $old_name Old option name
     * @return string|null New option name
     */
    public static function map_option_name($old_name) {
        foreach (self::SKIP_PATTERNS as $pattern) {
            if (preg_match($pattern, $old_name)) {
                return null;
            }
        }
        foreach (self::OPTION_PREFIXES as $old_prefix => $new_prefix) {
            if (strpos($old_name, $old_prefix) === 0) {
                return $new_prefix . substr($old_name, strlen($old_prefix));
            }
        }
        return null;
    }

    /**
     * Deactivate the previous plugin if it is still active.
     */
    private static function deactivate_legacy_plugins() {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $active = array_filter(self::LEGACY_PLUGINS, 'is_plugin_active');
        if ($active) {
            deactivate_plugins($active, true);
        }
    }

    /**
     * Copy options to their new names. Existing new options are never overwritten.
     */
    private static function migrate_options() {
        global $wpdb;

        $where = array();
        foreach (array_keys(self::OPTION_PREFIXES) as $old_prefix) {
            $where[] = $wpdb->prepare('option_name LIKE %s', $wpdb->esc_like($old_prefix) . '%');
        }

        $rows = $wpdb->get_results(
            "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE " . implode(' OR ', $where)
        );

        foreach ((array) $rows as $row) {
            $new_name = self::map_option_name($row->option_name);
            if ($new_name === null || get_option($new_name) !== false) {
                continue;
            }
            add_option(
                $new_name,
                maybe_unserialize($row->option_value),
                '',
                in_array($row->autoload, array('no', 'off'), true) ? 'no' : 'yes'
            );
        }
    }

    /**
     * Copy table rows. A missing new table is cloned from the old one; an
     * existing but empty new table receives the rows for the columns both share.
     */
    private static function migrate_tables() {
        global $wpdb;

        foreach (self::TABLES as $old => $new) {
            $old_table = $wpdb->prefix . $old;
            $new_table = $wpdb->prefix . $new;

            if (!self::table_exists($old_table)) {
                continue;
            }

            if (!self::table_exists($new_table)) {
                $wpdb->query("CREATE TABLE `{$new_table}` LIKE `{$old_table}`");
                $wpdb->query("INSERT INTO `{$new_table}` SELECT * FROM `{$old_table}`");
                continue;
            }

            if ((int) $wpdb->get_var("SELECT COUNT(*) FROM `{$new_table}`") > 0) {
                continue;
            }

            $columns = array_intersect(
                $wpdb->get_col("SHOW COLUMNS FROM `{$old_table}`"),
                $wpdb->get_col("SHOW COLUMNS FROM `{$new_table}`")
            );
            if ($columns) {
                $list = '`' . implode('`, `', $columns) . '`';
                $wpdb->query("INSERT INTO `{$new_table}` ({$list}) SELECT {$list} FROM `{$old_table}`");
            }
        }
    }

    /**
     * Unschedule cron events registered by the previous plugin. This plugin
     * schedules its own events under the new names.
     */
    private static function clear_legacy_cron() {
        $cron = _get_cron_array();
        if (!is_array($cron)) {
            return;
        }
        $hooks = array();
        foreach ($cron as $events) {
            foreach (array_keys((array) $events) as $hook) {
                foreach (self::CRON_PREFIXES as $prefix) {
                    if (strpos($hook, $prefix) === 0) {
                        $hooks[$hook] = true;
                    }
                }
            }
        }
        foreach (array_keys($hooks) as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }

    /**
     * @param string $table Full table name
     * @return bool
     */
    private static function table_exists($table) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    }
}
