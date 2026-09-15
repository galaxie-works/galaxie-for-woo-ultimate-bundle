<?php
/**
 * A Melhor Envio quote request, with the products packed into shipping cartons.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The request rewrite, without WordPress: which requests it touches, how the
 * request's products become packing items, and the body sent instead.
 *
 * The Melhor Envio plugin posts `products` — one entry per cart or order line,
 * with that line's quantity — to `/shipment/calculate`, and lets the API work
 * out the volumes. This replaces them with one entry per carton, quantity 1:
 * the carton's external size and its total weight (contents, carton, filler),
 * insured for what is inside. Everything else in the body is left as it was.
 *
 * A line comes with what WordPress knows about it (see
 * `Modules\ShippingCartons\Rewriter`): the product's shipping size and weight
 * and, for a line in a gift, its gift group and role. A gift box is one rigid
 * item, lid up, with its own outside size; the candles, cards and ribbons in
 * that gift add only their weight to it. Cards and ribbons in a gift with no
 * box add their weight to the first carton.
 *
 * Anything unexpected keeps the original body: this can only ever make a quote
 * more accurate, never stop one.
 */
final class CartonQuote {

	/** Melhor Envio API hosts, production and sandbox. */
	public const HOSTS = array( 'api.melhorenvio.com', 'sandbox.melhorenvio.com.br' );

	/** The quote route, at the end of the URL path. */
	public const PATH = '/shipment/calculate';

	/** Prefix of the product ids this sends, so a body is never packed twice. */
	public const ID_PREFIX = 'galaxie-carton-';

	/** Gift roles that are flat enough to count by weight only. */
	public const WEIGHT_ONLY_ROLES = array( 'card', 'ribbon' );

	/** How much bigger a carton is outside than inside when nobody said, in cm. */
	public const WALL = 0.6;

	/**
	 * Whether a WordPress HTTP request is a Melhor Envio quote.
	 *
	 * @param string $url  Request URL.
	 * @param mixed  $args `http_request_args` arguments.
	 */
	public static function is_calculate( string $url, $args ): bool {
		if ( ! is_array( $args ) || 'POST' !== strtoupper( (string) ( $args['method'] ?? '' ) ) || ! is_string( $args['body'] ?? null ) ) {
			return false;
		}

		$parts = parse_url( $url );

		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return false;
		}

		if ( ! in_array( strtolower( (string) ( $parts['host'] ?? '' ) ), self::HOSTS, true ) ) {
			return false;
		}

