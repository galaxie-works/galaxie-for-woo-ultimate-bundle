<?php
/**
 * Gift Wrap module — mark a purchase as a gift and build it in a pixfort popup.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\GiftWrap;

use Galaxie\Woo\Core\Field;
use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\Plugin;
use Galaxie\Woo\Core\ProvidesBootData;
use Galaxie\Woo\Core\ProvidesElementorWidgets;
use Galaxie\Woo\Core\ProvidesSettings;
use Galaxie\Woo\Modules\GiftWrap\Widget\GiftBuilderWidget;

defined( 'ABSPATH' ) || exit;

/**
 * A shopper ticks "Estou comprando um presente para alguém" in the Galaxie Buy
 * Box; Add to Cart and Buy Now then open a pixfort popup holding the Galaxie
 * Gift Builder before they act. There the candles are shared out over gift
 * boxes, with ribbons and cards, and everything goes into the cart as one gift
 * ({@see Groups}). The order keeps the gift and wp-admin tags it "Presente".
 *
 * The Buy Box's own "Gift (Presente)" section is where the storefront part is
 * configured, per widget; this module owns what is store-wide: the packing
 * rules, which categories hold boxes, ribbons and cards, and the card limit.
 */
final class Module implements ModuleContract, ProvidesBootData, ProvidesElementorWidgets, ProvidesSettings {

	public const ID = 'gift-wrap';

	/** Largest packing gap the settings accept, in cm. */
	private const MAX_PACKING_GAP = 5;

	/** How candles may lie in a box, as the packing engine names it. */
	private const ORIENTATIONS = array( 'lying', 'upright', 'any' );

