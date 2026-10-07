<?php
/**
 * Account deletion module — soft-delete request + scheduled purge.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\AccountDeletion;

use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\ProvidesBootData;
use Galaxie\Woo\Integrations\FluentCRM as FluentCRMApi;
use Galaxie\Woo\Support\ProfileFields;

defined( 'ABSPATH' ) || exit;

/**
 * Ported from eir-my-account-ux (class-eir-checkout-auth.php's
 * eir_request_account_deletion / maybe_cancel_pending_deletion /
 * purge_expired_deletions). Self-service "delete my account":
 *
 * 1. The request flags the user, ends every session the account has (each
 *    device, not only this browser), and takes the marketing consent away at
 *    once — in the account and in FluentCRM.
 * 2. For 6 months the customer can change their mind, but only by saying so:
 *    signing in shows a notice on My Account with a "Cancelar exclusão" link
 *    (its own nonce-checked action). Merely being signed in somewhere — a
 *    session the request could not reach, a passwordless login — cancels
 *    nothing.
 * 3. The daily cron then removes the account: its orders are anonymized with
 *    WooCommerce's own privacy eraser (names, addresses, e-mail, phone, the
 *    Brazilian CPF/CNPJ fields and the order notes), its saved cards are
 *    erased, its FluentCRM contact is unsubscribed and deleted, and the user is
 *    deleted. The order totals and items stay for the store's books.
 */
final class Module implements ModuleContract, ProvidesBootData {

	public const NONCE_ACTION  = 'galaxie_woo_account_deletion';
	public const CANCEL_ACTION = 'galaxie_cancel_account_deletion';
	private const META_KEY     = 'galaxie_account_deletion_requested_at';
	private const CRON_HOOK    = 'galaxie_woo_daily_cleanup';
	private const RETENTION    = 6 * MONTH_IN_SECONDS;

	/**
	 * Order meta the Brazilian checkout fields write, erased with the rest of
	 * the order's personal data (WooCommerce's eraser knows only its own).
	 */
	private const ORDER_META = array(
		'_billing_cpf'          => 'text',
		'_billing_cnpj'         => 'text',
		'_billing_document'     => 'text',
		'_billing_rg'           => 'text',
		'_billing_ie'           => 'text',
		'_billing_birthdate'    => 'date',
		'_billing_sex'          => 'text',
		'_billing_cellphone'    => 'text',
		'_billing_number'       => 'text',
		'_billing_neighborhood' => 'text',
		'_shipping_number'      => 'text',
		'_shipping_neighborhood' => 'text',
		'_shipping_phone'       => 'text',
	);

	public function id(): string {
		return 'account-deletion';
	}

	public function title(): string {
		return __( 'Exclusão de conta', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'O cliente exclui a própria conta: sai de todos os dispositivos, perde o consentimento de marketing na hora, tem 6 meses para cancelar (entrando e confirmando) e depois a conta é apagada, com os pedidos anonimizados e o contato do FluentCRM excluído.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return true;
	}

	public function boot(): void {
		add_action( 'wp_ajax_galaxie_request_account_deletion', array( $this, 'ajax_request_deletion' ) );
		add_action( 'admin_post_' . self::CANCEL_ACTION, array( $this, 'cancel_deletion' ) );
		add_action( 'template_redirect', array( $this, 'notice_pending_deletion' ) );
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
			wp_send_json_error( array( 'message' => __( 'Sua sessão expirou. Atualize a página e tente de novo.', 'galaxie-woo' ) ), 403 );
		}
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Entre na sua conta para continuar.', 'galaxie-woo' ) ), 401 );
		}

		$user    = wp_get_current_user();
		$user_id = (int) $user->ID;

		update_user_meta( $user_id, self::META_KEY, current_time( 'mysql', true ) );

		// No more marketing from the moment the customer asked to leave.
		do_action( 'galaxie_woo/change_source', __( 'Minha conta — Excluir conta', 'galaxie-woo' ) );
		update_user_meta( $user_id, ProfileFields::MARKETING_OPT_IN, 'no' );
		FluentCRMApi::set_consent( (string) $user->user_email, false, $user_id );

		// Every device, not just this one: a session left open elsewhere would
		// otherwise keep the account in use.
		if ( class_exists( '\WP_Session_Tokens' ) ) {
			\WP_Session_Tokens::get_instance( $user_id )->destroy_all();
		}
		wp_logout();

