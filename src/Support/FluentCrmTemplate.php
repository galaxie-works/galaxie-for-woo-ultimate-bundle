<?php
/**
 * A FluentCRM Email Template, rendered for a one-off transactional e-mail.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a template the merchant designed in FluentCRM (FluentCRM → Emails →
 * Templates) outside any campaign, the way FluentCRM renders its own one-off
 * transactional mail, the double opt-in confirmation
 * (`Mailer\Handler::sendDoubleOptInEmail()` in FluentCRM 3.2): block content
 * through its BlockParser, smartcodes through its parser, the template's
 * design wrapper. None of the campaign machinery runs: no open pixel, no
 * rewritten links, no compliance footer, no List-Unsubscribe header — a
 * sign-in code or an order confirmation is not a campaign, and the person may
 * not even be a contact.
 *
 * Shared by the sign-in code e-mail ({@see \Galaxie\Woo\Modules\PasswordlessAuth\OtpMail})
 * and the store's WooCommerce e-mails ({@see \Galaxie\Woo\Modules\StoreEmails\Module}).
 *
 * Our own smartcodes (`{{galaxie.*}}`, `{{pedido.*}}`…) are written in before
 * FluentCRM's parser sees the text, so they work for someone who is not a
 * contact, where FluentCRM would give every smartcode its default. Values
 * that are markup (an items table, an address with line breaks) go in as
 * opaque tokens and are swapped in only after the design wrapper: nothing in
 * FluentCRM's pipeline gets to escape, autop or re-parse them, and a
 * `{{…}}` a shopper typed into their order note is never resolved.
 */
final class FluentCrmTemplate {

	/** FluentCRM's email template post type (`fluentcrmTemplateCPTSlug()`). */
	public const POST_TYPE = 'fc_template';

	/** Designs whose content is not block markup: the BlockParser is skipped. */
	private const RAW_DESIGNS = array( 'raw_html', 'visual_builder', 'raw_classic' );

	/** Whether FluentCRM is loaded at all. */
	public static function crm_active(): bool {
		return defined( 'FLUENTCRM' ) || function_exists( 'FluentCrmApi' );
	}

	/** Whether FluentCRM's renderer classes are there to render with. */
	public static function can_render(): bool {
		return self::crm_active() && class_exists( '\FluentCrm\App\Services\Helper' );
	}

