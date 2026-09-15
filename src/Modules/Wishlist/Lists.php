<?php
/**
 * A customer's wishlists: several, named, shareable.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\Wishlist;

defined( 'ABSPATH' ) || exit;

/**
 * Every list lives in one user meta row, as a plain array, so reading a
 * customer's lists is one query and writing them is one update:
 *
 *     id      short random id, stable for the life of the list
 *     name    what the customer called it
 *     items   product ids, newest last
 *     default the list the heart saves to; exactly one list has it
 *     gifts   whether people with the link may send its products as gifts
 *     share   the secret in the public link, '' while the list is private
 *     created unix time
 *
 * The single list the module kept before (`_galaxie_wishlist`) becomes the
 * default list, "Favoritos", the first time these lists are read, and the old
 * row is removed. A public link is looked up through a meta row of its own,
 * keyed by the secret, so opening a shared list never scans every customer.
 *
 * Lists belong to accounts only: saving anything asks the visitor to sign in
 * first, so there is no guest copy to merge.
 */
final class Lists {

	private const META_KEY    = '_galaxie_wishlists';
	private const LEGACY_KEY  = '_galaxie_wishlist';
	private const SHARE_KEY   = '_galaxie_wishlist_share_';
	private const MAX_LISTS   = 50;
	private const MAX_ITEMS   = 500;
	private const NAME_LENGTH = 60;

	/** @return array<int,array<string,mixed>> Every list of the user, the default first. */
	public static function all( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$lists = self::normalize_lists( get_user_meta( $user_id, self::META_KEY, true ) );
		$old   = get_user_meta( $user_id, self::LEGACY_KEY, true );

		if ( ! $lists || '' !== $old ) {
			$legacy = self::normalize_items( $old );

			if ( ! $lists ) {
				$lists = array( self::blank( __( 'Favoritos', 'galaxie-woo' ), true ) );
			}

			if ( $legacy ) {
				$default                     = self::default_index( $lists );
				$lists[ $default ]['items'] = self::normalize_items( array_merge( $lists[ $default ]['items'], $legacy ) );
			}

			self::write( $user_id, $lists );
			delete_user_meta( $user_id, self::LEGACY_KEY );
		}

		usort( $lists, static fn( array $a, array $b ): int => (int) $b['default'] <=> (int) $a['default'] );

		return $lists;
	}

	/** @return array<string,mixed>|null */
	public static function get( int $user_id, string $list_id ): ?array {
		foreach ( self::all( $user_id ) as $list ) {
			if ( $list['id'] === $list_id ) {
				return $list;
			}
		}

		return null;
	}

	/** @return array<string,mixed> The list the heart saves to. */
	public static function default_list( int $user_id ): array {
		$lists = self::all( $user_id );

		return $lists[ self::default_index( $lists ) ] ?? self::blank( __( 'Favoritos', 'galaxie-woo' ), true );
	}

	/** Whether the product is on any of the user's lists. */
	public static function contains( int $user_id, int $product_id, string $list_id = '' ): bool {
		foreach ( self::all( $user_id ) as $list ) {
			if ( ( '' === $list_id || $list['id'] === $list_id ) && in_array( $product_id, $list['items'], true ) ) {
				return true;
			}
		}

		return false;
	}

	/** @return array<string,mixed>|\WP_Error The new list. */
	public static function create( int $user_id, string $name, array $items = array() ) {
		$lists = self::all( $user_id );
		$name  = self::clean_name( $name );

		if ( '' === $name ) {
			return new \WP_Error( 'name', __( 'Dê um nome para a lista.', 'galaxie-woo' ) );
		}

		if ( count( $lists ) >= self::MAX_LISTS ) {
			return new \WP_Error( 'limit', __( 'Você chegou ao limite de listas.', 'galaxie-woo' ) );
		}

		$list          = self::blank( $name, ! $lists );
		$list['items'] = self::normalize_items( $items );
		$lists[]       = $list;

		self::write( $user_id, $lists );

		return $list;
	}

