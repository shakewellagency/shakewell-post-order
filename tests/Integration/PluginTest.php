<?php
/**
 * Behaviour tests: settings, default ordering and the save endpoint.
 *
 * @package Shakewell\PostOrder
 */

namespace Shakewell\PostOrder\Tests;

use Shakewell\PostOrder\Plugin;
use WP_Ajax_UnitTestCase;

final class PluginTest extends WP_Ajax_UnitTestCase {

	/**
	 * Runs the save endpoint and returns the decoded JSON.
	 *
	 * @param array $post Request body.
	 */
	private function call( array $post ): array {
		$_POST = $post;
		try {
			$this->_handleAjax( 'shakewell_post_order_save' );
		} catch ( \WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			unset( $e );
		}
		return json_decode( $this->_last_response, true ) ?? array();
	}

	private function request( array $ids, int $offset = 0 ): array {
		return array(
			'nonce'  => wp_create_nonce( 'shakewell_post_order_save' ),
			'ids'    => $ids,
			'offset' => $offset,
		);
	}

	private function menu_order( int $id ): int {
		return (int) get_post( $id )->menu_order;
	}

	public function set_up(): void {
		parent::set_up();
		register_post_type( 'book', array( 'public' => true ) );
		register_post_type(
			'section',
			array(
				'public'       => true,
				'hierarchical' => true,
				'supports'     => array( 'title', 'page-attributes' ),
			)
		);
		update_option( Plugin::OPTION, array( 'book' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		global $pagenow;
		$pagenow = null;
		$_GET    = array();
		set_current_screen( 'front' );
		unregister_post_type( 'book' );
		unregister_post_type( 'section' );
		parent::tear_down();
	}

	public function test_nothing_is_enabled_by_default(): void {
		delete_option( Plugin::OPTION );
		$this->assertSame( array(), Plugin::enabled() );
	}

	public function test_filter_overrides_the_option(): void {
		add_filter( 'shakewell_post_order_post_types', static fn() => array( 'post', 'nope' ) );
		$this->assertSame( array( 'post' ), Plugin::enabled() );
	}

	public function test_sanitize_keeps_only_known_types(): void {
		$this->assertSame( array( 'book', 'page' ), Plugin::sanitize( array( 'book', 'page', 'bogus', '<b>' ) ) );
		$this->assertSame( array(), Plugin::sanitize( 'page-not-an-array-of-known' ) );
	}

	public function test_saves_positions_in_submitted_order(): void {
		$ids = self::factory()->post->create_many( 3, array( 'post_type' => 'book' ) );
		$out = $this->call( $this->request( array_reverse( $ids ) ) );
		$this->assertTrue( $out['success'] );
		$this->assertSame( array( 2, 1, 0 ), array_map( array( $this, 'menu_order' ), $ids ) );
	}

	public function test_positions_continue_from_the_previous_page_and_pages_stay_disjoint(): void {
		$ids = array();
		foreach ( array( 'A', 'B', 'C', 'D' ) as $title ) {
			$ids[ $title ] = self::factory()->post->create( array( 'post_type' => 'book', 'post_title' => $title ) );
		}
		// Page 2 holds C and D; the user swaps them. A and B keep the first two places.
		$this->call( $this->request( array( $ids['D'], $ids['C'] ), 2 ) );
		$this->assertSame( array( 0, 1, 3, 2 ), array_map( array( $this, 'menu_order' ), array_values( $ids ) ) );
	}

	/**
	 * Creates a hierarchical type's tree: returns a parent and its three children, plus another parent's child.
	 */
	private function tree(): array {
		update_option( Plugin::OPTION, array( 'section' ) );
		$make  = static fn( string $title, int $parent = 0, int $order = 0 ) => self::factory()->post->create(
			array(
				'post_type'   => 'section',
				'post_title'  => $title,
				'post_parent' => $parent,
				'menu_order'  => $order,
			)
		);
		$p     = $make( 'P' );
		$q     = $make( 'Q', 0, 5 );
		$kids  = array( $make( 'C1', $p ), $make( 'C2', $p ), $make( 'C3', $p ) );
		$other = $make( 'D1', $q, 7 );
		return array( $p, $q, $kids, $other );
	}

	public function test_hierarchical_types_are_offered(): void {
		$this->assertArrayHasKey( 'section', Plugin::eligible() );
		$this->assertSame( array( 'section' ), Plugin::sanitize( array( 'section' ) ) );
	}

	public function test_reorders_one_sibling_group_and_leaves_other_parents_alone(): void {
		list( $p, $q, $kids, $other ) = $this->tree();
		$out = $this->call( $this->request( array( $kids[2], $kids[0], $kids[1] ) ) );
		$this->assertTrue( $out['success'] );
		$this->assertSame( array( 1, 2, 0 ), array_map( array( $this, 'menu_order' ), $kids ) );
		$this->assertSame( array( 0, 5, 7 ), array( $this->menu_order( $p ), $this->menu_order( $q ), $this->menu_order( $other ) ) );
	}

	public function test_top_level_group_can_be_reordered(): void {
		list( $p, $q ) = $this->tree();
		$this->assertTrue( $this->call( $this->request( array( $q, $p ) ) )['success'] );
		$this->assertSame( array( 1, 0 ), array( $this->menu_order( $p ), $this->menu_order( $q ) ) );
	}

	public function test_refuses_a_cross_parent_move_on_the_server(): void {
		list( , , $kids, $other ) = $this->tree();
		$out = $this->call( $this->request( array( $kids[1], $other, $kids[0], $kids[2] ) ) );
		$this->assertFalse( $out['success'] );
		$this->assertSame( array( 0, 0, 0, 7 ), array_map( array( $this, 'menu_order' ), array( $kids[0], $kids[1], $kids[2], $other ) ) );
	}

	public function test_refuses_a_partial_sibling_group(): void {
		list( , , $kids ) = $this->tree();
		$out = $this->call( $this->request( array( $kids[1], $kids[0] ) ) );
		$this->assertFalse( $out['success'] );
		$this->assertSame( array( 0, 0, 0 ), array_map( array( $this, 'menu_order' ), $kids ) );
	}

	public function test_refuses_duplicate_ids_that_would_pass_a_count_check(): void {
		list( , , $kids ) = $this->tree();
		$out = $this->call( $this->request( array( $kids[0], $kids[0], $kids[1] ) ) );
		$this->assertFalse( $out['success'] );
		$this->assertSame( array( 0, 0, 0 ), array_map( array( $this, 'menu_order' ), $kids ) );
	}

	public function test_sibling_totals_only_count_statuses_the_list_shows(): void {
		list( $p ) = $this->tree();
		self::factory()->post->create( array( 'post_type' => 'section', 'post_parent' => $p, 'post_status' => 'trash' ) );
		$this->assertSame( 3, Plugin::sibling_totals( 'section' )[ $p ] );
	}

	public function test_sibling_totals_tell_the_list_when_a_group_is_split_across_pages(): void {
		list( $p, $q ) = $this->tree();
		$totals = Plugin::sibling_totals( 'section' );
		$this->assertSame( 3, $totals[ $p ] );
		$this->assertSame( 1, $totals[ $q ] );
		$this->assertSame( 2, $totals[0] );
	}

	public function test_hierarchical_default_sort_is_left_to_core(): void {
		global $pagenow;
		$pagenow = 'edit.php';
		$this->tree();
		$query                   = new \WP_Query();
		$GLOBALS['wp_the_query'] = $query;
		add_action( 'pre_get_posts', array( Plugin::class, 'default_order' ) );
		$query->query( array( 'post_type' => 'section' ) );
		$this->assertEmpty( $query->get( 'orderby' ) );
	}

	public function test_filtered_or_resorted_lists_are_not_plain(): void {
		$this->assertTrue( Plugin::plain_list() );
		foreach ( array( 'orderby' => 'date', 's' => 'x', 'post_status' => 'draft' ) as $key => $value ) {
			$_GET = array( $key => $value );
			$this->assertFalse( Plugin::plain_list(), $key );
		}
	}

	public function test_rejects_a_bad_nonce(): void {
		$id   = self::factory()->post->create( array( 'post_type' => 'book' ) );
		$body = $this->request( array( $id ) );
		$body['nonce'] = 'bad';
		$this->expectException( \WPAjaxDieStopException::class );
		try {
			$this->call( $body );
		} finally {
			$this->assertSame( 0, $this->menu_order( $id ) );
		}
	}

	public function test_rejects_a_user_who_cannot_edit_a_post(): void {
		$ids = self::factory()->post->create_many( 2, array( 'post_type' => 'book' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$out = $this->call( $this->request( array_reverse( $ids ) ) );
		$this->assertFalse( $out['success'] );
		$this->assertSame( array( 0, 0 ), array_map( array( $this, 'menu_order' ), $ids ) );
	}

	public function test_rejects_a_type_that_is_not_enabled(): void {
		$id  = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$out = $this->call( $this->request( array( $id ), 5 ) );
		$this->assertFalse( $out['success'] );
		$this->assertSame( 0, $this->menu_order( $id ) );
	}

	public function test_rejects_mixed_types_and_changes_nothing(): void {
		update_option( Plugin::OPTION, array( 'book', 'post' ) );
		$book = self::factory()->post->create( array( 'post_type' => 'book' ) );
		$post = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$out  = $this->call( $this->request( array( $book, $post ), 9 ) );
		$this->assertFalse( $out['success'] );
		$this->assertSame( 0, $this->menu_order( $book ) );
	}

	public function test_ignores_junk_ids(): void {
		$out = $this->call( $this->request( array( 'abc', '0', '-3x' ) ) );
		$this->assertFalse( $out['success'] );
	}

	public function test_admin_list_sorts_by_menu_order_then_title(): void {
		global $pagenow;
		$pagenow = 'edit.php';
		set_current_screen( 'edit-book' );
		$b = self::factory()->post->create( array( 'post_type' => 'book', 'post_title' => 'B', 'menu_order' => 1 ) );
		$a = self::factory()->post->create( array( 'post_type' => 'book', 'post_title' => 'A', 'menu_order' => 2 ) );
		$c = self::factory()->post->create( array( 'post_type' => 'book', 'post_title' => 'C', 'menu_order' => 1 ) );
		add_action( 'pre_get_posts', array( Plugin::class, 'default_order' ) );
		$query                = new \WP_Query();
		$GLOBALS['wp_the_query'] = $query;
		$query->query( array( 'post_type' => 'book', 'fields' => 'ids' ) );
		$this->assertSame( array( $b, $c, $a ), $query->posts );
	}

	public function test_admin_list_keeps_a_chosen_orderby(): void {
		global $pagenow;
		$pagenow = 'edit.php';
		set_current_screen( 'edit-book' );
		$_GET['orderby'] = 'date';
		$query           = new \WP_Query();
		$GLOBALS['wp_the_query'] = $query;
		add_action( 'pre_get_posts', array( Plugin::class, 'default_order' ) );
		$query->query( array( 'post_type' => 'book' ) );
		$this->assertEmpty( $query->get( 'orderby' ) );
	}
}
