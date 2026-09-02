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
			return array_map( static fn( $tag ) => (int) $tag->id, (array) $contact->tags );
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

	/** Finds (creating if needed) the contact, then runs $callback( $contact ). Non-fatal on any failure. */
	private static function with_contact( string $email, callable $callback ): void {
		if ( ! self::is_active() || '' === $email ) {
			return;
		}
		try {
			$contact = \FluentCrmApi( 'contacts' )->getContact( $email );
			if ( ! $contact ) {
				self::sync_contact( $email, array( 'status' => 'subscribed' ) );
				$contact = \FluentCrmApi( 'contacts' )->getContact( $email );
			}
			if ( $contact ) {
				$callback( $contact );
			}
		} catch ( \Throwable $e ) {
			// Non-fatal.
		}
	}
}
