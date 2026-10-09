<?php
/**
 * Cart popup overlay template.
 *
 * Shared by the shortcode and floating widget via the
 * 'aicwp_chat_cart_popup' action hook.
 *
 * @package AICWP
 * @since   1.9.6
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WooCommerce' ) || ! get_option( 'aicwp_chat_woo_cart_enabled', 0 ) ) {
    return;
}
?>
<div class="aicwp-cart-overlay" style="display: none;">
    <div class="aicwp-cart-popup">
        <div class="aicwp-cart-popup-header">
            <h3><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 7px;"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg><?php esc_html_e( 'Shopping Cart', 'ai-chat-wp' ); ?></h3>
            <div class="aicwp-cart-popup-close" role="button" tabindex="0" aria-label="<?php esc_attr_e( 'Close', 'ai-chat-wp' ); ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </div>
        </div>
        <div class="aicwp-cart-popup-body">
            <div class="aicwp-cart-items"></div>
            <div class="aicwp-cart-empty" style="display: none;">
                <p><?php esc_html_e( 'Your cart is empty.', 'ai-chat-wp' ); ?></p>
            </div>
        </div>
        <div class="aicwp-cart-popup-footer" style="display: none;">
            <div class="aicwp-cart-subtotal-row">
                <span><?php esc_html_e( 'Subtotal', 'ai-chat-wp' ); ?></span>
                <span class="aicwp-cart-subtotal-amount"></span>
            </div>
            <div class="aicwp-cart-popup-actions">
                <a href="<?php echo esc_url( wc_get_cart_url() ); ?>" class="aicwp-cart-view-btn"><?php esc_html_e( 'View Cart', 'ai-chat-wp' ); ?></a>
                <a href="<?php echo esc_url( wc_get_checkout_url() ); ?>" class="aicwp-cart-checkout-btn"><?php esc_html_e( 'Checkout', 'ai-chat-wp' ); ?></a>
            </div>
        </div>
    </div>
</div>
