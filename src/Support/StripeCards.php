<?php
/**
 * Saving a card with Stripe from inside the payment methods widget.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The card is typed into Stripe's own fields — iframes served by Stripe — laid
 * out inside the widget's card, so no card number ever reaches this site.
 *
 * Two Stripe plugins can be the store's card gateway, and everything that
 * talks to Stripe is the active plugin's own code, reached the way its own
 * add-payment-method page reaches it.
 *
 * WooCommerce Stripe Gateway (`stripe`):
 *
 * 1. Stripe.js turns the fields into a PaymentMethod (`pm_…`).
 * 2. The plugin's `wc_stripe_create_and_confirm_setup_intent` AJAX action
 *    creates the Stripe customer if needed and confirms a SetupIntent for it.
 * 3. Stripe.js runs any authentication the bank asks for.
 * 4. {@see ajax_save_card()} turns the confirmed SetupIntent into a WooCommerce
 *    payment token through the plugin's `create_token_from_setup_intent()` — the
 *    same call it makes after 3-D Secure — so the card lands in the saved list
 *    exactly as a card added on the plugin's own page would.
 *
 * FunnelKit Payment Gateway for Stripe (`fkwcs_stripe`):
 *
 * 1. Stripe.js turns the fields into a PaymentMethod, as above.
 * 2. FunnelKit's `fkwcs_create_setup_intent` AJAX action creates the Stripe
 *    customer if needed and an unconfirmed SetupIntent for that PaymentMethod.
 * 3. Stripe.js confirms it, running any bank authentication.
 * 4. {@see ajax_save_card()} hands the PaymentMethod to the gateway's own
 *    `add_payment_method()` — what WooCommerce calls when FunnelKit's
 *    add-payment-method form is posted after those same three steps — which
 *    attaches it to the customer and stores the `WC_Payment_Token_CC` through
 *    FunnelKit's `Helper::create_payment_token_for_user()`. The token is
 *    therefore exactly the one FunnelKit's own page would have stored: same
 *    gateway id, same `mode` meta, de-duplicated by PaymentMethod id, FunnelKit's
 *    token cache cleared and its `fkwcs_add_payment_method_fkwcs_stripe_success`
 *    action fired.
 *
 *    Linking to WooCommerce's add-payment-method page (which FunnelKit handles)
 *    would be just as correct, but there FunnelKit draws its own form in its own
 *    look on a page the account design does not own. The inline form stays; the
 *    link is still the widget's fallback whenever client_config() is null.
 *
 * Step 4 checks, before anything is saved, that the SetupIntent succeeded and
 * belongs to the signed-in customer's Stripe customer: an intent id posted by
 * someone else is refused rather than trusted. With FunnelKit the card saved is
 * the PaymentMethod on that verified intent, never an id taken from the
 * request, and a brand outside the merchant's "Allowed Card Brands" is refused
 * — FunnelKit's own page enforces that list in its script only.
 *
 * With both plugins switched on, the WooCommerce Stripe plugin keeps the job,
 * as it had before FunnelKit arrived.
 */
final class StripeCards {

	public const NONCE_ACTION = 'galaxie_woo_stripe_cards';

	/** Gateway ids, as client_config() reports them in `gateway`. */
	public const OFFICIAL  = 'stripe';
	public const FUNNELKIT = 'fkwcs_stripe';

	/** The Stripe plugin's own nonce action for step 2. */
	private const INTENT_NONCE_ACTION = 'wc_stripe_create_and_confirm_setup_intent_nonce';

	/** FunnelKit's nonce action for its step 2 (what its pages carry as `fkwcs_data.fkwcs_nonce`). */
	private const FUNNELKIT_NONCE_ACTION = 'fkwcs_nonce';

	public static function hooks(): void {
		add_action( 'wp_ajax_galaxie_stripe_save_card', array( self::class, 'ajax_save_card' ) );
	}

	/**
	 * What the browser needs to add a card inline, or null when it cannot: no
	 * Stripe plugin, the gateway switched off, no publishable key, or nobody
	 * signed in. `gateway` names the plugin whose step 2 the script calls; with
	 * FunnelKit, `brands` lists the card brands the merchant accepts (empty
	 * when the gateway reports none, which it treats as all).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function client_config(): ?array {
		$gateway = is_user_logged_in() ? self::gateway() : null;

		if ( ! $gateway ) {
			return null;
		}

		$funnelkit = self::is_funnelkit( $gateway );

		$config = array(
			'gateway'     => $funnelkit ? self::FUNNELKIT : self::OFFICIAL,
			'key'         => $funnelkit ? (string) $gateway->get_client_key() : (string) $gateway->publishable_key,
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'intentNonce' => wp_create_nonce( $funnelkit ? self::FUNNELKIT_NONCE_ACTION : self::INTENT_NONCE_ACTION ),
			'saveNonce'   => wp_create_nonce( self::NONCE_ACTION ),
			'locale'      => substr( get_locale(), 0, 2 ),
		);

		if ( $funnelkit ) {
			$config['brands'] = self::funnelkit_brands( $gateway );
		}

		return $config;
	}

	public static function ajax_save_card(): void {
		$failed = __( 'Não foi possível salvar o cartão. Tente de novo.', 'galaxie-woo' );

		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) || ! is_user_logged_in() ) {
			self::fail( __( 'Sua sessão expirou. Recarregue a página e tente de novo.', 'galaxie-woo' ), 403 );
		}

		/** Where the FluentCRM change notes say the card was added. */
		do_action( 'galaxie_woo/change_source', __( 'Minha conta — Formas de pagamento', 'galaxie-woo' ) );

