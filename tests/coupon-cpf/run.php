<?php
/**
 * Coupons limited per CPF: Rules (the decision) — run: php tests/coupon-cpf/run.php
 */
define( 'ABSPATH', __DIR__ );

function __( $text, $domain = null ) { return $text; }

require dirname( __DIR__, 2 ) . '/src/Modules/CouponCpfLimit/Rules.php';
use Galaxie\Woo\Modules\CouponCpfLimit\Rules;

$pass  = 0;
$fail  = 0;
$check = function ( $name, $got, $want ) use ( &$pass, &$fail ) {
	if ( $got === $want ) { $pass++; echo "  ok    $name\n"; } else { $fail++; echo "  FAIL  $name: got " . var_export( $got, true ) . ', want ' . var_export( $want, true ) . "\n"; }
};

$order = static fn( string $status, array $codes = array() ): array => array( 'status' => $status, 'codes' => $codes );

// Coupon settings.
$check( 'no settings -> no rules (coupon untouched)', Rules::rules( '', '' ), null );
$check( 'limit 0 and first off -> no rules', Rules::rules( '0', 'no' ), null );
$check( 'limit read as int', Rules::rules( '1', '' ), array( 'limit' => 1, 'first' => false ) );
$check( 'negative limit -> no rules', Rules::rules( '-3', '' ), null );
$check( 'first purchase alone', Rules::rules( '', 'yes' ), array( 'limit' => 0, 'first' => true ) );

$one   = array( 'limit' => 1, 'first' => false );
$two   = array( 'limit' => 2, 'first' => false );
$first = array( 'limit' => 0, 'first' => true );

// One use per CPF.
$check( 'never used -> allowed', Rules::violation( $one, 'BEMVINDO', array() ), null );
$check( 'used once on a paid order -> limit', Rules::violation( $one, 'BEMVINDO', array( $order( 'processing', array( 'bemvindo' ) ) ) ), 'limit' );
$check( 'code compared case-insensitively', Rules::violation( $one, 'bemvindo', array( $order( 'completed', array( 'BemVindo' ) ) ) ), 'limit' );
$check( 'wc- prefixed status counts too', Rules::violation( $one, 'BEMVINDO', array( $order( 'wc-on-hold', array( 'BEMVINDO' ) ) ) ), 'limit' );
$check( 'cancelled order gives the use back', Rules::violation( $one, 'BEMVINDO', array( $order( 'cancelled', array( 'BEMVINDO' ) ) ) ), null );
$check( 'unpaid (pending) Pix does not count', Rules::violation( $one, 'BEMVINDO', array( $order( 'pending', array( 'BEMVINDO' ) ) ) ), null );
$check( 'failed / refunded do not count', Rules::violation( $one, 'BEMVINDO', array( $order( 'failed', array( 'BEMVINDO' ) ), $order( 'refunded', array( 'BEMVINDO' ) ) ) ), null );
$check( 'another coupon on earlier orders -> allowed', Rules::violation( $one, 'BEMVINDO', array( $order( 'completed', array( 'NATAL' ) ) ) ), null );

// Two uses per CPF.
$check( 'limit 2, used once -> allowed', Rules::violation( $two, 'X', array( $order( 'completed', array( 'X' ) ) ) ), null );
$check( 'limit 2, used twice -> limit', Rules::violation( $two, 'X', array( $order( 'completed', array( 'X' ) ), $order( 'processing', array( 'x' ) ) ) ), 'limit' );

// First purchase.
$check( 'first purchase, no orders -> allowed', Rules::violation( $first, 'BEMVINDO', array() ), null );
$check( 'first purchase, any paid order -> refused', Rules::violation( $first, 'BEMVINDO', array( $order( 'completed' ) ) ), 'first_purchase' );
$check( 'first purchase, only cancelled orders -> allowed', Rules::violation( $first, 'BEMVINDO', array( $order( 'cancelled' ), $order( 'pending' ) ) ), null );

// Messages and digits.
$check( 'message for limit 1', Rules::message( 'limit', 1 ), 'Este cupom já foi usado com este CPF.' );
$check( 'message for first purchase', Rules::message( 'first_purchase', 0 ), 'Este cupom vale só para a primeira compra.' );
$check( 'digits from formatted CPF', Rules::digits( '123.456.789-09' ), '12345678909' );

echo "\n  $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
