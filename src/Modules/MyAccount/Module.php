<?php
/**
 * My Account experience module — the "Galaxie My Account" Elementor widget.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\MyAccount;

use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\ProvidesElementorWidgets;

defined( 'ABSPATH' ) || exit;

final class Module implements ModuleContract, ProvidesElementorWidgets {

	public function id(): string {
		return 'my-account';
	}

	public function title(): string {
		return __( 'My Account', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Redesigned My Account (tabs, addresses, profile, interests, communication) as an Elementor widget.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return true;
	}

	public function boot(): void {
		// TODO: port from eir-my-account-ux:
		//  - templates/woocommerce/myaccount/form-edit-account.php (tabs)
		//  - templates/woocommerce/myaccount/my-address.php (address cards)
		//  - assets/css/my-account.css: replace var(--et_*) with our own design
		//    tokens (they already carry hex fallbacks — just drop the theme vars).
		//  - assets/js + css my-account-details.* (interests picker, comms, delete UI)
		//  - the WooCommerce menu trimming (menu logic is WC-level, keep; drop the
		//    xstore-compare / xstore-wishlist specifics — wishlist becomes our module).
	}

	/** @return string[] */
	public function elementor_widgets(): array {
		// TODO: return [ Widget\MyAccountWidget::class ];
		return array();
	}
}
