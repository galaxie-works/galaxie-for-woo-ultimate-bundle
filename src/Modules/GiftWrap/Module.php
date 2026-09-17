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
use Galaxie\Woo\Modules\GiftWrap\Widget\KitBuilderWidget;
use Galaxie\Woo\Modules\GiftWrap\Widget\KitProgressWidget;

defined( 'ABSPATH' ) || exit;

/**
 * Gift kits (PR #21, kit-flow-scope.md). A shopper builds a kit — a name, a
 * gift box, an optional card with a message, and candles — in a pixfort popup
 * holding the Galaxie Kit Builder, opened from the Galaxie Buy Box ("Montar um
 * kit ou presente"), the popup's launcher or the Galaxie Kit Progress widget.
 * The kit being built is a draft outside the cart (Kit\Store); "Adicionar kit
 * ao carrinho" puts it in as one gift ({@see Groups}), titled with the kit's
 * name in the cart, the order, the e-mails and the packing summary. The order
 * keeps the gift and wp-admin tags it "Presente".
 *
 * The Buy Box's "Kit (Presente)" section is where the product page part is
 * configured, per widget; this module owns what is store-wide: the packing
 * rules, which categories hold boxes, ribbons and cards, the card limit, the
 * kit popup, its launcher and the room-left wording.
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
		// The kit flow (PR #21).
		'kit_popup'              => '',
		'kit_continue_url'       => '',
		'kit_launcher_icon'      => true,
		'kit_launcher_icon_name' => 'Line/pixfort-icon-gift-1',
		'kit_badge'              => true,
		'kit_badge_bg'           => 'primary',
		'kit_badge_color'        => '#ffffff',
		'kit_badge_size'         => 20,
		'kit_text_room_many'     => 'Ainda cabem {combos}',
		'kit_text_room_one'      => 'Ainda cabe {combos}',
		'kit_text_full'          => 'Caixa completa! 🎉',
		'kit_text_box_holds'     => 'Leva até {combos}',
		'kit_text_added'         => 'Adicionada ao kit {kit}. {room}',
	);

	/**
	 * The popup the Buy Box widget pointed at before the kit flow, kept once by
	 * the widget ({@see self::remember_legacy_popup()}) and used while "Popup do
	 * kit" is empty.
	 */
	public const LEGACY_POPUP_OPTION = 'galaxie_kit_popup_legacy';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Gift Wrap', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Gift kits: a "Montar um kit" button in the Galaxie Buy Box, a kit builder popup with gift boxes and cards, a launcher badge and a progress widget, kits grouped in the cart by name, and a packing summary on gift orders.', 'galaxie-woo' );
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
		DimensionNotice::hooks();

		// The kit flow: the draft's requests, and the popup launcher's icon and badge.
		Kit\Ajax::hooks();

		if ( ! is_admin() ) {
			Kit\Launcher::hooks();
		}

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
		return array( KitBuilderWidget::class, KitProgressWidget::class );
	}

	/**
	 * Store configuration only. No nonce and nothing about the visitor's kit:
	 * this is printed into pages LiteSpeed caches for days. The scripts ask the
	 * kit endpoint (Kit\Ajax, `get`) for both.
	 */
	public function boot_data(): array {
		$texts = array();

		foreach ( array( 'room_many', 'room_one', 'full', 'box_holds', 'added' ) as $key ) {
			$texts[ $key ] = (string) self::setting( 'kit_text_' . $key );
		}

		$continue = trim( (string) self::setting( 'kit_continue_url' ) );

		return array(
			'giftWrap' => array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'kit'     => array(
					'popup'        => self::kit_popup_id(),
					'launcherIcon' => (bool) self::setting( 'kit_launcher_icon' ),
					'badge'        => (bool) self::setting( 'kit_badge' ),
					'texts'        => $texts,
					'continueUrl'  => '' !== $continue ? esc_url_raw( $continue ) : ( function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : home_url( '/' ) ),
					'nameFormat'   => Kit\Kits::name_format(),
					'nameMax'      => \Galaxie\Woo\Support\GiftKit::NAME_MAX,
				),
			),
		);
	}

	/**
	 * The published pixfort popup holding the Galaxie Kit Builder, or 0: "Popup
	 * do kit", else the one the Buy Box widget used to name.
	 */
	public static function kit_popup_id(): int {
		static $resolved = array();

		$link = trim( (string) self::setting( 'kit_popup' ) );
		$id   = '' !== $link ? self::parse_popup_link( $link ) : absint( get_option( self::LEGACY_POPUP_OPTION, 0 ) );

		if ( isset( $resolved[ $id ] ) ) {
			return $resolved[ $id ];
		}

		return $resolved[ $id ] = ( $id && 'pixpopup' === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) ? $id : 0;
	}

	/**
	 * A popup id from what a merchant pastes.
	 *
	 * pixfort's own link for a popup is `#pix_popup_{id}` — the "Popup Link"
	 * column and the "Open from Link" field print it (includes/post-types/
	 * popup.php). Accepted: that link (`#pix_popup_4549`), a full URL ending in
	 * it, or the bare id (`4549`). Anything else is 0, not a guess.
	 */
	public static function parse_popup_link( string $link ): int {
		return preg_match( '/^(?:\S*#pix_popup_)?(\d+)$/i', trim( $link ), $match ) ? absint( $match[1] ) : 0;
	}

	/**
	 * Keeps, once, the popup a Buy Box widget was saved with before "Popup do
	 * kit" existed, so the kit flow opens it until the merchant sets one here.
	 */
	public static function remember_legacy_popup( int $id ): void {
		if ( $id < 1 || '' !== trim( (string) self::setting( 'kit_popup' ) ) || false !== get_option( self::LEGACY_POPUP_OPTION, false ) ) {
			return;
		}

		update_option( self::LEGACY_POPUP_OPTION, $id, true );
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
	 * the store's sizes from. When "pa_" plus the typed name is one of the
	 * store's global attributes, that is the one meant. Anything else stays as
	 * typed (a local attribute).
	 *
	 * Asked of WooCommerce's attribute table, not taxonomy_exists(): modules
	 * boot on plugins_loaded, and WooCommerce only registers the pa_*
	 * taxonomies on init, so BoxFields and CandleFields — built at boot — would
	 * otherwise always get the name as typed.
	 */
	public static function size_attribute(): string {
		$attribute = (string) self::setting( 'size_attribute' );

		if ( '' === $attribute || 0 === strpos( $attribute, 'pa_' ) || ! function_exists( 'wc_get_attribute_taxonomy_names' ) ) {
			return $attribute;
		}

		return in_array( 'pa_' . $attribute, (array) wc_get_attribute_taxonomy_names(), true ) ? 'pa_' . $attribute : $attribute;
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

			// ------------------------------------------------------ kit flow
			new Field(
				key: 'kit_popup',
				label: __( 'Popup do kit', 'galaxie-woo' ),
				type: Field::TYPE_TEXT,
				description: __( 'The pixfort popup holding the Galaxie Kit Builder widget: its link from wp-admin → Popups ("Popup Link" column), e.g. #pix_popup_4549, or the id alone. The Buy Box button, the launcher and the Kit Progress widget all open it. Empty: the popup the Buy Box widget was set to before, if any.', 'galaxie-woo' ),
				default: self::DEFAULTS['kit_popup'],
				placeholder: '#pix_popup_4549'
			),
			new Field(
				key: 'kit_continue_url',
				label: __( '"Continuar escolhendo" goes to', 'galaxie-woo' ),
				type: Field::TYPE_TEXT,
				description: __( 'Where the kit popup sends the shopper to keep choosing candles. Empty: the WooCommerce shop page.', 'galaxie-woo' ),
				default: self::DEFAULTS['kit_continue_url'],
				placeholder: '/loja/'
			),
			new Field(
				key: 'kit_launcher_icon',
				label: __( 'Ícone de presente no launcher', 'galaxie-woo' ),
				type: Field::TYPE_TOGGLE,
				description: __( 'Replace the icon of the kit popup\'s pixfort launcher (turn "Launcher" on in the popup\'s pixfort settings) with the icon below.', 'galaxie-woo' ),
				default: self::DEFAULTS['kit_launcher_icon']
			),
			new Field(
				key: 'kit_launcher_icon_name',
				label: __( 'Launcher icon', 'galaxie-woo' ),
				type: Field::TYPE_TEXT,
				description: __( 'A pixfort icon name, as in the icon pickers (e.g. Line/pixfort-icon-gift-1). Empty or unknown: a plain gift outline.', 'galaxie-woo' ),
				default: self::DEFAULTS['kit_launcher_icon_name'],
				placeholder: 'Line/pixfort-icon-gift-1'
			),
			new Field(
				key: 'kit_badge',
				label: __( 'Badge com a quantidade', 'galaxie-woo' ),
				type: Field::TYPE_TOGGLE,
				description: __( 'A number on the launcher: the candles in the kit being built. No kit, no badge; kits already in the cart do not count.', 'galaxie-woo' ),
				default: self::DEFAULTS['kit_badge']
			),
			new Field(
				key: 'kit_badge_bg',
				label: __( 'Badge background', 'galaxie-woo' ),
				type: Field::TYPE_TEXT,
				description: __( 'A pixfort palette name (primary, secondary, dark…) or a colour (#e11d48, rgb(…)).', 'galaxie-woo' ),
				default: self::DEFAULTS['kit_badge_bg'],
				placeholder: 'primary'
			),
			new Field(
				key: 'kit_badge_color',
				label: __( 'Badge text colour', 'galaxie-woo' ),
				type: Field::TYPE_TEXT,
				description: __( 'Same format as the background.', 'galaxie-woo' ),
				default: self::DEFAULTS['kit_badge_color'],
				placeholder: '#ffffff'
			),
			new Field(
				key: 'kit_badge_size',
				label: __( 'Badge size (px)', 'galaxie-woo' ),
				type: Field::TYPE_NUMBER,
				description: __( 'From 10 to 48.', 'galaxie-woo' ),
				default: self::DEFAULTS['kit_badge_size'],
				step: '1',
				min: '10',
				max: '48'
			),
			new Field(
				key: 'kit_text_room_many',
				label: __( 'Room left (several)', 'galaxie-woo' ),
				type: Field::TYPE_TEXT,
				description: __( '{combos} becomes e.g. "2 × 190g ou 4 × 50g ou 1 × 190g + 2 × 50g". Used by the popup, the Buy Box toast and the Kit Progress widget.', 'galaxie-woo' ),
				default: self::DEFAULTS['kit_text_room_many']
			),
			new Field(
				key: 'kit_text_room_one',
				label: __( 'Room left (one candle)', 'galaxie-woo' ),
				type: Field::TYPE_TEXT,
				description: __( 'When a single candle is all that still fits.', 'galaxie-woo' ),
				default: self::DEFAULTS['kit_text_room_one']
			),
			new Field(
				key: 'kit_text_full',
				label: __( 'Box full', 'galaxie-woo' ),
				type: Field::TYPE_TEXT,
				default: self::DEFAULTS['kit_text_full']
			),
			new Field(
				key: 'kit_text_box_holds',
				label: __( 'What an empty box holds', 'galaxie-woo' ),
				type: Field::TYPE_TEXT,
				description: __( 'Under each box in the popup. {combos} as above.', 'galaxie-woo' ),
				default: self::DEFAULTS['kit_text_box_holds']
			),
			new Field(
				key: 'kit_text_added',
				label: __( 'Toast after "Adicionar ao kit"', 'galaxie-woo' ),
				type: Field::TYPE_TEXT,
				description: __( '{kit} is the kit\'s name, {room} the room-left sentence, {combos} the bare list.', 'galaxie-woo' ),
				default: self::DEFAULTS['kit_text_added']
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
			esc_html__( 'Kits: the "Montar um kit ou presente" button and its texts are set in the Galaxie Buy Box widget, section "Kit (Presente)", in Elementor. The popup\'s screens, texts and styling are the Galaxie Kit Builder widget, edited in the pixfort popup named above. The Galaxie Kit Progress widget can go anywhere.', 'galaxie-woo' )
		);

		echo '<h2>' . esc_html__( 'Kit popup', 'galaxie-woo' ) . '</h2>';

		$typed  = trim( (string) ( $values['kit_popup'] ?? '' ) );
		$legacy = absint( get_option( self::LEGACY_POPUP_OPTION, 0 ) );
		$popup  = self::kit_popup_id();

		if ( '' !== $typed && ! $popup ) {
			printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html__( '"Popup do kit" does not name a published pixfort popup: the kit button, launcher and progress widget have nothing to open.', 'galaxie-woo' ) );
		} elseif ( '' === $typed && $popup ) {
			/* translators: %s: pixfort popup link. */
			printf( '<p class="description">%s</p>', esc_html( sprintf( __( 'Using the popup the Buy Box widget was set to before (%s) until one is set above.', 'galaxie-woo' ), '#pix_popup_' . $legacy ) ) );
		} elseif ( ! $popup ) {
			printf( '<div class="notice notice-info inline"><p>%s</p></div>', esc_html__( 'No kit popup yet: the kit flow stays hidden on the store.', 'galaxie-woo' ) );
		}

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'In the popup\'s pixfort settings, turn "Launcher" on (and set its display conditions to every page) for the floating gift button. The popup keeps its own close button: the kit is saved as the shopper goes.', 'galaxie-woo' )
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

		// Kit flow. A popup link that names no popup is kept as typed (the tab
		// says so); colours that are neither a palette name nor a colour fall back.
		$values['kit_popup']        = trim( (string) $values['kit_popup'] );
		$values['kit_continue_url'] = esc_url_raw( trim( (string) $values['kit_continue_url'] ) );
		$values['kit_badge_size']   = (int) max( 10, min( 48, (int) $values['kit_badge_size'] ) );

		foreach ( array( 'kit_badge_bg', 'kit_badge_color' ) as $key ) {
			if ( '' === Kit\Launcher::colour( (string) $values[ $key ] ) ) {
				$values[ $key ] = self::DEFAULTS[ $key ];
			}
		}

		foreach ( array( 'kit_text_room_many', 'kit_text_room_one', 'kit_text_full', 'kit_text_box_holds', 'kit_text_added' ) as $key ) {
			if ( '' === trim( (string) $values[ $key ] ) ) {
				$values[ $key ] = self::DEFAULTS[ $key ];
			}
		}

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