		return str_ends_with( rtrim( (string) ( $parts['path'] ?? '' ), '/' ), self::PATH );
	}

	/**
	 * The body to send instead, or the same body and why not.
	 *
	 * @param string   $body      JSON request body.
	 * @param callable $lines_for fn( array $products ): ?array — for each product (same keys):
	 *                            { length, width, height (cm), weight (g), group?, role? }; null keeps the body.
	 * @param array    $cartons   Carton arrays ({@see CartonPacking}), external size as outer_length/_width/_height.
	 * @param array    $options   {@see CartonPacking} options, plus `fallback`: 'split' (default) or 'original'.
	 * @return array{body:string, changed:bool, reason:string, packed:?array}
	 */
	public static function rewrite_body( string $body, callable $lines_for, array $cartons, array $options = array() ): array {
		$keep = static fn( string $reason ): array => array(
			'body'    => $body,
			'changed' => false,
			'reason'  => $reason,
			'packed'  => null,
		);

		// Objects, not arrays: an empty `{}` elsewhere in the body must go back out as `{}`.
		$payload = json_decode( $body );

		if ( ! $payload instanceof \stdClass || ! isset( $payload->products ) || ! is_array( $payload->products ) || ! $payload->products ) {
			return $keep( 'shape' );
		}

		$products = array();
		$units    = 0;

		foreach ( $payload->products as $product ) {
			if ( ! $product instanceof \stdClass ) {
				return $keep( 'shape' );
			}

			$p        = (array) $product;
			$id       = $p['id'] ?? null;
			$quantity = $p['quantity'] ?? null;

			if ( is_string( $id ) && str_starts_with( $id, self::ID_PREFIX ) ) {
				return $keep( 'already' );
			}

			if ( ! ( is_int( $id ) || ( is_string( $id ) && '' !== $id ) ) || ! is_numeric( $quantity ) || (float) $quantity < 1 || floor( (float) $quantity ) !== (float) $quantity ) {
				return $keep( 'shape' );
			}

			$p['quantity'] = (int) $quantity;
			$units        += $p['quantity'];
			$products[]    = $p;
		}

		if ( $units > CartonPacking::MAX_UNITS ) {
			return $keep( 'too_many' );
		}

		if ( ! $cartons ) {
			return $keep( 'no_cartons' );
		}

		$lines = $lines_for( $products );

		if ( ! is_array( $lines ) || count( $lines ) !== count( $products ) ) {
			return $keep( 'lines' );
		}

		$plan = self::plan( $products, $lines, $cartons, $options );

		if ( null === $plan['packed'] ) {
			return $keep( $plan['reason'] );
		}

		$packed            = $plan['packed'];
		$payload->products = self::products_for( $packed, $plan['built'] );
		$json              = json_encode( $payload );

		if ( false === $json ) {
			return $keep( 'encode' );
		}

		return array(
			'body'    => $json,
			'changed' => true,
			'reason'  => '',
			'packed'  => $packed,
		);
	}

	/**
	 * Cartons for a list of products and their lines — what the quote is
	 * rewritten with, and what the order screen shows.
	 *
	 * @param array $products { id, quantity (int), insurance_value?, unitary_value? } each.
	 * @param array $lines    Same keys: { length, width, height, weight, group?, role?, label? }.
	 * @param array $cartons  Carton arrays.
	 * @param array $options  {@see CartonPacking} options, plus `fallback`.
	 * @return array{packed:?array, built:?array, reason:string} Reason '' when packed.
	 */
	public static function plan( array $products, array $lines, array $cartons, array $options = array() ): array {
		$fail = static fn( string $reason, ?array $built = null ): array => array(
			'packed' => null,
			'built'  => $built,
			'reason' => $reason,
		);

		if ( ! $cartons ) {
			return $fail( 'no_cartons' );
		}

		if ( array_sum( array_map( static fn( array $p ): int => max( 0, (int) ( $p['quantity'] ?? 0 ) ), $products ) ) > CartonPacking::MAX_UNITS ) {
			return $fail( 'too_many' );
		}

		$built = self::items( array_values( $products ), array_values( $lines ) );

		if ( null === $built ) {
			return $fail( 'dimensions' );
		}

		$options['split'] = 'original' !== ( $options['fallback'] ?? 'split' );
		$packed           = CartonPacking::pack( $built['items'], $cartons, $options );

		if ( null === $packed ) {
			return $fail( $options['split'] ? 'no_fit' : 'needs_split', $built );
		}

		return array(
			'packed' => $packed,
			'built'  => $built,
			'reason' => '',
		);
	}

	/**
	 * Packing items for the request's products.
	 *
	 * @param array $products Request products as arrays, quantities as ints.
	 * @param array $lines    Same keys: { length, width, height, weight, group?, role? }.
	 * @return array{items:array, loose:array{weight:float, insurance:float, unitary:float, contents:array}, insurance:bool, unitary:bool}|null
	 *         Null when a line has no size or weight where one is needed. Each
	 *         item and `loose` list what they carry by weight only in `contents`
	 *         ([ label, quantity ]).
	 */
	public static function items( array $products, array $lines ): ?array {
		$items = array();
		$boxes = array();
		$loose = array(
			'weight'    => 0.0,
			'insurance' => 0.0,
			'unitary'   => 0.0,
			'contents'  => array(),
		);

		$has_insurance = false;
		$has_unitary   = false;

		foreach ( $products as $p ) {
			$has_insurance = $has_insurance || array_key_exists( 'insurance_value', $p );
			$has_unitary   = $has_unitary || array_key_exists( 'unitary_value', $p );
		}

		// Gift boxes first, so what goes in them has somewhere to go whatever the line order.
		foreach ( array_values( $products ) as $i => $p ) {
			$line = $lines[ $i ] ?? null;

			if ( ! is_array( $line ) ) {
				return null;
			}

			$group = (string) ( $line['group'] ?? '' );

			if ( '' === $group || 'box' !== ( $line['role'] ?? '' ) ) {
				continue;
			}

			for ( $n = 0; $n < $p['quantity']; $n++ ) {
				$item = self::item( $line, $p, 'upright' );

				if ( null === $item ) {
					return null;
				}

				$items[]           = $item;
				$boxes[ $group ] ??= count( $items ) - 1;
			}
		}

		foreach ( array_values( $products ) as $i => $p ) {
			$line  = $lines[ $i ];
			$group = (string) ( $line['group'] ?? '' );
			$role  = (string) ( $line['role'] ?? '' );
			$q     = $p['quantity'];

			if ( '' !== $group && 'box' === $role ) {
				continue;
			}

			$weight = self::number( $line['weight'] ?? null );

			if ( $weight <= 0 ) {
				return null;
			}

			if ( '' !== $group && isset( $boxes[ $group ] ) ) {
				$k                        = $boxes[ $group ];
				$items[ $k ]['weight']    += $weight * $q;
				$items[ $k ]['insurance'] += self::number( $p['insurance_value'] ?? null ) * $q;
				$items[ $k ]['unitary']   += self::number( $p['unitary_value'] ?? null ) * $q;
				$items[ $k ]['contents'][] = array( (string) ( $line['label'] ?? '' ), $q );
				continue;
			}

			if ( '' !== $group && in_array( $role, self::WEIGHT_ONLY_ROLES, true ) ) {
				$loose['weight']    += $weight * $q;
				$loose['insurance'] += self::number( $p['insurance_value'] ?? null ) * $q;
				$loose['unitary']   += self::number( $p['unitary_value'] ?? null ) * $q;
				$loose['contents'][] = array( (string) ( $line['label'] ?? '' ), $q );
				continue;
			}

			for ( $n = 0; $n < $q; $n++ ) {
				$item = self::item( $line, $p, 'any' );

				if ( null === $item ) {
					return null;
				}

				$items[] = $item;
			}
		}

		if ( ! $items ) {
			return null;
		}

		return array(
			'items'     => $items,
			'loose'     => $loose,
			'insurance' => $has_insurance,
			'unitary'   => $has_unitary,
		);
	}

	/**
	 * Pairs each request product with the cart or order line it came from.
	 *
	 * Both are lists of { id, quantity }; the lines also carry { group, role }.
	 * They only pair when they hold exactly the same (id, quantity) entries, in
	 * any order; then equal entries pair in order. Otherwise null: the request
	 * is not about these lines.
	 *
	 * @param array $products Request products.
	 * @param array $sources  Cart or order lines.
	 * @return array<int, array{group:string, role:string}>|null Keyed like $products.
	 */
	public static function align( array $products, array $sources ): ?array {
		$key  = static fn( array $e ): string => (string) ( $e['id'] ?? '' ) . '#' . (int) ( $e['quantity'] ?? 0 );
		$want = array_map( $key, $products );
		$have = array_map( $key, array_values( $sources ) );

		$a = array_values( $want );
		$b = $have;
		sort( $a, SORT_STRING );
		sort( $b, SORT_STRING );

		if ( $a !== $b ) {
			return null;
		}

		$sources = array_values( $sources );
		$used    = array();
		$out     = array();

		foreach ( $want as $i => $k ) {
			foreach ( $have as $j => $h ) {
				if ( ! isset( $used[ $j ] ) && $h === $k ) {
					$used[ $j ] = true;
					$out[ $i ]  = array(
						'group' => (string) ( $sources[ $j ]['group'] ?? '' ),
						'role'  => (string) ( $sources[ $j ]['role'] ?? '' ),
					);
					continue 2;
				}
			}
		}

		return $out;
	}

	/**
	 * "N7 20.6 × 15.6 × 15.6 cm, 0.889 kg" per carton, for logs and wp-admin.
	 *
	 * @param array $packed From {@see CartonPacking::pack()}.
	 * @return string[]
	 */
	public static function describe( array $packed ): array {
		return array_map(
			static function ( array $box ): string {
				$c = $box['carton'];

				return sprintf(
					'%s %s × %s × %s cm, %s kg',
					(string) ( $c['name'] ?? $c['code'] ?? '' ),
					self::format( self::outer( $c, 'length' ) ),
					self::format( self::outer( $c, 'width' ) ),
					self::format( self::outer( $c, 'height' ) ),
					number_format( $box['weight'] / 1000, 3, '.', '' )
				);
			},
			$packed
		);
	}

	/**
	 * A carton's outside size on one axis: as entered, or inside + WALL.
	 *
	 * @param array  $carton Carton array.
	 * @param string $axis   length, width or height.
	 */
	public static function outer( array $carton, string $axis ): float {
		$outer = self::number( $carton[ 'outer_' . $axis ] ?? null );

		return round( $outer > 0 ? $outer : self::number( $carton[ $axis ] ?? null ) + self::WALL, 2 );
	}

	/**
	 * @param array $packed From CartonPacking::pack().
	 * @param array $built  From items().
	 * @return object[]
	 */
	private static function products_for( array $packed, array $built ): array {
		$out = array();

		foreach ( array_values( $packed ) as $n => $box ) {
			$carton    = $box['carton'];
			$first     = 0 === $n;
			$insurance = $first ? $built['loose']['insurance'] : 0.0;
			$unitary   = $first ? $built['loose']['unitary'] : 0.0;
			$grams     = $box['weight'] + ( $first ? (int) round( $built['loose']['weight'] ) : 0 );

			foreach ( $box['items'] as $i ) {
				$insurance += $built['items'][ $i ]['insurance'];
				$unitary   += $built['items'][ $i ]['unitary'];
			}

			$entry = array(
				'id'     => self::ID_PREFIX . self::code( (string) ( $carton['code'] ?? '' ) ) . '-' . ( $n + 1 ),
				'width'  => self::outer( $carton, 'width' ),
				'height' => self::outer( $carton, 'height' ),
				'length' => self::outer( $carton, 'length' ),
				'weight' => round( $grams / 1000, 3 ),
			);

			// Only what the original products carried: the plugin's second,
			// uninsured Correios quote sends no insurance_value, and must stay so.
			if ( $built['insurance'] ) {
				$entry['insurance_value'] = round( $insurance, 2 );
			}

			if ( $built['unitary'] ) {
				$entry['unitary_value'] = round( $unitary, 2 );
			}

			$entry['quantity'] = 1;
			$out[]             = (object) $entry;
		}

		return $out;
	}

	/**
	 * @param array  $line   Line.
	 * @param array  $p      Request product.
	 * @param string $rotate 'any' or 'upright'.
	 */
	private static function item( array $line, array $p, string $rotate ): ?array {
		$item = array(
			'length'    => self::number( $line['length'] ?? null ),
			'width'     => self::number( $line['width'] ?? null ),
			'height'    => self::number( $line['height'] ?? null ),
			'weight'    => self::number( $line['weight'] ?? null ),
			'rotate'    => $rotate,
			'insurance' => self::number( $p['insurance_value'] ?? null ),
			'unitary'   => self::number( $p['unitary_value'] ?? null ),
			'id'        => $p['id'],
			'label'     => (string) ( $line['label'] ?? '' ),
			'contents'  => array(),
		);

		if ( $item['length'] <= 0 || $item['width'] <= 0 || $item['height'] <= 0 || $item['weight'] <= 0 ) {
			return null;
		}

		return $item;
	}

	/** A carton code as it goes in a product id: lowercase letters, digits and dashes. */
	public static function code( string $code ): string {
		$code = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $code ) ), '-' );

		return '' === $code ? 'box' : $code;
	}

	/** @param mixed $value */
	private static function number( $value ): float {
		return is_numeric( $value ) && (float) $value > 0 ? (float) $value : 0.0;
	}

	private static function format( float $cm ): string {
		return rtrim( rtrim( number_format( $cm, 2, '.', '' ), '0' ), '.' );
	}
}
