<?php
/**
 * 队列计算 —— 插件的所有业务规则都在这里，且只在请求内算一次。
 *
 * 规则：
 *   1. 只有「已发布 + 已置顶」的文章进队列 —— 置顶开关仍由 WordPress 原生的
 *      sticky 机制管，插件不新增任何入口；
 *   2. 顺序以本插件保存的 option 为准；
 *   3. option 里没有的新置顶，按发布日期倒序追加到队伍末尾（不会插队）；
 *   4. option 里有、但已经取消置顶或转成草稿的，直接丢弃 —— 取消置顶即刻生效；
 *   5. 分组模式下，一篇文章可以同时进多条分类队列；主队列取分组顺序里
 *      第一个命中的分类，供单值 API / REST 单值字段使用。
 *
 * @package StickyOrder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 队列解析与查询。
 */
class Sticky_Order_Queue {

	/**
	 * 单次请求内的结果缓存。
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * 解析所有队列。
	 *
	 * @return array{
	 *     queues: array<string,int[]>,
	 *     ranks: array<int,int>,
	 *     groups: array<int,string>,
	 *     positions: array<int,array<string,int>>,
	 *     queues_of: array<int,string[]>,
	 *     posts: array<int,WP_Post>,
	 *     single: bool
	 * }
	 */
	public static function resolve() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$groups = sticky_order_groups();
		$queues = array_fill_keys( sticky_order_group_keys(), array() );

		$result = array(
			'queues'    => $queues,
			'ranks'     => array(),
			'groups'    => array(),
			'positions' => array(),
			'queues_of' => array(),
			'posts'     => array(),
			'single'    => empty( $groups ),
		);

		$sticky = array_values( array_unique( array_map( 'intval', (array) get_option( 'sticky_posts', array() ) ) ) );
		if ( ! $sticky ) {
			self::$cache = $result;
			return self::$cache;
		}

		$posts = get_posts(
			array(
				'post__in'            => $sticky,
				'post_type'           => sticky_order_post_types(),
				'post_status'         => 'publish',
				'posts_per_page'      => -1,
				'ignore_sticky_posts' => true,
				'orderby'             => 'date',
				'order'               => 'DESC',
				'sticky_order_skip'   => true,
			)
		);

		/* 归组：候选集保持发布日期倒序，作为「未排序部分」的默认顺序 */
		$buckets = $queues;
		$indexed = array();

		foreach ( $posts as $post ) {
			$id = (int) $post->ID;

			/* 没分到组的也要收进索引：后台的「未归组」提示靠它 */
			$indexed[ $id ] = $post;

			$matched = self::groups_for( $post, $groups );
			if ( ! $matched ) {
				continue;
			}

			$result['queues_of'][ $id ] = array();

			/*
			 * 一篇文章可以同时进多条分类队列；每条队列有自己的独立位置。
			 * 主队列（ranks / groups）取分组顺序里第一个命中的队列，供旧的
			 * 单值 API、REST sticky_order_position / sticky_order_queue 使用。
			 */
			foreach ( $matched as $group ) {
				if ( ! isset( $buckets[ $group ] ) ) {
					continue;
				}

				$buckets[ $group ][]       = $id;
				$result['queues_of'][ $id ][] = (string) $group;
			}
		}

		$saved = get_option( sticky_order_option_name(), array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		foreach ( $buckets as $group => $candidates ) {
			$preferred = isset( $saved[ $group ] ) ? array_map( 'intval', (array) $saved[ $group ] ) : array();
			$ordered   = array();

			foreach ( $preferred as $id ) {
				if ( in_array( $id, $candidates, true ) && ! in_array( $id, $ordered, true ) ) {
					$ordered[] = $id;
				}
			}

			foreach ( $candidates as $id ) {
				if ( ! in_array( $id, $ordered, true ) ) {
					$ordered[] = $id;
				}
			}

			$queues[ $group ] = $ordered;

			foreach ( $ordered as $position => $id ) {
				$result['positions'][ $id ][ (string) $group ] = $position;

				if ( ! isset( $result['ranks'][ $id ] ) ) {
					$result['ranks'][ $id ]  = $position;
					$result['groups'][ $id ] = (string) $group;
				}
			}
		}

		$result['queues'] = $queues;
		$result['posts']  = $indexed;

		self::$cache = $result;

		return self::$cache;
	}

