<?php
/**
 * Product dimensions — one side, or the formatted set.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\ProductData\Tags;

use Elementor\Controls_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Same story as {@see ProductWeight}: on a variable product the dimensions are
 * stored per variation and the parent has none, so a server-rendered tag shows
 * nothing until the variation is followed.
 *
 * "Formatted" defers to `wc_format_dimensions()`, which is what WooCommerce
 * prints in its own Additional Information table — same order, same separator,
 * same unit — so a spec table built with this matches the rest of the store
 * instead of inventing a second convention.
 */
final class ProductDimension extends BaseTag {

	public function get_name(): string {
		return 'galaxie-product-dimension';
	}

	public function get_title(): string {
		return __( 'Product Dimension', 'galaxie-woo' );
	}

	protected function register_controls(): void {
		$this->add_control(
			'dimension',
			array(
				'label'   => __( 'Dimension', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'formatted',
				'options' => array(
					'formatted' => __( 'All three, formatted', 'galaxie-woo' ),
					'length'    => __( 'Length', 'galaxie-woo' ),
					'width'     => __( 'Width', 'galaxie-woo' ),
					'height'    => __( 'Height', 'galaxie-woo' ),
				),
			)
		);

		$this->add_control(
			'show_unit',
			array(
				'label'        => __( 'Show unit', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'condition'    => array( 'dimension!' => 'formatted' ),
			)
		);

		$this->add_follow_control();
	}

	public function render(): void {
		$product = $this->product();

		if ( ! $product ) {
			return;
		}

		$which = (string) $this->get_settings( 'dimension' );
		$unit  = 'formatted' !== $which && 'yes' === $this->get_settings( 'show_unit' )
			? (string) get_option( 'woocommerce_dimension_unit' )
			: '';

		$value = $this->value_for( $product, $which, $unit );

		if ( '' === $value && ! $product->is_type( 'variable' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wrap() escapes its attributes; the value is escaped here.
		echo $this->wrap(
			esc_html( $value ),
			'dimension',
			array(
				'dimension' => $which,
				'unit'      => $unit,
			)
		);
	}

	private function value_for( \WC_Product $product, string $which, string $unit ): string {
		if ( 'formatted' === $which ) {
			$formatted = wc_format_dimensions( $product->get_dimensions( false ) );

			// WooCommerce returns its own "N/A" string when every side is empty,
			// which is a placeholder for a table cell, not a value to print.
			return __( 'N/A', 'woocommerce' ) === $formatted ? '' : $formatted;
		}

		$getter = 'get_' . $which;
		$raw    = method_exists( $product, $getter ) ? (string) $product->{$getter}() : '';

		if ( '' === $raw ) {
			return '';
		}

		return '' === $unit ? $raw : $raw . ' ' . $unit;
	}
}
