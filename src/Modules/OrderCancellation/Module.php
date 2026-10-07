<?php
/**
 * Cancel until posted: a paid order can be cancelled by its customer, with a
 * full refund, until the carrier has it.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\OrderCancellation;

use Galaxie\Woo\Core\Field;
use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\ProvidesBootData;
use Galaxie\Woo\Core\ProvidesSettings;
use Galaxie\Woo\Support\AccountParts;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce lets a customer cancel only an order that was never paid. The
 * store's policy goes further: a paid order ("Processando", "Aguardando") may
 * be cancelled until it is posted, with the whole amount back. Paid means
 * paid, not just in one of those statuses (see {@see self::eligible()}).
 *
 * The Cancel button of My Account (Galaxie Account Orders / Order, and
 * WooCommerce's own table) appears for those orders too. Pressing it asks why
 * (always: a reason from the list set on wp-admin → Galaxie → Cancelamento,
 * and an optional comment), then:
 *
 * 1. Melhor Envio. No label for an order shipped by a Melhor Envio method —
 *    nothing to undo. Anything the module cannot vouch for — Melhor Envio
 *    inactive or failing, an order shipped some other way (free shipping,
 *    local pickup, a label bought outside the plugin, a tracking code on the
 *    order) — goes to the store to review, never to an automatic refund. A
 *    label still in
 *    the Melhor Envio cart is taken out of it. A paid label is cancelled when
 *    Melhor Envio says it can be (its credit goes back to the wallet). A
 *    label already posted, delivered or otherwise on its way cannot be
 *    cancelled from here: the button stays, and opens the widget's "order in
 *    transit" notice instead (refuse the delivery to cancel). Melhor Envio is
 *    asked before the reason is, so nobody fills one in to hear no.
 * 2. Stripe (or whichever gateway took the payment) refunds the full amount
 *    left on the order, through WooCommerce's refund, which is what tells the
 *    gateway.
 * 3. The order becomes "Cancelado" — not "Reembolsado" — with the reason in an
 *    order note and its meta. WooCommerce sends the store its "Pedido
 *    cancelado" e-mail (processing → cancelled) and the customer its "Pedido
 *    reembolsado" one, and puts the stock back.
 *
 * Whenever a step cannot be sure — Melhor Envio does not answer, says the
 * label cannot be cancelled although it was not posted, or the refund fails —
 * nothing is refunded. The request is recorded on the order, the store is
 * e-mailed to decide, and the customer is told it was received. Money never
 * goes back for something that may already be on its way.
 */
final class Module implements ModuleContract, ProvidesSettings, ProvidesBootData {

	public const ACTION          = 'galaxie_cancel_order';
	public const CHECK           = 'galaxie_cancel_check';
	/** The query argument a refused (in-transit) cancellation returns with: the order id. */
	public const POSTED_ARG      = 'galaxie_cancel_posted';
	/** On an order's screen: open the cancel dialog for this order id (a bare Cancel link lands here). */
	public const OPEN_ARG        = 'galaxie-cancel';
	public const META_REASON     = '_galaxie_cancel_reason';
	public const META_COMMENT    = '_galaxie_cancel_comment';
	public const META_REQUESTED  = '_galaxie_cancel_requested_at';
	public const META_CANCELLED  = '_galaxie_cancelled_by_customer_at';
	public const META_POSTED     = '_galaxie_shipment_posted';

	/** Option name prefix of the per-order lock, and how long a lock holds (seconds). */
	private const LOCK_PREFIX = '_galaxie_cancel_lock_';
	private const LOCK_TTL    = 120;

	/** Order statuses this module adds to WooCommerce's own cancellable ones. */
	private const PAID_STATUSES = array( 'processing', 'on-hold' );

	/** Melhor Envio label statuses that mean the parcel is out of the store's hands. */
	private const GONE = array( 'posted', 'delivered', 'undelivered', 'paused', 'suspended' );

	private const DEFAULT_REASONS = 'Comprei por engano | Escolhi o produto ou a variação errada | O prazo de entrega ficou longo | Encontrei um preço melhor | Outro motivo';

