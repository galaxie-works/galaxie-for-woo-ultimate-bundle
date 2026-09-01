<?php
/**
 * Address Autocomplete module — Google Places autocomplete for the address field.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\AddressAutocomplete;

use Galaxie\Woo\Core\Field;
use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\ProvidesSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Address auto-fill (Google Places) at checkout and in the cart's shipping
 * calculator — a distinct product from {@see \Galaxie\Woo\Modules\GoogleLogin\Module}
 * (sign-in): a Maps API key, not an OAuth client, and used regardless of how
 * the customer logged in.
 */
final class Module implements ModuleContract, ProvidesSettings {

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
		// TODO: enqueue the Maps Places script + wire the autocomplete handlers
		// once the Checkout and Cart modules land (ported from
		// eir-my-account-ux assets/js/checkout-auth.js initPlacesAutocomplete()
		// and assets/js/cart-shipping.js). Reads maps_api_key/country from this
		// module's settings.
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
