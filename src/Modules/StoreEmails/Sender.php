<?php
/**
 * Puts a FluentCRM template in place of a WooCommerce e-mail's subject and body.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\StoreEmails;

use Galaxie\Woo\Support\FluentCrmTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce keeps deciding when an e-mail goes and to whom: its triggers,
 * recipients, enabled flags, attachments, From / Reply-To (and FluentSMTP
 * sending it) are untouched. For an e-mail the merchant mapped to a FluentCRM
 * template, only the subject and the body are swapped, at the last moment.
 *
 * Why `woocommerce_mail_callback_params`: it is the one filter in
 * `WC_Email::send()` (WooCommerce 10.9 / 11.1) handed both the WC_Email
 * instance — its id, its order, who it is for — and the subject and message
 * about to go to wp_mail(). `woocommerce_mail_content` has the message but
 * not the e-mail; `woocommerce_email_subject_{id}` has the subject only, and
 * nothing filters `get_content()`. YayMail (4.4.5) works earlier, on
 * `wc_get_template` and `woocommerce_email_subject_{id}`, and never touches
 * this filter: whatever it drew is replaced, so its template is not applied
 * on top of ours. If ours cannot be used — FluentCRM off, the template
 * deleted, rendering failing — the params are left alone and the e-mail
 * WooCommerce (or YayMail) drew goes out, as before.
 *
 * FluentCRM templates are HTML. An e-mail WooCommerce is set to send as
 * plain text is sent as HTML when a template replaces it: its Content-Type
 * header, and the type wp_mail() asks the e-mail for while sending, say
 * text/html. A multipart one keeps a text part, made from our HTML.
 */
final class Sender {

	/** Late: after anything else that edits the params, ours is what is sent. */
	public const PRIORITY = 1000;

	/**
	 * The e-mail being sent with a template, and the text part for it, from
	 * the swap to `woocommerce_email_sent`.
	 *
	 * @var array{email:\WC_Email,text:string}|null
	 */
	private static ?array $sending = null;

	public static function hooks(): void {
		add_filter( 'woocommerce_mail_callback_params', array( self::class, 'swap' ), self::PRIORITY, 2 );
		add_filter( 'woocommerce_email_content_type', array( self::class, 'content_type' ), self::PRIORITY, 2 );
		add_action( 'phpmailer_init', array( self::class, 'alt_body' ), self::PRIORITY );
		add_action( 'woocommerce_email_sent', array( self::class, 'sent' ), 10, 0 );
	}

	/**
	 * `[ to, subject, message, headers, attachments ]` with our subject and
	 * body, when this e-mail is mapped and its template renders.
	 *
	 * @param mixed $params
	 * @param mixed $email
	 * @return mixed
	 */
	public static function swap( $params, $email = null ) {
		self::$sending = null;

		if ( ! is_array( $params ) || ! $email instanceof \WC_Email || count( $params ) < 3 ) {
			return $params;
		}

		$rendered = self::render( $email, Module::settings() );

		if ( null === $rendered ) {
			return $params;
		}

		$params[1] = '' !== trim( $rendered['subject'] ) ? $rendered['subject'] : $params[1];
		$params[2] = $rendered['html'];

		if ( isset( $params[3] ) ) {
			$params[3] = self::html_headers( $params[3] );
		}

		self::$sending = array(
			'email' => $email,
			'text'  => self::text_version( $rendered['html'] ),
		);

		return $params;
	}

	/**
	 * Subject and HTML for `$email` from its mapped template, or null to send
	 * WooCommerce's own. Also what "Enviar teste" sends.
	 *
	 * @param array<string,mixed> $settings The module's settings.
	 * @return array{subject:string,html:string}|null
	 */
	public static function render( \WC_Email $email, array $settings ): ?array {
		$slot     = (string) $email->id;
		$template = Module::template_for( $slot, $settings );

		if ( $template <= 0 || ! FluentCrmTemplate::can_render() ) {
			return null;
		}

		try {
			$context = self::context( $email, $settings );
			$codes   = Smartcodes::codes( $context );

			Smartcodes::set_current(
				array_map( static fn( array $group ): array => $group['values'], $codes )
			);

			$order      = $context['order'];
			$user       = $context['user'];
			$subscriber = $order instanceof \WC_Order
				? FluentCrmTemplate::subscriber(
					(string) $order->get_billing_email(),
					array(
						'first_name'     => (string) $order->get_billing_first_name(),
						'last_name'      => (string) $order->get_billing_last_name(),
						'phone'          => (string) $order->get_billing_phone(),
						'address_line_1' => (string) $order->get_billing_address_1(),
						'address_line_2' => (string) $order->get_billing_address_2(),
						'city'           => (string) $order->get_billing_city(),
						'state'          => (string) $order->get_billing_state(),
						'postal_code'    => (string) $order->get_billing_postcode(),
						'country'        => (string) $order->get_billing_country(),
					)
				)
				: ( $user instanceof \WP_User
					? FluentCrmTemplate::subscriber(
						(string) $user->user_email,
						array(
							'first_name' => (string) $user->first_name,
							'last_name'  => (string) $user->last_name,
						)
					)
					: null );

			$rendered = FluentCrmTemplate::render( $template, Module::subject_for( $slot, $settings ), $codes, $subscriber, array( 'require_patterns' => true ) );

			if ( null === $rendered ) {
				return null;
			}

			FluentCrmTemplate::disable_emoji();

			return array(
				'subject' => trim( wp_specialchars_decode( wp_strip_all_tags( $rendered['subject'] ), ENT_QUOTES ) ),
				'html'    => $rendered['html'],
			);
		} catch ( \Throwable $e ) {
			return null;
		} finally {
			Smartcodes::set_current( null );
		}
	}

