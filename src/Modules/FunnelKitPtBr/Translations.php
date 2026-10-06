<?php
/**
 * Portuguese (Brazil) for what FunnelKit's Stripe gateway shows customers.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\FunnelKitPtBr;

defined( 'ABSPATH' ) || exit;

/**
 * FunnelKit Payment Gateway for Stripe WooCommerce ships no pt_BR translation
 * (only fr_FR and its .pot), so a Brazilian checkout showed its gateway names,
 * card-form labels, card errors and payment notices in English. Three layers,
 * each touching only what FunnelKit itself would have translated:
 *
 * 1. `gettext_funnelkit-stripe-woo-payment-gateway` — every string FunnelKit
 *    passes through `__()` / `esc_html__()` in its own text domain: default
 *    gateway titles and descriptions, the save-card label, the CVC tip, wallet
 *    sheet lines, checkout notices. A string some installed translation already
 *    changed (`$translation !== $text`) is left to that translation.
 * 2. `fkwcs_stripe_localized_messages` — the card error messages, keyed by
 *    Stripe's error / decline code. FunnelKit puts them through gettext too,
 *    but it also hands the list to its script as `fkwcs_data.stripe_localized`
 *    and resolves server-side errors from it by code; translating by code keeps
 *    them right should FunnelKit reword an English sentence.
 * 3. `woocommerce_gateway_title` / `woocommerce_gateway_description` — the
 *    gateway's title as stored. Once the merchant saves FunnelKit's settings the
 *    English default is stored as the setting, and gettext never sees a stored
 *    value. Only a value still equal to FunnelKit's English default is
 *    translated: a title the merchant wrote is theirs.
 *
 * Nothing changes unless the request's locale is Portuguese.
 */
final class Translations {

	public const DOMAIN = 'funnelkit-stripe-woo-payment-gateway';

	public static function hooks(): void {
		add_filter( 'gettext_' . self::DOMAIN, array( self::class, 'gettext' ), 10, 2 );
		add_filter( 'fkwcs_stripe_localized_messages', array( self::class, 'messages' ), 20 );
		add_filter( 'woocommerce_gateway_title', array( self::class, 'gateway_title' ), 20, 2 );
		add_filter( 'woocommerce_gateway_description', array( self::class, 'gateway_description' ), 20, 2 );
	}

	/** Whether this request speaks Portuguese. */
	public static function active(): bool {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();

		return 0 === strpos( strtolower( (string) $locale ), 'pt' );
	}

	/**
	 * @param string $translation What gettext made of `$text` so far.
	 * @param string $text        FunnelKit's English original.
	 */
	public static function gettext( $translation, $text ): string {
		$translation = (string) $translation;

		if ( $translation !== (string) $text || ! self::active() ) {
			return $translation;
		}

		return self::strings()[ $translation ] ?? $translation;
	}

	/**
	 * FunnelKit's card error messages, by code, in Portuguese. Codes without one
	 * here (bank-account, Connect and API-usage errors no card shopper meets)
	 * keep what they had.
	 *
	 * @param mixed $messages
	 * @return mixed
	 */
	public static function messages( $messages ) {
		if ( ! is_array( $messages ) || ! self::active() ) {
			return $messages;
		}

		foreach ( self::card_messages() as $code => $message ) {
			if ( array_key_exists( $code, $messages ) ) {
				$messages[ $code ] = $message;
			}
		}

		return $messages;
	}

	/**
	 * @param string $title
	 * @param string $gateway_id
	 */
	public static function gateway_title( $title, $gateway_id = '' ) {
		return self::stored_default( $title, (string) $gateway_id, 0 );
	}

	/**
	 * @param string $description
	 * @param string $gateway_id
	 */
	public static function gateway_description( $description, $gateway_id = '' ) {
		return self::stored_default( $description, (string) $gateway_id, 1 );
	}

