<?php
/**
 * FunnelKit Stripe: Pix orders confirmed by Stripe's webhook.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * "FunnelKit Payment Gateway for Stripe WooCommerce" (gateway ids `fkwcs_*`)
 * leaves a Pix order "Pendente" until the customer's own browser comes back to
 * the store: the return URL's `verify_intent()` or the thank-you page's
 * `maybe_mark_order_paid_on_thankyou()` are the only places that complete it.
 * Its webhook does receive `payment_intent.succeeded`, but returns early for
 * `fkwcs_stripe_pix` (includes/webhook.php, `payment_intent_succeeded()`)
 * unless the order carries `_fkwcs_maybe_check_for_auth` — a meta nothing in
 * 1.15.0.1 writes, read nowhere else. The card and P24 gateways complete their
 * orders from the webhook through `fkwcs_webhook_event_intent_succeeded`
 * (`handle_webhook_intent_succeeded()`); Pix has no such handler.
 *
 * So a customer who pays the QR code in their bank app and closes the tab
 * leaves a paid order pending, and WooCommerce's unpaid-order sweep
 * (`wc_cancel_unpaid_orders`, every "Hold stock (minutes)") then cancels it
 * and puts its stock back. This class closes both ends:
 *
 * 1. {@see self::pix_paid()} listens to the same action FunnelKit's card gateway
 *    does. It fires inside FunnelKit's webhook listener only after the Stripe
 *    signature was verified (`\Stripe\Webhook::constructEvent`) and the order
 *    was found from the intent (site URL checked), so nothing here is reachable
 *    without Stripe's signature — there is no endpoint of ours. It completes
 *    the order with the Pix gateway's own `process_final_order()`, exactly what
 *    the return URL would have run. Setting `_fkwcs_maybe_check_for_auth`
 *    instead would also get FunnelKit's generic branch to run, but that meta
 *    belongs to card mandates (`maybe_check_for_auth()`, Indian 3DS) and the
 *    branch would revive a cancelled order; the gateway's own completion is
 *    the path FunnelKit itself uses for card and P24.
 * 2. {@see self::serialize_completion()} makes completing a Pix order happen once
 *    whichever of the three paths (webhook, return URL, thank-you page) gets
 *    there first, even when two run at the same moment — each reads the status
 *    it loaded before the other saved, and WooCommerce would complete twice:
 *    two "processing" e-mails, two payment notes.
 * 3. {@see self::keep_pending_pix()} stops the unpaid-order sweep from cancelling
 *    a Pix order while its QR code can still be paid.
 *
 * Inert without FunnelKit: its action never fires, and the WooCommerce filters
 * only look at orders paid with `fkwcs_stripe_pix` while FunnelKit is loaded.
 */
final class FunnelKitStripe {

	public const PIX = 'fkwcs_stripe_pix';

	/**
	 * How long a Pix QR code can be paid, in seconds. FunnelKit 1.15.0.1 sends
	 * no `payment_method_options.pix.expires_after_seconds`, so Stripe's default
	 * applies: 86 400 s (24 h). Filter `galaxie_woo/pix_expiry_seconds` if the
	 * gateway starts sending one.
	 */
	public const PIX_EXPIRY = 86400;

	/** Past the expiry, for a payment Stripe confirms a few minutes late. */
	public const CANCEL_GRACE = 1800;

	/** Set once a Pix payment arrived for an order already cancelled, so the note is written once. */
	public const META_PAID_AFTER_CANCEL = '_galaxie_pix_paid_after_cancel';

	/** Seconds a second completion waits for the first to save before going ahead anyway. */
	private const LOCK_WAIT = 10;

	/**
	 * MySQL named locks this request holds, by order id.
	 *
	 * @var array<int,string>
	 */
	private static array $locks = array();

	/**
	 * Resolves the Pix gateway instance. Replaceable, for tests.
	 *
	 * @var callable|null
	 */
	public static $gateway = null;

