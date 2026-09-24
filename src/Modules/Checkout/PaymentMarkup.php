<?php
/**
 * The checkout's payment step: saved cards as rows, and the editor's sample.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Checkout;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce prints a saved card as one sentence — "Visa terminando em 4242
 * (expira em 12/30)" — next to a radio. The checkout shows it the way My
 * Account's Payment Methods widget does instead: the brand's mark, the last
 * four digits, the expiry, and a badge for the default card or one that is
 * about to (or did) expire. Same brand icons, same badge words.
 *
 * Only the words change. The <li>, its radio (id, name, value, checked) and
 * the "new card" row are WooCommerce's own contract with the gateway, and are
 * printed exactly as core prints them, so Stripe — or whatever replaces it —
 * reads the choice as it always did. A card past its expiry month keeps its
 * row, disabled, rather than silently vanishing from a list the shopper
 * remembers.
 *
 * The rows carry neutral classes; the island adds the widget panel's classes
 * to them (native-checkout.ts `decoratePayment`), the same way it does for
 * the shipping rates, because WooCommerce redraws this list over AJAX where
 * no widget settings exist.
 */
final class PaymentMarkup {

	/** Same mapping as the Payment Methods widget: pixfort draws these two, anything else is a generic card. */
	private const BRAND_ICONS = array(
		'visa'       => 'Solid/pixfort-icon-visa-1',
		'mastercard' => 'Solid/pixfort-icon-mastercard-1',
		'master'     => 'Solid/pixfort-icon-mastercard-1',
	);

	private const GENERIC_ICON = 'Line/pixfort-icon-credit-card-1';

	public static function hooks(): void {
		add_filter( 'woocommerce_payment_gateway_get_saved_payment_method_option_html', array( self::class, 'saved_option' ), 10, 3 );
		add_filter( 'woocommerce_payment_gateway_get_new_payment_method_option_html_label', array( self::class, 'new_label' ), 10, 2 );
	}

	/**
	 * @param string            $html    Core's markup.
	 * @param \WC_Payment_Token $token
	 * @param \WC_Payment_Gateway $gateway
	 */
	public static function saved_option( $html, $token, $gateway ): string {
		if ( ! self::on_checkout() || ! $token instanceof \WC_Payment_Token_CC || ! $gateway instanceof \WC_Payment_Gateway ) {
			return (string) $html;
		}

		$expires = sprintf( '%02d/%s', (int) $token->get_expiry_month(), substr( (string) $token->get_expiry_year(), -2 ) );
		$state   = self::expiry_state( $expires );

		// Which row is chosen is not ours to decide: it is whatever the markup
		// we are replacing says (core, the gateway, or a filter before us).
		// Deciding it from is_default() here put a shopper back on the default
		// card whenever the order review was redrawn. An expired card cannot
		// be the chosen one, since its radio is disabled.
		$checked = (bool) preg_match( '/\schecked(?:=|\s|\/|>)/i', (string) $html );

		return self::option(
			$gateway->id,
			(string) $token->get_id(),
			self::label( (string) $token->get_card_type(), (string) $token->get_last4(), $expires, $token->is_default(), $state ),
			$checked && 'expired' !== $state,
			'expired' === $state
		);
	}

	/**
	 * "Usar outro cartão" for a card gateway only: a gateway that saves bank
	 * accounts or wallets keeps its own "new payment method" wording.
	 *
	 * @param string              $label   Core's "Use a new payment method".
	 * @param \WC_Payment_Gateway $gateway
	 */
	public static function new_label( $label, $gateway ): string {
		return self::on_checkout() && self::is_card_gateway( $gateway ) ? __( 'Usar outro cartão', 'galaxie-woo' ) : (string) $label;
	}

	/**
	 * WooCommerce's card-form gateways, and Stripe's card gateway (`stripe`),
	 * which in its Payment Element version does not extend the card class.
	 *
	 * @param mixed $gateway
	 */
	private static function is_card_gateway( $gateway ): bool {
		return $gateway instanceof \WC_Payment_Gateway_CC
			|| ( $gateway instanceof \WC_Payment_Gateway && 'stripe' === $gateway->id );
	}

