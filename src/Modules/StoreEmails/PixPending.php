<?php
/**
 * "Pix aguardando pagamento": when it is sent, and what it knows.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\StoreEmails;

use Galaxie\Woo\Integrations\FunnelKitStripe;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce sends nothing while an order is "Pendente", and a FunnelKit
 * Pix order (`fkwcs_stripe_pix`) stays pending until Stripe confirms the
 * payment: a shopper who closes the tab with the QR code unpaid hears nothing
 * from the store. {@see PixPendingEmail} is a WooCommerce e-mail of our own
 * (WooCommerce → Configurações → E-mails, like any other) that tells them the
 * order waits for its Pix, with the order-pay link — which makes a new QR
 * code when the old one expired. With a FluentCRM template chosen on the
 * "E-mails da loja" tab it goes out in that design, like the others.
 *
 * When: after FunnelKit created — or reused — the order's PaymentIntent and
 * accepted the attempt (`woocommerce_payment_successful_result`, which the
 * checkout and the order-pay page both filter; FunnelKit 1.15 creates the
 * intent unconfirmed in process_payment() and the browser confirms it). The
 * e-mail itself goes a minute later, from Action Scheduler, so the checkout
 * is not kept waiting on the mail server, and a shopper who pays at once
 * gets no e-mail: the order is checked again when it is sent, and an order
 * no longer pending, or paid, gets nothing.
 *
 * Once per order, with one exception:
 * - a retry (the order-pay page, a reload) within RETRY_GUARD of the e-mail
 *   sends nothing, whatever intent it is for;
 * - after that, only a NEW PaymentIntent, made once the QR code the last
 *   e-mail was about had expired, sends again — and only once: MAX_SENDS.
 *
 * The QR code itself is not in the e-mail. FunnelKit never sees it: Stripe
 * hands `next_action.pix_display_qr_code` (the copia-e-cola text, the image,
 * `expires_at`) to the browser that confirms the intent, and nothing stores
 * it on the order. Getting it here would take a Stripe API call per e-mail.
 * So `{{pedido.pix_copia_e_cola}}` and `{{pedido.pix_qr_url}}` are empty
 * unless something fills `galaxie_woo/store_emails/pix`, and
 * `{{pedido.pix_expira_em}}` is worked out: the attempt's time plus the QR
 * code's life (FunnelKitStripe::PIX_EXPIRY, Stripe's 24 h default), which is
 * when Stripe's own `expires_at` falls, give or take the seconds between
 * the attempt and the browser's confirmation.
 */
final class PixPending {

	/** The WooCommerce e-mail id, and the row on the settings tab. */
	public const ID = 'galaxie_pix_pending';

	/** Order meta: the last e-mail scheduled — [ intent, at, count ]. */
	public const META = '_galaxie_pix_pending_mail';

	/** No second e-mail for retries this soon after one, in seconds. */
	public const RETRY_GUARD = 1800;

	/** The first e-mail, and one more for a new QR code after the first expired. */
	public const MAX_SENDS = 2;

	/** Action Scheduler hook that sends it. */
	public const SEND_HOOK = 'galaxie_woo_send_pix_pending';

	/** Seconds between the attempt and the e-mail. Filter: `galaxie_woo/pix_pending_email_delay`. */
	public const DELAY = 60;

	public static function hooks(): void {
		add_filter( 'woocommerce_email_classes', array( self::class, 'register' ) );
		// After FunnelKitStripe::note_attempt() (priority 1), which records the attempt's time.
		add_filter( 'woocommerce_payment_successful_result', array( self::class, 'attempt' ), 30, 2 );
		add_action( self::SEND_HOOK, array( self::class, 'send' ), 10, 1 );
	}

	/**
	 * Our e-mail among WooCommerce's. The class extends WC_Email, so it is
	 * loaded only here, where WooCommerce has loaded its own.
	 *
	 * @param mixed $emails
	 * @return mixed
	 */
	public static function register( $emails ) {
		if ( is_array( $emails ) && class_exists( '\WC_Email' ) ) {
			$emails['Galaxie_Pix_Pending'] = new PixPendingEmail();
		}

		return $emails;
	}

	/**
	 * A payment attempt the gateway accepted: for a pending Pix order whose
	 * e-mail is due, the e-mail is claimed on the order and scheduled.
	 *
	 * @param mixed $result
	 * @param mixed $order_id
	 * @return mixed
	 */
	public static function attempt( $result, $order_id = 0 ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;

		if ( $order instanceof \WC_Order && self::due( $order, time() ) ) {
			self::claim( $order, time() );
			self::schedule( (int) $order->get_id() );
		}

		return $result;
	}

	/**
	 * Whether this attempt should bring an e-mail ({@see self} for the rules).
	 */
	public static function due( \WC_Order $order, int $now ): bool {
		if ( ! self::waiting( $order ) ) {
			return false;
		}

		$intent = self::intent_id( $order );
		if ( '' === $intent ) {
			return false;
		}

		$last = self::last( $order );
		if ( null === $last ) {
			return true;
		}

		if ( $now - $last['at'] < self::RETRY_GUARD || $intent === $last['intent'] || $last['count'] >= self::MAX_SENDS ) {
			return false;
		}

		// A new intent while the QR code of the last e-mail can still be paid
		// is a retry, not an expired code.
		return $now >= $last['at'] + self::expiry( $order );
	}

