<?php
/**
 * WooCommerce's cart/cart-shipping.php (template 8.8.0, unchanged through
 * WooCommerce 11.1.2) for a cart that holds a gift from a shared wish list:
 * the same row and rates, without anything naming the destination ("Shipping
 * to …", "No shipping options were found for …") and without the calculator —
 * the parcel goes to the list owner's saved address, which the buyer never
 * sees. Chosen by Modules\Wishlist\Gifts::shipping_template().
 *
 * Variables are the ones wc_cart_totals_shipping_html() passes.
 *
 * @package Galaxie\Woo
 *
 * @var array<string,mixed> $package
 * @var array<string,\WC_Shipping_Rate>|mixed $available_methods
 * @var bool   $show_package_details
 * @var string $package_details
 * @var string $package_name
 * @var int    $index
 * @var string $chosen_method
 * @var bool   $has_calculated_shipping
 */

defined( 'ABSPATH' ) || exit;

$has_calculated_shipping = ! empty( $has_calculated_shipping );
?>
<tr class="woocommerce-shipping-totals shipping galaxie-gift-shipping">
	<th><?php echo wp_kses_post( $package_name ); ?></th>
	<td data-title="<?php echo esc_attr( $package_name ); ?>">
		<?php if ( ! empty( $available_methods ) && is_array( $available_methods ) ) : ?>
			<ul id="shipping_method" class="woocommerce-shipping-methods">
				<?php foreach ( $available_methods as $method ) : ?>
					<li>
						<?php
						if ( 1 < count( $available_methods ) ) {
							printf( '<input type="radio" name="shipping_method[%1$d]" data-index="%1$d" id="shipping_method_%1$d_%2$s" value="%3$s" class="shipping_method" %4$s />', (int) $index, esc_attr( sanitize_title( $method->id ) ), esc_attr( $method->id ), checked( $method->id, $chosen_method, false ) );
						} else {
							printf( '<input type="hidden" name="shipping_method[%1$d]" data-index="%1$d" id="shipping_method_%1$d_%2$s" value="%3$s" class="shipping_method" />', (int) $index, esc_attr( sanitize_title( $method->id ) ), esc_attr( $method->id ) );
						}
						printf( '<label for="shipping_method_%1$s_%2$s">%3$s</label>', (int) $index, esc_attr( sanitize_title( $method->id ) ), wc_cart_totals_shipping_method_label( $method ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's own label markup.
						do_action( 'woocommerce_after_shipping_rate', $method, $index );
						?>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( is_cart() ) : ?>
				<p class="woocommerce-shipping-destination"><?php esc_html_e( 'Entrega no endereço que a pessoa presenteada cadastrou.', 'galaxie-woo' ); ?></p>
			<?php endif; ?>
		<?php elseif ( ! $has_calculated_shipping ) : ?>
			<?php esc_html_e( 'O frete é calculado para o endereço de quem recebe o presente.', 'galaxie-woo' ); ?>
		<?php else : ?>
			<?php esc_html_e( 'Não encontramos opções de entrega para este presente. Fale com a gente para combinar o envio.', 'galaxie-woo' ); ?>
		<?php endif; ?>

		<?php if ( ! empty( $show_package_details ) ) : ?>
			<?php echo '<p class="woocommerce-shipping-contents"><small>' . esc_html( (string) $package_details ) . '</small></p>'; ?>
		<?php endif; ?>
	</td>
</tr>
