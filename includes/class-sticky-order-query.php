<?php
/**
 * 查询层排序 —— 把置顶队列直接应用到前台与 REST 的文章列表顺序。
 *
 * 只用 WordPress 原生查询钩子：
 *   - pre_get_posts：关掉核心按日期前置 sticky 的行为，避免与队列顺序打架；
 *   - posts_orderby：在 SQL 的 ORDER BY 前插入「置顶优先 + 队列内顺序」。
 *
 * 排序放在 SQL 层而不是 the_posts，是为了让分页、found_posts、REST 的
 * per_page 都基于同一套顺序，不会出现第 1 页看不到队首文章的情况。
 *
 * @package StickyOrder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 把队列顺序应用到文章查询。
 */
class Sticky_Order_Query {

	/** 注册查询钩子。 */
	public static function init() {
		add_action( 'pre_get_posts', array( __CLASS__, 'pre_get_posts' ) );
		add_filter( 'posts_orderby', array( __CLASS__, 'posts_orderby' ), 99, 2 );
	}

	/**
	 * 停用核心的 sticky 前置排序。
	 *
	 * @param WP_Query $query 当前查询.
	 */
	public static function pre_get_posts( $query ) {
		if ( ! self::should_apply( $query ) ) {
			return;
		}

		if ( ! Sticky_Order_Queue::ordered_ids() ) {
			return;
		}

		/*
		 * 核心只在首页第 1 页按日期把 sticky 放到最前，还可能补拉文章；
		 * 这里改由 posts_orderby 统一处理，避免两套顺序叠加和分页错位。
		 */
		$query->set( 'ignore_sticky_posts', true );
	}

	/**
	 * 在 ORDER BY 前插入置顶优先与队列顺序。
	 *
	 * @param string   $orderby 原有 ORDER BY 子句（不含 ORDER BY 关键字）.
	 * @param WP_Query $query   当前查询.
	 * @return string
	 */
	public static function posts_orderby( $orderby, $query ) {
		if ( ! self::should_apply( $query ) ) {
			return $orderby;
		}

		$ids = Sticky_Order_Queue::ordered_ids_for_query( $query );
		if ( ! $ids ) {
			return $orderby;
		}

		global $wpdb;

		$ids          = array_map( 'absint', $ids );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		/*
		 * CASE 先把所有置顶文章分到同一组；FIELD 再让它们按队列表顺序排列，
		 * 未归组置顶因为排在 ordered_ids() 末尾，自然落在队尾。非置顶文章保持原查询顺序。
		 * 占位符交给 $wpdb->prepare()，两个子句共用同一份 ID 列表。
		 */
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from $wpdb.
		$sticky_order = $wpdb->prepare(
			"CASE WHEN {$wpdb->posts}.ID IN ({$placeholders}) THEN 0 ELSE 1 END, "
				. "FIELD({$wpdb->posts}.ID, {$placeholders})",
			array_merge( $ids, $ids )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $sticky_order ) {
			return $orderby;
		}

		return $orderby ? $sticky_order . ', ' . $orderby : $sticky_order;
	}

	/**
	 * 判断当前查询是否应该套用队列顺序。
	 *
	 * @param WP_Query $query 当前查询.
	 * @return bool
	 */
	private static function should_apply( $query ) {
		if ( ! ( $query instanceof WP_Query ) ) {
			return false;
		}

		/* 内部查询与显式退出：resolve() 自己也用 WP_Query，必须避免递归。 */
		if ( $query->get( 'sticky_order_skip' ) || $query->get( 'suppress_filters' ) || $query->get( 'groupby' ) ) {
			return false;
		}

		$default = ! is_admin()
			&& ! wp_doing_cron()
			&& ! ( defined( 'WP_CLI' ) && WP_CLI )
			&& ! $query->is_singular()
			&& self::includes_supported_post_type( $query );

		/**
		 * 过滤是否对某次查询应用队列顺序。
		 *
		 * @param bool     $apply 是否应用该顺序.
		 * @param WP_Query $query 被过滤的查询.
		 */
		return (bool) apply_filters( 'sticky_order_apply_to_query', $default, $query );
	}

	/**
	 * 查询的 post_type 是否包含插件支持的类型。
	 *
	 * @param WP_Query $query 当前查询.
	 * @return bool
	 */
	private static function includes_supported_post_type( $query ) {
		$supported = sticky_order_post_types();
		if ( ! $supported ) {
			return false;
		}

		$types = $query->get( 'post_type' );
		if ( empty( $types ) ) {
			$types = array( 'post' );
		}

		if ( 'any' === $types ) {
			return true;
		}

		$types = (array) $types;

		return in_array( 'any', $types, true ) || (bool) array_intersect( $types, $supported );
	}
}
