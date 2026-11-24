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
		// First submenu item names the list itself; Settings (inc/settings.php) follows it.
		add_submenu_page( 'easy-markup', 'Markup', __( 'Comments', 'easy-markup' ), 'edit_pages', 'easy-markup', 'easy_markup_admin_page' );
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

add_action(
	'admin_post_easy_markup_link',
	function () {
		check_admin_referer( 'easy_markup_link' );
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		if ( 'create' === $do ) {
			if ( ! current_user_can( EASY_MARKUP_SITE_CAP ) ) {
				wp_die( esc_html__( 'You can\'t make site links.', 'easy-markup' ), 403 );
			}
			$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
			$days  = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 30;
			easy_markup_create_link( 0, $label, min( 365, $days ) );
		} elseif ( 'revoke' === $do ) {
			$link = easy_markup_get_link( isset( $_POST['link'] ) ? absint( $_POST['link'] ) : 0 );
			$may  = $link && ( easy_markup_link_is_site( $link ) ? current_user_can( EASY_MARKUP_SITE_CAP ) : current_user_can( 'edit_post', (int) $link->post_id ) );
			if ( ! $may ) {
				wp_die( esc_html__( 'You can\'t turn this link off.', 'easy-markup' ), 403 );
			}
			easy_markup_revoke_link( (int) $link->id );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=easy-markup#review-links' ) );
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
						<td style="white-space:pre-wrap"><?php echo esc_html( $r->body ); ?><?php echo '' !== $out['snapshot'] ? '<br><a href="' . esc_url( $out['snapshot'] ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $out['snapshot'] ) . '" alt="' . esc_attr__( 'Snapshot of the page', 'easy-markup' ) . '" loading="lazy" style="max-width:220px;max-height:120px;margin-top:6px;border:1px solid #dcdcde;border-radius:4px"></a>' : ''; ?><?php echo 'resolved' === $r->status ? '<br><em style="color:#50575e">Resolved ' . esc_html( $out['resolved'] . ( $out['resolved_by'] ? ' by ' . $out['resolved_by'] : '' ) ) . '</em>' : ''; ?></td>
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

		<h2 id="review-links"><?php esc_html_e( 'Review links', 'easy-markup' ); ?></h2>
		<p><?php esc_html_e( 'Anyone with a review link can comment after giving their name. A page link works on its page; a whole-site link works on every published page. Make page links in the editor\'s Comments sidebar.', 'easy-markup' ); ?></p>
		<?php if ( current_user_can( EASY_MARKUP_SITE_CAP ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0">
				<input type="hidden" name="action" value="easy_markup_link">
				<input type="hidden" name="do" value="create">
				<?php wp_nonce_field( 'easy_markup_link' ); ?>
				<label><?php esc_html_e( 'Who it\'s for (optional)', 'easy-markup' ); ?> <input type="text" name="label" class="regular-text"></label>
				<label><?php esc_html_e( 'Works for', 'easy-markup' ); ?>
					<select name="days">
						<option value="7"><?php esc_html_e( '7 days', 'easy-markup' ); ?></option>
						<option value="30" selected><?php esc_html_e( '30 days', 'easy-markup' ); ?></option>
						<option value="90"><?php esc_html_e( '90 days', 'easy-markup' ); ?></option>
						<option value="0"><?php esc_html_e( 'Until turned off', 'easy-markup' ); ?></option>
					</select>
				</label>
				<button class="button button-primary"><?php esc_html_e( 'Make a whole-site link', 'easy-markup' ); ?></button>
			</form>
		<?php endif; ?>
		<?php
		$links = array_values(
			array_filter(
				easy_markup_links_of( 0 ),
				fn( $l ) => easy_markup_link_is_site( $l ) ? current_user_can( EASY_MARKUP_SITE_CAP ) : current_user_can( 'edit_post', (int) $l->post_id )
			)
		);
		?>
		<?php if ( ! $links ) : ?>
			<p><?php esc_html_e( 'No review links yet.', 'easy-markup' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr><th><?php esc_html_e( 'For', 'easy-markup' ); ?></th><th><?php esc_html_e( 'Works on', 'easy-markup' ); ?></th><th style="width:30%"><?php esc_html_e( 'Link', 'easy-markup' ); ?></th><th><?php esc_html_e( 'Status', 'easy-markup' ); ?></th><th style="width:9%"><?php esc_html_e( 'Comments', 'easy-markup' ); ?></th><th style="width:10%"></th></tr></thead>
				<tbody>
				<?php foreach ( $links as $l ) : ?>
					<?php $lo = easy_markup_link_out( $l ); ?>
					<tr<?php echo $lo['live'] ? '' : ' style="opacity:.6"'; ?>>
						<td><?php echo esc_html( '' !== $lo['label'] ? $lo['label'] : __( 'Review link', 'easy-markup' ) ); ?></td>
						<td><?php echo $lo['site'] ? '<strong>' . esc_html__( 'Whole site', 'easy-markup' ) . '</strong>' : esc_html( $lo['page'] ); ?></td>
						<td>
						<?php
						if ( $lo['live'] ) :
							?>
							<input type="text" readonly class="large-text code" value="<?php echo esc_attr( $lo['url'] ); ?>" onclick="this.select()"><?php endif; ?></td>
						<td>
							<?php
							if ( $lo['live'] ) {
								/* translators: 1: when made, 2: when it stops working. */
								echo esc_html( $lo['expires'] ? sprintf( __( 'Made %1$s, works until %2$s', 'easy-markup' ), $lo['created'], $lo['expires'] ) : sprintf( __( 'Made %s, no expiry', 'easy-markup' ), $lo['created'] ) );
							} else {
								echo esc_html( $lo['revoked'] ? __( 'Turned off ', 'easy-markup' ) . $lo['revoked'] : __( 'Expired ', 'easy-markup' ) . $lo['expires'] );
							}
							?>
						</td>
						<td><?php echo (int) $lo['comments']; ?></td>
						<td>
							<?php if ( $lo['live'] ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="easy_markup_link">
									<input type="hidden" name="do" value="revoke">
									<input type="hidden" name="link" value="<?php echo (int) $l->id; ?>">
									<?php wp_nonce_field( 'easy_markup_link' ); ?>
									<button class="button button-small button-link-delete"><?php esc_html_e( 'Turn off', 'easy-markup' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}
