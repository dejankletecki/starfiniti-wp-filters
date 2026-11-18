<?php
/**
 * Checks the plugin inside a real WordPress with WooCommerce and the demo catalog of playground/setup.php.
 *
 * `npm test` runs this in WordPress Playground. On any other install: `wp eval-file tests/checks.php`.
 * What a filter should return is worked out here from the products themselves, then compared with
 * what the plugin returns. The results also go to .cache/test-results.json, together with the totals
 * that tests/run.mjs expects on the shop pages.
 *
 * @package StarfinitiFilters
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

$sffilters_results = array();

$check = static function ( string $name, bool $pass, $detail = '' ) use ( &$sffilters_results ): void {
	$sffilters_results[] = array(
		'name'   => $name,
		'pass'   => $pass,
		'detail' => $pass ? '' : ( is_string( $detail ) ? $detail : (string) wp_json_encode( $detail ) ),
	);
};

$saved_settings = get_option( 'sffilters_settings', null );

$products = wc_get_products(
	array(
		'limit'  => -1,
		'status' => 'publish',
	)
);

$check( 'catalog: the demo catalog has 24 products', 24 === count( $products ), count( $products ) );

// Only the demo store gets the test properties and the test layout. tests/run.mjs asks for their pages.
$demo  = 24 === count( $products );
$known = array(
	array(
		'source' => 'product_cat',
		'slug'   => 'cat',
	),
	array(
		'source' => 'product_brand',
		'slug'   => 'brand',
	),
	array(
		'source' => 'pa_color',
		'slug'   => 'color',
	),
	array( 'source' => 'price' ),
	array( 'source' => 'stock' ),
	array( 'source' => 'sale' ),
);

if ( $demo ) {
	require_once SFFILTERS_PLUGIN_DIR . 'tests/properties.php';

	\Starfiniti\Filters\Properties::register_taxonomies();

	$check( 'properties: only usable properties are registered', array( 'tone', 'length' ) === array_keys( \Starfiniti\Filters\Properties::all() ), array_keys( \Starfiniti\Filters\Properties::all() ) );
	$check( 'properties: every product is worked out', count( $products ) === \Starfiniti\Filters\Properties::rebuild() );

	$known[] = array( 'source' => 'tone' );
	$known[] = array( 'source' => 'length' );
}

// A known set of filters, whatever the store had saved.
$saved = \Starfiniti\Filters\Settings::save(
	array(
		'pagination' => 'pages',
		'filters'    => $known,
	)
);

$check( 'settings: a valid list of filters is saved', true === $saved, is_wp_error( $saved ) ? $saved->get_error_message() : '' );

$filters = \Starfiniti\Filters\Settings::filters();

// --- What the products say.

$slugs = static function ( WC_Product $product, string $taxonomy ): array {
	$terms = wp_get_object_terms( $product->get_id(), $taxonomy, array( 'fields' => 'slugs' ) );

	return is_array( $terms ) ? $terms : array();
};

$in_category = static function ( WC_Product $product, string $slug ): bool {
	$term = get_term_by( 'slug', $slug, 'product_cat' );

	if ( ! $term instanceof WP_Term ) {
		return false;
	}

	$ids = array_merge( array( $term->term_id ), (array) get_term_children( $term->term_id, 'product_cat' ) );

	return array() !== array_intersect( $ids, $product->get_category_ids() );
};

$expect = static function ( callable $keep ) use ( $products ): array {
	$ids = array();

	foreach ( $products as $product ) {
		if ( $keep( $product ) ) {
			$ids[] = $product->get_id();
		}
	}

	sort( $ids );

	return $ids;
};

// --- What the plugin returns.

$base = array(
	'post_type'   => 'product',
	'post_status' => 'publish',
);

$state = static function ( array $request ) use ( $filters ): \Starfiniti\Filters\State {
	return \Starfiniti\Filters\State::from_request( $filters, $request );
};

$found = static function ( array $request ) use ( $filters, $state, $base ): array {
	$query = new WP_Query(
		array_merge(
			\Starfiniti\Filters\Query::apply( $base, $filters, $state( $request ) ),
			array(
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		)
	);

	$ids = array_map( 'intval', $query->posts );

	sort( $ids );

	return $ids;
};

$all  = $expect( '__return_true' );
$http = array();

$cases = array(
	'no filter returns every product'          => array(
		array(),
		'__return_true',
	),
	'category'                                 => array(
		array( 'sf_cat' => 'headphones' ),
		static fn( $p ) => $in_category( $p, 'headphones' ),
	),
	'parent category includes its children'    => array(
		array( 'sf_cat' => 'audio' ),
		static fn( $p ) => $in_category( $p, 'audio' ),
	),
	'two values of one filter widen the list'  => array(
		array( 'sf_brand' => 'anker,xiaomi' ),
		static fn( $p ) => array() !== array_intersect( array( 'anker', 'xiaomi' ), $slugs( $p, 'product_brand' ) ),
	),
	'values given as a list'                   => array(
		array( 'sf_brand' => array( 'anker', 'xiaomi' ) ),
		static fn( $p ) => array() !== array_intersect( array( 'anker', 'xiaomi' ), $slugs( $p, 'product_brand' ) ),
	),
	'two filters narrow the list'              => array(
		array(
			'sf_cat'   => 'audio',
			'sf_brand' => 'anker',
		),
		static fn( $p ) => $in_category( $p, 'audio' ) && in_array( 'anker', $slugs( $p, 'product_brand' ), true ),
	),
	'attribute'                                => array(
		array( 'sf_color' => 'black' ),
		static fn( $p ) => in_array( 'black', $slugs( $p, 'pa_color' ), true ),
	),
	'price range'                              => array(
		array( 'sf_price' => '50-200' ),
		static fn( $p ) => (float) $p->get_price() >= 50 && (float) $p->get_price() <= 200,
	),
	'price range written backwards'            => array(
		array( 'sf_price' => '200-50' ),
		static fn( $p ) => (float) $p->get_price() >= 50 && (float) $p->get_price() <= 200,
	),
	'price from'                               => array(
		array( 'sf_price' => '100-' ),
		static fn( $p ) => (float) $p->get_price() >= 100,
	),
	'price up to, with a decimal comma'        => array(
		array( 'sf_price' => '-100,5' ),
		static fn( $p ) => (float) $p->get_price() <= 100.5,
	),
	'in stock'                                 => array(
		array( 'sf_stock' => 'instock' ),
		static fn( $p ) => 'instock' === $p->get_stock_status(),
	),
	'on sale'                                  => array(
		array( 'sf_sale' => '1' ),
		static fn( $p ) => $p->is_on_sale(),
	),
	'every kind of filter at once'             => array(
		array(
			'sf_cat'   => 'audio',
			'sf_brand' => 'anker,xiaomi',
			'sf_price' => '50-250',
			'sf_stock' => 'instock',
		),
		static fn( $p ) => $in_category( $p, 'audio' )
			&& array() !== array_intersect( array( 'anker', 'xiaomi' ), $slugs( $p, 'product_brand' ) )
			&& (float) $p->get_price() >= 50 && (float) $p->get_price() <= 250
			&& 'instock' === $p->get_stock_status(),
	),
	'a term that does not exist finds nothing' => array(
		array( 'sf_cat' => 'no-such-category' ),
		'__return_false',
	),
	'a stock value with SQL in it is ignored'  => array(
		array( 'sf_stock' => "instock' OR '1'='1" ),
		'__return_true',
	),
	'a price that is not a number is ignored'  => array(
		array( 'sf_price' => 'cheap' ),
		'__return_true',
	),
	'a sale value other than 1 is ignored'     => array(
		array( 'sf_sale' => 'yes' ),
		'__return_true',
	),
);

$tones  = static fn( $p ) => (array) sffilters_test_tone( $p->get_id() );
$length = static fn( $p ) => sffilters_test_length( $p->get_id() );

if ( $demo ) {
	$cases += array(
		'property'                                  => array(
			array( 'sf_tone' => 'dark' ),
			static fn( $p ) => in_array( 'Dark', $tones( $p ), true ),
		),
		'property with two values on one product'   => array(
			array( 'sf_tone' => 'bright' ),
			static fn( $p ) => in_array( 'Bright', $tones( $p ), true ),
		),
		'two values of a property widen the list'   => array(
			array( 'sf_tone' => 'dark,light' ),
			static fn( $p ) => array() !== array_intersect( array( 'Dark', 'Light' ), $tones( $p ) ),
		),
		'number range'                              => array(
			array( 'sf_length' => '10-30' ),
			static fn( $p ) => null !== $length( $p ) && $length( $p ) >= 10 && $length( $p ) <= 30,
		),
		'number from'                               => array(
			array( 'sf_length' => '40-' ),
			static fn( $p ) => null !== $length( $p ) && $length( $p ) >= 40,
		),
		'number up to, with a decimal comma'        => array(
			array( 'sf_length' => '-12,5' ),
			static fn( $p ) => null !== $length( $p ) && $length( $p ) <= 12.5,
		),
		'property, number and category at once'     => array(
			array(
				'sf_tone'   => 'dark',
				'sf_length' => '5-',
				'sf_cat'    => 'audio',
			),
			static fn( $p ) => in_array( 'Dark', $tones( $p ), true ) && null !== $length( $p ) && $length( $p ) >= 5 && $in_category( $p, 'audio' ),
		),
		'a number that is not a number is ignored'  => array(
			array( 'sf_length' => 'long' ),
			'__return_true',
		),
		'a property value nobody has finds nothing' => array(
			array( 'sf_tone' => 'no-such-tone' ),
			'__return_false',
		),
	);
}

foreach ( $cases as $name => $case ) {
	$expected = $expect( $case[1] );
	$actual   = $found( $case[0] );

	$check(
		'query: ' . $name,
		$expected === $actual,
		array(
			'expected' => $expected,
			'found'    => $actual,
		)
	);

	// The same requests go to the shop page in tests/run.mjs.
	$http[] = array(
		'name'  => $name,
		'path'  => '/shop/',
		'query' => http_build_query( $case[0] ),
		'total' => count( $expected ),
	);
}

$check( 'query: the cases cover a real choice', count( $expect( $cases['every kind of filter at once'][1] ) ) > 0 && count( $expect( $cases['every kind of filter at once'][1] ) ) < count( $all ) );

$http[] = array(
	'name'  => 'a filter on a category page',
	'path'  => '/product-category/audio/',
	'query' => 'sf_brand=anker',
	'total' => count( $expect( static fn( $p ) => $in_category( $p, 'audio' ) && in_array( 'anker', $slugs( $p, 'product_brand' ), true ) ) ),
);

foreach ( array( 'price', 'price-desc', 'popularity', 'rating', 'date' ) as $orderby ) {
	$http[] = array(
		'name'  => 'sale and price filters sorted by ' . $orderby,
		'path'  => '/shop/',
		'query' => 'sf_sale=1&sf_price=50-&orderby=' . $orderby,
		'total' => count( $expect( static fn( $p ) => $p->is_on_sale() && (float) $p->get_price() >= 50 ) ),
	);
}

// --- A product list drawn without the WooCommerce loop hooks, the way a page builder loop draws it.

$check( 'layout: the bar and the sorting print nothing where there is no product list', '' === do_shortcode( '[sffilters_bar][sffilters_ordering]' ) );

$loop = null;
$tags = get_terms(
	array(
		'taxonomy'   => 'product_tag',
		'hide_empty' => true,
	)
);

if ( $demo && is_array( $tags ) && array() !== $tags ) {
	$tag    = $tags[0];
	$tagged = static fn( $p ) => in_array( $tag->slug, $slugs( $p, 'product_tag' ), true );
	$brand  = '';
	$fewest = PHP_INT_MAX;

	foreach ( array( 'anker', 'xiaomi', 'dyson' ) as $candidate ) {
		$with = count( $expect( static fn( $p ) => $tagged( $p ) && in_array( $candidate, $slugs( $p, 'product_brand' ), true ) ) );

		if ( $with > 0 && $with < $fewest ) {
			$brand  = $candidate;
			$fewest = $with;
		}
	}

	$check( 'layout: the tag of the test layout has products of a brand', '' !== $brand, $tag->slug );

	wp_mkdir_p( WPMU_PLUGIN_DIR );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A helper of a test run.
	file_put_contents(
		WPMU_PLUGIN_DIR . '/sffilters-test-loop.php',
		"<?php\n// Written by tests/checks.php of Starfiniti Filters. Registers the test properties, draws product tag pages\n// without the WooCommerce loop hooks and shows categories as links, one level at a time or the whole tree, when the address asks for it.\n"
		. "add_action(\n\t'plugins_loaded',\n\tstatic function () {\n"
		. "\t\tif ( defined( 'SFFILTERS_PLUGIN_DIR' ) ) {\n\t\t\trequire_once SFFILTERS_PLUGIN_DIR . 'tests/properties.php';\n\t\t}\n"
		. "\t},\n\t5\n);\n"
		. "add_filter(\n\t'sffilters_filter_definitions',\n\tstatic function ( \$definitions ) {\n"
		. "\t\tforeach ( isset( \$_GET['sffilters_test_level'] ) ? array_keys( \$definitions ) : array() as \$index ) {\n"
		. "\t\t\tif ( 'product_cat' === ( \$definitions[ \$index ]['taxonomy'] ?? '' ) ) {\n\t\t\t\t\$definitions[ \$index ]['display'] = 'links' === \$_GET['sffilters_test_level'] ? 'links' : 'level';\n\t\t\t}\n\t\t}\n\n"
		. "\t\treturn \$definitions;\n\t}\n);\n"
		. "// Prints what an SEO plugin would get from the `rank_math/frontend/robots` filter.\n"
		. "add_action(\n\t'wp_head',\n\tstatic function () {\n"
		. "\t\tif ( isset( \$_GET['sffilters_test_robots'] ) ) {\n"
		. "\t\t\techo '<meta name=\"sffilters-test-robots\" content=\"' . esc_attr( implode( ',', (array) apply_filters( 'rank_math/frontend/robots', array( 'index' => 'index', 'follow' => 'follow' ) ) ) ) . '\">';\n"
		. "\t\t}\n\t}\n);\n"
		. "add_filter(\n\t'template_include',\n\tstatic function ( \$template ) {\n"
		. "\t\treturn defined( 'SFFILTERS_PLUGIN_DIR' ) && is_tax( 'product_tag' ) ? SFFILTERS_PLUGIN_DIR . 'tests/custom-loop.php' : \$template;\n"
		. "\t},\n\tPHP_INT_MAX\n);\n"
	);

	$loop = array(
		'path'     => (string) wp_parse_url( (string) get_term_link( $tag ), PHP_URL_PATH ),
		'query'    => 'sf_brand=' . $brand,
		'filtered' => $fewest,
	);
}

// --- Counts in the panel.

$facet = static function ( string $slug, array $request ) use ( $filters, $state, $base ): array {
	$current = $state( $request );
	$ids_sql = \Starfiniti\Filters\Query::ids_sql( \Starfiniti\Filters\Query::apply( $base, $filters, $current->without( $slug ) ) );

	return $filters[ $slug ]->facet( $ids_sql, $current->values( $slug ) );
};

$flatten = static function ( array $options ) use ( &$flatten ): array {
	$flat = array();

	foreach ( $options as $option ) {
		$flat[ $option['value'] ] = (int) $option['count'];
		$flat                    += $flatten( $option['children'] ?? array() );
	}

	return $flat;
};

$brands   = $flatten( $facet( 'brand', array( 'sf_cat' => 'audio' ) )['options'] ?? array() );
$expected = array();

foreach ( array( 'anker', 'dyson', 'xiaomi' ) as $brand ) {
	$expected[ $brand ] = count( $expect( static fn( $p ) => $in_category( $p, 'audio' ) && in_array( $brand, $slugs( $p, 'product_brand' ), true ) ) );
}

ksort( $brands );
$check(
	'counts: brands inside a chosen category',
	$expected === $brands,
	array(
		'expected' => $expected,
		'found'    => $brands,
	)
);

// A filter counts its own choices as if it were not in use, so picking one brand still shows the others.
$brands = $flatten( $facet( 'brand', array( 'sf_brand' => 'anker' ) )['options'] ?? array() );
$check( 'counts: the other brands stay countable once one is chosen', ( $brands['xiaomi'] ?? 0 ) === count( $expect( static fn( $p ) => in_array( 'xiaomi', $slugs( $p, 'product_brand' ), true ) ) ), $brands );

$categories = $flatten( $facet( 'cat', array( 'sf_stock' => 'instock' ) )['options'] ?? array() );
$expected   = array();

foreach ( array_keys( $categories ) as $category ) {
	$expected[ $category ] = count( $expect( static fn( $p ) => $in_category( $p, $category ) && 'instock' === $p->get_stock_status() ) );
}

$check(
	'counts: categories of products in stock, parents with their children',
	array() !== $categories && $expected === $categories && isset( $categories['audio'], $categories['headphones'] ),
	array(
		'expected' => $expected,
		'found'    => $categories,
	)
);

$prices = array_map( static fn( $id ) => (float) wc_get_product( $id )->get_price(), $expect( static fn( $p ) => in_array( 'anker', $slugs( $p, 'product_brand' ), true ) ) );
$range  = $facet( 'price', array( 'sf_brand' => 'anker' ) );
$check( 'counts: price range of a chosen brand', array() !== $prices && floor( min( $prices ) ) === (float) ( $range['min'] ?? -1 ) && ceil( max( $prices ) ) === (float) ( $range['max'] ?? -1 ), $range );

$stock = $flatten( $facet( 'stock', array( 'sf_sale' => '1' ) )['options'] ?? array() );
$check( 'counts: stock of products on sale', ( $stock['instock'] ?? 0 ) === count( $expect( static fn( $p ) => $p->is_on_sale() && 'instock' === $p->get_stock_status() ) ), $stock );

foreach ( array( 'wearables', 'home' ) as $category ) {
	$sale     = $facet( 'sale', array( 'sf_cat' => $category ) );
	$expected = count( $expect( static fn( $p ) => $p->is_on_sale() && $in_category( $p, $category ) ) );

	// A choice that would find nothing is not shown at all.
	$check( 'counts: products on sale in the category ' . $category, 0 === $expected ? array() === $sale : ( $sale['count'] ?? -1 ) === $expected, $sale );
}

// --- Categories as links, one level at a time.

$level  = null;
$walked = new \Starfiniti\Filters\Filter\TaxonomyFilter( 'cat', 'Categories', 'product_cat', 'level' );
$term   = static fn( string $slug ) => get_term_by( 'slug', $slug, 'product_cat' );

if ( $demo && $term( 'audio' ) && $term( 'headphones' ) && $term( 'speakers' ) && $term( 'home' ) ) {
	$main_query = $GLOBALS['wp_query'];

	\Starfiniti\Filters\Plugin::instance()->set_base_query_vars( $base );

	// Asks for the links the way a page of this category would.
	$on = static function ( string $slug ) use ( $walked ): array {
		$GLOBALS['wp_query'] = '' === $slug
			? new WP_Query()
			: new WP_Query(
				array(
					'post_type'   => 'product',
					'product_cat' => $slug,
				)
			);

		return $walked->facet( '', array() );
	};

	$names   = static fn( array $facet ): array => array_column( $facet['options'] ?? array(), 'label' );
	$current = static fn( array $facet ): array => array_column( array_filter( $facet['options'] ?? array(), static fn( $option ) => $option['current'] ), 'label' );
	$top     = array();

	foreach ( get_terms( array( 'taxonomy' => 'product_cat', 'parent' => 0, 'hide_empty' => true, 'orderby' => 'name' ) ) as $found_term ) {
		if ( array() !== $expect( static fn( $p ) => $in_category( $p, $found_term->slug ) ) ) {
			$top[] = $found_term->name;
		}
	}

	$shop = $on( '' );
	$check( 'level: the shop lists the top categories', $top === $names( $shop ) && in_array( 'Audio', $top, true ) && ! in_array( 'Headphones', $top, true ) && empty( $shop['back'] ), $names( $shop ) );

	$parent = $on( 'audio' );
	$check( 'level: a category lists its subcategories', array( 'Headphones', 'Speakers' ) === $names( $parent ) && array() === $current( $parent ), $names( $parent ) );
	$check( 'level: a top category leads back to all products', wc_get_page_permalink( 'shop' ) === ( $parent['back']['url'] ?? '' ), $parent['back'] ?? null );

	$leaf = $on( 'headphones' );
	$check( 'level: a category without subcategories lists the ones beside it', array( 'Headphones', 'Speakers' ) === $names( $leaf ) && array( 'Headphones' ) === $current( $leaf ), $leaf );
	$check( 'level: a subcategory leads back one level up', get_term_link( $term( 'audio' ) ) === ( $leaf['back']['url'] ?? '' ) && false !== strpos( $leaf['back']['label'] ?? '', 'Audio' ), $leaf['back'] ?? null );
	$check( 'level: the count is what the category page lists', count( $expect( static fn( $p ) => $in_category( $p, 'speakers' ) ) ) === (int) ( array_column( $leaf['options'], 'count', 'label' )['Speakers'] ?? -1 ) );

	$alone = $on( 'home' );
	$check( 'level: a top category without subcategories lists the top categories', $top === $names( $alone ) && array( 'Home' ) === $current( $alone ), $alone );

	$check( 'level: links read nothing from the URL', array() === $walked->parse( 'audio' ) );

	// Checkboxes on a category page. One pair of headphones is also filed under Home for these checks.
	$boxes   = new \Starfiniti\Filters\Filter\TaxonomyFilter( 'cat', 'Categories', 'product_cat' );
	$crossed = $expect( static fn( $p ) => $in_category( $p, 'headphones' ) )[0];
	$offered = static function ( string $slug ) use ( $boxes, $base ): array {
		$GLOBALS['wp_query'] = new WP_Query(
			array(
				'post_type'   => 'product',
				'product_cat' => $slug,
			)
		);

		$facet = $boxes->facet( \Starfiniti\Filters\Query::ids_sql( array_merge( $base, array( 'product_cat' => $slug ) ) ), array() );

		return array_column( $facet['options'] ?? array(), 'count', 'label' );
	};

	wp_set_object_terms( $crossed, array( $term( 'home' )->term_id ), 'product_cat', true );

	$check( 'category page: the checkboxes offer the other categories of the listed products', array( 'Home' => 1 ) === $offered( 'headphones' ), $offered( 'headphones' ) );
	$check( 'category page: its subcategories come first, the category itself is not offered', array( 'Headphones', 'Home', 'Speakers' ) === array_keys( $offered( 'audio' ) ) && 1 === $offered( 'audio' )['Home'], $offered( 'audio' ) );

	wp_remove_object_terms( $crossed, array( $term( 'home' )->term_id ), 'product_cat' );

	$check( 'category page: a category whose products are in no other category offers none', array() === $offered( 'headphones' ), $offered( 'headphones' ) );

	$GLOBALS['wp_query'] = $main_query;

	$path  = static fn( string $slug ): string => (string) wp_parse_url( (string) get_term_link( $term( $slug ) ), PHP_URL_PATH );
	$level = array(
		'leaf'   => $path( 'headphones' ),
		'beside' => $path( 'speakers' ),
		'above'  => $path( 'audio' ),
	);
}

// --- Product properties of the store.

$properties = null;

if ( $demo ) {
	$stored = true;

	foreach ( $products as $product ) {
		$names = wp_get_object_terms( $product->get_id(), \Starfiniti\Filters\Properties::taxonomy( 'tone' ), array( 'fields' => 'names' ) );
		$names = is_array( $names ) ? $names : array();
		$tone  = $tones( $product );
		$meta  = get_post_meta( $product->get_id(), \Starfiniti\Filters\Properties::meta_key( 'length' ), true );

		sort( $names );
		sort( $tone );

		$stored = $stored && $names === $tone && ( null === $length( $product ) ? '' === $meta : (float) $meta === $length( $product ) );
	}

	$check( 'properties: the values kept with the products are what the store works out', $stored );

	$found_tones = $flatten( $facet( 'tone', array( 'sf_cat' => 'audio' ) )['options'] ?? array() );
	$expected    = array();

	foreach ( array( 'bright', 'dark', 'light' ) as $tone ) {
		$expected[ $tone ] = count( $expect( static fn( $p ) => $in_category( $p, 'audio' ) && in_array( ucfirst( $tone ), $tones( $p ), true ) ) );
	}

	ksort( $found_tones );
	$check(
		'properties: counts of a property inside a chosen category',
		$expected === $found_tones,
		array(
			'expected' => $expected,
			'found'    => $found_tones,
		)
	);

	$lengths = array_filter( array_map( $length, array_filter( $products, static fn( $p ) => in_array( 'anker', $slugs( $p, 'product_brand' ), true ) ) ), 'is_float' );
	$range   = $facet( 'length', array( 'sf_brand' => 'anker', 'sf_length' => '1-' ) );
	$check( 'properties: number range of a chosen brand', array() !== $lengths && floor( min( $lengths ) ) === (float) ( $range['min'] ?? -1 ) && ceil( max( $lengths ) ) === (float) ( $range['max'] ?? -1 ) && 'cm' === ( $range['unit'] ?? '' ), $range );

	// A property is hidden where too few of the listed products have a value: tone needs 40 %, length 60 %.
	$share = static function ( array $request, callable $has ) use ( $filters, $state, $found, $products ): float {
		$listed = $found( $request );
		$with   = 0;

		foreach ( $products as $product ) {
			if ( in_array( $product->get_id(), $listed, true ) && $has( $product ) ) {
				++$with;
			}
		}

		return array() === $listed ? 0.0 : $with / count( $listed );
	};

	$has_tone   = static fn( $p ) => array() !== $tones( $p );
	$has_length = static fn( $p ) => null !== $length( $p );
	$few        = array( 'sf_price' => '75-99' );
	$enough     = array( 'sf_price' => '51-99' );

	$check( 'properties: the test ranges sit on both sides of the share', $share( $few, $has_tone ) > 0 && $share( $few, $has_tone ) < 0.4 && $share( $enough, $has_tone ) >= 0.4, array( $share( $few, $has_tone ), $share( $enough, $has_tone ) ) );
	$check( 'properties: a property few of the listed products have is hidden', array() === $facet( 'tone', $few ) );
	$check( 'properties: a property enough of the listed products have is shown', array() !== $facet( 'tone', $enough ) );
	$check( 'properties: a hidden property stays visible while it is in use', array() !== $facet( 'tone', $few + array( 'sf_tone' => 'dark' ) ) );

	$check( 'properties: the whole shop is below the share of the number', $share( array(), $has_length ) < 0.6 && $share( array( 'sf_cat' => 'headphones' ), $has_length ) >= 0.6 );
	$check( 'properties: a number few of the listed products have is hidden', array() === $facet( 'length', array() ) );
	$check( 'properties: a number enough of the listed products have is shown', 'range' === ( $facet( 'length', array( 'sf_cat' => 'headphones' ) )['kind'] ?? '' ) );
	$check( 'properties: a hidden number stays visible while it is in use', 'range' === ( $facet( 'length', array( 'sf_length' => '10-30' ) )['kind'] ?? '' ) );

	$chip = $filters['length']->selected( $filters['length']->parse( '10-30' ) )[0]['label'] ?? '';
	$check( 'properties: the selected range names its filter and its unit', false !== strpos( $chip, 'Length' ) && false !== strpos( $chip, '10 cm' ) && false !== strpos( $chip, '30 cm' ), $chip );

	// Saving a product works its properties out again.
	$even = null;

	foreach ( $products as $product ) {
		if ( null === $length( $product ) ) {
			$even = wc_get_product( $product->get_id() );
			break;
		}
	}

	if ( $even instanceof WC_Product ) {
		$even->set_weight( '77.5' );
		$even->save();

		$check( 'properties: saving a product stores its new value', 77.5 === (float) get_post_meta( $even->get_id(), \Starfiniti\Filters\Properties::meta_key( 'length' ), true ) );
		$check( 'properties: the new value is found by the filter', in_array( $even->get_id(), $found( array( 'sf_length' => '77-78' ) ), true ) );

		$even->set_weight( '' );
		$even->save();

		$check( 'properties: a value the product no longer has is removed', '' === get_post_meta( $even->get_id(), \Starfiniti\Filters\Properties::meta_key( 'length' ), true ) );
	}

	$stale = wp_insert_term( 'Stale', \Starfiniti\Filters\Properties::taxonomy( 'tone' ) );
	\Starfiniti\Filters\Properties::rebuild();
	$check( 'properties: a rebuild removes values no product has', ! is_wp_error( $stale ) && null === get_term( $stale['term_id'], \Starfiniti\Filters\Properties::taxonomy( 'tone' ) ) );

	$headphones = get_term_by( 'slug', 'headphones', 'product_cat' );
	$properties = array(
		'path' => $headphones instanceof WP_Term ? (string) wp_parse_url( (string) get_term_link( $headphones ), PHP_URL_PATH ) : '',
	);
}

// --- Settings.

$before = get_option( 'sffilters_settings' );

if ( $demo ) {
	$result = \Starfiniti\Filters\Settings::save( array( 'filters' => array( array( 'source' => \Starfiniti\Filters\Properties::taxonomy( 'tone' ) ) ) ) );
	$check( 'settings: the taxonomy behind a property is not a source of its own', is_wp_error( $result ) && 'sffilters_unknown_source' === $result->get_error_code() );

	$export = \Starfiniti\Filters\Settings::export();
	$named  = array_column( $export['filters'], 'param', 'source' );
	$check( 'settings: a property is saved by its name', 'sf_tone' === ( $named['tone'] ?? '' ) && 'sf_length' === ( $named['length'] ?? '' ), $export['filters'] );
	$check( 'settings: properties are listed as sources', array( 'tone', 'length' ) === array_values( array_intersect( array_column( $export['available_sources'], 'source' ), array( 'tone', 'length' ) ) ) );
}

$result = \Starfiniti\Filters\Settings::save( array( 'filters' => array( array( 'source' => 'no_such_taxonomy' ) ) ) );
$check( 'settings: an unknown source is refused', is_wp_error( $result ) && 'sffilters_unknown_source' === $result->get_error_code() );

$result = \Starfiniti\Filters\Settings::save( array( 'filters' => array( array( 'source' => 'product_visibility' ) ) ) );
$check( 'settings: an internal taxonomy is refused', is_wp_error( $result ) );

$result = \Starfiniti\Filters\Settings::save( array( 'filters' => array( array( 'source' => 'price' ), array( 'source' => 'price' ) ) ) );
$check( 'settings: the same source twice is refused', is_wp_error( $result ) && 'sffilters_duplicate_filter' === $result->get_error_code() );

$result = \Starfiniti\Filters\Settings::save( array( 'pagination' => 'sideways' ) );
$check( 'settings: an unknown pagination mode is refused', is_wp_error( $result ) && 'sffilters_invalid_pagination' === $result->get_error_code() );

$check( 'settings: a refused save changes nothing', get_option( 'sffilters_settings' ) === $before );

\Starfiniti\Filters\Settings::save(
	array(
		'pagination' => 'infinite',
		'filters'    => array(
			array(
				'source'  => 'product_cat',
				'display' => 'links',
				'label'   => '<b>Shop by type</b>',
			),
			array(
				'source'  => 'price',
				'display' => 'links',
			),
		),
	)
);

$export = \Starfiniti\Filters\Settings::export();
$check( 'settings: pagination is saved', 'infinite' === $export['pagination'], $export['pagination'] );
$check( 'settings: only a taxonomy can be shown as links', 'links' === ( $export['filters'][0]['display'] ?? '' ) && '' === ( $export['filters'][1]['display'] ?? 'x' ), $export['filters'] );
$check( 'settings: a title is stored as plain text', 'Shop by type' === ( $export['filters'][0]['label'] ?? '' ), $export['filters'] );

$links = \Starfiniti\Filters\Settings::filters();
$first = reset( $links );
$check( 'settings: a filter shown as links reads nothing from the URL', false !== $first && array() === $first->parse( 'audio' ) );

\Starfiniti\Filters\Settings::save(
	array(
		'filters' => array(
			array(
				'source'  => 'product_cat',
				'display' => 'level',
			),
			array(
				'source'  => 'product_tag',
				'display' => 'sideways',
			),
		),
	)
);

$export = \Starfiniti\Filters\Settings::export();
$check( 'settings: a taxonomy can be shown one level at a time', 'level' === ( $export['filters'][0]['display'] ?? '' ) && 'checkboxes' === ( $export['filters'][1]['display'] ?? '' ), $export['filters'] );

\Starfiniti\Filters\Settings::save( array( 'filters' => array() ) );
$check( 'settings: an empty saved list means no filters', array() === \Starfiniti\Filters\Settings::filters() );

\Starfiniti\Filters\Settings::reset();
$check( 'settings: after a reset the default filters are back', count( \Starfiniti\Filters\Settings::default_definitions() ) === count( \Starfiniti\Filters\Settings::definitions() ) );

if ( null === $saved_settings ) {
	delete_option( 'sffilters_settings' );
} else {
	update_option( 'sffilters_settings', $saved_settings );
}

// The demo store keeps the two property filters, so tests/run.mjs finds them on the shop pages.
if ( $demo ) {
	\Starfiniti\Filters\Settings::save( array( 'filters' => array_merge( \Starfiniti\Filters\Settings::definitions(), array( array( 'source' => 'tone' ), array( 'source' => 'length' ) ) ) ) );
}

// --- Updates. GitHub is played by the list below, nothing leaves the machine.

$file     = plugin_basename( SFFILTERS_PLUGIN_FILE );
$headers  = get_plugin_data( SFFILTERS_PLUGIN_FILE, false, false );
$uri      = \Starfiniti\Filters\Updater::UPDATE_URI;
$api      = 'https://api.github.com/repos/dejankletecki/starfiniti-wp-filters/releases/latest';
$zip_name = 'starfiniti-filters-9.9.9.zip';
$zip_url  = $uri . '/releases/download/v9.9.9/' . $zip_name;
$zip_body = 'not really a zip, only bytes to checksum';
$zip_hash = hash( 'sha256', $zip_body );
$github   = array();

$play_github = static function ( $pre, $args, $url ) use ( &$github ) {
	if ( ! is_string( $url ) || false === strpos( $url, 'github' ) ) {
		return $pre;
	}

	if ( ! isset( $github[ $url ] ) ) {
		return new WP_Error( 'sffilters_test_offline', 'Not played in this check: ' . $url );
	}

	if ( ! empty( $args['stream'] ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A temporary file of a test.
		file_put_contents( $args['filename'], $github[ $url ] );
	}

	return array(
		'headers'  => array(),
		'body'     => empty( $args['stream'] ) ? $github[ $url ] : '',
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
		'filename' => $args['filename'] ?? null,
	);
};

add_filter( 'pre_http_request', $play_github, 10, 3 );

$release = static function ( array $change = array(), array $asset = array() ) use ( $zip_name, $zip_url, $zip_hash ): string {
	return (string) wp_json_encode(
		array_merge(
			array(
				'tag_name'   => 'v9.9.9',
				'draft'      => false,
				'prerelease' => false,
				'body'       => "- Added one thing.\n- Fixed another.",
				'assets'     => array(
					array_merge(
						array(
							'name'                 => $zip_name,
							'size'                 => 1234,
							'browser_download_url' => $zip_url,
							'digest'               => 'sha256:' . $zip_hash,
						),
						$asset
					),
				),
			),
			$change
		)
	);
};

$ask = static function ( string $body ) use ( &$github, $api, $headers, $file ) {
	delete_site_transient( \Starfiniti\Filters\Updater::CACHE_KEY );

	$github = array( $api => $body );

	return apply_filters( 'update_plugins_github.com', false, $headers, $file, array() );
};

$check( 'updates: the plugin header names the update address', $uri === ( $headers['UpdateURI'] ?? '' ) && SFFILTERS_VERSION === ( $headers['Version'] ?? '' ), $headers );

$update = $ask( $release() );
$check( 'updates: a newer release is offered', is_array( $update ) && '9.9.9' === $update['version'] && $zip_url === $update['package'] && $file === $update['plugin'], $update );
$check( 'updates: the offer repeats the requirements of the plugin header', is_array( $update ) && ( $headers['RequiresWP'] ?? '' ) === $update['requires'] && ( $headers['RequiresPHP'] ?? '' ) === $update['requires_php'], $update );

$check( 'updates: the installed version is not offered again', false === $ask( $release( array( 'tag_name' => 'v' . SFFILTERS_VERSION ), array( 'name' => 'starfiniti-filters-' . SFFILTERS_VERSION . '.zip', 'browser_download_url' => $uri . '/releases/download/v' . SFFILTERS_VERSION . '/starfiniti-filters-' . SFFILTERS_VERSION . '.zip' ) ) ) );
$check( 'updates: a pre-release is not offered', false === $ask( $release( array( 'prerelease' => true ) ) ) );
$check( 'updates: a draft is not offered', false === $ask( $release( array( 'draft' => true ) ) ) );
$check( 'updates: a tag that is not a version is not offered', false === $ask( $release( array( 'tag_name' => 'nightly' ) ) ) );
$check( 'updates: a package from another repository is not offered', false === $ask( $release( array(), array( 'browser_download_url' => 'https://github.com/someone-else/starfiniti-wp-filters/releases/download/v9.9.9/' . $zip_name ) ) ) );
$check( 'updates: a package from the retired address is not offered', false === $ask( $release( array(), array( 'browser_download_url' => 'https://github.com/Starfiniti/starfiniti-filters/releases/download/v9.9.9/' . $zip_name ) ) ) );

$moved  = 'https://github.com/Starfiniti/starfiniti-wp-filters/releases/download/v9.9.9/' . $zip_name;
$update = $ask( $release( array(), array( 'browser_download_url' => $moved ) ) );
$check( 'updates: a release is still offered once the repository has moved to the Starfiniti organization', is_array( $update ) && $moved === $update['package'], $update );

$check( 'updates: a release without a checksum is not offered', false === $ask( $release( array(), array( 'digest' => null ) ) ) );
$check( 'updates: an answer that is not a release is not offered', false === $ask( '{"message":"API rate limit exceeded"}' ) );

$other = array( 'version' => '1.2.3' );
$check( 'updates: other plugins are left alone', $other === apply_filters( 'update_plugins_github.com', $other, $headers, 'another-plugin/another-plugin.php', array() ) );

$ask( $release() );
$details = apply_filters( 'plugins_api', false, 'plugin_information', (object) array( 'slug' => dirname( $file ) ) );
$check( 'updates: the details window shows the version and the release notes', is_object( $details ) && '9.9.9' === $details->version && false !== strpos( $details->sections['changelog'], '<li>Added one thing.</li>' ), $details );

$download = static function ( string $package ) {
	$reply = apply_filters( 'upgrader_pre_download', false, $package, null, array() );

	if ( is_string( $reply ) && is_file( $reply ) ) {
		wp_delete_file( $reply );

		return 'installed';
	}

	return is_wp_error( $reply ) ? $reply->get_error_code() : $reply;
};

$ask( $release() );
$github[ $zip_url ] = $zip_body;
$check( 'updates: a package that matches its checksum is accepted', 'installed' === $download( $zip_url ), $download( $zip_url ) );

$github[ $zip_url ] = $zip_body . ' tampered';
$check( 'updates: a package that does not match its checksum is refused', 'sffilters_update_mismatch' === $download( $zip_url ), $download( $zip_url ) );

$sum_url = $zip_url . '.sha256';
$ask(
	(string) wp_json_encode(
		array(
			'tag_name' => 'v9.9.9',
			'assets'   => array(
				array(
					'name'                 => $zip_name,
					'size'                 => 1234,
					'browser_download_url' => $zip_url,
				),
				array(
					'name'                 => $zip_name . '.sha256',
					'size'                 => 90,
					'browser_download_url' => $sum_url,
				),
			),
		)
	)
);
$github[ $zip_url ] = $zip_body;
$github[ $sum_url ] = $zip_hash . '  ' . $zip_name . "\n";
$check( 'updates: the checksum file is used when the release has no digest', 'installed' === $download( $zip_url ), $download( $zip_url ) );

unset( $github[ $sum_url ] );
$check( 'updates: without a readable checksum nothing is installed', 'sffilters_update_unverified' === $download( $zip_url ), $download( $zip_url ) );

$ask( '{"message":"Not Found"}' );
$github[ $zip_url ] = $zip_body;
$check( 'updates: without release data nothing is installed', 'sffilters_update_unverified' === $download( $zip_url ), $download( $zip_url ) );

$check( 'updates: packages of other plugins are left to WordPress', false === $download( 'https://downloads.wordpress.org/plugin/woocommerce.zip' ) );

$ask( $release() );
// Called directly: other plugins hooked to `upgrader_process_complete` expect a real upgrader.
( new \Starfiniti\Filters\Updater() )->forget(
	null,
	array(
		'type'    => 'plugin',
		'action'  => 'update',
		'plugins' => array( $file ),
	)
);
$check( 'updates: the remembered release is dropped after an update', false === get_site_transient( \Starfiniti\Filters\Updater::CACHE_KEY ) );

remove_filter( 'pre_http_request', $play_github, 10 );
delete_site_transient( \Starfiniti\Filters\Updater::CACHE_KEY );

// --- Report.

$failed = array_values( array_filter( $sffilters_results, static fn( array $result ): bool => ! $result['pass'] ) );

foreach ( $sffilters_results as $result ) {
	echo ( $result['pass'] ? 'ok      ' : 'FAILED  ' ) . $result['name'] . ( '' !== $result['detail'] ? "\n        " . $result['detail'] : '' ) . "\n";
}

echo "\n" . ( count( $sffilters_results ) - count( $failed ) ) . ' of ' . count( $sffilters_results ) . " checks passed.\n";

$report = getenv( 'SFFILTERS_TEST_RESULTS' );
$report = is_string( $report ) && '' !== $report ? $report : SFFILTERS_PLUGIN_DIR . '.cache/test-results.json';

if ( wp_mkdir_p( dirname( $report ) ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A report of a test run.
	file_put_contents(
		$report,
		(string) wp_json_encode(
			array(
				'checks'     => $sffilters_results,
				'http'       => $http,
				'loop'       => $loop,
				'properties' => $properties,
				'level'      => $level,
			)
		)
	);
}

if ( array() !== $failed && defined( 'WP_CLI' ) && WP_CLI ) {
	exit( 1 );
}
