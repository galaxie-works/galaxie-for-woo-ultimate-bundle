<?php
/**
 * Gift kit tests: `php tests/kit/run.php` (exits 1 on any failure).
 *
 * 1. Combination wording, names and placeholders — the fixtures run.ts reads
 *    too (Support\GiftKit against frontend/src/lib/gift-kit.ts).
 * 2. The kit flow on fakes (a catalog, a WooCommerce session and cart, user
 *    meta): draft transitions, the cap, box swaps, the card following the box,
 *    discard, to_cart / to_cart_and_new and edit_from_cart through the real
 *    AJAX dispatcher, and the login merge.
 *
 * @package Galaxie\Woo
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );

$root = dirname( __DIR__, 2 );

// ------------------------------------------------------------------ stubs

$GLOBALS['kt'] = array(
	'user'     => 0,
	'meta'     => array(),
	'nonce_ok' => true,
	'fail_add' => 0,
	'actions'  => array(),
);

function __( $text, $domain = 'default' ) { return $text; }
function esc_html__( $text, $domain = 'default' ) { return $text; }
function esc_attr__( $text, $domain = 'default' ) { return $text; }
function _n( $single, $plural, $number, $domain = 'default' ) { return 1 === (int) $number ? $single : $plural; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_url_raw( $url ) { return (string) $url; }
function wp_strip_all_tags( $text ) { return strip_tags( (string) $text ); }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function wp_unslash( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function home_url( $path = '' ) { return 'https://example.test/' . ltrim( $path, '/' ); }
function wc_get_page_permalink( $page ) { return 'https://example.test/loja/'; }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function do_action( ...$args ) { $GLOBALS['kt']['actions'][] = $args[0]; }
function did_action( $hook ) { return 0; }
function add_action( ...$args ) { return true; }
function add_filter( ...$args ) { return true; }
function nocache_headers() {}
function is_user_logged_in() { return $GLOBALS['kt']['user'] > 0; }
function get_current_user_id() { return $GLOBALS['kt']['user']; }
function is_admin() { return false; }
function wp_doing_ajax() { return true; }
function get_user_meta( $user, $key, $single = false ) { return $GLOBALS['kt']['meta'][ $user ][ $key ] ?? ''; }
function update_user_meta( $user, $key, $value ) { $GLOBALS['kt']['meta'][ $user ][ $key ] = $value; return true; }
function delete_user_meta( $user, $key ) { unset( $GLOBALS['kt']['meta'][ $user ][ $key ] ); return true; }
function wp_generate_password( $length = 12, $special = true ) { static $n = 0; return substr( str_pad( 'Gen' . ( ++$n ), $length, 'x' ), 0, $length ); }
function wp_json_encode( $data, $options = 0 ) { return json_encode( $data, $options ); }
function wp_create_nonce( $action = -1 ) { return 'nonce:' . $action; }
function check_ajax_referer( $action, $field ) {
	$sent = $_REQUEST[ $field ] ?? '';
	if ( $sent !== 'nonce:' . $action ) {
		throw new KtResponse( false, array( 'nonce' => 'refused', 'expected' => 'nonce:' . $action, 'sent' => $sent ), 403 );
	}
	return 1;
}
function wc_get_notices( $type = '' ) { return array(); }
function wc_clear_notices() {}

final class KtResponse extends \Exception {
	public function __construct( public bool $ok, public array $data, public int $status = 200 ) {
		parent::__construct( 'response' );
	}
}

function wp_send_json_success( $data = null, $status = null ) { throw new KtResponse( true, (array) $data ); }
function wp_send_json_error( $data = null, $status = null ) { throw new KtResponse( false, (array) $data, (int) ( $status ?? 200 ) ); }

class WC_Product {
	public function __construct( public int $id, public int $parent = 0 ) {}
	public function get_id() { return $this->id; }
	public function get_parent_id() { return $this->parent; }
	public function is_type( $type ) { return 'variation' === $type && $this->parent > 0; }
}

class KtSession {
	public array $data = array();
	public bool $cookie = false;
	public function get( $key, $default = null ) { return $this->data[ $key ] ?? $default; }
	public function set( $key, $value ) { if ( null === $value ) { unset( $this->data[ $key ] ); } else { $this->data[ $key ] = $value; } }
	public function has_session() { return $this->cookie || is_user_logged_in(); }
	public function set_customer_session_cookie( $set ) { $this->cookie = true; }
	public function get_customer_id() { return $this->cookie ? 'guest42' : ''; }
}

class WC_Cart {
	public array $contents = array();
	public function get_cart() { return $this->contents; }
	public function get_cart_contents() { return $this->contents; }
	public function set_cart_contents( $contents ) { $this->contents = $contents; }
	public function calculate_totals() {}
	public function get_cart_hash() { return md5( json_encode( array_keys( $this->contents ) ) ); }
	public function get_cart_contents_count() { return array_sum( array_column( $this->contents, 'quantity' ) ); }
	public function add_to_cart( $product_id, $quantity = 1, $variation_id = 0, $variation = array(), $data = array() ) {
		$id = $variation_id ?: $product_id;
		if ( $GLOBALS['kt']['fail_add'] === $id ) {
			return false;
		}
		$key = md5( json_encode( array( $product_id, $variation_id, $data ) ) );
		if ( isset( $this->contents[ $key ] ) ) {
			$this->contents[ $key ]['quantity'] += $quantity;
			return $key;
		}
		$this->contents[ $key ] = $data + array(
			'key'          => $key,
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'variation'    => $variation,
			'quantity'     => $quantity,
			'data'         => new WC_Product( $id, $variation_id ? $product_id : 0 ),
		);
		return $key;
	}
}

class WooCommerce {
	public $cart;
	public $session;
}

function WC() { static $wc = null; return $wc ??= new WooCommerce(); }

foreach ( array(
	'src/Support/GiftPacking.php',
	'src/Support/GiftGroups.php',
	'src/Support/GiftKit.php',
	'src/Modules/GiftWrap/Flag.php',
	'src/Modules/GiftWrap/Groups.php',
	'src/Modules/GiftWrap/Kit/KitError.php',
	'src/Modules/GiftWrap/Kit/Catalog.php',
	'src/Modules/GiftWrap/Kit/Kits.php',
	'src/Modules/GiftWrap/Kit/Store.php',
	'src/Modules/GiftWrap/Kit/CartKits.php',
	'src/Modules/GiftWrap/Kit/Ajax.php',
) as $file ) {
	require $root . '/' . $file;
}

use Galaxie\Woo\Modules\GiftWrap\Groups;
use Galaxie\Woo\Modules\GiftWrap\Kit\Ajax;
use Galaxie\Woo\Modules\GiftWrap\Kit\CartKits;
use Galaxie\Woo\Modules\GiftWrap\Kit\Catalog;
use Galaxie\Woo\Modules\GiftWrap\Kit\KitError;
use Galaxie\Woo\Modules\GiftWrap\Kit\Kits;
use Galaxie\Woo\Modules\GiftWrap\Kit\Store;
use Galaxie\Woo\Support\GiftGroups;
use Galaxie\Woo\Support\GiftKit;
use Galaxie\Woo\Support\GiftPacking;

/**
 * The test store: 190g (100, unlimited) and 50g (101, 5 left) candles; the big
 * (10) and square (11) boxes; a card product (30) in both sizes and one (31)
 * only for the big box.
 */
