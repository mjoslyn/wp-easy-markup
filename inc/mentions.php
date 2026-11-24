<?php
/**
 * Mentions between staff.
 *
 * A staff member's comment that names another staff member by handle (an at sign and the handle)
 * emails them, once per comment, never the author. A handle is the part of the person's email address
 * before the at sign, in lower case (jsmith for jsmith@example.org); when two people share one, the
 * later account (by user ID) gets jsmith2, jsmith3 and so on. Staff = users who can edit pages.
 * Guests' comments notify nobody.
 *
 * @package EasyMarkup
 */

defined( 'ABSPATH' ) || exit;

/** Staff who can be mentioned: handle => WP_User. */
function easy_markup_people(): array {
	$users = get_users(
		array(
			'capability' => 'edit_pages',
			'orderby'    => 'ID',
		)
	);
	$out   = array();
	foreach ( $users as $u ) {
		$base = strtolower( (string) preg_replace( '/[^a-z0-9._-]/i', '', strstr( $u->user_email, '@', true ) ) );
		$base = trim( $base, '._-' );
		$base = '' !== $base ? $base : strtolower( $u->user_nicename );
		$h    = $base;
		for ( $n = 2; isset( $out[ $h ] ); $n++ ) {
			$h = $base . $n;
		}
		$out[ $h ] = $u;
	}
	uasort( $out, fn( $x, $y ) => strcasecmp( $x->display_name, $y->display_name ) );
	return $out;
}

/** For the scripts: [ { handle, name } ]. */
function easy_markup_people_out(): array {
	$out = array();
	foreach ( easy_markup_people() as $handle => $u ) {
		$out[] = array(
			'handle' => $handle,
			'name'   => $u->display_name,
		);
	}
	return $out;
}

/**
 * The people a comment's text mentions.
 *
 * @param string $body Comment text.
 * @return WP_User[] Keyed by handle.
 */
function easy_markup_mentioned( string $body ): array {
	if ( ! preg_match_all( '/(?<![\w@.])@([a-z0-9][a-z0-9._-]{0,59})/i', $body, $m ) ) {
		return array();
	}
	$people = easy_markup_people();
	$out    = array();
	foreach ( $m[1] as $h ) {
		$h = strtolower( rtrim( $h, '.-_' ) );
		if ( isset( $people[ $h ] ) ) {
			$out[ $h ] = $people[ $h ];
		}
	}
	return $out;
}

add_action(
	'easy_markup_comment_added',
	function ( $row, $by ) {
		if ( empty( $by['user'] ) ) {
			return;
		}
		$post = get_post( (int) $row->post_id );
		foreach ( easy_markup_mentioned( (string) $row->body ) as $u ) {
			if ( (int) $u->ID === (int) $by['user']->ID || ! $u->user_email ) {
				continue;
			}
			$show = add_query_arg( 'markup-show', (int) $row->id, get_permalink( $post ) );
			$edit = admin_url( 'post.php?post=' . (int) $post->ID . '&action=edit' );
			$html = '<p>' . esc_html( $row->author ) . ' mentioned you in a comment on <strong>' . esc_html( get_the_title( $post ) ) . '</strong>.</p>'
			. '<p style="color:#555f6d;border-left:3px solid #2271b1;padding-left:8px">' . esc_html( easy_markup_where_label( $row ) ) . '</p>'
			. '<p>' . nl2br( esc_html( $row->body ) ) . '</p>'
			. '<p><a href="' . esc_url( $show ) . '">See it on the page</a> or <a href="' . esc_url( $edit ) . '">open the page in the editor</a>.</p>';
			wp_mail(
				$u->user_email,
				sprintf( '%s mentioned you on %s', $row->author, wp_strip_all_tags( get_the_title( $post ) ) ),
				$html,
				array( 'Content-Type: text/html; charset=UTF-8' )
			);
		}
	},
	10,
	2
);
