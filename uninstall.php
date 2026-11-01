<?php
/**
 * Uninstall: removes data only when "delete data on uninstall" is enabled.
 * Generated coupons that were used in orders are kept (they belong to order history).
 *
 * @package Dev3\AiSmartCoupons
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dev3sc_settings = get_option( 'dev3sc_settings', array() );
if ( empty( $dev3sc_settings['delete_on_uninstall'] ) ) {
	return;
}
global $wpdb;

// Unused generated coupons.
$dev3sc_coupons = get_posts(
	array(
		'post_type'      => 'shop_coupon',
		'post_status'    => 'any',
		'fields'         => 'ids',
		'posts_per_page' => -1,
		'meta_key'       => '_dev3sc_generated', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
	)
);
foreach ( $dev3sc_coupons as $dev3sc_coupon_id ) {
	if ( 0 === (int) get_post_meta( $dev3sc_coupon_id, 'usage_count', true ) ) {
		wp_delete_post( $dev3sc_coupon_id, true );
	}
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dev3sc_usage" );
// Core tables added with the shared core v2 (AI cache, background jobs, embeddings).
foreach ( array( 'core_cache', 'core_jobs', 'core_embeddings' ) as $dev3sc_core_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dev3sc_{$dev3sc_core_table}" );
}
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dev3sc_offers" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_dev3sc\\_%' OR option_name LIKE '\\_transient\\_timeout\\_dev3sc\\_%'" );
// phpcs:enable
foreach ( array( 'dev3sc_settings', 'dev3sc_license', 'dev3sc_db_version', 'dev3sc_flash_sales', 'dev3sc_budget' ) as $dev3sc_option ) {
	delete_option( $dev3sc_option );
}
delete_transient( 'dev3sc_models' );
delete_site_transient( 'dev3sc_update_info' );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'dev3sc_sweep' );
	as_unschedule_all_actions( 'dev3sc_budget_reset' );
}
