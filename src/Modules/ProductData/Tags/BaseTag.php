<?php
/**
 * Shared behaviour for the Galaxie product dynamic tags.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\ProductData\Tags;

use Elementor\Controls_Manager;
use Elementor\Core\DynamicTags\Tag;
use Elementor\Modules\DynamicTags\Module as TagsModule;

defined( 'ABSPATH' ) || exit;

/**
 * A dynamic tag renders on the SERVER, once, while the page is built. On a
 * variable product that is a problem this base exists to solve: at that moment
 * no variation is chosen and `$product` is the parent, so anything living on
 * the variation — its weight, its dimensions, the one attribute value the
 * customer picked — renders empty or renders all of them at once.
 *
 * So each tag prints its server value inside a marked span, and the front-end
 * script rewrites that span on WooCommerce's `found_variation`, restoring the
 * original on `reset_data`. The span is opt-out: a merchant who wants the
 * parent's value and nothing else turns the switch off and gets bare text.
 */
abstract class BaseTag extends Tag {

	public const GROUP = 'galaxie';

	public function get_group(): string {
		return self::GROUP;
	}

	/**
	 * @return array<int,string>
	 */
	public function get_categories(): array {
		return array( TagsModule::TEXT_CATEGORY );
	}

	/**
	 * The product being rendered.
	 *
	 * `wc_get_product()` with no argument reads the global, which the theme
	 * builder sets for the product being previewed as well as for a real
	 * request — so this works in the editor canvas too.
	 */
	protected function product(): ?\WC_Product {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product();

		return $product instanceof \WC_Product ? $product : null;
	}

	/**
	 * The switch every one of these tags carries.
	 */
	protected function add_follow_control(): void {
		$this->add_control(
			'follow_variation',
			array(
				'label'        => __( 'Follow selected variation', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'description'  => __( 'Updates the value when the shopper picks a variation. Turn off to always show the parent product\'s value.', 'galaxie-woo' ),
				'return_value' => 'yes',
			)
		);
	}

	/**
	 * Wrap a server-rendered value so the script can rewrite it in place.
	 *
	 * Returned as plain text when following is off, because a bare value is
	 * what a merchant asked for and an inert span in the markup is noise. Also
	 * returned plain when the product is not variable — there is nothing to
	 * follow, and the span would only get in the way of a link or an attribute
	 * some other widget builds from this text.
	 *
	 * @param array<string,string> $data
	 */
	protected function wrap( string $value, string $kind, array $data = array() ): string {
		$product = $this->product();
		$follow  = 'yes' === ( $this->get_settings( 'follow_variation' ) ?: 'yes' );

		if ( ! $follow || ! $product || ! $product->is_type( 'variable' ) ) {
			return $value;
		}

		$attributes = ' data-galaxie-dyn="' . esc_attr( $kind ) . '"';

		foreach ( $data as $key => $item ) {
			$attributes .= ' data-galaxie-' . esc_attr( $key ) . '="' . esc_attr( $item ) . '"';
		}

		return '<span class="galaxie-dyn"' . $attributes . '>' . $value . '</span>';
	}
}
