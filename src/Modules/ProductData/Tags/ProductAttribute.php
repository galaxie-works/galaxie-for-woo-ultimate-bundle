<?php
/**
 * Product attribute as text — no links, a separator that survives, and it
 * follows the chosen variation.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\ProductData\Tags;

use Elementor\Controls_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Why this exists next to Elementor Pro's own "Product Terms".
 *
 * Pro's tag is one line:
 *
 *     $value = get_the_term_list( $product->get_id(), $taxonomy, '', $separator );
 *
 * `get_the_term_list()` is WordPress core and ALWAYS wraps each term in an
 * `<a>`. That is not a setting, and it produces three problems on a spec table:
 *
 * 1. Every value is a link the merchant never asked for.
 * 2. Those links go nowhere useful. An attribute with "Enable archives" off is
 *    registered with `query_var` and `rewrite` false, so the URL core builds —
 *    `?taxonomy=pa_peso&term=190g` — is not recognised and WordPress serves the
 *    front page. Measured: HTTP 200, title "Eir Naturals". Crawlable links to
 *    duplicate content.
 * 3. The separator arrives correct in the HTML (`</a>, <a`) and then loses its
 *    space, because a flex or grid parent turns ", " into an anonymous item
 *    whose edge whitespace is trimmed.
 *
 * Here the separator is written between values in one text node, so no layout
 * can eat it, and nothing is linked.
 */
final class ProductAttribute extends BaseTag {

	public function get_name(): string {
		return 'galaxie-product-attribute';
	}

	public function get_title(): string {
		return __( 'Product Attribute', 'galaxie-woo' );
	}

	protected function register_controls(): void {
		$this->add_control(
			'attribute',
			array(
				'label'   => __( 'Attribute', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => self::attribute_options(),
				'default' => '',
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
			'show_label',
			array(
				'label'        => __( 'Prefix with attribute name', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'label_separator',
			array(
				'label'     => __( 'After the name', 'galaxie-woo' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => ': ',
				'condition' => array( 'show_label' => 'yes' ),
			)
		);

		$this->add_follow_control();
	}

	/**
	 * Every global attribute, read from WooCommerce's own registry so a
	 * merchant adding one tomorrow finds it here without a release.
	 *
	 * @return array<string,string>
	 */
	private static function attribute_options(): array {
		$options = array( '' => __( '— Select —', 'galaxie-woo' ) );

		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return $options;
		}

		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$name = wc_attribute_taxonomy_name( $attribute->attribute_name );

			if ( $name ) {
				$options[ $name ] = $attribute->attribute_label ?: $attribute->attribute_name;
			}
		}

		return $options;
	}

	public function render(): void {
		$product   = $this->product();
		$taxonomy  = (string) $this->get_settings( 'attribute' );
		$separator = (string) $this->get_settings( 'separator' );

		if ( ! $product || ! $taxonomy ) {
			return;
		}

		$names = wc_get_product_terms( $product->get_id(), $taxonomy, array( 'fields' => 'names' ) );

		if ( empty( $names ) ) {
			return;
		}

		$value = implode( $separator, array_map( 'wp_strip_all_tags', $names ) );

		if ( 'yes' === $this->get_settings( 'show_label' ) ) {
			$label = wc_attribute_label( $taxonomy, $product );
			$value = $label . (string) $this->get_settings( 'label_separator' ) . $value;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wrap() escapes its own attributes and $value is escaped here.
		echo $this->wrap(
			esc_html( $value ),
			'attribute',
			array(
				'attribute' => $taxonomy,
				'prefix'    => 'yes' === $this->get_settings( 'show_label' )
					? wc_attribute_label( $taxonomy, $product ) . (string) $this->get_settings( 'label_separator' )
					: '',
			)
		);
	}
}