	/**
	 * Talks to the Melhor Envio API: (method, route, body) → decoded response,
	 * or null when there was no usable answer. Replaceable, for tests.
	 *
	 * @var callable|null
	 */
	public static $melhor_envio = null;

	/**
	 * Reads the Melhor Envio record of an order (order → array, as the Melhor
	 * Envio plugin's getData() answers). Replaceable, for tests.
	 *
	 * @var callable|null
	 */
	public static $label_source = null;

	/** label(): sure there is no label. */
	private const NO_LABEL = '#none';

	/** label(): cannot tell. */
	private const UNKNOWN = '#unknown';

	/** Why label() could not tell, for the order note. */
	private static string $unknown_why = '';

	/** The order whose full refund must leave it "cancelled", not "refunded". */
	private static int $cancelling = 0;

	public function id(): string {
		return 'order-cancellation';
	}

	public function title(): string {
		return __( 'Cancelamento até a postagem', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'O cliente cancela um pedido pago em Minha conta até a postagem: pergunta o motivo, cancela a etiqueta no Melhor Envio, estorna o valor total e avisa a loja.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return true;
	}

	public function boot(): void {
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'add_action' ), 20, 2 );
		add_filter( 'galaxie_woo/cancel_order_url', array( $this, 'cancel_url' ), 10, 3 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_' . self::CHECK, array( $this, 'check' ) );
		add_filter( 'woocommerce_order_fully_refunded_status', array( $this, 'refunded_status' ), 10, 2 );
	}

