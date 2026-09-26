<?php
/**
 * Passwordless authentication (email OTP) module.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\PasswordlessAuth;

use Galaxie\Woo\Core\Field;
use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\ProvidesBootData;
use Galaxie\Woo\Core\ProvidesSettings;
use Galaxie\Woo\Core\ProvidesElementorWidgets;
use Galaxie\Woo\Modules\PasswordlessAuth\Widget\LoginWidget;
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
final class Module implements ModuleContract, ProvidesBootData, ProvidesElementorWidgets, ProvidesSettings {

	private const OTP_TTL              = 10 * MINUTE_IN_SECONDS;
	private const OTP_MAX_ATTEMPTS     = 5;
	private const OTP_RESEND_WINDOW    = 15 * MINUTE_IN_SECONDS;
	private const OTP_RESEND_MAX_SENDS = 3;
	private const NONCE_ACTION         = 'galaxie_woo_auth';

	private const AJAX_ACTIONS = array( 'galaxie_auth_send_otp', 'galaxie_auth_verify_otp', 'galaxie_auth_register', 'galaxie_auth_password_login', 'galaxie_auth_password_register' );

	/** Wrong passwords allowed per e-mail in the window below before sign-in pauses. */
	private const PASSWORD_MAX_ATTEMPTS = 8;
	private const PASSWORD_WINDOW       = 15 * MINUTE_IN_SECONDS;
	private const PASSWORD_MIN_LENGTH   = 8;

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

	/** @return array<int,class-string> */
	public function elementor_widgets(): array {
		return array( LoginWidget::class );
	}

	public function boot(): void {
		OtpMail::hooks();

		foreach ( self::AJAX_ACTIONS as $action ) {
			add_action( 'wp_ajax_nopriv_' . $action, array( $this, $action ) );
			add_action( 'wp_ajax_' . $action, array( $this, $action ) );
		}
	}

	public function boot_data(): array {
		return array(
			'auth' => array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( self::NONCE_ACTION ),
				// Which sign-in the checkout and the Galaxie Login widget draw.
				'mode'            => $this->otp_enabled() ? 'otp' : 'password',
				'lostPasswordUrl' => function_exists( 'wc_lostpassword_url' ) ? wc_lostpassword_url() : wp_lostpassword_url(),
			),
		);
	}

	public function settings_tab_label(): string {
		return __( 'Login', 'galaxie-woo' );
	}

	/** @return Field[] */
	public function settings_fields(): array {
		$templates = OtpMail::templates();
		$choose    = array( '' => __( '— Escolha um template —', 'galaxie-woo' ) ) + $templates;
		$crm       = class_exists( '\FluentCrm\App\Models\Template' );

		return array(
			new Field(
				key: 'otp_enabled',
				label: __( 'Entrar com código por e-mail', 'galaxie-woo' ),
				type: Field::TYPE_TOGGLE,
				description: __( 'Ligado: o checkout e o widget Galaxie Login pedem só o e-mail e enviam um código de 6 dígitos. Desligado: pedem e-mail e senha, o cadastro pede uma senha, e aparece "Esqueci minha senha".', 'galaxie-woo' ),
				default: true
			),
			new Field(
				key: 'email_source',
				label: __( 'E-mail do código', 'galaxie-woo' ),
				type: Field::TYPE_SELECT,
				description: $crm
					? __( 'Template do FluentCRM: o e-mail sai com o layout de um template seu (FluentCRM → Emails → Templates). Se o template não puder ser usado, o e-mail simples vai no lugar, para o cliente nunca ficar sem código.', 'galaxie-woo' )
					: __( 'O FluentCRM não está ativo: o código sai no e-mail simples do plugin.', 'galaxie-woo' ),
				default: 'plugin',
				options: array(
					'plugin'    => __( 'E-mail simples do plugin', 'galaxie-woo' ),
					'fluentcrm' => __( 'Template do FluentCRM', 'galaxie-woo' ),
				)
			),
			new Field(
				key: 'login_template',
				label: __( 'Template: código para entrar', 'galaxie-woo' ),
				type: Field::TYPE_SELECT,
				description: __( 'Precisa mostrar {{galaxie.otp_code}}; sem ele, o e-mail simples é enviado.', 'galaxie-woo' ),
				default: '',
				options: $choose
			),
			new Field(
				key: 'login_subject',
				label: __( 'Assunto: código para entrar', 'galaxie-woo' ),
				type: Field::TYPE_TEXT,
				description: __( 'Vazio: usa o assunto do template (ou "Seu código de acesso" no e-mail simples). Aceita os smartcodes abaixo.', 'galaxie-woo' ),
				default: ''
			),
			new Field(
				key: 'register_template',
				label: __( 'Template: confirmar cadastro', 'galaxie-woo' ),
				type: Field::TYPE_SELECT,
				description: __( 'O código enviado a quem está criando a conta ("Primeira compra").', 'galaxie-woo' ),
				default: '',
				options: $choose
			),
			new Field(
				key: 'register_subject',
				label: __( 'Assunto: confirmar cadastro', 'galaxie-woo' ),
				type: Field::TYPE_TEXT,
				description: __( 'Vazio: usa o assunto do template (ou "Confirme seu e-mail para criar sua conta" no e-mail simples).', 'galaxie-woo' ),
				default: ''
			),
		);
	}

	public function render_extra_settings( array $values ): void {
		echo '<h3>' . esc_html__( 'Smartcodes para o template', 'galaxie-woo' ) . '</h3>';
		echo '<p>' . esc_html__( 'No editor de templates do FluentCRM eles aparecem no botão de smartcodes, no grupo "Galaxie: código de acesso". Os do próprio FluentCRM, como {{contact.first_name}}, também funcionam quando a pessoa já é contato.', 'galaxie-woo' ) . '</p><ul>';
		foreach ( OtpMail::smartcodes() as $key => $label ) {
			printf( '<li><code>{{galaxie.%1$s}}</code> — %2$s</li>', esc_html( $key ), esc_html( $label ) );
		}
		echo '</ul><p><em>' . esc_html__( 'Evite no template os links de descadastro e de "ver no navegador": este e-mail não é uma campanha e eles ficariam quebrados. Se o log de e-mails do FluentSMTP estiver ligado, os códigos ficam guardados nele.', 'galaxie-woo' ) . '</em></p>';
	}

	public function sanitize_settings( array $submitted, array $current ): array {
		return Field::sanitize_all( $this->settings_fields(), $submitted );
	}

	/** @return array<string,mixed> */
	private function settings(): array {
		return \Galaxie\Woo\Core\Plugin::instance()->settings()->module_settings( $this->id() );
	}

	private function otp_enabled(): bool {
		return (bool) ( $this->settings()['otp_enabled'] ?? true );
	}

	/** AJAX: request a code (login) or start registration (register). */
	public function galaxie_auth_send_otp(): void {
		$this->check_nonce();

		if ( ! $this->otp_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'O login por código está desligado. Entre com seu e-mail e senha.', 'galaxie-woo' ) ) );
		}

		$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$context = isset( $_POST['context'] ) && 'register' === $_POST['context'] ? 'register' : 'login';

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Informe um e-mail válido.', 'galaxie-woo' ) ) );
		}

		$existing_user = get_user_by( 'email', $email );

		// Copy deliberately doesn't confirm/deny account existence beyond this.
		if ( 'login' === $context && ! $existing_user ) {
			wp_send_json_error( array( 'message' => __( 'Não encontramos uma conta com este e-mail. Se é sua primeira compra, use "Primeira compra".', 'galaxie-woo' ) ) );
		}
		if ( 'register' === $context && $existing_user ) {
			wp_send_json_error( array( 'message' => __( 'Já existe uma conta com este e-mail. Entre em "Já sou cliente".', 'galaxie-woo' ) ) );
		}

		$rl_key = $this->otp_rate_limit_key( $email );
		$sends  = (int) get_transient( $rl_key );
		if ( $sends >= self::OTP_RESEND_MAX_SENDS ) {
			wp_send_json_error( array( 'message' => __( 'Muitos códigos pedidos. Aguarde alguns minutos e tente de novo.', 'galaxie-woo' ) ) );
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

		OtpMail::send( $email, $code, $context, $this->settings(), (int) ( self::OTP_TTL / MINUTE_IN_SECONDS ), $reg_data );

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
			wp_send_json_error( array( 'message' => __( 'Este código expirou. Peça um novo.', 'galaxie-woo' ) ) );
		}

		if ( $otp['attempts'] >= self::OTP_MAX_ATTEMPTS ) {
			delete_transient( $key );
			wp_send_json_error( array( 'message' => __( 'Muitas tentativas erradas. Peça um novo código.', 'galaxie-woo' ) ) );
		}

		if ( ! hash_equals( (string) $otp['code'], (string) $code ) ) {
			++$otp['attempts'];
			set_transient( $key, $otp, self::OTP_TTL );
			wp_send_json_error( array( 'message' => __( 'Código incorreto. Tente de novo.', 'galaxie-woo' ) ) );
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
				wp_send_json_error( array( 'message' => __( 'Não encontramos uma conta com este e-mail. Se é sua primeira compra, use "Primeira compra".', 'galaxie-woo' ) ) );
			}
			wp_set_current_user( $user->ID );
			wp_set_auth_cookie( $user->ID, true );
		}

		wp_send_json_success( array( 'redirect' => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' ) ) );
	}

	/**
	 * AJAX: e-mail and password, for when sign-in by code is switched off.
	 * WordPress's own check (wp_signon), with a pause after repeated wrong
	 * passwords for the same e-mail; the answer never says which of the two
	 * was wrong.
	 */
	public function galaxie_auth_password_login(): void {
		$this->check_nonce();

		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- a password is checked, never stored or echoed.

		if ( ! is_email( $email ) || '' === $password ) {
			wp_send_json_error( array( 'message' => __( 'Informe seu e-mail e sua senha.', 'galaxie-woo' ) ) );
		}

		$key      = 'galaxie_pw_' . md5( strtolower( $email ) );
		$attempts = (int) get_transient( $key );
		if ( $attempts >= self::PASSWORD_MAX_ATTEMPTS ) {
			wp_send_json_error( array( 'message' => __( 'Muitas tentativas. Aguarde alguns minutos ou use "Esqueci minha senha".', 'galaxie-woo' ) ) );
		}

		$user = wp_signon(
			array(
				'user_login'    => $email,
				'user_password' => $password,
				'remember'      => true,
			),
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
			set_transient( $key, $attempts + 1, self::PASSWORD_WINDOW );
			wp_send_json_error( array( 'message' => __( 'E-mail ou senha incorretos.', 'galaxie-woo' ) ) );
		}

		delete_transient( $key );
		wp_set_current_user( $user->ID );
		wp_send_json_success();
	}

	/**
	 * AJAX: the first-purchase form with a password, for when sign-in by
	 * code is switched off. The same fields and checks as the code flow, and
	 * the same account creation, so FluentCRM and everything else hear of
	 * the new customer the same way.
	 */
	public function galaxie_auth_password_register(): void {
		$this->check_nonce();

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( get_user_by( 'email', $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Já existe uma conta com este e-mail. Entre em "Já sou cliente".', 'galaxie-woo' ) ) );
		}

		$reg_data = $this->validate_registration_fields( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( is_wp_error( $reg_data ) ) {
			wp_send_json_error( array( 'message' => $reg_data->get_error_message() ) );
		}

		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- hashed by wp_insert_user.
		if ( strlen( $password ) < self::PASSWORD_MIN_LENGTH ) {
			/* translators: %d: minimum length */
			wp_send_json_error( array( 'message' => sprintf( __( 'Escolha uma senha com pelo menos %d caracteres.', 'galaxie-woo' ), self::PASSWORD_MIN_LENGTH ) ) );
		}

		$user_id = $this->create_account( $email, $reg_data, $password );
		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error( array( 'message' => $user_id->get_error_message() ) );
		}

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );
		wp_send_json_success();
	}

	/**
	 * @param array<string,mixed> $data Raw (unslashed) $_POST.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function validate_registration_fields( array $data ) {
		$email = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'invalid_email', __( 'Informe um e-mail válido.', 'galaxie-woo' ) );
		}

		$first_name = isset( $data['first_name'] ) ? sanitize_text_field( $data['first_name'] ) : '';
		$last_name  = isset( $data['last_name'] ) ? sanitize_text_field( $data['last_name'] ) : '';
		if ( '' === $first_name || '' === $last_name ) {
			return new \WP_Error( 'missing_name', __( 'Informe seu nome e sobrenome.', 'galaxie-woo' ) );
		}

		$birthdate = isset( $data['birthdate'] ) ? sanitize_text_field( $data['birthdate'] ) : '';
		if ( '' !== $birthdate && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $birthdate ) ) {
			return new \WP_Error( 'invalid_birthdate', __( 'Informe uma data de nascimento válida.', 'galaxie-woo' ) );
		}

		$cpf = isset( $data['cpf'] ) ? sanitize_text_field( $data['cpf'] ) : '';
		if ( '' !== $cpf && ! Cpf::is_valid( $cpf ) ) {
			return new \WP_Error( 'invalid_cpf', __( 'Informe um CPF válido.', 'galaxie-woo' ) );
		}

		// Optional, but stored in E.164 like everywhere else `billing_phone` is written.
		$phone = isset( $data['phone'] ) ? sanitize_text_field( $data['phone'] ) : '';
		if ( '' !== $phone ) {
			$phone = (string) \Galaxie\Woo\Support\Phone::normalize( $phone );
			if ( '' === $phone ) {
				return new \WP_Error( 'invalid_phone', __( 'Informe um celular válido, com DDD.', 'galaxie-woo' ) );
			}
		}

		if ( empty( $data['terms'] ) ) {
			return new \WP_Error( 'terms_required', __( 'Aceite os termos para continuar.', 'galaxie-woo' ) );
		}

		return array(
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'birthdate'  => $birthdate,
			'cpf'        => $cpf,
			'phone'      => $phone,
			'marketing'  => ! empty( $data['marketing'] ),
		);
	}

	/**
	 * @param array<string,mixed>|null $reg_data
	 * @return int|\WP_Error
	 */
	private function create_account( string $email, ?array $reg_data, string $password = '' ) {
		if ( empty( $reg_data ) || get_user_by( 'email', $email ) ) {
			return new \WP_Error( 'cannot_create', __( 'Não foi possível criar sua conta. Tente de novo.', 'galaxie-woo' ) );
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $email,
				'user_email'   => $email,
				// With sign-in by code the password is never used; with passwords, it is the one chosen.
				'user_pass'    => '' !== $password ? $password : wp_generate_password( 32, true, true ),
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

	private function otp_key( string $email ): string {
		return 'galaxie_otp_' . md5( strtolower( trim( $email ) ) );
	}

	private function otp_rate_limit_key( string $email ): string {
		return 'galaxie_otp_rl_' . md5( strtolower( trim( $email ) ) );
	}

	private function check_nonce(): void {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'A sessão expirou. Recarregue a página e tente de novo.', 'galaxie-woo' ) ), 403 );
		}
	}
}
