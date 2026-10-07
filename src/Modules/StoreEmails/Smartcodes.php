<?php
/**
 * The order, account and store smartcodes for FluentCRM templates.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\StoreEmails;

use Galaxie\Woo\Modules\GiftWrap\Groups;
use Galaxie\Woo\Modules\Wishlist\Gifts;
use Galaxie\Woo\Support\FluentCrmTemplate;
use Galaxie\Woo\Support\GiftOrders;

defined( 'ABSPATH' ) || exit;

/**
 * `{{pedido.*}}`, `{{conta.*}}` and `{{loja.*}}`: what a WooCommerce e-mail
 * knows that FluentCRM does not. The keys are the merchant's — templates are
 * being designed with them — so they are never renamed.
 *
 * They are registered in FluentCRM's smartcode picker, with a callback for
 * each group, and while a store e-mail is rendered they are written into the
 * template before FluentCRM's parser runs ({@see FluentCrmTemplate::render()}):
 * so they work for a guest who is not a contact, and `{{pedido.x|texto}}`
 * gives "texto" when the order has no x. Outside a store e-mail (a campaign
 * using them by mistake) every one of them is its default, or empty.
 *
 * Values are worked out from a context — the order, the WooCommerce e-mail,
 * the account — by {@see self::values()}, which is pure enough to test on
 * stubs. Five values are markup — the two items tables, the addresses and
 * the card messages, line by line — and the tables are blocks: alone in a
 * paragraph, they take its place ({@see FluentCrmTemplate::render()}).
 * Everything else is text, escaped where it lands.
 */
final class Smartcodes {

	public const GROUP_ORDER   = 'pedido';
	public const GROUP_ACCOUNT = 'conta';
	public const GROUP_STORE   = 'loja';

	/** Order values that are markup, not text. */
	public const HTML_KEYS = array( 'itens', 'itens_sem_totais', 'endereco_entrega', 'endereco_cobranca', 'mensagem_cartao' );

	/** Order values that are tables: alone in a paragraph, they replace it. */
	public const BLOCK_KEYS = array( 'itens', 'itens_sem_totais' );

	/** Melhor Envio's customer tracking page (melhor-envio-cotacao 2.16.6, TrackingService.php). */
	public const TRACKING_URL = 'https://melhorrastreio.com.br/rastreio/';

	/** Defaults of the store settings behind `{{loja.cnpj}}` / `{{loja.endereco}}`. */
	public const DEFAULT_CNPJ    = '48.548.856/0001-22';
	public const DEFAULT_ADDRESS = 'Rua Giovanni Thomé, 223, São Caetano do Sul/SP';

