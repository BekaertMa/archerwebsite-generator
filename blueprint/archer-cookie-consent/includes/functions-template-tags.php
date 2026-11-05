<?php
/**
 * Template tags and public helper functions.
 *
 * Kept in their own file so the class files stay purely object-oriented, as the
 * WordPress coding standards require.
 *
 * @package Archer_Cookie_Consent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build the attributes a hand-written blocked script tag needs.
 *
 * Usage in a template:
 *     <script <?php echo archer_cc_blocked_script_atts( 'marketing' ); ?>>
 *
 * Passing $service adds a per-service toggle to the preferences modal, but it
 * also changes the activation rule: CookieConsent will then only run the script
 * when that specific service is accepted, and a visitor who consented before
 * the service existed has no record of it. Use it only for a service that is
 * present from the start, and bump the policy revision when you add one.
 *
 * @param string $category Category slug.
 * @param string $service  Optional service label. See the caveat above.
 * @return string Escaped attribute string.
 */
function archer_cc_blocked_script_atts( $category, $service = '' ) {
	$allowed = array_keys( Archer_CC_Settings::categories() );

	if ( ! in_array( $category, $allowed, true ) || 'necessary' === $category ) {
		return '';
	}

	$atts = sprintf( 'type="text/plain" data-category="%s"', esc_attr( $category ) );

	if ( '' !== $service ) {
		$atts .= sprintf( ' data-service="%s"', esc_attr( $service ) );
	}

	return $atts;
}

/**
 * Whether the current visitor has granted a category.
 *
 * Server-side checks read the consent cookie, which is only reliable on
 * uncached requests — prefer the JS API or blocked script tags on cached pages.
 *
 * @param string $category Category slug.
 * @return bool
 */
function archer_cc_has_consent( $category ) {
	$cookie_name = (string) Archer_CC_Settings::get( 'cookie_name', 'archer_cc_consent' );

	if ( ! isset( $_COOKIE[ $cookie_name ] ) ) {
		return false;
	}

	// SECURITY: cookie content is untrusted input — unslash, sanitise, then
	// json_decode. Never unserialize().
	$raw     = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
	$decoded = json_decode( $raw, true );

	if ( ! is_array( $decoded ) || empty( $decoded['categories'] ) || ! is_array( $decoded['categories'] ) ) {
		return false;
	}

	return in_array( sanitize_key( $category ), array_map( 'sanitize_key', $decoded['categories'] ), true );
}
