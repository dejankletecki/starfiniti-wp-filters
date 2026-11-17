<?php
/**
 * Filter by a number kept with the product, such as a length.
 *
 * @package StarfinitiFilters
 */

namespace Starfiniti\Filters\Filter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads `sf_length=30-120`. Either end may be left out. Products without the number are not listed while it is in use.
 */
final class NumberFilter extends RangeFilter {

	/**
	 * How the stored text is compared as a number.
	 */
	private const TYPE = 'DECIMAL(15,3)';

	/**
	 * @param string $slug     Filter slug.
	 * @param string $label    Title in the panel.
	 * @param string $meta_key Product meta that holds the number.
	 * @param string $unit     Shown after the number, for example `cm`.
	 * @param float  $share    Hide the filter when a smaller share of the listed products has the number.
	 */
	public function __construct(
		string $slug,
		string $label,
		private string $meta_key,
		private string $unit = '',
		private float $share = 0.0
	) {
		parent::__construct( $slug, $label );
	}

	public function clauses( array $values ): array {
		$clause = array(
			'key'  => $this->meta_key,
			'type' => self::TYPE,
		);

		if ( isset( $values['min'], $values['max'] ) ) {
			$clause['value']   = array( (float) $values['min'], (float) $values['max'] );
			$clause['compare'] = 'BETWEEN';
		} elseif ( isset( $values['min'] ) ) {
			$clause['value']   = (float) $values['min'];
			$clause['compare'] = '>=';
		} else {
			$clause['value']   = (float) $values['max'];
			$clause['compare'] = '<=';
		}

		return array( 'meta_query' => $clause );
	}

	public function facet( string $ids_sql, array $values ): array {
		global $wpdb;

		$cast = 'CAST( meta_value AS ' . self::TYPE . ' )';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- $ids_sql is built by WP_Query, $cast is a constant, the meta key is escaped.
		$row = $wpdb->get_row(
			"SELECT MIN( {$cast} ) AS low, MAX( {$cast} ) AS high, COUNT( DISTINCT post_id ) AS total
			FROM {$wpdb->postmeta}
			WHERE meta_key = '" . esc_sql( $this->meta_key ) . "' AND post_id IN ( {$ids_sql} )"
		);

		if ( ! $row || null === $row->low || null === $row->high ) {
			return array();
		}

		$low  = floor( (float) $row->low );
		$high = ceil( (float) $row->high );

		if ( array() === $values && ( $low >= $high || self::sparse( $ids_sql, (int) $row->total, $this->share ) ) ) {
			return array();
		}

		return array(
			'kind'   => 'range',
			'min'    => $low,
			'max'    => $high,
			'from'   => $values['min'] ?? null,
			'to'     => $values['max'] ?? null,
			'unit'   => $this->unit,
			'labels' => array(
				/* translators: %s: filter title, for example Length. */
				sprintf( __( '%s from', 'starfiniti-filters' ), $this->label ),
				/* translators: %s: filter title, for example Length. */
				sprintf( __( '%s to', 'starfiniti-filters' ), $this->label ),
			),
		);
	}

	protected function text( float $amount ): string {
		$number = number_format_i18n( $amount, floor( $amount ) === $amount ? 0 : 1 );

		return '' !== $this->unit ? $number . ' ' . $this->unit : $number;
	}

	/**
	 * Two filters may share a unit, so the selected range names its filter.
	 */
	protected function chip( string $range ): string {
		/* translators: 1: filter title, for example Length. 2: the chosen range, for example 30 cm – 120 cm. */
		return sprintf( __( '%1$s: %2$s', 'starfiniti-filters' ), $this->label, $range );
	}
}
