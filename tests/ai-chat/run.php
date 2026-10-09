<?php
/**
 * AI Chat settings route tests: `php tests/ai-chat/run.php`.
 *
 * `Modules\AiChat` (Bridge + Controller) against a stand-in for the plugin's admin class
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
function get_post_status( $id ) { return $GLOBALS['gx_posts'][ $id ]['status'] ?? false; }
function get_the_title( $id ) { return $GLOBALS['gx_posts'][ $id ]['title'] ?? ''; }
$GLOBALS['gx_posts'] = array( 993104 => array( 'status' => 'publish', 'title' => 'Trocas e devoluções' ), 7 => array( 'status' => 'draft', 'title' => 'Rascunho' ) );
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

/** AI Chat's admin class, reduced to what the bridge reads. */
class AICWP_Admin_Interface {

	public function __construct() {
		throw new RuntimeException( 'constructor ran: admin hooks would be registered twice' );
	}

	private function get_settings_registry() {
		return array(
			'aicwp_provider'      => array( 'type' => 'select', 'section' => 'api-config', 'sanitize' => 'sanitize_text_field', 'default' => 'openai', 'options' => array( 'openai' => 'OpenAI', 'gemini' => 'Gemini' ) ),
			'aicwp_api_key'       => array( 'type' => 'text', 'section' => 'api-config', 'sanitize' => 'sanitize_text_field', 'default' => '' ),
			'aicwp_chat_model'           => array( 'type' => 'select', 'section' => 'ai-chat-config', 'sanitize' => 'sanitize_text_field', 'default' => 'gpt-5.4-mini' ),
			'aicwp_chat_name'            => array( 'type' => 'text', 'section' => 'ai-chat-config', 'sanitize' => 'sanitize_text_field', 'default' => 'AI Assistant' ),
			'aicwp_chat_system_prompt'   => array( 'type' => 'textarea', 'section' => 'ai-chat-config', 'sanitize' => 'sanitize_textarea_field', 'default' => '' ),
			'aicwp_chat_enabled'         => array( 'type' => 'checkbox', 'section' => 'ai-chat-config', 'sanitize' => 'intval', 'default' => 1 ),
			'aicwp_chat_context_length'  => array( 'type' => 'select', 'section' => 'ai-chat-config', 'sanitize' => 'sanitize_text_field', 'default' => 'normal', 'options' => array( 'short' => 'Short', 'normal' => 'Normal', 'long' => 'Long' ) ),
			'aicwp_primary_color'        => array( 'type' => 'color', 'section' => 'floating', 'sanitize' => 'sanitize_hex_color', 'default' => '#0073ee' ),
			'aicwp_chat_quick_buttons'   => array( 'type' => 'array', 'section' => 'quick-buttons', 'sanitize' => 'sanitize_text_field', 'default' => array() ),
			'aicwp_enabled_types' => array( 'type' => 'array', 'section' => 'internal', 'sanitize' => 'sanitize_text_field', 'default' => array() ),
			'aicwp_floating_position'    => array( 'type' => 'select', 'section' => 'floating', 'sanitize' => 'sanitize_text_field', 'default' => 'right' ),
			'aicwp_chat_history_enabled' => array( 'type' => 'checkbox', 'section' => 'ai-chat-config', 'sanitize' => 'intval', 'default' => 1 ),
		);
	}

	private function get_secret_setting_keys() {
		return array( 'aicwp_api_key' );
	}

	/** Records what it was given, then cleans like the plugin: unslash textareas, fall back on the default colour. */
	private function sanitize_setting( $key, $value ) {
		$GLOBALS['gx_sanitized'][ $key ] = $value;
		if ( 'aicwp_primary_color' === $key ) {
			$color = sanitize_hex_color( $value );
			return '' === $color ? '#0073ee' : $color;
		}
		if ( 'aicwp_chat_system_prompt' === $key ) {
			return mb_substr( stripslashes( (string) $value ), 0, 6000 );
		}
		if ( is_array( $value ) ) {
			return array_map( static fn( $row ) => is_array( $row ) ? array_map( 'sanitize_text_field', $row ) : sanitize_text_field( $row ), $value );
		}
		return sanitize_text_field( $value );
	}
}

