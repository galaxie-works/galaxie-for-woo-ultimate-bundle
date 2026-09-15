<?php
/**
 * A shared wishlist, inside the theme's header and footer.
 *
 * @package Galaxie\Woo
 */

defined( 'ABSPATH' ) || exit;

get_header();

echo '<main id="primary" class="galaxie-shared-page"><div class="container">';
echo \Galaxie\Woo\Modules\Wishlist\SharedPage::content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor's or the widget's own escaped markup.
echo '</div></main>';

get_footer();
