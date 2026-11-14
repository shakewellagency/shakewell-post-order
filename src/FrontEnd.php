<?php
/**
 * Optional front end ordering: sorts public queries and previous/next links by menu_order.
 *
 * @package Shakewell\PostOrder
 */

namespace Shakewell\PostOrder;

use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Off by default. Turn it on with `add_filter( 'shakewell_post_order_front_end', '__return_true' );`.
 *
 * It reproduces what Post Types Order calls "Auto Sort" and "Apply to navigation", so a site can switch
 * plugins without any list changing order: every query outside the admin list gets menu_order put in
 * front of its own ORDER BY (REST, WP-CLI and admin-ajax queries included, for every post type), and the
 * previous and next post links follow the same order. A query opts out with `ignore_custom_sort => true`.
 */
final class FrontEnd {

	/**
	 * Registers hooks. Called on plugins_loaded.
	 */
	public static function boot(): void {
		add_action( 'pre_get_posts', array( self::class, 'unsuppress' ) );
		add_filter( 'posts_orderby', array( self::class, 'orderby' ), 99, 2 );
		add_filter( 'get_previous_post_where', array( self::class, 'previous_where' ), 99 );
		add_filter( 'get_previous_post_sort', array( self::class, 'previous_sort' ) );
		add_filter( 'get_next_post_where', array( self::class, 'next_where' ), 99 );
		add_filter( 'get_next_post_sort', array( self::class, 'next_sort' ) );
	}

	/**
	 * Whether the feature is on.
	 */
	public static function enabled(): bool {
		return (bool) apply_filters( 'shakewell_post_order_front_end', false );
	}

	/**
	 * Whether a query is left alone: the admin list is handled by the Order column, and a query can opt out.
	 *
	 * @param WP_Query $query The query.
	 */
	private static function skip( WP_Query $query ): bool {
		return ( is_admin() && ! wp_doing_ajax() ) || true === $query->get( 'ignore_custom_sort' ) || ! self::enabled();
	}

	/**
	 * Lifts suppress_filters, which get_posts() sets and which would hide the orderby filter from it, as Post Types Order did.
	 *
	 * @param WP_Query $query The query.
	 */
	public static function unsuppress( WP_Query $query ): void {
		if ( ! is_admin() && 'nav_menu_item' !== $query->get( 'post_type' ) && ! self::skip( $query ) ) {
			$query->set( 'suppress_filters', false );
		}
	}

	/**
	 * Puts menu_order in front of the query's own ORDER BY.
	 *
	 * @param string   $orderby The ORDER BY clause.
	 * @param WP_Query $query   The query.
	 */
	public static function orderby( $orderby, $query ) {
		global $wpdb;
		if ( ! $query instanceof WP_Query || self::skip( $query ) ) {
			return $orderby;
		}
		// An explicit sort chosen in the URL, a search, and an ordering by a fixed list all win.
		if ( ( isset( $_GET['orderby'] ) && 'menu_order' !== $_GET['orderby'] ) || ( $query->is_search() && '' !== (string) $query->get( 's' ) ) || preg_match( '/FIELD\s*\(/i', (string) $orderby ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $orderby;
		}
		return '' === trim( (string) $orderby ) ? "{$wpdb->posts}.menu_order" : "{$wpdb->posts}.menu_order, {$orderby}";
	}

	/**
	 * Previous post: the one just before this one in menu_order, then newest first, order.
	 *
	 * @param string $where The WHERE clause.
	 */
	public static function previous_where( $where ) {
		return self::adjacent_where( $where, '<', '>' );
	}

	/**
	 * Next post: the one just after this one in menu_order, then newest first, order.
	 *
	 * @param string $where The WHERE clause.
	 */
	public static function next_where( $where ) {
		return self::adjacent_where( $where, '>', '<' );
	}

	/**
	 * Previous post sort.
	 *
	 * @param string $sort The ORDER BY ... LIMIT clause.
	 */
	public static function previous_sort( $sort ) {
		return self::enabled() && ! is_admin() ? 'ORDER BY p.menu_order DESC, p.post_date ASC LIMIT 1' : $sort;
	}

	/**
	 * Next post sort.
	 *
	 * @param string $sort The ORDER BY ... LIMIT clause.
	 */
	public static function next_sort( $sort ) {
		return self::enabled() && ! is_admin() ? 'ORDER BY p.menu_order ASC, p.post_date DESC LIMIT 1' : $sort;
	}

	/**
	 * Swaps core's date comparison for a menu_order one.
	 *
	 * @param string $where  The WHERE clause.
	 * @param string $core   Operator core compares post_date with ('<' previous, '>' next); menu_order uses the same.
	 * @param string $on_tie Operator for post_date when menu_order is equal.
	 */
	private static function adjacent_where( $where, string $core, string $on_tie ) {
		global $post, $wpdb;
		if ( ! self::enabled() || is_admin() || empty( $post ) ) {
			return $where;
		}
		return str_replace(
			"p.post_date {$core} '{$post->post_date}'",
			$wpdb->prepare(
				"( p.menu_order {$core} %d OR ( p.menu_order = %d AND p.post_date {$on_tie} %s ) )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- operators come from this class, never from input.
				$post->menu_order,
				$post->menu_order,
				$post->post_date
			),
			$where
		);
	}
}
