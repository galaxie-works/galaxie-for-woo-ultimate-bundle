<?php
/**
 * Address book module — several saved addresses per customer.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\AddressBook;

use Galaxie\Woo\Core\Field;
use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\Plugin;
use Galaxie\Woo\Core\ProvidesBootData;
use Galaxie\Woo\Core\ProvidesElementorWidgets;
use Galaxie\Woo\Core\ProvidesSettings;
use Galaxie\Woo\Modules\AddressBook\Widget\AccountAddressBookWidget;
use Galaxie\Woo\Support\AddressBook;

defined( 'ABSPATH' ) || exit;

/**
 * The customer keeps addresses in My Account and picks one on the block
 * checkout. Storage and the rules live in {@see AddressBook}; this wires the
 * AJAX handlers, the checkout data, and the two ways an address gets filed
 * without the customer asking: an order placed to a new address, and an edit
 * on WooCommerce's own address screen.
 */
final class Module implements ModuleContract, ProvidesElementorWidgets, ProvidesBootData, ProvidesSettings {

	public const NONCE_ACTION = 'galaxie_woo_address_book';

	public function id(): string {
		return 'address-book';
	}

	public function title(): string {
		return __( 'Address Book', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Customers keep several addresses in My Account and pick one at checkout.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return false;
	}

	public function boot(): void {
		add_action( 'wp_ajax_galaxie_address_book_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_galaxie_address_book_delete', array( $this, 'ajax_delete' ) );
		add_action( 'wp_ajax_galaxie_address_book_default', array( $this, 'ajax_default' ) );

		add_action( 'woocommerce_customer_save_address', array( $this, 'file_edited_address' ), 10, 2 );

		if ( $this->setting( 'save_from_orders' ) ) {
			add_action( 'woocommerce_checkout_order_processed', array( $this, 'file_order_address_classic' ), 10, 3 );
			add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'file_order_address' ) );
		}
	}

	/** @return string[] */
	public function elementor_widgets(): array {
		return array( AccountAddressBookWidget::class );
	}

	/** @return array<string,mixed> */
	public function boot_data(): array {
		$data = array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
		);

		// The addresses themselves only travel to the page that uses them.
		if ( $this->setting( 'checkout_picker' ) && is_user_logged_in() && function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) {
			$data['entries'] = AddressBook::for_js( get_current_user_id() );
			$data['i18n']    = array( 'pickerTitle' => __( 'Endereços salvos', 'galaxie-woo' ) );
		}

		return array( 'addressBook' => $data );
	}

	public function settings_tab_label(): string {
		return __( 'Address Book', 'galaxie-woo' );
	}

	/** @return Field[] */
	public function settings_fields(): array {
		return array(
			new Field(
				key: 'checkout_picker',
				label: __( 'Saved addresses at checkout', 'galaxie-woo' ),
				type: Field::TYPE_TOGGLE,
				description: __( 'Show the customer\'s saved addresses above the address form on the block checkout.', 'galaxie-woo' ),
				default: true
			),
			new Field(
				key: 'save_from_orders',
				label: __( 'Keep addresses used in orders', 'galaxie-woo' ),
				type: Field::TYPE_TOGGLE,
				description: __( 'When a signed-in customer orders to an address that is not in their book yet, add it.', 'galaxie-woo' ),
				default: true
			),
		);
	}

	public function render_extra_settings( array $values ): void {}

	public function sanitize_settings( array $submitted, array $current ): array {
		return Field::sanitize_all( $this->settings_fields(), $submitted );
	}

	public function ajax_save(): void {
		$this->check_nonce_and_login();

		$user_id = get_current_user_id();
		$id      = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$data    = array( 'label' => isset( $_POST['galaxie_ab_label'] ) ? sanitize_text_field( wp_unslash( $_POST['galaxie_ab_label'] ) ) : '' );

		// The form names its fields shipping_* so WooCommerce's country script
		// swaps the state list for it; the book stores them unprefixed.
		foreach ( AddressBook::FIELDS as $field ) {
			if ( isset( $_POST[ 'shipping_' . $field ] ) ) {
				$data[ $field ] = sanitize_text_field( wp_unslash( $_POST[ 'shipping_' . $field ] ) );
			}
		}

		$this->respond( AddressBook::save( $user_id, $id, $data ), $user_id );
	}

	public function ajax_delete(): void {
		$this->check_nonce_and_login();

		$user_id = get_current_user_id();
		$id      = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';

		$this->respond( AddressBook::delete( $user_id, $id ), $user_id );
	}

	public function ajax_default(): void {
		$this->check_nonce_and_login();

		$user_id = get_current_user_id();
		$id      = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$type    = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';

		$this->respond( AddressBook::set_default( $user_id, $id, $type ), $user_id );
	}

	/** An address saved on WooCommerce's own edit-address screen joins the book. */
	public function file_edited_address( int $user_id, string $load_address ): void {
		if ( in_array( $load_address, array( 'billing', 'shipping' ), true ) ) {
			AddressBook::add_if_new( $user_id, AddressBook::wc_address( $user_id, $load_address ) );
		}
	}

	/**
	 * @param int                 $order_id Unused, part of the hook's signature.
	 * @param array<string,mixed> $posted   Unused, part of the hook's signature.
	 */
	public function file_order_address_classic( int $order_id, array $posted, \WC_Order $order ): void {
		$this->file_order_address( $order );
	}

	/** Where the parcel goes, or the billing address when the order ships nowhere. */
	public function file_order_address( \WC_Order $order ): void {
		$type    = $order->has_shipping_address() ? 'shipping' : 'billing';
		$address = array();

		foreach ( AddressBook::FIELDS as $field ) {
			$getter            = 'get_' . $type . '_' . $field;
			$address[ $field ] = is_callable( array( $order, $getter ) ) ? (string) $order->{$getter}() : '';
		}

		AddressBook::add_if_new( (int) $order->get_customer_id(), $address );
	}

	/** @param true|string|\WP_Error $result */
	private function respond( $result, int $user_id ): void {
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'entries' => AddressBook::for_js( $user_id ) ) );
	}

	private function setting( string $key ): bool {
		$settings = Plugin::instance()->settings()->module_settings( $this->id() );

		return (bool) ( $settings[ $key ] ?? true );
	}

	private function check_nonce_and_login(): void {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh and try again.', 'galaxie-woo' ) ), 403 );
		}
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Please sign in first.', 'galaxie-woo' ) ), 401 );
		}
	}
}
