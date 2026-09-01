<?php
/**
 * Google Login module — "Sign in with Google" credentials + settings.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\GoogleLogin;

use Galaxie\Woo\Core\Field;
use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\ProvidesSettings;

defined( 'ABSPATH' ) || exit;

/**
 * OAuth sign-in via Google — a distinct product from {@see \Galaxie\Woo\Modules\AddressAutocomplete\Module}
 * (Maps/Places autocomplete): different Google Cloud credentials (OAuth client
 * ID/secret vs. a Maps API key), different settings tab, independent toggle.
 *
 * The actual OAuth flow (authorization-code exchange, People API lookups) is
 * ported alongside the Checkout module — this module owns only the module
 * registration + credential settings, so the settings layer can be delivered
 * (and the redirect URI known) before that logic lands.
 */
final class Module implements ModuleContract, ProvidesSettings {

	public function id(): string {
		return 'google-login';
	}

	public function title(): string {
		return __( 'Google Login', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Let customers sign in with their Google account at checkout and in My Account.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		// Needs credentials to function — don't show a broken "Sign in with
		// Google" button on stores that haven't configured this yet.
		return false;
	}

	public function boot(): void {
		// TODO: port the OAuth flow from eir-my-account-ux
		// includes/class-eir-checkout-auth.php (eir_google_start,
		// maybe_handle_google_callback, eir_google_callback) once the Checkout
		// module lands. It will read client_id/client_secret from this module's
		// settings via Settings::module_settings('google-login') instead of the
		// v1 hardcoded class constants.
	}

	public function settings_tab_label(): string {
		return __( 'Google Login', 'galaxie-woo' );
	}

	/** @return Field[] */
	public function settings_fields(): array {
		return array(
			new Field(
				key: 'client_id',
				label: __( 'Client ID', 'galaxie-woo' ),
				type: Field::TYPE_TEXT,
				description: __( 'From Google Cloud Console → APIs & Services → Credentials → OAuth 2.0 Client ID.', 'galaxie-woo' ),
				placeholder: '123456789-abc.apps.googleusercontent.com'
			),
			new Field(
				key: 'client_secret',
				label: __( 'Client Secret', 'galaxie-woo' ),
				type: Field::TYPE_PASSWORD
			),
			new Field(
				key: 'show_button',
				label: __( 'Show button', 'galaxie-woo' ),
				type: Field::TYPE_TOGGLE,
				description: __( 'Show the "Sign in with Google" button on the login/registration screen.', 'galaxie-woo' ),
				default: true
			),
		);
	}

	public function render_extra_settings( array $values ): void {
		$redirect_uri = home_url( 'checkout/google/oauth2callback' );
		?>
		<h3><?php esc_html_e( 'Redirect URI', 'galaxie-woo' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Add this exact URL under "Authorized redirect URIs" for the OAuth client above.', 'galaxie-woo' ); ?>
		</p>
		<input type="text" readonly class="regular-text" style="max-width:420px" onclick="this.select()" value="<?php echo esc_attr( $redirect_uri ); ?>" />
		<?php
	}

	public function sanitize_settings( array $submitted, array $current ): array {
		$sanitized = Field::sanitize_all( $this->settings_fields(), $submitted );

		// A blank secret field means "leave unchanged" (we never re-display the
		// saved secret in the input), not "clear it".
		if ( '' === $sanitized['client_secret'] ) {
			$sanitized['client_secret'] = $current['client_secret'] ?? '';
		}

		return $sanitized;
	}
}
