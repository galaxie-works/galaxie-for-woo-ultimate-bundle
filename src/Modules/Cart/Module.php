<?php
/**
 * Cart experience module — the "Galaxie Cart" Elementor widget.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Cart;

use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\ProvidesElementorWidgets;

defined( 'ABSPATH' ) || exit;

final class Module implements ModuleContract, ProvidesElementorWidgets {

	public function id(): string {
		return 'cart';
	}

	public function title(): string {
		return __( 'Cart Experience', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Cart with inline shipping calculator/relocation as an Elementor widget.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return true;
	}

	public function boot(): void {
		// TODO: port from eir-my-account-ux assets/js/cart-shipping.js + css
		// cart-shipping.css (shipping "change address" relocation, Places box).
		// Shares the free-shipping threshold setting with the Checkout module.
	}

	/** @return string[] */
	public function elementor_widgets(): array {
		// TODO: return [ Widget\CartWidget::class ];
		return array();
	}
}