	/** Pix, pending and not paid: the only orders this e-mail is about. */
	public static function waiting( \WC_Order $order ): bool {
		return FunnelKitStripe::PIX === $order->get_payment_method()
			&& $order->has_status( 'pending' )
			&& ! $order->is_paid()
			&& null === $order->get_date_paid();
	}

	/** Marks the e-mail as on its way for this intent, before it is sent: two requests cannot both schedule it. */
	public static function claim( \WC_Order $order, int $now ): void {
		$last = self::last( $order );

		$order->update_meta_data(
			self::META,
			array(
				'intent' => self::intent_id( $order ),
				'at'     => $now,
				'count'  => ( $last['count'] ?? 0 ) + 1,
			)
		);
		$order->save_meta_data();
	}

	private static function schedule( int $order_id ): void {
		$delay = max( 0, (int) apply_filters( 'galaxie_woo/pix_pending_email_delay', self::DELAY, $order_id ) );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::SEND_HOOK, array( $order_id ), 'galaxie-woo' );
			return;
		}

		// No Action Scheduler (it ships with WooCommerce, so hardly ever): at
		// the end of this request, after the checkout's answer is built.
		add_action( 'shutdown', static fn() => self::send( $order_id ) );
	}

	/**
	 * Sends the e-mail for an order still waiting for its Pix; an order paid,
	 * cancelled or switched to another method meanwhile gets nothing.
	 *
	 * @param mixed $order_id
	 */
	public static function send( $order_id ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;

		if ( ! $order instanceof \WC_Order || ! self::waiting( $order ) || ! function_exists( 'WC' ) ) {
			return;
		}

		foreach ( (array) WC()->mailer()->get_emails() as $email ) {
			if ( $email instanceof \WC_Email && self::ID === $email->id && method_exists( $email, 'trigger' ) ) {
				$email->trigger( (int) $order->get_id(), $order );
				return;
			}
		}
	}

	/**
	 * When the order's current QR code stops being payable, as a Unix time;
	 * 0 when no Pix attempt is known. From the latest attempt either we
	 * (this e-mail) or FunnelKitStripe recorded.
	 */
	public static function expires_at( \WC_Order $order ): int {
		if ( FunnelKitStripe::PIX !== $order->get_payment_method() ) {
			return 0;
		}

		$since = max( (int) ( self::last( $order )['at'] ?? 0 ), (int) $order->get_meta( FunnelKitStripe::META_ATTEMPT_AT ) );

		return $since > 0 ? $since + self::expiry( $order ) : 0;
	}

	/** `expires_at()` as the e-mail prints it: "07/10/2026 14:30", São Paulo time; '' when unknown. */
	public static function expires_label( \WC_Order $order ): string {
		$at = self::expires_at( $order );

		if ( $at <= 0 ) {
			return '';
		}

		return ( new \DateTimeImmutable( '@' . $at ) )->setTimezone( new \DateTimeZone( 'America/Sao_Paulo' ) )->format( 'd/m/Y H:i' );
	}

	/**
	 * The QR code's copia-e-cola text and image / instructions URL, when
	 * something stored them (see the class docblock): filter
	 * `galaxie_woo/store_emails/pix` with [ 'copia_e_cola' => …, 'qr_url' => … ].
	 *
	 * @return array{copia_e_cola:string,qr_url:string}
	 */
	public static function qr( \WC_Order $order ): array {
		$pix = apply_filters(
			'galaxie_woo/store_emails/pix',
			array(
				'copia_e_cola' => '',
				'qr_url'       => '',
			),
			$order
		);

		return array(
			'copia_e_cola' => is_array( $pix ) ? (string) ( $pix['copia_e_cola'] ?? '' ) : '',
			'qr_url'       => is_array( $pix ) ? (string) ( $pix['qr_url'] ?? '' ) : '',
		);
	}

	private static function expiry( \WC_Order $order ): int {
		return max( 0, (int) apply_filters( 'galaxie_woo/pix_expiry_seconds', FunnelKitStripe::PIX_EXPIRY, $order ) );
	}

	/** @return array{intent:string,at:int,count:int}|null */
	private static function last( \WC_Order $order ): ?array {
		$last = $order->get_meta( self::META );

		if ( ! is_array( $last ) || empty( $last['at'] ) ) {
			return null;
		}

		return array(
			'intent' => (string) ( $last['intent'] ?? '' ),
			'at'     => (int) $last['at'],
			'count'  => max( 1, (int) ( $last['count'] ?? 1 ) ),
		);
	}

	/** The PaymentIntent FunnelKit saved on the order: an array with 'id' (1.15), or a bare id. */
	private static function intent_id( \WC_Order $order ): string {
		$value = $order->get_meta( '_fkwcs_intent_id' );

		return is_array( $value ) ? (string) ( $value['id'] ?? '' ) : ( is_string( $value ) ? $value : '' );
	}
}
