<?php
/**
 * Toast notices module — WooCommerce notices rendered as modern toasts.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\ToastNotices;

use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\ProvidesBootData;
use Galaxie\Woo\Support\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * A global (non-widget) module: it loads the bundle on the front-end and flags
 * `toastNotices` so the JS boots the WC-notice interceptor. First module ported
 * from eir-my-account-ux (assets/js/toast-notices.js), and the one that proves
 * the module + boot-data + asset pipeline end to end.
 */
final class Module implements ModuleContract, ProvidesBootData {

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
		add_action( 'wp_enqueue_scripts', array( Assets::class, 'enqueue' ) );
	}

	public function boot_data(): array {
		return array( 'toastNotices' => true );
	}
}
