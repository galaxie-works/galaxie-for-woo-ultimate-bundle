<?php
/**
 * Variation Swatches module — turns variation <select> dropdowns into clickable badges.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\VariationSwatches;

use Galaxie\Woo\Core\Field;
use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\Plugin;
use Galaxie\Woo\Core\ProvidesBootData;
use Galaxie\Woo\Core\ProvidesElementorWidgets;
use Galaxie\Woo\Core\ProvidesSettings;
use Galaxie\Woo\Modules\VariationSwatches\Widget\VariationBadgesWidget;
use Galaxie\Woo\Support\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * A pure progressive-enhancement module, not a widget: WooCommerce's native
 * product page already renders a `<select>` per variation attribute (e.g.
 * "Peso" → 50g/190g) and its own JS reads that select's value to match a
 * variation and recalculate price/stock/image. We don't replace any of that —
 * we hide the select (kept in the DOM, still the source of truth) and render
 * badges next to it that set the select's value + dispatch `change`, so every
 * bit of WooCommerce's and the theme's own variation logic keeps working
 * untouched. Styling is pixfort-token-aware (falls back to neutral values on
 * any other theme), matching the rest of the bundle's design system.
 *
 * Ships theme-independent on purpose — this was going to be "install YITH or
 * ShopLentor for swatches", but the whole point of the bundle is not needing
 * a third-party suite for a WooCommerce storefront behavior this contained.
 *
 * Also ships an Elementor widget ({@see VariationBadgesWidget}) for when the
 * automatic behavior above (which just skins whatever `<select>` already
 * exists on the page) isn't enough — Wagner wanted the label/badges
 * themselves to be styled through pixfort's own Text/Badge component system
 * (the same global color dropdown — Primary, Gray 1-9, Dynamic Colors — every
 * other pixfort widget uses), not a separate hardcoded palette. That widget
 * literally renders through pixfort-core's own component functions.
 */
final class Module implements ModuleContract, ProvidesBootData, ProvidesElementorWidgets, ProvidesSettings {

	public function id(): string {
		return 'variation-swatches';
	}

	public function title(): string {
		return __( 'Variation Swatches', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Turns variation dropdowns (weight, color, size…) into clickable badges on the product page.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		// Off by default — it visibly changes the native product page the
		// moment it's on, so that should be a deliberate opt-in.
		return false;
	}

	public function boot(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	public function maybe_enqueue(): void {
		if ( function_exists( 'is_product' ) && is_product() ) {
			Assets::enqueue();
		}
	}

	public function elementor_widgets(): array {
		return array( VariationBadgesWidget::class );
	}

	public function boot_data(): array {
		$values = Plugin::instance()->settings()->module_settings( $this->id() );

		return array(
			'variationSwatches' => array(
				'attributes' => $this->attribute_slugs( $values ),
			),
		);
	}

	public function settings_tab_label(): string {
		return __( 'Variation Swatches', 'galaxie-woo' );
	}

	/** @return Field[] */
	public function settings_fields(): array {
		return array(
			new Field(
				key: 'attributes',
				label: __( 'Attributes', 'galaxie-woo' ),
				type: Field::TYPE_TEXT,
				description: __( 'Comma-separated attribute slugs to render as badges (e.g. pa_peso, pa_cor). Every other variation attribute keeps the native dropdown.', 'galaxie-woo' ),
				default: 'pa_peso',
				placeholder: 'pa_peso'
			),
		);
	}

	public function render_extra_settings( array $values ): void {}

	public function sanitize_settings( array $submitted, array $current ): array {
		return Field::sanitize_all( $this->settings_fields(), $submitted );
	}

	/** @param array<string,mixed> $values */
	private function attribute_slugs( array $values ): array {
		$raw = (string) ( $values['attributes'] ?? 'pa_peso' );
		$slugs = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		return array_values( $slugs );
	}
}
