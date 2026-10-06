<?php
/**
 * FunnelKit Stripe in Portuguese module.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\FunnelKitPtBr;

use Galaxie\Woo\Core\Module as ModuleContract;

defined( 'ABSPATH' ) || exit;

/**
 * Puts the customer-facing text of "FunnelKit Payment Gateway for Stripe
 * WooCommerce" in Brazilian Portuguese (see {@see Translations}). Filters
 * only: with FunnelKit absent, or a non-Portuguese locale, nothing it hooks is
 * ever called with anything to change. Turned off, FunnelKit speaks English
 * again (or whatever translation is installed for its text domain).
 */
final class Module implements ModuleContract {

	public function id(): string {
		return 'funnelkit-pt-br';
	}

	public function title(): string {
		return __( 'FunnelKit Stripe in Portuguese', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Brazilian Portuguese for what the FunnelKit Stripe gateway shows customers: payment method names, card fields and errors, checkout notices. Titles customised in the FunnelKit settings are kept.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return true;
	}

	public function boot(): void {
		Translations::hooks();
	}
}
