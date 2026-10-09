<?php
/**
 * OrderReceived: the thank-you template shows an order only with its key, to its owner,
 * and WooCommerce's page still runs underneath with its order table taken off.
 * Run: php tests/order-received/run.php
 */
define( 'ABSPATH', __DIR__ );

class WC_Order {
	public function __construct( public int $id, public string $key, public int $customer ) {}
	public function get_order_key() { return $this->key; }
	public function get_customer_id() { return $this->customer; }
	public function get_id() { return $this->id; }
	public function get_payment_method() { return 'fkwcs_stripe'; }
}

$GLOBALS['endpoint'] = 'order-received';
$GLOBALS['wp']       = (object) array( 'query_vars' => array( 'order-received' => '42' ) );
$GLOBALS['orders']   = array( 42 => new WC_Order( 42, 'wc_order_abc', 7 ) );
$GLOBALS['user']     = 7;
$GLOBALS['filters']  = array();
$GLOBALS['actions']  = array( 'woocommerce_thankyou' => array( 'woocommerce_order_details_table' => 10 ) );
$GLOBALS['status']   = 'publish';
$GLOBALS['seen']     = array();

function is_wc_endpoint_url( $e ) { return $GLOBALS['endpoint'] === $e; }
function absint( $v ) { return abs( (int) $v ); }
function wc_clean( $v ) { return is_string( $v ) ? trim( strip_tags( $v ) ) : $v; }
function wp_unslash( $v ) { return $v; }
function wc_get_order( $id ) { return $GLOBALS['orders'][ $id ] ?? false; }
function get_current_user_id() { return $GLOBALS['user']; }
function get_post_status( $id ) { return $GLOBALS['status']; }
function add_filter( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['filters'][ $h ] = $cb; }
function remove_filter( $h, $cb, $p = 10 ) { unset( $GLOBALS['filters'][ $h ] ); }
function has_action( $h, $cb ) { return $GLOBALS['actions'][ $h ][ $cb ] ?? false; }
function remove_action( $h, $cb, $p ) { unset( $GLOBALS['actions'][ $h ][ $cb ] ); }
function add_action( $h, $cb, $p ) { $GLOBALS['actions'][ $h ][ $cb ] = $p; }
function do_action( $h, ...$a ) { $GLOBALS['seen'][] = $h; }
function do_shortcode( $s ) {
	// What WooCommerce's shortcode would do: locate its template through the filter.
	$GLOBALS['seen'][] = 'table=' . ( isset( $GLOBALS['actions']['woocommerce_thankyou']['woocommerce_order_details_table'] ) ? 'on' : 'off' );
	$located           = $GLOBALS['filters']['wc_get_template']( '/wc/thankyou.php', 'checkout/thankyou.php' );
	$order             = wc_get_order( 42 );
	ob_start();
	include $located;
	return '<div class="woocommerce">' . ob_get_clean() . '</div>';
}

class Elementor_Frontend { public function get_builder_content_for_display( $id, $css ) { return '<div class="tpl-' . $id . '">template</div>'; } }
class Elementor_Plugin_Stub { public $frontend; public function __construct() { $this->frontend = new Elementor_Frontend(); } }
// phpcs:ignore
eval( 'namespace Elementor; class Plugin { public static $instance; }' );
\Elementor\Plugin::$instance = new Elementor_Plugin_Stub();

require dirname( __DIR__, 2 ) . '/src/Modules/Checkout/OrderReceived.php';
use Galaxie\Woo\Modules\Checkout\OrderReceived;

$pass  = 0;
$fail  = 0;
$check = function ( $name, $got, $want ) use ( &$pass, &$fail ) {
	if ( $got === $want ) { $pass++; echo "  ok    $name\n"; } else { $fail++; echo "  FAIL  $name: got " . var_export( $got, true ) . "\n"; }
};

$_GET['key'] = 'wc_order_abc';
$check( 'right key, own order -> the order', OrderReceived::order()?->get_id(), 42 );

$_GET['key'] = 'wc_order_xyz';
$check( 'wrong key -> nothing', OrderReceived::order(), null );

unset( $_GET['key'] );
$check( 'no key -> nothing', OrderReceived::order(), null );

$_GET['key']     = 'wc_order_abc';
$GLOBALS['user'] = 8;
$check( 'someone else signed in -> nothing', OrderReceived::order(), null );
$GLOBALS['user'] = 7;

$GLOBALS['endpoint'] = 'order-pay';
$check( 'not the thank-you page -> nothing', OrderReceived::order(), null );
$GLOBALS['endpoint'] = 'order-received';

$check( 'no template picked -> native page', OrderReceived::render( 0 ), null );

$GLOBALS['status'] = 'draft';
$check( 'unpublished template -> native page', OrderReceived::render( 5 ), null );
$GLOBALS['status'] = 'publish';

$GLOBALS['seen'] = array();
$html            = OrderReceived::render( 5 );
$check( 'template first, WooCommerce extras after', 0 === strpos( (string) $html, '<div class="tpl-5">' ) && false !== strpos( (string) $html, 'galaxie-thankyou-extras' ), true );
$check( 'order table off while WooCommerce runs', $GLOBALS['seen'][0] ?? '', 'table=off' );
$check( 'thank-you hooks still fire, in order', array_slice( $GLOBALS['seen'], 1 ), array( 'woocommerce_before_thankyou', 'woocommerce_thankyou_fkwcs_stripe', 'woocommerce_thankyou' ) );
$check( 'order table back on afterwards', $GLOBALS['actions']['woocommerce_thankyou']['woocommerce_order_details_table'] ?? null, 10 );
$check( 'template swap removed afterwards', isset( $GLOBALS['filters']['wc_get_template'] ), false );

$_GET['key'] = 'wc_order_xyz';
$check( 'wrong key with a template -> native page', OrderReceived::render( 5 ), null );

echo "\n  $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
