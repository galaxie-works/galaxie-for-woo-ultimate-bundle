<?php
/**
 * pixfort in Portuguese module.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\PixfortPtBr;

use Galaxie\Woo\Core\Module as ModuleContract;

defined( 'ABSPATH' ) || exit;

/**
 * pixfort Core ships no pt_BR translation (only Arabic and its .pot), so the
 * few strings it prints on the storefront stayed in English: the side cart's
 * "Your shopping cart" title, the "added to your shopping cart" notice, the
 * search overlay. This translates those, and only those, on the storefront
 * when the locale is Portuguese. A string some installed translation already
 * changed is left to that translation.
 */
final class Module implements ModuleContract {

	public const DOMAIN = 'pixfort-core';

	/** English original => Brazilian Portuguese, storefront strings only. */
	private const STRINGS = array(
		'Your shopping cart'                             => 'Seu carrinho',
		'Close Cart Sidebar'                             => 'Fechar carrinho',
		'The item has been added to your shopping cart!' => 'O produto foi adicionado ao seu carrinho!',
		'Hit enter to search or ESC to close'            => 'Tecle Enter para buscar ou Esc para fechar',
		'Search for:'                                    => 'Buscar por:',
		'Search'                                         => 'Buscar',
		'Close'                                          => 'Fechar',
		'Home'                                           => 'Início',
	);

	public function id(): string {
		return 'pixfort-pt-br';
	}

	public function title(): string {
		return __( 'pixfort in Portuguese', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Brazilian Portuguese for the storefront text pixfort Core prints in English: the side cart title, the added-to-cart notice and the search overlay.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return true;
	}

	public function boot(): void {
		add_filter( 'gettext_' . self::DOMAIN, array( self::class, 'gettext' ), 10, 2 );
	}

	/**
	 * @param string $translation What gettext made of `$text` so far.
	 * @param string $text        pixfort's English original.
	 */
	public static function gettext( $translation, $text ): string {
		$translation = (string) $translation;
		$text        = (string) $text;

		if ( $translation !== $text || ! isset( self::STRINGS[ $text ] ) ) {
			return $translation;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $translation;
		}

		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		if ( 0 !== strpos( strtolower( (string) $locale ), 'pt' ) ) {
			return $translation;
		}

		return self::STRINGS[ $text ];
	}
}
