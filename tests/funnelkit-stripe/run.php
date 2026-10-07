<?php
/**
 * FunnelKit Stripe tests: `php tests/funnelkit-stripe/run.php`.
 *
 * Covers `Integrations\FunnelKitStripe` (Pix completed from the webhook, once;
 * never revived once cancelled; pending Pix kept from the unpaid-order sweep,
 * counted from the latest QR code), `Integrations\FunnelKitPixTaxId` (the CPF
 * printed only on checkout / order-pay), `Modules\FunnelKitExpress` (product
 * and cart wallets only for a ready customer), `Core\FreshNonces::nonces_only()`,
 * `Wishlist\Gifts::
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
function wc_get_order( $id ) { return $GLOBALS['gx_orders'][ (int) $id ] ?? false; }
function get_current_user_id() { return (int) ( $GLOBALS['gx_user'] ?? 0 ); }
function get_user_meta( $user_id, $key, $single = false ) { return $GLOBALS['gx_user_meta'][ (int) $user_id ][ $key ] ?? ''; }
function is_admin() { return ! empty( $GLOBALS['gx_admin'] ); }
function wp_doing_ajax() { return false; }
function remove_action( $hook, $callback, $priority = 10 ) {
	foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks[ $priority ] ?? array() as $id => $entry ) {
		if ( $entry['function'] === $callback ) {
			unset( $GLOBALS['wp_filter'][ $hook ]->callbacks[ $priority ][ $id ] );
			return true;
		}
	}
	return false;
}
function is_product() { return 'product' === ( $GLOBALS['gx_page'] ?? '' ); }
function is_cart() { return 'cart' === ( $GLOBALS['gx_page'] ?? '' ); }
function is_checkout() { return in_array( $GLOBALS['gx_page'] ?? '', array( 'checkout', 'order-pay', 'order-received' ), true ); }
function is_wc_endpoint_url( $endpoint = '' ) { return $endpoint === ( $GLOBALS['gx_page'] ?? '' ); }
function wp_script_is( $handle, $list = 'enqueued' ) { return ! empty( $GLOBALS['gx_enqueued'] ); }
function wp_add_inline_script( $handle, $data, $position = 'after' ) { $GLOBALS['gx_inline'][] = $data; return true; }
function wp_json_encode( $data ) { return json_encode( $data ); }
function wp_dequeue_script( $handle ) { $GLOBALS['gx_dequeued'][] = $handle; }

/** FunnelKit's SmartButtons, as far as the express gate looks at it. */
eval( 'namespace FKWCS\Gateway\Stripe; class SmartButtons { private static $i; public static function get_instance() { return self::$i ??= new self(); } public function get_resolved_checkout_hook() { return "woocommerce_checkout_before_customer_details"; } public function payment_request_button() {} }' );

/** The age gate at 18, without the plugin behind it. */
eval( 'namespace Galaxie\Woo\Modules\AgeGate; final class Module { public static function check( string $date ): ?string { return $date <= date( "Y-m-d", strtotime( "-18 years" ) ) ? null : "menor de idade"; } }' );
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

use Galaxie\Woo\Core\FreshNonces;
use Galaxie\Woo\Integrations\FunnelKitPixTaxId;
use Galaxie\Woo\Integrations\FunnelKitStripe;
use Galaxie\Woo\Modules\FunnelKitExpress\Module as FunnelKitExpress;
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
$db->stored[101] = 'wc-cancelled';
$stale           = new WC_Order(); // FunnelKit loaded it pending; the sweep cancelled it since
FunnelKitStripe::pix_paid( $intent(), $stale );
$check( 'pix', 'cancelled in the database meanwhile: not completed, one refund note', array( $gw->completed, count( $stale->notes ) ), array( array(), 1 ) );

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
$check( 'serialize', 'still pending in the database: statuses kept, but never cancelled', FunnelKitStripe::serialize_completion( $statuses, new WC_Order() ), array( 'on-hold', 'pending', 'failed' ) );
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

