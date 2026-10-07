<?php
/**
 * Store e-mails designed in FluentCRM.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\StoreEmails;

use Galaxie\Woo\Core\Field;
use Galaxie\Woo\Core\Module as ModuleContract;
use Galaxie\Woo\Core\ProvidesRestSettings;
use Galaxie\Woo\Core\ProvidesSettings;
use Galaxie\Woo\Support\FluentCrmTemplate;

defined( 'ABSPATH' ) || exit;

/**
 * The merchant designs the store's transactional e-mails in FluentCRM's
 * template editor (FluentCRM → Emails → Templates) instead of YayMail. Here
 * each WooCommerce e-mail is given one of those templates, and an optional
 * subject; WooCommerce still decides when it goes and to whom
 * ({@see Sender}). FluentCRM has no order smartcodes, so this module brings
 * them: `{{pedido.*}}`, `{{conta.*}}`, `{{loja.*}}` ({@see Smartcodes}).
 *
 * Off by default: until a template is chosen nothing changes, but the
 * smartcode groups only reach FluentCRM's picker while it is on.
 *
 * An e-mail left on "— usar o e-mail padrão —", or whose template cannot be
 * used, goes out exactly as before (WooCommerce's, or YayMail's).
 */
final class Module implements ModuleContract, ProvidesSettings, ProvidesRestSettings {

	public const ID = 'store-emails';

	/** Settings key of the e-mail rows. */
	public const EMAILS = 'emails';

	/** admin-post action of "Enviar teste". */
	public const TEST_ACTION = 'galaxie_woo_store_emails_test';

