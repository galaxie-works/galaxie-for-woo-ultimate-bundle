<?php
/**
 * "Galaxie My Account" Elementor widget.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\MyAccount\Widget;

use Galaxie\Woo\Core\Plugin;
use Galaxie\Woo\Elementor\AbstractIslandWidget;
use Galaxie\Woo\Integrations\FluentCRM as FluentCRMApi;
use Galaxie\Woo\Support\Assets;
use Galaxie\Woo\Support\ProfileFields;

defined( 'ABSPATH' ) || exit;

/**
 * Hybrid, deliberately scoped this way: WooCommerce's My Account is several
 * endpoints (dashboard, orders, addresses, payment-methods, account-details),
 * each really its own screen. Rebuilding all of them is out of proportion for
 * this pass — the "Account details" screen (with the tabs Wagner cares most
 * about: Dados pessoais / Interesses / Comunicação / Conta) is the one that
 * gets a full custom React island. Every other endpoint relocates the native
 * `.woocommerce-MyAccount-content` (rendered hidden, alongside our own nav)
 * into a styled mount instead — same relocate-a-live-node mechanism as
 * Checkout, just without React managing that side. Visual redesign of
 * Orders/Addresses/Payment-methods is a follow-up, not a restructuring.
 *
 * Logged-out visitors see the native shortcode's own login form, unchanged —
 * v1 never customized My Account's login gate either (only checkout got the
 * OTP treatment).
 */
final class MyAccountWidget extends AbstractIslandWidget {

	public function get_name(): string {
		return 'galaxie-my-account';
	}

	public function get_title(): string {
		return __( 'Galaxie My Account', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-person';
	}

	protected function island_name(): string {
		return 'my-account';
	}

	protected function render(): void {
		if ( class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			echo '<div style="padding:2rem;text-align:center;border:1px dashed #ccc;border-radius:8px;">';
			esc_html_e( 'Galaxie My Account — renders the live account area on the front-end.', 'galaxie-woo' );
			echo '</div>';
			return;
		}

		if ( ! is_user_logged_in() ) {
			// Unchanged native behavior — the shortcode renders WooCommerce's own login form.
			echo do_shortcode( '[woocommerce_my_account]' );
			return;
		}

		Assets::enqueue();

		$endpoint = ( function_exists( 'WC' ) && WC()->query ) ? WC()->query->get_current_endpoint() : '';
		$is_custom = in_array( $endpoint, array( '', 'edit-account' ), true );

		echo '<div class="galaxie-myaccount">';

		echo '<div data-galaxie-native-myaccount hidden>';
		echo do_shortcode( '[woocommerce_my_account]' );
		echo '</div>';

		echo '<div class="galaxie-myaccount-shell flex flex-col gap-6 md:flex-row md:gap-10">';
		$this->render_nav( $endpoint );
		echo '<div class="min-w-0 flex-1">';

		if ( $is_custom ) {
			printf(
				'<div class="galaxie-ui" data-galaxie-island="%s" data-galaxie-props="%s"></div>',
				esc_attr( $this->island_name() ),
				esc_attr( (string) wp_json_encode( $this->island_props() ) )
			);
		} else {
			echo '<div data-galaxie-relocate-myaccount-content></div>';
			?>
			<script>
			(function () {
				var native = document.querySelector('[data-galaxie-native-myaccount] .woocommerce-MyAccount-content');
				var mount = document.currentScript.previousElementSibling;
				if (native && mount) {
					mount.appendChild(native);
				}
			})();
			</script>
			<?php
		}

		echo '</div></div></div>';
	}

	private function render_nav( string $current_endpoint ): void {
		if ( ! function_exists( 'wc_get_account_menu_items' ) ) {
			return;
		}
		echo '<nav class="flex shrink-0 flex-row gap-1 overflow-x-auto md:w-56 md:flex-col md:overflow-visible">';
		foreach ( wc_get_account_menu_items() as $item_endpoint => $label ) {
			$is_logout = 'customer-logout' === $item_endpoint;
			$url       = $is_logout ? wc_logout_url() : wc_get_account_endpoint_url( $item_endpoint );
			$is_active = ! $is_logout && $item_endpoint === $current_endpoint;
			printf(
				'<a href="%1$s" class="%2$s">%3$s</a>',
				esc_url( $url ),
				$is_active
					? 'rounded-md bg-primary px-3 py-2 text-sm font-medium whitespace-nowrap text-primary-foreground'
					: 'rounded-md px-3 py-2 text-sm whitespace-nowrap text-muted-foreground hover:bg-accent hover:text-accent-foreground',
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	protected function island_props(): array {
		$user            = wp_get_current_user();
		$fluent_settings = Plugin::instance()->settings()->module_settings( 'fluentcrm' );
		$interest_rows   = (array) ( $fluent_settings['interest_options'] ?? array() );

		return array(
			'values'        => array(
				'first_name'  => $user->first_name,
				'last_name'   => $user->last_name,
				'social_name' => get_user_meta( $user->ID, ProfileFields::SOCIAL_NAME, true ),
				'birthdate'   => get_user_meta( $user->ID, ProfileFields::BIRTHDATE, true ),
				'cpf'         => get_user_meta( $user->ID, ProfileFields::CPF, true ),
				'gender'      => get_user_meta( $user->ID, ProfileFields::GENDER, true ),
				'email'       => $user->user_email,
			),
			'genderOptions' => ProfileFields::gender_options(),
			'interests'     => array(
				'enabled'  => ! empty( $fluent_settings['interests_enabled'] ),
				'options'  => array_map(
					static fn( $row ) => array(
						'tagId'   => (int) ( $row['tag_id'] ?? 0 ),
						'label'   => (string) ( $row['label'] ?? '' ),
						'icon'    => (string) ( $row['icon'] ?? '' ),
						'iconUrl' => (string) ( $row['icon_url'] ?? '' ),
					),
					$interest_rows
				),
				'selected' => array_map( 'intval', FluentCRMApi::contact_tag_ids( $user->user_email ) ),
			),
			'communication' => array(
				'optedIn' => 'yes' === get_user_meta( $user->ID, ProfileFields::MARKETING_OPT_IN, true ),
			),
			'accountDeletionEnabled' => Plugin::instance()->modules()->is_enabled_by_id( 'account-deletion' ),
			'i18n'          => array(
				'genericError'       => __( 'Something went wrong. Please try again.', 'galaxie-woo' ),
				'deleteModalTitle'   => __( 'Delete your account?', 'galaxie-woo' ),
				'deleteModalBody'    => __( 'Your account will be deactivated immediately and permanently deleted after 6 months. Signing back in during that window cancels the deletion.', 'galaxie-woo' ),
				'deleteModalCancel'  => __( 'Cancel', 'galaxie-woo' ),
				'deleteModalConfirm' => __( 'Delete my account', 'galaxie-woo' ),
			),
		);
	}
}
