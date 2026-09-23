<?php
/**
 * Gift packing tests for `Support\GiftPacking`: `php tests/gift-packing/run.php`.
 *
 * No PHPUnit in this repo, so a plain runner over the fixtures that the
 * TypeScript twin also reads (`run.ts`). Exits 1 on any failure.
 *
 * @package Galaxie\Woo
 */

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__, 2 ) . '/src/Support/GiftPacking.php';
require dirname( __DIR__, 2 ) . '/src/Support/GiftGroups.php';

use Galaxie\Woo\Support\GiftGroups;
use Galaxie\Woo\Support\GiftPacking;

$fixtures = json_decode( (string) file_get_contents( __DIR__ . '/fixtures.json' ), true, 512, JSON_THROW_ON_ERROR );
$sizes    = $fixtures['sizes'];
$boxes    = $fixtures['boxes'];
$failed   = 0;
$passed   = 0;

$expand = static function ( array $pairs ) use ( $sizes ): array {
	$out = array();
	foreach ( $pairs as $pair ) {
		for ( $i = 0; $i < $pair[1]; $i++ ) {
			$out[] = $sizes[ $pair[0] ];
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

foreach ( $fixtures['fits'] as $case ) {
	$options = $case['options'] ?? array();
	$check( 'fits', $case['name'], GiftPacking::fits( $boxes[ $case['box'] ], $expand( $case['candles'] ), $options ), $case['expect'] );
}

$shape = static function ( array $gifts ): array {
	return array_map(
		static fn( array $gift ): array => array(
			'box'     => $gift['box']['id'],
			'candles' => array_map( static fn( array $c ): string => $c['size'], $gift['candles'] ),
		),
		$gifts
	);
};

foreach ( $fixtures['arrange'] as $case ) {
	$list  = array_map( static fn( string $id ): array => $boxes[ $id ], $case['boxes'] );
	$opts  = $case['options'] ?? array();
	$first = $shape( GiftPacking::arrange( $expand( $case['candles'] ), $list, $opts ) );
	$check( 'arrange', $case['name'], $first, $case['expect'] );
	$check( 'arrange', $case['name'] . ' (same again)', $shape( GiftPacking::arrange( $expand( $case['candles'] ), $list, $opts ) ), $first );
}

foreach ( $fixtures['room'] as $case ) {
	$try = array_map( static fn( string $s ): array => $sizes[ $s ], $case['sizes'] );
	$check( 'room', $case['name'], GiftPacking::room( $boxes[ $case['box'] ], $expand( $case['candles'] ), $try, $case['options'] ?? array() ), $case['expect'] );
}

foreach ( $fixtures['summary'] as $case ) {
	$try = array_map( static fn( string $s ): array => $sizes[ $s ], $case['sizes'] );
	$check( 'summary', $case['name'], GiftPacking::summary( $boxes[ $case['box'] ], $try, $case['options'] ?? array() ), $case['expect'] );
}

foreach ( $fixtures['cover'] as $case ) {
	$covered = GiftPacking::cover( array_map( static fn( string $s ): array => $sizes[ $s ], $case['candles'] ) );
	$check( 'cover', $case['name'], $covered, $case['expect'] );

	if ( isset( $case['box'] ) ) {
		$check( 'cover', $case['name'] . ' (fits ' . $case['box'] . ')', GiftPacking::fits( $boxes[ $case['box'] ], $covered, $case['options'] ?? array() ), $case['fits'] );
	}
}

// GiftGroups: fill bars, stepper limits, plan validation, totals.
foreach ( array_merge( $fixtures['fill'], $fixtures['fill_lying'] ) as $case ) {
	$try = array_map( static fn( string $s ): array => $sizes[ $s ], $case['sizes'] );
	$check( 'fill', $case['name'], GiftGroups::fill( $boxes[ $case['box'] ], $expand( $case['candles'] ), $try, $case['options'] ?? array() ), $case['expect'] );
}

foreach ( array_merge( $fixtures['max_quantity'], $fixtures['max_quantity_lying'] ) as $case ) {
	$check( 'max_quantity', $case['name'], GiftGroups::max_quantity( $boxes[ $case['box'] ], $expand( $case['others'] ), $sizes[ $case['candle'] ], $case['current'], $case['options'] ?? array() ), $case['expect'] );
}

foreach ( array_merge( $fixtures['validate'], $fixtures['validate_lying'] ) as $case ) {
	$groups = array();
	foreach ( $case['groups'] as $group ) {
		$candles = array();
		foreach ( $group['candles'] as $spec ) {
			for ( $i = 0; $i < $spec[1]; $i++ ) {
				$candles[] = $sizes[ $spec[0] ] + array( 'id' => $spec[2], 'added' => $spec[3] ?? true );
			}
		}

		$box      = null === $group['box'] ? null : $boxes[ $group['box']['id'] ] + array( 'added' => $group['box']['added'] ?? true );
		$groups[] = array(
			'candles' => $candles,
			'box'     => $box,
			'items'   => $group['items'],
		);
	}

	$plan = array(
		'groups'      => $groups,
		'stock'       => $case['stock'],
		'in_cart'     => $case['in_cart'],
		'message_max' => $case['message_max'],
	);
	$check( 'validate', $case['name'], GiftGroups::validate( $plan, $case['options'] ?? array() ), $case['expect'] );
}

foreach ( $fixtures['room_counts'] as $case ) {
	$try = array_map( static fn( string $s ): array => $sizes[ $s ], $case['sizes'] );
	$check( 'room_counts', $case['name'], GiftGroups::room_counts( $boxes[ $case['box'] ], $expand( $case['candles'] ), $try, $case['options'] ?? array() ), $case['expect'] );
}

foreach ( $fixtures['total'] as $case ) {
	$check( 'total', $case['name'], GiftGroups::total( $case['lines'] ), $case['expect'] );
}

foreach ( $fixtures['arrange_all'] as $case ) {
	$list   = array_map( static fn( string $id ): array => $boxes[ $id ], $case['boxes'] );
	$result = GiftGroups::arrange_all( $expand( $case['candles'] ), $list, $case['options'] ?? array() );
	$got    = array(
		'gifts' => $shape( $result['gifts'] ),
		'loose' => array_map( static fn( array $c ): string => $c['size'], $result['loose'] ),
	);

	$check( 'arrange_all', $case['name'], $got, $case['expect'] );

	// Whatever the answer, every box closes and holds no more than MAX_ITEMS.
	$sound = true;
	foreach ( $result['gifts'] as $gift ) {
		$sound = $sound && count( $gift['candles'] ) <= GiftPacking::MAX_ITEMS && GiftPacking::fits( $gift['box'], $gift['candles'], $case['options'] ?? array() );
	}
	$check( 'arrange_all', $case['name'] . ' (every box fits)', $sound, true );
}

foreach ( $fixtures['card_for'] as $case ) {
	$check( 'card_for', $case['name'], GiftGroups::card_for( $case['cards'], $case['parent'], $case['box'] ), $case['expect'] );
}

foreach ( $fixtures['roles'] as $case ) {
	$roles = GiftGroups::roles( $case['rows'], $case['categories'] );
	$check( 'roles', $case['name'], $roles, $case['expect'] );

	// The data endpoint sends these as JSON: lists, never objects keyed by id.
	$lists = true;
	foreach ( $roles as $ids ) {
		$lists = $lists && array_is_list( $ids ) && str_starts_with( (string) json_encode( $ids ), '[' );
	}
	$check( 'roles', $case['name'] . ' (JSON lists)', $lists, true );
}

foreach ( $fixtures['check_request'] as $case ) {
	$check( 'check_request', $case['name'], GiftGroups::check_request( $case['raw'], $case['pending'], $case['loose'], $case['existing'] ), $case['expect'] );
}

foreach ( $fixtures['clean_message'] as $case ) {
	$clean = GiftGroups::clean_message( $case['message'] );
	$check( 'clean_message', $case['name'], $clean, $case['expect'] );
	$check( 'clean_message', $case['name'] . ' (length)', GiftGroups::message_length( $clean ), $case['length'] );
}

// Bytes JSON cannot carry: invalid UTF-8 is dropped, the rest kept.
$check( 'clean_message', 'invalid UTF-8 dropped', GiftGroups::clean_message( "ok\xC3\x28 fim" ), 'ok( fim' );

// WooCommerce helpers, PHP only (the TS twin never sees products), on stubs.
require __DIR__ . '/wc-stubs.php';

$box_meta = static function ( array $extra ): WC_Product {
	return new WC_Product(
		array(
			'id'    => 42,
			'type'  => 'variation',
			'price' => '20',
			'meta'  => $extra + array(
				'_galaxie_box_length' => '14.2',
				'_galaxie_box_width'  => '14.2',
				'_galaxie_box_height' => '4.8',
			),
		)
	);
};

foreach ( array(
	'negative overflow becomes 0'     => array( '-1', 0.0 ),
	'overflow above 2 becomes 2'      => array( '3', 2.0 ),
	'non-numeric overflow becomes 0'  => array( 'abc', 0.0 ),
	'overflow 0.5 is kept'            => array( '0.5', 0.5 ),
	'no overflow meta is 0'           => array( null, 0.0 ),
) as $name => $spec ) {
	$box = GiftPacking::box_from_product( $box_meta( null === $spec[0] ? array() : array( '_galaxie_box_overflow' => $spec[0] ) ) );
	$check( 'box_from_product', $name, $box['overflow'] ?? 'missing', $spec[1] );
}

$check( 'box_from_product', 'no internal height: not a box', GiftPacking::box_from_product( $box_meta( array( '_galaxie_box_height' => '' ) ) ), null );

$candle_with = static function ( array $meta, array $attributes = array( 'pa_peso' => '50g' ) ): WC_Product {
	return new WC_Product(
		array(
			'id'         => 7,
			'type'       => 'variation',
			'attributes' => $attributes,
			'length'     => '5',
			'width'      => '5',
			'height'     => '6.5',
			'price'      => '30',
			'meta'       => $meta,
		)
	);
};

$dims = static fn( ?array $c ): ?array => null === $c ? null : array( $c['size'], $c['length'], $c['width'], $c['height'] );
$gift = array(
	'_galaxie_gift_length' => '5.3',
	'_galaxie_gift_width'  => '5.3',
	'_galaxie_gift_height' => '6.7',
);

$check( 'candle_from_product', 'all three gift dimensions set: used', $dims( GiftPacking::candle_from_product( $candle_with( $gift ) ) ), array( '50g', 5.3, 5.3, 6.7 ) );
$check( 'candle_from_product', 'gift height missing: WooCommerce dimensions', $dims( GiftPacking::candle_from_product( $candle_with( array( '_galaxie_gift_height' => '' ) + $gift ) ) ), array( '50g', 5.0, 5.0, 6.5 ) );
$check( 'candle_from_product', 'gift width 0: WooCommerce dimensions', $dims( GiftPacking::candle_from_product( $candle_with( array( '_galaxie_gift_width' => '0' ) + $gift ) ) ), array( '50g', 5.0, 5.0, 6.5 ) );
$check( 'candle_from_product', 'gift length non-numeric: WooCommerce dimensions', $dims( GiftPacking::candle_from_product( $candle_with( array( '_galaxie_gift_length' => 'x' ) + $gift ) ) ), array( '50g', 5.0, 5.0, 6.5 ) );
$check( 'candle_from_product', 'no gift meta at all: WooCommerce dimensions', $dims( GiftPacking::candle_from_product( $candle_with( array() ) ) ), array( '50g', 5.0, 5.0, 6.5 ) );
$check( 'candle_from_product', 'no size attribute: not a candle', GiftPacking::candle_from_product( $candle_with( $gift, array() ) ), null );

// Gift dimensions per size term: variation → size term → WooCommerce.
$GLOBALS['gx_terms']     = array(
	'pa_peso' => array(
		(object) array( 'term_id' => 11, 'slug' => '190g', 'name' => '190 g' ),
		(object) array( 'term_id' => 12, 'slug' => '50g', 'name' => '50 g' ),
	),
);
$GLOBALS['gx_term_meta'] = array(
	12 => array(
		'_galaxie_gift_length' => '5.1',
		'_galaxie_gift_width'  => '5.1',
		'_galaxie_gift_height' => '6.6',
	),
);
GiftPacking::flush_sizes();

$check( 'candle_from_product', 'variation gift dimensions win over the size term', $dims( GiftPacking::candle_from_product( $candle_with( $gift ) ) ), array( '50g', 5.3, 5.3, 6.7 ) );
$check( 'candle_from_product', 'no variation gift dimensions: size term', $dims( GiftPacking::candle_from_product( $candle_with( array() ) ) ), array( '50g', 5.1, 5.1, 6.6 ) );
$check( 'candle_from_product', 'variation gift height missing: size term, not a mix', $dims( GiftPacking::candle_from_product( $candle_with( array( '_galaxie_gift_height' => '' ) + $gift ) ) ), array( '50g', 5.1, 5.1, 6.6 ) );
$check( 'candle_from_product', 'size term without gift dimensions: WooCommerce dimensions', $dims( GiftPacking::candle_from_product( $candle_with( array(), array( 'pa_peso' => '190g' ) ) ) ), array( '190g', 5.0, 5.0, 6.5 ) );
$check( 'candle_from_product', 'size not a term: WooCommerce dimensions', $dims( GiftPacking::candle_from_product( $candle_with( array(), array( 'pa_peso' => '80g' ) ) ) ), array( '80g', 5.0, 5.0, 6.5 ) );

$simple = new WC_Product(
	array(
		'id'              => 8,
		'type'            => 'simple',
		'text_attributes' => array( 'pa_peso' => '50 g' ),
		'length'          => '5',
		'width'           => '5',
		'height'          => '6.5',
		'price'           => '30',
	)
);
$check( 'candle_from_product', 'simple product: size term found by name', $dims( GiftPacking::candle_from_product( $simple ) ), array( '50 g', 5.1, 5.1, 6.6 ) );

$GLOBALS['gx_term_meta'][12]['_galaxie_gift_width'] = '';
$check( 'candle_from_product', 'term read once per request (cached)', $dims( GiftPacking::candle_from_product( $candle_with( array() ) ) ), array( '50g', 5.1, 5.1, 6.6 ) );
GiftPacking::flush_sizes();
$check( 'candle_from_product', 'after flush_sizes: size term width gone, WooCommerce dimensions', $dims( GiftPacking::candle_from_product( $candle_with( array() ) ) ), array( '50g', 5.0, 5.0, 6.5 ) );

$GLOBALS['gx_terms'] = array();
GiftPacking::flush_sizes();
// store_sizes() on a stubbed store: one size per term of the attribute, gift
// dimensions winning over WooCommerce's, a draft product's variation ignored.
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

$GLOBALS['galaxie_stub_store'] = array(
	'published' => array( 100 ),
	'parents'   => array( 101 => 100, 102 => 100, 201 => 200 ),
	'products'  => array(
		101 => new WC_Product( array( 'id' => 101, 'type' => 'variation', 'attributes' => array( 'pa_peso' => '50g' ), 'length' => '5', 'width' => '5', 'height' => '6.5', 'price' => '30' ) ),
		102 => new WC_Product(
			array(
				'id'         => 102,
				'type'       => 'variation',
				'attributes' => array( 'pa_peso' => '190g' ),
				'length'     => '8',
				'width'      => '8',
				'height'     => '8.5',
				'price'      => '60',
				'meta'       => array( '_galaxie_gift_length' => '7.8', '_galaxie_gift_width' => '7.8', '_galaxie_gift_height' => '8.4' ),
			)
		),
		201 => new WC_Product( array( 'id' => 201, 'type' => 'variation', 'attributes' => array( 'pa_peso' => '190g' ), 'length' => '10', 'width' => '10', 'height' => '10', 'price' => '1' ) ),
	),
);

if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( $taxonomy ): bool {
		return 'pa_peso' === $taxonomy;
	}
}

if ( ! function_exists( 'get_terms' ) ) {
	function get_terms( $args ) {
		return 'pa_peso' === ( $args['taxonomy'] ?? '' )
			? array( (object) array( 'slug' => '50g', 'name' => '50g' ), (object) array( 'slug' => '190g', 'name' => '190g' ) )
			: array();
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args ) {
		$store = $GLOBALS['galaxie_stub_store'];

		if ( 'product' === ( $args['post_type'] ?? '' ) ) {
			return array_values( array_intersect( (array) ( $args['post__in'] ?? array() ), $store['published'] ) );
		}

		$value = $args['meta_query'][0]['value'] ?? '';
		$rows  = array();

		foreach ( $store['products'] as $id => $product ) {
			if ( ( $product->get_attributes()['pa_peso'] ?? '' ) === $value ) {
				$rows[] = (object) array( 'ID' => $id, 'post_parent' => $store['parents'][ $id ] );
			}
		}

		return $rows;
	}
}

if ( ! function_exists( 'wc_get_product' ) ) {
	function wc_get_product( $id ) {
		return $GLOBALS['galaxie_stub_store']['products'][ (int) $id ] ?? false;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration = 0 ): bool {
		return true;
	}
}

$flat = static fn( array $sizes ): array => array_map( static fn( array $s ): array => array( $s['size'], (float) $s['length'], (float) $s['width'], (float) $s['height'], $s['label'] ), $sizes );

$check( 'store_sizes', 'one size per term, gift dimensions win, drafts ignored', $flat( GiftPacking::store_sizes( 'pa_peso' ) ), array( array( '50g', 5.0, 5.0, 6.5, '50g' ), array( '190g', 7.8, 7.8, 8.4, '190g' ) ) );
$check( 'store_sizes', 'a list, for the data endpoint', array_is_list( GiftPacking::store_sizes( 'pa_peso' ) ), true );
$check( 'store_sizes', '"peso" is no taxonomy: nothing (Module::size_attribute() reads it as pa_peso)', GiftPacking::store_sizes( 'peso' ), array() );

// Timing, not pass/fail: the slowest 12-candle cases.
echo "\n  timing (best of 5):\n";
$time = static function ( string $label, callable $run ): void {
	$best = INF;
	for ( $i = 0; $i < 5; $i++ ) {
		$start = hrtime( true );
		$run();
		$best = min( $best, ( hrtime( true ) - $start ) / 1e6 );
	}
	printf( "    %-58s %8.2f ms\n", $label, $best );
};

$up   = array( 'orientation' => 'upright', 'gap' => 0.5 );
$lie  = array( 'orientation' => 'lying', 'gap' => 0.5 );
$any  = array( 'orientation' => 'any', 'gap' => 0.5 );
$mix6 = $expand( array( array( '50g', 6 ), array( '190g', 6 ) ) );
$b25  = $boxes['b25'];
$b30  = $boxes['b30'];

$time( 'upright: fits 6 x 190g + 6 x 50g in 25 x 25 (no)', static fn() => GiftPacking::fits( $b25, $mix6, $up ) );
$time( 'upright: fits 6 x 190g + 6 x 50g in 30 x 30 (yes)', static fn() => GiftPacking::fits( $b30, $mix6, $up ) );
$time( 'upright: fits 12 x 50g in 21 x 21 (no)', static fn() => GiftPacking::fits( $boxes['sq21'], $expand( array( array( '50g', 12 ) ) ), $up ) );
$time( 'upright: arrange 8 x 50g + 4 x 190g over 3 boxes', static fn() => GiftPacking::arrange( $expand( array( array( '50g', 8 ), array( '190g', 4 ) ) ), array( $boxes['p11'], $boxes['b14'], $boxes['sq14'] ), $up ) );
$time( 'upright: summary b30', static fn() => GiftPacking::summary( $b30, array( $sizes['50g'], $sizes['190g'] ), $up ) );
$time( 'lying: fits 6 x 190g + 6 x 50g in 30 x 30', static fn() => GiftPacking::fits( $b30, $mix6, $lie ) );
$time( 'lying: fits 6 x 190g + 6 x 50g in 25 x 25', static fn() => GiftPacking::fits( $b25, $mix6, $lie ) );
$time( 'lying, gift dims, gap 0: summary 24.44 x 8.94 x 7.94', static fn() => GiftPacking::summary( $boxes['real-long'], array( $sizes['50g-gift'], $sizes['190g-gift'] ), array( 'orientation' => 'lying', 'gap' => 0 ) ) );
$time( 'any: fits 6 x 190g + 6 x 50g in 30 x 30', static fn() => GiftPacking::fits( $b30, $mix6, $any ) );
$time( 'any: fits 6 x 190g + 6 x 50g in 25 x 25', static fn() => GiftPacking::fits( $b25, $mix6, $any ) );
$time( 'any: fits 12 x 50g in 21 x 21', static fn() => GiftPacking::fits( $boxes['sq21'], $expand( array( array( '50g', 12 ) ) ), $any ) );
$time( 'any: arrange 8 x 50g + 4 x 190g over 3 boxes', static fn() => GiftPacking::arrange( $expand( array( array( '50g', 8 ), array( '190g', 4 ) ) ), array( $boxes['p11'], $boxes['b14'], $boxes['sq14'] ), $any ) );
$time( 'any: summary b30', static fn() => GiftPacking::summary( $b30, array( $sizes['50g'], $sizes['190g'] ), $any ) );

echo "\n  {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
