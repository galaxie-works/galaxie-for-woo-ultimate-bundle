<?php
/**
 * The kit being built: every change to it, checked.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\GiftWrap\Kit;

use Galaxie\Woo\Support\GiftGroups;
use Galaxie\Woo\Support\GiftKit;
use Galaxie\Woo\Support\GiftPacking;

defined( 'ABSPATH' ) || exit;

/**
 * A draft kit is a plain array ({@see GiftKit::normalize()}): id, owner, name
 * (+ whether the shopper typed it), box (variation id), card (card PRODUCT id —
 * the variation follows the box), message, candles as [ { id, qty } ].
 *
 * Every method takes a draft and returns the changed one, or throws
 * {@see KitError} with the shopper's sentence; nothing here stores anything
 * ({@see Store}) or touches the cart ({@see CartKits}). What the store holds
 * comes from a {@see Catalog}, so the same rules run on the site and in
 * tests/kit/run.php.
 *
 * Rules (kit-flow-scope.md):
 * - a box is mandatory before any candle, and every candle must fit it, by
 *   the packing engine with the store's options;
 * - a box change is only to a box that still holds the candles; the card
 *   follows the box's size, and a card with no size for it refuses the change;
 * - 0 or 1 card, its message within the store's limit;
 * - stock is counted with what the cart already holds;
 * - the name is optional to type and always set ("Kit N").
 */
final class Kits {

	public function __construct( private Catalog $catalog ) {}

	public function catalog(): Catalog {
		return $this->catalog;
	}

	// ------------------------------------------------------------- changes

	/**
	 * A new draft, from the stepper: name, box (required), card, message, and
	 * the candle the product page started it with.
	 *
	 * @param array{name?:string, box?:int, card?:int, message?:string, candle?:int, qty?:int} $input
	 * @param string[]          $used    Names of the kits in the cart.
	 * @param array<string,int> $in_cart Units of each product already in the cart.
	 */
	public function start( string $id, int $owner, array $input, array $used, array $in_cart ): array {
		$draft = array(
			'id'      => $id,
			'owner'   => $owner,
			'name'    => '',
			'named'   => false,
			'box'     => 0,
			'card'    => 0,
			'message' => '',
			'candles' => array(),
			'updated' => time(),
		);

		$draft = $this->rename( $draft, (string) ( $input['name'] ?? '' ), $used );

		if ( empty( $input['box'] ) ) {
			throw new KitError( 'no_box', __( 'Escolha uma caixa para o kit.', 'galaxie-woo' ) );
		}

		$draft = $this->set_box( $draft, (int) $input['box'] );
		$draft = $this->set_card( $draft, (int) ( $input['card'] ?? 0 ) );
		$draft = $this->set_message( $draft, (string) ( $input['message'] ?? '' ) );

		if ( ! empty( $input['candle'] ) ) {
			$draft = $this->add_candle( $draft, (int) $input['candle'], (int) ( $input['qty'] ?? 1 ), $in_cart );
		}

		return $draft;
	}

	/**
	 * @param string[] $used Names of the kits in the cart.
	 */
	public function rename( array $draft, string $name, array $used ): array {
		$name = GiftKit::clean_name( $name );

		$draft['named'] = '' !== $name;
		$draft['name']  = '' !== $name ? $name : GiftKit::default_name( $used, self::name_format() );

		return self::touch( $draft );
	}

	public function set_box( array $draft, int $box ): array {
		$boxes = $this->catalog->boxes();
		$row   = $boxes[ $box ] ?? null;

		if ( ! $row ) {
			throw new KitError( 'box_gone', __( 'Essa caixa não está mais disponível.', 'galaxie-woo' ) );
		}

		if ( 0 === $row['stock'] && $box !== (int) $draft['box'] ) {
			throw new KitError( 'box_sold_out', __( 'Essa caixa está esgotada.', 'galaxie-woo' ) );
		}

		$units = $this->units( $draft );

		if ( $units && ! GiftPacking::fits( self::shape( $row ), $units, $this->catalog->options() ) ) {
			throw new KitError( 'box_too_small', __( 'Essa caixa não comporta as velas deste kit.', 'galaxie-woo' ) );
		}

		if ( $draft['card'] && ! $this->catalog->card_for( (int) $draft['card'], $box ) ) {
			throw new KitError( 'card_size', __( 'O cartão deste kit não existe para essa caixa.', 'galaxie-woo' ) );
		}

		$draft['box'] = $box;

		return self::touch( $draft );
	}