	/**
	 * What the FluentCRM smartcode callbacks answer with while a store e-mail
	 * is rendered: group => key => value.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static array $current = array();

	/**
	 * Every smartcode, by group: key => [ label, example ]. The examples are
	 * what the settings tab and the merchant's reference show.
	 *
	 * @return array<string,array{title:string,codes:array<string,array{0:string,1:string}>}>
	 */
	public static function catalogue(): array {
		return array(
			self::GROUP_ORDER   => array(
				'title' => __( 'Pedido (WooCommerce)', 'galaxie-woo' ),
				'codes' => array(
					'numero'                => array( __( 'Número do pedido', 'galaxie-woo' ), '1234' ),
					'data'                  => array( __( 'Data do pedido', 'galaxie-woo' ), '06/10/2026' ),
					'status'                => array( __( 'Status do pedido', 'galaxie-woo' ), 'Processando' ),
					'cliente_nome'          => array( __( 'Primeiro nome do cliente (cobrança)', 'galaxie-woo' ), 'Maria' ),
					'cliente_nome_completo' => array( __( 'Nome completo do cliente (cobrança)', 'galaxie-woo' ), 'Maria Silva' ),
					'itens'                 => array( __( 'Tabela de itens: produto e variação, quantidade, total; kits agrupados com a mensagem do cartão; e os totais (subtotal, desconto, frete, total, forma de pagamento)', 'galaxie-woo' ), '(tabela)' ),
					'itens_sem_totais'      => array( __( 'A mesma tabela de itens, sem os totais', 'galaxie-woo' ), '(tabela)' ),
					'subtotal'              => array( __( 'Subtotal dos produtos', 'galaxie-woo' ), 'R$ 178,00' ),
					'frete'                 => array( __( 'Valor do frete ("Grátis" quando zero)', 'galaxie-woo' ), 'R$ 25,90' ),
					'desconto'              => array( __( 'Desconto (vazio quando não há)', 'galaxie-woo' ), 'R$ 17,80' ),
					'total'                 => array( __( 'Total do pedido', 'galaxie-woo' ), 'R$ 186,10' ),
					'forma_pagamento'       => array( __( 'Forma de pagamento', 'galaxie-woo' ), 'Pix' ),
					'forma_entrega'         => array( __( 'Forma de entrega, com o prazo quando houver', 'galaxie-woo' ), 'PAC (3 a 5 dias úteis)' ),
					'endereco_entrega'      => array( __( 'Endereço de entrega (num presente de lista, só cidade/UF para o comprador)', 'galaxie-woo' ), 'Rua Exemplo, 100 - Centro, São Paulo/SP' ),
					'endereco_cobranca'     => array( __( 'Endereço de cobrança', 'galaxie-woo' ), 'Rua Exemplo, 100 - Centro, São Paulo/SP' ),
					'rastreio_codigo'       => array( __( 'Código de rastreio (Melhor Envio), vazio se ainda não há', 'galaxie-woo' ), 'ME2500012345BR' ),
					'rastreio_link'         => array( __( 'Link de rastreio (Melhor Rastreio), vazio se ainda não há', 'galaxie-woo' ), 'https://melhorrastreio.com.br/rastreio/ME2500012345BR' ),
					'link_pedido'           => array( __( 'Link para ver o pedido (Minha Conta; no e-mail à loja, o pedido no wp-admin)', 'galaxie-woo' ), 'https://eirnaturals.shop/minha-conta/view-order/1234/' ),
					'link_pagamento'        => array( __( 'Link para pagar (só quando o pedido ainda precisa de pagamento)', 'galaxie-woo' ), 'https://eirnaturals.shop/finalizar-compra/order-pay/1234/?pay_for_order=true&key=wc_order_…' ),
					'nota_cliente'          => array( __( 'Nota ao cliente (e-mail "Nota para o cliente") ou a observação do pedido', 'galaxie-woo' ), 'Seu pedido sai amanhã.' ),
					'valor_reembolsado'     => array( __( 'Valor reembolsado (o deste reembolso, se parcial)', 'galaxie-woo' ), 'R$ 50,00' ),
					'presente_para'         => array( __( 'Primeiro nome de quem recebe o presente (vazio se não é presente)', 'galaxie-woo' ), 'Ana' ),
					'mensagem_cartao'       => array( __( 'Mensagem(ns) do cartão dos kits', 'galaxie-woo' ), 'Feliz aniversário!' ),
					'pix_expira_em'         => array( __( 'Pix: até quando o código vale (dd/mm/aaaa hh:mm, horário de Brasília), vazio se não é Pix pendente', 'galaxie-woo' ), '07/10/2026 14:30' ),
					'pix_copia_e_cola'      => array( __( 'Pix: código copia e cola (vazio: a loja não recebe o código do FunnelKit)', 'galaxie-woo' ), '' ),
					'pix_qr_url'            => array( __( 'Pix: link da imagem/instruções do QR code (vazio: a loja não recebe o código do FunnelKit)', 'galaxie-woo' ), '' ),
				),
			),
			self::GROUP_ACCOUNT => array(
				'title' => __( 'Conta (WooCommerce)', 'galaxie-woo' ),
				'codes' => array(
					'nome'                 => array( __( 'Primeiro nome do cliente', 'galaxie-woo' ), 'Maria' ),
					'email'                => array( __( 'E-mail do cliente', 'galaxie-woo' ), 'maria@exemplo.com.br' ),
					'link_redefinir_senha' => array( __( 'Link para criar ou redefinir a senha (e-mails de senha e nova conta)', 'galaxie-woo' ), 'https://eirnaturals.shop/minha-conta/lost-password/?key=…' ),
					'link_minha_conta'     => array( __( 'Link para Minha Conta', 'galaxie-woo' ), 'https://eirnaturals.shop/minha-conta/' ),
				),
			),
			self::GROUP_STORE   => array(
				'title' => __( 'Loja', 'galaxie-woo' ),
				'codes' => array(
					'nome'     => array( __( 'Nome da loja', 'galaxie-woo' ), 'Eir Naturals' ),
					'url'      => array( __( 'Endereço do site', 'galaxie-woo' ), 'https://eirnaturals.shop/' ),
					'email'    => array( __( 'E-mail da loja (remetente do WooCommerce)', 'galaxie-woo' ), 'contato@eirnaturals.shop' ),
					'whatsapp' => array( __( 'WhatsApp da loja (configurado nesta aba)', 'galaxie-woo' ), '(11) 99999-9999' ),
					'cnpj'     => array( __( 'CNPJ (configurado nesta aba)', 'galaxie-woo' ), self::DEFAULT_CNPJ ),
					'endereco' => array( __( 'Endereço da loja (configurado nesta aba)', 'galaxie-woo' ), self::DEFAULT_ADDRESS ),
				),
			),
		);
	}

