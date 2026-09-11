<?php
/**
 * "Galaxie Account Communication": what the store may send.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\MyAccount\Widget;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Galaxie\Woo\Support\AccountParts;
use Galaxie\Woo\Support\Assets;
use Galaxie\Woo\Support\PixfortControls;
use Galaxie\Woo\Support\ProfileFields;

defined( 'ABSPATH' ) || exit;

/**
 * The newsletter consent as a switch, saved the moment it moves.
 *
 * `galaxie_myaccount_save_communication` stores the consent on the customer and,
 * when a newsletter list is set under Galaxie → FluentCRM, adds or removes them
 * from it, so an unsubscribe here is an unsubscribe there.
 */
final class AccountCommunicationWidget extends Widget_Base {

	public function get_name(): string {
		return 'galaxie-account-communication';
	}

	public function get_title(): string {
		return __( 'Galaxie Account Communication', 'galaxie-woo' );
	}

	public function get_icon(): string {
		return 'eicon-mail';
	}

	/** @return array<int,string> */
	public function get_categories(): array {
		return array( 'galaxie' );
	}

	/** @return array<int,string> */
	public function get_keywords(): array {
		return array( 'account', 'newsletter', 'communication', 'comunicação', 'email' );
	}

	protected function register_controls(): void {
		$this->start_controls_section( 'comm_section', array( 'label' => __( 'Communication', 'galaxie-woo' ) ) );

		$this->add_control( 'comm_heading', array( 'label' => __( 'Heading', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Comunicação', 'galaxie-woo' ) ) );
		$this->add_control( 'comm_title', array( 'label' => __( 'Option title', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Novidades e ofertas por e-mail', 'galaxie-woo' ) ) );
		$this->add_control( 'comm_text', array( 'label' => __( 'Option description', 'galaxie-woo' ), 'type' => Controls_Manager::TEXTAREA, 'rows' => 2, 'default' => __( 'Lançamentos, rituais e promoções, de vez em quando. Você pode sair quando quiser.', 'galaxie-woo' ) ) );
		$this->add_control( 'comm_on_text', array( 'label' => __( 'Message when turned on', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Pronto, você vai receber nossas novidades.', 'galaxie-woo' ), 'separator' => 'before' ) );
		$this->add_control( 'comm_off_text', array( 'label' => __( 'Message when turned off', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Tudo certo, não enviaremos mais e-mails de novidades.', 'galaxie-woo' ) ) );

		$this->end_controls_section();

		$this->start_controls_section( 'comm_box_style', array( 'label' => __( 'Box', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'comm_box', '{{WRAPPER}} .galaxie-account-communication' );
		$this->end_controls_section();

		$this->start_controls_section( 'comm_option_style', array( 'label' => __( 'Option card', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'comm_option', '{{WRAPPER}} .galaxie-comm-option', array( 'rounded' => 'rounded-lg' ) );
		$this->end_controls_section();

		$texts = array(
			'comm_heading_text' => array( __( 'Heading', 'galaxie-woo' ), '.galaxie-comm-heading', array( 'size' => 'text-20', 'bold' => 'font-weight-bold' ) ),
			'comm_title_text'   => array( __( 'Option title', 'galaxie-woo' ), '.galaxie-comm-title', array( 'bold' => 'font-weight-bold' ) ),
			'comm_desc_text'    => array( __( 'Option description', 'galaxie-woo' ), '.galaxie-comm-text', array( 'size' => 'text-sm', 'bold' => '' ) ),
			'comm_msg_text'     => array( __( 'Messages', 'galaxie-woo' ), '.galaxie-account-message', array( 'size' => 'text-sm', 'bold' => '' ) ),
		);

		foreach ( $texts as $prefix => $text ) {
			$this->start_controls_section( $prefix . '_style', array( 'label' => $text[0], 'tab' => Controls_Manager::TAB_STYLE ) );
			PixfortControls::text( $this, $prefix, array_merge( $text[2], array( 'remove_pb_padding' => 'm-0' ) ), array(), '{{WRAPPER}} ' . $text[1], 'text', array( 'position' ) );
			$this->end_controls_section();
		}

		$this->start_controls_section( 'comm_switch_style', array( 'label' => __( 'Switch', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::palette_control( $this, 'comm_switch_off', __( 'Off', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-switch-track', 'background-color' );
		PixfortControls::palette_control( $this, 'comm_switch_on', __( 'On', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-switch input:checked + .galaxie-switch-track', 'background-color' );
		PixfortControls::palette_control( $this, 'comm_switch_knob', __( 'Knob', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-switch-track::after', 'background-color' );
		$this->end_controls_section();
	}

	protected function render(): void {
		if ( ! is_user_logged_in() && ! AccountParts::editing() ) {
			return;
		}

		Assets::enqueue();

		$s   = $this->get_settings_for_display();
		$on  = 'yes' === get_user_meta( get_current_user_id(), ProfileFields::MARKETING_OPT_IN, true );
		$id  = 'galaxie-comm-' . $this->get_id();
		$txt = static fn( string $prefix, string $class, string $content ): string => '' !== trim( $content ) ? sprintf( '<div class="%1$s">%2$s</div>', esc_attr( $class ), PixfortControls::render_text( $s, $prefix, esc_html( $content ) ) ) : '';

		printf(
			'<div class="galaxie-account-communication %1$s" data-on="%2$s" data-off="%3$s" data-msg-class="%4$s">%5$s',
			esc_attr( PixfortControls::surface_classes( $s, 'comm_box' ) ),
			esc_attr( (string) ( $s['comm_on_text'] ?? '' ) ),
			esc_attr( (string) ( $s['comm_off_text'] ?? '' ) ),
			esc_attr( PixfortControls::text_classes( $s, 'comm_msg_text' ) ),
			$txt( 'comm_heading_text', 'galaxie-comm-heading', (string) ( $s['comm_heading'] ?? '' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
		);

		printf(
			'<label class="galaxie-comm-option card %1$s" for="%2$s"><span class="galaxie-comm-copy">%3$s%4$s</span><span class="galaxie-switch"><input type="checkbox" id="%2$s" name="opt_in" value="1"%5$s /><span class="galaxie-switch-track" aria-hidden="true"></span></span></label><div class="galaxie-account-message" role="status" aria-live="polite" hidden></div></div>',
			esc_attr( PixfortControls::surface_classes( $s, 'comm_option' ) ),
			esc_attr( $id ),
			$txt( 'comm_title_text', 'galaxie-comm-title', (string) ( $s['comm_title'] ?? '' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
			$txt( 'comm_desc_text', 'galaxie-comm-text', (string) ( $s['comm_text'] ?? '' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
			checked( $on, true, false )
		);
	}
}
