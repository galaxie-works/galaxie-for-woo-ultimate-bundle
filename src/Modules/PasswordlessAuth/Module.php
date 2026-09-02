<?php
/**
 * Passwordless authentication (email OTP) module.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\PasswordlessAuth;

use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\ProvidesBootData;
use Galaxie\Woo\Support\Cpf;
use Galaxie\Woo\Support\ProfileFields;

defined( 'ABSPATH' ) || exit;

/**
 * Ported from eir-my-account-ux's OTP logic (class-eir-checkout-auth.php).
 * Deliberately has NO dependency on the FluentCRM module: account creation
 * fires `galaxie_woo/customer_registered` instead of calling a sync method
 * directly, so FluentCRM (or anything else) can hook in without this module
 * needing to know it exists.
 *
 * Has no widget of its own — its AJAX endpoints are consumed by the Checkout
 * module's stepper (not yet ported). Implements {@see ProvidesBootData} so
 * `ajaxUrl`/`nonce` are already available in `window.__GALAXIE_WOO__` for
 * whatever consumes them, and so this module is directly testable (via
 * fetch() in a browser console) before the Checkout widget exists.
 */
final class Module implements ModuleContract, ProvidesBootData {

	private const OTP_TTL              = 10 * MINUTE_IN_SECONDS;
	private const OTP_MAX_ATTEMPTS     = 5;
	private const OTP_RESEND_WINDOW    = 15 * MINUTE_IN_SECONDS;
	private const OTP_RESEND_MAX_SENDS = 3;
	private const NONCE_ACTION         = 'galaxie_woo_auth';

	private const AJAX_ACTIONS = array( 'galaxie_auth_send_otp', 'galaxie_auth_verify_otp', 'galaxie_auth_register' );

	public function id(): string {
		return 'passwordless-auth';
	}

	public function title(): string {
		return __( 'Passwordless Auth', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Email one-time-code login and registration (no passwords).', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return true;
	}

	public function boot(): void {
		foreach ( self::AJAX_ACTIONS as $action ) {
			add_action( 'wp_ajax_nopriv_' . $action, array( $this, $action ) );
			add_action( 'wp_ajax_' . $action, array( $this, $action ) );
		}
	}

	public function boot_data(): array {
		return array(
			'auth' => array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			),
		);
	}

