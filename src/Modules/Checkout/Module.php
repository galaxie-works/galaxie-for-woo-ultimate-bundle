<?php
/**
 * Checkout experience module — the "Galaxie Checkout" Elementor widget (stepper).
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Checkout;

use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\ProvidesElementorWidgets;

defined( 'ABSPATH' ) || exit;

final class Module implements ModuleContract, ProvidesElementorWidgets {

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
		// TODO: port the stepper from eir-my-account-ux:
		//  - template checkout-auth-gate.php  -> widget render()
		//  - assets/js/checkout-auth.js       -> drop inject()/.etheme-* (widget self-mounts)
		//  - assets/css/checkout-auth.css     -> drop the theme-hiding rules
		//  - shipping relocation + free-shipping bar: replace the XStore
		//    Sales Booster theme_mod (booster_progress_price_et-desktop) with a
		//    module setting (there is NO native WC free-shipping method on the
		//    site — confirmed 2026-09-01 — so it must be our own threshold).
	}

	/** @return string[] */
	public function elementor_widgets(): array {
		// TODO: return [ Widget\CheckoutWidget::class ];
		return array();
	}
}
