<?php
/**
 * Product data module — makes WooCommerce product data reachable from Elementor.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\ProductData;

use Galaxie\Woo\Core\Module as ModuleContract;

defined( 'ABSPATH' ) || exit;

/**
 * Product attributes exist as taxonomies, and Elementor already knows how to
 * render a taxonomy — through Pro's own "Post Terms" and "Product Terms"
 * dynamic tags. They simply never appear in either tag's Taxonomy dropdown,
 * which is why a merchant building a spec table has nothing to pick.
 *
 * Both tags build that dropdown the same way:
 *
 *     get_taxonomies( array( 'show_in_nav_menus' => true, 'object_type' => array( 'product' ) ) )
 *
 * and WooCommerce registers every `pa_*` taxonomy like this
 * (class-wc-post-types.php):
 *
 *     'show_in_nav_menus' => 1 === $tax->attribute_public
 *         && apply_filters( 'woocommerce_attribute_show_in_nav_menus', false, $name ),
 *
 * So it is false twice over. Note the `&&`: on a store whose attributes have
 * "Enable archives" off — `attribute_public` is 0, which is every attribute on
 * this store — the expression short-circuits and WooCommerce's own filter never
 * even runs. Filtering `woocommerce_attribute_show_in_nav_menus` therefore does
 * nothing at all, which is the trap in every snippet written about this.
 *
 * The lever that does work is `woocommerce_taxonomy_args_{$name}`, which
 * replaces the whole argument array after that expression is evaluated.
 *
 * What this deliberately does NOT change is `public`, so attributes still get
 * no archive pages and no public URLs. The only visible side effect is that
 * they become available under Appearance → Menus, which is what
 * `show_in_nav_menus` means to WordPress.
 */
final class Module implements ModuleContract {

	public function id(): string {
		return 'product-data';
	}

	public function title(): string {
		return __( 'Product Data for Elementor', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Exposes product attributes to Elementor dynamic tags, so a spec table can be built without hardcoding values.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return true;
	}

	/**
	 * Priority 4 on `init`, because WooCommerce registers its taxonomies at 5
	 * and a filter added after `register_taxonomy()` has run is a filter that
	 * never fires.
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'expose_attribute_taxonomies' ), 4 );
	}

	/**
	 * One filter per attribute, added before WooCommerce registers any of them.
	 *
	 * The taxonomy names cannot be known statically — a merchant can add an
	 * attribute at any time — so they are read from WooCommerce's own registry,
	 * which is cached in a transient and costs nothing on a warm request.
	 */
	public function expose_attribute_taxonomies(): void {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return;
		}

		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$name = wc_attribute_taxonomy_name( $attribute->attribute_name );

			if ( ! $name ) {
				continue;
			}

			add_filter( "woocommerce_taxonomy_args_{$name}", array( $this, 'show_in_nav_menus' ) );
		}
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function show_in_nav_menus( $args ): array {
		$args = is_array( $args ) ? $args : array();

		$args['show_in_nav_menus'] = true;

		return $args;
	}
}