final class KtCatalog implements Catalog {
	public array $sizes;
	public array $boxes;
	public array $cards;
	public array $candles;

	public function __construct( array $fixtures ) {
		$s = $fixtures['sizes'];
		$b = $fixtures['boxes'];

		$this->sizes = array( $s['190g'] + array( 'label' => '190g' ), $s['50g'] + array( 'label' => '50g' ) );

		$this->candles = array(
			100 => self::row( 100, 1000, 'Nordic Moss 190g', 60.0, null ) + array( 'candle' => $s['190g'] ),
			101 => self::row( 101, 1001, 'Nordic Moss 50g', 25.0, 5 ) + array( 'candle' => $s['50g'] ),
		);

		$this->boxes = array(
			10 => self::row( 10, 9, 'Caixa - Grande', 10.0, null ) + array( 'shape' => array( 'id' => 10 ) + $b['big'], 'attrs' => array( 'tamanho' => 'grande' ), 'description' => '' ),
			11 => self::row( 11, 9, 'Caixa - Quadrada', 10.0, 3 ) + array( 'shape' => array( 'id' => 11 ) + $b['square'], 'attrs' => array( 'tamanho' => 'quadrada' ), 'description' => '' ),
		);

		$this->cards = array(
			20 => self::row( 20, 30, 'Cartão - Quadrada', 2.0, null ) + array( 'attrs' => array( 'tamanho' => 'quadrada' ) ),
			21 => self::row( 21, 30, 'Cartão - Grande', 2.0, null ) + array( 'attrs' => array( 'tamanho' => 'grande' ) ),
			22 => self::row( 22, 31, 'Cartão longo - Grande', 3.0, null ) + array( 'attrs' => array( 'tamanho' => 'grande' ) ),
		);
	}

	private static function row( int $id, int $parent, string $name, float $price, ?int $stock ): array {
		return array(
			'id'         => $id,
			'parent'     => $parent,
			'variation'  => true,
			'attributes' => array( 'attribute_x' => (string) $id ),
			'name'       => $name,
			'title'      => preg_replace( '/ - .*$/', '', $name ),
			'image'      => '',
			'price'      => $price,
			'stock'      => $stock,
		);
	}

