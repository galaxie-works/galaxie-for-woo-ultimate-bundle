<?php
/**
 * "Galaxie Products Carousel": pixfort's carousel, showing the right products.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\ProductsCarousel\Widget;

use Elementor\Controls_Manager;
use Galaxie\Woo\Modules\ProductsCarousel\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Inherits every control, style and the rendering of pixfort's widget, and
 * adds two things around that rendering.
 *
 * - Which products. pixfort builds a `WC_Product_Query` with no hook of its own,
 *   so the filter goes where every product query passes:
 *   `woocommerce_product_data_store_cpt_get_products_query`. It is attached for
 *   the length of this widget's render and applies to its first query only.
 * - Button texts. The "Select options" on a product card is WooCommerce's
 *   `add_to_cart_text()`, filtered the same way, for this widget only.
 *
 * Deliberately a subclass, not a copy: pixfort's fixes and new options arrive
 * with its updates. Where the carousel should become ours, a method here
 * replaces theirs.
 */
final class ProductsCarouselWidget extends \Elementor\Pix_Eor_Products_Carousel {

	/** @var array<string,mixed>|null The tax clause and exclusion for the query being filtered. */
	private static ?array $query = null;

	/** @var array<string,string> Button texts for the render in progress. */
	private static array $texts = array();

	public function get_name() {
		return 'galaxie-products-carousel';
	}

	public function get_title() {
		return __( 'Galaxie Products Carousel', 'galaxie-woo' );
	}

	public function get_categories() {
		return array( 'galaxie' );
	}

	public function get_keywords() {
		return array( 'products', 'carousel', 'slider', 'brand', 'marca', 'woocommerce' );
	}

	public function get_script_depends() {
		$depends = (array) parent::get_script_depends();

		if ( is_user_logged_in() ) {
			$depends[] = Module::EDITOR_HANDLE;
		}

		return $depends;
	}

