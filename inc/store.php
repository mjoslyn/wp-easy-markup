<?php
/**
 * Storage and the comment rules.
 *
 * {prefix}easy_markup_comments: one comment on one block of a page (block "b7", its type and a few of
 * its words to show where it was) or on the whole page (block ''), by a staff member (author_id) or a
 * guest with a review link (link_id, the name they gave). Open or resolved.
 * {prefix}easy_markup_links: review links, one page each: a token in the address, optional expiry,
 * revocable.
 *
 * Text is kept plain and shown escaped. Both tables are the plugin's own, so they are read and written
 * with $wpdb directly (WordPress has no API for custom tables); every query is prepared, with table
 * names as %i identifiers.
 *
 * @package EasyMarkup
 */

defined( 'ABSPATH' ) || exit;

const EASY_MARKUP_BODY_MAX       = 2000;
const EASY_MARKUP_NAME_MAX       = 80;
const EASY_MARKUP_QUOTE_MAX      = 120;
const EASY_MARKUP_GUEST_PER_HOUR = 30; // Comments one review link may leave in an hour (all its guests together).

/**
 * A plugin table's full name.
 *
 * @param string $name 'comments' or 'links'.
 * @return string
 */
function easy_markup_table( string $name ): string {
	global $wpdb;
	return $wpdb->prefix . 'easy_markup_' . $name;
}

/**
 * Create or update the tables (on activation, and when the schema version changes).
 *
 * @return void
 */
function easy_markup_install(): void {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();
	dbDelta(
		'CREATE TABLE ' . easy_markup_table( 'comments' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		post_id bigint(20) unsigned NOT NULL,
		link_id bigint(20) unsigned DEFAULT NULL,
		block varchar(32) NOT NULL DEFAULT '',
		block_type varchar(100) NOT NULL DEFAULT '',
		quote varchar(255) NOT NULL DEFAULT '',
		snapshot varchar(64) NOT NULL DEFAULT '',
		body text NOT NULL,
		author varchar(100) NOT NULL DEFAULT '',
		author_id bigint(20) unsigned DEFAULT NULL,
		status varchar(10) NOT NULL DEFAULT 'open',
		resolved_at datetime DEFAULT NULL,
		resolved_by bigint(20) unsigned DEFAULT NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY post_id (post_id,id),
		KEY link_id (link_id,created_at),
		KEY status (status)
	) $charset;"
	);
	dbDelta(
		'CREATE TABLE ' . easy_markup_table( 'links' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		post_id bigint(20) unsigned NOT NULL,
		token char(32) NOT NULL,
		label varchar(100) NOT NULL DEFAULT '',
		created_by bigint(20) unsigned DEFAULT NULL,
		created_at datetime NOT NULL,
		expires_at datetime DEFAULT NULL,
		revoked_at datetime DEFAULT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY token (token),
		KEY post_id (post_id)
	) $charset;"
	);
	update_option( 'easy_markup_db_version', EASY_MARKUP_DB_VERSION, false );
}

add_action(
	'plugins_loaded',
	function () {
		if ( EASY_MARKUP_DB_VERSION !== get_option( 'easy_markup_db_version' ) ) {
			easy_markup_install();
		}
	}
);

/**
 * Why a comment wasn't taken.
 *
 * @param string $code One of empty, long, missing, block, name, limit, access, failed.
 * @return WP_Error
 */
function easy_markup_error( string $code ): WP_Error {
	$text   = array(
		'empty'   => __( 'Write a comment first.', 'easy-markup' ),
		/* translators: %d: the most characters a comment can have. */
		'long'    => sprintf( __( 'A comment can be at most %d characters.', 'easy-markup' ), EASY_MARKUP_BODY_MAX ),
		'missing' => __( 'That page isn\'t available for comments.', 'easy-markup' ),
		'block'   => __( 'That block isn\'t in the saved page. Save the page, then comment.', 'easy-markup' ),
		'name'    => __( 'Add your name, so the editors know who wrote it.', 'easy-markup' ),
		'limit'   => __( 'Too many comments for now. Please wait a while and try again.', 'easy-markup' ),
		'access'  => __( 'You can\'t comment on this page. Open the review link again.', 'easy-markup' ),
		'failed'  => __( 'Your comment couldn\'t be saved. Try again.', 'easy-markup' ),
	);
	$status = array(
		'missing' => 404,
		'limit'   => 429,
		'access'  => 403,
	);
	return new WP_Error( 'easy_markup_' . $code, $text[ $code ] ?? $text['failed'], array( 'status' => $status[ $code ] ?? 400 ) );
}

/**
 * Leave a comment.
 *
 * @param array $c  post, block ('' for the whole page), body, quote (the block's words as shown), name (guests),
 *                  snapshot (a data: URL of the page, when snapshots are on).
 * @param array $by array( 'user' => WP_User ) or array( 'link' => link row ).
 * @return object|WP_Error The comment row, or why it wasn't taken.
 */
