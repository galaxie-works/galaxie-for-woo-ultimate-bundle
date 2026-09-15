<?php
/**
 * Just enough WordPress, WooCommerce and Elementor for `tests/boot/run.php` to
 * load the plugin and boot every module. Not WordPress: hooks are recorded and
 * never run, options come from the scenario, everything else answers plainly.
 *
 * A function the boot needs and this file lacks fails the runner with "Call
 * to undefined function" — add it here, as a no-op or a simple store.
 *
 * @package Galaxie\Woo
 */

// phpcs:disable

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

foreach (
	array(
		'WP_CONTENT_DIR'     => __DIR__,
		'WP_PLUGIN_DIR'      => __DIR__,
		'MINUTE_IN_SECONDS'  => 60,
		'HOUR_IN_SECONDS'    => 3600,
		'DAY_IN_SECONDS'     => 86400,
		'WEEK_IN_SECONDS'    => 604800,
		'MONTH_IN_SECONDS'   => 2592000,
		'YEAR_IN_SECONDS'    => 31536000,
		'COOKIEPATH'         => '/',
		'SITECOOKIEPATH'     => '/',
		'COOKIE_DOMAIN'      => '',
		'WC_VERSION'         => '10.9.3',
		'ELEMENTOR_VERSION'  => '3.30.0',
	) as $name => $value
) {
	if ( ! defined( $name ) ) {
		define( $name, $value );
	}
}

$GLOBALS['galaxie_boot'] = array(
	'options' => array(),
	'hooks'   => array(),
	'admin'   => false,
);

// ------------------------------------------------------------------- hooks

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['galaxie_boot']['hooks'][ $hook ][] = $callback;
	return true;
}

function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	return add_action( $hook, $callback, $priority, $args );
}

function remove_action( ...$args ) { return true; }
function remove_filter( ...$args ) { return true; }
function has_action( ...$args ) { return false; }
function has_filter( ...$args ) { return false; }
function did_action( $hook ) { return 0; }
function doing_action( ...$args ) { return false; }
function do_action( $hook, ...$args ) {}
function do_action_ref_array( $hook, $args ) {}
function apply_filters( $hook, $value, ...$args ) { return $value; }
function apply_filters_ref_array( $hook, $args ) { return $args[0] ?? null; }

/** Runs what was hooked on one action, as WordPress would at that point. */
function galaxie_boot_fire( string $hook, ...$args ): void {
	foreach ( $GLOBALS['galaxie_boot']['hooks'][ $hook ] ?? array() as $callback ) {
		call_user_func_array( $callback, $args );
	}
}

// ----------------------------------------------------------------- options

function get_option( $name, $default = false ) {
	return $GLOBALS['galaxie_boot']['options'][ $name ] ?? $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['galaxie_boot']['options'][ $name ] = $value;
	return true;
}

function add_option( $name, $value = '', ...$args ) { return update_option( $name, $value ); }
function delete_option( $name ) { unset( $GLOBALS['galaxie_boot']['options'][ $name ] ); return true; }
function get_site_option( $name, $default = false ) { return get_option( $name, $default ); }
function get_transient( $key ) { return false; }
function set_transient( ...$args ) { return true; }
function delete_transient( $key ) { return true; }
function wp_cache_get( ...$args ) { return false; }
function wp_cache_set( ...$args ) { return true; }
function wp_cache_delete( ...$args ) { return true; }

// ------------------------------------------------------------ environment

function is_admin() { return (bool) $GLOBALS['galaxie_boot']['admin']; }
function is_network_admin() { return false; }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }
function is_ssl() { return true; }
function is_multisite() { return false; }
function is_user_logged_in() { return false; }
function get_current_user_id() { return 0; }
function current_user_can( ...$args ) { return false; }
function wp_get_current_user() { return (object) array( 'ID' => 0, 'roles' => array() ); }
function is_rest() { return false; }
function wp_is_json_request() { return false; }
function get_locale() { return 'pt_BR'; }
function determine_locale() { return 'pt_BR'; }
function get_bloginfo( $show = '' ) { return 'charset' === $show ? 'UTF-8' : 'Eir Naturals'; }
/** As WordPress at plugins_loaded: WooCommerce's pa_* taxonomies exist only once init has run. */
function taxonomy_exists( $taxonomy ) {
	if ( 0 === strpos( (string) $taxonomy, 'pa_' ) ) {
		return ! empty( $GLOBALS['galaxie_boot']['init'] ) && 'pa_peso' === $taxonomy;
	}

	return in_array( $taxonomy, array( 'product_cat', 'product_tag' ), true );
}

