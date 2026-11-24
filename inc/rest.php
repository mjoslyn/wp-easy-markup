<?php
/**
 * REST API (namespace easy-markup/v1). Staff calls carry the usual wp_rest nonce; a guest's comment is
 * allowed by their review-link cookie for that page (easy_markup_guest_link).
 *
 *   POST   /comments                 add (staff or guest): post, block, quote, body, name (guests)
 *   GET    /comments?post=ID         a page's comments (staff)
 *   POST   /comments/<id>            action: resolve | reopen (staff)
 *   DELETE /comments/<id>            delete (staff)
 *   GET    /links?post=ID            a page's review links (staff)
 *   POST   /links                    make one: post, label, days (0 = no expiry) (staff)
 *   DELETE /links/<id>               turn one off (staff)
 *
 * @package EasyMarkup
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'rest_api_init',
	function () {
		$ns    = 'easy-markup/v1';
		$staff = function ( WP_REST_Request $r ) {
			$post_id = (int) $r['post'];
			if ( ! $post_id && $r['id'] ) {
				$row     = 'links' === explode( '/', trim( $r->get_route(), '/' ) )[2] ? easy_markup_get_link( (int) $r['id'] ) : easy_markup_get_comment( (int) $r['id'] );
				$post_id = $row ? (int) $row->post_id : 0;
			}
			return $post_id && easy_markup_is_staff( $post_id );
		};

		register_rest_route(
			$ns,
			'/comments',
			array(
				array(
					'methods'             => 'POST',
					'permission_callback' => function ( WP_REST_Request $r ) {
						$post_id = (int) $r['post'];
						return $post_id && easy_markup_can_comment( $post_id ) ? true : easy_markup_error( 'access' );
					},
					'callback'            => function ( WP_REST_Request $r ) {
						$post_id = (int) $r['post'];
						$by      = easy_markup_is_staff( $post_id ) ? array( 'user' => wp_get_current_user() ) : array( 'link' => easy_markup_guest_link( $post_id ) );
						$row     = easy_markup_add_comment(
							array(
								'post'  => $post_id,
								'block' => (string) $r['block'],
								'quote' => (string) $r['quote'],
								'body'  => (string) $r['body'],
								'name'  => (string) $r['name'],
							),
							$by
						);
						return is_wp_error( $row ) ? $row : rest_ensure_response(
							array(
								'ok'      => true,
								'comment' => easy_markup_comment_out( $row ),
							)
						);
					},
				),
				array(
					'methods'             => 'GET',
					'permission_callback' => $staff,
					'callback'            => function ( WP_REST_Request $r ) {
						return rest_ensure_response( array_map( 'easy_markup_comment_out', easy_markup_comments( (int) $r['post'] ) ) );
					},
				),
			)
		);

		register_rest_route(
			$ns,
			'/comments/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'POST',
					'permission_callback' => $staff,
					'callback'            => function ( WP_REST_Request $r ) {
						$action = (string) $r['action'];
						if ( ! in_array( $action, array( 'resolve', 'reopen' ), true ) ) {
							return new WP_Error( 'easy_markup_action', 'Unknown action.', array( 'status' => 400 ) );
						}
						easy_markup_set_status( (int) $r['id'], 'resolve' === $action ? 'resolved' : 'open' );
						return rest_ensure_response( easy_markup_comment_out( easy_markup_get_comment( (int) $r['id'] ) ) );
					},
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => $staff,
					'callback'            => function ( WP_REST_Request $r ) {
						return rest_ensure_response( array( 'deleted' => easy_markup_delete_comment( (int) $r['id'] ) ) );
					},
				),
			)
		);

		register_rest_route(
			$ns,
			'/links',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $staff,
					'callback'            => function ( WP_REST_Request $r ) {
						return rest_ensure_response( array_map( 'easy_markup_link_out', easy_markup_links_of( (int) $r['post'] ) ) );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $staff,
					'callback'            => function ( WP_REST_Request $r ) {
							$post_id = (int) $r['post'];
						if ( 'publish' !== get_post_status( $post_id ) ) {
							return new WP_Error( 'easy_markup_unpublished', 'Publish the page first. Review links open published pages.', array( 'status' => 400 ) );
						}
							$link = easy_markup_create_link( $post_id, (string) $r['label'], max( 0, min( 365, (int) $r['days'] ) ) );
							return $link ? rest_ensure_response( easy_markup_link_out( $link ) ) : new WP_Error( 'easy_markup_link', 'The link couldn\'t be made.', array( 'status' => 500 ) );
					},
				),
			)
		);

		register_rest_route(
			$ns,
			'/links/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'permission_callback' => $staff,
				'callback'            => function ( WP_REST_Request $r ) {
					easy_markup_revoke_link( (int) $r['id'] );
					return rest_ensure_response( easy_markup_link_out( easy_markup_get_link( (int) $r['id'] ) ) );
				},
			)
		);
	}
);
