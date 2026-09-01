<?php
/**
 * Account deletion module — soft-delete request + scheduled purge.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\AccountDeletion;

use Galaxie\Woo\Core\Module as ModuleContract;

defined( 'ABSPATH' ) || exit;

final class Module implements ModuleContract {

	public function id(): string {
		return 'account-deletion';
	}

	public function title(): string {
		return __( 'Account Deletion', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Self-service account deletion (soft-delete, 6-month grace, cron purge).', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return true;
	}

	public function boot(): void {
		// TODO: port from eir-my-account-ux includes/class-eir-checkout-auth.php:
		// eir_request_account_deletion (soft-delete flag), maybe_cancel_pending_deletion
		// (cancel on any authenticated pageview), purge_expired_deletions (daily cron,
		// 6-month retention). Fix the string-vs-date meta compare while porting.
	}
}
