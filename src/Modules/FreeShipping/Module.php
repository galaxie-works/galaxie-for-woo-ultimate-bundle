<?php
/**
 * Free Shipping module — one threshold, and the geography it applies to.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\FreeShipping;

use Galaxie\Woo\Core\Field;
use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\Plugin;
use Galaxie\Woo\Core\ProvidesSettings;

defined( 'ABSPATH' ) || exit;

/**
 * "Acima de R$ 350, o frete é por nossa conta" — but only where that sentence
 * is affordable.
 *
 * A bare threshold is a promise made to everyone, and the store is in São
 * Paulo: the same R$ 350 order goes to Recife for several times what it costs
 * across town, and to the United States for more than the order is worth. A
 * rule with no geography is not a generous rule, it is an unpriced one.
 *
 * So the threshold is scoped three ways, each answering a different way of
 * losing money:
 *
 *   - WHERE, by shipping zone. WooCommerce already models geography this way
 *     and the store already has a Brasil zone; anything outside the ticked
 *     zones is simply not offered free shipping. This is what stops the
 *     overseas order.
 *   - WHERE, more finely, by state. Within Brazil, São Paulo is not Amazonas.
 *   - HOW MUCH, by a cap on what the store is willing to absorb. This is the
 *     one that answers distance honestly: free up to R$ 30 of shipping means
 *     Recife still gets R$ 30 off rather than either a blank refusal or a bill
 *     the store did not expect.
 *
 * Nothing is selected by default, and nothing selected means the rule applies
 * nowhere. A free shipping rule that defaults to everywhere is exactly the
 * mistake this class exists to prevent.
 *
 * Read off production before designing this: rates there come from Melhor Envio
 * — six carrier methods in a Brasil zone — and there is no Free Shipping method
 * anywhere. A method added beside those six becomes a SEVENTH option the shopper
 * takes INSTEAD of a carrier, which is a shipping method with no carrier
 * attached. Hence the default of zeroing what the carriers quoted.
 */
final class Module implements ModuleContract, ProvidesSettings {

	public const MODE_ZERO     = 'zero';
	public const MODE_SEPARATE = 'separate';

	public const OVER_PARTIAL = 'partial';
	public const OVER_NONE    = 'none';

	public function id(): string {
		return 'free-shipping';
	}

	public function title(): string {
		return __( 'Free Shipping', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Free shipping above an order value, limited to the zones, states and carriers you choose — and read by the cart\'s progress bar so both say the same number.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return false;
	}

	public function boot(): void {
		add_filter( 'woocommerce_package_rates', array( $this, 'apply' ), 100, 2 );
	}

	/* ---------------------------------------------------------------------
	 * The rule
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string,mixed>
	 */
	private static function settings(): array {
		return Plugin::instance()->settings()->module_settings( 'free-shipping' );
	}

	private static function active(): bool {
		return Plugin::instance()->settings()->is_enabled( 'free-shipping', false );
	}

	/**
	 * The threshold for THIS customer: the configured minimum where the rule
	 * reaches them, and 0.0 where it does not.
	 *
	 * The cart's progress bar asks this, which is the point — a bar counting
	 * down to free shipping for someone in Florida is a lie with a percentage
	 * on it.
	 */
	public static function threshold(): float {
		if ( ! self::active() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0.0;
		}

		$packages = WC()->cart->get_shipping_packages();
		$package  = reset( $packages );

		if ( ! $package || ! self::covers( $package ) ) {
			return 0.0;
		}

		return (float) ( self::settings()['minimum'] ?? 0 );
	}

	/**
	 * Does the rule reach this destination?
	 *
	 * @param array<string,mixed> $package
	 */
	private static function covers( array $package ): bool {
		$settings = self::settings();
		$minimum  = (float) ( $settings['minimum'] ?? 0 );

		if ( $minimum <= 0 ) {
			return false;
		}

		$zones = (array) ( $settings['zones'] ?? array() );

		// Empty means nowhere, deliberately. The alternative reading —
		// "everywhere" — is the one that ships free parcels to another
		// continent because a field was left blank.
		if ( ! $zones || ! class_exists( '\WC_Shipping_Zones' ) ) {
			return false;
		}

		$zone = \WC_Shipping_Zones::get_zone_matching_package( $package );

		if ( ! $zone || ! in_array( (string) $zone->get_id(), array_map( 'strval', $zones ), true ) ) {
			return false;
		}

		$states = self::states( (string) ( $settings['states'] ?? '' ) );

		if ( ! $states ) {
			return true;
		}

		$destination = strtoupper( (string) ( $package['destination']['state'] ?? '' ) );

		return in_array( $destination, $states, true );
	}

	/**
	 * @return array<int,string>
	 */
	private static function states( string $raw ): array {
		$states = array_filter( array_map( 'trim', explode( ',', strtoupper( $raw ) ) ) );

		return array_values( $states );
	}

	/**
	 * Cart contents only — never a total that already includes shipping, or an
	 * expensive carrier could push an order over the line and then become free
	 * for having been expensive.
	 */
	public static function cart_total(): float {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0.0;
		}

		$subtotal = (float) WC()->cart->get_subtotal();

		if ( empty( self::settings()['include_discounts'] ) ) {
			return $subtotal;
		}

		return max( 0.0, $subtotal - (float) WC()->cart->get_discount_total() );
	}