class AICWP_Chat_History {
	public static function create_table() {
		++$GLOBALS['gx_tables'];
	}
}

use Galaxie\Woo\Modules\AiChat\Bridge;
use Galaxie\Woo\Modules\AiChat\Controller;
use Galaxie\Woo\Modules\AiChat\Module;

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
	return new Controller( new Bridge() );
};

// Module contract.
$module = new Module();
$check( 'module id', $module->id(), 'ai-chat' );
$check( 'module off by default', $module->default_enabled(), false );

// Permission.
$api = $reset();
$check( 'admin may manage', $api->can_manage(), true );
$GLOBALS['gx_caps'] = array( 'manage_woocommerce' );
$check( 'shop manager may not', $code( $api->can_manage() ), 'rest_forbidden' );

// GET.
$api = $reset( array( 'aicwp_chat_name' => 'Runa', 'aicwp_api_key' => 'sk-live-secret' ) );
$get = $api->get_settings( new WP_REST_Request() );
$check( 'GET returns stored value', $get['settings']['aicwp_chat_name'] ?? null, 'Runa' );
$check( 'GET falls back on the registry default', $get['settings']['aicwp_chat_enabled'] ?? null, 1 );
$check( 'GET never returns a secret value', array_key_exists( 'aicwp_api_key', $get['settings'] ), false );
$check( 'GET says which secrets are set', $get['secrets']['aicwp_api_key'] ?? null, true );
$check( 'GET lists unset known secrets too', $get['secrets']['aicwp_telegram_bot_token'] ?? null, false );
$check( 'GET hides the unused enabled_types key', array_key_exists( 'aicwp_enabled_types', $get['settings'] ), false );
$check( 'GET includes the extras', $get['settings']['aicwp_enabled_post_types'] ?? null, array() );
$check( 'GET contact recipient defaults to admin e-mail', $get['settings']['aicwp_contact_form_recipient'] ?? null, 'admin@example.com' );
$check( 'GET includes schema by default', isset( $get['schema']['aicwp_chat_name']['section'] ), true );
$check( 'no secret anywhere in the GET body', false === strpos( json_encode( $get ), 'sk-live-secret' ), true );

$some = $api->get_settings( new WP_REST_Request( array( 'keys' => 'aicwp_chat_name, aicwp_primary_color', 'schema' => false ) ) );
$check( 'GET ?keys narrows', array_keys( $some['settings'] ), array( 'aicwp_chat_name', 'aicwp_primary_color' ) );
$check( 'GET ?schema=0 drops schema', array_key_exists( 'schema', $some ), false );
$check( 'GET ?keys with a secret is refused', $code( $api->get_settings( new WP_REST_Request( array( 'keys' => 'aicwp_api_key' ) ) ) ), 'galaxie_ai_chat_unknown' );

// PATCH.
$api    = $reset();
$result = $api->update_settings( new WP_REST_Request( array(), array( 'aicwp_chat_name' => 'Runa', 'aicwp_chat_enabled' => true, 'aicwp_primary_color' => '#C6A96B' ) ) );
$check( 'PATCH reports the keys it wrote', $result['updated'] ?? null, array( 'aicwp_chat_name', 'aicwp_chat_enabled', 'aicwp_primary_color' ) );
$check( 'PATCH stores text', get_option( 'aicwp_chat_name' ), 'Runa' );
$check( 'PATCH stores checkbox as int', get_option( 'aicwp_chat_enabled' ), 1 );
$check( 'WordPress request parameters in the body are ignored', $code( $api->update_settings( new WP_REST_Request( array(), array( 'aicwp_chat_name' => 'Runa', '_fields' => 'updated', '_locale' => 'user' ) ) ) ), 'not an error' );
$check( 'only WordPress parameters is an empty write', $code( $api->update_settings( new WP_REST_Request( array(), array( '_fields' => 'updated' ) ) ) ), 'galaxie_empty_settings' );
$check( 'PATCH goes through the plugin sanitizer', get_option( 'aicwp_primary_color' ), '#C6A96B' );

