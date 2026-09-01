<?php
/**
 * Base Elementor widget that renders a React island mount.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Elementor;

use Galaxie\Woo\Support\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * Concrete storefront widgets (Galaxie Checkout / My Account / Cart) extend
 * this. They only declare their island name and the props to hand the React
 * side; this base handles the mount markup, asset enqueue, and category. The
 * `.galaxie-ui` class scopes the component library's styles to the mount.
 */
abstract class AbstractIslandWidget extends \Elementor\Widget_Base {

	/** The island name registered on the JS side via `registerIsland()`. */
	abstract protected function island_name(): string;

	/**
	 * Data handed to the island as JSON (`data-galaxie-props`). Include the
	 * ajax url + nonce here for any island that talks back to the server.
	 *
	 * @return array<string,mixed>
	 */
	protected function island_props(): array {
		return array();
	}

	public function get_categories(): array {
		return array( 'galaxie' );
	}

	protected function render(): void {
		Assets::enqueue();

		printf(
			'<div class="galaxie-ui" data-galaxie-island="%s" data-galaxie-props="%s"></div>',
			esc_attr( $this->island_name() ),
			esc_attr( (string) wp_json_encode( $this->island_props() ) )
		);
	}
}