// CP-12: WooCommerce 10.9+ lets payment_complete() move a cancelled order to processing.
$db              = $fresh( null );
$db->stored[101] = 'wc-cancelled';
$late            = new WC_Order( 101, 'pending' ); // loaded before the sweep cancelled it
$late->meta['_fkwcs_intent_id'] = array( 'id' => 'pi_9' );
$check( 'serialize', 'cancelled in the database (return URL): no valid status', FunnelKitStripe::serialize_completion( $statuses, $late ), array() );
$check( 'serialize', 'cancelled: refund note, naming the intent', count( $late->notes ) === 1 && false !== strpos( $late->notes[0], 'pi_9' ) && false !== strpos( $late->notes[0], 'estorne manualmente' ), true );
$check( 'serialize', 'cancelled: lock let go at once', count( $db->held ), 0 );
FunnelKitStripe::serialize_completion( $statuses, $late );
$check( 'serialize', 'cancelled: the note is written once (thank-you page after return URL)', count( $late->notes ), 1 );

$db = $fresh( null ); // status unknown in the database
$check( 'serialize', 'cancelled on the object, unknown in the database: blocked', FunnelKitStripe::serialize_completion( $statuses, new WC_Order( 101, 'cancelled' ) ), array() );
$check( 'serialize', 'card order keeps cancelled (not ours)', FunnelKitStripe::serialize_completion( $statuses, new WC_Order( 101, 'cancelled', 'fkwcs_stripe' ) ), $statuses );

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

// CP-12: counted from the latest QR code, not from the order's creation.
$retried = new WC_Order();
$retried->created = time() - 30 * 3600;
$retried->meta[ FunnelKitStripe::META_ATTEMPT_AT ] = (string) ( time() - 2 * 3600 );
$check( 'sweep', 'order 30 h old, Pix retried 2 h ago: kept', FunnelKitStripe::keep_pending_pix( true, $retried ), false );
$retried->meta[ FunnelKitStripe::META_ATTEMPT_AT ] = (string) ( time() - ( FunnelKitStripe::PIX_EXPIRY + FunnelKitStripe::CANCEL_GRACE + 60 ) );
$check( 'sweep', 'retried past expiry + grace: cancelled', FunnelKitStripe::keep_pending_pix( true, $retried ), true );

$saving = new WC_Order();
$saving->meta['_fkwcs_intent_id'] = array( 'id' => 'pi_new', 'client_secret' => 'x' );
FunnelKitStripe::note_intent( $saving );
$check( 'attempt', 'a new intent on save: time and intent recorded', array( $saving->meta[ FunnelKitStripe::META_ATTEMPT_INTENT ] ?? null, abs( (int) ( $saving->meta[ FunnelKitStripe::META_ATTEMPT_AT ] ?? 0 ) - time() ) <= 2 ), array( 'pi_new', true ) );
$saving->meta[ FunnelKitStripe::META_ATTEMPT_AT ] = '100';
FunnelKitStripe::note_intent( $saving );
$check( 'attempt', 'same intent saved again: time kept', $saving->meta[ FunnelKitStripe::META_ATTEMPT_AT ], '100' );
$card = new WC_Order( 101, 'pending', 'fkwcs_stripe' );
$card->meta['_fkwcs_intent_id'] = array( 'id' => 'pi_card' );
FunnelKitStripe::note_intent( $card );
$check( 'attempt', 'card order: nothing recorded', isset( $card->meta[ FunnelKitStripe::META_ATTEMPT_AT ] ), false );

$GLOBALS['gx_orders'][101] = $saving;
$result = array( 'result' => 'success', 'fkwcs_intent_secret' => 'x' );
$check( 'attempt', 'successful result passed through untouched', FunnelKitStripe::note_attempt( $result, 101 ), $result );
$check( 'attempt', 'reused intent, new attempt: time renewed and saved', array( (int) $saving->meta[ FunnelKitStripe::META_ATTEMPT_AT ] > 100, $saving->meta_saved ), array( true, true ) );
unset( $GLOBALS['gx_orders'][101] );

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

