<?php
/**
 * Sticky Order — uninstall cleanup.
 *
 * Runs only when the plugin is deleted through wp-admin, not on deactivation.
 * Deactivation keeps the saved queue so re-activating restores the order.
 *
 * The plugin creates no files, directories, custom tables, cron events, or
 * transients. The only persistent data is the queue option.
 *
 * The option name is filterable via `sticky_order_option_name`, but the filter
 * (and the whole plugin) is NOT loaded in the uninstall context. We therefore
 * delete the known default name and sweep every `sticky_order_*` option by
 * prefix. Custom option names must keep that prefix to be cleaned up here.
 *
 * Note: the core `sticky_posts` option belongs to WordPress itself and is
 * deliberately left untouched.
 *
 * @package StickyOrder
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete the plugin options for the current site.
 *
 * @return void
 */
function sticky_order_uninstall_site_options() {
	delete_option( 'sticky_order_queue_map' );

	global $wpdb;

	/*
	 * Sweep custom names set through the `sticky_order_option_name` filter.
	 * The prefix is unique to this plugin, so collateral damage is unlikely.
	 * delete_option() is used per row so the option cache is cleared too.
	 */
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup: no WP API exists for listing options by prefix; delete_option() handles the cache.
	$option_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'sticky_order_' ) . '%'
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	foreach ( (array) $option_names as $option_name ) {
		delete_option( $option_name );
	}
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	if ( $site_ids ) {
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			sticky_order_uninstall_site_options();
			restore_current_blog();
		}
	} else {
		sticky_order_uninstall_site_options();
	}
} else {
	sticky_order_uninstall_site_options();
}
