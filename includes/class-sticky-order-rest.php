<?php
/**
 * REST 字段 —— 把队列位置挂到文章响应上，而不是单开一个 endpoint。
 *
 * 无头前端拉文章列表时顺手就带回来了：不用多发请求，也不用按 slug 做二次关联。
 * 未置顶（或不在队列里）为 null，前端据此回退到「按日期」。
 *
 * 字段：
 *   sticky_order_position    主队列内的位置，0 起；不在队列里为 null
 *   sticky_order_queue       主队列的 key（单队列模式下是 default）；不在队列里为 null
 *   sticky_order_positions   所有队列的位置映射（文章属于多个分类时使用）
 *
 * @package StickyOrder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST 字段注册。
 */
class Sticky_Order_REST {

	/** 注册 REST 字段钩子。 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	/** 注册三个只读的文章字段。 */
	public static function register() {
		$post_types = sticky_order_post_types();
		if ( ! $post_types ) {
			return;
		}

		$fields = array(
			'sticky_order_position'  => array(
				'method'      => 'position_of',
				'description' => __( 'Position in its primary sticky queue, starting at 0; null when the post is not queued.', 'sticky-order' ),
				'type'        => array( 'integer', 'null' ),
			),
			'sticky_order_queue'     => array(
				'method'      => 'queue_of',
				'description' => __( 'Key of the primary queue the post belongs to; null when the post is not queued.', 'sticky-order' ),
				'type'        => array( 'string', 'null' ),
			),
			'sticky_order_positions' => array(
				'method'      => 'positions_of',
				'description' => __( 'All queue positions for the post, keyed by queue; null when the post is not queued.', 'sticky-order' ),
				'type'        => array( 'object', 'null' ),
			),
		);

		foreach ( $fields as $name => $field ) {
			register_rest_field(
				$post_types,
				$name,
				array(
					'get_callback' => static function ( $post_arr ) use ( $field ) {
						$id = isset( $post_arr['id'] ) ? (int) $post_arr['id'] : 0;

						return call_user_func( array( 'Sticky_Order_Queue', $field['method'] ), $id );
					},
					'schema'       => array(
						'description' => $field['description'],
						'type'        => $field['type'],
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
				)
			);
		}
	}
}