// ---------------------------------------------------------------------------
echo "Pix tax id script only where Pix is paid (CP-13)\n";

$GLOBALS['gx_user']         = 7;
$GLOBALS['gx_user_meta'][7] = array( 'eir_cpf' => '529.982.247-25' );
$GLOBALS['gx_page']         = 'product';
$check( 'taxid', 'product page: not a payment page', FunnelKitPixTaxId::on_payment_page(), false );
$GLOBALS['gx_page'] = 'cart';
$check( 'taxid', 'cart: not a payment page', FunnelKitPixTaxId::on_payment_page(), false );
$GLOBALS['gx_page'] = 'checkout';
$check( 'taxid', 'checkout form: payment page', FunnelKitPixTaxId::on_payment_page(), true );
$GLOBALS['gx_page'] = 'order-pay';
$check( 'taxid', 'order-pay: payment page', FunnelKitPixTaxId::on_payment_page(), true );
$GLOBALS['gx_page'] = 'order-received';
$check( 'taxid', 'order received: not a payment page', FunnelKitPixTaxId::on_payment_page(), false );
$GLOBALS['gx_enqueued'] = true;
$GLOBALS['gx_inline']   = array();
$GLOBALS['gx_page']     = 'product';
FunnelKitPixTaxId::pix_tax_id();
$check( 'taxid', "product page: no CPF printed, though FunnelKit's script is there", $GLOBALS['gx_inline'], array() );
$GLOBALS['gx_page'] = 'checkout';
FunnelKitPixTaxId::pix_tax_id();
$check( 'taxid', "checkout: listener printed with the customer's CPF", count( $GLOBALS['gx_inline'] ) === 1 && false !== strpos( $GLOBALS['gx_inline'][0], '"52998224725"' ), true );

// ---------------------------------------------------------------------------
echo "Express buttons for ready customers only (CP-01)\n";

$GLOBALS['gx_user'] = 0;
$check( 'express', 'visitor: not ready', FunnelKitExpress::customer_ready( 0 ), false );
$GLOBALS['gx_user_meta'][7] = array();
$check( 'express', 'signed in, no CPF, no birth date: not ready', FunnelKitExpress::customer_ready( 7 ), false );
$GLOBALS['gx_user_meta'][7] = array( 'eir_cpf' => '529.982.247-25' );
$check( 'express', 'CPF but no birth date: not ready', FunnelKitExpress::customer_ready( 7 ), false );
$GLOBALS['gx_user_meta'][7] = array( 'eir_cpf' => '111.111.111-11', 'eir_birthdate' => '1990-05-01' );
$check( 'express', 'invalid CPF: not ready', FunnelKitExpress::customer_ready( 7 ), false );
$GLOBALS['gx_user_meta'][7] = array( 'eir_cpf' => '529.982.247-25', 'eir_birthdate' => '2015-05-01' );
$check( 'express', 'birth date the age gate refuses: not ready', FunnelKitExpress::customer_ready( 7 ), false );
$GLOBALS['gx_user_meta'][7] = array( 'billing_cpf' => '52998224725', 'eir_birthdate' => '1990-05-01' );
$check( 'express', "CPF from the Brazilian plugin's meta + birth date: ready", FunnelKitExpress::customer_ready( 7 ), true );

$sb   = \FKWCS\Gateway\Stripe\SmartButtons::get_instance();
$hook = static function ( string $name, int $priority ) use ( $sb ): void {
	$GLOBALS['wp_filter'][ $name ] = (object) array( 'callbacks' => array( $priority => array( 'fk' => array( 'function' => array( $sb, 'payment_request_button' ), 'accepted_args' => 1 ) ) ) );
};
$arm  = static function () use ( $hook ): void {
	$GLOBALS['wp_filter'] = array();
	$hook( 'woocommerce_after_add_to_cart_button', 1 );
	$hook( 'woocommerce_proceed_to_checkout', 1 );
	$hook( 'woocommerce_checkout_before_customer_details', 5 );
	// Someone else's callback on the same hook stays.
	$GLOBALS['wp_filter']['woocommerce_proceed_to_checkout']->callbacks[20]['wc'] = array( 'function' => 'woocommerce_button_proceed_to_checkout', 'accepted_args' => 1 );
};
$left = static fn( string $name ): int => array_sum( array_map( 'count', $GLOBALS['wp_filter'][ $name ]->callbacks ?? array() ) );

