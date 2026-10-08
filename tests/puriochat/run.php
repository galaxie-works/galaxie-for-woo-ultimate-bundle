<?php
/**
 * PurioChat settings route tests: `php tests/puriochat/run.php`.
 *
 * `Modules\PurioChatSettings` against a stand-in for PurioChat's admin class
 * with the same private methods (registry, sanitizer, secret keys) and a
 * constructor that must never run. Plain runner on tests/settings-api's stubs.
 * Exits 1 on any failure.
 *
 * @package Galaxie\Woo
 */

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/settings-api/wp-stubs.php';

function sanitize_email( $email ) { return strtolower( trim( (string) $email ) ); }
function is_email( $email ) { return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL ); }
function wp_slash( $value ) { return is_string( $value ) ? addslashes( $value ) : $value; }
function sanitize_textarea_field( $str ) { return trim( (string) $str ); }
function wp_kses_post( $str ) { return strip_tags( (string) $str, '<b><strong><em><a><br>' ); }
function sanitize_hex_color( $color ) { return preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', (string) $color ) ? $color : ''; }

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

/** PurioChat's admin class, reduced to what the bridge reads. */
class Listeo_AI_Search_Admin_Interface {

	public function __construct() {
		throw new RuntimeException( 'constructor ran: PurioChat admin hooks would be registered twice' );
	}

	private function get_settings_registry() {
		return array(
			'listeo_ai_search_provider'      => array( 'type' => 'select', 'section' => 'api-config', 'sanitize' => 'sanitize_text_field', 'default' => 'openai', 'options' => array( 'openai' => 'OpenAI', 'gemini' => 'Gemini' ) ),
			'listeo_ai_search_api_key'       => array( 'type' => 'text', 'section' => 'api-config', 'sanitize' => 'sanitize_text_field', 'default' => '' ),
			'listeo_ai_chat_model'           => array( 'type' => 'select', 'section' => 'ai-chat-config', 'sanitize' => 'sanitize_text_field', 'default' => 'gpt-5.4-mini' ),
			'listeo_ai_chat_name'            => array( 'type' => 'text', 'section' => 'ai-chat-config', 'sanitize' => 'sanitize_text_field', 'default' => 'AI Assistant' ),
			'listeo_ai_chat_system_prompt'   => array( 'type' => 'textarea', 'section' => 'ai-chat-config', 'sanitize' => 'sanitize_textarea_field', 'default' => '' ),
			'listeo_ai_chat_enabled'         => array( 'type' => 'checkbox', 'section' => 'ai-chat-config', 'sanitize' => 'intval', 'default' => 1 ),
			'listeo_ai_chat_context_length'  => array( 'type' => 'select', 'section' => 'ai-chat-config', 'sanitize' => 'sanitize_text_field', 'default' => 'normal', 'options' => array( 'short' => 'Short', 'normal' => 'Normal', 'long' => 'Long' ) ),
			'listeo_ai_primary_color'        => array( 'type' => 'color', 'section' => 'floating', 'sanitize' => 'sanitize_hex_color', 'default' => '#0073ee' ),
			'listeo_ai_chat_quick_buttons'   => array( 'type' => 'array', 'section' => 'quick-buttons', 'sanitize' => 'sanitize_text_field', 'default' => array() ),
			'listeo_ai_search_enabled_types' => array( 'type' => 'array', 'section' => 'internal', 'sanitize' => 'sanitize_text_field', 'default' => array() ),
			'listeo_ai_floating_position'    => array( 'type' => 'select', 'section' => 'floating', 'sanitize' => 'sanitize_text_field', 'default' => 'right' ),
			'listeo_ai_chat_history_enabled' => array( 'type' => 'checkbox', 'section' => 'ai-chat-config', 'sanitize' => 'intval', 'default' => 1 ),
		);
	}

	private function get_secret_setting_keys() {
		return array( 'listeo_ai_search_api_key' );
	}

	/** Records what it was given, then cleans like PurioChat: unslash textareas, fall back on the default colour. */
	private function sanitize_setting( $key, $value ) {
		$GLOBALS['gx_sanitized'][ $key ] = $value;
		if ( 'listeo_ai_primary_color' === $key ) {
			$color = sanitize_hex_color( $value );
			return '' === $color ? '#0073ee' : $color;
		}
		if ( 'listeo_ai_chat_system_prompt' === $key ) {
			return mb_substr( stripslashes( (string) $value ), 0, 6000 );
		}
		if ( is_array( $value ) ) {
			return array_map( static fn( $row ) => is_array( $row ) ? array_map( 'sanitize_text_field', $row ) : sanitize_text_field( $row ), $value );
		}
		return sanitize_text_field( $value );
	}
}

class Listeo_AI_Search_Chat_History {
	public static function create_table() {
		++$GLOBALS['gx_tables'];
	}
}

use Galaxie\Woo\Modules\PurioChatSettings\Controller;
use Galaxie\Woo\Modules\PurioChatSettings\Module;
use Galaxie\Woo\Modules\PurioChatSettings\PurioChat;

$failed = 0;
$passed = 0;

$check = static function ( string $name, $actual, $expect ) use ( &$failed, &$passed ): void {
	if ( $actual === $expect ) {
		++$passed;
		echo "  ok    {$name}\n";
		return;
	}
	++$failed;
	echo "  FAIL  {$name}\n        expected " . json_encode( $expect ) . "\n        got      " . json_encode( $actual ) . "\n";
};

$code = static fn( $result ) => $result instanceof WP_Error ? $result->get_error_code() : 'not an error';

$reset = static function ( array $options = array() ): Controller {
	$GLOBALS['gx_options']   = $options + array( 'admin_email' => 'admin@example.com' );
	$GLOBALS['gx_caps']      = array( 'manage_options' );
	$GLOBALS['gx_sanitized'] = array();
	$GLOBALS['gx_tables']    = 0;
	return new Controller( new PurioChat() );
};

// Module contract.
$module = new Module();
$check( 'module id', $module->id(), 'puriochat-settings' );
$check( 'module off by default', $module->default_enabled(), false );

// Permission.
$api = $reset();
$check( 'admin may manage', $api->can_manage(), true );
$GLOBALS['gx_caps'] = array( 'manage_woocommerce' );
$check( 'shop manager may not', $code( $api->can_manage() ), 'rest_forbidden' );

// GET.
$api = $reset( array( 'listeo_ai_chat_name' => 'Runa', 'listeo_ai_search_api_key' => 'sk-live-secret' ) );
$get = $api->get_settings( new WP_REST_Request() );
$check( 'GET returns stored value', $get['settings']['listeo_ai_chat_name'] ?? null, 'Runa' );
$check( 'GET falls back on the registry default', $get['settings']['listeo_ai_chat_enabled'] ?? null, 1 );
$check( 'GET never returns a secret value', array_key_exists( 'listeo_ai_search_api_key', $get['settings'] ), false );
$check( 'GET says which secrets are set', $get['secrets']['listeo_ai_search_api_key'] ?? null, true );
$check( 'GET lists unset known secrets too', $get['secrets']['listeo_ai_telegram_bot_token'] ?? null, false );
$check( 'GET hides the unused enabled_types key', array_key_exists( 'listeo_ai_search_enabled_types', $get['settings'] ), false );
$check( 'GET includes the extras', $get['settings']['listeo_ai_search_enabled_post_types'] ?? null, array( 'listing' ) );
$check( 'GET contact recipient defaults to admin e-mail', $get['settings']['listeo_ai_contact_form_recipient'] ?? null, 'admin@example.com' );
$check( 'GET includes schema by default', isset( $get['schema']['listeo_ai_chat_name']['section'] ), true );
$check( 'no secret anywhere in the GET body', false === strpos( json_encode( $get ), 'sk-live-secret' ), true );

$some = $api->get_settings( new WP_REST_Request( array( 'keys' => 'listeo_ai_chat_name, listeo_ai_primary_color', 'schema' => false ) ) );
$check( 'GET ?keys narrows', array_keys( $some['settings'] ), array( 'listeo_ai_chat_name', 'listeo_ai_primary_color' ) );
$check( 'GET ?schema=0 drops schema', array_key_exists( 'schema', $some ), false );
$check( 'GET ?keys with a secret is refused', $code( $api->get_settings( new WP_REST_Request( array( 'keys' => 'listeo_ai_search_api_key' ) ) ) ), 'galaxie_puriochat_unknown' );

// PATCH.
$api    = $reset();
$result = $api->update_settings( new WP_REST_Request( array(), array( 'listeo_ai_chat_name' => 'Runa', 'listeo_ai_chat_enabled' => true, 'listeo_ai_primary_color' => '#C6A96B' ) ) );
$check( 'PATCH reports the keys it wrote', $result['updated'] ?? null, array( 'listeo_ai_chat_name', 'listeo_ai_chat_enabled', 'listeo_ai_primary_color' ) );
$check( 'PATCH stores text', get_option( 'listeo_ai_chat_name' ), 'Runa' );
$check( 'PATCH stores checkbox as int', get_option( 'listeo_ai_chat_enabled' ), 1 );
$check( 'PATCH goes through PurioChat sanitizer', get_option( 'listeo_ai_primary_color' ), '#C6A96B' );

$api = $reset();
$api->update_settings( new WP_REST_Request( array(), array( 'listeo_ai_chat_system_prompt' => 'Use \\n literally. Você é a Runa.' ) ) );
$check( 'prompt keeps backslashes through the unslashing sanitizer', get_option( 'listeo_ai_chat_system_prompt' ), 'Use \\n literally. Você é a Runa.' );

$api = $reset( array( 'listeo_ai_chat_name' => 'Old' ) );
$check( 'secret write refused', $code( $api->update_settings( new WP_REST_Request( array(), array( 'listeo_ai_chat_name' => 'New', 'listeo_ai_search_api_key' => 'sk-x' ) ) ) ), 'galaxie_puriochat_secret' );
$check( 'unknown key refused', $code( $api->update_settings( new WP_REST_Request( array(), array( 'listeo_ai_chat_name' => 'New', 'listeo_ai_chat_stats' => array() ) ) ) ), 'galaxie_puriochat_unknown' );
$check( 'bad select refused', $code( $api->update_settings( new WP_REST_Request( array(), array( 'listeo_ai_chat_name' => 'New', 'listeo_ai_chat_context_length' => 'huge' ) ) ) ), 'galaxie_puriochat_invalid' );
$check( 'select without registry options checked against known choices', $code( $api->update_settings( new WP_REST_Request( array(), array( 'listeo_ai_floating_position' => 'middle' ) ) ) ), 'galaxie_puriochat_invalid' );
$check( 'known choices exposed in the schema', $api->get_settings( new WP_REST_Request( array( 'keys' => 'listeo_ai_floating_position' ) ) )['schema']['listeo_ai_floating_position']['options'] ?? null, array( 'left', 'right' ) );
$check( 'bad checkbox refused', $code( $api->update_settings( new WP_REST_Request( array(), array( 'listeo_ai_chat_enabled' => 'yes' ) ) ) ), 'galaxie_puriochat_invalid' );
$check( 'array setting needs an array', $code( $api->update_settings( new WP_REST_Request( array(), array( 'listeo_ai_chat_quick_buttons' => 'x' ) ) ) ), 'galaxie_puriochat_invalid' );
$check( 'empty body refused', $code( $api->update_settings( new WP_REST_Request( array(), array() ) ) ), 'galaxie_empty_settings' );
$check( 'a refused write changes nothing', get_option( 'listeo_ai_chat_name' ), 'Old' );

$api = $reset( array( 'listeo_ai_search_provider' => 'openai' ) );
$check( 'provider change alone refused', $code( $api->update_settings( new WP_REST_Request( array(), array( 'listeo_ai_search_provider' => 'gemini' ) ) ) ), 'galaxie_puriochat_model_required' );
$api->update_settings( new WP_REST_Request( array(), array( 'listeo_ai_search_provider' => 'gemini', 'listeo_ai_chat_model' => 'gemini-2.5-flash' ) ) );
$check( 'provider + model accepted', array( get_option( 'listeo_ai_search_provider' ), get_option( 'listeo_ai_chat_model' ) ), array( 'gemini', 'gemini-2.5-flash' ) );
$check( 'same provider alone accepted', $code( $api->update_settings( new WP_REST_Request( array(), array( 'listeo_ai_search_provider' => 'gemini' ) ) ) ), 'not an error' );

// Extras.
$api = $reset( array( 'listeo_ai_search_custom_post_types' => array( 'faq' ) ) );
$api->update_settings( new WP_REST_Request( array(), array( 'listeo_ai_search_enabled_post_types' => array( 'product', 'page', 'faq', 'product' ) ) ) );
$check( 'post types stored, deduplicated', get_option( 'listeo_ai_search_enabled_post_types' ), array( 'product', 'page', 'faq' ) );
$check( 'post type PurioChat does not offer refused', $code( $api->update_settings( new WP_REST_Request( array(), array( 'listeo_ai_search_enabled_post_types' => array( 'shop_order' ) ) ) ) ), 'galaxie_puriochat_invalid' );
$check( 'bad contact e-mail refused', $code( $api->update_settings( new WP_REST_Request( array(), array( 'listeo_ai_contact_form_recipient' => 'not-an-email' ) ) ) ), 'galaxie_puriochat_invalid' );
$api->update_settings( new WP_REST_Request( array(), array( 'listeo_ai_contact_form_recipient' => ' Contato@EirNaturals.shop ', 'listeo_ai_disable_auto_training' => false ) ) );
$check( 'contact e-mail cleaned', get_option( 'listeo_ai_contact_form_recipient' ), 'contato@eirnaturals.shop' );
$check( 'auto-training flag stored as int', get_option( 'listeo_ai_disable_auto_training' ), 0 );

// After save.
$api = $reset( array( 'listeo_ai_chat_history_enabled' => 0 ) );
$api->update_settings( new WP_REST_Request( array(), array( 'listeo_ai_chat_history_enabled' => 1 ) ) );
$check( 'turning history on creates its table', $GLOBALS['gx_tables'], 1 );
$api->update_settings( new WP_REST_Request( array(), array( 'listeo_ai_chat_name' => 'Runa' ) ) );
$check( 'other writes leave the table alone', $GLOBALS['gx_tables'], 1 );

echo "\n  {$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
