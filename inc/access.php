<?php
/**
 * Who may comment on a page.
 *
 * Staff: anyone who can edit the page (editors and admins). They comment as themselves.
 * Guests: anyone who opened a review link for the page. Opening /any-page/?markup-link=<token> checks
 * the link (not revoked, not expired, the page published), sets a cookie for that page only, and sends
 * them to the page with comment mode on. The cookie is signed over the link's ID and token, and the
 * link is read again on every comment, so turning a link off ends its guests' access at once.
 *
 * @package EasyMarkup
 */

defined( 'ABSPATH' ) || exit;

const EASY_MARKUP_COOKIE_DAYS = 30;

/**
 * Whether the current user is staff for a page (can edit it).
 *
 * @param int $post_id Page ID.
 * @return bool
 */
function easy_markup_is_staff( int $post_id ): bool {
	return is_user_logged_in() && current_user_can( 'edit_post', $post_id );
}

/**
 * A review link by its token.
 *
 * @param string $token 32 hex characters.
 * @return object|null
 */
function easy_markup_get_link_by_token( string $token ): ?object {
	global $wpdb;
	if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
		return null;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; a link must be read fresh so turning it off works at once.
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token = %s', easy_markup_table( 'links' ), $token ) );
	return $row ? $row : null;
}

/**
 * A review link by its ID.
 *
 * @param int $id Link ID.
 * @return object|null
 */
function easy_markup_get_link( int $id ): ?object {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; a link must be read fresh so turning it off works at once.
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', easy_markup_table( 'links' ), $id ) );
	return $row ? $row : null;
}

/**
 * Whether a link still lets guests in: not turned off, not past its expiry, its page published.
 *
 * @param object|null $link Link row.
 * @return bool
 */
function easy_markup_link_live( ?object $link ): bool {
	if ( ! $link || $link->revoked_at ) {
		return false;
	}
	if ( $link->expires_at && strtotime( $link->expires_at . ' UTC' ) < time() ) {
		return false;
	}
	return 'publish' === get_post_status( (int) $link->post_id );
}

/**
 * The guest cookie's name for a page.
 *
 * @param int $post_id Page ID.
 * @return string
 */
function easy_markup_cookie_name( int $post_id ): string {
	return 'easy_markup_' . $post_id;
}

/**
 * The signature a guest cookie carries for a link.
 *
 * @param object $link Link row.
 * @return string
 */
function easy_markup_sign( object $link ): string {
	return hash_hmac( 'sha256', $link->id . '|' . $link->post_id . '|' . $link->token, wp_salt( 'auth' ) );
}

/**
 * The live review link this browser holds for the page, or null.
 *
 * @param int $post_id Page ID.
 * @return object|null
 */
function easy_markup_guest_link( int $post_id ): ?object {
	$name = easy_markup_cookie_name( $post_id );
	$raw  = isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : '';
	if ( ! preg_match( '/^(\d+)\.([a-f0-9]{64})$/', $raw, $m ) ) {
		return null;
	}
	$link = easy_markup_get_link( (int) $m[1] );
	if ( ! $link || (int) $link->post_id !== $post_id || ! hash_equals( easy_markup_sign( $link ), $m[2] ) || ! easy_markup_link_live( $link ) ) {
		return null;
	}
	return $link;
}

/**
 * Whether the current visitor may comment on a page.
 *
 * @param int $post_id Page ID.
 * @return bool
 */
function easy_markup_can_comment( int $post_id ): bool {
	return easy_markup_is_staff( $post_id ) || null !== easy_markup_guest_link( $post_id );
}

add_action(
	'template_redirect',
	function () {
		// A review link is its own credential (the token); there is no form to carry a nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET['markup-link'] ) ? sanitize_key( wp_unslash( $_GET['markup-link'] ) ) : '';
		if ( '' === $token ) {
			return;
		}
		$link = easy_markup_get_link_by_token( $token );
		if ( ! easy_markup_link_live( $link ) ) {
			status_header( 403 );
			nocache_headers();
			wp_die(
				'<p>' . esc_html__( 'This review link has expired or been turned off.', 'easy-markup' ) . '</p><p>' . esc_html__( 'Ask the person who sent it for a new one.', 'easy-markup' ) . '</p>',
				esc_html__( 'Review link', 'easy-markup' ),
				array( 'response' => 403 )
			);
		}
		$expires = time() + EASY_MARKUP_COOKIE_DAYS * DAY_IN_SECONDS;
		if ( $link->expires_at ) {
			$expires = min( strtotime( $link->expires_at . ' UTC' ), $expires );
		}
		setcookie(
			easy_markup_cookie_name( (int) $link->post_id ),
			$link->id . '.' . easy_markup_sign( $link ),
			array(
				'expires'  => $expires,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		nocache_headers();
		wp_safe_redirect( add_query_arg( 'markup', '1', get_permalink( (int) $link->post_id ) ) );
		exit;
	},
	1
);

/**
 * Make a review link for a page.
 *
 * @param int    $post_id Page ID.
 * @param string $label   Who it's for.
 * @param int    $days    Days it works for (0 = until turned off).
 * @return object|null The link row.
 */
function easy_markup_create_link( int $post_id, string $label, int $days = 0 ): ?object {
	global $wpdb;
	$user = get_current_user_id();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table.
	$ok = $wpdb->insert(
		easy_markup_table( 'links' ),
		array(
			'post_id'    => $post_id,
			'token'      => bin2hex( random_bytes( 16 ) ),
			'label'      => mb_substr( sanitize_text_field( $label ), 0, 100 ),
			'created_by' => $user ? $user : null,
			'created_at' => current_time( 'mysql', true ),
			'expires_at' => $days > 0 ? gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ) : null,
		)
	);
	return $ok ? easy_markup_get_link( (int) $wpdb->insert_id ) : null;
}

/**
 * Turn a review link off.
 *
 * @param int $id Link ID.
 * @return bool
 */
function easy_markup_revoke_link( int $id ): bool {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
	return false !== $wpdb->update( easy_markup_table( 'links' ), array( 'revoked_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) );
}

/**
 * A page's review links, newest first.
 *
 * @param int $post_id Page ID.
 * @return object[]
 */
function easy_markup_links_of( int $post_id ): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d ORDER BY id DESC', easy_markup_table( 'links' ), $post_id ) );
	return $rows ? $rows : array();
}

/**
 * A review link as the editor sidebar shows it.
 *
 * @param object $l Link row.
 * @return array
 */
function easy_markup_link_out( object $l ): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
	$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE link_id = %d', easy_markup_table( 'comments' ), $l->id ) );
	return array(
		'id'       => (int) $l->id,
		'label'    => (string) $l->label,
		'url'      => add_query_arg( 'markup-link', $l->token, get_permalink( (int) $l->post_id ) ),
		'live'     => easy_markup_link_live( $l ),
		'created'  => easy_markup_when( $l->created_at ),
		'expires'  => $l->expires_at ? easy_markup_when( $l->expires_at ) : '',
		'revoked'  => $l->revoked_at ? easy_markup_when( $l->revoked_at ) : '',
		'comments' => $count,
	);
}
