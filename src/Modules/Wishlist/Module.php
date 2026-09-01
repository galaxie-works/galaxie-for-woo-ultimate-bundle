<?php
/**
 * Wishlist module — our own implementation (replaces XStore's add-on).
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Wishlist;

use Galaxie\Woo\Core\Module as ModuleContract;

defined( 'ABSPATH' ) || exit;

final class Module implements ModuleContract {

	public function id(): string {
		return 'wishlist';
	}

	public function title(): string {
		return __( 'Wishlist', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Own wishlist feature (add/remove, My Account view) — no theme dependency.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return true;
	}

	public function boot(): void {
		// TODO: BUILD (not a port). The current wishlist is XStore's et-core
		// add-on, only re-skinned by eir-my-account-ux. Reimplement here:
		// storage (user-meta / table), add/remove endpoints, a My Account view,
		// and reuse the look we already built for the XStore-skinned version.
	}
}
