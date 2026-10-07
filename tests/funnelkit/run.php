<?php
/**
 * FunnelKit Stripe tests: `php tests/funnelkit/run.php` (exits 1 on any failure).
 *
 * A plain runner like tests/gift-packing, on stubs defined here, for the two
 * pieces written for "FunnelKit Payment Gateway for Stripe WooCommerce":
 *
 * - Support\StripeCards with FunnelKit as the card gateway: what the account
 *   widget is told (gateway, key, nonce action, brands), when it is told
 *   nothing, and the save — the card stored is the one on a verified intent of
 *   this customer, through the gateway's own add_payment_method(); an intent of
 *   another customer, one not yet succeeded, or a brand the merchant refuses
 *   stores nothing. The WooCommerce Stripe plugin still wins when both are on.
 * - Modules\FunnelKitPtBr\Translations: gettext only in a Portuguese request
 *   and only where no translation exists; stored English defaults translated,
 *   merchant titles kept; card messages by code; every placeholder of an
 *   original kept in its translation; the express "Or" separator as "ou".
 *
 * `--funnelkit=<dir>` (a copy of the FunnelKit plugin) also checks that every
 * original string and code is still one FunnelKit writes.
 *
 * @package Galaxie\Woo
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );

$args = getopt( '', array( 'funnelkit:' ) );

/* ---------------------------------------------------------------- stubs */

$GLOBALS['gx'] = array();

function gx_reset( array $state = array() ): void {
	$GLOBALS['gx'] = array_merge(
		array(
			'logged_in'  => true,
			'user_id'    => 7,
			'locale'     => 'pt_BR',
			'gateways'   => array(),
			'user_meta'  => array(),
			'tokens'     => array(),
			'response'   => null,
			'nonce_ok'   => true,
		),
		$state
	);
	$_POST = array();
}

final class GxJsonSent extends \Exception {}

function __( $text, $domain = '' ) { return $text; }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function do_action( ...$a ) {}
function is_user_logged_in() { return $GLOBALS['gx']['logged_in']; }
function get_current_user_id() { return $GLOBALS['gx']['user_id']; }
function wp_get_current_user() { return (object) array( 'ID' => $GLOBALS['gx']['user_id'] ); }
function admin_url( $path = '' ) { return 'https://shop.test/wp-admin/' . $path; }
function wp_create_nonce( $action ) { return 'nonce:' . $action; }
function check_ajax_referer( $action, $arg, $die ) { return $GLOBALS['gx']['nonce_ok']; }
function get_locale() { return $GLOBALS['gx']['locale']; }
function determine_locale() { return $GLOBALS['gx']['locale']; }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function wp_unslash( $v ) { return $v; }
function get_user_option( $key, $user_id ) { return $GLOBALS['gx']['user_meta'][ $key ] ?? false; }
function is_admin() { return ! empty( $GLOBALS['gx']['admin'] ); }
function wp_doing_ajax() { return false; }

/** The first JSON answer is the one the browser gets; anything after it never leaves the server. */
function gx_send( bool $success, $data ): void {
	$GLOBALS['gx']['response'] ??= array( 'success' => $success, 'data' => $data );
	throw new GxJsonSent();
}
function wp_send_json_success( $data = null ) { gx_send( true, $data ); }
function wp_send_json_error( $data = null, $status = null ) { gx_send( false, $data ); }

class WC_Payment_Gateway {
	public $id       = '';
	public $enabled  = 'yes';
	public $supports = array( 'products' );
	public function supports( $feature ) { return in_array( $feature, $this->supports, true ); }
}

class WC_Payment_Token {
	private $id;
	private $token;
	public function __construct( int $id, string $token ) { $this->id = $id; $this->token = $token; }
	public function get_id() { return $this->id; }
	public function get_token() { return $this->token; }
}

class WC_Payment_Tokens {
	public static function get_customer_tokens( $user_id, $gateway_id ) {
		return $GLOBALS['gx']['tokens'][ $gateway_id ] ?? array();
	}
}

