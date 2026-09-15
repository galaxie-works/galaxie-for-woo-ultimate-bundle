<?php
/**
 * The cartons an order goes out in, on its edit screen.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\ShippingCartons;

use Galaxie\Woo\Support\CartonQuote;

defined( 'ABSPATH' ) || exit;

/**
 * A read-only "Caixas de envio" box beside the order: each carton with its
 * outside size, total weight and what goes in it, worked out again from the
 * order's lines and the cartons registered now — the same packing the quote
 * was rewritten with, so whoever packs sends what was quoted.
 */
final class OrderBox {

	/** Order edit screens, both storages. */
	private const SCREENS = array( 'shop_order', 'woocommerce_page_wc-orders' );

	/** Why no cartons are shown. */
	private const REASONS = array(
		'no_lines'    => 'Nenhum produto a enviar neste pedido.',
		'no_cartons'  => 'Nenhuma caixa ativa cadastrada.',
		'too_many'    => 'Itens demais para calcular; o frete foi cotado sem caixas.',
		'dimensions'  => 'Um produto não tem dimensões ou peso de envio no WooCommerce; o frete foi cotado sem caixas.',
		'no_fit'      => 'Um item não cabe em nenhuma caixa cadastrada; o frete foi cotado sem caixas.',
		'needs_split' => 'Nenhuma caixa sozinha comporta o pedido e a opção é enviar a cotação original.',
	);

	public static function hooks(): void {
		add_action( 'add_meta_boxes', array( self::class, 'meta_box' ), 30, 2 );
	}

	/**
	 * @param mixed $screen_id
	 * @param mixed $post_or_order
	 */
	public static function meta_box( $screen_id = '', $post_or_order = null ): void {
		if ( ! in_array( (string) $screen_id, self::SCREENS, true ) || ! self::order( $post_or_order ) ) {
			return;
		}

		add_meta_box(
			'galaxie-shipping-cartons',
			__( 'Caixas de envio', 'galaxie-woo' ),
			array( self::class, 'render' ),
			(string) $screen_id,
			'side',
			'default'
		);
	}

	/** @param mixed $post_or_order */
	public static function render( $post_or_order ): void {
		$order = self::order( $post_or_order );

		if ( ! $order || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		$plan = self::plan( $order );

		if ( null === $plan['packed'] ) {
			echo '<p>' . esc_html( self::REASONS[ $plan['reason'] ] ?? $plan['reason'] ) . '</p>';
			return;
		}

		$loose = $plan['built']['loose']['contents'];

		foreach ( array_values( $plan['packed'] ) as $n => $box ) {
			$carton = $box['carton'];
			$weight = $box['weight'] + ( 0 === $n ? (int) round( $plan['built']['loose']['weight'] ) : 0 );

			printf(
				'<p style="margin:12px 0 4px"><strong>%1$s</strong><br>%2$s</p>',
				/* translators: 1: carton number, 2: carton name. */
				esc_html( sprintf( __( 'Caixa %1$d: %2$s', 'galaxie-woo' ), $n + 1, (string) $carton['name'] ) ),
				esc_html(
					sprintf(
						/* translators: 1–3: outside length, width, height in cm; 4: total weight in kg. */
						__( '%1$s × %2$s × %3$s cm, %4$s kg', 'galaxie-woo' ),
						wc_format_localized_decimal( CartonQuote::outer( $carton, 'length' ) ),
						wc_format_localized_decimal( CartonQuote::outer( $carton, 'width' ) ),
						wc_format_localized_decimal( CartonQuote::outer( $carton, 'height' ) ),
						wc_format_localized_decimal( number_format( $weight / 1000, 3, '.', '' ) )
					)
				)
			);

			echo '<ul style="margin:0 0 0 1.2em;list-style:disc">';

			foreach ( self::contents( $box, $plan['built']['items'], 0 === $n ? $loose : array() ) as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}

			echo '</ul>';
		}

		printf(
			'<p class="description" style="margin-top:12px">%s</p>',
			esc_html__( 'Calculado agora com as caixas cadastradas e o enchimento das configurações. Se elas mudaram depois da compra, o frete cobrado pode ter usado outras caixas.', 'galaxie-woo' )
		);
	}

	/**
	 * The order's lines packed with the current settings.
	 *
	 * @return array{packed:?array, built:?array, reason:string}
	 */
	public static function plan( \WC_Order $order ): array {
		$sources = Context::order_lines( $order );

		if ( ! $sources ) {
			return array(
				'packed' => null,
				'built'  => null,
				'reason' => 'no_lines',
			);
		}

		$products = array();
		$lines    = array();

		foreach ( $sources as $source ) {
			$products[] = array(
				'id'       => $source['id'],
				'quantity' => $source['quantity'],
			);

			$lines[] = Rewriter::measure( $source['product'] ) + array(
				'group' => $source['group'],
				'role'  => $source['role'],
			);
		}

		return CartonQuote::plan( $products, $lines, Module::cartons(), Module::packing_options() );
	}

	/**
	 * "2 × Vela 190g", "Caixa quadrada (com 2 × Vela 190g, 1 × Cartão)" per line.
	 *
	 * @param array $box   One carton from the plan.
	 * @param array $items Every packed item.
	 * @param array $loose Weight-only entries that ride in this carton.
	 * @return string[]
	 */
	private static function contents( array $box, array $items, array $loose ): array {
		$counts = array();

		foreach ( $box['items'] as $i ) {
			$item  = $items[ $i ];
			$label = $item['label'];

			if ( $item['contents'] ) {
				$inner = array_map(
					/* translators: 1: quantity, 2: product name. */
					static fn( array $entry ): string => sprintf( __( '%1$d × %2$s', 'galaxie-woo' ), $entry[1], $entry[0] ),
					$item['contents']
				);

				/* translators: 1: gift box name, 2: what is in it. */
				$label = sprintf( __( '%1$s (com %2$s)', 'galaxie-woo' ), $label, implode( ', ', $inner ) );
			}

			$counts[ $label ] = ( $counts[ $label ] ?? 0 ) + 1;
		}

		foreach ( $loose as $entry ) {
			$counts[ $entry[0] ] = ( $counts[ $entry[0] ] ?? 0 ) + $entry[1];
		}

		$out = array();

		foreach ( $counts as $label => $count ) {
			/* translators: 1: quantity, 2: product name. */
			$out[] = sprintf( __( '%1$d × %2$s', 'galaxie-woo' ), $count, $label );
		}

		return $out;
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