	/**
	 * `$value` in Portuguese when it is still FunnelKit's English default
	 * title (`$which` 0) or description (1) for that gateway; otherwise as is.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function stored_default( $value, string $gateway_id, int $which ) {
		$defaults = self::gateway_defaults()[ $gateway_id ] ?? null;

		if ( ! $defaults || ! is_string( $value ) || trim( $value ) !== $defaults[ $which ] || ! self::active() ) {
			return $value;
		}

		return self::strings()[ $defaults[ $which ] ] ?? $value;
	}

	/**
	 * FunnelKit's English default title and description of each gateway a
	 * customer can meet, by gateway id.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function gateway_defaults(): array {
		return array(
			'fkwcs_stripe'            => array( 'Credit Card (Stripe)', 'Pay with your credit card via Stripe' ),
			'fkwcs_stripe_pix'        => array( 'Stripe Pix', 'Pay with Pix' ),
			'fkwcs_stripe_apple_pay'  => array( 'Apple Pay', 'Pay with Apple Pay' ),
			'fkwcs_stripe_google_pay' => array( 'Google Pay', 'Pay with your Google Pay' ),
		);
	}

	/**
	 * FunnelKit's customer-facing strings, English original => Portuguese. The
	 * originals are exactly as FunnelKit writes them (placeholders included,
	 * `%1$1s` and all), or gettext would not match them.
	 *
	 * @return array<string,string>
	 */
	public static function strings(): array {
		static $strings = null;

		if ( null !== $strings ) {
			return $strings;
		}

		$strings = array(
			// Gateway titles and descriptions (defaults; see stored_default()).
			'Credit Card (Stripe)'                  => 'Cartão de crédito (Stripe)',
			'Pay with your credit card via Stripe'  => 'Pague com seu cartão de crédito via Stripe',
			'Stripe Pix'                            => 'Pix (Stripe)',
			'Pay with Pix'                          => 'Pague com Pix',
			'Pay with Apple Pay'                    => 'Pague com Apple Pay',
			'Pay with your Google Pay'              => 'Pague com seu Google Pay',
			'Or'                                    => 'Ou',

			// The card form.
			'Save payment information to my account for future purchases.' => 'Salvar os dados de pagamento na minha conta para compras futuras.',
			'3 digit Security Code usually found on the back of your card. American Express Cards have 4 digit code usually found on the front.' => 'Código de segurança de 3 dígitos, geralmente no verso do cartão. Cartões American Express têm um código de 4 dígitos, geralmente na frente.',
			'is not allowed'                        => 'não é aceito',
			'%1$1s Test Mode Enabled:%2$2s Use demo card 4242424242424242 with any future date and CVV. Check more %3$3sdemo cards%4$4s' => '%1$1s Modo de teste ativo:%2$2s use o cartão de teste 4242424242424242 com qualquer data futura e qualquer CVV. Veja mais %3$3scartões de teste%4$4s',

			// Notices when a payment or a saved card fails.
			'Payment failed. Please try again or contact support.' => 'O pagamento não foi concluído. Tente de novo ou fale com o nosso atendimento.',
			'Payment is still processing. Please try again or use an alternative payment method.' => 'O pagamento ainda está em processamento. Tente de novo ou use outra forma de pagamento.',
			'Payment processing failed. Please retry.' => 'Não foi possível processar o pagamento. Tente de novo.',
			'Payment verification error: %s'        => 'Erro ao verificar o pagamento: %s',
			'Sorry, the minimum allowed order total is %1$s to use this payment method.' => 'Desculpe, o valor mínimo do pedido para usar esta forma de pagamento é %1$s.',
			'Sorry, we were unable to process your payment right now. Please try again in a few moments.' => 'Desculpe, não conseguimos processar seu pagamento agora. Tente de novo em alguns instantes.',
			'Stripe SCA authentication failed.'     => 'A autenticação do pagamento com o banco falhou.',
			'Stripe SCA authentication failed. Reason: %s' => 'A autenticação do pagamento com o banco falhou. Motivo: %s',
			'The selected saved card could not be found for this order. Please try a different payment method.' => 'O cartão salvo escolhido não foi encontrado para este pedido. Use outra forma de pagamento.',
			'There was a problem adding the payment method.' => 'Houve um problema ao adicionar a forma de pagamento.',
			'Unable to process this payment, please try again or use alternative method.' => 'Não foi possível processar este pagamento. Tente de novo ou use outra forma de pagamento.',
			'We are unable to process payments using the selected method. Please choose a different payment method.' => 'Não conseguimos processar pagamentos com a forma escolhida. Escolha outra forma de pagamento.',
			'We do not accept %s. Please use a different card.' => 'Não aceitamos %s. Use outro cartão.',
			'Invalid order. Please refresh the page and try again.' => 'Pedido inválido. Recarregue a página e tente de novo.',
			'Invalid stripe source'                 => 'Forma de pagamento inválida.',
			'Unable to attach payment method to customer' => 'Não foi possível vincular a forma de pagamento à sua conta.',
			'Stripe is not configured properly.'    => 'O pagamento com Stripe não está configurado corretamente.',
			'Error: Unable to get payment method from the browser, please check for browser console error. ' => 'Erro: não foi possível obter a forma de pagamento no navegador. Recarregue a página e tente de novo. ',
			'Invalid payment gateway.'              => 'Forma de pagamento inválida.',
			'Invalid product.'                      => 'Produto inválido.',
			'Product with the ID (%d) cannot be found.' => 'O produto com o ID (%d) não foi encontrado.',
			'You cannot add that amount of "%1$s"; to the cart because there is not enough stock (%2$s remaining).' => 'Não é possível adicionar essa quantidade de "%1$s" ao carrinho porque não há estoque suficiente (%2$s disponíveis).',

			// Apple Pay / Google Pay: the wallet sheet's lines and messages.
			'Shipping address is invalid or no shipping methods are available. Please update your address.' => 'O endereço de entrega é inválido ou não tem opções de frete. Atualize o endereço.',
			'Your shipping address is not serviceable.' => 'Não entregamos no seu endereço.',
			'loading shipping methods...'           => 'carregando opções de frete...',
			'Stripe enabled Payment Request is not available in this browser' => 'O pagamento por carteira digital não está disponível neste navegador',
			'Waiting...'                            => 'Aguardando...',
			'Empty cart'                            => 'Carrinho vazio',
			'Pending'                               => 'Pendente',
			'Shipping'                              => 'Frete',
			'Discount'                              => 'Desconto',
			'Tax'                                   => 'Impostos',
			'Sign up Fee'                           => 'Taxa de adesão',
		);

		return $strings;
	}

