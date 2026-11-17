<?php
/**
 * Product properties a store works out with its own code.
 *
 * @package StarfinitiFilters
 */

namespace Starfiniti\Filters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A property is a value a product does not hold as a taxonomy term, for example a product-level
 * attribute "Material: forged steel with boron" that the shop wants to offer as "Forged steel".
 *
 * The store registers its properties with the `sffilters_properties` filter:
 *
 *     add_filter(
 *         'sffilters_properties',
 *         static function ( array $properties ): array {
 *             $properties['material'] = array(
 *                 'label'    => 'Material',
 *                 'callback' => 'my_store_material', // Gets the product ID. Returns a value, a list of values or null.
 *             );
 *             $properties['length']   = array(
 *                 'label'    => 'Length',
 *                 'type'     => 'number',            // The callback returns a number or null. Shown as a range.
 *                 'unit'     => 'cm',
 *                 'share'    => 0.6,                 // Hide the filter when fewer than 60 % of the listed products have a value.
 *                 'callback' => 'my_store_length',
 *             );
 *
 *             return $properties;
 *         }
 *     );
 *
 * The values are worked out when a product is saved and kept with the product: text values as terms of a
 * taxonomy without screens of its own, numbers as product meta. That way they are filtered and counted like
 * any other taxonomy or number. `wp sffilters rebuild` works them out again for every product.
 */
final class Properties {

	public const TYPES = array( 'options', 'number' );

	/**
	 * Names a property cannot have, because a filter source already goes by them.
	 */
	private const RESERVED = array( 'taxonomy', 'property', 'price', 'stock', 'sale' );

	private const BATCH = 200;

	public function register(): void {
		add_action( 'init', array( self::class, 'register_taxonomies' ), 20 );
		add_action( 'woocommerce_new_product', array( self::class, 'update' ) );
		add_action( 'woocommerce_update_product', array( self::class, 'update' ) );
	}

	/**
	 * Returns the registered properties, keyed by their name.
	 *
	 * @return array<string, array{label: string, type: string, unit: string, share: float, callback: callable}>
	 */
	public static function all(): array {
		/**
		 * Filters the product properties of the store.
		 *
		 * @param array[] $properties Properties keyed by a short name of lowercase letters, digits and underscores.
		 */
		$registered = apply_filters( 'sffilters_properties', array() );
		$properties = array();

		foreach ( is_array( $registered ) ? $registered : array() as $key => $property ) {
			// The taxonomy of a property is named after it, and a taxonomy name holds 32 characters.
			if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,21}$/', $key ) || in_array( $key, self::RESERVED, true ) || taxonomy_exists( $key ) ) {
				continue;
			}

			if ( ! is_array( $property ) || ! isset( $property['callback'] ) || ! is_callable( $property['callback'] ) ) {
				continue;
			}

			$label = isset( $property['label'] ) && is_scalar( $property['label'] ) ? trim( wp_strip_all_tags( (string) $property['label'] ) ) : '';
			$unit  = isset( $property['unit'] ) && is_scalar( $property['unit'] ) ? trim( wp_strip_all_tags( (string) $property['unit'] ) ) : '';
			$share = isset( $property['share'] ) && is_numeric( $property['share'] ) ? (float) $property['share'] : 0.0;