	/** @param int $parent Card product id; 0 for no card. */
	public function set_card( array $draft, int $parent ): array {
		if ( $parent < 1 ) {
			$draft['card'] = 0;
			return self::touch( $draft );
		}

		$known = false;

		foreach ( $this->catalog->cards() as $card ) {
			if ( (int) $card['parent'] === $parent ) {
				$known = true;
				break;
			}
		}

		if ( ! $known ) {
			throw new KitError( 'card_gone', __( 'Esse cartão não está mais disponível.', 'galaxie-woo' ) );
		}

		if ( ! $this->catalog->card_for( $parent, (int) $draft['box'] ) ) {
			throw new KitError( 'card_size', __( 'Esse cartão não existe para a caixa deste kit.', 'galaxie-woo' ) );
		}

		$draft['card'] = $parent;

		return self::touch( $draft );
	}

	public function set_message( array $draft, string $message ): array {
		$message = GiftGroups::clean_message( $message );
		$max     = $this->catalog->message_max();

		if ( $max > 0 && GiftGroups::message_length( $message ) > $max ) {
			/* translators: %d: character limit. */
			throw new KitError( 'message_too_long', sprintf( __( 'A mensagem passa de %d caracteres.', 'galaxie-woo' ), $max ), array( 'max' => $max ) );
		}

		$draft['message'] = $message;

		return self::touch( $draft );
	}

	/**
	 * @param array<string,int> $in_cart Units of each product already in the cart.
	 */
	public function add_candle( array $draft, int $id, int $qty, array $in_cart ): array {
		if ( $qty < 1 || $qty > GiftPacking::MAX_ITEMS ) {
			throw new KitError( 'bad_quantity', __( 'Quantidade inválida.', 'galaxie-woo' ) );
		}

		$candle = $this->candle( $id );
		$cap    = $this->room_for( $draft, $candle );

		if ( $qty > $cap ) {
			throw self::no_room( $cap );
		}

		$current = 0;

		foreach ( $draft['candles'] as $line ) {
			if ( $line['id'] === $id ) {
				$current = $line['qty'];
			}
		}

		$this->check_stock( $candle, $current + $qty, $in_cart );

		if ( $current ) {
			foreach ( $draft['candles'] as $i => $line ) {
				if ( $line['id'] === $id ) {
					$draft['candles'][ $i ]['qty'] += $qty;
				}
			}
		} else {
			$draft['candles'][] = array(
				'id'  => $id,
				'qty' => $qty,
			);
		}

		return self::touch( $draft );
	}

	/**
	 * A candle line set to a quantity; 0 removes it.
	 *
	 * @param array<string,int> $in_cart Units of each product already in the cart.
	 */
	public function update_candle( array $draft, int $id, int $qty, array $in_cart ): array {
		if ( 0 === $qty ) {
			return $this->remove_candle( $draft, $id );
		}

		if ( $qty < 0 || $qty > GiftPacking::MAX_ITEMS ) {
			throw new KitError( 'bad_quantity', __( 'Quantidade inválida.', 'galaxie-woo' ) );
		}

		$index = null;

		foreach ( $draft['candles'] as $i => $line ) {
			if ( $line['id'] === $id ) {
				$index = $i;
			}
		}

		if ( null === $index ) {
			throw new KitError( 'not_in_kit', __( 'Essa vela não está no kit.', 'galaxie-woo' ) );
		}

		$candle = $this->candle( $id );
		$others = $draft;
		unset( $others['candles'][ $index ] );
		$cap = $this->room_for( $others, $candle );

		if ( $qty > $cap ) {
			throw self::no_room( $cap );
		}

		$this->check_stock( $candle, $qty, $in_cart );

		$draft['candles'][ $index ]['qty'] = $qty;

		return self::touch( $draft );
	}

	public function remove_candle( array $draft, int $id ): array {
		$draft['candles'] = array_values( array_filter( $draft['candles'], static fn( array $line ): bool => $line['id'] !== $id ) );

		return self::touch( $draft );
	}

	// ------------------------------------------------------------- reading