/** FunnelKit's Stripe client: answers are scripted per test. */
final class FakeFkClient {
	public array $intents = array();
	public array $methods = array();
	public function setup_intents( $method, $args ) {
		$intent = $this->intents[ $args[0] ] ?? null;
		return $intent ? array( 'success' => true, 'data' => $intent ) : array( 'success' => false, 'message' => 'No such setupintent' );
	}
	public function payment_methods( $method, $args ) {
		$pm = $this->methods[ $args[0] ] ?? null;
		return $pm ? array( 'success' => true, 'data' => $pm ) : array( 'success' => false, 'message' => 'No such payment_method' );
	}
}

/** Stands in for \FKWCS\Gateway\Stripe\CreditCard: the members StripeCards uses. */
final class FakeFunnelKitCard extends WC_Payment_Gateway {
	public $id                 = 'fkwcs_stripe';
	public $supports           = array( 'products', 'tokenization', 'add_payment_method' );
	public $enable_saved_cards = 'yes';
	public $allowed_cards      = array( 'mastercard', 'visa' );
	public ?FakeFkClient $client;
	public array $added = array();
	public function __construct() { $this->client = new FakeFkClient(); }
	public function is_configured() { return true; }
	public function get_client() { return $this->client; }
	public function get_client_key() { return 'pk_test_fk'; }
	public function add_payment_method() {
		$this->added[] = array( 'source' => $_POST['fkwcs_source'] ?? '', 'payment_method' => $_POST['payment_method'] ?? '' );
		$GLOBALS['gx']['tokens']['fkwcs_stripe'][] = new WC_Payment_Token( 501, (string) ( $_POST['fkwcs_source'] ?? '' ) );
		return array( 'result' => 'success', 'redirect' => '/minha-conta/payment-methods/' );
	}
}

/** The WooCommerce Stripe plugin's gateway, for "both are on". */
class WC_Stripe_UPE_Payment_Gateway extends WC_Payment_Gateway {
	public const ID = 'stripe';
	public $id = 'stripe';
	public $publishable_key = 'pk_test_official';
	public function is_saved_cards_enabled() { return true; }
}

final class GxGateways {
	public function payment_gateways() { return $GLOBALS['gx']['gateways']; }
}

final class GxWc {
	public $session = null;
	public function payment_gateways() { return new GxGateways(); }
}

function WC() { return new GxWc(); }

require dirname( __DIR__, 2 ) . '/src/Support/StripeCards.php';
require dirname( __DIR__, 2 ) . '/src/Modules/FunnelKitPtBr/Translations.php';

use Galaxie\Woo\Modules\FunnelKitPtBr\Translations;
use Galaxie\Woo\Support\StripeCards;

/* ---------------------------------------------------------------- runner */

$failed = 0;
$passed = 0;

$check = static function ( string $group, string $name, $actual, $expect ) use ( &$failed, &$passed ): void {
	if ( $actual === $expect ) {
		++$passed;
		echo "  ok    {$group}: {$name}\n";
		return;
	}

	++$failed;
	echo "  FAIL  {$group}: {$name}\n        expected " . json_encode( $expect ) . "\n        got      " . json_encode( $actual ) . "\n";
};

/** Runs the save endpoint and returns what it answered. */
$save = static function ( string $intent_id ): ?array {
	$_POST['nonce']        = 'x';
	$_POST['setup_intent'] = $intent_id;

	try {
		StripeCards::ajax_save_card();
	} catch ( GxJsonSent $e ) {
		// The answer is in $GLOBALS['gx']['response'].
	}

	return $GLOBALS['gx']['response'];
};

$intent = static fn( string $status, string $customer = 'cus_mine', $pm = 'pm_card' ): object => (object) array(
	'id'             => 'seti_1',
	'status'         => $status,
	'customer'       => $customer,
	'payment_method' => $pm,
);