	public function candle( int $id ): ?array { return $this->candles[ $id ] ?? null; }
	public function boxes(): array { return $this->boxes; }
	public function cards(): array { return $this->cards; }

	public function card_for( int $parent, int $box ): int {
		$rows = array_map( static fn( array $card ): array => array( 'id' => $card['id'], 'parent' => $card['parent'], 'attrs' => $card['attrs'], 'stock' => $card['stock'] ), array_values( $this->cards ) );
		return GiftGroups::card_for( $rows, $parent, $box ? ( $this->boxes[ $box ]['attrs'] ?? array( 'x' => 'none' ) ) : null );
	}

	public function sizes(): array { return $this->sizes; }
	public function options(): array { return array( 'gap' => 0, 'stacking' => false, 'orientation' => 'lying' ); }
	public function message_max(): int { return 20; }
	public function can_add( array $product, int $quantity ): string { return 101 === $product['id'] && $quantity > 3 ? 'A loja recusou.' : ''; }
	public function money( float $amount ): string { return 'R$ ' . number_format( $amount, 2, ',', '.' ); }
	public array $kept = array();
	public function remember( string $key, callable $compute ) { return $this->kept[ $key ] ??= $compute(); }
}

// ---------------------------------------------------------------- runner

$fixtures = json_decode( (string) file_get_contents( __DIR__ . '/fixtures.json' ), true, 512, JSON_THROW_ON_ERROR );
$failed   = 0;
$passed   = 0;

$check = static function ( string $group, string $name, $actual, $expect ) use ( &$failed, &$passed ): void {
	if ( $actual === $expect ) {
		++$passed;
		echo "  ok    {$group}: {$name}\n";
		return;
	}

	++$failed;
	echo "  FAIL  {$group}: {$name}\n        expected " . json_encode( $expect, JSON_UNESCAPED_UNICODE ) . "\n        got      " . json_encode( $actual, JSON_UNESCAPED_UNICODE ) . "\n";
};

// 1. Shared fixtures.
$expand = static function ( array $pairs ) use ( $fixtures ): array {
	$out = array();
	foreach ( $pairs as $pair ) {
		for ( $i = 0; $i < $pair[1]; $i++ ) {
			$out[] = $fixtures['sizes'][ $pair[0] ];
		}
	}
	return $out;
};

foreach ( $fixtures['combos'] as $case ) {
	$sizes = array_map( static fn( string $s ): array => $fixtures['sizes'][ $s ], $case['sizes'] );
	$found = GiftKit::combos( $fixtures['boxes'][ $case['box'] ], $expand( $case['candles'] ), $sizes );
	$check( 'combos', $case['name'], $found, $case['expect'] );
	$check( 'combos wording', $case['name'], GiftKit::wording( $found, $fixtures['labels'] ), $case['wording'] );
}

// Timing: answered within the bound, and what is listed is true.
foreach ( $fixtures['timing'] as $case ) {
	$box     = $fixtures['boxes'][ $case['box'] ];
	$sizes   = array_map( static fn( string $s ): array => $fixtures['sizes'][ $s ], $case['sizes'] );
	$inside  = $expand( $case['candles'] );
	$started = hrtime( true );
	$found   = GiftKit::combos( $box, $inside, $sizes );
	$ms      = ( hrtime( true ) - $started ) / 1e6;

	$check( 'timing', "{$case['name']}: within {$case['max_ms']} ms", $ms <= $case['max_ms'], true );
	echo sprintf( "        %.0f ms, %d single(s), %d mix(es), %s\n", $ms, count( $found['singles'] ), count( $found['mixes'] ), $found['complete'] ? 'complete' : 'partial' );

	$room = 12 - count( $inside );
	$true = true;

	foreach ( $found['singles'] as $row ) {
		$size = $fixtures['sizes'][ $row[0]['size'] ] ?? null;
		$with = static fn( int $n ): array => array_merge( $inside, array_fill( 0, $n, $size ) );
		$true = $true && 1 === count( $row ) && GiftPacking::fits( $box, $with( $row[0]['count'] ) ) && ( $row[0]['count'] + 1 > $room || ! GiftPacking::fits( $box, $with( $row[0]['count'] + 1 ) ) );
	}

	foreach ( $found['mixes'] as $row ) {
		$group = $inside;
		foreach ( $row as $entry ) {
			$group = array_merge( $group, array_fill( 0, $entry['count'], $fixtures['sizes'][ $entry['size'] ] ) );
		}
		$true = $true && GiftPacking::fits( $box, $group );
	}

	$check( 'timing', "{$case['name']}: every size listed is its exact most, every mix fits", $true, true );
}

foreach ( $fixtures['wording'] as $case ) {
	$check( 'wording', $case['name'], GiftKit::wording( $case['combos'], $case['labels'] ), $case['expect'] );
}

foreach ( $fixtures['fill'] as $case ) {
	$check( 'fill', $case['name'], GiftKit::fill( $case['text'], $case['values'] ), $case['expect'] );
}

