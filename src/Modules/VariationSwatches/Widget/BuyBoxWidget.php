<?php
/**
 * "Galaxie Buy Box" Elementor widget — the product page's whole purchase area.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\VariationSwatches\Widget;

use Elementor\Controls_Manager;
use Galaxie\Woo\Core\Plugin;
use Galaxie\Woo\Modules\GiftWrap\Module as GiftWrapModule;
use Galaxie\Woo\Modules\Wishlist\Gifts;
use Galaxie\Woo\Support\GiftPacking;
use Galaxie\Woo\Support\PixfortControls;
use Galaxie\Woo\Support\QuantityField;
use Elementor\Repeater;
use Elementor\Widget_Base;
use Galaxie\Woo\Modules\VariationSwatches\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Price, variation swatches, quantity, Add to Cart and Buy Now, each an
 * independently orderable and removable block, each styled through pixfort's
 * own components ({@see PixfortControls}).
 *
 * WHY THIS REPLACES VariationBadgesWidget. That widget rendered its own UI
 * *beside* WooCommerce's native form and then hid the native one piece by
 * piece. Every visual bug it produced was the same bug: a native block nobody
 * remembered to hide (the duplicated price and stock), or a control that fell
 * outside `form.cart` and so missed the theme's own scoped CSS (the misaligned
 * quantity stepper). Here the form IS ours, so there is no second copy of
 * anything and every control sits inside `form.cart` where theme styling
 * reaches it.
 *
 * What is NOT reimplemented is variation matching. The real `<select>` per
 * attribute stays in the form (visually hidden), so WooCommerce's own
 * `wc-add-to-cart-variation.js` keeps doing the matching it has always done and
 * keeps firing `found_variation` / `show_variation` / `hide_variation` /
 * `reset_data` — the events third-party plugins actually bind to.
 *
 * CONTROL LAYOUT, and why it is what it is. The repeater holds ONLY the block
 * type: it decides order, and deleting a row is how a block is turned off, so
 * an unwanted block never reaches the canvas. Everything else lives in a
 * clearly-named section per block, for two reasons. One is legibility — with
 * every set flattened into one repeater row there was no telling whether
 * "Bold" belonged to the caption or the badge. The other is a hard constraint:
 * pixfort's `pixfort_icon_selector` prints its entire icon library inline in
 * its `content_template()`, and Elementor re-renders a repeater row's controls
 * on every interaction, so thousands of nodes were being rebuilt per click —
 * that is what froze the editor. Custom controls of that shape must stay out
 * of repeater rows.
 */
final class BuyBoxWidget extends Widget_Base {

	/**
	 * `PixAlert::render()` enqueues its own stylesheet as it renders, which is
	 * fine on the site — the handle lands in the footer — but useless inside
	 * Elementor, where the widget is rendered through an AJAX call that prints no
	 * <head> and no footer. pixfort's own Alert widget solves this in its
	 * constructor, gated on a logged-in user so visitors never pay for it, and
	 * this does the same rather than inventing a second mechanism.
	 *
	 * @param array<string,mixed> $data
	 * @param array<string,mixed>|null $args
	 */
	public function __construct( $data = array(), $args = null ) {
		parent::__construct( $data, $args );

		if ( is_user_logged_in() && defined( 'PIX_CORE_PLUGIN_URI' ) && defined( 'PIXFORT_PLUGIN_VERSION' ) ) {
			wp_enqueue_style(
				'pixfort-alert-style',
				PIX_CORE_PLUGIN_URI . 'includes/assets/css/elements/alert.min.css',
				false,
				PIXFORT_PLUGIN_VERSION
			);
		}
	}

	public function get_name(): string {
		return 'galaxie-buybox';
	}

