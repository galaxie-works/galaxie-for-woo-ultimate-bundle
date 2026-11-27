<?php
/**
 * Coupons limited per CPF.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\CouponCpfLimit;

use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Integrations\BrazilianCheckoutFields;
use Galaxie\Woo\Support\Cpf;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce limits a coupon per account or per e-mail, and a shopper can
 * open a second account with another e-mail (or the same Gmail with a
 * "+tag") to use a first-purchase coupon again. The CPF the checkout already
 * requires is much harder to multiply, so a coupon can also be limited by it:
 *
 * - "Limite de uso por CPF": how many orders one CPF (or CNPJ) may use it on;
 * - "Só na primeira compra do CPF": only a CPF — and an account — with no
 *   earlier order.
 *
 * Both are set on the coupon (Marketing › Cupons › Restrição de uso › Limites
 * de uso). A coupon without them is not touched.
 *
 * Checked twice: when the coupon is applied or the cart recalculated, with
 * the CPF on the signed-in customer's profile (so the shopper hears it early);
 * and when the order is placed, with the CPF typed at checkout — the check
 * that cannot be skipped. Orders count as in {@see Rules::COUNTED_STATUSES}.
 * New orders keep the document's digits in `_galaxie_document`, beside the
 * Brazilian plugin's formatted `_billing_cpf`, which older orders are found by.
 */
final class Module implements ModuleContract {

	public const DOCUMENT_META = '_galaxie_document';

	public function id(): string {
		return 'coupon-cpf-limit';
	}

