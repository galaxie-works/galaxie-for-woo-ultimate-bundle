<?php
/**
 * "Galaxie Account Content": the current My Account screen.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\MyAccount\Widget;

use Elementor\Widget_Base;
use Galaxie\Woo\Support\AccountParts;
use Galaxie\Woo\Support\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * Whatever screen the address names: the dashboard, Orders, one order, an
 * address form, a screen of the merchant's own. Its template when it has one,
 * WooCommerce's screen when it does not.
 *
 * Belongs on the page set as My Account under WooCommerce → Settings →
 * Advanced, since that is the page the screen addresses hang off.
 */
final class AccountContentWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-account-content';
	}

	public function get_title(): string {
		return __( 'Galaxie Account Content', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-single-page';
	}

	/** @return array<int,string> */
	public function get_categories(): array {
		return array( 'galaxie' );
	}

	/** @return array<int,string> */
	public function get_keywords(): array {
		return array( 'account', 'my account', 'content', 'orders', 'conta', 'woocommerce' );
	}

	protected function register_controls(): void {
		AccountParts::register_content_controls( $this );
		AccountParts::register_content_style_controls( $this );
	}

	protected function render(): void {
		Assets::enqueue();

		echo AccountParts::render_content( $this->get_settings_for_display() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's and Elementor's own output.
	}
}
