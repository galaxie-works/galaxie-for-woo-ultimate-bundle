<?php
/**
 * "Galaxie Wishlist Button" Elementor widget — pixfort-native styled toggle.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Wishlist\Widget;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Galaxie\Woo\Modules\Wishlist\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the current product's wishlist toggle through pixfort's own Button
 * component — same approach (and the same `btn_*` attr mapping) as
 * {@see \Galaxie\Woo\Modules\VariationSwatches\Widget\VariationBadgesWidget},
 * so it inherits pixfort's global color dropdown (Primary, Gray 1-9, Dynamic
 * Colors — which is what makes it follow the theme's light/dark switch) and
 * pixfort's own icon picker, instead of a separate hardcoded palette.
 *
 * BOTH states are fully customizable and BOTH are rendered server-side, one
 * hidden by CSS — exactly the pattern already used for "Badge"/"Badge —
 * selecionado" in the variation widget. That's deliberate: a shopper's
 * "favoritado" heart usually wants a different icon AND a different color from
 * the empty one, and a single rendered button would leave JS rewriting
 * pixfort's markup by hand (fragile, and impossible to restyle from Elementor).
 * Toggling is therefore a one-line class flip in JS — see globals/wishlist.ts.
 */
final class WishlistButtonWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-wishlist-button';
	}

	public function get_title(): string {
		return __( 'Galaxie Wishlist Button', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-heart';
	}

	public function get_categories(): array {
		return array( 'galaxie' );
	}

	private function pixfort_active(): bool {
		return class_exists( '\PixfortCore' );
	}

	protected function register_controls(): void {
		$this->register_button_controls(
			'add',
			'add_section',
			__( 'Button — not saved', 'galaxie-woo' ),
			__( 'Adicionar aos favoritos', 'galaxie-woo' ),
			'primary',
			'outline'
		);
		$this->register_button_controls(
			'saved',
			'saved_section',
			__( 'Button — saved', 'galaxie-woo' ),
			__( 'Remover dos favoritos', 'galaxie-woo' ),
			'primary',
			'flat'
		);
	}

	/**
	 * Full pixfort Button parity for one state, mirroring
	 * {@see \Galaxie\Woo\Modules\VariationSwatches\Widget\VariationBadgesWidget::register_button_controls()}.
	 *
	 * Control ids are prefixed so the two states can coexist — the same reason
	 * pixfort's own shared `pix_get_elementor_btn()` helper can't be reused here
	 * (it hardcodes unprefixed ids, so it only works once per widget).
	 */
	private function register_button_controls( string $prefix, string $section_id, string $label, string $default_text, string $default_color, string $default_style ): void {
		$this->start_controls_section(
			$section_id,
			array( 'label' => $label )
		);

		$this->add_control(
			$prefix . '_text',
			array(
				'label'   => __( 'Text', 'galaxie-woo' ),
				'type'    => Controls_Manager::TEXT,
				'default' => $default_text,
			)
		);

		if ( $this->pixfort_active() ) {
			$this->add_control(
				$prefix . '_icon',
				array(
					'label'   => __( 'Icon', 'galaxie-woo' ),
					'type'    => \Elementor\CustomControl\PixfortIconSelector_Control::PixfortIconSelector,
					'default' => '',
				)
			);
			$this->add_control(
				$prefix . '_icon_position',
				array(
					'label'     => __( 'Icon position', 'galaxie-woo' ),
					'type'      => Controls_Manager::SELECT,
					'options'   => array(
						''      => __( 'Before text', 'galaxie-woo' ),
						'after' => __( 'After text', 'galaxie-woo' ),
					),
					'default'   => '',
					'condition' => array( $prefix . '_icon!' => '' ),
				)
			);
			$this->add_control(
				$prefix . '_style',
				array(
					'label'   => __( 'Button style', 'galaxie-woo' ),
					'type'    => Controls_Manager::SELECT,
					'options' => array(
						''          => __( 'Default', 'galaxie-woo' ),
						'flat'      => __( 'Flat', 'galaxie-woo' ),
						'line'      => __( 'Line', 'galaxie-woo' ),
						'outline'   => __( 'Outline', 'galaxie-woo' ),
						'underline' => __( 'Underline', 'galaxie-woo' ),
						'link'      => __( 'Link', 'galaxie-woo' ),
						'blink'     => __( 'Blink', 'galaxie-woo' ),
					),
					'default' => $default_style,
				)
			);
			$this->add_control(
				$prefix . '_color',
				array(
					'label'   => __( 'Button color', 'galaxie-woo' ),
					'type'    => Controls_Manager::SELECT,
					'groups'  => \PixfortCore::instance()->coreFunctions->getColorsArray( array( 'defaultColors' => false, 'mainLight' => true, 'custom' => false ) ),
					'default' => $default_color,
				)
			);
			$this->add_control(
				$prefix . '_text_color',
				array(
					'label'   => __( 'Text color', 'galaxie-woo' ),
					'type'    => Controls_Manager::SELECT,
					'groups'  => \PixfortCore::instance()->coreFunctions->getColorsArray( array( 'defaultValue' => array( '' => __( 'Default', 'galaxie-woo' ) ), 'mainLight' => true ) ),
					'default' => '',
				)
			);
		} else {
			$this->add_control(
				$prefix . '_fallback_color',
				array(
					'label'     => __( 'Button color', 'galaxie-woo' ),
					'type'      => Controls_Manager::COLOR,
					'selectors' => array( '{{WRAPPER}} .galaxie-wishlist-' . $prefix . ' .wishlist-btn' => 'background-color: {{VALUE}};' ),
				)
			);
		}

		$this->add_control(
			$prefix . '_size',
			array(
				'label'   => __( 'Button size', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					'sm'     => __( 'Small', 'galaxie-woo' ),
					'normal' => __( 'Normal', 'galaxie-woo' ),
					'md'     => __( 'Medium', 'galaxie-woo' ),
					'lg'     => __( 'Large', 'galaxie-woo' ),
					'xl'     => __( 'X-Large', 'galaxie-woo' ),
				),
				'default' => 'md',
			)
		);
		$this->add_control(
			$prefix . '_rounded',
			array(
				'label'        => __( 'Rounded corners', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'btn-rounded',
				'default'      => '',
			)
		);
		$this->add_control(
			$prefix . '_full',
			array(
				'label'        => __( 'Full width', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			)
		);

		$this->end_controls_section();
	}

	protected function render(): void {
		$product = $this->current_product();

		if ( ! $product ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div style="padding:2rem;text-align:center;border:1px dashed #ccc;border-radius:8px;">';
				esc_html_e( 'Galaxie Wishlist Button — place inside a single product template, or anywhere a product is in context.', 'galaxie-woo' );
				echo '</div>';
			}
			return;
		}

		$settings    = $this->get_settings_for_display();
		$in_wishlist = Module::is_in_wishlist( $product->get_id() );

		printf(
			'<button type="button" class="galaxie-wishlist-btn%1$s" data-product-id="%2$s" aria-pressed="%3$s">',
			$in_wishlist ? ' is-in-wishlist' : '',
			esc_attr( (string) $product->get_id() ),
			$in_wishlist ? 'true' : 'false'
		);
		// Both states ship in the markup; CSS shows exactly one. See class docblock.
		echo '<span class="galaxie-wishlist-add">' . $this->render_button( 'add', $settings ) . '</span>'; // phpcs:ignore -- pixfort's own component markup.
		echo '<span class="galaxie-wishlist-saved">' . $this->render_button( 'saved', $settings ) . '</span>'; // phpcs:ignore -- pixfort's own component markup.
		echo '</button>';
	}

	/** @param array<string,mixed> $settings */
	private function render_button( string $prefix, array $settings ): string {
		$text = (string) ( $settings[ $prefix . '_text' ] ?? '' );

		if ( $this->pixfort_active() ) {
			$attr = array(
				'is_elementor'      => 'true',
				'btn_text'          => $text,
				'btn_link'          => '', // Empty on purpose: renders a <span>, not an <a> — our own wrapping <button> handles the click.
				'btn_icon'          => $settings[ $prefix . '_icon' ] ?? '',
				'btn_icon_position' => $settings[ $prefix . '_icon_position' ] ?? '',
				'btn_style'         => $settings[ $prefix . '_style' ] ?? '',
				'btn_color'         => $settings[ $prefix . '_color' ] ?? 'primary',
				'btn_text_color'    => $settings[ $prefix . '_text_color' ] ?? '',
				'btn_size'          => $settings[ $prefix . '_size' ] ?? 'md',
				'btn_rounded'       => $settings[ $prefix . '_rounded' ] ?? '',
				'btn_full'          => $settings[ $prefix . '_full' ] ?? '',
			);
			return \PixfortCore::instance()->elementsManager->renderElement( 'Button', $attr );
		}

		return '<span class="wishlist-btn">' . esc_html( $text ) . '</span>';
	}

	/** Current product on a real single-product page, or Elementor's preview post. */
	private function current_product(): ?\WC_Product {
		global $product;

		if ( $product instanceof \WC_Product ) {
			return $product;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return null;
		}

		$candidate = wc_get_product( $post_id );
		return $candidate instanceof \WC_Product ? $candidate : null;
	}
}