	public function boot_data(): array {
		return array(
			'orderCancellation' => array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'posted'   => __( 'Seu pedido já está a caminho. Para que ele seja cancelado, basta recusar o recebimento.', 'galaxie-woo' ),
				'reasons'  => self::reasons(),
				'question' => __( 'Por que você quer cancelar?', 'galaxie-woo' ),
				'choose'   => __( 'Escolha um motivo', 'galaxie-woo' ),
				'comment'  => __( 'Quer contar mais? (opcional)', 'galaxie-woo' ),
				'required' => __( 'Escolha um motivo para continuar.', 'galaxie-woo' ),
			),
		);
	}

	/** @return string[] */
	public static function reasons(): array {
		$settings = \Galaxie\Woo\Core\Plugin::instance()->settings()->module_settings( 'order-cancellation' );
		$raw      = trim( (string) ( $settings['reasons'] ?? '' ) );
		$list     = array_values( array_filter( array_map( 'trim', explode( '|', '' !== $raw ? $raw : self::DEFAULT_REASONS ) ) ) );

		return $list ? $list : array( __( 'Outro motivo', 'galaxie-woo' ) );
	}

	/**
	 * Whether this module draws a Cancel button for `$order` (WooCommerce draws
	 * the unpaid ones itself). An order in transit keeps it: it opens the
	 * in-transit notice. One whose request is with the store does not.
	 *
	 * The status alone does not prove a payment: "Aguardando" (on-hold) is also
	 * where an unpaid order waits — a Pix whose intent Stripe reports as
	 * processing, a bank transfer, a card only authorized. Refunding one of
	 * those would refund nothing, or a payment still to come, and cancel the
	 * order on the way. So the order must have been paid: WooCommerce stamps
	 * `date_paid` in `payment_complete()` and on any move to processing or
	 * completed, and keeps it when a paid order is put on hold by the store.
	 * An unpaid on-hold order gets no button from here (WooCommerce's own
	 * Cancel covers pending and failed only).
	 */
	public static function eligible( \WC_Order $order ): bool {
		return $order->has_status( self::PAID_STATUSES )
			&& null !== $order->get_date_paid()
			&& (int) $order->get_customer_id() > 0
			&& '' === (string) $order->get_meta( self::META_REQUESTED );
	}

	/** Known to be on its way (Melhor Envio said so on an earlier try). */
	public static function in_transit( \WC_Order $order ): bool {
		return '' !== (string) $order->get_meta( self::META_POSTED );
	}

	/**
	 * WooCommerce's "Cancel" action, for a paid order still at the store.
	 *
	 * @param array<string,array{url:string,name:string}> $actions
	 * @param \WC_Order                                   $order
	 * @return array<string,array{url:string,name:string}>
	 */
	public function add_action( $actions, $order ) {
		if ( ! $order instanceof \WC_Order || isset( $actions['cancel'] ) || ! self::eligible( $order ) || (int) $order->get_customer_id() !== get_current_user_id() ) {
			return $actions;
		}

		$actions['cancel'] = array(
			'url'  => self::url( $order, $order->get_view_order_url() ),
			'name' => __( 'Cancelar', 'galaxie-woo' ),
		);

		return $actions;
	}

	/** Our link in place of WooCommerce's, where Galaxie's screens build their own return address. */
	public function cancel_url( string $url, \WC_Order $order, string $target ): string {
		return self::eligible( $order ) ? self::url( $order, $target ) : $url;
	}

	private static function url( \WC_Order $order, string $target ): string {
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'order_id' => $order->get_id(),
				'redirect' => rawurlencode( $target ),
				'_wpnonce' => wp_create_nonce( self::ACTION . '_' . $order->get_id() ),
				// The script opens the in-transit notice straight away.
				'posted'   => self::in_transit( $order ) ? '1' : false,
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * AJAX, before the reason is asked: is this order already on its way?
	 * `{ posted: true }` when Melhor Envio says so (remembered on the order);
	 * false otherwise, including when it does not answer — the request itself
	 * then goes to the store.
	 */
	public function check(): void {
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- verified just below.
		$nonce    = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof \WC_Order || ! wp_verify_nonce( $nonce, self::ACTION . '_' . $order_id ) || (int) $order->get_customer_id() !== get_current_user_id() || ! self::eligible( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'Não foi possível cancelar este pedido. Atualize a página e tente de novo.', 'galaxie-woo' ) ) );
		}

		$posted = self::in_transit( $order ) || true === self::shipment_gone( $order );

		if ( $posted && ! self::in_transit( $order ) ) {
			$order->update_meta_data( self::META_POSTED, gmdate( 'Y-m-d H:i:s' ) );
			$order->save();
		}

		wp_send_json_success( array( 'posted' => $posted ) );
	}

	/**
	 * Whether Melhor Envio says the order's label is already on its way: true,
	 * false (no label, or not yet), or null when it could not be asked — or
	 * when there is no telling whether the order left some other way.
	 */
	public static function shipment_gone( \WC_Order $order ): ?bool {
		[ $label, $status ] = self::label_status( $order );

		if ( self::NO_LABEL === $label ) {
			return false;
		}

		if ( self::UNKNOWN === $label ) {
			return null;
		}

		return '' === $status ? null : in_array( $status, self::GONE, true );
	}

	/**
	 * The order's Melhor Envio label and its status there ('' when unknown).
	 * The label is NO_LABEL or UNKNOWN when there is none to ask about
	 * ({@see self::label()}).
	 *
	 * @return array{0:string,1:string}
	 */
	private static function label_status( \WC_Order $order ): array {
		$label = self::label( $order );

		if ( self::NO_LABEL === $label || self::UNKNOWN === $label ) {
			return array( $label, '' );
		}

		$tracking = self::api( 'POST', '/shipment/tracking', array( 'orders' => array( $label ) ) );

		return array( $label, is_array( $tracking ) ? (string) ( $tracking[ $label ]['status'] ?? '' ) : '' );
	}

	/** The cancel request, from the dialog's form (POST) or a bare link (GET, which asks for the reason). */
	public function handle(): void {
		$order_id = isset( $_REQUEST['order_id'] ) ? absint( wp_unslash( $_REQUEST['order_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- verified just below.
		$target   = isset( $_REQUEST['redirect'] ) ? esc_url_raw( rawurldecode( wp_unslash( (string) $_REQUEST['redirect'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$fallback = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'orders' ) : home_url( '/' );
		$back     = wp_validate_redirect( $target, $fallback );

		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		$order = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! is_user_logged_in() || ! $order instanceof \WC_Order || ! wp_verify_nonce( $nonce, self::ACTION . '_' . $order_id ) || (int) $order->get_customer_id() !== get_current_user_id() ) {
			$this->leave( $back, __( 'Não foi possível cancelar este pedido. Atualize a página e tente de novo.', 'galaxie-woo' ), 'error', false );
		}

		$reason  = isset( $_REQUEST['reason'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['reason'] ) ) : '';
		$comment = isset( $_REQUEST['comment'] ) ? sanitize_textarea_field( wp_unslash( $_REQUEST['comment'] ) ) : '';
		$comment = function_exists( 'mb_substr' ) ? mb_substr( $comment, 0, 500 ) : substr( $comment, 0, 500 );

		// A bare link (WooCommerce's own Cancel button on the orders table or the
		// order-received page, which the reason dialog does not catch): to the
		// order's screen, where the script opens the dialog for it.
		if ( '' === $reason && 'GET' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) && self::eligible( $order ) && ! self::in_transit( $order ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$this->leave( add_query_arg( self::OPEN_ARG, $order_id, $order->get_view_order_url() ), '', '', false );
		}

		if ( ! self::lock( $order_id ) ) {
			$this->leave( $back, __( 'Já estamos processando o cancelamento deste pedido. Atualize a página em instantes.', 'galaxie-woo' ), 'notice', false );
		}

		// Read again under the lock: a second press (another tab, a double
		// click) finds what the first one did and reports it instead of
		// refunding twice.
		$fresh = wc_get_order( $order_id );
		$order = $fresh instanceof \WC_Order ? $fresh : $order;
		$done  = self::already_handled( $order );

		if ( '' !== $done ) {
			self::unlock( $order_id );
			$this->finish( $order, $done, $back );
		}

		if ( ! self::eligible( $order ) ) {
			self::unlock( $order_id );
			$this->leave( $back, __( 'Este pedido não pode mais ser cancelado por aqui. Fale com a gente.', 'galaxie-woo' ), 'error', false );
		}

		// On its way: the screen opens the in-transit notice; nothing to ask.
		if ( self::in_transit( $order ) ) {
			self::unlock( $order_id );
			$this->leave( add_query_arg( self::POSTED_ARG, $order_id, $back ), '', '', false );
		}

		if ( ! in_array( $reason, self::reasons(), true ) ) {
			self::unlock( $order_id );
			$this->leave( $back, __( 'Escolha o motivo do cancelamento para continuar.', 'galaxie-woo' ), 'error', false );
		}

		$order->update_meta_data( self::META_REASON, $reason );
		$order->update_meta_data( self::META_COMMENT, $comment );
		$order->save();

		try {
			$result = self::cancel( $order, $reason, $comment );
		} finally {
			self::unlock( $order_id );
		}

		$this->finish( $order, $result, $back );
	}

	/** Back to the screen with what came of the request: cancelled, posted or requested. */
	private function finish( \WC_Order $order, string $result, string $back ): void {
		if ( 'cancelled' === $result ) {
			// WooCommerce's own words, so Galaxie's screens recognise the notice
			// and show their "cancelled" alert instead (AccountParts).
			$this->leave( $back, (string) apply_filters( 'woocommerce_order_cancelled_notice', __( 'Your order was cancelled.', 'woocommerce' ) ), 'notice', true ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
		}

		if ( 'posted' === $result ) {
			$this->leave( add_query_arg( self::POSTED_ARG, $order->get_id(), $back ), '', '', false );
		}

		$this->leave( $back, __( 'Recebemos seu pedido de cancelamento; vamos confirmar em até 1 dia útil.', 'galaxie-woo' ), 'notice', false );
	}

	/**
	 * What an earlier request already did with this order: 'cancelled' (by the
	 * customer, here), 'requested' (with the store) or '' (nothing yet).
	 */
	private static function already_handled( \WC_Order $order ): string {
		if ( '' !== (string) $order->get_meta( self::META_CANCELLED ) ) {
			return 'cancelled';
		}

		if ( '' !== (string) $order->get_meta( self::META_REQUESTED ) ) {
			return 'requested';
		}

		return '';
	}

	/**
	 * One cancellation at a time per order. `add_option()` is an INSERT on a
	 * unique key, so only one request wins; a lock older than LOCK_TTL is a
	 * request that died, and is taken over.
	 */
	private static function lock( int $order_id ): bool {
		$key = self::LOCK_PREFIX . $order_id;

		if ( add_option( $key, (string) time(), '', 'no' ) ) {
			return true;
		}

		$since = (int) get_option( $key, 0 );

		if ( $since > 0 && time() - $since < self::LOCK_TTL ) {
			return false;
		}

		delete_option( $key );

		return add_option( $key, (string) time(), '', 'no' );
	}

	private static function unlock( int $order_id ): void {
		delete_option( self::LOCK_PREFIX . $order_id );
	}

	/**
	 * Cancels a paid order: Melhor Envio first, then the refund, then the status.
	 *
	 * @return string 'cancelled', 'posted' (refused, already on its way) or 'requested' (left to the store).
	 */
	public static function cancel( \WC_Order $order, string $reason, string $comment = '' ): string {
		$said = '' !== $comment ? sprintf( '%s — "%s"', $reason, $comment ) : $reason;

		$shipping = self::undo_shipment( $order );

		if ( 'posted' === $shipping ) {
			$order->update_meta_data( self::META_POSTED, gmdate( 'Y-m-d H:i:s' ) );
			$order->save();
			$order->add_order_note( sprintf( /* translators: %s: reason */ __( 'O cliente tentou cancelar, mas o Melhor Envio informa que a etiqueta já foi postada. Motivo informado: %s', 'galaxie-woo' ), $said ) );

			return 'posted';
		}

		if ( 'ok' !== $shipping ) {
			return self::request( $order, $said, $shipping );
		}

		$remaining = (float) $order->get_remaining_refund_amount();

		if ( $remaining > 0 ) {
			self::$cancelling = $order->get_id();

			$refund = wc_create_refund(
				array(
					'order_id'       => $order->get_id(),
					'amount'         => wc_format_decimal( $remaining, wc_get_price_decimals() ),
					'reason'         => sprintf( /* translators: %s: reason */ __( 'Cancelado pelo cliente: %s', 'galaxie-woo' ), $reason ),
					'refund_payment' => true,
					'restock_items'  => false, // Cancelling puts the stock back.
				)
			);

			self::$cancelling = 0;

			if ( is_wp_error( $refund ) ) {
				return self::request( $order, $said, sprintf( /* translators: %s: gateway error */ __( 'o estorno falhou: %s', 'galaxie-woo' ), $refund->get_error_message() ) );
			}

			$order = wc_get_order( $order->get_id() );
		}

		if ( $order && ! $order->has_status( 'cancelled' ) ) {
			$order->update_status( 'cancelled' );
		}

		if ( $order ) {
			$order->update_meta_data( self::META_CANCELLED, gmdate( 'Y-m-d H:i:s' ) );
			$order->save();
			$order->add_order_note( sprintf( /* translators: 1: reason, 2: amount */ __( 'Cancelado pelo cliente em Minha conta, com estorno de %2$s. Motivo: %1$s', 'galaxie-woo' ), $said, wp_strip_all_tags( wc_price( $remaining, array( 'currency' => $order->get_currency() ) ) ) ) );
		}

		return 'cancelled';
	}

	/**
	 * Undoes the order's Melhor Envio label, if it has one.
	 *
	 * @return string 'ok' (nothing left to undo), 'posted' (too late), or why
	 *                it could not be sure — for the store to decide.
	 */
	private static function undo_shipment( \WC_Order $order ): string {
		[ $label, $status ] = self::label_status( $order );

		if ( self::NO_LABEL === $label ) {
			return 'ok';
		}

		if ( self::UNKNOWN === $label ) {
			return self::$unknown_why ?: __( 'não há como confirmar que o pedido ainda não foi postado', 'galaxie-woo' );
		}

		if ( '' === $status ) {
			return __( 'o Melhor Envio não respondeu sobre a etiqueta', 'galaxie-woo' );
		}

		if ( in_array( $status, self::GONE, true ) ) {
			return 'posted';
		}

		if ( in_array( $status, array( 'canceled', 'cancelled', 'expired' ), true ) ) {
			return 'ok';
		}

		// Still in the Melhor Envio cart: never paid, so just taken out of it.
		if ( 'pending' === $status ) {
			$removed = self::api( 'DELETE', '/cart/' . rawurlencode( $label ), null );
			if ( null === $removed ) {
				$order->add_order_note( __( 'A etiqueta deste pedido ainda estava no carrinho do Melhor Envio e não pôde ser removida de lá. Remova-a à mão.', 'galaxie-woo' ) );
			}

			return 'ok';
		}

		$check = self::api( 'POST', '/shipment/cancellable', array( 'orders' => array( $label ) ) );
		if ( ! is_array( $check ) || empty( $check[ $label ]['cancellable'] ) ) {
			return __( 'o Melhor Envio não permite cancelar a etiqueta', 'galaxie-woo' );
		}

		$cancelled = self::api(
			'POST',
			'/shipment/cancel',
			array(
				'order' => array(
					'id'          => $label,
					'reason_id'   => '2', // Always 2 for integrations (Melhor Envio's docs).
					'description' => 'Cancelado pelo cliente',
				),
			)
		);

		if ( ! is_array( $cancelled ) || empty( $cancelled[ $label ]['canceled'] ) && empty( $cancelled[ $label ]['cancelled'] ) ) {
			return __( 'o cancelamento da etiqueta no Melhor Envio falhou', 'galaxie-woo' );
		}

		$order->add_order_note( __( 'Etiqueta do Melhor Envio cancelada; o crédito volta para a carteira.', 'galaxie-woo' ) );

		return 'ok';
	}

	/**
	 * The Melhor Envio order (label) id the Melhor Envio plugin keeps on the
	 * order; NO_LABEL when it is sure there is none; UNKNOWN otherwise.
	 *
	 * "No label" refunds at once, so it must be known, not assumed: the order
	 * went by a Melhor Envio method, the Melhor Envio plugin is there and
	 * answers for it, and nothing on the order says it was posted another way.
	 * Everything else — the plugin off or renamed, an error, free shipping or
	 * local pickup (posted at a counter or handed over without a label from
	 * the plugin), a tracking code written on the order — is UNKNOWN, and the
	 * request goes to the store ({@see self::request()}). Why is kept in
	 * $unknown_why for the order note.
	 */
	private static function label( \WC_Order $order ): string {
		self::$unknown_why = '';

		$unknown = static function ( string $why ): string {
			self::$unknown_why = $why;

			return self::UNKNOWN;
		};

		if ( is_callable( self::$label_source ) ) {
			$data = call_user_func( self::$label_source, $order );
		} elseif ( ! class_exists( '\MelhorEnvio\Services\OrderQuotationService' ) ) {
			return $unknown( __( 'o plugin do Melhor Envio não está ativo', 'galaxie-woo' ) );
		} else {
			try {
				$data = ( new \MelhorEnvio\Services\OrderQuotationService() )->getData( $order->get_id() );
			} catch ( \Throwable $e ) {
				return $unknown( __( 'o plugin do Melhor Envio falhou ao ler o pedido', 'galaxie-woo' ) );
			}
		}

		if ( ! is_array( $data ) ) {
			return $unknown( __( 'o Melhor Envio não tem registro deste pedido', 'galaxie-woo' ) );
		}

		$label = trim( (string) ( $data['order_id'] ?? '' ) );

		if ( '' !== $label ) {
			return $label;
		}

		if ( '' !== trim( (string) ( $data['tracking'] ?? '' ) ) || self::has_tracking_meta( $order ) ) {
			return $unknown( __( 'o pedido tem um código de rastreio', 'galaxie-woo' ) );
		}

		$methods = array();

		foreach ( $order->get_shipping_methods() as $line ) {
			$methods[] = (string) $line->get_method_id();
		}

		if ( ! $methods ) {
			return $unknown( __( 'o pedido não tem forma de entrega', 'galaxie-woo' ) );
		}

		/**
		 * Shipping method id prefixes whose labels the Melhor Envio plugin
		 * records on the order — an order sent by one of these, with no label
		 * recorded, has not been posted.
		 *
		 * @param string[]  $prefixes
		 * @param \WC_Order $order
		 */
		$trusted = (array) apply_filters( 'galaxie_woo/cancel_label_methods', array( 'melhorenvio' ), $order );

		foreach ( $methods as $method ) {
			$known = false;

			foreach ( $trusted as $prefix ) {
				if ( '' !== (string) $prefix && 0 === strpos( $method, (string) $prefix ) ) {
					$known = true;
					break;
				}
			}

			if ( ! $known ) {
				return $unknown(
					in_array( $method, array( 'local_pickup', 'pickup_location' ), true )
						? __( 'retirada no local', 'galaxie-woo' )
						/* translators: %s: shipping method id */
						: sprintf( __( 'a entrega (%s) não é do Melhor Envio, então a etiqueta pode ter sido feita fora do plugin', 'galaxie-woo' ), $method )
				);
			}
		}

		return self::NO_LABEL;
	}

	/** Any tracking code another plugin (or the store, by hand) wrote on the order. */
	private static function has_tracking_meta( \WC_Order $order ): bool {
		foreach ( $order->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			$key  = (string) ( $data['key'] ?? '' );

			if ( '' === $key || false === stripos( $key, 'track' ) ) {
				continue;
			}

			$value = $data['value'] ?? '';

			if ( is_array( $value ) ? array_filter( $value ) : '' !== trim( (string) $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Calls the Melhor Envio API with the token its plugin keeps.
	 *
	 * @param array<string,mixed>|null $body
	 * @return array<string,mixed>|null Decoded response; null without a usable answer.
	 */
	private static function api( string $method, string $route, ?array $body ): ?array {
		if ( is_callable( self::$melhor_envio ) ) {
			return call_user_func( self::$melhor_envio, $method, $route, $body );
		}

		if ( ! class_exists( '\MelhorEnvio\Services\TokenService' ) ) {
			return null;
		}

		try {
			$token = ( new \MelhorEnvio\Services\TokenService() )->get();
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( ! is_array( $token ) ) {
			return null;
		}

		$production = 'production' === ( $token['token_environment'] ?? '' );
		$bearer     = (string) ( $production ? ( $token['token'] ?? '' ) : ( $token['token_sandbox'] ?? '' ) );
		$base       = $production ? 'https://api.melhorenvio.com/v2/me' : 'https://sandbox.melhorenvio.com.br/api/v2/me';

		if ( '' === $bearer ) {
			return null;
		}

		$response = wp_remote_request(
			$base . $route,
			array(
				'method'  => $method,
				'timeout' => 15,
				'headers' => array(
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $bearer,
					'User-Agent'    => 'Galaxie Bundle (' . get_option( 'admin_email' ) . ')',
				),
				'body'    => null !== $body ? (string) wp_json_encode( $body ) : null,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = trim( (string) wp_remote_retrieve_body( $response ) );
		$data = '' !== $raw ? json_decode( $raw, true ) : array();

		// A removal answers 2xx with no body: success, with nothing in it.
		return $code >= 200 && $code < 300 && is_array( $data ) ? $data : null;
	}

	/**
	 * Not sure enough to refund: the request is recorded, the store e-mailed.
	 *
	 * @return string 'requested'
	 */
	private static function request( \WC_Order $order, string $said, string $why ): string {
		$order->update_meta_data( self::META_REQUESTED, gmdate( 'Y-m-d H:i:s' ) );
		$order->save();

		$note = sprintf(
			/* translators: 1: reason, 2: why it was not automatic */
			__( 'O cliente pediu o cancelamento em Minha conta, mas ele não foi feito sozinho (%2$s). Nada foi estornado. Revise o pedido e cancele/estorne à mão. Motivo: %1$s', 'galaxie-woo' ),
			$said,
			$why
		);
		$order->add_order_note( $note );

		wp_mail(
			(string) get_option( 'admin_email' ),
			sprintf( /* translators: %s: order number */ __( 'Pedido #%s: cancelamento para revisar', 'galaxie-woo' ), $order->get_order_number() ),
			$note . "\n\n" . $order->get_edit_order_url()
		);

		return 'requested';
	}

	/**
	 * A full refund made by this module leaves the order "cancelled", which is
	 * what happened, rather than WooCommerce's "refunded".
	 *
	 * @param string $status
	 * @param int    $order_id
	 */
	public function refunded_status( $status, $order_id ) {
		return self::$cancelling && (int) $order_id === self::$cancelling ? 'cancelled' : $status;
	}

	/** Back to the screen the customer came from, with a notice. */
	private function leave( string $back, string $message, string $type, bool $cancelled ): void {
		if ( '' !== $message && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, $type );
		}

		// Only a real cancellation keeps the "cancelled" mark the alert reads.
		if ( ! $cancelled ) {
			$back = remove_query_arg( AccountParts::CANCELLED_ARG, $back );
		}

		wp_safe_redirect( $back );
		exit;
	}

	public function settings_tab_label(): string {
		return __( 'Cancelamento', 'galaxie-woo' );
	}

	/** @return Field[] */
	public function settings_fields(): array {
		return array(
			new Field(
				key: 'reasons',
				label: __( 'Motivos', 'galaxie-woo' ),
				type: Field::TYPE_TEXT,
				description: __( 'As opções do "Por que você quer cancelar?", separadas por |. O cliente sempre escolhe uma, e pode escrever um comentário. Ficam no pedido (nota e campos _galaxie_cancel_reason / _galaxie_cancel_comment).', 'galaxie-woo' ),
				default: self::DEFAULT_REASONS
			),
		);
	}

	public function render_extra_settings( array $values ): void {
		echo '<h3>' . esc_html__( 'Como funciona', 'galaxie-woo' ) . '</h3><ul style="list-style:disc;padding-left:1.5em">';
		foreach (
			array(
				__( 'O botão Cancelar aparece em Minha conta para pedidos "Processando" e "Aguardando" (os não pagos o WooCommerce já cancela).', 'galaxie-woo' ),
				__( 'Entrega por um método do Melhor Envio e nenhuma etiqueta: cancela e estorna na hora. Etiqueta no carrinho do Melhor Envio: é removida. Etiqueta paga e ainda não postada: é cancelada lá (o crédito volta para a carteira) e o pedido é cancelado e estornado.', 'galaxie-woo' ),
				__( 'Quando não dá para ter certeza de que o pedido não saiu — frete grátis ou outra entrega que não é do Melhor Envio, retirada no local, código de rastreio no pedido, plugin do Melhor Envio desativado ou com erro — nada é estornado: o pedido vai para a sua revisão (nota no pedido + e-mail) e o cliente lê "Recebemos seu pedido de cancelamento; vamos confirmar em até 1 dia útil."', 'galaxie-woo' ),
				__( 'Etiqueta já postada: o botão continua, e abre o aviso "Cancelamento de pedido em rota" (título, texto e botão OK editáveis no widget Galaxie Account Orders / Order). O texto padrão orienta a recusar o recebimento.', 'galaxie-woo' ),
				__( 'Se o Melhor Envio não responder ou o estorno falhar, nada é estornado: o pedido ganha uma nota, você recebe um e-mail para decidir e o cliente vê que o pedido de cancelamento foi recebido.', 'galaxie-woo' ),
				__( 'O botão Cancelar nativo do WooCommerce (tabela de pedidos, página de pedido recebido) leva à tela do pedido, que abre a pergunta do motivo. Dois cliques seguidos não estornam duas vezes.', 'galaxie-woo' ),
				__( 'O estorno é sempre do valor total que resta no pedido (produtos e frete). O WooCommerce envia à loja o e-mail "Pedido cancelado" e ao cliente o "Pedido reembolsado".', 'galaxie-woo' ),
			) as $line
		) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul>';
	}

	public function sanitize_settings( array $submitted, array $current ): array {
		return Field::sanitize_all( $this->settings_fields(), $submitted );
	}
}
