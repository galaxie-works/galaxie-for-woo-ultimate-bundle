<?php
/**
 * "Galaxie Account Communication": what the store may send.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\MyAccount\Widget;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Galaxie\Woo\Integrations\FluentCRM as FluentCRMApi;
use Galaxie\Woo\Modules\FluentCRM\Module as FluentCRMModule;
use Galaxie\Woo\Support\AccountParts;
use Galaxie\Woo\Support\Assets;
use Galaxie\Woo\Support\PixfortControls;
use Galaxie\Woo\Support\ProfileFields;

defined( 'ABSPATH' ) || exit;

/**
 * The newsletter consent as a switch, saved the moment it moves, and whatever
 * other communications the merchant wrote under Galaxie → FluentCRM.
 *
 * `galaxie_myaccount_save_communication` stores the consent on the customer and,
 * when a newsletter list is set under Galaxie → FluentCRM, adds or removes them
 * from it, so an unsubscribe here is an unsubscribe there. The other switches
 * are a FluentCRM list each, nothing else: what the customer sees is the
 * contact's membership, and `galaxie_myaccount_toggle_communication` joins or
 * leaves the list. Nothing of them is stored on our side, so a list emptied in
 * FluentCRM shows as off here the moment it is.
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
		// The wording of every switch — the consent's included — is written once
		// under Galaxie → FluentCRM. It used to be typed here as well, which
		// meant two places to change one sentence.
		$this->add_control(
			'comm_wording_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'The titles and the lines under them are written in wp-admin → Galaxie → FluentCRM: the consent switch has its own two fields there, and the rest are the Communications rows.', 'galaxie-woo' ),
				'content_classes' => 'elementor-descriptor',
			)
		);
		$this->add_control(
			'comm_compact',
			array(
				'label'        => __( 'Short version (no description)', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
				'description'  => __( 'For a dashboard: the switch and its title, without the sentence under it.', 'galaxie-woo' ),
			)
		);

		$this->add_control(
			'comm_show_newsletter',
			array(
				'label'        => __( 'The newsletter consent', 'galaxie-woo' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'The consent the customer gave at checkout or signup, which is also a field on their profile. The communications below are FluentCRM lists only.', 'galaxie-woo' ),
				'separator'    => 'before',
			)
		);

		$this->add_control(
			'comm_options',
			array(
				'label'       => __( 'Communications to show', 'galaxie-woo' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'options'     => self::option_labels(),
				'default'     => array(),
				'description' => self::option_labels()
					// phpcs:ignore Generic.Files.LineLength.TooLong -- one sentence.
					? __( 'Empty shows every one of them. They are written under Galaxie → FluentCRM → Communications.', 'galaxie-woo' )
					: __( 'None are set yet. Write them under Galaxie → FluentCRM → Communications: a title, a line and the list each one joins.', 'galaxie-woo' ),
				'separator'   => 'before',
			)
		);

		$this->add_control(
			'comm_switch_place',
			array(
				'label'       => __( 'The switch sits', 'galaxie-woo' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => array(
					'end'   => __( 'After the text, same line', 'galaxie-woo' ),
					'start' => __( 'Before the text, same line', 'galaxie-woo' ),
					'below' => __( 'Under the text', 'galaxie-woo' ),
				),
				'default'     => 'end',
				'description' => __( 'On a narrow screen the same line wraps on its own, whichever is chosen.', 'galaxie-woo' ),
				'separator'   => 'before',
			)
		);

		$this->add_control( 'comm_on_text', array( 'label' => __( 'Message when turned on', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Pronto, você vai receber nossas novidades.', 'galaxie-woo' ), 'separator' => 'before' ) );
		$this->add_control( 'comm_off_text', array( 'label' => __( 'Message when turned off', 'galaxie-woo' ), 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => __( 'Tudo certo, não enviaremos mais e-mails de novidades.', 'galaxie-woo' ) ) );

		$this->end_controls_section();

		$this->start_controls_section( 'comm_box_style', array( 'label' => __( 'Box', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'comm_box', '{{WRAPPER}} .galaxie-account-communication' );
		$this->end_controls_section();

		$this->start_controls_section( 'comm_option_style', array( 'label' => __( 'Option card', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::surface( $this, 'comm_option', '{{WRAPPER}} .galaxie-comm-option', array( 'rounded' => 'rounded-lg' ) );

		$this->add_responsive_control(
			'comm_option_align',
			array(
				'label'     => __( 'Text alignment', 'galaxie-woo' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => array(
					'flex-start' => array( 'title' => __( 'Left', 'galaxie-woo' ), 'icon' => 'eicon-text-align-left' ),
					'center'     => array( 'title' => __( 'Center', 'galaxie-woo' ), 'icon' => 'eicon-text-align-center' ),
					'flex-end'   => array( 'title' => __( 'Right', 'galaxie-woo' ), 'icon' => 'eicon-text-align-right' ),
				),
				'separator' => 'before',
				'selectors' => array(
					'{{WRAPPER}} .galaxie-comm-copy'        => 'align-items: {{VALUE}}; text-align: {{VALUE}};',
					'{{WRAPPER}} .galaxie-account-message'  => 'text-align: {{VALUE}};',
				),
				'selectors_dictionary' => array( 'flex-start' => 'left', 'center' => 'center', 'flex-end' => 'right' ),
			)
		);

		$this->add_responsive_control(
			'comm_option_gap',
			array(
				'label'      => __( 'Space between the text and the switch', 'galaxie-woo' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 64 ) ),
				'selectors'  => array( '{{WRAPPER}} .galaxie-comm-option' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'comm_switch_self',
			array(
				'label'     => __( 'The switch, under the text, sits', 'galaxie-woo' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => array(
					'flex-start' => array( 'title' => __( 'Left', 'galaxie-woo' ), 'icon' => 'eicon-h-align-left' ),
					'center'     => array( 'title' => __( 'Center', 'galaxie-woo' ), 'icon' => 'eicon-h-align-center' ),
					'flex-end'   => array( 'title' => __( 'Right', 'galaxie-woo' ), 'icon' => 'eicon-h-align-right' ),
				),
				'condition' => array( 'comm_switch_place' => 'below' ),
				'selectors' => array( '{{WRAPPER}} .galaxie-comm-option' => '--galaxie-comm-switch-self: {{VALUE}};' ),
			)
		);

		$this->end_controls_section();

		$texts = array(
			'comm_heading_text' => array( __( 'Heading', 'galaxie-woo' ), '.galaxie-comm-heading', array( 'size' => 'text-20', 'bold' => 'font-weight-bold' ), true ),
			'comm_title_text'   => array( __( 'Option title', 'galaxie-woo' ), '.galaxie-comm-title', array( 'bold' => 'font-weight-bold' ), true ),
			'comm_desc_text'    => array( __( 'Option description', 'galaxie-woo' ), '.galaxie-comm-text', array( 'size' => 'text-sm', 'bold' => '' ), true ),
			'comm_msg_text'     => array( __( 'Messages', 'galaxie-woo' ), '.galaxie-account-message', array( 'size' => 'text-sm', 'bold' => '' ) ),
		);

		// A fourth `true` marks text drawn by pixfort's Text element; the rest are
		// classes on this widget's own markup, which carry fewer controls.
		foreach ( $texts as $prefix => $text ) {
			$this->start_controls_section( $prefix . '_style', array( 'label' => $text[0], 'tab' => Controls_Manager::TAB_STYLE ) );
			PixfortControls::text( $this, $prefix, array_merge( $text[2], array( 'remove_pb_padding' => 'm-0' ) ), array(), '{{WRAPPER}} ' . $text[1], 'text', empty( $text[3] ) ? array( 'position', 'inline' ) : array( 'position' ) );
			$this->end_controls_section();
		}

		$this->start_controls_section( 'comm_switch_style', array( 'label' => __( 'Switch', 'galaxie-woo' ), 'tab' => Controls_Manager::TAB_STYLE ) );
		PixfortControls::palette_control( $this, 'comm_switch_off', __( 'Off', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-switch-track', 'background-color' );
		PixfortControls::palette_control( $this, 'comm_switch_on', __( 'On', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-switch input:checked + .galaxie-switch-track', 'background-color' );
		PixfortControls::palette_control( $this, 'comm_switch_knob', __( 'Knob', 'galaxie-woo' ), '{{WRAPPER}} .galaxie-switch-track::after', 'background-color' );
		$this->end_controls_section();
	}

	/**
	 * The configured communications, by list id, for the picker above.
	 *
	 * @return array<int,string>
	 */
	private static function option_labels(): array {
		$labels = array();

		foreach ( FluentCRMModule::communications() as $row ) {
			$labels[ $row['list_id'] ] = $row['title'];
		}

		return $labels;
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

		$place = esc_attr( in_array( $s['comm_switch_place'] ?? 'end', array( 'start', 'below' ), true ) ? (string) $s['comm_switch_place'] : 'end' );

		if ( 'yes' === ( $s['comm_show_newsletter'] ?? 'yes' ) ) {
				$this->newsletter( $s, $txt, $id, $on, $place );
		}

		$this->communications( $s, $txt, $place );

		echo '<div class="galaxie-account-message" role="status" aria-live="polite" hidden></div></div>';
	}

	/**
	 * The consent switch the store has always had: a customer field, and a
	 * FluentCRM list beside it when one is mapped.
	 *
	 * @param array<string,mixed> $s     Widget settings.
	 * @param callable            $txt   The text helper render() builds.
	 * @param string              $id    The input's id.
	 * @param bool                $on    Whether consent is given.
	 * @param string              $place Where the switch sits.
	 */
	private function newsletter( array $s, callable $txt, string $id, bool $on, string $place ): void {
		// A wording typed into this widget before the settings had these two
		// fields still wins over the default: nothing a merchant wrote is lost.
		$wording = FluentCRMModule::newsletter_wording(
			array(
				'title' => (string) ( $s['comm_title'] ?? '' ),
				'text'  => (string) ( $s['comm_text'] ?? '' ),
			)
		);

		printf(
			'<label class="galaxie-comm-option card is-switch-%6$s %1$s" for="%2$s"><span class="galaxie-comm-copy">%3$s%4$s</span><span class="galaxie-switch"><input type="checkbox" id="%2$s" name="opt_in" value="1"%5$s /><span class="galaxie-switch-track" aria-hidden="true"></span></span></label>',
			esc_attr( PixfortControls::surface_classes( $s, 'comm_option' ) ),
			esc_attr( $id ),
			$txt( 'comm_title_text', 'galaxie-comm-title', $wording['title'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
			'yes' === ( $s['comm_compact'] ?? '' ) ? '' : $txt( 'comm_desc_text', 'galaxie-comm-text', $wording['text'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
			checked( $on, true, false ),
			esc_attr( $place )
		);
	}

	/**
	 * One switch per communication the merchant wrote, showing whether the
	 * contact is on that list. In the editor they are all drawn off, because
	 * there is no contact to ask.
	 *
	 * @param array<string,mixed> $s     Widget settings.
	 * @param callable            $txt   The text helper render() builds.
	 * @param string              $place Where the switch sits.
	 */
	private function communications( array $s, callable $txt, string $place ): void {
		// With the consent switch on the screen, its own list is already
		// answered for; a row on it would be the same question twice.
		$rows = FluentCRMModule::communications( 'yes' === ( $s['comm_show_newsletter'] ?? 'yes' ) );

		if ( ! $rows ) {
			return;
		}

		$wanted = array_map( 'absint', (array) ( $s['comm_options'] ?? array() ) );
		$on     = AccountParts::editing() ? array() : FluentCRMApi::contact_list_ids( (string) wp_get_current_user()->user_email );

		foreach ( $rows as $i => $row ) {
			if ( $wanted && ! in_array( $row['list_id'], $wanted, true ) ) {
				continue;
			}

			$id = 'galaxie-comm-' . $this->get_id() . '-' . $row['list_id'];

			printf(
				'<label class="galaxie-comm-option card is-switch-%7$s %1$s" for="%2$s" data-list="%6$d"><span class="galaxie-comm-copy">%3$s%4$s</span><span class="galaxie-switch"><input type="checkbox" id="%2$s" name="communication" value="%6$d"%5$s /><span class="galaxie-switch-track" aria-hidden="true"></span></span></label>',
				esc_attr( PixfortControls::surface_classes( $s, 'comm_option' ) ),
				esc_attr( $id ),
				$txt( 'comm_title_text', 'galaxie-comm-title', $row['title'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
				'yes' === ( $s['comm_compact'] ?? '' ) ? '' : $txt( 'comm_desc_text', 'galaxie-comm-text', $row['text'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pixfort's own element around escaped text.
				checked( in_array( $row['list_id'], $on, true ), true, false ),
				(int) $row['list_id'],
				esc_attr( $place )
			);

			unset( $i );
		}
	}
}