	/** The partially refunded e-mail: WC_Email_Customer_Refunded_Order sets this id for a partial refund. */
	public const PARTIAL_REFUND = 'customer_partially_refunded_order';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'E-mails da loja (FluentCRM)', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Os e-mails do WooCommerce (pedido recebido, enviado, senha…) com o layout de templates do FluentCRM e smartcodes de pedido ({{pedido.*}}, {{conta.*}}, {{loja.*}}). O WooCommerce continua decidindo quando e para quem enviar.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return false;
	}

	public function boot(): void {
		Smartcodes::hooks();
		Sender::hooks();

		if ( is_admin() ) {
			add_action( 'admin_post_' . self::TEST_ACTION, array( $this, 'send_test' ) );
		}
	}

	/**
	 * The e-mails that can take a template, in the order the tab lists them:
	 * slot => [ label, for the customer? ]. A slot is the WC_Email id at
	 * send time; a partial refund has its own, which falls back to the full
	 * refund's choice ({@see self::template_for()}).
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function emails(): array {
		return array(
			'customer_on_hold_order'      => array( __( 'Pedido aguardando (em espera)', 'galaxie-woo' ), true ),
			'customer_processing_order'   => array( __( 'Pedido em processamento', 'galaxie-woo' ), true ),
			'customer_completed_order'    => array( __( 'Pedido concluído', 'galaxie-woo' ), true ),
			'customer_refunded_order'     => array( __( 'Pedido reembolsado', 'galaxie-woo' ), true ),
			self::PARTIAL_REFUND          => array( __( 'Pedido reembolsado parcialmente (vazio: usa o de cima)', 'galaxie-woo' ), true ),
			'customer_cancelled_order'    => array( __( 'Pedido cancelado', 'galaxie-woo' ), true ),
			'customer_failed_order'       => array( __( 'Pedido malsucedido', 'galaxie-woo' ), true ),
			'customer_invoice'            => array( __( 'Detalhes do pedido / pagar', 'galaxie-woo' ), true ),
			'customer_note'               => array( __( 'Nota para o cliente', 'galaxie-woo' ), true ),
			'customer_new_account'        => array( __( 'Nova conta', 'galaxie-woo' ), true ),
			'customer_reset_password'     => array( __( 'Redefinir senha', 'galaxie-woo' ), true ),
			'new_order'                   => array( __( 'Novo pedido (para a loja)', 'galaxie-woo' ), false ),
			'cancelled_order'             => array( __( 'Pedido cancelado (para a loja)', 'galaxie-woo' ), false ),
			'failed_order'                => array( __( 'Pedido malsucedido (para a loja)', 'galaxie-woo' ), false ),
		);
	}

	/** @return array<string,mixed> The saved settings. */
	public static function settings(): array {
		return \Galaxie\Woo\Core\Plugin::instance()->settings()->module_settings( self::ID );
	}

	/**
	 * The template chosen for an e-mail; 0 for WooCommerce's own. A partial
	 * refund without its own choice takes the full refund's.
	 *
	 * @param array<string,mixed> $settings
	 */
	public static function template_for( string $slot, array $settings ): int {
		$rows = self::rows( $settings );
		$id   = (int) ( $rows[ $slot ]['template'] ?? 0 );

		if ( $id <= 0 && self::PARTIAL_REFUND === $slot ) {
			$id = (int) ( $rows['customer_refunded_order']['template'] ?? 0 );
		}

		return max( 0, $id );
	}

	/**
	 * The subject typed for an e-mail; '' for the template's own. A partial
	 * refund borrows the full refund's only along with its template.
	 *
	 * @param array<string,mixed> $settings
	 */
	public static function subject_for( string $slot, array $settings ): string {
		$rows = self::rows( $settings );

		if ( self::PARTIAL_REFUND === $slot && (int) ( $rows[ $slot ]['template'] ?? 0 ) <= 0 ) {
			$slot = 'customer_refunded_order';
		}

		return trim( (string) ( $rows[ $slot ]['subject'] ?? '' ) );
	}

	/**
	 * The saved rows by slot.
	 *
	 * @param array<string,mixed> $settings
	 * @return array<string,array{template:string,subject:string}>
	 */
	private static function rows( array $settings ): array {
		$out = array();

		foreach ( (array) ( $settings[ self::EMAILS ] ?? array() ) as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$slot = is_string( $key ) ? $key : (string) ( $row['email'] ?? '' );

			if ( '' !== $slot ) {
				$out[ $slot ] = array(
					'template' => (string) ( $row['template'] ?? '' ),
					'subject'  => (string) ( $row['subject'] ?? '' ),
				);
			}
		}

		return $out;
	}

	// ------------------------------------------------------------ settings

	public function settings_tab_label(): string {
		return __( 'E-mails da loja', 'galaxie-woo' );
	}

	/** @return Field[] */
	public function settings_fields(): array {
		return array(
			new Field(
				key: 'loja_whatsapp',
				label: __( 'WhatsApp da loja', 'galaxie-woo' ),
				description: __( 'Para {{loja.whatsapp}} nos templates. Como deve aparecer no e-mail, ex.: (11) 99999-9999.', 'galaxie-woo' ),
				default: '',
				placeholder: '(11) 99999-9999'
			),
			new Field(
				key: 'loja_cnpj',
				label: __( 'CNPJ', 'galaxie-woo' ),
				description: __( 'Para {{loja.cnpj}}.', 'galaxie-woo' ),
				default: Smartcodes::DEFAULT_CNPJ
			),
			new Field(
				key: 'loja_endereco',
				label: __( 'Endereço da loja', 'galaxie-woo' ),
				description: __( 'Para {{loja.endereco}}.', 'galaxie-woo' ),
				default: Smartcodes::DEFAULT_ADDRESS
			),
		);
	}

	public function render_extra_settings( array $values ): void {
		$templates = FluentCrmTemplate::templates();
		$rows      = self::rows( $values );
		$wc_emails = self::wc_emails();

		$this->test_notice();

		echo '<h2>' . esc_html__( 'Template de cada e-mail', 'galaxie-woo' ) . '</h2>';

		if ( ! FluentCrmTemplate::crm_active() ) {
			printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html__( 'O FluentCRM não está ativo: todos os e-mails saem no padrão do WooCommerce (ou do YayMail).', 'galaxie-woo' ) );
		} elseif ( ! $templates ) {
			printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html__( 'Nenhum template de e-mail foi encontrado no FluentCRM. Crie um em FluentCRM → Emails → Templates e recarregue esta página.', 'galaxie-woo' ) );
		}

		echo '<p>' . esc_html__( 'O WooCommerce continua decidindo quando e para quem cada e-mail vai (e se está ativado, em WooCommerce → Configurações → E-mails); aqui só se troca o assunto e o conteúdo pelo template. Se o template não puder ser usado (FluentCRM desativado, template apagado), o e-mail padrão sai no lugar. E-mails configurados como "texto simples" no WooCommerce passam a sair em HTML quando têm template.', 'galaxie-woo' ) . '</p>';

		if ( defined( 'YAYMAIL_VERSION' ) ) {
			printf(
				'<div class="notice notice-info inline"><p>%s</p></div>',
				esc_html__( 'O YayMail está ativo. Quando um e-mail tem template do FluentCRM, é ele que sai — o do YayMail não é aplicado por cima. Mesmo assim, desative no YayMail os templates dos e-mails que você passou para o FluentCRM: assim fica claro onde cada e-mail é editado, e se o template do FluentCRM falhar sai o padrão do WooCommerce.', 'galaxie-woo' )
			);
		}

		$choose = array( '' => __( '— usar o e-mail padrão —', 'galaxie-woo' ) ) + $templates;
		?>
		<table class="widefat striped" style="max-width:1100px">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'E-mail do WooCommerce', 'galaxie-woo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Template do FluentCRM', 'galaxie-woo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Assunto (opcional, aceita smartcodes)', 'galaxie-woo' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Teste', 'galaxie-woo' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( self::emails() as $slot => $info ) : ?>
				<?php
				$current = (string) ( $rows[ $slot ]['template'] ?? '' );
				$subject = (string) ( $rows[ $slot ]['subject'] ?? '' );
				$name    = 'fields[' . self::EMAILS . '][' . $slot . ']';
				$wc      = $wc_emails[ self::PARTIAL_REFUND === $slot ? 'customer_refunded_order' : $slot ] ?? null;
				?>
				<tr>
					<td>
						<strong><?php echo esc_html( $info[0] ); ?></strong><br>
						<code><?php echo esc_html( $slot ); ?></code>
						<?php if ( $wc && ! $wc->is_enabled() ) : ?>
							<br><em><?php esc_html_e( 'Desativado no WooCommerce: não é enviado.', 'galaxie-woo' ); ?></em>
						<?php endif; ?>
					</td>
					<td>
						<select name="<?php echo esc_attr( $name . '[template]' ); ?>">
							<?php foreach ( $choose as $value => $label ) : ?>
								<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $current, (string) $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
							<?php if ( '' !== $current && ! isset( $choose[ $current ] ) ) : ?>
								<option value="<?php echo esc_attr( $current ); ?>" selected><?php echo esc_html( sprintf( /* translators: %s: template id */ __( 'Template #%s (não encontrado — sai o e-mail padrão)', 'galaxie-woo' ), $current ) ); ?></option>
							<?php endif; ?>
						</select>
						<?php if ( self::yaymail_active( $slot ) ) : ?>
							<p class="description" style="color:#996800"><?php esc_html_e( 'O template do YayMail para este e-mail está ativo. Se escolher um template do FluentCRM, desative o do YayMail.', 'galaxie-woo' ); ?></p>
						<?php endif; ?>
					</td>
					<td>
						<input type="text" class="regular-text" name="<?php echo esc_attr( $name . '[subject]' ); ?>" value="<?php echo esc_attr( $subject ); ?>" placeholder="<?php esc_attr_e( 'Vazio: assunto do template', 'galaxie-woo' ); ?>" />
					</td>
					<td>
						<?php if ( self::template_for( $slot, $values ) > 0 ) : ?>
							<a class="button button-secondary" href="<?php echo esc_url( self::test_url( $slot ) ); ?>"><?php esc_html_e( 'Enviar teste para mim', 'galaxie-woo' ); ?></a>
						<?php else : ?>
							<span class="description"><?php esc_html_e( 'Escolha um template e salve', 'galaxie-woo' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: the current user's e-mail. */
					__( '"Enviar teste para mim" usa o que está salvo (salve antes) e o pedido mais recente da loja — ou um pedido de exemplo, se não houver nenhum — e envia só para %s, com "[Teste]" no assunto. Nada é enviado ao cliente.', 'galaxie-woo' ),
					wp_get_current_user()->user_email
				)
			);
			?>
		</p>

		<h2><?php esc_html_e( 'Smartcodes', 'galaxie-woo' ); ?></h2>
		<p><?php esc_html_e( 'No editor de templates do FluentCRM, eles aparecem no botão de smartcodes, nos grupos abaixo (com este módulo ligado). Aceitam um texto para quando estão vazios: {{pedido.rastreio_codigo|ainda não disponível}}. Os do próprio FluentCRM, como {{contact.first_name}}, usam os dados de cobrança do pedido quando o cliente ainda não é contato.', 'galaxie-woo' ); ?></p>
		<?php foreach ( Smartcodes::catalogue() as $group => $spec ) : ?>
			<h3><?php echo esc_html( $spec['title'] ); ?></h3>
			<ul style="list-style:disc;padding-left:20px">
				<?php foreach ( $spec['codes'] as $key => $code ) : ?>
					<li><code>{{<?php echo esc_html( $group . '.' . $key ); ?>}}</code> — <?php echo esc_html( $code[0] ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endforeach; ?>
		<p><em><?php esc_html_e( 'Estes e-mails não são campanhas: não há pixel de abertura, links rastreados nem rodapé de descadastro. Evite no template os links de descadastro e de "ver no navegador" — ficariam quebrados.', 'galaxie-woo' ); ?></em></p>
		<?php
	}

	public function sanitize_settings( array $submitted, array $current ): array {
		$out       = Field::sanitize_all( $this->settings_fields(), $submitted );
		$templates = FluentCrmTemplate::templates();
		$known     = self::rows( $current );
		$posted    = self::rows( array( self::EMAILS => is_array( $submitted[ self::EMAILS ] ?? null ) ? $submitted[ self::EMAILS ] : array() ) );
		$rows      = array();

		foreach ( array_keys( self::emails() ) as $slot ) {
			$row      = $posted[ $slot ] ?? array();
			$template = (string) absint( $row['template'] ?? 0 );

			// An id that is not a template any more is kept only if it was
			// already saved (FluentCRM switched off for a moment must not wipe
			// the choices); a new unknown id is dropped.
			if ( '0' === $template || ( ! isset( $templates[ $template ] ) && ( $known[ $slot ]['template'] ?? '' ) !== $template ) ) {
				$template = '';
			}

			$rows[] = array(
				'email'    => $slot,
				'template' => $template,
				'subject'  => sanitize_text_field( (string) ( $row['subject'] ?? '' ) ),
			);
		}

		$out[ self::EMAILS ] = $rows;

		return $out;
	}

	public function rest_settings_schema(): array {
		return array(
			self::EMAILS => array(
				'description' => __( 'One row per WooCommerce e-mail: the FluentCRM template id that replaces it ("" for WooCommerce\'s own) and an optional subject. Sending the list replaces it; e-mails left out go back to WooCommerce\'s own.', 'galaxie-woo' ),
				'default'     => array(),
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'email'    => array(
							'type' => 'string',
							'enum' => array_keys( self::emails() ),
						),
						'template' => array(
							'type'        => 'string',
							'description' => __( 'FluentCRM email template (post) id, or "".', 'galaxie-woo' ),
						),
						'subject'  => array(
							'type'        => 'string',
							'description' => __( 'Subject; "" for the template\'s own. Smartcodes allowed.', 'galaxie-woo' ),
						),
					),
				),
			),
		);
	}

	public function rest_settings_submitted( array $values ): array {
		$by_slot = array();

		foreach ( (array) ( $values[ self::EMAILS ] ?? array() ) as $row ) {
			if ( is_array( $row ) && is_string( $row['email'] ?? null ) ) {
				$by_slot[ $row['email'] ] = array(
					'template' => is_scalar( $row['template'] ?? null ) ? (string) $row['template'] : '',
					'subject'  => is_scalar( $row['subject'] ?? null ) ? (string) $row['subject'] : '',
				);
			}
		}

		$values[ self::EMAILS ] = $by_slot;

		return $values;
	}

	// ---------------------------------------------------------------- test

	private static function test_url( string $slot ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::TEST_ACTION,
					'email'  => $slot,
				),
				admin_url( 'admin-post.php' )
			),
			self::TEST_ACTION . '_' . $slot
		);
	}

	/**
	 * "Enviar teste": the e-mail's template, rendered with the latest order
	 * (or an unsaved example), sent to the staff member who clicked — and to
	 * no one else.
	 */
	public function send_test(): void {
		$slot = isset( $_GET['email'] ) ? sanitize_key( wp_unslash( $_GET['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below, per e-mail.

		if ( ! current_user_can( 'manage_woocommerce' ) || ! isset( self::emails()[ $slot ] ) ) {
			wp_die( esc_html__( 'Você não tem permissão para isso.', 'galaxie-woo' ) );
		}

		check_admin_referer( self::TEST_ACTION . '_' . $slot );

		$result = $this->test( $slot, wp_get_current_user() );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'galaxie-woo',
					'tab'       => self::ID,
					'gx_test'   => $result['status'],
					'gx_email'  => $slot,
					'gx_order'  => $result['order'],
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * @return array{status:string,order:string} status: sent, failed, no_template, no_email, no_render.
	 */
	private function test( string $slot, \WP_User $user ): array {
		$settings = self::settings();
		$out      = array( 'status' => 'no_template', 'order' => '' );

		if ( self::template_for( $slot, $settings ) <= 0 ) {
			return $out;
		}

		$email = self::wc_emails()[ self::PARTIAL_REFUND === $slot ? 'customer_refunded_order' : $slot ] ?? null;

		if ( ! $email ) {
			$out['status'] = 'no_email';
			return $out;
		}

		// A copy: the mailer's own instance is the one WooCommerce sends with.
		$email = clone $email;
		$order = self::test_order( $user );

		$email->id = $slot;

		if ( in_array( $slot, array( 'customer_new_account', 'customer_reset_password' ), true ) ) {
			$email->object = $user;

			if ( 'customer_reset_password' === $slot ) {
				$email->user_login = $user->user_login;
				$email->reset_key  = 'chave-de-teste'; // Not a real key: the link shows where it leads, and does not work.
			} else {
				$email->user_login       = $user->user_login;
				$email->set_password_url = wc_get_page_permalink( 'myaccount' );
			}
		} else {
			$email->object = $order;

			if ( 'customer_note' === $slot ) {
				$email->customer_note = __( 'Esta é uma nota de exemplo, do teste do template.', 'galaxie-woo' );
			}

			if ( in_array( $slot, array( 'customer_refunded_order', self::PARTIAL_REFUND ), true ) ) {
				$refunds              = $order->get_id() ? $order->get_refunds() : array();
				$email->partial_refund = self::PARTIAL_REFUND === $slot;
				$email->refund        = $refunds ? reset( $refunds ) : false;
			}

			$out['order'] = $order->get_id() ? (string) $order->get_order_number() : 'exemplo';
		}

		$rendered = Sender::render( $email, $settings );

		if ( null === $rendered ) {
			$out['status'] = 'no_render';
			return $out;
		}

		$type = static fn(): string => 'text/html';
		add_filter( 'wp_mail_content_type', $type );
		/* translators: %s: the e-mail's subject. */
		$sent = wp_mail( $user->user_email, sprintf( __( '[Teste] %s', 'galaxie-woo' ), '' !== $rendered['subject'] ? $rendered['subject'] : self::emails()[ $slot ][0] ), $rendered['html'] );
		remove_filter( 'wp_mail_content_type', $type );

		$out['status'] = $sent ? 'sent' : 'failed';

		return $out;
	}

	/**
	 * The store's latest order, or an unsaved example: never written to the
	 * database (no calculate_totals(), which saves), so it leaves no trace.
	 */
	private static function test_order( \WP_User $user ): \WC_Order {
		$latest = wc_get_orders(
			array(
				'limit'   => 1,
				'orderby' => 'date',
				'order'   => 'DESC',
				'type'    => 'shop_order',
			)
		);

		if ( $latest && $latest[0] instanceof \WC_Order ) {
			return $latest[0];
		}

		$order = new \WC_Order();
		$order->set_currency( get_woocommerce_currency() );
		$order->set_billing_first_name( 'Maria' );
		$order->set_billing_last_name( 'Silva' );
		$order->set_billing_email( $user->user_email );
		$order->set_billing_address_1( 'Rua Exemplo, 100' );
		$order->set_billing_city( 'São Paulo' );
		$order->set_billing_state( 'SP' );
		$order->set_billing_postcode( '01001-000' );
		$order->set_billing_country( 'BR' );
		$order->set_shipping_first_name( 'Maria' );
		$order->set_shipping_last_name( 'Silva' );
		$order->set_shipping_address_1( 'Rua Exemplo, 100' );
		$order->set_shipping_city( 'São Paulo' );
		$order->set_shipping_state( 'SP' );
		$order->set_shipping_postcode( '01001-000' );
		$order->set_shipping_country( 'BR' );
		$order->set_payment_method_title( 'Pix' );
		$order->set_date_created( time() );

		$item = new \WC_Order_Item_Product();
		$item->set_name( __( 'Produto de exemplo', 'galaxie-woo' ) );
		$item->set_quantity( 2 );
		$item->set_subtotal( 178 );
		$item->set_total( 178 );
		$order->add_item( $item );

		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_title( 'PAC (3 a 5 dias úteis)' );
		$shipping->set_total( 25.9 );
		$order->add_item( $shipping );

		$order->set_shipping_total( 25.9 );
		$order->set_total( 203.9 );

		return $order;
	}

	/** The result of the last "Enviar teste", after its redirect. */
	private function test_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display of a redirect's result.
		if ( ! isset( $_GET['gx_test'] ) ) {
			return;
		}

		$status = sanitize_key( wp_unslash( $_GET['gx_test'] ) );
		$slot   = isset( $_GET['gx_email'] ) ? sanitize_key( wp_unslash( $_GET['gx_email'] ) ) : '';
		$order  = isset( $_GET['gx_order'] ) ? sanitize_text_field( wp_unslash( $_GET['gx_order'] ) ) : '';
		// phpcs:enable

		$label    = self::emails()[ $slot ][0] ?? $slot;
		$messages = array(
			/* translators: 1: e-mail label, 2: e-mail address, 3: order number. */
			'sent'        => array( 'success', sprintf( __( 'Teste de "%1$s" enviado para %2$s (pedido %3$s).', 'galaxie-woo' ), $label, wp_get_current_user()->user_email, '' !== $order ? $order : '—' ) ),
			'failed'      => array( 'error', __( 'O template foi montado, mas o envio falhou. Veja o log do FluentSMTP.', 'galaxie-woo' ) ),
			'no_template' => array( 'warning', __( 'Escolha um template para este e-mail e salve antes de testar.', 'galaxie-woo' ) ),
			'no_email'    => array( 'error', __( 'Este e-mail não existe nesta versão do WooCommerce.', 'galaxie-woo' ) ),
			'no_render'   => array( 'error', __( 'O template não pôde ser montado (FluentCRM desativado, ou o template foi apagado). Nesse caso a loja envia o e-mail padrão.', 'galaxie-woo' ) ),
		);

		if ( isset( $messages[ $status ] ) ) {
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $messages[ $status ][0] ), esc_html( $messages[ $status ][1] ) );
		}
	}

	// ------------------------------------------------------------- helpers

	/** @return array<string,\WC_Email> WooCommerce's e-mails by id; empty without WooCommerce. */
	private static function wc_emails(): array {
		if ( ! function_exists( 'WC' ) || ! is_object( WC() ) || ! method_exists( WC(), 'mailer' ) ) {
			return array();
		}

		$out = array();

		foreach ( (array) WC()->mailer()->get_emails() as $email ) {
			if ( $email instanceof \WC_Email ) {
				$out[ (string) $email->id ] = $email;
			}
		}

		return $out;
	}

	/**
	 * Whether YayMail (4.4) has an active template for this e-mail: a
	 * `yaymail_template` post whose `_yaymail_template` is the e-mail id and
	 * `_yaymail_status` is 'active'. Read from the posts, not through
	 * `YayMail\YayMailTemplate`, whose constructor inserts a missing template.
	 */
	private static function yaymail_active( string $slot ): bool {
		if ( ! defined( 'YAYMAIL_VERSION' ) ) {
			return false;
		}

		$ids = self::PARTIAL_REFUND === $slot ? array( $slot, 'customer_refunded_order' ) : array( $slot );

		$found = get_posts(
			array(
				'post_type'        => 'yaymail_template',
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin settings tab only.
					array(
						'key'     => '_yaymail_template',
						'value'   => $ids,
						'compare' => 'IN',
					),
					array(
						'key'   => '_yaymail_status',
						'value' => 'active',
					),
				),
			)
		);

		return ! empty( $found );
	}
}
