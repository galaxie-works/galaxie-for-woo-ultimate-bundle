<?php
/**
 * Gift packing dimensions on candle variations.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\GiftWrap;

defined( 'ABSPATH' ) || exit;

/**
 * "Medidas para embalagem de presente (cm)" on each candle variation
 * (Products → edit → Variations): the jar alone, lid on, without its thin
 * shipping box.
 *
 * WooCommerce's own dimensions stay the jar in its shipping box, because
 * Melhor Envio quotes freight from them. When all three gift dimensions are
 * filled, `Support\GiftPacking::candle_from_product()` packs with them instead,
 * and so do `store_sizes()` and the boxes' "Cabe:" preview. Left blank, the
 * shipping dimensions are used.
 *
 * Shown on variable products that have the candle size attribute and are not
 * gift boxes. Self-contained: the Gift Wrap module boots it with its settings.
 */
final class CandleFields {

	/** Variation meta, by field. */
	public const META = array(
		'length' => '_galaxie_gift_length',
		'width'  => '_galaxie_gift_width',
		'height' => '_galaxie_gift_height',
	);

	private const NONCE = 'galaxie_gift_candle_fields';

	private const NONCE_FIELD = 'galaxie_gift_candle_nonce';

	/**
	 * @param string $attribute      Candle size attribute, e.g. `pa_peso`.
	 * @param int[]  $box_categories Gift box product categories, which never get these fields.
	 */
	public function __construct(
		private string $attribute = 'pa_peso',
		private array $box_categories = array()
	) {}

	public function register(): void {
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'render' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save' ), 10, 2 );
		\Galaxie\Woo\Support\GiftPacking::watch_sizes();
	}

	/**
	 * The three fields, inside one variation.
	 *
	 * @param int      $loop           Variation index in the panel.
	 * @param array    $variation_data Unused.
	 * @param \WP_Post $variation      Variation post.
	 */
	public function render( $loop, $variation_data, $variation ): void {
		if ( ! $variation instanceof \WP_Post || ! $this->applies( (int) $variation->post_parent ) ) {
			return;
		}

		$loop = (int) $loop;
		$id   = (int) $variation->ID;

		echo '<div class="galaxie-gift-candle-fields" style="clear:both;border-top:1px solid #eee;padding-top:8px">';
		echo '<p class="form-row form-row-full" style="margin-bottom:0"><strong>' . esc_html__( 'Medidas para embalagem de presente (cm)', 'galaxie-woo' ) . '</strong><br><em>' . esc_html__( 'Só o pote, sem a caixinha de envio. Em branco usa as dimensões de envio.', 'galaxie-woo' ) . '</em></p>';

		wp_nonce_field( self::NONCE, self::NONCE_FIELD, false );

		$fields = array(
			'length' => array( __( 'Comprimento (cm)', 'galaxie-woo' ), 'form-row-first' ),
			'width'  => array( __( 'Largura (cm)', 'galaxie-woo' ), 'form-row-last' ),
			'height' => array( __( 'Altura (cm)', 'galaxie-woo' ), 'form-row-first' ),
		);

		foreach ( $fields as $field => $spec ) {
			$value = (float) get_post_meta( $id, self::META[ $field ], true );

			woocommerce_wp_text_input(
				array(
					'id'                => self::META[ $field ] . $loop,
					'name'              => self::META[ $field ] . '[' . $loop . ']',
					'value'             => $value > 0 ? wc_format_localized_decimal( $value ) : '',
					'label'             => $spec[0],
					'type'              => 'number',
					'wrapper_class'     => 'form-row ' . $spec[1],
					'custom_attributes' => array(
						'step' => '0.01',
						'min'  => '0',
					),
				)
			);
		}

		echo '</div>';
	}

	/**
	 * Saves the fields of one variation (AJAX "Save changes" and the product
	 * form alike). WooCommerce checks its own nonce first; ours makes sure the
	 * values came from these fields.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $i            Variation index in the submitted arrays.
	 */
	public function save( $variation_id, $i ): void {
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE ) || ! current_user_can( 'edit_product', (int) $variation_id ) ) {
			return;
		}

		$variation_id = (int) $variation_id;
		$i            = (int) $i;

		foreach ( self::META as $key ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wc_clean below.
			if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) || ! array_key_exists( $i, $_POST[ $key ] ) ) {
				continue;
			}

			$raw   = wc_clean( wp_unslash( $_POST[ $key ][ $i ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$value = (float) wc_format_decimal( is_string( $raw ) ? $raw : '' );

			$value > 0 ? update_post_meta( $variation_id, $key, wc_format_decimal( $value ) ) : delete_post_meta( $variation_id, $key );
		}
	}

	/**
	 * Whether the fields belong on this product: variable, with the size
	 * attribute, and not in a gift box category.
	 *
	 * @param int $product_id Parent product ID.
	 */
	private function applies( int $product_id ): bool {
		if ( $this->box_categories && has_term( array_map( 'intval', $this->box_categories ), 'product_cat', $product_id ) ) {
			return false;
		}

		$product = wc_get_product( $product_id );

		return $product && $product->is_type( 'variable' ) && array_key_exists( $this->attribute, $product->get_attributes() );
	}
}
