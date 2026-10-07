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
use Galaxie\Woo\Support\QuotedDestination;

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

	private const OTP_TTL      = 10 * MINUTE_IN_SECONDS;
	private const NONCE_ACTION = 'galaxie_woo_auth';

	/*
	 * Limits on the code flow. "Network" is the visitor's address (see
	 * Throttle::client_ip()). Counted before anything is looked up, so the
	 * answers cannot be used to test a list of e-mails quickly.
	 */

	/** Codes one network may ask for, per 15 minutes and per hour, whatever the e-mail. */
	private const SEND_PER_IP_15 = 10;
	private const SEND_PER_IP_60 = 30;
	/** Codes one network may ask for one e-mail per 15 minutes. Per network, so a stranger cannot use up the owner's. */
	private const SEND_PER_EMAIL_IP_15 = 3;
	/** Codes one e-mail may be sent per hour from all networks together (more than one network alone can ask for). */
	private const SEND_PER_EMAIL_60 = 15;
	/** Sign-up codes the whole store sends per hour: these go to addresses no one has proven yet. Filterable. */
	private const SEND_REGISTER_GLOBAL_60 = 200;
	/** Codes alive at once per e-mail, and per network within those: a resend, or a stranger's request, does not void a code already on its way. */
	private const ACTIVE_CODES        = 5;
	private const ACTIVE_CODES_PER_IP = 2;
	/** Codes one network may try, per 15 minutes and per hour, whatever the e-mail. */
	private const VERIFY_PER_IP_15 = 20;
	private const VERIFY_PER_IP_60 = 60;
	/** Wrong codes one network may enter for one e-mail per 15 minutes. */
	private const FAILS_PER_EMAIL_IP_15 = 5;
	/** Wrong codes for one e-mail, from all networks, that lock its codes for an hour. */
	private const FAILS_PER_EMAIL_60 = 10;
	private const LOCKOUT            = HOUR_IN_SECONDS;

	private const AJAX_ACTIONS = array( 'galaxie_auth_send_otp', 'galaxie_auth_verify_otp', 'galaxie_auth_register', 'galaxie_auth_password_login', 'galaxie_auth_password_register' );

	/** Sign-in attempts allowed per e-mail in the window below before sign-in pauses. */
	private const PASSWORD_MAX_ATTEMPTS = 8;
	private const PASSWORD_WINDOW       = 15 * MINUTE_IN_SECONDS;
	/** Password sign-ins one network may try per 15 minutes, whatever the e-mail. */
	private const PASSWORD_PER_IP_15 = 20;
	/** Accounts one network may create with a password per hour. */
	private const PASSWORD_REGISTER_PER_IP_60 = 5;
	private const PASSWORD_MIN_LENGTH         = 8;

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

		QuotedDestination::hooks();
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
		$crm       = OtpMail::crm_active();

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
		// Say why the template lists are empty rather than leave them blank.
		if ( OtpMail::crm_active() && ! OtpMail::templates() ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__( 'Nenhum template de e-mail foi encontrado no FluentCRM. Crie um em FluentCRM → Emails → Templates e recarregue esta página.', 'galaxie-woo' )
			);
		}

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

	/**
	 * AJAX: request a code (login) or start registration (register).
	 *
	 * Signing in, the answer is the same whether or not the e-mail has an
	 * account (and for staff accounts, which never sign in by code): a code
	 * goes out only when it can be used. Creating an account still says when
	 * the e-mail is taken — the form cannot work otherwise — but only after
	 * the per-network limit, so that cannot be used to test a list either.
	 */
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

		$reg_data = null;
		if ( 'register' === $context ) {
			$reg_data = $this->validate_registration_fields( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( is_wp_error( $reg_data ) ) {
				wp_send_json_error( array( 'message' => $reg_data->get_error_message() ) );
			}
		}

		// Per network, before the e-mail is looked up. (A form the shopper
		// filled in wrong is answered above without counting: nothing in
		// those answers depends on whether the e-mail has an account.)
		$ip       = Throttle::client_ip();
		$too_many = __( 'Muitos códigos pedidos. Aguarde alguns minutos e tente de novo.', 'galaxie-woo' );
		$this->throttle( 'otp_send_ip15|' . $ip, self::SEND_PER_IP_15, 15 * MINUTE_IN_SECONDS, $too_many );
		$this->throttle( 'otp_send_ip60|' . $ip, self::SEND_PER_IP_60, HOUR_IN_SECONDS, $too_many );

		$existing_user = get_user_by( 'email', $email );

		if ( 'register' === $context && $existing_user ) {
			wp_send_json_error( array( 'message' => __( 'Já existe uma conta com este e-mail. Entre em "Já sou cliente".', 'galaxie-woo' ) ) );
		}

		// Signing in, a code goes out only to a customer account; to anyone
		// else the answer is the same, and nothing is sent.
		$deliver = 'register' === $context || ( $existing_user instanceof \WP_User && self::may_use_code( $existing_user ) );

		if ( 'register' === $context ) {
			/**
			 * Sign-up codes the store sends per hour, all visitors together.
			 *
			 * @param int $max
			 */
			$global = (int) apply_filters( 'galaxie_woo/otp_register_sends_per_hour', self::SEND_REGISTER_GLOBAL_60 );
			$this->throttle( 'otp_send_register', max( 1, $global ), HOUR_IN_SECONDS, __( 'Muitos cadastros agora. Tente de novo em alguns minutos.', 'galaxie-woo' ) );
		}

		$code   = str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$hash   = $this->code_hash( $email, $code );
		$status = $this->with_record(
			$email,
			static function ( array $record ) use ( $ip, $deliver, $hash, $context, $reg_data ): array {
				$now = time();

				if ( ( $record['locked_until'] ?? 0 ) > $now ) {
					return array( $record, 'locked' );
				}

				$from_ip = 0;
				foreach ( $record['sends'] as $send ) {
					if ( $send['ip'] === $ip && $send['t'] > $now - 15 * MINUTE_IN_SECONDS ) {
						++$from_ip;
					}
				}
				if ( $from_ip >= self::SEND_PER_EMAIL_IP_15 || count( $record['sends'] ) >= self::SEND_PER_EMAIL_60 ) {
					return array( $record, 'too_many' );
				}

				// Counted whether or not a code goes out, so the limits answer
				// the same for an e-mail with no account.
				$record['sends'][] = array( 't' => $now, 'ip' => $ip );

				if ( $deliver ) {
					$record['codes'][] = array(
						'h'        => $hash,
						'exp'      => $now + self::OTP_TTL,
						'ip'       => $ip,
						'context'  => $context,
						'reg_data' => $reg_data,
					);
					$record['codes'] = self::keep_codes( $record['codes'] );
				}

				return array( $record, 'ok' );
			}
		);

		if ( 'locked' === $status ) {
			wp_send_json_error( array( 'message' => $this->locked_message() ) );
		}
		if ( 'too_many' === $status ) {
			wp_send_json_error( array( 'message' => $too_many ) );
		}

		if ( $deliver ) {
			OtpMail::send( $email, $code, $context, $this->settings(), (int) ( self::OTP_TTL / MINUTE_IN_SECONDS ), $reg_data );
		}

		if ( 'login' === $context ) {
			wp_send_json_success( array( 'notice' => __( 'Se existir uma conta com este e-mail, enviamos um código. Se é sua primeira compra, use "Primeira compra".', 'galaxie-woo' ) ) );
		}

		wp_send_json_success();
	}

	/** AJAX: alias of send_otp — the registration panel's "resend" affordance re-validates + re-sends. */
	public function galaxie_auth_register(): void {
		$this->galaxie_auth_send_otp();
	}

	/**
	 * AJAX: verify a code; on success, create the account (register) or log in (login).
	 *
	 * A code that is wrong, expired, never sent, or for an e-mail with no
	 * account all get the same answer. The code is compared and spent inside
	 * the e-mail's lock, so it works once, and wrong codes are counted exactly.
	 */
	public function galaxie_auth_verify_otp(): void {
		$this->check_nonce();

		if ( ! $this->otp_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'O login por código está desligado. Entre com seu e-mail e senha.', 'galaxie-woo' ) ) );
		}

		$ip       = Throttle::client_ip();
		$too_many = __( 'Muitas tentativas. Aguarde alguns minutos e tente de novo.', 'galaxie-woo' );
		$this->throttle( 'otp_verify_ip15|' . $ip, self::VERIFY_PER_IP_15, 15 * MINUTE_IN_SECONDS, $too_many );
		$this->throttle( 'otp_verify_ip60|' . $ip, self::VERIFY_PER_IP_60, HOUR_IN_SECONDS, $too_many );

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$code  = isset( $_POST['code'] ) ? preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['code'] ) ) ) : '';
		$wrong = __( 'Código incorreto ou expirado. Confira o código ou peça um novo.', 'galaxie-woo' );

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => $wrong ) );
		}

		$hash  = 6 === strlen( (string) $code ) ? $this->code_hash( $email, (string) $code ) : '';
		$match = null;

		$status = $this->with_record(
			$email,
			static function ( array $record ) use ( $ip, $hash, &$match ): array {
				$now = time();

				if ( ( $record['locked_until'] ?? 0 ) > $now ) {
					return array( $record, 'locked' );
				}

				$ip_fails = 0;
				foreach ( $record['fails'] as $fail ) {
					if ( $fail['ip'] === $ip && $fail['t'] > $now - 15 * MINUTE_IN_SECONDS ) {
						++$ip_fails;
					}
				}
				if ( $ip_fails >= self::FAILS_PER_EMAIL_IP_15 ) {
					return array( $record, 'ip_locked' );
				}

				if ( '' !== $hash ) {
					foreach ( $record['codes'] as $candidate ) {
						if ( hash_equals( (string) $candidate['h'], $hash ) ) {
							$match = $candidate;
							// Spent, with every other code for this e-mail.
							$record['codes'] = array();
							return array( $record, 'ok' );
						}
					}
				}

				$record['fails'][] = array( 't' => $now, 'ip' => $ip );
				if ( count( $record['fails'] ) >= self::FAILS_PER_EMAIL_60 ) {
					$record['locked_until'] = $now + self::LOCKOUT;
					$record['codes']        = array();
					return array( $record, 'locked' );
				}

				return array( $record, 'wrong' );
			}
		);

		if ( 'locked' === $status ) {
			wp_send_json_error( array( 'message' => $this->locked_message() ) );
		}
		if ( 'ip_locked' === $status ) {
			wp_send_json_error( array( 'message' => __( 'Muitas tentativas erradas. Aguarde alguns minutos e peça um novo código.', 'galaxie-woo' ) ) );
		}
		if ( 'ok' !== $status || ! is_array( $match ) ) {
			wp_send_json_error( array( 'message' => $wrong ) );
		}

		// Before signing in: WooCommerce drops the guest's cart CEP on the
		// first signed-in request. See QuotedDestination.
		$quoted = QuotedDestination::remember();

		if ( 'register' === $match['context'] ) {
			$user_id = $this->create_account( $email, is_array( $match['reg_data'] ) ? $match['reg_data'] : null );
			if ( is_wp_error( $user_id ) ) {
				wp_send_json_error( array( 'message' => $user_id->get_error_message() ) );
			}
			if ( null !== $quoted ) {
				QuotedDestination::keep_in_account( $user_id, $quoted );
			}
			wp_set_current_user( $user_id );
			wp_set_auth_cookie( $user_id, true );
		} else {
			$user = get_user_by( 'email', $email );
			// Checked again here: a role can change while a code is on its way.
			if ( ! $user instanceof \WP_User || ! self::may_use_code( $user ) ) {
				wp_send_json_error( array( 'message' => $wrong ) );
			}

			// What a password sign-in goes through after the password: plugins
			// that block an account (unapproved, banned, locked out) say no here.
			$user = apply_filters( 'wp_authenticate_user', $user, '' );
			if ( is_wp_error( $user ) || ! $user instanceof \WP_User ) {
				$reason = is_wp_error( $user ) ? trim( wp_strip_all_tags( $user->get_error_message() ) ) : '';
				wp_send_json_error( array( 'message' => '' !== $reason ? $reason : __( 'Não foi possível entrar com esta conta. Fale com a loja.', 'galaxie-woo' ) ) );
			}

			wp_set_current_user( $user->ID );
			wp_set_auth_cookie( $user->ID, true );

			/** The sign-in, announced as WordPress's own (activity logs, WooCommerce's cart). */
			do_action( 'wp_login', $user->user_login, $user );
		}

		wp_send_json_success( array( 'redirect' => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' ) ) );
	}

	/**
	 * AJAX: e-mail and password, for when sign-in by code is switched off.
	 * WordPress's own check (wp_signon), with a pause after repeated attempts
	 * for the same e-mail and from the same network; the answer never says
	 * which of the two was wrong. Refused while sign-in by code is on: the
	 * store then has no passwords to offer.
	 */
	public function galaxie_auth_password_login(): void {
		$this->check_nonce();

		if ( $this->otp_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'Nesta loja você entra com um código enviado por e-mail.', 'galaxie-woo' ) ), 403 );
		}

		$too_many = __( 'Muitas tentativas. Aguarde alguns minutos ou use "Esqueci minha senha".', 'galaxie-woo' );
		$this->throttle( 'pw_login_ip15|' . Throttle::client_ip(), self::PASSWORD_PER_IP_15, 15 * MINUTE_IN_SECONDS, $too_many );

		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- a password is checked, never stored or echoed.

		if ( ! is_email( $email ) || '' === $password ) {
			wp_send_json_error( array( 'message' => __( 'Informe seu e-mail e sua senha.', 'galaxie-woo' ) ) );
		}

		// Counted before the attempt (so parallel requests cannot all slip
		// under it) and forgotten on success.
		$bucket = 'pw_login_email|' . strtolower( $email );
		$this->throttle( $bucket, self::PASSWORD_MAX_ATTEMPTS, self::PASSWORD_WINDOW, $too_many );

		$user = wp_signon(
			array(
				'user_login'    => $email,
				'user_password' => $password,
				'remember'      => true,
			),
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
			wp_send_json_error( array( 'message' => __( 'E-mail ou senha incorretos.', 'galaxie-woo' ) ) );
		}

		try {
			Throttle::reset( $bucket );
		} catch ( \RuntimeException $e ) {
			unset( $e ); // Signed in either way; the count simply runs out.
		}
		wp_set_current_user( $user->ID );
		wp_send_json_success();
	}

	/**
	 * AJAX: the first-purchase form with a password, for when sign-in by
	 * code is switched off. The same fields and checks as the code flow, and
	 * the same account creation, so FluentCRM and everything else hear of
	 * the new customer the same way. Refused while sign-in by code is on:
	 * there an account is created only once the e-mail is proven.
	 *
	 * TODO: the e-mail is not confirmed in password mode (the account is
	 * created and signed in at once). Confirming it needs a pending state and
	 * a link or code e-mail; until then this mode is limited per network.
	 */
	public function galaxie_auth_password_register(): void {
		$this->check_nonce();

		if ( $this->otp_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'Nesta loja a conta é criada com um código enviado por e-mail. Use "Primeira compra".', 'galaxie-woo' ) ), 403 );
		}

		$this->throttle( 'pw_register_ip60|' . Throttle::client_ip(), self::PASSWORD_REGISTER_PER_IP_60, HOUR_IN_SECONDS, __( 'Muitos cadastros a partir desta conexão. Tente de novo mais tarde.', 'galaxie-woo' ) );

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

		// Required and checked against the minimum age while the Idade mínima
		// module is on: refused here, no account and no FluentCRM contact exist.
		$birthdate = isset( $data['birthdate'] ) ? sanitize_text_field( $data['birthdate'] ) : '';
		$problem   = \Galaxie\Woo\Modules\AgeGate\Module::check( $birthdate );
		if ( null !== $problem ) {
			return new \WP_Error( 'invalid_birthdate', $problem );
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

	/**
	 * Whether this account may sign in with an e-mailed code: customers only.
	 * Staff (anyone who can edit content, run the shop or the site) sign in
	 * at wp-login.php, with their password and whatever second factor the
	 * site adds — a code to their inbox must not be a way around those.
	 */
	private static function may_use_code( \WP_User $user ): bool {
		return ! ( user_can( $user, 'edit_posts' ) || user_can( $user, 'manage_woocommerce' ) || user_can( $user, 'manage_options' ) );
	}

	/**
	 * The code as stored: keyed to the e-mail and the site's secret, so the
	 * database (or a backup of it) never holds a code that can be typed in.
	 */
	private function code_hash( string $email, string $code ): string {
		return hash_hmac( 'sha256', strtolower( trim( $email ) ) . '|' . $code, wp_salt( 'auth' ) );
	}

	/**
	 * Reads, changes and saves an e-mail's code record while holding its lock.
	 * `$change` gets the record (expired entries already dropped) and returns
	 * `array( $record, $status )`; the status is returned.
	 *
	 * The record: `codes` (hash, expiry, network, context, sign-up data),
	 * `sends` and `fails` of the last hour (time, network), `locked_until`.
	 */
	private function with_record( string $email, callable $change ): string {
		$key = $this->otp_key( $email );

		try {
			return (string) Throttle::locked(
				$key,
				static function () use ( $key, $change ): string {
					$now    = time();
					$stored = Throttle::read( $key );
					$fresh  = static fn( $list, callable $keep ): array => array_values( array_filter( is_array( $list ) ? $list : array(), static fn( $item ): bool => is_array( $item ) && $keep( $item ) ) );

					// A record from before codes were hashed has no `codes`
					// list: its code simply stops working, as if expired.
					$record = array(
						'codes'        => $fresh( $stored['codes'] ?? null, static fn( array $c ): bool => isset( $c['h'], $c['exp'] ) && (int) $c['exp'] > $now ),
						'sends'        => $fresh( $stored['sends'] ?? null, static fn( array $s ): bool => isset( $s['t'], $s['ip'] ) && (int) $s['t'] > $now - HOUR_IN_SECONDS ),
						'fails'        => $fresh( $stored['fails'] ?? null, static fn( array $f ): bool => isset( $f['t'], $f['ip'] ) && (int) $f['t'] > $now - HOUR_IN_SECONDS ),
						'locked_until' => (int) ( $stored['locked_until'] ?? 0 ) > $now ? (int) $stored['locked_until'] : 0,
					);

					list( $record, $status ) = $change( $record );

					$empty = ! $record['codes'] && ! $record['sends'] && ! $record['fails'] && ! $record['locked_until'];
					Throttle::write( $key, $empty ? array() : $record, max( HOUR_IN_SECONDS, (int) $record['locked_until'] - $now ) );

					return (string) $status;
				}
			);
		} catch ( \RuntimeException $e ) {
			$this->busy();
		}
	}

	/**
	 * The codes to keep once one is added (last in the list): the newest
	 * {@see ACTIVE_CODES_PER_IP} per network, and the newest
	 * {@see ACTIVE_CODES} overall.
	 *
	 * @param array<int,array<string,mixed>> $codes Oldest first.
	 * @return array<int,array<string,mixed>>
	 */
	private static function keep_codes( array $codes ): array {
		$per_ip = array();
		$kept   = array();
		foreach ( array_reverse( $codes ) as $code ) {
			$ip            = (string) $code['ip'];
			$per_ip[ $ip ] = ( $per_ip[ $ip ] ?? 0 ) + 1;
			if ( $per_ip[ $ip ] <= self::ACTIVE_CODES_PER_IP && count( $kept ) < self::ACTIVE_CODES ) {
				$kept[] = $code;
			}
		}

		return array_reverse( $kept );
	}

	/** Answers and stops when `$bucket` is over `$max` requests per `$window` seconds. */
	private function throttle( string $bucket, int $max, int $window, string $message ): void {
		try {
			$allowed = Throttle::hit( $bucket, $max, $window );
		} catch ( \RuntimeException $e ) {
			$this->busy();
		}

		if ( ! $allowed ) {
			wp_send_json_error( array( 'message' => $message ) );
		}
	}

	/** The database lock could not be had: refuse rather than count wrongly. */
	private function busy(): never {
		wp_send_json_error( array( 'message' => __( 'Não foi possível concluir agora. Tente de novo em instantes.', 'galaxie-woo' ) ) );
		exit; // wp_send_json_error() dies; this is for static analysis and the test stubs.
	}

	private function locked_message(): string {
		return __( 'Muitas tentativas erradas com este e-mail. Por segurança, aguarde uma hora e peça um novo código.', 'galaxie-woo' );
	}

	private function check_nonce(): void {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'A sessão expirou. Recarregue a página e tente de novo.', 'galaxie-woo' ) ), 403 );
		}
	}
}
