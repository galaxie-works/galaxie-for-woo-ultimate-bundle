<?php
/**
 * Shipping carton tests for `Support\CartonPacking` and `Support\CartonQuote`:
 * `php tests/carton-packing/run.php`.
 *
 * No PHPUnit in this repo, so a plain runner like tests/gift-packing. Exits 1
 * on any failure. A fixture marked `disagreement` is a case where the packer's
 * answer differs from what was expected when the cartons were chosen; it is
 * printed as DIFF with the geometry, not counted as a failure.
 *
 * @package Galaxie\Woo
 */

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__, 2 ) . '/src/Support/CartonPacking.php';
require dirname( __DIR__, 2 ) . '/src/Support/CartonQuote.php';

use Galaxie\Woo\Support\CartonPacking;
use Galaxie\Woo\Support\CartonQuote;

$fixtures = json_decode( (string) file_get_contents( __DIR__ . '/fixtures.json' ), true, 512, JSON_THROW_ON_ERROR );
$cartons  = $fixtures['cartons'];
$catalog  = $fixtures['items'];
$defaults = $fixtures['options'];
$failed   = 0;
$passed   = 0;
$diffs    = 0;

$real = array_values( array_filter( $cartons, static fn( array $c ): bool => empty( $c['test_only'] ) ) );

$expand = static function ( array $pairs ) use ( $catalog ): array {
	$out = array();
	foreach ( $pairs as $pair ) {
		for ( $i = 0; $i < $pair[1]; $i++ ) {
			$out[] = $catalog[ $pair[0] ];
		}
	}
	return $out;
};

$check = static function ( string $group, string $name, $actual, $expect ) use ( &$failed, &$passed ): void {
	if ( $actual === $expect ) {
		++$passed;
		echo "  ok    {$group}: {$name}\n";
		return;
	}

	++$failed;
	echo "  FAIL  {$group}: {$name}\n        expected " . json_encode( $expect ) . "\n        got      " . json_encode( $actual ) . "\n";
};

// ------------------------------------------------------------------ packing

foreach ( $fixtures['pack'] as $case ) {
	$list    = isset( $case['cartons'] ) ? array_map( static fn( string $c ): array => $cartons[ $c ], $case['cartons'] ) : $real;
	$options = array_merge( $defaults, $case['options'] ?? array() );
	$items   = $expand( $case['items'] );
	$packed  = CartonPacking::pack( $items, $list, $options );
	$got     = null === $packed ? null : array_map( static fn( array $b ): string => $b['carton']['code'], $packed );

	if ( isset( $case['disagreement'] ) && $got !== $case['expect'] ) {
		++$diffs;
		echo "  DIFF  pack: {$case['name']}\n        expected " . json_encode( $case['expect'] ) . "\n        got      " . json_encode( $got ) . "\n        why      {$case['disagreement']}\n";
	} else {
		$check( 'pack', $case['name'], $got, $case['expect'] );
	}

	// Whatever the answer: every unit placed once, and every carton really holds its items and load.
	if ( null !== $packed ) {
		$seen  = array();
		$sound = true;
		foreach ( $packed as $box ) {
			$set   = array_map( static fn( int $i ): array => $items[ $i ], $box['items'] );
			$sound = $sound && CartonPacking::fits( $box['carton'], $set, $options ) && CartonPacking::load_ok( $box['carton'], $set, $options );
			$seen  = array_merge( $seen, $box['items'] );
		}
		sort( $seen );
		$check( 'pack', $case['name'] . ' (sound)', $sound && $seen === range( 0, count( $items ) - 1 ), true );
	}
}

foreach ( $fixtures['fits'] as $case ) {
	$options = array_merge( $defaults, $case['options'] ?? array() );
	$check( 'fits', $case['name'], CartonPacking::fits( $cartons[ $case['carton'] ], $expand( $case['items'] ), $options ), $case['expect'] );
}

foreach ( $fixtures['filler'] as $case ) {
	$check( 'filler', $case['name'], CartonPacking::filler( $cartons[ $case['carton'] ], $expand( $case['items'] ), $defaults ), $case['expect'] );
}

// Total weight of one carton: contents + filler + empty carton.
$one = CartonPacking::pack( $expand( array( array( '190g', 1 ) ) ), $real, $defaults );
$check( 'pack', '1 x 190g weighs 412 + 62 filler + 60 carton', $one[0]['weight'] ?? null, 534 );

