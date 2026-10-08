<?php
/**
 * Bridge to PurioChat's own settings registry and sanitizer.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\PurioChatSettings;

defined( 'ABSPATH' ) || exit;

/**
 * PurioChat's settings live in one registry, `get_settings_registry()` on
 * `Listeo_AI_Search_Admin_Interface` (Pro adds its keys through the
 * `ai_chat_search_settings_registry` filter), and every save is cleaned by
 * `sanitize_setting()` on the same class. Both are private.
 *
 * Rather than copy ~100 keys and their rules (and drift on PurioChat's next
 * update), this reads them in place: an instance made WITHOUT the constructor —
 * which would register PurioChat's admin hooks a second time — and the two
 * methods called through reflection. Neither touches instance state; the
 * registry is a literal array and the sanitizer only reads the registry.
 *
 * When PurioChat renames either method the routes answer 501 instead of
 * guessing.
 *
 * The settings PurioChat saves outside the registry (Data Training post types,
 * auto-training, the contact form) are described here by hand: {@see extras()}.
 */
final class PurioChat {

	private const ADMIN_CLASS = 'Listeo_AI_Search_Admin_Interface';

	/**
	 * Never read or written over REST. PurioChat's own list
	 * (`get_secret_setting_keys()`) covers the provider API keys only.
	 */
	private const SECRETS = array(
		'listeo_ai_search_api_key',
		'listeo_ai_search_gemini_api_key',
		'listeo_ai_search_mistral_api_key',
		'listeo_ai_search_openrouter_api_key',
		'listeo_ai_webhook_secret',
		'listeo_ai_whatsapp_auth_token',
		'listeo_ai_telegram_bot_token',
		'listeo_ai_telegram_secret_token',
	);

	/** Registry keys that are not settings: `enabled_types` is read nowhere. */
	private const HIDDEN = array( 'listeo_ai_search_enabled_types' );

	/**
	 * Allowed values of the choice settings whose registry entry lists none
	 * (PurioChat checks them only in its settings screen's markup, so its
	 * sanitizer would store anything).
	 */
	private const CHOICES = array(
		'listeo_ai_floating_position'             => array( 'left', 'right' ),
		'listeo_ai_color_scheme'                  => array( 'light', 'dark', 'auto' ),
		'listeo_ai_floating_header_style'         => array( 'simple', 'image', 'animated' ),
		'listeo_ai_chat_quick_buttons_visibility' => array( 'always', 'hide_after_first' ),
		'listeo_ai_chat_loading_style'            => array( 'spinner', 'dots' ),
		'listeo_ai_chat_context_length'           => array( 'short', 'normal', 'long' ),
		'listeo_ai_search_suggestions_source'     => array( 'top_searches', 'custom' ),
	);

	/** Post types PurioChat always offers on its Data Training tab. */
	private const DEFAULT_POST_TYPES = array( 'listing', 'post', 'page', 'product', 'ai_pdf_document', 'ai_external_page' );

	/** @var object|null */
	private $admin = null;

	/** @var array<string,array<string,mixed>>|null */
	private ?array $registry = null;

	/** @var string[]|null */
	private ?array $secret_keys = null;

	public function installed(): bool {
		return class_exists( self::ADMIN_CLASS );
	}

	/** PurioChat's version, or '' when it does not say. */
	public function version(): string {
		return defined( 'LISTEO_AI_SEARCH_VERSION' ) ? (string) constant( 'LISTEO_AI_SEARCH_VERSION' ) : '';
	}

	public function pro(): bool {
		return class_exists( 'AI_Chat_Search_Pro_Proxy_License_Manager' ) || defined( 'AI_CHAT_SEARCH_PRO_VERSION' );
	}