	/**
	 * @param array<string,\WC_Shipping_Rate> $rates
	 * @param array<string,mixed>             $package
	 * @return array<string,\WC_Shipping_Rate>
	 */
	public function apply( array $rates, array $package ): array {
		if ( ! self::active() || ! self::covers( $package ) ) {
			return $rates;
		}

		$settings = self::settings();
		$minimum  = (float) ( $settings['minimum'] ?? 0 );

		if ( self::cart_total() < $minimum ) {
			return $rates;
		}

		$label   = (string) ( $settings['label'] ?? __( 'Frete grátis', 'galaxie-woo' ) );
		$cap     = (float) ( $settings['max_subsidy'] ?? 0 );
		$methods = array_map( 'strval', (array) ( $settings['methods'] ?? array() ) );

		if ( self::MODE_SEPARATE === ( $settings['mode'] ?? self::MODE_ZERO ) ) {
			return array( 'galaxie_free_shipping' => new \WC_Shipping_Rate( 'galaxie_free_shipping', $label, 0.0, array(), 'galaxie_free_shipping' ) ) + $rates;
		}

		foreach ( $rates as $key => $rate ) {
			// An empty carrier list means every carrier in those zones. A
			// non-empty one is the store saying "free by PAC, not by Sedex",
			// which is how a threshold survives an express carrier.
			if ( $methods && ! in_array( (string) $rate->get_method_id(), $methods, true ) ) {
				continue;
			}

			$cost = (float) $rate->get_cost();

			if ( $cap > 0 && $cost > $cap ) {
				if ( self::OVER_NONE === ( $settings['over_cap'] ?? self::OVER_PARTIAL ) ) {
					continue;
				}

				$rate->set_cost( $cost - $cap );
				$rate->set_taxes( array() );
				continue;
			}

			$rate->set_cost( 0.0 );
			$rate->set_taxes( array() );

			// The carrier stays in the label: the shopper is still choosing
			// one, and "Frete grátis (Correios PAC)" carries the delivery
			// estimate that a bare "Frete grátis" throws away.
			$rate->set_label( sprintf( '%s (%s)', $label, $rate->get_label() ) );

			unset( $key );
		}

		return $rates;
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	public function settings_tab_label(): string {
		return __( 'Free Shipping', 'galaxie-woo' );
	}

	/**
	 * The store's own shipping zones, so the merchant ticks places that exist
	 * rather than typing country codes at a text field.
	 *
	 * @return array<string,string>
	 */
	private static function zone_options(): array {
		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			return array();
		}

		$options = array();

		foreach ( \WC_Shipping_Zones::get_zones() as $zone ) {
			$options[ (string) $zone['zone_id'] ] = (string) $zone['zone_name'];
		}

		// Zone 0 is WooCommerce's "everywhere my other zones do not cover",
		// which for a São Paulo store means the rest of the planet. It is
		// offered, because a merchant may genuinely want it, and it is named
		// plainly so that ticking it is a decision rather than an accident.
		$options['0'] = __( 'Everywhere else (the rest of the world)', 'galaxie-woo' );

		return $options;
	}

	/**
	 * @return array<string,string>
	 */
	private static function method_options(): array {
		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			return array();
		}

		$options = array();

