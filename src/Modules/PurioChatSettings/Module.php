<?php
/**
 * PurioChat's settings over REST.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\PurioChatSettings;

use Galaxie\Woo\Core\Module as ModuleContract;

defined( 'ABSPATH' ) || exit;

/**
 * PurioChat (PureThemes' `ai-chat-search` + Pro) keeps its settings in plain
 * `wp_options` rows registered without `show_in_rest`, and saves them through
 * an admin-ajax action bound to its own settings screen. Nothing outside
 * wp-admin can read or change them — not `/wp/v2/settings`, not an MCP client.
 *
 * This module adds `galaxie-woo/v1/puriochat` (see {@see Controller}): read
 * every setting with its schema, change any of them by key. Values go through
 * PurioChat's own registry and sanitizer ({@see PurioChat}), so a REST write is
 * cleaned exactly like a save on its settings screen. API keys, the webhook
 * secret and messaging tokens are never returned or written here.
 */
final class Module implements ModuleContract {

	public function id(): string {
		return 'puriochat-settings';
	}

	public function title(): string {
		return __( 'Configurações do PurioChat via REST', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Lê e altera as configurações do PurioChat (nome, prompt, cores, tipos de conteúdo…) pela rota galaxie-woo/v1/puriochat, só para administradores. Chaves de API ficam de fora.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return false;
	}

	public function boot(): void {
		( new Controller( new PurioChat() ) )->hooks();
	}
}