// ------------------------------------------------------------------ request rewrite

$sample = array(
	'from'     => array( 'postal_code' => '01001000' ),
	'to'       => array( 'postal_code' => '20040002' ),
	'services' => '1,2,3,4,17',
	'options'  => array(
		'own_hand'            => false,
		'receipt'             => false,
		'insurance_value'     => 169.7,
		'use_insurance_value' => true,
	),
	'products' => array(
		array( 'id' => 101, 'name' => 'Vela 190g', 'width' => 8.2, 'height' => 8.8, 'length' => 8.2, 'weight' => 0.412, 'unitary_value' => 89.9, 'insurance_value' => 89.9, 'quantity' => 1, 'type' => 'variation', 'is_virtual' => false, 'components' => array() ),
		array( 'id' => 102, 'name' => 'Vela 50g', 'width' => 5.5, 'height' => 7, 'length' => 5.5, 'weight' => 0.143, 'unitary_value' => 39.9, 'insurance_value' => 39.9, 'quantity' => 2, 'type' => 'variation', 'is_virtual' => false, 'components' => array() ),
	),
);

$dims = array(
	101 => array( 'length' => 8.2, 'width' => 8.2, 'height' => 8.8, 'weight' => 412 ),
	102 => array( 'length' => 5.5, 'width' => 5.5, 'height' => 7, 'weight' => 143 ),
	900 => array( 'length' => 14.76, 'width' => 14.76, 'height' => 5.36, 'weight' => 196 ),
	950 => array( 'length' => 14, 'width' => 14, 'height' => 0.1, 'weight' => 6 ),
);

// Stands in for Modules\ShippingCartons\Rewriter: product data by id, gift roles from a map.
$lines_for = static function ( array $roles = array() ) use ( $dims ): callable {
	return static function ( array $products ) use ( $dims, $roles ): ?array {
		$out = array();
		foreach ( $products as $k => $p ) {
			if ( ! isset( $dims[ $p['id'] ] ) ) {
				return null;
			}
			$out[ $k ] = $dims[ $p['id'] ] + ( $roles[ $k ] ?? array() );
		}
		return $out;
	};
};

$result  = CartonQuote::rewrite_body( json_encode( $sample ), $lines_for(), $real, $defaults );
$decoded = json_decode( $result['body'], true );

$check( 'rewrite', 'sample: changed', $result['changed'], true );
$check(
	'rewrite',
	'sample: one N7 entry, external size, total weight, insured for the contents',
	$decoded['products'],
	array(
		array(
			'id'              => 'galaxie-carton-n7-1',
			'width'           => 15.6,
			'height'          => 15.6,
			'length'          => 20.6,
			'weight'          => 0.889,
			'insurance_value' => 169.7,
			'unitary_value'   => 169.7,
			'quantity'        => 1,
		),
	)
);

unset( $decoded['products'] );
$rest = $sample;
unset( $rest['products'] );
$check( 'rewrite', 'sample: from, to, services and options untouched', $decoded, $rest );
$check( 'rewrite', 'sample: rewriting the rewritten body changes nothing', CartonQuote::rewrite_body( $result['body'], $lines_for(), $real, $defaults )['reason'], 'already' );

// The plugin's second, uninsured Correios quote carries no insurance_value: neither does the carton.
$uninsured = $sample;
foreach ( $uninsured['products'] as &$product ) {
	unset( $product['insurance_value'] );
}
unset( $product );
$u = json_decode( CartonQuote::rewrite_body( json_encode( $uninsured ), $lines_for(), $real, $defaults )['body'], true );
$check( 'rewrite', 'uninsured quote: no insurance_value added', array_key_exists( 'insurance_value', $u['products'][0] ), false );

