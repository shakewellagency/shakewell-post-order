<?php
/**
 * Behaviour tests: optional front end ordering.
 *
 * @package Shakewell\PostOrder
 */

namespace Shakewell\PostOrder\Tests;

use WP_UnitTestCase;

final class FrontEndTest extends WP_UnitTestCase {

	/**
	 * Three posts: A is oldest with menu_order 2, B middle with 0, C newest with 1.
	 *
	 * @return int[] IDs keyed by title.
	 */
	private function posts(): array {
		$ids = array();
		foreach ( array(
			'A' => array( '2020-01-01 00:00:00', 2 ),
			'B' => array( '2020-01-02 00:00:00', 0 ),
			'C' => array( '2020-01-03 00:00:00', 1 ),
		) as $title => $row ) {
			$ids[ $title ] = self::factory()->post->create(
				array(
					'post_title'  => $title,
					'post_date'   => $row[0],
					'menu_order'  => $row[1],
				)
			);
		}
		return $ids;
	}

	private function titles( array $posts ): array {
		return array_map( static fn( $p ) => $p->post_title, $posts );
	}

	public function tear_down(): void {
		global $post;
		$post = null;
		unset( $_GET['orderby'] );
		parent::tear_down();
	}

	public function test_off_by_default_core_order_is_untouched(): void {
		$this->posts();
		$this->assertSame( array( 'C', 'B', 'A' ), $this->titles( get_posts( array( 'numberposts' => -1 ) ) ) );
	}

	public function test_menu_order_goes_in_front_of_get_posts_even_with_its_own_orderby(): void {
		$this->posts();
		add_filter( 'shakewell_post_order_front_end', '__return_true' );
		$this->assertSame( array( 'B', 'C', 'A' ), $this->titles( get_posts( array( 'numberposts' => -1 ) ) ) );
		self::factory()->post->create( array( 'post_title' => 'D', 'post_date' => '2020-01-04 00:00:00', 'menu_order' => 0 ) );
		$this->assertSame(
			array( 'D', 'B', 'C', 'A' ),
			$this->titles( get_posts( array( 'numberposts' => -1, 'orderby' => 'title', 'order' => 'DESC' ) ) ),
			'menu_order leads and the query\'s own order breaks ties'
		);
	}

	public function test_a_query_can_opt_out(): void {
		$this->posts();
		add_filter( 'shakewell_post_order_front_end', '__return_true' );
		$this->assertSame( array( 'C', 'B', 'A' ), $this->titles( get_posts( array( 'numberposts' => -1, 'ignore_custom_sort' => true ) ) ) );
	}

	public function test_an_orderby_in_the_url_is_left_alone(): void {
		$this->posts();
		add_filter( 'shakewell_post_order_front_end', '__return_true' );
		$_GET['orderby'] = 'title';
		$this->assertSame( array( 'C', 'B', 'A' ), $this->titles( get_posts( array( 'numberposts' => -1 ) ) ) );
		unset( $_GET['orderby'] );
	}

	public function test_a_query_that_already_sorts_by_menu_order_keeps_its_direction(): void {
		$this->posts();
		add_filter( 'shakewell_post_order_front_end', '__return_true' );
		$this->assertSame( array( 'A', 'C', 'B' ), $this->titles( get_posts( array( 'numberposts' => -1, 'orderby' => 'menu_order', 'order' => 'DESC' ) ) ) );
	}

	public function test_previous_and_next_follow_menu_order(): void {
		global $post;
		$ids = $this->posts();
		add_filter( 'shakewell_post_order_front_end', '__return_true' );
		// List order is B (0), C (1), A (2).
		$post = get_post( $ids['C'] );
		$this->assertSame( $ids['B'], get_previous_post()->ID );
		$this->assertSame( $ids['A'], get_next_post()->ID );
		$post = get_post( $ids['B'] );
		$this->assertSame( '', get_previous_post() );
		$this->assertSame( $ids['C'], get_next_post()->ID );
	}

	public function test_core_adjacent_posts_when_off(): void {
		global $post;
		$ids  = $this->posts();
		$post = get_post( $ids['B'] );
		$this->assertSame( $ids['A'], get_previous_post()->ID );
		$this->assertSame( $ids['C'], get_next_post()->ID );
	}
}