	/**
	 * How many more of this candle the kit's box takes (0 with no box).
	 *
	 * @param array $candle A catalog candle row.
	 */
	public function room_for( array $draft, array $candle ): int {
		$box = $this->catalog->boxes()[ (int) $draft['box'] ] ?? null;

		if ( ! $box ) {
			return 0;
		}

		$units = $this->units( $draft );

		if ( count( $units ) >= GiftPacking::MAX_ITEMS ) {
			return 0;
		}

		return GiftGroups::max_quantity( self::shape( $box ), $units, $candle['candle'], 0, $this->catalog->options() );
	}

	/**
	 * What still fits, in words: { state: full|one|many, combos }.
	 *
	 * @return array{state:string, combos:string}
	 */
	public function room( array $draft ): array {
		$box = $this->catalog->boxes()[ (int) $draft['box'] ] ?? null;

		if ( ! $box ) {
			return array(
				'state'  => 'none',
				'combos' => '',
			);
		}

		$sizes = $this->catalog->sizes();

		return GiftKit::wording(
			GiftKit::combos( self::shape( $box ), $this->units( $draft ), $sizes, $this->catalog->options() ),
			self::labels( $sizes )
		);
	}

	/**
	 * The lines a draft puts in the cart, every rule checked again: candles
	 * still sold, the box on offer and holding them, the card for this box,
	 * the message within its limit, stock with what the cart holds, and the
	 * store's own add-to-cart validation for each.
	 *
	 * @param array<string,int> $in_cart Units of each product already in the cart.
	 * @return array<int, array{role:string, product:array, qty:int, message:string}>
	 */
	public function plan( array $draft, array $in_cart ): array {
		if ( ! $draft['candles'] ) {
			throw new KitError( 'empty', __( 'Adicione pelo menos uma vela ao kit.', 'galaxie-woo' ) );
		}

		$box = $this->catalog->boxes()[ (int) $draft['box'] ] ?? null;

		if ( ! $box ) {
			throw new KitError( 'no_box', __( 'Escolha uma caixa para o kit.', 'galaxie-woo' ) );
		}

		$lines  = array();
		$units  = array();
		$stock  = array( (string) $box['id'] => $box['stock'] );
		$names  = array( (string) $box['id'] => $box['name'] );

		foreach ( $draft['candles'] as $line ) {
			$candle = $this->catalog->candle( (int) $line['id'] );

			if ( ! $candle ) {
				throw new KitError( 'candle_gone', __( 'Uma vela do kit não está mais disponível. Remova-a do kit.', 'galaxie-woo' ) );
			}

			for ( $i = 0; $i < $line['qty']; $i++ ) {
				$units[] = $candle['candle'] + array( 'id' => $candle['id'] );
			}

			$stock[ (string) $candle['id'] ] = $candle['stock'];
			$names[ (string) $candle['id'] ] = $candle['name'];
			$lines[]                         = array(
				'role'    => 'candle',
				'product' => $candle,
				'qty'     => (int) $line['qty'],
				'message' => '',
			);
		}

		if ( count( $units ) > GiftPacking::MAX_ITEMS ) {
			throw new KitError( 'box_too_small', __( 'As velas deste kit não cabem na caixa escolhida.', 'galaxie-woo' ) );
		}

		$lines[] = array(
			'role'    => 'box',
			'product' => $box,
			'qty'     => 1,
			'message' => '',
		);

		$items = array();

		if ( $draft['card'] ) {
			$id   = $this->catalog->card_for( (int) $draft['card'], (int) $box['id'] );
			$card = $id ? ( $this->catalog->cards()[ $id ] ?? null ) : null;

			if ( ! $card ) {
				throw new KitError( 'card_size', __( 'O cartão do kit não existe para esta caixa. Troque o cartão.', 'galaxie-woo' ) );
			}

			$stock[ (string) $card['id'] ] = $card['stock'];
			$names[ (string) $card['id'] ] = $card['name'];
			$items[]                       = array(
				'id'       => $card['id'],
				'kind'     => 'card',
				'quantity' => 1,
				'message'  => $draft['message'],
			);
			$lines[]                       = array(
				'role'    => 'card',
				'product' => $card,
				'qty'     => 1,
				'message' => $draft['message'],
			);
		}

		$errors = GiftGroups::validate(
			array(
				'groups'      => array(
					array(
						'candles' => $units,
						'box'     => self::shape( $box ) + array( 'id' => $box['id'] ),
						'items'   => $items,
					),
				),
				'stock'       => $stock,
				'in_cart'     => $in_cart,
				'message_max' => $this->catalog->message_max(),
			),
			$this->catalog->options()
		);

		if ( $errors ) {
			$error = $errors[0];

			switch ( $error['code'] ) {
				case 'box_too_small':
					throw new KitError( 'box_too_small', __( 'As velas deste kit não cabem na caixa escolhida.', 'galaxie-woo' ) );
				case 'message_too_long':
					/* translators: %d: character limit. */
					throw new KitError( 'message_too_long', sprintf( __( 'A mensagem passa de %d caracteres.', 'galaxie-woo' ), $this->catalog->message_max() ) );
				case 'out_of_stock':
					/* translators: %s: product name. */
					throw new KitError( 'out_of_stock', sprintf( __( 'Não há estoque suficiente de %s.', 'galaxie-woo' ), $names[ (string) $error['id'] ] ?? '' ) );
				default:
					throw new KitError( 'invalid', __( 'Não foi possível adicionar o kit ao carrinho.', 'galaxie-woo' ) );
			}
		}

		foreach ( $lines as $line ) {
			$reason = $this->catalog->can_add( $line['product'], $line['qty'] );

			if ( '' !== $reason ) {
				throw new KitError( 'refused', $reason );
			}
		}

		return $lines;
	}