	/** @return true|\WP_Error */
	public static function rename( int $user_id, string $list_id, string $name ) {
		$name = self::clean_name( $name );

		if ( '' === $name ) {
			return new \WP_Error( 'name', __( 'Dê um nome para a lista.', 'galaxie-woo' ) );
		}

		return self::change( $user_id, $list_id, static function ( array $list ) use ( $name ): array {
			$list['name'] = $name;
			return $list;
		} );
	}

	/**
	 * Removes a list. The default passes to the next one, and removing the last
	 * list leaves a fresh, empty "Favoritos" — there is always somewhere for the
	 * heart to save to.
	 *
	 * @return true|\WP_Error
	 */
	public static function delete( int $user_id, string $list_id ) {
		$lists = self::all( $user_id );
		$kept  = array();
		$gone  = null;

		foreach ( $lists as $list ) {
			if ( $list['id'] === $list_id ) {
				$gone = $list;
				continue;
			}

			$kept[] = $list;
		}

		if ( ! $gone ) {
			return new \WP_Error( 'missing', __( 'Lista não encontrada.', 'galaxie-woo' ) );
		}

		if ( '' !== $gone['share'] ) {
			delete_user_meta( $user_id, self::SHARE_KEY . $gone['share'] );
		}

		if ( ! $kept ) {
			$kept = array( self::blank( __( 'Favoritos', 'galaxie-woo' ), true ) );
		} elseif ( $gone['default'] ) {
			$kept[0]['default'] = true;
		}

		self::write( $user_id, $kept );

		return true;
	}

	/** @return true|\WP_Error */
	public static function set_default( int $user_id, string $list_id ) {
		$lists = self::all( $user_id );

		if ( null === self::find_index( $lists, $list_id ) ) {
			return new \WP_Error( 'missing', __( 'Lista não encontrada.', 'galaxie-woo' ) );
		}

		foreach ( $lists as $i => $list ) {
			$lists[ $i ]['default'] = $list['id'] === $list_id;
		}

		self::write( $user_id, $lists );

		return true;
	}

	/**
	 * Puts a product on a list, or takes it off.
	 *
	 * @return bool|\WP_Error Whether the product is on the list afterwards.
	 */
	public static function set_item( int $user_id, string $list_id, int $product_id, bool $on ) {
		$result = null;

		$changed = self::change( $user_id, $list_id, static function ( array $list ) use ( $product_id, $on, &$result ) {
			$items = array_values( array_diff( $list['items'], array( $product_id ) ) );

			if ( $on ) {
				if ( count( $items ) >= self::MAX_ITEMS ) {
					return new \WP_Error( 'limit', __( 'Essa lista está cheia.', 'galaxie-woo' ) );
				}

				$items[] = $product_id;
			}

			$list['items'] = $items;
			$result        = $on;

			return $list;
		} );

		return is_wp_error( $changed ) ? $changed : (bool) $result;
	}

	/** @return bool|\WP_Error Whether the product is on the default list afterwards. */
	public static function toggle_default( int $user_id, int $product_id ) {
		$list = self::default_list( $user_id );

		return self::set_item( $user_id, (string) $list['id'], $product_id, ! in_array( $product_id, $list['items'], true ) );
	}

	/**
	 * Turns a list's public link on or off, or gives it a new secret — which is
	 * how an owner takes a link back from people it reached.
	 *
	 * @return array<string,mixed>|\WP_Error The list afterwards.
	 */
	public static function set_sharing( int $user_id, string $list_id, bool $shared, bool $renew = false ) {
		$updated = null;

		$changed = self::change( $user_id, $list_id, static function ( array $list ) use ( $user_id, $shared, $renew, &$updated ): array {
			if ( '' !== $list['share'] && ( ! $shared || $renew ) ) {
				delete_user_meta( $user_id, self::SHARE_KEY . $list['share'] );
				$list['share'] = '';
			}

			if ( $shared && '' === $list['share'] ) {
				$list['share'] = strtolower( wp_generate_password( 20, false, false ) );
				update_user_meta( $user_id, self::SHARE_KEY . $list['share'], $list['id'] );
			}

			$updated = $list;

			return $list;
		} );

		return is_wp_error( $changed ) ? $changed : (array) $updated;
	}

