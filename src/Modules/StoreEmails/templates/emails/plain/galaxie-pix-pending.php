<?php
/**
 * "Pix aguardando pagamento" (plain text). See ../galaxie-pix-pending.php.
 *
 * @package Galaxie\Woo
 *
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $additional_content
 * @var string   $pay_url
 * @var string   $expires
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

if ( '' !== (string) $order->get_billing_first_name() ) {
	/* translators: %s: customer first name */
	echo sprintf( esc_html__( 'Olá, %s.', 'galaxie-woo' ), esc_html( $order->get_billing_first_name() ) ) . "\n\n";
} else {
	echo esc_html__( 'Olá.', 'galaxie-woo' ) . "\n\n";
}

/* translators: %s: order number */
echo sprintf( esc_html__( 'Recebemos o seu pedido #%s. Ele está aguardando o pagamento via Pix; assim que o pagamento for confirmado, você recebe outro e-mail.', 'galaxie-woo' ), esc_html( $order->get_order_number() ) ) . "\n\n";

if ( '' !== $expires ) {
	/* translators: %s: date and time */
	echo sprintf( esc_html__( 'O código Pix gerado na compra vale até %s (horário de Brasília).', 'galaxie-woo' ), esc_html( $expires ) ) . "\n\n";
}

if ( '' !== $pay_url ) {
	echo esc_html__( 'Pagar com Pix:', 'galaxie-woo' ) . ' ' . esc_url_raw( $pay_url ) . "\n";
	echo esc_html__( 'Se o código expirou ou você fechou a página, este link abre o pagamento do pedido e gera um novo código.', 'galaxie-woo' ) . "\n\n";
}

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n----------------------------------------\n\n";

do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n\n----------------------------------------\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