	/**
	 * Every readable setting with its schema: PurioChat's registry minus the
	 * secrets, plus {@see extras()}.
	 *
	 * @return array<string,array<string,mixed>>|\WP_Error
	 */
	public function schema() {
		$registry = $this->registry();

		if ( $registry instanceof \WP_Error ) {
			return $registry;
		}

		$schema = array();

		foreach ( $registry as $key => $config ) {
			if ( ! is_string( $key ) || ! is_array( $config ) || $this->is_secret( $key ) || in_array( $key, self::HIDDEN, true ) ) {
				continue;
			}

			$entry = array( 'source' => 'registry' );
			foreach ( array( 'type', 'section', 'default', 'description', 'options', 'min', 'max' ) as $field ) {
				if ( array_key_exists( $field, $config ) ) {
					$entry[ $field ] = $config[ $field ];
				}
			}
			if ( empty( $entry['options'] ) && isset( self::CHOICES[ $key ] ) ) {
				$entry['options'] = self::CHOICES[ $key ];
			}
			$schema[ $key ] = $entry;
		}

		return $schema + $this->extras();
	}

	/**
	 * Settings PurioChat saves through their own admin-ajax actions, not the
	 * registry.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function extras(): array {
		$admin_email = (string) get_option( 'admin_email', '' );

		return array(
			'listeo_ai_search_enabled_post_types'   => array(
				'source'      => 'data-training',
				'type'        => 'array',
				'default'     => array( 'listing' ),
				'options'     => $this->allowed_post_types(),
				'description' => 'Post types the assistant is trained on (Data Training tab). Content added here still has to be trained there before the assistant sees it.',
			),
			'listeo_ai_knowledge_sources'           => array(
				'source'      => 'knowledge-sources',
				'type'        => 'sources',
				'default'     => array(),
				'description' => '"Sugestões para IA": rows of { topic, post_id }. For each topic the assistant also searches that published page, post or product (it must be trained). post_title is filled in from the post.',
			),
			'listeo_ai_disable_auto_training'       => array(
				'source'      => 'data-training',
				'type'        => 'checkbox',
				'default'     => 0,
				'description' => 'Stop re-training a post automatically when it is saved.',
			),
			'listeo_ai_contact_form_recipient'      => array(
				'source'      => 'contact-form',
				'type'        => 'email',
				'default'     => $admin_email,
				'description' => 'Who receives the messages the assistant sends.',
			),
			'listeo_ai_contact_form_from_name'      => array(
				'source'      => 'contact-form',
				'type'        => 'text',
				'default'     => '',
				'description' => 'Sender name of those e-mails.',
			),
			'listeo_ai_contact_form_from_email'     => array(
				'source'      => 'contact-form',
				'type'        => 'email',
				'default'     => $admin_email,
				'description' => 'Sender address of those e-mails.',
			),
			'listeo_ai_contact_form_subject'        => array(
				'source'      => 'contact-form',
				'type'        => 'text',
				'default'     => '[{site_name}] New message from {name}',
				'description' => 'Subject; {site_name} and {name} are replaced.',
			),
			'listeo_ai_contact_form_success_message' => array(
				'source'      => 'contact-form',
				'type'        => 'text',
				'default'     => '',
				'description' => 'What the visitor reads after the message is sent.',
			),
		);
	}

	/**
	 * The stored value, or the schema default when the option was never saved.
	 *
	 * @param array<string,mixed> $schema One entry of {@see schema()}.
	 * @return mixed
	 */
	public function value( string $key, array $schema ) {
		return get_option( $key, $schema['default'] ?? null );
	}

	/** Which secrets hold a value — never the value itself. @return array<string,bool> */
	public function secrets_set(): array {
		$set = array();
		foreach ( $this->secret_keys() as $key ) {
			$set[ $key ] = '' !== (string) get_option( $key, '' );
		}
		return $set;
	}

	public function is_secret( string $key ): bool {
		return in_array( $key, $this->secret_keys(), true );
	}

