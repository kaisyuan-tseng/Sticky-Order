<?php
/**
 * Plugin Name: Sticky Order
 * Plugin URI: https://github.com/kaisyuan-tseng/Sticky-Order
 * Description: Turn sticky posts into an ordered queue. The WordPress sticky flag still decides which posts are sticky; Sticky Order decides their order, independent of publish dates. Reorder by drag and drop under Posts, optionally split into one queue per group, and let front-end and REST post lists use the saved order automatically - no backdating, no theme changes.
 * Version: 3.0.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: kaisyuan-tseng
 * Author URI: https://github.com/kaisyuan-tseng
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sticky-order
 * Domain Path: /languages
 *
 * Generic plugin: no site-specific term names, post types, or UI strings.
 * Site policy is injected through the filters listed in readme.txt.
 *
 * @wordpress-plugin
 * @package StickyOrder
 * @author  kaisyuan-tseng
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'STICKY_ORDER_VERSION' ) ) {
	define( 'STICKY_ORDER_VERSION', '3.0.1' );
}
if ( ! defined( 'STICKY_ORDER_DIR' ) ) {
	define( 'STICKY_ORDER_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'STICKY_ORDER_URL' ) ) {
	define( 'STICKY_ORDER_URL', plugin_dir_url( __FILE__ ) );
}

/**
 * 加载插件翻译。
 *
 * 挂在 `init`（不是 `admin_init`），这样前台请求里的 REST 字段描述也能翻译。
 */
add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'sticky-order', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

/* ------------------------------------------------------------------ 公共 API */

/**
 * 分组清单：array( group_slug => 显示名 )。
 *
 * 返回空数组时插件退化成「单队列」模式 —— 所有置顶文章共用一条队列，
 * 后台只显示一个队列区块，REST 的 sticky_order_position 是全局序号。
 */
function sticky_order_groups() {
	$groups = (array) apply_filters( 'sticky_order_groups', array() );

	$normalized = array();
	foreach ( $groups as $slug => $label ) {
		$slug = (string) $slug;
		if ( '' === $slug ) {
			continue;
		}
		$normalized[ $slug ] = '' !== (string) $label ? (string) $label : $slug;
	}

	return $normalized;
}

/**
 * 队列键名列表：把「没配分组 = 单队列 default」这条规则收在一处，
 * 队列计算、后台渲染、保存校验都读它，不必各自判断一遍。
 *
 * @return string[]
 */
function sticky_order_group_keys() {
	$groups = sticky_order_groups();

	return $groups ? array_keys( $groups ) : array( 'default' );
}

/** 分组依据的 taxonomy（按 term slug 与分组 slug 对齐） */
function sticky_order_group_taxonomy() {
	return (string) apply_filters( 'sticky_order_group_taxonomy', 'category' );
}

/** 参与排序的文章类型 */
function sticky_order_post_types() {
	$types = (array) apply_filters( 'sticky_order_post_types', array( 'post' ) );

	return array_values( array_filter( array_map( 'strval', $types ) ) );
}

/** 存队列的 option 名 */
function sticky_order_option_name() {
	return (string) apply_filters( 'sticky_order_option_name', 'sticky_order_queue_map' );
}

/** 后台页所需权限 */
function sticky_order_capability() {
	return (string) apply_filters( 'sticky_order_capability', 'edit_others_posts' );
}

/** 后台菜单挂载位置 */
function sticky_order_menu_parent() {
	return (string) apply_filters( 'sticky_order_menu_parent', 'edit.php' );
}

/**
 * 后台界面文案。
 *
 * 默认值都是英文 msgid，经 `sticky-order` 文本域翻译（/languages 内置 zh_CN）。
 * 插件列表里的插件名始终是英文；页面标题与菜单名属于界面文案，跟随站点语言。
 * 站点可通过 `sticky_order_labels` 过滤器覆盖任意键：过滤器收到默认值，
 * 缺键时回退到默认值。
 *
 * @return array<string,string>
 */
function sticky_order_labels() {
	$defaults = array(
		'page_title'    => __( 'Sticky Order', 'sticky-order' ),
		'menu_title'    => __( 'Sticky Order', 'sticky-order' ),
		'single_title'  => __( 'All sticky posts', 'sticky-order' ),
		'empty_group'   => __( 'No sticky posts yet. Check "Sticky" in the post editor and the post will appear here automatically.', 'sticky-order' ),
		'drag_hint'     => __( 'Drag the handle on the left to reorder. Posts at the top come first.', 'sticky-order' ),
		'save'          => __( 'Save Changes', 'sticky-order' ),
		'saved'         => __( 'Order saved.', 'sticky-order' ),
		'no_thumb'      => __( 'No featured image', 'sticky-order' ),
		'orphans_title' => __( 'These sticky posts are not in any group and will not appear in any queue:', 'sticky-order' ),
		'orphans_hint'  => __( 'Assign them a category that matches a group.', 'sticky-order' ),
	);

	/* 缺 key 时回退默认值，站点只改一两句也不用整包重写 */
	return array_merge( $defaults, (array) apply_filters( 'sticky_order_labels', $defaults ) );
}

/* ------------------------------------------------------------------- 启动 */

require_once STICKY_ORDER_DIR . 'includes/class-sticky-order-queue.php';
require_once STICKY_ORDER_DIR . 'includes/class-sticky-order-query.php';
require_once STICKY_ORDER_DIR . 'includes/class-sticky-order-rest.php';
require_once STICKY_ORDER_DIR . 'includes/class-sticky-order-admin.php';

Sticky_Order_Query::init();
Sticky_Order_REST::init();
Sticky_Order_Admin::init();
