<?php
/**
 * The checkout's order summary, as data for the stepper island.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Checkout;

use Galaxie\Woo\Support\FreeShipping;
use Galaxie\Woo\Support\ShippingRates;

defined( 'ABSPATH' ) || exit;

/**
 * The cart as the summary column shows it: lines, then the amount rows.
 *
 * Data, not markup, because the island draws it. The amounts are WooCommerce's
 * own display strings (`wc_price`, flattened to text) so the summary can never
 * disagree with the totals WooCommerce charges: the island only lays them out.
 *
 * It is rebuilt on every `updated_checkout` through the order-review fragments
 * (see {@see Module::summary_fragment()}), which is the round trip in which a
 * carrier, a coupon or an address changes what the order costs.
 */
final class OrderSummary {

	/** The fragment WooCommerce swaps on every checkout update. */
	public const FRAGMENT_ID = 'galaxie-checkout-summary';

	/**
	 * @return array{
	 *   count:int,
	 *   items:array<int,array{key:string,name:string,meta:string,quantity:int,image:string,total:string}>,
	 *   rows:array<int,array{id:string,label:string,value:string,note:string}>,
	 *   total:string
	 * }
	 */
	public static function data(): array {
		if ( ! function_exists( 'WC' ) || null === WC()->cart ) {
			return self::empty();
		}

		$cart  = WC()->cart;
		$items = array();

		foreach ( $cart->get_cart() as $key => $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof \WC_Product || ! apply_filters( 'woocommerce_checkout_cart_item_visible', true, $item, $key ) ) {
				continue;
			}

			$items[] = array(
				'key'      => (string) $key,
				'name'     => self::text( (string) apply_filters( 'woocommerce_cart_item_name', $product->get_name(), $item, $key ) ),
				'meta'     => self::text( (string) wc_get_formatted_cart_item_data( $item, true ) ),
				'quantity' => (int) $item['quantity'],
				'image'    => self::image( $product ),
				'total'    => self::text( (string) $cart->get_product_subtotal( $product, $item['quantity'] ) ),
			);
		}

		$rows   = array();
		$rows[] = array(
			'id'    => 'subtotal',
			'label' => __( 'Subtotal', 'galaxie-woo' ),
			'value' => self::text( $cart->get_cart_subtotal() ),
			'note'  => '',
		);

		foreach ( $cart->get_coupons() as $code => $coupon ) {
			$rows[] = array(
				'id'    => 'coupon-' . sanitize_title( (string) $code ),
				/* translators: %s: coupon code */
				'label' => sprintf( __( 'Cupom %s', 'galaxie-woo' ), strtoupper( (string) $code ) ),
				'value' => '-' . self::text( wc_price( $cart->get_coupon_discount_amount( (string) $code, $cart->display_cart_ex_tax ) ) ),
				'note'  => '',
			);
		}

		if ( $cart->needs_shipping() ) {
			$rows[] = self::shipping_row();
		}

		foreach ( $cart->get_fees() as $fee ) {
			$rows[] = array(
				'id'    => 'fee-' . sanitize_title( (string) $fee->id ),
				'label' => self::text( (string) $fee->name ),
				'value' => self::text( wc_price( $cart->display_prices_including_tax() ? $fee->total + $fee->tax : $fee->total ) ),
				'note'  => '',
			);
		}

