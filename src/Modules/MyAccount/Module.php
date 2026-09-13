<?php
/**
 * My Account experience module — the "Galaxie My Account" Elementor widget.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\MyAccount;

use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\ProvidesBootData;
use Galaxie\Woo\Core\ProvidesElementorWidgets;
use Galaxie\Woo\Core\ProvidesSettings;
use Galaxie\Woo\Integrations\FluentCRM as FluentCRMApi;
use Galaxie\Woo\Modules\MyAccount\Widget\AccountAddressesWidget;
use Galaxie\Woo\Modules\MyAccount\Widget\AccountCommunicationWidget;
use Galaxie\Woo\Modules\MyAccount\Widget\AccountContentWidget;
use Galaxie\Woo\Modules\MyAccount\Widget\AccountDeleteWidget;
use Galaxie\Woo\Modules\MyAccount\Widget\AccountDetailsWidget;
use Galaxie\Woo\Modules\MyAccount\Widget\AccountInterestsWidget;
use Galaxie\Woo\Modules\MyAccount\Widget\AccountLayoutWidget;
use Galaxie\Woo\Modules\MyAccount\Widget\AccountMenuWidget;
use Galaxie\Woo\Modules\MyAccount\Widget\AccountOrderWidget;
use Galaxie\Woo\Modules\MyAccount\Widget\AccountOrdersWidget;
use Galaxie\Woo\Modules\MyAccount\Widget\AccountUserWidget;
use Galaxie\Woo\Modules\MyAccount\Widget\MyAccountWidget;
use Galaxie\Woo\Support\AccountEndpoints;
use Galaxie\Woo\Support\CustomerProfile;
use Galaxie\Woo\Support\Cpf;
use Galaxie\Woo\Support\ProfileFields;

defined( 'ABSPATH' ) || exit;

final class Module implements ModuleContract, ProvidesElementorWidgets, ProvidesBootData, ProvidesSettings {

	public const NONCE_ACTION = 'galaxie_woo_myaccount';

	public function id(): string {
		return 'my-account';
	}

	public function title(): string {
		return __( 'My Account', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Redesigned My Account (tabs, addresses, profile, interests, communication) as an Elementor widget.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return true;
	}

	public function boot(): void {
		AccountEndpoints::hooks();

		add_action( 'wp_ajax_galaxie_myaccount_screen', array( $this, 'ajax_screen' ) );
		add_action( 'wp_ajax_galaxie_myaccount_save_details', array( $this, 'ajax_save_details' ) );
		add_action( 'wp_ajax_galaxie_myaccount_toggle_interest', array( $this, 'ajax_toggle_interest' ) );
		add_action( 'wp_ajax_galaxie_myaccount_save_communication', array( $this, 'ajax_save_communication' ) );
	}

	public function boot_data(): array {
		return array(
			'myAccount' => array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			),
		);
	}

	/** @return string[] */
	public function elementor_widgets(): array {
		return array(
			AccountLayoutWidget::class,
			AccountMenuWidget::class,
			AccountContentWidget::class,
			AccountUserWidget::class,
			AccountOrdersWidget::class,
			AccountOrderWidget::class,
			AccountAddressesWidget::class,
			AccountDetailsWidget::class,
			AccountInterestsWidget::class,
			AccountCommunicationWidget::class,
			AccountDeleteWidget::class,
			MyAccountWidget::class,
		);
	}

	public function settings_tab_label(): string {
		return __( 'My Account', 'galaxie-woo' );
	}

	/** @return \Galaxie\Woo\Core\Field[] */
	public function settings_fields(): array {
		return array();
	}

	/**
	 * The screens table: one row per screen, and a list of our own below it.
	 *
	 * @param array<string,mixed> $values
	 */
	public function render_extra_settings( array $values ): void {
		$templates = AccountEndpoints::templates();
		$screens   = AccountEndpoints::all();
		$custom    = AccountEndpoints::custom_rows();
		$path      = (string) wp_parse_url( trailingslashit( (string) wc_get_page_permalink( 'myaccount' ) ), PHP_URL_PATH );
		?>
		<h2><?php esc_html_e( 'Screens', 'galaxie-woo' ); ?></h2>
		<p class="description" style="max-width:760px">
			<?php esc_html_e( 'Every screen of My Account. A hidden screen leaves the menu, and its address sends the customer back to the dashboard. The address of WooCommerce\'s own screens is the same setting as WooCommerce → Settings → Advanced. Each screen shows the Galaxie account widgets by default; pick an Elementor template to compose it yourself, or WooCommerce\'s own screen to go back to WooCommerce\'s markup.', 'galaxie-woo' ); ?>
		</p>

		<table class="widefat striped" style="max-width:1100px;margin-top:12px">
			<thead>
				<tr>
					<th style="width:18%"><?php esc_html_e( 'Screen', 'galaxie-woo' ); ?></th>
					<th style="width:6%"><?php esc_html_e( 'Show', 'galaxie-woo' ); ?></th>
					<th style="width:22%"><?php esc_html_e( 'Name', 'galaxie-woo' ); ?></th>
					<th style="width:28%"><?php esc_html_e( 'Address', 'galaxie-woo' ); ?></th>
					<th><?php esc_html_e( 'Template', 'galaxie-woo' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $screens as $key => $screen ) : ?>
				<?php
				if ( 'custom' === $screen['type'] ) {
					continue;
				}
				$name   = 'fields[endpoints][' . $key . ']';
				$locked = in_array( $key, array( 'dashboard', 'view-order' ), true );
				?>
				<tr>
					<td>
						<strong><?php echo esc_html( $screen['default_label'] ); ?></strong>
						<br /><code style="font-size:11px"><?php echo esc_html( $key ); ?></code>
						<?php if ( 'external' === $screen['type'] ) : ?>
							<br /><span class="description"><?php esc_html_e( 'Added by another plugin', 'galaxie-woo' ); ?></span>
						<?php elseif ( 'galaxie' === $screen['type'] ) : ?>
							<br /><span class="description"><?php esc_html_e( 'A Galaxie screen', 'galaxie-woo' ); ?></span>
						<?php elseif ( 'view-order' === $key ) : ?>
							<br /><span class="description"><?php esc_html_e( 'One order, opened from Orders', 'galaxie-woo' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $locked ) : ?>
							<input type="hidden" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" />
							<span class="dashicons dashicons-lock" title="<?php esc_attr_e( 'Always available', 'galaxie-woo' ); ?>"></span>
						<?php else : ?>
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( $screen['enabled'] ); ?> />
						<?php endif; ?>
					</td>
					<td>
						<input type="text" style="width:100%" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $screen['label'] !== $screen['default_label'] ? $screen['label'] : '' ); ?>" placeholder="<?php echo esc_attr( $screen['default_label'] ); ?>" />
					</td>
					<td>
						<?php if ( in_array( $screen['type'], array( 'core', 'galaxie' ), true ) ) : ?>
							<code style="font-size:11px"><?php echo esc_html( $path ); ?></code>
							<?php if ( 'dashboard' !== $key ) : ?>
								<input type="text" style="width:50%" name="<?php echo esc_attr( $name ); ?>[slug]" value="<?php echo esc_attr( $screen['slug'] ); ?>" />
							<?php endif; ?>
						<?php else : ?>
							<span class="description"><?php esc_html_e( 'Set by its plugin', 'galaxie-woo' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( 'customer-logout' === $key ) : ?>
							<span class="description"><?php esc_html_e( 'Signs the customer out', 'galaxie-woo' ); ?></span>
						<?php else : ?>
							<?php $this->template_select( $name . '[template]', $screen['template'], $templates, $key, $screen['type'] ); ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h2 style="margin-top:32px"><?php esc_html_e( 'Your own screens', 'galaxie-woo' ); ?></h2>
		<p class="description" style="max-width:760px">
			<?php esc_html_e( 'A screen of your own is a real address inside My Account, with the Elementor template you pick as its content: interests, a loyalty page, a size guide. Add it to the Galaxie Account Menu to link it.', 'galaxie-woo' ); ?>
		</p>

		<table class="widefat striped" style="max-width:1100px;margin-top:12px">
			<thead>
				<tr>
					<th style="width:6%"><?php esc_html_e( 'Show', 'galaxie-woo' ); ?></th>
					<th style="width:24%"><?php esc_html_e( 'Name', 'galaxie-woo' ); ?></th>
					<th style="width:30%"><?php esc_html_e( 'Address', 'galaxie-woo' ); ?></th>
					<th><?php esc_html_e( 'Template', 'galaxie-woo' ); ?></th>
					<th style="width:7%"></th>
				</tr>
			</thead>
			<tbody id="gxa-custom-rows">
				<?php foreach ( $custom as $i => $row ) : ?>
					<?php $this->custom_row( (string) $i, $row, $templates, $path ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><button type="button" class="button" id="gxa-custom-add"><?php esc_html_e( '+ Add screen', 'galaxie-woo' ); ?></button></p>

		<template id="gxa-custom-template">
			<?php $this->custom_row( '__INDEX__', array( 'key' => '', 'label' => '', 'slug' => '', 'template' => 0, 'enabled' => true ), $templates, $path ); ?>
		</template>

		<script>
		( function () {
			var rows = document.getElementById( 'gxa-custom-rows' );
			var tpl = document.getElementById( 'gxa-custom-template' );
			var next = rows.children.length;

			rows.addEventListener( 'click', function ( event ) {
				var remove = event.target.closest( '[data-role="remove"]' );
				if ( remove ) {
					event.preventDefault();
					remove.closest( 'tr' ).remove();
				}
			} );

			document.getElementById( 'gxa-custom-add' ).addEventListener( 'click', function () {
				var table = document.createElement( 'table' );
				table.innerHTML = '<tbody>' + tpl.innerHTML.replace( /__INDEX__/g, String( next++ ) ).trim() + '</tbody>';
				var row = table.querySelector( 'tr' );
				rows.appendChild( row );
				row.querySelector( 'input[type="text"]' ).focus();
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * @param array{key:string,label:string,slug:string,template:int,enabled:bool} $row
	 * @param array<int,string>                                                     $templates
	 */
	private function custom_row( string $index, array $row, array $templates, string $path ): void {
		$name = 'fields[custom][' . $index . ']';
		?>
		<tr>
			<td>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[key]" value="<?php echo esc_attr( $row['key'] ); ?>" />
				<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( $row['enabled'] ); ?> />
			</td>
			<td><input type="text" style="width:100%" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $row['label'] ); ?>" placeholder="<?php esc_attr_e( 'Interesses', 'galaxie-woo' ); ?>" /></td>
			<td>
				<code style="font-size:11px"><?php echo esc_html( $path ); ?></code>
				<input type="text" style="width:50%" name="<?php echo esc_attr( $name ); ?>[slug]" value="<?php echo esc_attr( $row['slug'] ); ?>" placeholder="<?php esc_attr_e( 'interesses', 'galaxie-woo' ); ?>" />
			</td>
			<td><?php $this->template_select( $name . '[template]', $row['template'], $templates, (string) $row['key'], 'custom' ); ?></td>
			<td><a href="#" class="button-link-delete" data-role="remove"><?php esc_html_e( 'Remove', 'galaxie-woo' ); ?></a></td>
		</tr>
		<?php
	}

	/** @param array<int,string> $templates */
	private function template_select( string $name, int $current, array $templates, string $key = '', string $type = 'core' ): void {
		$galaxie = AccountEndpoints::has_galaxie_default( $key );
		?>
		<select name="<?php echo esc_attr( $name ); ?>" style="max-width:80%">
			<?php if ( $galaxie ) : ?>
				<option value="0" <?php selected( $current, 0 ); ?>><?php esc_html_e( 'Galaxie screen', 'galaxie-woo' ); ?></option>
				<?php if ( 'core' === $type ) : ?>
					<option value="<?php echo esc_attr( (string) AccountEndpoints::NATIVE ); ?>" <?php selected( $current, AccountEndpoints::NATIVE ); ?>><?php esc_html_e( 'WooCommerce\'s own screen', 'galaxie-woo' ); ?></option>
				<?php endif; ?>
			<?php elseif ( 'custom' === $type ) : ?>
				<option value="0"><?php esc_html_e( 'Choose a template…', 'galaxie-woo' ); ?></option>
			<?php else : ?>
				<option value="0"><?php esc_html_e( 'WooCommerce\'s own screen', 'galaxie-woo' ); ?></option>
			<?php endif; ?>
			<?php foreach ( $templates as $id => $title ) : ?>
				<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $current, $id ); ?>><?php echo esc_html( $title ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php if ( $current > 0 ) : ?>
			<a href="<?php echo esc_url( add_query_arg( array( 'post' => $current, 'action' => 'elementor' ), admin_url( 'post.php' ) ) ); ?>" target="_blank" rel="noopener" style="margin-left:4px"><?php esc_html_e( 'Edit', 'galaxie-woo' ); ?></a>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param array<string,mixed> $submitted
	 * @param array<string,mixed> $current
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( array $submitted, array $current ): array {
		return array_merge( $current, AccountEndpoints::sanitize( $submitted ) );
	}

	public function ajax_save_details(): void {
		$this->check_nonce_and_login();
		$user_id = get_current_user_id();

		$first_name  = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$last_name   = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
		$social_name = isset( $_POST['social_name'] ) ? sanitize_text_field( wp_unslash( $_POST['social_name'] ) ) : '';
		$birthdate   = isset( $_POST['birthdate'] ) ? sanitize_text_field( wp_unslash( $_POST['birthdate'] ) ) : '';
		$cpf         = isset( $_POST['cpf'] ) ? sanitize_text_field( wp_unslash( $_POST['cpf'] ) ) : '';
		$gender      = isset( $_POST['gender'] ) ? sanitize_text_field( wp_unslash( $_POST['gender'] ) ) : '';

		if ( '' === $first_name || '' === $last_name ) {
			wp_send_json_error( array( 'message' => __( 'Please enter your first and last name.', 'galaxie-woo' ) ) );
		}
		if ( '' !== $cpf && ! Cpf::is_valid( $cpf ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid CPF.', 'galaxie-woo' ) ) );
		}
		if ( '' !== $birthdate && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $birthdate ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid date of birth.', 'galaxie-woo' ) ) );
		}
		if ( '' !== $gender && ! array_key_exists( $gender, ProfileFields::gender_options() ) ) {
			wp_send_json_error( array( 'message' => __( 'Please choose a valid option.', 'galaxie-woo' ) ) );
		}

		wp_update_user(
			array(
				'ID'         => $user_id,
				'first_name' => $first_name,
				'last_name'  => $last_name,
			)
		);
		update_user_meta( $user_id, 'billing_first_name', $first_name );
		update_user_meta( $user_id, 'billing_last_name', $last_name );
		update_user_meta( $user_id, ProfileFields::SOCIAL_NAME, $social_name );
		if ( '' !== $birthdate ) {
			update_user_meta( $user_id, ProfileFields::BIRTHDATE, $birthdate );
		}
		if ( '' !== $cpf ) {
			update_user_meta( $user_id, ProfileFields::CPF, Cpf::format( $cpf ) );
		}
		update_user_meta( $user_id, ProfileFields::GENDER, $gender );

		/** Fires after a My Account details save — FluentCRM's module re-syncs core fields on this. */
		do_action( 'galaxie_woo/profile_updated', $user_id );

		wp_send_json_success( CustomerProfile::status( $user_id ) );
	}

	/**
	 * One account screen, so the menu can swap it in instead of reloading.
	 *
	 * Returns the screen alone — not the box around it — because the wrapper on
	 * the page carries the merchant's surface classes and the selectors Elementor
	 * wrote against that widget. Replacing it would drop the styling.
	 */
	public function ajax_screen(): void {
		$this->check_nonce_and_login();

		$key = isset( $_POST['screen'] ) ? sanitize_key( wp_unslash( $_POST['screen'] ) ) : '';

		// Logging out is a real navigation: it ends the session the page is drawn
		// from, so it is never swapped in.
		if ( '' === $key || 'customer-logout' === $key || ! AccountEndpoints::get( $key ) || ! AccountEndpoints::is_enabled( $key ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown screen.', 'galaxie-woo' ) ), 404 );
		}

		$value    = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';
		$override = isset( $_POST['template'] ) ? absint( $_POST['template'] ) : 0;

		// The override travels from the clicked menu item, so it arrives as the
		// visitor's word. Only a template the site actually offers is honoured,
		// checked against the very list the pickers are built from — naming a post
		// type here instead would go stale the moment that list grows, and the
		// screen would quietly differ from the same screen loaded normally. On
		// anything else this falls back to what Galaxie → My Account says, which
		// is what a page render with no override would have shown anyway.
		if ( $override && ! isset( AccountEndpoints::templates()[ $override ] ) ) {
			$override = 0;
		}

		$title = function_exists( 'WC' ) && WC()->query
			? wp_strip_all_tags( (string) WC()->query->get_endpoint_title( $key ) )
			: '';

		wp_send_json_success(
			array(
				'screen' => $key,
				'title'  => '' !== $title ? $title : AccountEndpoints::label( $key ),
				'html'   => AccountEndpoints::render( $key, $value, $override ),
			)
		);
	}

	public function ajax_toggle_interest(): void {
		$this->check_nonce_and_login();
		$user = wp_get_current_user();

		$tag_id   = isset( $_POST['tag_id'] ) ? absint( $_POST['tag_id'] ) : 0;
		$selected = ! empty( $_POST['selected'] );

		if ( $tag_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid interest.', 'galaxie-woo' ) ) );
		}

		// An interest lives only in FluentCRM. Without it there is nowhere to
		// write the choice, and answering "saved" would show a pill as chosen
		// that is gone on the next visit.
		if ( ! FluentCRMApi::is_active() ) {
			wp_send_json_error( array( 'message' => __( 'Não foi possível salvar seus interesses agora. Tente de novo mais tarde.', 'galaxie-woo' ) ) );
		}

		if ( $selected ) {
			FluentCRMApi::attach_tags( $user->user_email, array( $tag_id ) );
		} else {
			FluentCRMApi::detach_tags( $user->user_email, array( $tag_id ) );
		}

		wp_send_json_success();
	}

	public function ajax_save_communication(): void {
		$this->check_nonce_and_login();
		$user_id = get_current_user_id();
		$user    = wp_get_current_user();

		$opt_in = ! empty( $_POST['opt_in'] );
		update_user_meta( $user_id, ProfileFields::MARKETING_OPT_IN, $opt_in ? 'yes' : 'no' );

		$fluent_settings = \Galaxie\Woo\Core\Plugin::instance()->settings()->module_settings( 'fluentcrm' );
		$list_id         = (int) ( $fluent_settings['newsletter_list_id'] ?? 0 );
		if ( $list_id > 0 ) {
			if ( $opt_in ) {
				FluentCRMApi::attach_lists( $user->user_email, array( $list_id ) );
			} else {
				FluentCRMApi::detach_lists( $user->user_email, array( $list_id ) );
			}
		}

		wp_send_json_success( array( 'optedIn' => $opt_in ) );
	}

	private function check_nonce_and_login(): void {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh and try again.', 'galaxie-woo' ) ), 403 );
		}
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Please sign in first.', 'galaxie-woo' ) ), 401 );
		}
	}
}