$card = static fn( string $brand ): object => (object) array( 'id' => 'pm_card', 'card' => (object) array( 'brand' => $brand ) );

/** A FunnelKit gateway on the store, the customer's Stripe customer stored as FunnelKit stores it. */
$funnelkit = static function ( array $state = array() ) {
	$fk = new FakeFunnelKitCard();
	gx_reset( array_merge( array( 'gateways' => array( 'fkwcs_stripe' => $fk ), 'user_meta' => array( '_fkwcs_customer_id' => 'cus_mine' ) ), $state ) );
	return $fk;
};

/* -------------------------------------------------- StripeCards: config */

$fk     = $funnelkit();
$config = StripeCards::client_config();
$check( 'config', 'FunnelKit is the gateway', $config['gateway'] ?? null, 'fkwcs_stripe' );
$check( 'config', 'its publishable key', $config['key'] ?? null, 'pk_test_fk' );
$check( 'config', 'FunnelKit\'s own nonce action for its intent', $config['intentNonce'] ?? null, 'nonce:fkwcs_nonce' );
$check( 'config', 'our nonce for the save', $config['saveNonce'] ?? null, 'nonce:galaxie_woo_stripe_cards' );
$check( 'config', 'the brands the merchant accepts', $config['brands'] ?? null, array( 'mastercard', 'visa' ) );

$fk->enable_saved_cards = 'no';
$check( 'config', 'nothing with Saved Cards off', StripeCards::client_config(), null );

$fk = $funnelkit();
$fk->enabled = 'no';
$check( 'config', 'nothing with the gateway off', StripeCards::client_config(), null );

$fk = $funnelkit();
$fk->client = null;
$check( 'config', 'nothing without a Stripe client (keys missing)', StripeCards::client_config(), null );

$funnelkit( array( 'logged_in' => false ) );
$check( 'config', 'nothing for a visitor', StripeCards::client_config(), null );

$fk = $funnelkit();
$GLOBALS['gx']['gateways']['stripe'] = new WC_Stripe_UPE_Payment_Gateway();
$check( 'config', 'the WooCommerce Stripe plugin wins when both are on', StripeCards::client_config()['gateway'] ?? null, 'stripe' );

$official = StripeCards::client_config();
$check( 'config', 'and keeps its own key and nonce', array( $official['key'] ?? null, $official['intentNonce'] ?? null ), array( 'pk_test_official', 'nonce:wc_stripe_create_and_confirm_setup_intent_nonce' ) );

/* -------------------------------------------------- StripeCards: save */

$fk = $funnelkit();
$fk->client->intents['seti_1'] = $intent( 'succeeded' );
$fk->client->methods['pm_card'] = $card( 'visa' );
$_POST['fkwcs_source'] = 'pm_someone_elses'; // Ignored: the intent says which card.
$answer = $save( 'seti_1' );
$check( 'save', 'a succeeded intent of this customer is saved', $answer, array( 'success' => true, 'data' => array( 'token' => 501 ) ) );
$check( 'save', 'through FunnelKit\'s add_payment_method(), with the intent\'s card', $fk->added, array( array( 'source' => 'pm_card', 'payment_method' => 'fkwcs_stripe' ) ) );

$fk = $funnelkit();
$fk->client->intents['seti_1'] = $intent( 'succeeded', 'cus_someone_else' );
$fk->client->methods['pm_card'] = $card( 'visa' );
$answer = $save( 'seti_1' );
$check( 'save', 'an intent of another customer is refused', $answer['success'] ?? null, false );
$check( 'save', 'and nothing is stored', $fk->added, array() );

$fk = $funnelkit( array( 'user_meta' => array() ) );
$fk->client->intents['seti_1'] = $intent( 'succeeded' );
$save( 'seti_1' );
$check( 'save', 'no stored Stripe customer: refused', $fk->added, array() );