	/** AJAX: request a code (login) or start registration (register). */
	public function galaxie_auth_send_otp(): void {
		$this->check_nonce();

		$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$context = isset( $_POST['context'] ) && 'register' === $_POST['context'] ? 'register' : 'login';

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'galaxie-woo' ) ) );
		}

		$existing_user = get_user_by( 'email', $email );

		// Copy deliberately doesn't confirm/deny account existence beyond this.
		if ( 'login' === $context && ! $existing_user ) {
			wp_send_json_error( array( 'message' => __( "We couldn't find an account with that email.", 'galaxie-woo' ) ) );
		}
		if ( 'register' === $context && $existing_user ) {
			wp_send_json_error( array( 'message' => __( 'An account with that email already exists. Try signing in instead.', 'galaxie-woo' ) ) );
		}

		$rl_key = $this->otp_rate_limit_key( $email );
		$sends  = (int) get_transient( $rl_key );
		if ( $sends >= self::OTP_RESEND_MAX_SENDS ) {
			wp_send_json_error( array( 'message' => __( 'Too many codes requested. Please wait a few minutes and try again.', 'galaxie-woo' ) ) );
		}

		$reg_data = null;
		if ( 'register' === $context ) {
			$reg_data = $this->validate_registration_fields( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( is_wp_error( $reg_data ) ) {
				wp_send_json_error( array( 'message' => $reg_data->get_error_message() ) );
			}
		}

		$code = str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );

		set_transient(
			$this->otp_key( $email ),
			array(
				'code'     => $code,
				'attempts' => 0,
				'context'  => $context,
				'reg_data' => $reg_data,
			),
			self::OTP_TTL
		);
		set_transient( $rl_key, $sends + 1, self::OTP_RESEND_WINDOW );

		$this->send_otp_email( $email, $code, $context );

		wp_send_json_success();
	}

	/** AJAX: alias of send_otp — the registration panel's "resend" affordance re-validates + re-sends. */
	public function galaxie_auth_register(): void {
		$this->galaxie_auth_send_otp();
	}

	/** AJAX: verify a code; on success, create the account (register) or log in (login). */
	public function galaxie_auth_verify_otp(): void {
		$this->check_nonce();

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$code  = isset( $_POST['code'] ) ? preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['code'] ) ) ) : '';

		$key = $this->otp_key( $email );
		$otp = get_transient( $key );

		if ( ! $otp ) {
			wp_send_json_error( array( 'message' => __( 'That code has expired. Please request a new one.', 'galaxie-woo' ) ) );
		}

		if ( $otp['attempts'] >= self::OTP_MAX_ATTEMPTS ) {
			delete_transient( $key );
			wp_send_json_error( array( 'message' => __( 'Too many incorrect attempts. Please request a new code.', 'galaxie-woo' ) ) );
		}

		if ( ! hash_equals( (string) $otp['code'], (string) $code ) ) {
			++$otp['attempts'];
			set_transient( $key, $otp, self::OTP_TTL );
			wp_send_json_error( array( 'message' => __( 'Incorrect code. Please try again.', 'galaxie-woo' ) ) );
		}

		delete_transient( $key );

		if ( 'register' === $otp['context'] ) {
			$user_id = $this->create_account( $email, $otp['reg_data'] );
			if ( is_wp_error( $user_id ) ) {
				wp_send_json_error( array( 'message' => $user_id->get_error_message() ) );
			}
			wp_set_current_user( $user_id );
			wp_set_auth_cookie( $user_id, true );
		} else {
			$user = get_user_by( 'email', $email );
			if ( ! $user ) {
				wp_send_json_error( array( 'message' => __( "We couldn't find an account with that email.", 'galaxie-woo' ) ) );
			}
			wp_set_current_user( $user->ID );
			wp_set_auth_cookie( $user->ID, true );
		}

		wp_send_json_success( array( 'redirect' => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' ) ) );
	}

	/**
	 * @param array<string,mixed> $data Raw (unslashed) $_POST.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function validate_registration_fields( array $data ) {
		$email = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'invalid_email', __( 'Please enter a valid email address.', 'galaxie-woo' ) );
		}

		$first_name = isset( $data['first_name'] ) ? sanitize_text_field( $data['first_name'] ) : '';
		$last_name  = isset( $data['last_name'] ) ? sanitize_text_field( $data['last_name'] ) : '';
		if ( '' === $first_name || '' === $last_name ) {
			return new \WP_Error( 'missing_name', __( 'Please enter your first and last name.', 'galaxie-woo' ) );
		}

		$birthdate = isset( $data['birthdate'] ) ? sanitize_text_field( $data['birthdate'] ) : '';
		if ( '' !== $birthdate && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $birthdate ) ) {
			return new \WP_Error( 'invalid_birthdate', __( 'Please enter a valid date of birth.', 'galaxie-woo' ) );
		}

		$cpf = isset( $data['cpf'] ) ? sanitize_text_field( $data['cpf'] ) : '';
		if ( '' !== $cpf && ! Cpf::is_valid( $cpf ) ) {
			return new \WP_Error( 'invalid_cpf', __( 'Please enter a valid CPF.', 'galaxie-woo' ) );
		}

		if ( empty( $data['terms'] ) ) {
			return new \WP_Error( 'terms_required', __( 'Please accept the terms to continue.', 'galaxie-woo' ) );
		}

		return array(
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'birthdate'  => $birthdate,
			'cpf'        => $cpf,
			'phone'      => isset( $data['phone'] ) ? sanitize_text_field( $data['phone'] ) : '',
			'marketing'  => ! empty( $data['marketing'] ),
		);
	}

	/**
	 * @param array<string,mixed>|null $reg_data
	 * @return int|\WP_Error
	 */
	private function create_account( string $email, ?array $reg_data ) {
		if ( empty( $reg_data ) || get_user_by( 'email', $email ) ) {
			return new \WP_Error( 'cannot_create', __( "We couldn't create your account. Please try again.", 'galaxie-woo' ) );
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $email,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'first_name'   => $reg_data['first_name'],
				'last_name'    => $reg_data['last_name'],
				'display_name' => trim( $reg_data['first_name'] . ' ' . $reg_data['last_name'] ),
				'role'         => 'customer',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_user_meta( $user_id, 'billing_first_name', $reg_data['first_name'] );
		update_user_meta( $user_id, 'billing_last_name', $reg_data['last_name'] );
		if ( '' !== $reg_data['phone'] ) {
			update_user_meta( $user_id, 'billing_phone', $reg_data['phone'] );
		}
		if ( '' !== $reg_data['birthdate'] ) {
			update_user_meta( $user_id, ProfileFields::BIRTHDATE, $reg_data['birthdate'] );
		}
		if ( '' !== $reg_data['cpf'] ) {
			update_user_meta( $user_id, ProfileFields::CPF, Cpf::format( $reg_data['cpf'] ) );
		}
		update_user_meta( $user_id, ProfileFields::MARKETING_OPT_IN, $reg_data['marketing'] ? 'yes' : 'no' );
		update_user_meta( $user_id, ProfileFields::SIGNUP_SOURCE, 'email' );

		/**
		 * Fires after a new customer account is created via passwordless email
		 * signup. FluentCRM's module hooks this to sync the contact — kept
		 * decoupled so this module has no FluentCRM dependency.
		 *
		 * @param int    $user_id
		 * @param string $source 'email' (GoogleLogin fires its own with 'google').
		 */
		do_action( 'galaxie_woo/customer_registered', $user_id, 'email' );
		do_action( 'woocommerce_created_customer', $user_id, array(), false );

		return $user_id;
	}

	private function send_otp_email( string $email, string $code, string $context ): void {
		$subject = 'register' === $context
			? __( 'Confirm your email to create your account', 'galaxie-woo' )
			: __( 'Your sign-in code', 'galaxie-woo' );

		$body = sprintf(
			'<p>%1$s</p><p style="font-size:28px;font-weight:600;letter-spacing:4px;">%2$s</p><p>%3$s</p>',
			esc_html__( 'Here is your code:', 'galaxie-woo' ),
			esc_html( $code ),
			esc_html__( 'This code expires in 10 minutes.', 'galaxie-woo' )
		);

		add_filter( 'wp_mail_content_type', array( $this, 'html_mail_content_type' ) );
		wp_mail( $email, $subject, $body );
		remove_filter( 'wp_mail_content_type', array( $this, 'html_mail_content_type' ) );
	}

	public function html_mail_content_type(): string {
		return 'text/html';
	}

	private function otp_key( string $email ): string {
		return 'galaxie_otp_' . md5( strtolower( trim( $email ) ) );
	}

	private function otp_rate_limit_key( string $email ): string {
		return 'galaxie_otp_rl_' . md5( strtolower( trim( $email ) ) );
	}

	private function check_nonce(): void {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh and try again.', 'galaxie-woo' ) ), 403 );
		}
	}
}