	/**
	 * The draft as the popup, the badge and the widgets read it. Never printed
	 * into a page: only the uncached kit endpoints send it.
	 *
	 * @param array<string,int> $in_cart Units of each product already in the cart.
	 * @return array<string,mixed>
	 */
	public function view( array $draft, array $in_cart = array() ): array {
		$boxes   = $this->catalog->boxes();
		$box     = $boxes[ (int) $draft['box'] ] ?? null;
		$sizes   = $this->catalog->sizes();
		$labels  = self::labels( $sizes );
		$total   = 0.0;
		$count   = 0;
		$candles = array();

		foreach ( $draft['candles'] as $line ) {
			$candle = $this->catalog->candle( (int) $line['id'] );
			$count += (int) $line['qty'];

			if ( ! $candle ) {
				$candles[] = array(
					'id'      => (int) $line['id'],
					'name'    => __( 'Produto indisponível', 'galaxie-woo' ),
					'image'   => '',
					'price'   => 0.0,
					'qty'     => (int) $line['qty'],
					'size'    => '',
					'label'   => '',
					'candle'  => null,
					'missing' => true,
					'cap'     => 0,
				);
				continue;
			}

			$size   = (string) $candle['candle']['size'];
			$others = $draft;
			$others['candles'] = array_values( array_filter( $draft['candles'], static fn( array $l ): bool => $l['id'] !== $line['id'] ) );
			$total += $candle['price'] * $line['qty'];

			$candles[] = array(
				'id'      => $candle['id'],
				'name'    => $candle['name'],
				'image'   => $candle['image'],
				'price'   => $candle['price'],
				'qty'     => (int) $line['qty'],
				'size'    => $size,
				'label'   => $labels[ $size ] ?? $size,
				'candle'  => $candle['candle'],
				'missing' => false,
				// The most this line may hold, for the popup's + button.
				'cap'     => $box ? min( $this->room_for( $others, $candle ), self::stock_left( $candle, $in_cart ) ) : (int) $line['qty'],
			);
		}

		$card     = null;
		$card_row = null;

		if ( $draft['card'] && $box ) {
			$id       = $this->catalog->card_for( (int) $draft['card'], (int) $box['id'] );
			$card_row = $id ? ( $this->catalog->cards()[ $id ] ?? null ) : null;
		}

		if ( $card_row ) {
			$total += $card_row['price'];
			$card   = array(
				'parent' => (int) $card_row['parent'],
				'id'     => (int) $card_row['id'],
				'name'   => $card_row['name'],
				'title'  => $card_row['title'],
				'image'  => $card_row['image'],
				'price'  => $card_row['price'],
			);
		}

		$room = $this->room( $draft );
		$fill = 0;

		if ( $box ) {
			$total += $box['price'];
			$fill   = GiftGroups::fill( self::shape( $box ), $this->units( $draft ), $sizes, $this->catalog->options() );
		}

		$warnings = array();

		if ( $card && '' === $draft['message'] ) {
			$warnings[] = 'card_without_message';
		}

		if ( $draft['card'] && ! $card ) {
			$warnings[] = 'card_missing';
		}

		return array(
			'id'         => $draft['id'],
			'name'       => $draft['name'],
			'named'      => (bool) $draft['named'],
			'box'        => $box ? self::box_json( $box ) + array( 'priceText' => $this->catalog->money( (float) $box['price'] ) ) : null,
			'card'       => $card,
			'cardParent' => (int) $draft['card'],
			'message'    => $draft['message'],
			'messageMax' => $this->catalog->message_max(),
			'candles'    => $candles,
			'count'      => $count,
			'full'       => 'full' === $room['state'],
			'room'       => $room,
			'fill'       => $fill,
			'total'      => round( $total, 2 ),
			'totalText'  => $this->catalog->money( $total ),
			'warnings'   => $warnings,
			'options'    => $this->catalog->options(),
			'sizes'      => array_values(
				array_map(
					static fn( array $size ): array => array(
						'size'   => (string) $size['size'],
						'label'  => (string) ( $size['label'] ?? $size['size'] ),
						'length' => (float) $size['length'],
						'width'  => (float) $size['width'],
						'height' => (float) $size['height'],
					),
					$sizes
				)
			),
		);
	}

