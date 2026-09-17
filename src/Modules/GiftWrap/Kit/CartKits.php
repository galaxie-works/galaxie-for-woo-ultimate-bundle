<?php
/**
 * Kits in and out of the cart.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\GiftWrap\Kit;

use Galaxie\Woo\Modules\GiftWrap\Flag;
use Galaxie\Woo\Modules\GiftWrap\Groups;
use Galaxie\Woo\Support\GiftKit;

defined( 'ABSPATH' ) || exit;

/**
 * A kit in the cart is a closed gift group (#18): its candles, box and card
 * are ordinary lines tied by `galaxie_gift_group`, with the kit's name on each.
 *
 * - add(): the draft's lines, checked by {@see Kits::plan()}, written in one
 *   go; the cart is put back as it was if any add fails, so a kit never lands
 *   half-built.
 * - extract(): "Editar kit" — a kit's lines leave the cart and come back as
 *   the draft, with its name, box, card and message.
 * - merge_login(): the guest's draft wins; the account's older one goes to the
 *   cart when it has a candle, with a notice (decision 15).
 */
final class CartKits {

	public function __construct( private Kits $kits ) {}

	/** @return \WC_Cart|null */
	public static function cart(): ?object {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		if ( null === WC()->cart && function_exists( 'wc_load_cart' ) && did_action( 'woocommerce_init' ) ) {
			wc_load_cart();
		}

		return WC()->cart ?: null;
	}

	/**
	 * Units of each product in the cart, by product (variation) id.
	 *
	 * @return array<string,int>
	 */
	public function in_cart(): array {
		$cart   = self::cart();
		$counts = array();

		foreach ( $cart ? $cart->get_cart_contents() : array() as $item ) {
			if ( ( $item['data'] ?? null ) instanceof \WC_Product ) {
				$id            = (string) $item['data']->get_id();
				$counts[ $id ] = ( $counts[ $id ] ?? 0 ) + (int) $item['quantity'];
			}
		}

		return $counts;
	}

	/**
	 * Titles of the gifts in the cart.
	 *
	 * @return string[]
	 */
	public function kit_names(): array {
		$cart = self::cart();

		if ( ! $cart ) {
			return array();
		}

		$names = array();

		foreach ( Groups::groups( $cart->get_cart_contents() ) as $gift ) {
			if ( '' !== $gift['name'] ) {
				$names[] = $gift['name'];
			}
		}

		return $names;
	}

	/**
	 * Puts a draft in the cart as a closed kit.
	 *
	 * @return string The kit's group id in the cart.
	 */
	public function add( array $draft ): string {
		$cart = self::cart();

		if ( ! $cart ) {
			throw new KitError( 'no_cart', __( 'Carrinho indisponível.', 'galaxie-woo' ) );
		}

		$lines    = $this->kits->plan( $draft, $this->in_cart() );
		$contents = $cart->get_cart_contents();
		$groups   = Groups::groups( $contents );
		$names    = $this->kit_names();
		$name     = $draft['name'];

		// A name the shopper did not type makes way for a kit already called that.
		if ( ! $draft['named'] && in_array( strtolower( $name ), array_map( 'strtolower', $names ), true ) ) {
			$name = GiftKit::default_name( $names, Kits::name_format() );
		}

		$id = $draft['id'];

		while ( isset( $groups[ $id ] ) ) {
			$id = Store::new_id();
		}

		$snapshot = $contents;
		$added    = true;

		foreach ( $lines as $line ) {
			$product = $line['product'];
			$data    = Groups::data( $id, $line['role'], $line['message'], $name );

			if ( Groups::ROLE_CANDLE === $line['role'] ) {
				$data = array( Flag::CART_KEY => array( 'gift' => true ) ) + $data;
			}

			$added = (bool) $cart->add_to_cart(
				(int) $product['parent'],
				(int) $line['qty'],
				$product['variation'] ? (int) $product['id'] : 0,
				(array) $product['attributes'],
				$data
			);

			if ( ! $added ) {
				break;
			}
		}

		if ( ! $added ) {
			$notices = function_exists( 'wc_get_notices' ) ? wc_get_notices( 'error' ) : array();

			if ( function_exists( 'wc_clear_notices' ) ) {
				wc_clear_notices();
			}

			$cart->set_cart_contents( $snapshot );
			$cart->calculate_totals();

			throw new KitError( 'refused', $notices ? wp_strip_all_tags( (string) $notices[0]['notice'] ) : __( 'Não foi possível adicionar o kit ao carrinho.', 'galaxie-woo' ) );
		}

		Groups::tidy( $cart );
		$cart->calculate_totals();

		return $id;
	}

