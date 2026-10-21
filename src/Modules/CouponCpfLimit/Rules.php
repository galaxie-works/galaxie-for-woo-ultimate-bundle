<?php
/**
 * Per-CPF coupon rules: the decision, with no WordPress in it.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\CouponCpfLimit;

defined( 'ABSPATH' ) || exit;

/**
 * What a coupon allows a document (CPF, or CNPJ for a company) to do, given
 * the orders that document — and the signed-in account — already placed.
 * Pure, so the rules are tested without WooCommerce ({@see Module} gathers
 * the orders and shows the answer).
 */
final class Rules {

	/** Coupon meta: how many orders one document may use the coupon on (0 = no limit). */
	public const META_LIMIT = '_galaxie_cpf_usage_limit';

	/** Coupon meta: 'yes' when only a document with no earlier order may use it. */
	public const META_FIRST = '_galaxie_cpf_first_purchase';

	/**
	 * Orders that count. Cancelled, failed and refunded orders do not, nor one
	 * still waiting for payment (an unpaid Pix): WooCommerce gives a cancelled
	 * order's coupon back, and an abandoned payment must not lock the shopper
	 * out of trying again.
	 */
	public const COUNTED_STATUSES = array( 'processing', 'completed', 'on-hold' );

	/** Digits only. */
	public static function digits( string $document ): string {
		return (string) preg_replace( '/\D/', '', $document );
	}

	/**
	 * The rules a coupon carries, or null when it carries none.
	 *
	 * @param mixed $limit The META_LIMIT value.
	 * @param mixed $first The META_FIRST value.
	 * @return array{limit:int,first:bool}|null
	 */
	public static function rules( $limit, $first ): ?array {
		$limit = is_numeric( $limit ) ? max( 0, (int) $limit ) : 0;
		$first = 'yes' === $first;

		return ( $limit > 0 || $first ) ? array( 'limit' => $limit, 'first' => $first ) : null;
	}

	/**
	 * Why the coupon cannot be used, or null when it can.
	 *
	 * @param array{limit:int,first:bool}                             $rules
	 * @param string                                                  $code   The coupon code.
	 * @param array<int,array{status:string,codes:array<int,string>}> $orders Earlier orders of this document / account.
	 * @return 'first_purchase'|'limit'|null
	 */
	public static function violation( array $rules, string $code, array $orders ): ?string {
		$counted = array_filter(
			$orders,
			static fn( array $order ): bool => in_array( self::status( $order['status'] ), self::COUNTED_STATUSES, true )
		);

		if ( $rules['first'] && $counted ) {
			return 'first_purchase';
		}

		if ( $rules['limit'] > 0 ) {
			$code = strtolower( trim( $code ) );
			$used = 0;
			foreach ( $counted as $order ) {
				if ( in_array( $code, array_map( static fn( $c ): string => strtolower( trim( (string) $c ) ), $order['codes'] ), true ) ) {
					++$used;
				}
			}
			if ( $used >= $rules['limit'] ) {
				return 'limit';
			}
		}

		return null;
	}

	/** The text a shopper reads for a violation. */
	public static function message( string $violation, int $limit ): string {
		if ( 'first_purchase' === $violation ) {
			return __( 'Este cupom vale só para a primeira compra.', 'galaxie-woo' );
		}

		return 1 === $limit
			? __( 'Este cupom já foi usado com este CPF.', 'galaxie-woo' )
			: __( 'Este cupom já foi usado o número máximo de vezes com este CPF.', 'galaxie-woo' );
	}

	private static function status( string $status ): string {
		return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
	}
}
