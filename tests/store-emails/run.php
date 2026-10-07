<?php
/**
 * Store e-mails tests: `php tests/store-emails/run.php` (exits 1 on any failure).
 *
 * Covers `Modules\StoreEmails` (Smartcodes, Sender, Module settings) and the
 * shared `Support\FluentCrmTemplate` on plain stubs — no WordPress, no
 * WooCommerce, no FluentCRM:
 *
 * - smartcode values and replacement: text and markup, `|default`, empty
 *   tracking, the url-encoded form in a link, `{{` in a shopper's note never
 *   resolved, FluentCRM's own `{{contact.*}}` from the order's billing data;
 * - gift masking: a shared wish list's gift shows "Presente para …" to the
 *   buyer and the whole address to the store; kit lines grouped under the
 *   kit with its card message;
 * - the send-time swap: subject and body replaced, Content-Type made HTML, a
 *   multipart text part from our HTML; untouched when the e-mail is not
 *   mapped, the template is gone or trashed, a synced pattern is missing,
 *   rendering throws, or FluentCRM is off;
 * - the merchant's exported template (fixtures/fc-template-993212.json):
 *   header/footer synced patterns rendered, `{{pedido.itens}}` replacing its
 *   paragraph, subject and preheader;
 * - OtpMail: renders and sends exactly what it did before the renderer was
 *   shared (compared with OtpMail as of 6f38232, read with `git show`).
 *
 * FluentCRM is stubbed the way 3.2.5 behaves where it matters here: its
 * BlockParser renders `core/block` refs from fc_meta `email_pattern` rows,
 * its parser resolves `{{group.key|default}}` through the group callbacks.
 *
 * @package Galaxie\Woo
 */

// phpcs:disable

namespace {

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['gx_hooks']    = array();
$GLOBALS['gx_posts']    = array();
$GLOBALS['gx_postmeta'] = array();
$GLOBALS['gx_options']  = array();
$GLOBALS['gx_patterns'] = array();
$GLOBALS['gx_contacts'] = array();
$GLOBALS['gx_mailer']   = array();
$GLOBALS['gx_wp_mail']  = array();
$GLOBALS['gx_errors']   = array();
$GLOBALS['gx_user']     = 0;
$GLOBALS['gx_caps']     = array();

function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return esc_html( $text ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return esc_html( $text ); }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['gx_hooks'][ $hook ][] = array( $callback, $priority, $args ); return true; }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { return add_filter( $hook, $callback, $priority, $args ); }
function remove_filter( $hook, $callback ) {
	foreach ( $GLOBALS['gx_hooks'][ $hook ] ?? array() as $i => $entry ) {
		if ( $entry[0] === $callback ) {
			unset( $GLOBALS['gx_hooks'][ $hook ][ $i ] );
		}
	}
	return true;
}
function apply_filters( $hook, $value, ...$args ) {
	$callbacks = $GLOBALS['gx_hooks'][ $hook ] ?? array();
	usort( $callbacks, static fn( $a, $b ) => $a[1] <=> $b[1] );
	foreach ( $callbacks as $callback ) {
		$value = call_user_func_array( $callback[0], array_slice( array_merge( array( $value ), $args ), 0, max( 1, $callback[2] ) ) );
	}
	return $value;
}
function do_action( ...$args ) {}
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) ); }
function sanitize_text_field( $text ) { return trim( strip_tags( (string) $text ) ); }
function absint( $n ) { return abs( (int) $n ); }
function wp_unslash( $value ) { return $value; }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( (array) $defaults, (array) $args ); }
function wp_strip_all_tags( $text, $breaks = false ) {
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
	return trim( strip_tags( $text ) );
}
function wp_specialchars_decode( $text, $quote = ENT_NOQUOTES ) { return htmlspecialchars_decode( (string) $text, ENT_QUOTES ); }
function get_option( $key, $default = false ) { return $GLOBALS['gx_options'][ $key ] ?? $default; }
function update_option( $key, $value ) { $GLOBALS['gx_options'][ $key ] = $value; return true; }
function get_bloginfo( $what ) { return 'EIR Naturals'; }
function home_url( $path = '' ) { return 'https://eirnaturals.shop' . $path; }
function admin_url( $path = '' ) { return 'https://eirnaturals.shop/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
function get_post( $id ) { return $GLOBALS['gx_posts'][ (int) $id ] ?? null; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['gx_postmeta'][ (int) $id ][ $key ] ?? ''; }
function get_posts( $args ) {
	return array_values( array_filter( $GLOBALS['gx_posts'], static fn( $p ) => $p->post_type === ( $args['post_type'] ?? '' ) && in_array( $p->post_status, (array) ( $args['post_status'] ?? array( 'publish' ) ), true ) ) );
}
function get_user_meta( $id, $key, $single = false ) { return ''; }
function get_userdata( $id ) { return 7 === (int) $id ? (object) array( 'first_name' => 'Ana' ) : false; }
function get_current_user_id() { return $GLOBALS['gx_user']; }
function current_user_can( $cap ) { return in_array( $cap, $GLOBALS['gx_caps'], true ); }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }
function did_action( $hook ) { return 0; }
function wp_mail( $to, $subject, $body, $headers = '', $attachments = array() ) {
	$GLOBALS['gx_wp_mail'][] = compact( 'to', 'subject', 'body' );
	return true;
}
function esc_url( $url ) { return esc_html( $url ); }
function esc_html_e( $text, $domain = null ) { echo esc_html( $text ); }
function esc_attr_e( $text, $domain = null ) { echo esc_html( $text ); }
function esc_attr__( $text, $domain = null ) { return esc_html( $text ); }
function selected( $a, $b ) { echo (string) $a === (string) $b ? ' selected' : ''; }
function wp_nonce_url( $url, $action ) { return $url . '&_wpnonce=n'; }
function wp_get_current_user() { return new WP_User(); }
function wc_price( $amount, $args = array() ) {
	return '<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">R&#36;</span>&nbsp;' . number_format( (float) $amount, 2, ',', '.' ) . '</bdi></span>';
}
function wc_format_datetime( $date ) { return $date->format( 'd/m/Y' ); }
function wc_get_order_status_name( $status ) { return array( 'processing' => 'Processando', 'pending' => 'Pagamento pendente', 'on-hold' => 'Aguardando' )[ $status ] ?? $status; }
function wc_get_page_permalink( $page ) { return 'https://eirnaturals.shop/minha-conta/'; }
function wc_get_endpoint_url( $endpoint, $value = '', $permalink = '' ) { return rtrim( $permalink, '/' ) . '/' . $endpoint . '/'; }

class WP_Post {
	public function __construct( public int $ID, public string $post_type, public string $post_status, public string $post_title, public string $post_content, public string $post_excerpt = '' ) {}
}

class WP_User {
	public $ID = 3;
	public $user_login = 'maria';
	public $user_email = 'maria@exemplo.com.br';
	public $first_name = 'Maria';
	public $last_name = 'Silva';
	public $display_name = 'maria';
}

class GX_Meta {
	public function __construct( public string $display_key, public string $display_value ) {}
}