// A gift: square box + 2 x 190g + a card, one group. The box is the item; candles and card are weight.
$gift             = $sample;
$gift['products'] = array(
	array( 'id' => 101, 'name' => 'Vela 190g', 'unitary_value' => 89.9, 'insurance_value' => 89.9, 'quantity' => 2 ),
	array( 'id' => 900, 'name' => 'Caixa quadrada', 'unitary_value' => 10, 'insurance_value' => 10, 'quantity' => 1 ),
	array( 'id' => 950, 'name' => 'Cartão', 'unitary_value' => 2, 'insurance_value' => 2, 'quantity' => 1 ),
);
$roles = array(
	0 => array( 'group' => 'g1', 'role' => 'candle' ),
	1 => array( 'group' => 'g1', 'role' => 'box' ),
	2 => array( 'group' => 'g1', 'role' => 'card' ),
);
$g = CartonQuote::rewrite_body( json_encode( $gift ), $lines_for( $roles ), $real, $defaults );
$gp = json_decode( $g['body'], true )['products'];
// N16 inside 21 × 19 × 18 = 7182 cm³, box 14.76 × 14.76 × 5.36 = 1167.7 cm³ → 6014.3 × 0.029 = 174 g filler.
$check( 'rewrite', 'gift box: one N16, box + 2 candles + card + filler + carton', array( $gp[0]['id'] ?? null, $gp[0]['weight'] ?? null, $gp[0]['insurance_value'] ?? null, count( $gp ) ), array( 'galaxie-carton-n16-1', round( ( 196 + 2 * 412 + 6 + 174 + 120 ) / 1000, 3 ), 191.8, 1 ) );

// Not a gift: the same lines each take room.
$plain = CartonQuote::rewrite_body( json_encode( $gift ), $lines_for(), $real, $defaults );
// Box floor 14.76 leaves 3.24 cm beside it in N16 (usable 18 × 16 × 15) and 7.24 cm in N17 (22 × 22 × 11): no room for an 8.2 cm jar; N18 it is.
$check( 'rewrite', 'same lines without gift roles: every line takes room (N18, not N16)', array_map( static fn( array $p ): string => $p['id'], json_decode( $plain['body'], true )['products'] ), array( 'galaxie-carton-n18-1' ) );

$check( 'rewrite', 'malformed JSON: unchanged', CartonQuote::rewrite_body( '{"products": [', $lines_for(), $real, $defaults ), array( 'body' => '{"products": [', 'changed' => false, 'reason' => 'shape', 'packed' => null ) );
$check( 'rewrite', 'no products: unchanged', CartonQuote::rewrite_body( '{"from":{"postal_code":"1"}}', $lines_for(), $real, $defaults )['changed'], false );
$check( 'rewrite', 'products not a list of objects: unchanged', CartonQuote::rewrite_body( '{"products":[1,2]}', $lines_for(), $real, $defaults )['reason'], 'shape' );
$check( 'rewrite', 'product without quantity: unchanged', CartonQuote::rewrite_body( '{"products":[{"id":101}]}', $lines_for(), $real, $defaults )['reason'], 'shape' );
$check( 'rewrite', 'fractional quantity: unchanged', CartonQuote::rewrite_body( '{"products":[{"id":101,"quantity":1.5}]}', $lines_for(), $real, $defaults )['reason'], 'shape' );
$check( 'rewrite', 'unknown product (no dimensions): unchanged', CartonQuote::rewrite_body( '{"products":[{"id":555,"quantity":1}]}', $lines_for(), $real, $defaults )['reason'], 'lines' );
$check(
	'rewrite',
	'a product with no weight: unchanged',
	CartonQuote::rewrite_body( '{"products":[{"id":101,"quantity":1}]}', static fn( array $p ): array => array( array( 'length' => 8.2, 'width' => 8.2, 'height' => 8.8, 'weight' => 0 ) ), $real, $defaults )['reason'],
	'dimensions'
);
$check( 'rewrite', 'no cartons registered: unchanged', CartonQuote::rewrite_body( json_encode( $sample ), $lines_for(), array(), $defaults )['reason'], 'no_cartons' );
$check( 'rewrite', 'too many units: unchanged', CartonQuote::rewrite_body( '{"products":[{"id":101,"quantity":61}]}', $lines_for(), $real, $defaults )['reason'], 'too_many' );

$many             = $sample;
$many['products'] = array( array( 'id' => 101, 'unitary_value' => 89.9, 'insurance_value' => 89.9, 'quantity' => 8 ) );
$check( 'rewrite', 'fallback "original" and a split needed: unchanged', CartonQuote::rewrite_body( json_encode( $many ), $lines_for(), $real, $defaults + array( 'fallback' => 'original' ) )['reason'], 'needs_split' );
$split = json_decode( CartonQuote::rewrite_body( json_encode( $many ), $lines_for(), $real, $defaults )['body'], true )['products'];
$check(
	'rewrite',
	'fallback "split": one entry per carton, numbered, each insured for its own jars',
	array_map( static fn( array $p ): array => array( $p['id'], $p['insurance_value'], $p['quantity'] ), $split ),
	array( array( 'galaxie-carton-n18-1', 539.4, 1 ), array( 'galaxie-carton-n7-2', 179.8, 1 ) )
);

