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

/*
 * Two more hooks a store may add, switched on from the address for the checks of the shop pages:
 * `sffilters_test_share` adds a tag filter that shows from that share of the listed products (0 for always),
 * `sffilters_test_facet` leaves "Out of stock" out of the availability filter,
 * `sffilters_test_ranges` shows the price filter as ready-made ranges.
 */
add_filter(
	'sffilters_filter_definitions',
	static function ( $definitions ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test helper, reads no value.
		foreach ( isset( $_GET['sffilters_test_ranges'] ) && is_array( $definitions ) ? array_keys( $definitions ) : array() as $index ) {
			if ( 'price' === ( $definitions[ $index ]['source'] ?? '' ) ) {
				$definitions[ $index ]['display'] = 'ranges';
			}
		}

		return $definitions;
	}
);

add_filter(
	'sffilters_filter_definitions',
	static function ( $definitions ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test helper, reads a number.
		if ( ! isset( $_GET['sffilters_test_share'] ) || ! is_array( $definitions ) ) {
			return $definitions;
		}

		$definitions = array_filter( $definitions, static fn( $definition ): bool => 'product_tag' !== ( $definition['taxonomy'] ?? '' ) );

		$definitions[] = array(
			'source' => 'product_tag',
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- Test helper, cast to a number.
			'share'  => (float) $_GET['sffilters_test_share'],
		);

		return array_values( $definitions );
	}
);

add_filter(
	'sffilters_facet',
	static function ( $facet, $slug ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test helper.
		if ( ! isset( $_GET['sffilters_test_facet'] ) || 'stock' !== $slug || empty( $facet['options'] ) ) {
			return $facet;
		}

		$facet['options'] = array_values( array_filter( $facet['options'], static fn( array $option ): bool => 'outofstock' !== $option['value'] ) );

		return $facet;
	},
	10,
	2
);