/** WooCommerce reads these from its own table, available before init. */
function wc_get_attribute_taxonomy_names() { return array( 'pa_peso' ); }
function post_type_exists( $type ) { return in_array( $type, array( 'product', 'product_variation', 'shop_order' ), true ); }
function wp_next_scheduled( ...$args ) { return false; }
function wp_schedule_event( ...$args ) { return true; }
function wp_unschedule_event( ...$args ) { return true; }
function wp_clear_scheduled_hook( ...$args ) { return 0; }

// -------------------------------------------------------- paths and URLs

function plugin_dir_path( $file ) { return rtrim( str_replace( '\\', '/', dirname( $file ) ), '/' ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/'; }
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
function plugins_url( $path = '', $plugin = '' ) { return 'https://example.test/wp-content/plugins/' . ltrim( $path, '/' ); }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
function home_url( $path = '' ) { return 'https://example.test/' . ltrim( $path, '/' ); }
function site_url( $path = '' ) { return home_url( $path ); }
function rest_url( $path = '' ) { return home_url( 'wp-json/' . ltrim( $path, '/' ) ); }
function trailingslashit( $value ) { return rtrim( $value, '/\\' ) . '/'; }
function untrailingslashit( $value ) { return rtrim( $value, '/\\' ); }
function register_activation_hook( ...$args ) {}
function register_deactivation_hook( ...$args ) {}
function register_uninstall_hook( ...$args ) {}
function load_plugin_textdomain( ...$args ) { return true; }

// --------------------------------------------------- strings and escaping

function __( $text, $domain = 'default' ) { return $text; }
function _e( $text, $domain = 'default' ) { echo $text; }
function _x( $text, $context, $domain = 'default' ) { return $text; }
function _n( $single, $plural, $number, $domain = 'default' ) { return 1 === (int) $number ? $single : $plural; }
function esc_html__( $text, $domain = 'default' ) { return $text; }
function esc_attr__( $text, $domain = 'default' ) { return $text; }
function esc_html_e( $text, $domain = 'default' ) { echo $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_url( $url ) { return (string) $url; }
function esc_url_raw( $url ) { return (string) $url; }
function esc_js( $text ) { return (string) $text; }
function esc_textarea( $text ) { return esc_html( $text ); }
function wp_kses_post( $text ) { return (string) $text; }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function sanitize_text_field( $text ) { return trim( (string) $text ); }
function sanitize_title( $title ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $title ) ), '-' ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_unslash( $value ) { return $value; }
function wp_json_encode( $data, $options = 0 ) { return json_encode( $data, $options ); }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( (array) $defaults, (array) $args ); }
function wp_create_nonce( $action = -1 ) { return 'nonce'; }
function wp_salt( $scheme = 'auth' ) { return 'salt'; }

// ------------------------------------------------------------ registering

function add_shortcode( ...$args ) {}
function register_post_type( ...$args ) { return (object) array(); }
function register_taxonomy( ...$args ) { return true; }
function register_post_meta( ...$args ) { return true; }
function register_meta( ...$args ) { return true; }
function register_rest_route( ...$args ) { return true; }
function add_rewrite_rule( ...$args ) {}
function add_rewrite_tag( ...$args ) {}
function add_rewrite_endpoint( ...$args ) {}
function flush_rewrite_rules( ...$args ) {}
function wp_register_script( ...$args ) { return true; }
function wp_register_style( ...$args ) { return true; }
function wp_enqueue_script( ...$args ) {}
function wp_enqueue_style( ...$args ) {}
function wp_localize_script( ...$args ) { return true; }
function wp_add_inline_script( ...$args ) { return true; }
function wp_add_inline_style( ...$args ) { return true; }
function add_menu_page( ...$args ) { return 'galaxie'; }
function add_submenu_page( ...$args ) { return 'galaxie'; }

// ----------------------------------------------------------- WooCommerce

if ( ! class_exists( 'WooCommerce' ) ) {
	class WooCommerce {
		public $cart    = null;
		public $session = null;
	}
}

function WC() { static $wc = null; return $wc ??= new WooCommerce(); }
function wc_get_page_id( $page ) { return 0; }
function wc_get_page_permalink( $page ) { return home_url( $page ); }
function is_woocommerce() { return false; }
function is_product() { return false; }
function is_cart() { return false; }
function is_checkout() { return false; }
function is_account_page() { return false; }
function wc_get_product( $id ) { return false; }
