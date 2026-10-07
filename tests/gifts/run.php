<?php
/**
 * Gift privacy tests: `php tests/gifts/run.php` (exits 1 on any failure).
 *
 * Covers `Modules\Wishlist\Gifts` and `Support\QuotedDestination` on plain
 * stubs, no WordPress and no WooCommerce:
 *
 * - LP-01: rates are quoted with the owner's real address and the packages
 *   that leave WC_Shipping::calculate_shipping() hold the area only; the
 *   shipping row template is swapped for a gift cart; the Store API scrub
 *   still masks a rate's destination.
 * - LP-02: the order-level hooks are registered by `order_hooks()`, not by
 *   the module's `hooks()`.
 * - LP-04: a stale gift (gifts turned off) fails the classic checkout and is
 *   taken out of the cart; the Store API path throws.
 * - LP-05: a guest with a gift in the cart never has the owner's area
 *   remembered as their own quote.
 * - LP-06: who sees the full address — e-mails, admin screens, the preview
 *   AJAX, staff elsewhere (masked), cron (masked), the owner.
 * - LP-09: a remembered gift turns an add-to-cart into a gift only from the
 *   gift view of the product page.
 *
 * The WooCommerce flow reproduced in `calculate_shipping()` below follows
 * WC_Shipping::calculate_shipping() (includes/class-wc-shipping.php, 10.9.3
 * lines 255-281, identical in 11.1.2): each package is rated by
 * calculate_shipping_for_package(), then the rated packages go through
 * `woocommerce_shipping_packages` and are kept as get_packages().
 *
 * @package Galaxie\Woo
 */

// phpcs:disable

namespace {

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['gx_hooks']   = array();
$GLOBALS['gx_user']    = 0;
$GLOBALS['gx_caps']    = array();
$GLOBALS['gx_admin']   = false;
$GLOBALS['gx_ajax']    = false;
$GLOBALS['gx_cron']    = false;
$GLOBALS['gx_actions'] = array();
$GLOBALS['gx_screen']  = null;
$GLOBALS['gx_meta']    = array();
$GLOBALS['gx_gifts']   = true;

function __( $text, $domain = null ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['gx_hooks'][ $hook ][] = array( $callback, $priority ); return true; }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { return add_filter( $hook, $callback, $priority, $args ); }
function apply_filters( $hook, $value, ...$args ) {
	$callbacks = $GLOBALS['gx_hooks'][ $hook ] ?? array();
	usort( $callbacks, static fn( $a, $b ) => $a[1] <=> $b[1] );
	foreach ( $callbacks as $callback ) {
		$value = call_user_func( $callback[0], $value, ...$args );
	}
	return $value;
}
function do_action( ...$args ) {}
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) ); }
function wp_unslash( $value ) { return $value; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function get_userdata( $id ) { return (object) array( 'first_name' => 'Wagner' ); }
function get_user_meta( $id, $key, $single = false ) { return $GLOBALS['gx_meta'][ $id ][ $key ] ?? ''; }
function get_current_user_id() { return $GLOBALS['gx_user']; }
function is_user_logged_in() { return $GLOBALS['gx_user'] > 0; }
function current_user_can( $cap ) { return in_array( $cap, $GLOBALS['gx_caps'], true ); }
function is_admin() { return $GLOBALS['gx_admin']; }
function wp_doing_ajax() { return $GLOBALS['gx_ajax']; }
function wp_doing_cron() { return $GLOBALS['gx_cron']; }
function did_action( $hook ) { return in_array( $hook, $GLOBALS['gx_actions'], true ) ? 1 : 0; }
function get_current_screen() { return $GLOBALS['gx_screen']; }
function wc_get_product( $id ) { return new WC_Product( (int) $id ); }
function get_users( $args ) { return array(); }

class WC_Product {
	public function __construct( public int $id ) {}
	public function get_parent_id() { return 501 === $this->id ? 500 : 0; }
}

/** The owner's saved shipping address (user 7). */
class WC_Customer {
	public function __construct( public int $id = 0 ) {}
	public function __call( $name, $args ) {
		$field = substr( $name, strlen( 'get_shipping_' ) );
		return array(
			'first_name' => 'Wagner', 'last_name' => 'Consani', 'company' => '', 'address_1' => 'Rua Secreta, 42',
			'address_2' => 'Apto 9', 'city' => 'Curitiba', 'state' => 'PR', 'postcode' => '80530-000', 'country' => 'BR', 'phone' => '41999990000',
		)[ $field ] ?? '';
	}
}

class WC_Order {
	public array $meta = array();
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function get_shipping_first_name() { return 'Wagner'; }
	public function get_shipping_city() { return 'Curitiba'; }
	public function get_shipping_state() { return 'PR'; }
}

class WC_Email {
	public function __construct( private bool $customer ) {}
	public function is_customer_email() { return $this->customer; }
}

class WP_Error {
	public array $errors = array();
	public function add( $code, $message ) { $this->errors[ $code ][] = $message; }
}

class WP_REST_Request {
	public function __construct( private string $route ) {}
	public function get_route() { return $this->route; }
}

class WP_REST_Response {
	public array $headers = array();
	public function __construct( private $data ) {}
	public function get_data() { return $this->data; }
	public function set_data( $data ) { $this->data = $data; }
	public function header( $key, $value ) { $this->headers[ $key ] = $value; }
}

class GX_Session {
	public array $data = array();
	public function get( $key ) { return $this->data[ $key ] ?? null; }
	public function set( $key, $value ) { $this->data[ $key ] = $value; }
	public function get_customer_id() { return 'guest-1'; }
}

class GX_Cart {
	public array $contents = array();
	public int $calculated = 0;
	public function get_cart() { return $this->contents; }
	public function get_cart_contents() { return $this->contents; }
	public function set_cart_contents( $contents ) { $this->contents = $contents; }
	public function calculate_totals() { $this->calculated++; }
}

/** The session customer: the buyer's own quote, until a gift replaces it. */
class GX_SessionCustomer {
	public array $fields = array( 'country' => 'BR', 'state' => 'SP', 'postcode' => '01310-100', 'city' => 'São Paulo' );
	public function __call( $name, $args ) {
		if ( 0 === strpos( $name, 'get_shipping_' ) ) {
			return $this->fields[ substr( $name, 13 ) ] ?? '';
		}
		if ( 0 === strpos( $name, 'set_shipping_' ) ) {
			$this->fields[ substr( $name, 13 ) ] = (string) $args[0];
		}
		return null;
	}
	public function set_shipping_location( $country, $state, $postcode, $city ) {
		$this->fields = array_merge( $this->fields, compact( 'country', 'state', 'postcode', 'city' ) );
	}
	public function save() {}
}

final class GX_WC {
	public $cart;
	public $session;
	public $customer;
}

function WC() { return $GLOBALS['gx_wc']; }

}