	public function title(): string {
		return __( 'Cupons: limite por CPF', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Limita o uso de um cupom por CPF (e "só na primeira compra"), para que a mesma pessoa não use o cupom de novo com outra conta ou outro e-mail. Configurado em cada cupom; cupons sem limite não mudam.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return true;
	}

	public function boot(): void {
		add_action( 'woocommerce_coupon_options_usage_limit', array( self::class, 'coupon_fields' ), 20, 2 );
		add_action( 'woocommerce_coupon_options_save', array( self::class, 'save_coupon_fields' ), 20, 2 );

		add_filter( 'woocommerce_coupon_is_valid', array( self::class, 'cart_check' ), 20, 3 );
		add_action( 'woocommerce_after_checkout_validation', array( self::class, 'checkout_check' ), 20, 2 );

		add_action( 'woocommerce_checkout_create_order', array( self::class, 'remember_document' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( self::class, 'remember_document_store_api' ), 20, 1 );
	}

	// ---------------------------------------------------------------- admin

	/**
	 * @param int        $coupon_id
	 * @param \WC_Coupon $coupon
	 */
	public static function coupon_fields( $coupon_id, $coupon = null ): void {
		if ( ! function_exists( 'woocommerce_wp_text_input' ) ) {
			return;
		}

		$coupon = $coupon instanceof \WC_Coupon ? $coupon : new \WC_Coupon( (int) $coupon_id );
		$limit  = (int) $coupon->get_meta( Rules::META_LIMIT );

		woocommerce_wp_text_input(
			array(
				'id'                => Rules::META_LIMIT,
				'label'             => __( 'Limite de uso por CPF', 'galaxie-woo' ),
				'placeholder'       => esc_attr__( 'Ilimitado', 'galaxie-woo' ),
				'description'       => __( 'Quantas vezes o mesmo CPF pode usar este cupom, em qualquer conta. 1 = uma vez por CPF.', 'galaxie-woo' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'value'             => $limit > 0 ? (string) $limit : '',
				'custom_attributes' => array(
					'step' => '1',
					'min'  => '0',
				),
			)
		);

		woocommerce_wp_checkbox(
			array(
				'id'          => Rules::META_FIRST,
				'label'       => __( 'Só na primeira compra do CPF', 'galaxie-woo' ),
				'description' => __( 'Só um CPF (e uma conta) sem pedido anterior pode usar este cupom.', 'galaxie-woo' ),
				'value'       => 'yes' === $coupon->get_meta( Rules::META_FIRST ) ? 'yes' : 'no',
			)
		);
	}

	/**
	 * @param int        $post_id
	 * @param \WC_Coupon $coupon
	 */
	public static function save_coupon_fields( $post_id, $coupon = null ): void {
		$coupon = $coupon instanceof \WC_Coupon ? $coupon : new \WC_Coupon( (int) $post_id );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce checked its meta-box nonce before this action.
		$limit = isset( $_POST[ Rules::META_LIMIT ] ) ? absint( wp_unslash( $_POST[ Rules::META_LIMIT ] ) ) : 0;
		$first = ! empty( $_POST[ Rules::META_FIRST ] );
		// phpcs:enable

		if ( $limit > 0 ) {
			$coupon->update_meta_data( Rules::META_LIMIT, $limit );
		} else {
			$coupon->delete_meta_data( Rules::META_LIMIT );
		}

		if ( $first ) {
			$coupon->update_meta_data( Rules::META_FIRST, 'yes' );
		} else {
			$coupon->delete_meta_data( Rules::META_FIRST );
		}

		$coupon->save();
	}

	// ---------------------------------------------------------------- checks

	/**
	 * Applying the coupon, or recalculating a cart that has it.
	 *
	 * @param bool          $valid
	 * @param \WC_Coupon    $coupon
	 * @param \WC_Discounts $discounts
	 * @return bool
	 * @throws \Exception The reason, which WooCommerce shows the shopper.
	 */
	public static function cart_check( $valid, $coupon = null, $discounts = null ) {
		if ( ! $valid || ! $coupon instanceof \WC_Coupon ) {
			return $valid;
		}

		$rules = self::coupon_rules( $coupon );
		if ( null === $rules ) {
			return $valid;
		}

		// An order staff edit in wp-admin is theirs to discount — also through
		// the order screen's "Aplicar cupom" button, which is AJAX, and the REST
		// API: there the signed-in user is the staff member, not the customer.
		$object = is_object( $discounts ) && method_exists( $discounts, 'get_object' ) ? $discounts->get_object() : null;
		if ( $object instanceof \WC_Order && ( ( is_admin() && ! wp_doing_ajax() ) || current_user_can( 'edit_shop_orders' ) ) ) {
			return $valid;
		}

		// On an order, the order's customer is the one whose history counts.
		$user_id  = $object instanceof \WC_Order ? (int) $object->get_customer_id() : get_current_user_id();
		$document = $object instanceof \WC_Order ? self::order_document( $object ) : '';
		if ( '' === $document ) {
			$document = Rules::digits( BrazilianCheckoutFields::customer_cpf( $user_id ) );
		}

		$violation = self::violation( $rules, $coupon->get_code(), $document, $user_id, $object instanceof \WC_Order ? $object->get_id() : 0 );
		if ( null !== $violation ) {
			throw new \Exception( esc_html( Rules::message( $violation, $rules['limit'] ) ), 100 );
		}

		return $valid;
	}

	/**
	 * Placing the order, with the document typed at checkout.
	 *
	 * @param array<string,mixed> $data
	 * @param \WP_Error           $errors
	 */
	public static function checkout_check( $data, $errors ): void {
		if ( ! is_array( $data ) || ! $errors instanceof \WP_Error || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$document = self::posted_document( $data );
		if ( '' === $document ) {
			$document = Rules::digits( BrazilianCheckoutFields::customer_cpf( get_current_user_id() ) );
		}

		foreach ( WC()->cart->get_applied_coupons() as $code ) {
			$coupon = new \WC_Coupon( $code );
			$rules  = self::coupon_rules( $coupon );
			if ( null === $rules ) {
				continue;
			}

			$violation = self::violation( $rules, (string) $code, $document, get_current_user_id(), self::awaiting_order_id() );
			if ( null !== $violation ) {
				/* translators: 1: coupon code, 2: why it cannot be used */
				$errors->add( 'galaxie_coupon_cpf', sprintf( __( 'Cupom %1$s: %2$s Remova-o para continuar.', 'galaxie-woo' ), strtoupper( (string) $code ), Rules::message( $violation, $rules['limit'] ) ) );
			}
		}
	}

	// ------------------------------------------------------------- recording

	/**
	 * @param \WC_Order           $order
	 * @param array<string,mixed> $data
	 */
	public static function remember_document( $order, $data = array() ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$document = is_array( $data ) ? self::posted_document( $data ) : '';
		if ( '' === $document ) {
			$document = Rules::digits( BrazilianCheckoutFields::customer_cpf( (int) $order->get_customer_id() ) );
		}
		if ( '' !== $document ) {
			$order->update_meta_data( self::DOCUMENT_META, $document );
		}
	}

	/** @param \WC_Order $order */
	public static function remember_document_store_api( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$document = self::order_document( $order );
		if ( '' !== $document && $document !== (string) $order->get_meta( self::DOCUMENT_META ) ) {
			$order->update_meta_data( self::DOCUMENT_META, $document );
		}
	}

	// ---------------------------------------------------------------- lookup

	/** @return array{limit:int,first:bool}|null */
	private static function coupon_rules( \WC_Coupon $coupon ): ?array {
		return Rules::rules( $coupon->get_meta( Rules::META_LIMIT ), $coupon->get_meta( Rules::META_FIRST ) );
	}

	/**
	 * @param array{limit:int,first:bool} $rules
	 * @return 'first_purchase'|'limit'|null
	 */
	private static function violation( array $rules, string $code, string $document, int $user_id, int $exclude_order ): ?string {
		if ( '' === $document && $user_id <= 0 ) {
			return null; // Nothing to tell this shopper apart by yet; the checkout check has the document.
		}

		return Rules::violation( $rules, $code, self::earlier_orders( $document, $user_id, $exclude_order ) );
	}

	/**
	 * Orders placed with this document (any account) or by this account.
	 *
	 * @return array<int,array{status:string,codes:array<int,string>}>
	 */
	private static function earlier_orders( string $document, int $user_id, int $exclude_order ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$ids = array();

		if ( '' !== $document ) {
			$meta_query = array( 'relation' => 'OR' );
			foreach ( self::document_lookups( $document ) as $key => $values ) {
				$meta_query[] = array(
					'key'     => $key,
					'value'   => $values,
					'compare' => 'IN',
				);
			}
			$ids = self::order_ids_by_meta( $meta_query );
		}

		if ( $user_id > 0 ) {
			$found = wc_get_orders(
				array(
					'status'      => Rules::COUNTED_STATUSES,
					'limit'       => -1,
					'return'      => 'ids',
					'customer_id' => $user_id,
				)
			);
			$ids = array_merge( $ids, is_array( $found ) ? $found : array() );
		}

		$orders = array();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			if ( $id === $exclude_order ) {
				continue;
			}
			$order = wc_get_order( $id );
			// Checked here too: whatever the lookup returned, only this
			// document's or this account's orders decide.
			if ( ! $order instanceof \WC_Order
				|| ! ( ( '' !== $document && self::order_document( $order ) === $document ) || ( $user_id > 0 && (int) $order->get_customer_id() === $user_id ) ) ) {
				continue;
			}
			$orders[] = array(
				'status' => (string) $order->get_status(),
				'codes'  => array_map( 'strval', $order->get_coupon_codes() ),
			);
		}

		return $orders;
	}

	/**
	 * The order meta a document may be stored under, with each value it may
	 * be stored as (digits, or formatted by the Brazilian plugin).
	 *
	 * @return array<string,array<int,string>>
	 */
	private static function document_lookups( string $document ): array {
		$values = static fn( array $list ): array => array_values( array_unique( $list ) );

		return array(
			self::DOCUMENT_META => array( $document ),
			'_billing_cpf'      => $values( array( $document, Cpf::format( $document ) ) ),
			'_billing_cnpj'     => $values( array( $document, self::format_cnpj( $document ) ) ),
			'_billing_document' => $values( array( $document, Cpf::format( $document ), self::format_cnpj( $document ) ) ),
		);
	}

	/**
	 * Ids of counted orders matching a meta query. wc_get_orders() honours
	 * `meta_query` only with the order tables (HPOS); with orders stored as
	 * posts it ignores it and returns every order, so posts are queried
	 * directly there.
	 *
	 * @param array<int|string,mixed> $meta_query
	 * @return array<int,int>
	 */
	private static function order_ids_by_meta( array $meta_query ): array {
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		if ( $hpos ) {
			$found = wc_get_orders(
				array(
					'status'     => Rules::COUNTED_STATUSES,
					'limit'      => -1,
					'return'     => 'ids',
					'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- only for coupons with a CPF rule.
				)
			);
			return is_array( $found ) ? array_map( 'intval', $found ) : array();
		}

		$found = get_posts(
			array(
				'post_type'        => 'shop_order',
				'post_status'      => array_map( static fn( string $s ): string => 'wc-' . $s, Rules::COUNTED_STATUSES ),
				'fields'           => 'ids',
				'numberposts'      => -1,
				'suppress_filters' => true,
				'meta_query'       => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- only for coupons with a CPF rule.
			)
		);
		return array_map( 'intval', $found );
	}

	/**
	 * The document in a checkout's posted data: the Brazilian plugin's CPF,
	 * its combined field, or a company's CNPJ.
	 *
	 * @param array<string,mixed> $data
	 */
	private static function posted_document( array $data ): string {
		foreach ( array( 'billing_cpf', 'billing_document', 'billing_cnpj' ) as $key ) {
			$digits = isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? Rules::digits( (string) $data[ $key ] ) : '';
			if ( 11 === strlen( $digits ) || 14 === strlen( $digits ) ) {
				return $digits;
			}
		}

		return '';
	}

	private static function order_document( \WC_Order $order ): string {
		foreach ( array( self::DOCUMENT_META, '_billing_cpf', '_billing_document', '_billing_cnpj' ) as $key ) {
			$digits = Rules::digits( (string) $order->get_meta( $key ) );
			if ( 11 === strlen( $digits ) || 14 === strlen( $digits ) ) {
				return $digits;
			}
		}

		return Rules::digits( BrazilianCheckoutFields::customer_cpf( (int) $order->get_customer_id() ) );
	}

	/** The order a retried payment reuses: its own coupon use is not an earlier one. */
	private static function awaiting_order_id(): int {
		return function_exists( 'WC' ) && WC()->session ? absint( WC()->session->get( 'order_awaiting_payment' ) ) : 0;
	}

	private static function format_cnpj( string $digits ): string {
		return 14 === strlen( $digits )
			? substr( $digits, 0, 2 ) . '.' . substr( $digits, 2, 3 ) . '.' . substr( $digits, 5, 3 ) . '/' . substr( $digits, 8, 4 ) . '-' . substr( $digits, 12, 2 )
			: $digits;
	}
}
