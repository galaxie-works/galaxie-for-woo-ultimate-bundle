<?php
/**
 * Admin → Galaxie → Modules: the toggle board.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Core\Admin;

use Galaxie\Woo\Core\ModuleRegistry;
use Galaxie\Woo\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Deliberately a plain WordPress admin page — a fieldset of checkboxes, one per
 * module, saved through admin-post. No build step, no framework. Functional over
 * fancy on purpose.
 */
final class ModulesPage {

	private const SLUG   = 'galaxie-woo';
	private const ACTION = 'galaxie_woo_save_modules';
	private const NONCE  = 'galaxie_woo_modules';

	public function __construct( private ModuleRegistry $modules, private Settings $settings ) {}

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save' ) );
	}

	public function menu(): void {
		add_menu_page(
			__( 'Galaxie for WooCommerce', 'galaxie-woo' ),
			__( 'Galaxie', 'galaxie-woo' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-star-filled',
			56
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Galaxie for WooCommerce — Modules', 'galaxie-woo' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Modules updated.', 'galaxie-woo' ); ?></p></div>
			<?php endif; ?>

			<p class="description"><?php esc_html_e( 'Enable only what this store uses. Each module is independent.', 'galaxie-woo' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::NONCE ); ?>

				<table class="form-table" role="presentation">
					<tbody>
					<?php foreach ( $this->modules->all() as $module ) : ?>
						<tr>
							<th scope="row">
								<label for="mod-<?php echo esc_attr( $module->id() ); ?>">
									<?php echo esc_html( $module->title() ); ?>
								</label>
							</th>
							<td>
								<label>
									<input
										type="checkbox"
										id="mod-<?php echo esc_attr( $module->id() ); ?>"
										name="modules[<?php echo esc_attr( $module->id() ); ?>]"
										value="1"
										<?php checked( $this->modules->is_enabled( $module ) ); ?>
									/>
									<?php esc_html_e( 'Enabled', 'galaxie-woo' ); ?>
								</label>
								<p class="description"><?php echo esc_html( $module->description() ); ?></p>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button( __( 'Save modules', 'galaxie-woo' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'galaxie-woo' ) );
		}
		check_admin_referer( self::NONCE );

		// Unchecked boxes are absent from POST, so build the map from the full
		// module list rather than from what was submitted.
		$submitted = isset( $_POST['modules'] ) && is_array( $_POST['modules'] ) ? wp_unslash( $_POST['modules'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$map       = array();
		foreach ( $this->modules->all() as $module ) {
			$map[ $module->id() ] = ! empty( $submitted[ $module->id() ] );
		}
		$this->settings->set_enabled_map( $map );

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
