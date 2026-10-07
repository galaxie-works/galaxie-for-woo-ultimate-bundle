<?php
/**
 * The e-mail that carries a one-time sign-in code.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\PasswordlessAuth;

use Galaxie\Woo\Support\FluentCrmTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the code, either as the plugin's own plain message or through an
 * Email Template the merchant designed in FluentCRM (FluentCRM → Emails →
 * Templates), chosen per purpose — signing in, or confirming a new account —
 * on the plugin's Passwordless Auth settings tab.
 *
 * A FluentCRM template is rendered by {@see FluentCrmTemplate} — the way
 * FluentCRM renders its own double opt-in mail, with none of the campaign
 * machinery (no open pixel, no rewritten links, no compliance footer) — and
 * sent through FluentCRM's `Mailer::send()` with its From / Reply-To and no
 * List-Unsubscribe header: a sign-in code is not a campaign, and the person
 * may not even be a contact yet.
 *
 * The code reaches the template through smartcodes of our own, `{{galaxie.*}}`,
 * which appear in FluentCRM's smartcode picker. They are replaced before
 * FluentCRM's parser runs, so they work for someone who is not a contact,
 * where FluentCRM would give every smartcode its default.
 *
 * Whenever the template cannot be used — FluentCRM off, the template deleted,
 * none chosen, rendering failing — the plain message goes instead. A shopper
 * waiting for a code must get one.
 */
final class OtpMail {

	/** Smartcode group key: `{{galaxie.otp_code}}`. */
	private const GROUP = 'galaxie';

	/**
	 * The values the smartcode callback answers with while a code e-mail is
	 * being rendered, for anything FluentCRM parses that our own pre-replace
	 * did not reach (a `{{galaxie.otp_code|…}}` with a default, say).
	 *
	 * @var array<string,string>
	 */
	private static array $current = array();

	/** The smartcodes, as key => label shown in FluentCRM's picker. */
	public static function smartcodes(): array {
		return array(
			'otp_code'    => __( 'Código de acesso (6 dígitos)', 'galaxie-woo' ),
			'otp_minutes' => __( 'Validade do código, em minutos', 'galaxie-woo' ),
			'otp_email'   => __( 'E-mail para onde o código foi enviado', 'galaxie-woo' ),
		);
	}

	/**
	 * Registers the smartcode group with FluentCRM's editor and parser. The
	 * same two filters FluentCRM's own `Extender::addSmartCode()` adds, set
	 * directly: its API key name differs between FluentCRM's docblock and its
	 * docs, and the filters are what the parser actually reads.
	 */
	public static function hooks(): void {
		$group = static function ( $groups ) {
			$codes = array();
			foreach ( self::smartcodes() as $key => $label ) {
				$codes[ '{{' . self::GROUP . '.' . $key . '}}' ] = $label;
			}

			$groups   = is_array( $groups ) ? $groups : array();
			$groups[] = array(
				'key'        => self::GROUP,
				'title'      => __( 'Galaxie: código de acesso', 'galaxie-woo' ),
				'shortcodes' => $codes,
			);

			return $groups;
		};

		// FluentCRM Pro reads the second for its own groups; registering on both
		// is what keeps the group in the picker either way.
		add_filter( 'fluent_crm/extended_smart_codes', $group, 100 );
		add_filter( 'fluent_crm/smartcode_groups', $group, 100 );

		add_filter(
			'fluent_crm/smartcode_group_callback_' . self::GROUP,
			static fn( $code, $value_key, $default = '' ) => self::$current[ $value_key ] ?? $default,
			10,
			3
		);
	}

	/**
	 * The FluentCRM Email Templates, as id => title, for the settings select.
	 * Empty when FluentCRM is not active.
	 *
	 * @return array<string,string>
	 */
	public static function templates(): array {
		return FluentCrmTemplate::templates();
	}

	/** Whether FluentCRM is loaded at all. */
	public static function crm_active(): bool {
		return FluentCrmTemplate::crm_active();
	}

	/**
	 * Sends the code for `$context` ('login' or 'register').
	 *
	 * @param array<string,mixed>      $settings The Passwordless Auth module settings.
	 * @param array<string,mixed>|null $reg_data A registration's validated fields (first name for the greeting).
	 */
	public static function send( string $email, string $code, string $context, array $settings, int $minutes, ?array $reg_data = null ): void {
		$values = array(
			'otp_code'    => $code,
			'otp_minutes' => (string) $minutes,
			'otp_email'   => $email,
		);

		if ( 'fluentcrm' === ( $settings['email_source'] ?? 'plugin' ) ) {
			$template = (int) ( $settings[ $context . '_template' ] ?? 0 );
			$subject  = (string) ( $settings[ $context . '_subject' ] ?? '' );

			if ( $template > 0 && self::send_template( $email, $template, $subject, $values, $reg_data ) ) {
				return;
			}
		}

		self::send_plain( $email, $context, $values, $settings );
	}

