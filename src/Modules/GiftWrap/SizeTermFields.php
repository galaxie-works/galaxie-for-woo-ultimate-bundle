<?php
/**
 * Gift packing dimensions per candle size (attribute term).
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\GiftWrap;

use Galaxie\Woo\Support\GiftPacking;

defined( 'ABSPATH' ) || exit;

/**
 * "Medidas para embalagem de presente (cm)" on each term of the candle size
 * attribute (Products → Attributes → Peso → edit a term): every 190g jar is the
 * same jar, so its size is filled once here instead of on every variation.
 *
 * `GiftPacking::candle_from_product()` reads, most specific first: the
 * variation's own gift dimensions ({@see CandleFields}), then these, each only
 * when all three are set, then WooCommerce's shipping dimensions.
 *
 * Stored as term meta under the same keys as the variation fields, cleaned by
 * {@see CandleFields::clean()}. Registered with `show_in_rest` (writable with
 * `manage_product_terms`), and the attribute taxonomy itself is shown in the
 * REST API, so `wp/v2/{attribute}/{term_id}` can set them. Any change clears
 * the cached store sizes (`GiftPacking::watch_sizes()`).
 *
 * Self-contained: the Gift Wrap module boots it with its size attribute setting.
 */
final class SizeTermFields {

	private const NONCE = 'galaxie_gift_size_term_fields';

	private const NONCE_FIELD = 'galaxie_gift_size_term_nonce';

	/** What managing attribute terms takes in WooCommerce. */
	public const CAPABILITY = 'manage_product_terms';

	/**
	 * @param string $attribute Candle size attribute taxonomy, e.g. `pa_peso`.
	 */
	public function __construct( private string $attribute = 'pa_peso' ) {}

	/**
	 * Built at boot with `Module::size_attribute()`, which already names the
	 * taxonomy ("peso" typed for the global attribute Peso is `pa_peso`), so
	 * everything hooks on that name straight away.
	 */
	public function register(): void {
		if ( '' === $this->attribute ) {
			return;
		}

		$taxonomy = $this->attribute;

		add_action( $taxonomy . '_add_form_fields', array( $this, 'render_add' ) );
		add_action( $taxonomy . '_edit_form_fields', array( $this, 'render_edit' ), 10, 1 );
		add_action( 'created_' . $taxonomy, array( $this, 'save' ), 10, 1 );
		add_action( 'edited_' . $taxonomy, array( $this, 'save' ), 10, 1 );

		// WooCommerce registers attribute taxonomies without REST (on init,
		// through this filter); this one only.
		add_filter( 'woocommerce_taxonomy_args_' . $taxonomy, array( self::class, 'show_in_rest' ) );

		$descriptions = array(
			'length' => 'The item of this size alone, as it goes into a gift box: length in cm. Used when the variation has no gift dimensions of its own.',
			'width'  => 'The item of this size alone, as it goes into a gift box: width in cm.',
			'height' => 'The item of this size alone, as it goes into a gift box: height in cm.',
		);

		foreach ( CandleFields::META as $field => $key ) {
			register_term_meta(
				$taxonomy,
				$key,
				array(
					'type'              => 'number',
					'description'       => $descriptions[ $field ],
					'single'            => true,
					'show_in_rest'      => array(
						'schema' => array(
							'type'    => 'number',
							'minimum' => 0,
						),
					),
					'sanitize_callback' => array( CandleFields::class, 'clean' ),
					'auth_callback'     => array( self::class, 'can_manage' ),
				)
			);
		}

		GiftPacking::watch_sizes();
	}

	/**
	 * Shows the size attribute in the REST API, unless something already chose.
	 *
	 * @param array $args Taxonomy arguments.
	 */
	public static function show_in_rest( $args ): array {
		$args                 = (array) $args;
		$args['show_in_rest'] = $args['show_in_rest'] ?? true;

		return $args;
	}

	/**
	 * `auth_callback` for the registered term meta.
	 *
	 * @param bool   $allowed  Unused.
	 * @param string $meta_key Unused.
	 * @param int    $term_id  Unused: WooCommerce's term capabilities are not per term.
	 * @param int    $user_id  User.
	 */
	public static function can_manage( $allowed, $meta_key, $term_id, $user_id ): bool {
		return user_can( (int) $user_id, self::CAPABILITY );
	}

	/** The fields on the "Add new" form beside the terms list. */
	public function render_add(): void {
		wp_nonce_field( self::NONCE, self::NONCE_FIELD, false );

		echo '<div class="form-field galaxie-gift-size-fields"><p><strong>' . esc_html( self::heading() ) . '</strong><br>' . esc_html( self::help() ) . '</p></div>';

		foreach ( self::labels() as $field => $label ) {
			printf(
				'<div class="form-field"><label for="%1$s">%2$s</label><input type="number" name="%1$s" id="%1$s" value="" step="0.01" min="0" /></div>',
				esc_attr( CandleFields::META[ $field ] ),
				esc_html( $label )
			);
		}
	}

	/**
	 * The fields on a term's edit screen.
	 *
	 * @param \WP_Term $term Term being edited.
	 */
	public function render_edit( $term ): void {
		$term_id = is_object( $term ) && isset( $term->term_id ) ? (int) $term->term_id : 0;

		echo '<tr class="form-field galaxie-gift-size-fields"><th scope="row">' . esc_html( self::heading() ) . '</th><td><p class="description">' . esc_html( self::help() ) . '</p>';
		wp_nonce_field( self::NONCE, self::NONCE_FIELD, false );
		echo '</td></tr>';

		foreach ( self::labels() as $field => $label ) {
			$key   = CandleFields::META[ $field ];
			$value = $term_id > 0 ? (float) get_term_meta( $term_id, $key, true ) : 0.0;

			printf(
				'<tr class="form-field"><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="number" name="%1$s" id="%1$s" value="%3$s" step="0.01" min="0" /></td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $value > 0 ? wc_format_localized_decimal( $value ) : '' )
			);
		}
	}

	/**
	 * Saves the fields when a term is added or edited. WordPress checks its own
	 * nonce first; ours makes sure the values came from these fields.
	 *
	 * @param int $term_id Term ID.
	 */
	public function save( $term_id ): void {
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE ) || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$term_id = (int) $term_id;

		foreach ( CandleFields::META as $key ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wc_clean below.
			if ( ! isset( $_POST[ $key ] ) || is_array( $_POST[ $key ] ) ) {
				continue;
			}

			$value = CandleFields::clean( wc_clean( wp_unslash( $_POST[ $key ] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			'' !== $value ? update_term_meta( $term_id, $key, $value ) : delete_term_meta( $term_id, $key );
		}
	}

	private static function heading(): string {
		return __( 'Medidas para embalagem de presente (cm)', 'galaxie-woo' );
	}

	private static function help(): string {
		return __( 'O item deste tamanho sozinho, como vai dentro da caixa de presente (sem a embalagem de envio). Vale para todas as variações deste tamanho que não tenham medidas próprias. Em branco usa as dimensões de envio de cada variação.', 'galaxie-woo' );
	}

	/** @return array<string,string> Field => label. */
	private static function labels(): array {
		return array(
			'length' => __( 'Comprimento (cm)', 'galaxie-woo' ),
			'width'  => __( 'Largura (cm)', 'galaxie-woo' ),
			'height' => __( 'Altura (cm)', 'galaxie-woo' ),
		);
	}
}
