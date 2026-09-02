<?php
/**
 * FluentCRM module — contact sync, order-status tags, and a curated "Interests" builder.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\FluentCRM;

use Galaxie\Woo\Core\Field;
use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\ProvidesSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Two different things live on this tab, and they must not be conflated (per
 * Wagner, 2026-09-01):
 *
 * 1. **Automation mapping** (signup source, newsletter opt-in, order status)
 *    — FluentCRM tags/lists applied automatically by our code reacting to
 *    events. v1 hardcoded these tag/list IDs as class constants, which breaks
 *    across environments (confirmed: staging's tag IDs won't match prod's).
 *    This tab discovers the real tags/lists live and lets the merchant map
 *    them via dropdowns instead.
 *
 * 2. **Interests** — a curated list of options (icon + label, each linked to a
 *    FluentCRM tag) that the CUSTOMER explicitly selects in My Account. This is
 *    self-reported preference, not inferred/automated behavior (FluentCRM's
 *    own automations can already infer things like "buys lavender often" —
 *    that's a different, lower-confidence signal). The merchant builds this
 *    list here: type a label (autocompleted against existing tags — pick an
 *    existing one, or a brand new title creates the tag in FluentCRM on save)
 *    plus an emoji/icon. The front-end picker (ported from
 *    eir-my-account-ux's interests UI) displays the list alphabetically by
 *    label and syncs the customer's selection to their FluentCRM tags.
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
		return __( 'Sync customers to FluentCRM (signup source, order status) and let customers declare interests.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		// Needs the FluentCRM plugin active and its tags/lists mapped to be useful.
		return false;
	}

	public function boot(): void {
		// TODO: port the sync logic from eir-my-account-ux
		// includes/class-eir-checkout-auth.php (sync_to_fluentcrm, tag_customer_on_order)
		// and includes/class-eir-fluentcrm-order-tags.php, reading tag/list IDs from
		// this module's settings instead of hardcoded class constants. The Interests
		// front-end picker (My Account) reads `interest_options` from settings and,
		// on toggle, attach/detach the linked tag_id — same AJAX shape as v1's
		// eir_toggle_interest.
	}

	public function settings_tab_label(): string {
		return __( 'FluentCRM', 'galaxie-woo' );
	}

	/** @return Field[] Just the Interests on/off — everything else on this tab is custom-rendered. */
	public function settings_fields(): array {
		return array(
			new Field(
				key: 'interests_enabled',
				label: __( 'User interests', 'galaxie-woo' ),
				type: Field::TYPE_TOGGLE,
				description: __( 'Let customers declare interests (e.g. "Lavanda") in My Account. This is self-reported — a customer explicitly saying what they like, not FluentCRM inferring it from behavior.', 'galaxie-woo' ),
				default: false
			),
		);
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
		echo '<p class="description">' . esc_html__( 'FluentCRM\'s own funnel automation has a known bug for order-status triggers, so this plugin applies these tags directly instead.', 'galaxie-woo' ) . '</p>';
		$this->select_row( 'order_paid_tag_id', __( 'Tag: order paid', 'galaxie-woo' ), $tags, $values );
		$this->select_row( 'order_cancelled_tag_id', __( 'Tag: order cancelled', 'galaxie-woo' ), $tags, $values );
		$this->select_row( 'order_refunded_tag_id', __( 'Tag: order refunded', 'galaxie-woo' ), $tags, $values );
		$this->select_row( 'order_failed_tag_id', __( 'Tag: order failed', 'galaxie-woo' ), $tags, $values );

		$this->render_interests_builder( $tags, $values );
	}

	/**
	 * @param array<int,string>   $tags   tag id => title, for the autocomplete suggestions.
	 * @param array<string,mixed> $values currently saved settings.
	 */
	private function render_interests_builder( array $tags, array $values ): void {
		$options = array_values( (array) ( $values['interest_options'] ?? array() ) );
		?>
		<h3><?php esc_html_e( 'Interests', 'galaxie-woo' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'The curated list customers pick from in My Account → Interests, shown to them in alphabetical order. Each row is an icon/emoji + a label. Type a label — pick a suggestion to link an existing FluentCRM tag, or type a new name to create one when you save.', 'galaxie-woo' ); ?>
		</p>

		<datalist id="gxf-interest-tag-suggestions">
			<?php foreach ( $tags as $tag_title ) : ?>
				<option value="<?php echo esc_attr( $tag_title ); ?>"></option>
			<?php endforeach; ?>
		</datalist>
		<script type="application/json" id="gxf-interest-tag-map"><?php echo wp_json_encode( array_flip( array_map( 'strtolower', $tags ) ) ); ?></script>

		<div id="gxf-interests-rows">
			<?php foreach ( $options as $i => $option ) : ?>
				<?php $this->render_interest_row( (int) $i, (array) $option ); ?>
			<?php endforeach; ?>
		</div>

		<p>
			<button type="button" class="button" id="gxf-interests-add"><?php esc_html_e( '+ Add interest', 'galaxie-woo' ); ?></button>
		</p>

		<template id="gxf-interest-row-template">
			<?php $this->render_interest_row( '__INDEX__', array() ); ?>
		</template>

		<script>
		(function () {
			var rows = document.getElementById( 'gxf-interests-rows' );
			var tpl = document.getElementById( 'gxf-interest-row-template' );
			var addBtn = document.getElementById( 'gxf-interests-add' );
			var tagMap = JSON.parse( document.getElementById( 'gxf-interest-tag-map' ).textContent || '{}' );
			var enabledToggle = document.getElementById( 'gxf-interests_enabled' );
			var nextIndex = rows.children.length;

			function wireRow( row ) {
				var labelInput = row.querySelector( '[data-role="label"]' );
				var tagIdInput = row.querySelector( '[data-role="tag_id"]' );
				var removeBtn = row.querySelector( '[data-role="remove"]' );

				labelInput.addEventListener( 'change', function () {
					var match = tagMap[ labelInput.value.trim().toLowerCase() ];
					tagIdInput.value = ( undefined !== match ) ? match : '';
				} );
				removeBtn.addEventListener( 'click', function () {
					row.remove();
				} );
			}

			Array.prototype.forEach.call( rows.children, wireRow );

			addBtn.addEventListener( 'click', function () {
				var html = tpl.innerHTML.replace( /__INDEX__/g, String( nextIndex++ ) );
				var wrapper = document.createElement( 'div' );
				wrapper.innerHTML = html.trim();
				var row = wrapper.firstElementChild;
				rows.appendChild( row );
				wireRow( row );
				row.querySelector( '[data-role="label"]' ).focus();
			} );

			function syncVisibility() {
				rows.style.display = enabledToggle.checked ? '' : 'none';
				addBtn.style.display = enabledToggle.checked ? '' : 'none';
			}
			if ( enabledToggle ) {
				enabledToggle.addEventListener( 'change', syncVisibility );
				syncVisibility();
			}
		})();
		</script>
		<?php
	}

	/** @param int|string $index Row index (or the `__INDEX__` template placeholder). */
	private function render_interest_row( $index, array $option ): void {
		$tag_id = $option['tag_id'] ?? '';
		$label  = $option['label'] ?? '';
		$icon   = $option['icon'] ?? '';
		?>
		<div class="gxf-interest-row" style="display:flex;gap:8px;align-items:center;margin-bottom:6px;">
			<input type="hidden" data-role="tag_id" name="<?php echo esc_attr( "fields[interests][{$index}][tag_id]" ); ?>" value="<?php echo esc_attr( (string) $tag_id ); ?>" />
			<input
				type="text"
				data-role="icon"
				name="<?php echo esc_attr( "fields[interests][{$index}][icon]" ); ?>"
				value="<?php echo esc_attr( (string) $icon ); ?>"
				placeholder="🪻"
				style="width:60px;text-align:center;"
			/>
			<input
				type="text"
				data-role="label"
				list="gxf-interest-tag-suggestions"
				name="<?php echo esc_attr( "fields[interests][{$index}][label]" ); ?>"
				value="<?php echo esc_attr( (string) $label ); ?>"
				placeholder="<?php esc_attr_e( 'e.g. Lavanda', 'galaxie-woo' ); ?>"
				class="regular-text"
			/>
			<button type="button" class="button-link-delete" data-role="remove"><?php esc_html_e( 'Remove', 'galaxie-woo' ); ?></button>
		</div>
		<?php
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
	 * Fetch tags and lists from FluentCRM's own Eloquent models (the same
	 * mechanism FluentCRM's own admin UI uses internally) as id => title maps.
	 * Returns [null, null] on any failure — defensive, since this is exercised
	 * for the first time only once FluentCRM is actually active.
	 *
	 * @return array{0: array<int,string>|null, 1: array<int,string>|null}
	 */
	private function discover(): array {
		if ( ! class_exists( '\FluentCrm\App\Models\Tag' ) || ! class_exists( '\FluentCrm\App\Models\Lists' ) ) {
			return array( null, null );
		}
		try {
			$tag_map = array();
			foreach ( \FluentCrm\App\Models\Tag::orderBy( 'title' )->get() as $tag ) {
				$tag_map[ (int) $tag->id ] = (string) $tag->title;
			}
			$list_map = array();
			foreach ( \FluentCrm\App\Models\Lists::orderBy( 'title' )->get() as $list ) {
				$list_map[ (int) $list->id ] = (string) $list->title;
			}
			return array( $tag_map, $list_map );
		} catch ( \Throwable $e ) {
			return array( null, null );
		}
	}

	/** Find an existing tag by exact (case-insensitive) title, or create one. Returns 0 on failure. */
	private function find_or_create_tag( string $label ): int {
		if ( ! class_exists( '\FluentCrm\App\Models\Tag' ) ) {
			return 0;
		}
		try {
			foreach ( \FluentCrm\App\Models\Tag::all() as $tag ) {
				if ( 0 === strcasecmp( (string) $tag->title, $label ) ) {
					return (int) $tag->id;
				}
			}
			$tag = \FluentCrm\App\Models\Tag::create(
				array(
					'title' => $label,
					'slug'  => sanitize_title( $label ),
				)
			);
			return (int) $tag->id;
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	public function sanitize_settings( array $submitted, array $current ): array {
		$sanitized = Field::sanitize_all( $this->settings_fields(), $submitted );

		foreach ( self::SCALAR_KEYS as $key ) {
			$raw               = $submitted[ $key ] ?? '';
			$sanitized[ $key ] = '' === $raw ? '' : absint( $raw );
		}

		$sanitized['interest_options'] = $this->sanitize_interests( (array) ( $submitted['interests'] ?? array() ) );

		return $sanitized;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array{tag_id:int,label:string,icon:string}>
	 */
	private function sanitize_interests( array $rows ): array {
		$out       = array();
		$seen_tags = array();

		foreach ( $rows as $row ) {
			$label = sanitize_text_field( trim( (string) ( $row['label'] ?? '' ) ) );
			if ( '' === $label ) {
				continue; // Blank row (e.g. added then left empty).
			}

			$icon   = sanitize_text_field( trim( (string) ( $row['icon'] ?? '' ) ) );
			$tag_id = absint( $row['tag_id'] ?? 0 );

			if ( $tag_id <= 0 ) {
				$tag_id = $this->find_or_create_tag( $label );
			}
			if ( $tag_id <= 0 || isset( $seen_tags[ $tag_id ] ) ) {
				continue; // Couldn't resolve/create a tag, or a duplicate.
			}

			$seen_tags[ $tag_id ] = true;
			$out[]                = array(
				'tag_id' => $tag_id,
				'label'  => $label,
				'icon'   => $icon,
			);
		}

		return $out;
	}
}
