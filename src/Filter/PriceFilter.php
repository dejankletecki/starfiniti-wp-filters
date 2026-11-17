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
 */
final class PriceFilter extends RangeFilter {

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
	 * Formats an amount as plain text in the store currency, without decimals for whole amounts.
	 */
	protected function text( float $amount ): string {
		$decimals = floor( $amount ) === $amount ? 0 : wc_get_price_decimals();
		$price    = wc_price( $amount, array( 'decimals' => $decimals ) );

		return html_entity_decode( wp_strip_all_tags( $price ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