	protected function register_controls() {
		parent::register_controls();

		// Our controls sit at the top of pixfort's General section, before its
		// own Count and Category: the source decides what those apply to.
		$this->start_injection( array( 'type' => 'control', 'at' => 'before', 'of' => 'count' ) );

		$this->add_control(
			'gx_source',
			array(
				'label'       => __( 'Products from', 'galaxie-woo' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => array(
					''              => __( 'Every product', 'galaxie-woo' ),
					'archive'       => __( 'The brand, category or tag being viewed', 'galaxie-woo' ),
					'product_brand' => __( 'The current product\'s brand', 'galaxie-woo' ),
					'brands'        => __( 'Chosen brands', 'galaxie-woo' ),
				),
				'default'     => '',
				'separator'   => 'before',
				'description' => __( 'On an archive template, the editor previews the archive picked under Preview Settings.', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'gx_brands',
			array(
				'label'       => __( 'Brands', 'galaxie-woo' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'options'     => self::brand_options(),
				'condition'   => array( 'gx_source' => 'brands' ),
			)
		);

		$this->add_control(
			'gx_exclude_current',
			array(
				'label'        => __( 'Leave out the product being viewed', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'condition'    => array( 'gx_source' => 'product_brand' ),
			)
		);

		$this->add_control(
			'gx_without_context',
			array(
				'label'     => __( 'Where there is nothing to filter by', 'galaxie-woo' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => array(
					'all'  => __( 'Show every product', 'galaxie-woo' ),
					'hide' => __( 'Show nothing', 'galaxie-woo' ),
				),
				'default'   => 'all',
				'condition' => array( 'gx_source' => array( 'archive', 'product_brand' ) ),
				'separator' => 'after',
			)
		);

		$this->end_injection();

		$this->start_controls_section( 'gx_buttons_section', array( 'label' => __( 'Button texts', 'galaxie-woo' ) ) );

		$this->add_control(
			'gx_buttons_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => __( 'The button on each card, when the theme shows it. Leave a field empty to keep WooCommerce\'s text.', 'galaxie-woo' ),
				'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
			)
		);

		$this->add_control( 'gx_text_simple', array( 'label' => __( 'Product that goes straight to the cart', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'placeholder' => __( 'Add to cart', 'woocommerce' ) ) );
		$this->add_control( 'gx_text_variable', array( 'label' => __( 'Product with options to choose', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'placeholder' => __( 'Select options', 'woocommerce' ) ) );
		$this->add_control( 'gx_text_unavailable', array( 'label' => __( 'Out of stock or not for sale', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'placeholder' => __( 'Read more', 'woocommerce' ) ) );

		$this->end_controls_section();
	}

	/** @return array<int,string> */
	private static function brand_options(): array {
		if ( ! taxonomy_exists( 'product_brand' ) ) {
			return array();
		}

		$terms = get_terms( array( 'taxonomy' => 'product_brand', 'hide_empty' => false ) );

		return is_array( $terms ) ? wp_list_pluck( $terms, 'name', 'term_id' ) : array();
	}

	/** Taxonomies a product archive can be for. */
	private static function product_taxonomies(): array {
		return get_object_taxonomies( 'product' );
	}

	/**
	 * The filter this render needs: a tax clause and products to leave out, or
	 * null for no filter, or false for "show nothing".
	 *
	 * @param array<string,mixed> $s
	 * @return array{clause:array<string,mixed>,exclude:int[]}|null|false
	 */
	private function filter_for( array $s ) {
		$source = (string) ( $s['gx_source'] ?? '' );

		if ( 'brands' === $source ) {
			$ids = array_filter( array_map( 'absint', (array) ( $s['gx_brands'] ?? array() ) ) );

			return $ids ? array( 'clause' => array( 'taxonomy' => 'product_brand', 'field' => 'term_id', 'terms' => $ids ), 'exclude' => array() ) : null;
		}

		$found = null;

		if ( 'archive' === $source ) {
			$term = get_queried_object();

			if ( $term instanceof \WP_Term && in_array( $term->taxonomy, self::product_taxonomies(), true ) ) {
				$found = array( 'clause' => array( 'taxonomy' => $term->taxonomy, 'field' => 'term_id', 'terms' => array( (int) $term->term_id ) ), 'exclude' => array() );
			}
		} elseif ( 'product_brand' === $source && taxonomy_exists( 'product_brand' ) ) {
			$product_id = is_singular( 'product' ) ? (int) get_queried_object_id() : 0;

			if ( ! $product_id && isset( $GLOBALS['product'] ) && $GLOBALS['product'] instanceof \WC_Product ) {
				$product_id = $GLOBALS['product']->get_id();
			}

			$brands = $product_id ? wp_get_post_terms( $product_id, 'product_brand', array( 'fields' => 'ids' ) ) : array();

			if ( is_array( $brands ) && $brands ) {
				$found = array(
					'clause'  => array( 'taxonomy' => 'product_brand', 'field' => 'term_id', 'terms' => array_map( 'intval', $brands ) ),
					'exclude' => 'yes' === ( $s['gx_exclude_current'] ?? 'yes' ) ? array( $product_id ) : array(),
				);
			}
		} else {
			return null;
		}

		if ( $found ) {
			return $found;
		}

		// Nothing to filter by. In the editor that is normal (a template is not
		// an archive), so the designer sees products rather than an empty box.
		$editing = class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance->editor && \Elementor\Plugin::$instance->editor->is_edit_mode();

		return 'hide' === ( $s['gx_without_context'] ?? 'all' ) && ! $editing ? false : null;
	}

	protected function render() {
		$s      = $this->get_settings_for_display();
		$filter = $this->filter_for( $s );

		if ( false === $filter ) {
			return;
		}

		self::$query = $filter;
		self::$texts = array_filter(
			array(
				'simple'      => trim( (string) ( $s['gx_text_simple'] ?? '' ) ),
				'variable'    => trim( (string) ( $s['gx_text_variable'] ?? '' ) ),
				'unavailable' => trim( (string) ( $s['gx_text_unavailable'] ?? '' ) ),
			)
		);

		add_filter( 'woocommerce_product_data_store_cpt_get_products_query', array( self::class, 'filter_query' ), 10, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( self::class, 'filter_button_text' ), 20, 2 );

		try {
			parent::render();
		} finally {
			remove_filter( 'woocommerce_product_data_store_cpt_get_products_query', array( self::class, 'filter_query' ), 10 );
			remove_filter( 'woocommerce_product_add_to_cart_text', array( self::class, 'filter_button_text' ), 20 );
			self::$query = null;
			self::$texts = array();
		}
	}

	/**
	 * Narrows pixfort's product query, once: the first product query of the
	 * render is the carousel's, and anything a product card might query after
	 * it is left alone.
	 *
	 * @param array<string,mixed> $wp_query_args
	 * @param array<string,mixed> $query_vars
	 * @return array<string,mixed>
	 */
	public static function filter_query( $wp_query_args, $query_vars ) {
		if ( ! is_array( self::$query ) ) {
			return $wp_query_args;
		}

		$filter      = self::$query;
		self::$query = null;

		$tax_query   = isset( $wp_query_args['tax_query'] ) && is_array( $wp_query_args['tax_query'] ) ? $wp_query_args['tax_query'] : array();
		$tax_query[] = $filter['clause'];

		$wp_query_args['tax_query'] = $tax_query;

		if ( $filter['exclude'] ) {
			$wp_query_args['post__not_in'] = array_merge( (array) ( $wp_query_args['post__not_in'] ?? array() ), $filter['exclude'] );
		}

		return $wp_query_args;
	}

	/**
	 * @param string      $text
	 * @param \WC_Product $product
	 */
	public static function filter_button_text( $text, $product ) {
		if ( ! self::$texts || ! $product instanceof \WC_Product ) {
			return $text;
		}

		$available = $product->is_purchasable() && $product->is_in_stock();

		if ( $product->is_type( 'variable' ) ) {
			$key = $product->is_purchasable() ? 'variable' : 'unavailable';
		} elseif ( $product->is_type( 'simple' ) ) {
			$key = $available ? 'simple' : 'unavailable';
		} else {
			return $text;
		}

		return self::$texts[ $key ] ?? $text;
	}
}
