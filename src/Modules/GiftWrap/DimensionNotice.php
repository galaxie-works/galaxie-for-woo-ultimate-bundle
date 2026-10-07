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
 * link to edit each product; and a second one listing the gift boxes and cards
 * on offer that Shipping Cartons cannot weigh or size for a quote.
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

		$missing     = self::missing( Module::size_attribute(), $on_product ? $product_id : 0 );
		$accessories = self::accessories( $on_product ? $product_id : 0 );

		self::render_accessories( $accessories );

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
	 * Boxes and cards on offer that the shipping quote cannot weigh or size.
	 *
	 * @param array<int, array{product:\WC_Product, role:string, missing:string[]}> $rows
	 */
	private static function render_accessories( array $rows ): void {
		if ( ! $rows ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Gift Wrap', 'galaxie-woo' ) . ':</strong> '
			. esc_html__( 'these gift boxes and cards are missing shipping data (Products → edit → Shipping, or the variation). Shipping Cartons packs every quote with them in it; a missing weight makes it give up and send the Melhor Envio plugin\'s own request. A box with no length, width or height is quoted at its inside measures plus 0.6 cm until they are filled in.', 'galaxie-woo' )
			. '</p><ul style="list-style:disc;margin-left:1.5em">';

		$labels = array(
			'weight' => __( 'weight', 'galaxie-woo' ),
			'length' => __( 'length', 'galaxie-woo' ),
			'width'  => __( 'width', 'galaxie-woo' ),
			'height' => __( 'height', 'galaxie-woo' ),
		);

		foreach ( array_slice( $rows, 0, self::LIMIT ) as $row ) {
			$product = $row['product'];
			$parent  = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

			printf(
				'<li>%1$s (%2$s): %3$s — <a href="%4$s">%5$s</a></li>',
				esc_html( wp_strip_all_tags( $product->get_name() ) ),
				esc_html( 'box' === $row['role'] ? __( 'box', 'galaxie-woo' ) : __( 'card', 'galaxie-woo' ) ),
				esc_html( implode( ', ', array_map( static fn( string $field ): string => $labels[ $field ] ?? $field, $row['missing'] ) ) ),
				esc_url( (string) get_edit_post_link( $parent ) ),
				esc_html__( 'Edit', 'galaxie-woo' )
			);
		}

		echo '</ul></div>';
	}

	/**
	 * The boxes and cards on offer ({@see Builder::offer()}) without what a
	 * shipping quote needs: a box its weight and outside size, a card (packed
	 * flat, by weight) its weight. Of one product, or all.
	 *
	 * @param int $parent Product id, or 0 for every accessory on offer.
	 * @return array<int, array{product:\WC_Product, role:string, missing:string[]}>
	 */
	public static function accessories( int $parent = 0 ): array {
		$offer = Builder::offer();
		$rows  = array();

		foreach ( array( 'box', 'card' ) as $role ) {
			foreach ( $offer[ $role ] ?? array() as $product ) {
				$owner = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

				if ( $parent > 0 && $owner !== $parent ) {
					continue;
				}

				$fields  = 'box' === $role ? array( 'weight', 'length', 'width', 'height' ) : array( 'weight' );
				$missing = array();

				foreach ( $fields as $field ) {
					// get_weight() and friends fall back to the parent's, as the quote does.
					if ( (float) $product->{'get_' . $field}() <= 0 ) {
						$missing[] = $field;
					}
				}

				if ( $missing ) {
					$rows[] = array(
						'product' => $product,
						'role'    => $role,
						'missing' => $missing,
					);
				}
			}
		}

		return $rows;
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