$fk = $funnelkit();
$fk->client->intents['seti_1'] = $intent( 'requires_action' );
$answer = $save( 'seti_1' );
$check( 'save', 'bank authentication not finished: refused, saying so', array( $answer['success'] ?? null, $answer['data']['status'] ?? null ), array( false, 'requires_action' ) );
$check( 'save', 'and nothing is stored', $fk->added, array() );

$fk = $funnelkit();
$fk->client->intents['seti_1'] = $intent( 'processing' );
$answer = $save( 'seti_1' );
$check( 'save', 'still processing: refused as pending', $answer['data']['status'] ?? null, 'processing' );

$fk = $funnelkit();
$fk->client->intents['seti_1'] = $intent( 'succeeded' );
$fk->client->methods['pm_card'] = $card( 'amex' );
$answer = $save( 'seti_1' );
$check( 'save', 'a brand the merchant refuses is not stored', array( $answer['data']['status'] ?? null, $fk->added ), array( 'brand', array() ) );

$fk = $funnelkit();
$fk->client->intents['seti_1'] = $intent( 'succeeded', 'cus_mine', $card( 'mastercard' ) );
$save( 'seti_1' );
$check( 'save', 'an expanded payment method on the intent is read directly', $fk->added[0]['source'] ?? null, 'pm_card' );

$fk = $funnelkit();
$answer = $save( 'pi_not_a_setup_intent' );
$check( 'save', 'an id that is not a SetupIntent is refused', array( $answer['success'] ?? null, $fk->added ), array( false, array() ) );

$fk = $funnelkit();
$answer = $save( 'seti_unknown' );
$check( 'save', 'an intent Stripe does not know is refused', array( $answer['success'] ?? null, $fk->added ), array( false, array() ) );

$fk = $funnelkit( array( 'nonce_ok' => false ) );
$answer = $save( 'seti_1' );
$check( 'save', 'a bad nonce is refused', $answer['success'] ?? null, false );

/* -------------------------------------------------- Translations */

gx_reset();
$check( 'pt-br', 'a default title in Portuguese', Translations::gettext( 'Credit Card (Stripe)', 'Credit Card (Stripe)' ), 'Cartão de crédito (Stripe)' );
$check( 'pt-br', 'a string with no entry stays', Translations::gettext( 'Enable Stripe Gateway', 'Enable Stripe Gateway' ), 'Enable Stripe Gateway' );
$check( 'pt-br', 'an installed translation wins', Translations::gettext( 'Cartão (tradução oficial)', 'Credit Card (Stripe)' ), 'Cartão (tradução oficial)' );

gx_reset( array( 'locale' => 'en_US' ) );
$check( 'pt-br', 'nothing outside Portuguese', Translations::gettext( 'Credit Card (Stripe)', 'Credit Card (Stripe)' ), 'Credit Card (Stripe)' );
$check( 'pt-br', 'stored default kept outside Portuguese', Translations::gateway_title( 'Credit Card (Stripe)', 'fkwcs_stripe' ), 'Credit Card (Stripe)' );

gx_reset();
$check( 'pt-br', 'stored English default title translated', Translations::gateway_title( 'Credit Card (Stripe)', 'fkwcs_stripe' ), 'Cartão de crédito (Stripe)' );
$check( 'pt-br', 'stored English default description translated', Translations::gateway_description( 'Pay with Pix', 'fkwcs_stripe_pix' ), 'Pague com Pix — o código aparece ao finalizar o pedido.' );
$check( 'pt-br', 'Pix is just "Pix"', Translations::gateway_title( 'Stripe Pix', 'fkwcs_stripe_pix' ), 'Pix' );