// Which requests are touched at all.
$post = array( 'method' => 'POST', 'body' => '{}' );
$check( 'is_calculate', 'production calculate', CartonQuote::is_calculate( 'https://api.melhorenvio.com/v2/me/shipment/calculate', $post ), true );
$check( 'is_calculate', 'sandbox calculate', CartonQuote::is_calculate( 'https://sandbox.melhorenvio.com.br/api/v2/me/shipment/calculate', $post ), true );
$check( 'is_calculate', 'GET', CartonQuote::is_calculate( 'https://api.melhorenvio.com/v2/me/shipment/calculate', array( 'method' => 'GET', 'body' => '{}' ) ), false );
$check( 'is_calculate', 'cart route', CartonQuote::is_calculate( 'https://api.melhorenvio.com/v2/me/cart', $post ), false );
$check( 'is_calculate', 'look-alike host', CartonQuote::is_calculate( 'https://api.melhorenvio.com.evil.example/v2/me/shipment/calculate', $post ), false );
$check( 'is_calculate', 'plain http', CartonQuote::is_calculate( 'http://api.melhorenvio.com/v2/me/shipment/calculate', $post ), false );
$check( 'is_calculate', 'array body', CartonQuote::is_calculate( 'https://api.melhorenvio.com/v2/me/shipment/calculate', array( 'method' => 'POST', 'body' => array() ) ), false );

// Pairing request products with cart or order lines.
$check(
	'align',
	'same lines, other order: roles follow the ids',
	CartonQuote::align(
		array( array( 'id' => 101, 'quantity' => 2 ), array( 'id' => 900, 'quantity' => 1 ) ),
		array( array( 'id' => 900, 'quantity' => 1, 'group' => 'g1', 'role' => 'box' ), array( 'id' => 101, 'quantity' => 2, 'group' => 'g1', 'role' => 'candle' ) )
	),
	array( array( 'group' => 'g1', 'role' => 'candle' ), array( 'group' => 'g1', 'role' => 'box' ) )
);
$check(
	'align',
	'same candle loose and in a gift: paired in order',
	CartonQuote::align(
		array( array( 'id' => 101, 'quantity' => 1 ), array( 'id' => 101, 'quantity' => 1 ) ),
		array( array( 'id' => 101, 'quantity' => 1, 'group' => 'g1', 'role' => 'candle' ), array( 'id' => 101, 'quantity' => 1 ) )
	),
	array( array( 'group' => 'g1', 'role' => 'candle' ), array( 'group' => '', 'role' => '' ) )
);
$check( 'align', 'different quantity: not these lines', CartonQuote::align( array( array( 'id' => 101, 'quantity' => 2 ) ), array( array( 'id' => 101, 'quantity' => 1 ) ) ), null );
$check( 'align', 'extra line: not these lines', CartonQuote::align( array( array( 'id' => 101, 'quantity' => 1 ) ), array( array( 'id' => 101, 'quantity' => 1 ), array( 'id' => 102, 'quantity' => 1 ) ) ), null );

// ------------------------------------------------------------------ timing

echo "\n  timing (best of 3):\n";
$time = static function ( string $label, callable $run ): void {
	$best = INF;
	for ( $i = 0; $i < 3; $i++ ) {
		$start = hrtime( true );
		$run();
		$best = min( $best, ( hrtime( true ) - $start ) / 1e6 );
	}
	printf( "    %-58s %8.2f ms\n", $label, $best );
};

$time( 'pack 12 x 190g + 12 x 50g', static fn() => CartonPacking::pack( $expand( array( array( '190g', 12 ), array( '50g', 12 ) ) ), $real, $defaults ) );
$time( 'pack 30 x 190g + 30 x 50g (MAX_UNITS)', static fn() => CartonPacking::pack( $expand( array( array( '190g', 30 ), array( '50g', 30 ) ) ), $real, $defaults ) );
$time( 'pack 4 big gift boxes + 6 x 50g', static fn() => CartonPacking::pack( $expand( array( array( 'big', 4 ), array( '50g', 6 ) ) ), $real, $defaults ) );

echo "\n  {$passed} passed, {$failed} failed, {$diffs} differ from the expected cartons (see DIFF)\n";
exit( $failed > 0 ? 1 : 0 );
