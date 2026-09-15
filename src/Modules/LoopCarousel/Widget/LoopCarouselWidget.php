<?php
/**
 * "Galaxie Loop Carousel": pixfort's loop carousel, with a working term context.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\LoopCarousel\Widget;

use Galaxie\Woo\Modules\LoopCarousel\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Every control is inherited: the template picker, the whole Query section
 * (Posts, Post Taxonomy, Products, Product Taxonomy and their sources, filters
 * and ordering), the carousel settings, navigation and dots. Only the rendering
 * is ours, and only because of one gap — see element.php for what the gap is.
 *
 * A subclass rather than a copy, so pixfort's query work and slider keep
 * arriving with their updates.
 */
final class LoopCarouselWidget extends \Elementor\Pix_Eor_Loop_Carousel {

	public function get_name() {
		return 'galaxie-loop-carousel';
	}

	public function get_title() {
		return __( 'Galaxie Loop Carousel', 'galaxie-woo' );
	}

	public function get_categories() {
		return array( 'galaxie' );
	}

	public function get_keywords() {
		return array( 'loop', 'carousel', 'template', 'query', 'brand', 'marca', 'taxonomy' );
	}

	public function get_script_depends() {
		$depends = (array) parent::get_script_depends();

		if ( is_user_logged_in() ) {
			$depends[] = Module::EDITOR_HANDLE;
		}

		return $depends;
	}

	protected function render() {
		// Required here, not at the top: the class it declares extends pixfort's.
		require_once __DIR__ . '/../element.php';

		$element = new \GalaxieLoopCarousel();

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the element returns built markup, as pixfort's own widget does.
		echo $element->render( $this->get_settings_for_display() );
	}
}