	/** @return true|\WP_Error */
	public static function set_gifts( int $user_id, string $list_id, bool $gifts ) {
		return self::change( $user_id, $list_id, static function ( array $list ) use ( $gifts ): array {
			$list['gifts'] = $gifts;
			return $list;
		} );
	}

	/**
	 * The shared list behind a public link, and whose it is.
	 *
	 * @return array{user_id:int,list:array<string,mixed>}|null
	 */
	public static function find_shared( string $token ): ?array {
		$token = strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', $token ) ?? '' );

		if ( strlen( $token ) < 12 ) {
			return null;
		}

		$users = get_users(
			array(
				'meta_key' => self::SHARE_KEY . $token, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one exact key, indexed.
				'number'   => 1,
				'fields'   => 'ID',
			)
		);

		$user_id = (int) ( $users[0] ?? 0 );

		if ( ! $user_id ) {
			return null;
		}

		$list = self::get( $user_id, (string) get_user_meta( $user_id, self::SHARE_KEY . $token, true ) );

		return $list && $list['share'] === $token ? array( 'user_id' => $user_id, 'list' => $list ) : null;
	}

	/** The address a shared list's link opens. */
	public static function share_url( array $list ): string {
		return '' !== (string) ( $list['share'] ?? '' ) ? home_url( user_trailingslashit( 'lista/' . $list['share'] ) ) : '';
	}

	/**
	 * @param callable(array<string,mixed>):(array<string,mixed>|\WP_Error) $edit
	 * @return true|\WP_Error
	 */
	private static function change( int $user_id, string $list_id, callable $edit ) {
		$lists = self::all( $user_id );
		$index = self::find_index( $lists, $list_id );

		if ( null === $index ) {
			return new \WP_Error( 'missing', __( 'Lista não encontrada.', 'galaxie-woo' ) );
		}

		$list = $edit( $lists[ $index ] );

		if ( is_wp_error( $list ) ) {
			return $list;
		}

		$lists[ $index ] = $list;
		self::write( $user_id, $lists );

		return true;
	}

	/** @param array<int,array<string,mixed>> $lists */
	private static function find_index( array $lists, string $list_id ): ?int {
		foreach ( $lists as $i => $list ) {
			if ( $list['id'] === $list_id ) {
				return $i;
			}
		}

		return null;
	}

	/** @param array<int,array<string,mixed>> $lists */
	private static function default_index( array $lists ): int {
		foreach ( $lists as $i => $list ) {
			if ( ! empty( $list['default'] ) ) {
				return $i;
			}
		}

		return 0;
	}

	/** @param array<int,array<string,mixed>> $lists */
	private static function write( int $user_id, array $lists ): void {
		update_user_meta( $user_id, self::META_KEY, array_values( $lists ) );
	}

	/** @return array<string,mixed> */
	private static function blank( string $name, bool $default ): array {
		return array(
			'id'      => strtolower( wp_generate_password( 8, false, false ) ),
			'name'    => $name,
			'items'   => array(),
			'default' => $default,
			'gifts'   => false,
			'share'   => '',
			'created' => time(),
		);
	}

	private static function clean_name( string $name ): string {
		return trim( mb_substr( sanitize_text_field( $name ), 0, self::NAME_LENGTH ) );
	}

	/** @return array<int,array<string,mixed>> */
	private static function normalize_lists( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$lists   = array();
		$default = false;

		foreach ( $raw as $list ) {
			if ( ! is_array( $list ) || empty( $list['id'] ) ) {
				continue;
			}

			$is_default = ! $default && ! empty( $list['default'] );
			$default    = $default || $is_default;

			$lists[] = array(
				'id'      => (string) $list['id'],
				'name'    => (string) ( $list['name'] ?? '' ),
				'items'   => self::normalize_items( $list['items'] ?? array() ),
				'default' => $is_default,
				'gifts'   => ! empty( $list['gifts'] ),
				'share'   => (string) ( $list['share'] ?? '' ),
				'created' => (int) ( $list['created'] ?? 0 ),
			);
		}

		if ( $lists && ! $default ) {
			$lists[0]['default'] = true;
		}

		return $lists;
	}

	/** @return int[] */
	private static function normalize_items( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );
	}
}