$api = $reset();
$api->update_settings( new WP_REST_Request( array(), array( 'aicwp_chat_system_prompt' => 'Use \\n literally. Você é a Runa.' ) ) );
$check( 'prompt keeps backslashes through the unslashing sanitizer', get_option( 'aicwp_chat_system_prompt' ), 'Use \\n literally. Você é a Runa.' );

$api = $reset( array( 'aicwp_chat_name' => 'Old' ) );
$check( 'secret write refused', $code( $api->update_settings( new WP_REST_Request( array(), array( 'aicwp_chat_name' => 'New', 'aicwp_api_key' => 'sk-x' ) ) ) ), 'galaxie_ai_chat_secret' );
$check( 'unknown key refused', $code( $api->update_settings( new WP_REST_Request( array(), array( 'aicwp_chat_name' => 'New', 'aicwp_chat_stats' => array() ) ) ) ), 'galaxie_ai_chat_unknown' );
$check( 'bad select refused', $code( $api->update_settings( new WP_REST_Request( array(), array( 'aicwp_chat_name' => 'New', 'aicwp_chat_context_length' => 'huge' ) ) ) ), 'galaxie_ai_chat_invalid' );
$check( 'select without registry options checked against known choices', $code( $api->update_settings( new WP_REST_Request( array(), array( 'aicwp_floating_position' => 'middle' ) ) ) ), 'galaxie_ai_chat_invalid' );
$check( 'known choices exposed in the schema', $api->get_settings( new WP_REST_Request( array( 'keys' => 'aicwp_floating_position' ) ) )['schema']['aicwp_floating_position']['options'] ?? null, array( 'left', 'right' ) );
$check( 'bad checkbox refused', $code( $api->update_settings( new WP_REST_Request( array(), array( 'aicwp_chat_enabled' => 'yes' ) ) ) ), 'galaxie_ai_chat_invalid' );
$check( 'array setting needs an array', $code( $api->update_settings( new WP_REST_Request( array(), array( 'aicwp_chat_quick_buttons' => 'x' ) ) ) ), 'galaxie_ai_chat_invalid' );
$check( 'empty body refused', $code( $api->update_settings( new WP_REST_Request( array(), array() ) ) ), 'galaxie_empty_settings' );
$check( 'a refused write changes nothing', get_option( 'aicwp_chat_name' ), 'Old' );

$api = $reset( array( 'aicwp_provider' => 'openai' ) );
$check( 'provider change alone refused', $code( $api->update_settings( new WP_REST_Request( array(), array( 'aicwp_provider' => 'gemini' ) ) ) ), 'galaxie_ai_chat_model_required' );
$api->update_settings( new WP_REST_Request( array(), array( 'aicwp_provider' => 'gemini', 'aicwp_chat_model' => 'gemini-2.5-flash' ) ) );
$check( 'provider + model accepted', array( get_option( 'aicwp_provider' ), get_option( 'aicwp_chat_model' ) ), array( 'gemini', 'gemini-2.5-flash' ) );
$check( 'same provider alone accepted', $code( $api->update_settings( new WP_REST_Request( array(), array( 'aicwp_provider' => 'gemini' ) ) ) ), 'not an error' );

// Extras.
$api = $reset( array( 'aicwp_custom_post_types' => array( 'faq' ) ) );
$api->update_settings( new WP_REST_Request( array(), array( 'aicwp_enabled_post_types' => array( 'product', 'page', 'faq', 'product' ) ) ) );
$check( 'post types stored, deduplicated', get_option( 'aicwp_enabled_post_types' ), array( 'product', 'page', 'faq' ) );
$check( 'post type the plugin does not offer refused', $code( $api->update_settings( new WP_REST_Request( array(), array( 'aicwp_enabled_post_types' => array( 'shop_order' ) ) ) ) ), 'galaxie_ai_chat_invalid' );
$check( 'bad contact e-mail refused', $code( $api->update_settings( new WP_REST_Request( array(), array( 'aicwp_contact_form_recipient' => 'not-an-email' ) ) ) ), 'galaxie_ai_chat_invalid' );
$api->update_settings( new WP_REST_Request( array(), array( 'aicwp_contact_form_recipient' => ' Contato@EirNaturals.shop ', 'aicwp_disable_auto_training' => false ) ) );
$check( 'contact e-mail cleaned', get_option( 'aicwp_contact_form_recipient' ), 'contato@eirnaturals.shop' );
$check( 'auto-training flag stored as int', get_option( 'aicwp_disable_auto_training' ), 0 );