		// The notices already waiting before anything below runs, so a failure
		// can take back only what this request added (see fail()).
		if ( function_exists( 'wc_get_notices' ) && function_exists( 'WC' ) && WC()->session ) {
			self::$notices_before = wc_get_notices();
		}

		$intent_id = isset( $_POST['setup_intent'] ) ? sanitize_text_field( wp_unslash( $_POST['setup_intent'] ) ) : '';
		$gateway   = self::gateway();

		if ( ! $gateway ) {
			self::fail( __( 'Não é possível salvar cartões nesta loja no momento.', 'galaxie-woo' ) );
		}

		if ( 0 !== strpos( $intent_id, 'seti_' ) ) {
			self::fail( $failed );
		}

		try {
			$token_id = self::is_funnelkit( $gateway )
				? self::funnelkit_token( $gateway, $intent_id )
				: self::official_token( $gateway, $intent_id );

			wp_send_json_success( array( 'token' => $token_id ) );
		} catch ( \Throwable $e ) {
			self::log( $gateway, $e->getMessage() );

			self::fail( $failed );
		}
	}

	/**
	 * Step 4 with the WooCommerce Stripe plugin: the token comes from its own
	 * create_token_from_setup_intent().
	 *
	 * @param \WC_Stripe_UPE_Payment_Gateway $gateway
	 */
	private static function official_token( $gateway, string $intent_id ): int {
		$intent   = \WC_Stripe_API::retrieve( 'setup_intents/' . $intent_id );
		$customer = new \WC_Stripe_Customer( get_current_user_id() );

		if ( ! empty( $intent->error ) || self::owner_of( $intent ) !== (string) $customer->get_id() || '' === (string) $customer->get_id() ) {
			throw new \RuntimeException( 'The setup intent is not an intent of this customer.' );
		}

		self::require_succeeded( (string) ( $intent->status ?? '' ) );

		$token = $gateway->create_token_from_setup_intent( $intent_id, wp_get_current_user() );

		if ( ! $token instanceof \WC_Payment_Token ) {
			throw new \RuntimeException( 'No token was created from the setup intent.' );
		}

		return (int) $token->get_id();
	}

	/**
	 * Step 4 with FunnelKit: the intent is read back through FunnelKit's own
	 * Stripe client (same keys, same mode as the one that created it), and the
	 * card it set up goes through the gateway's add_payment_method() — see the
	 * class comment. That method reports failure only by queueing a notice and
	 * returning nothing, so anything but its success array is a failure here.
	 *
	 * The customer compared against is the one FunnelKit stored on the user
	 * when step 2 created the intent (`_fkwcs_customer_id`). Asking the gateway
	 * again (get_customer_id()) would make a Stripe call and, should that call
	 * fail, forget the customer and create a new one.
	 *
	 * @param \WC_Payment_Gateway $gateway FunnelKit's CreditCard gateway.
	 */
	private static function funnelkit_token( $gateway, string $intent_id ): int {
		$user_id  = get_current_user_id();
		$client   = $gateway->get_client();
		$response = $client ? $client->setup_intents( 'retrieve', array( $intent_id ) ) : null;
		$intent   = is_array( $response ) && ! empty( $response['success'] ) ? ( $response['data'] ?? null ) : null;

		if ( ! is_object( $intent ) ) {
			throw new \RuntimeException( 'The setup intent could not be read: ' . ( is_array( $response ) ? (string) ( $response['message'] ?? '' ) : 'no client' ) );
		}

		$stored   = get_user_option( '_fkwcs_customer_id', $user_id );
		$customer = is_array( $stored ) ? (string) ( $stored['customer_id'] ?? '' ) : (string) $stored;

		if ( '' === $customer || self::owner_of( $intent ) !== $customer ) {
			throw new \RuntimeException( 'The setup intent is not an intent of this customer.' );
		}

		self::require_succeeded( (string) ( $intent->status ?? '' ) );

		$method    = $intent->payment_method ?? null;
		$method_id = is_object( $method ) ? (string) ( $method->id ?? '' ) : (string) $method;

		if ( 0 !== strpos( $method_id, 'pm_' ) ) {
			throw new \RuntimeException( 'The setup intent carries no payment method.' );
		}

		$brand  = self::funnelkit_brand( $gateway, $method, $method_id );
		$brands = self::funnelkit_brands( $gateway );

		if ( '' !== $brand && $brands && ! in_array( $brand, $brands, true ) ) {
			self::fail( __( 'A loja não aceita cartões desta bandeira. Use outro cartão.', 'galaxie-woo' ), null, 'brand' );
		}

		// What FunnelKit's add-payment-method form posts: the PaymentMethod as
		// `fkwcs_source`, and the gateway as `payment_method` (it names the
		// success action after it).
		$_POST['fkwcs_source']   = $method_id;
		$_POST['payment_method'] = self::FUNNELKIT;

		$result = $gateway->add_payment_method();

		if ( ! is_array( $result ) || 'success' !== ( $result['result'] ?? '' ) ) {
			throw new \RuntimeException( 'FunnelKit did not add the payment method.' );
		}

		foreach ( \WC_Payment_Tokens::get_customer_tokens( $user_id, self::FUNNELKIT ) as $token ) {
			if ( $token instanceof \WC_Payment_Token && $method_id === (string) $token->get_token() ) {
				return (int) $token->get_id();
			}
		}

		// Saved (FunnelKit said so) but not found under this gateway: say
		// nothing of a token rather than call the save a failure — the widget
		// redraws the list either way, and a retry would save the card twice.
		return 0;
	}

	/**
	 * Refuses every intent status but `succeeded`.
	 *
	 * Stripe's plugin counts processing, requires_action and
	 * requires_confirmation as a successful setup too, but none of them is a
	 * card that can be charged yet: saving it now would put a card in the list
	 * that may still be declined. Only a succeeded intent is saved; the others
	 * get a message saying where it stands.
	 */
	private static function require_succeeded( string $status ): void {
		if ( 'processing' === $status ) {
			self::fail( __( 'O banco ainda está confirmando este cartão, por isso ele não foi salvo. Aguarde alguns minutos e tente de novo.', 'galaxie-woo' ), null, 'processing' );
		}

		if ( in_array( $status, array( 'requires_action', 'requires_confirmation' ), true ) ) {
			self::fail( __( 'A confirmação do cartão com o banco não foi concluída, por isso ele não foi salvo. Tente de novo.', 'galaxie-woo' ), null, $status );
		}

		if ( 'succeeded' !== $status ) {
			throw new \RuntimeException( 'The setup intent did not succeed: ' . $status );
		}
	}

	/** The Stripe customer id a SetupIntent belongs to, expanded or not; '' for none. */
	private static function owner_of( $intent ): string {
		$customer = $intent->customer ?? null;

		return is_object( $customer ) ? (string) ( $customer->id ?? '' ) : (string) $customer;
	}

	/**
	 * The card brands FunnelKit's gateway accepts, as Stripe's brand slugs
	 * (`visa`, `amex`…). FunnelKit keeps them in `allowed_cards`; an empty list
	 * means any.
	 *
	 * @param \WC_Payment_Gateway $gateway
	 * @return string[]
	 */
	private static function funnelkit_brands( $gateway ): array {
		$brands = isset( $gateway->allowed_cards ) && is_array( $gateway->allowed_cards ) ? $gateway->allowed_cards : array();

		return array_values( array_filter( array_map( static fn( $brand ): string => strtolower( (string) $brand ), $brands ) ) );
	}

	/**
	 * The brand of the card on the intent: from the expanded PaymentMethod when
	 * Stripe sent one, otherwise read through FunnelKit's client. '' when it
	 * cannot be told — the brand check is then left to the checkout, which
	 * FunnelKit enforces on every payment anyway.
	 *
	 * @param \WC_Payment_Gateway $gateway
	 * @param mixed               $method
	 */
	private static function funnelkit_brand( $gateway, $method, string $method_id ): string {
		if ( ! is_object( $method ) ) {
			$client   = $gateway->get_client();
			$response = $client ? $client->payment_methods( 'retrieve', array( $method_id ) ) : null;
			$method   = is_array( $response ) && ! empty( $response['success'] ) ? ( $response['data'] ?? null ) : null;
		}

		return is_object( $method ) && isset( $method->card ) && is_object( $method->card ) ? strtolower( (string) ( $method->card->brand ?? '' ) ) : '';
	}

	/**
	 * Writes a failed save to the active plugin's own log.
	 *
	 * @param \WC_Payment_Gateway $gateway
	 */
	private static function log( $gateway, string $message ): void {
		$line = 'Galaxie: could not save a card from the account screen. ' . $message;

		if ( self::is_funnelkit( $gateway ) ) {
			if ( class_exists( '\FKWCS\Gateway\Stripe\Helper' ) && method_exists( '\FKWCS\Gateway\Stripe\Helper', 'log' ) ) {
				\FKWCS\Gateway\Stripe\Helper::log( $line );
			}
			return;
		}

		if ( class_exists( 'WC_Stripe_Logger' ) ) {
			\WC_Stripe_Logger::error( 'Galaxie: could not save a card from the account screen.', array( 'error_message' => $message ) );
		}
	}

	/**
	 * The session's notices as they stood when the save began, or null before then.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $notices_before = null;

	/**
	 * Refuses the save with a message for the widget.
	 *
	 * Both Stripe plugins report their own failures with wc_add_notice() — the
	 * WooCommerce one from create_token_from_setup_intent(), FunnelKit from
	 * add_payment_method(). In an AJAX request nothing prints that notice, so it
	 * would wait in the session and greet the customer on the next page they
	 * open. The widget already says what went wrong, so the queue is put back
	 * as it was before the save: notices queued earlier, for a page the customer
	 * has yet to see, stay.
	 */
	private static function fail( string $message, ?int $http_status = null, string $intent_status = '' ): void {
		if ( null !== self::$notices_before && function_exists( 'wc_set_notices' ) && function_exists( 'WC' ) && WC()->session ) {
			wc_set_notices( self::$notices_before );
		}

		$data = array( 'message' => $message );

		if ( '' !== $intent_status ) {
			$data['status'] = $intent_status;
		}

		wp_send_json_error( $data, $http_status );
	}

	/** Whether `$gateway` is FunnelKit's card gateway rather than the WooCommerce Stripe plugin's. */
	private static function is_funnelkit( $gateway ): bool {
		return $gateway instanceof \WC_Payment_Gateway && self::FUNNELKIT === $gateway->id;
	}

	/**
	 * The card gateway that can save a card for this customer: the WooCommerce
	 * Stripe plugin's when it qualifies, otherwise FunnelKit's.
	 *
	 * @return \WC_Payment_Gateway|null
	 */
	private static function gateway() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}

		$gateways = WC()->payment_gateways()->payment_gateways();

		return self::official_gateway( $gateways ) ?? self::funnelkit_gateway( $gateways );
	}

	/**
	 * The Stripe gateway, when it can save a card for this customer: enabled,
	 * with a publishable key, and with the merchant's "Saved cards" setting on.
	 * With that setting off Stripe would still attach the card and WooCommerce
	 * would still store a token, but the gateway filters every saved card out
	 * of the list — the customer would see nothing, and each retry would attach
	 * one more card.
	 *
	 * @param array<string,mixed> $gateways
	 */
	private static function official_gateway( array $gateways ): ?\WC_Stripe_UPE_Payment_Gateway {
		if ( ! class_exists( 'WC_Stripe_UPE_Payment_Gateway' ) ) {
			return null;
		}

		$gateway = $gateways[ \WC_Stripe_UPE_Payment_Gateway::ID ] ?? null;

		if ( ! $gateway instanceof \WC_Stripe_UPE_Payment_Gateway || 'yes' !== $gateway->enabled || '' === (string) $gateway->publishable_key ) {
			return null;
		}

		$saved_cards = method_exists( $gateway, 'is_saved_cards_enabled' )
			? (bool) $gateway->is_saved_cards_enabled()
			: 'yes' === $gateway->get_option( 'saved_cards' );

		return $saved_cards ? $gateway : null;
	}

	/**
	 * FunnelKit's card gateway, when it can save a card for this customer:
	 * enabled, both keys of the current mode set (is_configured()), a Stripe
	 * client built from them, a publishable key, and "Saved Cards" on. With
	 * Saved Cards off FunnelKit never offers a saved card at checkout, so a card
	 * added here would be one nobody can pay with.
	 *
	 * @param array<string,mixed> $gateways
	 * @return \WC_Payment_Gateway|null
	 */
	private static function funnelkit_gateway( array $gateways ) {
		$gateway = $gateways[ self::FUNNELKIT ] ?? null;

		if ( ! $gateway instanceof \WC_Payment_Gateway || ! $gateway->supports( 'add_payment_method' ) ) {
			return null;
		}

		foreach ( array( 'is_configured', 'get_client', 'get_client_key', 'add_payment_method' ) as $method ) {
			if ( ! method_exists( $gateway, $method ) ) {
				return null;
			}
		}

		if ( 'yes' !== $gateway->enabled || ! $gateway->is_configured() || ! $gateway->get_client() || '' === (string) $gateway->get_client_key() ) {
			return null;
		}

		return 'yes' === ( $gateway->enable_saved_cards ?? '' ) ? $gateway : null;
	}
}
