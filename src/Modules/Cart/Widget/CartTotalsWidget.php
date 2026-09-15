<?php
/**
 * "Galaxie Cart Totals" — the summary on its own.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Cart\Widget;

use Elementor\Widget_Base;
use Galaxie\Woo\Support\CartParts;

defined( 'ABSPATH' ) || exit;

/**
 * The totals, for a container of their own — a sticky column beside the table,
 * usually.
 *
 * Renders NOTHING when the cart is empty. The empty state belongs to
 * {@see CartTableWidget}, which is the widget a shopper is actually looking at
 * when there is nothing to total; a summary of zero items beside a "your cart
 * is empty" message would be two ways of saying the same thing, one of them
 * useless.
 */
final class CartTotalsWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-cart-totals';
	}

	public function get_title(): string {
		return __( 'Galaxie Cart Totals', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-price-list';
	}

	/** @return array<int,string> */
	public function get_categories(): array {
		return array( 'galaxie' );
	}

	protected function register_controls(): void {
		CartParts::register_totals_controls( $this );
		CartParts::register_totals_style( $this );
	}

	protected function render(): void {
		if ( ! CartParts::available() || WC()->cart->is_empty() ) {
			return;
		}

		echo CartParts::totals_markup( $this->get_settings_for_display() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}
}
