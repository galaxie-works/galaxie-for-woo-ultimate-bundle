<?php
/**
 * Atomic counters and state for the sign-in endpoints.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\PasswordlessAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Every read-change-write of sign-in state (codes, wrong attempts, request
 * counts) runs while holding a MySQL named lock (GET_LOCK) for that piece of
 * state, so two requests at once cannot both read "4 attempts" and both
 * write "5": the second waits for the first. Kept in transients, which WordPress
 * cleans up on its own; inside the lock the per-request option cache is
 * dropped first, so a value read earlier in the same request is never trusted.
 *
 * The object cache alone is not used for counting: without a persistent one
 * it lives for a single request, and with one its increments do not cover the
 * "read the code, compare, record" sequence this needs.
 */
final class Throttle {

	/** Seconds a request waits for a lock held by a parallel one. */
	private const LOCK_WAIT = 5;

	private const PREFIX = 'galaxie_thr_';

	/**
	 * Runs `$fn` holding the named lock for `$name`.
	 *
	 * @throws \RuntimeException When the lock cannot be had (busy, or the database refuses).
	 * @return mixed What `$fn` returns.
	 */
	public static function locked( string $name, callable $fn ) {
		global $wpdb;

		// Named locks are server-wide: the site's database and table prefix keep
		// two sites on one MySQL server from waiting on each other.
		$lock = 'gx_' . md5( ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . $wpdb->prefix . '|' . $name );
		$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_WAIT ) );

		if ( '1' !== (string) $got ) {
			throw new \RuntimeException( 'galaxie_lock_unavailable' );
		}

		try {
			return $fn();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Counts one request against "`$max` per `$window` seconds" for `$bucket`.
	 * Over the limit the request is refused and not counted, so the bucket
	 * opens again as its oldest request leaves the window.
	 *
	 * @throws \RuntimeException When the lock cannot be had.
	 * @return bool True when the request is within the limit (and was counted).
	 */
	public static function hit( string $bucket, int $max, int $window ): bool {
		$key = self::PREFIX . md5( $bucket );

		return (bool) self::locked(
			$key,
			static function () use ( $key, $max, $window ): bool {
				$now  = time();
				$hits = self::since( (array) ( self::read( $key )['t'] ?? array() ), $now - $window );

				if ( count( $hits ) >= $max ) {
					return false;
				}

				$hits[] = $now;
				self::write( $key, array( 't' => array_slice( $hits, -$max ) ), $window );

				return true;
			}
		);
	}

	/** Forgets `$bucket`'s count (a successful sign-in clears its own e-mail's). */
	public static function reset( string $bucket ): void {
		$key = self::PREFIX . md5( $bucket );
		self::locked( $key, static fn() => delete_transient( $key ) );
	}

	/**
	 * A stored array, read fresh. Call only while holding the lock for `$key`.
	 *
	 * @return array<string,mixed>
	 */
	public static function read( string $key ): array {
		self::forget_request_cache( $key );
		$value = get_transient( $key );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * Stores (or, when empty, deletes) an array. Call only while holding the lock for `$key`.
	 *
	 * @param array<string,mixed> $value
	 */
	public static function write( string $key, array $value, int $ttl ): void {
		if ( empty( $value ) ) {
			delete_transient( $key );
			return;
		}
		set_transient( $key, $value, max( 1, $ttl ) );
	}

	/**
	 * The network a request comes from, for per-network limits: REMOTE_ADDR,
	 * which LiteSpeed on Hostinger already sets to the visitor's address.
	 * X-Forwarded-For is not read — anyone can send it. An IPv6 address counts
	 * by its /64, the block one connection is handed, or a single visitor
	 * could rotate addresses past every limit.
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';

		/**
		 * The visitor's address for the sign-in limits. Only for a site behind
		 * a proxy that does not already put it in REMOTE_ADDR.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'galaxie_woo/auth_client_ip', $ip );

		$packed = false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? inet_pton( $ip ) : false;
		if ( false === $packed ) {
			return 'unknown';
		}

		if ( 16 === strlen( $packed ) ) {
			// An IPv4 address written as IPv6 (::ffff:a.b.c.d) is the IPv4 one.
			if ( str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 ) ) {
				return (string) inet_ntop( substr( $packed, 12 ) );
			}
			return 'v6:' . bin2hex( substr( $packed, 0, 8 ) );
		}

		return (string) inet_ntop( $packed );
	}

	/**
	 * The timestamps after `$after`.
	 *
	 * @param array<int,mixed> $stamps
	 * @return int[]
	 */
	private static function since( array $stamps, int $after ): array {
		return array_values( array_filter( array_map( 'intval', $stamps ), static fn( int $t ): bool => $t > $after ) );
	}

	/**
	 * Without a persistent object cache, a transient is an option, and
	 * WordPress keeps each option it read (or found missing) for the rest of
	 * the request. Dropping those copies makes the next read go to the
	 * database, where the request holding the lock before this one wrote.
	 */
	private static function forget_request_cache( string $key ): void {
		if ( wp_using_ext_object_cache() ) {
			return;
		}

		$names = array( '_transient_' . $key, '_transient_timeout_' . $key );
		foreach ( $names as $name ) {
			wp_cache_delete( $name, 'options' );
		}

		$missing = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $missing ) && array_intersect_key( $missing, array_flip( $names ) ) ) {
			wp_cache_set( 'notoptions', array_diff_key( $missing, array_flip( $names ) ), 'options' );
		}
	}
}
