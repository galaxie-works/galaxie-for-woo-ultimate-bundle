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

$up   = array( 'orientation' => 'upright' );
$lie  = array( 'orientation' => 'lying' );
$any  = array( 'orientation' => 'any' );
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
$time( 'lying: summary 25 x 9.5 x 8.5', static fn() => GiftPacking::summary( $boxes['long25'], array( $sizes['50g'], $sizes['190g'] ), $lie ) );
$time( 'any: fits 6 x 190g + 6 x 50g in 30 x 30', static fn() => GiftPacking::fits( $b30, $mix6, $any ) );
$time( 'any: fits 6 x 190g + 6 x 50g in 25 x 25', static fn() => GiftPacking::fits( $b25, $mix6, $any ) );
$time( 'any: fits 12 x 50g in 21 x 21', static fn() => GiftPacking::fits( $boxes['sq21'], $expand( array( array( '50g', 12 ) ) ), $any ) );
$time( 'any: arrange 8 x 50g + 4 x 190g over 3 boxes', static fn() => GiftPacking::arrange( $expand( array( array( '50g', 8 ), array( '190g', 4 ) ) ), array( $boxes['p11'], $boxes['b14'], $boxes['sq14'] ), $any ) );
$time( 'any: summary b30', static fn() => GiftPacking::summary( $b30, array( $sizes['50g'], $sizes['190g'] ), $any ) );

echo "\n  {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
