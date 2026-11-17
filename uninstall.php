<?php
/**
 * Removes what the plugin itself stored: its options and the product property values it worked out.
 * Products, their own terms and orders are never touched.
 *
 * @package StarfinitiFilters
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'sffilters_settings' );
delete_option( 'sffilters_version' );
delete_site_transient( 'sffilters_release' );

global $wpdb;

// Property values: numbers in product meta, text values as terms of the `sffilters_<property>` taxonomies.
// The taxonomies are no longer registered here, so their rows are removed directly.
$sffilters_meta     = $wpdb->esc_like( '_sffilters_' ) . '%';
$sffilters_taxonomy = $wpdb->esc_like( 'sffilters_' ) . '%';

// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $sffilters_meta ) );
$wpdb->query( $wpdb->prepare( "DELETE tr FROM {$wpdb->term_relationships} AS tr INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy LIKE %s", $sffilters_taxonomy ) );
$wpdb->query( $wpdb->prepare( "DELETE t FROM {$wpdb->terms} AS t INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_id = t.term_id WHERE tt.taxonomy LIKE %s", $sffilters_taxonomy ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->term_taxonomy} WHERE taxonomy LIKE %s", $sffilters_taxonomy ) );
// phpcs:enable

wp_cache_flush();
