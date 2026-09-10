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
					'range' => __( 'List every size', 'galaxie-woo' ),
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
	 * What to show before a variation is chosen.
	 *
	 * The whole set is listed — "5 × 5 × 6.5 cm, 8 × 8 × 8.5 cm" — rather than
	 * ranged per axis. This first shipped the other way, as "5 – 8 × 5 – 8 ×
	 * 6.5 – 8.5 cm", on the reasoning that it shows which measurement varies.
	 * On screen that is unreadable: six numbers and two kinds of dash in one
	 * line, and not one of them is a box you can picture. Each entry here is a
	 * real product you could hold.
	 *
	 * A single axis is still a range, because two numbers with a dash between
	 * them is exactly what a range should look like.
	 */
	private function range( \WC_Product $product, string $which, string $unit ): string {
		if ( 'formatted' !== $which ) {
			return $this->axis_range( $product, $which, $unit );
		}

		$unit  = (string) get_option( 'woocommerce_dimension_unit' );
		$boxes = array();

		foreach ( $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );

			if ( ! $child ) {
				continue;
			}

			$sides = array_filter(
				array(
					(string) $child->get_length(),
					(string) $child->get_width(),
					(string) $child->get_height(),
				),
				static fn( string $side ): bool => '' !== $side
			);

			if ( empty( $sides ) ) {
				continue;
			}

			// wc_format_dimensions() joins with the HTML entity `&times;`, and
			// this string is escaped on the way out — an entity would print
			// literally, as "5 &times; 5". The character, therefore.
			$box = implode( ' × ', $sides ) . ( '' === $unit ? '' : ' ' . $unit );

			// Variations often share a size; listing it twice says nothing.
			if ( ! in_array( $box, $boxes, true ) ) {
				$boxes[] = $box;
			}
		}

		return implode( ', ', $boxes );
	}

	/**
	 * Low to high on one axis.
	 */
	private function axis_range( \WC_Product $product, string $axis, string $unit ): string {
		$values = array();
		$getter = 'get_' . $axis;

		foreach ( $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );

			if ( $child && method_exists( $child, $getter ) && '' !== (string) $child->{$getter}() ) {
				$values[] = (float) $child->{$getter}();
			}
		}

		if ( empty( $values ) ) {
			return '';
		}

		$low   = min( $values );
		$high  = max( $values );
		$value = $low === $high
			? (string) wc_format_localized_decimal( $low )
			: wc_format_localized_decimal( $low ) . ' – ' . wc_format_localized_decimal( $high );

		return '' === $unit ? $value : $value . ' ' . $unit;
	}
}
