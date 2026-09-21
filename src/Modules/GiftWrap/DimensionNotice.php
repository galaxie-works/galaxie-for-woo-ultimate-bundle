<?php
/**
 * Candles that cannot go in a gift because nothing says how big they are.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\GiftWrap;

use Galaxie\Woo\Support\GiftPacking;

defined( 'ABSPATH' ) || exit;

/**
 * An admin notice listing candle variations — variations with the candle size
 * attribute — that have neither gift dimensions ("Medidas para embalagem de
 * presente", their own or their size term's) nor WooCommerce dimensions, with a
 * link to edit each product.
 *
 * The gift builder refuses such a candle ("Não foi possível calcular a
 * embalagem deste produto"), so this is where the merchant finds out before a
 * shopper does:
 * - on the Gift Wrap settings tab, for the whole store;
 * - on a product's edit screen, for that product's variations.
 *
 * It only reads; nothing is changed on the products.
 */
final class DimensionNotice {

	/** Rows listed before "and N more". */
	private const LIMIT = 50;

	/** Variations read at most, on the settings tab. */
	private const SCAN = 500;

	public static function hooks(): void {
		if ( is_admin() ) {
			add_action( 'admin_notices', array( self::class, 'render' ) );
		}
	}

	public static function render(): void {
		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only: which screen this is.
		$page       = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab        = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$product_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$on_settings = 'galaxie-woo' === $page && Module::ID === $tab;
		$on_product  = $screen && 'product' === $screen->id && $product_id > 0;

		if ( ! $on_settings && ! $on_product ) {
			return;
		}

		$missing = self::missing( Module::size_attribute(), $on_product ? $product_id : 0 );

		if ( ! $missing ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Gift Wrap', 'galaxie-woo' ) . ':</strong> '
			. esc_html__( 'these variations have no gift dimensions (their own or their size\'s) and no WooCommerce dimensions, so they cannot be packed in a gift. The gift builder refuses them until dimensions are filled in: once per size in Products → Attributes → edit the size, or on the variation (Products → edit → Variations).', 'galaxie-woo' )
			. '</p><ul style="list-style:disc;margin-left:1.5em">';

		foreach ( array_slice( $missing, 0, self::LIMIT ) as $variation ) {
			$link = get_edit_post_link( $variation->get_parent_id() );

			printf(
				'<li>%1$s — <a href="%2$s">%3$s</a></li>',
				esc_html( wp_strip_all_tags( $variation->get_name() ) ),
				esc_url( (string) $link ),
				esc_html__( 'Edit', 'galaxie-woo' )
			);
		}

		if ( count( $missing ) > self::LIMIT ) {
			/* translators: %d: how many more variations are missing dimensions. */
			printf( '<li>%s</li>', esc_html( sprintf( __( 'and %d more', 'galaxie-woo' ), count( $missing ) - self::LIMIT ) ) );
		}

		echo '</ul></div>';
	}

	/**
	 * Candle variations the packing engine cannot size, of one product or all.
	 *
	 * @param string $attribute Candle size attribute, e.g. `pa_peso`.
	 * @param int    $parent    Product id, or 0 for every product not in the trash.
	 * @return \WC_Product[]
	 */
	public static function missing( string $attribute, int $parent = 0 ): array {
		if ( '' === $attribute ) {
			return array();
		}

		$args = array(
			'post_type'      => 'product_variation',
			'post_status'    => array( 'publish', 'private' ),
			'posts_per_page' => self::SCAN,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => 'attribute_' . $attribute,
					'compare' => 'EXISTS',
				),
			),
		);

		if ( $parent > 0 ) {
			$args['post_parent'] = $parent;
		}

		$missing = array();

		foreach ( get_posts( $args ) as $id ) {
			$variation = wc_get_product( $id );

			if ( ! $variation instanceof \WC_Product || 'trash' === get_post_status( $variation->get_parent_id() ) ) {
				continue;
			}

			if ( ! GiftPacking::candle_from_product( $variation, $attribute ) ) {
				$missing[] = $variation;
			}
		}

		return $missing;
	}
}
