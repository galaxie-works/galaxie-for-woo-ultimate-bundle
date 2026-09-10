<?php
/**
 * Address Autocomplete module — Google Places autocomplete for the address field.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\AddressAutocomplete;

use Galaxie\Woo\Core\Field;
use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\ProvidesBootData;
use Galaxie\Woo\Core\ProvidesSettings;
use Galaxie\Woo\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Address auto-fill (Google Places) at checkout and in the cart's shipping
 * calculator — a distinct product from {@see \Galaxie\Woo\Modules\GoogleLogin\Module}
 * (sign-in): a Maps API key, not an OAuth client, and used regardless of how
 * the customer logged in.
 */
final class Module implements ModuleContract, ProvidesSettings, ProvidesBootData {

	public function id(): string {
		return 'address-autocomplete';
	}

	public function title(): string {
		return __( 'Address Autocomplete', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Google Places address search at checkout and in the cart shipping calculator.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		// Needs a Maps API key to function.
		return false;
	}

	public function boot(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 5 );
	}

	/**
	 * Google's script, and only where an address is actually being typed.
	 *
	 * Loading Maps on every page of a shop is a billable request per view for a
	 * feature two pages use. `loading=async` is Google's own recommendation and
	 * the reason the frontend polls for `google.maps` rather than assuming a
	 * declared dependency has finished.
	 */
	public function enqueue(): void {
		$settings = Plugin::instance()->settings()->module_settings( $this->id() );
		$key      = (string) ( $settings['maps_api_key'] ?? '' );

		if ( '' === $key ) {
			return;
		}

		if ( ! function_exists( 'is_cart' ) || ! ( is_cart() || is_checkout() ) ) {
			return;
		}

		wp_enqueue_script(
			'google-maps-places',
			add_query_arg(
				array(
					'key'       => rawurlencode( $key ),
					'libraries' => 'places',
					'loading'   => 'async',
					'language'  => substr( get_locale(), 0, 2 ),
				),
				'https://maps.googleapis.com/maps/api/js'
			),
			array(),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google versions its own endpoint.
			true
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function boot_data(): array {
		$settings = Plugin::instance()->settings()->module_settings( $this->id() );

		if ( '' === (string) ( $settings['maps_api_key'] ?? '' ) ) {
			return array();
		}

		return array(
			'addressAutocomplete' => array(
				// The key itself never travels: the script tag carries it, and
				// repeating it in a JSON blob would only widen where it leaks
				// from.
				'country'     => (string) ( $settings['country'] ?? 'BR' ),
				'placeholder' => __( 'Digite seu endereço', 'galaxie-woo' ),
			),
		);
	}

	public function settings_tab_label(): string {
		return __( 'Address Autocomplete', 'galaxie-woo' );
	}

	/** @return Field[] */
	public function settings_fields(): array {
		return array(
			new Field(
				key: 'maps_api_key',
				label: __( 'Maps API Key', 'galaxie-woo' ),
				type: Field::TYPE_PASSWORD,
				description: __( 'From Google Cloud Console. Restrict this key to the Places API and this site\'s domain(s).', 'galaxie-woo' )
			),
			new Field(
				key: 'country',
				label: __( 'Country', 'galaxie-woo' ),
				type: Field::TYPE_TEXT,
				description: __( 'Two-letter ISO country code the address search is restricted to.', 'galaxie-woo' ),
				default: 'BR',
				placeholder: 'BR'
			),
		);
	}

	public function render_extra_settings( array $values ): void {}

	public function sanitize_settings( array $submitted, array $current ): array {
		$sanitized = Field::sanitize_all( $this->settings_fields(), $submitted );

		if ( '' === $sanitized['maps_api_key'] ) {
			$sanitized['maps_api_key'] = $current['maps_api_key'] ?? '';
		}

		$sanitized['country'] = strtoupper( substr( (string) $sanitized['country'], 0, 2 ) );
		if ( '' === $sanitized['country'] ) {
			$sanitized['country'] = 'BR';
		}

		return $sanitized;
	}
}
