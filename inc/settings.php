<?php
/**
 * Settings > Easy Markup: the colors of comment mode on the page.
 *
 * Accent: the hover and selected outlines, the active Comment button, quote bars and focus rings.
 * Button: the Comment button's background. Button text: its text. Applied as CSS custom properties
 * (--em-accent, --em-button, --em-button-text) on the page script's stylesheet.
 *
 * @package EasyMarkup
 */

defined( 'ABSPATH' ) || exit;

const EASY_MARKUP_COLOR_DEFAULTS = array(
	'accent'      => '#2271b1',
	'button'      => '#1d2327',
	'button_text' => '#ffffff',
);

/**
 * The colors in use: saved ones, with the defaults for any not set.
 *
 * @return array accent, button, button_text (hex).
 */
function easy_markup_colors(): array {
	$saved = get_option( 'easy_markup_colors', array() );
	$out   = EASY_MARKUP_COLOR_DEFAULTS;
	foreach ( $out as $key => $default ) {
		$value = is_array( $saved ) && isset( $saved[ $key ] ) ? sanitize_hex_color( $saved[ $key ] ) : '';
		if ( $value ) {
			$out[ $key ] = $value;
		}
	}
	return $out;
}

/**
 * The CSS that sets the colors.
 *
 * @return string
 */
function easy_markup_colors_css(): string {
	$c = easy_markup_colors();
	return sprintf( ':root{--em-accent:%s;--em-button:%s;--em-button-text:%s}', $c['accent'], $c['button'], $c['button_text'] );
}

/**
 * Keep only valid hex colors; an empty field goes back to the default.
 *
 * @param mixed $input The submitted colors.
 * @return array
 */
function easy_markup_sanitize_colors( $input ): array {
	$out = array();
	foreach ( array_keys( EASY_MARKUP_COLOR_DEFAULTS ) as $key ) {
		$value = is_array( $input ) && isset( $input[ $key ] ) ? sanitize_hex_color( trim( (string) $input[ $key ] ) ) : '';
		if ( $value ) {
			$out[ $key ] = $value;
		}
	}
	return $out;
}

add_action(
	'admin_init',
	function () {
		register_setting(
			'easy_markup',
			'easy_markup_colors',
			array(
				'type'              => 'array',
				'sanitize_callback' => 'easy_markup_sanitize_colors',
				'default'           => array(),
			)
		);
	}
);

add_action(
	'admin_menu',
	function () {
		add_options_page(
			__( 'Easy Markup', 'easy-markup' ),
			__( 'Easy Markup', 'easy-markup' ),
			'manage_options',
			'easy-markup-settings',
			'easy_markup_settings_page'
		);
	}
);

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( 'settings_page_easy-markup-settings' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){$(".easy-markup-color").wpColorPicker();});' );
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( EASY_MARKUP_FILE ),
	function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=easy-markup-settings' ) ) . '">' . esc_html__( 'Settings', 'easy-markup' ) . '</a>' );
		return $links;
	}
);

/**
 * The settings page.
 *
 * @return void
 */
function easy_markup_settings_page(): void {
	$colors = easy_markup_colors();
	$fields = array(
		'accent'      => array( __( 'Accent', 'easy-markup' ), __( 'Outlines on the block under the pointer and the chosen block, the Comment button while comment mode is on, quote bars and focus rings.', 'easy-markup' ) ),
		'button'      => array( __( 'Button', 'easy-markup' ), __( 'The Comment button and the Send button.', 'easy-markup' ) ),
		'button_text' => array( __( 'Button text', 'easy-markup' ), __( 'Text on those buttons. Keep it readable against both colors above.', 'easy-markup' ) ),
	);
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Easy Markup', 'easy-markup' ); ?></h1>
		<p><?php esc_html_e( 'Colors of comment mode on your pages. Clear a color to go back to the default.', 'easy-markup' ); ?></p>
		<form method="post" action="options.php">
			<?php settings_fields( 'easy_markup' ); ?>
			<table class="form-table" role="presentation">
				<?php foreach ( $fields as $key => $field ) : ?>
					<tr>
						<th scope="row"><label for="easy-markup-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field[0] ); ?></label></th>
						<td>
							<input type="text" class="easy-markup-color" id="easy-markup-<?php echo esc_attr( $key ); ?>" name="easy_markup_colors[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $colors[ $key ] ); ?>" data-default-color="<?php echo esc_attr( EASY_MARKUP_COLOR_DEFAULTS[ $key ] ); ?>">
							<p class="description"><?php echo esc_html( $field[1] ); ?></p>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
