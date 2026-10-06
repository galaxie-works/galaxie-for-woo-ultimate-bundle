<?php
/**
 * FunnelKit Stripe's Pix gateway, handed the customer's CPF.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Pix needs the payer's tax id — Stripe's `billing_details.tax_id`, a CPF or
 * a CNPJ — and FunnelKit's `fkwcs_stripe_pix` (FunnelKit Stripe 1.15.0.1)
 * never sends one from the checkout:
 *
 *  - The PaymentIntent is created on the server WITHOUT a payment method
 *    (LocalGateway::process_payment → get_payment_intent) and confirmed in
 *    the browser: stripe-elements.js, `FKWCS_PIX.confirmStripePayment`, calls
 *    `stripe.confirmPayment({ elements, clientSecret, confirmParams: {
 *    payment_method_data: { billing_details: this.getBillingAddress() } } })`.
 *  - Its Payment Element is created with `fields: { billingDetails: 'never' }`
 *    (Pix::payment_element_data), and `getBillingAddress()` returns name,
 *    e-mail, phone and address read off `#billing_*` — no tax id.
 *
 * So the server filter it offers, `fkwcs_payment_intent_data`, is the wrong
 * place: a `payment_method_data` put on the intent at creation makes a
 * PaymentMethod the browser's confirm then replaces with the one it builds
 * from the Element and `getBillingAddress()`. The tax id has to be in what
 * `getBillingAddress()` returns. FunnelKit's way in is the jQuery event it
 * fires right after building its gateways, `fkwcs_gateway_loaded`, whose
 * argument carries every gateway class (`FKWCS_PIX` among them) for exactly
 * this kind of extension: we wrap `FKWCS_PIX.prototype.getBillingAddress` to
 * add `tax_id` — Pix only, never overwriting one, and handing back a copy
 * rather than touching FunnelKit's objects. The instances already exist when
 * the event fires, and they find the method on the prototype, so they pick
 * the wrapper up.
 *
 * The event fires inside stripe-elements.js's own jQuery-ready callback, so
 * the listener has to be bound before that file runs: an inline script
 * printed BEFORE FunnelKit's `fkwcs-stripe-js` handle. The CPF comes from the
 * Brazilian plugin's fields the checkout fills (`#billing_cpf`,
 * `#billing_cnpj`, `#billing_document`, see BrazilianCheckoutFields), falling
 * back to the signed-in customer's profile CPF — already on this page in the
 * checkout island's own data — for a store without those fields.
 *
 * No-ops unless FunnelKit Stripe is active and has enqueued its script.
 */
final class FunnelKitPixTaxId {

	public const PIX_GATEWAY = 'fkwcs_stripe_pix';

	/** FunnelKit's checkout script (assets/js/stripe-elements.js), registered in Abstract_Payment_Gateway::register_stripe_js(). */
	public const SCRIPT = 'fkwcs-stripe-js';

	public static function is_active(): bool {
		return defined( 'FKWCS_VERSION' );
	}

	public static function hooks(): void {
		// Late: FunnelKit enqueues on this hook at 10, 11 (Google Pay) and 101
		// (express buttons). Still inside wp_enqueue_scripts, so the head —
		// where its script prints — has not gone out yet.
		add_action( 'wp_enqueue_scripts', array( self::class, 'pix_tax_id' ), 999 );
	}

	public static function pix_tax_id(): void {
		if ( ! self::is_active() || ! wp_script_is( self::SCRIPT, 'enqueued' ) ) {
			return;
		}

		$fallback = BrazilianCheckoutFields::customer_cpf( get_current_user_id() );

		wp_add_inline_script( self::SCRIPT, self::inline_script( (string) preg_replace( '/\D/', '', $fallback ) ), 'before' );
	}

	/**
	 * The listener, ES5 and dependency-free beyond the jQuery FunnelKit's
	 * script already depends on (so it is loaded before this prints). Digits
	 * only: 11 is a CPF, 14 a CNPJ; anything else is not a tax id and is left
	 * for Stripe to ask about.
	 */
	public static function inline_script( string $fallback ): string {
		$js = <<<'JS'
(function ($, fallback) {
	if (!$) { return; }
	function taxId() {
		var ids = ['billing_cpf', 'billing_cnpj', 'billing_document'];
		for (var i = 0; i < ids.length; i++) {
			var el = document.getElementById(ids[i]);
			var digits = el ? String(el.value || '').replace(/\D/g, '') : '';
			if (11 === digits.length || 14 === digits.length) { return digits; }
		}
		return fallback;
	}
	$(document).on('fkwcs_gateway_loaded', function (event, classes) {
		var Pix = classes && classes.FKWCS_PIX;
		if (!Pix || !Pix.prototype || Pix.prototype.galaxieTaxId) { return; }
		var base = Pix.prototype.getBillingAddress;
		if ('function' !== typeof base) { return; }
		Pix.prototype.getBillingAddress = function () {
			var details = base.apply(this, arguments);
			var id = taxId();
			if (id && details && 'object' === typeof details && !$.isArray(details) && !details.tax_id) {
				details = $.extend({}, details, { tax_id: id });
			}
			return details;
		};
		Pix.prototype.galaxieTaxId = true;
	});
})(window.jQuery, %s);
JS;

		return sprintf( $js, wp_json_encode( $fallback ) );
	}
}
