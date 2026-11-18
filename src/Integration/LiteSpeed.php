<?php
/**
 * Keeps LiteSpeed Cache away from the stylesheet and the script of the panel.
 *
 * @package StarfinitiFilters
 */

namespace Starfiniti\Filters\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LiteSpeed Cache can serve a visitor only the CSS a page used when it was first seen ("Unique CSS", forced by
 * Guest optimization) and can hold every script back until the first click or scroll. A panel added after that
 * stylesheet was made is then drawn without its styles, and the first click on a filter is lost.
 *
 * The stylesheet and the script are small, so they are left as they are. The filters only add to the lists the
 * store keeps in the LiteSpeed settings, and do nothing where LiteSpeed Cache is not installed.
 */
final class LiteSpeed {

	/**
	 * The stylesheet, by a part of its address.
	 */
	private const STYLES = array( 'starfiniti-filters/assets/filters.css' );

	/**
	 * The script by a part of its address, and the two inline scripts by a word only they hold: the settings
	 * of the script, and the line that marks small screens before the page is drawn.
	 */
	private const SCRIPTS = array( 'starfiniti-filters/assets/filters.js', 'sffiltersConfig', 'sffilters-mobile' );

	public function register(): void {
		add_filter( 'litespeed_optimize_css_excludes', array( $this, 'keep_styles' ) );

		// Combining and minifying, deferring or delaying, and the same for visitors in Guest mode.
		foreach ( array( 'litespeed_optimize_js_excludes', 'litespeed_optm_js_defer_exc', 'litespeed_optm_gm_js_exc' ) as $hook ) {
			add_filter( $hook, array( $this, 'keep_scripts' ) );
		}
	}

	/**
	 * @param mixed $excluded What the store already keeps out of CSS optimization.
	 */
	public function keep_styles( $excluded ): array {
		return self::with( $excluded, self::STYLES );
	}

	/**
	 * @param mixed $excluded What the store already keeps out of JS optimization.
	 */
	public function keep_scripts( $excluded ): array {
		return self::with( $excluded, self::SCRIPTS );
	}

	/**
	 * Adds entries to a list of LiteSpeed, once each.
	 *
	 * @param mixed    $excluded The list so far.
	 * @param string[] $entries  Entries to add.
	 */
	private static function with( $excluded, array $entries ): array {
		return array_values( array_unique( array_merge( is_array( $excluded ) ? $excluded : array(), $entries ) ) );
	}
}
