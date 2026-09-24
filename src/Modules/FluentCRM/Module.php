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
use Galaxie\Woo\Support\AddressBook;
use Galaxie\Woo\Support\ProfileFields;

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
 *
 * 3. **Profile sync** — the customer's contact follows their account: name,
 *    phone, date of birth, address, CPF, gender and social name. Watched at the
 *    user meta itself rather than at each screen that writes it (My Account
 *    details, the address book, the checkout's profile step, wp-admin), so a
 *    new screen cannot forget to sync, and flushed once per request.
 *
 *    Only what changed in the request is sent: a contact also gathers data
 *    from forms, imports and the merchant's own edits, and a customer changing
 *    their phone must not overwrite the rest with what the account happens to
 *    hold. Edits made in FluentCRM's own admin are never pushed back over.
 *
 *    A field the customer empties is emptied on the contact too, so the
 *    contact never shows data the customer took back. What it held is not
 *    lost: every change — profile fields, addresses, the communication consent,
 *    interests, saved cards added, deleted or made the default (brand, last
 *    four digits, expiry) — is written on the contact's Notes tab as before → after, with
 *    the date and time, where it was made and who made it.
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
		$settings            = $this->settings();
		$this->sync_contacts = ! empty( $settings['profile_sync'] ?? true );
		$this->log_changes   = ! empty( $settings['profile_notes'] ?? true );

		if ( $this->sync_contacts || $this->log_changes ) {
			// Watched only when FluentCRM is there to take the notes and changes —
			// otherwise every profile save would pay for capturing old values that
			// go nowhere. The check waits for init: this boot runs on plugins_loaded,
			// possibly before FluentCRM has defined its API, and init still comes
			// before any handler that saves a profile.
			if ( did_action( 'init' ) ) {
				$this->register_profile_watchers();
			} else {
				add_action( 'init', array( $this, 'register_profile_watchers' ), 0 );
			}
		}

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

	/** The user meta a contact is built from. */
	private const PROFILE_META = array(
		'first_name',
		'last_name',
		'billing_first_name',
		'billing_last_name',
		'billing_phone',
		'billing_address_1',
		'billing_address_2',
		'billing_city',
		'billing_state',
		'billing_postcode',
		'billing_country',
		'shipping_phone',
		'shipping_address_1',
		'shipping_address_2',
		'shipping_city',
		'shipping_state',
		'shipping_postcode',
		'shipping_country',
		ProfileFields::CPF,
		ProfileFields::BIRTHDATE,
		ProfileFields::GENDER,
		ProfileFields::SOCIAL_NAME,
		// Noted, not synced: the contact has no column for either.
		ProfileFields::MARKETING_OPT_IN,
		\Galaxie\Woo\Support\AddressBook::META_KEY,
	);

	/** FluentCRM has no columns for these, so they are custom contact fields. */
	private const CUSTOM_FIELDS = array(
		array( 'slug' => 'cpf', 'label' => 'CPF', 'type' => 'text' ),
		array( 'slug' => 'genero', 'label' => 'Gênero', 'type' => 'text' ),
		array( 'slug' => 'nome_social', 'label' => 'Nome social', 'type' => 'text' ),
	);

	/**
	 * The merchant's own multi-line field for the addresses that are not the
	 * contact's main one. Found by label too, in case its slug is renamed.
	 */
	private const OTHER_ADDRESSES_SLUG  = 'outros_endereços_cadastra';
	private const OTHER_ADDRESSES_LABEL = 'Outros endereços que o usuário cadastrou';

	/** @var array<int,true> Users whose contact is re-synced at the end of this request. */
	private array $profile_queue = array();

	private bool $profile_flushing = false;

	private bool $sync_contacts = true;

	private bool $log_changes = true;

	/** @var array<int,array<string,mixed>> Each watched field's value before this request first touched it. */
	private array $old_meta = array();

	/** @var array<int,array<int,string>> Changes that are not user meta — interests, saved cards — in this request. */
	private array $event_log = array();

	/** @var array<int,true> Accounts created in this request: what registration writes is not a change. */
	private array $registered = array();

	/** @var array<int,string> user id => the e-mail the account had before this request. */
	private array $email_changes = array();

	/** Where the change was made, as the handler that made it declared. */
	private ?string $declared_source = null;

	/** Memo of store_api_request(). */
	private ?string $store_api = null;

	/** @var array<int,array{columns:array<string,string>,custom:array<string,string>}> Contact id => its profile before FluentCRM's admin saved it. */
	private array $panel_before = array();

	/** A contact's profile was saved in FluentCRM's admin in this request, and noted there. */
	private bool $panel_edit = false;

	/**
	 * Old values set aside by Store API customer updates, for the checkout to
	 * note and sync. Not in PROFILE_META, so writing it is not itself a change.
	 */
	private const PENDING_META = '_galaxie_fcrm_pending_old';

	public function register_profile_watchers(): void {
		if ( ! FluentCRMApi::is_active() ) {
			return;
		}

		add_action( 'galaxie_woo/profile_updated', array( $this, 'queue_profile_sync' ) );
		add_action( 'galaxie_woo/change_source', array( $this, 'declare_change_source' ) );
		add_action( 'profile_update', array( $this, 'on_profile_update' ), 10, 2 );
		add_action( 'user_register', array( $this, 'mark_registered' ) );
		add_action( 'galaxie_woo/customer_registered', array( $this, 'mark_registered' ), 1 );
		add_action( 'galaxie_woo/interest_changed', array( $this, 'remember_interest' ), 10, 3 );
		add_action( 'galaxie_woo/communication_changed', array( $this, 'remember_communication' ), 10, 3 );
		add_action( 'woocommerce_new_payment_token', array( $this, 'remember_card_added' ), 10, 2 );
		add_action( 'woocommerce_payment_token_deleted', array( $this, 'remember_card_deleted' ), 10, 2 );
		add_action( 'woocommerce_payment_token_set_default', array( $this, 'remember_card_default' ), 10, 2 );

		foreach ( array( 'add_user_metadata', 'update_user_metadata', 'delete_user_metadata' ) as $hook ) {
			add_filter( $hook, array( $this, 'remember_old_meta' ), 10, 3 );
		}

		foreach ( array( 'added_user_meta', 'updated_user_meta', 'deleted_user_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'watch_user_meta' ), 10, 3 );
		}

		add_action( 'wp_loaded', array( $this, 'queue_pending_changes' ) );
		add_action( 'shutdown', array( $this, 'flush_profile_sync' ) );

		if ( $this->log_changes ) {
			add_filter( 'rest_request_before_callbacks', array( $this, 'snapshot_panel_contacts' ), 10, 3 );
			add_filter( 'rest_request_after_callbacks', array( $this, 'note_panel_contacts' ), 10, 3 );
		}
	}

	/**
	 * FluentCRM's admin saving a contact's profile (FluentCRM 2.9.x,
	 * app/Http/Routes/api.php): `PUT subscribers/{id}` — SubscriberController@
	 * updateSubscriber, columns and `custom_values` in one request — and
	 * `PUT subscribers/subscribers-property` with `property=status` —
	 * @updateProperty, the status dropdown. The admin sends both as POST with
	 * X-HTTP-Method-Override, which WordPress resolves before these filters.
	 * Notes, tags, lists, e-mails and the rest are not profile edits and match
	 * nothing, so writing a note never leads to another.
	 *
	 * @return int[] Contact ids the request edits.
	 */
	private function panel_contact_ids( \WP_REST_Request $request ): array {
		if ( ! in_array( strtoupper( $request->get_method() ), array( 'PUT', 'PATCH', 'POST' ), true ) ) {
			return array();
		}

		$route = '/' . trim( (string) $request->get_route(), '/' );

		if ( preg_match( '#^/fluent-crm/v\d+/subscribers/(\d+)$#', $route, $match ) ) {
			return array( (int) $match[1] );
		}

		if ( preg_match( '#^/fluent-crm/v\d+/subscribers/subscribers-property$#', $route ) && 'status' === $request->get_param( 'property' ) ) {
			$ids = array_filter( array_map( 'absint', (array) $request->get_param( 'subscribers' ) ) );

			// A handful at a time in practice; the cap keeps a very large bulk
			// change from reading every contact twice.
			return array_slice( array_values( array_unique( $ids ) ), 0, 200 );
		}

		return array();
	}

	/**
	 * What each contact the panel is about to save holds, before it saves.
	 * A filter that changes nothing.
	 *
	 * @param mixed $response
	 * @param mixed $handler
	 * @param mixed $request
	 * @return mixed
	 */
	public function snapshot_panel_contacts( $response, $handler, $request ) {
		try {
			if ( ! $request instanceof \WP_REST_Request || is_wp_error( $response ) ) {
				return $response;
			}

			foreach ( $this->panel_contact_ids( $request ) as $id ) {
				$before = FluentCRMApi::contact_profile( $id );

				if ( null !== $before ) {
					$this->panel_before[ $id ] = $before;
					$this->panel_edit          = true;
				}
			}
		} catch ( \Throwable $e ) {
			// Never in the way of the merchant's save.
		}

		return $response;
	}

	/**
	 * One "Alteração de perfil" note per contact the panel changed, comparing
	 * the contact with what it held before the save — whatever the response
	 * says, so a save that failed halfway still notes what it did write.
	 *
	 * @param mixed $response
	 * @param mixed $handler
	 * @param mixed $request
	 * @return mixed
	 */
	public function note_panel_contacts( $response, $handler, $request ) {
		if ( ! $this->panel_before ) {
			return $response;
		}

		$snapshots          = $this->panel_before;
		$this->panel_before = array();

		try {
			$actor = wp_get_current_user();
			$by    = $actor && $actor->exists() ? (string) $actor->display_name : __( 'sistema', 'galaxie-woo' );
			$names = null;

			foreach ( $snapshots as $id => $before ) {
				$after = FluentCRMApi::contact_profile( (int) $id );

				if ( null === $after ) {
					continue;
				}

				$names ??= FluentCRMApi::custom_field_labels();
				$lines   = $this->panel_changes( $before, $after, $names );

				if ( $lines ) {
					FluentCRMApi::add_note_by_contact_id( (int) $id, __( 'Alteração de perfil', 'galaxie-woo' ), $this->note_description( __( 'FluentCRM (painel)', 'galaxie-woo' ), $by, $lines ) );
				}
			}
		} catch ( \Throwable $e ) {
			// Best effort, like every other note.
		}

		return $response;
	}

	/**
	 * @param array{columns:array<string,string>,custom:array<string,string>} $before
	 * @param array{columns:array<string,string>,custom:array<string,string>} $after
	 * @param array<string,string>                                            $names Custom field labels by slug.
	 * @return string[] Escaped lines.
	 */
	private function panel_changes( array $before, array $after, array $names ): array {
		$lines   = array();
		$columns = array(
			'first_name'     => __( 'Nome', 'galaxie-woo' ),
			'last_name'      => __( 'Sobrenome', 'galaxie-woo' ),
			'email'          => __( 'E-mail', 'galaxie-woo' ),
			'phone'          => __( 'Telefone', 'galaxie-woo' ),
			'date_of_birth'  => __( 'Data de nascimento', 'galaxie-woo' ),
			'address_line_1' => __( 'Endereço', 'galaxie-woo' ),
			'address_line_2' => __( 'Complemento', 'galaxie-woo' ),
			'city'           => __( 'Cidade', 'galaxie-woo' ),
			'state'          => __( 'Estado', 'galaxie-woo' ),
			'postal_code'    => __( 'CEP', 'galaxie-woo' ),
			'country'        => __( 'País', 'galaxie-woo' ),
			'status'         => __( 'Status', 'galaxie-woo' ),
		);

		foreach ( $columns as $column => $label ) {
			$old = $before['columns'][ $column ] ?? '';
			$new = $after['columns'][ $column ] ?? '';

			if ( $old !== $new ) {
				$lines[] = sprintf( '<strong>%1$s:</strong> %2$s → %3$s', esc_html( $label ), esc_html( $this->panel_value( $column, $old ) ), esc_html( $this->panel_value( $column, $new ) ) );
			}
		}

		// Our own fields keep their label when the definitions cannot be read.
		$known = array( self::OTHER_ADDRESSES_SLUG => self::OTHER_ADDRESSES_LABEL );

		foreach ( self::CUSTOM_FIELDS as $field ) {
			$known[ $field['slug'] ] = $field['label'];
		}

		$custom = array_keys( $before['custom'] + $after['custom'] );

		foreach ( $custom as $slug ) {
			$old = $before['custom'][ $slug ] ?? '';
			$new = $after['custom'][ $slug ] ?? '';

			if ( $old === $new ) {
				continue;
			}

			$label = ( $names[ $slug ] ?? '' ) ?: ( $known[ $slug ] ?? $slug );

			// Multi-line values — the other addresses above all — keep their lines.
			$lines[] = sprintf(
				'<strong>%1$s:</strong> %2$s → %3$s',
				esc_html( $label ),
				nl2br( esc_html( '' === $old ? __( '(vazio)', 'galaxie-woo' ) : $old ), false ),
				nl2br( esc_html( '' === $new ? __( '(vazio)', 'galaxie-woo' ) : $new ), false )
			);
		}

		return $lines;
	}

	private function panel_value( string $column, string $value ): string {
		if ( '' === $value ) {
			return __( '(vazio)', 'galaxie-woo' );
		}

		if ( 'date_of_birth' === $column && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $date ) ) {
			return $date[3] . '/' . $date[2] . '/' . $date[1];
		}

		if ( 'country' === $column && function_exists( 'WC' ) && WC()->countries ) {
			return (string) ( WC()->countries->get_countries()[ $value ] ?? $value );
		}

		if ( 'status' === $column ) {
			$statuses = array(
				'subscribed'    => __( 'Inscrito', 'galaxie-woo' ),
				'pending'       => __( 'Pendente', 'galaxie-woo' ),
				'unsubscribed'  => __( 'Descadastrado', 'galaxie-woo' ),
				'transactional' => __( 'Transacional', 'galaxie-woo' ),
				'bounced'       => __( 'Devolvido (bounce)', 'galaxie-woo' ),
				'complained'    => __( 'Reclamação', 'galaxie-woo' ),
				'spammed'       => __( 'Marcado como spam', 'galaxie-woo' ),
			);

			return $statuses[ $value ] ?? $value;
		}

		return $value;
	}

	/**
	 * A customer who edits their address in the block checkout and leaves
	 * without ordering has it saved on the account but only set aside for the
	 * contact (see carry_over()). Their next request of any kind queues them,
	 * so the flush at its end writes the note and syncs — or, when that request
	 * is another Store API customer update, just keeps the values set aside.
	 */
	public function queue_pending_changes(): void {
		$user_id = get_current_user_id();

		if ( $user_id > 0 && is_array( get_user_meta( $user_id, self::PENDING_META, true ) ) ) {
			$this->queue_profile_sync( $user_id );
		}
	}

	/**
	 * Keeps what a field held before its first write in this request, so the
	 * note can say before → after. A filter that changes nothing.
	 *
	 * Another filter may short-circuit the write (a gift checkout keeps the
	 * buyer's shipping address this way). The value captured is then simply the
	 * current one, no `updated_user_meta` follows, and at the flush old equals
	 * new — no note, no sync. A real write later in the request still compares
	 * against the right value, since nothing changed in between.
	 *
	 * @param mixed $check
	 * @param mixed $user_id
	 * @param mixed $meta_key
	 * @return mixed
	 */
	public function remember_old_meta( $check, $user_id, $meta_key ) {
		$user_id = (int) $user_id;
		$key     = (string) $meta_key;

		if ( ! $this->profile_flushing && $user_id > 0 && in_array( $key, self::PROFILE_META, true ) && ! array_key_exists( $key, $this->old_meta[ $user_id ] ?? array() ) ) {
			$this->old_meta[ $user_id ][ $key ] = get_user_meta( $user_id, $key, true );
		}

		return $check;
	}

	/**
	 * The communications a customer may turn on and off: a title, a line of
	 * explanation and the FluentCRM list the answer is written to. Rows with no
	 * list are not communications — there would be nowhere to write the answer.
	 *
	 * @param bool $consent_shown Whether the consent switch is on the same screen.
	 * @return array<int, array{list_id:int, title:string, text:string}>
	 */
	public static function communications( bool $consent_shown = false ): array {
		$settings = Plugin::instance()->settings()->module_settings( 'fluentcrm' );
		$out      = array();

		// The consent switch is already one of these: it writes the customer's
		// field *and* the newsletter list. A row on that same list is the same
		// switch twice, contradicting itself the moment one of them moves, so
		// the screen that shows the consent leaves that row out.
		$consent = $consent_shown ? (int) ( $settings['newsletter_list_id'] ?? 0 ) : 0;

		foreach ( (array) ( $settings['communication_options'] ?? array() ) as $row ) {
			$row     = (array) $row;
			$list_id = (int) ( $row['list_id'] ?? 0 );
			$title   = trim( (string) ( $row['title'] ?? '' ) );

			if ( $list_id <= 0 || '' === $title || ( $consent > 0 && $consent === $list_id ) ) {
				continue;
			}

			$out[] = array(
				'list_id' => $list_id,
				'title'   => $title,
				'text'    => trim( (string) ( $row['text'] ?? '' ) ),
			);
		}

		return $out;
	}

	/**
	 * @param mixed $user_id
	 * @param mixed $list_id
	 * @param mixed $selected
	 */
	public function remember_communication( $user_id, $list_id, $selected ): void {
		$label = '#' . (int) $list_id;

		foreach ( self::communications() as $row ) {
			if ( $row['list_id'] === (int) $list_id ) {
				$label = $row['title'];
				break;
			}
		}

		$this->event_log[ (int) $user_id ][] = ( $selected ? __( 'Comunicação ligada', 'galaxie-woo' ) : __( 'Comunicação desligada', 'galaxie-woo' ) ) . ': ' . $label;
		$this->queue_profile_sync( $user_id );
	}

	/**
	 * @param mixed $user_id
	 * @param mixed $tag_id
	 * @param mixed $selected
	 */
	public function remember_interest( $user_id, $tag_id, $selected ): void {
		$label = '#' . (int) $tag_id;

		foreach ( (array) ( $this->settings()['interest_options'] ?? array() ) as $row ) {
			$row = (array) $row;

			if ( (int) ( $row['tag_id'] ?? 0 ) === (int) $tag_id ) {
				$label = trim( (string) ( $row['icon'] ?? '' ) . ' ' . (string) ( $row['label'] ?? '' ) );
				break;
			}
		}

		$this->event_log[ (int) $user_id ][] = ( $selected ? __( 'Interesse marcado', 'galaxie-woo' ) : __( 'Interesse desmarcado', 'galaxie-woo' ) ) . ': ' . $label;
		$this->queue_profile_sync( $user_id );
	}

	/**
	 * Saved cards, noted by brand, last four digits and expiry — what is needed
	 * to trace a card in a fraud dispute, and nothing a card could be used with.
	 *
	 * @param mixed $token_id
	 * @param mixed $token
	 */
	public function remember_card_added( $token_id, $token ): void {
		$this->remember_card( $token, __( 'Cartão incluído', 'galaxie-woo' ) );
	}

	/**
	 * @param mixed $token_id
	 * @param mixed $token
	 */
	public function remember_card_deleted( $token_id, $token ): void {
		$this->remember_card( $token, __( 'Cartão excluído', 'galaxie-woo' ) );
	}

	/**
	 * @param mixed $token_id
	 * @param mixed $token
	 */
	public function remember_card_default( $token_id, $token ): void {
		$this->remember_card( $token, __( 'Cartão definido como padrão', 'galaxie-woo' ) );
	}

	/** @param mixed $token */
	private function remember_card( $token, string $what ): void {
		if ( ! $token instanceof \WC_Payment_Token || ! $token->get_user_id() ) {
			return;
		}

		if ( $token instanceof \WC_Payment_Token_CC ) {
			$card = sprintf(
				/* translators: 1: card brand, 2: last four digits, 3: expiry month, 4: expiry year. */
				__( '%1$s final %2$s, validade %3$s/%4$s', 'galaxie-woo' ),
				ucfirst( (string) $token->get_card_type() ),
				(string) $token->get_last4(),
				str_pad( (string) $token->get_expiry_month(), 2, '0', STR_PAD_LEFT ),
				(string) $token->get_expiry_year()
			);
		} else {
			$card = wp_strip_all_tags( (string) $token->get_display_name() );
		}

		$gateway = (string) $token->get_gateway_id();

		$this->event_log[ (int) $token->get_user_id() ][] = $what . ': ' . $card . ( '' !== $gateway ? ' (' . $gateway . ')' : '' );
		$this->queue_profile_sync( $token->get_user_id() );
	}

	/** @param mixed $user_id */
	public function queue_profile_sync( $user_id ): void {
		// FluentCRM writes the name back to the user while it saves the contact;
		// that echo must not queue another round.
		if ( ! $this->profile_flushing && (int) $user_id > 0 ) {
			$this->profile_queue[ (int) $user_id ] = true;
		}
	}

	/**
	 * An account created in this request. `user_register` fires after
	 * wp_insert_user() has written the name, and the signup goes on writing
	 * billing fields, the CPF and the opt-in before `customer_registered` —
	 * none of it a change of the customer's mind, so none of it is noted. The
	 * flag is read at the flush, which is after all of them.
	 *
	 * @param mixed $user_id
	 */
	public function mark_registered( $user_id ): void {
		if ( (int) $user_id > 0 ) {
			$this->registered[ (int) $user_id ] = true;
		}
	}

	/**
	 * `profile_update` fires on every wp_update_user(), checkout's customer
	 * save included, so it queues nothing by itself: the meta watchers already
	 * see every field that really changed. The one thing they cannot see is the
	 * e-mail, which lives on the user row.
	 *
	 * @param mixed $user_id
	 * @param mixed $old_user_data
	 */
	public function on_profile_update( $user_id, $old_user_data = null ): void {
		$user_id = (int) $user_id;

		if ( $this->profile_flushing || $user_id <= 0 || ! $old_user_data instanceof \WP_User ) {
			return;
		}

		$user = get_userdata( $user_id );

		if ( ! $user || 0 === strcasecmp( (string) $old_user_data->user_email, (string) $user->user_email ) ) {
			return;
		}

		if ( ! isset( $this->email_changes[ $user_id ] ) ) {
			$this->email_changes[ $user_id ] = (string) $old_user_data->user_email;
		}

		$this->queue_profile_sync( $user_id );
	}

	/**
	 * A handler saying where its change is made:
	 * `do_action( 'galaxie_woo/change_source', 'Minha conta — Informações pessoais' )`.
	 *
	 * @param mixed $label
	 */
	public function declare_change_source( $label ): void {
		if ( is_string( $label ) && '' !== trim( $label ) ) {
			$this->declared_source = sanitize_text_field( $label );
		}
	}

	/**
	 * @param mixed  $meta_id  Unused; an array of ids on deleted_user_meta.
	 * @param mixed  $user_id
	 * @param string $meta_key
	 */
	public function watch_user_meta( $meta_id, $user_id, $meta_key ): void {
		if ( in_array( (string) $meta_key, self::PROFILE_META, true ) ) {
			$this->queue_profile_sync( $user_id );
		}
	}

	/** One sync per user per request, however many fields a save touched. */
	public function flush_profile_sync(): void {
		$store_api = $this->store_api_request();
		$customer  = get_current_user_id();

		// The checkout picks up what the cart's customer updates set aside
		// (see carry_over()); saving the same values again changes nothing, so
		// without this the addresses typed at checkout would never be noted.
		if ( 'checkout' === $store_api && $customer > 0 && is_array( get_user_meta( $customer, self::PENDING_META, true ) ) ) {
			$this->queue_profile_sync( $customer );
		}

		if ( ! $this->profile_queue || ! FluentCRMApi::is_active() ) {
			return;
		}

		$this->profile_flushing = true;
		$users                  = array_keys( $this->profile_queue );
		$this->profile_queue    = array();

		// The merchant editing a contact in FluentCRM's admin makes FluentCRM
		// write the name into the account. Pushing the account back would revert
		// the merchant's own edit, so such a request only takes notes.
		$from_crm = $this->is_fluentcrm_request();

		foreach ( $users as $user_id ) {
			$user = get_userdata( (int) $user_id );

			if ( ! $user ) {
				continue;
			}

			// Block checkout posts the customer to the Store API on every field
			// it leaves, each call saving the account: one note and one sync per
			// keystroke pause. Those calls only set the old values aside.
			if ( 'other' === $store_api ) {
				$this->carry_over( $user->ID );
				continue;
			}

			$old     = $this->old_values( $user->ID, ! $from_crm );
			$changed = array();

			foreach ( $old as $key => $value ) {
				if ( $this->differs( (string) $key, $value, get_user_meta( $user->ID, (string) $key, true ) ) ) {
					$changed[ (string) $key ] = true;
				}
			}

			$old_email = $this->email_changes[ $user->ID ] ?? '';

			if ( 0 === strcasecmp( $old_email, (string) $user->user_email ) ) {
				$old_email = '';
			}

			// The contact moves to the new address before anything else looks for it.
			if ( $this->sync_contacts && ! $from_crm && '' !== $old_email ) {
				FluentCRMApi::change_contact_email( $user->ID, $old_email, (string) $user->user_email );
			}

			// The note first: it records the account's own before and after,
			// whatever the contact happened to hold.
			// A contact saved in FluentCRM's admin was noted from the contact
			// itself (note_panel_contacts()); the name FluentCRM then writes into
			// the account is the same change, and is not noted twice.
			if ( $this->log_changes && ! $this->panel_edit && ! isset( $this->registered[ $user->ID ] ) ) {
				// Changes that are all set aside from checkout's customer updates
				// were made at checkout, whatever page flushes them.
				$own_changes = '' !== $old_email || ! empty( $this->event_log[ $user->ID ] );

				foreach ( $this->old_meta[ $user->ID ] ?? array() as $key => $value ) {
					if ( ! $own_changes && $this->differs( (string) $key, $value, get_user_meta( $user->ID, (string) $key, true ) ) ) {
						$own_changes = true;
					}
				}

				$this->log_profile_changes( $user, $old, $old_email, $changed && ! $own_changes ? __( 'Checkout', 'galaxie-woo' ) : null );
			}

			if ( $this->sync_contacts && ! $from_crm && $changed ) {
				$this->sync_profile( $user, $old, $changed );
			}
		}

		$this->old_meta         = array();
		$this->event_log        = array();
		$this->email_changes    = array();
		$this->profile_flushing = false;
	}

	/**
	 * What the watched fields held before this request — and, when $with_pending,
	 * before the Store API calls that preceded it, which were earlier still and
	 * so take precedence. Those are consumed here.
	 *
	 * @return array<string,mixed>
	 */
	private function old_values( int $user_id, bool $with_pending ): array {
		$old = $this->old_meta[ $user_id ] ?? array();

		if ( ! $with_pending ) {
			return $old;
		}

		$pending = get_user_meta( $user_id, self::PENDING_META, true );

		if ( is_array( $pending ) ) {
			delete_user_meta( $user_id, self::PENDING_META );
			$old = array_merge( $old, array_intersect_key( $pending, array_flip( self::PROFILE_META ) ) );
		}

		return $old;
	}

	/**
	 * Sets this request's old values aside for the checkout, keeping the
	 * earliest value of each field; a field back where it started is dropped.
	 */
	private function carry_over( int $user_id ): void {
		$pending = get_user_meta( $user_id, self::PENDING_META, true );
		$pending = ( is_array( $pending ) ? $pending : array() ) + ( $this->old_meta[ $user_id ] ?? array() );

		foreach ( $pending as $key => $value ) {
			if ( ! in_array( (string) $key, self::PROFILE_META, true ) || ! $this->differs( (string) $key, $value, get_user_meta( $user_id, (string) $key, true ) ) ) {
				unset( $pending[ $key ] );
			}
		}

		if ( $pending ) {
			update_user_meta( $user_id, self::PENDING_META, $pending );
		} else {
			delete_user_meta( $user_id, self::PENDING_META );
		}
	}

	/**
	 * @param mixed $old
	 * @param mixed $new
	 */
	private function differs( string $key, $old, $new ): bool {
		if ( AddressBook::META_KEY === $key ) {
			return maybe_serialize( $old ) !== maybe_serialize( $new );
		}

		return $this->scalar( $old ) !== $this->scalar( $new );
	}

	/** @param mixed $value */
	private function scalar( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Every change in this request, as one note on the contact.
	 *
	 * @param array<string,mixed> $old
	 */
	private function log_profile_changes( \WP_User $user, array $old, string $old_email, ?string $source = null ): void {
		$lines = array();

		if ( '' !== $old_email ) {
			$lines[] = sprintf( '<strong>%1$s:</strong> %2$s → %3$s', esc_html__( 'E-mail', 'galaxie-woo' ), esc_html( $old_email ), esc_html( (string) $user->user_email ) );
		}

		foreach ( $old as $key => $value ) {
			$key = (string) $key;
			$new = get_user_meta( $user->ID, $key, true );

			if ( AddressBook::META_KEY === $key ) {
				// Not an array before means the book was created in this request
				// from the billing and shipping addresses already on the account —
				// AddressBook::entries() seeds it on its first read, a page view
				// included. Nothing the customer did.
				if ( is_array( $value ) ) {
					$lines = array_merge( $lines, $this->address_book_changes( $value, is_array( $new ) ? $new : array() ) );
				}
				continue;
			}

			$before = $this->display_value( $key, $this->scalar( $value ) );
			$after  = $this->display_value( $key, $this->scalar( $new ) );

			if ( $before !== $after ) {
				$lines[] = sprintf( '<strong>%1$s:</strong> %2$s → %3$s', esc_html( $this->field_label( $key ) ), esc_html( $before ), esc_html( $after ) );
			}
		}

		foreach ( $this->event_log[ $user->ID ] ?? array() as $entry ) {
			$lines[] = esc_html( $entry );
		}

		if ( ! $lines ) {
			return;
		}

		$actor = get_current_user_id();
		$who   = $actor ? get_userdata( $actor ) : false;
		$by    = $actor === $user->ID ? __( 'o próprio cliente', 'galaxie-woo' ) : ( $who ? (string) $who->display_name : __( 'sistema', 'galaxie-woo' ) );

		FluentCRMApi::add_note( (string) $user->user_email, __( 'Alteração de perfil', 'galaxie-woo' ), $this->note_description( $source ?? $this->change_source(), $by, $lines ), $user->ID, $old_email );
	}

	/**
	 * A profile note's body: when, where and by whom, then one item per change.
	 *
	 * @param string[] $lines Already escaped.
	 */
	private function note_description( string $source, string $by, array $lines ): string {
		return sprintf(
			'<p><strong>%1$s</strong> %2$s<br><strong>%3$s</strong> %4$s<br><strong>%5$s</strong> %6$s</p><ul><li>%7$s</li></ul>',
			esc_html__( 'Data e hora:', 'galaxie-woo' ),
			esc_html( wp_date( 'd/m/Y H:i:s' ) ),
			esc_html__( 'Origem:', 'galaxie-woo' ),
			esc_html( $source ),
			esc_html__( 'Alterado por:', 'galaxie-woo' ),
			esc_html( $by ),
			implode( '</li><li>', $lines )
		);
	}

	/**
	 * @param array<string,mixed> $old
	 * @param array<string,mixed> $new
	 * @return string[] Escaped lines.
	 */
	private function address_book_changes( array $old, array $new ): array {
		$show = fn( $entry ): string => $this->address_line( (array) $entry );

		$lines = array();

		foreach ( $new as $id => $entry ) {
			if ( ! isset( $old[ $id ] ) ) {
				$lines[] = '<strong>' . esc_html__( 'Endereço incluído:', 'galaxie-woo' ) . '</strong> ' . esc_html( $show( $entry ) );
			} elseif ( $show( $old[ $id ] ) !== $show( $entry ) ) {
				$lines[] = '<strong>' . esc_html__( 'Endereço alterado:', 'galaxie-woo' ) . '</strong> ' . esc_html( $show( $old[ $id ] ) ) . ' → ' . esc_html( $show( $entry ) );
			}
		}

		foreach ( $old as $id => $entry ) {
			if ( ! isset( $new[ $id ] ) ) {
				$lines[] = '<strong>' . esc_html__( 'Endereço removido:', 'galaxie-woo' ) . '</strong> ' . esc_html( $show( $entry ) );
			}
		}

		return $lines;
	}

	private function display_value( string $key, string $value ): string {
		$value = trim( $value );

		// Never answered is consenting — the default — so it reads "Sim", and
		// a first explicit "yes" is not noted as a change.
		if ( ProfileFields::MARKETING_OPT_IN === $key ) {
			return '' === $value || 'yes' === $value ? __( 'Sim', 'galaxie-woo' ) : __( 'Não', 'galaxie-woo' );
		}

		if ( '' === $value ) {
			return __( '(vazio)', 'galaxie-woo' );
		}

		if ( ProfileFields::BIRTHDATE === $key && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $date ) ) {
			return $date[3] . '/' . $date[2] . '/' . $date[1];
		}

		if ( ProfileFields::GENDER === $key ) {
			return ProfileFields::gender_label( $value );
		}

		if ( in_array( $key, array( 'billing_country', 'shipping_country' ), true ) && function_exists( 'WC' ) ) {
			return (string) ( WC()->countries->get_countries()[ $value ] ?? $value );
		}

		return $value;
	}

	private function field_label( string $key ): string {
		$labels = array(
			'first_name'                    => __( 'Nome', 'galaxie-woo' ),
			'last_name'                     => __( 'Sobrenome', 'galaxie-woo' ),
			'billing_first_name'            => __( 'Nome (cobrança)', 'galaxie-woo' ),
			'billing_last_name'             => __( 'Sobrenome (cobrança)', 'galaxie-woo' ),
			'billing_phone'                 => __( 'Celular', 'galaxie-woo' ),
			'shipping_phone'                => __( 'Telefone (entrega)', 'galaxie-woo' ),
			ProfileFields::CPF              => __( 'CPF', 'galaxie-woo' ),
			ProfileFields::BIRTHDATE        => __( 'Data de nascimento', 'galaxie-woo' ),
			ProfileFields::GENDER           => __( 'Gênero', 'galaxie-woo' ),
			ProfileFields::SOCIAL_NAME      => __( 'Nome social', 'galaxie-woo' ),
			ProfileFields::MARKETING_OPT_IN => __( 'Aceita receber comunicações', 'galaxie-woo' ),
		);

		if ( isset( $labels[ $key ] ) ) {
			return $labels[ $key ];
		}

		$parts = array(
			'address_1' => __( 'Endereço', 'galaxie-woo' ),
			'address_2' => __( 'Complemento', 'galaxie-woo' ),
			'city'      => __( 'Cidade', 'galaxie-woo' ),
			'state'     => __( 'Estado', 'galaxie-woo' ),
			'postcode'  => __( 'CEP', 'galaxie-woo' ),
			'country'   => __( 'País', 'galaxie-woo' ),
		);

		foreach ( array( 'billing' => __( 'cobrança', 'galaxie-woo' ), 'shipping' => __( 'entrega', 'galaxie-woo' ) ) as $type => $name ) {
			foreach ( $parts as $part => $label ) {
				if ( $type . '_' . $part === $key ) {
					return $label . ' (' . $name . ')';
				}
			}
		}

		return $key;
	}

	/**
	 * Where the change was made: as the handler declared it, else from the
	 * request that made it.
	 */
	private function change_source(): string {
		if ( null !== $this->declared_source ) {
			return $this->declared_source;
		}

		if ( '' !== $this->rest_route() ) {
			if ( $this->is_fluentcrm_request() ) {
				return __( 'FluentCRM (painel)', 'galaxie-woo' );
			}

			return 'checkout' === $this->store_api_request() ? __( 'Checkout', 'galaxie-woo' ) : __( 'API', 'galaxie-woo' );
		}

		if ( wp_doing_ajax() ) {
			// The fallback for handlers that declare nothing. `action` is the
			// visitor's word, so it counts only for our own actions, and only when
			// admin-ajax really had a handler to dispatch it to.
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read to label a note; the handler checked its own nonce.

			$screens = array(
				'galaxie_myaccount_save_details'       => __( 'Minha conta — Informações pessoais', 'galaxie-woo' ),
				'galaxie_myaccount_save_communication' => __( 'Minha conta — Comunicação', 'galaxie-woo' ),
				'galaxie_myaccount_toggle_interest'    => __( 'Minha conta — Interesses', 'galaxie-woo' ),
				'galaxie_address_book_save'            => __( 'Minha conta — Endereços', 'galaxie-woo' ),
				'galaxie_address_book_delete'          => __( 'Minha conta — Endereços', 'galaxie-woo' ),
				'galaxie_address_book_default'         => __( 'Minha conta — Endereços', 'galaxie-woo' ),
				'galaxie_save_profile'                 => __( 'Checkout — dados pessoais', 'galaxie-woo' ),
				'galaxie_save_address'                 => __( 'Checkout — endereço', 'galaxie-woo' ),
				'galaxie_stripe_save_card'             => __( 'Minha conta — Formas de pagamento', 'galaxie-woo' ),
			);

			if ( isset( $screens[ $action ] ) && has_action( 'wp_ajax_' . $action ) ) {
				return $screens[ $action ];
			}
		}

		// Deleting a card or making it the default is a link WooCommerce handles.
		if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'delete-payment-method' ) || is_wc_endpoint_url( 'set-default-payment-method' ) || is_wc_endpoint_url( 'add-payment-method' ) ) ) {
			return __( 'Minha conta — Formas de pagamento', 'galaxie-woo' );
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return __( 'wp-admin', 'galaxie-woo' );
		}

		return __( 'Site', 'galaxie-woo' );
	}

	/** The REST route this request serves ('/wc/store/v1/checkout'), or '' outside the REST API. */
	private function rest_route(): string {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return '';
		}

		$route = '';

		if ( isset( $GLOBALS['wp'] ) && $GLOBALS['wp'] instanceof \WP && isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			$route = (string) $GLOBALS['wp']->query_vars['rest_route'];
		}

		if ( '' === $route && isset( $_SERVER['REQUEST_URI'] ) ) {
			$path   = (string) wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only matched against route patterns.
			$prefix = '/' . trim( rest_get_url_prefix(), '/' ) . '/';
			$at     = strpos( $path, $prefix );

			if ( false !== $at ) {
				$route = substr( $path, $at + strlen( $prefix ) );
			}
		}

		return '/' . trim( $route, '/' );
	}

	private function is_fluentcrm_request(): bool {
		return 0 === strpos( $this->rest_route(), '/fluent-crm/' );
	}

	/**
	 * 'checkout' for the Store API checkout (a batch counts when it carries
	 * one), 'other' for the rest of the Store API — the cart's customer updates
	 * above all — and '' when this is not a Store API request.
	 */
	private function store_api_request(): string {
		if ( null !== $this->store_api ) {
			return $this->store_api;
		}

		$this->store_api = '';

		if ( ! preg_match( '#^/wc/store(?:/v\d+)?(/.*)?$#', $this->rest_route(), $match ) ) {
			return $this->store_api;
		}

		$endpoint        = $match[1] ?? '';
		$this->store_api = $this->is_checkout_endpoint( $endpoint ) ? 'checkout' : 'other';

		if ( '/batch' === $endpoint ) {
			$body = json_decode( (string) file_get_contents( 'php://input' ), true );

			foreach ( is_array( $body ) && is_array( $body['requests'] ?? null ) ? $body['requests'] : array() as $request ) {
				$path = is_array( $request ) ? (string) wp_parse_url( (string) ( $request['path'] ?? '' ), PHP_URL_PATH ) : '';

				if ( preg_match( '#^/wc/store(?:/v\d+)?(/.*)?$#', $path, $inner ) && $this->is_checkout_endpoint( $inner[1] ?? '' ) ) {
					$this->store_api = 'checkout';
					break;
				}
			}
		}

		return $this->store_api;
	}

	/** '/checkout', or '/checkout/123' when an existing order is paid. */
	private function is_checkout_endpoint( string $endpoint ): bool {
		return (bool) preg_match( '#^/checkout(?:/\d+)?/?$#', $endpoint );
	}

	/**
	 * What changed in this request, as the contact should show it — and nothing
	 * else, so data the contact gathered elsewhere (forms, imports, the
	 * merchant's edits) stays. The address is the billing one, where the
	 * customer lives and pays; the shipping one only when there is no billing
	 * address, and it goes as a whole, since its parts only make sense
	 * together. A field is cleared on the contact only when the customer
	 * emptied it in this request — and never the name, which a contact keeps.
	 *
	 * @param array<string,mixed> $old     Values before the request.
	 * @param array<string,true>  $changed The watched keys that changed.
	 */
	private function sync_profile( \WP_User $user, array $old, array $changed ): void {
		$user_id = $user->ID;
		$meta    = fn( string $key ): string => $this->scalar( get_user_meta( $user_id, $key, true ) );
		$emptied = fn( string $key ): bool => isset( $changed[ $key ] ) && '' === $meta( $key ) && '' !== $this->scalar( $old[ $key ] ?? '' );
		$columns = array();

		foreach ( array( 'first_name', 'last_name' ) as $name ) {
			// The billing name stands in only while the account has none. With
			// the account name set, a checkout's billing name is not the
			// contact's name, and must not resend it over whatever the contact holds.
			if ( isset( $changed[ $name ] ) || ( isset( $changed[ 'billing_' . $name ] ) && '' === $meta( $name ) ) ) {
				$value = $meta( $name ) ?: $meta( 'billing_' . $name );

				if ( '' !== $value ) {
					$columns[ $name ] = $value;
				}
			}
		}

		// The billing phone when it changed, emptied included: a customer who
		// just erased it must not find the shipping phone put in its place.
		if ( isset( $changed['billing_phone'] ) ) {
			$columns['phone'] = $meta( 'billing_phone' );
		} elseif ( isset( $changed['shipping_phone'] ) && '' === $meta( 'billing_phone' ) ) {
			$columns['phone'] = $meta( 'shipping_phone' );
		}

		if ( isset( $changed[ ProfileFields::BIRTHDATE ] ) ) {
			$birth = $meta( ProfileFields::BIRTHDATE );

			if ( '' === $birth ) {
				$columns['date_of_birth'] = null;
			} elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $birth ) ) {
				$columns['date_of_birth'] = $birth;
			}
		}

		$parts = array(
			'address_1' => 'address_line_1',
			'address_2' => 'address_line_2',
			'city'      => 'city',
			'state'     => 'state',
			'postcode'  => 'postal_code',
			'country'   => 'country',
		);

		$type    = '' !== $meta( 'billing_address_1' ) ? 'billing' : 'shipping';
		$touched = static fn( string $prefix ): bool => (bool) array_intersect_key(
			$changed,
			array_flip( array_map( static fn( string $part ): string => $prefix . '_' . $part, array_keys( $parts ) ) )
		);

		// Which address the contact showed before this request. When that switches
		// (a first billing address over the shipping one, or billing emptied back
		// to shipping), every part comes from the new source, blanks included: a
		// part the new source never had would otherwise keep the old one's value
		// and leave the contact with a mixed address.
		$was_billing = array_key_exists( 'billing_address_1', $old ) ? $this->scalar( $old['billing_address_1'] ) : $meta( 'billing_address_1' );
		$switched    = ( '' !== $was_billing ? 'billing' : 'shipping' ) !== $type;

		// A change to the shipping address is the contact's business only while
		// the shipping address is the one it shows.
		if ( $switched || $touched( 'billing' ) || ( 'shipping' === $type && $touched( 'shipping' ) ) ) {
			foreach ( $parts as $part => $column ) {
				$value = $meta( $type . '_' . $part );

				if ( $switched || '' !== $value || $emptied( 'billing_' . $part ) || ( 'shipping' === $type && $emptied( 'shipping_' . $part ) ) ) {
					$columns[ $column ] = $value;
				}
			}
		}

		$custom = array();

		if ( isset( $changed[ ProfileFields::CPF ] ) ) {
			$custom['cpf'] = $meta( ProfileFields::CPF );
		}

		if ( isset( $changed[ ProfileFields::GENDER ] ) ) {
			$gender           = $meta( ProfileFields::GENDER );
			$custom['genero'] = '' !== $gender ? ProfileFields::gender_label( $gender ) : '';
		}

		if ( isset( $changed[ ProfileFields::SOCIAL_NAME ] ) ) {
			$custom['nome_social'] = $meta( ProfileFields::SOCIAL_NAME );
		}

		if ( $custom ) {
			FluentCRMApi::ensure_custom_fields( array_values( array_filter( self::CUSTOM_FIELDS, static fn( array $field ): bool => isset( $custom[ $field['slug'] ] ) ) ) );
		}

		if ( $columns || $custom ) {
			FluentCRMApi::update_contact( (string) $user->user_email, $columns, $custom, $user_id );
		}

		if ( isset( $changed[ AddressBook::META_KEY ] ) || $touched( 'billing' ) || $touched( 'shipping' ) ) {
			$this->append_other_addresses( $user, $type );
		}
	}

	/**
	 * The address book's other addresses, added to the merchant's field and never
	 * removed from it: a record of every place the customer has kept, dated the
	 * day it was first seen. The contact's main address is left out while it is
	 * the main one, and joins the list the day it stops being it.
	 *
	 * Only with the Address Book module on: reading the book creates it, and a
	 * store that turned the module off has not asked for one.
	 */
	private function append_other_addresses( \WP_User $user, string $main_type ): void {
		if ( ! Plugin::instance()->modules()->is_enabled_by_id( 'address-book' ) ) {
			return;
		}

		$slug = FluentCRMApi::find_custom_field( self::OTHER_ADDRESSES_SLUG, self::OTHER_ADDRESSES_LABEL );

		if ( null === $slug ) {
			return;
		}

		$lines = array();

		foreach ( AddressBook::for_js( $user->ID ) as $entry ) {
			if ( ! empty( $entry[ $main_type ] ) ) {
				continue;
			}

			$values = (array) $entry['values'];
			$place  = $this->address_text( $values );

			if ( '' === $place ) {
				continue;
			}

			// Keyed on the place alone: the same address under a new label or
			// with a new phone is not a new place, and is not appended again.
			/* translators: 1: the address, 2: the date it was recorded. */
			$lines[ $place ] = sprintf( __( '%1$s (registrado em %2$s)', 'galaxie-woo' ), $this->address_line( array_merge( $values, array( 'label' => (string) $entry['label'] ) ) ), wp_date( 'd/m/Y' ) );
		}

		FluentCRMApi::append_to_custom_field( (string) $user->user_email, $slug, $lines, $user->ID );
	}

	/**
	 * The address alone, as WooCommerce formats it for the country, on one line
	 * of plain text.
	 *
	 * AddressBook::format() escapes each value, so what a shopper typed is
	 * entity-encoded in it. Decoded first and stripped after: the other way
	 * round, an `&lt;img …&gt;` typed into a field would come out as real markup.
	 *
	 * @param array<string,mixed> $entry
	 */
	private function address_text( array $entry ): string {
		$html = (string) preg_replace( '#<br\s*/?>#i', ', ', AddressBook::format( $entry ) );

		return trim( wp_strip_all_tags( html_entity_decode( $html, ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * One address book entry on one line: its label, the address, and its phone.
	 *
	 * @param array<string,mixed> $entry
	 */
	private function address_line( array $entry ): string {
		$address = $this->address_text( $entry );
		$phone   = trim( (string) ( $entry['phone'] ?? '' ) );
		$label   = trim( (string) ( $entry['label'] ?? '' ) );

		if ( '' === $address ) {
			return '';
		}

		return ( '' !== $label ? $label . ' — ' : '' ) . $address . ( '' !== $phone ? ' — ' . $phone : '' );
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

	/**
	 * What marks a FluentCRM tag as a customer interest: its description starts
	 * with this, followed by the emoji. It is how the two sides recognise each
	 * other — the list here is written into tags that carry it, and tags that
	 * carry it are read back into the list.
	 */
	private const INTEREST_MARKER = 'Interesse do cliente (My Account)';

	/** @return array<string,mixed> */
	private function settings(): array {
		return Plugin::instance()->settings()->module_settings( $this->id() );
	}

	public function settings_tab_label(): string {
		return __( 'FluentCRM', 'galaxie-woo' );
	}

	/** The consent switch's wording when the merchant has not written its own. */
	public const NEWSLETTER_TITLE = 'Novidades e ofertas por e-mail';

	/** @see self::NEWSLETTER_TITLE */
	public const NEWSLETTER_TEXT = 'Lançamentos, rituais e promoções, de vez em quando. Você pode sair quando quiser.';

	/**
	 * How the newsletter consent reads on the screen. It is one row among the
	 * communications, so it is written where they are — the widget only decides
	 * whether to show it.
	 *
	 * A wording typed into the widget before this existed still wins over the
	 * default, so nothing a merchant wrote is lost; `$legacy` is what the widget
	 * carries.
	 *
	 * @param array{title?:string, text?:string} $legacy The widget's own saved wording.
	 * @return array{title:string, text:string}
	 */
	public static function newsletter_wording( array $legacy = array() ): array {
		$settings = Plugin::instance()->settings()->module_settings( 'fluentcrm' );
		$pick     = static function ( string $key, string $old, string $fallback ) use ( $settings, $legacy ): string {
			$typed = trim( (string) ( $settings[ $key ] ?? '' ) );

			if ( '' !== $typed ) {
				return $typed;
			}

			$kept = trim( (string) ( $legacy[ $old ] ?? '' ) );

			return '' !== $kept ? $kept : $fallback;
		};

		return array(
			'title' => $pick( 'newsletter_title', 'title', __( self::NEWSLETTER_TITLE, 'galaxie-woo' ) ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- the constant is the literal.
			'text'  => $pick( 'newsletter_text', 'text', __( self::NEWSLETTER_TEXT, 'galaxie-woo' ) ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- the constant is the literal.
		);
	}

	/** @return Field[] Just the Interests on/off — everything else on this tab is custom-rendered. */
	public function settings_fields(): array {
		return array(
			new Field(
				key: 'newsletter_title',
				label: __( 'Newsletter consent: title', 'galaxie-woo' ),
				type: Field::TYPE_TEXT,
				description: __( 'How the consent switch reads in My Account → Comunicação. The list it writes to is "List: newsletter opt-in" below.', 'galaxie-woo' ),
				default: self::NEWSLETTER_TITLE
			),
			new Field(
				key: 'newsletter_text',
				label: __( 'Newsletter consent: line under it', 'galaxie-woo' ),
				type: Field::TYPE_TEXT,
				default: self::NEWSLETTER_TEXT
			),
			new Field(
				key: 'interests_enabled',
				label: __( 'User interests', 'galaxie-woo' ),
				type: Field::TYPE_TOGGLE,
				description: __( 'Let customers declare interests (e.g. "Lavanda") in My Account. This is self-reported — a customer explicitly saying what they like, not FluentCRM inferring it from behavior.', 'galaxie-woo' ),
				default: false
			),
			new Field(
				key: 'profile_sync',
				label: __( 'Keep contacts up to date', 'galaxie-woo' ),
				type: Field::TYPE_TOGGLE,
				description: __( 'Whenever a customer\'s account changes — in My Account, at checkout or in wp-admin — their FluentCRM contact follows: name, phone, date of birth, billing address (shipping when there is none), and CPF, gender and social name as custom fields, created in FluentCRM if missing. The address book\'s other addresses are appended, never removed, to a multi-line custom field labelled "Outros endereços que o usuário cadastrou" when the store has one. Only contacts that already exist are updated.', 'galaxie-woo' ),
				default: true
			),
			new Field(
				key: 'profile_notes',
				label: __( 'Record profile changes in contact notes', 'galaxie-woo' ),
				type: Field::TYPE_TOGGLE,
				description: __( 'Every change a customer makes — profile fields, addresses, communication consent, interests, saved cards (brand, last four digits, expiry) — is written on their FluentCRM contact\'s Notes tab as before → after, with the date and time, where it was made and who made it. Edits to a contact\'s profile in FluentCRM\'s own admin (columns, status and custom fields) are noted too, credited to the admin who made them. Keeps a record of data the contact itself no longer shows.', 'galaxie-woo' ),
				default: true
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

		$this->render_communications_builder( $lists, $values );
		$this->render_interests_builder( $tags, $values );
	}

	/**
	 * @param array<int,string>   $tags   tag id => title, for the autocomplete suggestions.
	 * @param array<string,mixed> $values currently saved settings.
	 */
	/**
	 * The communications builder: a title, a line under it and the list the
	 * answer is written to. The widget shows whichever of these the merchant
	 * picks, and a customer turning one on joins that list.
	 *
	 * @param array<int,string>   $lists  List id => title, from discover().
	 * @param array<string,mixed> $values The tab's saved values.
	 */
	private function render_communications_builder( array $lists, array $values ): void {
		$rows = array_values( (array) ( $values['communication_options'] ?? array() ) );
		?>
		<h3><?php esc_html_e( 'Communications', 'galaxie-woo' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'What a customer may turn on and off in My Account → Comunicação. Each row is a title, a line of explanation and the FluentCRM list the answer is written to: turning it on joins the list, turning it off leaves it. Which of them a screen shows is chosen on the Galaxie Account Communication widget.', 'galaxie-woo' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'A row on the same list as "List: newsletter opt-in" is not shown beside the consent switch: that switch already is that list, and two switches on one list would disagree the moment one moved. Point the row at another list, or turn the consent off on the widget.', 'galaxie-woo' ); ?>
		</p>

		<div id="gxf-comms-rows">
			<?php foreach ( $rows as $i => $row ) : ?>
				<?php $this->render_communication_row( (int) $i, (array) $row, $lists ); ?>
			<?php endforeach; ?>
		</div>

		<p>
			<button type="button" class="button" id="gxf-comms-add"><?php esc_html_e( '+ Add communication', 'galaxie-woo' ); ?></button>
			<?php // Says the builder was drawn: an empty list posts no rows, and without this a save with FluentCRM unreadable would read as "all removed". ?>
			<input type="hidden" name="fields[communications_present]" value="1" />
		</p>

		<template id="gxf-comm-row-template">
			<?php $this->render_communication_row( '__INDEX__', array(), $lists ); ?>
		</template>

		<script>
		(function () {
			var rows = document.getElementById( 'gxf-comms-rows' );
			var tpl = document.getElementById( 'gxf-comm-row-template' );
			var addBtn = document.getElementById( 'gxf-comms-add' );
			var nextIndex = rows.children.length;

			function wireRow( row ) {
				var removeBtn = row.querySelector( '[data-role="remove"]' );
				if ( removeBtn ) {
					removeBtn.addEventListener( 'click', function () {
						row.remove();
					} );
				}
			}

			Array.prototype.forEach.call( rows.children, wireRow );

			addBtn.addEventListener( 'click', function () {
				var html = tpl.innerHTML.split( '__INDEX__' ).join( String( nextIndex++ ) );
				var holder = document.createElement( 'div' );
				holder.innerHTML = html;
				var row = holder.firstElementChild;
				rows.appendChild( row );
				wireRow( row );
			} );
		})();
		</script>
		<?php
	}

	/**
	 * One communication row.
	 *
	 * @param int|string          $index Row index, or __INDEX__ for the template.
	 * @param array<string,mixed> $row   Saved values.
	 * @param array<int,string>   $lists List id => title.
	 */
	private function render_communication_row( $index, array $row, array $lists ): void {
		$prefix = "fields[communications][{$index}]";
		?>
		<div class="gxf-comm-row" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:8px;">
			<input
				type="text"
				name="<?php echo esc_attr( "{$prefix}[title]" ); ?>"
				value="<?php echo esc_attr( (string) ( $row['title'] ?? '' ) ); ?>"
				placeholder="<?php esc_attr_e( 'Title, e.g. Novidades e ofertas', 'galaxie-woo' ); ?>"
				style="width:220px;"
			/>
			<input
				type="text"
				name="<?php echo esc_attr( "{$prefix}[text]" ); ?>"
				value="<?php echo esc_attr( (string) ( $row['text'] ?? '' ) ); ?>"
				placeholder="<?php esc_attr_e( 'The line under it (optional)', 'galaxie-woo' ); ?>"
				style="flex:1 1 320px;min-width:220px;"
			/>
			<select name="<?php echo esc_attr( "{$prefix}[list_id]" ); ?>" style="width:220px;">
				<option value=""><?php esc_html_e( '— pick a list —', 'galaxie-woo' ); ?></option>
				<?php foreach ( $lists as $id => $title ) : ?>
					<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( (int) ( $row['list_id'] ?? 0 ), (int) $id ); ?>>
						<?php echo esc_html( $title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="button-link-delete" data-role="remove"><?php esc_html_e( 'Remove', 'galaxie-woo' ); ?></button>
		</div>
		<?php
	}

	private function render_interests_builder( array $tags, array $values ): void {
		$options = array_values( (array) ( $values['interest_options'] ?? array() ) );
		?>
		<h3><?php esc_html_e( 'Interests', 'galaxie-woo' ); ?></h3>
		<?php
		$notice_key = 'galaxie_woo_fcrm_sync_' . get_current_user_id();
		$notice     = get_transient( $notice_key );

		if ( is_string( $notice ) && '' !== $notice ) {
			delete_transient( $notice_key );
			printf( '<div class="notice notice-info inline"><p>%s</p></div>', esc_html( $notice ) );
		}
		?>
		<p class="description">
			<?php esc_html_e( 'The curated list customers pick from in My Account → Interests, shown to them in alphabetical order. Each row is an icon/emoji + a label. Type a label — pick a suggestion to link an existing FluentCRM tag, or type a new name to create one when you save.', 'galaxie-woo' ); ?>
		</p>
		<p class="description">
			<?php
			printf(
				/* translators: %s: the text a tag description starts with. */
				esc_html__( 'The list and FluentCRM feed each other. Saving writes each label and emoji into its tag, whose description becomes "%s: emoji"; a row you remove loses that description. "Import and update from FluentCRM" does the reverse: every tag with that description joins the list, names and emojis are taken from the tags, and rows whose tag was deleted go away.', 'galaxie-woo' ),
				esc_html( self::INTEREST_MARKER )
			);
			?>
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
			<?php
			// A plain button that submits on purpose, not a submit button: this sits
			// above "Save settings", and Enter in any field presses the first submit
			// button in the form.
			?>
			<button type="button" class="button" id="gxf-interests-sync"><?php esc_html_e( 'Import and update from FluentCRM', 'galaxie-woo' ); ?></button>
			<input type="hidden" id="gxf-interests-sync-input" name="fields[interests_sync]" value="" />
			<?php
			// Says the builder was drawn. An empty list posts no rows at all, so
			// without it a save with the builder missing (FluentCRM inactive or
			// unreadable) would look like every interest removed.
			?>
			<input type="hidden" name="fields[interests_present]" value="1" />
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

			var syncBtn = document.getElementById( 'gxf-interests-sync' );
			var syncInput = document.getElementById( 'gxf-interests-sync-input' );

			syncBtn.addEventListener( 'click', function () {
				syncInput.value = 'pull';
				syncBtn.form.submit();
			} );

			function syncVisibility() {
				rows.style.display = enabledToggle.checked ? '' : 'none';
				addBtn.style.display = enabledToggle.checked ? '' : 'none';
				syncBtn.style.display = enabledToggle.checked ? '' : 'none';
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
			// Absent is not "— none —": the selects are not drawn at all when
			// FluentCRM is inactive or unreadable, and saving the tab then must not
			// wipe the mapping.
			if ( ! array_key_exists( $key, $submitted ) ) {
				if ( array_key_exists( $key, $current ) ) {
					$sanitized[ $key ] = $current[ $key ];
				}
				continue;
			}

			$raw               = $submitted[ $key ];
			$sanitized[ $key ] = '' === $raw ? '' : absint( $raw );
		}

		// The communications builder says it was drawn too; without it the rows
		// stay exactly as they were.
		if ( empty( $submitted['communications_present'] ) ) {
			if ( array_key_exists( 'communication_options', $current ) ) {
				$sanitized['communication_options'] = $current['communication_options'];
			}
		} else {
			$sanitized['communication_options'] = self::sanitize_communications( (array) ( $submitted['communications'] ?? array() ) );
		}

		// Same for the interests builder, which says it was drawn: without it,
		// keep the list and push nothing into FluentCRM.
		if ( empty( $submitted['interests_present'] ) ) {
			if ( array_key_exists( 'interest_options', $current ) ) {
				$sanitized['interest_options'] = $current['interest_options'];
			}

			return $sanitized;
		}

		$rows     = $this->sanitize_interests( (array) ( $submitted['interests'] ?? array() ) );
		$previous = (array) ( $current['interest_options'] ?? array() );

		// One direction per save, never both: pulling and then pushing would
		// write back the very names just read, and pushing first would erase
		// the changes made in FluentCRM that the import exists to bring in.
		if ( 'pull' === ( $submitted['interests_sync'] ?? '' ) ) {
			[ $rows, $message ] = $this->pull_interests( $rows );
		} else {
			$message = $this->push_interests( $rows, $previous );
		}

		$sanitized['interest_options'] = $rows;

		if ( '' !== $message ) {
			set_transient( 'galaxie_woo_fcrm_sync_' . get_current_user_id(), $message, MINUTE_IN_SECONDS );
		}

		return $sanitized;
	}

	/**
	 * Communication rows as they are kept: a list, a title and a line. A row
	 * without both a list and a title is dropped — it could not be shown, and
	 * an answer to it would have nowhere to go.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array{list_id:int,title:string,text:string}>
	 */
	public static function sanitize_communications( array $rows ): array {
		$out  = array();
		$seen = array();

		foreach ( $rows as $row ) {
			$row     = (array) $row;
			$list_id = absint( $row['list_id'] ?? 0 );
			$title   = sanitize_text_field( (string) ( $row['title'] ?? '' ) );

			// One row per list: two switches writing the same list would
			// contradict each other the moment one of them moved.
			if ( $list_id <= 0 || '' === trim( $title ) || isset( $seen[ $list_id ] ) ) {
				continue;
			}

			$seen[ $list_id ] = true;

			$out[] = array(
				'list_id' => $list_id,
				'title'   => $title,
				'text'    => sanitize_text_field( (string) ( $row['text'] ?? '' ) ),
			);
		}

		return $out;
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

	/**
	 * FluentCRM → the list. Every tag marked as an interest is in the list
	 * afterwards, named and iconed as the tag is; a row whose tag no longer
	 * exists is dropped, since a deleted tag can never be applied. Rows linked
	 * to unmarked tags stay as they are — the admin chose them here — and an
	 * uploaded image is kept, FluentCRM having nowhere to hold one.
	 *
	 * @param array<int,array{tag_id:int,label:string,icon:string,icon_url:string}> $rows
	 * @return array{0: array<int,array{tag_id:int,label:string,icon:string,icon_url:string}>, 1: string}
	 */
	private function pull_interests( array $rows ): array {
		$tags = $this->all_tags();

		if ( null === $tags ) {
			return array( $rows, __( 'Could not read the tags from FluentCRM. Nothing was imported.', 'galaxie-woo' ) );
		}

		$added   = 0;
		$updated = 0;
		$removed = 0;
		$out     = array();
		$listed  = array();

		foreach ( $rows as $row ) {
			$tag = $tags[ $row['tag_id'] ] ?? null;

			if ( null === $tag ) {
				++$removed;
				continue;
			}

			$icon = $this->marked_icon( (string) $tag->description );
			$next = $row;

			$next['label'] = (string) $tag->title;

			if ( null !== $icon ) {
				$next['icon'] = $icon;
			}

			if ( $next !== $row ) {
				++$updated;
			}

			$out[]                     = $next;
			$listed[ $row['tag_id'] ] = true;
		}

		foreach ( $tags as $id => $tag ) {
			$icon = $this->marked_icon( (string) $tag->description );

			if ( null === $icon || isset( $listed[ $id ] ) ) {
				continue;
			}

			$out[] = array(
				'tag_id'   => (int) $id,
				'label'    => (string) $tag->title,
				'icon'     => $icon,
				'icon_url' => '',
			);
			++$added;
		}

		return array(
			$out,
			sprintf(
				/* translators: 1: interests added, 2: interests updated, 3: interests removed. */
				__( 'Imported from FluentCRM: %1$d added, %2$d updated, %3$d removed because their tag no longer exists.', 'galaxie-woo' ),
				$added,
				$updated,
				$removed
			),
		);
	}

	/**
	 * The list → FluentCRM. Each tag takes its row's label and emoji and is
	 * marked as an interest; a tag whose row was removed here loses the mark,
	 * so the next import does not bring it back. Tags are never deleted — the
	 * customers who chose one keep it.
	 *
	 * @param array<int,array{tag_id:int,label:string,icon:string,icon_url:string}> $rows
	 * @param array<int,mixed>                                                      $previous
	 */
	private function push_interests( array $rows, array $previous ): string {
		$tags = $this->all_tags();

		if ( null === $tags ) {
			return '';
		}

		$changed  = 0;
		$unmarked = 0;
		$kept     = array();

		try {
			foreach ( $rows as $row ) {
				$kept[ $row['tag_id'] ] = true;
				$tag                    = $tags[ $row['tag_id'] ] ?? null;

				if ( null === $tag ) {
					continue;
				}

				$description = trim( self::INTEREST_MARKER . ': ' . $row['icon'] );

				if ( (string) $tag->title !== $row['label'] || (string) $tag->description !== $description ) {
					$tag->title       = $row['label'];
					$tag->description = $description;
					$tag->save();
					++$changed;
				}
			}

			foreach ( $previous as $row ) {
				$id  = absint( ( (array) $row )['tag_id'] ?? 0 );
				$tag = $tags[ $id ] ?? null;

				if ( null === $tag || isset( $kept[ $id ] ) || null === $this->marked_icon( (string) $tag->description ) ) {
					continue;
				}

				$tag->description = '';
				$tag->save();
				++$unmarked;
			}
		} catch ( \Throwable $e ) {
			return __( 'The list was saved, but FluentCRM could not be updated. Try saving again.', 'galaxie-woo' );
		}

		if ( 0 === $changed && 0 === $unmarked ) {
			return '';
		}

		return sprintf(
			/* translators: 1: tags updated, 2: tags no longer marked as interests. */
			__( 'FluentCRM updated: %1$d tags renamed or re-iconed, %2$d no longer marked as interests.', 'galaxie-woo' ),
			$changed,
			$unmarked
		);
	}

	/**
	 * The emoji a marked description carries ('' when marked without one), or
	 * null when the description does not mark the tag as an interest.
	 */
	private function marked_icon( string $description ): ?string {
		$description = trim( $description );

		if ( 0 !== strpos( $description, self::INTEREST_MARKER ) ) {
			return null;
		}

		return trim( ltrim( substr( $description, strlen( self::INTEREST_MARKER ) ), " \t:" ) );
	}

	/** @return array<int,object>|null tag id => FluentCRM tag model, or null when FluentCRM cannot be read. */
	private function all_tags(): ?array {
		if ( ! class_exists( '\FluentCrm\App\Models\Tag' ) ) {
			return null;
		}

		try {
			$tags = array();

			foreach ( \FluentCrm\App\Models\Tag::all() as $tag ) {
				$tags[ (int) $tag->id ] = $tag;
			}

			return $tags;
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