foreach ( $fixtures['clean_name'] as $case ) {
	$check( 'clean_name', $case['name'], GiftKit::clean_name( $case['name_in'] ), $case['expect'] );
}

foreach ( $fixtures['default_name'] as $case ) {
	$check( 'default_name', $case['name'], GiftKit::default_name( $case['used'] ), $case['expect'] );
}

// 2. The kit flow.
$catalog = new KtCatalog( $fixtures );
$kits    = new Kits( $catalog );

$reset = static function () use ( $catalog ): void {
	WC()->cart    = new WC_Cart();
	WC()->session = new KtSession();
	$GLOBALS['kt']['user']     = 0;
	$GLOBALS['kt']['meta']     = array();
	$GLOBALS['kt']['fail_add'] = 0;
	$_REQUEST                  = array();
	$_POST                     = array();
	Ajax::use_catalog( $catalog );
};

$error = static function ( callable $run ): array {
	try {
		$run();
	} catch ( KitError $e ) {
		return array( $e->reason, $e->data['cap'] ?? null );
	}
	return array( 'none', null );
};

/** One request through Ajax::dispatch(), with a valid nonce unless told otherwise. */
$call = static function ( string $action, array $fields = array(), ?string $nonce = null ): KtResponse {
	$session = WC()->session;
	$id      = $session->has_session() ? $session->get_customer_id() : '';
	$_POST   = $fields + array( 'action' => 'galaxie_kit_' . $action, 'nonce' => $nonce ?? 'nonce:galaxie_kit|' . $id );
	$_REQUEST = $_POST;

	try {
		Ajax::dispatch();
	} catch ( KtResponse $response ) {
		return $response;
	}

	throw new RuntimeException( 'no response' );
};

$reset();
$start = static fn( array $input, array $used = array() ): array => $kits->start( 'kit' . count( $used ), 0, $input, $used, array() );

// start, names
$draft = $start( array( 'name' => '', 'box' => 10 ) );
$check( 'start', 'no name: "Kit 1", not typed', array( $draft['name'], $draft['named'] ), array( 'Kit 1', false ) );
$check( 'start', 'no name with "Kit 1" in the cart: "Kit 2"', $start( array( 'box' => 10 ), array( 'Kit 1' ) )['name'], 'Kit 2' );
$check( 'start', 'a typed name is kept, cut at 40', $start( array( 'name' => str_repeat( 'Stella ', 10 ), 'box' => 10 ) )['name'], 'Stella Stella Stella Stella Stella Stell' );
$check( 'start', 'a box is required', $error( fn() => $start( array( 'name' => 'A' ) ) ), array( 'no_box', null ) );
$check( 'start', 'an unknown box is refused', $error( fn() => $start( array( 'box' => 99 ) ) ), array( 'box_gone', null ) );
$check( 'start', 'the starting candle must fit: 4 × 190g in the big box, 3 fit', $error( fn() => $start( array( 'box' => 10, 'candle' => 100, 'qty' => 4 ) ) ), array( 'no_room', 3 ) );
$check( 'start', 'no 190g in the square box', $error( fn() => $start( array( 'box' => 11, 'candle' => 100, 'qty' => 1 ) ) ), array( 'no_room', 0 ) );
$check( 'start', 'a product that is not a candle', $error( fn() => $start( array( 'box' => 10, 'candle' => 20, 'qty' => 1 ) ) ), array( 'not_candle', null ) );
$draft = $start( array( 'name' => 'Stella', 'box' => 10, 'card' => 30, 'message' => 'Parabéns!', 'candle' => 100, 'qty' => 2 ) );
$check( 'start', 'with box, card, message and 2 × 190g', array( $draft['box'], $draft['card'], $draft['message'], $draft['candles'] ), array( 10, 30, 'Parabéns!', array( array( 'id' => 100, 'qty' => 2 ) ) ) );

// add until full, cap
$view = $kits->view( $draft );
$check( 'room', '2 × 190g in the big box: one more of either', array( $view['room'], $view['full'], $view['count'] ), array( array( 'state' => 'many', 'combos' => '1 × 190g ou 1 × 50g' ), false, 2 ) );
$check( 'room', 'the 190g line may grow to 3', $view['candles'][0]['cap'], 3 );
$check( 'cap', 'room for one more 190g', $kits->room_for( $draft, $catalog->candle( 100 ) ), 1 );
$check( 'cap', 'two more is refused with the cap', $error( fn() => $kits->add_candle( $draft, 100, 2, array() ) ), array( 'no_room', 1 ) );
$draft = $kits->add_candle( $draft, 100, 1, array() );
$view  = $kits->view( $draft );
$check( 'full', 'the third 190g fills the box', array( $view['full'], $view['room']['state'], $view['fill'], $draft['candles'] ), array( true, 'full', 100, array( array( 'id' => 100, 'qty' => 3 ) ) ) );
$check( 'full', 'nothing more fits: cap 0', $error( fn() => $kits->add_candle( $draft, 101, 1, array() ) ), array( 'no_room', 0 ) );
$check( 'view', 'total: 3 × 60 + box 10 + card 2', array( $view['total'], $view['totalText'] ), array( 192.0, 'R$ 192,00' ) );
$check( 'view', 'the card is the big one', $view['card']['id'], 21 );
$check( 'quantity', 'quantity 13 is refused before anything else', $error( fn() => $kits->add_candle( $draft, 100, 13, array() ) ), array( 'bad_quantity', null ) );