	/**
	 * The value to store, cleaned the way PurioChat's own save would clean it,
	 * or an error explaining why it cannot be stored.
	 *
	 * @param mixed               $value
	 * @param array<string,mixed> $schema One entry of {@see schema()}.
	 * @return mixed|\WP_Error
	 */
	public function sanitize( string $key, $value, array $schema ) {
		if ( 'registry' !== ( $schema['source'] ?? '' ) ) {
			return $this->sanitize_extra( $key, $value, $schema );
		}

		$type = (string) ( $schema['type'] ?? '' );

		if ( 'checkbox' === $type ) {
			return $this->flag( $key, $value );
		}

		if ( 'array' === $type ) {
			if ( ! is_array( $value ) ) {
				return $this->invalid( $key, 'expected a JSON array' );
			}
		} elseif ( ! is_scalar( $value ) && null !== $value ) {
			return $this->invalid( $key, 'expected a single value' );
		}

		if ( ( 'select' === $type || isset( self::CHOICES[ $key ] ) ) && ! empty( $schema['options'] ) && is_array( $schema['options'] ) && ! $this->open_select( $key ) ) {
			$allowed = array_map( 'strval', array_is_list( $schema['options'] ) ? $schema['options'] : array_keys( $schema['options'] ) );
			if ( ! in_array( (string) $value, $allowed, true ) ) {
				return $this->invalid( $key, 'expected one of: ' . implode( ', ', $allowed ) );
			}
		}

		// The sanitizer unslashes textareas and HTML, as it reads them from the
		// slashed $_POST; a REST body is not slashed, so slash it first or
		// backslashes in a prompt would be lost.
		$config   = $this->registry();
		$callback = is_array( $config ) ? (string) ( $config[ $key ]['sanitize'] ?? '' ) : '';
		if ( in_array( $callback, array( 'wp_kses_post', 'sanitize_textarea_field' ), true ) || 'listeo_ai_chat_system_prompt' === $key ) {
			$value = wp_slash( $value );
		}

		return $this->call( 'sanitize_setting', array( $key, $value ) );
	}

	/** The work PurioChat's own save does after the options are written. @param array<string,mixed> $saved */
	public function after_save( array $saved ): void {
		if ( array_key_exists( 'listeo_ai_chat_history_enabled', $saved ) && get_option( 'listeo_ai_chat_history_enabled', 0 ) && class_exists( 'Listeo_AI_Search_Chat_History' ) ) {
			\Listeo_AI_Search_Chat_History::create_table();
		}
	}

	/** @return array<string,array<string,mixed>>|\WP_Error */
	private function registry() {
		if ( null !== $this->registry ) {
			return $this->registry;
		}

		$registry = $this->call( 'get_settings_registry' );

		if ( $registry instanceof \WP_Error ) {
			return $registry;
		}

		if ( ! is_array( $registry ) || ! $registry ) {
			return $this->unsupported( 'get_settings_registry() returned no settings' );
		}

		return $this->registry = $registry;
	}

	/** @return string[] */
	private function secret_keys(): array {
		if ( null !== $this->secret_keys ) {
			return $this->secret_keys;
		}

		$own = $this->installed() ? $this->call( 'get_secret_setting_keys' ) : array();

		return $this->secret_keys = array_values( array_unique( array_merge( self::SECRETS, is_array( $own ) ? array_map( 'strval', $own ) : array() ) ) );
	}