	/**
	 * @param array<string,string>     $values
	 * @param array<string,mixed>|null $reg_data
	 */
	private static function send_template( string $email, int $template_id, string $subject, array $values, ?array $reg_data ): bool {
		if ( ! self::crm_active() || ! class_exists( '\FluentCrm\App\Services\Libs\Mailer\Mailer' ) || ! class_exists( '\FluentCrm\App\Services\Helper' ) ) {
			return false;
		}

		self::$current = $values;

		try {
			// The contact, when there is one, so {{contact.first_name}} and the
			// like fill in; otherwise an unsaved one built from what we know. The
			// name typed on the sign-up form is anyone's to choose and goes to an
			// address no one has proven yet, so only a name-shaped part of it
			// reaches the e-mail: no links, no digits, short.
			$subscriber = FluentCrmTemplate::subscriber(
				$email,
				array(
					'first_name' => self::safe_name( (string) ( $reg_data['first_name'] ?? '' ) ),
					'last_name'  => self::safe_name( (string) ( $reg_data['last_name'] ?? '' ) ),
				)
			);

			$rendered = FluentCrmTemplate::render( $template_id, $subject, array( self::GROUP => array( 'values' => $values ) ), $subscriber );

			if ( null === $rendered || false === strpos( $rendered['html'], $values['otp_code'] ) ) {
				// A template that does not show the code is no use to the
				// shopper; the plain message will.
				return false;
			}

			FluentCrmTemplate::disable_emoji();

			$sent = \FluentCrm\App\Services\Libs\Mailer\Mailer::send(
				array(
					'to'      => array( 'email' => $email, 'name' => self::safe_name( (string) ( $reg_data['first_name'] ?? '' ) ) ),
					'subject' => '' !== $rendered['subject'] ? $rendered['subject'] : __( 'Seu código de acesso', 'galaxie-woo' ),
					'body'    => $rendered['html'],
					'headers' => \FluentCrm\App\Services\Helper::getMailHeader(),
				),
				null, // No subscriber here: it would add a List-Unsubscribe header to a code.
				null,
				true  // Not throttled: someone is waiting for this.
			);

			return false !== $sent;
		} catch ( \Throwable $e ) {
			return false;
		} finally {
			self::$current = array();
		}
	}

	/**
	 * The plain message: used without FluentCRM, and whenever a template
	 * cannot be sent.
	 *
	 * @param array<string,string> $values
	 * @param array<string,mixed>  $settings
	 */
	private static function send_plain( string $email, string $context, array $values, array $settings ): void {
		$typed   = trim( (string) ( $settings[ $context . '_subject' ] ?? '' ) );
		$subject = '' !== $typed
			? self::replace( $typed, $values, false )
			: ( 'register' === $context ? __( 'Confirme seu e-mail para criar sua conta', 'galaxie-woo' ) : __( 'Seu código de acesso', 'galaxie-woo' ) );

		$body = sprintf(
			'<p>%1$s</p><p style="font-size:28px;font-weight:600;letter-spacing:4px;">%2$s</p><p>%3$s</p>',
			esc_html__( 'Aqui está o seu código:', 'galaxie-woo' ),
			esc_html( $values['otp_code'] ),
			/* translators: %s: minutes */
			esc_html( sprintf( __( 'Ele vale por %s minutos.', 'galaxie-woo' ), $values['otp_minutes'] ) )
		);

		$html = static fn(): string => 'text/html';
		add_filter( 'wp_mail_content_type', $html );
		wp_mail( $email, $subject, $body );
		remove_filter( 'wp_mail_content_type', $html );
	}

	/**
	 * A typed name as it may appear in a code e-mail: links dropped, then
	 * only letters, spaces, hyphens and apostrophes, at most 40 characters.
	 */
	public static function safe_name( string $name ): string {
		$name = (string) preg_replace( '~(?:[a-z][a-z0-9+.-]*://|www\.)\S*~iu', ' ', $name );
		$name = (string) preg_replace( '~\S*\.(?:[a-z]{2,})(?:/\S*)?~iu', ' ', $name ); // bare domains: golpe.com, x.com.br/y
		$name = (string) preg_replace( "~[^\p{L}\p{M} '\-]+~u", ' ', $name );
		$name = trim( (string) preg_replace( '~\s+~u', ' ', $name ) );

		return trim( function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 40 ) : substr( $name, 0, 40 ) );
	}

	/**
	 * Our smartcodes, written in before FluentCRM's parser sees the text —
	 * also the `{{galaxie.x|default}}` form. Escaped for HTML bodies.
	 *
	 * @param array<string,string> $values
	 */
	private static function replace( string $text, array $values, bool $html ): string {
		return FluentCrmTemplate::replace( self::GROUP, $text, $values, $html );
	}
}