// update / remove
$draft = $kits->update_candle( $draft, 100, 1, array() );
$check( 'update', '3 → 1', $draft['candles'], array( array( 'id' => 100, 'qty' => 1 ) ) );
$check( 'update', '1 → 4 refused, cap 3', $error( fn() => $kits->update_candle( $draft, 100, 4, array() ) ), array( 'no_room', 3 ) );
$check( 'update', 'a candle not in the kit', $error( fn() => $kits->update_candle( $draft, 101, 1, array() ) ), array( 'not_in_kit', null ) );
$draft = $kits->add_candle( $draft, 101, 2, array() );
$check( 'add', 'a 50g line joins', $draft['candles'], array( array( 'id' => 100, 'qty' => 1 ), array( 'id' => 101, 'qty' => 2 ) ) );
$check( 'room', '1 × 190g + 2 × 50g: one 50g more', $kits->room( $draft ), array( 'state' => 'one', 'combos' => '1 × 50g' ) );

// stock
$check( 'stock', '50g: 5 left, 4 in the cart, 2 in the kit, 1 more asked', $error( fn() => $kits->add_candle( $draft, 101, 1, array( '101' => 4 ) ) ), array( 'out_of_stock', 1 ) );
$check( 'stock', 'the view caps the line by stock', $kits->view( $draft, array( '101' => 4 ) )['candles'][1]['cap'], 1 );

// swap box, card follows
$check( 'swap', 'the square box cannot hold a 190g', $error( fn() => $kits->set_box( $draft, 11 ) ), array( 'box_too_small', null ) );
$small = $kits->remove_candle( $draft, 100 );
$small = $kits->set_box( $small, 11 );
$view  = $kits->view( $small );
$check( 'swap', '2 × 50g move to the square box; the card follows its size, message kept', array( $small['box'], $view['card']['id'], $small['message'] ), array( 11, 20, 'Parabéns!' ) );
$long = $kits->set_card( $draft, 31 );
$check( 'swap', 'a card with no square size refuses the square box', $error( fn() => $kits->set_box( $kits->remove_candle( $long, 100 ), 11 ) ), array( 'card_size', null ) );
$check( 'card', 'that card cannot be chosen for a square kit', $error( fn() => $kits->set_card( $small, 31 ) ), array( 'card_size', null ) );
$check( 'card', 'an unknown card product', $error( fn() => $kits->set_card( $small, 77 ) ), array( 'card_gone', null ) );
$check( 'card', 'no card', $kits->set_card( $small, 0 )['card'], 0 );
$check( 'message', 'over the store limit (20)', $error( fn() => $kits->set_message( $small, str_repeat( 'a', 21 ) ) ), array( 'message_too_long', null ) );
$check( 'message', '"<3" and "100%" kept', $kits->set_message( $small, ' <3 100% ' )['message'], '<3 100%' );
$check( 'view', 'a card without a message is flagged', $kits->view( $kits->set_message( $small, '' ) )['warnings'], array( 'card_without_message' ) );
$check( 'rename', 'blank goes back to the default, not typed', array_slice( $kits->rename( $small, "  \n ", array( 'Kit 1' ) ), 2, 2 ), array( 'name' => 'Kit 2', 'named' => false ) );
$sold = $kits->set_box( $kits->remove_candle( $draft, 100 ), 10 );
$catalog->boxes[11]['stock'] = 0;
$check( 'swap', 'a sold-out box is refused', $error( fn() => $kits->set_box( $sold, 11 ) ), array( 'box_sold_out', null ) );
$catalog->boxes[11]['stock'] = 3;

// plan
$check( 'plan', 'an empty kit cannot go to the cart', $error( fn() => $kits->plan( $kits->remove_candle( $kits->remove_candle( $draft, 100 ), 101 ), array() ) ), array( 'empty', null ) );
$check( 'plan', 'the store refuses a product: its sentence', $error( fn() => $kits->plan( $kits->update_candle( $kits->remove_candle( $draft, 100 ), 101, 4, array() ), array() ) ), array( 'refused', null ) );
$check( 'plan', 'box stock counts the cart', $error( fn() => $kits->plan( $small, array( '11' => 3 ) ) ), array( 'out_of_stock', null ) );