$arm();
$GLOBALS['gx_user'] = 0;
$check( 'express', 'visitor: product and cart wallets unhooked', FunnelKitExpress::gate(), 2 );
$check( 'express', 'visitor: product hook empty', $left( 'woocommerce_after_add_to_cart_button' ), 0 );
$check( 'express', "visitor: WooCommerce's own cart button kept", $left( 'woocommerce_proceed_to_checkout' ), 1 );
$check( 'express', 'visitor: checkout wallet kept', $left( 'woocommerce_checkout_before_customer_details' ), 1 );

$arm();
$GLOBALS['gx_user']         = 7;
$GLOBALS['gx_user_meta'][7] = array( 'eir_cpf' => '529.982.247-25' );
$check( 'express', 'signed in without birth date: unhooked too', FunnelKitExpress::gate(), 2 );

$arm();
$GLOBALS['gx_user_meta'][7] = array( 'eir_cpf' => '529.982.247-25', 'eir_birthdate' => '1990-05-01' );
$check( 'express', 'ready customer: nothing unhooked', array( FunnelKitExpress::gate(), $left( 'woocommerce_after_add_to_cart_button' ) ), array( 0, 1 ) );

$arm();
$GLOBALS['gx_user']  = 0;
$GLOBALS['gx_admin'] = true;
$check( 'express', 'wp-admin screen: left alone', FunnelKitExpress::gate(), 0 );
$GLOBALS['gx_admin'] = false;

$GLOBALS['gx_filters']['fkwcs_express_button_cart_position'] = static fn() => 'galaxie_custom_cart_spot';
$arm();
$hook( 'galaxie_custom_cart_spot', 3 );
FunnelKitExpress::gate();
$check( 'express', "a cart position moved by FunnelKit's filter is followed", $left( 'galaxie_custom_cart_spot' ), 0 );
unset( $GLOBALS['gx_filters']['fkwcs_express_button_cart_position'] );

$GLOBALS['gx_dequeued'] = array();
$GLOBALS['gx_page']     = 'product';
FunnelKitExpress::dequeue();
$check( 'express', 'visitor on a product page: express script dequeued', $GLOBALS['gx_dequeued'], array( 'fkwcs-express-checkout-js' ) );
$GLOBALS['gx_dequeued'] = array();
$GLOBALS['gx_page']     = 'checkout';
FunnelKitExpress::dequeue();
$check( 'express', 'checkout: script kept', $GLOBALS['gx_dequeued'], array() );

// ---------------------------------------------------------------------------
echo "Fresh nonces for cached pages (CP-04)\n";

$boot = array(
	'auth'              => array( 'ajaxUrl' => 'https://shop.test/wp-admin/admin-ajax.php', 'nonce' => 'a1', 'mode' => 'otp' ),
	'variationSwatches' => array( 'attributes' => array( 'pa_peso' ), 'buyBox' => array( 'ajaxUrl' => 'x', 'nonce' => 'b2' ) ),
	'ageGate'           => array( 'minAge' => 18 ),
	'stripeCards'       => array( 'intentNonce' => 'c3', 'saveNonce' => 'd4', 'key' => 'pk' ),
	'legal'             => array( 'terms' => array( 'url' => 'u' ) ),
);
$check(
	'nonces',
	'only the nonces, with their keys',
	FreshNonces::nonces_only( $boot ),
	array(
		'auth'              => array( 'nonce' => 'a1' ),
		'variationSwatches' => array( 'buyBox' => array( 'nonce' => 'b2' ) ),
		'stripeCards'       => array( 'intentNonce' => 'c3', 'saveNonce' => 'd4' ),
	)
);

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
