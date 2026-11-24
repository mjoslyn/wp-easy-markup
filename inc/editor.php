<?php
/**
 * The block editor's Comments sidebar (assets/editor.js): the page's comments, open ones first, with
 * Show (selects the block), Resolve, Reopen and Delete; a box to comment on the selected block or the
 * whole page; and the page's review links (make, copy, turn off).
 *
 * @package EasyMarkup
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'enqueue_block_editor_assets',
	function () {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || ! post_type_supports( (string) $screen->post_type, 'editor' ) || ! is_post_type_viewable( (string) $screen->post_type ) ) {
			return; // Not in the site editor or for post types without a page to comment on.
		}
		wp_enqueue_script(
			'easy-markup-editor',
			EASY_MARKUP_URL . 'assets/editor.js',
			array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-block-editor', 'wp-core-data', 'wp-url' ),
			EASY_MARKUP_VERSION,
			true
		);
		wp_localize_script(
			'easy-markup-editor',
			'easyMarkupEditor',
			array(
				'people' => easy_markup_people_out(),
				'labels' => easy_markup_type_labels(),
			)
		);
	}
);
