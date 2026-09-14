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
			add_action( 'galaxie_woo/profile_updated', array( $this, 'queue_profile_sync' ) );
			add_action( 'profile_update', array( $this, 'queue_profile_sync' ) );
			add_action( 'galaxie_woo/interest_changed', array( $this, 'remember_interest' ), 10, 3 );
			add_action( 'woocommerce_new_payment_token', array( $this, 'remember_card_added' ), 10, 2 );
			add_action( 'woocommerce_payment_token_deleted', array( $this, 'remember_card_deleted' ), 10, 2 );
			add_action( 'woocommerce_payment_token_set_default', array( $this, 'remember_card_default' ), 10, 2 );

			foreach ( array( 'add_user_metadata', 'update_user_metadata', 'delete_user_metadata' ) as $hook ) {
				add_filter( $hook, array( $this, 'remember_old_meta' ), 10, 3 );
			}

			foreach ( array( 'added_user_meta', 'updated_user_meta', 'deleted_user_meta' ) as $hook ) {
				add_action( $hook, array( $this, 'watch_user_meta' ), 10, 3 );
			}

			add_action( 'shutdown', array( $this, 'flush_profile_sync' ) );
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

	/**
	 * Keeps what a field held before its first write in this request, so the
	 * note can say before → after. A filter that changes nothing.
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
		if ( ! $this->profile_queue || ! FluentCRMApi::is_active() ) {
			return;
		}

		$this->profile_flushing = true;
		$users                  = array_keys( $this->profile_queue );
		$this->profile_queue    = array();

		if ( $this->sync_contacts ) {
			FluentCRMApi::ensure_custom_fields( self::CUSTOM_FIELDS );
		}

		foreach ( $users as $user_id ) {
			$user = get_userdata( (int) $user_id );

			if ( ! $user ) {
				continue;
			}

			// The note first: it records the account's own before and after,
			// whatever the contact happened to hold.
			if ( $this->log_changes ) {
				$this->log_profile_changes( $user );
			}

			if ( $this->sync_contacts ) {
				$this->sync_profile( (int) $user_id );
			}
		}

		$this->old_meta         = array();
		$this->event_log        = array();
		$this->profile_flushing = false;
	}

	/** Every change in this request, as one note on the contact. */
	private function log_profile_changes( \WP_User $user ): void {
		$lines = array();

		foreach ( $this->old_meta[ $user->ID ] ?? array() as $key => $old ) {
			$new = get_user_meta( $user->ID, $key, true );

			if ( \Galaxie\Woo\Support\AddressBook::META_KEY === $key ) {
				$lines = array_merge( $lines, $this->address_book_changes( is_array( $old ) ? $old : array(), is_array( $new ) ? $new : array() ) );
				continue;
			}

			$before = $this->display_value( $key, is_scalar( $old ) ? (string) $old : '' );
			$after  = $this->display_value( $key, is_scalar( $new ) ? (string) $new : '' );

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

		$description = sprintf(
			'<p><strong>%1$s</strong> %2$s<br><strong>%3$s</strong> %4$s<br><strong>%5$s</strong> %6$s</p><ul><li>%7$s</li></ul>',
			esc_html__( 'Data e hora:', 'galaxie-woo' ),
			esc_html( wp_date( 'd/m/Y H:i:s' ) ),
			esc_html__( 'Origem:', 'galaxie-woo' ),
			esc_html( $this->change_source() ),
			esc_html__( 'Alterado por:', 'galaxie-woo' ),
			esc_html( $by ),
			implode( '</li><li>', $lines )
		);

		FluentCRMApi::add_note( $user->user_email, __( 'Alteração de perfil', 'galaxie-woo' ), $description );
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

		if ( '' === $value ) {
			return __( '(vazio)', 'galaxie-woo' );
		}

		if ( ProfileFields::BIRTHDATE === $key && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $date ) ) {
			return $date[3] . '/' . $date[2] . '/' . $date[1];
		}

		if ( ProfileFields::GENDER === $key ) {
			return ProfileFields::gender_label( $value );
		}

		if ( ProfileFields::MARKETING_OPT_IN === $key ) {
			return 'yes' === $value ? __( 'Sim', 'galaxie-woo' ) : __( 'Não', 'galaxie-woo' );
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

	/** Where the change was made, from the request that made it. */
	private function change_source(): string {
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
			'wc_stripe_create_and_confirm_setup_intent' => __( 'Minha conta — Formas de pagamento', 'galaxie-woo' ),
		);

		if ( isset( $screens[ $action ] ) ) {
			return $screens[ $action ];
		}

		// Deleting a card or making it the default is a link WooCommerce handles.
		if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'delete-payment-method' ) || is_wc_endpoint_url( 'set-default-payment-method' ) || is_wc_endpoint_url( 'add-payment-method' ) ) ) {
			return __( 'Minha conta — Formas de pagamento', 'galaxie-woo' );
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return __( 'Checkout', 'galaxie-woo' );
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return __( 'wp-admin', 'galaxie-woo' );
		}

		return __( 'Site', 'galaxie-woo' );
	}

	/**
	 * The account, as the contact should show it. The address is the billing
	 * one, where the customer lives and pays; the shipping one only when there is
	 * no billing address. An emptied field empties the contact's too — except the
	 * name, which a contact keeps.
	 */
	private function sync_profile( int $user_id ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$meta  = static fn( string $key ): string => trim( (string) get_user_meta( $user_id, $key, true ) );
		$type  = '' !== $meta( 'billing_address_1' ) ? 'billing' : 'shipping';
		$birth = $meta( ProfileFields::BIRTHDATE );

		$columns = array(
			'first_name'     => trim( (string) $user->first_name ) ?: $meta( 'billing_first_name' ),
			'last_name'      => trim( (string) $user->last_name ) ?: $meta( 'billing_last_name' ),
			'phone'          => $meta( 'billing_phone' ) ?: $meta( 'shipping_phone' ),
			'date_of_birth'  => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $birth ) ? $birth : null,
			'address_line_1' => $meta( $type . '_address_1' ),
			'address_line_2' => $meta( $type . '_address_2' ),
			'city'           => $meta( $type . '_city' ),
			'state'          => $meta( $type . '_state' ),
			'postal_code'    => $meta( $type . '_postcode' ),
			'country'        => $meta( $type . '_country' ),
		);

		foreach ( array( 'first_name', 'last_name' ) as $name ) {
			if ( '' === $columns[ $name ] ) {
				unset( $columns[ $name ] );
			}
		}

		$gender = $meta( ProfileFields::GENDER );

		FluentCRMApi::update_contact(
			$user->user_email,
			$columns,
			array(
				'cpf'         => $meta( ProfileFields::CPF ),
				'genero'      => '' !== $gender ? ProfileFields::gender_label( $gender ) : '',
				'nome_social' => $meta( ProfileFields::SOCIAL_NAME ),
			)
		);

		$this->append_other_addresses( $user, $type );
	}

	/**
	 * The address book's other addresses, added to the merchant's field and never
	 * removed from it: a record of every place the customer has kept, dated the
	 * day it was first seen. The contact's main address is left out while it is
	 * the main one, and joins the list the day it stops being it.
	 */
	private function append_other_addresses( \WP_User $user, string $main_type ): void {
		if ( ! class_exists( '\Galaxie\Woo\Support\AddressBook' ) ) {
			return;
		}

		$slug = FluentCRMApi::find_custom_field( self::OTHER_ADDRESSES_SLUG, self::OTHER_ADDRESSES_LABEL );

		if ( null === $slug ) {
			return;
		}

		$lines = array();

		foreach ( \Galaxie\Woo\Support\AddressBook::for_js( $user->ID ) as $entry ) {
			if ( ! empty( $entry[ $main_type ] ) ) {
				continue;
			}

			$text = $this->address_line( array_merge( (array) $entry['values'], array( 'label' => (string) $entry['label'] ) ) );

			if ( '' !== $text ) {
				/* translators: 1: the address, 2: the date it was recorded. */
				$lines[ $text ] = sprintf( __( '%1$s (registrado em %2$s)', 'galaxie-woo' ), $text, wp_date( 'd/m/Y' ) );
			}
		}

		FluentCRMApi::append_to_custom_field( $user->user_email, $slug, $lines );
	}

	/**
	 * One address book entry on one line: its label, the address as WooCommerce
	 * formats it for the country, and its phone.
	 *
	 * @param array<string,mixed> $entry
	 */
	private function address_line( array $entry ): string {
		$address = html_entity_decode( wp_strip_all_tags( (string) preg_replace( '#<br\s*/?>#i', ', ', \Galaxie\Woo\Support\AddressBook::format( $entry ) ) ), ENT_QUOTES );
		$phone   = trim( (string) ( $entry['phone'] ?? '' ) );
		$label   = trim( (string) ( $entry['label'] ?? '' ) );

		if ( '' === trim( $address ) ) {
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
				description: __( 'Every change a customer makes — profile fields, addresses, communication consent, interests, saved cards (brand, last four digits, expiry) — is written on their FluentCRM contact\'s Notes tab as before → after, with the date and time, where it was made and who made it. Keeps a record of data the contact itself no longer shows.', 'galaxie-woo' ),
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
			$raw               = $submitted[ $key ] ?? '';
			$sanitized[ $key ] = '' === $raw ? '' : absint( $raw );
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
