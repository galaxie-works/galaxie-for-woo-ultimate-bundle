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

	/** An account draft the login merge could not put in the cart. */
	public const PREVIOUS_META = '_galaxie_kit_draft_previous';

	/** The draft's last packing answer ({@see self::remember()}). */
	public const PACKING_KEY = 'galaxie_kit_packing';

	/** Cookie telling the page's script a draft may exist. */
	public const HINT_COOKIE = 'galaxie_kit';

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
		self::hint( true );
	}

	/**
	 * The `galaxie_kit` cookie while there is a draft: kit-store.ts only asks the
	 * kit endpoint when it sees it, so a page without a kit costs no request,
	 * signed in or not. It says nothing about the kit; its value says whose
	 * (`g` for a guest, `u{id}` for an account), so another account signing in
	 * on the same browser is not told about the last one's kit.
	 *
	 * Kept in step by every kit answer (sync_hint()), by every signed-in page
	 * load (another device may have started or finished the kit), and cleared
	 * at logout.
	 */
	private static function hint( bool $on ): void {
		if ( headers_sent() || ! function_exists( 'wc_setcookie' ) ) {
			return;
		}

		$user   = self::user();
		$wanted = $user ? 'u' . $user : 'g';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only compared.
		$now = isset( $_COOKIE[ self::HINT_COOKIE ] ) ? (string) $_COOKIE[ self::HINT_COOKIE ] : null;

		if ( $on && $now !== $wanted ) {
			wc_setcookie( self::HINT_COOKIE, $wanted, time() + 30 * DAY_IN_SECONDS, is_ssl(), false );
			$_COOKIE[ self::HINT_COOKIE ] = $wanted;
		} elseif ( ! $on && null !== $now ) {
			self::forget_hint();
		}
	}

	/** The hint cookie as the visitor's draft says it should be. */
	public static function sync_hint(): void {
		self::hint( null !== self::get() );
	}

	/** No hint (logout: the next visitor of this browser is someone else). */
	public static function forget_hint(): void {
		if ( headers_sent() || ! function_exists( 'wc_setcookie' ) ) {
			return;
		}

		wc_setcookie( self::HINT_COOKIE, '', time() - YEAR_IN_SECONDS, is_ssl(), false );
		unset( $_COOKIE[ self::HINT_COOKIE ] );
	}

	/** Forgets this visitor's draft, in both places. */
	public static function clear(): void {
		$user = self::user();

		if ( $user ) {
			delete_user_meta( $user, self::USER_META );
		}

		self::session_set( self::SESSION_KEY, null );
		self::hint( false );
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

	/**
	 * How long a packing answer the search could not finish is kept in the
	 * session before it is worked out again. A settled answer keeps until the
	 * draft changes; this one is a guess, and a shopper who waits a moment and
	 * asks again deserves the better answer.
	 */
	private const UNSETTLED_SECONDS = 60;

	/**
	 * One packing answer for this visitor's draft, kept in the session by key
	 * (the draft's box, candles, sizes and options): the kit endpoint answers
	 * every request with the draft, and the answer only changes with those.
	 *
	 * `$compute` answers `[ value, settled ]`. An answer the search could not
	 * finish is kept for UNSETTLED_SECONDS and then asked again, so one slow
	 * request does not fix a shopper's "ainda cabem …" for as long as the draft
	 * lasts. A value kept before this rule has no marker and is asked again.
	 *
	 * @param callable $compute (): array{0:mixed, 1:bool}
	 * @return mixed
	 */
	public static function remember( string $key, callable $compute ) {
		$session = self::session();
		$kept    = $session ? $session->get( self::PACKING_KEY ) : null;
		$now     = time();

		if ( is_array( $kept ) && ( $kept['key'] ?? null ) === $key
			&& array_key_exists( 'value', $kept ) && array_key_exists( 'settled', $kept )
			&& ( $kept['settled'] || $now - (int) ( $kept['at'] ?? 0 ) < self::UNSETTLED_SECONDS ) ) {
			return $kept['value'];
		}

		list( $value, $settled ) = $compute();

		if ( $session && self::get() ) {
			self::session_set(
				self::PACKING_KEY,
				array(
					'key'     => $key,
					'value'   => $value,
					'settled' => (bool) $settled,
					'at'      => $now,
				)
			);
		}

		return $value;
	}

	/** Keeps an account's refused draft aside (one at a time, the newest). */
	public static function keep_previous( int $user, array $draft ): void {
		if ( $user ) {
			update_user_meta( $user, self::PREVIOUS_META, $draft );
		}
	}

	/** The signed-in shopper's kept-aside draft, or null. */
	public static function previous(): ?array {
		$user = self::user();

		return $user ? GiftKit::normalize( get_user_meta( $user, self::PREVIOUS_META, true ) ) : null;
	}

	/** Forgets the kept-aside draft. */
	public static function forget_previous(): void {
		$user = self::user();

		if ( $user ) {
			delete_user_meta( $user, self::PREVIOUS_META );
		}
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
