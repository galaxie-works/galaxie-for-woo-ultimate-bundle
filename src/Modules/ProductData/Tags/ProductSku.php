<?php
/**
 * Product SKU that changes with the chosen variation.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\ProductData\Tags;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor Pro already has a Product SKU tag, and it reads the parent:
 *
 *     $product = $this->get_product( ... );
 *     echo esc_html( $product->get_sku() );
 *
 * On a variable product that is the catalogue code, not the code of the thing
 * the shopper is about to buy. On this store:
 *
 *     parent    EIR-VEL-NR-NM
 *     50g       EIR-VEL-NR-NM-PV70-050G
 *     190g      EIR-VEL-NR-NM-PV265-190G
 *
 * In a spec table where every other row follows the variation, a SKU that does
 * not is the one line a customer would quote back to support and be wrong
 * about. This exists only so it can follow.
 *
 * The parent SKU stays the fallback before a choice is made, which is what the
 * catalogue code is for.
 */
final class ProductSku extends BaseTag {

	public function get_name(): string {
		return 'galaxie-product-sku';
	}

	public function get_title(): string {
		return __( 'Product SKU', 'galaxie-woo' );
	}

	protected function register_controls(): void {
		$this->add_follow_control();
	}

	public function render(): void {
		$product = $this->product();

		if ( ! $product ) {
			return;
		}

		$sku = (string) $product->get_sku();

		if ( '' === $sku && ! $product->is_type( 'variable' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wrap() escapes its attributes; the value is escaped here.
		echo $this->wrap( esc_html( $sku ), 'sku' );
	}
}