// Knowledge sources ("Sugestões para IA").
$api = $reset();
$api->update_settings( new WP_REST_Request( array(), array( 'aicwp_knowledge_sources' => array( array( 'topic' => ' trocas, devoluções ', 'post_id' => '993104', 'post_title' => 'ignored' ) ) ) ) );
$check( 'source stored with the real title', get_option( 'aicwp_knowledge_sources' ), array( array( 'topic' => 'trocas, devoluções', 'post_id' => 993104, 'post_title' => 'Trocas e devoluções' ) ) );
$check( 'source on a draft refused', $code( $api->update_settings( new WP_REST_Request( array(), array( 'aicwp_knowledge_sources' => array( array( 'topic' => 'x', 'post_id' => 7 ) ) ) ) ) ), 'galaxie_ai_chat_invalid' );
$check( 'source without topic refused', $code( $api->update_settings( new WP_REST_Request( array(), array( 'aicwp_knowledge_sources' => array( array( 'post_id' => 993104 ) ) ) ) ) ), 'galaxie_ai_chat_invalid' );
$check( 'sources must be a list', $code( $api->update_settings( new WP_REST_Request( array(), array( 'aicwp_knowledge_sources' => array( 'topic' => 'x', 'post_id' => 993104 ) ) ) ) ), 'galaxie_ai_chat_invalid' );
$api->update_settings( new WP_REST_Request( array(), array( 'aicwp_knowledge_sources' => array() ) ) );
$check( 'empty list clears the sources', get_option( 'aicwp_knowledge_sources' ), array() );

// After save.
$api = $reset( array( 'aicwp_chat_history_enabled' => 0 ) );
$api->update_settings( new WP_REST_Request( array(), array( 'aicwp_chat_history_enabled' => 1 ) ) );
$check( 'turning history on creates its table', $GLOBALS['gx_tables'], 1 );
$api->update_settings( new WP_REST_Request( array(), array( 'aicwp_chat_name' => 'Runa' ) ) );
$check( 'other writes leave the table alone', $GLOBALS['gx_tables'], 1 );

// The embedded plugin must not declare a global name another plugin could
// also declare. On 2026-10-09 three unprefixed admin classes it shared with
// PurioChat were a fatal "Cannot declare class" on every page of the site.
$scan_globals = static function ( string $lib ): array {
	$unprefixed = array();
	$files      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $lib, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $files as $file ) {
		$path = str_replace( '\\', '/', $file->getPathname() );
		if ( 'php' !== $file->getExtension() || str_contains( $path, '/vendor/' ) ) {
			continue;
		}
		$code = (string) file_get_contents( $path );
		if ( preg_match( '/^\s*namespace\s+[A-Za-z]/m', $code ) ) {
			continue;
		}
		$relative = substr( $path, strlen( str_replace( '\\', '/', $lib ) ) + 1 );
		preg_match_all( '/^\s*(?:final\s+|abstract\s+)?(class|interface|trait)\s+([A-Za-z0-9_]+)/m', $code, $types, PREG_SET_ORDER );
		foreach ( $types as $m ) {
			if ( 0 !== strpos( $m[2], 'AICWP_' ) ) {
				$unprefixed[] = "{$relative}: {$m[1]} {$m[2]}";
			}
		}
		preg_match_all( '/^function\s+&?\s*([A-Za-z0-9_]+)\s*\(/m', $code, $functions );
		foreach ( $functions[1] as $name ) {
			if ( 0 !== strpos( $name, 'aicwp_' ) ) {
				$unprefixed[] = "{$relative}: function {$name}";
			}
		}
	}
	return $unprefixed;
};
$check( 'embedded plugin declares no unprefixed global class or function', $scan_globals( dirname( __DIR__, 2 ) . '/lib/ai-chat-wp' ), array() );
if ( getenv( 'GX_SCAN_DIR' ) ) {
	$check( 'scan of ' . getenv( 'GX_SCAN_DIR' ), $scan_globals( (string) getenv( 'GX_SCAN_DIR' ) ), array() );
}

echo "\n  {$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
