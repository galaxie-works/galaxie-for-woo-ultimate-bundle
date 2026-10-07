<?php
/**
 * FluentCRM contact sync — a plain service, not a toggleable module.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by any module that needs to read/write a customer's FluentCRM
 * contact (MyAccount's Interests/Communication tabs, the FluentCRM module's
 * own signup/order-status tagging). Every method no-ops safely if FluentCRM
 * isn't active — callers never need their own `function_exists()` guard.
 *
 * Method names (`getContact`, `attachTags`, `createOrUpdate`, ...) match
 * eir-my-account-ux's proven, live-verified usage of `FluentCrmApi()` — not
 * the unverified `FluentCrmApi('tags')/('lists')` guess used elsewhere for
 * tag/list *discovery* (see Modules\FluentCRM's settings tab).
 */
final class FluentCRM {

	/** @var array<string,true> Custom field slugs already checked in this request. */
	private static array $ensured_fields = array();

	/** @var array<string,string|null> find_custom_field() answers, per request. */
	private static array $found_fields = array();

	public static function is_active(): bool {
		return function_exists( 'FluentCrmApi' );
	}

	/** @return int[] */
	public static function contact_tag_ids( string $email ): array {
		if ( ! self::is_active() || '' === $email ) {
			return array();
		}
		try {
			$contact = \FluentCrmApi( 'contacts' )->getContact( $email );
			if ( ! $contact ) {
				return array();
			}
			// `tags` is an Eloquent Collection. Cast to an array it yields the
			// object's internal properties, not the tags — which is how every
			// chosen interest came back unchosen. Iterating it walks the tags.
			$ids = array();

			foreach ( $contact->tags ?? array() as $tag ) {
				$ids[] = (int) ( is_object( $tag ) ? ( $tag->id ?? 0 ) : ( $tag['id'] ?? 0 ) );
			}

			return array_values( array_filter( $ids ) );
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * The lists a contact belongs to, by id. Same shape and the same trap as
	 * {@see self::contact_tag_ids()}: `lists` is a Collection, so it is walked.
	 *
	 * @return int[]
	 */
	public static function contact_list_ids( string $email ): array {
		if ( ! self::is_active() || '' === $email ) {
			return array();
		}

		try {
			$contact = \FluentCrmApi( 'contacts' )->getContact( $email );

			if ( ! $contact ) {
				return array();
			}

			$ids = array();

			foreach ( $contact->lists ?? array() as $list ) {
				$ids[] = (int) ( is_object( $list ) ? ( $list->id ?? 0 ) : ( $list['id'] ?? 0 ) );
			}

			return array_values( array_filter( $ids ) );
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/** @param int[] $tag_ids */
	public static function attach_tags( string $email, array $tag_ids ): void {
		self::with_contact( $email, static fn( $contact ) => $contact->attachTags( $tag_ids ) );
	}

	/** @param int[] $tag_ids */
	public static function detach_tags( string $email, array $tag_ids ): void {
		self::with_contact( $email, static fn( $contact ) => $contact->detachTags( $tag_ids ) );
	}

	/** @param int[] $list_ids */
	public static function attach_lists( string $email, array $list_ids ): void {
		self::with_contact( $email, static fn( $contact ) => $contact->attachLists( $list_ids ) );
	}

	/** @param int[] $list_ids */
	public static function detach_lists( string $email, array $list_ids ): void {
		self::with_contact( $email, static fn( $contact ) => $contact->detachLists( $list_ids ) );
	}

	/**
	 * The status for a contact who has not consented to marketing (LGPD): in
	 * FluentCRM, but receiving only transactional e-mail.
	 *
	 * `transactional` where this FluentCRM has it; otherwise `pending` for a
	 * new contact (no double opt-in is sent from here) and `unsubscribed` for
	 * one withdrawing consent.
	 */
	public static function no_consent_status( bool $withdrawing = false ): string {
		if ( function_exists( 'fluentcrm_subscriber_statuses' ) ) {
			$statuses = (array) fluentcrm_subscriber_statuses();
			if ( in_array( 'transactional', $statuses, true ) || isset( $statuses['transactional'] ) ) {
				return 'transactional';
			}
		}

		return $withdrawing ? 'unsubscribed' : 'pending';
	}

	/** Whether the account behind `$email` said yes to marketing. Never answered is no. */
	public static function has_consent( string $email ): bool {
		$user = get_user_by( 'email', $email );

		return $user && 'yes' === get_user_meta( $user->ID, \Galaxie\Woo\Support\ProfileFields::MARKETING_OPT_IN, true );
	}

	/**
	 * Creates or updates a contact with a status that follows the consent:
	 * consent makes it `subscribed`; without it a NEW contact gets
	 * {@see no_consent_status()} and an existing one keeps whatever status it
	 * has — a subscription given before is never downgraded here.
	 *
	 * @param array<string,mixed> $fields Contact fields, without `status`.
	 */
	public static function sync_contact_consent( string $email, array $fields, bool $consent ): void {
		if ( ! self::is_active() || '' === $email ) {
			return;
		}
		unset( $fields['status'] );
		try {
			if ( $consent ) {
				$fields['status'] = 'subscribed';
			} elseif ( ! \FluentCrmApi( 'contacts' )->getContact( $email ) ) {
				$fields['status'] = self::no_consent_status();
			}
		} catch ( \Throwable $e ) {
			return;
		}
		self::sync_contact( $email, $fields );
	}

	/**
	 * The customer changed their answer: yes subscribes the contact, no takes
	 * marketing away ({@see no_consent_status()}).
	 *
	 * An existing contact has its status set through FluentCRM's own
	 * updateStatus(): createOrUpdate() keeps a subscribed contact subscribed
	 * whatever status it is sent (FluentCRM's updateOrCreate() drops the status
	 * unless forced), so "no" never took a subscribed contact out of
	 * marketing. A contact that does not exist yet is created with the status.
	 */
	public static function set_consent( string $email, bool $consent, int $user_id = 0 ): void {
		if ( ! self::is_active() || ( '' === $email && $user_id <= 0 ) ) {
			return;
		}

		$status = $consent ? 'subscribed' : self::no_consent_status( true );

		try {
			$contact = self::find_contact( $email, $user_id );

			if ( $contact ) {
				self::apply_status( $contact, $status );
				return;
			}
		} catch ( \Throwable $e ) {
			return;
		}

		if ( '' !== $email ) {
			self::sync_contact( $email, array_merge( array( 'status' => $status ), self::names_for( $email ) ) );
		}
	}

	/**
	 * A contact's status moved to `$status` — or left alone where FluentCRM's
	 * own rules say so: a bounce, complaint or spam report is never
	 * re-subscribed from here, and withdrawing consent leaves a contact that is
	 * already outside marketing as it is.
	 *
	 * @param object $contact FluentCRM Subscriber model.
	 */
	public static function apply_status( $contact, string $status ): bool {
		$current = (string) ( $contact->status ?? '' );

		if ( $current === $status ) {
			return false;
		}

		if ( 'subscribed' === $status && in_array( $current, array( 'bounced', 'complained', 'spammed' ), true ) ) {
			return false;
		}

		if ( 'subscribed' !== $status && in_array( $current, array( 'unsubscribed', 'bounced', 'complained', 'spammed', 'transactional' ), true ) ) {
			return false;
		}

		try {
			if ( is_callable( array( $contact, 'updateStatus' ) ) ) {
				$contact->updateStatus( $status );
			} else {
				$contact->status = $status;
				$contact->save();
			}

			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/** Whether the account said yes to marketing. Never answered is no. */
	private static function account_consent( int $user_id ): bool {
		return $user_id > 0 && 'yes' === get_user_meta( $user_id, \Galaxie\Woo\Support\ProfileFields::MARKETING_OPT_IN, true );
	}

	/**
	 * The account's contact taken out of FluentCRM: unsubscribed first (so
	 * FluentCRM stops its automations and e-mails for it the way it does for
	 * any unsubscribe), then deleted with FluentCRM's own helper, which also
	 * removes its notes, tags, lists and custom values.
	 */
	public static function delete_contact( string $email, int $user_id = 0 ): bool {
		if ( ! self::is_active() || ( '' === $email && $user_id <= 0 ) ) {
			return false;
		}
		try {
			$contact = self::find_contact( $email, $user_id );

			if ( ! $contact ) {
				return false;
			}

			if ( 'unsubscribed' !== (string) $contact->status && is_callable( array( $contact, 'updateStatus' ) ) ) {
				$contact->updateStatus( 'unsubscribed' );
			}

			if ( is_callable( array( '\FluentCrm\App\Services\Helper', 'deleteContacts' ) ) ) {
				\FluentCrm\App\Services\Helper::deleteContacts( array( (int) $contact->id ) );
			} else {
				$contact->delete();
			}

			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/** @param array<string,mixed> $fields */
	public static function sync_contact( string $email, array $fields ): void {
		if ( ! self::is_active() || '' === $email ) {
			return;
		}
		try {
			\FluentCrmApi( 'contacts' )->createOrUpdate( array_merge( array( 'email' => $email ), $fields ) );
		} catch ( \Throwable $e ) {
			// Non-fatal — FluentCRM sync is a best-effort side effect.
		}
	}

	/**
	 * An existing contact brought in line with the account. Never creates one:
	 * a contact is born where there is consent or a reason for it, not because
	 * someone edited their address.
	 *
	 * Written through the model rather than `createOrUpdate()`, which drops empty
	 * values — so a phone or an address the customer erased would stay behind in
	 * FluentCRM for ever. Only what the caller passes is touched, so the caller
	 * decides what changed; a column already holding the value is left out, so
	 * an unchanged date of birth does not make the contact dirty.
	 *
	 * @param array<string,string|null> $columns Contact columns; '' (or null for dates) clears one.
	 * @param array<string,string>      $custom  Custom field slug => value; '' clears one.
	 * @param int                       $user_id The account behind the contact, found by it first.
	 */
	public static function update_contact( string $email, array $columns, array $custom = array(), int $user_id = 0 ): bool {
		if ( ! self::is_active() || ( '' === $email && $user_id <= 0 ) ) {
			return false;
		}
		try {
			$contact = self::find_contact( $email, $user_id );
			if ( ! $contact ) {
				return false;
			}

			foreach ( $columns as $column => $value ) {
				if ( self::column_value( $column, $contact->{$column} ?? null ) === self::column_value( $column, $value ) ) {
					unset( $columns[ $column ] );
				}
			}

			$dirty = array();

			if ( $columns ) {
				$contact->fill( $columns );
				$dirty = $contact->getDirty();

				if ( $dirty ) {
					$contact->save();
				}
			}

			// FluentCRM only deletes a custom value sent as '' when told to, so the
			// flag is raised only when a value is being cleared.
			$clears  = in_array( '', array_map( 'strval', $custom ), true );
			$changed = $custom ? (array) $contact->syncCustomFieldValues( $custom, $clears ) : array();

			if ( $dirty || $changed ) {
				do_action( 'fluent_crm/contact_updated', $contact, $dirty );
			}

			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Moves the contact of an account whose e-mail changed to the new address,
	 * so later syncs, notes and tags still find it.
	 *
	 * When a contact already has the new address, merging the two is the
	 * merchant's call, so they stay apart — but both get the account's current
	 * consent (the person behind both addresses answered it) and a note saying
	 * what happened, so the split is seen rather than found later.
	 */
	public static function change_contact_email( int $user_id, string $old_email, string $new_email ): bool {
		if ( ! self::is_active() || '' === $new_email || 0 === strcasecmp( $old_email, $new_email ) ) {
			return false;
		}
		try {
			$api      = \FluentCrmApi( 'contacts' );
			$existing = $api->getContact( $new_email );

			if ( $existing ) {
				$status   = self::account_consent( $user_id ) ? 'subscribed' : self::no_consent_status( true );
				$contacts = array( (int) $existing->id => $existing );
				$old      = '' !== $old_email ? $api->getContact( $old_email ) : null;

				if ( $old ) {
					$contacts[ (int) $old->id ] = $old;
				}

				$note = sprintf(
					/* translators: 1: account id, 2: old e-mail, 3: new e-mail, 4: Sim/Não */
					__( 'A conta #%1$d trocou o e-mail de %2$s para %3$s, e já existia um contato com o novo e-mail. Os contatos continuam separados — mescle à mão se forem a mesma pessoa. Consentimento de marketing aplicado aos dois: %4$s.', 'galaxie-woo' ),
					$user_id,
					$old_email,
					$new_email,
					'subscribed' === $status ? __( 'Sim', 'galaxie-woo' ) : __( 'Não', 'galaxie-woo' )
				);

				foreach ( $contacts as $id => $contact ) {
					self::apply_status( $contact, $status );
					self::add_note_by_contact_id( (int) $id, __( 'E-mail da conta alterado', 'galaxie-woo' ), '<p>' . esc_html( $note ) . '</p>' );
				}

				return false;
			}

			$contact = self::find_contact( $old_email, $user_id );
			if ( ! $contact ) {
				return false;
			}

			$contact->email = $new_email;
			$dirty          = $contact->getDirty();

			if ( $dirty ) {
				$contact->save();
				do_action( 'fluent_crm/contact_updated', $contact, $dirty );
			}

			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * A note on the contact's Notes tab, dated by FluentCRM and credited to the
	 * signed-in user. Nothing when there is no contact to write it on.
	 *
	 * @param string $description HTML, already escaped by the caller.
	 * @param int    $user_id     The account behind the contact, found by it first.
	 * @param string $alt_email   Another address to try — the old one, when the e-mail just changed.
	 */
	public static function add_note( string $email, string $title, string $description, int $user_id = 0, string $alt_email = '' ): bool {
		if ( ! self::is_active() || ! class_exists( '\FluentCrm\App\Models\SubscriberNote' ) ) {
			return false;
		}
		try {
			$contact = self::find_contact( $email, $user_id, $alt_email );
			if ( ! $contact ) {
				return false;
			}

			\FluentCrm\App\Models\SubscriberNote::create(
				array(
					'subscriber_id' => (int) $contact->id,
					'type'          => 'note',
					'title'         => $title,
					'description'   => $description,
				)
			);

			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * A note on the contact with this id. For callers that know the contact
	 * rather than the account — an edit made in FluentCRM's own admin.
	 *
	 * @param string $description HTML, already escaped by the caller.
	 */
	public static function add_note_by_contact_id( int $contact_id, string $title, string $description ): bool {
		if ( $contact_id <= 0 || ! self::is_active() || ! class_exists( '\FluentCrm\App\Models\SubscriberNote' ) ) {
			return false;
		}
		try {
			\FluentCrm\App\Models\SubscriberNote::create(
				array(
					'subscriber_id' => $contact_id,
					'type'          => 'note',
					'title'         => $title,
					'description'   => $description,
				)
			);

			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/** The contact columns a profile is made of, as FluentCRM names them. */
	public const PROFILE_COLUMNS = array(
		'first_name',
		'last_name',
		'email',
		'phone',
		'date_of_birth',
		'address_line_1',
		'address_line_2',
		'city',
		'state',
		'postal_code',
		'country',
		'status',
	);

	/**
	 * What a contact's profile holds right now, read fresh from the database:
	 * its profile columns and every custom field value, each as a comparable
	 * string (an empty date and a missing custom value are ''; a multi-choice
	 * value is its choices joined by ", "). Null when there is no such contact
	 * or it cannot be read.
	 *
	 * @return array{columns:array<string,string>,custom:array<string,string>}|null
	 */
	public static function contact_profile( int $contact_id ): ?array {
		if ( $contact_id <= 0 || ! self::is_active() || ! class_exists( '\FluentCrm\App\Models\Subscriber' ) ) {
			return null;
		}
		try {
			$contact = \FluentCrm\App\Models\Subscriber::find( $contact_id );

			if ( ! $contact ) {
				return null;
			}

			$columns = array();

			foreach ( self::PROFILE_COLUMNS as $column ) {
				$columns[ $column ] = self::column_value( $column, $contact->{$column} ?? null );
			}

			$custom = array();

			foreach ( (array) $contact->custom_fields() as $slug => $value ) {
				$custom[ (string) $slug ] = self::custom_value( $value );
			}

			return array(
				'columns' => $columns,
				'custom'  => $custom,
			);
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Every custom contact field's label, by slug, as the merchant named it in
	 * FluentCRM. Empty when the definitions cannot be read.
	 *
	 * @return array<string,string>
	 */
	public static function custom_field_labels(): array {
		if ( ! class_exists( '\FluentCrm\App\Models\CustomContactField' ) ) {
			return array();
		}
		try {
			$labels = array();

			foreach ( (array) ( ( new \FluentCrm\App\Models\CustomContactField() )->getGlobalFields()['fields'] ?? array() ) as $field ) {
				$slug = (string) ( $field['slug'] ?? '' );

				if ( '' !== $slug ) {
					$labels[ $slug ] = trim( (string) ( $field['label'] ?? '' ) );
				}
			}

			return $labels;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/** @param mixed $value */
	private static function custom_value( $value ): string {
		if ( is_string( $value ) && is_serialized( $value ) ) {
			$value = maybe_unserialize( $value );
		}

		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( array_map( static fn( $item ): string => is_scalar( $item ) ? trim( (string) $item ) : '', $value ), 'strlen' ) );
		}

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * The slug of a custom contact field, found by slug first and by its label
	 * when the slug is not there — a field the merchant created by hand keeps
	 * working if they rename its slug. Null when neither matches. Remembered for
	 * the request: the global fields are one option read each time otherwise.
	 */
	public static function find_custom_field( string $slug, string $label ): ?string {
		$cache_key = $slug . '|' . $label;

		if ( array_key_exists( $cache_key, self::$found_fields ) ) {
			return self::$found_fields[ $cache_key ];
		}

		if ( ! class_exists( '\FluentCrm\App\Models\CustomContactField' ) ) {
			return null;
		}

		$found = null;

		try {
			$fields = (array) ( ( new \FluentCrm\App\Models\CustomContactField() )->getGlobalFields()['fields'] ?? array() );

			foreach ( $fields as $field ) {
				if ( (string) ( $field['slug'] ?? '' ) === $slug ) {
					$found = $slug;
					break;
				}
			}

			if ( null === $found ) {
				foreach ( $fields as $field ) {
					if ( 0 === strcasecmp( trim( (string) ( $field['label'] ?? '' ) ), trim( $label ) ) ) {
						$found = (string) $field['slug'];
						break;
					}
				}
			}
		} catch ( \Throwable $e ) {
			// Not remembered: a read that failed may succeed later in the request.
			return null;
		}

		self::$found_fields[ $cache_key ] = $found;

		return $found;
	}

	/**
	 * Makes a multi-line custom field hold exactly these lines: a line whose
	 * key is already in the field keeps the wording it has there (and the date
	 * it was first recorded), a new one is added, and a line whose key is no
	 * longer given is taken out — an address the customer deleted leaves the
	 * contact too.
	 *
	 * @param array<string,string> $lines Key to look for => line to write when it is new.
	 */
	public static function replace_custom_field_lines( string $email, string $slug, array $lines, int $user_id = 0 ): bool {
		if ( ! self::is_active() || '' === $slug ) {
			return false;
		}
		try {
			$contact = self::find_contact( $email, $user_id );
			if ( ! $contact ) {
				return false;
			}

			$current = trim( (string) ( $contact->custom_fields()[ $slug ] ?? '' ) );
			$split   = preg_split( '/\R/', $current );
			$kept    = array_values( array_filter( array_map( 'trim', is_array( $split ) ? $split : array() ), 'strlen' ) );
			$out     = array();

			foreach ( $lines as $key => $line ) {
				$key   = trim( (string) $key );
				$found = null;

				foreach ( $kept as $existing ) {
					if ( '' !== $key && false !== mb_stripos( $existing, $key ) ) {
						$found = $existing;
						break;
					}
				}

				$out[] = $found ?? $line;
			}

			$value = implode( "\n", array_unique( $out ) );

			if ( $value === $current ) {
				return true;
			}

			$contact->syncCustomFieldValues( array( $slug => $value ), '' === $value );

			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Adds the custom contact fields that are missing, leaving every field the
	 * store already has — and its values — as it is. Checked once per request:
	 * the caller runs it just before writing a custom value, which can happen
	 * for several users in one request.
	 *
	 * @param array<int,array{slug:string,label:string,type:string}> $fields
	 */
	public static function ensure_custom_fields( array $fields ): void {
		$fields = array_values( array_filter( $fields, static fn( array $field ): bool => ! isset( self::$ensured_fields[ $field['slug'] ] ) ) );

		if ( ! $fields || ! class_exists( '\FluentCrm\App\Models\CustomContactField' ) ) {
			return;
		}
		try {
			$model    = new \FluentCrm\App\Models\CustomContactField();
			$existing = array_values( (array) ( $model->getGlobalFields()['fields'] ?? array() ) );
			$slugs    = array_column( $existing, 'slug' );
			$added    = false;

			foreach ( $fields as $field ) {
				self::$ensured_fields[ $field['slug'] ] = true;

				if ( in_array( $field['slug'], $slugs, true ) ) {
					continue;
				}

				$existing[] = array(
					'field_key' => $field['type'],
					'type'      => $field['type'],
					'label'     => $field['label'],
					'slug'      => $field['slug'],
					'group'     => 'default',
				);
				$added      = true;
			}

			if ( $added ) {
				$model->saveGlobalFields( $existing );
				self::$found_fields = array();
			}
		} catch ( \Throwable $e ) {
			// Non-fatal: the columns still sync without the custom fields.
		}
	}

	/**
	 * The contact of an account: by the WordPress user first, which survives an
	 * e-mail change once FluentCRM has linked the two, then by address.
	 *
	 * @return object|null FluentCRM Subscriber model.
	 */
	private static function find_contact( string $email, int $user_id = 0, string $alt_email = '' ) {
		$api = \FluentCrmApi( 'contacts' );

		if ( $user_id > 0 && is_callable( array( $api, 'getContactByUserRef' ) ) ) {
			try {
				$contact = $api->getContactByUserRef( $user_id );
				if ( $contact ) {
					return $contact;
				}
			} catch ( \Throwable $e ) {
				// Older FluentCRM: fall back to the address.
			}
		}

		foreach ( array_unique( array( $email, $alt_email ) ) as $address ) {
			if ( '' !== $address ) {
				$contact = $api->getContact( $address );
				if ( $contact ) {
					return $contact;
				}
			}
		}

		return null;
	}

	/**
	 * A column's value as it compares: null, '' and a zero date are all "empty",
	 * and a date is its day, whether it arrives as a string or a Carbon.
	 *
	 * @param mixed $value
	 */
	private static function column_value( string $column, $value ): string {
		$value = null === $value ? '' : trim( (string) $value );

		if ( 'date_of_birth' === $column ) {
			$value = substr( $value, 0, 10 );
			return '0000-00-00' === $value ? '' : $value;
		}

		return $value;
	}

	/**
	 * Finds (creating if needed) the contact, then runs $callback( $contact ).
	 * Non-fatal on any failure.
	 *
	 * A contact is born here more often than anywhere else — the first interest
	 * a customer picks, the first opt-in, a paid order joining the customers
	 * list — so it is born with the account's name, and a contact that was
	 * created nameless gets it on its next visit. It is born `subscribed` only
	 * with the account's marketing consent (LGPD); otherwise with
	 * {@see no_consent_status()}.
	 */
	private static function with_contact( string $email, callable $callback ): void {
		if ( ! self::is_active() || '' === $email ) {
			return;
		}
		try {
			$contact = \FluentCrmApi( 'contacts' )->getContact( $email );
			if ( ! $contact ) {
				self::sync_contact( $email, array_merge( array( 'status' => self::has_consent( $email ) ? 'subscribed' : self::no_consent_status() ), self::names_for( $email ) ) );
				$contact = \FluentCrmApi( 'contacts' )->getContact( $email );
			} elseif ( '' === trim( (string) $contact->first_name ) && ( $names = self::names_for( $email ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found
				self::sync_contact( $email, $names );
				$contact = \FluentCrmApi( 'contacts' )->getContact( $email );
			}
			if ( $contact ) {
				$callback( $contact );
			}
		} catch ( \Throwable $e ) {
			// Non-fatal.
		}
	}

	/**
	 * The name of the account behind an email, falling back to the billing name
	 * a checkout wrote when the profile itself was never filled in.
	 *
	 * @return array<string,string> Only the parts that are known.
	 */
	private static function names_for( string $email ): array {
		$user = get_user_by( 'email', $email );

		if ( ! $user ) {
			return array();
		}

		$first = trim( (string) $user->first_name ) ?: trim( (string) get_user_meta( $user->ID, 'billing_first_name', true ) );
		$last  = trim( (string) $user->last_name ) ?: trim( (string) get_user_meta( $user->ID, 'billing_last_name', true ) );

		return array_filter(
			array(
				'first_name' => $first,
				'last_name'  => $last,
			)
		);
	}
}