	public function get_title(): string {
		return __( 'Galaxie Buy Box', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-cart-medium';
	}

	public function get_categories(): array {
		return array( 'galaxie' );
	}

	protected function register_controls(): void {
		$this->register_blocks_section();
		$this->register_price_section();
		$this->register_variations_section();
		$this->register_alert_section();
		$this->register_gift_section();
		$this->register_giftwrap_section();
		$this->register_quantity_section();
		$this->register_button_section( 'addcart', __( 'Add to Cart button', 'galaxie-woo' ), __( 'Adicionar ao carrinho', 'galaxie-woo' ), '' );
		$this->register_button_section( 'buynow', __( 'Buy Now button', 'galaxie-woo' ), __( 'Comprar agora', 'galaxie-woo' ), 'outline' );
		$this->register_layout_section();
	}

	/** Order and presence. Nothing else — see the class docblock. */
	private function register_blocks_section(): void {
		$this->start_controls_section(
			'blocks_section',
			array( 'label' => __( 'Blocks', 'galaxie-woo' ) )
		);

		$this->add_control(
			'blocks_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Drag to reorder. Remove a row to leave that block out entirely — its own section below is then ignored.', 'galaxie-woo' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$repeater = new Repeater();
		$repeater->add_control(
			'block',
			array(
				'label'   => __( 'Block', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => self::block_options(),
				'default' => 'price',
			)
		);

		$this->add_control(
			'blocks',
			array(
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'title_field' => '{{{ block }}}',
				'default'     => array(
					array( 'block' => 'price' ),
					array( 'block' => 'variations' ),
					array( 'block' => 'alert' ),
					array( 'block' => 'quantity' ),
					array( 'block' => 'addcart' ),
				),
			)
		);

		$this->end_controls_section();
	}

	private function register_price_section(): void {
		$this->start_controls_section(
			'price_section',
			array( 'label' => __( 'Price', 'galaxie-woo' ) )
		);

		$this->heading( 'price_regular_heading', __( 'Regular price', 'galaxie-woo' ), false );
		PixfortControls::text( $this, 'price_regular', array( 'size' => 'h4', 'bold' => 'font-weight-bold', 'remove_pb_padding' => 'm-0' ), array(), '{{WRAPPER}} .galaxie-buybox-price-regular', 'heading' );

		$this->heading( 'price_sale_heading', __( 'Sale price', 'galaxie-woo' ) );
		$this->add_control(
			'sale_display',
			array(
				'label'   => __( 'Sale price display', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array(
					'simple'   => __( 'Simple (text)', 'galaxie-woo' ),
					'advanced' => __( 'Advanced (badge)', 'galaxie-woo' ),
				),
				'default' => 'simple',
			)
		);
		PixfortControls::text( $this, 'price_sale', array( 'size' => 'h4', 'bold' => 'font-weight-bold', 'remove_pb_padding' => 'm-0' ), array( 'sale_display' => 'simple' ), '{{WRAPPER}} .galaxie-buybox-price-sale', 'heading' );
		PixfortControls::badge( $this, 'price_sale_badge', array(), array( 'sale_display' => 'advanced' ) );

		$this->heading( 'stock_heading', __( 'Stock', 'galaxie-woo' ) );
		$this->add_control(
			'show_stock',
			array(
				'label'        => __( 'Show stock', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);
		PixfortControls::text( $this, 'stock', array( 'bold' => '', 'remove_pb_padding' => 'm-0' ), array( 'show_stock' => 'yes' ), '{{WRAPPER}} .galaxie-buybox-stock' );

		$this->end_controls_section();
	}

	private function register_variations_section(): void {
		$this->start_controls_section(
			'variations_section',
			array( 'label' => __( 'Variations', 'galaxie-woo' ) )
		);

		$this->add_control(
			'attribute',
			array(
				'label'   => __( 'Attribute', 'galaxie-woo' ),
				'type'    => Controls_Manager::SELECT,
				'options' => self::attribute_options(),
				'default' => 'pa_peso',
			)
		);

		$this->heading( 'label_heading', __( 'Caption', 'galaxie-woo' ) );
		$this->add_control(
			'label_text',
			array(
				'label'       => __( 'Caption text', 'galaxie-woo' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => __( 'Defaults to the attribute name', 'galaxie-woo' ),
			)
		);
		$this->add_control(
			'show_label',
			array(
				'label'        => __( 'Show caption', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);
		PixfortControls::text( $this, 'label', array( 'bold' => 'font-weight-bold', 'remove_pb_padding' => 'm-0' ), array( 'show_label' => 'yes' ), '{{WRAPPER}} .galaxie-variation-label' );

		$this->heading( 'badge_heading', __( 'Badge', 'galaxie-woo' ) );
		PixfortControls::badge( $this, 'badge', array( 'text_color' => 'primary', 'bg_color' => 'primary-light', 'rounded' => 'badge-pill' ) );

		$this->heading( 'badgesel_heading', __( 'Badge — selected', 'galaxie-woo' ) );
		PixfortControls::badge( $this, 'badgesel', array( 'text_color' => 'white', 'bg_color' => 'primary', 'rounded' => 'badge-pill' ) );

		$this->end_controls_section();
	}

	/**
	 * The quantity field is native WooCommerce markup, so it can't go through a
	 * pixfort component — but it still has to follow the theme's light/dark and
	 * Dynamic Colors, which is what {@see PixfortControls::palette_control()}
	 * is for.
	 */
	private function register_quantity_section(): void {
		$this->start_controls_section(
			'quantity_section',
			array( 'label' => __( 'Quantity', 'galaxie-woo' ) )
		);

		// The whole set, Style and the dropdown ceiling included — they were
		// registered here once, which is how the cart ended up with a Quantity
		// panel that was missing two controls this one had.
		PixfortControls::quantity(
			$this,
			'qty',
			'{{WRAPPER}} .galaxie-buybox-quantity .quantity',
			'{{WRAPPER}} .galaxie-buybox-quantity .qty',
			array(),
			array(),
			'{{WRAPPER}} .galaxie-buybox-quantity'
		);

		$this->end_controls_section();
	}

	/**
	 * The messages, and the one skin they share.
	 *
	 * Every message is a plain text field the merchant owns, because the wording
	 * of a refusal is a shop's voice and not the plugin's. Leaving one empty
	 * turns that case off rather than printing a blank alert — which is how
	 * "added to cart" ships off by default, since the theme already pops its own
	 * cart panel and two confirmations of the same click is one too many.
	 */
	private function register_alert_section(): void {
		$this->start_controls_section(
			'alert_section',
			array( 'label' => __( 'Alert', 'galaxie-woo' ) )
		);

		$this->add_control(
			'alert_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Shown in place, without reloading the page. Drag the Alert block in Blocks to move it — directly under Variations is where it reads best. Leave a message empty to say nothing in that case.', 'galaxie-woo' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		foreach ( self::alert_messages() as $key => $message ) {
			$this->add_control(
				'alert_' . $key . '_heading',
				array(
					'label'     => $message['label'],
					'type'      => Controls_Manager::HEADING,
					'separator' => 'before',
				)
			);

			$this->add_control(
				'alert_' . $key . '_text',
				array(
					'label'       => __( 'Message', 'galaxie-woo' ),
					'label_block' => true,
					'type'        => Controls_Manager::TEXTAREA,
					'rows'        => 2,
					'default'     => $message['text'],
					'placeholder' => __( 'Leave empty to stay silent', 'galaxie-woo' ),
				)
			);

			$this->add_control(
				'alert_' . $key . '_type',
				array(
					'label'     => __( 'Alert type', 'galaxie-woo' ),
					'type'      => Controls_Manager::SELECT,
					'options'   => self::alert_types(),
					'default'   => $message['type'],
					'condition' => array( 'alert_' . $key . '_text!' => '' ),
				)
			);

			if ( ! empty( $message['link'] ) ) {
				$this->add_control(
					'alert_' . $key . '_link_text',
					array(
						'label'       => __( 'Link text', 'galaxie-woo' ),
						'description' => __( 'Sits just left of the close button. Leave empty for no link.', 'galaxie-woo' ),
						'label_block' => true,
						'type'        => Controls_Manager::TEXT,
						'default'     => $message['link_text'],
						'condition'   => array( 'alert_' . $key . '_text!' => '' ),
					)
				);

				$this->add_control(
					'alert_' . $key . '_link',
					array(
						'label'       => __( 'Link', 'galaxie-woo' ),
						'description' => __( 'Empty points at the cart.', 'galaxie-woo' ),
						'type'        => Controls_Manager::URL,
						'default'     => array( 'url' => '', 'is_external' => false, 'nofollow' => false ),
						'condition'   => array(
							'alert_' . $key . '_text!'      => '',
							'alert_' . $key . '_link_text!' => '',
						),
					)
				);

				PixfortControls::palette_select(
					$this,
					'alert_' . $key . '_link_color',
					__( 'Link color', 'galaxie-woo' ),
					'alert-default',
					array(
						'alert_' . $key . '_text!'      => '',
						'alert_' . $key . '_link_text!' => '',
					)
				);
			}

			PixfortControls::icon_select(
				$this,
				'alert_' . $key . '_icon',
				__( 'Icon', 'galaxie-woo' ),
				$message['icon'],
				array(
					'alert_' . $key . '_text!' => '',
					'alert_media_type'         => 'icon',
				)
			);
		}

		$this->add_control(
			'alert_style_heading',
			array(
				'label'     => __( 'Appearance', 'galaxie-woo' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		PixfortControls::alert( $this, 'alert', array(), array(), '{{WRAPPER}} .galaxie-buybox-alert' );

		$this->end_controls_section();
	}

	/**
	 * The notice above the buy box for a shopper who came from "Give as a gift"
	 * on a shared wish list, so they know the add-to-cart is a gift delivered to
	 * someone else — with a link back to buying for themselves.
	 */
	private function register_gift_section(): void {
		$this->start_controls_section(
			'gift_section',
			array( 'label' => __( 'Gift notice (shared wish list)', 'galaxie-woo' ) )
		);

		$this->add_control(
			'gift_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Shown at the top of the buy box when the shopper came from "Give as a gift" on a shared wish list. {name} is the list owner\'s first name. The link opens the product again as an ordinary purchase. Leave the message empty to show nothing.', 'galaxie-woo' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$this->add_control(
			'gift_text',
			array(
				'label'       => __( 'Message', 'galaxie-woo' ),
				'label_block' => true,
				'type'        => Controls_Manager::TEXTAREA,
				'rows'        => 2,
				'default'     => __( 'Você está dando este produto de presente para {name}. A entrega vai para o endereço dessa pessoa.', 'galaxie-woo' ),
			)
		);

		$shown = array( 'gift_text!' => '' );

		$this->add_control(
			'gift_preview',
			array(
				'label'        => __( 'Show in the editor', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
				'condition'    => $shown,
			)
		);

		$this->add_control(
			'gift_type',
			array(
				'label'     => __( 'Alert type', 'galaxie-woo' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => self::alert_types(),
				'default'   => 'info',
				'condition' => $shown,
			)
		);

		PixfortControls::icon_select( $this, 'gift_icon', __( 'Icon', 'galaxie-woo' ), 'Line/pixfort-icon-gift-1', $shown );

		$this->add_control(
			'gift_link_text',
			array(
				'label'       => __( 'Link text', 'galaxie-woo' ),
				'description' => __( 'Leave empty for no link.', 'galaxie-woo' ),
				'label_block' => true,
				'type'        => Controls_Manager::TEXT,
				'default'     => __( 'Comprar pra mim', 'galaxie-woo' ),
				'condition'   => $shown,
			)
		);

		PixfortControls::palette_select( $this, 'gift_link_color', __( 'Link color', 'galaxie-woo' ), 'alert-default', $shown + array( 'gift_link_text!' => '' ) );

		$this->add_control(
			'gift_inherit',
			array(
				'label'        => __( 'Same appearance as the Alert', 'galaxie-woo' ),
				'description'  => __( 'Wears the Appearance set in the Alert section. Turn off to style this notice on its own.', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'separator'    => 'before',
				'condition'    => $shown,
			)
		);

		PixfortControls::alert( $this, 'gift_alert', array(), $shown + array( 'gift_inherit!' => 'yes' ), '{{WRAPPER}} .galaxie-buybox-gift' );

		$this->end_controls_section();
	}

	/**
	 * "Montar um kit ou presente" — the Gift Wrap module's kit button, and
	 * nothing to do with the section above: that one is a notice for a shopper
	 * sent here by someone else's wish list, this one starts or fills a kit.
	 *
	 * One button whose label follows the kit being built: without one it opens
	 * the kit popup on the name step with this candle; with one it adds the
	 * chosen candle to that kit ("Adicionar ao kit {kit}"), or says it does not
	 * fit. The popup is store-wide ("Popup do kit" in wp-admin → Galaxie → Gift
	 * Wrap). Products without the candle size attribute get no button.
	 *
	 * The keys are the ones the checkbox section used (`giftwrap_enable`, the
	 * `giftwrap` Blocks row), so a widget that had it on shows the button in the
	 * same place.
	 */
	private function register_giftwrap_section(): void {
		$this->start_controls_section(
			'giftwrap_section',
			array( 'label' => __( 'Kit (Presente)', 'galaxie-woo' ) )
		);

		if ( ! Plugin::instance()->modules()->is_enabled_by_id( GiftWrapModule::ID ) ) {
			$this->add_control(
				'giftwrap_module_note',
				array(
					'type'            => Controls_Manager::RAW_HTML,
					'raw'             => esc_html__( 'The Gift Wrap module is off, so this section shows nothing on the site. Turn it on in wp-admin → Galaxie → Modules.', 'galaxie-woo' ),
					'content_classes' => 'elementor-panel-alert elementor-panel-alert-warning',
				)
			);
		}

		$this->add_control(
			'giftwrap_enable',
			array(
				'label'        => __( 'Show "Montar um kit ou presente"', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			)
		);

		$on = array( 'giftwrap_enable' => 'yes' );

		$this->add_control(
			'giftwrap_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Sits just above the buttons, or wherever a "Kit (Presente)" row is placed in Blocks. The popup it opens is set in wp-admin → Galaxie → Gift Wrap ("Popup do kit"); its screens are the Galaxie Kit Builder widget inside that popup. {kit} is the name of the kit being built.', 'galaxie-woo' ),
				'content_classes' => 'elementor-descriptor',
				'condition'       => $on,
			)
		);

		$texts = array(
			'giftkit_start_text' => array( __( 'Text with no kit open', 'galaxie-woo' ), __( 'Montar um kit ou presente', 'galaxie-woo' ) ),
			'giftkit_add_text'   => array( __( 'Text with a kit open', 'galaxie-woo' ), __( 'Adicionar ao kit {kit}', 'galaxie-woo' ) ),
			'giftkit_full_text'  => array( __( 'Text when the candle does not fit', 'galaxie-woo' ), __( 'Não cabe na caixa deste kit', 'galaxie-woo' ) ),
		);

		foreach ( $texts as $key => $text ) {
			$this->add_control(
				$key,
				array(
					'label'       => $text[0],
					'label_block' => true,
					'type'        => Controls_Manager::TEXT,
					'default'     => $text[1],
					'condition'   => $on,
				)
			);
		}

		$this->add_control(
			'giftkit_preview',
			array(
				'label'     => __( 'Show in the editor', 'galaxie-woo' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'start',
				'options'   => array(
					'start' => __( 'No kit open', 'galaxie-woo' ),
					'add'   => __( 'A kit open', 'galaxie-woo' ),
					'full'  => __( 'Candle does not fit', 'galaxie-woo' ),
				),
				'condition' => $on,
			)
		);

		// The popup the first versions of this section pointed at, kept hidden:
		// "Popup do kit" falls back to it once (Module::remember_legacy_popup()).
		$this->add_control(
			'giftwrap_popup_link',
			array(
				'type'    => Controls_Manager::HIDDEN,
				'default' => '',
			)
		);

		$this->add_control(
			'giftwrap_popup',
			array(
				'type'    => Controls_Manager::HIDDEN,
				'default' => '',
			)
		);

		$this->heading( 'giftkit_btn_heading', __( 'Button', 'galaxie-woo' ) );
		PixfortControls::button(
			$this,
			'giftkit_btn',
			array( 'style' => 'outline', 'color' => 'primary', 'icon' => 'Line/pixfort-icon-gift-1', 'full' => 'yes' ),
			$on,
			'{{WRAPPER}}',
			array( 'text' )
		);

		$this->end_controls_section();
	}

	private function register_button_section( string $prefix, string $label, string $default_text, string $default_style ): void {
		$this->start_controls_section(
			$prefix . '_section',
			array( 'label' => $label )
		);

		PixfortControls::button(
			$this,
			$prefix,
			array( 'text' => $default_text, 'style' => $default_style, 'color' => 'primary' ),
			array(),
			'{{WRAPPER}} .galaxie-buybox-' . $prefix
		);

		$this->end_controls_section();
	}

	/** Spacing between and inside blocks — the thing the previous widget had no answer for at all. */
	private function register_layout_section(): void {
		$this->start_controls_section(
			'layout_section',
			array(
				'label' => __( 'Layout', 'galaxie-woo' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'block_gap',
			array(
				'label'      => __( 'Gap between blocks', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 80 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 12 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-buybox' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_control(
			'inline_actions',
			array(
				'label'        => __( 'Buttons beside quantity', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Lays adjacent quantity and button blocks out on one row.', 'galaxie-woo' ),
			)
		);

		$this->add_responsive_control(
			'variations_cols_gap',
			array(
				'label'      => __( 'Swatch column gap', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 8 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-swatch-options' => 'column-gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'variations_rows_gap',
			array(
				'label'      => __( 'Swatch row gap', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 8 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-swatch-options' => 'row-gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'label_spacing',
			array(
				'label'      => __( 'Caption spacing', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'default'    => array( 'unit' => 'px', 'size' => 6 ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-variation-label' => 'margin-bottom: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();
	}

	private function heading( string $id, string $label, bool $separator = true ): void {
		$this->add_control(
			$id,
			array(
				'label'     => $label,
				'type'      => Controls_Manager::HEADING,
				'separator' => $separator ? 'before' : 'default',
			)
		);
	}

	protected function render(): void {
		$product = $this->current_product();

		if ( ! $product ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div style="padding:2rem;text-align:center;border:1px dashed #ccc;border-radius:8px;">';
				esc_html_e( 'Galaxie Buy Box — place inside a single product template, or anywhere a product is in context.', 'galaxie-woo' );
				echo '</div>';
			}
			return;
		}

		// Takes a post ID or WP_Post, never a WC_Product: the guard clause reads
		// $post->post_type, which a WC_Product has no such property for, so it
		// silently returns false and leaves the globals unset.
		wc_setup_product_data( $product->get_id() );

		$settings = $this->get_settings_for_display();
		$rows     = is_array( $settings['blocks'] ?? null ) ? $settings['blocks'] : array();
		$variable = $product->is_type( 'variable' );

		printf(
			'<form class="cart galaxie-buybox%1$s" method="post" enctype="multipart/form-data" action="%2$s" data-product_id="%3$d" data-product_variations="%4$s">',
			$variable ? ' variations_form' : '',
			esc_url( apply_filters( 'woocommerce_add_to_cart_form_action', $product->get_permalink() ) ),
			(int) $product->get_id(),
			$variable ? wc_esc_json( (string) wp_json_encode( $product->get_available_variations() ) ) : '' // phpcs:ignore WordPress.Security.EscapeOutput -- wc_esc_json() is the escaper.
		);

		if ( $variable ) {
			// WooCommerce enqueues its own variation script from inside
			// woocommerce_variable_add_to_cart(), which this widget replaces —
			// so it has to be asked for explicitly. Without it nothing matches
			// variations at all: no price update, no narrowing of options.
			wp_enqueue_script( 'wc-add-to-cart-variation' );
			$this->render_attribute_selects( $product );
			$this->render_single_variation_slot();
		}

		$this->render_gift_notice( $settings, $product );
		$this->render_blocks( $rows, $product, $settings );
		$this->render_hidden_fields( $product, $variable );

		echo '</form>';
	}

	/**
	 * Walks the configured order, pairing an adjacent quantity and button into
	 * one row when asked — the common storefront layout, and impossible to get
	 * from the block list alone.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<string,mixed>            $settings
	 */
	private function render_blocks( array $rows, \WC_Product $product, array $settings ): void {
		$rows    = $this->with_giftwrap_row( $rows, $settings );
		$inline  = 'yes' === ( $settings['inline_actions'] ?? 'yes' );
		$buttons = array( 'addcart', 'buynow' );
		$count   = count( $rows );

		for ( $i = 0; $i < $count; $i++ ) {
			$block = (string) ( $rows[ $i ]['block'] ?? '' );
			$next  = (string) ( $rows[ $i + 1 ]['block'] ?? '' );

			$pairs = $inline
				&& ( 'quantity' === $block || in_array( $block, $buttons, true ) )
				&& in_array( $next, $buttons, true );

			if ( ! $pairs ) {
				$this->render_block( $block, $product, $settings );
				continue;
			}

			echo '<div class="galaxie-buybox-row">';
			$this->render_block( $block, $product, $settings );

			// Keep absorbing following button blocks so quantity + Add to Cart
			// + Buy Now all land on the same row rather than just the first two.
			while ( $i + 1 < $count && in_array( (string) ( $rows[ $i + 1 ]['block'] ?? '' ), $buttons, true ) ) {
				++$i;
				$this->render_block( (string) $rows[ $i ]['block'], $product, $settings );
			}
			echo '</div>';
		}
	}

	/**
	 * The element WooCommerce needs in order to announce a chosen variation.
	 *
	 * Of the four events a variation form is expected to emit, this widget was
	 * only ever getting two. Measured on the page: `found_variation` and
	 * `reset_data` fired, `show_variation` and `hide_variation` never did.
	 *
	 * The reason is one line of WooCommerce's own script:
	 *
	 *     self.$singleVariation = $form.find( '.single_variation' );
	 *
	 * It is resolved ONCE, when the form object is constructed, and those two
	 * events are triggered on that selection. No `.single_variation` in our
	 * markup meant an empty jQuery set, and triggering on an empty set is a
	 * silent no-op — which is also why injecting the element afterwards fixes
	 * nothing: by then the empty selection is already cached.
	 *
	 * So it is rendered server side, before the script ever runs. WooCommerce
	 * writes its own price/availability template into it and animates it; the
	 * wrapper keeps all of that out of sight, and our own blocks stay the only
	 * thing on screen.
	 *
	 * The trade this accepts: WooCommerce binds its own handlers to those two
	 * events, and they toggle `disabled wc-variation-selection-needed` on
	 * `.single_add_to_cart_button` — which is our button. Checked on the live
	 * page before committing to it: with those classes applied, computed
	 * opacity, cursor and pointer-events are unchanged and the click still
	 * reaches our handler, because the rules this widget already uses to
	 * neutralise the theme cover that state too. The alert flow is untouched.
	 */
	private function render_single_variation_slot(): void {
		echo '<div class="galaxie-buybox-wc-variation" aria-hidden="true"><div class="single_variation_wrap"><div class="single_variation"></div></div></div>';
	}

	/**
	 * The real attribute dropdowns, visually hidden. WooCommerce's own
	 * `wc-add-to-cart-variation.js` only recognises a `<select>` inside
	 * `.variations`, and keeping them is what lets it go on matching
	 * variations and firing the events plugins listen for.
	 */
	private function render_attribute_selects( \WC_Product $product ): void {
		echo '<div class="variations galaxie-buybox-attrs" aria-hidden="true">';

		foreach ( $product->get_variation_attributes() as $name => $options ) {
			printf(
				'<select name="attribute_%1$s" data-attribute_name="attribute_%1$s" tabindex="-1"><option value="">%2$s</option>',
				esc_attr( sanitize_title( $name ) ),
				esc_html__( 'Choose an option', 'galaxie-woo' )
			);

			foreach ( $options as $option ) {
				printf(
					'<option value="%s">%s</option>',
					esc_attr( $option ),
					esc_html( self::term_label( $name, $option ) )
				);
			}

			echo '</select>';
		}

		echo '</div>';
	}

	private function render_hidden_fields( \WC_Product $product, bool $variable ): void {
		printf( '<input type="hidden" name="add-to-cart" value="%d" />', (int) $product->get_id() );

		if ( $variable ) {
			printf( '<input type="hidden" name="product_id" value="%d" />', (int) $product->get_id() );
			echo '<input type="hidden" name="variation_id" class="variation_id" value="0" />';
		}

		printf( '<input type="hidden" name="%s" value="" />', esc_attr( Module::BUY_NOW_FIELD ) );
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function render_block( string $block, \WC_Product $product, array $settings ): void {
		if ( '' === $block ) {
			return;
		}

		// Deliberately NOT `galaxie-buybox-{block}`: the block renderers emit
		// their own element with that exact class, and two nested nodes sharing
		// it meant every rule written for the inner one also hit the wrapper —
		// which is what put the stock line beside the price instead of under it.
		echo '<div class="galaxie-buybox-block galaxie-buybox-block--' . esc_attr( sanitize_html_class( $block ) ) . '">';

		switch ( $block ) {
			case 'price':
				$this->render_price( $settings, $product );
				break;
			case 'variations':
				$this->render_variations( $settings, $product );
				break;
			case 'alert':
				$this->render_alert( $settings );
				break;
			case 'quantity':
				$this->render_quantity( $settings, $product );
				break;
			case 'giftwrap':
				$this->render_giftwrap( $settings, $product );
				break;
			case 'addcart':
			case 'buynow':
				$this->render_button( $settings, $block );
				break;
		}

		echo '</div>';
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function render_price( array $settings, \WC_Product $product ): void {
		// Both slots always ship, because on a variable product there is no
		// price to split until a variation is chosen — the JS fills them from
		// the matched variation's own `price_html`. Rendering only what the
		// parent product currently knows would mean the sale styling could
		// never apply to a variable product at all, which is most of the
		// catalogue here.
		// Kept as markup rather than stripped: `get_price_html()` carries a
		// `screen-reader-text` span ("Price range: X through Y") whose whole
		// job is to be visually hidden, and stripping the tags leaves the text
		// behind with nothing left to hide it.
		$regular = $product->is_on_sale() && '' !== $product->get_regular_price()
			? wc_price( wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) ) )
			: $product->get_price_html();

		$sale = $product->is_on_sale() && '' !== $product->get_regular_price()
			? wc_price( wc_get_price_to_display( $product ) )
			: '';

		echo '<div class="galaxie-buybox-price">';

		printf(
			'<span class="galaxie-buybox-price-regular%s">%s</span>',
			'' === $sale ? '' : ' is-struck',
			$this->text( $settings, 'price_regular', $regular ) // phpcs:ignore WordPress.Security.EscapeOutput -- rendered through pixfort's own component.
		);

		printf(
			'<span class="galaxie-buybox-price-sale" data-galaxie-sale="%s"%s>%s</span>',
			esc_attr( (string) ( $settings['sale_display'] ?? 'simple' ) ),
			'' === $sale ? ' hidden' : '',
			'advanced' === ( $settings['sale_display'] ?? 'simple' )
				? $this->badge( $settings, 'price_sale_badge', $sale, true ) // phpcs:ignore WordPress.Security.EscapeOutput -- pixfort's own component markup.
				: $this->text( $settings, 'price_sale', $sale ) // phpcs:ignore WordPress.Security.EscapeOutput -- rendered through pixfort's own component.
		);

		echo '</div>';

		if ( 'yes' === ( $settings['show_stock'] ?? 'yes' ) ) {
			$stock = wp_strip_all_tags( wc_get_stock_html( $product ) );
			echo '<div class="galaxie-buybox-stock">' . $this->text( $settings, 'stock', $stock ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered through pixfort's own component.
		}
	}


	/**
	 * @param array<string,mixed> $settings
	 */
	private function render_variations( array $settings, \WC_Product $product ): void {
		$slug       = (string) ( $settings['attribute'] ?? 'pa_peso' );
		$attributes = $product->get_variation_attributes();

		$options = null;
		foreach ( $attributes as $name => $values ) {
			if ( sanitize_title( $name ) === sanitize_title( $slug ) ) {
				$options = $values;
				$slug    = $name;
				break;
			}
		}

		if ( ! $options ) {
			return;
		}

		printf(
			'<div class="galaxie-variation-picker" data-galaxie-attribute="%s">',
			esc_attr( sanitize_title( $slug ) )
		);

		if ( 'yes' === ( $settings['show_label'] ?? 'yes' ) ) {
			$caption = trim( (string) ( $settings['label_text'] ?? '' ) );
			if ( '' === $caption ) {
				$caption = wc_attribute_label( $slug, $product );
			}
			echo '<div class="galaxie-variation-label">' . $this->text( $settings, 'label', $caption ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered through pixfort's own component.
		}

		echo '<div class="galaxie-swatch-options">';
		foreach ( $options as $option ) {
			printf( '<button type="button" class="galaxie-swatch-option" data-value="%s">', esc_attr( $option ) );
			echo '<span class="galaxie-swatch-normal">' . $this->badge( $settings, 'badge', self::term_label( $slug, $option ) ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- pixfort's own component markup.
			echo '<span class="galaxie-swatch-selected">' . $this->badge( $settings, 'badgesel', self::term_label( $slug, $option ) ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- pixfort's own component markup.
			echo '</button>';
		}
		echo '</div>';

		echo '</div>';
	}

	/**
	 * One alert, rendered once and hidden, reused for every message.
	 *
	 * Not one alert per message: `alert-{type}` is the only place PixAlert puts
	 * the type, so swapping that single class is an exact substitution and four
	 * near-identical blocks of markup would buy nothing. The messages ride along
	 * as JSON for the script to pick from.
	 *
	 * It renders visible inside Elementor so the merchant can actually see what
	 * they are styling; on the site it stays out of the way until something goes
	 * wrong.
	 *
	 * @param array<string,mixed> $settings
	 */
	private function render_alert( array $settings ): void {
		$rendered = 0;
		$editing  = self::is_editing();

		ob_start();

		foreach ( self::alert_messages() as $key => $message ) {
			$text = trim( (string) ( $settings[ 'alert_' . $key . '_text' ] ?? '' ) );

			if ( '' === $text ) {
				continue;
			}

			$text = wp_kses_post( $text );
			$type = (string) ( $settings[ 'alert_' . $key . '_type' ] ?? $message['type'] );
			$icon = PixfortControls::icon_value( $settings, 'alert_' . $key . '_icon' );
			$link = $this->alert_link( $settings, $key, $message );

			// Only the first configured message is visible in the editor, so the
			// merchant sees one alert rather than a stack of four.
			printf(
				'<div class="galaxie-buybox-alert-message%s" data-galaxie-alert="%s">',
				$editing && 0 === $rendered ? ' is-current' : '',
				esc_attr( $key )
			);

			if ( PixfortControls::available() ) {
				echo \PixfortCore::instance()->elementsManager->renderElement( 'Alert', PixfortControls::alert_attr( $settings, 'alert', $text, $type, $icon, $link ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- pixfort's own component markup.
			} else {
				printf(
					'<div class="alert alert-%s" role="alert"><div class="pix-alert-title">%s</div></div>',
					esc_attr( $type ),
					wp_kses_post( $text )
				);
			}

			echo '</div>';
			++$rendered;
		}

		$markup = (string) ob_get_clean();

		if ( ! $rendered ) {
			return;
		}

		/*
		 * One alert per message, not one alert repainted.
		 *
		 * Repainting meant every message wore the same icon, and a warning
		 * triangle over "Added to cart" is worse than no icon at all — the glyph
		 * is part of what the sentence means, not part of the widget's skin.
		 * Rendering each one through PixAlert also means the type, the icon and
		 * the colour all come from pixfort exactly as configured, instead of
		 * from us swapping a class at runtime and hoping the rest follows.
		 */
		printf(
			'<div class="galaxie-buybox-alert%s" role="status" aria-live="polite">%s</div>',
			$editing ? ' is-visible' : '',
			$markup // phpcs:ignore WordPress.Security.EscapeOutput -- built above from escaped parts and pixfort's own component markup.
		);
	}

	/**
	 * "You are giving this to {name}", for a shopper who opened this product from
	 * "Give as a gift" — or a sample in the editor when asked for.
	 *
	 * Its own element, outside `.galaxie-buybox-alert`: that one is the script's,
	 * hidden until an add-to-cart goes wrong, and this has to stay on screen.
	 *
	 * @param array<string,mixed> $settings
	 */
	private function render_gift_notice( array $settings, \WC_Product $product ): void {
		$text = trim( (string) ( $settings['gift_text'] ?? '' ) );

		if ( '' === $text ) {
			return;
		}

		$editing = self::is_editing();
		$gift    = ! $editing && Plugin::instance()->modules()->is_enabled_by_id( 'wishlist' ) ? Gifts::pending_for( (int) $product->get_id() ) : null;

		if ( ! $gift && ! ( $editing && 'yes' === ( $settings['gift_preview'] ?? '' ) ) ) {
			return;
		}

		$name    = $gift ? (string) $gift['name'] : 'Maria';
		// The name is the list owner's own, typed into their account. PixAlert runs
		// the title through do_shortcode(), so escaping it is not enough on its own.
		$text    = wp_kses_post( str_replace( '{name}', PixfortControls::shortcode_safe( esc_html( $name ) ), $text ) );
		$type    = (string) ( $settings['gift_type'] ?? 'info' );
		$inherit = 'yes' === ( $settings['gift_inherit'] ?? 'yes' );
		$prefix  = $inherit ? 'alert' : 'gift_alert';
		$label   = trim( (string) ( $settings['gift_link_text'] ?? '' ) );

		// Back to the clean product page, which forgets the gift.
		$link = '' === $label ? array() : array(
			'link_text'  => $label,
			'link'       => array( 'url' => (string) $product->get_permalink(), 'is_external' => false, 'nofollow' => false ),
			'link_color' => (string) ( $settings['gift_link_color'] ?? 'alert-default' ),
		);

		echo '<div class="galaxie-buybox-gift" role="status">';

		// The Alert's icon size is a selector scoped to its own element.
		$size = $settings['alert_icon_size'] ?? '';

		if ( $inherit && is_numeric( $size ) ) {
			printf( '<style>.elementor-element-%1$s .galaxie-buybox-gift .pix-alert-icon > div{font-size:%2$spx !important;}</style>', esc_attr( $this->get_id() ), esc_attr( (string) (float) $size ) );
		}

		if ( PixfortControls::available() ) {
			if ( defined( 'PIX_CORE_PLUGIN_URI' ) && defined( 'PIXFORT_PLUGIN_VERSION' ) ) {
				wp_enqueue_style( 'pixfort-alert-style', PIX_CORE_PLUGIN_URI . 'includes/assets/css/elements/alert.min.css', array(), PIXFORT_PLUGIN_VERSION );
			}

			echo \PixfortCore::instance()->elementsManager->renderElement( 'Alert', PixfortControls::alert_attr( $settings, $prefix, $text, $type, PixfortControls::icon_value( $settings, 'gift_icon' ), $link ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- pixfort's own component markup.
		} else {
			printf( '<div class="alert alert-%s" role="alert"><div class="pix-alert-title">%s</div></div>', esc_attr( $type ), $text ); // phpcs:ignore WordPress.Security.EscapeOutput -- kses above.
		}

		echo '</div>';
	}

	/**
	 * The link slot for one message, or nothing when it has no link to offer.
	 *
	 * An empty URL falls back to the cart rather than rendering a dead anchor:
	 * the only link this message ever wants is the cart, and making the merchant
	 * paste that URL to get the obvious behaviour is a step with no decision in
	 * it. Leaving the TEXT empty is how the link is turned off — the same
	 * "empty means silent" rule the messages themselves follow.
	 *
	 * @param array<string,mixed> $settings
	 * @param array<string,string> $message
	 * @return array<string,mixed>
	 */
	private function alert_link( array $settings, string $key, array $message ): array {
		if ( empty( $message['link'] ) ) {
			return array();
		}

		$text = trim( (string) ( $settings[ 'alert_' . $key . '_link_text' ] ?? '' ) );

		if ( '' === $text ) {
			return array();
		}

		$link = $settings[ 'alert_' . $key . '_link' ] ?? array();
		$link = is_array( $link ) ? $link : array( 'url' => (string) $link );

		if ( '' === trim( (string) ( $link['url'] ?? '' ) ) ) {
			$link['url'] = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '';
		}

		return array(
			'link_text'  => $text,
			'link'       => $link,
			'link_color' => (string) ( $settings[ 'alert_' . $key . '_link_color' ] ?? 'alert-default' ),
		);
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function render_quantity( array $settings, \WC_Product $product ): void {
		echo '<div class="galaxie-buybox-quantity">';
		QuantityField::render( $settings, 'qty', $product );
		echo '</div>';
	}

	/**
	 * The rows with a "Kit (Presente)" block where one belongs.
	 *
	 * A row the merchant placed is left where it is. Otherwise the section's
	 * switch places it above the first button — and above the quantity when
	 * that quantity would share the buttons' row, so the kit button never lands
	 * inside the row it is meant to sit over.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<string,mixed>            $settings
	 * @return array<int,array<string,mixed>>
	 */
	private function with_giftwrap_row( array $rows, array $settings ): array {
		if ( 'yes' !== ( $settings['giftwrap_enable'] ?? '' ) ) {
			return $rows;
		}

		$blocks = array_map( static fn( $row ): string => (string) ( is_array( $row ) ? ( $row['block'] ?? '' ) : '' ), array_values( $rows ) );

		if ( in_array( 'giftwrap', $blocks, true ) ) {
			return $rows;
		}

		$rows = array_values( $rows );
		$at   = count( $rows );

		foreach ( $blocks as $i => $block ) {
			if ( in_array( $block, array( 'addcart', 'buynow' ), true ) ) {
				$at = $i;
				break;
			}
		}

		if ( $at > 0 && $at < count( $rows ) && 'quantity' === $blocks[ $at - 1 ] ) {
			--$at;
		}

		array_splice( $rows, $at, 0, array( array( 'block' => 'giftwrap' ) ) );

		return $rows;
	}

	/**
	 * The kit button.
	 *
	 * Nothing about the visitor's kit is printed — product pages are cached for
	 * days — so the button starts hidden and kit-buy-box.ts gives it its label,
	 * its quantity cap and its disabled state once it has read the kit. What is
	 * printed is the product's own: the packing size of each of its candles, so
	 * the script can tell what still fits without asking.
	 *
	 * @param array<string,mixed> $settings
	 */
	private function render_giftwrap( array $settings, \WC_Product $product ): void {
		if ( 'yes' !== ( $settings['giftwrap_enable'] ?? '' ) ) {
			return;
		}

		$editing = self::is_editing();
		$enabled = Plugin::instance()->modules()->is_enabled_by_id( GiftWrapModule::ID );

		if ( ! $editing && ! $enabled ) {
			return;
		}

		if ( $enabled ) {
			GiftWrapModule::remember_legacy_popup( self::legacy_popup_id( $settings ) );
		}

		$popup   = $enabled ? GiftWrapModule::kit_popup_id() : 0;
		$candles = $enabled ? self::kit_candles( $product ) : array();

		// No popup to open, or not a candle: no button.
		if ( ! $editing && ( ! $popup || ! $candles ) ) {
			return;
		}

		$start   = (string) ( $settings['giftkit_start_text'] ?? '' );
		$add     = (string) ( $settings['giftkit_add_text'] ?? '' );
		$full    = (string) ( $settings['giftkit_full_text'] ?? '' );
		$preview = $editing ? (string) ( $settings['giftkit_preview'] ?? 'start' ) : 'start';
		$label   = 'add' === $preview ? str_replace( '{kit}', __( 'Kit 1', 'galaxie-woo' ), $add ) : ( 'full' === $preview ? $full : $start );

		printf(
			'<div class="galaxie-buybox-kit%1$s" data-galaxie-kit-button data-popup="%2$d" data-candles="%3$s" data-text-start="%4$s" data-text-add="%5$s" data-text-full="%6$s"%7$s>',
			$editing ? '' : ' is-loading',
			(int) $popup,
			esc_attr( (string) wp_json_encode( (object) $candles ) ),
			esc_attr( $start ),
			esc_attr( $add ),
			esc_attr( $full ),
			$editing ? ' data-editing="1"' : ''
		);

		printf(
			'<button type="button" class="galaxie-buybox-btn galaxie-kit-button"%s>',
			'full' === $preview ? ' disabled aria-disabled="true"' : ''
		);
		echo PixfortControls::render_button( $settings, 'giftkit_btn', $label ); // phpcs:ignore WordPress.Security.EscapeOutput -- pixfort's own component markup.
		echo '</button>';

		echo '</div>';
	}

	/**
	 * This product's candles as the packing engine sees them, by product
	 * (variation) id: only purchasable ones with the size attribute and
	 * dimensions. Empty for anything that is not a candle.
	 *
	 * @return array<string, array>
	 */
	private static function kit_candles( \WC_Product $product ): array {
		if ( ! class_exists( GiftPacking::class ) ) {
			return array();
		}

		$attribute = GiftWrapModule::size_attribute();
		$ids       = $product->is_type( 'variable' ) ? $product->get_children() : array( $product->get_id() );
		$out       = array();

		foreach ( array_slice( $ids, 0, 60 ) as $id ) {
			$candidate = wc_get_product( $id );

			if ( ! $candidate instanceof \WC_Product || $candidate->is_type( 'variable' ) || ! $candidate->is_purchasable() ) {
				continue;
			}

			$candle = GiftPacking::candle_from_product( $candidate, $attribute );

			if ( $candle ) {
				$out[ (string) $candidate->get_id() ] = $candle;
			}
		}

		return $out;
	}

	/**
	 * The popup id the checkbox section was saved with (a link, or the older
	 * dropdown's id), or 0.
	 *
	 * @param array<string,mixed> $settings
	 */
	private static function legacy_popup_id( array $settings ): int {
		$link = trim( (string) ( $settings['giftwrap_popup_link'] ?? '' ) );

		return '' !== $link ? GiftWrapModule::parse_popup_link( $link ) : absint( $settings['giftwrap_popup'] ?? 0 );
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function render_button( array $settings, string $prefix ): void {
		$text = (string) ( $settings[ $prefix . '_text' ] ?? '' );

		// Both are real submit buttons: with JS off the form still posts and
		// WooCommerce still adds the item. The JS upgrades Add to Cart to AJAX
		// and flags Buy Now for the checkout redirect. `single_add_to_cart_button`
		// is kept on Add to Cart because WooCommerce's own variation JS toggles
		// its disabled state as combinations narrow.
		printf(
			'<button type="submit" class="galaxie-buybox-btn galaxie-buybox-%s%s">',
			esc_attr( $prefix ),
			'addcart' === $prefix ? ' single_add_to_cart_button' : ''
		);

		echo PixfortControls::render_button( $settings, $prefix, $text ); // phpcs:ignore WordPress.Security.EscapeOutput -- pixfort's own component markup.

		echo '</button>';
	}

	/**
	 * Moved to {@see PixfortControls::render_text()} once the cart needed the
	 * same thing: registering pixfort's text controls and then printing our own
	 * markup gives the merchant a panel where nothing moves, and that is a trap
	 * worth having in one place rather than two.
	 *
	 * @param array<string,mixed> $settings
	 */
	private function text( array $settings, string $prefix, string $content ): string {
		return PixfortControls::render_text( $settings, $prefix, $content );
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function badge( array $settings, string $prefix, string $text, bool $html = false ): string {
		if ( PixfortControls::available() ) {
			return \PixfortCore::instance()->elementsManager->renderElement( 'Badge', PixfortControls::badge_attr( $settings, $prefix, $text, $html ) );
		}

		return '<span class="galaxie-swatch-badge">' . ( $html ? wp_kses_post( $text ) : esc_html( $text ) ) . '</span>';
	}

	/** A taxonomy attribute stores slugs; the shopper should see the term name. */
	private static function term_label( string $taxonomy, string $option ): string {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return $option;
		}

		$term = get_term_by( 'slug', $option, $taxonomy );

		return $term instanceof \WP_Term ? $term->name : $option;
	}

	/**
	 * The cases the buy box can hit, in the order a shopper meets them.
	 *
	 * Keyed, not a repeater: each one is raised by a specific thing going wrong,
	 * so the script has to be able to ask for it by name. The merchant owns the
	 * wording and the colour; the list of situations is ours.
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function alert_messages(): array {
		return array(
			'select'      => array(
				'label' => __( 'No option chosen', 'galaxie-woo' ),
				'icon'  => 'Line/pixfort-icon-alert-1',
				'text'  => __( 'Escolha uma opção antes de adicionar ao carrinho.', 'galaxie-woo' ),
				'type'  => 'warning',
			),
			'unavailable' => array(
				'label' => __( 'Combination unavailable', 'galaxie-woo' ),
				'icon'  => 'Line/pixfort-icon-prohibited-circle-1',
				'text'  => __( 'Essa combinação não está disponível. Escolha outra.', 'galaxie-woo' ),
				'type'  => 'danger',
			),
			'error'       => array(
				'label' => __( 'Add to cart failed', 'galaxie-woo' ),
				'icon'  => 'Line/pixfort-icon-exclamation-mark-circle-1',
				'text'  => __( 'Não foi possível adicionar ao carrinho. Tente novamente.', 'galaxie-woo' ),
				'type'  => 'danger',
			),
			// Off unless the merchant asks for it: the theme already opens its cart
			// panel on a successful add, and saying it twice is worse than once.
			'added'       => array(
				'label'     => __( 'Added to cart', 'galaxie-woo' ),
				'icon'      => 'Line/pixfort-icon-check-circle-1',
				'text'      => '',
				'type'      => 'success',
				// The one message where a link earns its place: it is the only
				// one raised after something succeeded, so it is the only one
				// with somewhere to send the shopper next.
				'link'      => true,
				'link_text' => __( 'Ver carrinho', 'galaxie-woo' ),
			),
		);
	}

	/** pixfort's own alert palette, verbatim. @return array<string,string> */
	private static function alert_types(): array {
		return array(
			'success'   => __( 'Success', 'galaxie-woo' ),
			'secondary' => __( 'Secondary', 'galaxie-woo' ),
			'primary'   => __( 'Primary', 'galaxie-woo' ),
			'danger'    => __( 'Danger', 'galaxie-woo' ),
			'warning'   => __( 'Warning', 'galaxie-woo' ),
			'info'      => __( 'Info', 'galaxie-woo' ),
			'light'     => __( 'Light', 'galaxie-woo' ),
			'dark'      => __( 'Dark', 'galaxie-woo' ),
		);
	}

	/** True inside the Elementor editor's canvas. */
	private static function is_editing(): bool {
		return class_exists( '\\Elementor\\Plugin' )
			&& \Elementor\Plugin::$instance->editor->is_edit_mode();
	}

	/** @return array<string,string> */
	private static function block_options(): array {
		return array(
			'price'      => __( 'Price', 'galaxie-woo' ),
			'variations' => __( 'Variations', 'galaxie-woo' ),
			'alert'      => __( 'Alert', 'galaxie-woo' ),
			'quantity'   => __( 'Quantity', 'galaxie-woo' ),
			'giftwrap'   => __( 'Kit (Presente)', 'galaxie-woo' ),
			'addcart'    => __( 'Add to Cart', 'galaxie-woo' ),
			'buynow'     => __( 'Buy Now', 'galaxie-woo' ),
		);
	}

	/** @return array<string,string> */
	private static function attribute_options(): array {
		$options = array();

		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( wc_get_attribute_taxonomies() as $taxonomy ) {
				$slug             = wc_attribute_taxonomy_name( $taxonomy->attribute_name );
				$options[ $slug ] = $taxonomy->attribute_label;
			}
		}

		return $options ? $options : array( 'pa_peso' => 'pa_peso' );
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