			$properties[ $key ] = array(
				'label'    => '' !== $label ? $label : $key,
				'type'     => in_array( $property['type'] ?? '', self::TYPES, true ) ? $property['type'] : 'options',
				'unit'     => $unit,
				'share'    => min( 1.0, max( 0.0, $share ) ),
				'callback' => $property['callback'],
			);
		}

		return $properties;
	}

	/**
	 * Returns the taxonomy that holds the values of a text property.
	 */
	public static function taxonomy( string $key ): string {
		return 'sffilters_' . $key;
	}

	/**
	 * Returns the product meta key that holds the value of a number property.
	 */
	public static function meta_key( string $key ): string {
		return '_sffilters_' . $key;
	}

	/**
	 * Registers one taxonomy per text property. It has no screens, no archive pages and no REST route.
	 */
	public static function register_taxonomies(): void {
		foreach ( self::all() as $key => $property ) {
			if ( 'options' !== $property['type'] || taxonomy_exists( self::taxonomy( $key ) ) ) {
				continue;
			}

			register_taxonomy(
				self::taxonomy( $key ),
				array( 'product' ),
				array(
					'labels'       => array( 'name' => $property['label'] ),
					'public'       => false,
					'hierarchical' => false,
					'query_var'    => false,
					'rewrite'      => false,
				)
			);
		}
	}

	/**
	 * Works out every property of one product and stores the values with it.
	 *
	 * @param int|string $product_id Product ID.
	 */
	public static function update( $product_id ): void {
		$product_id = (int) $product_id;

		if ( $product_id <= 0 || 'product' !== get_post_type( $product_id ) ) {
			return;
		}

		foreach ( self::all() as $key => $property ) {
			try {
				$value = call_user_func( $property['callback'], $product_id );
			} catch ( \Throwable $error ) {
				// A mistake in the rules of a store must not stop the product from being saved.
				wc_get_logger()->error(
					sprintf( 'The property "%1$s" could not be worked out for product %2$d: %3$s', $key, $product_id, $error->getMessage() ),
					array( 'source' => 'starfiniti-filters' )
				);

				continue;
			}

			if ( 'number' === $property['type'] ) {
				if ( is_numeric( $value ) ) {
					update_post_meta( $product_id, self::meta_key( $key ), (string) round( (float) $value, 3 ) );
				} else {
					delete_post_meta( $product_id, self::meta_key( $key ) );
				}

				continue;
			}

			$taxonomy = self::taxonomy( $key );

			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$names   = self::names( $value );
			$current = wp_get_object_terms( $product_id, $taxonomy, array( 'fields' => 'names' ) );
			$current = is_array( $current ) ? array_map( 'strval', $current ) : array();

			sort( $current );

			// Most saves change nothing here, so the terms are only written when they differ.
			if ( $current !== $names ) {
				wp_set_object_terms( $product_id, $names, $taxonomy );
			}
		}
	}

	/**
	 * Works out the properties of every product again and removes values no product has any more.
	 *
	 * @param callable|null $tick Called once per product, for a progress bar.
	 * @return int Number of products.
	 */
	public static function rebuild( ?callable $tick = null ): int {
		global $wpdb;

		$done = 0;
		$last = 0;

		wp_defer_term_counting( true );

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Walks all products by ID, which WP_Query pages cannot do reliably while terms change.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status NOT IN ( 'trash', 'auto-draft' ) AND ID > %d ORDER BY ID ASC LIMIT %d", $last, self::BATCH ) );

			foreach ( $ids as $id ) {
				self::update( (int) $id );

				$last = (int) $id;
				++$done;

				if ( null !== $tick ) {
					$tick();
				}
			}

			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		} while ( count( $ids ) === self::BATCH );

		wp_defer_term_counting( false );

		foreach ( self::all() as $key => $property ) {
			if ( 'options' !== $property['type'] || ! taxonomy_exists( self::taxonomy( $key ) ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Finds terms without any product, drafts included, which the term count does not tell.
			$unused = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT tt.term_id FROM {$wpdb->term_taxonomy} AS tt
					LEFT JOIN {$wpdb->term_relationships} AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
					WHERE tt.taxonomy = %s AND tr.object_id IS NULL",
					self::taxonomy( $key )
				)
			);

			foreach ( $unused as $term_id ) {
				wp_delete_term( (int) $term_id, self::taxonomy( $key ) );
			}
		}

		return $done;
	}

	/**
	 * Returns what a callback gave as a sorted list of names, each once.
	 *
	 * @param mixed $value A value, a list of values or nothing.
	 * @return string[]
	 */
	private static function names( $value ): array {
		$names = array();

		foreach ( is_array( $value ) ? $value : array( $value ) as $name ) {
			$name = is_scalar( $name ) ? trim( wp_strip_all_tags( (string) $name ) ) : '';

			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		$names = array_values( array_unique( $names ) );

		sort( $names );

		return $names;
	}
}
