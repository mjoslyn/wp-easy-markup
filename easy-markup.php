<?php
/**
 * Plugin Name: Easy Markup
 * Plugin URI: https://github.com/mjoslyn/wp-easy-markup
 * Description: Comment on a page or on any of its blocks, right on the page. Editors and admins comment as themselves; guests comment through a review link. Comments are listed in the block editor's Comments sidebar and under Markup.
 * Version: 1.5.2
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: mjoslyn
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: easy-markup
 *
 * How it fits together:
 *   inc/store.php     tables (comments, review links) and the comment rules
 *   inc/blocks.php    numbers the page's content blocks as they render (data-em-block="b7") and
 *                     knows the same numbers from the saved content, so a comment names its block
 *   inc/access.php    who may comment: staff (can edit the page) or a guest with a review link
 *   inc/mentions.php  @handle emails between staff
 *   inc/rest.php      the REST API the page script and the editor sidebar use
 *   inc/frontend.php  the page script (comment mode, showing a comment), admin bar link
 *   inc/editor.php    the block editor's Comments sidebar
 *   inc/admin.php     Markup: every comment, by page
 *   inc/snapshots.php block snapshots: store, serve to staff, delete
 *   inc/notify.php    emails for guest comments
 *   inc/settings.php  Settings > Easy Markup: comment mode colors and guest comment emails
 *
 * @package EasyMarkup
 */

defined( 'ABSPATH' ) || exit;

const EASY_MARKUP_VERSION    = '1.5.2';
const EASY_MARKUP_DB_VERSION = '2';
define( 'EASY_MARKUP_FILE', __FILE__ );
define( 'EASY_MARKUP_URL', plugin_dir_url( __FILE__ ) );
define( 'EASY_MARKUP_DIR', __DIR__ );

foreach ( array( 'store', 'blocks', 'access', 'snapshots', 'mentions', 'notify', 'rest', 'settings', 'frontend', 'editor', 'admin' ) as $easy_markup_part ) {
	require_once __DIR__ . '/inc/' . $easy_markup_part . '.php';
}
unset( $easy_markup_part );

register_activation_hook( __FILE__, 'easy_markup_install' );