namespace Galaxie\Woo\Modules\Wishlist {
	/** The one shared list: token "abcdefabcdef12", owner 7, product 500 on it. */
	final class Lists {
		public static function find_shared( string $token ): ?array {
			if ( 'abcdefabcdef12' !== $token ) {
				return null;
			}
			return array( 'user_id' => 7, 'list' => array( 'id' => 'l1', 'share' => $token, 'gifts' => $GLOBALS['gx_gifts'], 'items' => array( 500 ) ) );
		}
	}
}

namespace Galaxie\Woo\Support {
	final class FreeShipping {
		public static function destination_known(): bool { return true; }
	}
}

namespace {

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'Galaxie\\Woo\\';
		if ( 0 !== strncmp( $prefix, $class, strlen( $prefix ) ) ) {
			return;
		}
		$path = dirname( __DIR__, 2 ) . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

use Galaxie\Woo\Modules\Wishlist\Gifts;
use Galaxie\Woo\Support\QuotedDestination;

$passed = 0;
$failed = 0;
$check  = static function ( string $name, $actual, $expected ) use ( &$passed, &$failed ): void {
	if ( $actual === $expected ) {
		$passed++;
		echo "  ok    $name\n";
		return;
	}
	$failed++;
	echo "  FAIL  $name\n        expected " . var_export( $expected, true ) . "\n        got      " . var_export( $actual, true ) . "\n";
};

$reset = static function ( bool $gift = true ): void {
	$wc           = new GX_WC();
	$wc->cart     = new GX_Cart();
	$wc->session  = new GX_Session();
	$wc->customer = new GX_SessionCustomer();
	if ( $gift ) {
		$wc->cart->contents['k1'] = array( 'product_id' => 500, 'variation_id' => 0, 'galaxie_gift' => array( 'token' => 'abcdefabcdef12', 'owner' => 7, 'list' => 'l1', 'name' => 'Wagner' ) );
	} else {
		$wc->cart->contents['k1'] = array( 'product_id' => 500, 'variation_id' => 0 );
	}
	$GLOBALS['gx_wc']    = $wc;
	$GLOBALS['gx_gifts'] = true;
	$GLOBALS['gx_user']  = 0;
	$GLOBALS['gx_caps']  = array();
	$GLOBALS['gx_admin'] = false;
	$GLOBALS['gx_ajax']  = false;
	$GLOBALS['gx_cron']  = false;
	$GLOBALS['gx_actions'] = array();
	$GLOBALS['gx_screen']  = null;
	$_REQUEST = array();
	unset( $_SERVER['HTTP_REFERER'] );
};

/**
 * WC_Cart::get_shipping_packages() → `woocommerce_cart_shipping_packages`,
 * then WC_Shipping::calculate_shipping(): each package rated (here: the
 * destination the "carrier" saw is recorded), then `woocommerce_shipping_packages`.
 */
$calculate_shipping = static function ( array &$quoted ): array {
	$packages = apply_filters( 'woocommerce_cart_shipping_packages', array(
		array(
			'contents'    => array(),
			'destination' => array( 'country' => 'BR', 'state' => 'PR', 'postcode' => '80010-000', 'city' => 'Curitiba', 'address' => 'Endereço de quem recebe o presente', 'address_1' => 'Endereço de quem recebe o presente', 'address_2' => '' ),
		),
	) );
	foreach ( $packages as $key => $package ) {
		$quoted[]                    = $package['destination'];
		$packages[ $key ]['rates'] = array( 'melhorenvio_correios_sedex' => 'R$ 30,00' );
	}
	return array_filter( (array) apply_filters( 'woocommerce_shipping_packages', $packages ) );
};

echo "LP-02 — hook registration\n";
$GLOBALS['gx_hooks'] = array();
Gifts::hooks();
$check( 'module hooks(): no e-mail/mask/Stripe hooks', array_values( array_intersect( array_keys( $GLOBALS['gx_hooks'] ), array( 'woocommerce_order_get_formatted_shipping_address', 'woocommerce_order_get_shipping_phone', 'woocommerce_email_header', 'fkwcs_payment_intent_data', 'http_request_args', 'rest_request_after_callbacks' ) ) ), array() );
$GLOBALS['gx_hooks'] = array();
Gifts::order_hooks();
foreach ( array( 'woocommerce_order_get_formatted_shipping_address', 'woocommerce_order_get_shipping_phone', 'woocommerce_email_header', 'woocommerce_email_customer_details', 'woocommerce_mail_content', 'wc_stripe_generate_payment_request', 'wc_stripe_generate_create_intent_request', 'http_request_args', 'fkwcs_payment_intent_data', 'rest_request_after_callbacks', 'woocommerce_hydration_request_after_callbacks' ) as $hook ) {
	$check( "order_hooks(): $hook", isset( $GLOBALS['gx_hooks'][ $hook ] ), true );
}
$plugin = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
$check( 'Core\\Plugin::boot() calls Gifts::order_hooks() unconditionally', 1 === preg_match( '/GiftOrders::hooks\(\);\s*\\\\Galaxie\\\\Woo\\\\Modules\\\\Wishlist\\\\Gifts::order_hooks\(\);/', $plugin ), true );

echo "LP-01 — quote with the real address, mask after\n";
$GLOBALS['gx_hooks'] = array();
Gifts::hooks();
Gifts::order_hooks();
$reset();
$quoted   = array();
$packages = $calculate_shipping( $quoted );
$check( 'carrier quoted the real street', $quoted[0]['address_1'], 'Rua Secreta, 42' );
$check( 'carrier quoted the real CEP', $quoted[0]['postcode'], '80530-000' );
$check( 'carrier quoted the real legacy address', $quoted[0]['address'], 'Rua Secreta, 42' );
$check( 'rated package: street gone', $packages[0]['destination']['address_1'], '' );
$check( 'rated package: complement gone', $packages[0]['destination']['address_2'], '' );
$check( 'rated package: legacy address gone', $packages[0]['destination']['address'], '' );
$check( 'rated package: stand-in CEP of the state', $packages[0]['destination']['postcode'] !== '80530-000' && '' !== $packages[0]['destination']['postcode'], true );
$check( 'rated package: city/state kept', array( $packages[0]['destination']['city'], $packages[0]['destination']['state'] ), array( 'Curitiba', 'PR' ) );
$check( 'rated package: rates kept', $packages[0]['rates'], array( 'melhorenvio_correios_sedex' => 'R$ 30,00' ) );
$check( 'nothing of the street survives in the rated packages', false !== strpos( serialize( $packages ), 'Secreta' ) || false !== strpos( serialize( $packages ), '80530' ), false );

$check( 'gift cart: cart-shipping.php swapped', realpath( apply_filters( 'wc_get_template', '/wc/templates/cart/cart-shipping.php', 'cart/cart-shipping.php' ) ), realpath( dirname( __DIR__, 2 ) . '/src/Modules/Wishlist/templates/cart-shipping-gift.php' ) );
$check( 'gift template file exists', is_readable( dirname( __DIR__, 2 ) . '/src/Modules/Wishlist/templates/cart-shipping-gift.php' ), true );
$template = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Modules/Wishlist/templates/cart-shipping-gift.php' );
$check( 'gift template never formats the destination', false === strpos( $template, 'formatted_destination' ) && false === strpos( $template, "'destination'" ), true );
$check( 'other templates untouched', apply_filters( 'wc_get_template', '/x/cart/cart-totals.php', 'cart/cart-totals.php' ), '/x/cart/cart-totals.php' );

$store = new WP_REST_Response( array( 'shipping_rates' => array( array( 'destination' => (object) array( 'address_1' => 'Rua Secreta, 42', 'address_2' => 'Apto 9', 'city' => 'Curitiba', 'state' => 'PR', 'postcode' => '80530-000', 'country' => 'BR' ) ) ) ) );
$out   = Gifts::scrub_store_api( $store, null, new WP_REST_Request( '/wc/store/v1/cart' ) );
$dest  = $out->get_data()['shipping_rates'][0]['destination'];
$check( 'Store API cart: rate destination street masked', $dest->address_1, Gifts::address_placeholder() );
$check( 'Store API cart: rate destination CEP masked', '80530-000' !== $dest->postcode, true );

$reset( false );
$quoted   = array();
$packages = $calculate_shipping( $quoted );
$check( 'no gift: packages untouched', $packages[0]['destination']['address_1'], 'Endereço de quem recebe o presente' );
$check( 'no gift: template untouched', apply_filters( 'wc_get_template', '/wc/templates/cart/cart-shipping.php', 'cart/cart-shipping.php' ), '/wc/templates/cart/cart-shipping.php' );

echo "LP-04 — revalidation at order time\n";
$reset();
$errors = new WP_Error();
Gifts::revalidate_checkout( array(), $errors );
$check( 'valid gift: no error', $errors->errors, array() );
$check( 'valid gift: item kept', array_keys( WC()->cart->contents ), array( 'k1' ) );

$reset();
$GLOBALS['gx_gifts'] = false; // The owner turned gifts off.
$errors = new WP_Error();
Gifts::revalidate_checkout( array(), $errors );
$check( 'gifts off: checkout error', isset( $errors->errors['galaxie_gift'][0] ) && false !== strpos( $errors->errors['galaxie_gift'][0], 'Wagner' ), true );
$check( 'gifts off: gift taken out of the cart', WC()->cart->contents, array() );
$check( 'gifts off: cart recalculated (saved)', WC()->cart->calculated, 1 );

$reset();
WC()->cart->contents['k1']['product_id'] = 999; // No longer on the list.
$errors = new WP_Error();
Gifts::revalidate_checkout( array(), $errors );
$check( 'product off the list: checkout error', isset( $errors->errors['galaxie_gift'] ), true );

$reset();
$GLOBALS['gx_gifts'] = false;
$threw = false;
try {
	Gifts::revalidate_store_api( new WC_Order() );
} catch ( \Throwable $e ) {
	$threw = false !== strpos( $e->getMessage(), 'Wagner' );
}
$check( 'Store API: stale gift throws before the address is written', $threw, true );

$reset( false );
$errors = new WP_Error();
Gifts::revalidate_checkout( array(), $errors );
$check( 'ordinary cart: no error', $errors->errors, array() );

echo "LP-05 — a guest's quote with a gift in the cart\n";
$reset();
Gifts::ship_to_owner_area();
$check( 'session location is the owner area', WC()->customer->fields['city'], 'Curitiba' );
$check( 'remembered: the buyer\'s own quote, not the owner\'s area', QuotedDestination::remember(), array( 'country' => 'BR', 'state' => 'SP', 'postcode' => '01310-100', 'city' => 'São Paulo' ) );
$reset();
WC()->customer->fields = array( 'country' => 'BR', 'state' => '', 'postcode' => '', 'city' => '' );
Gifts::ship_to_owner_area();
$check( 'no quote of their own: nothing remembered', QuotedDestination::remember(), null );
$check( 'no quote of their own: session key unset', WC()->session->get( 'galaxie_quoted_destination' ), null );

echo "LP-06 — who sees the whole address\n";
$order = new WC_Order();
$order->meta['_galaxie_gift_owner'] = 7;
$reset();
$GLOBALS['gx_user'] = 1;
$GLOBALS['gx_caps'] = array( 'edit_shop_orders' );
$check( 'staff on the front end (another plugin\'s e-mail): masked', Gifts::reveals_address( $order ), false );
$check( 'staff, mask() returns the gift line', Gifts::mask( 'Rua Secreta, 42', array(), $order ), 'Presente para Wagner — Curitiba/PR' );
$check( 'staff, phone hidden', Gifts::mask_phone( '41999990000', $order ), '' );
$GLOBALS['gx_admin'] = true;
$GLOBALS['gx_screen'] = (object) array( 'id' => 'woocommerce_page_wc-orders', 'post_type' => '' );
$check( 'staff saving an order (before the admin header): masked', Gifts::reveals_address( $order ), false );
$GLOBALS['gx_actions'] = array( 'in_admin_header' );
$check( 'staff on the HPOS order screen: whole', Gifts::reveals_address( $order ), true );
$GLOBALS['gx_screen'] = (object) array( 'id' => 'shop_order', 'post_type' => 'shop_order' );
$check( 'staff on the legacy order screen: whole', Gifts::reveals_address( $order ), true );
$GLOBALS['gx_screen'] = (object) array( 'id' => 'dashboard', 'post_type' => '' );
$check( 'staff on another admin screen: masked', Gifts::reveals_address( $order ), false );
$GLOBALS['gx_ajax'] = true;
$_REQUEST['action'] = 'woocommerce_get_order_details';
$check( 'staff, orders list preview AJAX: whole', Gifts::reveals_address( $order ), true );
$_REQUEST['action'] = 'melhor_envio_add_order';
$check( 'staff, any other admin AJAX: masked', Gifts::reveals_address( $order ), false );
$GLOBALS['gx_caps'] = array();
$_REQUEST['action'] = 'woocommerce_get_order_details';
$check( 'preview AJAX without the capability: masked', Gifts::reveals_address( $order ), false );
$reset();
Gifts::email_header( '', new WC_Email( false ) );
$check( 'WooCommerce e-mail to the store: whole', Gifts::reveals_address( $order ), true );
Gifts::email_footer();
Gifts::email_header( '', new WC_Email( true ) );
$GLOBALS['gx_caps'] = array( 'edit_shop_orders' );
$check( 'WooCommerce e-mail to the customer, sent by staff: masked', Gifts::reveals_address( $order ), false );
Gifts::email_sent( '' );
$GLOBALS['gx_caps'] = array();
$GLOBALS['gx_user'] = 7;
$check( 'the list owner: whole', Gifts::reveals_address( $order ), true );
$GLOBALS['gx_cron'] = true;
$check( 'the owner\'s id under cron: masked', Gifts::reveals_address( $order ), false );
$GLOBALS['gx_cron'] = false;
$GLOBALS['gx_user'] = 3;
$check( 'the buyer: masked', Gifts::reveals_address( $order ), false );

echo "LP-09 — a remembered gift only from the gift view\n";
$validate_token = static function (): string {
	$method = new ReflectionMethod( Gifts::class, 'incoming_token' );
	return (string) $method->invoke( null, 500 );
};
$pending = static function (): void {
	WC()->session->set( Gifts::PENDING, array( 'token' => 'abcdefabcdef12', 'product' => 500, 'time' => time() ) );
};
$reset( false );
WC()->cart->contents = array();
$pending();
$_SERVER['HTTP_REFERER'] = 'https://eirnaturals.shop/produto/vela/?galaxie_gift_view=1';
$check( 'buy box AJAX from the gift view: gift', $validate_token(), 'abcdefabcdef12' );
$_SERVER['HTTP_REFERER'] = 'https://eirnaturals.shop/loja/';
$check( 'same product from the shop grid: own purchase', $validate_token(), '' );
$check( 'same product from the shop grid: remembered gift forgotten', WC()->session->get( Gifts::PENDING ), null );
$pending();
unset( $_SERVER['HTTP_REFERER'] );
$_REQUEST[ Gifts::VIEW_ARG ] = '1';
$check( 'request marked as the gift view: gift', $validate_token(), 'abcdefabcdef12' );
$_REQUEST = array( Gifts::REQUEST_ARG => 'abcdefabcdef12' );
$_SERVER['HTTP_REFERER'] = 'https://eirnaturals.shop/lista/abc/';
WC()->session->set( Gifts::PENDING, null );
$check( '"Dar de presente" link (token in the request): gift', $validate_token(), 'abcdefabcdef12' );

echo "LP-06 — Melhor Envio's staff requests (galaxie_woo/gift_reveal_actions)\n";
$order = new WC_Order();
$order->meta['_galaxie_gift_owner'] = 7;
$staff_ajax = static function ( string $action, bool $staff = true ): void {
	$GLOBALS['gx_user']  = 1;
	$GLOBALS['gx_caps']  = $staff ? array( 'edit_shop_orders' ) : array();
	$GLOBALS['gx_admin'] = true;
	$GLOBALS['gx_ajax']  = true;
	$_REQUEST            = array( 'action' => $action );
};
$reset();
$staff_ajax( 'add_order' );
$check( 'Melhor Envio not loaded: its generic action names reveal nothing', Gifts::reveals_address( $order ), false );
define( 'MELHORENVIO_VERSION', '2.16.6' );
foreach ( array( 'add_order', 'add_cart', 'buy_click', 'get_orders', 'get_quotation', 'update_order', 'create_ticket', 'pay_ticket', 'print_ticket', 'get_payload', 'get_payload_cart' ) as $action ) {
	$staff_ajax( $action );
	$check( "staff, Melhor Envio '$action': whole", Gifts::reveals_address( $order ), true );
}
$staff_ajax( 'add_order' );
$check( 'staff, Melhor Envio: real phone', Gifts::mask_phone( '41999990000', $order ), '41999990000' );
$check( 'staff, Melhor Envio: real formatted address', Gifts::mask( 'Rua Secreta, 42', array(), $order ), 'Rua Secreta, 42' );
$staff_ajax( 'add_order', false );
$check( 'Melhor Envio action without edit_shop_orders: masked', Gifts::reveals_address( $order ), false );
$check( 'Melhor Envio action without edit_shop_orders: phone hidden', Gifts::mask_phone( '41999990000', $order ), '' );
$staff_ajax( 'fluentcrm_send_email' );
$check( 'staff, another plugin\'s AJAX: masked', Gifts::reveals_address( $order ), false );
$staff_ajax( 'add_order' );
$GLOBALS['gx_admin'] = false;
$check( 'Melhor Envio action name outside admin-ajax: masked', Gifts::reveals_address( $order ), false );
add_filter( 'galaxie_woo/gift_reveal_actions', static fn( $actions ) => array_values( array_diff( $actions, array( 'add_order' ) ) ) );
$staff_ajax( 'add_order' );
$check( 'filter can take an action out', Gifts::reveals_address( $order ), false );
$staff_ajax( 'buy_click' );
$check( 'filter keeps the rest', Gifts::reveals_address( $order ), true );
$GLOBALS['gx_hooks']['galaxie_woo/gift_reveal_actions'] = array();

// Last: REST_REQUEST cannot be undefined again.
add_filter( 'galaxie_woo/gift_reveal_actions', static fn( $actions ) => array_merge( $actions, array( '/melhor-envio/v1/' ) ) );
define( 'REST_REQUEST', true );
$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'rest_route' => '/melhor-envio/v1/orders/12' ) );
$staff_ajax( 'add_order' );
$GLOBALS['gx_admin'] = false;
$GLOBALS['gx_ajax']  = false;
$check( 'staff, filtered REST route prefix: whole', Gifts::reveals_address( $order ), true );
$GLOBALS['wp']->query_vars['rest_route'] = '/wc/v3/orders/12';
$check( 'staff, any other REST route: masked', Gifts::reveals_address( $order ), false );
$GLOBALS['wp']->query_vars['rest_route'] = '/melhor-envio/v1/orders/12';
$GLOBALS['gx_caps'] = array();
$check( 'filtered REST route without edit_shop_orders: masked', Gifts::reveals_address( $order ), false );

echo "\n  $passed passed, $failed failed\n";
exit( $failed ? 1 : 0 );

}
