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

	/** @var callable|null ( string $key, callable $compute ): mixed — the session's packing cache. */
	private $remember;

	/**
	 * @param callable|null $remember Keeps the draft's packing answer between
	 *                                requests ({@see Store::remember()}); none: computed each time.
	 */
	public function __construct( private Catalog $catalog, ?callable $remember = null ) {
		$this->remember = $remember;
	}

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

		$draft = $this->set_box( $draft, (int) $input['box'], $in_cart );
		$draft = $this->set_card( $draft, (int) ( $input['card'] ?? 0 ) );
		$draft = $this->set_message( $draft, (string) ( $input['message'] ?? '' ) );

		// The candle the shopper came with is the one thing here that can go away
		// between the product page and this request. Losing it must not lose the
		// name, the box, the card and the message with it: the kit is kept and
		// the candle is reported on its own.
		$refused = '';

		if ( ! empty( $input['candle'] ) ) {
			try {
				$draft = $this->add_candle( $draft, (int) $input['candle'], (int) ( $input['qty'] ?? 1 ), $in_cart );
			} catch ( KitError $error ) {
				$refused = $error->getMessage();
			}
		}

		return array( $draft, $refused );
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

	/**
	 * @param array<string,int> $in_cart Units of each product already in the cart: a
	 *                                   box another kit already takes is not free.
	 */
	public function set_box( array $draft, int $box, array $in_cart = array() ): array {
		$boxes = $this->catalog->boxes();
		$row   = $boxes[ $box ] ?? null;

		if ( ! $row ) {
			throw new KitError( 'box_gone', __( 'Essa caixa não está mais disponível.', 'galaxie-woo' ) );
		}

		// The kit's own box stays chosen; to_cart checks its stock again.
		if ( $box !== (int) $draft['box'] && ! self::box_available( $row, $in_cart ) ) {
			throw new KitError( 'box_sold_out', __( 'Essa caixa está esgotada.', 'galaxie-woo' ) );
		}

		$units = $this->units( $draft );

		// Only a proved "no" refuses the box: a search that ran out of work has
		// not shown the candles will not go in, and the shopper must not pay for
		// the difference.
		if ( $units && false === GiftPacking::fits_known( self::shape( $row ), $units, $this->catalog->options() ) ) {
			throw new KitError( 'box_too_small', __( 'Essa caixa não comporta as velas deste kit.', 'galaxie-woo' ) );
		}

		if ( $draft['card'] && ! $this->catalog->card_for( (int) $draft['card'], $box ) ) {
			// A refusal with no way out is a dead end: the shopper cannot guess that
			// the card is what stands between them and the box they want.
			throw new KitError( 'card_size', __( 'O cartão deste kit não existe para essa caixa. Troque o cartão (ou siga sem cartão) e escolha a caixa de novo.', 'galaxie-woo' ) );
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

		if ( ! $this->takes( $draft, $candle, $qty ) ) {
			throw self::no_room( $this->room_within( $draft, $candle ) );
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

		if ( ! $this->takes( $others, $candle, $qty ) ) {
			throw self::no_room( $this->room_within( $others, $candle ) );
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
	 * The walk stops where `takes()` starts refusing and nowhere earlier: one
	 * more is counted in while the search cannot prove it will not go in, and a
	 * check that ran out of work or of clock has proved nothing. The two used to
	 * disagree — the cap was budgeted and the refusal was not — so the "+" ran
	 * out before the server did.
	 *
	 * @param array $candle A catalog candle row.
	 */
	public function room_for( array $draft, array $candle ): int {
		$box = $this->catalog->boxes()[ (int) $draft['box'] ] ?? null;

		if ( ! $box ) {
			return 0;
		}

		$units   = $this->units( $draft );
		$shape   = self::shape( $box );
		$options = $this->catalog->options();
		$more    = 0;

		while ( count( $units ) + $more < GiftPacking::MAX_ITEMS ) {
			$with = $units;

			for ( $i = 0; $i <= $more; $i++ ) {
				$with[] = $candle['candle'];
			}

			if ( false === GiftPacking::fits_known( $shape, $with, $options ) ) {
				break;
			}

			++$more;
		}

		return $more;
	}

	/**
	 * Whether the draft's box takes `$qty` more of this candle: one fit check,
	 * within the combinations' budget so a long search cannot hold the request,
	 * and an unproved "no" lets the add through ({@see self::room_for()}).
	 */
	private function takes( array $draft, array $candle, int $qty ): bool {
		$box   = $this->catalog->boxes()[ (int) $draft['box'] ] ?? null;
		$units = $this->units( $draft );

		if ( ! $box || count( $units ) + $qty > GiftPacking::MAX_ITEMS ) {
			return false;
		}

		for ( $i = 0; $i < $qty; $i++ ) {
			$units[] = $candle['candle'];
		}

		$shape   = self::shape( $box );
		$options = $this->catalog->options();

		list( $answer ) = GiftPacking::with_deadline(
			(float) GiftKit::BUDGET_MS,
			static fn(): ?bool => GiftPacking::fits_known( $shape, $units, $options )
		);

		return false !== $answer;
	}

	/**
	 * room_for() within the same budget takes() gets, for the refusal message
	 * and the popup's + button.
	 */
	private function room_within( array $draft, array $candle ): int {
		list( $cap ) = GiftPacking::with_deadline( (float) GiftKit::BUDGET_MS, fn(): int => $this->room_for( $draft, $candle ) );

		return (int) $cap;
	}

	/**
	 * What still fits, in words: { state: full|one|many|unknown, combos }.
	 *
	 * @return array{state:string, combos:string}
	 */
	public function room( array $draft ): array {
		return $this->packing( $draft )['room'];
	}

	/**
	 * The draft's packing answer — what still fits, in words and per size, and
	 * how full the box is — from one budgeted combos() call, kept in the session
	 * by the draft's box, candles, the store's sizes and options.
	 *
	 * @return array{room: array{state:string, combos:string}, extras: array<string,int>, complete: bool, fill: ?int}
	 */
	public function packing( array $draft ): array {
		$box = $this->catalog->boxes()[ (int) $draft['box'] ] ?? null;

		if ( ! $box ) {
			return array(
				'room'     => array(
					'state'  => 'none',
					'combos' => '',
				),
				'extras'   => array(),
				'complete' => true,
				// With no box there is nothing to be full of, which is not 0 %.
				'fill'     => null,
			);
		}

		$sizes   = $this->catalog->sizes();
		$options = $this->catalog->options();
		$units   = $this->units( $draft );
		$key     = 'packing|' . md5( (string) wp_json_encode( array( self::shape( $box ), $units, $sizes, $options, GiftKit::BUDGET_MS ) ) );

		// [ the answer, whether the search finished ]: only a finished one is
		// worth keeping past this request ({@see Store::remember()}).
		$compute = static function () use ( $box, $units, $sizes, $options ): array {
			$combos = GiftKit::combos( self::shape( $box ), $units, $sizes, $options );
			$extras = array();

			foreach ( $combos['singles'] as $row ) {
				$entry = $row['entries'][0] ?? null;

				if ( $entry ) {
					$extras[ (string) $entry['size'] ] = (int) $entry['count'];
				}
			}

			return array(
				array(
					'room'     => GiftKit::wording( $combos, self::labels( $sizes ) ),
					'extras'   => $extras,
					'complete' => (bool) $combos['complete'],
					'fill'     => GiftKit::fill_percent( $combos, $sizes, $units ),
				),
				! empty( $combos['complete'] ) && ! empty( $combos['settled'] ),
			);
		};

		$found = $this->remember ? ( $this->remember )( $key, $compute ) : $compute()[0];

		return is_array( $found ) && isset( $found['room'], $found['extras'] ) ? $found : $compute()[0];
	}

	/**
	 * "Leva até …" for an empty box: the same answer for every shopper, kept by
	 * the catalog (a transient on the site) by box, sizes and options.
	 *
	 * @return array{state:string, combos:string}
	 */
	public function box_holds( array $box ): array {
		$sizes   = $this->catalog->sizes();
		$options = $this->catalog->options();
		$key     = 'holds|' . md5( (string) wp_json_encode( array( self::shape( $box ), $sizes, $options, GiftKit::BUDGET_MS ) ) );

		$found = $this->catalog->remember(
			$key,
			static function () use ( $box, $sizes, $options ): array {
				$combos = GiftKit::combos( self::shape( $box ), array(), $sizes, $options );

				// A day of every shopper's "Leva até …" is only for an answer
				// the search finished.
				return array(
					GiftKit::wording( $combos, self::labels( $sizes ) ),
					! empty( $combos['complete'] ) && ! empty( $combos['settled'] ),
				);
			}
		);

		return is_array( $found ) && isset( $found['state'] ) ? $found : array(
			'state'  => 'unknown',
			'combos' => '',
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
		$packing = $this->packing( $draft );
		$shapes  = array();
		$total   = 0.0;

		foreach ( $sizes as $size ) {
			$shapes[ (string) $size['size'] ] = array( (float) $size['length'], (float) $size['width'], (float) $size['height'] );
		}
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
				// The most this line may hold, for the popup's + button: by room,
				// and by stock only when the store shows stock amounts (a cap would
				// tell the number). The server checks stock on every add anyway.
				'cap'     => $box ? ( $this->catalog->shows_stock() ? min( $this->line_cap( $packing, $shapes, $line, $candle, $others ), max( (int) $line['qty'], self::stock_left( $candle, $in_cart ) ) ) : $this->line_cap( $packing, $shapes, $line, $candle, $others ) ) : (int) $line['qty'],
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

		$room = $packing['room'];
		$fill = $packing['fill'];

		// An empty kit is never "full": a box that holds nothing with no candle
		// in it is a box too small for what the store sells, and saying "a caixa
		// já está completa" there is a lie the shopper cannot act on.
		if ( 'full' === $room['state'] && $count < 1 ) {
			$room['state'] = 'nofit';
		}

		if ( $box ) {
			$total += $box['price'];
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
			'full'       => 'full' === $room['state'] && $count > 0,
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
			// In stock or not, never how many: `get` is public.
			'inStock'     => 0 !== $box['stock'],
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

	/**
	 * The most a candle line may hold: its quantity plus what the packing
	 * answer says still fits of its size, when the candle is exactly that
	 * size's shape; otherwise the line's own search, within the budget.
	 *
	 * @param array                         $packing From packing().
	 * @param array<string, array<int,float>> $shapes  Size => [ length, width, height ].
	 */
	private function line_cap( array $packing, array $shapes, array $line, array $candle, array $others ): int {
		$size = (string) $candle['candle']['size'];
		$same = isset( $shapes[ $size ] ) && array( (float) $candle['candle']['length'], (float) $candle['candle']['width'], (float) $candle['candle']['height'] ) === $shapes[ $size ];

		if ( $same && ( isset( $packing['extras'][ $size ] ) || $packing['complete'] ) ) {
			return (int) $line['qty'] + (int) ( $packing['extras'][ $size ] ?? 0 );
		}

		return max( (int) $line['qty'], $this->room_within( $others, $candle ) );
	}

	/**
	 * Whether one more of this box can be sold, counting those in the cart.
	 *
	 * @param array<string,int> $in_cart
	 */
	public static function box_available( array $box, array $in_cart ): bool {
		if ( null === $box['stock'] ) {
			return true;
		}

		return (int) $box['stock'] - (int) ( $in_cart[ (string) $box['id'] ] ?? 0 ) >= 1;
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
	/** @param array<string,int> $in_cart */
	private function check_stock( array $candle, int $wanted, array $in_cart ): void {
		if ( $wanted > self::stock_left( $candle, $in_cart ) ) {
			/* translators: %s: product name. */
			throw new KitError( 'out_of_stock', sprintf( __( 'Não há estoque suficiente de %s.', 'galaxie-woo' ), $candle['name'] ), $this->catalog->shows_stock() ? array( 'cap' => max( 0, self::stock_left( $candle, $in_cart ) ) ) : array() );
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
