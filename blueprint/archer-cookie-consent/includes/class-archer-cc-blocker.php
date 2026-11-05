<?php
/**
 * Prior blocking of non-necessary scripts.
 *
 * Under ePrivacy Art. 5(3) consent is required *before* storage is written, so
 * any tag that sets cookies or local storage must not execute on first load.
 * Three mechanisms are provided:
 *
 *  1. Enqueued handles listed in the settings are rewritten to inert
 *     type="text/plain" tags with a data-category attribute.
 *  2. The archer_cc_block_script filter lets other code block a handle in PHP.
 *  3. Template authors can write blocked tags by hand, or call
 *     archer_cc_blocked_script_atts() to get the right attributes.
 *
 * @package Archer_Cookie_Consent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites enqueued script tags into consent-gated tags.
 */
class Archer_CC_Blocker {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		// HOOKS: explicit priority and accepted-args count on every callback.
		add_filter( 'script_loader_tag', array( $this, 'filter_script_tag' ), 20, 3 );
		add_filter( 'wp_inline_script_attributes', array( $this, 'filter_inline_attributes' ), 20, 2 );
	}

	/**
	 * Resolve the consent category for a script handle.
	 *
	 * @param string $handle Script handle.
	 * @return string Category slug, or '' when the handle is not blocked.
	 */
	public function category_for_handle( $handle ) {
		$map      = Archer_CC_Settings::get_handle_map();
		$category = isset( $map[ $handle ] ) ? $map[ $handle ] : '';

		/**
		 * Filters the consent category a script handle belongs to.
		 *
		 * Return a category slug (functionality|analytics|marketing) to block the
		 * handle until that category is granted, or '' to leave it alone.
		 *
		 * @param string $category Resolved category.
		 * @param string $handle   Script handle.
		 */
		$category = apply_filters( 'archer_cc_block_script', $category, $handle );

		$allowed = array_keys( Archer_CC_Settings::categories() );

		if ( ! in_array( $category, $allowed, true ) || 'necessary' === $category ) {
			return '';
		}

		return $category;
	}

	/**
	 * Rewrite a registered script tag so CookieConsent controls it.
	 *
	 * @param string $tag    Full script tag HTML.
	 * @param string $handle Script handle.
	 * @param string $src    Script source URL.
	 * @return string
	 */
	public function filter_script_tag( $tag, $handle, $src ) {
		unset( $src );

		if ( is_admin() || ! Archer_CC_Frontend::should_render() ) {
			return $tag;
		}

		$category = $this->category_for_handle( $handle );

		if ( '' === $category ) {
			return $tag;
		}

		// Drop any existing type attribute, then force the inert one.
		$tag = preg_replace( '/\stype=(["\'])[^"\']*\1/i', '', $tag );

		// CRITICAL: category only — never data-service. In CookieConsent v3 a
		// script that carries a service name can only ever be activated by
		// serviceWasJustEnabled(), and auto-discovered services are not written
		// into the visitor's stored _acceptedServices. Tagging a service here
		// would mean that anyone who consented BEFORE the handle was added to the
		// block list never runs it again — silently, until the revision is bumped.
		$replacement = sprintf(
			'<script type="text/plain" data-category="%1$s"',
			esc_attr( $category )
		);

		return preg_replace( '/<script/i', $replacement, (string) $tag, 1 );
	}

	/**
	 * Rewrite inline script attributes for a blocked handle.
	 *
	 * @param array  $attributes Attribute map.
	 * @param string $javascript Inline JS (unused).
	 * @return array
	 */
	public function filter_inline_attributes( $attributes, $javascript ) {
		unset( $javascript );

		if ( is_admin() || ! Archer_CC_Frontend::should_render() || empty( $attributes['id'] ) ) {
			return $attributes;
		}

		// WordPress ids inline blocks as "{handle}-js-before" / "{handle}-js-after".
		$handle = preg_replace( '/-js-(before|after|extra|translations)$/', '', (string) $attributes['id'] );

		$category = $this->category_for_handle( $handle );

		if ( '' === $category ) {
			return $attributes;
		}

		// See filter_script_tag(): category only, no data-service.
		$attributes['type']          = 'text/plain';
		$attributes['data-category'] = $category;

		return $attributes;
	}
}
