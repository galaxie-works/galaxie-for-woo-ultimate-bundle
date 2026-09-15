<?php
/**
 * What goes in each gift box, for whoever packs the order.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Support;

use Galaxie\Woo\Modules\GiftWrap\Groups;

defined( 'ABSPATH' ) || exit;

/**
 * "Presente 1: 2 × Vela 50g, Caixa P, 1 × Fita, Cartão — 'Feliz aniversário'"
 * per gift, in the order screen and in the new-order e-mail to the store and
 * the e-mails to the customer.
 *
 * Read from the hidden line item meta {@see Groups::line_item()} writes, so it
 * needs neither the cart nor the Gift Wrap module: like the "Presente" badge
 * ({@see GiftOrders}) it is booted from Plugin::boot() and keeps working for
 * orders placed before the module was switched off.
 */
final class GiftSummary {

	/** Order edit screens, both storages. */
	private const SCREENS = array( 'shop_order', 'woocommerce_page_wc-orders' );

	public static function hooks(): void {
		add_action( 'add_meta_boxes', array( self::class, 'meta_box' ), 30, 2 );
		add_action( 'woocommerce_email_after_order_table', array( self::class, 'email' ), 20, 4 );
	}

	/**
	 * Each gift in the order, by number.
	 *
	 * @return array<int, array{candles:array, box:array, ribbons:array, cards:array}>
	 *         Lists of [ name, quantity, message ].
	 */
	public static function groups( \WC_Order $order ): array {
		$groups = array();
		$keys   = array(
			Groups::ROLE_CANDLE => 'candles',
			Groups::ROLE_BOX    => 'box',
			Groups::ROLE_RIBBON => 'ribbons',
			Groups::ROLE_CARD   => 'cards',
		);

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$id   = (string) $item->get_meta( Groups::ITEM_GROUP );
			$role = (string) $item->get_meta( Groups::ITEM_ROLE );

			if ( '' === $id || ! isset( $keys[ $role ] ) ) {
				continue;
			}

			$number = max( 1, (int) $item->get_meta( Groups::ITEM_NUMBER ) );

			if ( ! isset( $groups[ $number ] ) ) {
				$groups[ $number ] = array_fill_keys( array_values( $keys ), array() );
			}

			$groups[ $number ][ $keys[ $role ] ][] = array(
				'name'     => wp_strip_all_tags( $item->get_name() ),
				'quantity' => (int) $item->get_quantity(),
				'message'  => (string) $item->get_meta( Groups::ITEM_MESSAGE ),
			);
		}

		ksort( $groups );

