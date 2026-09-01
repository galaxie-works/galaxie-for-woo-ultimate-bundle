<?php
/**
 * Admin → Galaxie: Modules tab + one settings tab per configurable module.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Core\Admin;

use Galaxie\Woo\Core\Field;
use Galaxie\Woo\Core\Module;
use Galaxie\Woo\Core\ModuleRegistry;
use Galaxie\Woo\Core\ProvidesSettings;
use Galaxie\Woo\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Deliberately a plain WordPress admin page — tabs + a fieldset per tab, saved
 * through admin-post. No build step, no framework.
 *
 * Tab "Modules" is the on/off board. Every ENABLED module that implements
 * {@see ProvidesSettings} gets its own tab — a module's settings tab only
 * appears once it's turned on, so you configure what you just enabled instead
 * of hunting through config for things that don't apply yet.
 */
final class SettingsPage {

	private const SLUG          = 'galaxie-woo';
	private const SAVE_MODULES  = 'galaxie_woo_save_modules';
	private const SAVE_SETTINGS = 'galaxie_woo_save_settings';
	private const MODULES_NONCE = 'galaxie_woo_modules';

	public function __construct( private ModuleRegistry $modules, private Settings $settings ) {}

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_' . self::SAVE_MODULES, array( $this, 'save_modules' ) );
		add_action( 'admin_post_' . self::SAVE_SETTINGS, array( $this, 'save_settings' ) );
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

	/** @return array<string,Module&ProvidesSettings> Enabled modules with a settings tab, keyed by id. */
	private function configurable_modules(): array {
		$out = array();
		foreach ( $this->modules->enabled() as $module ) {
			if ( $module instanceof ProvidesSettings ) {
				$out[ $module->id() ] = $module;
			}
		}
		return $out;
	}

	private function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'modules';
		if ( 'modules' === $tab || array_key_exists( $tab, $this->configurable_modules() ) ) {
			return $tab;
		}
		return 'modules';
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$tab        = $this->current_tab();
		$configurable = $this->configurable_modules();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Galaxie for WooCommerce', 'galaxie-woo' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'galaxie-woo' ); ?></p></div>
			<?php endif; ?>

			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $this->tab_url( 'modules' ) ); ?>" class="nav-tab <?php echo 'modules' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Modules', 'galaxie-woo' ); ?>
				</a>
				<?php foreach ( $configurable as $id => $module ) : ?>
					<a href="<?php echo esc_url( $this->tab_url( $id ) ); ?>" class="nav-tab <?php echo $id === $tab ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $module->settings_tab_label() ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<?php if ( 'modules' === $tab ) : ?>
				<?php $this->render_modules_tab(); ?>
			<?php else : ?>
				<?php $this->render_settings_tab( $configurable[ $tab ] ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function tab_url( string $tab ): string {
		return add_query_arg( array( 'page' => self::SLUG, 'tab' => $tab ), admin_url( 'admin.php' ) );
	}

	private function render_modules_tab(): void {
		?>
		<p class="description"><?php esc_html_e( 'Enable only what this store uses. Each module is independent.', 'galaxie-woo' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_MODULES ); ?>" />
			<?php wp_nonce_field( self::MODULES_NONCE ); ?>

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
		<?php
	}

	/** @param Module&ProvidesSettings $module */
	private function render_settings_tab( $module ): void {
		$values = $this->settings->module_settings( $module->id() );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_SETTINGS ); ?>" />
			<input type="hidden" name="module" value="<?php echo esc_attr( $module->id() ); ?>" />
			<?php wp_nonce_field( self::settings_nonce_action( $module->id() ) ); ?>

			<?php $fields = $module->settings_fields(); ?>
			<?php if ( ! empty( $fields ) ) : ?>
				<table class="form-table" role="presentation">
					<tbody>
					<?php foreach ( $fields as $field ) : ?>
						<?php $this->render_field( $field, $values[ $field->key ] ?? $field->default ); ?>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php $module->render_extra_settings( $values ); ?>

			<?php submit_button( __( 'Save settings', 'galaxie-woo' ) ); ?>
		</form>
		<?php
	}

	private function render_field( Field $field, mixed $value ): void {
		$id   = 'gxf-' . $field->key;
		$name = 'fields[' . $field->key . ']';
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field->label ); ?></label></th>
			<td>
				<?php if ( Field::TYPE_TOGGLE === $field->type ) : ?>
					<label>
						<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( (bool) $value ); ?> />
						<?php echo esc_html( $field->description ); ?>
					</label>
				<?php elseif ( Field::TYPE_SELECT === $field->type ) : ?>
					<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>">
						<?php foreach ( $field->options as $option_value => $option_label ) : ?>
							<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( (string) $value, (string) $option_value ); ?>>
								<?php echo esc_html( $option_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<?php if ( $field->description ) : ?>
						<p class="description"><?php echo esc_html( $field->description ); ?></p>
					<?php endif; ?>
				<?php else : ?>
					<input
						type="<?php echo Field::TYPE_PASSWORD === $field->type ? 'password' : ( Field::TYPE_NUMBER === $field->type ? 'number' : 'text' ); ?>"
						id="<?php echo esc_attr( $id ); ?>"
						name="<?php echo esc_attr( $name ); ?>"
						value="<?php echo Field::TYPE_PASSWORD === $field->type ? '' : esc_attr( (string) $value ); ?>"
						placeholder="<?php echo Field::TYPE_PASSWORD === $field->type && '' !== (string) $value ? esc_attr__( '•••••••• (unchanged — leave blank to keep)', 'galaxie-woo' ) : esc_attr( $field->placeholder ); ?>"
						class="regular-text"
					/>
					<?php if ( $field->description ) : ?>
						<p class="description"><?php echo esc_html( $field->description ); ?></p>
					<?php endif; ?>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	public function save_modules(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'galaxie-woo' ) );
		}
		check_admin_referer( self::MODULES_NONCE );

		// Unchecked boxes are absent from POST, so build the map from the full
		// module list rather than from what was submitted.
		$submitted = isset( $_POST['modules'] ) && is_array( $_POST['modules'] ) ? wp_unslash( $_POST['modules'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$map       = array();
		foreach ( $this->modules->all() as $module ) {
			$map[ $module->id() ] = ! empty( $submitted[ $module->id() ] );
		}
		$this->settings->set_enabled_map( $map );

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'modules', 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function save_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'galaxie-woo' ) );
		}

		$module_id = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : '';
		$module    = $this->configurable_modules()[ $module_id ] ?? null;

		if ( null === $module ) {
			wp_die( esc_html__( 'Unknown or disabled module.', 'galaxie-woo' ) );
		}

		check_admin_referer( self::settings_nonce_action( $module_id ) );

		$submitted = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$current   = $this->settings->module_settings( $module_id );
		$sanitized = $module->sanitize_settings( $submitted, $current );

		$this->settings->set_module_settings( $module_id, $sanitized );

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tab' => $module_id, 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function settings_nonce_action( string $module_id ): string {
		return 'galaxie_woo_settings_' . $module_id;
	}
}