	/**
	 * A saved-method row, in core's own markup (see
	 * WC_Payment_Gateway::get_saved_payment_method_option_html()) with our
	 * label inside.
	 */
	private static function option( string $gateway_id, string $token_id, string $label, bool $checked, bool $disabled ): string {
		return sprintf(
			'<li class="woocommerce-SavedPaymentMethods-token%5$s"><input id="wc-%1$s-payment-token-%2$s" type="radio" name="wc-%1$s-payment-token" value="%2$s" style="width:auto;" class="woocommerce-SavedPaymentMethods-tokenInput"%3$s%4$s /><label for="wc-%1$s-payment-token-%2$s">%6$s</label></li>',
			esc_attr( $gateway_id ),
			esc_attr( $token_id ),
			$checked ? ' checked="checked"' : '',
			$disabled ? ' disabled="disabled"' : '',
			$disabled ? ' is-expired' : '',
			$label // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by label() from escaped parts.
		);
	}

	/** Brand mark, "•••• 4242", "Validade 12/30", badges. */
	private static function label( string $brand, string $last4, string $expires, bool $default, string $state ): string {
		$badges = $default && 'expired' !== $state ? '<span class="gx-co-card-badge is-default">' . esc_html__( 'Padrão', 'galaxie-woo' ) . '</span>' : '';
		if ( 'expiring' === $state ) {
			$badges .= '<span class="gx-co-card-badge is-expiring">' . esc_html__( 'Vence em breve', 'galaxie-woo' ) . '</span>';
		} elseif ( 'expired' === $state ) {
			$badges .= '<span class="gx-co-card-badge is-expired">' . esc_html__( 'Vencido', 'galaxie-woo' ) . '</span>';
		}

		return sprintf(
			'<span class="gx-co-card-brand" title="%1$s">%2$s</span><span class="gx-co-card-text"><span class="gx-co-card-number"><span class="screen-reader-text">%1$s </span><span aria-hidden="true">••••</span> %3$s</span><span class="gx-co-card-expiry">%4$s %5$s</span></span>%6$s',
			esc_attr( ucfirst( $brand ) ),
			self::brand_icon( $brand ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own icon markup.
			esc_html( $last4 ),
			esc_html__( 'Validade', 'galaxie-woo' ),
			esc_html( $expires ),
			'' !== $badges ? '<span class="gx-co-card-badges">' . $badges . '</span>' : ''
		);
	}

	/**
	 * A payment block for the Elementor editor, which has no gateway to ask:
	 * two saved cards (one default, one expiring), the new-card row with
	 * sample card fields, the save checkbox, and a Pix and a boleto method
	 * below — every state the Style tab has a control for. Same markup as the
	 * live block, so the island decorates it with the same code.
	 *
	 * Without `$saved` (a first purchase) there is no saved-card list, and
	 * the card fields and "save this card" show on their own, as they do for
	 * a customer with nothing on file.
	 */
	public static function sample( bool $saved = true ): string {
		$soon  = gmdate( 'm/y', strtotime( '+1 month' ) );
		$cards = self::option( 'stripe', '101', self::label( 'visa', '4242', '12/30', true, '' ), true, false )
			. self::option( 'stripe', '102', self::label( 'mastercard', '4444', $soon, false, 'expiring' ), false, false )
			. '<li class="woocommerce-SavedPaymentMethods-new"><input id="wc-stripe-payment-token-new" type="radio" name="wc-stripe-payment-token" value="new" style="width:auto;" class="woocommerce-SavedPaymentMethods-tokenInput" /><label for="wc-stripe-payment-token-new">' . esc_html__( 'Usar outro cartão', 'galaxie-woo' ) . '</label></li>';

		$field = static fn( string $label, string $sample, string $class ): string => sprintf(
			'<div class="gx-co-sample-field %3$s"><span class="gx-co-sample-label">%1$s</span><span class="form-control gx-co-sample-input">%2$s</span></div>',
			esc_html( $label ),
			esc_html( $sample ),
			esc_attr( $class )
		);

		$method = static fn( string $id, string $title, string $body, bool $checked ): string => sprintf(
			'<li class="wc_payment_method payment_method_%1$s"><input id="payment_method_%1$s" type="radio" class="input-radio" name="payment_method" value="%1$s"%4$s /><label for="payment_method_%1$s">%2$s</label><div class="payment_box payment_method_%1$s"%5$s>%3$s</div></li>',
			esc_attr( $id ),
			esc_html( $title ),
			$body, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts.
			$checked ? ' checked="checked"' : '',
			$checked ? '' : ' style="display:none;"'
		);

		$card_box = '<fieldset>' . ( $saved ? '<ul class="woocommerce-SavedPaymentMethods wc-saved-payment-methods" data-count="2">' . $cards . '</ul>' : '' )
			. '<fieldset id="wc-stripe-upe-form" class="wc-upe-form wc-payment-form gx-co-sample-card">'
			. $field( __( 'Número do cartão', 'galaxie-woo' ), '1234 1234 1234 1234', 'is-number' )
			. $field( __( 'Validade', 'galaxie-woo' ), 'MM / AA', 'is-expiry' )
			. $field( __( 'CVC', 'galaxie-woo' ), 'CVC', 'is-cvc' )
			. '</fieldset>'
			. '<p class="form-row woocommerce-SavedPaymentMethods-saveNew"><input id="wc-stripe-new-payment-method" name="wc-stripe-new-payment-method" type="checkbox" value="true" style="width:auto;" /><label for="wc-stripe-new-payment-method" style="display:inline;">' . esc_html__( 'Salvar este cartão para as próximas compras', 'galaxie-woo' ) . '</label></p>'
			. '</fieldset>';

		$methods = $method( 'stripe', __( 'Cartão de crédito', 'galaxie-woo' ), $card_box, true )
			. $method( 'pix', __( 'Pix', 'galaxie-woo' ), '<p>' . esc_html__( 'O código Pix aparece depois de finalizar o pedido. Aprovação na hora.', 'galaxie-woo' ) . '</p>', false )
			. $method( 'boleto', __( 'Boleto bancário', 'galaxie-woo' ), '<p>' . esc_html__( 'O boleto vence em 3 dias úteis; o pedido segue após a compensação.', 'galaxie-woo' ) . '</p>', false );

		return '<div id="payment" class="woocommerce-checkout-payment"><ul class="wc_payment_methods payment_methods methods">' . $methods . '</ul>'
			. '<div class="form-row place-order"><div class="woocommerce-terms-and-conditions-wrapper"><div class="woocommerce-privacy-policy-text"><p>' . esc_html__( 'Seus dados pessoais serão usados para processar seu pedido, conforme a nossa política de privacidade.', 'galaxie-woo' ) . '</p></div></div>'
			. '<button type="button" class="button alt" id="place_order" value="Finalizar pedido" data-value="Finalizar pedido">' . esc_html__( 'Finalizar pedido', 'galaxie-woo' ) . '</button></div></div>';
	}

	/**
	 * The checkout page, or WooCommerce's order-review refresh, which defines
	 * WOOCOMMERCE_CHECKOUT and so answers is_checkout() too. My Account's own
	 * payment-method screens keep core's wording.
	 */
	private static function on_checkout(): bool {
		return function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url( 'order-pay' );
	}

	/** Same rule as the Payment Methods widget: 'expired' past its month, 'expiring' within 60 days. */
	private static function expiry_state( string $expires ): string {
		if ( ! preg_match( '#^(\d{1,2})\s*/\s*(\d{2}|\d{4})$#', trim( $expires ), $match ) ) {
			return '';
		}

		$year = (int) $match[2];
		$year = $year < 100 ? 2000 + $year : $year;
		$end  = gmmktime( 23, 59, 59, (int) $match[1] + 1, 0, $year );
		$now  = time();

		if ( $now > $end ) {
			return 'expired';
		}

		return $end - $now < 60 * DAY_IN_SECONDS ? 'expiring' : '';
	}

	private static function brand_icon( string $brand ): string {
		$key  = (string) preg_replace( '/[^a-z]/', '', strtolower( $brand ) );
		$name = self::BRAND_ICONS[ $key ] ?? self::GENERIC_ICON;

		if ( ! class_exists( '\PixfortCore' ) ) {
			return '';
		}

		return (string) \PixfortCore::instance()->icons->getIcon( $name, 32, 'gx-co-card-icon' );
	}
}
