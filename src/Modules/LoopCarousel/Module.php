<?php
/**
 * Galaxie Loop Carousel: one template, repeated over whatever you query.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\LoopCarousel;

use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\ProvidesElementorWidgets;
use Galaxie\Woo\Modules\LoopCarousel\Widget\LoopCarouselWidget;

defined( 'ABSPATH' ) || exit;

/**
 * pixfort already ships a Loop Carousel, and its query controls are good. What
 * it cannot do is render a template of dynamic tags per term: it publishes the
 * looped term only as `$wp_query->loop_term`, which just five of its own tags
 * read, so a template built with Elementor Pro or ACF tags comes back blank.
 *
 * This widget is that carousel with the term also set where WordPress keeps it,
 * so every dynamic tag resolves. Offered only while pixfort's class is loaded —
 * it is gated behind the theme's `loop_builder` capability, and without it there
 * is nothing to extend.
 */
final class Module implements ModuleContract, ProvidesElementorWidgets {

	/** pixfort's own widget class, loaded by pixfort before widgets register. */
	public const PIXFORT_CLASS = '\Elementor\Pix_Eor_Loop_Carousel';

	public const EDITOR_HANDLE = 'galaxie-loop-carousel-handle';

	public function id(): string {
		return 'loop-carousel';
	}

	public function title(): string {
		return __( 'Galaxie Loop Carousel', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'pixfort\'s Loop Carousel, able to render a template of dynamic tags once per brand, category or tag. Needs pixfort.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return true;
	}

	public function boot(): void {
		add_action( 'elementor/frontend/after_register_scripts', array( $this, 'register_editor_script' ) );
	}

	/**
	 * pixfort rebuilds its carousels in the editor from a handler bound to its
	 * own widget names, which ours does not share. Ours runs that same handler
	 * rather than a copy of it.
	 */
	public function register_editor_script(): void {
		wp_register_script(
			self::EDITOR_HANDLE,
			GALAXIE_WOO_URL . 'assets/editor/loop-carousel.js',
			array( 'elementor-frontend' ),
			GALAXIE_WOO_VERSION,
			true
		);
	}

	/** @return string[] */
	public function elementor_widgets(): array {
		// Naming our class would load it, and it extends pixfort's: without
		// pixfort that is a fatal error, not a missing widget.
		return class_exists( self::PIXFORT_CLASS, false ) ? array( LoopCarouselWidget::class ) : array();
	}
}
