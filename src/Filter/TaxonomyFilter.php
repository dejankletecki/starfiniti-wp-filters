<?php
/**
 * Filter by terms of a product taxonomy.
 *
 * @package StarfinitiFilters
 */

namespace Starfiniti\Filters\Filter;

use Starfiniti\Filters\Plugin;
use Starfiniti\Filters\Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Covers categories, tags, brands and product attributes.
 *
 * Shown as checkboxes or as swatches, the terms filter the current list. A swatch is a checkbox drawn as a
 * colour or a picture, which the store gives for each term with the `sffilters_swatch` filter.
 *
 * Shown as checkboxes, the terms filter the current list. On a category page the checkboxes offer every other
 * category the listed products are in, so a shopper on "Mugs" can narrow the list to "Gifts". Shown as links,
 * each term leads to its own page,
 * for example the category page, and nothing is read from the URL: `links` lists the whole tree, `level`
 * lists one level at a time, the way a shopper walks down the categories.
 */
final class TaxonomyFilter extends Filter {

	/**
	 * Parent of every term that has one, keyed by term ID. Read once, with one query.
	 *
	 * @var array<int, int>|null
	 */
	private ?array $parents = null;

	/**
	 * @param string $slug     Filter slug.
	 * @param string $label    Title in the panel.
	 * @param string $taxonomy Taxonomy name.
	 * @param string $display  Empty for checkboxes, `swatches`, or `links` or `level` for links to the terms' own pages.
	 * @param float  $share    Hide the filter when a smaller share of the listed products has a term.
	 * @param int    $limit    Show this many terms, the rest behind "more". Zero shows all.
	 */
	public function __construct(
		string $slug,
		string $label,
		private string $taxonomy,
		private string $display = '',
		private float $share = 0.0,
		private int $limit = 0
	) {
		parent::__construct( $slug, $label );
	}

	/**
	 * Tells whether the terms are links to their own pages, which read nothing from the URL.
	 */
	private function leads_away(): bool {
		return in_array( $this->display, array( 'links', 'level' ), true );
	}

	public function parse( $raw ): array {
		if ( $this->leads_away() ) {
			return array();
		}

		$slugs = array_filter( array_map( 'sanitize_title', self::split( $raw ) ) );

		return array_values( array_unique( $slugs ) );
	}

	public function clauses( array $values ): array {
		return array(
			'tax_query' => array(
				'taxonomy' => $this->taxonomy,
				'field'    => 'slug',
				'terms'    => $values,
				'operator' => 'IN',
			),
		);
	}

	public function facet( string $ids_sql, array $values ): array {
		if ( $this->leads_away() ) {
			return $this->links();
		}

		$hierarchical = is_taxonomy_hierarchical( $this->taxonomy );
		$root         = is_tax( $this->taxonomy ) ? (int) get_queried_object_id() : 0;

		// On the archive of a flat taxonomy, such as a brand page, there is nothing left to choose.
		if ( 0 !== $root && ! $hierarchical ) {
			return array();
		}

		if ( array() === $values && $this->share > 0 && self::sparse( $ids_sql, $this->with_term( $ids_sql ), $this->share ) ) {
			return array();
		}

		$counts = $this->counts( $ids_sql, $hierarchical );
		$nodes  = array();

		// The category being viewed and the ones above it hold every listed product, so they are not offered.
		foreach ( 0 !== $root ? $this->lineage( $root ) : array() as $above ) {
			unset( $counts[ $above ] );
		}

		foreach ( $this->terms( array_keys( $counts ), $values ) as $term ) {
			$nodes[ $term->term_id ] = array(
				'value'    => urldecode( $term->slug ),
				'label'    => $term->name,
				'count'    => $counts[ $term->term_id ] ?? 0,
				'selected' => in_array( $term->slug, $values, true ),
				'parent'   => (int) $term->parent,
			);

			if ( 'swatches' === $this->display ) {
				/**
				 * Filters the colour and the picture of one swatch. A swatch with neither is drawn empty.
				 *
				 * @param array    $swatch `color` as a hex colour such as `#b3261e`, `image` as the address of a small picture.
				 * @param \WP_Term $term   The term the swatch stands for.
				 * @param string   $slug   Slug of the filter.
				 */
				$nodes[ $term->term_id ]['swatch'] = (array) apply_filters(
					'sffilters_swatch',
					array(
						'color' => '',
						'image' => '',
					),
					$term,
					$this->slug
				);
			}
		}

		if ( array() === $nodes ) {
			return array();
		}

		// Swatches sit side by side, so they are one flat list, whatever the taxonomy.
		if ( 'swatches' === $this->display ) {
			$flat = array();

			foreach ( $nodes as $node ) {
				unset( $node['parent'] );

				$node['children'] = array();
				$flat[]           = $node;
			}

			return array(
				'kind'    => 'swatches',
				'options' => $flat,
				'limit'   => $this->limit,
			);
		}

		return array(
			'kind'    => 'options',
			'options' => self::tree( $nodes, 0 ),
			'limit'   => $this->limit,
		);
	}