		foreach ( \WC_Shipping_Zones::get_zones() as $zone ) {
			$instance = \WC_Shipping_Zones::get_zone( (int) $zone['zone_id'] );

			if ( ! $instance ) {
				continue;
			}

			foreach ( $instance->get_shipping_methods( true ) as $method ) {
				$options[ (string) $method->id ] = (string) $method->get_method_title();
			}
		}

		return $options;
	}

	/** @return Field[] */
	public function settings_fields(): array {
		return array(
			new Field(
				key: 'minimum',
				label: __( 'Free shipping from', 'galaxie-woo' ),
				type: Field::TYPE_NUMBER,
				description: __( 'Order value above which shipping stops being charged. The cart\'s progress bar reads this same number.', 'galaxie-woo' ),
				default: 0,
				placeholder: '350'
			),
			new Field(
				key: 'zones',
				label: __( 'Where it applies', 'galaxie-woo' ),
				type: Field::TYPE_MULTI,
				description: __( 'Nothing ticked means nowhere. Leaving this blank cannot accidentally offer free shipping abroad.', 'galaxie-woo' ),
				default: array(),
				options: self::zone_options()
			),
			new Field(
				key: 'states',
				label: __( 'Only these states', 'galaxie-woo' ),
				type: Field::TYPE_TEXT,
				description: __( 'Two-letter codes separated by commas, e.g. SP, RJ, MG. Leave blank for the whole zone.', 'galaxie-woo' ),
				placeholder: 'SP, RJ, MG'
			),
			new Field(
				key: 'max_subsidy',
				label: __( 'Most the store will absorb', 'galaxie-woo' ),
				type: Field::TYPE_NUMBER,
				description: __( 'The cap on one parcel. Zero means no cap — the whole quote is absorbed however far the parcel goes.', 'galaxie-woo' ),
				default: 0,
				placeholder: '30'
			),
			new Field(
				key: 'over_cap',
				label: __( 'When the quote is above the cap', 'galaxie-woo' ),
				type: Field::TYPE_SELECT,
				default: self::OVER_PARTIAL,
				options: array(
					self::OVER_PARTIAL => __( 'Take the cap off and charge the rest', 'galaxie-woo' ),
					self::OVER_NONE    => __( 'Charge the full quote', 'galaxie-woo' ),
				)
			),
			new Field(
				key: 'methods',
				label: __( 'Which carriers', 'galaxie-woo' ),
				type: Field::TYPE_MULTI,
				description: __( 'Nothing ticked means all of them. Ticking only the economy carrier is how a threshold survives an express quote.', 'galaxie-woo' ),
				default: array(),
				options: self::method_options()
			),
			new Field(
				key: 'include_discounts',
				label: __( 'Count the cart after discounts', 'galaxie-woo' ),
				type: Field::TYPE_TOGGLE,
				description: __( 'On, a coupon can push an order back below the threshold.', 'galaxie-woo' ),
				default: true
			),
			new Field(
				key: 'mode',
				label: __( 'How to apply it', 'galaxie-woo' ),
				type: Field::TYPE_SELECT,
				description: __( 'Zeroing keeps the carrier the shopper chose, and its delivery estimate. A separate option replaces that choice with one that names no carrier.', 'galaxie-woo' ),
				default: self::MODE_ZERO,
				options: array(
					self::MODE_ZERO     => __( 'Zero what the carriers quoted', 'galaxie-woo' ),
					self::MODE_SEPARATE => __( 'Offer it as a separate option', 'galaxie-woo' ),
				)
			),
			new Field(
				key: 'label',
				label: __( 'Label', 'galaxie-woo' ),
				type: Field::TYPE_TEXT,
				default: 'Frete grátis',
				placeholder: 'Frete grátis'
			),
		);
	}

	public function render_extra_settings( array $values ): void {}

	/**
	 * @param array<string,mixed> $submitted
	 * @param array<string,mixed> $current
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( array $submitted, array $current ): array {
		$sanitized = Field::sanitize_all( $this->settings_fields(), $submitted );

		$sanitized['minimum']     = max( 0.0, (float) $sanitized['minimum'] );
		$sanitized['max_subsidy'] = max( 0.0, (float) $sanitized['max_subsidy'] );
		$sanitized['states']      = implode( ', ', self::states( (string) $sanitized['states'] ) );

		if ( '' === trim( (string) $sanitized['label'] ) ) {
			$sanitized['label'] = __( 'Frete grátis', 'galaxie-woo' );
		}

		return $sanitized;
	}
}
