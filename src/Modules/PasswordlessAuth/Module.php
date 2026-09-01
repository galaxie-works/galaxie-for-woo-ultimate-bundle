<?php
/**
 * Passwordless authentication (email OTP) module.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\PasswordlessAuth;

use Galaxie\Woo\Core\Module as ModuleContract;

defined( 'ABSPATH' ) || exit;

final class Module implements ModuleContract {

	public function id(): string {
		return 'passwordless-auth';
	}

	public function title(): string {
		return __( 'Passwordless Auth', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Email one-time-code login and registration (no passwords).', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return true;
	}

	public function boot(): void {
		// TODO: port the OTP logic from eir-my-account-ux
		// includes/class-eir-checkout-auth.php (eir_auth_send_otp / verify /
		// register, create_account, rate-limiting, OTP transients).
	}
}