	/**
	 * Lists every term with products as a link to its own page.
	 *
	 * The counts cover the whole catalog, because the link leaves the current list.
	 */
	private function links(): array {
		$base = Plugin::instance()->base_query_vars();

		if ( null === $base ) {
			return array();
		}

		$object  = get_taxonomy( $this->taxonomy );
		$current = is_tax( $this->taxonomy ) ? (int) get_queried_object_id() : 0;

		// On a term page, count without that term, so every link shows what its own page lists.
		unset( $base[ $object && $object->query_var ? $object->query_var : $this->taxonomy ], $base['taxonomy'], $base['term'], $base['term_id'] );

		$counts = $this->counts( Query::ids_sql( $base ), is_taxonomy_hierarchical( $this->taxonomy ) );
		$nodes  = array();
		$listed = array_keys( $counts );

		// One level at a time needs the terms of that level only, however large the tree is.
		if ( 'level' === $this->display ) {
			$listed = $this->on_level( $listed, $current );
		}

		foreach ( $this->terms( $listed, array() ) as $term ) {
			$url = get_term_link( $term );

			if ( is_wp_error( $url ) ) {
				continue;
			}

			$nodes[ $term->term_id ] = array(
				'label'   => $term->name,
				'count'   => $counts[ $term->term_id ] ?? 0,
				'url'     => $url,
				'current' => $term->term_id === $current,
				'parent'  => (int) $term->parent,
			);
		}

		// On a term page, the way back to the whole shop.
		$back = 0 !== $current
			? array(
				'label' => __( 'All products', 'starfiniti-filters' ),
				'url'   => (string) wc_get_page_permalink( 'shop' ),
			)
			: null;

		if ( 'level' === $this->display ) {
			return $this->level( $nodes, $current, $back );
		}

		if ( array() === $nodes ) {
			return array();
		}

		return array(
			'kind'    => 'links',
			'options' => self::tree( $nodes, 0 ),
			'back'    => $back,
		);
	}

	/**
	 * Keeps one level of the links: the children of the term being viewed, or the terms beside it when it has
	 * none. A page that is no term page gets the top level. The way back leads one level up.
	 *
	 * @param array<int, array> $nodes   Links keyed by term ID.
	 * @param int               $current Term being viewed, or 0.
	 * @param array|null        $back    The way back to the whole shop.
	 */
	private function level( array $nodes, int $current, ?array $back ): array {
		$term   = 0 !== $current ? get_term( $current, $this->taxonomy ) : null;
		$parent = $term instanceof \WP_Term ? (int) $term->parent : 0;
		$level  = static fn( int $of ): array => array_filter( $nodes, static fn( array $node ): bool => $node['parent'] === $of );
		$shown  = 0 !== $current ? $level( $current ) : array();

		if ( array() === $shown ) {
			$shown = $level( $parent );
		}

		if ( array() === $shown ) {
			return array();
		}

		if ( 0 !== $parent ) {
			$above = get_term( $parent, $this->taxonomy );
			$url   = $above instanceof \WP_Term ? get_term_link( $above ) : null;

			if ( is_string( $url ) ) {
				$back = array(
					/* translators: %s: name of the category one level up. */
					'label' => sprintf( __( 'Back to %s', 'starfiniti-filters' ), $above->name ),
					'url'   => $url,
				);
			}
		}

		$options = array();

		foreach ( $shown as $node ) {
			unset( $node['parent'] );

			$node['children'] = array();
			$options[]        = $node;
		}

		return array(
			'kind'    => 'links',
			'options' => $options,
			'back'    => $back,
		);
	}

	public function selected( array $values ): array {
		$names = array();
		$terms = get_terms(
			array(
				'taxonomy'   => $this->taxonomy,
				'slug'       => $values,
				'hide_empty' => false,
			)
		);

		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$names[ $term->slug ] = $term->name;
		}

		$chips = array();

		foreach ( $values as $value ) {
			$chips[] = array(
				'label'  => $names[ $value ] ?? urldecode( $value ),
				'values' => array_values( array_diff( $values, array( $value ) ) ),
			);
		}

