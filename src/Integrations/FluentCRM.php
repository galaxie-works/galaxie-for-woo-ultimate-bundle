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
	 * FluentCRM for ever.
	 *
	 * @param array<string,string|null> $columns Contact columns; '' (or null for dates) clears one.
	 * @param array<string,string>      $custom  Custom field slug => value; '' clears one.
	 */
	public static function update_contact( string $email, array $columns, array $custom = array() ): bool {
		if ( ! self::is_active() || '' === $email ) {
			return false;
		}
		try {
			$contact = \FluentCrmApi( 'contacts' )->getContact( $email );
			if ( ! $contact ) {
				return false;
			}

			$contact->fill( $columns );
			$dirty = $contact->getDirty();

			if ( $dirty ) {
				$contact->save();
			}

			$changed = $custom ? (array) $contact->syncCustomFieldValues( $custom, true ) : array();

			if ( $dirty || $changed ) {
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
	 */
	public static function add_note( string $email, string $title, string $description ): bool {
		if ( ! self::is_active() || '' === $email || ! class_exists( '\FluentCrm\App\Models\SubscriberNote' ) ) {
			return false;
		}
		try {
			$contact = \FluentCrmApi( 'contacts' )->getContact( $email );
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
	 * Adds the custom contact fields that are missing, leaving every field the
	 * store already has — and its values — as it is.
	 *
	 * @param array<int,array{slug:string,label:string,type:string}> $fields
	 */
	public static function ensure_custom_fields( array $fields ): void {
		if ( ! class_exists( '\FluentCrm\App\Models\CustomContactField' ) ) {
			return;
		}
		try {
			$model    = new \FluentCrm\App\Models\CustomContactField();
			$existing = array_values( (array) ( $model->getGlobalFields()['fields'] ?? array() ) );
			$slugs    = array_column( $existing, 'slug' );
			$added    = false;

			foreach ( $fields as $field ) {
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
			}
		} catch ( \Throwable $e ) {
			// Non-fatal: the columns still sync without the custom fields.
		}
	}

	/**
	 * Finds (creating if needed) the contact, then runs $callback( $contact ).
	 * Non-fatal on any failure.
	 *
	 * A contact is born here more often than anywhere else — the first interest
	 * a customer picks, the first opt-in — so it is born with the account's
	 * name, and a contact that was created nameless gets it on its next visit.
	 */
	private static function with_contact( string $email, callable $callback ): void {
		if ( ! self::is_active() || '' === $email ) {
			return;
		}
		try {
			$contact = \FluentCrmApi( 'contacts' )->getContact( $email );
			if ( ! $contact ) {
				self::sync_contact( $email, array_merge( array( 'status' => 'subscribed' ), self::names_for( $email ) ) );
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
