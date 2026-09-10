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

		$this->add_control(
			'fallback',
			array(
				'label'       => __( 'Before a variation is chosen', 'galaxie-woo' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'range',
				'options'     => array(
					'range' => __( 'Show the range', 'galaxie-woo' ),
					'blank' => __( 'Show nothing', 'galaxie-woo' ),
				),
				'description' => __( 'Only applies to variable products, whose dimensions are stored per variation.', 'galaxie-woo' ),
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

		// The unit the SCRIPT should append, which is not always the one used
		// above: "formatted" ignores the Show unit switch because WooCommerce's
		// own formatting always carries the unit, and the script rebuilds that
		// string from the raw sides rather than reusing `dimensions_html`.
		$script_unit = 'formatted' === $which
			? (string) get_option( 'woocommerce_dimension_unit' )
			: $unit;

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wrap() escapes its attributes; the value is escaped here.
		echo $this->wrap(
			esc_html( $value ),
			'dimension',
			array(
				'dimension' => $which,
				'unit'      => $script_unit,
			)
		);
	}

	private function value_for( \WC_Product $product, string $which, string $unit ): string {
		$own = $this->own_value( $product, $which, $unit );

		if ( '' !== $own ) {
			return $own;
		}

		if ( ! $product->is_type( 'variable' ) || 'range' !== $this->get_settings( 'fallback' ) ) {
			return '';
		}

		return $this->range( $product, $which, $unit );
	}

	private function own_value( \WC_Product $product, string $which, string $unit ): string {
		if ( 'formatted' === $which ) {
			$formatted = wc_format_dimensions( $product->get_dimensions( false ) );

			// WooCommerce returns its own "N/A" string when every side is empty,
			// which is a placeholder for a table cell, not a value to print.
			if ( __( 'N/A', 'woocommerce' ) === $formatted ) {
				return '';
			}

			// Same reason: turn `&times;` into the character before it is escaped.
			return html_entity_decode( $formatted, ENT_QUOTES, 'UTF-8' );
		}

		$getter = 'get_' . $which;
		$raw    = method_exists( $product, $getter ) ? (string) $product->{$getter}() : '';

		if ( '' === $raw ) {
			return '';
		}

		return '' === $unit ? $raw : $raw . ' ' . $unit;
	}

	/**
	 * The spread across variations, per axis.
	 *
	 * Ranged side by side rather than as two whole boxes — "5 – 8 × 5 – 8 ×
	 * 6.5 – 8.5" says which measurement varies and by how much, where
	 * "5 × 5 × 6.5 – 8 × 8 × 8.5" reads as one number sequence and has to be
	 * decoded. An axis that is the same on every variation prints once.
	 */
	private function range( \WC_Product $product, string $which, string $unit ): string {
		$axes    = 'formatted' === $which ? array( 'length', 'width', 'height' ) : array( $which );
		$spreads = array();

		foreach ( $axes as $axis ) {
			$values = array();

			foreach ( $product->get_children() as $child_id ) {
				$child  = wc_get_product( $child_id );
				$getter = 'get_' . $axis;

				if ( $child && method_exists( $child, $getter ) && '' !== (string) $child->{$getter}() ) {
					$values[] = (float) $child->{$getter}();
				}
			}

			if ( empty( $values ) ) {
				return '';
			}

			$low  = min( $values );
			$high = max( $values );

			$spreads[] = $low === $high
				? (string) wc_format_localized_decimal( $low )
				: wc_format_localized_decimal( $low ) . ' – ' . wc_format_localized_decimal( $high );
		}

		// wc_format_dimensions() joins with the HTML entity `&times;`. Written as
		// the character instead, because this string is escaped on the way out and
		// the JS writes its own through textContent - both of which would print an
		// entity literally, as "5 &times; 5".
		$value = implode( ' × ', $spreads );
		$show  = 'formatted' === $which
			? (string) get_option( 'woocommerce_dimension_unit' )
			: $unit;

		return '' === $show ? $value : $value . ' ' . $show;
	}
}