	/**
	 * Registers the groups with FluentCRM's editor and parser — the same two
	 * filters OtpMail sets for `{{galaxie.*}}`, and one callback per group.
	 */
	public static function hooks(): void {
		$groups = static function ( $groups ) {
			$groups = is_array( $groups ) ? $groups : array();

			foreach ( self::catalogue() as $key => $group ) {
				$codes = array();
				foreach ( $group['codes'] as $code => $info ) {
					$codes[ '{{' . $key . '.' . $code . '}}' ] = $info[0];
				}

				$groups[] = array(
					'key'        => $key,
					'title'      => $group['title'],
					'shortcodes' => $codes,
				);
			}

			return $groups;
		};

		add_filter( 'fluent_crm/extended_smart_codes', $groups, 100 );
		add_filter( 'fluent_crm/smartcode_groups', $groups, 100 );

		foreach ( array_keys( self::catalogue() ) as $group ) {
			add_filter(
				'fluent_crm/smartcode_group_callback_' . $group,
				static function ( $code, $value_key, $default = '' ) use ( $group ) {
					// Reached only for what our own pre-replace could not see:
					// smartcodes inside a synced pattern (header, footer), which
					// FluentCRM renders into the body itself. So: body markup.
					$value = self::$current[ $group ][ $value_key ] ?? '';
					$html  = self::GROUP_ORDER === $group && in_array( (string) $value_key, self::HTML_KEYS, true );
					$value = $value instanceof \Closure ? (string) $value( array() ) : (string) $value;

					if ( '' === trim( $value ) ) {
						return $default;
					}

					return $html ? $value : esc_html( str_replace( array( '{{', '}}' ), array( '{ {', '} }' ), $value ) );
				},
				10,
				3
			);
		}
	}

	/**
	 * Sets the values the callbacks answer with; null clears them.
	 *
	 * @param array<string,array<string,mixed>>|null $values
	 */
	public static function set_current( ?array $values ): void {
		self::$current = $values ?? array();
	}

	/**
	 * The three groups for {@see FluentCrmTemplate::render()}.
	 *
	 * @param array<string,mixed> $context See {@see self::values()}.
	 * @return array<string,array{values:array<string,mixed>,html:string[]}>
	 */
	public static function codes( array $context ): array {
		$values = self::values( $context );

		return array(
			self::GROUP_ORDER   => array(
				'values' => $values[ self::GROUP_ORDER ],
				'html'   => self::HTML_KEYS,
				'block'  => self::BLOCK_KEYS,
			),
			self::GROUP_ACCOUNT => array(
				'values' => $values[ self::GROUP_ACCOUNT ],
				'html'   => array(),
			),
			self::GROUP_STORE   => array(
				'values' => $values[ self::GROUP_STORE ],
				'html'   => array(),
			),
		);
	}