// store, discard
$reset();
Store::put( $draft );
$check( 'store', 'a guest draft lives in the session', array( Store::get()['id'] ?? null, WC()->session->cookie ), array( $draft['id'], true ) );
Store::clear();
$check( 'store', 'discard forgets it', Store::get(), null );
WC()->session->set( Store::SESSION_KEY, array( 'id' => 'bad id!' ) );
$check( 'store', 'a malformed draft reads as none', Store::get(), null );

// endpoints: get without nonce, start, nonce, add, to_cart
$reset();
$response = $call( 'get', array(), '' );
$check( 'ajax', 'get needs no nonce and hands out one', array( $response->ok, $response->data['kit'], $response->data['nonce'] ), array( true, null, 'nonce:galaxie_kit|' ) );
$response = $call( 'start', array( 'name' => 'Stella', 'box' => '10', 'card' => '30', 'message' => 'Oi', 'candle' => '100', 'qty' => '2' ), 'forged' );
$check( 'ajax', 'a change with a wrong nonce is refused', array( $response->ok, $response->status ), array( false, 403 ) );
$response = $call( 'start', array( 'name' => 'Stella', 'box' => '10', 'card' => '30', 'message' => 'Oi', 'candle' => '100', 'qty' => '2' ) );
$check( 'ajax', 'start opens a session and answers with the kit and its new nonce', array( $response->ok, $response->data['kit']['name'], $response->data['kit']['count'], $response->data['nonce'] ), array( true, 'Stella', 2, 'nonce:galaxie_kit|guest42' ) );
$kept = WC()->session->get( Store::PACKING_KEY );
$check( 'cache', 'the draft\'s packing answer is kept in the session', array( is_array( $kept ), $kept['value']['room'] ?? null ), array( true, $response->data['kit']['room'] ) );
WC()->session->set( Store::PACKING_KEY, array( 'key' => $kept['key'], 'value' => array( 'room' => array( 'state' => 'many', 'combos' => 'cached' ), 'extras' => array(), 'complete' => true, 'fill' => 7 ) ) );
$check( 'cache', 'and read back while the draft is unchanged', array( $call( 'get', array(), '' )->data['kit']['room']['combos'], $call( 'get', array(), '' )->data['kit']['fill'] ), array( 'cached', 7 ) );
WC()->session->set( Store::PACKING_KEY, $kept );
$response = $call( 'get', array( 'catalog' => '1' ), '' );
$check( 'cache', 'the catalog sends each box\'s "Leva até" answer', array_map( fn( $b ) => $b['holds'], $response->data['catalog']['boxes'] ), array( array( 'state' => 'many', 'combos' => '3 × 190g ou 4 × 50g ou 2 × 190g + 1 × 50g ou 1 × 190g + 3 × 50g' ), array( 'state' => 'many', 'combos' => '4 × 50g' ) ) );
foreach ( array( array( 'big', array() ), array( 'big', array( array( '190g', 2 ) ) ), array( 'big', array( array( '190g', 1 ), array( '50g', 2 ) ) ), array( 'square', array( array( '50g', 3 ) ) ), array( 'big', array( array( '190g', 3 ) ) ) ) as list( $b, $pairs ) ) {
	$inside = $expand( $pairs );
	$sizes  = array( $fixtures['sizes']['190g'], $fixtures['sizes']['50g'] );
	$check( 'fill', "{$b} with " . json_encode( $pairs ) . ': same as GiftGroups::fill()', GiftKit::fill_percent( GiftKit::combos( $fixtures['boxes'][ $b ], $inside, $sizes ), $sizes, count( $inside ) ), GiftGroups::fill( $fixtures['boxes'][ $b ], $inside, $sizes ) );
}
$check( 'fill', 'unknown room: an empty bar, never a full one', GiftKit::fill_percent( array( 'singles' => array(), 'mixes' => array(), 'complete' => false ), array( $fixtures['sizes']['50g'] ), 3 ), 0 );

