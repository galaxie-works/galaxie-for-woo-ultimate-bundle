<?php
/**
 * FunnelKit express buttons (Apple Pay / Google Pay) for ready customers only.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\FunnelKitExpress;

use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Integrations\BrazilianCheckoutFields;
use Galaxie\Woo\Support\ProfileFields;

defined( 'ABSPATH' ) || exit;

/**
 * The merchant's rule (audit CP-01): the wallet buttons FunnelKit Stripe puts
 * on the PRODUCT and CART pages are for signed-in customers whose profile
 * already holds a CPF and a date of birth. Everyone else — visitors, and
 * customers still missing either — buys through the normal checkout, where
 * the profile step asks for both. The checkout's own wallet buttons are left
 * alone: whoever reaches the payment step has been through that step.
 *
 * Why the profile matters: an express order skips our checkout island, so the
 * only CPF it can carry is the profile's — BrazilianCheckoutFields fills
 * `billing_cpf` / `billing_document` / `billing_persontype` from it on
 * `woocommerce_checkout_process` and `woocommerce_checkout_posted_data`, which
 * FunnelKit's express endpoint runs too (`process_smart_checkout()` ends in
 * `WC()->checkout()->process_checkout()`). Without a CPF the order would be
 * placed with none; without a birth date the age gate was never met.
 *
 * How (FunnelKit Stripe 1.15.0.1, gateways/smart-buttons.php): one
 * SmartButtons instance, made on `wp_loaded` (10), hooks its
 * `payment_request_button()` on the product hook (`woocommerce_after_add_to_cart_button`
 * at 1 for its "below" default, `woocommerce_after_add_to_cart_quantity` at 10
 * for "above"), the cart hook (`woocommerce_proceed_to_checkout` at 1) and a
 * checkout hook. FunnelKit has no filter that decides per visitor (its
 * position filters run in that constructor), so for a customer who is not
 * ready the product and cart registrations are removed, right after it made
 * them. That covers WooCommerce's own templates and the bundle's Buy Box and
 * Cart Totals widgets alike — both only fire those actions — and leaves no
 * wrapper and no "ou" separator behind. The express script is then dequeued on
 * those pages: with no container it only cost a Stripe.js download.
 *
 * Caching: guests get cached product pages, and they never see the buttons,
 * so a cached page is always right for them. LiteSpeed keeps signed-in
 * visitors out of the public cache (its login vary cookie), so their pages
 * are rendered for them.
 */
final class Module implements ModuleContract {

	/** FunnelKit's class (gateways/smart-buttons.php). */
	public const SMART_BUTTONS = '\FKWCS\Gateway\Stripe\SmartButtons';

	/** FunnelKit's express script handle (registered in SmartButtons::register_stripe_js()). */
	public const SCRIPT = 'fkwcs-express-checkout-js';

	/** Everything FunnelKit enqueues on product and cart pages for the wallet. */
	private const STOREFRONT_SCRIPTS = array( self::SCRIPT, 'fkwcs-stripe-js', 'fkwcs-stripe-external' );

	public function id(): string {
		return 'funnelkit-express';
	}