class WC_Order_Item_Product {
	public array $meta = array();
	public function __construct( public string $name, public int $qty, public float $subtotal, array $meta = array() ) { $this->meta = $meta; }
	public function get_name() { return $this->name; }
	public function get_quantity() { return $this->qty; }
	public function get_subtotal() { return $this->subtotal; }
	public function get_subtotal_tax() { return 0.0; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function get_formatted_meta_data() {
		$out = array();
		foreach ( $this->meta as $key => $value ) {
			if ( '_' !== substr( $key, 0, 1 ) ) {
				$out[] = new GX_Meta( $key, '<p>' . $value . '</p>' );
			}
		}
		return $out;
	}
}

class WC_Order_Item_Shipping {
	public function __construct( public string $name, public array $meta = array() ) {}
	public function get_name() { return $this->name; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
}

class WC_Order_Refund {
	public function __construct( public float $amount ) {}
	public function get_amount() { return $this->amount; }
}

class WC_Order {
	public array $data = array(
		'id'                  => 1234,
		'customer_id'         => 3,
		'status'              => 'processing',
		'currency'            => 'BRL',
		'subtotal'            => 228.0,
		'shipping_total'      => 25.9,
		'shipping_tax'        => 0.0,
		'total_discount'      => 0.0,
		'total'               => 253.9,
		'total_refunded'      => 0.0,
		'payment_method_title' => 'Pix',
		'customer_note'       => '',
		'needs_payment'       => false,
		'billing_first_name'  => 'Maria',
		'billing_last_name'   => 'Silva',
		'billing_email'       => 'maria@exemplo.com.br',
		'billing_phone'       => '11988887777',
		'billing_address_1'   => 'Rua das Flores, 10',
		'billing_address_2'   => 'Centro',
		'billing_city'        => 'São Paulo',
		'billing_state'       => 'SP',
		'billing_postcode'    => '01001-000',
		'billing_country'     => 'BR',
		'shipping_first_name' => 'Maria',
		'shipping_last_name'  => 'Silva',
		'shipping_address_1'  => 'Rua das Flores, 10',
		'shipping_address_2'  => 'Centro',
		'shipping_city'       => 'São Paulo',
		'shipping_state'      => 'SP',
	);
	public array $meta = array();
	public array $items = array();
	public array $shipping = array();
	public function __call( $name, $args ) {
		$key = substr( $name, 4 );
		return $this->data[ $key ] ?? '';
	}
	public int $meta_saves = 0;
	public function get_id() { return $this->data['id']; }
	public function get_payment_method() { return $this->data['payment_method'] ?? ''; }
	public function has_status( $status ) { return in_array( $this->data['status'], (array) $status, true ); }
	public function is_paid() { return in_array( $this->data['status'], array( 'processing', 'completed' ), true ); }
	public function get_date_paid() { return $this->data['date_paid'] ?? null; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function save_meta_data() { $this->meta_saves++; }
	public function get_order_number() { return (string) $this->data['id']; }
	public function get_date_created() { return new DateTime( '2026-10-06 10:00:00' ); }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function get_items( $type = 'line_item' ) { return 'shipping' === $type ? $this->shipping : $this->items; }
	public function needs_payment() { return $this->data['needs_payment']; }
	public function get_checkout_payment_url() { return 'https://eirnaturals.shop/finalizar-compra/order-pay/1234/?pay_for_order=true&key=wc_order_abc'; }
	public function get_view_order_url() { return 'https://eirnaturals.shop/minha-conta/view-order/1234/'; }
	public function get_checkout_order_received_url() { return 'https://eirnaturals.shop/finalizar-compra/order-received/1234/?key=wc_order_abc'; }
	public function get_edit_order_url() { return 'https://eirnaturals.shop/wp-admin/admin.php?page=wc-orders&action=edit&id=1234'; }
	private function formatted( string $type ): string {
		$d = $this->data;
		return implode( '<br/>', array_filter( array( esc_html( $d[ $type . '_first_name' ] . ' ' . $d[ $type . '_last_name' ] ), esc_html( $d[ $type . '_address_1' ] ), esc_html( $d[ $type . '_address_2' ] ), esc_html( $d[ $type . '_city' ] . '/' . $d[ $type . '_state' ] ) ) ) );
	}
	public function get_formatted_shipping_address( $empty = '' ) {
		return apply_filters( 'woocommerce_order_get_formatted_shipping_address', $this->formatted( 'shipping' ), array(), $this );
	}
	public function get_formatted_billing_address( $empty = '' ) { return $this->formatted( 'billing' ); }
}

class WC_Email {
	public $id;
	public $object;
	public $email_type = 'html';
	public $refund;
	public $partial_refund;
	public $customer_note;
	public $reset_key;
	public $user_login;
	public $set_password_url;
	public function __construct( string $id, public bool $customer = true ) { $this->id = $id; }
	public function is_customer_email() { return $this->customer; }
	public function get_email_type() { return $this->email_type; }
	public function is_enabled() { return true; }
}

class GX_PixEmail extends WC_Email {
	public array $sent = array();
	public function trigger( $order_id, $order = false ) { $this->sent[] = (int) $order_id; }
}

final class GX_Mailer {
	public function __construct( public array $emails ) {}
	public function get_emails() { return $this->emails; }
}

final class GX_WC {
	public function __construct( public GX_Mailer $mailer ) {}
	public function mailer() { return $this->mailer; }
}

function WC() { return $GLOBALS['gx_wc']; }
function wc_get_order( $id ) { return $GLOBALS['gx_orders'][ (int) $id ] ?? false; }
function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '' ) { $GLOBALS['gx_scheduled'][] = array( $hook, $args, $group, $timestamp - time() ); return 1; }

class GX_PHPMailer {
	public $AltBody = 'woocommerce plain text';
}

}

// ------------------------------------------------------------- FluentCRM stubs

/** The shared renderer's log line, caught (an unqualified call resolves here first). */
namespace Galaxie\Woo\Support {
	function error_log( $message ) { $GLOBALS['gx_errors'][] = $message; return true; }
}

namespace FluentCrm\App\Services {
	class Helper {
		public static function getTemplateConfig( $design = '' ) {
			return array( 'text_color' => '#202020', 'content_font_family' => 'Arial, sans-serif', 'paragraph_font_size' => '16px', 'body_bg_color' => '#FAFAFA', 'link_color' => '#0693e3' );
		}
		public static function getMailHeader() { return array( 'From: EIR <contato@eirnaturals.shop>' ); }
		public static function maybeDisableEmojiOnEmail() {}
	}