	/**
	 * The FluentCRM Email Templates, as id => title, for a settings select.
	 * Empty when FluentCRM is not active.
	 *
	 * @return array<string,string>
	 */
	public static function templates(): array {
		if ( ! self::crm_active() ) {
			return array();
		}

		// Straight from WordPress, not through FluentCRM's ORM: its models
		// need FluentCRM's own container, and a query that failed there was
		// swallowed into an empty list — the templates never showed. These are
		// plain posts of FluentCRM's template post type.
		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => array( 'publish', 'draft', 'private' ),
				'posts_per_page'   => 200,
				'orderby'          => 'ID',
				'order'            => 'DESC',
				'suppress_filters' => true,
			)
		);

		$out = array();
		foreach ( $posts as $post ) {
			$title = '' !== (string) $post->post_title ? (string) $post->post_title : sprintf( '#%d', $post->ID );
			$out[ (string) $post->ID ] = 'publish' === $post->post_status ? $title : sprintf( '%s (%s)', $title, $post->post_status );
		}

		return $out;
	}

	/** The template post, when `$id` still is one: not deleted, not in the trash. */
	public static function template( int $id ): ?\WP_Post {
		if ( $id <= 0 ) {
			return null;
		}

		$post = get_post( $id );

		return $post instanceof \WP_Post && self::POST_TYPE === $post->post_type && 'trash' !== $post->post_status ? $post : null;
	}

	/**
	 * The contact with this e-mail, or an unsaved one built from `$fields`
	 * (first_name, last_name, phone, address_line_1, city…) so FluentCRM's
	 * own `{{contact.*}}` smartcodes fill in for someone who is not a contact.
	 * A null subscriber would give every smartcode its default. Null when
	 * FluentCRM's model is not there.
	 *
	 * @param array<string,string> $fields
	 * @return object|null A `\FluentCrm\App\Models\Subscriber`.
	 */
	public static function subscriber( string $email, array $fields = array() ) {
		if ( ! class_exists( '\FluentCrm\App\Models\Subscriber' ) ) {
			return null;
		}

		$subscriber = '' !== $email ? \FluentCrm\App\Models\Subscriber::where( 'email', $email )->first() : null;

		return $subscriber ? $subscriber : new \FluentCrm\App\Models\Subscriber( array( 'email' => $email ) + $fields );
	}

	/**
	 * Renders template `$template_id`.
	 *
	 * `$codes` holds our smartcode groups: group key => [ 'values' => key =>
	 * value, 'html' => keys whose value is markup ]. A markup value may be a
	 * closure taking the template's design config (colours, font), so a table
	 * can match the text around it. Text values are escaped into the body; a
	 * markup value becomes text in the subject and preheader.
	 *
	 * A markup value listed under `block` (a table) that is the whole of a
	 * paragraph replaces the paragraph: a `<table>` inside a `<p>` is invalid
	 * and Outlook and Gmail break it. Other markup (an address with line
	 * breaks) stays in its paragraph, and keeps the paragraph's styling.
	 *
	 * Synced patterns (`<!-- wp:block {"ref":N} /-->`, the header and footer
	 * the merchant reuses across e-mails) are rendered by FluentCRM's own
	 * BlockParser, exactly as in a campaign: it reads them from its `fc_meta`
	 * table (object_type `email_pattern`), not from WordPress's `wp_block`
	 * posts. FluentCRM silently drops a pattern it cannot find; with
	 * `$options['require_patterns']` the render is refused instead (and
	 * logged), so the caller can send its own e-mail rather than one without
	 * its header or footer.
	 *
	 * @param array<string,array{values:array<string,mixed>,html?:string[],block?:string[]}> $codes
	 * @param object|null         $subscriber From {@see self::subscriber()}.
	 * @param array<string,mixed> $options    require_patterns: bool.
	 * @return array{subject:string,html:string,design:string}|null Null when the template is gone, FluentCRM is off, or nothing came out.
	 */
	public static function render( int $template_id, string $subject_override, array $codes, $subscriber = null, array $options = array() ): ?array {
		if ( ! self::can_render() ) {
			return null;
		}

		try {
			$template = self::template( $template_id );
			if ( ! $template ) {
				return null;
			}

			if ( ! empty( $options['require_patterns'] ) ) {
				$missing = self::missing_patterns( (string) $template->post_content );

				if ( $missing ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the server log says why the merchant's design did not go out.
					error_log( sprintf( '[galaxie-bundle] FluentCRM template #%d uses synced pattern(s) %s that FluentCRM does not have (fc_meta email_pattern); the default e-mail was sent instead.', $template_id, implode( ', ', $missing ) ) );
					return null;
				}
			}

			$design = (string) get_post_meta( $template->ID, '_design_template', true );
			$design = '' !== $design ? $design : 'simple';

			$tokens  = array();
			$subject = '' !== trim( $subject_override ) ? $subject_override : (string) get_post_meta( $template->ID, '_email_subject', true );
			$body    = self::replace_all( (string) $template->post_content, $codes, true, $tokens );
			$subject = self::replace_all( $subject, $codes, false, $tokens );
			$header  = self::replace_all( (string) $template->post_excerpt, $codes, false, $tokens );

			if ( ! in_array( $design, self::RAW_DESIGNS, true ) && class_exists( '\FluentCrm\App\Services\BlockParser' ) ) {
				$body = ( new \FluentCrm\App\Services\BlockParser( $subscriber ) )->parse( $body );
			}

			$body    = (string) apply_filters( 'fluent_crm/parse_campaign_email_text', $body, $subscriber );
			$subject = (string) apply_filters( 'fluent_crm/parse_campaign_email_text', $subject, $subscriber );
			$header  = (string) apply_filters( 'fluent_crm/parse_campaign_email_text', $header, $subscriber );

			$config                    = wp_parse_args( (array) get_post_meta( $template->ID, '_template_config', true ), \FluentCrm\App\Services\Helper::getTemplateConfig( $design ) );
			$config['design_template'] = $design;

			$html = (string) apply_filters(
				'fluent_crm/email-design-template-' . $design,
				$body,
				array(
					'preHeader'     => $header,
					'email_body'    => $body,
					'footer_text'   => '',
					'footer_config' => array( 'disable_footer' => 'yes' ),
					'config'        => $config,
				),
				false,
				$subscriber
			);

			$html = self::swap_tokens( $html, $tokens, $config );

			if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
				return null;
			}

			return array(
				'subject' => $subject,
				'html'    => $html,
				'design'  => $design,
			);
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * The synced pattern ids a template's content refers to that FluentCRM
	 * cannot find — the same lookup its BlockParser::renderSyncedPattern()
	 * does (3.2.5): `fc_meta` rows of object_type `email_pattern` by id, with
	 * content. Empty when there are none, or FluentCRM's model is not there
	 * to ask (then FluentCRM will not render them either way).
	 *
	 * @return int[]
	 */
	public static function missing_patterns( string $content ): array {
		if ( false === strpos( $content, 'wp:block' ) || ! preg_match_all( '~<!--\s+wp:block\s+(\{.*?\})\s*/?-->~', $content, $matches ) ) {
			return array();
		}

		if ( ! class_exists( '\FluentCrm\App\Models\Meta' ) ) {
			return array();
		}

		$missing = array();

		foreach ( $matches[1] as $json ) {
			$attrs = json_decode( $json, true );
			$ref   = is_array( $attrs ) ? (int) ( $attrs['ref'] ?? 0 ) : 0;

			if ( $ref <= 0 || in_array( $ref, $missing, true ) ) {
				continue;
			}

			$pattern = \FluentCrm\App\Models\Meta::where( 'object_type', 'email_pattern' )->where( 'id', $ref )->first();
			$value   = $pattern ? $pattern->value : null;

			if ( ! is_array( $value ) || empty( $value['content'] ) ) {
				$missing[] = $ref;
			}
		}

		return $missing;
	}

	/** FluentCRM's own switch that keeps WordPress from turning emoji into images in a mail. */
	public static function disable_emoji(): void {
		if ( class_exists( '\FluentCrm\App\Services\Helper' ) && method_exists( '\FluentCrm\App\Services\Helper', 'maybeDisableEmojiOnEmail' ) ) {
			\FluentCrm\App\Services\Helper::maybeDisableEmojiOnEmail();
		}
	}

	/**
	 * One group's smartcodes written into `$text` — also the
	 * `{{group.key|default}}` form, whose default stands in for an empty
	 * value. A key the group does not have is left for FluentCRM. Text values
	 * are escaped for HTML bodies; markup values are inserted as they are.
	 *
	 * @param array<string,mixed> $values
	 * @param string[]            $html_keys Keys whose value is markup.
	 */
	public static function replace( string $group, string $text, array $values, bool $html, array $html_keys = array(), array $block_keys = array() ): string {
		$tokens = array();
		$out    = self::replace_all(
			$text,
			array(
				$group => array(
					'values' => $values,
					'html'   => $html_keys,
					'block'  => $block_keys,
				),
			),
			$html,
			$tokens
		);

		return $html ? self::swap_tokens( $out, $tokens, array() ) : $out;
	}

	/**
	 * Markup as one line of text, for a subject or preheader: line breaks and
	 * table rows become separators, tags go, entities are decoded.
	 */
	public static function to_text( string $html ): string {
		$text = (string) preg_replace( '~<br\s*/?>~i', ', ', $html );
		$text = (string) preg_replace( '~</(?:tr|p|div|li|h[1-6])>~i', '; ', $text );
		$text = (string) preg_replace( '~</t[dh]>~i', ' ', $text );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		$text = (string) preg_replace( '~\s+~u', ' ', $text );
		$text = (string) preg_replace( '~\s*([,;])(?:\s*[,;])+~u', '$1', $text );
		$text = (string) preg_replace( '~\s+([,;])~u', '$1', $text );

		return trim( $text, " ,;\t\n\r\0\x0B" );
	}

	/**
	 * @param array<string,array{values:array<string,mixed>,html?:string[],block?:string[]}> $codes
	 * @param array<string,array{value:mixed,block:bool}>                                    $tokens Token => markup value, filled in.
	 */
	private static function replace_all( string $text, array $codes, bool $html, array &$tokens ): string {
		if ( '' === $text || false === strpos( $text, '{{' ) && false === stripos( $text, '%7B%7B' ) ) {
			return $text;
		}

		foreach ( $codes as $group => $spec ) {
			$values     = (array) ( $spec['values'] ?? array() );
			$html_keys  = (array) ( $spec['html'] ?? array() );
			$block_keys = (array) ( $spec['block'] ?? array() );

			$callback = static function ( array $match ) use ( $values, $html_keys, $block_keys, $html, &$tokens ): string {
				$key = $match[1];
				if ( ! array_key_exists( $key, $values ) ) {
					return $match[0];
				}

				$value   = $values[ $key ];
				$default = isset( $match[2] ) ? trim( rawurldecode( $match[2] ) ) : '';
				$is_html = in_array( $key, $html_keys, true );

				if ( ! $value instanceof \Closure && '' === trim( (string) $value ) ) {
					// The template's own fallback text, as written in the editor.
					return '' !== $default ? ( $html ? $default : html_entity_decode( $default, ENT_QUOTES, 'UTF-8' ) ) : '';
				}

				if ( $is_html ) {
					if ( ! $html ) {
						return self::neutralise( self::to_text( $value instanceof \Closure ? (string) $value( array() ) : (string) $value ) );
					}

					$token            = 'gxsc' . substr( md5( $key . count( $tokens ) . random_int( 0, PHP_INT_MAX ) ), 0, 12 ) . 'x';
					$tokens[ $token ] = array(
						'value' => $value,
						'block' => in_array( $key, $block_keys, true ),
					);

					return $token;
				}

				$value = self::neutralise( (string) $value );

				return $html ? esc_html( $value ) : $value;
			};

			$quoted = preg_quote( (string) $group, '/' );
			$text   = (string) preg_replace_callback( '/\{\{\s*' . $quoted . '\.([a-z_]+)(?:\|([^}]*))?\s*\}\}/', $callback, $text );

			// A smartcode inside a link's address, as some editors save it.
			$text = (string) preg_replace_callback( '/%7B%7B\s*' . $quoted . '\.([a-z_]+)(?:(?:\||%7C)((?:(?!%7D)[^}])*))?\s*%7D%7D/i', $callback, $text );
		}

		return $text;
	}

	/**
	 * A value can hold `{{` (a shopper's note, a product name): broken up, so
	 * FluentCRM's parser, which runs after ours, never resolves it.
	 */
	private static function neutralise( string $value ): string {
		return str_replace( array( '{{', '}}' ), array( '{ {', '} }' ), $value );
	}

	/**
	 * The markup values in place of their tokens. A block value's token that
	 * is the whole of a paragraph replaces the paragraph.
	 *
	 * @param array<string,array{value:mixed,block:bool}> $tokens
	 * @param array<string,mixed>                        $config The template's design config.
	 */
	private static function swap_tokens( string $html, array $tokens, array $config ): string {
		foreach ( $tokens as $token => $entry ) {
			$value  = $entry['value'];
			$markup = $value instanceof \Closure ? (string) $value( $config ) : (string) $value;

			if ( $entry['block'] ) {
				$html = (string) preg_replace_callback(
					'~<p\b[^>]*>\s*' . preg_quote( $token, '~' ) . '\s*</p>~i',
					static fn(): string => $markup,
					$html
				);
			}

			$html = str_replace( $token, $markup, $html );
		}

		return $html;
	}
}
