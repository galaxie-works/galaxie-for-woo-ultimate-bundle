<?php
/**
 * Checkout experience module — the "Galaxie Checkout" Elementor widget (stepper).
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Checkout;

use Galaxie\Woo\Core\Field;
use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\ProvidesBootData;
use Galaxie\Woo\Core\ProvidesElementorWidgets;
use Galaxie\Woo\Core\ProvidesSettings;
use Galaxie\Woo\Modules\Checkout\Widget\CheckoutWidget;
use Galaxie\Woo\Support\CustomerProfile;

defined( 'ABSPATH' ) || exit;

final class Module implements ModuleContract, ProvidesElementorWidgets, ProvidesSettings, ProvidesBootData {

	public const NONCE_ACTION = 'galaxie_woo_checkout';

	public function id(): string {
		return 'checkout';
	}

	public function title(): string {
		return __( 'Checkout Experience', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Stepped, passwordless checkout as an Elementor widget (auth, address, shipping, payment).', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return true;
	}

	public function boot(): void {
		add_filter( 'woocommerce_package_rates', array( $this, 'filter_shipping_rates' ), 100, 2 );

		add_action( 'wp_ajax_galaxie_save_profile', array( $this, 'ajax_save_profile' ) );
		add_action( 'wp_ajax_galaxie_save_address', array( $this, 'ajax_save_address' ) );
	}

	public function boot_data(): array {
		return array(
			'checkout' => array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			),
		);
	}

	/** @return string[] */
	public function elementor_widgets(): array {
		return array( CheckoutWidget::class );
	}

	public function settings_tab_label(): string {
		return __( 'Checkout', 'galaxie-woo' );
	}

	/** @return Field[] */
	public function settings_fields(): array {
		return array(
			new Field(
				key: 'free_shipping_threshold',
				label: __( 'Free shipping threshold', 'galaxie-woo' ),
				type: Field::TYPE_NUMBER,
				description: __( 'Cart subtotal (in your store currency) at or above which a synthetic "Free Shipping" rate is offered — only used when no real free-shipping method already applies. Leave at 0 to disable.', 'galaxie-woo' ),
				default: 350
			),
			new Field(
				key: 'free_shipping_ignore_discount',
				label: __( 'Ignore discounts', 'galaxie-woo' ),
				type: Field::TYPE_TOGGLE,
				description: __( 'Count the subtotal toward the threshold before coupon discounts are applied.', 'galaxie-woo' ),
				default: true
			),
			new Field(
				key: 'free_shipping_label',
				label: __( 'Free shipping label', 'galaxie-woo' ),
				type: Field::TYPE_TEXT,
				default: __( 'Free Shipping (order minimum)', 'galaxie-woo' )
			),
		);
	}

	public function render_extra_settings( array $values ): void {}

	public function sanitize_settings( array $submitted, array $current ): array {
		return Field::sanitize_all( $this->settings_fields(), $submitted );
	}

	/**
	 * Mirrors v1's free-shipping-bar behavior (previously read from the XStore
	 * Sales Booster theme_mod — there is no native WooCommerce Free Shipping
	 * method configured on this store, confirmed 2026-09-01, so the threshold
	 * is a plugin setting instead): if a real free/zero-cost rate already
	 * exists, keep only that; else, once the cart clears the threshold, offer a
	 * synthetic free-shipping rate; else sort the real rates cheapest-first.
	 *
	 * @param \WC_Shipping_Rate[] $rates
	 * @return \WC_Shipping_Rate[]
	 */
	public function filter_shipping_rates( array $rates, array $package ): array {
		foreach ( $rates as $rate ) {
			if ( 'free_shipping' === $rate->method_id || 0.0 === (float) $rate->cost ) {
				return array( $rate->get_id() => $rate );
			}
		}

		if ( $this->cart_qualifies_for_free_shipping_bar() ) {
			$settings = $this->settings();
			$label    = $settings['free_shipping_label'] ?? __( 'Free Shipping (order minimum)', 'galaxie-woo' );
			$free     = new \WC_Shipping_Rate( 'galaxie_free_shipping_bar', $label, 0, array(), 'galaxie_free_shipping_bar' );
			return array( $free->get_id() => $free );
		}

		uasort( $rates, static fn( $a, $b ) => $a->cost <=> $b->cost );
		return $rates;
	}

	private function cart_qualifies_for_free_shipping_bar(): bool {
		if ( ! function_exists( 'WC' ) || null === WC()->cart ) {
			return false;
		}

		$settings  = $this->settings();
		$threshold = (float) ( $settings['free_shipping_threshold'] ?? 0 );
		if ( $threshold <= 0 ) {
			return false;
		}

		$amount = wc_tax_enabled() ? WC()->cart->get_displayed_subtotal() : WC()->cart->get_subtotal();
		if ( ! empty( $settings['free_shipping_ignore_discount'] ) ) {
			$amount += WC()->cart->get_discount_total();
		}

		return $amount >= $threshold;
	}

	/** @return array<string,mixed> */
	private function settings(): array {
		return \Galaxie\Woo\Core\Plugin::instance()->settings()->module_settings( $this->id() );
	}

	public function ajax_save_profile(): void {
		$this->check_nonce_and_login();

		$user_id = get_current_user_id();

		$first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$last_name  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
		$phone      = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$birthdate  = isset( $_POST['birthdate'] ) ? sanitize_text_field( wp_unslash( $_POST['birthdate'] ) ) : '';
		$cpf        = isset( $_POST['cpf'] ) ? sanitize_text_field( wp_unslash( $_POST['cpf'] ) ) : '';

		if ( '' === $first_name || '' === $last_name ) {
			wp_send_json_error( array( 'message' => __( 'Please enter your first and last name.', 'galaxie-woo' ) ) );
		}
		if ( '' !== $cpf && ! \Galaxie\Woo\Support\Cpf::is_valid( $cpf ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid CPF.', 'galaxie-woo' ) ) );
		}
		if ( '' !== $birthdate && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $birthdate ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid date of birth.', 'galaxie-woo' ) ) );
		}

		wp_update_user(
			array(
				'ID'         => $user_id,
				'first_name' => $first_name,
				'last_name'  => $last_name,
			)
		);
		update_user_meta( $user_id, 'billing_first_name', $first_name );
		update_user_meta( $user_id, 'billing_last_name', $last_name );
		if ( '' !== $phone ) {
			update_user_meta( $user_id, 'billing_phone', $phone );
		}
		if ( '' !== $birthdate ) {
			update_user_meta( $user_id, \Galaxie\Woo\Support\ProfileFields::BIRTHDATE, $birthdate );
		}
		if ( '' !== $cpf ) {
			update_user_meta( $user_id, \Galaxie\Woo\Support\ProfileFields::CPF, \Galaxie\Woo\Support\Cpf::format( $cpf ) );
		}

		wp_send_json_success( CustomerProfile::status( $user_id ) );
	}

	public function ajax_save_address(): void {
		$this->check_nonce_and_login();

		$user_id = get_current_user_id();

		$address = array(
			'address_1' => isset( $_POST['address_1'] ) ? sanitize_text_field( wp_unslash( $_POST['address_1'] ) ) : '',
			'address_2' => isset( $_POST['address_2'] ) ? sanitize_text_field( wp_unslash( $_POST['address_2'] ) ) : '',
			'city'      => isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '',
			'state'     => isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '',
			'postcode'  => isset( $_POST['postcode'] ) ? sanitize_text_field( wp_unslash( $_POST['postcode'] ) ) : '',
			'country'   => isset( $_POST['country'] ) && '' !== $_POST['country'] ? sanitize_text_field( wp_unslash( $_POST['country'] ) ) : 'BR',
		);

		if ( '' === $address['address_1'] || '' === $address['city'] || '' === $address['state'] || '' === $address['postcode'] ) {
			wp_send_json_error( array( 'message' => __( 'Please fill in the required address fields.', 'galaxie-woo' ) ) );
		}

		CustomerProfile::save_address( $user_id, $address );

		wp_send_json_success( CustomerProfile::saved_address( $user_id ) );
	}

	private function check_nonce_and_login(): void {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh and try again.', 'galaxie-woo' ) ), 403 );
		}
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Please sign in first.', 'galaxie-woo' ) ), 401 );
		}
	}
}
