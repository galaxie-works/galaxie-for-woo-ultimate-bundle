<?php
/**
 * "Pode ser inserido em kits?" on the product edit screen.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\GiftWrap;

use Galaxie\Woo\Support\GiftPacking;

defined( 'ABSPATH' ) || exit;

/**
 * A checkbox on the product's General tab (Products → edit → Product data →
 * General, simple and variable products alike), ticked by default: unticked,
 * the product leaves gift kits — no "Montar um kit" button on its page, and
 * the kit refuses it on every path ({@see GiftPacking::kit_allowed()}).
 *
 * Stored as `_galaxie_kit_allowed` = 'no' only when unticked; ticked deletes
 * it, so every product that existed before keeps going in kits. The REST
 * registration ({@see ProductMeta}) is not needed: WooCommerce's
 * `wc/v3/products` takes it in `meta_data`.
 */
final class KitAllowedField {

	private const NONCE = 'galaxie_kit_allowed_field';

	private const NONCE_FIELD = 'galaxie_kit_allowed_nonce';

	private const FIELD = '_galaxie_kit_allowed_check';

	public static function hooks(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( self::class, 'render' ) );
		add_action( 'woocommerce_admin_process_product_object', array( self::class, 'save' ) );
	}

	public static function render(): void {
		global $post;

		$id = $post instanceof \WP_Post ? (int) $post->ID : 0;

		if ( ! $id ) {
			return;
		}

		echo '<div class="options_group galaxie-kit-allowed">';

		wp_nonce_field( self::NONCE, self::NONCE_FIELD, false );

		woocommerce_wp_checkbox(
			array(
				'id'          => self::FIELD,
				'label'       => __( 'Pode ser inserido em kits?', 'galaxie-woo' ),
				'description' => __( 'Desmarcado, o produto sai dos kits de presente: o botão "Montar um kit" some da página dele e o kit não o aceita. Para produtos que já vêm prontos para presente.', 'galaxie-woo' ),
				'value'       => 'no' === (string) get_post_meta( $id, GiftPacking::KIT_ALLOWED_META, true ) ? 'no' : 'yes',
				'cbvalue'     => 'yes',
			)
		);

		echo '</div>';
	}

	/** @param \WC_Product|mixed $product */
	public static function save( $product ): void {
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';

		// The nonce is the sign the checkbox was on the form: an unticked box posts nothing.
		if ( ! $product instanceof \WC_Product || ! wp_verify_nonce( $nonce, self::NONCE ) || ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return;
		}

		if ( isset( $_POST[ self::FIELD ] ) ) {
			$product->delete_meta_data( GiftPacking::KIT_ALLOWED_META );
		} else {
			$product->update_meta_data( GiftPacking::KIT_ALLOWED_META, 'no' );
		}
	}
}