	public static function hooks(): void {
		// Priority 20: after FunnelKit's own card/P24 handlers (10), which return for Pix.
		add_action( 'fkwcs_webhook_event_intent_succeeded', array( self::class, 'pix_paid' ), 20, 2 );
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( self::class, 'serialize_completion' ), PHP_INT_MAX, 2 );
		add_action( 'woocommerce_payment_complete', array( self::class, 'release' ), PHP_INT_MAX, 1 );
		add_action( 'shutdown', array( self::class, 'release_all' ) );
		add_filter( 'woocommerce_cancel_unpaid_order', array( self::class, 'keep_pending_pix' ), 20, 2 );
	}

	/** FunnelKit loads on `plugins_loaded` priority 0 and defines this as it does. */
	public static function active(): bool {
		return defined( 'FKWCS_VERSION' );
	}

	/**
	 * `payment_intent.succeeded` for a Pix order, from FunnelKit's verified
	 * webhook. Completes the order once, the way the return URL would have.
	 *
	 * Skipped — FunnelKit then returns early as it always did — when the order
	 * is not Pix, is already paid, or is not waiting for a payment. A payment
	 * for an order that was cancelled meanwhile is not turned back into a sale
	 * behind the store's back (its stock went back on sale): the store gets a
	 * note to refund it or reopen the order.
	 *
	 * Errors from Stripe (fetching the charge) are let through: FunnelKit's
	 * listener answers 500 and Stripe retries the event later.
	 *
	 * @param mixed $intent The event's PaymentIntent object.
	 * @param mixed $order  The order FunnelKit matched it to.
	 */
	public static function pix_paid( $intent, $order ): void {
		if ( ! $order instanceof \WC_Order || self::PIX !== $order->get_payment_method() || ! is_object( $intent ) ) {
			return;
		}

		if ( 'payment_intent' !== ( $intent->object ?? '' ) || 'succeeded' !== ( $intent->status ?? '' ) ) {
			return;
		}

		$stored = self::stored_intent_id( $order );
		$other  = '' !== $stored && (string) ( $intent->id ?? '' ) !== $stored;

		if ( null !== $order->get_date_paid() || $order->is_paid() ) {
			// A second Pix paid for the same order — a QR code from an earlier attempt.
			if ( $other ) {
				$order->add_order_note(
					sprintf(
						/* translators: %s: Stripe PaymentIntent id */
						__( 'Stripe confirmou outro pagamento Pix (%s) para este pedido, que já estava pago. Verifique no painel da Stripe e estorne o pagamento em duplicidade.', 'galaxie-woo' ),
						(string) ( $intent->id ?? '' )
					)
				);
			}

			return;
		}

		if ( $order->has_status( 'cancelled' ) ) {
			if ( '' === (string) $order->get_meta( self::META_PAID_AFTER_CANCEL ) ) {
				$order->update_meta_data( self::META_PAID_AFTER_CANCEL, (string) time() );
				$order->save_meta_data();
				$order->add_order_note(
					sprintf(
						/* translators: %s: Stripe PaymentIntent id */
						__( 'Stripe confirmou o pagamento Pix (%s) depois que o pedido foi cancelado. Estorne pelo painel da Stripe, ou reabra o pedido se ainda houver estoque.', 'galaxie-woo' ),
						(string) ( $intent->id ?? '' )
					)
				);
			}

			return;
		}

		if ( ! $order->has_status( array( 'pending', 'failed', 'on-hold' ) ) ) {
			return;
		}

		// The QR code was made for the order's total; anything else is not this order's payment.
		$expected = (int) round( (float) $order->get_total() * 100 );
		$received = (int) ( $intent->amount_received ?? $intent->amount ?? 0 );
		if ( $received < $expected || strtolower( (string) ( $intent->currency ?? '' ) ) !== strtolower( (string) $order->get_currency() ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: Stripe PaymentIntent id, 2: amount received in cents, 3: currency */
					__( 'Stripe confirmou um pagamento Pix (%1$s) de %2$d centavos (%3$s) que não corresponde ao total do pedido. O pedido não foi marcado como pago: confira no painel da Stripe.', 'galaxie-woo' ),
					(string) ( $intent->id ?? '' ),
					$received,
					strtoupper( (string) ( $intent->currency ?? '' ) )
				)
			);

			return;
		}

		$gateway = self::gateway();
		if ( ! is_object( $gateway ) || ! method_exists( $gateway, 'get_latest_charge_from_intent' ) || ! method_exists( $gateway, 'process_final_order' ) ) {
			self::log( 'warning', sprintf( 'Pix order %d: payment_intent.succeeded received, but the fkwcs_stripe_pix gateway is not loaded; the order was left as it was.', $order->get_id() ) );

			return;
		}

		// The webhook's intent has `latest_charge` (an id) on current API versions;
		// FunnelKit retrieves the charge from it, with the event's own mode.
		$charge = $gateway->get_latest_charge_from_intent( $intent );
		if ( ! is_object( $charge ) || empty( $charge->id ) || true !== ( $charge->captured ?? null ) || 'succeeded' !== ( $charge->status ?? '' ) ) {
			self::log( 'warning', sprintf( 'Pix order %d: intent %s succeeded but its charge is not a captured, succeeded charge; the order was left as it was.', $order->get_id(), (string) ( $intent->id ?? '' ) ) );

			return;
		}

		// The return URL may have completed it since FunnelKit loaded the order;
		// then FunnelKit's own notes would be written a second time for nothing.
		if ( in_array( self::stored_status( (int) $order->get_id() ), self::paid_statuses(), true ) ) {
			return;
		}

		self::log( 'info', sprintf( 'Pix order %d: completing from payment_intent.succeeded (%s).', $order->get_id(), (string) ( $intent->id ?? '' ) ) );

		// payment_complete() inside goes through serialize_completion(), which
		// re-reads the status under a lock: a return URL that got here first wins.
		$gateway->process_final_order( $charge, $order->get_id() );

		// Worded to be true whichever request saved the order paid.
		$order->add_order_note(
			$other
				/* translators: %s: Stripe PaymentIntent id */
				? sprintf( __( 'Webhook da Stripe: pagamento Pix recebido (%s), de uma tentativa anterior de pagamento deste pedido.', 'galaxie-woo' ), (string) ( $intent->id ?? '' ) )
				/* translators: %s: Stripe PaymentIntent id */
				: sprintf( __( 'Webhook da Stripe: pagamento Pix recebido (%s).', 'galaxie-woo' ), (string) ( $intent->id ?? '' ) )
		);
	}

	/**
	 * Inside `WC_Order::payment_complete()`, for a Pix order: take a named lock
	 * for the order, then read its status from the database, not from the
	 * object the caller loaded. If another request completed it meanwhile, no
	 * status is valid, and WooCommerce skips the completion (it fires
	 * `woocommerce_payment_complete_order_status_<status>` instead).
	 *
	 * The lock is MySQL's `GET_LOCK`: one per order, held until this request's
	 * `woocommerce_payment_complete` (after the order was saved) or its end —
	 * MySQL drops it with the connection. A request that cannot get it within
	 * LOCK_WAIT seconds goes ahead as WooCommerce would have without this.
	 *
	 * @param mixed $statuses
	 * @param mixed $order
	 * @return mixed
	 */
	public static function serialize_completion( $statuses, $order = null ) {
		if ( ! $order instanceof \WC_Order || self::PIX !== $order->get_payment_method() || ! self::active() || ! is_array( $statuses ) ) {
			return $statuses;
		}

		$order_id = (int) $order->get_id();
		self::lock( $order_id );

		$status = self::stored_status( $order_id );
		if ( in_array( $status, self::paid_statuses(), true ) ) {
			self::log( 'info', sprintf( 'Pix order %d: already %s when another request tried to complete it; skipped.', $order_id, $status ) );
			self::release( $order_id );

			return array();
		}

		return $statuses;
	}

	/**
	 * Unpaid-order sweep: a pending Pix order younger than the QR code's life
	 * (plus a grace for late confirmations) is not cancelled. Its stock stays
	 * held that long — the cost of never cancelling an order whose customer
	 * already paid. Past it, Stripe no longer accepts the payment and the
	 * sweep cancels as usual.
	 *
	 * Counted from the order's creation: a customer who retries Pix on the
	 * same order later gets a QR code that outlives this window by the time
	 * between the two attempts.
	 *
	 * @param mixed $cancel
	 * @param mixed $order
	 * @return mixed
	 */
	public static function keep_pending_pix( $cancel, $order = null ) {
		if ( ! $cancel || ! $order instanceof \WC_Order || self::PIX !== $order->get_payment_method() || ! self::active() ) {
			return $cancel;
		}

		$created = $order->get_date_created();
		if ( ! $created ) {
			return $cancel;
		}

		$expiry = (int) apply_filters( 'galaxie_woo/pix_expiry_seconds', self::PIX_EXPIRY, $order );
		$window = max( 0, $expiry ) + self::CANCEL_GRACE;

		return ( time() - $created->getTimestamp() ) < $window ? false : $cancel;
	}

	/** Lets go of the order's lock, once it was saved paid. */
	public static function release( $order_id = 0 ): void {
		$order_id = (int) $order_id;
		if ( ! isset( self::$locks[ $order_id ] ) ) {
			return;
		}

		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::$locks[ $order_id ] ) );
		unset( self::$locks[ $order_id ] );
	}

	public static function release_all(): void {
		foreach ( array_keys( self::$locks ) as $order_id ) {
			self::release( $order_id );
		}
	}

	private static function lock( int $order_id ): void {
		global $wpdb;

		if ( isset( self::$locks[ $order_id ] ) || ! is_object( $wpdb ) ) {
			return;
		}

		// Per site and order; MySQL caps lock names at 64 characters.
		$name = substr( 'galaxie_pix_' . md5( (string) $wpdb->prefix ) . '_' . $order_id, 0, 64 );
		if ( '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::LOCK_WAIT ) ) ) {
			self::$locks[ $order_id ] = $name;
		}
	}

	/** The order's status as saved, without the object caches: '' when unknown. */
	private static function stored_status( int $order_id ): string {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			return '';
		}

		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		$status = $hpos
			? $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}wc_orders WHERE id = %d", $order_id ) )
			: $wpdb->get_var( $wpdb->prepare( "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", $order_id ) );

		$status = (string) $status;

		return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
	}

	/** @return string[] WooCommerce's paid statuses (processing, completed), without the 'wc-' prefix. */
	private static function paid_statuses(): array {
		return function_exists( 'wc_get_is_paid_statuses' ) ? (array) wc_get_is_paid_statuses() : array( 'processing', 'completed' );
	}

	/** The PaymentIntent FunnelKit saved on the order: an array with 'id', or a bare id. */
	private static function stored_intent_id( \WC_Order $order ): string {
		$value = $order->get_meta( '_fkwcs_intent_id' );

		if ( is_array( $value ) ) {
			return (string) ( $value['id'] ?? '' );
		}

		return is_string( $value ) ? $value : '';
	}

	private static function gateway() {
		if ( is_callable( self::$gateway ) ) {
			return call_user_func( self::$gateway );
		}

		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}

		return WC()->payment_gateways()->payment_gateways()[ self::PIX ] ?? null;
	}

	private static function log( string $level, string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => 'galaxie-funnelkit-pix' ) );
		}
	}
}
