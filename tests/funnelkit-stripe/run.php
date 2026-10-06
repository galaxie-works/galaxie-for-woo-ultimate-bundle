<?php
/**
 * FunnelKit Stripe tests: `php tests/funnelkit-stripe/run.php`.
 *
 * Covers `Integrations\FunnelKitStripe` (Pix completed from the webhook, once;
 * pending Pix kept from the unpaid-order sweep), `Wishlist\Gifts::
 * funnelkit_request()` (no recipient address in FunnelKit's PaymentIntent) and
 * `OrderCancellation\Module::eligible()` (a paid order only). No WordPress and
 * no FunnelKit: a plain runner on the stubs below, with a fake order, gateway
 * and `$wpdb`. Exits 1 on any failure.
 *
 * @package Galaxie\Woo
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'FKWCS_VERSION', '1.15.0.1' );

function __( $text, $domain = null ) { return $text; }
function add_action( ...$args ) { return true; }
function add_filter( ...$args ) { return true; }
function apply_filters( $hook, $value, ...$args ) {
	return isset( $GLOBALS['gx_filters'][ $hook ] ) ? $GLOBALS['gx_filters'][ $hook ]( $value, ...$args ) : $value;
}
function wc_get_is_paid_statuses() { return array( 'processing', 'completed' ); }
function wc_get_logger() {
	return new class() {
		public function log( $level, $message, $context = array() ) { $GLOBALS['gx_log'][] = "$level: $message"; }
	};
}

/** Just the WC_Order surface the code under test reads. */
class WC_Order {
	public array $notes = array();
	public array $meta  = array();
	public ?object $date_paid = null;
	public int $created;
	public bool $meta_saved = false;

	public function __construct(
		public int $id = 101,
		public string $status = 'pending',
		public string $method = 'fkwcs_stripe_pix',
		public string $total = '129.90',
		public string $currency = 'BRL',
		public int $customer = 7
	) {
		$this->created = time();
	}

	public function get_id() { return $this->id; }
	public function get_payment_method() { return $this->method; }
	public function get_total() { return $this->total; }
	public function get_currency() { return $this->currency; }
	public function get_customer_id() { return $this->customer; }
	public function get_date_paid() { return $this->date_paid; }
	public function is_paid() { return in_array( $this->status, wc_get_is_paid_statuses(), true ); }
	public function has_status( $status ) { return in_array( $this->status, (array) $status, true ); }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function save_meta_data() { $this->meta_saved = true; }
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function get_date_created() {
		$created = $this->created;
		return new class( $created ) {
			public function __construct( private int $ts ) {}
			public function getTimestamp() { return $this->ts; }
		};
	}
}

/** $wpdb: named locks and a stored status per order. */
class GxWpdb {
	public string $prefix = 'wp_';
	public string $posts  = 'wp_posts';
	public array $stored  = array();
	public array $held    = array();
	public array $queries = array();

	public function prepare( $sql, ...$args ) {
		foreach ( $args as $arg ) {
			$sql = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . $arg . "'", $sql, 1 );
		}
		return $sql;
	}

	public function get_var( $sql ) {
		$this->queries[] = $sql;
		if ( preg_match( "/GET_LOCK\\('([^']+)'/", $sql, $m ) ) {
			if ( isset( $this->held[ $m[1] ] ) ) {
				return '0';
			}
			$this->held[ $m[1] ] = true;
			return '1';
		}
		if ( preg_match( '/ID = (\d+)/', $sql, $m ) ) {
			return $this->stored[ (int) $m[1] ] ?? null;
		}
		return null;
	}

	public function query( $sql ) {
		$this->queries[] = $sql;
		if ( preg_match( "/RELEASE_LOCK\\('([^']+)'/", $sql, $m ) ) {
			unset( $this->held[ $m[1] ] );
		}
		return 1;
	}
}

/** The Pix gateway: hands back the charge it was given, records completions. */
class GxGateway {
	public array $completed = array();
	public function __construct( public $charge = null ) {}
	public function get_latest_charge_from_intent( $intent ) { return $this->charge; }
	public function process_final_order( $charge, $order_id ) { $this->completed[] = array( $charge->id, $order_id ); return ''; }
}

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'Galaxie\\Woo\\';
		if ( 0 !== strncmp( $prefix, $class, strlen( $prefix ) ) ) {
			return;
		}
		$path = dirname( __DIR__, 2 ) . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

use Galaxie\Woo\Integrations\FunnelKitStripe;
use Galaxie\Woo\Modules\OrderCancellation\Module as OrderCancellation;
use Galaxie\Woo\Modules\Wishlist\Gifts;

$failed = 0;
$passed = 0;

$check = static function ( string $group, string $name, $actual, $expect ) use ( &$failed, &$passed ): void {
	if ( $actual === $expect ) {
		++$passed;
		echo "  ok    {$group}: {$name}\n";
		return;
	}
	++$failed;
	echo "  FAIL  {$group}: {$name}\n        expected " . json_encode( $expect ) . "\n        got      " . json_encode( $actual ) . "\n";
};

