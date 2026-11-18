<?php
/**
 * Filter by price range.
 *
 * @package StarfinitiFilters
 */

namespace Starfiniti\Filters\Filter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads `sf_price=50-200`. Either end may be left out: `50-` or `-200`.
 *
 * The panel shows a slider with two fields, or ready-made ranges to pick one from. The ranges are worked out
 * from the prices of the listed products, so each holds about a quarter of them.
 */
final class PriceFilter extends RangeFilter {

	/**
	 * @param string $slug   Filter slug.
	 * @param string $label  Title in the panel.
	 * @param bool   $ranges Whether the panel offers ready-made ranges and no slider.
	 */
	public function __construct( string $slug, string $label, private bool $ranges = false ) {
		parent::__construct( $slug, $label );
	}

	public function clauses( array $values ): array {
		$lookup = array();

		if ( isset( $values['min'] ) ) {
			$lookup['min_price'] = (float) $values['min'];
		}

		if ( isset( $values['max'] ) ) {
			$lookup['max_price'] = (float) $values['max'];
		}

		return array( 'lookup' => $lookup );
	}

	public function facet( string $ids_sql, array $values ): array {
		global $wpdb;

		if ( $this->ranges ) {
			return $this->ranges( $ids_sql, $values );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- $ids_sql is built by WP_Query.
		$row = $wpdb->get_row(
			"SELECT MIN( min_price ) AS low, MAX( max_price ) AS high
			FROM {$wpdb->wc_product_meta_lookup}
			WHERE product_id IN ( {$ids_sql} )"
		);

		if ( ! $row || null === $row->low || null === $row->high ) {
			return array();
		}

		$low  = floor( (float) $row->low );
		$high = ceil( (float) $row->high );

		if ( $low >= $high && array() === $values ) {
			return array();
		}

		return array(
			'kind'   => 'range',
			'min'    => $low,
			'max'    => $high,
			'from'   => $values['min'] ?? null,
			'to'     => $values['max'] ?? null,
			'unit'   => get_woocommerce_currency_symbol(),
			'labels' => array( __( 'Lowest price', 'starfiniti-filters' ), __( 'Highest price', 'starfiniti-filters' ) ),
		);
	}

	/**
	 * Offers up to four ranges, cut at the prices a quarter, a half and three quarters of the way through the
	 * listed products. A product counts for every range its price touches, as the filter itself does.
	 *
	 * @param string $ids_sql SQL that selects the IDs of the listed products.
	 * @param array  $values  The chosen range, empty when none is.
	 */
	private function ranges( string $ids_sql, array $values ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- $ids_sql is built by WP_Query.
		$rows = (array) $wpdb->get_results(
			"SELECT min_price AS low, max_price AS high
			FROM {$wpdb->wc_product_meta_lookup}
			WHERE product_id IN ( {$ids_sql} ) AND min_price IS NOT NULL AND max_price IS NOT NULL
			ORDER BY min_price"
		);

		if ( array() === $rows ) {
			return array();
		}

		$lowest  = (float) $rows[0]->low;
		$highest = max( array_map( static fn( $row ): float => (float) $row->high, $rows ) );
		$cuts    = array();

		foreach ( array( 0.25, 0.5, 0.75 ) as $share ) {
			$cut = self::round_off( (float) $rows[ (int) floor( count( $rows ) * $share ) ]->low );

			if ( $cut > $lowest && $cut < $highest ) {
				$cuts[ (string) $cut ] = $cut;
			}
		}

		if ( array() === $cuts && array() === $values ) {
			return array();
		}

		sort( $cuts );

		$held    = static fn( ?float $from, ?float $to ): int => count( array_filter( $rows, static fn( $row ): bool => ( null === $from || (float) $row->high >= $from ) && ( null === $to || (float) $row->low <= $to ) ) );
		$chosen  = array() !== $values ? array( isset( $values['min'] ) ? (float) $values['min'] : null, isset( $values['max'] ) ? (float) $values['max'] : null ) : null;
		$ends    = array() !== $cuts ? array_merge( array( null ), $cuts, array( null ) ) : array();
		$options = array();
		$found   = null === $chosen;

		for ( $index = 0; $index < count( $ends ) - 1; $index++ ) {
			$range    = array( $ends[ $index ], $ends[ $index + 1 ] );
			$selected = $range === $chosen;
			$count    = $held( $range[0], $range[1] );
			$found    = $found || $selected;

			if ( $count > 0 || $selected ) {
				$options[] = $this->option( $range[0], $range[1], $count, $selected );
			}
		}

		// A range that is none of the ready-made ones: picked before the list changed, or typed into the address.
		if ( ! $found ) {
			$options[] = $this->option( $chosen[0], $chosen[1], $held( $chosen[0], $chosen[1] ), true );
		}

		return array(
			'kind'    => 'choices',
			'options' => $options,
		);
	}

	/**
	 * Describes one range for the panel.
	 */
	private function option( ?float $from, ?float $to, int $count, bool $selected ): array {
		return array(
			'value'    => $this->format(
				array(
					'min' => $from,
					'max' => $to,
				)
			),
			'label'    => $this->words( $from, $to ),
			'count'    => $count,
			'selected' => $selected,
		);
	}

	/**
	 * Rounds a price to two significant digits, so a range ends at 13 € and not at 12,90 €.
	 */
	private static function round_off( float $amount ): float {
		if ( $amount <= 0 ) {
			return 0.0;
		}

		$unit = 10 ** ( (int) floor( log10( $amount ) ) - 1 );

		return round( round( $amount / $unit ) * $unit, 2 );
	}

	/**
	 * Formats an amount as plain text in the store currency, without decimals for whole amounts.
	 */
	protected function text( float $amount ): string {
		$decimals = floor( $amount ) === $amount ? 0 : wc_get_price_decimals();
		$price    = wc_price( $amount, array( 'decimals' => $decimals ) );

		return html_entity_decode( wp_strip_all_tags( $price ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