	/**
	 * Every value, by group, for one e-mail.
	 *
	 * `$context`:
	 * - order:     ?\WC_Order
	 * - email:     ?\WC_Email — whose audience decides the gift masking and
	 *              where {{pedido.link_pedido}} points
	 * - user:      ?\WP_User — account e-mails
	 * - note:      string — the customer note being sent
	 * - refund:    ?\WC_Order_Refund, with partial: bool
	 * - reset_url: string — set-password / reset-password link
	 * - settings:  the module's settings (store WhatsApp, CNPJ, address)
	 *
	 * Order values are read inside {@see Gifts::within_email()}: a gift
	 * buyer's e-mail gets the address the way WooCommerce's own e-mail to
	 * them would — "Presente para Ana — Curitiba/PR" — and the store's the
	 * whole address.
	 *
	 * @param array<string,mixed> $context
	 * @return array<string,array<string,mixed>>
	 */
	public static function values( array $context ): array {
		$order = ( $context['order'] ?? null ) instanceof \WC_Order ? $context['order'] : null;
		$email = ( $context['email'] ?? null ) instanceof \WC_Email ? $context['email'] : null;

		$order_values = array_fill_keys( array_keys( self::catalogue()[ self::GROUP_ORDER ]['codes'] ), '' );

		if ( $order ) {
			$order_values = Gifts::within_email( $email, static fn(): array => self::order_values( $order, $email, $context ) );
		}

		return array(
			self::GROUP_ORDER   => $order_values,
			self::GROUP_ACCOUNT => self::account_values( $order, $context ),
			self::GROUP_STORE   => self::store_values( (array) ( $context['settings'] ?? array() ) ),
		);
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	private static function order_values( \WC_Order $order, ?\WC_Email $email, array $context ): array {
		$currency = (string) $order->get_currency();
		$first    = trim( (string) $order->get_billing_first_name() );
		$tracking = self::tracking( $order );
		$refund   = ( $context['refund'] ?? null ) instanceof \WC_Order_Refund ? $context['refund'] : null;
		$refunded = ! empty( $context['partial'] ) && $refund ? abs( (float) $refund->get_amount() ) : (float) $order->get_total_refunded();
		$note     = trim( (string) ( $context['note'] ?? '' ) );
		$created  = $order->get_date_created();
		$shipping = $order->get_items( 'shipping' );
		$freight  = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
		$discount = (float) $order->get_total_discount();
		$pix      = PixPending::waiting( $order );
		$qr       = $pix ? PixPending::qr( $order ) : array();

		return array(
			'numero'                => (string) $order->get_order_number(),
			'data'                  => $created ? (string) wc_format_datetime( $created ) : '',
			'status'                => (string) wc_get_order_status_name( $order->get_status() ),
			'cliente_nome'          => $first,
			'cliente_nome_completo' => trim( $first . ' ' . trim( (string) $order->get_billing_last_name() ) ),
			'itens'                 => static fn( array $config ): string => self::items_table( $order, $config, true ),
			'itens_sem_totais'      => static fn( array $config ): string => self::items_table( $order, $config, false ),
			'subtotal'              => self::money( (float) $order->get_subtotal(), $currency ),
			'frete'                 => $shipping ? ( $freight > 0 ? self::money( $freight, $currency ) : __( 'Grátis', 'galaxie-woo' ) ) : '',
			'desconto'              => $discount > 0 ? self::money( $discount, $currency ) : '',
			'total'                 => self::money( (float) $order->get_total(), $currency ),
			'forma_pagamento'       => trim( (string) $order->get_payment_method_title() ),
			'forma_entrega'         => self::shipping_methods( $shipping ),
			'endereco_entrega'      => self::address_html( (string) $order->get_formatted_shipping_address( '' ) ),
			'endereco_cobranca'     => self::address_html( (string) $order->get_formatted_billing_address( '' ) ),
			'rastreio_codigo'       => $tracking['code'],
			'rastreio_link'         => $tracking['url'],
			'link_pedido'           => self::order_link( $order, $email ),
			'link_pagamento'        => $order->needs_payment() ? (string) $order->get_checkout_payment_url() : '',
			'nota_cliente'          => '' !== $note ? $note : trim( (string) $order->get_customer_note() ),
			'valor_reembolsado'     => $refunded > 0 ? self::money( $refunded, $currency ) : '',
			'presente_para'         => self::recipient( $order ),
			'mensagem_cartao'       => implode( '<br>', array_map( 'esc_html', self::card_messages( $order ) ) ),
			'pix_expira_em'         => $pix ? PixPending::expires_label( $order ) : '',
			'pix_copia_e_cola'      => $pix ? $qr['copia_e_cola'] : '',
			'pix_qr_url'            => $pix ? $qr['qr_url'] : '',
		);
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,string>
	 */
	private static function account_values( ?\WC_Order $order, array $context ): array {
		$user  = ( $context['user'] ?? null ) instanceof \WP_User ? $context['user'] : null;
		$name  = '';
		$email = '';

		if ( $user ) {
			$name  = trim( (string) get_user_meta( $user->ID, 'billing_first_name', true ) );
			$name  = '' !== $name ? $name : trim( (string) $user->first_name );
			$name  = '' !== $name ? $name : trim( (string) $user->display_name );
			$email = (string) $user->user_email;
		} elseif ( $order ) {
			$name  = trim( (string) $order->get_billing_first_name() );
			$email = (string) $order->get_billing_email();
		}

		return array(
			'nome'                 => $name,
			'email'                => $email,
			'link_redefinir_senha' => (string) ( $context['reset_url'] ?? '' ),
			'link_minha_conta'     => (string) wc_get_page_permalink( 'myaccount' ),
		);
	}

	/**
	 * @param array<string,mixed> $settings
	 * @return array<string,string>
	 */
	private static function store_values( array $settings ): array {
		$from = trim( (string) get_option( 'woocommerce_email_from_address', '' ) );

		return array(
			'nome'     => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'url'      => (string) home_url( '/' ),
			'email'    => '' !== $from ? $from : (string) get_option( 'admin_email', '' ),
			'whatsapp' => trim( (string) ( $settings['loja_whatsapp'] ?? '' ) ),
			'cnpj'     => trim( (string) ( $settings['loja_cnpj'] ?? self::DEFAULT_CNPJ ) ),
			'endereco' => trim( (string) ( $settings['loja_endereco'] ?? self::DEFAULT_ADDRESS ) ),
		);
	}

	/** An amount as WooCommerce prints it, as text: "R$ 1.234,50". */
	private static function money( float $amount, string $currency ): string {
		$html = (string) wc_price( $amount, '' !== $currency ? array( 'currency' => $currency ) : array() );

		return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * A formatted address, one escaped line per line. WooCommerce's (and the
	 * Brazilian fields' número / bairro, see Integrations\BrazilianCheckoutFields)
	 * as the order returns it — so through the gift mask.
	 */
	private static function address_html( string $formatted ): string {
		$lines = preg_split( '~<br\s*/?>|\n~i', $formatted ) ?: array();
		$lines = array_filter(
			array_map( static fn( string $line ): string => trim( html_entity_decode( wp_strip_all_tags( $line ), ENT_QUOTES, 'UTF-8' ) ), $lines ),
			static fn( string $line ): bool => '' !== $line
		);

		return implode( '<br>', array_map( 'esc_html', $lines ) );
	}

	/**
	 * Each shipping line's title, with its delivery estimate when Melhor
	 * Envio stored one apart from the title (rate meta `delivery_time`,
	 * " (3 a 5 dias úteis)"; 2.16.6 also puts it in the title itself).
	 *
	 * @param array<int|string,mixed> $lines
	 */
	private static function shipping_methods( array $lines ): string {
		$out = array();

		foreach ( $lines as $line ) {
			if ( ! $line instanceof \WC_Order_Item_Shipping ) {
				continue;
			}

			$title = trim( wp_strip_all_tags( (string) $line->get_name() ) );
			$eta   = trim( (string) $line->get_meta( 'delivery_time' ) );

			if ( '' !== $eta && false === strpos( $title, trim( $eta, ' ()' ) ) ) {
				$title .= ' ' . ( '(' === substr( $eta, 0, 1 ) ? $eta : '(' . $eta . ')' );
			}

			if ( '' !== $title ) {
				$out[] = $title;
			}
		}

		return implode( ', ', $out );
	}

	/**
	 * The tracking code Melhor Envio (melhor-envio-cotacao 2.16.6) keeps on
	 * the order, read in the order its own TrackingService::getTrackingOrder()
	 * reads it: order meta `melhorenvio_tracking` (HPOS-safe), then the
	 * `tracking` of the `melhorenvio_status_v2` post meta array (`_sandbox`
	 * while its token is a sandbox one). Filterable as
	 * `galaxie_woo/store_emails/tracking` for another carrier.
	 *
	 * @return array{code:string,url:string}
	 */
	public static function tracking( \WC_Order $order ): array {
		$code = trim( (string) $order->get_meta( 'melhorenvio_tracking' ) );

		if ( '' === $code ) {
			$key  = 'sandbox' === get_option( 'wpmelhorenvio_token_environment' ) ? 'melhorenvio_status_v2_sandbox' : 'melhorenvio_status_v2';
			$data = $order->get_meta( $key );
			$data = is_array( $data ) ? $data : get_post_meta( (int) $order->get_id(), $key, true );
			$code = is_array( $data ) && is_scalar( $data['tracking'] ?? null ) ? trim( (string) $data['tracking'] ) : '';
		}

		$tracking = apply_filters(
			'galaxie_woo/store_emails/tracking',
			array(
				'code' => $code,
				'url'  => '' !== $code ? self::TRACKING_URL . rawurlencode( $code ) : '',
			),
			$order
		);

		return array(
			'code' => is_array( $tracking ) ? (string) ( $tracking['code'] ?? '' ) : '',
			'url'  => is_array( $tracking ) ? (string) ( $tracking['url'] ?? '' ) : '',
		);
	}

	/**
	 * Where "ver pedido" goes: to the customer, their account's order page,
	 * or — a guest has no account — the order-received page, which its key
	 * opens; to the store, the order in wp-admin.
	 */
	private static function order_link( \WC_Order $order, ?\WC_Email $email ): string {
		if ( $email && ! $email->is_customer_email() ) {
			return (string) $order->get_edit_order_url();
		}

		return $order->get_customer_id() ? (string) $order->get_view_order_url() : (string) $order->get_checkout_order_received_url();
	}

	/**
	 * The first name of who receives a gift: a shared wish list's owner (as
	 * the buyer already saw it), or, for a Gift Wrap gift sent to someone
	 * else, the shipping first name. Empty for an order that is no gift, or
	 * one the buyer ships to themselves.
	 */
	private static function recipient( \WC_Order $order ): string {
		$owner = (int) $order->get_meta( Gifts::ORDER_OWNER );

		if ( $owner ) {
			return Gifts::owner_name( $owner, (string) $order->get_shipping_first_name() );
		}

		if ( ! GiftOrders::is_gift( $order ) ) {
			return '';
		}

		$to = trim( (string) $order->get_shipping_first_name() );

		return '' !== $to && 0 !== strcasecmp( $to, trim( (string) $order->get_billing_first_name() ) ) ? $to : '';
	}

	/** @return string[] The card messages of the order's gifts, in order. */
	private static function card_messages( \WC_Order $order ): array {
		$out = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product || Groups::ROLE_CARD !== (string) $item->get_meta( Groups::ITEM_ROLE ) ) {
				continue;
			}

			$message = trim( (string) $item->get_meta( Groups::ITEM_MESSAGE ) );

			if ( '' !== $message ) {
				$out[] = $message;
			}
		}

		return $out;
	}

	/**
	 * The items as a table mail clients draw alike: inline styles only, the
	 * template's own text colour and font, no images. Lines of a gift (a kit,
	 * or Gift Wrap's "Presente 1") are listed under its name, with its card
	 * message; everything else line by line, in the order's order. With
	 * `$totals`, a footer: subtotal, discount (when there is one), shipping
	 * with its method, total, and how it was paid.
	 *
	 * @param array<string,mixed> $config The FluentCRM template's design config.
	 */
	public static function items_table( \WC_Order $order, array $config = array(), bool $totals = true ): string {
		$color  = self::css_value( (string) ( $config['text_color'] ?? '' ), '#202020' );
		$font   = self::css_value( (string) ( $config['content_font_family'] ?? '' ), 'Arial, Helvetica, sans-serif' );
		$size   = self::css_value( (string) ( $config['paragraph_font_size'] ?? '' ), '15px' );
		$line   = 'border-bottom:1px solid #e4e4e4;';
		$cell   = 'padding:8px 6px;vertical-align:top;' . $line;
		$money  = static fn( float $amount ): string => self::money( $amount, (string) $order->get_currency() );
		$rows   = '';
		$blocks = self::item_blocks( $order );

		foreach ( $blocks as $block ) {
			if ( '' !== $block['title'] ) {
				$rows .= '<tr><td colspan="3" style="padding:14px 6px 6px;font-weight:bold;' . $line . '">' . esc_html( $block['title'] ) . '</td></tr>';
			}

			foreach ( $block['items'] as $item ) {
				$details = $item['details'] ? '<br><span style="font-size:0.85em;opacity:0.8;">' . esc_html( implode( ' · ', $item['details'] ) ) . '</span>' : '';
				$indent  = '' !== $block['title'] ? 'padding-left:16px;' : '';
				$rows   .= '<tr>'
					. '<td style="' . $cell . $indent . '">' . esc_html( $item['name'] ) . $details . '</td>'
					. '<td style="' . $cell . 'text-align:center;white-space:nowrap;">' . esc_html( (string) $item['quantity'] ) . '</td>'
					. '<td style="' . $cell . 'text-align:right;white-space:nowrap;">' . esc_html( $money( $item['total'] ) ) . '</td>'
					. '</tr>';
			}

			foreach ( $block['messages'] as $message ) {
				/* translators: %s: the card message the shopper wrote. */
				$rows .= '<tr><td colspan="3" style="padding:6px 6px 10px 16px;font-style:italic;' . $line . '">' . esc_html( sprintf( __( 'Mensagem do cartão: “%s”', 'galaxie-woo' ), $message ) ) . '</td></tr>';
			}
		}

		if ( '' === $rows ) {
			return '';
		}

		$th   = 'padding:8px 6px;border-bottom:2px solid ' . $color . ';font-weight:bold;';
		$foot = $totals ? self::totals_rows( $order, $color ) : '';

		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;margin:0 0 16px;color:' . $color . ';font-family:' . $font . ';font-size:' . $size . ';line-height:1.5;">'
			. '<thead><tr>'
			. '<th style="' . $th . 'text-align:left;">' . esc_html__( 'Produto', 'galaxie-woo' ) . '</th>'
			. '<th style="' . $th . 'text-align:center;">' . esc_html__( 'Qtd.', 'galaxie-woo' ) . '</th>'
			. '<th style="' . $th . 'text-align:right;">' . esc_html__( 'Total', 'galaxie-woo' ) . '</th>'
			. '</tr></thead><tbody>' . $rows . '</tbody>' . $foot . '</table>';
	}

	/**
	 * The totals under the items: label across two columns, amount in the
	 * third; the total in bold above a heavier rule.
	 */
	private static function totals_rows( \WC_Order $order, string $color ): string {
		$currency = (string) $order->get_currency();
		$rows     = array( array( __( 'Subtotal', 'galaxie-woo' ), self::money( (float) $order->get_subtotal(), $currency ), '' ) );
		$discount = (float) $order->get_total_discount();

		if ( $discount > 0 ) {
			$rows[] = array( __( 'Desconto', 'galaxie-woo' ), '-' . self::money( $discount, $currency ), '' );
		}

		$shipping = $order->get_items( 'shipping' );

		if ( $shipping ) {
			$freight = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
			$rows[]  = array( __( 'Frete', 'galaxie-woo' ), $freight > 0 ? self::money( $freight, $currency ) : __( 'Grátis', 'galaxie-woo' ), self::shipping_methods( $shipping ) );
		}

		$rows[] = array( __( 'Total', 'galaxie-woo' ), self::money( (float) $order->get_total(), $currency ), '', true );

		$method = trim( (string) $order->get_payment_method_title() );

		if ( '' !== $method ) {
			$rows[] = array( __( 'Forma de pagamento', 'galaxie-woo' ), $method, '' );
		}

		$out = '<tfoot>';

		foreach ( $rows as $row ) {
			$strong = ! empty( $row[3] );
			$style  = 'padding:6px;text-align:right;' . ( $strong ? 'font-weight:bold;border-top:2px solid ' . $color . ';' : '' );
			$note   = '' !== $row[2] ? '<br><span style="font-size:0.85em;font-weight:normal;opacity:0.8;">' . esc_html( $row[2] ) . '</span>' : '';
			$out   .= '<tr><td colspan="2" style="' . $style . '">' . esc_html( $row[0] ) . $note . '</td><td style="' . $style . 'white-space:nowrap;">' . esc_html( $row[1] ) . '</td></tr>';
		}

		return $out . '</tfoot>';
	}

	/**
	 * The order's product lines, grouped: one block per gift (titled with the
	 * kit's name or "Presente N") where its first line was, an untitled block
	 * for each run of other lines.
	 *
	 * @return array<int,array{title:string,items:array<int,array{name:string,quantity:int,total:float,details:string[]}>,messages:string[]}>
	 */
	public static function item_blocks( \WC_Order $order ): array {
		$blocks = array();
		$gifts  = array(); // group id => index in $blocks.

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$group  = (string) $item->get_meta( Groups::ITEM_GROUP );
			$number = (int) $item->get_meta( Groups::ITEM_NUMBER );
			$name   = (string) $item->get_meta( Groups::ITEM_NAME );
			$hidden = array();

			if ( '' !== $group ) {
				// The "Kit Lavanda: Caixa" and "Mensagem" lines the order carries
				// for wp-admin: the grouping says the same here.
				$hidden = array( esc_html( Groups::label( $number, $name ) ), __( 'Mensagem', 'galaxie-woo' ) );

				if ( ! isset( $gifts[ $group ] ) ) {
					$blocks[]         = array(
						'title'    => Groups::label( $number, $name ),
						'items'    => array(),
						'messages' => array(),
					);
					$gifts[ $group ] = count( $blocks ) - 1;
				}

				$index = $gifts[ $group ];
			} else {
				$last = count( $blocks ) - 1;

				if ( $last < 0 || '' !== $blocks[ $last ]['title'] ) {
					$blocks[] = array(
						'title'    => '',
						'items'    => array(),
						'messages' => array(),
					);
					$last     = count( $blocks ) - 1;
				}

				$index = $last;
			}

			$blocks[ $index ]['items'][] = array(
				'name'     => trim( wp_strip_all_tags( (string) $item->get_name() ) ),
				'quantity' => (int) $item->get_quantity(),
				'total'    => (float) $item->get_subtotal() + (float) $item->get_subtotal_tax(),
				'details'  => self::item_details( $item, $hidden ),
			);

			$message = trim( (string) $item->get_meta( Groups::ITEM_MESSAGE ) );

			if ( '' !== $group && '' !== $message && Groups::ROLE_CARD === (string) $item->get_meta( Groups::ITEM_ROLE ) ) {
				$blocks[ $index ]['messages'][] = $message;
			}
		}

		return $blocks;
	}