$check( 'pt-br', 'express separator "Or" becomes "ou"', Translations::separator( array( 'separator_text' => 'Or', 'enabled' => 'yes' ), 'fkwcs_stripe_google_pay' ), array( 'separator_text' => 'ou', 'enabled' => 'yes' ) );
$check( 'pt-br', 'legacy shared separators too', Translations::separator( array( 'express_checkout_separator_product' => 'OR ', 'express_checkout_separator_cart' => 'Or' ) ), array( 'express_checkout_separator_product' => 'ou', 'express_checkout_separator_cart' => 'ou' ) );
$check( 'pt-br', 'a separator the merchant wrote is kept', Translations::separator( array( 'separator_text' => 'ou pague com' ) ), array( 'separator_text' => 'ou pague com' ) );
$GLOBALS['gx']['admin'] = true;
$check( 'pt-br', 'wp-admin settings screen shows what is stored', Translations::separator( array( 'separator_text' => 'Or' ) ), array( 'separator_text' => 'Or' ) );
gx_reset( array( 'locale' => 'en_US' ) );
$check( 'pt-br', 'separator kept outside Portuguese', Translations::separator( array( 'separator_text' => 'Or' ) ), array( 'separator_text' => 'Or' ) );
gx_reset();
$check( 'pt-br', 'a title the merchant wrote is kept', Translations::gateway_title( 'Cartão de crédito', 'fkwcs_stripe' ), 'Cartão de crédito' );
$check( 'pt-br', 'another gateway\'s identical title is not touched', Translations::gateway_title( 'Credit Card (Stripe)', 'stripe' ), 'Credit Card (Stripe)' );
$check( 'pt-br', 'brand names stay', Translations::gateway_title( 'Apple Pay', 'fkwcs_stripe_apple_pay' ), 'Apple Pay' );

$messages = Translations::messages( array( 'incomplete_number' => 'Your card number is incomplete.', 'bank_account_exists' => 'English', 'unknown' => 'x' ) );
$check( 'pt-br', 'card messages by code', $messages['incomplete_number'], 'O número do cartão está incompleto.' );
$check( 'pt-br', 'codes without an entry kept', array( $messages['bank_account_exists'], $messages['unknown'] ), array( 'English', 'x' ) );
$check( 'pt-br', 'no code is added', count( $messages ), 3 );

$placeholders = static function ( string $s ): array {
	preg_match_all( '/%(?:\d+\$)?\d*[sd]/', $s, $m );
	sort( $m[0] );
	return $m[0];
};
$broken = array();
foreach ( Translations::strings() as $english => $portuguese ) {
	if ( $placeholders( $english ) !== $placeholders( $portuguese ) ) {
		$broken[] = $english;
	}
}
$check( 'pt-br', 'every placeholder kept in its translation', $broken, array() );

$missing = array();
foreach ( Translations::gateway_defaults() as $id => $defaults ) {
	foreach ( $defaults as $default ) {
		if ( ! isset( Translations::strings()[ $default ] ) && ! in_array( $default, array( 'Apple Pay', 'Google Pay' ), true ) ) {
			$missing[] = $default;
		}
	}
}
$check( 'pt-br', 'every gateway default has a translation (names excepted)', $missing, array() );

if ( ! empty( $args['funnelkit'] ) ) {
	$source = '';
	$files  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( (string) $args['funnelkit'], FilesystemIterator::SKIP_DOTS ) );
	foreach ( $files as $file ) {
		if ( preg_match( '/\.php(\.txt)?$/', $file->getFilename() ) ) {
			$source .= file_get_contents( $file->getPathname() );
		}
	}

	$gone = array();
	foreach ( array_keys( Translations::strings() ) as $english ) {
		if ( false === strpos( $source, "'" . str_replace( "'", "\\'", $english ) . "'" ) ) {
			$gone[] = $english;
		}
	}
	foreach ( array_keys( Translations::card_messages() ) as $code ) {
		if ( false === strpos( $source, "'" . $code . "'" ) ) {
			$gone[] = $code;
		}
	}
	$check( 'funnelkit source', 'every original string and code is still FunnelKit\'s', $gone, array() );
}

echo "\n  {$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