$intent = static fn( array $over = array() ): object => (object) array_merge(
	array(
		'id'              => 'pi_1',
		'object'          => 'payment_intent',
		'status'          => 'succeeded',
		'amount'          => 12990,
		'amount_received' => 12990,
		'currency'        => 'brl',
		'latest_charge'   => 'ch_1',
	),
	$over
);
$charge = (object) array( 'id' => 'ch_1', 'captured' => true, 'status' => 'succeeded' );

$fresh = static function ( $gateway ) {
	$GLOBALS['wpdb']          = new GxWpdb();
	FunnelKitStripe::$gateway = static fn() => $gateway;
	return $GLOBALS['wpdb'];
};

// ---------------------------------------------------------------------------
echo "Pix completed from the webhook\n";

$gw    = new GxGateway( $charge );
$db    = $fresh( $gw );
$order = new WC_Order();
$order->meta['_fkwcs_intent_id'] = array( 'id' => 'pi_1' );
FunnelKitStripe::pix_paid( $intent(), $order );
$check( 'pix', 'pending Pix order is completed through the gateway', $gw->completed, array( array( 'ch_1', 101 ) ) );
$check( 'pix', 'a webhook note is written', count( $order->notes ), 1 );

$gw = new GxGateway( $charge );
$fresh( $gw );
FunnelKitStripe::pix_paid( $intent(), new WC_Order( 101, 'pending', 'fkwcs_stripe' ) );
$check( 'pix', 'card order is left to FunnelKit', $gw->completed, array() );

$gw = new GxGateway( $charge );
$fresh( $gw );
FunnelKitStripe::pix_paid( $intent( array( 'status' => 'processing' ) ), new WC_Order() );
$check( 'pix', 'intent not succeeded: nothing', $gw->completed, array() );

$gw    = new GxGateway( $charge );
$fresh( $gw );
$order = new WC_Order( 101, 'processing' );
$order->date_paid = new stdClass();
FunnelKitStripe::pix_paid( $intent(), $order );
$check( 'pix', 'already paid: not completed again', $gw->completed, array() );
$check( 'pix', 'already paid, same intent: no note', $order->notes, array() );

$order = new WC_Order( 101, 'processing' );
$order->date_paid = new stdClass();
$order->meta['_fkwcs_intent_id'] = array( 'id' => 'pi_2' );
FunnelKitStripe::pix_paid( $intent(), $order );
$check( 'pix', 'already paid, another intent: duplicate-payment note', count( $order->notes ), 1 );

$gw    = new GxGateway( $charge );
$fresh( $gw );
$order = new WC_Order( 101, 'cancelled' );
FunnelKitStripe::pix_paid( $intent(), $order );
FunnelKitStripe::pix_paid( $intent(), $order );
$check( 'pix', 'cancelled: not revived', $gw->completed, array() );
$check( 'pix', 'cancelled: one note, however many deliveries', count( $order->notes ), 1 );
$check( 'pix', 'cancelled: flag saved', $order->meta_saved && '' !== $order->get_meta( FunnelKitStripe::META_PAID_AFTER_CANCEL ), true );

$gw    = new GxGateway( $charge );
$fresh( $gw );
$order = new WC_Order();
FunnelKitStripe::pix_paid( $intent( array( 'amount_received' => 100 ) ), $order );
$check( 'pix', 'amount short of the total: not completed', $gw->completed, array() );
$check( 'pix', 'amount short of the total: note', count( $order->notes ), 1 );

$gw = new GxGateway( $charge );
$fresh( $gw );
FunnelKitStripe::pix_paid( $intent( array( 'currency' => 'usd' ) ), new WC_Order() );
$check( 'pix', 'other currency: not completed', $gw->completed, array() );

$gw = new GxGateway( (object) array( 'id' => 'ch_1', 'captured' => false, 'status' => 'succeeded' ) );
$fresh( $gw );
FunnelKitStripe::pix_paid( $intent(), new WC_Order() );
$check( 'pix', 'charge not captured: not completed', $gw->completed, array() );

$gw              = new GxGateway( $charge );
$db              = $fresh( $gw );
$db->stored[101] = 'wc-processing';
FunnelKitStripe::pix_paid( $intent(), new WC_Order() );
$check( 'pix', 'saved as processing by the return URL meanwhile: skipped', $gw->completed, array() );

$fresh( null );
$order = new WC_Order();
FunnelKitStripe::pix_paid( $intent(), $order );
$check( 'pix', 'gateway not loaded: order left alone', $order->notes, array() );

// ---------------------------------------------------------------------------
echo "One completion, whichever path gets there first\n";

$db              = $fresh( null );
$db->stored[101] = 'wc-pending';
$statuses        = array( 'on-hold', 'pending', 'failed', 'cancelled' );
$check( 'serialize', 'still pending in the database: statuses kept', FunnelKitStripe::serialize_completion( $statuses, new WC_Order() ), $statuses );
$check( 'serialize', 'lock held until payment_complete', count( $db->held ), 1 );
FunnelKitStripe::release( 101 );
$check( 'serialize', 'released on woocommerce_payment_complete', count( $db->held ), 0 );

