<?php
/**
 * "Pix aguardando pagamento" (HTML). WooCommerce's own e-mail body, used when
 * no FluentCRM template is chosen for it on the "E-mails da loja" tab. A theme
 * may override it at yourtheme/woocommerce/emails/galaxie-pix-pending.php.
 *
 * @package Galaxie\Woo
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $additional_content
 * @var string   $pay_url  The order-pay link ('' once paid).
 * @var string   $expires  When the QR code expires, "07/10/2026 14:30" ('' when unknown).
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p>
<?php
if ( '' !== (string) $order->get_billing_first_name() ) {
	/* translators: %s: customer first name */
	printf( esc_html__( 'Olá, %s.', 'galaxie-woo' ), esc_html( $order->get_billing_first_name() ) );
} else {
	esc_html_e( 'Olá.', 'galaxie-woo' );
}
?>
</p>
<p>
<?php
/* translators: %s: order number */
printf( esc_html__( 'Recebemos o seu pedido #%s. Ele está aguardando o pagamento via Pix; assim que o pagamento for confirmado, você recebe outro e-mail.', 'galaxie-woo' ), esc_html( $order->get_order_number() ) );
?>
</p>
<?php if ( '' !== $expires ) : ?>
	<p>
	<?php
	/* translators: %s: date and time */
	printf( esc_html__( 'O código Pix gerado na compra vale até %s (horário de Brasília).', 'galaxie-woo' ), '<strong>' . esc_html( $expires ) . '</strong>' );
	?>
	</p>
<?php endif; ?>
<?php if ( '' !== $pay_url ) : ?>
	<p><a class="button" href="<?php echo esc_url( $pay_url ); ?>"><?php esc_html_e( 'Pagar com Pix', 'galaxie-woo' ); ?></a></p>
	<p><?php esc_html_e( 'Se o código expirou ou você fechou a página, o botão acima abre o pagamento do pedido e gera um novo código.', 'galaxie-woo' ); ?></p>
<?php endif; ?>
<?php

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
