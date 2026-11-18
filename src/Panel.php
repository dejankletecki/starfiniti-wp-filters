<?php
/**
 * The filter panel.
 *
 * @package StarfinitiFilters
 */

namespace Starfiniti\Filters;

use Starfiniti\Filters\Filter\Filter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the panel as a plain GET form, so it works without JavaScript.
 *
 * Place it with the `[sffilters]` shortcode. On small screens it opens as a drawer from a button above the products.
 *
 * A product list drawn without the WooCommerce loop hooks, such as a page builder loop, gets its bar
 * and its sorting from `[sffilters_bar]` and `[sffilters_ordering]`.
 */
final class Panel {

	/**
	 * @var array<string, string>
	 */
	private array $ids_sql = array();

	/**
	 * Number of panels rendered on this page. Each panel gets its number, so the script can match them up.
	 */
	private int $count = 0;

	private ?array $config = null;

	public function register(): void {
		add_shortcode( 'sffilters', array( $this, 'render' ) );
		add_shortcode( 'sffilters_bar', array( $this, 'bar_shortcode' ) );
		add_shortcode( 'sffilters_ordering', array( $this, 'ordering_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_head', array( $this, 'head' ), 1 );
	}

	/**
	 * Marks the page before it is drawn, so the panel on small screens does not flash before the script hides it.
	 */
	public function head(): void {
		if ( null === Plugin::instance()->base_query_vars() ) {
			return;
		}

		$config = $this->config();

		wp_print_inline_script_tag(
			'if(window.HTMLDialogElement){(function(c){c.add("sffilters-js");if(window.matchMedia(' . wp_json_encode( (string) $config['mobile'] ) . ').matches){c.add("sffilters-mobile");}})(document.documentElement.classList);}'
		);
	}

	public function assets(): void {
		wp_register_style( 'sffilters', SFFILTERS_PLUGIN_URL . 'assets/filters.css', array(), SFFILTERS_VERSION );
		wp_register_script(
			'sffilters',
			SFFILTERS_PLUGIN_URL . 'assets/filters.js',
			array(),
			SFFILTERS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_add_inline_script( 'sffilters', 'window.sffiltersConfig = ' . wp_json_encode( $this->config() ) . ';', 'before' );

		// The panel usually sits in a sidebar or template, so load both in time when a product list is on the page.
		if ( null !== Plugin::instance()->base_query_vars() ) {
			wp_enqueue_style( 'sffilters' );
			wp_enqueue_script( 'sffilters' );
		}
	}

	/**
	 * Returns the settings of the script: where the product list is and when the panel becomes a drawer.
	 */
	private function config(): array {
		if ( null !== $this->config ) {
			return $this->config;
		}

		/**
		 * Filters the selectors the script uses to find the product list and its pagination links,
		 * and the media query (`mobile`) below which the panel opens as a drawer.
		 * The defaults cover the WooCommerce loop, the Breakdance post loop and a Bricks query loop.
		 *
		 * @param array $config Script settings.
		 */
		$config = (array) apply_filters(
			'sffilters_script_config',
			array(
				'results'    => '.sffilters-results',
				'products'   => 'ul.products, .bde-loop',
				'pagination' => '.woocommerce-pagination a, .bde-posts-pagination a, .bricks-pagination a',
				'next'       => '.woocommerce-pagination a.next, .bde-posts-pagination > .bde-posts-prev-next-link:last-child a, .bricks-pagination a.next',
				'ordering'   => '.woocommerce-ordering',
				'mobile'     => '(max-width: 768px)',
				'mode'       => Settings::pagination(),
				'strings'    => array(
					'title' => __( 'Filters', 'starfiniti-filters' ),
					'close' => __( 'Close filters', 'starfiniti-filters' ),
					'more'  => __( 'Load more', 'starfiniti-filters' ),
				),
			)
		);

		if ( empty( $config['mobile'] ) || ! is_string( $config['mobile'] ) ) {
			$config['mobile'] = '(max-width: 768px)';
		}

		$this->config = $config;

		return $config;
	}

	/**
	 * Returns the bar above the products: the button that opens the panel on small screens
	 * and one removable chip per selected value.
	 */
	public function bar(): string {
		$plugin  = Plugin::instance();
		$filters = $plugin->filters();
		$state   = $plugin->state();
		$base    = $this->base_url( $filters );
		$args    = array();
		$chips   = '';
		$count   = 0;

		foreach ( $state->all() as $slug => $values ) {
			$args[ $filters[ $slug ]->param() ] = self::encode( $filters[ $slug ]->format( $values ) );
		}

		foreach ( $state->all() as $slug => $values ) {
			$filter = $filters[ $slug ];

			foreach ( $filter->selected( $values ) as $chip ) {
				$rest = $args;

				if ( array() === $chip['values'] ) {
					unset( $rest[ $filter->param() ] );
				} else {
					$rest[ $filter->param() ] = self::encode( $filter->format( $chip['values'] ) );
				}

				$chips .= sprintf(
					'<li><a class="sffilters-chip" href="%1$s" rel="nofollow" aria-label="%2$s" data-sffilters-remove>%3$s <span aria-hidden="true">&times;</span></a></li>',
					esc_url( add_query_arg( $rest, $base ) ),
					/* translators: %s: selected filter value, for example a brand name. */
					esc_attr( sprintf( __( 'Remove filter: %s', 'starfiniti-filters' ), $chip['label'] ) ),
					esc_html( $chip['label'] )
				);

				++$count;
			}
		}

		if ( '' !== $chips ) {
			$chips = sprintf(
				'<ul class="sffilters-chips" aria-label="%1$s">%2$s<li><a class="sffilters-clear" href="%3$s" rel="nofollow" data-sffilters-clear>%4$s</a></li></ul>',
				esc_attr__( 'Selected filters', 'starfiniti-filters' ),
				$chips,
				esc_url( $base ),
				esc_html__( 'Clear all', 'starfiniti-filters' )
			);
		}

		$total = (int) wc_get_loop_prop( 'total', 0 );

		return sprintf(
			'<div class="sffilters-bar%1$s" data-sffilters-base="%6$s"><button type="button" class="sffilters-toggle button" aria-haspopup="dialog" data-sffilters-toggle data-sffilters-show="%2$s">%3$s%4$s</button>%5$s</div>',
			0 < $count ? ' sffilters-bar--active' : '',
			/* translators: %s: number of products. */
			esc_attr( sprintf( _n( 'Show %s product', 'Show %s products', $total, 'starfiniti-filters' ), number_format_i18n( $total ) ) ),
			esc_html__( 'Filters', 'starfiniti-filters' ),
			0 < $count ? ' <span class="sffilters-toggle__count">' . esc_html( number_format_i18n( $count ) ) . '</span>' : '',
			$chips,
			esc_url( $this->first_page() )
		);
	}

	/**
	 * Returns page one of this product list without its query string. The script sorts from here.
	 */
	public function first_page(): string {
		return (string) strtok( get_pagenum_link( 1, false ), '?' );
	}

	/**
	 * `[sffilters_bar]`: the bar for a product list that is drawn without the WooCommerce loop hooks.
	 */
	public function bar_shortcode(): string {
		if ( null === Plugin::instance()->base_query_vars() ) {
			return '';
		}

		wp_enqueue_style( 'sffilters' );
		wp_enqueue_script( 'sffilters' );

		return $this->bar();
	}

	/**
	 * `[sffilters_ordering]`: the WooCommerce sorting dropdown for such a product list.
	 */
	public function ordering_shortcode(): string {
		if ( null === Plugin::instance()->base_query_vars() || ! function_exists( 'woocommerce_catalog_ordering' ) ) {
			return '';
		}

		ob_start();
		woocommerce_catalog_ordering();

		return (string) ob_get_clean();
	}

	/**
	 * Encodes a filter value for the address and keeps commas readable.
	 */
	private static function encode( string $value ): string {
		return str_replace( '%2C', ',', rawurlencode( $value ) );
	}

	/**
	 * Returns the panel, or an empty string when the page has no product list to filter.
	 */
	public function render(): string {
		$plugin = Plugin::instance();
		$base   = $plugin->base_query_vars();

		if ( null === $base ) {
			return '';
		}

		$filters = $plugin->filters();
		$state   = $plugin->state();
		$groups  = '';

		foreach ( $filters as $slug => $filter ) {
			$scope = $state->without( $slug );
			$key   = md5( (string) wp_json_encode( $scope->all() ) );

			// Filters that are not in use all choose from the same products, so the SQL is built once.
			if ( ! isset( $this->ids_sql[ $key ] ) ) {
				$this->ids_sql[ $key ] = Query::ids_sql( Query::apply( $base, $filters, $scope ) );
			}

			if ( '' !== $this->ids_sql[ $key ] ) {
				/**
				 * Filters what one filter offers in the panel, for example to leave a value out or to sort the values.
				 * An empty array hides the filter.
				 *
				 * @param array  $facet  What the filter offers: `kind` and, by kind, `options`, `back`, `min`, `max` or `count`.
				 * @param string $slug   Slug of the filter.
				 * @param Filter $filter The filter.
				 */
				$facet = (array) apply_filters( 'sffilters_facet', $filter->facet( $this->ids_sql[ $key ], $state->values( $slug ) ), $slug, $filter );

				$groups .= $this->group( $filter, $facet );
			}
		}

		if ( '' === $groups ) {
			return '';
		}

		wp_enqueue_style( 'sffilters' );
		wp_enqueue_script( 'sffilters' );

		$url    = $this->base_url( $filters );
		$parts  = explode( '?', $url, 2 );
		$hidden = '';

		if ( isset( $parts[1] ) ) {
			wp_parse_str( $parts[1], $kept );
			$hidden = wc_query_string_form_fields( $kept, array(), '', true );
		}

		$clear = $state->is_active()
			? sprintf( '<a class="sffilters-clear" href="%s" rel="nofollow" data-sffilters-clear>%s</a>', esc_url( $url ), esc_html__( 'Clear all', 'starfiniti-filters' ) )
			: '';

		return sprintf(
			'<form class="sffilters-panel" method="get" action="%1$s" aria-label="%2$s" data-sffilters-panel="%7$d">%3$s%4$s<noscript><button type="submit" class="sffilters-apply button">%5$s</button></noscript>%6$s</form>',
			esc_url( $parts[0] ),
			esc_attr__( 'Product filters', 'starfiniti-filters' ),
			$hidden, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wc_query_string_form_fields().
			$groups, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where it is built.
			esc_html__( 'Apply filters', 'starfiniti-filters' ),
			$clear, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			++$this->count
		);
	}

	/**
	 * Returns the address of this product list on page one with no filters.
	 *
	 * @param array<string, Filter> $filters Filters keyed by slug.
	 */
	private function base_url( array $filters ): string {
		$params = array();

		foreach ( $filters as $filter ) {
			$params[] = $filter->param();
		}

		return remove_query_arg( $params, get_pagenum_link( 1, false ) );
	}

	/**
	 * Renders one filter. An empty facet renders nothing.
	 */
	private function group( Filter $filter, array $facet ): string {
		$param = $filter->param();
		$kind  = $facet['kind'] ?? '';

		if ( 'toggle' === $kind ) {
			return sprintf(
				'<div class="sffilters-filter sffilters-filter--toggle" data-sffilters-param="%s"><div class="sffilters-option">%s</div></div>',
				esc_attr( $param ),
				$this->checkbox( $param, '1', $filter->label(), (int) $facet['count'], (bool) $facet['selected'] )
			);
		}

		if ( 'options' === $kind ) {
			$body = $this->options( $param . '[]', $facet['options'] );
		} elseif ( 'links' === $kind ) {
			$body = $this->links( $facet['options'] );

			if ( ! empty( $facet['back'] ) ) {
				$body = sprintf( '<p class="sffilters-filter__all"><a href="%s">%s</a></p>', esc_url( $facet['back']['url'] ), esc_html( $facet['back']['label'] ) ) . $body;
			}
		} elseif ( 'range' === $kind ) {
			$body = $this->range( $param, $facet );
		} else {
			return '';
		}

		return sprintf(
			'<fieldset class="sffilters-filter sffilters-filter--%1$s" data-sffilters-param="%2$s"%3$s><legend class="sffilters-filter__title">%4$s</legend>%5$s</fieldset>',
			esc_attr( $kind ),
			esc_attr( $param ),
			'range' === $kind ? ' data-sffilters-range' : '',
			esc_html( $filter->label() ),
			$body
		);
	}

	/**
	 * Renders a list of checkboxes, nested for child options.
	 */
	private function options( string $name, array $options ): string {
		$items = '';
		$tree  = false;

		foreach ( $options as $option ) {
			$under = '';

			if ( ! empty( $option['children'] ) ) {
				$tree  = true;
				$under = $this->branch(
					(string) $option['value'],
					(string) $option['label'],
					self::holds( $option['children'], 'selected' ),
					$this->options( $name, $option['children'] )
				);
			}

			$items .= sprintf(
				'<li class="sffilters-option">%s%s</li>',
				$this->checkbox( $name, (string) $option['value'], (string) $option['label'], (int) $option['count'], (bool) $option['selected'] ),
				$under
			);
		}

		return '<ul class="sffilters-options' . ( $tree ? ' sffilters-options--tree' : '' ) . '">' . $items . '</ul>';
	}

	/**
	 * Renders a list of links, nested for child options. The link of the page being viewed is marked.
	 */
	private function links( array $options ): string {
		$items = '';
		$tree  = false;

		foreach ( $options as $option ) {
			$under = '';

			if ( ! empty( $option['children'] ) ) {
				$tree  = true;
				$under = $this->branch(
					(string) $option['url'],
					(string) $option['label'],
					$option['current'] || self::holds( $option['children'], 'current' ),
					$this->links( $option['children'] )
				);
			}

			$items .= sprintf(
				'<li class="sffilters-option"><a class="sffilters-option__control sffilters-option__link" href="%1$s"%2$s><span class="sffilters-option__label">%3$s</span> <span class="sffilters-option__count">%4$s</span></a>%5$s</li>',
				esc_url( (string) $option['url'] ),
				$option['current'] ? ' aria-current="page"' : '',
				esc_html( (string) $option['label'] ),
				esc_html( number_format_i18n( (int) $option['count'] ) ),
				$under
			);
		}

		return '<ul class="sffilters-options' . ( $tree ? ' sffilters-options--tree' : '' ) . '">' . $items . '</ul>';
	}

	/**
	 * Folds the options under one option away behind an arrow. The branch starts open when it holds a choice
	 * the shopper made. It is a `details` element, so it opens without the script too.
	 *
	 * @param string $key   What tells this branch from the others of its filter: the value or the address of its option.
	 * @param string $label Label of the option the branch belongs to.
	 * @param bool   $open  Whether the branch starts open.
	 * @param string $list  The rendered options under it.
	 */
	private function branch( string $key, string $label, bool $open, string $list ): string {
		return sprintf(
			'<details class="sffilters-branch" data-sffilters-branch="%1$s"%2$s><summary class="sffilters-branch__toggle" aria-label="%3$s"></summary>%4$s</details>',
			esc_attr( $key ),
			$open ? ' open' : '',
			/* translators: %s: name of a category that has subcategories. */
			esc_attr( sprintf( __( '%s: subcategories', 'starfiniti-filters' ), $label ) ),
			$list
		);
	}

	/**
	 * Tells whether one of the options, at any depth, carries the flag: `selected` for checkboxes, `current` for links.
	 */
	private static function holds( array $options, string $flag ): bool {
		foreach ( $options as $option ) {
			if ( ! empty( $option[ $flag ] ) || ( ! empty( $option['children'] ) && self::holds( $option['children'], $flag ) ) ) {
				return true;
			}
		}

		return false;
	}

	private function checkbox( string $name, string $value, string $label, int $count, bool $selected ): string {
		return sprintf(
			'<label class="sffilters-option__control"><input type="checkbox" name="%1$s" value="%2$s"%3$s> <span class="sffilters-option__label">%4$s</span> <span class="sffilters-option__count">%5$s</span></label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $selected, true, false ),
			esc_html( $label ),
			esc_html( number_format_i18n( $count ) )
		);
	}

	/**
	 * Renders the two inputs of a range. The placeholders show the lowest and highest value on offer.
	 */
	private function range( string $param, array $facet ): string {
		$inputs = '';
		$ends   = array(
			'min' => array( (string) ( $facet['labels'][0] ?? '' ), $facet['from'], $facet['min'] ),
			'max' => array( (string) ( $facet['labels'][1] ?? '' ), $facet['to'], $facet['max'] ),
		);

		foreach ( $ends as $end => list( $label, $value, $limit ) ) {
			$inputs .= sprintf(
				'<label class="sffilters-range__end"><span class="screen-reader-text">%1$s</span><input type="number" name="%2$s[%3$s]" value="%4$s" placeholder="%5$s" min="0" step="any" inputmode="decimal" data-sffilters-%3$s></label>',
				esc_html( $label ),
				esc_attr( $param ),
				esc_attr( $end ),
				esc_attr( null === $value ? '' : (string) (float) $value ),
				esc_attr( (string) (float) $limit )
			);
		}

		return sprintf(
			'<div class="sffilters-range" data-min="%3$s" data-max="%4$s">%1$s<span class="sffilters-range__unit">%2$s</span></div>',
			$inputs,
			esc_html( (string) ( $facet['unit'] ?? '' ) ),
			esc_attr( (string) (float) $facet['min'] ),
			esc_attr( (string) (float) $facet['max'] )
		);
	}
}