		return $groups;
	}

	/**
	 * @param mixed $screen_id
	 * @param mixed $post_or_order
	 */
	public static function meta_box( $screen_id = '', $post_or_order = null ): void {
		if ( ! in_array( (string) $screen_id, self::SCREENS, true ) ) {
			return;
		}

		$order = self::order( $post_or_order );

		if ( ! $order || ! self::groups( $order ) ) {
			return;
		}

		add_meta_box(
			'galaxie-gift-packing',
			__( 'Montagem dos presentes', 'galaxie-woo' ),
			array( self::class, 'render_meta_box' ),
			(string) $screen_id,
			'normal',
			'default'
		);
	}

	/** @param mixed $post_or_order */
	public static function render_meta_box( $post_or_order ): void {
		$order = self::order( $post_or_order );

		if ( $order ) {
			echo self::html( self::groups( $order ), false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html().
		}
	}

	/**
	 * Below the order table: in the store's new-order e-mail and in every
	 * e-mail to the customer. Other store e-mails (cancelled, failed) are about
	 * something other than packing.
	 *
	 * @param mixed $order
	 * @param mixed $sent_to_admin
	 * @param mixed $plain_text
	 * @param mixed $email
	 */
	public static function email( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( $email instanceof \WC_Email && ! $email->is_customer_email() && 'new_order' !== $email->id ) {
			return;
		}

		$groups = self::groups( $order );

		if ( ! $groups ) {
			return;
		}

		if ( $plain_text ) {
			// A plain-text e-mail: entities would print as typed, so tags are stripped instead.
			echo wp_strip_all_tags( self::text( $groups ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text, tags stripped.
			return;
		}

		echo self::html( $groups, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html().
	}

	/**
	 * @param array $groups From groups().
	 * @param bool  $email  Inline styles for mail clients; wp-admin's table classes otherwise.
	 */
	public static function html( array $groups, bool $email ): string {
		$table = $email ? ' cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;margin:0 0 16px"' : ' class="widefat striped" style="margin:0 0 12px"';
		$th    = $email ? ' style="text-align:left;vertical-align:top;width:30%"' : ' style="width:25%"';
		$out   = $email ? '<h2>' . esc_html__( 'Montagem dos presentes', 'galaxie-woo' ) . '</h2>' : '';

		foreach ( $groups as $number => $gift ) {
			$out .= $email ? '<h3>' . esc_html( Groups::label( (int) $number ) ) . '</h3>' : '<h4 style="margin:8px 0">' . esc_html( Groups::label( (int) $number ) ) . '</h4>';
			$out .= '<table' . $table . '><tbody>';

			foreach ( self::rows( $gift ) as $row ) {
				$out .= '<tr><th scope="row"' . $th . '>' . esc_html( $row[0] ) . '</th><td>' . implode( '<br>', array_map( 'esc_html', $row[1] ) ) . '</td></tr>';
			}

			$out .= '</tbody></table>';
		}

		return $out;
	}

	/** @param array $groups From groups(). */
	public static function text( array $groups ): string {
		$out = "\n" . strtoupper( __( 'Montagem dos presentes', 'galaxie-woo' ) ) . "\n\n";

		foreach ( $groups as $number => $gift ) {
			$out .= Groups::label( (int) $number ) . "\n";

			foreach ( self::rows( $gift ) as $row ) {
				$out .= '  ' . $row[0] . ': ' . implode( '; ', $row[1] ) . "\n";
			}

			$out .= "\n";
		}

		return $out;
	}

	/**
	 * Label and lines for one gift; the box row says so when there is none.
	 *
	 * @param array $gift One entry of groups().
	 * @return array<int, array{0:string, 1:string[]}>
	 */
	private static function rows( array $gift ): array {
		$line = static function ( array $entry ): string {
			/* translators: 1: quantity, 2: product name. */
			return sprintf( __( '%1$d × %2$s', 'galaxie-woo' ), $entry['quantity'], $entry['name'] );
		};

		$rows = array(
			array( __( 'Velas', 'galaxie-woo' ), array_map( $line, $gift['candles'] ) ),
			array( __( 'Caixa', 'galaxie-woo' ), $gift['box'] ? array_map( static fn( array $e ): string => $e['name'], $gift['box'] ) : array( __( 'Sem caixa', 'galaxie-woo' ) ) ),
		);

		if ( $gift['ribbons'] ) {
			$rows[] = array( __( 'Fitas', 'galaxie-woo' ), array_map( $line, $gift['ribbons'] ) );
		}

		if ( $gift['cards'] ) {
			$rows[] = array(
				__( 'Cartões', 'galaxie-woo' ),
				array_map(
					static function ( array $entry ) use ( $line ): string {
						return '' === $entry['message']
							? $line( $entry ) . ' — ' . __( 'sem mensagem', 'galaxie-woo' )
							/* translators: 1: "1 × Cartão", 2: the shopper's message. */
							: sprintf( __( '%1$s — “%2$s”', 'galaxie-woo' ), $line( $entry ), $entry['message'] );
					},
					$gift['cards']
				),
			);
		}

		return $rows;
	}

	/** @param mixed $post_or_order */
	private static function order( $post_or_order ): ?\WC_Order {
		if ( $post_or_order instanceof \WC_Order ) {
			return $post_or_order;
		}

		$order = $post_or_order instanceof \WP_Post ? wc_get_order( $post_or_order->ID ) : null;

		return $order instanceof \WC_Order ? $order : null;
	}
}