	/**
	 * A line's visible meta — the variation ("Tamanho: 180g"), "Presente
	 * para" — as "Key: value" texts, without the ones in `$hidden`.
	 *
	 * @param string[] $hidden Display keys to leave out.
	 * @return string[]
	 */
	private static function item_details( \WC_Order_Item_Product $item, array $hidden ): array {
		$out = array();

		foreach ( (array) $item->get_formatted_meta_data() as $meta ) {
			$key = trim( (string) ( $meta->display_key ?? '' ) );

			if ( '' === $key || in_array( $key, $hidden, true ) || in_array( wp_strip_all_tags( $key ), $hidden, true ) ) {
				continue;
			}

			$value = trim( html_entity_decode( wp_strip_all_tags( (string) ( $meta->display_value ?? '' ) ), ENT_QUOTES, 'UTF-8' ) );
			$label = html_entity_decode( wp_strip_all_tags( $key ), ENT_QUOTES, 'UTF-8' );

			if ( '' !== $value ) {
				$out[] = $label . ': ' . $value;
			}
		}

		return $out;
	}

	/** A config value fit for a style attribute, or `$fallback`. */
	private static function css_value( string $value, string $fallback ): string {
		$value = trim( $value );

		return '' !== $value && ! preg_match( '~[;"<>{}\\\\]~', $value ) ? str_replace( '"', "'", $value ) : $fallback;
	}
}
