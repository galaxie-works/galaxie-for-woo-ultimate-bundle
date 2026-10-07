<?php
/**
 * Registry of all modules + resolution of which are enabled.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Core;

defined( 'ABSPATH' ) || exit;

final class ModuleRegistry {

	/** @var array<string,Module> */
	private array $modules = array();

	/**
	 * Modules whose boot() threw on this request, by id: the error message.
	 *
	 * @var array<string,string>
	 */
	private array $failed = array();

	public function __construct( private Settings $settings ) {}

	public function register( Module $module ): void {
		$this->modules[ $module->id() ] = $module;
	}

	/** @return array<string,Module> */
	public function all(): array {
		return $this->modules;
	}

	public function is_enabled( Module $module ): bool {
		return $this->settings->is_enabled( $module->id(), $module->default_enabled() );
	}

	/** Convenience for callers that only have a module id (e.g. a widget checking a sibling module). */
	public function is_enabled_by_id( string $id ): bool {
		$module = $this->modules[ $id ] ?? null;
		return null !== $module && ! isset( $this->failed[ $id ] ) && $this->is_enabled( $module );
	}

	/**
	 * Enabled modules, minus any whose boot failed on this request: their boot
	 * data, widgets and settings tab would lean on a half-registered module.
	 *
	 * @return array<string,Module>
	 */
	public function enabled(): array {
		return array_filter(
			$this->modules,
			fn( Module $module ): bool => ! isset( $this->failed[ $module->id() ] ) && $this->is_enabled( $module )
		);
	}

	/** @return array<string,string> Modules whose boot threw on this request: id => message. */
	public function failed(): array {
		return $this->failed;
	}

	/**
	 * Boot every enabled module exactly once.
	 *
	 * One module's exception must not take down the whole site — wp-admin
	 * included, where the merchant would switch it off. Each boot is isolated:
	 * a Throwable is logged with the module id, the module is marked failed for
	 * this request (enabled() and is_enabled_by_id() stop reporting it), and
	 * administrators get a notice naming it. Hooks it registered before the
	 * throw stay registered; a PHP fatal (memory, recursion) is not catchable
	 * and still stops the request, as before.
	 */
	public function boot_enabled(): void {
		foreach ( $this->enabled() as $id => $module ) {
			try {
				$module->boot();
			} catch ( \Throwable $e ) {
				$this->failed[ $id ] = $e->getMessage();
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the failure must reach the server log.
				error_log(
					sprintf(
						'[galaxie-bundle] module "%s" failed to boot: %s: %s in %s:%d',
						$id,
						get_class( $e ),
						$e->getMessage(),
						$e->getFile(),
						$e->getLine()
					)
				);
			}
		}

		if ( $this->failed && function_exists( 'add_action' ) ) {
			add_action( 'admin_notices', array( $this, 'failed_notice' ) );
		}
	}

	/** Admin notice listing the modules that failed to boot on this request. */
	public function failed_notice(): void {
		if ( ! $this->failed || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$names = array();
		foreach ( array_keys( $this->failed ) as $id ) {
			$names[] = $this->modules[ $id ]->title() . ' (' . $id . ')';
		}

		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'Galaxie:', 'galaxie-woo' ),
			esc_html(
				sprintf(
					/* translators: %s: comma-separated module names. */
					__( 'estes módulos falharam ao iniciar e estão desativados nesta página: %s. O erro está no log do PHP (error_log).', 'galaxie-woo' ),
					implode( ', ', $names )
				)
			)
		);
	}
}
