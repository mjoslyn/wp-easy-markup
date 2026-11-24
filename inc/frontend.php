<?php
/**
 * On the page: the comment script for people who may comment, and an admin bar link for staff.
 *
 * ?markup=1 starts comment mode (a review link sends guests there). ?markup-show=<comment id> (staff)
 * scrolls to that comment's block, outlines it and shows the comment.
 *
 * @package EasyMarkup
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! is_singular() ) {
			return;
		}
		$post_id = (int) get_queried_object_id();
		if ( ! $post_id || ! easy_markup_can_comment( $post_id ) ) {
			return;
		}
		$staff = easy_markup_is_staff( $post_id );
		$show  = null;
		// Read-only display switches in the address (nothing changes), so no nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$start = ! empty( $_GET['markup'] );
		if ( $staff && isset( $_GET['markup-show'] ) ) {
			$row = easy_markup_get_comment( absint( $_GET['markup-show'] ) );
			if ( $row && (int) $row->post_id === $post_id ) {
				$show = easy_markup_comment_out( $row );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		wp_enqueue_style( 'easy-markup', EASY_MARKUP_URL . 'assets/markup.css', array(), EASY_MARKUP_VERSION );
		wp_enqueue_script(
			'easy-markup',
			EASY_MARKUP_URL . 'assets/markup.js',
			array(),
			EASY_MARKUP_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_localize_script(
			'easy-markup',
			'easyMarkup',
			array(
				'api'    => esc_url_raw( rest_url( 'easy-markup/v1/' ) ),
				'nonce'  => $staff ? wp_create_nonce( 'wp_rest' ) : '',
				'post'   => $post_id,
				'guest'  => ! $staff,
				'start'  => $start || null !== $show,
				'show'   => $show,
				'people' => $staff ? easy_markup_people_out() : array(),
				'labels' => easy_markup_type_labels(),
			)
		);
	}
);

/** Block type labels for the script ("core/paragraph" => "Paragraph"). */
function easy_markup_type_labels(): array {
	$out = array();
	foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
		$out[ $name ] = easy_markup_type_label( $name );
	}
	return $out;
}

/** Admin bar: "Markup (3)" for staff, to this page's comments; "Comment on this page" turns comment mode on. */
add_action(
	'admin_bar_menu',
	function ( WP_Admin_Bar $bar ) {
		if ( is_admin() || ! is_singular() ) {
			return;
		}
		$post_id = (int) get_queried_object_id();
		if ( ! $post_id || ! easy_markup_is_staff( $post_id ) ) {
			return;
		}
		$open = easy_markup_open_count( $post_id );
		$bar->add_node(
			array(
				'id'    => 'easy-markup',
				'title' => 'Markup' . ( $open ? ' (' . $open . ')' : '' ),
				'href'  => add_query_arg(
					array(
						'page' => 'easy-markup',
						'post' => $post_id,
					),
					admin_url( 'admin.php' )
				),
			)
		);
		$bar->add_node(
			array(
				'parent' => 'easy-markup',
				'id'     => 'easy-markup-on',
				'title'  => 'Comment on this page',
				'href'   => add_query_arg( 'markup', '1', get_permalink( $post_id ) ),
			)
		);
		$bar->add_node(
			array(
				'parent' => 'easy-markup',
				'id'     => 'easy-markup-list',
				'title'  => $open ? sprintf( 'See %d open %s', $open, 1 === $open ? 'comment' : 'comments' ) : 'See comments',
				'href'   => add_query_arg(
					array(
						'page' => 'easy-markup',
						'post' => $post_id,
					),
					admin_url( 'admin.php' )
				),
			)
		);
	},
	90
);
