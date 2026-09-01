<?php
/**
 * Plugin orchestrator.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Core;

use Galaxie\Woo\Core\Admin\ModulesPage;
use Galaxie\Woo\Elementor\Widgets;

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

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	private function __construct() {
		$this->settings = new Settings();
		$this->modules  = new ModuleRegistry( $this->settings );
	}

	public function settings(): Settings {
		return $this->settings;
	}

	public function modules(): ModuleRegistry {
		return $this->modules;
	}

	public function boot(): void {
		$this->register_modules();

		load_plugin_textdomain( 'galaxie-woo', false, dirname( plugin_basename( GALAXIE_WOO_FILE ) ) . '/languages' );

		if ( is_admin() ) {
			( new ModulesPage( $this->modules, $this->settings ) )->hooks();
		}

		// Safe to attach unconditionally — the callbacks only fire if Elementor is loaded.
		( new Widgets( $this->modules ) )->hooks();

		$this->modules->boot_enabled();
	}

	/**
	 * The single source of truth for what ships in the bundle. Order here is the
	 * order shown on the admin Modules page.
	 */
	private function register_modules(): void {
		$this->modules->register( new \Galaxie\Woo\Modules\PasswordlessAuth\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\Checkout\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\MyAccount\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\Cart\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\Wishlist\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\AccountDeletion\Module() );
		$this->modules->register( new \Galaxie\Woo\Modules\ToastNotices\Module() );
	}
}
