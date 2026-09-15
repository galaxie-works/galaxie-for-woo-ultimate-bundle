<?php
/**
 * The "Presente" tag on orders in wp-admin.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\GiftWrap;

defined( 'ABSPATH' ) || exit;

/**
 * A badge beside the order heading and a column in the orders list, so whoever
 * packs the order sees it is a gift before opening the items.
 *
 * Both order storages are covered: HPOS lists orders on `wc-orders` and hands
 * the column callback the order itself; the legacy posts table hands a post id.
 * Which orders count is {@see Flag::is_gift()}'s decision, so a shared wish
 * list's gift wears the same tag as one marked on the product page.
 */
final class AdminTag {

	private const COLUMN = 'galaxie_gift';

	/** Order list and order edit screens, in both storages. */
	private const SCREENS = array( 'edit-shop_order', 'shop_order', 'woocommerce_page_wc-orders' );

	public static function hooks(): void {
		if ( ! is_admin() ) {
			return;
		}

		add_filter( 'manage_edit-shop_order_columns', array( self::class, 'add_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( self::class, 'legacy_column' ), 10, 2 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( self::class, 'add_column' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( self::class, 'hpos_column' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( self::class, 'order_heading' ) );
		add_action( 'admin_head', array( self::class, 'styles' ) );
	}

	/**
	 * @param mixed $columns
	 * @return mixed
	 */
	public static function add_column( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}

		$out = array();

		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;

			if ( 'order_status' === $key ) {
				$out[ self::COLUMN ] = __( 'Presente', 'galaxie-woo' );
			}
		}

		// A list table without a status column still gets one.
		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = __( 'Presente', 'galaxie-woo' );
		}

		return $out;
	}

	/** @param mixed $column @param mixed $post_id */
	public static function legacy_column( $column, $post_id ): void {
		if ( self::COLUMN === $column ) {
			self::cell( wc_get_order( (int) $post_id ) );
		}
	}

	/** @param mixed $column @param mixed $order */
	public static function hpos_column( $column, $order ): void {
		if ( self::COLUMN === $column ) {
			self::cell( $order );
		}
	}

	/**
	 * The badge, moved into the "Order #123 details" heading.
	 *
	 * WooCommerce offers no hook inside that heading; this one fires just below
	 * it, in the same panel, so the badge is printed here and placed by a line
	 * of script. Without script it stays where it was printed, still visible.
	 *
	 * @param mixed $order
	 */
	public static function order_heading( $order ): void {
		if ( ! $order instanceof \WC_Order || ! Flag::is_gift( $order ) ) {
			return;
		}

		printf( '<p class="form-field form-field-wide"><span class="galaxie-gift-badge" id="galaxie-gift-badge-heading">%s</span></p>', esc_html__( 'Presente', 'galaxie-woo' ) );
		echo "<script>(function(){var b=document.getElementById('galaxie-gift-badge-heading'),h=document.querySelector('.woocommerce-order-data__heading');if(b&&h){var p=b.parentNode;h.appendChild(b);if(p&&!p.children.length){p.remove();}}})();</script>";
	}

	public static function styles(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! in_array( $screen->id, self::SCREENS, true ) ) {
			return;
		}

		echo '<style>'
			. '.galaxie-gift-badge{display:inline-block;padding:0 8px;border-radius:999px;background:#fbe7ef;color:#8a1f4a;font-size:12px;font-weight:600;line-height:22px;vertical-align:middle;white-space:nowrap}'
			. '.woocommerce-order-data__heading .galaxie-gift-badge{margin-left:10px;font-size:13px}'
			. '.column-' . esc_attr( self::COLUMN ) . '{width:90px}'
			. '</style>';
	}

	/** @param mixed $order */
	private static function cell( $order ): void {
		if ( $order instanceof \WC_Order && Flag::is_gift( $order ) ) {
			printf( '<span class="galaxie-gift-badge">%s</span>', esc_html__( 'Presente', 'galaxie-woo' ) );
		}
	}
}