		wp_send_json_success( array( 'redirect' => home_url( '/' ) ) );
	}

	/** When the account was flagged, or '' when it is not. */
	private static function requested_at( int $user_id ): string {
		return $user_id > 0 ? (string) get_user_meta( $user_id, self::META_KEY, true ) : '';
	}

	/** The "Cancelar exclusão" address for the signed-in customer. */
	private static function cancel_url(): string {
		return wp_nonce_url( add_query_arg( 'action', self::CANCEL_ACTION, admin_url( 'admin-post.php' ) ), self::CANCEL_ACTION . '_' . get_current_user_id() );
	}

	/**
	 * A customer whose account is waiting to be deleted signs in: My Account
	 * says so, with the date and the link that cancels it. Nothing changes
	 * until they press it.
	 */
	public function notice_pending_deletion(): void {
		if ( ! is_user_logged_in() || ! function_exists( 'is_account_page' ) || ! is_account_page() || ! function_exists( 'wc_add_notice' ) ) {
			return;
		}

		$at = self::requested_at( get_current_user_id() );

		if ( '' === $at ) {
			return;
		}

		$when = strtotime( $at . ' UTC' );
		$date = $when ? wp_date( 'd/m/Y', $when + self::RETENTION ) : '';

		$message = sprintf(
			/* translators: 1: the date the account will be deleted, 2: the cancel link */
			__( 'Sua conta está marcada para exclusão em %1$s. %2$s', 'galaxie-woo' ),
			esc_html( $date ),
			sprintf( '<a href="%1$s" class="button">%2$s</a>', esc_url( self::cancel_url() ), esc_html__( 'Cancelar exclusão', 'galaxie-woo' ) )
		);

		if ( ! wc_has_notice( $message, 'notice' ) ) {
			wc_add_notice( $message, 'notice' );
		}
	}

	/** The customer pressed "Cancelar exclusão". */
	public function cancel_deletion(): void {
		$back = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : home_url( '/' );

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( $back );
			exit;
		}

		$user_id = get_current_user_id();
		$nonce   = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::CANCEL_ACTION . '_' . $user_id ) ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( __( 'Não foi possível cancelar a exclusão. Atualize a página e tente de novo.', 'galaxie-woo' ), 'error' );
			}
			wp_safe_redirect( $back );
			exit;
		}

		if ( '' !== self::requested_at( $user_id ) ) {
			delete_user_meta( $user_id, self::META_KEY );

			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( __( 'Pronto: a exclusão da sua conta foi cancelada. Se quiser voltar a receber nossas novidades, ative em Comunicação.', 'galaxie-woo' ), 'success' );
			}
		}

		wp_safe_redirect( $back );
		exit;
	}

	/** Daily cron: removes every account still flagged after the retention window. */
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
			$this->erase_account( (int) $user_id );
		}
	}

	/**
	 * One account removed: orders anonymized, saved cards erased, the FluentCRM
	 * contact unsubscribed and deleted, then the user. A failure in one step is
	 * no reason to keep the rest.
	 */
	private function erase_account( int $user_id ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$email = (string) $user->user_email;

		// An order still being handled waits for its outcome: the account is
		// picked up again on a later day.
		if ( ! $this->anonymize_orders( $user_id, $email ) ) {
			return;
		}

		if ( is_callable( array( '\WC_Privacy_Erasers', 'customer_tokens_eraser' ) ) ) {
			try {
				\WC_Privacy_Erasers::customer_tokens_eraser( $email, 1 );
			} catch ( \Throwable $e ) {
				// Not in the way of the rest.
			}
		}

		FluentCRMApi::delete_contact( $email, $user_id );

		wp_delete_user( $user_id );
	}

	/**
	 * Every order of the account (by its id and its e-mail) through
	 * WooCommerce's own `remove_order_personal_data()` — the same eraser as
	 * Tools → Erase Personal Data, without waiting for the store's "remove
	 * order data" option, since the customer asked for exactly this.
	 *
	 * @return bool False while an order is still being handled (pending,
	 *              processing, on hold): nothing is erased yet, and the account
	 *              is kept for a later day.
	 */
	private function anonymize_orders( int $user_id, string $email ): bool {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return true;
		}

		$customer = array_values( array_filter( array( $email, $user_id ) ) );
		$active   = wc_get_orders(
			array(
				'limit'    => 1,
				'return'   => 'ids',
				'customer' => $customer,
				'type'     => 'shop_order',
				'status'   => array( 'wc-pending', 'wc-processing', 'wc-on-hold' ),
			)
		);

		if ( $active ) {
			return false;
		}

		if ( ! is_callable( array( '\WC_Privacy_Erasers', 'remove_order_personal_data' ) ) ) {
			return true;
		}

		$extra = static fn( $meta ) => array_merge( (array) $meta, self::ORDER_META );
		add_filter( 'woocommerce_privacy_remove_order_personal_data_meta', $extra );

		try {
			$page = 1;

			do {
				$orders = wc_get_orders(
					array(
						'limit'    => 50,
						'page'     => $page,
						'customer' => $customer,
						'type'     => 'shop_order',
					)
				);

				foreach ( $orders as $order ) {
					if ( ! $order instanceof \WC_Order || 'yes' === $order->get_meta( '_anonymized' ) ) {
						continue;
					}

					/** Same filter WooCommerce's eraser asks, so a store can keep an order. */
					if ( apply_filters( 'woocommerce_privacy_erase_order_personal_data', true, $order ) ) {
						\WC_Privacy_Erasers::remove_order_personal_data( $order );
					}
				}

				++$page;
			} while ( count( $orders ) >= 50 );
		} catch ( \Throwable $e ) {
			// Best effort: the user is still removed.
		} finally {
			remove_filter( 'woocommerce_privacy_remove_order_personal_data_meta', $extra );
		}

		return true;
	}
}