$response = $call( 'start', array( 'box' => '10' ) );
$check( 'ajax', 'one draft at a time', array( $response->ok, $response->data['reason'], $response->data['kit']['name'] ), array( false, 'draft_open', 'Stella' ) );
$response = $call( 'add_candle', array( 'candle' => '100', 'qty' => '5' ) );
$check( 'ajax', 'add past the box: refused with the cap', array( $response->ok, $response->data['reason'], $response->data['cap'] ), array( false, 'no_room', 1 ) );
$response = $call( 'add_candle', array( 'candle' => '100', 'qty' => 'lots' ) );
$check( 'ajax', 'a quantity that is not a number', array( $response->ok, $response->data['reason'] ), array( false, 'bad_quantity' ) );
$response = $call( 'rename', array( 'name' => str_repeat( 'x', 5000 ) ) );
$check( 'ajax', 'a text past 4000 bytes is refused before cleaning', array( $response->ok, $response->data['reason'] ), array( false, 'too_long' ) );
$response = $call( 'add_candle', array( 'candle' => '100', 'qty' => '1' ) );
$check( 'ajax', 'add the last one: full', array( $response->ok, $response->data['kit']['full'] ), array( true, true ) );
$response = $call( 'to_cart' );
$cart     = WC()->cart->get_cart_contents();
$groups   = Groups::groups( $cart );
$gift     = reset( $groups );
$card     = reset( $gift['cards'] );
$check( 'to_cart', 'the kit is a closed group named Stella', array( $response->ok, $response->data['kit'], $response->data['next'], count( $groups ), $gift['name'] ), array( true, null, 'close', 1, 'Stella' ) );
$check( 'to_cart', 'candles (flagged), box and card with its message', array( array_sum( array_map( fn( $i ) => $i['quantity'], $gift['candles'] ) ), ! empty( reset( $gift['candles'] )['galaxie_gift_wrap']['gift'] ), reset( $gift['box'] )['data']->get_id(), $card['data']->get_id(), Groups::group_of( $card )['message'] ), array( 3, true, 10, 21, 'Oi' ) );
$check( 'to_cart', 'the kit can be edited', Groups::editable( $gift ), true );
$check( 'to_cart', 'the draft is gone', Store::get(), null );
$response = $call( 'to_cart' );
$check( 'to_cart', 'nothing to add', array( $response->ok, $response->data['reason'] ), array( false, 'no_draft' ) );

// to_cart_and_new, default names in the cart
$response = $call( 'start', array( 'box' => '11', 'candle' => '101', 'qty' => '2' ) );
$check( 'names', 'a second kit is "Kit 1" (the first is Stella)', $response->data['kit']['name'], 'Kit 1' );
$response = $call( 'to_cart_and_new' );
$check( 'to_cart_and_new', 'added, and the popup is told to start a new kit', array( $response->ok, $response->data['next'], count( Groups::groups( WC()->cart->get_cart_contents() ) ) ), array( true, 'new', 2 ) );
$response = $call( 'start', array( 'box' => '11', 'candle' => '101', 'qty' => '1' ) );
$check( 'names', 'the next one is "Kit 2"', $response->data['kit']['name'], 'Kit 2' );
$response = $call( 'rename', array( 'name' => '' ) );
$check( 'names', 'renamed to blank: still "Kit 2"', $response->data['kit']['name'], 'Kit 2' );

// a failed add leaves the cart as it was
$before = WC()->cart->get_cart_contents();
$GLOBALS['kt']['fail_add'] = 11;
$response = $call( 'to_cart' );
$check( 'to_cart', 'a refused line: nothing added, the draft kept', array( $response->ok, WC()->cart->get_cart_contents() === $before, $response->data['kit']['name'] ), array( false, true, 'Kit 2' ) );
$GLOBALS['kt']['fail_add'] = 0;

// edit_from_cart with an open draft
$stella = array_keys( array_filter( Groups::groups( WC()->cart->get_cart_contents() ), fn( $g ) => 'Stella' === $g['name'] ) )[0];
$response = $call( 'edit_from_cart', array( 'group' => $stella ) );
$check( 'edit', 'another kit is open: ask first', array( $response->ok, $response->data['reason'], $response->data['current'], count( Groups::groups( WC()->cart->get_cart_contents() ) ) ), array( false, 'needs_confirm', 'Kit 2', 2 ) );
$response = $call( 'edit_from_cart', array( 'group' => $stella, 'confirm' => '1' ) );
$groups   = Groups::groups( WC()->cart->get_cart_contents() );
$check( 'edit', 'confirmed: Kit 2 goes to the cart, Stella comes out whole', array( $response->ok, $response->data['kit']['name'], $response->data['kit']['named'], $response->data['kit']['box']['id'], $response->data['kit']['cardParent'], $response->data['kit']['message'], $response->data['kit']['count'] ), array( true, 'Stella', true, 10, 30, 'Oi', 3 ) );
$check( 'edit', 'the cart holds Kit 1 and Kit 2, not Stella', array_values( array_map( fn( $g ) => $g['name'], $groups ) ), array( 'Kit 1', 'Kit 2' ) );
$check( 'edit', 'the kit keeps its id', $response->data['kit']['id'], $stella );
$response = $call( 'edit_from_cart', array( 'group' => 'nope' ) );
$check( 'edit', 'a kit no longer in the cart', array( $response->ok, $response->data['reason'] ), array( false, 'gone' ) );

// edit_from_cart without a draft
$call( 'discard' );
$kit2     = array_keys( array_filter( Groups::groups( WC()->cart->get_cart_contents() ), fn( $g ) => 'Kit 2' === $g['name'] ) )[0];
$response = $call( 'edit_from_cart', array( 'group' => $kit2 ) );
$check( 'edit', 'no draft open: straight out, name kept but not "typed"', array( $response->ok, $response->data['kit']['name'], $response->data['kit']['named'], count( Groups::groups( WC()->cart->get_cart_contents() ) ) ), array( true, 'Kit 2', false, 1 ) );

