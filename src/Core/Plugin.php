<?php
/**
 * Plugin orchestrator.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Core;

use Galaxie\Woo\Core\Admin\SettingsPage;
use Galaxie\Woo\Core\Cli\ModuleCommand;
use Galaxie\Woo\Core\Cli\SettingsCommand;
use Galaxie\Woo\Core\Rest\SettingsController;
use Galaxie\Woo\Elementor\Widgets;
use Galaxie\Woo\Integrations\Acf;
use Galaxie\Woo\Support\GiftOrders;
use Galaxie\Woo\Support\GiftSummary;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the bundle together: builds the registry, registers every module,
 * mounts the admin page + Elementor widget registrar, and boots the enabled
 * modules. Instantiated once from the main plugin file on `plugins_loaded`.
 */
final class Plugin {

	private static ?Plugin $instance = null;
	private Settings $settings;
	private ModuleRegistry $modules;
	private SettingsService $service;
	private bool $booted = false;

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	private function __construct() {
		$this->settings = new Settings();
		$this->modules  = new ModuleRegistry( $this->settings );
		$this->service  = new SettingsService( $this->modules, $this->settings );
	}

	public function settings(): Settings {
		return $this->settings;
	}

	public function modules(): ModuleRegistry {
		return $this->modules;
	}

	/** Where module toggles and settings are saved, from wp-admin, REST or WP-CLI. */
	public function settings_service(): SettingsService {
		return $this->service;
	}

	public function boot(): void {
		// Belt to the bootstrap's braces: `register_modules()` builds fresh module
		// instances every call, so a second boot would register every hook again
		// under callbacks WordPress sees as distinct and cannot dedupe.
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$this->register_modules();

		load_plugin_textdomain( 'galaxie-woo', false, dirname( plugin_basename( GALAXIE_WOO_FILE ) ) . '/languages' );

		// Unconditional: the filter is inert when ACF is not installed, and
		// registering it here means the field groups are available as soon as
		// ACF is, with no module toggle standing between a deploy and the
		// definitions the storefront reads.
		Acf::hooks();

		// Also unconditional: an order is a gift whichever modules are on today —
		// a shared wish list's gift never needed Gift Wrap — so the "Presente"
		// tag in wp-admin reads order meta with no toggle in the way.
		GiftOrders::hooks();
		\Galaxie\Woo\Modules\Wishlist\Gifts::order_hooks(); // Past gift orders stay masked with the Wishlist module off.

		// And for the same reason, what goes in each gift box: the order screen and
		// the e-mails read it from the order's own line items.
		GiftSummary::hooks();

		// The settings page without wp-admin: REST (`galaxie-woo/v1`) and WP-CLI
		// (`wp galaxie`), saving through the same service as the page.
		( new SettingsController( $this->service ) )->hooks();

		// Fresh nonces for pages LiteSpeed / the CDN kept longer than a nonce
		// lives (admin-ajax, so is_admin() is true there: outside the branch below).
		FreshNonces::hooks();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'galaxie module', new ModuleCommand( $this->service ) );
			\WP_CLI::add_command( 'galaxie settings', new SettingsCommand( $this->service ) );
		}

		if ( is_admin() ) {
			( new SettingsPage( $this->modules, $this->settings, $this->service ) )->hooks();
		} else {
			add_action( 'wp_head', array( $this, 'print_boot_data' ), 5 );
		}

		// Safe to attach unconditionally — the callbacks only fire if Elementor is loaded.
		( new Widgets( $this->modules ) )->hooks();

		$this->modules->boot_enabled();
	}

	/** Merged boot config from every enabled module that implements {@see ProvidesBootData}. */
	public function boot_data(): array {
		$data = array();
		foreach ( $this->modules->enabled() as $module ) {
			if ( $module instanceof ProvidesBootData ) {
				$data = array_merge( $data, $module->boot_data() );
			}
		}

		return $data;
	}

	/**
	 * Prints `window.__GALAXIE_WOO__` — {@see boot_data()} — in the head, ahead
	 * of the deferred module bundle so the JS can read it before it runs.
	 *
	 * With when it was made (`generatedAt`, Unix seconds) and where to ask for
	 * fresh nonces (`ajaxUrl`): the page may be served from a cache for days,
	 * and the script renews the nonces of one old enough ({@see FreshNonces}).
	 */
	public function print_boot_data(): void {
		$data = $this->boot_data();
		if ( empty( $data ) ) {
			return;
		}
		$data['generatedAt'] = time();
		$data['ajaxUrl']     = admin_url( 'admin-ajax.php' );
		echo '<script>window.__GALAXIE_WOO__=Object.assign(window.__GALAXIE_WOO__||{},'
			. wp_json_encode( $data ) . ');</script>' . "\n";
	}

	/**
	 * The single source of truth for what ships in the bundle. Order here is the
	 * order shown on the admin Modules page.
	 */
	private function register_modules(): void {
		$this->modules->register( new \Galaxie\Woo\Modules\PasswordlessAuth\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\LegalPages\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\AgeGate\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\OrderCancellation\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\GoogleLogin\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\AddressAutocomplete\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\FluentCRM\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\StoreEmails\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\Checkout\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\MyAccount\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\FunnelKitPtBr\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\FunnelKitExpress\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\Cart\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\FreeShipping\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\Wishlist\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\GiftWrap\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\ShippingCartons\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\AddressBook\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\AccountDeletion\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\ToastNotices\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\VariationSwatches\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\VariationSpotlight\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\QuantityDiscounts\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\ProductsCarousel\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\LoopCarousel\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\ProductData\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\PixfortIconPicker\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\PixfortImageAlt\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\AssetTrim\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\PurioChatSettings\Module() );
	}
}
