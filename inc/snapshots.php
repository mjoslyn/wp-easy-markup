<?php
/**
 * Page snapshots: a picture of the whole page, with the commented block outlined, as it looked when
 * someone commented on the page.
 *
 * The page script captures the page (html2canvas, bundled in assets/vendor) when the comment box opens,
 * scaled to fit 1600 x 8000, and sends it with the comment as a data: URL. Here it is checked (PNG, JPEG or WebP, at most
 * EASY_MARKUP_SNAPSHOT_MAX_BYTES, at most 12000px a side), then re-encoded as a JPEG at most 1600px wide, which
 * also drops anything hidden in the file. It is stored under uploads/easy-markup-snapshots with a
 * random name and is never linked directly: staff view it through admin-post.php, which checks they
 * can edit the comment's page. Deleting a comment deletes its snapshot.
 *
 * Markup > Settings turns snapshots off (they are on by default).
 *
 * @package EasyMarkup
 */

defined( 'ABSPATH' ) || exit;

const EASY_MARKUP_SNAPSHOT_MAX_BYTES = 6 * MB_IN_BYTES;
const EASY_MARKUP_SNAPSHOT_MAX_WIDTH = 1600;

/**
 * Whether block snapshots are on.
 *
 * @return bool
 */
function easy_markup_snapshots_on(): bool {
	return '1' === (string) get_option( 'easy_markup_snapshots', '1' );
}

/**
 * The snapshot folder, made on first use with files that keep it from being listed or served
 * where the server reads them.
 *
 * @return string Absolute path, no trailing slash, or '' if it can't be made.
 */
function easy_markup_snapshot_dir(): string {
	$uploads = wp_upload_dir( null, false );
	if ( ! empty( $uploads['error'] ) ) {
		return '';
	}
	$dir = $uploads['basedir'] . '/easy-markup-snapshots';
	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return '';
	}
	if ( ! file_exists( $dir . '/index.php' ) ) {
		file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A fixed guard file in the plugin's own folder.
		file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A fixed guard file in the plugin's own folder.
	}
	return $dir;
}

/**
 * Check, re-encode and store a snapshot sent as a data: URL.
 *
 * @param string $data_url data:image/(png|jpeg|webp);base64,...
 * @return string The stored file name, or '' if it wasn't a usable image.
 */
function easy_markup_store_snapshot( string $data_url ): string {
	if ( ! preg_match( '#^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/=\s]+)$#', $data_url, $m ) ) {
		return '';
	}
	$bytes = base64_decode( $m[2], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding an uploaded image.
	if ( false === $bytes || strlen( $bytes ) > EASY_MARKUP_SNAPSHOT_MAX_BYTES ) {
		return '';
	}
	$info = getimagesizefromstring( $bytes );
	if ( ! $info || ! in_array( $info[2], array( IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP ), true ) || $info[0] < 1 || $info[1] < 1 || $info[0] > 12000 || $info[1] > 12000 ) {
		return '';
	}
	$dir = easy_markup_snapshot_dir();
	if ( '' === $dir ) {
		return '';
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$tmp = wp_tempnam( 'easy-markup' );
	file_put_contents( $tmp, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A temp file for the image editor.
	$name   = bin2hex( random_bytes( 16 ) ) . '.jpg';
	$editor = wp_get_image_editor( $tmp );
	$saved  = false;
	if ( ! is_wp_error( $editor ) ) {
		$size = $editor->get_size();
		if ( $size['width'] > EASY_MARKUP_SNAPSHOT_MAX_WIDTH ) {
			$editor->resize( EASY_MARKUP_SNAPSHOT_MAX_WIDTH, null, false );
		}
		$editor->set_quality( 82 );
		$saved = ! is_wp_error( $editor->save( $dir . '/' . $name, 'image/jpeg' ) );
	}
	wp_delete_file( $tmp );
	return $saved ? $name : '';
}

/**
 * The staff-only address of a comment's snapshot, or '' if it has none.
 *
 * @param object $r Comment row.
 * @return string
 */
function easy_markup_snapshot_url( object $r ): string {
	if ( empty( $r->snapshot ) ) {
		return '';
	}
	return add_query_arg(
		array(
			'action'  => 'easy_markup_snapshot',
			'comment' => (int) $r->id,
		),
		admin_url( 'admin-post.php' )
	);
}

/**
 * Delete a comment's snapshot file.
 *
 * @param object $r Comment row.
 * @return void
 */
function easy_markup_delete_snapshot( object $r ): void {
	if ( empty( $r->snapshot ) || ! preg_match( '/^[a-f0-9]{32}\.jpg$/', (string) $r->snapshot ) ) {
		return;
	}
	$dir = easy_markup_snapshot_dir();
	if ( '' !== $dir && file_exists( $dir . '/' . $r->snapshot ) ) {
		wp_delete_file( $dir . '/' . $r->snapshot );
	}
}

// Serve a snapshot to staff who can edit its page. Signed-in users only (no nopriv handler).
add_action(
	'admin_post_easy_markup_snapshot',
	function () {
		// A read of one image for a signed-in user, checked by permission below; no nonce in an <img>.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id  = isset( $_GET['comment'] ) ? absint( $_GET['comment'] ) : 0;
		$row = $id ? easy_markup_get_comment( $id ) : null;
		if ( ! $row || ! current_user_can( 'edit_post', (int) $row->post_id ) || ! preg_match( '/^[a-f0-9]{32}\.jpg$/', (string) $row->snapshot ) ) {
			status_header( 404 );
			exit;
		}
		$file = easy_markup_snapshot_dir() . '/' . $row->snapshot;
		if ( ! is_readable( $file ) ) {
			status_header( 404 );
			exit;
		}
		header( 'Content-Type: image/jpeg' );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'Content-Disposition: inline; filename="comment-' . (int) $row->id . '-page.jpg"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, max-age=3600' );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a stored image to an authorised user.
		exit;
	}
);