		return $chips;
	}

	public function format( array $values ): string {
		return implode( ',', array_map( 'urldecode', $values ) );
	}

	/**
	 * Loads the terms that have products, plus selected terms that have none left.
	 *
	 * @param int[]    $term_ids Terms with at least one product.
	 * @param string[] $selected Selected term slugs.
	 * @return \WP_Term[]
	 */
	private function terms( array $term_ids, array $selected ): array {
		$terms = array();

		if ( array() !== $term_ids ) {
			$found = get_terms(
				array(
					'taxonomy'   => $this->taxonomy,
					'hide_empty' => false,
					'include'    => $term_ids,
				)
			);

			if ( ! is_wp_error( $found ) ) {
				foreach ( $found as $term ) {
					$terms[ $term->term_id ] = $term;
				}
			}
		}

		foreach ( $selected as $slug ) {
			$term = get_term_by( 'slug', $slug, $this->taxonomy );

			if ( $term instanceof \WP_Term && ! isset( $terms[ $term->term_id ] ) ) {
				$terms[ $term->term_id ] = $term;
			}
		}

		return $terms;
	}

	/**
	 * Counts products per term.
	 *
	 * In a hierarchical taxonomy a product in a child term also counts for every
	 * ancestor, once. That needs the product IDs, so those rows are not grouped in SQL.
	 * The ancestors come from the parents read with one query, so a shop with thousands
	 * of categories does not look each one up.
	 *
	 * @return array<int, int>
	 */
	private function counts( string $ids_sql, bool $hierarchical ): array {
		global $wpdb;

		$select = $hierarchical ? 'tr.object_id AS object_id' : 'COUNT( DISTINCT tr.object_id ) AS total';
		$group  = $hierarchical ? '' : 'GROUP BY tt.term_id';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- $ids_sql is built by WP_Query, the taxonomy is escaped.
		$rows = $wpdb->get_results(
			"SELECT tt.term_id AS term_id, {$select}
			FROM {$wpdb->term_relationships} AS tr
			INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			WHERE tt.taxonomy = '" . esc_sql( $this->taxonomy ) . "' AND tr.object_id IN ( {$ids_sql} )
			{$group}"
		);

		if ( ! $hierarchical ) {
			return array_map( 'intval', wp_list_pluck( (array) $rows, 'total', 'term_id' ) );
		}

		$lineage  = array();
		$products = array();

		foreach ( (array) $rows as $row ) {
			$term_id = (int) $row->term_id;

			if ( ! isset( $lineage[ $term_id ] ) ) {
				$lineage[ $term_id ] = $this->lineage( $term_id );
			}

			foreach ( $lineage[ $term_id ] as $id ) {
				$products[ $id ][ (int) $row->object_id ] = true;
			}
		}

		return array_map( 'count', $products );
	}

	/**
	 * Counts the listed products that have at least one term of this taxonomy.
	 */
	private function with_term( string $ids_sql ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- $ids_sql is built by WP_Query, the taxonomy is escaped.
		return (int) $wpdb->get_var(
			"SELECT COUNT( DISTINCT tr.object_id )
			FROM {$wpdb->term_relationships} AS tr
			INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			WHERE tt.taxonomy = '" . esc_sql( $this->taxonomy ) . "' AND tr.object_id IN ( {$ids_sql} )"
		);
	}

	/**
	 * Returns the parent of every term of this taxonomy that has one.
	 *
	 * @return array<int, int>
	 */
	private function parents(): array {
		global $wpdb;

		if ( null === $this->parents ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One read per page for the whole tree. The term cache would need one lookup per term.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT term_id, parent FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND parent > 0", $this->taxonomy ) );

			$this->parents = array_map( 'intval', wp_list_pluck( (array) $rows, 'parent', 'term_id' ) );
		}

		return $this->parents;
	}

	/**
	 * Returns a term and the terms above it, nearest first.
	 *
	 * @return int[]
	 */
	private function lineage( int $term_id ): array {
		$parents = $this->parents();
		$line    = array( $term_id );

		while ( isset( $parents[ $term_id ] ) && ! in_array( $parents[ $term_id ], $line, true ) ) {
			$term_id = $parents[ $term_id ];
			$line[]  = $term_id;
		}

		return $line;
	}

	/**
	 * Keeps the terms of the level that `level` shows: the children of the term being viewed, or the terms
	 * beside it when it has none.
	 *
	 * @param int[] $term_ids Terms with products.
	 * @param int   $current  Term being viewed, or 0.
	 * @return int[]
	 */
	private function on_level( array $term_ids, int $current ): array {
		$parents = $this->parents();
		$under   = static fn( int $of ): array => array_values( array_filter( $term_ids, static fn( int $id ): bool => ( $parents[ $id ] ?? 0 ) === $of ) );
		$shown   = 0 !== $current ? $under( $current ) : array();

		return array() !== $shown ? $shown : $under( $parents[ $current ] ?? 0 );
	}

	/**
	 * Nests options under their parents. An option whose parent is not listed becomes a top level option.
	 *
	 * @param array<int, array> $nodes  Options keyed by term ID.
	 * @param int               $parent Term ID to list the children of.
	 */
	private static function tree( array $nodes, int $parent ): array {
		$under = array();

		foreach ( $nodes as $term_id => $node ) {
			$under[ $node['parent'] !== $term_id && isset( $nodes[ $node['parent'] ] ) ? $node['parent'] : 0 ][] = $term_id;
		}

		return self::nest( $nodes, $under, $parent );
	}

	/**
	 * Lists the options under one parent, each with its own children.
	 *
	 * @param array<int, array> $nodes  Options keyed by term ID.
	 * @param array<int, int[]> $under  Term IDs keyed by the parent they are listed under.
	 * @param int               $parent Term ID to list the children of.
	 */
	private static function nest( array $nodes, array $under, int $parent ): array {
		$options = array();

		foreach ( $under[ $parent ] ?? array() as $term_id ) {
			$node = $nodes[ $term_id ];

			unset( $node['parent'] );

			$node['children'] = self::nest( $nodes, $under, $term_id );
			$options[]        = $node;
		}

		return $options;
	}
}
