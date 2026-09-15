<?php
/**
 * "Galaxie Account Menu": the My Account menu on its own.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\MyAccount\Widget;

use Elementor\Widget_Base;
use Galaxie\Woo\Support\AccountParts;
use Galaxie\Woo\Support\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * The menu, for a page laid out by hand: in a sidebar column, in the header, above
 * a hero. Pairs with the Galaxie Account Content widget. The Galaxie Account
 * widget puts both together. The markup and controls are shared with it, see
 * {@see AccountParts}.
 */
final class AccountMenuWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-account-menu';
	}

	public function get_title(): string {
		return __( 'Galaxie Account Menu', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-nav-menu';
	}

	/** @return array<int,string> */
	public function get_categories(): array {
		return array( 'galaxie' );
	}

	/** @return array<int,string> */
	public function get_keywords(): array {
		return array( 'account', 'my account', 'menu', 'tabs', 'conta', 'woocommerce' );
	}

	protected function register_controls(): void {
		AccountParts::register_menu_controls( $this );
		AccountParts::register_menu_style_controls( $this );
	}

	protected function render(): void {
		Assets::enqueue();

		echo AccountParts::render_menu( $this->get_settings_for_display() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_menu().
	}
}
