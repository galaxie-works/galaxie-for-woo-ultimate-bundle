<?php
/**
 * "Galaxie Cart Table" — the item lines on their own.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Cart\Widget;

use Elementor\Widget_Base;
use Galaxie\Woo\Support\CartParts;

defined( 'ABSPATH' ) || exit;

/**
 * For the layout the combined widget cannot give you: table here, totals in a
 * container of their own.
 *
 * This one owns the empty cart. When the cart empties, something has to say so,
 * and if both widgets did it a shopper would read the same message twice —
 * {@see CartTotalsWidget} renders nothing at all in that state.
 */
final class CartTableWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-cart-table';
	}

	public function get_title(): string {
		return __( 'Galaxie Cart Table', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-table';
	}

	/** @return array<int,string> */
	public function get_categories(): array {
		return array( 'galaxie' );
	}

	/** @return array<int,string> */
	public function get_script_depends(): array {
		return array( 'wc-cart-fragments' );
	}

	protected function register_controls(): void {
		CartParts::register_line_controls( $this );
		CartParts::register_behaviour_controls( $this );
		CartParts::register_empty_controls( $this );
		CartParts::register_head_style( $this );
		CartParts::register_table_style( $this );
	}

	protected function render(): void {
		if ( ! CartParts::available() ) {
			return;
		}

		$settings = $this->get_settings_for_display();

		if ( WC()->cart->is_empty() ) {
			CartParts::render_empty( $settings );
			return;
		}

		echo '<div class="galaxie-cart galaxie-cart--table">';
		CartParts::render_table( $settings );
		echo '</div>';
	}
}
