<?php
/**
 * Gift box sizes on the variation panel.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\GiftWrap;

use Galaxie\Woo\Support\GiftPacking;

defined( 'ABSPATH' ) || exit;

/**
 * Internal length / width / height and an optional candle limit on each gift
 * box variation (Products → edit → Variations), with a read-only "Cabe:" line
 * under them worked out from the candle sizes the store actually sells.
 *
 * Box dimensions are the INSIDE of the box, in cm, whatever unit WooCommerce
 * uses for shipping — they are not the shipping dimensions, which stay free for
 * the outside. The preview is computed when the panel loads, so it follows a
 * change after the variations are saved.
 *
 * Self-contained: the Gift Wrap module boots it with its settings.
 */
final class BoxFields {

	/** Variation meta, by field. */
	public const META = array(
		'length' => '_galaxie_box_length',
		'width'  => '_galaxie_box_width',
		'height' => '_galaxie_box_height',
		'max'      => '_galaxie_box_max',
		'overflow' => '_galaxie_box_overflow',
	);

	/** Most extra height a lid can be trusted to close over, cm. */
	public const OVERFLOW_MAX = 2.0;

	private const NONCE = 'galaxie_gift_box_fields';

	private const NONCE_FIELD = 'galaxie_gift_box_nonce';

	/**
	 * @param string    $attribute   Candle size attribute, e.g. `pa_peso`.
	 * @param float     $gap         Packing gap in cm (default 0: tissue paper fills).
	 * @param bool      $stacking    Stacking setting (accepted; the engine packs one layer).
	 * @param int[]     $categories  Product categories that are gift boxes; empty = every variable product.
	 * @param string    $orientation 'lying' (default, the jar on its side), 'upright' or 'any'.
	 */
	public function __construct(
		private string $attribute = 'pa_peso',
		private float $gap = 0.0,
		private bool $stacking = false,
		private array $categories = array(),
		private string $orientation = 'lying'
	) {
		if ( ! in_array( $this->orientation, array( 'upright', 'lying', 'any' ), true ) ) {
			$this->orientation = 'lying';
		}
	}

	public function register(): void {
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'render' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * The fields and the preview, inside one variation.
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

		echo '<div class="galaxie-gift-box-fields" style="clear:both;border-top:1px solid #eee;padding-top:8px">';
		echo '<p class="form-row form-row-full" style="margin-bottom:0"><strong>' . esc_html__( 'Gift box', 'galaxie-woo' ) . '</strong> — ' . esc_html__( 'INTERNAL (usable) dimensions in cm: the space the candles actually get, used to work out which candles fit. Leave empty if this is not a box.', 'galaxie-woo' ) . '<br><em>' . esc_html__( 'Measuring the outside? Subtract the MDF/cardboard thickness: twice the wall for length and width, and the base and lid for height (e.g. 3 mm MDF: 14.2 cm outside = 13.6 cm inside).', 'galaxie-woo' ) . '</em></p>';

		wp_nonce_field( self::NONCE, self::NONCE_FIELD, false );

		$fields = array(
			'length' => array( __( 'Internal (usable) length, cm', 'galaxie-woo' ), 'form-row-first' ),
			'width'  => array( __( 'Internal (usable) width, cm', 'galaxie-woo' ), 'form-row-last' ),
			'height' => array( __( 'Internal (usable) height, cm', 'galaxie-woo' ), 'form-row-first' ),
		);

		foreach ( $fields as $field => $spec ) {
			woocommerce_wp_text_input(
				array(
					'id'                => self::META[ $field ] . $loop,
					'name'              => self::META[ $field ] . '[' . $loop . ']',
					'value'             => (string) get_post_meta( $id, self::META[ $field ], true ),
					'label'             => $spec[0],
					'type'              => 'number',
					'wrapper_class'     => 'form-row ' . $spec[1],
					'custom_attributes' => array(
						'step' => '0.1',
						'min'  => '0',
					),
				)
			);
		}

		$max = absint( get_post_meta( $id, self::META['max'], true ) );

		woocommerce_wp_text_input(
			array(
				'id'                => self::META['max'] . $loop,
				'name'              => self::META['max'] . '[' . $loop . ']',
				'value'             => $max > 0 ? (string) $max : '',
				'label'             => __( 'Max candles (optional)', 'galaxie-woo' ),
				'type'              => 'number',
				'wrapper_class'     => 'form-row form-row-last',
				'desc_tip'          => true,
				'description'       => __( 'Leave empty for no limit beyond what physically fits.', 'galaxie-woo' ),
				'custom_attributes' => array(
					'step' => '1',
					'min'  => '0',
				),
			)
		);

		$overflow = (float) get_post_meta( $id, self::META['overflow'], true );

		woocommerce_wp_text_input(
			array(
				'id'                => self::META['overflow'] . $loop,
				'name'              => self::META['overflow'] . '[' . $loop . ']',
				'value'             => $overflow > 0 ? wc_format_localized_decimal( $overflow ) : '',
				'label'             => __( 'Altura extra permitida (cm)', 'galaxie-woo' ),
				'type'              => 'number',
				'wrapper_class'     => 'form-row form-row-first',
				'desc_tip'          => true,
				'description'       => __( 'How far candles may stand above the base while the lid still closes over them (0–2 cm). Added to the internal height.', 'galaxie-woo' ),
				'custom_attributes' => array(
					'step' => '0.1',
					'min'  => '0',
					'max'  => (string) self::OVERFLOW_MAX,
				),
			)
		);

		echo '<p class="form-row form-row-full galaxie-gift-box-fit" style="clear:both"><em>' . esc_html( $this->preview( $id ) ) . '</em></p>';
		echo '</div>';
	}

