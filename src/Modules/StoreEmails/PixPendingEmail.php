<?php
/**
 * WooCommerce e-mail: Pix aguardando pagamento.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\StoreEmails;

defined( 'ABSPATH' ) || exit;

/**
 * A customer e-mail like WooCommerce's own: on, off, subject, heading, extra
 * text and type in WooCommerce → Configurações → E-mails; From / Reply-To,
 * attachments and FluentSMTP as for any other. Its body is the template in
 * `templates/emails/` (a theme may override it at
 * `woocommerce/emails/galaxie-pix-pending.php`), or — chosen on the "E-mails
 * da loja" tab — a FluentCRM template, swapped in by {@see Sender} like the
 * others. When it is sent: {@see PixPending}.
 *
 * Loaded only from `woocommerce_email_classes` (PixPending::register()),
 * when WC_Email exists.
 */
class PixPendingEmail extends \WC_Email {

	public function __construct() {
		$this->id             = PixPending::ID;
		$this->customer_email = true;
		$this->title          = __( 'Pix aguardando pagamento', 'galaxie-woo' );
		$this->description    = __( 'Enviado ao cliente quando um pedido pago com Pix (FunnelKit Stripe) fica aguardando o pagamento, com o link para pagar. Uma vez por pedido; de novo só se um novo código Pix for gerado depois que o anterior expirou.', 'galaxie-woo' );
		$this->email_group    = 'payments';
		$this->template_html  = 'emails/galaxie-pix-pending.php';
		$this->template_plain = 'emails/plain/galaxie-pix-pending.php';
		$this->template_base  = __DIR__ . '/templates/';
		$this->placeholders   = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);

		parent::__construct();
	}

	public function get_default_subject() {
		return __( 'Seu pedido #{order_number} aguarda o pagamento via Pix', 'galaxie-woo' );
	}

	public function get_default_heading() {
		return __( 'Falta só o Pix', 'galaxie-woo' );
	}

	public function get_default_additional_content() {
		return __( 'Qualquer dúvida, é só responder este e-mail.', 'galaxie-woo' );
	}

	/**
	 * @param int            $order_id
	 * @param \WC_Order|bool $order
	 */
	public function trigger( $order_id, $order = false ) {
		$this->setup_locale();

		if ( $order_id && ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( $order instanceof \WC_Order ) {
			$this->object                         = $order;
			$this->recipient                      = $order->get_billing_email();
			$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
			$this->placeholders['{order_number}'] = $order->get_order_number();
		}

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
	}

	/** @return array<string,mixed> What both templates receive. */
	private function template_args( bool $plain ): array {
		$order = $this->object instanceof \WC_Order ? $this->object : null;

		return array(
			'order'              => $order,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'pay_url'            => $order && $order->needs_payment() ? $order->get_checkout_payment_url() : '',
			'expires'            => $order ? PixPending::expires_label( $order ) : '',
			'sent_to_admin'      => false,
			'plain_text'         => $plain,
			'email'              => $this,
		);
	}

	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}
}
