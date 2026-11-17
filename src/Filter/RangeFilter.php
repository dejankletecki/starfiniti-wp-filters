<?php
/**
 * Base class for a filter by a range of numbers.
 *
 * @package StarfinitiFilters
 */

namespace Starfiniti\Filters\Filter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads a value such as `50-200`. Either end may be left out: `50-` or `-200`.
 */
abstract class RangeFilter extends Filter {

	public function parse( $raw ): array {
		if ( is_array( $raw ) ) {
			$min = $raw['min'] ?? '';
			$max = $raw['max'] ?? '';
		} else {
			$parts = explode( '-', is_scalar( $raw ) ? (string) $raw : '', 2 );
			$min   = $parts[0];
			$max   = $parts[1] ?? '';
		}

		$min = self::amount( $min );
		$max = self::amount( $max );

		if ( null === $min && null === $max ) {
			return array();
		}

		if ( null !== $min && null !== $max && $min > $max ) {
			list( $min, $max ) = array( $max, $min );
		}

		return array(
			'min' => $min,
			'max' => $max,
		);
	}

	public function selected( array $values ): array {
		$min = isset( $values['min'] ) ? $this->text( (float) $values['min'] ) : null;
		$max = isset( $values['max'] ) ? $this->text( (float) $values['max'] ) : null;

		if ( null !== $min && null !== $max ) {
			/* translators: 1: lowest price, 2: highest price. */
			$label = sprintf( _x( '%1$s – %2$s', 'price range', 'starfiniti-filters' ), $min, $max );
		} elseif ( null !== $min ) {
			/* translators: %s: lowest price. */
			$label = sprintf( __( 'From %s', 'starfiniti-filters' ), $min );
		} else {
			/* translators: %s: highest price. */
			$label = sprintf( __( 'Up to %s', 'starfiniti-filters' ), $max );
		}

		return array(
			array(
				'label'  => $this->chip( $label ),
				'values' => array(),
			),
		);
	}

	public function format( array $values ): string {
		$min = isset( $values['min'] ) ? (string) (float) $values['min'] : '';
		$max = isset( $values['max'] ) ? (string) (float) $values['max'] : '';

		return $min . '-' . $max;
	}

	/**
	 * Writes one end of the range the way the shopper reads it, for example `50 €` or `120 cm`.
	 */
	abstract protected function text( float $amount ): string;

	/**
	 * Returns the text of the selected range above the products.
	 *
	 * @param string $range The range in words, for example `From 50 €`.
	 */
	protected function chip( string $range ): string {
		return $range;
	}

	/**
	 * Reads one amount. Accepts a decimal comma.
	 *
	 * @param mixed $value Raw amount.
	 */
	private static function amount( $value ): ?float {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = str_replace( ',', '.', trim( (string) $value ) );

		if ( ! is_numeric( $value ) ) {
			return null;
		}

		return max( 0.0, (float) $value );
	}
}
