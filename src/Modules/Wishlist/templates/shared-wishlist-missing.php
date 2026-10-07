<?php
/**
 * A shared wish list's link that leads nowhere — renewed, made private or
 * deleted — inside the theme's header and footer. Served with a 404 status and
 * noindex (see SharedPage::template() and robots()).
 *
 * @package Galaxie\Woo
 */

defined( 'ABSPATH' ) || exit;

get_header();

echo '<main id="primary" class="galaxie-shared-page"><div class="container">';
echo \Galaxie\Woo\Modules\Wishlist\SharedPage::missing_content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
echo '</div></main>';

get_footer();
