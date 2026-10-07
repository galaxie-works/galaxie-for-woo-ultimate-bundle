<?php
/**
 * Gives pixfort's Image widget the alt text written in the Media Library.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\PixfortImageAlt;

use Galaxie\Woo\Core\Module as ModuleContract;

defined( 'ABSPATH' ) || exit;

/**
 * pixfort's Image element (pixfort-core `includes/elements/Img.php`) never
 * reads the attachment's alt. It takes the widget's own "alt" field and, when
 * that is empty, writes the literal `__( 'Image link', 'pixfort-core' )`. Every
 * image placed with it — the home banners, the founder's photo, the logos —
 * was read by screen readers and search engines as "Image link".
 *
 * The image itself goes through `wp_get_attachment_image()` (pixfort's
 * `CoreFunctions::getImage()` for attachment ids), so the fix is the core
 * `wp_get_attachment_image_attributes` filter: when the alt is pixfort's
 * placeholder, use the Media Library alt instead, or an empty alt — the right
 * value for a decorative image — when the library has none.
 *
 * An alt typed in the widget is left alone. Images pixfort prints from a bare
 * URL (no attachment id) do not pass through this filter; those need the
 * widget's own field.
 */
final class Module implements ModuleContract {

	public function id(): string {
		return 'pixfort-image-alt';
	}

	public function title(): string {
		return __( 'Texto alternativo das imagens do pixfort', 'galaxie-woo' );
	}

	public function description(): string {
		return __( 'Troca o "Image link" que o widget de imagem do pixfort escreve por padrão pelo texto alternativo da Biblioteca de mídia.', 'galaxie-woo' );
	}

	public function default_enabled(): bool {
		return true;
	}

	public function boot(): void {
		add_filter( 'wp_get_attachment_image_attributes', array( self::class, 'alt' ), 20, 2 );
	}

	/**
	 * @param array<string,mixed> $attr
	 * @param \WP_Post|mixed      $attachment
	 * @return array<string,mixed>
	 */
	public static function alt( $attr, $attachment ): array {
		$attr = is_array( $attr ) ? $attr : array();

		if ( ! isset( $attr['alt'] ) || ! self::is_placeholder( (string) $attr['alt'] ) ) {
			return $attr;
		}

		$id          = $attachment instanceof \WP_Post ? (int) $attachment->ID : 0;
		$library_alt = $id ? trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) : '';
		$attr['alt'] = $library_alt;

		return $attr;
	}

	/** pixfort's default, in English or in whatever its translation says. */
	private static function is_placeholder( string $alt ): bool {
		$alt = trim( $alt );
		if ( '' === $alt ) {
			return false;
		}

		return 'Image link' === $alt || __( 'Image link', 'pixfort-core' ) === $alt; // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
	}
}