// an old gift that is not a kit shape cannot be edited
WC()->cart->add_to_cart( 9, 2, 10, array(), Groups::data( 'oldgift', Groups::ROLE_BOX ) );
WC()->cart->add_to_cart( 1000, 1, 100, array(), Groups::data( 'oldgift', Groups::ROLE_CANDLE ) );
$check( 'edit', 'a gift with two boxes is not editable', Groups::editable( Groups::groups( WC()->cart->get_cart_contents() )['oldgift'] ), false );

// labels
$check( 'labels', 'a kit is titled by its name', Groups::label( 1, 'Stella' ), 'Stella' );
WC()->cart->add_to_cart( 9, 1, 10, array(), Groups::data( 'named', Groups::ROLE_BOX, '', '<a href="x">Mãe</a> & <3' ) );
$named = array_values( array_filter( WC()->cart->get_cart_contents(), fn( $i ) => 'named' === ( Groups::group_of( $i )['id'] ?? '' ) ) )[0];
$check( 'labels', 'a kit name is escaped where WooCommerce prints item data keys as markup', Groups::item_data( array(), $named )[0]['key'], '&lt;a href=&quot;x&quot;&gt;Mãe&lt;/a&gt; &amp; &lt;3' );
$check( 'labels', 'an older gift keeps its number', Groups::label( 2 ), 'Presente 2' );

// login merge
$reset();
Store::put( $kits->start( 'guestkit', 0, array( 'box' => 10, 'candle' => 101, 'qty' => 1 ), array(), array() ) );
$GLOBALS['kt']['meta'][7][ Store::USER_META ] = $kits->start( 'oldkit', 7, array( 'name' => 'Antigo', 'box' => 10, 'candle' => 100, 'qty' => 2 ), array(), array() );
$GLOBALS['kt']['user'] = 7;
( new CartKits( $kits ) )->merge_login();
$groups = Groups::groups( WC()->cart->get_cart_contents() );
$check( 'login', 'the guest draft wins and becomes the account\'s', array( Store::get()['id'], Store::get()['owner'], $GLOBALS['kt']['meta'][7][ Store::USER_META ]['id'] ), array( 'guestkit', 7, 'guestkit' ) );
$check( 'login', 'the older account draft is in the cart', array_values( array_map( fn( $g ) => $g['name'], $groups ) ), array( 'Antigo' ) );
$check( 'login', 'with a notice, once', array( Store::take_notices(), Store::take_notices() ), array( array( 'Encontramos o kit Antigo que você começou antes e colocamos no carrinho. Você pode editar ou remover.' ), array() ) );
( new CartKits( $kits ) )->merge_login();
$check( 'login', 'nothing happens twice', count( Groups::groups( WC()->cart->get_cart_contents() ) ), 1 );

$reset();
Store::put( $kits->start( 'guestkit', 0, array( 'box' => 10 ), array(), array() ) );
$GLOBALS['kt']['meta'][7][ Store::USER_META ] = $kits->start( 'emptyold', 7, array( 'box' => 10 ), array(), array() );
$GLOBALS['kt']['user'] = 7;
( new CartKits( $kits ) )->merge_login();
$check( 'login', 'an empty old draft is dropped silently', array( Store::get()['id'], WC()->cart->get_cart_contents(), Store::take_notices() ), array( 'guestkit', array(), array() ) );

$reset();
$GLOBALS['kt']['meta'][7][ Store::USER_META ] = $kits->start( 'acct', 7, array( 'box' => 10 ), array(), array() );
$GLOBALS['kt']['user'] = 7;
( new CartKits( $kits ) )->merge_login();
$check( 'login', 'no guest draft: the account\'s is the draft', Store::get()['id'], 'acct' );

$reset();
Store::put( $kits->start( 'guestkit', 0, array( 'box' => 10 ), array(), array() ) );
$GLOBALS['kt']['meta'][7][ Store::USER_META ] = $kits->start( 'soldout', 7, array( 'name' => 'Velho', 'box' => 11, 'candle' => 101, 'qty' => 1 ), array(), array() );
$catalog->boxes[11]['stock'] = 0;
$GLOBALS['kt']['user'] = 7;
( new CartKits( $kits ) )->merge_login();
$catalog->boxes[11]['stock'] = 3;
$check( 'login', 'an old draft the cart refuses: a notice says why', array( WC()->cart->get_cart_contents(), Store::take_notices() ), array( array(), array( 'Encontramos o kit Velho que você começou antes, mas não foi possível colocá-lo no carrinho: Não há estoque suficiente de Caixa - Quadrada.' ) ) );

echo "\n  {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