		return array(
			'count' => (int) $cart->get_cart_contents_count(),
			'items' => $items,
			'rows'  => $rows,
			'total' => self::text( $cart->get_total() ),
		);
	}

	/**
	 * What the Elementor editor shows. The editor has no cart of its own, and
	 * a summary with nothing in it is no use for styling one, so it borrows
	 * two real products from the catalogue: real names, real photos, the
	 * store's own price format.
	 *
	 * @return array<string,mixed>
	 */
	public static function sample(): array {
		$candidates = function_exists( 'wc_get_products' )
			? wc_get_products( array( 'limit' => 12, 'status' => 'publish', 'orderby' => 'date', 'order' => 'DESC' ) )
			: array();

		$items    = array();
		$subtotal = 0.0;

		foreach ( $candidates as $product ) {
			// A variable product is shown as its first variation, the way a
			// cart line is: that variation's price and its attributes.
			$line = $product;
			if ( $product->is_type( 'variable' ) ) {
				$children = $product->get_children();
				$line     = $children ? wc_get_product( $children[0] ) : null;
			}

			// Only lines that look like a real order: a photo and a price. The
			// newest products on this store are gift add-ons, which have neither.
			if ( ! $line || (float) $line->get_price() <= 0 || ! $product->get_image_id() ) {
				continue;
			}

			$quantity  = $items ? 2 : 1;
			$price     = (float) $line->get_price();
			$subtotal += $price * $quantity;
			$items[]   = array(
				'key'      => 'sample-' . $line->get_id(),
				'name'     => $product->get_name(),
				'meta'     => $line instanceof \WC_Product_Variation ? self::text( wc_get_formatted_variation( $line, true, true ) ) : '',
				'quantity' => $quantity,
				'image'    => self::image( $product ),
				'total'    => self::text( wc_price( $price * $quantity ) ),
			);

			if ( 2 === count( $items ) ) {
				break;
			}
		}

		$shipping = 14.9;

		return array(
			'count' => array_sum( array_column( $items, 'quantity' ) ),
			'items' => $items,
			'rows'  => array(
				array( 'id' => 'subtotal', 'label' => __( 'Subtotal', 'galaxie-woo' ), 'value' => self::text( wc_price( $subtotal ) ), 'note' => '' ),
				array( 'id' => 'shipping', 'label' => __( 'Frete', 'galaxie-woo' ), 'value' => self::text( wc_price( $shipping ) ), 'note' => __( 'SEDEX · 2 a 4 dias úteis', 'galaxie-woo' ) ),
			),
			'total' => self::text( wc_price( $subtotal + $shipping ) ),
		);
	}

	/**
	 * The chosen carrier and its price, or a line saying the price comes later.
	 *
	 * Same rule as the cart totals: a price quoted for the store's own address
	 * (what WooCommerce falls back to before anyone types a CEP) is not a price
	 * for this shopper, so it is not shown as one.
	 *
	 * @return array{id:string,label:string,value:string,note:string}
	 */
	private static function shipping_row(): array {
		$row = array(
			'id'    => 'shipping',
			'label' => __( 'Frete', 'galaxie-woo' ),
			'value' => __( 'Calculado na entrega', 'galaxie-woo' ),
			'note'  => '',
		);

		if ( ! FreeShipping::destination_known() ) {
			return $row;
		}

		$chosen  = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods', array() ) : array();
		$total   = 0.0;
		$methods = array();

		foreach ( WC()->shipping()->get_packages() as $index => $package ) {
			$id = (string) ( $chosen[ $index ] ?? '' );
			if ( '' === $id || empty( $package['rates'][ $id ] ) ) {
				continue;
			}

			$rate      = $package['rates'][ $id ];
			$total    += ShippingRates::display_cost( $rate );
			$parts     = ShippingRates::parts( $rate );
			$methods[] = '' !== $parts['days'] ? $parts['name'] . ' · ' . $parts['days'] : $parts['name'];
		}

		if ( ! $methods ) {
			return $row;
		}

		$row['value'] = $total > 0 ? self::text( wc_price( $total ) ) : __( 'Grátis', 'galaxie-woo' );
		$row['note']  = implode( ' + ', array_unique( $methods ) );

		return $row;
	}

	private static function image( \WC_Product $product ): string {
		$id = $product->get_image_id();
		if ( ! $id && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$id     = $parent ? $parent->get_image_id() : 0;
		}

		$src = $id ? wp_get_attachment_image_url( (int) $id, 'woocommerce_thumbnail' ) : '';

		return $src ? (string) $src : (string) wc_placeholder_img_src( 'woocommerce_thumbnail' );
	}

	/**
	 * WooCommerce's price and name strings are HTML (`<span class="amount">`,
	 * `&#82;&#36;`). The island prints text, so it gets the text.
	 */
	private static function text( string $html ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	/** @return array{count:int,items:array<int,mixed>,rows:array<int,mixed>,total:string} */
	private static function empty(): array {
		return array( 'count' => 0, 'items' => array(), 'rows' => array(), 'total' => '' );
	}
}
