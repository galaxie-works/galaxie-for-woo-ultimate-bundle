<?php
/**
 * "Galaxie Cart" — the table and the totals in one widget.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Cart\Widget;

use Elementor\Widget_Base;
use Galaxie\Woo\Support\CartParts;

defined( 'ABSPATH' ) || exit;

/**
 * The whole cart in one block: drop it in, configure it, done.
 *
 * Use the separate {@see CartTableWidget} and {@see CartTotalsWidget} instead
 * when the two need to sit in different containers — a sticky right-hand column
 * for the totals, say. Layout stays the builder's job either way; that is
 * exactly why this widget has no "columns" control.
 *
 * Every part it renders comes from {@see CartParts}, shared with the other two.
 */
final class CartWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-cart';
	}

	public function get_title(): string {
		return __( 'Galaxie Cart', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-cart';
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
		CartParts::register_totals_controls( $this );
		CartParts::register_empty_controls( $this );
		CartParts::register_head_style( $this );
		CartParts::register_table_style( $this );
		CartParts::register_totals_style( $this );
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

		echo '<div class="galaxie-cart">';
		CartParts::render_table( $settings );
		echo CartParts::totals_markup( $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo '</div>';
	}
}
