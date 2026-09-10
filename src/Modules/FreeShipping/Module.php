<?php
/**
 * Free Shipping module — one threshold, honoured at checkout and on the bar.
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
 * "Acima de R$ 200, o frete é por nossa conta."
 *
 * WooCommerce ships a Free Shipping method already, so this exists for a
 * specific reason: read off production, this store's rates come from Melhor
 * Envio — six carrier methods in the Brasil zone — and a Free Shipping method
 * added beside them becomes a SEVENTH option the shopper picks INSTEAD of a
 * carrier. That is not free shipping, that is a shipping method with no
 * carrier attached, and someone still has to post the parcel.
 *
 * So the default is to zero what the carriers quoted: the shopper still chooses
 * PAC or Sedex, the label still says which, and the price says R$ 0,00. The
 * separate-option behaviour is available for stores that want it, because some
 * do — but it is not what a store quoting real carriers usually means.
 *
 * The cart's progress bar reads the same number. That is the point of putting
 * the threshold here rather than in the widget: a bar promising R$ 200 while
 * checkout charges above R$ 200 is worse than no bar at all.
 */
final class Module implements ModuleContract, ProvidesSettings {

	public const MODE_ZERO     = 'zero';
	public const MODE_SEPARATE = 'separate';

	public function id(): string {
		return 'free-shipping';
	}

	public function title(): string {
		return __( 'Free Shipping', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Free shipping above an order value, applied to the carriers you already quote — and read by the cart\'s progress bar so both say the same number.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		// Off until a threshold is set: enabled with a minimum of zero would
		// mean free shipping for every order, which is a costly default.
		return false;
	}

	public function boot(): void {
		add_filter( 'woocommerce_package_rates', array( $this, 'apply' ), 100, 2 );
	}

	/**
	 * The configured minimum, or 0.0 when the module is off or unset.
	 *
	 * Public because the cart's progress bar asks for it — one number, one
	 * place, whatever is reading it.
	 */
	public static function threshold(): float {
		$plugin = Plugin::instance();

		if ( ! $plugin->settings()->is_enabled( 'free-shipping', false ) ) {
			return 0.0;
		}

		return (float) ( $plugin->settings()->module_settings( 'free-shipping' )['minimum'] ?? 0 );
	}

	/**
	 * What the cart counts towards the threshold.
	 *
	 * Contents only — never the shipping already in the total, which would let
	 * an expensive carrier push an order over the line and then become free
	 * because it was expensive.
	 */
	public static function cart_total(): float {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0.0;
		}

		$settings = Plugin::instance()->settings()->module_settings( 'free-shipping' );
		$subtotal = (float) WC()->cart->get_subtotal();

		if ( empty( $settings['include_discounts'] ) ) {
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
		$threshold = self::threshold();

		if ( $threshold <= 0 || self::cart_total() < $threshold ) {
			return $rates;
		}

		$settings = Plugin::instance()->settings()->module_settings( $this->id() );
		$label    = (string) ( $settings['label'] ?? __( 'Frete grátis', 'galaxie-woo' ) );

		if ( self::MODE_SEPARATE === ( $settings['mode'] ?? self::MODE_ZERO ) ) {
			$rate = new \WC_Shipping_Rate( 'galaxie_free_shipping', $label, 0.0, array(), 'galaxie_free_shipping' );

			return array( 'galaxie_free_shipping' => $rate ) + $rates;
		}

		foreach ( $rates as $rate ) {
			$rate->set_cost( 0.0 );
			$rate->set_taxes( array() );

			// The carrier stays in the label, because the shopper is still
			// choosing a carrier: "Frete grátis (Correios PAC)" tells them how
			// long it will take, which a bare "Frete grátis" does not.
			$rate->set_label( sprintf( '%s (%s)', $label, $rate->get_label() ) );
		}

		return $rates;
	}

	public function settings_tab_label(): string {
		return __( 'Free Shipping', 'galaxie-woo' );
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
				placeholder: '200'
			),
			new Field(
				key: 'include_discounts',
				label: __( 'Count the cart after discounts', 'galaxie-woo' ),
				type: Field::TYPE_TOGGLE,
				description: __( 'On, a coupon can push an order back below the threshold. Off, the threshold is measured before any discount.', 'galaxie-woo' ),
				default: true
			),
			new Field(
				key: 'mode',
				label: __( 'How to apply it', 'galaxie-woo' ),
				type: Field::TYPE_SELECT,
				description: __( 'Zeroing keeps the carrier the shopper chose — and its delivery estimate. A separate option replaces that choice with one that names no carrier.', 'galaxie-woo' ),
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

		$sanitized['minimum'] = max( 0.0, (float) $sanitized['minimum'] );

		if ( '' === trim( (string) $sanitized['label'] ) ) {
			$sanitized['label'] = __( 'Frete grátis', 'galaxie-woo' );
		}

		return $sanitized;
	}
}
