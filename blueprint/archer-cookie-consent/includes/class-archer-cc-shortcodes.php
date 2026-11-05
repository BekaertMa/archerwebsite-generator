<?php
/**
 * Shortcodes for withdrawing consent and documenting cookie use.
 *
 * @package Archer_Cookie_Consent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front-end shortcodes.
 */
class Archer_CC_Shortcodes {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( 'archer_cc_preferences', array( $this, 'render_preferences_link' ) );
		add_shortcode( 'archer_cc_cookie_policy', array( $this, 'render_policy_table' ) );
	}

	/**
	 * [archer_cc_preferences] — a link or button that reopens the preferences modal.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_preferences_link( $atts ) {
		$atts = shortcode_atts(
			array(
				'label' => Archer_CC_I18n_Strings::trigger_label( Archer_CC_Settings::page_language() ),
				'class' => '',
				'tag'   => 'button',
			),
			(array) $atts,
			'archer_cc_preferences'
		);

		$label = sanitize_text_field( (string) $atts['label'] );

		$class_parts = preg_split( '/\s+/', trim( (string) $atts['class'] ) );

		if ( ! is_array( $class_parts ) ) {
			$class_parts = array();
		}

		$class = trim( 'archer-cc-preferences-trigger ' . implode( ' ', array_map( 'sanitize_html_class', $class_parts ) ) );

		if ( 'a' === $atts['tag'] ) {
			return sprintf(
				'<a href="#cookie-preferences" class="%1$s" data-cc="show-preferencesModal">%2$s</a>',
				esc_attr( $class ),
				esc_html( $label )
			);
		}

		return sprintf(
			'<button type="button" class="%1$s" data-cc="show-preferencesModal" aria-haspopup="dialog">%2$s</button>',
			esc_attr( $class ),
			esc_html( $label )
		);
	}

	/**
	 * [archer_cc_cookie_policy] — a purpose table for the cookie policy page.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_policy_table( $atts ) {
		$atts = shortcode_atts(
			array(
				'lang' => Archer_CC_Settings::page_language(),
			),
			(array) $atts,
			'archer_cc_cookie_policy'
		);

		$lang = array_key_exists( $atts['lang'], Archer_CC_Settings::languages() ) ? $atts['lang'] : 'en';

		$labels      = Archer_CC_I18n_Strings::category_labels( $lang );
		$strings     = Archer_CC_I18n_Strings::get( $lang );
		$sections    = isset( $strings['preferencesModal']['sections'] ) ? $strings['preferencesModal']['sections'] : array();
		$by_category = array();

		foreach ( $sections as $section ) {
			if ( ! empty( $section['linkedCategory'] ) ) {
				$by_category[ $section['linkedCategory'] ] = isset( $section['description'] ) ? $section['description'] : '';
			}
		}

		$expiry = absint( Archer_CC_Settings::get( 'expires_days', 182 ) );

		ob_start();
		?>
		<table class="archer-cc-policy-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Purpose', 'archer-cookie-consent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What it is used for', 'archer-cookie-consent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Legal basis', 'archer-cookie-consent' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( array_keys( Archer_CC_Settings::categories() ) as $slug ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug ); ?></th>
					<td><?php echo esc_html( isset( $by_category[ $slug ] ) ? $by_category[ $slug ] : '' ); ?></td>
					<td>
						<?php
						echo 'necessary' === $slug
							? esc_html__( 'Strictly necessary — exempt from consent (ePrivacy Art. 5(3))', 'archer-cookie-consent' )
							: esc_html__( 'Consent (GDPR Art. 6(1)(a))', 'archer-cookie-consent' );
						?>
					</td>
				</tr>
			<?php endforeach; ?>
				<tr>
					<th scope="row"><?php echo esc_html( (string) Archer_CC_Settings::get( 'cookie_name', 'archer_cc_consent' ) ); ?></th>
					<td><?php esc_html_e( 'Stores your cookie choice so we do not have to ask again on every page.', 'archer-cookie-consent' ); ?></td>
					<td>
						<?php
						/* translators: %d: number of days */
						printf( esc_html__( 'Strictly necessary — expires after %d days', 'archer-cookie-consent' ), (int) $expiry );
						?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
		// SECURITY: not passed through wp_kses_post() on purpose — kses strips the
		// data-cc attribute the modal trigger depends on. Every interpolated value
		// inside render_preferences_link() is already escaped at the point of use.
		?>
		<p><?php echo $this->render_preferences_link( array( 'tag' => 'button' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
		<?php

		return (string) ob_get_clean();
	}
}
