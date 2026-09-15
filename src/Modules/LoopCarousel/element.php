<?php
/**
 * The rendering half of the Galaxie Loop Carousel.
 *
 * Deliberately not autoloaded and deliberately without a namespace: it extends
 * a pixfort class, so naming it anywhere that runs before pixfort has loaded
 * turns a missing dependency into a fatal for the whole site. The widget
 * requires this file at render time, by which point pixfort is certainly up.
 *
 * @package Galaxie\Woo
 */

defined( 'ABSPATH' ) || exit;

require_once PIX_CORE_PLUGIN_DIR . '/includes/elements/LoopCarousel.php';

if ( ! class_exists( 'GalaxieLoopCarousel' ) ) {

	/**
	 * pixfort's Loop Carousel, with the term context every dynamic tag reads.
	 *
	 * Their loop renders a template once per queried item. For posts that works:
	 * `PixLoopBuilder::render_template()` runs `setup_postdata()` AND sets
	 * `$wp_query->queried_object`, so the template sees the post.
	 *
	 * For terms it does not. `render_term_template()` sets `$wp_query->loop_term`
	 * and nothing else, and only five tags in the whole theme read that property —
	 * the `pixfort-archive` group (Archive Title, Archive Description, Archive URL,
	 * Archive Meta, Category Image). Every other dynamic tag — Elementor Pro's, ACF's,
	 * ours — resolves through `get_queried_object()`, which still points at whatever
	 * the page itself is. On a brand archive that means each slide renders the brand
	 * of the page rather than its own, and off an archive it renders nothing at all.
	 * That is why a template full of dynamic tags comes back blank in the canvas and
	 * on the site alike.
	 *
	 * So the term is published where WordPress itself keeps it, for the length of
	 * one item, and pixfort's own `loop_term` is still set by the parent call — both
	 * families of tags resolve, and neither is fought.
	 */
	class GalaxieLoopCarousel extends PixLoopCarousel {

		/**
		 * @param int|string $template_id
		 * @param \WP_Term   $term
		 * @return string
		 */
		public function render_term_template( $template_id, $term ) {
			global $wp_query;

			$has_query = $wp_query instanceof \WP_Query;

			if ( ! $has_query || ! $term instanceof \WP_Term ) {
				return parent::render_term_template( $template_id, $term );
			}

			// `isset()` is what `get_queried_object()` tests, and an unset property
			// and one holding null answer it the same way — so restoring null is a
			// faithful restore for a query that never had a queried object.
			$object    = isset( $wp_query->queried_object ) ? $wp_query->queried_object : null;
			$object_id = isset( $wp_query->queried_object_id ) ? $wp_query->queried_object_id : 0;

			$wp_query->queried_object    = $term;
			$wp_query->queried_object_id = (int) $term->term_id;

			try {
				return parent::render_term_template( $template_id, $term );
			} finally {
				$wp_query->queried_object    = $object;
				$wp_query->queried_object_id = $object_id;
			}
		}

		/**
		 * Says why the carousel is empty, in the editor only.
		 *
		 * pixfort returns an empty string both when no template is chosen and when
		 * the query matches nothing, which is indistinguishable from a broken widget
		 * while building a page — the merchant sees a blank space and no reason for
		 * it. Nothing is added on the front end, where an empty carousel should stay
		 * invisible.
		 *
		 * @param array<string,mixed> $attr
		 * @param string|null         $content
		 * @return string
		 */
		public function render( $attr, $content = null ) {
			$output = parent::render( $attr, $content );

			if ( '' !== $output || ! self::editing() ) {
				return $output;
			}

			$reason = empty( $attr['template_id'] )
				? __( 'Pick the template each slide should use, under Layout.', 'galaxie-woo' )
				: __( 'Nothing matched this query. Check Query — and that "Hide empty" is not hiding terms with no products.', 'galaxie-woo' );

			return '<p class="galaxie-loop-carousel-empty">' . esc_html( $reason ) . '</p>';
		}

		private static function editing(): bool {
			return class_exists( '\Elementor\Plugin' )
				&& \Elementor\Plugin::$instance->editor
				&& \Elementor\Plugin::$instance->editor->is_edit_mode();
		}
	}
}
