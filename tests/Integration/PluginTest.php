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
		update_option( Plugin::OPTION, array( 'book' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		global $pagenow;
		$pagenow = null;
		$_GET    = array();
		set_current_screen( 'front' );
		unregister_post_type( 'book' );
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
		$this->assertSame( array( 'book' ), Plugin::sanitize( array( 'book', 'page', 'bogus', '<b>' ) ) );
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

	public function test_hierarchical_types_are_not_offered(): void {
		$this->assertArrayNotHasKey( 'page', Plugin::eligible() );
		update_option( Plugin::OPTION, array( 'page' ) );
		$this->assertSame( array(), Plugin::enabled() );
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
