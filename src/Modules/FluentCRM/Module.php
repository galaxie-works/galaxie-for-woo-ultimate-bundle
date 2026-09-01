<?php
/**
 * FluentCRM module — contact sync + auto-discovered tag/list mapping.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\FluentCRM;

use Galaxie\Woo\Core\Field;
use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\ProvidesSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Syncs customers to FluentCRM and tags orders by status. Entirely
 * custom-rendered ({@see settings_fields()} is empty): v1 hardcoded tag/list
 * IDs (1–3, 36–39, a 4–35 interest range) as class constants, which breaks the
 * moment an environment's FluentCRM has different IDs — true here, since
 * staging is not a data clone of production. Instead this tab discovers the
 * store's actual tags/lists live from FluentCRM and lets the merchant map them
 * via dropdowns, so the same settings UI works on any install.
 */
final class Module implements ModuleContract, ProvidesSettings {

	private const SCALAR_KEYS = array(
		'signup_email_tag_id',
		'signup_google_tag_id',
		'newsletter_list_id',
		'customer_tag_id',
		'customers_list_id',
		'order_paid_tag_id',
		'order_cancelled_tag_id',
		'order_refunded_tag_id',
		'order_failed_tag_id',
	);

	public function id(): string {
		return 'fluentcrm';
	}

	public function title(): string {
		return __( 'FluentCRM', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Sync customers to FluentCRM (signup source, order status, interests) and manage newsletter opt-in.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		// Needs the FluentCRM plugin active and its tags/lists mapped to be useful.
		return false;
	}

	public function boot(): void {
		// TODO: port the sync logic from eir-my-account-ux
		// includes/class-eir-checkout-auth.php (sync_to_fluentcrm, tag_customer_on_order)
		// and includes/class-eir-fluentcrm-order-tags.php, reading tag/list IDs from
		// this module's settings instead of hardcoded class constants.
	}

	public function settings_tab_label(): string {
		return __( 'FluentCRM', 'galaxie-woo' );
	}

	/** @return Field[] Empty — this tab is entirely custom-rendered (live tag/list discovery). */
	public function settings_fields(): array {
		return array();
	}

	public function render_extra_settings( array $values ): void {
		if ( ! function_exists( 'FluentCrmApi' ) ) {
			?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'FluentCRM is not active. Activate it to map tags and lists.', 'galaxie-woo' ); ?></p>
			</div>
			<?php
			return;
		}

		[ $tags, $lists ] = $this->discover();

		if ( null === $tags || null === $lists ) {
			?>
			<div class="notice notice-error inline">
				<p><?php esc_html_e( 'Could not read tags/lists from FluentCRM. Try reloading this page.', 'galaxie-woo' ); ?></p>
			</div>
			<?php
			return;
		}

		echo '<h3>' . esc_html__( 'Signup', 'galaxie-woo' ) . '</h3>';
		$this->select_row( 'signup_email_tag_id', __( 'Tag: signed up via email', 'galaxie-woo' ), $tags, $values );
		$this->select_row( 'signup_google_tag_id', __( 'Tag: signed up via Google', 'galaxie-woo' ), $tags, $values );

		echo '<h3>' . esc_html__( 'Newsletter & Customers', 'galaxie-woo' ) . '</h3>';
		$this->select_row( 'newsletter_list_id', __( 'List: newsletter opt-in', 'galaxie-woo' ), $lists, $values );
		$this->select_row( 'customer_tag_id', __( 'Tag: customer (applied on paid order)', 'galaxie-woo' ), $tags, $values );
		$this->select_row( 'customers_list_id', __( 'List: customers (applied on paid order)', 'galaxie-woo' ), $lists, $values );

		echo '<h3>' . esc_html__( 'Order status tags', 'galaxie-woo' ) . '</h3>';
		$this->select_row( 'order_paid_tag_id', __( 'Tag: order paid', 'galaxie-woo' ), $tags, $values );
		$this->select_row( 'order_cancelled_tag_id', __( 'Tag: order cancelled', 'galaxie-woo' ), $tags, $values );
		$this->select_row( 'order_refunded_tag_id', __( 'Tag: order refunded', 'galaxie-woo' ), $tags, $values );
		$this->select_row( 'order_failed_tag_id', __( 'Tag: order failed', 'galaxie-woo' ), $tags, $values );

		echo '<h3>' . esc_html__( 'Interests', 'galaxie-woo' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Tags shown as selectable interests on My Account.', 'galaxie-woo' ) . '</p>';
		$selected_interests = array_map( 'strval', (array) ( $values['interest_tag_ids'] ?? array() ) );
		echo '<fieldset style="max-width:480px">';
		foreach ( $tags as $id => $label ) {
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="fields[interest_tag_ids][]" value="%1$s" %2$s /> %3$s</label>',
				esc_attr( (string) $id ),
				checked( in_array( (string) $id, $selected_interests, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
	}

	/**
	 * @param array<int|string,string> $options tag/list id => title.
	 * @param array<string,mixed>      $values  currently saved settings.
	 */
	private function select_row( string $key, string $label, array $options, array $values ): void {
		$selected = (string) ( $values[ $key ] ?? '' );
		echo '<p><label style="display:inline-block;min-width:320px">' . esc_html( $label ) . '</label> ';
		echo '<select name="' . esc_attr( 'fields[' . $key . ']' ) . '">';
		echo '<option value="">' . esc_html__( '— none —', 'galaxie-woo' ) . '</option>';
		foreach ( $options as $id => $option_label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( (string) $id ),
				selected( $selected, (string) $id, false ),
				esc_html( $option_label )
			);
		}
		echo '</select></p>';
	}

	/**
	 * Fetch tags and lists from FluentCRM as id => title maps for the dropdowns.
	 * Returns [null, null] if the FluentCRM API shape doesn't match what we
	 * expect (defensive — this needs re-verification the first time FluentCRM
	 * is actually active, since it can't be exercised until then).
	 *
	 * @return array{0: array<int,string>|null, 1: array<int,string>|null}
	 */
	private function discover(): array {
		try {
			$tags  = FluentCrmApi( 'tags' )->all(); // @phpstan-ignore-line -- FluentCRM global, not autoloadable.
			$lists = FluentCrmApi( 'lists' )->all(); // @phpstan-ignore-line

			$tag_map  = array();
			foreach ( $tags as $tag ) {
				$tag_map[ $tag->id ] = $tag->title;
			}
			$list_map = array();
			foreach ( $lists as $list ) {
				$list_map[ $list->id ] = $list->title;
			}

			return array( $tag_map, $list_map );
		} catch ( \Throwable $e ) {
			return array( null, null );
		}
	}

	public function sanitize_settings( array $submitted, array $current ): array {
		$sanitized = array();

		foreach ( self::SCALAR_KEYS as $key ) {
			$raw               = $submitted[ $key ] ?? '';
			$sanitized[ $key ] = '' === $raw ? '' : absint( $raw );
		}

		$interest_ids               = (array) ( $submitted['interest_tag_ids'] ?? array() );
		$sanitized['interest_tag_ids'] = array_values( array_filter( array_map( 'absint', $interest_ids ) ) );

		return $sanitized;
	}
}
