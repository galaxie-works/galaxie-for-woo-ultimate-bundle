<?php
/**
 * Toast notices module — WooCommerce notices rendered as modern toasts.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\ToastNotices;

use Galaxie\Woo\Core\Module as ModuleContract;

defined( 'ABSPATH' ) || exit;

final class Module implements ModuleContract {

	public function id(): string {
		return 'toast-notices';
	}

	public function title(): string {
		return __( 'Toast Notices', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Intercepts WooCommerce notices and re-renders them as toasts.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return true;
	}

	public function boot(): void {
		// TODO: port from eir-my-account-ux assets/js/toast-notices.js + css
		// toast-notices.css. Theme-agnostic already; good first port to validate
		// the module + asset-loading pattern.
	}
}
