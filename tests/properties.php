<?php
/**
 * Two product properties for the checks, registered the way a store registers its own.
 *
 * tests/checks.php loads this file on the demo store, and so does the helper it writes for the shop pages.
 *
 * @package StarfinitiFilters
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A text property: black products are "Dark", white ones are both "Light" and "Bright", the rest have none.
 *
 * @param int $product_id Product ID.
 * @return string|string[]|null
 */
function sffilters_test_tone( int $product_id ) {
	$colors = wp_get_object_terms( $product_id, 'pa_color', array( 'fields' => 'slugs' ) );
	$colors = is_array( $colors ) ? $colors : array();

	if ( in_array( 'black', $colors, true ) ) {
		return 'Dark';
	}

	return in_array( 'white', $colors, true ) ? array( 'Light', 'Bright' ) : null;
}

/**
 * A number property: the weight of the product when it has one, else a length for every second product.
 *
 * @param int $product_id Product ID.
 */
function sffilters_test_length( int $product_id ): ?float {
	$product = wc_get_product( $product_id );

	if ( ! $product instanceof WC_Product ) {
		return null;
	}

	if ( '' !== (string) $product->get_weight() ) {
		return (float) $product->get_weight();
	}

	$number = (int) substr( (string) $product->get_sku(), -2 );

	return 1 === $number % 2 ? $number * 2.5 : null;
}

add_filter(
	'sffilters_properties',
	static function ( array $properties ): array {
		$properties['tone']   = array(
			'label'    => 'Tone',
			'share'    => 0.4,
			'callback' => 'sffilters_test_tone',
		);
		$properties['length'] = array(
			'label'    => 'Length',
			'type'     => 'number',
			'unit'     => 'cm',
			'share'    => 0.6,
			'callback' => 'sffilters_test_length',
		);

		// Left out: a name that is not a short key, and a property without a callback.
		$properties['Not A Key'] = array( 'callback' => '__return_null' );
		$properties['broken']    = array( 'label' => 'Broken' );

		return $properties;
	}
);