	/**
	 * What the smartcodes need from a WooCommerce e-mail, read off the
	 * properties its trigger() sets (WooCommerce 10.9 / 11.1).
	 *
	 * @param array<string,mixed> $settings
	 * @return array<string,mixed>
	 */
	public static function context( \WC_Email $email, array $settings ): array {
		$object = $email->object ?? null;
		$order  = $object instanceof \WC_Order ? $object : null;
		$user   = $object instanceof \WP_User ? $object : null;
		$reset  = '';

		if ( 'customer_reset_password' === $email->id && $user && '' !== (string) ( $email->reset_key ?? '' ) ) {
			// The link WooCommerce's own customer-reset-password.php prints.
			$reset = add_query_arg(
				array(
					'key'   => (string) $email->reset_key,
					'id'    => (int) $user->ID,
					'login' => rawurlencode( (string) ( $email->user_login ?? $user->user_login ) ),
				),
				wc_get_endpoint_url( 'lost-password', '', wc_get_page_permalink( 'myaccount' ) )
			);
		} elseif ( 'customer_new_account' === $email->id ) {
			$reset = (string) ( $email->set_password_url ?? '' );
		}

		return array(
			'order'     => $order,
			'email'     => $email,
			'user'      => $user,
			'note'      => 'customer_note' === $email->id ? (string) ( $email->customer_note ?? '' ) : '',
			'refund'    => ( $email->refund ?? null ) instanceof \WC_Order_Refund ? $email->refund : null,
			'partial'   => ! empty( $email->partial_refund ),
			'reset_url' => $reset,
			'settings'  => $settings,
		);
	}

	/**
	 * The e-mail we are sending answers text/html when wp_mail() asks
	 * (`wp_mail_content_type` → WC_Email::get_content_type()).
	 *
	 * @param mixed $type
	 * @param mixed $email
	 * @return mixed
	 */
	public static function content_type( $type, $email = null ) {
		return null !== self::$sending && $email === self::$sending['email'] ? 'text/html' : $type;
	}

	/**
	 * After WooCommerce's own handle_multipart(): a multipart e-mail's text
	 * part is our HTML as text, not WooCommerce's plain template; any other
	 * gets none.
	 *
	 * @param mixed $mailer PHPMailer.
	 */
	public static function alt_body( $mailer ): void {
		if ( null === self::$sending || ! is_object( $mailer ) || ! property_exists( $mailer, 'AltBody' ) ) {
			return;
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's property.
		$mailer->AltBody = 'multipart' === self::$sending['email']->get_email_type() ? self::$sending['text'] : '';
	}

	/** The send is over: nothing of ours is in effect any more. */
	public static function sent(): void {
		self::$sending = null;
	}

	/**
	 * WooCommerce's headers, Content-Type made text/html.
	 *
	 * @param mixed $headers A "\r\n"-separated string (WC_Email::get_headers()) or a list.
	 * @return mixed
	 */
	public static function html_headers( $headers ) {
		$fix = static fn( string $line ): string => (string) preg_replace( '~^(\s*Content-Type:\s*)[^;\r\n]+~i', '$1text/html', $line );

		if ( is_array( $headers ) ) {
			return array_map( static fn( $line ) => is_string( $line ) ? $fix( $line ) : $line, $headers );
		}

		if ( ! is_string( $headers ) ) {
			return $headers;
		}

		if ( ! preg_match( '~^\s*Content-Type:~im', $headers ) ) {
			return "Content-Type: text/html\r\n" . $headers;
		}

		return implode( "\n", array_map( $fix, explode( "\n", $headers ) ) );
	}

	/** The text part of a multipart e-mail. */
	private static function text_version( string $html ): string {
		$html = (string) preg_replace( '~<(style|script|head)\b[^>]*>.*?</\1>~is', '', $html );
		$html = (string) preg_replace( '~<br\s*/?>|</(?:p|div|tr|h[1-6]|li|table)>~i', "\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		$text = (string) preg_replace( "~[ \t]+~", ' ', $text );
		$text = (string) preg_replace( "~\s*\n\s*(\n\s*)+~", "\n\n", $text );

		return trim( $text );
	}
}
