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
		$t      = self::policy_strings( $lang );

		ob_start();
		?>
		<table class="archer-cc-policy-table">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html( $t['purpose'] ); ?></th>
					<th scope="col"><?php echo esc_html( $t['used_for'] ); ?></th>
					<th scope="col"><?php echo esc_html( $t['basis'] ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( array_keys( Archer_CC_Settings::categories() ) as $slug ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug ); ?></th>
					<td><?php echo esc_html( isset( $by_category[ $slug ] ) ? $by_category[ $slug ] : '' ); ?></td>
					<td>
						<?php
						echo esc_html( 'necessary' === $slug ? $t['necessary'] : $t['consent'] );
						?>
					</td>
				</tr>
			<?php endforeach; ?>
				<tr>
					<th scope="row"><?php echo esc_html( (string) Archer_CC_Settings::get( 'cookie_name', 'archer_cc_consent' ) ); ?></th>
					<td><?php echo esc_html( $t['choice'] ); ?></td>
					<td>
						<?php
						echo esc_html( sprintf( $t['expires'], (int) $expiry ) );
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

	/**
	 * Labels of the cookie policy table in the language of the page. The banner copy is per language
	 * already; the table followed the WordPress locale and stayed English on a Dutch page.
	 *
	 * @param string $lang Language code (en, nl, fr).
	 * @return array<string,string>
	 */
	public static function policy_strings( $lang ) {
		$all = array(
			'en' => array(
				'purpose'   => 'Purpose',
				'used_for'  => 'What it is used for',
				'basis'     => 'Legal basis',
				'necessary' => 'Strictly necessary — exempt from consent (ePrivacy Art. 5(3))',
				'consent'   => 'Consent (GDPR Art. 6(1)(a))',
				'choice'    => 'Stores your cookie choice so we do not have to ask again on every page.',
				/* translators: %d: number of days */
				'expires'   => 'Strictly necessary — expires after %d days',
			),
			'nl' => array(
				'purpose'   => 'Doel',
				'used_for'  => 'Waarvoor dient het',
				'basis'     => 'Rechtsgrond',
				'necessary' => 'Strikt noodzakelijk — geen toestemming nodig (ePrivacy art. 5(3))',
				'consent'   => 'Toestemming (AVG art. 6(1)(a))',
				'choice'    => 'Bewaart uw cookiekeuze, zodat we die niet op elke pagina opnieuw moeten vragen.',
				'expires'   => 'Strikt noodzakelijk — vervalt na %d dagen',
			),
			'fr' => array(
				'purpose'   => 'Finalité',
				'used_for'  => 'À quoi il sert',
				'basis'     => 'Base juridique',
				'necessary' => 'Strictement nécessaire — dispensé de consentement (ePrivacy art. 5(3))',
				'consent'   => 'Consentement (RGPD art. 6(1)(a))',
				'choice'    => 'Enregistre votre choix en matière de cookies pour ne pas vous le redemander sur chaque page.',
				'expires'   => 'Strictement nécessaire — expire après %d jours',
			),
		);
		$t = isset( $all[ $lang ] ) ? $all[ $lang ] : $all['en'];
		return (array) apply_filters( 'archer_cc_policy_strings', $t, $lang );
	}
}
