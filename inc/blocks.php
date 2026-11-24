<?php
/**
 * Which block a comment is on.
 *
 * WordPress blocks have no saved IDs, so a block's ID is its place in the page content: b1, b2, ...
 * counting every block (inner blocks too) in order, as parse_blocks() reads the saved content. When a
 * person who may comment views the page, the page content's blocks are marked as they render
 * (data-em-block="b7" data-em-type="core/paragraph" on the block's outer tag), with the same numbers.
 * A comment also keeps the block's first words, so a comment can still find its block after edits
 * have moved the numbers (the page script and the editor sidebar check the words).
 *
 * Only the page's own content is numbered: not the header, footer or template, and not the inside of a
 * synced pattern (those render from their own content). A rendered block is matched to the next saved
 * block of the same name; one that doesn't match (a synced pattern's inside) is skipped.
 *
 * @package EasyMarkup
 */

defined( 'ABSPATH' ) || exit;

/**
 * A block's own words, from its saved markup (inner blocks not included).
 *
 * @param array $block Parsed block.
 * @return string
 */
function easy_markup_block_words( array $block ): string {
	$html = implode( ' ', array_filter( (array) ( $block['innerContent'] ?? array() ), 'is_string' ) );
	return mb_substr( trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES ) ) ), 0, EASY_MARKUP_QUOTE_MAX );
}

/**
 * The saved content's blocks in order: array( 'b1' => array( 'type' => ..., 'words' => ... ), ... ).
 *
 * @param int $post_id Page ID.
 * @return array
 */
function easy_markup_blocks_of( int $post_id ): array {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return array();
	}
	$out  = array();
	$n    = 0;
	$walk = function ( array $blocks ) use ( &$walk, &$out, &$n ) {
		foreach ( $blocks as $b ) {
			if ( empty( $b['blockName'] ) ) {
				continue; // Whitespace between blocks.
			}
			++$n;
			$out[ 'b' . $n ] = array(
				'type'  => $b['blockName'],
				'words' => easy_markup_block_words( $b ),
			);
			if ( ! empty( $b['innerBlocks'] ) ) {
				$walk( $b['innerBlocks'] );
			}
		}
	};
	$walk( parse_blocks( $post->post_content ) );
	return $out;
}

/** While the main page's content renders: the saved block names to match, and how far along. */
$GLOBALS['easy_markup_render'] = null;

add_filter(
	'the_content',
	function ( $content ) {
		// do_blocks runs at priority 9. Number only the main page's content, for people who may comment.
		// Every pass of the main page's content starts at b1, so an SEO plugin reading the content for a
		// description first doesn't throw the page's numbers off.
		$id = (int) get_the_ID();
		if ( is_singular() && $id && (int) get_queried_object_id() === $id && easy_markup_can_comment( $id ) ) {
			$GLOBALS['easy_markup_render'] = array(
				'expect' => array_column( easy_markup_blocks_of( $id ), 'type' ),
				'pos'    => 0,
			);
		}
		return $content;
	},
	8
);

add_filter(
	'the_content',
	function ( $content ) {
		$GLOBALS['easy_markup_render'] = null;
		return $content;
	},
	10
);

add_filter(
	'render_block_data',
	function ( $parsed ) {
		$r = &$GLOBALS['easy_markup_render'];
		if ( ! $r || empty( $parsed['blockName'] ) ) {
			return $parsed;
		}
		if ( ( $r['expect'][ $r['pos'] ] ?? null ) === $parsed['blockName'] ) {
			++$r['pos'];
			$parsed['attrs']['__emBlock'] = 'b' . $r['pos'];
		}
		return $parsed;
	}
);

add_filter(
	'render_block',
	function ( $html, $block ) {
		$id = $block['attrs']['__emBlock'] ?? '';
		if ( ! $id || '' === trim( (string) $html ) ) {
			return $html;
		}
		$p = new WP_HTML_Tag_Processor( $html );
		if ( ! $p->next_tag() ) {
			return $html;
		}
		$p->set_attribute( 'data-em-block', $id );
		$p->set_attribute( 'data-em-type', (string) $block['blockName'] );
		return $p->get_updated_html();
	},
	10,
	2
);
