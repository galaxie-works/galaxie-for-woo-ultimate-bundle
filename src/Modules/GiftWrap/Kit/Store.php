<?php
/**
 * Where the draft kit lives: the WooCommerce session, and the account.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\GiftWrap\Kit;

use Galaxie\Woo\Support\GiftKit;

defined( 'ABSPATH' ) || exit;

/**
 * One draft per visitor (kit-flow-scope.md, decisions 1, 5 and 15):
 * - a guest's draft is the session's `galaxie_kit_draft`, and lives as long
 *   as the WooCommerce session;
 * - a signed-in shopper's is user meta `_galaxie_kit_draft`, mirrored to the
 *   session, and kept until added to the cart or discarded. The account copy
 *   is the one read, so another device's change wins;
 * - a draft remembers who made it (`owner`, 0 for a guest). A guest draft
 *   still in the session once the shopper is signed in is the one to merge
 *   ({@see CartKits::merge_login()}).
 *
 * Every draft read goes through {@see GiftKit::normalize()}.
 */
final class Store {

	public const SESSION_KEY = 'galaxie_kit_draft';
	public const USER_META   = '_galaxie_kit_draft';

	/** Sentences for the next kit request to show (a login merge's notice). */
	public const NOTICE_KEY = 'galaxie_kit_notices';

	/** The visitor's draft, or null. */
	public static function get(): ?array {
		$user = self::user();

		if ( $user ) {
			return GiftKit::normalize( get_user_meta( $user, self::USER_META, true ) );
		}

		return self::session_draft();
	}

	/** Saves the draft for this visitor. */
	public static function put( array $draft ): void {
		$user = self::user();

		$draft['owner'] = $user;

		if ( $user ) {
			update_user_meta( $user, self::USER_META, $draft );
		}

		self::ensure_session();
		self::session_set( self::SESSION_KEY, $draft );
	}

	/** Forgets this visitor's draft, in both places. */
	public static function clear(): void {
		$user = self::user();

		if ( $user ) {
			delete_user_meta( $user, self::USER_META );
		}

		self::session_set( self::SESSION_KEY, null );
	}

	/** The draft in the session, whoever made it. */
	public static function session_draft(): ?array {
		$session = self::session();

		return $session ? GiftKit::normalize( $session->get( self::SESSION_KEY ) ) : null;
	}

	/** An account's saved draft. */
	public static function account_draft( int $user ): ?array {
		return $user ? GiftKit::normalize( get_user_meta( $user, self::USER_META, true ) ) : null;
	}

	/** A fresh draft id, lowercase letters and digits. */
	public static function new_id(): string {
		return strtolower( wp_generate_password( 12, false ) );
	}

	/** Keeps a sentence for the next kit response. */
	public static function notice( string $text ): void {
		$session = self::session();

		if ( ! $session ) {
			return;
		}

		$list   = (array) $session->get( self::NOTICE_KEY );
		$list[] = $text;
		self::session_set( self::NOTICE_KEY, array_slice( $list, -5 ) );
	}

	/** @return string[] The kept sentences, now forgotten. */
	public static function take_notices(): array {
		$session = self::session();
		$list    = $session ? array_values( array_filter( (array) $session->get( self::NOTICE_KEY ), 'is_string' ) ) : array();

		if ( $list ) {
			self::session_set( self::NOTICE_KEY, null );
		}

		return $list;
	}

	/**
	 * A guest has no WooCommerce session until the cart is first used; a draft
	 * needs one to be remembered on the next request.
	 */
	public static function ensure_session(): void {
		$session = self::session();

		if ( $session && method_exists( $session, 'has_session' ) && ! $session->has_session() && method_exists( $session, 'set_customer_session_cookie' ) ) {
			$session->set_customer_session_cookie( true );
		}
	}

	private static function user(): int {
		return is_user_logged_in() ? (int) get_current_user_id() : 0;
	}

	/** @return object|null WooCommerce's session handler. */
	private static function session(): ?object {
		return function_exists( 'WC' ) && isset( WC()->session ) && is_object( WC()->session ) ? WC()->session : null;
	}

	/** @param mixed $value */
	private static function session_set( string $key, $value ): void {
		$session = self::session();

		if ( $session ) {
			$session->set( $key, $value );
		}
	}
}
