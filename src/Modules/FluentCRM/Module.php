<?php
/**
 * FluentCRM module — contact sync, order-status tags, and a curated "Interests" builder.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\FluentCRM;

use Galaxie\Woo\Core\Field;
use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\Plugin;
use Galaxie\Woo\Core\ProvidesSettings;
use Galaxie\Woo\Integrations\FluentCRM as FluentCRMApi;

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
		// Ported from eir-my-account-ux's sync_to_fluentcrm() + the separate
		// order-tags class — both decoupled via action hooks (fired by
		// PasswordlessAuth/MyAccount) rather than those modules calling this one
		// directly, so this module can be off without breaking them.
		add_action( 'galaxie_woo/customer_registered', array( $this, 'on_customer_registered' ), 10, 2 );
		add_action( 'galaxie_woo/profile_updated', array( $this, 'on_profile_updated' ) );

		add_action( 'woocommerce_order_status_processing', array( $this, 'on_order_paid' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'on_order_paid' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'on_order_cancelled' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'on_order_refunded' ) );
		add_action( 'woocommerce_order_status_failed', array( $this, 'on_order_failed' ) );
	}

	/** @param string $source 'email' or 'google'. */
	public function on_customer_registered( int $user_id, string $source ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$settings = $this->settings();

		FluentCRMApi::sync_contact(
			$user->user_email,
			array(
				'first_name' => $user->first_name,
				'last_name'  => $user->last_name,
				'status'     => 'subscribed',
			)
		);

		$tag_key = 'google' === $source ? 'signup_google_tag_id' : 'signup_email_tag_id';
		$tag_id  = (int) ( $settings[ $tag_key ] ?? 0 );
		if ( $tag_id > 0 ) {
			FluentCRMApi::attach_tags( $user->user_email, array( $tag_id ) );
		}

		if ( 'yes' === get_user_meta( $user_id, \Galaxie\Woo\Support\ProfileFields::MARKETING_OPT_IN, true ) ) {
			$list_id = (int) ( $settings['newsletter_list_id'] ?? 0 );
			if ( $list_id > 0 ) {
				FluentCRMApi::attach_lists( $user->user_email, array( $list_id ) );
			}
		}
	}

	/** Re-syncs core fields after a My Account details edit — never re-applies signup/tag logic. */
	public function on_profile_updated( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		FluentCRMApi::sync_contact(
			$user->user_email,
			array(
				'first_name' => $user->first_name,
				'last_name'  => $user->last_name,
			)
		);
	}

	public function on_order_paid( int $order_id ): void {
		$this->tag_order( $order_id, 'order_paid_tag_id', true );
	}

	public function on_order_cancelled( int $order_id ): void {
		$this->tag_order( $order_id, 'order_cancelled_tag_id' );
	}

	public function on_order_refunded( int $order_id ): void {
		$this->tag_order( $order_id, 'order_refunded_tag_id' );
	}

	public function on_order_failed( int $order_id ): void {
		$this->tag_order( $order_id, 'order_failed_tag_id' );
	}

	/** @param bool $also_customer Also apply the "customer" tag/list (only on a paid order). */
	private function tag_order( int $order_id, string $tag_key, bool $also_customer = false ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$email = $order->get_billing_email();
		if ( ! $email ) {
			return;
		}

		$settings = $this->settings();
		$tags     = array();

		$tag_id = (int) ( $settings[ $tag_key ] ?? 0 );
		if ( $tag_id > 0 ) {
			$tags[] = $tag_id;
		}
		if ( $also_customer ) {
			$customer_tag = (int) ( $settings['customer_tag_id'] ?? 0 );
			if ( $customer_tag > 0 ) {
				$tags[] = $customer_tag;
			}
		}
		if ( ! empty( $tags ) ) {
			FluentCRMApi::attach_tags( $email, $tags );
		}

		if ( $also_customer ) {
			$list_id = (int) ( $settings['customers_list_id'] ?? 0 );
			if ( $list_id > 0 ) {
				FluentCRMApi::attach_lists( $email, array( $list_id ) );
			}
		}
	}

	/** @return array<string,mixed> */
	private function settings(): array {
		return Plugin::instance()->settings()->module_settings( $this->id() );
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

		<p class="description">
			<?php
			printf(
				/* translators: 1: Windows shortcut, 2: Mac shortcut */
				esc_html__( 'Tip: the icon field takes a plain emoji — open your OS emoji picker to type one (Windows: %1$s · Mac: %2$s).', 'galaxie-woo' ),
				'<kbd>Win</kbd> + <kbd>.</kbd>',
				'<kbd>Cmd</kbd> + <kbd>Ctrl</kbd> + <kbd>Space</kbd>'
			);
			?>
		</p>

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
				var uploadBtn = row.querySelector( '[data-role="upload"]' );
				var removeImageBtn = row.querySelector( '[data-role="remove-image"]' );
				var iconUrlInput = row.querySelector( '[data-role="icon_url"]' );
				var preview = row.querySelector( '[data-role="preview"]' );
				var previewImg = row.querySelector( '[data-role="preview-img"]' );

				labelInput.addEventListener( 'change', function () {
					var match = tagMap[ labelInput.value.trim().toLowerCase() ];
					tagIdInput.value = ( undefined !== match ) ? match : '';
				} );
				removeBtn.addEventListener( 'click', function () {
					row.remove();
				} );

				uploadBtn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					if ( ! window.wp || ! wp.media ) {
						return;
					}
					var frame = wp.media( {
						title: <?php echo wp_json_encode( __( 'Select interest icon', 'galaxie-woo' ) ); ?>,
						button: { text: <?php echo wp_json_encode( __( 'Use this image', 'galaxie-woo' ) ); ?> },
						library: { type: 'image' },
						multiple: false
					} );
					frame.on( 'select', function () {
						var attachment = frame.state().get( 'selection' ).first().toJSON();
						iconUrlInput.value = attachment.url;
						previewImg.src = attachment.url;
						preview.style.display = 'inline-flex';
						removeImageBtn.style.display = 'inline';
					} );
					frame.open();
				} );

				removeImageBtn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					iconUrlInput.value = '';
					previewImg.src = '';
					preview.style.display = 'none';
					removeImageBtn.style.display = 'none';
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

	/**
	 * @param int|string $index Row index (or the `__INDEX__` template placeholder).
	 *
	 * The icon can be a typed emoji (`icon`) and/or an uploaded image
	 * (`icon_url`, via the WP media library). If both are set, the front-end
	 * (built later, alongside My Account) prefers the image.
	 */
	private function render_interest_row( $index, array $option ): void {
		$tag_id   = $option['tag_id'] ?? '';
		$label    = $option['label'] ?? '';
		$icon     = $option['icon'] ?? '';
		$icon_url = $option['icon_url'] ?? '';
		$prefix   = "fields[interests][{$index}]";
		?>
		<div class="gxf-interest-row" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:8px;">
			<input type="hidden" data-role="tag_id" name="<?php echo esc_attr( "{$prefix}[tag_id]" ); ?>" value="<?php echo esc_attr( (string) $tag_id ); ?>" />
			<input type="hidden" data-role="icon_url" name="<?php echo esc_attr( "{$prefix}[icon_url]" ); ?>" value="<?php echo esc_attr( (string) $icon_url ); ?>" />

			<span
				data-role="preview"
				style="width:32px;height:32px;display:<?php echo $icon_url ? 'inline-flex' : 'none'; ?>;align-items:center;justify-content:center;border:1px solid #dcdcde;border-radius:4px;overflow:hidden;flex-shrink:0;"
			>
				<img data-role="preview-img" src="<?php echo esc_url( (string) $icon_url ); ?>" style="max-width:100%;max-height:100%;" alt="" />
			</span>

			<input
				type="text"
				data-role="icon"
				name="<?php echo esc_attr( "{$prefix}[icon]" ); ?>"
				value="<?php echo esc_attr( (string) $icon ); ?>"
				placeholder="🪻"
				title="<?php esc_attr_e( 'Emoji', 'galaxie-woo' ); ?>"
				style="width:50px;text-align:center;"
			/>

			<button type="button" class="button" data-role="upload"><?php esc_html_e( 'Upload image', 'galaxie-woo' ); ?></button>
			<button type="button" class="button-link" data-role="remove-image" style="display:<?php echo $icon_url ? 'inline' : 'none'; ?>;"><?php esc_html_e( 'Remove image', 'galaxie-woo' ); ?></button>

			<input
				type="text"
				data-role="label"
				list="gxf-interest-tag-suggestions"
				name="<?php echo esc_attr( "{$prefix}[label]" ); ?>"
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
	 * @return array<int,array{tag_id:int,label:string,icon:string,icon_url:string}>
	 */
	private function sanitize_interests( array $rows ): array {
		$out       = array();
		$seen_tags = array();

		foreach ( $rows as $row ) {
			$label = sanitize_text_field( trim( (string) ( $row['label'] ?? '' ) ) );
			if ( '' === $label ) {
				continue; // Blank row (e.g. added then left empty).
			}

			$icon     = sanitize_text_field( trim( (string) ( $row['icon'] ?? '' ) ) );
			$icon_url = esc_url_raw( trim( (string) ( $row['icon_url'] ?? '' ) ) );
			$tag_id   = absint( $row['tag_id'] ?? 0 );

			if ( $tag_id <= 0 ) {
				$tag_id = $this->find_or_create_tag( $label );
			}
			if ( $tag_id <= 0 || isset( $seen_tags[ $tag_id ] ) ) {
				continue; // Couldn't resolve/create a tag, or a duplicate.
			}

			$seen_tags[ $tag_id ] = true;
			$out[]                = array(
				'tag_id'   => $tag_id,
				'label'    => $label,
				'icon'     => $icon,
				'icon_url' => $icon_url,
			);
		}

		return $out;
	}
}
