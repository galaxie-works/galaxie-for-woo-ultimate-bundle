<?php
/**
 * Loads the embedded AI Chat plugin (lib/ai-chat-wp).
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\AiChat;

use Galaxie\Woo\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The embedded plugin is written to be loaded as a plugin: its main file
 * starts itself as it is read and hooks `plugins_loaded` at priority 4, while
 * modules boot later, on `plugins_loaded` at 10. So the file is required here,
 * from the bundle's main file while WordPress loads plugins, which gives it
 * the same timing it has when installed on its own. {@see Module::boot()} only
 * does what an activation would have done.
 *
 * Not loaded when the module is off, nor when ai-chat-wp is also installed as
 * a standalone plugin and active (it loads first, alphabetically): two copies
 * would declare the same classes.
 *
 * Nor in a request where PurioChat, the plugin it was forked from, is still
 * active. Both draw the chat widget and run the same cron, and an older fork
 * declared classes PurioChat also declares, which took the site down on
 * 2026-10-09. That request deactivates PurioChat instead; the plugin loads on
 * the next one, and its activation copies PurioChat's data.
 */
final class Loader {

	public const MODULE_ID = 'ai-chat';

	/** The plugin file, relative to the bundle. */
	private const FILE = 'lib/ai-chat-wp/ai-chat-wp.php';

	/** PurioChat (free and Pro): replaced by this module. */
	private const LEGACY_PLUGINS = array(
		'ai-chat-search/ai-chat-search.php',
		'ai-chat-search-pro/ai-chat-search-pro.php',
	);

	/** Set when this module deactivated PurioChat, for a one-time notice. */
	private const RETIRED_NOTICE = 'galaxie_ai_chat_retired_puriochat';

	/** Cron hooks the plugin schedules; cleared when the module is switched off. */
	private const CRON_HOOKS = array(
		'aicwp_aggregate_monthly_stats',
		'aicwp_bulk_process_listings',
		'aicwp_process_listing',
		'aicwp_cleanup_chat_history',
		'aicwp_cleanup_contact_messages',
	);

	public static function load(): void {
		add_action( 'update_option_galaxie_woo_modules', array( self::class, 'modules_saved' ), 10, 2 );

		if ( ! ( new Settings() )->is_enabled( self::MODULE_ID, false ) ) {
			return;
		}

		if ( defined( 'AICWP_VERSION' ) ) {
			add_action( 'admin_notices', array( self::class, 'standalone_notice' ) );
			return;
		}

		if ( self::legacy_active() ) {
			add_action( 'plugins_loaded', array( self::class, 'retire_legacy' ), 0 );
			return;
		}

		add_action( 'admin_notices', array( self::class, 'retired_notice' ) );

		require_once GALAXIE_WOO_DIR . self::FILE;
	}

	/** Whether PurioChat is active, or already loaded in this request. */
	private static function legacy_active(): bool {
		if ( class_exists( 'Listeo_AI_Search', false ) || class_exists( 'AI_Chat_Search_Pro', false ) ) {
			return true;
		}

		return (bool) array_intersect( self::LEGACY_PLUGINS, (array) get_option( 'active_plugins', array() ) );
	}

	/** Deactivates PurioChat; the embedded plugin takes over from the next request. */
	public static function retire_legacy(): void {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		deactivate_plugins( self::LEGACY_PLUGINS, true );
		update_option( self::RETIRED_NOTICE, 1, false );
	}

	public static function retired_notice(): void {
		if ( ! get_option( self::RETIRED_NOTICE ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		delete_option( self::RETIRED_NOTICE );
		echo '<div class="notice notice-info is-dismissible"><p>';
		esc_html_e( 'Galaxie: o PurioChat foi desativado porque o módulo Assistente de IA (AI Chat) o substitui. As configurações, o treino e o histórico foram copiados; os dados do PurioChat continuam guardados.', 'galaxie-woo' );
		echo '</p></div>';
	}

	/** Whether the copy running is the one in the bundle (not a standalone install). */
	public static function embedded(): bool {
		return defined( 'AICWP_PLUGIN_PATH' ) && wp_normalize_path( (string) constant( 'AICWP_PLUGIN_PATH' ) ) === wp_normalize_path( GALAXIE_WOO_DIR . dirname( self::FILE ) . '/' );
	}

	/**
	 * The module was switched off: what the plugin's deactivation hook does.
	 *
	 * @param mixed $old Previous `galaxie_woo_modules`.
	 * @param mixed $new New `galaxie_woo_modules`.
	 */
	public static function modules_saved( $old, $new ): void {
		if ( empty( ( (array) $old )[ self::MODULE_ID ] ) || ! empty( ( (array) $new )[ self::MODULE_ID ] ) || ! self::embedded() ) {
			return;
		}

		foreach ( self::CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		delete_option( Module::ACTIVATED_OPTION );
	}

	public static function standalone_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		esc_html_e( 'Galaxie: o módulo AI Chat está ligado, mas o plugin AI Chat for WordPress também está ativo e é ele que está rodando. Desative o plugin para usar a cópia do bundle.', 'galaxie-woo' );
		echo '</p></div>';
	}
}