	/** Defaults for every setting, read through {@see self::setting()}. */
	private const DEFAULTS = array(
		'size_attribute'     => 'pa_peso',
		// Jars go into a gift box bare (without their shipping box), snug: no gap unless the merchant adds one.
		'packing_gap'        => 0,
		'allow_stacking'     => false,
		'candle_orientation' => 'lying',
		'box_categories'     => array(),
		'ribbon_categories'  => array(),
		'card_categories'    => array(),
		'card_message_max'   => 200,
	);

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Gift Wrap', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'A "this is a gift" checkbox in the Galaxie Buy Box, a gift builder popup with boxes, ribbons and cards, gifts grouped in the cart, and a packing summary on gift orders.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return false;
	}

	public function boot(): void {
		// The "Presente" badge and the packing summary in wp-admin and e-mails are
		// not booted here: Support\GiftOrders and Support\GiftSummary run from
		// Plugin::boot() so gift orders keep them with this module off.
		Flag::hooks();
		Groups::hooks();
		Builder::hooks();
		DimensionNotice::hooks();

		$options = self::packing_options();

		( new BoxFields(
			self::size_attribute(),
			$options['gap'],
			$options['stacking'],
			self::categories( 'box' ),
			$options['orientation']
		) )->register();

		// The jar's own size for packing, beside the shipping dimensions Melhor Envio reads.
		( new CandleFields( self::size_attribute(), self::categories( 'box' ) ) )->register();

		// The jar's size once per size term, used when a variation has none of its
		// own. Same normalised taxonomy as the fields above (`peso` → `pa_peso`).
		( new SizeTermFields( self::size_attribute() ) )->register();

		// The variation sizes over the REST API, sanitised by BoxFields and CandleFields.
		ProductMeta::hooks();
	}

	public function elementor_widgets(): array {
		return array( GiftBuilderWidget::class );
	}

	public function boot_data(): array {
		return array(
			'giftWrap' => array(
				// No nonce here: this is printed into pages LiteSpeed caches for days.
				// The builder asks Builder::NONCE_ACTION for a fresh one when it opens.
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			),
		);
	}

	/**
	 * One saved value, or its default.
	 *
	 * @return mixed
	 */
	public static function setting( string $key ) {
		$values = Plugin::instance()->settings()->module_settings( self::ID );

		return $values[ $key ] ?? ( self::DEFAULTS[ $key ] ?? null );
	}

	/**
	 * The candle size attribute as WooCommerce names it.
	 *
	 * The setting is typed by hand, and "peso" for the global attribute Peso is
	 * an easy slip: a variation still answers get_attribute( 'peso' ), so the
	 * candle being bought looks fine, but there is no taxonomy "peso" to list
	 * the store's sizes from. When the typed name is no taxonomy and "pa_" plus
	 * it is one, that is the one meant. A local attribute stays as typed.
	 */
	public static function size_attribute(): string {
		$attribute = (string) self::setting( 'size_attribute' );

		if ( '' !== $attribute && ! taxonomy_exists( $attribute ) && taxonomy_exists( 'pa_' . $attribute ) ) {
			return 'pa_' . $attribute;
		}

		return $attribute;
	}

	/**
	 * The options every packing question is asked with — here, in the cart, on
	 * the server and, through the builder's data, in the popup.
	 *
	 * @return array{gap:float, stacking:bool, orientation:string}
	 */
	public static function packing_options(): array {
		$orientation = (string) self::setting( 'candle_orientation' );

		return array(
			'gap'         => (float) self::setting( 'packing_gap' ),
			'stacking'    => (bool) self::setting( 'allow_stacking' ),
			'orientation' => in_array( $orientation, self::ORIENTATIONS, true ) ? $orientation : self::DEFAULTS['candle_orientation'],
		);
	}

	/**
	 * Product category ids holding one kind of accessory, from the module
	 * settings. The only place categories are chosen: values an older Gift
	 * Builder widget saved for them are ignored.
	 *
	 * @param string $kind `box`, `ribbon` or `card`.
	 * @return int[]
	 */
	public static function categories( string $kind ): array {
		$saved = self::setting( $kind . '_categories' );

		return array_values( array_unique( array_filter( array_map( 'absint', is_array( $saved ) ? $saved : array() ) ) ) );
	}

	// -------------------------------------------------------------- settings

	public function settings_tab_label(): string {
		return __( 'Gift Wrap', 'galaxie-woo' );
	}

	public function settings_fields(): array {
		$categories = self::category_options();

		return array(
			new Field(
				key: 'size_attribute',
				label: __( 'Candle size attribute', 'galaxie-woo' ),
				type: Field::TYPE_TEXT,
				description: __( 'The variation attribute that tells candle sizes apart. Each candle variation\'s own dimensions (L × W × H) are what a gift box is checked against.', 'galaxie-woo' ),
				default: self::DEFAULTS['size_attribute'],
				placeholder: 'pa_peso'
			),
			new Field(
				key: 'packing_gap',
				label: __( 'Packing gap (cm)', 'galaxie-woo' ),
				type: Field::TYPE_NUMBER,
				description: __( 'Room left around each candle for paper filling when checking what fits in a box. Decimals allowed, from 0 to 5.', 'galaxie-woo' ),
				default: self::DEFAULTS['packing_gap'],
				step: '0.1',
				min: '0',
				max: (string) self::MAX_PACKING_GAP
			),
			new Field(
				key: 'allow_stacking',
				label: __( 'Stacking allowed', 'galaxie-woo' ),
				type: Field::TYPE_TOGGLE,
				description: __( 'Let candles be stacked in a box. Off: one layer, side by side.', 'galaxie-woo' ),
				default: self::DEFAULTS['allow_stacking']
			),
			new Field(
				key: 'candle_orientation',
				label: __( 'Posição das velas na caixa', 'galaxie-woo' ),
				type: Field::TYPE_SELECT,
				description: __( 'How candles are laid in a gift box when checking what fits. "Qualquer uma" lets each candle lie down or stand up, whichever fits.', 'galaxie-woo' ),
				default: self::DEFAULTS['candle_orientation'],
				options: array(
					'lying'   => __( 'Deitadas', 'galaxie-woo' ),
					'upright' => __( 'Em pé', 'galaxie-woo' ),
					'any'     => __( 'Qualquer uma', 'galaxie-woo' ),
				)
			),
			new Field(
				key: 'box_categories',
				label: __( 'Gift box categories', 'galaxie-woo' ),
				type: Field::TYPE_MULTI,
				description: __( 'Products in these categories are offered as gift boxes: each variation with inside dimensions (Products → edit → Variations) is one box size. A box product is never offered as a card or a ribbon, even in a shared category.', 'galaxie-woo' ),
				default: self::DEFAULTS['box_categories'],
				options: $categories
			),
			new Field(
				key: 'ribbon_categories',
				label: __( 'Ribbon categories', 'galaxie-woo' ),
				type: Field::TYPE_MULTI,
				description: __( 'Optional. Products in these categories are offered as extra ribbons. Leave empty when gift boxes are sold with their ribbon: the builder then has no ribbon step at all.', 'galaxie-woo' ),
				default: self::DEFAULTS['ribbon_categories'],
				options: $categories
			),
			new Field(
				key: 'card_categories',
				label: __( 'Card categories', 'galaxie-woo' ),
				type: Field::TYPE_MULTI,
				description: __( 'Products in these categories are offered as cards with a message. They may share a category with the gift boxes.', 'galaxie-woo' ),
				default: self::DEFAULTS['card_categories'],
				options: $categories
			),
			new Field(
				key: 'card_message_max',
				label: __( 'Card message max characters', 'galaxie-woo' ),
				type: Field::TYPE_NUMBER,
				description: __( 'The longest message a shopper can write on a card.', 'galaxie-woo' ),
				default: self::DEFAULTS['card_message_max']
			),
		);
	}

	public function render_extra_settings( array $values ): void {
		echo '<h2>' . esc_html__( 'Accessory products', 'galaxie-woo' ) . '</h2>';

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Gift boxes, ribbons and cards are ordinary WooCommerce products in the categories above, added to the cart at their own price as part of a gift. So they are offered only inside the gift builder, set each one\'s Catalog visibility to "Hidden" in WooCommerce (Products → edit → Publish box → Catalog visibility). This plugin does not change your products for you.', 'galaxie-woo' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'The product page part — the checkbox, its popup and its styling — is set in the Galaxie Buy Box widget, section "Gift (Presente)", in Elementor. The popup\'s content and texts are the Galaxie Gift Builder widget, edited in the pixfort popup.', 'galaxie-woo' )
		);
	}

	public function sanitize_settings( array $submitted, array $current ): array {
		$values = array_merge( $current, Field::sanitize_all( $this->settings_fields(), $submitted ) );

		$values['size_attribute']     = '' !== sanitize_title( (string) $values['size_attribute'] ) ? sanitize_title( (string) $values['size_attribute'] ) : self::DEFAULTS['size_attribute'];
		// A float, clamped: centimetres of paper, where bare jars need none and
		// anything past a few cm is a typo, not a packing choice.
		$values['packing_gap']        = round( min( (float) self::MAX_PACKING_GAP, max( 0.0, (float) $values['packing_gap'] ) ), 2 );
		$values['candle_orientation'] = in_array( $values['candle_orientation'] ?? '', self::ORIENTATIONS, true ) ? $values['candle_orientation'] : self::DEFAULTS['candle_orientation'];
		$values['card_message_max']   = max( 1, (int) $values['card_message_max'] );

		return $values;
	}

	/** @return array<string,string> product category id => name. */
	public static function category_options(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$options = array();

		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$options[ (string) $term->term_id ] = $term->name;
			}
		}

		return $options;
	}
}