$db->stored[101] = 'wc-processing';
$check( 'serialize', 'already processing in the database: no valid status', FunnelKitStripe::serialize_completion( $statuses, new WC_Order() ), array() );
$check( 'serialize', 'and the lock is let go at once', count( $db->held ), 0 );

$db = $fresh( null );
$check( 'serialize', 'card order: untouched, no lock', array( FunnelKitStripe::serialize_completion( $statuses, new WC_Order( 101, 'pending', 'fkwcs_stripe' ) ), count( $db->queries ) ), array( $statuses, 0 ) );

$db              = $fresh( null );
$db->stored[101] = 'wc-pending';
FunnelKitStripe::serialize_completion( $statuses, new WC_Order() );
FunnelKitStripe::release_all();
$check( 'serialize', 'shutdown lets every lock go', count( $db->held ), 0 );

// ---------------------------------------------------------------------------
echo "Unpaid-order sweep\n";

$young = new WC_Order();
$young->created = time() - 7200;
$check( 'sweep', 'Pix pending 2 h: kept', FunnelKitStripe::keep_pending_pix( true, $young ), false );

$old = new WC_Order();
$old->created = time() - ( FunnelKitStripe::PIX_EXPIRY + FunnelKitStripe::CANCEL_GRACE + 60 );
$check( 'sweep', 'Pix past expiry + grace: cancelled', FunnelKitStripe::keep_pending_pix( true, $old ), true );

$check( 'sweep', 'card order: cancelled as before', FunnelKitStripe::keep_pending_pix( true, new WC_Order( 101, 'pending', 'fkwcs_stripe' ) ), true );
$check( 'sweep', 'admin-created (false) stays false', FunnelKitStripe::keep_pending_pix( false, $young ), false );

$GLOBALS['gx_filters']['galaxie_woo/pix_expiry_seconds'] = static fn() => 3600;
$check( 'sweep', 'expiry filtered to 1 h: 2 h old is cancelled', FunnelKitStripe::keep_pending_pix( true, $young ), true );
unset( $GLOBALS['gx_filters']['galaxie_woo/pix_expiry_seconds'] );

// ---------------------------------------------------------------------------
echo "Gift orders: no recipient address to Stripe through FunnelKit\n";

$request = array(
	'amount'         => 12990,
	'metadata'       => array( 'order_id' => 101 ),
	'shipping'       => array( 'name' => 'Ana Lima', 'address' => array( 'line1' => 'Rua X, 1', 'postal_code' => '01001000' ) ),
	'amount_details' => array( 'line_items' => array( array( 'product_name' => 'Vela' ) ), 'shipping' => array( 'to_postal_code' => '01001000' ) ),
);

$gift = new WC_Order();
$gift->meta[ Gifts::ORDER_OWNER ] = 42;
$out = Gifts::funnelkit_request( $request, $gift );
$check( 'gift', 'shipping removed', array_key_exists( 'shipping', $out ), false );
$check( 'gift', 'amount_details CEP removed', array_key_exists( 'shipping', $out['amount_details'] ), false );
$check( 'gift', 'line items kept', $out['amount_details']['line_items'], $request['amount_details']['line_items'] );
$check( 'gift', 'rest of the request kept', array( $out['amount'], $out['metadata'] ), array( 12990, array( 'order_id' => 101 ) ) );
$check( 'gift', 'ordinary order: untouched', Gifts::funnelkit_request( $request, new WC_Order() ), $request );
$check( 'gift', 'setup intent (third arg, no order owner): untouched', Gifts::funnelkit_request( array( 'customer' => 'cus_1' ), new WC_Order() ), array( 'customer' => 'cus_1' ) );

// ---------------------------------------------------------------------------
echo "Refund-cancel only for a paid order\n";

$paid = new WC_Order( 101, 'processing' );
$paid->date_paid = new stdClass();
$check( 'cancel', 'processing and paid: eligible', OrderCancellation::eligible( $paid ), true );

$held = new WC_Order( 101, 'on-hold' );
$check( 'cancel', 'on-hold, never paid (Pix awaiting, transfer): not eligible', OrderCancellation::eligible( $held ), false );

$held->date_paid = new stdClass();
$check( 'cancel', 'paid, then put on hold by the store: eligible', OrderCancellation::eligible( $held ), true );

$check( 'cancel', 'pending: not ours (WooCommerce cancels it)', OrderCancellation::eligible( new WC_Order( 101, 'pending' ) ), false );

$guest = new WC_Order( 101, 'processing', 'fkwcs_stripe', '10', 'BRL', 0 );
$guest->date_paid = new stdClass();
$check( 'cancel', 'guest order: not eligible', OrderCancellation::eligible( $guest ), false );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
