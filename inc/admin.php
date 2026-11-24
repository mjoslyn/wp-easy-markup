<?php
/**
 * Markup (admin menu): every comment, by page, open ones first. Filter by page and by open or resolved.
 * Each row: where it is (the block's type and words), the comment, who and when, and View on page,
 * Edit page, Resolve or Reopen, Delete. The menu shows the number of open comments.
 *
 * @package EasyMarkup
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'admin_menu',
	function () {
		$open  = easy_markup_open_count();
		$title = 'Markup' . ( $open ? ' <span class="awaiting-mod">' . (int) $open . '</span>' : '' );
		add_menu_page( 'Markup', $title, 'edit_pages', 'easy-markup', 'easy_markup_admin_page', 'dashicons-format-chat', 26 );
	}
);

add_action(
	'admin_post_easy_markup',
	function () {
		$id  = absint( $_POST['comment'] ?? 0 );
		$row = $id ? easy_markup_get_comment( $id ) : null;
		check_admin_referer( 'easy_markup_' . $id );
		if ( ! $row || ! current_user_can( 'edit_post', (int) $row->post_id ) ) {
			wp_die( 'You can\'t change this comment.', 403 );
		}
		$do = sanitize_key( $_POST['do'] ?? '' );
		if ( 'delete' === $do ) {
			easy_markup_delete_comment( $id );
		} elseif ( in_array( $do, array( 'resolve', 'reopen' ), true ) ) {
			easy_markup_set_status( $id, 'resolve' === $do ? 'resolved' : 'open' );
		}
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url( 'admin.php?page=easy-markup' ) );
		exit;
	}
);

/**
 * The Markup admin page.
 *
 * @return void
 */
function easy_markup_admin_page(): void {
	// Read-only filters for a list (no change is made), so no nonce.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
	$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'open';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	$status = in_array( $status, array( 'open', 'resolved', 'all' ), true ) ? $status : 'open';
	$rows   = array_values(
		array_filter(
			easy_markup_comments( $post_id, 'all' === $status ? '' : $status ),
			fn( $r ) => current_user_can( 'edit_post', (int) $r->post_id )
		)
	);
	$pages  = easy_markup_pages_with_comments();
	$base   = admin_url( 'admin.php?page=easy-markup' );
	?>
	<div class="wrap">
		<h1>Markup</h1>
		<p>Comments left on pages and their blocks, by editors and by guests with a review link. Turn on comment mode from the admin bar on any page (Markup &gt; Comment on this page), or use the Comments sidebar in the editor. Make review links there too.</p>
		<form method="get" style="margin:12px 0">
			<input type="hidden" name="page" value="easy-markup">
			<label>Page
				<select name="post">
					<option value="0">All pages</option>
					<?php foreach ( $pages as $p ) : ?>
						<option value="<?php echo (int) $p->post_id; ?>" <?php selected( $post_id, (int) $p->post_id ); ?>><?php echo esc_html( get_the_title( (int) $p->post_id ) ? get_the_title( (int) $p->post_id ) : '#' . (int) $p->post_id ); ?> (<?php echo (int) $p->open_n; ?> open)</option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>Show
				<select name="status">
					<option value="open" <?php selected( $status, 'open' ); ?>>Open</option>
					<option value="resolved" <?php selected( $status, 'resolved' ); ?>>Resolved</option>
					<option value="all" <?php selected( $status, 'all' ); ?>>All</option>
				</select>
			</label>
			<button class="button">Filter</button>
		</form>
		<?php if ( ! $rows ) : ?>
			<p><?php echo 'open' === $status ? 'No open comments.' : 'No comments.'; ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr><th style="width:16%">Page</th><th style="width:22%">Where</th><th>Comment</th><th style="width:14%">From</th><th style="width:16%">Actions</th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<?php
					$out   = easy_markup_comment_out( $r );
					$nonce = wp_create_nonce( 'easy_markup_' . (int) $r->id );
					?>
					<tr<?php echo 'open' === $r->status ? '' : ' style="opacity:.65"'; ?>>
						<td><a href="<?php echo esc_url( add_query_arg( 'post', (int) $r->post_id, $base ) ); ?>"><?php echo esc_html( get_the_title( (int) $r->post_id ) ); ?></a></td>
						<td><strong><?php echo esc_html( easy_markup_type_label( (string) $r->block_type ) ); ?></strong><?php echo '' !== $r->quote ? '<br><span style="color:#50575e">' . esc_html( mb_substr( $r->quote, 0, 90 ) ) . '</span>' : ''; ?></td>
						<td style="white-space:pre-wrap"><?php echo esc_html( $r->body ); ?><?php echo 'resolved' === $r->status ? '<br><em style="color:#50575e">Resolved ' . esc_html( $out['resolved'] . ( $out['resolved_by'] ? ' by ' . $out['resolved_by'] : '' ) ) . '</em>' : ''; ?></td>
						<td><?php echo esc_html( $r->author ); ?><?php echo $r->link_id ? ' <span style="color:#50575e">(review link)</span>' : ''; ?><br><span style="color:#50575e"><?php echo esc_html( $out['created'] ); ?></span></td>
						<td>
							<a href="<?php echo esc_url( $out['show_url'] ); ?>">View on page</a> |
							<a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $r->post_id . '&action=edit' ) ); ?>">Edit page</a>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px;margin-top:6px">
								<input type="hidden" name="action" value="easy_markup">
								<input type="hidden" name="comment" value="<?php echo (int) $r->id; ?>">
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
								<button class="button button-small" name="do" value="<?php echo 'open' === $r->status ? 'resolve' : 'reopen'; ?>"><?php echo 'open' === $r->status ? 'Resolve' : 'Reopen'; ?></button>
								<button class="button button-small button-link-delete" name="do" value="delete" onclick="return confirm('Delete this comment for good? To keep it but mark it done, use Resolve.');">Delete</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}
