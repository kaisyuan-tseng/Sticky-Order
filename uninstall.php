<?php
/**
 * Sticky Order —— 卸载清理。
 *
 * 只在后台删除插件时运行，停用时不运行。
 * 停用会保留已保存的队列，重新启用即可恢复顺序。
 *
 * 插件不创建任何文件、目录、自定义表、定时任务或 transient，
 * 唯一持久化的数据就是存队列的 option。
 *
 * option 名可通过 `sticky_order_option_name` 过滤，但卸载时插件（含该过滤器）
 * 不会被加载。因此这里先删已知的默认名，再按 `sticky_order_` 前缀清扫同名 option；
 * 自定义的 option 名必须保留该前缀，才会被一并删除。
 *
 * 注意：核心的 `sticky_posts` option 属于 WordPress 本身，这里有意不动。
 *
 * @package StickyOrder
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * 删除当前站点的插件 option。
 *
 * @return void
 */
function sticky_order_uninstall_site_options() {
	delete_option( 'sticky_order_queue_map' );

	global $wpdb;

	/*
	 * 清扫通过 `sticky_order_option_name` 过滤器设置的自定义名。
	 * 该前缀在本站唯一，误删的可能性很低。
	 * 逐条调用 delete_option()，顺带清掉 option 缓存。
	 */
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 卸载清理：WordPress 没有按前缀列出 option 的 API，缓存由 delete_option() 处理。
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
