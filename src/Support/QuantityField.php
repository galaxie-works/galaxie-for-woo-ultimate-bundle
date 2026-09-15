<?php
/**
 * The quantity field, rendered the same way wherever one appears.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * One renderer for the control set {@see PixfortControls::quantity()} registers.
 *
 * The controls were shared long before the markup was, which is how the buy box
 * ended up able to offer a dropdown and the cart not — the Style control lived
 * in the buy box widget, and so did the only code that could honour it. A
 * control set that one caller can act on and another cannot is not a shared set.
 *
 * Both shapes carry `.quantity` and `.qty`, which is what every selector in the
 * control set and the theme's own stylesheet look for.
 */
final class QuantityField {

	/**
	 * @param array<string,mixed> $settings Widget settings.
	 * @param string              $prefix   Control-id prefix, e.g. `qty`.
	 * @param array<string,mixed> $args     WooCommerce's own `woocommerce_quantity_input()` arguments.
	 */
	public static function render( array $settings, string $prefix, \WC_Product $product, array $args = array() ): void {
		if ( 'select' !== ( $settings[ $prefix . '_style' ] ?? 'input' ) ) {
			woocommerce_quantity_input( $args, $product, true );
			return;
		}

		self::render_select( $settings, $prefix, $product, $args );
	}

	/**
	 * Dropdown alternative to the number input.
	 *
	 * @param array<string,mixed> $settings
	 * @param array<string,mixed> $args
	 */
	private static function render_select( array $settings, string $prefix, \WC_Product $product, array $args ): void {
		$max         = (int) ( $settings[ $prefix . '_max' ] ?? 5 );
		$product_max = (int) $product->get_max_purchase_quantity();

		// Never below one, even where the caller passes a minimum of zero: zero
		// is the cart's "remove this line", and a shopper reaches that through
		// the remove control rather than by finding it in a list of numbers.
		$min = max( 1, (int) ( $args['min_value'] ?? $product->get_min_purchase_quantity() ) );

		if ( $product_max > 0 ) {
			$max = min( $max, $product_max );
		}

		$max     = max( $min, $max );
		$name    = (string) ( $args['input_name'] ?? 'quantity' );
		$current = (int) ( $args['input_value'] ?? $min );

		echo '<div class="quantity galaxie-quantity-select">';
		printf( '<select name="%s" class="qty">', esc_attr( $name ) );

		for ( $i = $min; $i <= $max; $i++ ) {
			printf(
				'<option value="%1$d"%2$s>%1$d</option>',
				$i,
				selected( $i, $current, false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns a fixed attribute.
			);
		}

		echo '</select>';
		echo '</div>';
	}
}