	/**
	 * A private method of PurioChat's admin class, on an instance built
	 * without its constructor.
	 *
	 * @param mixed[] $args
	 * @return mixed|\WP_Error
	 */
	private function call( string $method, array $args = array() ) {
		if ( ! $this->installed() ) {
			return new \WP_Error( 'galaxie_puriochat_missing', __( 'PurioChat is not active.', 'galaxie-woo' ), array( 'status' => 503 ) );
		}

		try {
			if ( null === $this->admin ) {
				$this->admin = ( new \ReflectionClass( self::ADMIN_CLASS ) )->newInstanceWithoutConstructor();
			}

			$reflection = new \ReflectionMethod( $this->admin, $method );
			$reflection->setAccessible( true );

			return $reflection->invokeArgs( $this->admin, $args );
		} catch ( \ReflectionException $e ) {
			return $this->unsupported( $method . '() not found' );
		} catch ( \Error $e ) {
			return $this->unsupported( $method . '() failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Data Training, auto-training and the contact form, cleaned as their own
	 * admin-ajax handlers clean them.
	 *
	 * @param mixed               $value
	 * @param array<string,mixed> $schema
	 * @return mixed|\WP_Error
	 */
	private function sanitize_extra( string $key, $value, array $schema ) {
		switch ( $schema['type'] ?? '' ) {
			case 'checkbox':
				return $this->flag( $key, $value );

			case 'array':
				if ( ! is_array( $value ) ) {
					return $this->invalid( $key, 'expected a JSON array of post type slugs' );
				}
				$types   = array_values( array_unique( array_map( 'sanitize_key', array_map( 'strval', $value ) ) ) );
				$unknown = array_diff( $types, $this->allowed_post_types() );
				if ( $unknown ) {
					return $this->invalid( $key, 'not offered by PurioChat: ' . implode( ', ', $unknown ) . ' (allowed: ' . implode( ', ', $this->allowed_post_types() ) . ')' );
				}
				return $types;

			case 'sources':
				return $this->sanitize_sources( $key, $value );

			case 'email':
				$email = sanitize_email( (string) $value );
				if ( '' !== $email && ! is_email( $email ) ) {
					return $this->invalid( $key, 'not a valid e-mail address' );
				}
				return $email;

			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Rows as PurioChat's "Sugestões para IA" dialog saves them
	 * (`ajax_add_knowledge_source()`): topic, post id, post title. The title is
	 * taken from the post, and a post that is not published is refused — the
	 * assistant could not find it.
	 *
	 * @param mixed $value
	 * @return array<int,array{topic:string,post_id:int,post_title:string}>|\WP_Error
	 */
	private function sanitize_sources( string $key, $value ) {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return $this->invalid( $key, 'expected a JSON array of { "topic": "...", "post_id": 123 }' );
		}

		$rows = array();
		foreach ( $value as $i => $row ) {
			$topic   = is_array( $row ) ? sanitize_text_field( (string) ( $row['topic'] ?? '' ) ) : '';
			$post_id = is_array( $row ) ? (int) ( $row['post_id'] ?? 0 ) : 0;

			if ( '' === $topic || $post_id <= 0 ) {
				return $this->invalid( $key, "row {$i} needs a topic and a post_id" );
			}
			if ( 'publish' !== get_post_status( $post_id ) ) {
				return $this->invalid( $key, "row {$i}: post {$post_id} is not a published post" );
			}

			$rows[] = array(
				'topic'      => $topic,
				'post_id'    => $post_id,
				'post_title' => sanitize_text_field( (string) get_the_title( $post_id ) ),
			);
		}

		return $rows;
	}

	/** PurioChat's default post types plus the custom ones added on its Data Training tab. @return string[] */
	private function allowed_post_types(): array {
		$custom = get_option( 'listeo_ai_search_custom_post_types', array() );

		return array_values( array_unique( array_merge( self::DEFAULT_POST_TYPES, is_array( $custom ) ? array_map( 'strval', $custom ) : array() ) ) );
	}

	/**
	 * Checkboxes are stored as int 1/0.
	 *
	 * @param mixed $value
	 * @return int|\WP_Error
	 */
	private function flag( string $key, $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 1 : 0;
		}

		if ( in_array( $value, array( 0, 1, '0', '1' ), true ) ) {
			return (int) $value;
		}

		return $this->invalid( $key, 'expected true/false or 1/0' );
	}

	/**
	 * Selects whose registry lists only some of the accepted values: the
	 * provider list omits Mistral, which the sanitizer and the chat accept.
	 */
	private function open_select( string $key ): bool {
		return in_array( $key, array( 'listeo_ai_search_provider', 'listeo_ai_embedding_model', 'listeo_ai_chat_model', 'listeo_ai_floating_button_icon' ), true );
	}

	private function invalid( string $key, string $why ): \WP_Error {
		return new \WP_Error( 'galaxie_puriochat_invalid', sprintf( '%s: %s', $key, $why ), array( 'status' => 400, 'key' => $key ) );
	}

	private function unsupported( string $why ): \WP_Error {
		/* translators: %s: what is missing in PurioChat */
		return new \WP_Error( 'galaxie_puriochat_unsupported', sprintf( __( 'This PurioChat version is not supported (%s).', 'galaxie-woo' ), $why ), array( 'status' => 501 ) );
	}
}