function easy_markup_add_comment( array $c, array $by ) {
	global $wpdb;
	$post_id = (int) ( $c['post'] ?? 0 );
	$post    = get_post( $post_id );
	if ( ! $post || 'trash' === $post->post_status ) {
		return easy_markup_error( 'missing' );
	}
	$body = trim( sanitize_textarea_field( (string) ( $c['body'] ?? '' ) ) );
	if ( '' === $body ) {
		return easy_markup_error( 'empty' );
	}
	if ( mb_strlen( $body ) > EASY_MARKUP_BODY_MAX ) {
		return easy_markup_error( 'long' );
	}
	$block  = (string) ( $c['block'] ?? '' );
	$type   = '';
	$blocks = array();
	if ( '' !== $block ) {
		$blocks = easy_markup_blocks_of( $post_id );
		if ( ! isset( $blocks[ $block ] ) ) {
			return easy_markup_error( 'block' );
		}
		$type = $blocks[ $block ]['type'];
	}
	// The words as the page showed them (dynamic blocks such as forms have none in the saved content).
	$quote = mb_substr( trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) ( $c['quote'] ?? '' ) ) ) ), 0, EASY_MARKUP_QUOTE_MAX );
	if ( '' === $quote && '' !== $block ) {
		$quote = $blocks[ $block ]['words'];
	}

	if ( isset( $by['link'] ) ) {
		$author = mb_substr( trim( sanitize_text_field( (string) ( $c['name'] ?? '' ) ) ), 0, EASY_MARKUP_NAME_MAX );
		if ( '' === $author ) {
			return easy_markup_error( 'name' );
		}
		if ( easy_markup_link_comments_last_hour( (int) $by['link']->id ) >= EASY_MARKUP_GUEST_PER_HOUR ) {
			return easy_markup_error( 'limit' );
		}
		$author_id = null;
		$link_id   = (int) $by['link']->id;
	} else {
		$author    = '' !== $by['user']->display_name ? $by['user']->display_name : $by['user']->user_login;
		$author_id = (int) $by['user']->ID;
		$link_id   = null;
	}

	// A picture of the page as it looked (comments left on the page, when snapshots are on).
	$snapshot = '';
	if ( easy_markup_snapshots_on() && ! empty( $c['snapshot'] ) && is_string( $c['snapshot'] ) ) {
		$snapshot = easy_markup_store_snapshot( $c['snapshot'] );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table.
	$ok = $wpdb->insert(
		easy_markup_table( 'comments' ),
		array(
			'post_id'    => $post_id,
			'link_id'    => $link_id,
			'block'      => $block,
			'block_type' => $type,
			'quote'      => $quote,
			'snapshot'   => $snapshot,
			'body'       => $body,
			'author'     => $author,
			'author_id'  => $author_id,
			'status'     => 'open',
			'created_at' => current_time( 'mysql', true ),
		)
	);
	if ( ! $ok ) {
		easy_markup_delete_snapshot( (object) array( 'snapshot' => $snapshot ) );
		return easy_markup_error( 'failed' );
	}
	$id = (int) $wpdb->insert_id;
	// The count and the insert are two statements, so comments sent at the same moment could all pass:
	// the link's comments of the hour up to this one decide, and one past the limit is taken out again.
	if ( $link_id && easy_markup_link_comments_last_hour( $link_id, $id ) > EASY_MARKUP_GUEST_PER_HOUR ) {
		easy_markup_delete_comment( $id );
		return easy_markup_error( 'limit' );
	}
	$row = easy_markup_get_comment( $id );
	/**
	 * After a comment is added.
	 *
	 * @param object $row The comment row.
	 * @param array  $by  Who left it: array( 'user' => WP_User ) or array( 'link' => link row ).
	 */
	do_action( 'easy_markup_comment_added', $row, $by );
	return $row;
}

/**
 * Comments a review link left in the last hour (all its guests together).
 *
 * @param int $link_id The link.
 * @param int $up_to   Count only comments with an ID up to this one (0 = all).
 * @return int
 */
function easy_markup_link_comments_last_hour( int $link_id, int $up_to = 0 ): int {
	global $wpdb;
	$since = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
	$up_to = $up_to ? $up_to : PHP_INT_MAX;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; a rate limit must read the latest rows.
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE link_id = %d AND created_at > %s AND id <= %d', easy_markup_table( 'comments' ), $link_id, $since, $up_to ) );
}

/**
 * One comment.
 *
 * @param int $id Comment ID.
 * @return object|null
 */
function easy_markup_get_comment( int $id ): ?object {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', easy_markup_table( 'comments' ), $id ) );
	return $row ? $row : null;
}

/**
 * Comments, open ones first, then newest first.
 *
 * @param int    $post_id 0 = every page.
 * @param string $status  'open', 'resolved' or '' for both.
 * @param int    $limit   The most to return.
 * @return object[]
 */