	public function title(): string {
		return __( 'FunnelKit express buttons for ready customers', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Shows FunnelKit\'s Apple Pay / Google Pay buttons on product and cart pages only to signed-in customers whose profile has a CPF and a date of birth. Everyone else uses the normal checkout. The buttons on the checkout\'s payment step are not affected.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return true;
	}

	public function boot(): void {
		// After FunnelKit's own wp_loaded (10), which builds SmartButtons and hooks it.
		add_action( 'wp_loaded', array( self::class, 'gate' ), 20 );
		// After FunnelKit enqueues the express script (101).
		add_action( 'wp_enqueue_scripts', array( self::class, 'dequeue' ), 102 );
	}

	/**
	 * Whether this customer may see the product / cart wallet buttons: signed
	 * in, a valid CPF on the profile, and a date of birth the age gate accepts.
	 */
	public static function customer_ready( int $user_id ): bool {
		if ( $user_id <= 0 || '' === BrazilianCheckoutFields::customer_cpf( $user_id ) ) {
			return false;
		}

		$birthdate = trim( (string) get_user_meta( $user_id, ProfileFields::BIRTHDATE, true ) );
		if ( '' === $birthdate ) {
			return false;
		}

		if ( class_exists( '\Galaxie\Woo\Modules\AgeGate\Module' ) ) {
			return null === \Galaxie\Woo\Modules\AgeGate\Module::check( $birthdate );
		}

		return true;
	}

	/** On the storefront and its AJAX (wc-ajax cart refreshes): not in wp-admin screens. */
	private static function storefront(): bool {
		return ! is_admin() || wp_doing_ajax();
	}

	/**
	 * Unhooks FunnelKit's product and cart wallet for a customer who is not
	 * ready. Returns how many registrations were removed (for the tests).
	 */
	public static function gate(): int {
		if ( ! self::storefront() || ! class_exists( self::SMART_BUTTONS, false ) || self::customer_ready( get_current_user_id() ) ) {
			return 0;
		}

		$checkout = self::checkout_hook();
		$removed  = 0;

		foreach ( self::product_and_cart_hooks() as $hook ) {
			if ( $hook === $checkout ) {
				continue;
			}

			foreach ( self::callbacks_on( $hook ) as $priority => $callback ) {
				if ( remove_action( $hook, $callback, $priority ) ) {
					++$removed;
				}
			}
		}

		return $removed;
	}

	/**
	 * No container on the page, so none of FunnelKit's scripts either: the
	 * express script, its main script and Stripe.js itself. Stripe.js alone is
	 * ~290 KB and pulls in hCaptcha, PerimeterX and Google Pay frames; measured
	 * on a throttled phone it held the product page's DOMContentLoaded (and so
	 * pixfort's page-transition overlay) back by about 1.5 s. Nothing else on a
	 * product or cart page uses them — the card and Pix fields live on the
	 * checkout, which this never touches.
	 */
	public static function dequeue(): void {
		if ( ! function_exists( 'is_product' ) || ! ( is_product() || is_cart() ) || self::customer_ready( get_current_user_id() ) ) {
			return;
		}

		foreach ( self::STOREFRONT_SCRIPTS as $handle ) {
			wp_dequeue_script( $handle );
		}
	}

	/**
	 * Every hook FunnelKit may have put the product or cart wallet on: both
	 * product positions (it picks one from its settings) and the cart one,
	 * each through the filter FunnelKit itself applies.
	 *
	 * @return string[]
	 */
	public static function product_and_cart_hooks(): array {
		$hooks = array();

		foreach ( array( 'woocommerce_after_add_to_cart_quantity', 'woocommerce_after_add_to_cart_button' ) as $hook ) {
			$hooks[] = $hook;
			$hooks[] = (string) apply_filters( 'fkwcs_express_button_single_product_position', $hook, array() );
		}

		$hooks[] = (string) apply_filters( 'fkwcs_express_button_cart_position', 'woocommerce_proceed_to_checkout' );

		return array_values( array_unique( array_filter( $hooks ) ) );
	}

	/** The checkout's wallet hook, never touched. '' when FunnelKit cannot say. */
	private static function checkout_hook(): string {
		$class = self::SMART_BUTTONS;

		if ( ! method_exists( $class, 'get_instance' ) ) {
			return '';
		}

		$instance = $class::get_instance();
		$hook     = is_object( $instance ) && method_exists( $instance, 'get_resolved_checkout_hook' ) ? (string) $instance->get_resolved_checkout_hook() : '';

		return '' === $hook ? '' : (string) apply_filters( 'fkwcs_express_button_checkout_position', $hook );
	}

	/**
	 * SmartButtons' `payment_request_button` registrations on $hook, by priority.
	 *
	 * @return array<int,array{0:object,1:string}>
	 */
	private static function callbacks_on( string $hook ): array {
		global $wp_filter;

		$found = array();
		$class = ltrim( self::SMART_BUTTONS, '\\' );

		if ( ! isset( $wp_filter[ $hook ] ) || ! is_object( $wp_filter[ $hook ] ) || ! isset( $wp_filter[ $hook ]->callbacks ) ) {
			return $found;
		}

		foreach ( (array) $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( (array) $callbacks as $entry ) {
				$function = $entry['function'] ?? null;

				if ( is_array( $function ) && isset( $function[0], $function[1] ) && $function[0] instanceof $class && 'payment_request_button' === $function[1] ) {
					$found[ (int) $priority ] = $function;
				}
			}
		}

		return $found;
	}
}