	/**
	 * A box as the popup lists it.
	 *
	 * @return array<string,mixed>
	 */
	public static function box_json( array $box ): array {
		return array(
			'id'          => (int) $box['id'],
			'parent'      => (int) $box['parent'],
			'name'        => $box['name'],
			'title'       => $box['title'],
			'image'       => $box['image'],
			'price'       => $box['price'],
			'stock'       => $box['stock'],
			'description' => (string) ( $box['description'] ?? '' ),
			'attrs'       => (object) ( $box['attrs'] ?? array() ),
			'shape'       => self::shape( $box ),
		);
	}

	/** The candles of a draft, one per unit, those still sold. */
	public function units( array $draft ): array {
		$shapes = array();

		foreach ( $draft['candles'] as $line ) {
			$candle = $this->catalog->candle( (int) $line['id'] );

			if ( $candle ) {
				$shapes[ (int) $line['id'] ] = $candle['candle'];
			}
		}

		return GiftKit::units( $draft['candles'], $shapes );
	}

	/** "Kit %d", translatable. */
	public static function name_format(): string {
		/* translators: %d: kit number. Keep %d. */
		return __( 'Kit %d', 'galaxie-woo' );
	}

	// ----------------------------------------------------------- internals

	private function candle( int $id ): array {
		$candle = $this->catalog->candle( $id );

		if ( ! $candle ) {
			throw new KitError( 'not_candle', __( 'Este produto não pode entrar num kit.', 'galaxie-woo' ) );
		}

		return $candle;
	}

	/**
	 * @param array<string,int> $in_cart
	 */
	private function check_stock( array $candle, int $wanted, array $in_cart ): void {
		if ( $wanted > self::stock_left( $candle, $in_cart ) ) {
			/* translators: %s: product name. */
			throw new KitError( 'out_of_stock', sprintf( __( 'Não há estoque suficiente de %s.', 'galaxie-woo' ), $candle['name'] ), array( 'cap' => max( 0, self::stock_left( $candle, $in_cart ) ) ) );
		}
	}

	/**
	 * @param array<string,int> $in_cart
	 */
	private static function stock_left( array $candle, array $in_cart ): int {
		if ( null === $candle['stock'] ) {
			return GiftPacking::MAX_ITEMS;
		}

		return max( 0, (int) $candle['stock'] - (int) ( $in_cart[ (string) $candle['id'] ] ?? 0 ) );
	}

	private static function no_room( int $cap ): KitError {
		if ( $cap < 1 ) {
			return new KitError( 'no_room', __( 'Não cabe na caixa deste kit.', 'galaxie-woo' ), array( 'cap' => 0 ) );
		}

		return new KitError(
			'no_room',
			/* translators: %d: how many more fit. */
			sprintf( _n( 'Só cabe mais %d desta vela na caixa deste kit.', 'Só cabem mais %d desta vela na caixa deste kit.', $cap, 'galaxie-woo' ), $cap ),
			array( 'cap' => $cap )
		);
	}

	private static function shape( array $box ): array {
		return (array) $box['shape'];
	}

	/** @return array<string,string> */
	private static function labels( array $sizes ): array {
		$out = array();

		foreach ( $sizes as $size ) {
			$out[ (string) $size['size'] ] = (string) ( $size['label'] ?? $size['size'] );
		}

		return $out;
	}

	private static function touch( array $draft ): array {
		$draft['updated'] = time();

		return $draft;
	}
}
