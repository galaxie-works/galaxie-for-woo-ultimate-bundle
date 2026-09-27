<?php
/**
 * Just enough WordPress and WooCommerce for `tests/settings-api/run.php`:
 * options, hooks, capabilities, post meta and transients kept in globals, and
 * the REST request and error objects.
 *
 * Not WordPress: `sanitize_text_field()` strips tags and squeezes whitespace,
 * `wc_format_decimal()` keeps digits, dot and minus. Enough to show the
 * plugin's own rules run the same from every way in, not to test WordPress.
 *
 * @package Galaxie\Woo
 */

$GLOBALS['gx_options']     = array();
$GLOBALS['gx_hooks']       = array();
$GLOBALS['gx_caps']        = array();
$GLOBALS['gx_user_caps']   = array();
$GLOBALS['gx_post_meta']   = array();
$GLOBALS['gx_meta']        = array();
$GLOBALS['gx_deleted']     = array();
$GLOBALS['gx_cache_bumps'] = 0;

function __( $text, $domain = null ) {
	return $text;
}

function esc_html__( $text, $domain = null ) {
	return $text;
}

function sanitize_text_field( $str ) {
	return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $str ) ) );
}

function sanitize_key( $key ) {
	return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_title( $title ) {
	return trim( (string) preg_replace( '/[^a-z0-9_\-]+/', '-', strtolower( (string) $title ) ), '-' );
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['gx_options'] ) ? $GLOBALS['gx_options'][ $name ] : $default;
}

function update_option( $name, $value ) {
	$GLOBALS['gx_options'][ $name ] = $value;
	return true;
}

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['gx_hooks'][ $hook ][] = $callback;
	return true;
}

function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	return add_action( $hook, $callback, $priority, $args );
}

function apply_filters( $hook, $value ) {
	return $value;
}

/** `cap` or `cap:object_id` in `$GLOBALS['gx_caps']`. */
function current_user_can( $cap, ...$args ) {
	return in_array( $args ? $cap . ':' . $args[0] : $cap, $GLOBALS['gx_caps'], true );
}

/** `cap` or `cap:object_id` in `$GLOBALS['gx_user_caps'][ $user ]`. */
function user_can( $user, $cap, ...$args ) {
	return in_array( $args ? $cap . ':' . $args[0] : $cap, $GLOBALS['gx_user_caps'][ (int) $user ] ?? array(), true );
}

function rest_authorization_required_code() {
	return 403;
}

function rest_ensure_response( $data ) {
	return $data;
}

function get_woocommerce_currency_symbol() {
	return 'R$';
}

function wc_format_decimal( $number ) {
	if ( is_float( $number ) || is_int( $number ) ) {
		return rtrim( rtrim( sprintf( '%.6F', $number ), '0' ), '.' );
	}

	return (string) preg_replace( '/[^0-9\.\-]/', '', (string) $number );
}

function wc_clean( $value ) {
	return is_array( $value ) ? array_map( 'wc_clean', $value ) : ( is_scalar( $value ) ? sanitize_text_field( $value ) : $value );
}

function wp_unslash( $value ) {
	return $value;
}

function wp_verify_nonce( $nonce, $action ) {
	return 'ok' === $nonce ? 1 : false;
}

function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['gx_post_meta'][ $post_id ][ $key ] = $value;
	return true;
}

function delete_post_meta( $post_id, $key ) {
	unset( $GLOBALS['gx_post_meta'][ $post_id ][ $key ] );
	return true;
}

function register_post_meta( $post_type, $key, $args ) {
	$GLOBALS['gx_meta'][ $post_type ][ $key ] = $args;
	return true;
}

function delete_transient( $name ) {
	$GLOBALS['gx_deleted'][] = $name;
	return true;
}

/** No product categories: the Gift Wrap category fields just have no options. */
// The Gift Wrap settings page now says which candle sizes it found, which asks
// the packing engine, which caches. A schema request must survive that.
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

function get_transient( $key ) { return false; }
function set_transient( $key, $value, $ttl = 0 ) { return true; }
function taxonomy_exists( $taxonomy ) { return false; }
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args = array() ) { return array(); }
}

function get_terms( $args = array() ) {
	return array();
}

function register_term_meta( $taxonomy, $key, $args ) {
	$GLOBALS['gx_term_meta_args'][ $taxonomy ][ $key ] = $args;
	return true;
}

function update_term_meta( $term_id, $key, $value ) {
	$GLOBALS['gx_term_meta'][ (int) $term_id ][ $key ] = $value;
	return true;
}

function delete_term_meta( $term_id, $key ) {
	unset( $GLOBALS['gx_term_meta'][ (int) $term_id ][ $key ] );
	return true;
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function wp_nonce_field( $action, $name, $referer = true ) {
	echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="ok" />';
}

function wc_format_localized_decimal( $value ) {
	return (string) $value;
}

if ( ! function_exists( 'mb_substr' ) ) {
	function mb_substr( $string, $start, $length = null ) {
		return substr( $string, $start, $length );
	}
}

class WP_Error {

	public function __construct( private string $code = '', private string $message = '', private $data = null ) {}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

/** URL parameters, a JSON body (or null for none) and form fields. */
class WP_REST_Request implements ArrayAccess {

	public function __construct( private array $params = array(), private $json = null, private array $body = array() ) {}

	public function get_json_params() {
		return $this->json;
	}

	public function get_body_params() {
		return $this->body;
	}

	public function offsetExists( mixed $offset ): bool {
		return isset( $this->params[ $offset ] );
	}

	public function offsetGet( mixed $offset ): mixed {
		return $this->params[ $offset ] ?? null;
	}

	public function offsetSet( mixed $offset, mixed $value ): void {
		$this->params[ $offset ] = $value;
	}

	public function offsetUnset( mixed $offset ): void {
		unset( $this->params[ $offset ] );
	}
}

/** Counts shipping cache version bumps. */
class WC_Cache_Helper {

	public static function get_transient_version( $group, $refresh = false ) {
		if ( $refresh ) {
			++$GLOBALS['gx_cache_bumps'];
		}
		return '1';
	}
}
