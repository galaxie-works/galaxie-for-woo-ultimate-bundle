<?php
/**
 * Product terms as text — any product taxonomy, linked only when asked.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\ProductData\Tags;

use Elementor\Controls_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Why this exists next to Elementor Pro's own "Product Terms".
 *
 * Pro's tag renders in one line (modules/woocommerce/tags/product-terms.php):
 *
 *     $value = get_the_term_list( $product->get_id(), $settings['taxonomy'], '', $settings['separator'] );
 *     echo wp_kses_post( $value );
 *
 * `get_the_term_list()` is WordPress core and ALWAYS wraps every term in an
 * `<a href="...">`. Pro offers no switch to turn that off, while the tag
 * advertises itself as TEXT_CATEGORY — so Elementor offers it inside controls
 * that hold plain text: a heading, a button label, an image alt, a pixfort text
 * field. Those escape what they are handed, and the anchor arrives as visible
 * markup, which is how the whole href ends up printed on the page. It depends on
 * where the tag was dropped, not on which taxonomy was picked.
 *
 * Its Taxonomy dropdown is also narrower than the store, because it asks for
 *
 *     get_taxonomies( array( 'show_in_nav_menus' => true, 'object_type' => array( 'product' ) ) )
 *
 * and anything registered without `show_in_nav_menus` is simply absent. Here the
 * list is every taxonomy actually attached to products.
 *
 * Linking is opt-in, and refused where it would be a lie: a taxonomy registered
 * with `public` false has no archive, and the URL core still builds for it
 * (`?taxonomy=pa_peso&term=190g`) is not recognised — WordPress answers 200 with
 * the front page. A link to that is worse than no link.
 */
final class ProductTerms extends BaseTag {

	public function get_name(): string {
		return 'galaxie-product-terms';
	}

	public function get_title(): string {
		return __( 'Product Terms', 'galaxie-woo' );
	}

	protected function register_controls(): void {
		$this->add_control(
			'taxonomy',
			array(
				'label'   => __( 'Taxonomy', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => self::taxonomy_options(),
				'default' => 'product_cat',
			)
		);

		$this->add_control(
			'separator',
			array(
				'label'   => __( 'Separator', 'galaxie-woo' ),
				'type'    => Controls_Manager::TEXT,
				'default' => ', ',
			)
		);

		$this->add_control(
			'link',
			array(
				'label'        => __( 'Link each term to its archive', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => 'yes',
				'description'  => __( 'Leave this off wherever the value lands in plain text — a heading, a button label, an alt text — or the link markup shows up as visible text. Ignored for taxonomies that have no archive page of their own.', 'galaxie-woo' ),
			)
		);
	}

	/**
	 * Every taxonomy attached to products, not only the ones WordPress would
	 * offer in a nav menu.
	 *
	 * `product_visibility` carries WooCommerce's catalog flags and is registered
	 * with neither UI nor archive, so it drops out on its own rather than by
	 * name — a store adding its own hidden taxonomy tomorrow is treated the same.
	 *
	 * @return array<string,string>
	 */
	private static function taxonomy_options(): array {
		$options = array();

		foreach ( get_object_taxonomies( 'product', 'objects' ) as $taxonomy ) {
			if ( empty( $taxonomy->show_ui ) && empty( $taxonomy->public ) ) {
				continue;
			}

			$options[ $taxonomy->name ] = $taxonomy->label ? $taxonomy->label : $taxonomy->name;
		}

		asort( $options );

		return $options;
	}

	/**
	 * Whether a term of this taxonomy leads anywhere.
	 *
	 * WooCommerce registers an attribute whose "Enable archives" is off with
	 * `public`, `query_var` and `rewrite` all false. Measured on this store: the
	 * URL core builds for such a term answers HTTP 200 with the front page.
	 */
	private static function has_archive( string $taxonomy ): bool {
		$object = get_taxonomy( $taxonomy );

		return $object && ! empty( $object->public );
	}

	private static function linked( \WP_Term $term ): string {
		$url = get_term_link( $term );

		if ( is_wp_error( $url ) ) {
			return esc_html( $term->name );
		}

		return '<a href="' . esc_url( $url ) . '">' . esc_html( $term->name ) . '</a>';
	}

	public function render(): void {
		$product  = $this->product();
		$taxonomy = (string) $this->get_settings( 'taxonomy' );

		if ( ! $product || ! $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$terms = wp_get_post_terms( $product->get_id(), $taxonomy );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}

		$linked = 'yes' === $this->get_settings( 'link' ) && self::has_archive( $taxonomy );
		$values = array();

		foreach ( $terms as $term ) {
			$values[] = $linked ? self::linked( $term ) : esc_html( $term->name );
		}

		// The separator is written between values in one text node, so a flex or
		// grid parent cannot trim its spaces the way it does with ", " sitting
		// between two anchors.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every term is escaped above and so is the separator.
		echo implode( esc_html( (string) $this->get_settings( 'separator' ) ), $values );
	}
}
