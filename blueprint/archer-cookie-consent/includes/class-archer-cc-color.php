<?php
/**
 * Colour maths: used to render CSS custom properties and to warn the admin when
 * a palette turns the reject button into a dark pattern.
 *
 * @package Archer_Cookie_Consent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stateless colour helpers.
 */
class Archer_CC_Color {

	/**
	 * Convert a hex colour to an RGB triplet.
	 *
	 * @param string $hex Hex colour (#rgb or #rrggbb).
	 * @return array{0:int,1:int,2:int}
	 */
	public static function to_rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return array( 0, 0, 0 );
		}

		return array(
			(int) hexdec( substr( $hex, 0, 2 ) ),
			(int) hexdec( substr( $hex, 2, 2 ) ),
			(int) hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * Build an rgba() string from a hex colour and a 0-100 opacity.
	 *
	 * @param string $hex     Hex colour.
	 * @param int    $opacity Opacity percentage.
	 * @return string
	 */
	public static function to_rgba( $hex, $opacity ) {
		list( $r, $g, $b ) = self::to_rgb( $hex );
		$alpha             = max( 0, min( 100, (int) $opacity ) ) / 100;

		return sprintf( 'rgba(%d, %d, %d, %s)', $r, $g, $b, rtrim( rtrim( number_format( $alpha, 2, '.', '' ), '0' ), '.' ) );
	}

	/**
	 * WCAG relative luminance.
	 *
	 * @param string $hex Hex colour.
	 * @return float
	 */
	public static function luminance( $hex ) {
		$channels = self::to_rgb( $hex );
		$linear   = array();

		foreach ( $channels as $channel ) {
			$value    = $channel / 255;
			$linear[] = $value <= 0.03928 ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 );
		}

		return ( 0.2126 * $linear[0] ) + ( 0.7152 * $linear[1] ) + ( 0.0722 * $linear[2] );
	}

	/**
	 * WCAG contrast ratio between two colours (1.0 – 21.0).
	 *
	 * @param string $hex_a First colour.
	 * @param string $hex_b Second colour.
	 * @return float
	 */
	public static function contrast( $hex_a, $hex_b ) {
		$lum_a = self::luminance( $hex_a );
		$lum_b = self::luminance( $hex_b );
		$light = max( $lum_a, $lum_b );
		$dark  = min( $lum_a, $lum_b );

		return round( ( $light + 0.05 ) / ( $dark + 0.05 ), 2 );
	}

	/**
	 * Audit one palette for accessibility and "equal prominence" problems.
	 *
	 * COMPLIANCE: the EDPB Cookie Banner Taskforce report treats a reject button
	 * that is visually weaker than accept as invalid consent. We cannot police
	 * taste, but we can measure contrast and tell the admin when the two buttons
	 * are objectively unequal.
	 *
	 * @param array  $palette Palette slug => hex.
	 * @param string $label   Human label for the palette ("Light"/"Dark").
	 * @return array<int,array{level:string,message:string}>
	 */
	public static function audit_palette( $palette, $label ) {
		$issues = array();

		$bg          = isset( $palette['bg'] ) ? $palette['bg'] : '#ffffff';
		$text        = isset( $palette['text'] ) ? $palette['text'] : '#000000';
		$accept_bg   = isset( $palette['btn_primary_bg'] ) ? $palette['btn_primary_bg'] : '#000000';
		$accept_text = isset( $palette['btn_primary_text'] ) ? $palette['btn_primary_text'] : '#ffffff';
		$reject_bg   = isset( $palette['btn_secondary_bg'] ) ? $palette['btn_secondary_bg'] : '#ffffff';
		$reject_text = isset( $palette['btn_secondary_text'] ) ? $palette['btn_secondary_text'] : '#000000';

		$body_contrast   = self::contrast( $text, $bg );
		$accept_contrast = self::contrast( $accept_text, $accept_bg );
		$reject_contrast = self::contrast( $reject_text, $reject_bg );
		$reject_edge     = self::contrast( $reject_bg, $bg );
		$accept_edge     = self::contrast( $accept_bg, $bg );

		if ( $body_contrast < 4.5 ) {
			$issues[] = array(
				'level'   => 'error',
				/* translators: 1: palette name, 2: contrast ratio */
				'message' => sprintf( __( '%1$s palette: body text has a contrast ratio of %2$s:1 against the modal background. WCAG AA requires at least 4.5:1 — the banner text may be unreadable.', 'archer-cookie-consent' ), $label, number_format_i18n( $body_contrast, 2 ) ),
			);
		}

		if ( $accept_contrast < 4.5 ) {
			$issues[] = array(
				'level'   => 'warning',
				/* translators: 1: palette name, 2: contrast ratio */
				'message' => sprintf( __( '%1$s palette: the “Accept all” label has a contrast ratio of %2$s:1 against its own button. Aim for 4.5:1 or higher.', 'archer-cookie-consent' ), $label, number_format_i18n( $accept_contrast, 2 ) ),
			);
		}

		if ( $reject_contrast < 4.5 ) {
			$issues[] = array(
				'level'   => 'error',
				/* translators: 1: palette name, 2: contrast ratio */
				'message' => sprintf( __( '%1$s palette: the “Reject all” label has a contrast ratio of only %2$s:1 against its own button. A hard-to-read reject button is the single most-cited dark pattern in EU cookie-banner enforcement.', 'archer-cookie-consent' ), $label, number_format_i18n( $reject_contrast, 2 ) ),
			);
		}

		// Relative legibility is the honest test for steering. A muted secondary
		// fill is ordinary, legitimate UI — what matters is whether the reject
		// label is materially harder to read than the accept label.
		if ( $accept_contrast >= 4.5 && $reject_contrast < ( $accept_contrast / 2 ) ) {
			$issues[] = array(
				'level'   => 'error',
				/* translators: 1: palette name, 2: reject ratio, 3: accept ratio */
				'message' => sprintf( __( '%1$s palette: the reject label (%2$s:1) is far less legible than the accept label (%3$s:1). Making refusal harder to read than acceptance is exactly the steering the EDPB taskforce identified.', 'archer-cookie-consent' ), $label, number_format_i18n( $reject_contrast, 2 ), number_format_i18n( $accept_contrast, 2 ) ),
			);
		}

		// A reject button with no visible edge against the modal, while the accept
		// button clearly reads as a button, removes the affordance to refuse.
		if ( $reject_edge < 1.2 && $accept_edge >= 1.5 && $reject_contrast < 7 ) {
			$issues[] = array(
				'level'   => 'warning',
				/* translators: %s: palette name */
				'message' => sprintf( __( '%s palette: the reject button has no visible edge against the modal background while the accept button clearly reads as a button. Give reject a border or a distinguishable fill so both options look equally clickable.', 'archer-cookie-consent' ), $label ),
			);
		}

		return $issues;
	}
}
