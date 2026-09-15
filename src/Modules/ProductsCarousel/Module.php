<?php
/**
 * Galaxie Products Carousel: pixfort's carousel, filtered by the page it is on.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\ProductsCarousel;

use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\ProvidesElementorWidgets;
use Galaxie\Woo\Modules\ProductsCarousel\Widget\ProductsCarouselWidget;

defined( 'ABSPATH' ) || exit;

/**
 * pixfort's Products Carousel lists products by count, category, order and
 * stock, and nothing else: on a brand's page it shows every brand. This module
 * adds a widget that is that carousel, inherited rather than copied, plus a
 * source (the archive being viewed, the current product's brand, chosen
 * brands) and the button texts WooCommerce otherwise decides alone.
 *
 * Only offered while pixfort is active, since the widget is pixfort's class.
 */
final class Module implements ModuleContract, ProvidesElementorWidgets {

	/** pixfort's own widget class, loaded by pixfort before widgets register. */
	public const PIXFORT_CLASS = '\Elementor\Pix_Eor_Products_Carousel';

	public const EDITOR_HANDLE = 'galaxie-products-carousel-handle';

	public function id(): string {
		return 'products-carousel';
	}

	public function title(): string {
		return __( 'Galaxie Products Carousel', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'pixfort\'s Products Carousel, able to show only the brand, category or tag being viewed, with editable button texts. Needs pixfort.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return true;
	}

	public function boot(): void {
		add_action( 'elementor/frontend/after_register_scripts', array( $this, 'register_editor_script' ) );
	}

	/**
	 * pixfort rebuilds its carousel after each change in the editor from a
	 * handler bound to its own widget name, which ours does not share. This is
	 * that handler for ours.
	 */
	public function register_editor_script(): void {
		wp_register_script(
			self::EDITOR_HANDLE,
			GALAXIE_WOO_URL . 'assets/editor/products-carousel.js',
			array( 'elementor-frontend' ),
			GALAXIE_WOO_VERSION,
			true
		);
	}

	/** @return string[] */
	public function elementor_widgets(): array {
		// Naming our class would load it, and it extends pixfort's: without
		// pixfort that is a fatal error, not a missing widget.
		return class_exists( self::PIXFORT_CLASS, false ) ? array( ProductsCarouselWidget::class ) : array();
	}
}