	/**
	 * 某篇文章归属的所有队列键名，按分组顺序返回。
	 * $groups 为空即单队列模式，所有文章都归到 default。
	 *
	 * @param WP_Post              $post   文章.
	 * @param array<string,string> $groups 分组清单（调用方传入，避免逐篇重复跑 filter）.
	 * @return string[]
	 */
	private static function groups_for( $post, array $groups ) {
		if ( ! $groups ) {
			return array( 'default' );
		}

		$terms = get_the_terms( $post, sticky_order_group_taxonomy() );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return array();
		}

		$slugs = wp_list_pluck( $terms, 'slug' );

		/* array_keys 保留分组顺序，不能按分类项顺序决定主队列 */
		return array_values( array_intersect( array_keys( $groups ), $slugs ) );
	}

	/**
	 * 置顶了、但不属于任何分组的文章 ID —— 后台提示出来，
	 * 否则会出现「明明置顶了却哪里都不显示」的情况。
	 *
	 * @return int[]
	 */
	public static function orphans() {
		$data = self::resolve();

		if ( $data['single'] ) {
			return array();
		}

		$orphans = array();
		foreach ( array_keys( $data['posts'] ) as $id ) {
			if ( ! isset( $data['groups'][ $id ] ) ) {
				$orphans[] = (int) $id;
			}
		}

		return $orphans;
	}

	/**
	 * 查询层使用的置顶 ID 顺序：先按队列顺序，再补上未归组的置顶文章。
	 *
	 * 未归组置顶（不属于任何分组）没有队列位置，统一排在队尾，
	 * 这样既不会丢失核心 sticky 的置顶语义，也不会插进任何分组队首。
	 *
	 * @return int[]
	 */
	public static function ordered_ids() {
		$data = self::resolve();
		$ids  = array();

		foreach ( $data['queues'] as $queue ) {
			foreach ( $queue as $id ) {
				$ids[] = (int) $id;
			}
		}

		foreach ( self::orphans() as $id ) {
			$ids[] = (int) $id;
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * 查询层使用的置顶 ID 顺序。
	 *
	 * 单分类查询用该分类自己的队列；未过滤、多分类或无法识别的查询
	 * 用主队列顺序（ordered_ids()）。
	 *
	 * @param WP_Query|null $query 当前查询.
	 * @return int[]
	 */
	public static function ordered_ids_for_query( $query = null ) {
		$group = self::query_group( $query );

		if ( null !== $group ) {
			$data = self::resolve();

			return isset( $data['queues'][ $group ] ) ? array_map( 'intval', $data['queues'][ $group ] ) : array();
		}

		return self::ordered_ids();
	}

	/**
	 * 识别查询是否只限定在一个分组分类内。
	 *
	 * @param WP_Query|null $query 当前查询.
	 * @return string|null 分组 slug；不是单分类查询时返回 null.
	 */
	private static function query_group( $query ) {
		if ( ! ( $query instanceof WP_Query ) ) {
			return null;
		}

		$groups = sticky_order_groups();
		if ( ! $groups ) {
			return null;
		}

		$taxonomy = sticky_order_group_taxonomy();
		$slugs    = array();

		$category_name = $query->get( 'category_name' );
		if ( $category_name ) {
			foreach ( preg_split( '/[,+]/', (string) $category_name ) as $slug ) {
				$slug = sanitize_title( trim( $slug ) );
				if ( '' !== $slug ) {
					$slugs[] = $slug;
				}
			}
		}

		foreach ( array( 'cat', 'category__in' ) as $key ) {
			$value = $query->get( $key );
			if ( empty( $value ) ) {
				continue;
			}

			foreach ( (array) $value as $ids ) {
				foreach ( preg_split( '/[,\s]+/', (string) $ids ) as $id ) {
					$id = (int) $id;
					if ( $id <= 0 ) {
						continue;
					}

					$term = get_term( $id, $taxonomy );
					if ( $term && ! is_wp_error( $term ) ) {
						$slugs[] = $term->slug;
					}
				}
			}
		}

		$tax_query = $query->get( 'tax_query' );
		if ( is_array( $tax_query ) ) {
			foreach ( $tax_query as $clause ) {
				if ( ! is_array( $clause ) || ! isset( $clause['taxonomy'], $clause['terms'] ) ) {
					continue;
				}
				if ( $taxonomy !== $clause['taxonomy'] ) {
					continue;
				}

				$operator = isset( $clause['operator'] ) ? strtoupper( (string) $clause['operator'] ) : 'IN';
				if ( 'IN' !== $operator && 'AND' !== $operator ) {
					continue;
				}

				$field = isset( $clause['field'] ) ? (string) $clause['field'] : 'term_id';

				foreach ( (array) $clause['terms'] as $term_value ) {
					foreach ( preg_split( '/[,\s]+/', (string) $term_value ) as $single ) {
						if ( 'slug' === $field ) {
							$slugs[] = sanitize_title( $single );
							continue;
						}

						if ( 'name' === $field ) {
							$term = get_term_by( 'name', $single, $taxonomy );
						} else {
							$term = get_term( (int) $single, $taxonomy );
						}

						if ( $term && ! is_wp_error( $term ) ) {
							$slugs[] = $term->slug;
						}
					}
				}
			}
		}

		$slugs = array_values( array_unique( array_filter( $slugs ) ) );
		if ( 1 !== count( $slugs ) ) {
			return null;
		}

		$slug = (string) $slugs[0];

		return isset( $groups[ $slug ] ) ? $slug : null;
	}

	/**
	 * 某文章在所有队列里的位置，键名是队列 slug。
	 * 不在任何队列时返回 null；单队列模式下键名是 default。
	 *
	 * @param int $post_id 文章 ID.
	 * @return array<string,int>|null
	 */
	public static function positions_of( $post_id ) {
		$data = self::resolve();
		$id   = (int) $post_id;

		return isset( $data['positions'][ $id ] ) ? $data['positions'][ $id ] : null;
	}

	/**
	 * 某文章所属的全部队列键名。
	 *
	 * @param int $post_id 文章 ID.
	 * @return string[]
	 */
	public static function queues_of( $post_id ) {
		$data = self::resolve();
		$id   = (int) $post_id;

		return isset( $data['queues_of'][ $id ] ) ? array_map( 'strval', $data['queues_of'][ $id ] ) : array();
	}

	/**
	 * 某文章在其主队列里的位置；不在队列里返回 null。
	 * 传入 $group 时返回该分类队列里的位置。
	 *
	 * @param int         $post_id 文章 ID.
	 * @param string|null $group   队列 slug；不传则用主队列.
	 * @return int|null
	 */
	public static function position_of( $post_id, $group = null ) {
		$data = self::resolve();
		$id   = (int) $post_id;

		if ( null !== $group ) {
			$group = (string) $group;

			return isset( $data['positions'][ $id ][ $group ] ) ? (int) $data['positions'][ $id ][ $group ] : null;
		}

		return isset( $data['ranks'][ $id ] ) ? (int) $data['ranks'][ $id ] : null;
	}

	/**
	 * 某文章的主队列键名；不在队列里返回 null。
	 * 传入 $group 时返回该分类队列键名或 null。
	 *
	 * @param int         $post_id 文章 ID.
	 * @param string|null $group   队列 slug；不传则用主队列.
	 * @return string|null
	 */
	public static function queue_of( $post_id, $group = null ) {
		$data = self::resolve();
		$id   = (int) $post_id;

		if ( null !== $group ) {
			$group = (string) $group;

			return isset( $data['positions'][ $id ][ $group ] ) ? $group : null;
		}

		return isset( $data['groups'][ $id ] ) ? (string) $data['groups'][ $id ] : null;
	}
}
