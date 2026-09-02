<?php
/**
 * Account deletion module — soft-delete request + scheduled purge.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\AccountDeletion;

use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\ProvidesBootData;

defined( 'ABSPATH' ) || exit;

/**
 * Ported from eir-my-account-ux (class-eir-checkout-auth.php's
 * eir_request_account_deletion / maybe_cancel_pending_deletion /
 * purge_expired_deletions). Self-service "delete my account": flags the user,
 * logs them out, gives them 6 months to change their mind (any authenticated
 * pageview cancels the flag — necessary because passwordless logins never
 * fire WordPress's own `wp_login`, which is what a "reactivate" flow would
 * normally hook), then hard-deletes on the daily cron.
 */
final class Module implements ModuleContract, ProvidesBootData {

	public const NONCE_ACTION = 'galaxie_woo_account_deletion';
	private const META_KEY    = 'galaxie_account_deletion_requested_at';
	private const CRON_HOOK   = 'galaxie_woo_daily_cleanup';
	private const RETENTION   = 6 * MONTH_IN_SECONDS;

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
		add_action( 'wp_ajax_galaxie_request_account_deletion', array( $this, 'ajax_request_deletion' ) );
		add_action( 'wp', array( $this, 'maybe_cancel_pending_deletion' ) );
		add_action( self::CRON_HOOK, array( $this, 'purge_expired_deletions' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	public function boot_data(): array {
		return array(
			'accountDeletion' => array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			),
		);
	}

	public function ajax_request_deletion(): void {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh and try again.', 'galaxie-woo' ) ), 403 );
		}
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Please sign in first.', 'galaxie-woo' ) ), 401 );
		}

		$user_id = get_current_user_id();
		update_user_meta( $user_id, self::META_KEY, current_time( 'mysql', true ) );
		wp_logout();

		wp_send_json_success( array( 'redirect' => home_url( '/' ) ) );
	}

	/** Any authenticated pageview cancels a pending deletion — passwordless logins never fire `wp_login`. */
	public function maybe_cancel_pending_deletion(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( get_user_meta( $user_id, self::META_KEY, true ) ) {
			delete_user_meta( $user_id, self::META_KEY );
		}
	}

	/** Daily cron: hard-deletes any account still flagged after the retention window. */
	public function purge_expired_deletions(): void {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::RETENTION );

		$user_ids = get_users(
			array(
				'meta_key'     => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'   => $cutoff, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_compare' => '<=',
				'fields'       => 'ID',
			)
		);

		if ( empty( $user_ids ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $user_ids as $user_id ) {
			wp_delete_user( (int) $user_id );
		}
	}
}
