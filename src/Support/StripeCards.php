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
 * Everything that talks to Stripe is the WooCommerce Stripe plugin's own code,
 * reached the way its add-payment-method page reaches it:
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
 * Step 4 checks, before anything is saved, that the SetupIntent succeeded and
 * belongs to the signed-in customer's Stripe customer: an intent id posted by
 * someone else is refused rather than trusted.
 */
final class StripeCards {

	public const NONCE_ACTION = 'galaxie_woo_stripe_cards';

	/** The Stripe plugin's own nonce action for step 2. */
	private const INTENT_NONCE_ACTION = 'wc_stripe_create_and_confirm_setup_intent_nonce';

	public static function hooks(): void {
		add_action( 'wp_ajax_galaxie_stripe_save_card', array( self::class, 'ajax_save_card' ) );
	}

	/**
	 * What the browser needs to add a card inline, or null when it cannot: no
	 * Stripe plugin, the gateway switched off, no publishable key, or nobody
	 * signed in.
	 *
	 * @return array<string,string>|null
	 */
	public static function client_config(): ?array {
		$gateway = is_user_logged_in() ? self::gateway() : null;

		if ( ! $gateway ) {
			return null;
		}

		return array(
			'key'         => (string) $gateway->publishable_key,
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'intentNonce' => wp_create_nonce( self::INTENT_NONCE_ACTION ),
			'saveNonce'   => wp_create_nonce( self::NONCE_ACTION ),
			'locale'      => substr( get_locale(), 0, 2 ),
		);
	}

	public static function ajax_save_card(): void {
		$failed = __( 'Não foi possível salvar o cartão. Tente de novo.', 'galaxie-woo' );

		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) || ! is_user_logged_in() ) {
			self::fail( __( 'Sua sessão expirou. Recarregue a página e tente de novo.', 'galaxie-woo' ), 403 );
		}

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
			$intent   = \WC_Stripe_API::retrieve( 'setup_intents/' . $intent_id );
			$customer = new \WC_Stripe_Customer( get_current_user_id() );
			$owner    = is_object( $intent->customer ?? null ) ? (string) $intent->customer->id : (string) ( $intent->customer ?? '' );

			if ( ! empty( $intent->error ) || '' === $owner || $owner !== (string) $customer->get_id() ) {
				throw new \RuntimeException( 'The setup intent is not an intent of this customer.' );
			}

			$status = (string) ( $intent->status ?? '' );

			// Stripe's plugin counts processing, requires_action and
			// requires_confirmation as a successful setup too, but none of them
			// is a card that can be charged yet: saving it now would put a card
			// in the list that may still be declined. Only a succeeded intent is
			// saved; the others get a message saying where it stands.
			if ( 'processing' === $status ) {
				self::fail( __( 'O banco ainda está confirmando este cartão, por isso ele não foi salvo. Aguarde alguns minutos e tente de novo.', 'galaxie-woo' ), null, 'processing' );
			}

			if ( in_array( $status, array( 'requires_action', 'requires_confirmation' ), true ) ) {
				self::fail( __( 'A confirmação do cartão com o banco não foi concluída, por isso ele não foi salvo. Tente de novo.', 'galaxie-woo' ), null, $status );
			}

			if ( 'succeeded' !== $status ) {
				throw new \RuntimeException( 'The setup intent did not succeed: ' . $status );
			}

			$token = $gateway->create_token_from_setup_intent( $intent_id, wp_get_current_user() );

			if ( ! $token instanceof \WC_Payment_Token ) {
				throw new \RuntimeException( 'No token was created from the setup intent.' );
			}

			wp_send_json_success( array( 'token' => $token->get_id() ) );
		} catch ( \Throwable $e ) {
			if ( class_exists( 'WC_Stripe_Logger' ) ) {
				\WC_Stripe_Logger::error( 'Galaxie: could not save a card from the account screen.', array( 'error_message' => $e->getMessage() ) );
			}

			self::fail( $failed );
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
	 * The Stripe plugin reports its own failures with wc_add_notice() — among
	 * them create_token_from_setup_intent(). In an AJAX request nothing prints
	 * that notice, so it would wait in the session and greet the customer on
	 * the next page they open. The widget already says what went wrong, so the
	 * queue is put back as it was before the save: notices queued earlier, for
	 * a page the customer has yet to see, stay.
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

	/**
	 * The Stripe gateway, when it can save a card for this customer: enabled,
	 * with a publishable key, and with the merchant's "Saved cards" setting on.
	 * With that setting off Stripe would still attach the card and WooCommerce
	 * would still store a token, but the gateway filters every saved card out
	 * of the list — the customer would see nothing, and each retry would attach
	 * one more card.
	 */
	private static function gateway(): ?\WC_Stripe_UPE_Payment_Gateway {
		if ( ! class_exists( 'WC_Stripe_UPE_Payment_Gateway' ) || ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}

		$gateway = WC()->payment_gateways()->payment_gateways()[ \WC_Stripe_UPE_Payment_Gateway::ID ] ?? null;

		if ( ! $gateway instanceof \WC_Stripe_UPE_Payment_Gateway || 'yes' !== $gateway->enabled || '' === (string) $gateway->publishable_key ) {
			return null;
		}

		$saved_cards = method_exists( $gateway, 'is_saved_cards_enabled' )
			? (bool) $gateway->is_saved_cards_enabled()
			: 'yes' === $gateway->get_option( 'saved_cards' );

		return $saved_cards ? $gateway : null;
	}
}
