<?php
/**
 * "Galaxie Account Delete": the customer closes their account.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\MyAccount\Widget;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Galaxie\Woo\Core\Plugin;
use Galaxie\Woo\Support\AccountParts;
use Galaxie\Woo\Support\Assets;
use Galaxie\Woo\Support\PixfortControls;

defined( 'ABSPATH' ) || exit;

/**
 * A card that explains what deleting means, and a confirmation before it happens.
 *
 * The request goes to the Account Deletion module's own handler: the account is
 * flagged, the customer signed out, and the account purged six months later
 * unless they sign back in. The dialog is a native `<dialog>`, so focus, Escape
 * and the backdrop behave without a script library.
 */
final class AccountDeleteWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-account-delete';
	}

	public function get_title(): string {
		return __( 'Galaxie Account Delete', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-trash-o';
	}

	/** @return array<int,string> */
	public function get_categories(): array {
		return array( 'galaxie' );
	}

	/** @return array<int,string> */
	public function get_keywords(): array {
		return array( 'account', 'delete', 'excluir', 'lgpd', 'privacy' );
	}

	public static function available(): bool {
		return Plugin::instance()->modules()->is_enabled_by_id( 'account-deletion' );
	}

	protected function register_controls(): void {
		$this->start_controls_section( 'delete_section', array( 'label' => __( 'Delete account', 'galaxie-woo' ) ) );

		$this->add_control( 'delete_title', array( 'label' => __( 'Title', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Excluir conta', 'galaxie-woo' ) ) );
		$this->add_control( 'delete_text', array( 'label' => __( 'Text', 'galaxie-woo' ), 'type' => Controls_Manager::TEXTAREA, 'rows' => 3, 'default' => __( 'Sua conta é desativada na hora e excluída de vez depois de 6 meses. Se você entrar de novo nesse período, a exclusão é cancelada.', 'galaxie-woo' ) ) );
		$this->add_control( 'delete_dialog_title', array( 'label' => __( 'Confirmation title', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Excluir sua conta?', 'galaxie-woo' ), 'separator' => 'before' ) );
		$this->add_control( 'delete_dialog_text', array( 'label' => __( 'Confirmation text', 'galaxie-woo' ), 'type' => Controls_Manager::TEXTAREA, 'rows' => 3, 'default' => __( 'Você será desconectado agora. Pedidos em andamento continuam sendo entregues normalmente.', 'galaxie-woo' ) ) );

		$this->end_controls_section();

		$buttons = array(
			'delete_open'    => array( __( 'Delete button', 'galaxie-woo' ), array( 'text' => __( 'Excluir minha conta', 'galaxie-woo' ), 'style' => 'outline', 'color' => 'red', 'text_color' => 'red', 'size' => 'sm' ) ),
			'delete_confirm' => array( __( 'Confirm button', 'galaxie-woo' ), array( 'text' => __( 'Sim, excluir', 'galaxie-woo' ), 'color' => 'red', 'text_color' => 'white' ) ),
			'delete_cancel'  => array( __( 'Cancel button', 'galaxie-woo' ), array( 'text' => __( 'Cancelar', 'galaxie-woo' ), 'style' => 'link' ) ),
		);

		foreach ( $buttons as $prefix => $button ) {
			$this->start_controls_section( $prefix . '_section', array( 'label' => $button[0] ) );
			PixfortControls::button( $this, $prefix, $button[1] );
			$this->end_controls_section();
		}

		$this->start_controls_section( 'delete_box_style', array( 'label' => __( 'Card', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'delete_box', '{{WRAPPER}} .galaxie-account-delete', array( 'rounded' => 'rounded-lg' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'delete_dialog_style', array( 'label' => __( 'Confirmation', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'delete_dialog', '{{WRAPPER}} .galaxie-delete-dialog', array( 'rounded' => 'rounded-xl', 'shadow' => '4' ) );
		$this->end_controls_section();

		$texts = array(
			'delete_title_text' => array( __( 'Titles', 'galaxie-woo' ), '.galaxie-delete-title', array( 'size' => 'text-18', 'bold' => 'font-weight-bold', 'content_color' => 'red' ) ),
			'delete_body_text'  => array( __( 'Texts', 'galaxie-woo' ), '.galaxie-delete-text', array( 'size' => 'text-sm', 'bold' => '' ) ),
			'delete_msg_text'   => array( __( 'Error message', 'galaxie-woo' ), '.galaxie-account-message', array( 'size' => 'text-sm', 'bold' => '', 'content_color' => 'red' ) ),
		);

		foreach ( $texts as $prefix => $text ) {
			$this->start_controls_section( $prefix . '_style', array( 'label' => $text[0], 'tab' => Controls_Manager::TAB_STYLE ) );
			PixfortControls::text( $this, $prefix, array_merge( $text[2], array( 'remove_pb_padding' => 'm-0' ) ), array(), '{{WRAPPER}} ' . $text[1], 'text', array( 'position' ) );
			$this->end_controls_section();
		}
	}

	protected function render(): void {
		if ( ( ! is_user_logged_in() && ! AccountParts::editing() ) ) {
			return;
		}

		if ( ! self::available() ) {
			echo AccountParts::editor_note( __( 'Turn on the Account Deletion module to offer this.', 'galaxie-woo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			return;
		}

		Assets::enqueue();

		$s   = $this->get_settings_for_display();
		$txt = static fn( string $prefix, string $class, string $content ): string => '' !== trim( $content ) ? sprintf( '<div class="%1$s">%2$s</div>', esc_attr( $class ), PixfortControls::render_text( $s, $prefix, esc_html( $content ) ) ) : '';

		printf(
			'<div class="galaxie-account-delete card %1$s" data-msg-class="%2$s">%3$s%4$s<div class="galaxie-delete-actions"><button type="button" class="galaxie-account-submit galaxie-delete-open">%5$s</button></div>',
			esc_attr( PixfortControls::surface_classes( $s, 'delete_box' ) ),
			esc_attr( PixfortControls::text_classes( $s, 'delete_msg_text' ) ),
			$txt( 'delete_title_text', 'galaxie-delete-title', (string) ( $s['delete_title'] ?? '' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
			$txt( 'delete_body_text', 'galaxie-delete-text', (string) ( $s['delete_text'] ?? '' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
			PixfortControls::render_button( $s, 'delete_open', (string) ( $s['delete_open_text'] ?? '' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own button.
		);

		printf(
			'<dialog class="galaxie-delete-dialog %1$s">%2$s%3$s<div class="galaxie-account-message" role="alert" hidden></div><div class="galaxie-delete-dialog-actions"><button type="button" class="galaxie-account-submit galaxie-delete-cancel">%4$s</button><button type="button" class="galaxie-account-submit galaxie-delete-confirm">%5$s</button></div></dialog></div>',
			esc_attr( PixfortControls::surface_classes( $s, 'delete_dialog' ) ),
			$txt( 'delete_title_text', 'galaxie-delete-title', (string) ( $s['delete_dialog_title'] ?? '' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
			$txt( 'delete_body_text', 'galaxie-delete-text', (string) ( $s['delete_dialog_text'] ?? '' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
			PixfortControls::render_button( $s, 'delete_cancel', (string) ( $s['delete_cancel_text'] ?? '' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own button.
			PixfortControls::render_button( $s, 'delete_confirm', (string) ( $s['delete_confirm_text'] ?? '' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own button.
		);
	}
}
