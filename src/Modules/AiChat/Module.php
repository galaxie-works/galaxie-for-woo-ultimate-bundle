<?php
/**
 * AI Chat: the site's AI assistant, embedded.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\AiChat;

use Galaxie\Woo\Core\Module as ModuleContract;

defined( 'ABSPATH' ) || exit;

/**
 * Runs galaxie-works/ai-chat-wp from lib/ai-chat-wp (a git subtree) instead of
 * as a separate plugin. {@see Loader} requires it while plugins load; this
 * module does the rest:
 *
 * - what the plugin's activation hook would have done (database tables,
 *   default settings, cron, and the one-time copy of the settings, trained
 *   content and history of PurioChat, the plugin it was forked from, which it
 *   then deactivates), once per plugin version;
 * - `galaxie-woo/v1/ai-chat` ({@see Controller}): read every setting with its
 *   schema and change any of them by key, cleaned by the plugin's own
 *   sanitizer ({@see Bridge}). API keys and tokens are never returned or
 *   written there.
 *
 * Switching the module off clears the plugin's cron ({@see Loader::modules_saved()});
 * its settings and data stay.
 */
final class Module implements ModuleContract {

	/** The plugin version whose activation already ran in the bundle. */
	public const ACTIVATED_OPTION = 'galaxie_ai_chat_activated';

	/** Held while the activation runs, so two requests do not run it at once. */
	private const LOCK_OPTION = 'galaxie_ai_chat_activating';

	public function id(): string {
		return Loader::MODULE_ID;
	}

	public function title(): string {
		return __( 'Assistente de IA (AI Chat)', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Chat com IA treinado no conteúdo do site, com busca de produtos, carrinho e consulta de pedidos. Ao ligar, importa as configurações e o treino do PurioChat e o desativa. As configurações também ficam na rota galaxie-woo/v1/ai-chat, só para administradores.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return false;
	}

	public function boot(): void {
		if ( ! defined( 'AICWP_VERSION' ) ) {
			return;
		}

		if ( Loader::embedded() ) {
			$this->maybe_activate();
		}

		( new Controller( new Bridge() ) )->hooks();
	}

	private function maybe_activate(): void {
		$version = (string) constant( 'AICWP_VERSION' );

		if ( get_option( self::ACTIVATED_OPTION ) === $version || ! class_exists( 'AICWP_Plugin' ) ) {
			return;
		}

		// add_option() fails when the row exists: a request already holding the
		// lock (or one that died holding it less than ten minutes ago) wins.
		$lock = get_option( self::LOCK_OPTION );
		if ( false !== $lock && time() - (int) $lock < 600 ) {
			return;
		}
		delete_option( self::LOCK_OPTION );
		if ( ! add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return;
		}

		try {
			\AICWP_Plugin::get_instance()->activate();
			update_option( self::ACTIVATED_OPTION, $version, false );
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}
}