	/**
	 * Saves the fields of one variation. Runs for the AJAX "Save changes" and for
	 * the product form alike; WooCommerce checks its own nonce first, ours makes
	 * sure the values came from these fields.
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

		foreach ( self::META as $field => $key ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wc_clean below.
			if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) || ! array_key_exists( $i, $_POST[ $key ] ) ) {
				continue;
			}

			$raw = wc_clean( wp_unslash( $_POST[ $key ][ $i ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( 'max' === $field ) {
				$value = absint( $raw );
				$value > 0 ? update_post_meta( $variation_id, $key, $value ) : delete_post_meta( $variation_id, $key );
				continue;
			}

			if ( 'overflow' === $field ) {
				$value = min( self::OVERFLOW_MAX, max( 0.0, (float) wc_format_decimal( is_string( $raw ) ? $raw : '' ) ) );
				$value > 0 ? update_post_meta( $variation_id, $key, wc_format_decimal( $value ) ) : delete_post_meta( $variation_id, $key );
				continue;
			}

			$value = (float) wc_format_decimal( is_string( $raw ) ? $raw : '' );
			$value > 0 ? update_post_meta( $variation_id, $key, wc_format_decimal( $value ) ) : delete_post_meta( $variation_id, $key );
		}
	}

	/**
	 * "Cabe: 4 × 50g · 1 × 190g · 2 × 50g + 1 × 190g" for a saved variation.
	 *
	 * @param int $variation_id Variation ID.
	 */
	public function preview( int $variation_id ): string {
		$box = array(
			'id'     => $variation_id,
			'length' => (float) get_post_meta( $variation_id, self::META['length'], true ),
			'width'  => (float) get_post_meta( $variation_id, self::META['width'], true ),
			'height' => (float) get_post_meta( $variation_id, self::META['height'], true ),
			'max'      => absint( get_post_meta( $variation_id, self::META['max'], true ) ),
			'overflow' => min( self::OVERFLOW_MAX, max( 0.0, (float) get_post_meta( $variation_id, self::META['overflow'], true ) ) ),
			'price'    => 0,
		);

		if ( $box['length'] <= 0 || $box['width'] <= 0 || $box['height'] <= 0 ) {
			return __( 'Fill in the inside length, width and height and save to see which candles fit.', 'galaxie-woo' );
		}

		$sizes = GiftPacking::store_sizes( $this->attribute );

		if ( ! $sizes ) {
			/* translators: %s: attribute taxonomy, e.g. pa_peso */
			return sprintf( __( 'No candle variations with dimensions found for the size attribute %s.', 'galaxie-woo' ), $this->attribute );
		}

		$labels = array();
		foreach ( $sizes as $size ) {
			$labels[ $size['size'] ] = $size['label'];
		}

		$options = array(
			'gap'         => $this->gap,
			'stacking'    => $this->stacking,
			'orientation' => $this->orientation,
		);
		$rows    = array();

		foreach ( GiftPacking::summary( $box, $sizes, $options ) as $row ) {
			$parts = array();
			foreach ( $row['counts'] as $size => $count ) {
				$parts[] = $count . ' × ' . ( $labels[ $size ] ?? $size );
			}

			// The check stops at 12 candles; a bigger box is not "12".
			/* translators: %s: a fit such as "12 × 50g" that the box may exceed */
			$rows[] = $row['capped'] ? sprintf( __( '%s ou mais', 'galaxie-woo' ), implode( ' + ', $parts ) ) : implode( ' + ', $parts );
		}

		$ways = array(
			'lying'   => __( 'deitadas', 'galaxie-woo' ),
			'upright' => __( 'em pé', 'galaxie-woo' ),
			'any'     => __( 'em pé ou deitadas', 'galaxie-woo' ),
		);

		if ( ! $rows ) {
			/* translators: %s: how the candles sit, e.g. "deitadas" */
			return sprintf( __( 'Cabe: nenhuma vela (%s)', 'galaxie-woo' ), $ways[ $this->orientation ] );
		}

		/* translators: 1: fits, e.g. "4 × 50g · 1 × 190g", 2: how the candles sit, e.g. "deitadas", 3: gap in cm */
		return sprintf( __( 'Cabe: %1$s (%2$s, folga de %3$s cm)', 'galaxie-woo' ), implode( ' · ', $rows ), $ways[ $this->orientation ], wc_format_localized_decimal( $this->gap ) );
	}

	/**
	 * Whether the fields belong on this product.
	 *
	 * @param int $product_id Parent product ID.
	 */
	private function applies( int $product_id ): bool {
		if ( $this->categories ) {
			return has_term( array_map( 'intval', $this->categories ), 'product_cat', $product_id );
		}

		// No box categories chosen: every variable product except the candles
		// (those with the size attribute get CandleFields instead).
		$product = wc_get_product( $product_id );

		return $product && ! array_key_exists( $this->attribute, $product->get_attributes() );
	}
}
