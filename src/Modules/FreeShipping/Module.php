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
use Galaxie\Woo\Support\ShippingRates;

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
		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'state_from_postcode' ) );
	}

	/* ---------------------------------------------------------------------
	 * The rule
	 * ------------------------------------------------------------------ */

	/**
	 * @return array<string,mixed>
	 */
	/** Which quote becomes the free one. */
	public const PICK_CHEAPEST = 'cheapest';
	public const PICK_FASTEST  = 'fastest';
	public const PICK_ALL      = 'all';

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
	/**
	 * Does WooCommerce place this package in one of the zones ticked here?
	 *
	 * Zone only: no minimum, no CEP. {@see covers()} is the stricter question
	 * of whether the promise itself reaches the destination.
	 *
	 * @param array<string,mixed> $package
	 */
	private static function in_our_zones( array $package ): bool {
		$zones = array_map( 'strval', (array) ( self::settings()['zones'] ?? array() ) );

		if ( ! $zones || ! class_exists( '\WC_Shipping_Zones' ) ) {
			return false;
		}

		$zone = \WC_Shipping_Zones::get_zone_matching_package( $package );

		return $zone && in_array( (string) $zone->get_id(), $zones, true );
	}

	private static function covers( array $package ): bool {
		$settings = self::settings();

		// In Brazil the CEP is the destination and the State field is only an
		// opinion. No CEP, or one outside every range, means we cannot say
		// where the parcel goes, and a promise to an unknown place is not made.
		if ( 'BR' === strtoupper( (string) ( $package['destination']['country'] ?? '' ) ) ) {
			$state = \Galaxie\Woo\Support\BrazilianPostcode::state( (string) ( $package['destination']['postcode'] ?? '' ) );

			if ( null === $state ) {
				return false;
			}

			$package['destination']['state'] = $state;
		}
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
	 * Correct each package's state from its CEP before WooCommerce picks a zone.
	 *
	 * Zones here are drawn by state. The state on an address is whatever the
	 * shopper left in a dropdown, and on the test store that dropdown opened on
	 * Sao Paulo. A Recife CEP under it was matched to "Sul e Sudeste", zone
	 * rules and all. The CEP decides instead. A CEP we cannot place leaves the
	 * state as typed.
	 *
	 * @param array<int,array<string,mixed>> $packages
	 * @return array<int,array<string,mixed>>
	 */
	public function state_from_postcode( array $packages ): array {
		if ( ! self::active() ) {
			return $packages;
		}

		foreach ( $packages as $index => $package ) {
			if ( 'BR' !== strtoupper( (string) ( $package['destination']['country'] ?? '' ) ) ) {
				continue;
			}

			$state = \Galaxie\Woo\Support\BrazilianPostcode::state( (string) ( $package['destination']['postcode'] ?? '' ) );

			if ( null !== $state ) {
				$packages[ $index ]['destination']['state'] = $state;
			}
		}

		return $packages;
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
		if ( ! self::active() ) {
			return $rates;
		}

		// WooCommerce's own Free shipping with no requirement, in a zone this
		// module is set for, is the trap the diagnostics panel describes: a free
		// option on a R$ 29,90 cart, sitting beside the rule that says R$ 350.
		// Free shipping has one owner here, so it goes. One that needs a coupon
		// or a minimum of its own stays. Those are deliberate.
		//
		// This runs before the CEP check on purpose. An anonymous visitor with
		// no CEP is placed at the store's own address, São Paulo, which matches
		// the Sul e Sudeste zone by state, and the test store's cart offered them
		// "Free shipping" at R$ 29,90 before they had typed anything.
		if ( self::in_our_zones( $package ) ) {
			foreach ( $rates as $key => $rate ) {
				if ( 'free_shipping' !== $rate->get_method_id() ) {
					continue;
				}

				$method = \WC_Shipping_Zones::get_shipping_method( (int) $rate->get_instance_id() );

				if ( $method && '' === (string) $method->get_option( 'requires' ) ) {
					unset( $rates[ $key ] );
				}
			}
		}

		if ( ! self::covers( $package ) ) {
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

		$eligible = array();

		foreach ( $rates as $key => $rate ) {
			// An empty carrier list means every carrier in those zones. A
			// non-empty one is the store saying "free by PAC, not by Sedex",
			// which is how a threshold survives an express carrier.
			if ( $methods && ! in_array( (string) $rate->get_method_id(), $methods, true ) ) {
				continue;
			}

			// Already free: a coupon's free shipping, a local pickup. Relabelling
			// one produced "Frete grátis (Free shipping)" on the test store.
			if ( 'free_shipping' === $rate->get_method_id() || (float) $rate->get_cost() <= 0 ) {
				continue;
			}

			$eligible[ $key ] = $rate;
		}

		// The ticked carriers quoted nothing for this parcel. On the test store,
		// Correios PAC alone was ticked, and PAC does not take 12 candles to São
		// Paulo in one volume, so the cart said "Você ganhou frete grátis!" over a
		// list where nothing was free. The threshold has been met and the bar has
		// said so; the promise is kept with whichever carriers did quote.
		if ( ! $eligible && $methods ) {
			foreach ( $rates as $key => $rate ) {
				if ( 'free_shipping' !== $rate->get_method_id() && (float) $rate->get_cost() > 0 ) {
					$eligible[ $key ] = $rate;
				}
			}
		}

		if ( ! $eligible ) {
			return $rates;
		}

		$pick = (string) ( $settings['pick'] ?? self::PICK_CHEAPEST );

		// One quote becomes the free one, and the rest keep their price, so a
		// shopper who wants it sooner can still pay for that. Ties go to the
		// other measure: the cheaper of two equally fast, the faster of two
		// equally cheap.
		if ( self::PICK_ALL !== $pick ) {
			$by_cost = static fn( $a, $b ) => array( (float) $a->get_cost(), ShippingRates::max_days( $a ) ) <=> array( (float) $b->get_cost(), ShippingRates::max_days( $b ) );
			$by_days = static fn( $a, $b ) => array( ShippingRates::max_days( $a ), (float) $a->get_cost() ) <=> array( ShippingRates::max_days( $b ), (float) $b->get_cost() );

			uasort( $eligible, self::PICK_FASTEST === $pick ? $by_days : $by_cost );
			$eligible = array_slice( $eligible, 0, 1, true );
		}

		foreach ( $eligible as $rate ) {
			$cost = (float) $rate->get_cost();

			if ( $cap > 0 && $cost > $cap ) {
				if ( self::OVER_NONE === ( $settings['over_cap'] ?? self::OVER_PARTIAL ) ) {
					continue;
				}

				$reduced = $cost - $cap;

				// The shopper still pays part of the quote, and that part is
				// still taxable. Each tax line is scaled by the share of the
				// cost that remains rather than recomputed, so whatever built
				// them (per-item rates, a shipping tax class, a carrier plugin)
				// is kept. WooCommerce stores rate taxes as rate id => unrounded
				// amount, and this keeps that shape.
				$taxes = array();

				foreach ( (array) $rate->get_taxes() as $rate_id => $tax ) {
					$taxes[ $rate_id ] = (float) $tax * ( $reduced / $cost );
				}

				$rate->set_cost( $reduced );
				$rate->set_taxes( $taxes );
				continue;
			}

			$days = ShippingRates::parts( $rate )['days'];

			$rate->set_cost( 0.0 );
			$rate->set_taxes( array() );

			// One free option reads "Frete grátis (4 a 6 dias úteis)": which
			// carrier it is matters less than when it arrives. When every
			// carrier is free, the carrier is what tells the options apart.
			$rate->set_label(
				self::PICK_ALL === $pick
					? sprintf( '%s – %s', $label, $rate->get_label() )
					: ( '' !== $days ? sprintf( '%s (%s)', $label, $days ) : $label )
			);
		}

		if ( self::PICK_ALL !== $pick ) {
			// First in the list: where the shopper looks, and where WooCommerce
			// takes its default choice from.
			$rates = array_intersect_key( $rates, $eligible ) + $rates;
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
				// WooCommerce's own Free shipping is not a carrier whose quote can
				// be zeroed, and ticking it here did nothing but look meaningful.
				if ( 'free_shipping' === $method->id ) {
					continue;
				}

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
				description: __( 'Nothing ticked means all of them. When none of the ticked carriers quotes for a parcel, all of them count, so a reached threshold always ends in a free option.', 'galaxie-woo' ),
				default: array(),
				options: self::method_options()
			),
			new Field(
				key: 'pick',
				label: __( 'Which quote becomes free', 'galaxie-woo' ),
				type: Field::TYPE_SELECT,
				description: __( 'The cheapest or the fastest carrier becomes "Frete grátis (x dias úteis)" and moves to the top; the others keep their price. "All of them" zeroes every carrier.', 'galaxie-woo' ),
				default: self::PICK_CHEAPEST,
				options: array(
					self::PICK_CHEAPEST => __( 'The cheapest', 'galaxie-woo' ),
					self::PICK_FASTEST  => __( 'The fastest', 'galaxie-woo' ),
					self::PICK_ALL      => __( 'All of them', 'galaxie-woo' ),
				)
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

	/**
	 * What the store's shipping actually looks like, said out loud.
	 *
	 * This exists because of a real afternoon: a zone was created for Sul e
	 * Sudeste with a Free Shipping method in it, and the method was left at
	 * "No requirement" with a minimum of zero — so every order in half the
	 * country shipped free, including a R$ 20 one. Nothing warned anybody. The
	 * WooCommerce screen shows a method called Free shipping and a green tick;
	 * you have to open it to learn what it means.
	 *
	 * So the plugin reads the zones and says what will happen, in the words a
	 * merchant would use. Three traps, all of them silent in WooCommerce:
	 *
	 *   - A Free Shipping method with no requirement: everything ships free.
	 *   - A zone whose only method is Free Shipping: the moment it requires a
	 *     minimum, orders below that have NO shipping option and checkout stops
	 *     dead. WooCommerce lets you build this and says nothing.
	 *   - A zone doing free shipping twice, once here and once in WooCommerce.
	 *     Two rules for one promise is how they start disagreeing.
	 *
	 * @param array<string,mixed> $values
	 */
	public function render_extra_settings( array $values ): void {
		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			return;
		}

		$ours    = array_map( 'strval', (array) ( $values['zones'] ?? array() ) );
		$minimum = (float) ( $values['minimum'] ?? 0 );
		$active  = Plugin::instance()->settings()->is_enabled( $this->id(), false );
		$zones   = \WC_Shipping_Zones::get_zones();

		$zones[] = array(
			'zone_id'   => 0,
			'zone_name' => __( 'Everywhere else', 'galaxie-woo' ),
		);

		echo '<h2>' . esc_html__( 'What the store will actually do', 'galaxie-woo' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
		echo '<th>' . esc_html__( 'Zone', 'galaxie-woo' ) . '</th>';
		echo '<th>' . esc_html__( 'Shipping methods', 'galaxie-woo' ) . '</th>';
		echo '<th>' . esc_html__( 'What happens', 'galaxie-woo' ) . '</th>';
		echo '</tr></thead><tbody>';

		$conflicts = array();

		foreach ( $zones as $zone ) {
			$instance = \WC_Shipping_Zones::get_zone( (int) $zone['zone_id'] );

			if ( ! $instance ) {
				continue;
			}

			$paid = array();
			$free = null;

			foreach ( $instance->get_shipping_methods( true ) as $method ) {
				if ( 'free_shipping' === $method->id ) {
					$free = $method;
					continue;
				}

				$paid[] = $method->get_title();
			}

			$notes    = array();
			$selected = in_array( (string) $zone['zone_id'], $ours, true );

			if ( $free ) {
				$requires = (string) $free->get_option( 'requires' );
				$amount   = (float) $free->get_option( 'min_amount' );

				if ( '' === $requires ) {
					$notes[] = array( 'bad', __( 'WooCommerce\'s own Free shipping here has NO requirement — every order in this zone ships free, however small.', 'galaxie-woo' ) );
				} elseif ( in_array( $requires, array( 'min_amount', 'either', 'both' ), true ) && $amount > 0 ) {
					/* translators: %s: formatted amount. */
					$notes[] = array( 'ok', sprintf( __( 'WooCommerce ships free above %s here.', 'galaxie-woo' ), wp_strip_all_tags( wc_price( $amount ) ) ) );

					if ( ! $paid ) {
						$notes[] = array( 'bad', __( 'And it is the only method in this zone, so an order below that amount has NO shipping option at all and checkout stops. Add a carrier or a flat rate here.', 'galaxie-woo' ) );
					}
				}

				if ( $active && $selected && $minimum > 0 ) {
					$conflicts[] = (int) $zone['zone_id'];
					$notes[]     = array( 'bad', __( 'This zone is also ticked above, so free shipping is being decided in two places. Turn one of them off.', 'galaxie-woo' ) );
				}
			}

			if ( $active && $selected && $minimum > 0 && ! $free ) {
				if ( $paid ) {
					/* translators: %s: formatted amount. */
					$notes[] = array( 'ok', sprintf( __( 'This module ships free above %s here, by zeroing what the carriers quote.', 'galaxie-woo' ), wp_strip_all_tags( wc_price( $minimum ) ) ) );
				} else {
					$notes[] = array( 'bad', __( 'Ticked above, but this zone quotes nothing — there is no carrier price to zero. Add a carrier or a flat rate here.', 'galaxie-woo' ) );
				}
			}

			if ( ! $notes ) {
				$notes[] = array( 'plain', $paid ? __( 'Paid shipping only.', 'galaxie-woo' ) : __( 'No shipping methods — nobody in this zone can check out.', 'galaxie-woo' ) );
			}

			echo '<tr>';
			echo '<td><strong>' . esc_html( (string) $zone['zone_name'] ) . '</strong></td>';
			echo '<td>' . esc_html( $paid ? implode( ', ', $paid ) : __( '—', 'galaxie-woo' ) ) . ( $free ? '<br><em>' . esc_html__( 'Free shipping (WooCommerce)', 'galaxie-woo' ) . '</em>' : '' ) . '</td>';
			echo '<td>';

			foreach ( $notes as $note ) {
				list( $kind, $text ) = $note;
				$colour              = 'bad' === $kind ? '#b32d2e' : ( 'ok' === $kind ? '#1e7e34' : 'inherit' );

				printf( '<p style="margin:0 0 6px;color:%s">%s</p>', esc_attr( $colour ), esc_html( $text ) );
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';

		if ( $conflicts ) {
			// Namespaced under `fields[...]` because that is the only part of
			// the form the settings page hands to sanitize_settings — a
			// top-level name would post fine and never be read, which is the
			// same silent nothing as a control with no selector.
			echo '<p><label><input type="checkbox" name="fields[galaxie_disable_wc_free_shipping]" value="1" /> ';
			echo esc_html__( 'On save, switch off WooCommerce\'s own Free shipping in the zones flagged above, leaving this module as the only rule.', 'galaxie-woo' );
			echo '</label></p>';
		}

		echo '<p class="description">' . esc_html__( 'Carriers are not something this plugin can create — a zone with no price to quote needs a real shipping method, from Melhor Envio or a flat rate.', 'galaxie-woo' ) . '</p>';
	}


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

		// The one thing the panel below offers to change in WooCommerce, and
		// only when ticked. Switching a method off is reversible from the
		// WooCommerce screen in one click, which is why it is offered at all —
		// deleting it would not be.
		if ( ! empty( $submitted['galaxie_disable_wc_free_shipping'] ) ) {
			self::disable_wc_free_shipping( array_map( 'intval', (array) $sanitized['zones'] ) );
		}

		return $sanitized;
	}

	/**
	 * Switch off WooCommerce's own Free shipping in the given zones.
	 *
	 * @param array<int,int> $zones
	 */
	private static function disable_wc_free_shipping( array $zones ): void {
		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			return;
		}

		foreach ( $zones as $zone_id ) {
			$zone = \WC_Shipping_Zones::get_zone( $zone_id );

			if ( ! $zone ) {
				continue;
			}

			foreach ( $zone->get_shipping_methods( true ) as $method ) {
				if ( 'free_shipping' === $method->id ) {
					$zone->get_data_store()->update_method_status( $zone_id, (int) $method->instance_id, false );
				}
			}
		}
	}
}