function easy_markup_comments( int $post_id = 0, string $status = '', int $limit = 500 ): array {
	global $wpdb;
	$status = in_array( $status, array( 'open', 'resolved' ), true ) ? $status : '';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM %i WHERE ( %d = 0 OR post_id = %d ) AND ( %s = '' OR status = %s ) ORDER BY status = 'open' DESC, id DESC LIMIT %d",
			easy_markup_table( 'comments' ),
			$post_id,
			$post_id,
			$status,
			$status,
			$limit
		)
	);
	return $rows ? $rows : array();
}

/**
 * Open comments on a page, or on every page.
 *
 * @param int $post_id 0 = every page.
 * @return int
 */
function easy_markup_open_count( int $post_id = 0 ): int {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'open' AND ( %d = 0 OR post_id = %d )", easy_markup_table( 'comments' ), $post_id, $post_id ) );
}

/**
 * Open and total comments per page that has any.
 *
 * @return object[] Rows of post_id, n, open_n.
 */
function easy_markup_pages_with_comments(): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, COUNT(*) AS n, SUM(status = 'open') AS open_n FROM %i GROUP BY post_id", easy_markup_table( 'comments' ) ) );
	return $rows ? $rows : array();
}

/**
 * Resolve or reopen a comment.
 *
 * @param int    $id     Comment ID.
 * @param string $status 'resolved' or 'open'.
 * @return bool
 */
function easy_markup_set_status( int $id, string $status ): bool {
	global $wpdb;
	$user = get_current_user_id();
	$data = 'resolved' === $status
		? array(
			'status'      => 'resolved',
			'resolved_at' => current_time( 'mysql', true ),
			'resolved_by' => $user ? $user : null,
		)
		: array(
			'status'      => 'open',
			'resolved_at' => null,
			'resolved_by' => null,
		);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
	return false !== $wpdb->update( easy_markup_table( 'comments' ), $data, array( 'id' => $id ) );
}

/**
 * Delete a comment.
 *
 * @param int $id Comment ID.
 * @return bool
 */
function easy_markup_delete_comment( int $id ): bool {
	global $wpdb;
	$row = easy_markup_get_comment( $id );
	if ( $row ) {
		easy_markup_delete_snapshot( $row );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
	return (bool) $wpdb->delete( easy_markup_table( 'comments' ), array( 'id' => $id ) );
}

/**
 * A comment as the API, the sidebar and the page script show it.
 *
 * @param object $r Comment row.
 * @return array
 */
function easy_markup_comment_out( object $r ): array {
	$resolver = $r->resolved_by ? get_userdata( (int) $r->resolved_by ) : null;
	return array(
		'id'          => (int) $r->id,
		'post'        => (int) $r->post_id,
		'block'       => (string) $r->block,
		'block_type'  => (string) $r->block_type,
		'where'       => easy_markup_where_label( $r ),
		'quote'       => (string) $r->quote,
		'snapshot'    => easy_markup_snapshot_url( $r ),
		'body'        => (string) $r->body,
		'author'      => (string) $r->author,
		'guest'       => (bool) $r->link_id,
		'status'      => (string) $r->status,
		'created_at'  => mysql_to_rfc3339( $r->created_at ),
		'created'     => easy_markup_when( $r->created_at ),
		'resolved'    => $r->resolved_at ? easy_markup_when( $r->resolved_at ) : '',
		'resolved_by' => $resolver ? $resolver->display_name : '',
		'show_url'    => add_query_arg( 'markup-show', (int) $r->id, get_permalink( (int) $r->post_id ) ),
	);
}

/**
 * A block type as people name it: "Paragraph", "Heading", or "Whole page" for none.
 *
 * @param string $type Block name, for example core/paragraph.
 * @return string
 */
function easy_markup_type_label( string $type ): string {
	if ( '' === $type ) {
		return __( 'Whole page', 'easy-markup' );
	}
	$labels = array(
		'core/paragraph' => __( 'Paragraph', 'easy-markup' ),
		'core/heading'   => __( 'Heading', 'easy-markup' ),
		'core/group'     => __( 'Section', 'easy-markup' ),
	);
	if ( isset( $labels[ $type ] ) ) {
		return $labels[ $type ];
	}
	$registered = WP_Block_Type_Registry::get_instance()->get_registered( $type );
	if ( $registered && $registered->title ) {
		return $registered->title;
	}
	$slash = strpos( $type, '/' );
	$short = false === $slash ? $type : substr( $type, $slash + 1 );
	return ucfirst( str_replace( '-', ' ', $short ) );
}

/**
 * Where a comment is: "Paragraph: the first words".
 *
 * @param object $r Comment row.
 * @return string
 */
function easy_markup_where_label( object $r ): string {
	return easy_markup_type_label( (string) $r->block_type ) . ( '' !== (string) $r->quote ? ': ' . $r->quote : '' );
}

/**
 * A stored UTC time in the site's time zone.
 *
 * @param string $utc MySQL datetime in UTC.
 * @return string
 */
function easy_markup_when( string $utc ): string {
	return wp_date( 'M j, Y g:i a', strtotime( $utc . ' UTC' ) );
}
