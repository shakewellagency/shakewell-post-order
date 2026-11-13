<?php
/**
 * Plugin bootstrap, settings screen, admin list ordering and the save endpoint.
 *
 * @package Shakewell\PostOrder
 */

namespace Shakewell\PostOrder;

use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Lets admins drag (or use buttons to move) rows on the list table and stores the result in menu_order.
 */
final class Plugin {

	public const OPTION = 'shakewell_post_order_post_types';
	private const AJAX  = 'shakewell_post_order_save';
	private const COL   = 'shakewell_post_order';
	private const MAX   = 500;

	/**
	 * Registers hooks. Called on plugins_loaded.
	 */
	public static function boot(): void {
		add_action( 'admin_init', array( self::class, 'register_setting' ) );
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'wp_ajax_' . self::AJAX, array( self::class, 'save' ) );
		add_action( 'pre_get_posts', array( self::class, 'default_order' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_init', array( self::class, 'columns' ) );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
	}

	/**
	 * Post types that can be ordered, as objects keyed by name. Hierarchical types are left out: their list
	 * is paged in PHP and grouped by parent, so a flat page order cannot describe it.
	 *
	 * @return \WP_Post_Type[]
	 */
	public static function eligible(): array {
		return get_post_types(
			array(
				'public'       => true,
				'show_ui'      => true,
				'hierarchical' => false,
			),
			'objects'
		);
	}

	/**
	 * The post types saved on the settings screen.
	 *
	 * @return string[]
	 */
	public static function saved(): array {
		return self::sanitize( get_option( self::OPTION, array() ) );
	}

	/**
	 * Enabled post types: the saved option, overridable by a filter, limited to eligible types.
	 *
	 * @return string[]
	 */
	public static function enabled(): array {
		$types = (array) apply_filters( 'shakewell_post_order_post_types', self::saved() );
		return array_values( array_intersect( array_unique( array_filter( $types, 'is_string' ) ), array_keys( self::eligible() ) ) );
	}

	/**
	 * Whether the current list is the plain, unfiltered, default-sorted one. Dragging a filtered or re-sorted
	 * view would renumber rows that are hidden or shown in another order.
	 */
	public static function plain_list(): bool {
		$keys = array( 'orderby', 'order', 's', 'author', 'm', 'post_parent' );
		foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $taxonomy ) {
			$keys[] = $taxonomy->query_var ? $taxonomy->query_var : $taxonomy->name;
		}
		foreach ( $keys as $key ) {
			if ( ! empty( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return false;
			}
		}
		return empty( $_GET['post_status'] ) || 'all' === $_GET['post_status']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Keeps only known post type slugs.
	 *
	 * @param mixed $value Submitted value.
	 * @return string[]
	 */
	public static function sanitize( $value ): array {
		return array_values( array_intersect( array_map( 'sanitize_key', (array) $value ), array_keys( self::eligible() ) ) );
	}

	/**
	 * Registers the option.
	 */
	public static function register_setting(): void {
		register_setting(
			'shakewell_post_order',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Adds Settings > Post order.
	 */
	public static function menu(): void {
		add_options_page( __( 'Post order', 'shakewell-post-order' ), __( 'Post order', 'shakewell-post-order' ), 'manage_options', 'shakewell-post-order', array( self::class, 'screen' ) );
	}

	/**
	 * Settings screen: one checkbox per post type.
	 */
	public static function screen(): void {
		$types   = self::eligible();
		$enabled = self::saved();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Post order', 'shakewell-post-order' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'shakewell_post_order' ); ?>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Post types with drag-and-drop ordering', 'shakewell-post-order' ); ?></legend>
					<p><?php esc_html_e( 'Choose the post types you can reorder from their list screen. The order is saved in each item\'s menu_order field. Pages and other hierarchical types are not offered.', 'shakewell-post-order' ); ?></p>
					<?php if ( self::enabled() !== $enabled ) : ?>
						<p><strong><?php esc_html_e( 'The shakewell_post_order_post_types filter changes the post types in use, so the choice below may not apply.', 'shakewell-post-order' ); ?></strong></p>
					<?php endif; ?>
					<?php foreach ( $types as $type ) : ?>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, $enabled, true ) ); ?> />
							<?php echo esc_html( $type->labels->name ); ?>
						</label><br />
					<?php endforeach; ?>
				</fieldset>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Sorts an enabled type's admin list by menu_order, then title, unless the user picked a column.
	 *
	 * @param WP_Query $query The query.
	 */
	public static function default_order( WP_Query $query ): void {
		global $pagenow;
		if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() || ! empty( $_GET['orderby'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$type = $query->get( 'post_type' );
		if ( is_string( $type ) && in_array( $type, self::enabled(), true ) ) {
			$query->set(
				'orderby',
				array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				)
			);
		}
	}

	/**
	 * Explains why ordering is off on a filtered or re-sorted list.
	 */
	public static function notice(): void {
		$screen = get_current_screen();
		if ( $screen && 'edit' === $screen->base && in_array( $screen->post_type, self::enabled(), true ) && ! self::plain_list() ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'Reordering is available on the unfiltered list, sorted by the default order.', 'shakewell-post-order' ) . '</p></div>';
		}
	}

	/**
	 * Adds the move-buttons column to each enabled type.
	 */
	public static function columns(): void {
		if ( ! self::plain_list() ) {
			return;
		}
		foreach ( self::enabled() as $type ) {
			add_filter(
				"manage_{$type}_posts_columns",
				static function ( array $cols ): array {
					$cols[ self::COL ] = __( 'Order', 'shakewell-post-order' );
					return $cols;
				}
			);
			add_action( "manage_{$type}_posts_custom_column", array( self::class, 'cell' ), 10, 2 );
		}
	}

	/**
	 * Renders the Move up / Move down buttons.
	 *
	 * @param string $column Column key.
	 * @param int    $id     Post ID.
	 */
	public static function cell( string $column, int $id ): void {
		if ( self::COL !== $column ) {
			return;
		}
		$title = get_the_title( $id );
		printf(
			'<button type="button" class="button-link shakewell-post-order-move" data-dir="-1">%1$s</button> <button type="button" class="button-link shakewell-post-order-move" data-dir="1">%2$s</button>',
			/* translators: %s: post title */
			esc_html( sprintf( __( 'Move up: %s', 'shakewell-post-order' ), $title ) ),
			/* translators: %s: post title */
			esc_html( sprintf( __( 'Move down: %s', 'shakewell-post-order' ), $title ) )
		);
	}

	/**
	 * Loads the script on the list screen of an enabled type.
	 *
	 * @param string $hook Admin page hook.
	 */
	public static function enqueue( string $hook ): void {
		global $wp_query;
		$screen = get_current_screen();
		if ( 'edit.php' !== $hook || ! $screen || ! in_array( $screen->post_type, self::enabled(), true ) || ! self::plain_list() ) {
			return;
		}
		$per_page = $wp_query instanceof WP_Query ? (int) $wp_query->get( 'posts_per_page' ) : 0;
		$paged    = $wp_query instanceof WP_Query ? max( 1, (int) $wp_query->get( 'paged' ) ) : 1;
		wp_enqueue_script( 'shakewell-post-order', plugins_url( 'assets/order.js', FILE ), array( 'jquery-ui-sortable', 'wp-a11y' ), VERSION, true );
		wp_localize_script(
			'shakewell-post-order',
			'shakewellPostOrder',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX,
				'nonce'   => wp_create_nonce( self::AJAX ),
				'offset'  => max( 0, $per_page ) * ( $paged - 1 ),
				'saved'   => __( 'Order saved.', 'shakewell-post-order' ),
				'failed'  => __( 'The order could not be saved. Reload the page and try again.', 'shakewell-post-order' ),
				/* translators: %d: new position in the list */
				'moved'   => __( 'Moved to position %d.', 'shakewell-post-order' ),
			)
		);
		wp_add_inline_style( 'common', '.column-' . self::COL . '{width:9em}.shakewell-post-order-move{display:block}#the-list tr{cursor:move}' );
	}

	/**
	 * AJAX: stores the submitted order of one page of one post type.
	 */
	public static function save(): void {
		check_ajax_referer( self::AJAX, 'nonce' );

		$raw    = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value goes through absint() below.
		$ids    = array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		if ( ! $ids || count( $ids ) > self::MAX ) {
			wp_send_json_error( null, 400 );
		}

		$types = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || ! current_user_can( 'edit_post', $id ) ) {
				wp_send_json_error( null, 403 );
			}
			$types[ $post->post_type ] = true;
		}
		$type = array_key_first( $types );
		if ( count( $types ) > 1 || ! in_array( $type, self::enabled(), true ) ) {
			wp_send_json_error( null, 400 );
		}

		// Renumber the whole type so pages stay disjoint: posts before this page, this page, then the rest.
		// ponytail: loads every ID and menu_order of the type; fine for thousands, batch it for far more.
		global $wpdb;
		$rest    = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT ID, menu_order FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash', 'auto-draft') ORDER BY menu_order ASC, post_title ASC, ID ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$type
			),
			OBJECT_K
		);
		$current = wp_list_pluck( $rest, 'menu_order' );
		$others  = array_keys( array_diff_key( $rest, array_flip( $ids ) ) );
		$order   = array_merge( array_slice( $others, 0, $offset ), $ids, array_slice( $others, $offset ) );
		foreach ( $order as $position => $id ) {
			if ( ! isset( $current[ $id ] ) || (int) $current[ $id ] !== $position ) {
				$wpdb->update( $wpdb->posts, array( 'menu_order' => $position ), array( 'ID' => $id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- menu_order only; wp_update_post would create revisions and fire save hooks for every row.
				clean_post_cache( $id );
			}
		}
		wp_send_json_success();
	}
}
