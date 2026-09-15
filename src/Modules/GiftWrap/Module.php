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
use Galaxie\Woo\Core\ProvidesElementorWidgets;
use Galaxie\Woo\Core\ProvidesSettings;
use Galaxie\Woo\Modules\GiftWrap\Widget\GiftBuilderWidget;

defined( 'ABSPATH' ) || exit;

/**
 * A shopper ticks "Estou comprando um presente para alguém" in the Galaxie Buy
 * Box; Add to Cart and Buy Now then open a pixfort popup holding the Galaxie
 * Gift Builder before they act. The cart line carries the flag through to the
 * order, which wp-admin tags "Presente" — as it does a shared wish list's gift.
 *
 * The Buy Box's own "Gift (Presente)" section is where the storefront part is
 * configured, per widget; this module owns what is store-wide.
 *
 * Phase 1 is the flag alone. The settings below already hold what Phase 2's
 * boxes, fitting and accessories read (docs/gift-wrap-scope.md), so the tab does
 * not change shape when that lands.
 */
final class Module implements ModuleContract, ProvidesElementorWidgets, ProvidesSettings {

	public const ID = 'gift-wrap';

	/** Defaults for every setting, read through {@see self::setting()}. */
	private const DEFAULTS = array(
		'size_attribute'     => 'pa_peso',
		'packing_gap'        => 0.5,
		'allow_stacking'     => false,
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
		return __( 'A "this is a gift" checkbox in the Galaxie Buy Box, a gift builder popup before adding to cart, and a "Presente" tag on gift orders.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return false;
	}

	public function boot(): void {
		// The "Presente" badge in wp-admin is not booted here: Support\GiftOrders
		// runs from Plugin::boot() so gift orders keep it with this module off.
		Flag::hooks();

		// Phase 2 (branch feat/gift-wrap-packing): box variation fields — internal
		// size and max candles on each gift box variation. Booted here once that
		// class exists; nothing to do until then.
		if ( class_exists( BoxFields::class ) ) {
			( new BoxFields() )->register();
		}
	}

	public function elementor_widgets(): array {
		return array( GiftBuilderWidget::class );
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
				description: __( 'Room left around each candle for paper filling when checking what fits in a box.', 'galaxie-woo' ),
				default: self::DEFAULTS['packing_gap']
			),
			new Field(
				key: 'allow_stacking',
				label: __( 'Stacking allowed', 'galaxie-woo' ),
				type: Field::TYPE_TOGGLE,
				description: __( 'Let candles be stacked in a box. Off: candles stand upright, side by side.', 'galaxie-woo' ),
				default: self::DEFAULTS['allow_stacking']
			),
			new Field(
				key: 'box_categories',
				label: __( 'Gift box categories', 'galaxie-woo' ),
				type: Field::TYPE_MULTI,
				description: __( 'Products in these categories are offered as gift boxes. The Gift Builder widget can override this.', 'galaxie-woo' ),
				default: self::DEFAULTS['box_categories'],
				options: $categories
			),
			new Field(
				key: 'ribbon_categories',
				label: __( 'Ribbon categories', 'galaxie-woo' ),
				type: Field::TYPE_MULTI,
				description: __( 'Products in these categories are offered as ribbons. The Gift Builder widget can override this.', 'galaxie-woo' ),
				default: self::DEFAULTS['ribbon_categories'],
				options: $categories
			),
			new Field(
				key: 'card_categories',
				label: __( 'Card categories', 'galaxie-woo' ),
				type: Field::TYPE_MULTI,
				description: __( 'Products in these categories are offered as cards with a message. The Gift Builder widget can override this.', 'galaxie-woo' ),
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
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Boxes, ribbons and cards are not offered yet: this version only marks purchases as gifts. The product page part — the checkbox, its popup and its styling — is set in the Galaxie Buy Box widget, section "Gift (Presente)", in Elementor.', 'galaxie-woo' )
		);
	}

	public function sanitize_settings( array $submitted, array $current ): array {
		$values = array_merge( $current, Field::sanitize_all( $this->settings_fields(), $submitted ) );

		$values['size_attribute']   = '' !== sanitize_title( (string) $values['size_attribute'] ) ? sanitize_title( (string) $values['size_attribute'] ) : self::DEFAULTS['size_attribute'];
		$values['packing_gap']      = max( 0.0, (float) $values['packing_gap'] );
		$values['card_message_max'] = max( 1, (int) $values['card_message_max'] );

		return $values;
	}

	/** @return array<string,string> product category id => name, parents before children. */
	private static function category_options(): array {
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