	/**
	 * "Editar kit": the kit leaves the cart and becomes the draft.
	 *
	 * @return array The draft (not saved here).
	 */
	public function extract( string $group ): array {
		$cart = self::cart();

		if ( ! $cart || ! preg_match( '/^[a-z0-9]{1,32}$/', $group ) ) {
			throw new KitError( 'gone', __( 'Esse kit não está mais no carrinho.', 'galaxie-woo' ) );
		}

		$contents = $cart->get_cart_contents();
		$gift     = Groups::groups( $contents )[ $group ] ?? null;

		if ( ! $gift ) {
			throw new KitError( 'gone', __( 'Esse kit não está mais no carrinho.', 'galaxie-woo' ) );
		}

		if ( ! Groups::editable( $gift ) ) {
			throw new KitError( 'not_editable', __( 'Este presente não pode ser editado como kit.', 'galaxie-woo' ) );
		}

		$candles = array();

		foreach ( $gift['candles'] as $item ) {
			$id             = $item['data']->get_id();
			$candles[ $id ] = ( $candles[ $id ] ?? 0 ) + (int) $item['quantity'];
		}

		$box     = reset( $gift['box'] );
		$card    = $gift['cards'] ? reset( $gift['cards'] ) : null;
		$message = '';
		$parent  = 0;

		if ( $card ) {
			$product = $card['data'];
			$parent  = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
			$message = Groups::group_of( $card )['message'] ?? '';
		}

		$others = array_values( array_diff( $this->kit_names(), array( $gift['name'] ) ) );
		$name   = '' !== $gift['name'] ? $gift['name'] : GiftKit::default_name( $others, Kits::name_format() );
		$typed  = '' !== $gift['name'] && ! self::is_default_name( $gift['name'] );

		$draft = GiftKit::normalize(
			array(
				'id'      => $group,
				'name'    => $name,
				'named'   => $typed,
				'box'     => $box['data']->get_id(),
				'card'    => $parent,
				'message' => $message,
				'candles' => array_map(
					static fn( int $id, int $qty ): array => array(
						'id'  => $id,
						'qty' => $qty,
					),
					array_keys( $candles ),
					array_values( $candles )
				),
				'updated' => time(),
			)
		);

		// Straight out of the contents, like Groups::tidy(): no removal hooks,
		// no "undo" that would bring back half a kit.
		foreach ( array_keys( $contents ) as $key ) {
			$line = Groups::group_of( $contents[ $key ] );

			if ( $line && $line['id'] === $group ) {
				unset( $contents[ $key ] );
			}
		}

		$cart->set_cart_contents( $contents );
		$cart->calculate_totals();

		return (array) $draft;
	}

	/**
	 * The first request of a signed-in shopper whose session still holds a
	 * guest draft: that draft becomes the account's. An older account draft
	 * with candles goes to the cart (a notice says so); an empty one is dropped.
	 */
	public function merge_login(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$guest = Store::session_draft();

		if ( ! $guest || 0 !== $guest['owner'] ) {
			return;
		}

		$old = Store::account_draft( (int) get_current_user_id() );

		if ( $old && $old['id'] !== $guest['id'] && $old['candles'] ) {
			try {
				$this->add( $old );
				Store::notice(
					/* translators: %s: kit name. */
					sprintf( __( 'Encontramos o kit %s que você começou antes e colocamos no carrinho. Você pode editar ou remover.', 'galaxie-woo' ), $old['name'] )
				);
			} catch ( KitError $e ) {
				Store::notice(
					/* translators: 1: kit name, 2: why. */
					sprintf( __( 'Encontramos o kit %1$s que você começou antes, mas não foi possível colocá-lo no carrinho: %2$s', 'galaxie-woo' ), $old['name'], $e->getMessage() )
				);
			}
		}

		Store::put( $guest );
	}

	/** Whether a name is one the store gave ("Kit 3"), not one typed. */
	private static function is_default_name( string $name ): bool {
		$pattern = '/^' . str_replace( '%d', '\d+', preg_quote( Kits::name_format(), '/' ) ) . '$/u';

		return 1 === preg_match( $pattern, $name );
	}
}
