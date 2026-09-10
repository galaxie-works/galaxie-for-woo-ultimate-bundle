<?php
/**
 * Product weight, which on a variable product lives on the variation.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\ProductData\Tags;

use Elementor\Controls_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Nothing in Elementor or Elementor Pro exposes weight, and the reason it needs
 * more than a getter is where the data sits. Read off this store:
 *
 *     parent    weight ""        dimensions "" x "" x ""
 *     50g       weight 143       dimensions 5 x 5 x 6.5
 *     190g      weight 412       dimensions 8 x 8 x 8.5
 *
 * A tag that renders server side on a variable product page therefore renders
 * EMPTY, always, because at that moment `$product` is the parent. Following the
 * variation is not a refinement here — without it the tag has nothing to show.
 *
 * Before a variation is picked there is still a choice to make, hence the
 * fallback control: show the range across variations, or show nothing.
 */
final class ProductWeight extends BaseTag {

	public function get_name(): string {
		return 'galaxie-product-weight';
	}

	public function get_title(): string {
		return __( 'Product Weight', 'galaxie-woo' );
	}

	protected function register_controls(): void {
		$this->add_control(
			'show_unit',
			array(
				'label'        => __( 'Show unit', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'variable_fallback',
			array(
				'label'       => __( 'Before a variation is chosen', 'galaxie-woo' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'range',
				'options'     => array(
					'range' => __( 'Show the range', 'galaxie-woo' ),
					'blank' => __( 'Show nothing', 'galaxie-woo' ),
				),
				'description' => __( 'Only applies to variable products, whose weight is stored per variation.', 'galaxie-woo' ),
			)
		);

		$this->add_follow_control();
	}

	public function render(): void {
		$product = $this->product();

		if ( ! $product ) {
			return;
		}

		$unit  = 'yes' === $this->get_settings( 'show_unit' ) ? (string) get_option( 'woocommerce_weight_unit' ) : '';
		$value = $this->initial_value( $product, $unit );

		if ( '' === $value && ! $product->is_type( 'variable' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wrap() escapes its attributes; the value is escaped here.
		echo $this->wrap( esc_html( $value ), 'weight', array( 'unit' => $unit ) );
	}

	private function initial_value( \WC_Product $product, string $unit ): string {
		$own = (string) $product->get_weight();

		if ( '' !== $own ) {
			return $this->format( $own, $unit );
		}

		if ( ! $product->is_type( 'variable' ) || 'range' !== $this->get_settings( 'variable_fallback' ) ) {
			return '';
		}

		$weights = array();

		foreach ( $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );

			if ( $child && '' !== (string) $child->get_weight() ) {
				$weights[] = (float) $child->get_weight();
			}
		}

		if ( empty( $weights ) ) {
			return '';
		}

		$low  = min( $weights );
		$high = max( $weights );

		return $low === $high
			? $this->format( (string) wc_format_localized_decimal( $low ), $unit )
			: $this->format( (string) wc_format_localized_decimal( $low ), '' ) . ' – ' . $this->format( (string) wc_format_localized_decimal( $high ), $unit );
	}

	private function format( string $number, string $unit ): string {
		return '' === $unit ? $number : $number . ' ' . $unit;
	}
}
