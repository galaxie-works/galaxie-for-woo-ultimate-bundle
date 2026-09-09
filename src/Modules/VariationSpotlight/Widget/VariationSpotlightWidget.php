<?php
/**
 * "Galaxie Variation Spotlight" Elementor widget — showcases one fixed variation.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\VariationSpotlight\Widget;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Plain server-rendered widget — no React island. The admin picks ONE
 * variation, by id, from a dropdown built from every variation of every
 * published variable product (label: "Product — Peso: 190g", via WooCommerce's
 * own {@see wc_get_formatted_variation()}). Rendering is a static snapshot of
 * that variation (image/price/label); the buy button either links to the real
 * product page with the attribute pre-selected via query string — WooCommerce
 * natively reads `?attribute_xxx=` on page load, no code needed on our side —
 * or adds straight to cart through this module's own AJAX endpoint.
 */
final class VariationSpotlightWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-variation-spotlight';
	}

	public function get_title(): string {
		return __( 'Galaxie Variation Spotlight', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-product-images';
	}

	public function get_categories(): array {
		return array( 'galaxie' );
	}

	protected function register_controls(): void {
		$this->start_controls_section(
			'content_section',
			array(
				'label' => __( 'Content', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'variation_id',
			array(
				'label'   => __( 'Variation', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT2,
				'options' => $this->variation_options(),
				'default' => '',
			)
		);

		$this->add_control(
			'show_price',
			array(
				'label'        => __( 'Show price', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'label_on'     => __( 'Yes', 'galaxie-woo' ),
				'label_off'    => __( 'No', 'galaxie-woo' ),
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'button_behavior',
			array(
				'label'   => __( 'Buy button', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'link',
				'options' => array(
					'link' => __( 'Go to product page (pre-selected)', 'galaxie-woo' ),
					'ajax' => __( 'Add straight to cart', 'galaxie-woo' ),
				),
			)
		);

		$this->add_control(
			'button_text',
			array(
				'label'   => __( 'Button text', 'galaxie-woo' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Comprar', 'galaxie-woo' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Every variation of every published variable product, as id => label.
	 * Rebuilt whenever the Elementor editor panel loads — no AJAX needed at
	 * this catalog size (see project memory for the tradeoff if it grows).
	 *
	 * @return array<int,string>
	 */
	private function variation_options(): array {
		$options = array( '' => __( '— Select a variation —', 'galaxie-woo' ) );

		$products = wc_get_products(
			array(
				'type'   => 'variable',
				'status' => 'publish',
				'limit'  => -1,
			)
		);

		foreach ( $products as $product ) {
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( ! $variation ) {
					continue;
				}
				$options[ $variation_id ] = sprintf(
					'%s — %s',
					$product->get_name(),
					wc_get_formatted_variation( $variation, true )
				);
			}
		}

		return $options;
	}

	protected function render(): void {
		$settings     = $this->get_settings_for_display();
		$variation_id = absint( $settings['variation_id'] ?? 0 );

		if ( ! $variation_id ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div style="padding:2rem;text-align:center;border:1px dashed #ccc;border-radius:8px;">';
				esc_html_e( 'Galaxie Variation Spotlight — select a variation in the widget settings.', 'galaxie-woo' );
				echo '</div>';
			}
			return;
		}

		$variation = wc_get_product( $variation_id );
		if ( ! $variation || 'variation' !== $variation->get_type() ) {
			return;
		}

		$parent = wc_get_product( $variation->get_parent_id() );
		if ( ! $parent ) {
			return;
		}

		$label       = wc_get_formatted_variation( $variation, true );
		$button_text = (string) ( $settings['button_text'] ?? __( 'Comprar', 'galaxie-woo' ) );

		echo '<div class="galaxie-ui galaxie-spotlight">';

		echo '<div class="galaxie-spotlight-image">' . wp_kses_post( $variation->get_image( 'woocommerce_single' ) ) . '</div>';

		echo '<div class="galaxie-spotlight-body">';
		echo '<p class="galaxie-spotlight-name">' . esc_html( $parent->get_name() ) . '</p>';
		echo '<p class="galaxie-spotlight-variation">' . esc_html( $label ) . '</p>';

		if ( 'yes' === ( $settings['show_price'] ?? 'yes' ) ) {
			echo '<p class="galaxie-spotlight-price">' . wp_kses_post( $variation->get_price_html() ) . '</p>';
		}

		if ( 'ajax' === ( $settings['button_behavior'] ?? 'link' ) ) {
			printf(
				'<button type="button" class="galaxie-spotlight-add" data-variation-id="%d">%s</button>',
				$variation_id,
				esc_html( $button_text )
			);
		} else {
			$url = add_query_arg( $variation->get_variation_attributes(), $parent->get_permalink() );
			printf(
				'<a class="galaxie-spotlight-add" href="%s">%s</a>',
				esc_url( $url ),
				esc_html( $button_text )
			);
		}

		echo '</div>'; // .galaxie-spotlight-body
		echo '</div>'; // .galaxie-spotlight
	}
}
