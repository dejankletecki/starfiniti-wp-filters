<?php
/**
 * A product archive drawn the way a page builder loop draws it: from the main query,
 * without the WooCommerce loop hooks. tests/checks.php switches it on for product tag pages.
 *
 * @package StarfinitiFilters
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

echo '<div class="sffilters-results">';
echo do_shortcode( '[sffilters_bar][sffilters_ordering]' );
echo '<ul class="bde-loop">';

while ( have_posts() ) {
	the_post();
	echo '<li class="type-product">' . esc_html( get_the_title() ) . '</li>';
}

echo '</ul></div>';
echo do_shortcode( '[sffilters]' );

get_footer();
