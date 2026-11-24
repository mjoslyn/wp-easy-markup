<?php
/**
 * Emails for guest comments (Settings > Easy Markup > Guest comment emails).
 *
 * When it's on, every comment left through a review link emails the addresses in the list: the page,
 * the block and its first words, the comment, the guest's name, the review link they used, and links
 * to the comment on the page and to the Markup list. Staff comments don't send these; staff use
 * mentions instead.
 *
 * @package EasyMarkup
 */

defined( 'ABSPATH' ) || exit;

/** The most addresses kept. */
const EASY_MARKUP_NOTIFY_MAX = 20;

/**
 * The guest comment email settings.
 *
 * @return array guests (bool), emails (string[]).
 */
function easy_markup_notify_settings(): array {
	$saved = get_option( 'easy_markup_notify', array() );
	$saved = is_array( $saved ) ? $saved : array();
	return array(
		'guests' => ! empty( $saved['guests'] ),
		'emails' => easy_markup_clean_emails( $saved['emails'] ?? array() ),
	);
}

/**
 * Valid, unique email addresses from a list or from text with commas, spaces or new lines.
 *
 * @param mixed $input A string or an array of strings.
 * @return string[]
 */
function easy_markup_clean_emails( $input ): array {
	$parts = is_array( $input ) ? $input : preg_split( '/[\s,;]+/', (string) $input );
	$out   = array();
	foreach ( (array) $parts as $part ) {
		$email = sanitize_email( trim( (string) $part ) );
		if ( $email && is_email( $email ) ) {
			$out[ strtolower( $email ) ] = $email;
		}
	}
	return array_slice( array_values( $out ), 0, EASY_MARKUP_NOTIFY_MAX );
}

/**
 * Keep the switch and the valid addresses; say which entries were dropped.
 *
 * @param mixed $input The submitted settings.
 * @return array
 */
function easy_markup_sanitize_notify( $input ): array {
	$input = is_array( $input ) ? $input : array();
	$raw   = $input['emails'] ?? '';
	// WordPress runs this twice on an option's first save (update_option, then add_option), the
	// second time with the already-clean list, so take a list as it is and check only typed text.
	$emails = easy_markup_clean_emails( $raw );
	$typed  = is_array( $raw ) ? array() : array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', (string) $raw ) ) );
	$bad    = array_diff( array_map( 'strtolower', $typed ), array_map( 'strtolower', $emails ) );
	if ( $bad ) {
		add_settings_error(
			'easy_markup_notify',
			'easy_markup_bad_emails',
			/* translators: %s: the entries that aren't email addresses. */
			sprintf( __( 'These aren\'t email addresses and were left out: %s', 'easy-markup' ), implode( ', ', $bad ) )
		);
	}
	$guests = ! empty( $input['guests'] );
	if ( $guests && ! $emails ) {
		add_settings_error( 'easy_markup_notify', 'easy_markup_no_emails', __( 'Add at least one email address, or guest comment emails stay off.', 'easy-markup' ) );
		$guests = false;
	}
	return array(
		'guests' => $guests,
		'emails' => $emails,
	);
}

add_action(
	'easy_markup_comment_added',
	function ( $row, $by ) {
		if ( empty( $by['link'] ) || ! $row ) {
			return;
		}
		$settings = easy_markup_notify_settings();
		if ( ! $settings['guests'] || ! $settings['emails'] ) {
			return;
		}
		$post  = get_post( (int) $row->post_id );
		$title = wp_strip_all_tags( get_the_title( $post ) );
		$link  = $by['link'];
		$which = easy_markup_link_is_site( $link ) ? __( 'a whole-site review link', 'easy-markup' ) : __( 'a review link for this page', 'easy-markup' );
		if ( '' !== (string) $link->label ) {
			$which .= ' (' . $link->label . ')';
		}
		$show = add_query_arg( 'markup-show', (int) $row->id, get_permalink( $post ) );
		$list = add_query_arg(
			array(
				'page' => 'easy-markup',
				'post' => (int) $row->post_id,
			),
			admin_url( 'admin.php' )
		);
		$html = '<p>' . sprintf(
			/* translators: 1: guest's name, 2: page title, 3: which review link. */
			esc_html__( '%1$s commented on %2$s through %3$s.', 'easy-markup' ),
			'<strong>' . esc_html( $row->author ) . '</strong>',
			'<strong>' . esc_html( $title ) . '</strong>',
			esc_html( $which )
		) . '</p>'
			. '<p style="color:#50575e;border-left:3px solid #2271b1;padding-left:8px">' . esc_html( easy_markup_where_label( $row ) ) . '</p>'
			. '<p>' . nl2br( esc_html( $row->body ) ) . '</p>'
			. ( '' !== (string) $row->snapshot ? '<p><a href="' . esc_url( easy_markup_snapshot_url( $row ) ) . '">' . esc_html__( 'See the snapshot of the page (sign-in needed)', 'easy-markup' ) . '</a></p>' : '' )
			. '<p><a href="' . esc_url( $show ) . '">' . esc_html__( 'See it on the page', 'easy-markup' ) . '</a> · <a href="' . esc_url( $list ) . '">' . esc_html__( 'All comments on this page', 'easy-markup' ) . '</a></p>';
		wp_mail(
			$settings['emails'],
			/* translators: 1: guest's name, 2: page title. */
			sprintf( __( 'New comment from %1$s on %2$s', 'easy-markup' ), $row->author, $title ),
			$html,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	},
	10,
	2
);