	/** 3.2.5's parser where it matters: synced patterns from fc_meta, comments gone. */
	class BlockParser {
		public function __construct( $subscriber = null ) {}
		public function parse( $content ) {
			if ( false !== strpos( $content, 'GX_THROW' ) ) {
				throw new \RuntimeException( 'parser failed' );
			}
			$content = preg_replace_callback(
				'~<!--\s+wp:block\s+(\{.*?\})\s*/?-->~',
				static function ( $m ) {
					$ref = (int) ( json_decode( $m[1], true )['ref'] ?? 0 );
					return $GLOBALS['gx_patterns'][ $ref ] ?? '';
				},
				$content
			);
			return trim( preg_replace( '~<!--.*?-->~s', '', $content ) );
		}
	}
}

namespace FluentCrm\App\Services\Libs\Mailer {
	class Mailer {
		public static function send( $data, $subscriber = null, $email = null, $force = false ) {
			$GLOBALS['gx_mailer'][] = $data;
			return true;
		}
	}
}

namespace FluentCrm\App\Models {
	class GX_Query {
		public array $where = array();
		public function __construct( private string $model ) {}
		public function where( $key, $value ) { $this->where[ $key ] = $value; return $this; }
		public function first() {
			if ( Meta::class === $this->model ) {
				$id = (int) ( $this->where['id'] ?? 0 );
				return isset( $GLOBALS['gx_patterns'][ $id ] ) ? (object) array( 'value' => array( 'content' => $GLOBALS['gx_patterns'][ $id ] ) ) : null;
			}
			$email = $this->where['email'] ?? '';
			return isset( $GLOBALS['gx_contacts'][ $email ] ) ? new Subscriber( $GLOBALS['gx_contacts'][ $email ] ) : null;
		}
	}
	class Subscriber {
		public function __construct( public array $attributes = array() ) {}
		public function __get( $key ) { return $this->attributes[ $key ] ?? null; }
		public static function where( $key, $value ) { return ( new GX_Query( self::class ) )->where( $key, $value ); }
	}
	class Meta {
		public static function where( $key, $value ) { return ( new GX_Query( self::class ) )->where( $key, $value ); }
	}
}

// ------------------------------------------------------------------- runner

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

/** FluentCRM's parser: {{contact.x|d}} from the subscriber, other groups through their callbacks. */
add_filter(
	'fluent_crm/parse_campaign_email_text',
	static function ( $text, $subscriber = null ) {
		return preg_replace_callback(
			'/\{\{\s*([a-z_]+)\.([a-z_]+)(?:\|([^}]*))?\s*\}\}/',
			static function ( $m ) use ( $subscriber ) {
				$default = $m[3] ?? '';
				if ( 'contact' === $m[1] ) {
					$value = $subscriber ? (string) $subscriber->{$m[2]} : '';
					return '' !== $value ? $value : $default;
				}
				return apply_filters( 'fluent_crm/smartcode_group_callback_' . $m[1], $m[0], $m[2], $default, $subscriber );
			},
			(string) $text
		);
	},
	10,
	2
);

/** FluentCRM's "simple" design wrapper, reduced: preheader, then the body in the text colour. */
foreach ( array( 'simple', 'raw_html' ) as $design ) {
	add_filter(
		'fluent_crm/email-design-template-' . $design,
		static fn( $body, $args ) => '<html><body style="background:' . ( $args['config']['body_bg_color'] ?? '' ) . '"><span class="preheader">' . $args['preHeader'] . '</span><div style="color:' . $args['config']['text_color'] . '">' . $body . '</div>' . ( 'yes' === ( $args['footer_config']['disable_footer'] ?? '' ) ? '' : '<footer>unsubscribe</footer>' ) . '</body></html>',
		10,
		4
	);
}

use Galaxie\Woo\Modules\GiftWrap\Groups;
use Galaxie\Woo\Modules\StoreEmails\Module;
use Galaxie\Woo\Modules\StoreEmails\PixPending;
use Galaxie\Woo\Modules\StoreEmails\Sender;
use Galaxie\Woo\Modules\StoreEmails\Smartcodes;
use Galaxie\Woo\Modules\Wishlist\Gifts;
use Galaxie\Woo\Support\FluentCrmTemplate;

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
$has = static fn( string $haystack, string $needle ): bool => false !== strpos( $haystack, $needle );

$template = static function ( int $id, string $content, string $subject = '', string $excerpt = '', string $status = 'publish', string $design = 'simple' ): void {
	$GLOBALS['gx_posts'][ $id ]    = new WP_Post( $id, 'fc_template', $status, 'T' . $id, $content, $excerpt );
	$GLOBALS['gx_postmeta'][ $id ] = array( '_email_subject' => $subject, '_design_template' => $design, '_template_config' => array() );
};

$map = static function ( array $rows, array $extra = array() ): void {
	$GLOBALS['gx_options']['galaxie_woo_settings'] = array( Module::ID => array( Module::EMAILS => $rows ) + $extra );
};

$order = static function (): WC_Order {
	$order        = new WC_Order();
	$order->items = array(
		new WC_Order_Item_Product( 'Vela Nordic', 1, 89.0, array( 'Tamanho' => '180g' ) ),
	);
	$order->shipping = array( new WC_Order_Item_Shipping( 'PAC', array( 'delivery_time' => ' (3 a 5 dias úteis)' ) ) );
	return $order;
};

Gifts::order_hooks();
Smartcodes::hooks();
Sender::hooks();

echo "\nFluentCRM off\n";

$template( 10, '<!-- wp:paragraph --><p>Pedido {{pedido.numero}}</p><!-- /wp:paragraph -->', 'Pedido {{pedido.numero}}' );
$map( array( array( 'email' => 'customer_processing_order', 'template' => '10', 'subject' => '' ) ) );
$params = array( 'maria@exemplo.com.br', 'Seu pedido', '<p>WooCommerce</p>', "Content-Type: text/html\r\n", array() );
$email  = new WC_Email( 'customer_processing_order' );
$email->object = $order();
$check( 'FluentCRM not loaded: the WooCommerce e-mail goes as it is', Sender::swap( $params, $email ), $params );
$check( 'FluentCRM not loaded: no templates to choose from', FluentCrmTemplate::templates(), array() );

define( 'FLUENTCRM', '3.2.5' );

echo "\nSmartcode values\n";

$o      = $order();
$values = Smartcodes::values( array( 'order' => $o, 'email' => new WC_Email( 'customer_processing_order' ), 'settings' => array( 'loja_whatsapp' => '(11) 90000-0000' ) ) );
$p      = $values['pedido'];
$check( 'numero', $p['numero'], '1234' );
$check( 'data', $p['data'], '06/10/2026' );
$check( 'status', $p['status'], 'Processando' );
$check( 'cliente_nome', $p['cliente_nome'], 'Maria' );
$check( 'cliente_nome_completo', $p['cliente_nome_completo'], 'Maria Silva' );
$check( 'subtotal as text', $p['subtotal'], "R$\u{00A0}228,00" );
$check( 'frete', $p['frete'], "R$\u{00A0}25,90" );
$check( 'desconto empty without a discount', $p['desconto'], '' );
$check( 'total', $p['total'], "R$\u{00A0}253,90" );
$check( 'forma_pagamento', $p['forma_pagamento'], 'Pix' );
$check( 'forma_entrega with Melhor Envio delivery_time', $p['forma_entrega'], 'PAC (3 a 5 dias úteis)' );
$check( 'endereco_entrega, one line each', $p['endereco_entrega'], 'Maria Silva<br>Rua das Flores, 10<br>Centro<br>São Paulo/SP' );
$check( 'link_pedido to the account', $p['link_pedido'], 'https://eirnaturals.shop/minha-conta/view-order/1234/' );
$check( 'link_pagamento empty when paid', $p['link_pagamento'], '' );
$check( 'rastreio_codigo empty without tracking', $p['rastreio_codigo'], '' );
$check( 'rastreio_link empty without tracking', $p['rastreio_link'], '' );
$check( 'presente_para empty: not a gift', $p['presente_para'], '' );
$check( 'conta.nome from the order', $values['conta']['nome'], 'Maria' );
$check( 'conta.link_minha_conta', $values['conta']['link_minha_conta'], 'https://eirnaturals.shop/minha-conta/' );
$check( 'loja.whatsapp from settings', $values['loja']['whatsapp'], '(11) 90000-0000' );
$check( 'loja.cnpj default', $values['loja']['cnpj'], '48.548.856/0001-22' );
$check( 'loja.endereco default', $values['loja']['endereco'], 'Rua Giovanni Thomé, 223, São Caetano do Sul/SP' );

$o2 = $order();
$o2->data['shipping_total'] = 0.0;
$o2->data['total_discount'] = 10.0;
$o2->data['needs_payment']  = true;
$o2->data['customer_id']    = 0;
$o2->meta['melhorenvio_status_v2'] = array( 'order_id' => 'abc', 'tracking' => 'ME123BR' );
$p2 = Smartcodes::values( array( 'order' => $o2, 'email' => new WC_Email( 'customer_invoice' ) ) )['pedido'];
$check( 'frete "Grátis" at zero', $p2['frete'], 'Grátis' );
$check( 'desconto', $p2['desconto'], "R$\u{00A0}10,00" );
$check( 'link_pagamento when the order needs payment', $p2['link_pagamento'], 'https://eirnaturals.shop/finalizar-compra/order-pay/1234/?pay_for_order=true&key=wc_order_abc' );
$check( 'link_pedido for a guest: order-received', $p2['link_pedido'], 'https://eirnaturals.shop/finalizar-compra/order-received/1234/?key=wc_order_abc' );
$check( 'rastreio_codigo from melhorenvio_status_v2', $p2['rastreio_codigo'], 'ME123BR' );
$check( 'rastreio_link to Melhor Rastreio', $p2['rastreio_link'], 'https://melhorrastreio.com.br/rastreio/ME123BR' );

$o2->meta['melhorenvio_tracking'] = 'QB987BR';
$check( 'melhorenvio_tracking wins over the status array', Smartcodes::tracking( $o2 )['code'], 'QB987BR' );

$p3 = Smartcodes::values( array( 'order' => $order(), 'email' => new WC_Email( 'new_order', false ) ) )['pedido'];
$check( 'link_pedido to the store: wp-admin', $p3['link_pedido'], 'https://eirnaturals.shop/wp-admin/admin.php?page=wc-orders&action=edit&id=1234' );

$refunded = $order();
$refunded->data['total_refunded'] = 80.0;
$check( 'valor_reembolsado: the order total refunded', Smartcodes::values( array( 'order' => $refunded ) )['pedido']['valor_reembolsado'], "R$\u{00A0}80,00" );
$check( 'valor_reembolsado: this partial refund', Smartcodes::values( array( 'order' => $refunded, 'refund' => new WC_Order_Refund( -30.0 ), 'partial' => true ) )['pedido']['valor_reembolsado'], "R$\u{00A0}30,00" );
$check( 'nota_cliente: the note being sent', Smartcodes::values( array( 'order' => $order(), 'note' => 'Sai amanhã' ) )['pedido']['nota_cliente'], 'Sai amanhã' );

echo "\nGift masking\n";

$gift                                = $order();
$gift->meta[ Gifts::ORDER_OWNER ]    = 7;
$gift->data['shipping_first_name']   = 'Ana';
$gift->data['shipping_last_name']    = 'Souza';
$gift->data['shipping_address_1']    = 'Rua Secreta, 42';
$gift->data['shipping_address_2']    = 'Apto 9';
$gift->data['shipping_city']         = 'Curitiba';
$gift->data['shipping_state']        = 'PR';
$to_buyer = Smartcodes::values( array( 'order' => $gift, 'email' => new WC_Email( 'customer_processing_order', true ) ) )['pedido'];
$to_store = Smartcodes::values( array( 'order' => $gift, 'email' => new WC_Email( 'new_order', false ) ) )['pedido'];
$check( 'gift, e-mail to the buyer: masked', $to_buyer['endereco_entrega'], 'Presente para Ana — Curitiba/PR' );
$check( 'gift, e-mail to the buyer: no street anywhere', $has( implode( ' ', array_filter( $to_buyer, 'is_string' ) ), 'Secreta' ), false );
$check( 'gift, e-mail to the store: whole address', $to_store['endereco_entrega'], 'Ana Souza<br>Rua Secreta, 42<br>Apto 9<br>Curitiba/PR' );
$check( 'presente_para: the list owner', $to_buyer['presente_para'], 'Ana' );
$check( 'masking state put back after the e-mail', Gifts::reveals_address( $gift ), false );

$wrap                                       = $order();
$wrap->meta['_galaxie_is_gift']             = 'yes';
$wrap->data['shipping_first_name']          = 'João';
$check( 'presente_para: a Gift Wrap gift to someone else', Smartcodes::values( array( 'order' => $wrap ) )['pedido']['presente_para'], 'João' );
$wrap->data['shipping_first_name'] = 'Maria';
$check( 'presente_para: empty when the buyer ships to themselves', Smartcodes::values( array( 'order' => $wrap ) )['pedido']['presente_para'], '' );

echo "\nKit grouping\n";

$kit        = $order();
$kit->items = array(
	new WC_Order_Item_Product( 'Sabonete', 2, 40.0 ),
	new WC_Order_Item_Product( 'Vela Lavanda', 1, 89.0, array( Groups::ITEM_GROUP => 'g1', Groups::ITEM_ROLE => 'candle', Groups::ITEM_NUMBER => 1, Groups::ITEM_NAME => 'Kit Lavanda', 'Kit Lavanda' => 'Vela', 'Tamanho' => '180g' ) ),
	new WC_Order_Item_Product( 'Caixa P', 1, 15.0, array( Groups::ITEM_GROUP => 'g1', Groups::ITEM_ROLE => 'box', Groups::ITEM_NUMBER => 1, Groups::ITEM_NAME => 'Kit Lavanda', 'Kit Lavanda' => 'Caixa' ) ),
	new WC_Order_Item_Product( 'Cartão', 1, 5.0, array( Groups::ITEM_GROUP => 'g1', Groups::ITEM_ROLE => 'card', Groups::ITEM_NUMBER => 1, Groups::ITEM_NAME => 'Kit Lavanda', 'Kit Lavanda' => 'Cartão', Groups::ITEM_MESSAGE => 'Feliz aniversário <3', 'Mensagem' => 'Feliz aniversário <3' ) ),
	new WC_Order_Item_Product( 'Vela Cedro', 1, 79.0 ),
);
$blocks = Smartcodes::item_blocks( $kit );
$check( 'blocks: loose, kit, loose', array_column( $blocks, 'title' ), array( '', 'Kit Lavanda', '' ) );
$check( 'kit lines under the kit', array_column( $blocks[1]['items'], 'name' ), array( 'Vela Lavanda', 'Caixa P', 'Cartão' ) );
$check( 'kit card message', $blocks[1]['messages'], array( 'Feliz aniversário <3' ) );
$check( 'variation kept, kit meta hidden', $blocks[1]['items'][0]['details'], array( 'Tamanho: 180g' ) );
$table = Smartcodes::items_table( $kit, array( 'text_color' => '#123456' ), true );
$check( 'table in the template text colour', $has( $table, 'color:#123456' ), true );
$check( 'card message escaped in the table', $has( $table, 'Mensagem do cartão: “Feliz aniversário &lt;3”' ), true );
$check( 'totals footer: subtotal, frete with method, total, payment', array( $has( $table, '<tfoot>' ), $has( $table, 'Subtotal' ), $has( $table, 'PAC (3 a 5 dias úteis)' ), $has( $table, 'Forma de pagamento' ) ), array( true, true, true, true ) );
$check( 'no Desconto row without a discount', $has( $table, 'Desconto' ), false );
$check( 'itens_sem_totais: no footer', $has( Smartcodes::items_table( $kit, array(), false ), '<tfoot>' ), false );
$check( 'mensagem_cartao', Smartcodes::values( array( 'order' => $kit ) )['pedido']['mensagem_cartao'], 'Feliz aniversário &lt;3' );

echo "\nReplacement\n";

$codes = Smartcodes::codes( array( 'order' => $order(), 'email' => new WC_Email( 'customer_processing_order' ) ) );
$template(
	20,
	'<!-- wp:paragraph --><p>Olá {{contact.first_name}} / {{pedido.cliente_nome}}</p><!-- /wp:paragraph -->'
	. '<p>Rastreio: {{pedido.rastreio_codigo|ainda não disponível}} — {{pedido.rastreio_link}}.</p>'
	. '<p>{{pedido.itens}}</p>'
	. '<p>{{pedido.endereco_entrega}}</p>'
	. '<p><a href="%7B%7Bpedido.link_pedido%7D%7D">ver</a> {{pedido.desconhecido|x}} {{loja.nome}}</p>',
	'Pedido #{{pedido.numero}} {{pedido.itens}}',
	'Obrigado, {{pedido.cliente_nome}}'
);
$r = FluentCrmTemplate::render( 20, '', $codes, FluentCrmTemplate::subscriber( 'maria@exemplo.com.br', array( 'first_name' => 'Maria' ) ) );
$check( 'contact smartcode from billing for a non-contact', $has( $r['html'], 'Olá Maria / Maria' ), true );
$check( '|default for empty tracking', $has( $r['html'], 'Rastreio: ainda não disponível — .' ), true );
$check( 'items table replaces its paragraph', array( $has( $r['html'], '<p><table' ), $has( $r['html'], '<table role="presentation"' ) ), array( false, true ) );
$check( 'address stays in its paragraph', $has( $r['html'], '<p>Maria Silva<br>Rua das Flores, 10<br>Centro<br>São Paulo/SP</p>' ), true );
$check( 'url-encoded smartcode in a link', $has( $r['html'], 'href="https://eirnaturals.shop/minha-conta/view-order/1234/"' ), true );
$check( 'unknown key left to FluentCRM, its default', $has( $r['html'], '</a> x EIR Naturals' ), true );
$check( 'subject: markup value as text', $r['subject'], "Pedido #1234 Produto Qtd. Total; Vela Nordic, Tamanho: 180g 1 R$ 89,00; Subtotal R$ 228,00; Frete, PAC (3 a 5 dias úteis) R$ 25,90; Total R$ 253,90; Forma de pagamento Pix" );
$check( 'preheader', $has( $r['html'], '<span class="preheader">Obrigado, Maria</span>' ), true );
$check( 'no footer, no tracking', array( $has( $r['html'], 'unsubscribe' ), $has( $r['html'], 'gxsc' ) ), array( false, false ) );

// FluentCRM 3.2.5's GutenbergEmailParser draws a paragraph as `<p id="">…</p>` in a table cell.
$check(
	'a table alone in FluentCRM\'s <p id=""> takes its place',
	FluentCrmTemplate::replace( 'pedido', '<td><p id="">{{pedido.itens}}</p></td>', array( 'itens' => '<table></table>' ), true, array( 'itens' ), array( 'itens' ) ),
	'<td><table></table></td>'
);
$check(
	'a table beside text stays where it is',
	FluentCrmTemplate::replace( 'pedido', '<p>Itens: {{pedido.itens}}</p>', array( 'itens' => '<table></table>' ), true, array( 'itens' ), array( 'itens' ) ),
	'<p>Itens: <table></table></p>'
);

$sneaky = $order();
$sneaky->data['customer_note'] = '<b>oi</b> {{contact.email}}';
$template( 21, '<p>{{pedido.nota_cliente}}</p>' );
$r = FluentCrmTemplate::render( 21, '', Smartcodes::codes( array( 'order' => $sneaky ) ), FluentCrmTemplate::subscriber( 'maria@exemplo.com.br' ) );
$check( 'a note is escaped and its {{ never resolved', $has( $r['html'], '&lt;b&gt;oi&lt;/b&gt; { {contact.email} }' ), true );

$GLOBALS['gx_contacts']['maria@exemplo.com.br'] = array( 'email' => 'maria@exemplo.com.br', 'first_name' => 'Mariazinha' );
$r = FluentCrmTemplate::render( 20, '', $codes, FluentCrmTemplate::subscriber( 'maria@exemplo.com.br', array( 'first_name' => 'Maria' ) ) );
$check( 'an existing contact is used as it is', $has( $r['html'], 'Olá Mariazinha' ), true );
unset( $GLOBALS['gx_contacts']['maria@exemplo.com.br'] );

echo "\nSend-time swap\n";

$headers = "Content-Type: text/plain\r\nReply-to: EIR <contato@eirnaturals.shop>\r\n";
$params  = array( 'maria@exemplo.com.br', 'Seu pedido foi recebido', '<p>WooCommerce</p>', $headers, array( '/tmp/nf.pdf' ) );
$map(
	array(
		array( 'email' => 'customer_processing_order', 'template' => '10', 'subject' => '' ),
		array( 'email' => 'customer_completed_order', 'template' => '10', 'subject' => 'Enviado: #{{pedido.numero}} {{pedido.rastreio_codigo|sem código}}' ),
		array( 'email' => 'customer_refunded_order', 'template' => '10', 'subject' => 'Reembolso #{{pedido.numero}}' ),
		array( 'email' => 'customer_on_hold_order', 'template' => '999', 'subject' => '' ),
		array( 'email' => 'customer_invoice', 'template' => '30', 'subject' => '' ),
		array( 'email' => 'customer_cancelled_order', 'template' => '31', 'subject' => '' ),
		array( 'email' => 'customer_failed_order', 'template' => '32', 'subject' => '' ),
		array( 'email' => 'customer_reset_password', 'template' => '33', 'subject' => '' ),
	)
);

$email                = new WC_Email( 'customer_processing_order' );
$email->email_type    = 'multipart';
$email->object        = $order();
$out = Sender::swap( $params, $email );
$check( 'mapped: recipient and attachments untouched', array( $out[0], $out[4] ), array( $params[0], $params[4] ) );
$check( 'mapped: subject from the template', $out[1], 'Pedido 1234' );
$check( 'mapped: body from the template', $has( $out[2], '<p>Pedido 1234</p>' ) && ! $has( $out[2], 'WooCommerce' ), true );
$check( 'mapped: Content-Type made text/html, Reply-to kept', $out[3], "Content-Type: text/html\r\nReply-to: EIR <contato@eirnaturals.shop>\r\n" );
$check( 'mapped: wp_mail asks the e-mail its type: text/html', apply_filters( 'woocommerce_email_content_type', 'multipart/alternative', $email ), 'text/html' );
$check( 'mapped: other e-mails keep their type', apply_filters( 'woocommerce_email_content_type', 'text/plain', new WC_Email( 'x' ) ), 'text/plain' );
$mailer = new GX_PHPMailer();
Sender::alt_body( $mailer );
$check( 'multipart: text part from our HTML', $mailer->AltBody, 'Pedido 1234' );
Sender::sent();
$check( 'after sending: nothing of ours in effect', apply_filters( 'woocommerce_email_content_type', 'text/plain', $email ), 'text/plain' );

$email         = new WC_Email( 'customer_completed_order' );
$email->object = $order();
$check( 'subject override with smartcodes and |default', Sender::swap( $params, $email )[1], 'Enviado: #1234 sem código' );

$email                 = new WC_Email( Module::PARTIAL_REFUND );
$email->object         = $order();
$email->partial_refund = true;
$check( 'partial refund falls back to the full refund row', Sender::swap( $params, $email )[1], 'Reembolso #1234' );

$email         = new WC_Email( 'customer_note' );
$email->object = $order();
$check( 'not mapped: params untouched', Sender::swap( $params, $email ), $params );

$email         = new WC_Email( 'customer_on_hold_order' );
$email->object = $order();
$check( 'template deleted: the WooCommerce e-mail goes', Sender::swap( $params, $email ), $params );

$template( 30, '<p>x {{pedido.numero}}</p>', '', '', 'trash' );
$email         = new WC_Email( 'customer_invoice' );
$email->object = $order();
$check( 'template in the trash: the WooCommerce e-mail goes', Sender::swap( $params, $email ), $params );

$template( 31, '<!-- wp:block {"ref":77} /--><p>{{pedido.numero}}</p>' );
$email         = new WC_Email( 'customer_cancelled_order' );
$email->object = $order();
$check( 'synced pattern missing: the WooCommerce e-mail goes', Sender::swap( $params, $email ), $params );
$check( 'synced pattern missing: logged', $has( implode( "\n", $GLOBALS['gx_errors'] ), 'template #31 uses synced pattern(s) 77' ), true );

$template( 32, '<p>GX_THROW {{pedido.numero}}</p>' );
$email         = new WC_Email( 'customer_failed_order' );
$email->object = $order();
$check( 'rendering throws: the WooCommerce e-mail goes', Sender::swap( $params, $email ), $params );

$template( 33, '<p>Oi {{conta.nome}}: {{conta.link_redefinir_senha}}</p>', 'Senha' );
$email             = new WC_Email( 'customer_reset_password' );
$email->object     = new WP_User();
$email->user_login = 'maria';
$email->reset_key  = 'k3y';
$out               = Sender::swap( $params, $email );
$check( 'reset password: link as WooCommerce prints it', $has( $out[2], 'Oi Maria: https://eirnaturals.shop/minha-conta/lost-password/?key=k3y&amp;id=3&amp;login=maria' ), true );
Sender::sent();

echo "\nThe merchant's exported template (post 993212)\n";

$export = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/fc-template-993212.json' ), true );
$check( 'fixture: a FluentCRM export', array( $export['is_fc_template'], $export['design_template'], $export['settings']['template_config']['disable_footer'] ), array( 'yes', 'simple', 'yes' ) );
$GLOBALS['gx_posts'][993212]    = new WP_Post( 993212, 'fc_template', 'publish', $export['post_title'], $export['post_content'], $export['post_excerpt'] );
$GLOBALS['gx_postmeta'][993212] = array( '_email_subject' => $export['email_subject'], '_design_template' => $export['design_template'], '_template_config' => $export['settings']['template_config'] + array( 'text_color' => '#3a3a3a' ) );
$GLOBALS['gx_patterns'][12]     = '<!-- wp:image --><figure><img src="https://eirnaturals.shop/logo.png" alt="EIR"/></figure><!-- /wp:image -->';
$GLOBALS['gx_patterns'][14]     = '<!-- wp:paragraph --><p>{{loja.nome}} · CNPJ {{loja.cnpj}} · {{loja.endereco}}</p><!-- /wp:paragraph -->';
$GLOBALS['gx_postmeta'][993212]['_template_config']['text_color'] = '#3a3a3a';

$map( array( array( 'email' => 'customer_on_hold_order', 'template' => '993212', 'subject' => '' ) ), array( 'loja_cnpj' => '48.548.856/0001-22' ) );
$email         = new WC_Email( 'customer_on_hold_order' );
$email->object = $kit;
$out           = Sender::swap( $params, $email );
$html          = $out[2];
$check( 'subject from email_subject with smartcodes', $out[1], 'Recebemos seu pedido #1234 na EIR Naturals.' );
$check( 'preheader from post_excerpt', $has( $html, '<span class="preheader">Obrigado por comprar na EIR Naturals.</span>' ), true );
$check( 'header pattern (ref 12) rendered', $has( $html, 'logo.png' ), true );
$check( 'footer pattern (ref 14) smartcodes through the callbacks', $has( $html, 'EIR Naturals · CNPJ 48.548.856/0001-22 · Rua Giovanni Thomé, 223, São Caetano do Sul/SP' ), true );
$check( 'greeting', $has( $html, 'Olá, Maria</h4>' ), true );
$check( '{{pedido.itens}} paragraph replaced by the table', array( $has( $html, '<p><table' ), substr_count( $html, '<table role="presentation"' ) ), array( false, 1 ) );
$check( 'table in the template text colour (template_config)', $has( $html, 'color:#3a3a3a' ), true );
$check( 'kit grouped in the merchant\'s template', $has( $html, '>Kit Lavanda</td>' ), true );
$check( 'shipping address in its paragraph', $has( $html, '<p>Maria Silva<br>Rua das Flores, 10<br>Centro<br>São Paulo/SP</p>' ), true );
$check( 'no smartcode or token left', array( $has( $html, '{{' ), $has( $html, 'gxsc' ) ), array( false, false ) );
$check( 'footer disabled', $has( $html, 'unsubscribe' ), false );
Sender::sent();

unset( $GLOBALS['gx_patterns'][14] );
$check( 'merchant template without its footer pattern: default e-mail', Sender::swap( $params, $email ), $params );
$GLOBALS['gx_patterns'][14] = '<p>rodapé</p>';

echo "\nPix aguardando pagamento\n";

$pix = static function ( string $intent = 'pi_1' ): WC_Order {
	$o                           = new WC_Order();
	$o->data['status']           = 'pending';
	$o->data['payment_method']   = 'fkwcs_stripe_pix';
	$o->data['needs_payment']    = true;
	$o->meta['_fkwcs_intent_id'] = array( 'id' => $intent, 'client_secret' => 'secret' );
	$GLOBALS['gx_orders'][ $o->get_id() ] = $o;
	return $o;
};
$GLOBALS['gx_scheduled'] = array();

$o = $pix();
PixPending::attempt( array( 'result' => 'success' ), 1234 );
$check( 'first attempt: e-mail scheduled once, a minute later, by Action Scheduler', $GLOBALS['gx_scheduled'], array( array( PixPending::SEND_HOOK, array( 1234 ), 'galaxie-woo', 60 ) ) );
$check( 'first attempt: claimed on the order', array( $o->meta[ PixPending::META ]['intent'], $o->meta[ PixPending::META ]['count'], $o->meta_saves ), array( 'pi_1', 1, 1 ) );
PixPending::attempt( array( 'result' => 'success' ), 1234 );
$check( 'a retry right after: nothing more scheduled', count( $GLOBALS['gx_scheduled'] ), 1 );

$t0 = 1791288000; // 2026-10-06 12:00 UTC.
$at = static function ( WC_Order $o, string $intent, int $at, int $count = 1 ): void {
	$o->meta[ PixPending::META ] = array( 'intent' => $intent, 'at' => $at, 'count' => $count );
};
$o = $pix( 'pi_1' );
$at( $o, 'pi_1', $t0 );
$check( 'same intent 10 min later: no', PixPending::due( $o, $t0 + 600 ), false );
$o->meta['_fkwcs_intent_id'] = array( 'id' => 'pi_2' );
$check( 'new intent 20 min later (retry guard): no', PixPending::due( $o, $t0 + 1200 ), false );
$check( 'new intent 2 h later, first QR code still valid: no', PixPending::due( $o, $t0 + 7200 ), false );
$check( 'new intent after the first QR code expired: yes', PixPending::due( $o, $t0 + 86400 + 60 ), true );
$o->meta['_fkwcs_intent_id'] = array( 'id' => 'pi_1' );
$check( 'same intent a day later: no', PixPending::due( $o, $t0 + 90000 ), false );
$o->meta['_fkwcs_intent_id'] = array( 'id' => 'pi_3' );
$at( $o, 'pi_2', $t0, 2 );
$check( 'a third e-mail, even after expiry: no (resent once at most)', PixPending::due( $o, $t0 + 3 * 86400 ), false );

$paid                 = $pix( 'pi_9' );
$paid->data['status'] = 'processing';
$check( 'paid order: no', PixPending::due( $paid, $t0 ), false );
$card                         = $pix( 'pi_9' );
$card->data['payment_method'] = 'fkwcs_stripe';
$check( 'card order: no', PixPending::due( $card, $t0 ), false );
$none = $pix();
unset( $none->meta['_fkwcs_intent_id'] );
$check( 'no PaymentIntent yet: no', PixPending::due( $none, $t0 ), false );

$mail             = new GX_PixEmail( PixPending::ID );
$GLOBALS['gx_wc'] = new GX_WC( new GX_Mailer( array( new WC_Email( 'customer_processing_order' ), $mail ) ) );
$o                = $pix();
PixPending::send( 1234 );
$check( 'sent when the order still waits', $mail->sent, array( 1234 ) );
$o->data['status'] = 'processing';
PixPending::send( 1234 );
$check( 'not sent once the order was paid meanwhile', $mail->sent, array( 1234 ) );
$o->data['status']    = 'pending';
$o->data['date_paid'] = new DateTime();
PixPending::send( 1234 );
$check( 'not sent when a payment date is set', $mail->sent, array( 1234 ) );

$o = $pix();
$o->meta[ \Galaxie\Woo\Integrations\FunnelKitStripe::META_ATTEMPT_AT ] = (string) $t0;
$check( 'expiry: attempt + 24 h, São Paulo time', PixPending::expires_label( $o ), '07/10/2026 09:00' );
$at( $o, 'pi_1', $t0 + 3600 );
$check( 'expiry: from the latest attempt known', PixPending::expires_label( $o ), '07/10/2026 10:00' );
$pv = Smartcodes::values( array( 'order' => $o ) )['pedido'];
$check( 'smartcodes: pix_expira_em, link_pagamento; QR code empty (FunnelKit never stores it)', array( $pv['pix_expira_em'], '' !== $pv['link_pagamento'], $pv['pix_copia_e_cola'], $pv['pix_qr_url'] ), array( '07/10/2026 10:00', true, '', '' ) );
add_filter( 'galaxie_woo/store_emails/pix', static fn( $pix ) => array( 'copia_e_cola' => '00020126...', 'qr_url' => 'https://qr.stripe.com/x.png' ), 10, 2 );
$pv = Smartcodes::values( array( 'order' => $o ) )['pedido'];
$check( 'smartcodes: QR code when something provides it', array( $pv['pix_copia_e_cola'], $pv['pix_qr_url'] ), array( '00020126...', 'https://qr.stripe.com/x.png' ) );
$check( 'smartcodes: no Pix values for a card order', Smartcodes::values( array( 'order' => $order() ) )['pedido']['pix_expira_em'], '' );

$template( 50, '<p>Pague até {{pedido.pix_expira_em|amanhã}}: {{pedido.link_pagamento}}</p>', 'Pix do pedido #{{pedido.numero}}' );
$map( array( array( 'email' => PixPending::ID, 'template' => '50', 'subject' => '' ) ) );
$email         = new WC_Email( PixPending::ID );
$email->object = $o;
$out           = Sender::swap( $params, $email );
$check( 'Pix e-mail with a FluentCRM template', array( $out[1], $has( $out[2], 'Pague até 07/10/2026 10:00: https://eirnaturals.shop/finalizar-compra/order-pay/' ) ), array( 'Pix do pedido #1234', true ) );
Sender::sent();
$check( 'Pix e-mail is a row on the settings tab', isset( Module::emails()[ PixPending::ID ] ), true );
$check( 'registering leaves a non-list alone', PixPending::register( 'x' ), 'x' );

echo "\nSettings\n";

$GLOBALS['gx_posts'][993213] = new WP_Post( 993213, 'fc_template', 'draft', 'Rascunho', '<p>x</p>' );
$module = new Module();
$saved  = $module->sanitize_settings(
	array(
		'loja_whatsapp' => '(11) 90000-0000',
		Module::EMAILS  => array(
			'customer_processing_order' => array( 'template' => '993212', 'subject' => ' Pedido <b>#{{pedido.numero}}</b> ' ),
			'customer_completed_order'  => array( 'template' => '424242', 'subject' => '' ),
			'new_order'                 => array( 'template' => '555', 'subject' => '' ),
			'failed_order'              => array( 'template' => '556', 'subject' => '' ),
			'not_an_email'              => array( 'template' => '993212', 'subject' => '' ),
		),
	),
	array( Module::EMAILS => array( array( 'email' => 'new_order', 'template' => '555', 'subject' => '' ) ) )
);
$rows = array_column( $saved[ Module::EMAILS ], null, 'email' );
$check( 'a row per known e-mail, in order', array_keys( $rows ), array_keys( Module::emails() ) );
$check( 'known template kept, subject cleaned', $rows['customer_processing_order'], array( 'email' => 'customer_processing_order', 'template' => '993212', 'subject' => 'Pedido #{{pedido.numero}}' ) );
$check( 'unknown template id dropped', $rows['customer_completed_order']['template'], '' );
$check( 'a saved id kept while its template is not listed (FluentCRM off a moment)', $rows['new_order']['template'], '555' );
$check( 'a new id that is no template dropped', $rows['failed_order']['template'], '' );
$check( 'store fields', array( $saved['loja_whatsapp'], $saved['loja_cnpj'] ), array( '(11) 90000-0000', '' ) );
$rest = $module->rest_settings_submitted( array( Module::EMAILS => array( array( 'email' => 'customer_note', 'template' => '993212', 'subject' => 'Nota' ) ) ) );
$check( 'REST rows become the form shape', $rest[ Module::EMAILS ], array( 'customer_note' => array( 'template' => '993212', 'subject' => 'Nota' ) ) );
$check( 'template_for: partial refund borrows the full one', Module::template_for( Module::PARTIAL_REFUND, array( Module::EMAILS => array( array( 'email' => 'customer_refunded_order', 'template' => '7', 'subject' => 'R' ) ) ) ), 7 );
$check( 'module off by default', $module->default_enabled(), false );

ob_start();
$module->render_extra_settings( array( Module::EMAILS => array( array( 'email' => 'customer_processing_order', 'template' => '993212', 'subject' => 'Oi' ) ) ) );
$tab = (string) ob_get_clean();
$check( 'settings tab: a row per e-mail, the template selected, a test button where mapped', array( substr_count( $tab, '<tr>' ) - 1, 1 === preg_match( '~value="993212"\s+selected~', $tab ), substr_count( $tab, 'button button-secondary' ) ), array( count( Module::emails() ), true, 1 ) );
$check( 'settings tab: "— usar o e-mail padrão —" first', $has( $tab, '<option value="" >— usar o e-mail padrão —</option>' ) || $has( $tab, '— usar o e-mail padrão —' ), true );

$groups = apply_filters( 'fluent_crm/smartcode_groups', array() );
$pedido = array_values( array_filter( $groups, static fn( $g ) => 'pedido' === $g['key'] ) )[0] ?? array();
$check( 'picker: "Pedido (WooCommerce)" group', $pedido['title'] ?? '', 'Pedido (WooCommerce)' );
$check( 'picker: the merchant\'s keys', array_slice( array_keys( $pedido['shortcodes'] ?? array() ), 0, 3 ), array( '{{pedido.numero}}', '{{pedido.data}}', '{{pedido.status}}' ) );
$check( 'picker: conta and loja groups', array_values( array_intersect( array( 'conta', 'loja' ), array_column( $groups, 'key' ) ) ), array( 'conta', 'loja' ) );
$check( 'outside a store e-mail a smartcode is its default', apply_filters( 'fluent_crm/smartcode_group_callback_pedido', '{{pedido.numero}}', 'numero', 'n/d', null ), 'n/d' );

echo "\nOtpMail, before and after the shared renderer\n";

$root   = dirname( __DIR__, 2 );
$legacy = shell_exec( 'git -C ' . escapeshellarg( $root ) . ' show 6f38232:src/Modules/PasswordlessAuth/OtpMail.php 2>' . ( '\\' === DIRECTORY_SEPARATOR ? 'NUL' : '/dev/null' ) );

if ( ! is_string( $legacy ) || false === strpos( $legacy, 'final class OtpMail' ) ) {
	$failed++;
	echo "  FAIL  could not read OtpMail as of 6f38232 with git show\n";
} else {
	$legacy = str_replace( 'namespace Galaxie\\Woo\\Modules\\PasswordlessAuth;', 'namespace Galaxie\\Woo\\Legacy;', $legacy );
	eval( '?>' . $legacy );

	$otp_templates = array(
		40 => array( '<!-- wp:paragraph --><p>Olá {{contact.first_name|amigo}}, seu código: <strong>{{galaxie.otp_code}}</strong> ({{galaxie.otp_minutes}} min, {{galaxie.otp_email}}) {{galaxie.otp_code|x}}</p><!-- /wp:paragraph -->', 'Código {{galaxie.otp_code}}', 'Para {{galaxie.otp_email}}' ),
		41 => array( '<p>Sem código aqui</p>', 'Oi', '' ),
		42 => array( '<p>{{galaxie.otp_code}}</p>', '', '' ),
	);
	foreach ( $otp_templates as $id => $t ) {
		$template( $id, $t[0], $t[1], $t[2] );
	}

	$cases = array(
		'template, new person'      => array( 'nova@exemplo.com', '123456', 'login', array( 'email_source' => 'fluentcrm', 'login_template' => '40' ), array( 'first_name' => 'Ana <a href="http://x.com">x</a>' ) ),
		'template, typed subject'   => array( 'nova@exemplo.com', '654321', 'register', array( 'email_source' => 'fluentcrm', 'register_template' => '40', 'register_subject' => 'Confirme: {{galaxie.otp_code}}' ), null ),
		'template without the code' => array( 'nova@exemplo.com', '111111', 'login', array( 'email_source' => 'fluentcrm', 'login_template' => '41' ), null ),
		'template deleted'          => array( 'nova@exemplo.com', '222222', 'login', array( 'email_source' => 'fluentcrm', 'login_template' => '404' ), null ),
		'no subject anywhere'       => array( 'nova@exemplo.com', '333333', 'login', array( 'email_source' => 'fluentcrm', 'login_template' => '42' ), null ),
		'plain message'             => array( 'nova@exemplo.com', '444444', 'register', array( 'email_source' => 'plugin' ), null ),
	);

	foreach ( $cases as $name => $case ) {
		$runs = array();
		foreach ( array( \Galaxie\Woo\Legacy\OtpMail::class, \Galaxie\Woo\Modules\PasswordlessAuth\OtpMail::class ) as $class ) {
			$GLOBALS['gx_mailer']  = array();
			$GLOBALS['gx_wp_mail'] = array();
			$class::send( $case[0], $case[1], $case[2], $case[3], 10, $case[4] );
			$runs[] = array( 'fluentcrm' => $GLOBALS['gx_mailer'], 'wp_mail' => $GLOBALS['gx_wp_mail'] );
		}
		$check( "otp: {$name}: same e-mail as before", $runs[1], $runs[0] );
	}

	$GLOBALS['gx_mailer'] = array();
	\Galaxie\Woo\Modules\PasswordlessAuth\OtpMail::send( 'nova@exemplo.com', '123456', 'login', array( 'email_source' => 'fluentcrm', 'login_template' => '40' ), 10, array( 'first_name' => 'Ana' ) );
	$sent = $GLOBALS['gx_mailer'][0] ?? array();
	$check( 'otp: subject', $sent['subject'] ?? '', 'Código 123456' );
	$check( 'otp: body has the code, the name, the default', $has( (string) ( $sent['body'] ?? '' ), 'Olá Ana, seu código: <strong>123456</strong> (10 min, nova@exemplo.com) 123456' ), true );
}

echo "\n  $passed passed, $failed failed\n";
exit( $failed ? 1 : 0 );

}