	/**
	 * Card error messages by Stripe code (error codes and the issuers' decline
	 * codes), as FunnelKit keys them in `Helper::get_localized_messages()`.
	 * Written to be read by the shopper: the script shows them under the card
	 * form, and PHP turns a declined payment into a checkout notice from them.
	 *
	 * @return array<string,string>
	 */
	public static function card_messages(): array {
		$declined = 'O cartão foi recusado por um motivo não informado. Fale com o banco emissor do cartão para mais informações.';

		return array(
			'stripe_cc_generic'                 => 'Houve um erro ao processar seu cartão de crédito.',
			'incomplete_number'                 => 'O número do cartão está incompleto.',
			'incomplete_expiry'                 => 'A data de validade do cartão está incompleta.',
			'incomplete_cvc'                    => 'O código de segurança do cartão está incompleto.',
			'incomplete_zip'                    => 'O CEP do cartão está incompleto.',
			'incorrect_number'                  => 'O número do cartão está incorreto. Confira o número ou use outro cartão.',
			'incorrect_cvc'                     => 'O código de segurança do cartão está incorreto. Confira o código ou use outro cartão.',
			'incorrect_zip'                     => 'O CEP do cartão está incorreto. Confira o CEP ou use outro cartão.',
			'invalid_number'                    => 'O número do cartão é inválido. Confira os dados do cartão ou use outro cartão.',
			'invalid_characters'                => 'O campo tem caracteres que não são aceitos.',
			'invalid_cvc'                       => 'O código de segurança do cartão é inválido. Confira o código ou use outro cartão.',
			'invalid_expiry_month'              => 'O mês de validade do cartão está incorreto. Confira a validade ou use outro cartão.',
			'invalid_expiry_year'               => 'O ano de validade do cartão está incorreto. Confira a validade ou use outro cartão.',
			'incorrect_address'                 => 'O endereço do cartão está incorreto. Confira o endereço ou use outro cartão.',
			'expired_card'                      => 'O cartão está vencido. Confira a validade ou use outro cartão.',
			'card_declined'                     => 'O cartão foi recusado.',
			'invalid_expiry_year_past'          => 'O ano de validade do cartão já passou.',
			'amount_too_large'                  => 'O valor é maior que o máximo permitido. Use um valor menor e tente de novo.',
			'amount_too_small'                  => 'O valor é menor que o mínimo permitido. Use um valor maior e tente de novo.',
			'authentication_required'           => 'Este pagamento precisa ser autenticado com o banco. Tente de novo ou use outro cartão que não exija autenticação.',
			'card_decline_rate_limit_exceeded'  => 'Este cartão foi recusado muitas vezes. Tente de novo daqui a 24 horas ou use outro cartão.',
			'email_invalid'                     => 'O e-mail é inválido. Confira se está escrito corretamente.',
			'postal_code_invalid'               => 'O CEP informado está incorreto.',
			'processing_error'                  => 'Ocorreu um erro ao processar o cartão. Tente de novo mais tarde ou use outra forma de pagamento.',
			'card_not_supported'                => 'O cartão não aceita este tipo de compra.',
			'call_issuer'                       => 'O cartão foi recusado por um motivo desconhecido.',
			'card_velocity_exceeded'            => 'O saldo ou o limite de crédito do cartão foi excedido.',
			'currency_not_supported'            => 'O cartão não aceita a moeda desta compra.',
			'do_not_honor'                      => $declined,
			'fraudulent'                        => 'O pagamento foi recusado por suspeita de fraude.',
			'generic_decline'                   => $declined,
			'incorrect_pin'                     => 'A senha informada está incorreta.',
			'insufficient_funds'                => 'O cartão não tem saldo suficiente para esta compra.',
			'empty_element'                     => 'Escolha uma forma de pagamento para continuar.',
			'incomplete_boleto_tax_id'          => 'Informe um CPF ou CNPJ válido.',
			'test_mode_live_card'               => 'O cartão foi recusado: a loja está em modo de teste e um cartão real foi usado. No modo de teste só cartões de teste são aceitos.',
			'phone_required'                    => 'Informe um telefone de cobrança.',
			'approve_with_id'                   => 'O pagamento não pôde ser autorizado. Tente de novo; se o problema continuar, fale com o banco emissor do cartão.',
			'do_not_try_again'                  => $declined,
			'duplicate_transaction'             => 'Uma compra com o mesmo valor e o mesmo cartão foi enviada há pouco. Confira se ela já não foi paga.',
			'expired_card_decline'              => 'O cartão está vencido. Use outro cartão.',
			'invalid_account'                   => 'O cartão, ou a conta ligada a ele, é inválido. Fale com o banco emissor do cartão.',
			'invalid_amount'                    => 'O valor do pagamento é inválido ou maior que o permitido. Se o valor estiver certo, fale com o banco emissor do cartão.',
			'invalid_pin'                       => 'A senha informada está incorreta.',
			'issuer_not_available'              => 'Não foi possível falar com o banco emissor do cartão para autorizar o pagamento. Tente de novo; se o problema continuar, fale com o banco.',
			'lost_card'                         => 'O pagamento foi recusado porque o cartão foi informado como perdido.',
			'merchant_blacklist'                => 'O pagamento foi recusado.',
			'new_account_information_available' => 'O cartão, ou a conta ligada a ele, é inválido. Fale com o banco emissor do cartão.',
			'no_action_taken'                   => $declined,
			'not_permitted'                     => 'O pagamento não é permitido. Fale com o banco emissor do cartão.',
			'offline_pin_required'              => 'O cartão foi recusado porque exige senha.',
			'online_or_offline_pin_required'    => 'O cartão foi recusado porque exige senha.',
			'pickup_card'                       => 'Este cartão não pode ser usado neste pagamento. Fale com o banco emissor do cartão.',
			'pin_try_exceeded'                  => 'O número de tentativas de senha foi excedido. Use outro cartão ou outra forma de pagamento.',
			'reenter_transaction'               => 'O banco não conseguiu processar o pagamento. Tente de novo; se o problema continuar, fale com o banco emissor do cartão.',
			'restricted_card'                   => 'Este cartão não pode ser usado neste pagamento. Fale com o banco emissor do cartão.',
			'revocation_of_all_authorizations'  => $declined,
			'revocation_of_authorization'       => $declined,
			'security_violation'                => 'O pagamento foi recusado por motivos de segurança. Fale com o banco emissor do cartão.',
			'service_not_allowed'               => $declined,
			'stolen_card'                       => 'O pagamento foi recusado porque o cartão foi informado como roubado.',
			'stop_payment_order'                => $declined,
			'testmode_decline'                  => 'Um cartão de teste da Stripe foi usado. Use um cartão real para pagar.',
			'transaction_not_allowed'           => $declined,
			'try_again_later'                   => 'O cartão foi recusado por um motivo não informado. Tente de novo; se o problema continuar, fale com o banco emissor do cartão.',
			'withdrawal_count_limit_exceeded'   => 'O saldo ou o limite de crédito do cartão foi excedido. Use outra forma de pagamento.',
		);
	}
}
