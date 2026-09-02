<?php
/**
 * My Account experience module — the "Galaxie My Account" Elementor widget.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\MyAccount;

use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\ProvidesBootData;
use Galaxie\Woo\Core\ProvidesElementorWidgets;
use Galaxie\Woo\Integrations\FluentCRM as FluentCRMApi;
use Galaxie\Woo\Modules\MyAccount\Widget\MyAccountWidget;
use Galaxie\Woo\Support\CustomerProfile;
use Galaxie\Woo\Support\Cpf;
use Galaxie\Woo\Support\ProfileFields;

defined( 'ABSPATH' ) || exit;

final class Module implements ModuleContract, ProvidesElementorWidgets, ProvidesBootData {

	public const NONCE_ACTION = 'galaxie_woo_myaccount';

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
		add_action( 'wp_ajax_galaxie_myaccount_save_details', array( $this, 'ajax_save_details' ) );
		add_action( 'wp_ajax_galaxie_myaccount_toggle_interest', array( $this, 'ajax_toggle_interest' ) );
		add_action( 'wp_ajax_galaxie_myaccount_save_communication', array( $this, 'ajax_save_communication' ) );
	}

	public function boot_data(): array {
		return array(
			'myAccount' => array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			),
		);
	}

	/** @return string[] */
	public function elementor_widgets(): array {
		return array( MyAccountWidget::class );
	}

	public function ajax_save_details(): void {
		$this->check_nonce_and_login();
		$user_id = get_current_user_id();

		$first_name  = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$last_name   = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
		$social_name = isset( $_POST['social_name'] ) ? sanitize_text_field( wp_unslash( $_POST['social_name'] ) ) : '';
		$birthdate   = isset( $_POST['birthdate'] ) ? sanitize_text_field( wp_unslash( $_POST['birthdate'] ) ) : '';
		$cpf         = isset( $_POST['cpf'] ) ? sanitize_text_field( wp_unslash( $_POST['cpf'] ) ) : '';
		$gender      = isset( $_POST['gender'] ) ? sanitize_text_field( wp_unslash( $_POST['gender'] ) ) : '';

		if ( '' === $first_name || '' === $last_name ) {
			wp_send_json_error( array( 'message' => __( 'Please enter your first and last name.', 'galaxie-woo' ) ) );
		}
		if ( '' !== $cpf && ! Cpf::is_valid( $cpf ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid CPF.', 'galaxie-woo' ) ) );
		}
		if ( '' !== $birthdate && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $birthdate ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid date of birth.', 'galaxie-woo' ) ) );
		}
		if ( '' !== $gender && ! array_key_exists( $gender, ProfileFields::gender_options() ) ) {
			wp_send_json_error( array( 'message' => __( 'Please choose a valid option.', 'galaxie-woo' ) ) );
		}

		wp_update_user(
			array(
				'ID'         => $user_id,
				'first_name' => $first_name,
				'last_name'  => $last_name,
			)
		);
		update_user_meta( $user_id, 'billing_first_name', $first_name );
		update_user_meta( $user_id, 'billing_last_name', $last_name );
		update_user_meta( $user_id, ProfileFields::SOCIAL_NAME, $social_name );
		if ( '' !== $birthdate ) {
			update_user_meta( $user_id, ProfileFields::BIRTHDATE, $birthdate );
		}
		if ( '' !== $cpf ) {
			update_user_meta( $user_id, ProfileFields::CPF, Cpf::format( $cpf ) );
		}
		update_user_meta( $user_id, ProfileFields::GENDER, $gender );

		/** Fires after a My Account details save — FluentCRM's module re-syncs core fields on this. */
		do_action( 'galaxie_woo/profile_updated', $user_id );

		wp_send_json_success( CustomerProfile::status( $user_id ) );
	}

	public function ajax_toggle_interest(): void {
		$this->check_nonce_and_login();
		$user = wp_get_current_user();

		$tag_id   = isset( $_POST['tag_id'] ) ? absint( $_POST['tag_id'] ) : 0;
		$selected = ! empty( $_POST['selected'] );

		if ( $tag_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid interest.', 'galaxie-woo' ) ) );
		}

		if ( $selected ) {
			FluentCRMApi::attach_tags( $user->user_email, array( $tag_id ) );
		} else {
			FluentCRMApi::detach_tags( $user->user_email, array( $tag_id ) );
		}

		wp_send_json_success();
	}

	public function ajax_save_communication(): void {
		$this->check_nonce_and_login();
		$user_id = get_current_user_id();
		$user    = wp_get_current_user();

		$opt_in = ! empty( $_POST['opt_in'] );
		update_user_meta( $user_id, ProfileFields::MARKETING_OPT_IN, $opt_in ? 'yes' : 'no' );

		$fluent_settings = \Galaxie\Woo\Core\Plugin::instance()->settings()->module_settings( 'fluentcrm' );
		$list_id         = (int) ( $fluent_settings['newsletter_list_id'] ?? 0 );
		if ( $list_id > 0 ) {
			if ( $opt_in ) {
				FluentCRMApi::attach_lists( $user->user_email, array( $list_id ) );
			} else {
				FluentCRMApi::detach_lists( $user->user_email, array( $list_id ) );
			}
		}

		wp_send_json_success( array( 'optedIn' => $opt_in ) );
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
