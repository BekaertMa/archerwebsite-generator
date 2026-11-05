<?php
/**
 * Settings storage, defaults and sanitisation.
 *
 * @package Archer_Cookie_Consent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for plugin options.
 */
class Archer_CC_Settings {

	/**
	 * Runtime cache of the merged settings array.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Runtime cache of the parsed script-blocking map.
	 *
	 * @var array|null
	 */
	private static $handle_map_cache = null;

	/**
	 * Supported banner languages.
	 *
	 * @return array<string,string> Locale code => English label.
	 */
	public static function languages() {
		return array(
			'en' => __( 'English', 'archer-cookie-consent' ),
			'nl' => __( 'Dutch', 'archer-cookie-consent' ),
			'fr' => __( 'French', 'archer-cookie-consent' ),
		);
	}

	/**
	 * Banner language of the site itself: the base subtag of the WordPress locale
	 * ("nl_BE" -> "nl") when the banner has copy for it, otherwise English.
	 *
	 * @return string
	 */
	public static function site_language() {
		$base = strtolower( substr( (string) get_locale(), 0, 2 ) );

		return array_key_exists( $base, self::languages() ) ? $base : 'en';
	}

	/**
	 * Language of the page being rendered.
	 *
	 * Uses determine_locale(), which multilingual plugins (WPML, Polylang, TranslatePress)
	 * filter per request, so a French page on a Dutch site gets the French banner. Falls
	 * back to the configured default language.
	 *
	 * @return string
	 */
	public static function page_language() {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$base   = strtolower( substr( (string) $locale, 0, 2 ) );
		$lang   = array_key_exists( $base, self::languages() ) ? $base : (string) self::get( 'default_language', 'en' );

		/**
		 * Filters the banner language for the current page.
		 *
		 * @param string $lang   Language code (en|nl|fr).
		 * @param string $locale Locale of the request.
		 */
		$lang = (string) apply_filters( 'archer_cc_page_language', $lang, $locale );

		return array_key_exists( $lang, self::languages() ) ? $lang : 'en';
	}

	/**
	 * Consent categories handled by the plugin.
	 *
	 * "necessary" is always read-only and always granted, which is the only
	 * category that may be pre-enabled under ePrivacy Art. 5(3).
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function categories() {
		return array(
			'necessary'     => array(
				'read_only' => true,
				'enabled'   => true,
			),
			'functionality' => array(
				'read_only' => false,
				'enabled'   => false,
			),
			'analytics'     => array(
				'read_only' => false,
				'enabled'   => false,
			),
			'marketing'     => array(
				'read_only' => false,
				'enabled'   => false,
			),
		);
	}

	/**
	 * Colour slugs exposed in the theme editor, mapped to CookieConsent CSS variables.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function color_map() {
		return array(
			'bg'                     => array(
				'label' => __( 'Modal background', 'archer-cookie-consent' ),
				'vars'  => array( '--cc-bg' ),
			),
			'text'                   => array(
				'label' => __( 'Body text', 'archer-cookie-consent' ),
				'vars'  => array( '--cc-primary-color' ),
			),
			'muted_text'             => array(
				'label' => __( 'Muted / secondary text', 'archer-cookie-consent' ),
				'vars'  => array( '--cc-secondary-color' ),
			),
			'link'                   => array(
				'label' => __( 'Link colour', 'archer-cookie-consent' ),
				'vars'  => array( '--cc-link-color' ),
			),
			'border'                 => array(
				'label' => __( 'Borders & separators', 'archer-cookie-consent' ),
				'vars'  => array(
					'--cc-separator-border-color',
					'--cc-section-category-border',
					'--cc-cookie-category-block-border',
					'--cc-cookie-category-block-hover-border',
					'--cc-footer-border-color',
				),
			),
			'btn_primary_bg'         => array(
				'label' => __( '“Accept all” background', 'archer-cookie-consent' ),
				'vars'  => array( '--cc-btn-primary-bg', '--cc-btn-primary-border-color' ),
			),
			'btn_primary_text'       => array(
				'label' => __( '“Accept all” text', 'archer-cookie-consent' ),
				'vars'  => array( '--cc-btn-primary-color', '--cc-btn-primary-hover-color' ),
			),
			'btn_primary_hover_bg'   => array(
				'label' => __( '“Accept all” hover background', 'archer-cookie-consent' ),
				'vars'  => array( '--cc-btn-primary-hover-bg', '--cc-btn-primary-hover-border-color' ),
			),
			'btn_secondary_bg'       => array(
				'label' => __( '“Reject” / “Preferences” background', 'archer-cookie-consent' ),
				'vars'  => array( '--cc-btn-secondary-bg', '--cc-btn-secondary-border-color' ),
			),
			'btn_secondary_text'     => array(
				'label' => __( '“Reject” / “Preferences” text', 'archer-cookie-consent' ),
				'vars'  => array( '--cc-btn-secondary-color', '--cc-btn-secondary-hover-color' ),
			),
			'btn_secondary_hover_bg' => array(
				'label' => __( '“Reject” / “Preferences” hover background', 'archer-cookie-consent' ),
				'vars'  => array( '--cc-btn-secondary-hover-bg', '--cc-btn-secondary-hover-border-color' ),
			),
			'toggle_on_bg'           => array(
				'label' => __( 'Toggle — enabled', 'archer-cookie-consent' ),
				'vars'  => array( '--cc-toggle-on-bg' ),
			),
			'toggle_off_bg'          => array(
				'label' => __( 'Toggle — disabled', 'archer-cookie-consent' ),
				'vars'  => array( '--cc-toggle-off-bg' ),
			),
			'toggle_readonly_bg'     => array(
				'label' => __( 'Toggle — locked (necessary)', 'archer-cookie-consent' ),
				'vars'  => array( '--cc-toggle-readonly-bg' ),
			),
			'toggle_knob'            => array(
				'label' => __( 'Toggle knob', 'archer-cookie-consent' ),
				'vars'  => array( '--cc-toggle-on-knob-bg', '--cc-toggle-off-knob-bg', '--cc-toggle-readonly-knob-bg' ),
			),
			'block_bg'               => array(
				'label' => __( 'Category block background', 'archer-cookie-consent' ),
				'vars'  => array( '--cc-cookie-category-block-bg', '--cc-cookie-category-expanded-block-bg' ),
			),
			'block_hover_bg'         => array(
				'label' => __( 'Category block hover', 'archer-cookie-consent' ),
				'vars'  => array( '--cc-cookie-category-block-hover-bg', '--cc-cookie-category-expanded-block-hover-bg' ),
			),
			'footer_bg'              => array(
				'label' => __( 'Preferences footer background', 'archer-cookie-consent' ),
				'vars'  => array( '--cc-footer-bg' ),
			),
			'footer_text'            => array(
				'label' => __( 'Preferences footer text', 'archer-cookie-consent' ),
				'vars'  => array( '--cc-footer-color' ),
			),
			'overlay_bg'             => array(
				'label' => __( 'Page overlay', 'archer-cookie-consent' ),
				'vars'  => array( '--cc-overlay-bg' ),
				'alpha' => true,
			),
		);
	}

	/**
	 * Default light palette (CookieConsent stock light theme).
	 *
	 * @return array<string,string>
	 */
	public static function default_palette_light() {
		return array(
			'bg'                     => '#f9faff',
			'text'                   => '#2d4156',
			'muted_text'             => '#5c6f7c',
			'link'                   => '#2d4156',
			'border'                 => '#e1e7f3',
			'btn_primary_bg'         => '#2d4156',
			'btn_primary_text'       => '#ffffff',
			'btn_primary_hover_bg'   => '#1d2e38',
			'btn_secondary_bg'       => '#e5ecf6',
			'btn_secondary_text'     => '#2d4156',
			'btn_secondary_hover_bg' => '#d5dee9',
			'toggle_on_bg'           => '#2d4156',
			'toggle_off_bg'          => '#918f8f',
			'toggle_readonly_bg'     => '#d5dee2',
			'toggle_knob'            => '#ffffff',
			'block_bg'               => '#f0f4f7',
			'block_hover_bg'         => '#e9eff4',
			'footer_bg'              => '#f0f4f7',
			'footer_text'            => '#2d4156',
			'overlay_bg'             => '#040608',
		);
	}

	/**
	 * Default dark palette.
	 *
	 * @return array<string,string>
	 */
	public static function default_palette_dark() {
		return array(
			'bg'                     => '#161a1c',
			'text'                   => '#ebeff0',
			'muted_text'             => '#b5b5b5',
			'link'                   => '#ebeff0',
			'border'                 => '#2b3436',
			'btn_primary_bg'         => '#4d6470',
			'btn_primary_text'       => '#ffffff',
			'btn_primary_hover_bg'   => '#5c7887',
			'btn_secondary_bg'       => '#242c2e',
			'btn_secondary_text'     => '#ebeff0',
			'btn_secondary_hover_bg' => '#2f3839',
			'toggle_on_bg'           => '#4d6470',
			'toggle_off_bg'          => '#667481',
			'toggle_readonly_bg'     => '#454c4e',
			'toggle_knob'            => '#ffffff',
			'block_bg'               => '#1e2325',
			'block_hover_bg'         => '#242a2c',
			'footer_bg'              => '#1e2325',
			'footer_text'            => '#ebeff0',
			'overlay_bg'             => '#000000',
		);
	}

	/**
	 * Default "which cookies to erase when a category is refused" map.
	 *
	 * Values beginning with ^ are treated as regular expressions by the front-end
	 * runtime, everything else as an exact cookie name.
	 *
	 * @return string
	 */
	public static function default_autoclear_map() {
		return implode(
			"\n",
			array(
				'analytics|^_ga',
				'analytics|_gid',
				'analytics|^_gat',
				'analytics|^_hj',
				'analytics|^_clck',
				'analytics|^_clsk',
				'marketing|^_fbp',
				'marketing|^_gcl',
				'marketing|^_uet',
				'marketing|^_ttp',
				'marketing|^li_',
				'marketing|IDE',
			)
		);
	}

	/**
	 * Full default settings array.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		$texts = array();
		foreach ( array_keys( self::languages() ) as $lang ) {
			$texts[ $lang ] = array(
				'title'       => '',
				'description' => '',
				'policy_url'  => '',
			);
		}

		$defaults = array(
			// --- General ---------------------------------------------------.
			'enabled'                  => 1,
			'default_language'         => self::site_language(),
			'auto_detect'              => 'document',
			'revision'                 => 0,
			'expires_days'             => 182,
			'cookie_name'              => 'archer_cc_consent',
			'same_site'                => 'Lax',
			'auto_clear_cookies'       => 1,
			'autoclear_cookies'        => self::default_autoclear_map(),
			'hide_from_bots'           => 1,
			'disable_page_interaction' => 0,
			'show_floating_button'     => 1,
			'exclude_logged_in'        => 0,

			// --- Layout ----------------------------------------------------.
			'consent_layout'           => 'box',
			'consent_position'         => 'bottom left',
			'prefs_layout'             => 'box',
			'prefs_position'           => 'right',
			'equal_weight_buttons'     => 1,
			'flip_buttons'             => 0,

			// --- Appearance ------------------------------------------------.
			'color_scheme'             => 'auto',
			'font_family'              => '',
			'modal_radius'             => '0.5rem',
			'btn_radius'               => '0.4rem',
			'toggle_radius'            => '4em',
			'overlay_opacity'          => 65,
			'palette_light'            => self::default_palette_light(),
			'palette_dark'             => self::default_palette_dark(),

			// --- Content ---------------------------------------------------.
			'texts'                    => $texts,

			// --- Google Consent Mode v2 -----------------------------------.
			'consent_mode'             => 1,
			'consent_mode_type'        => 'advanced',
			'consent_mode_wait'        => 500,
			'ads_data_redaction'       => 1,
			'url_passthrough'          => 0,
			'gtm_id'                   => '',
			'ga4_id'                   => '',

			// --- Script blocking ------------------------------------------.
			'blocked_handles'          => '',

			// --- Consent log ----------------------------------------------.
			'log_enabled'              => 1,
			'log_retention_days'       => 400,
			'log_user_agent'           => 1,
			'delete_data_on_uninstall' => 0,
		);

		/**
		 * Filters the plugin's default settings.
		 *
		 * @param array $defaults Default settings.
		 */
		return apply_filters( 'archer_cc_default_settings', $defaults );
	}

	/**
	 * Get the merged settings array.
	 *
	 * PERF: result is memoised per request; the option itself is autoloaded.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$defaults = self::defaults();
		$stored   = get_option( ARCHER_CC_OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$merged = array_merge( $defaults, $stored );

		// Nested arrays need a second-level merge so a partial save never drops keys.
		$merged['palette_light'] = array_merge( $defaults['palette_light'], isset( $stored['palette_light'] ) && is_array( $stored['palette_light'] ) ? $stored['palette_light'] : array() );
		$merged['palette_dark']  = array_merge( $defaults['palette_dark'], isset( $stored['palette_dark'] ) && is_array( $stored['palette_dark'] ) ? $stored['palette_dark'] : array() );

		foreach ( array_keys( self::languages() ) as $lang ) {
			$stored_lang              = isset( $stored['texts'][ $lang ] ) && is_array( $stored['texts'][ $lang ] ) ? $stored['texts'][ $lang ] : array();
			$merged['texts'][ $lang ] = array_merge( $defaults['texts'][ $lang ], $stored_lang );
		}

		self::$cache = $merged;

		return self::$cache;
	}

	/**
	 * Read a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $fallback Value returned when the key is unknown.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Clear the runtime cache (used after a save).
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$cache            = null;
		self::$handle_map_cache = null;
	}

	/**
	 * Sanitise callback for register_setting().
	 *
	 * ADMIN: the settings screen is tabbed but writes to a single option, so we
	 * merge the submitted slice over the stored value instead of replacing it.
	 * SECURITY: every value is whitelisted or cast; nothing is trusted as-is.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ) {
		$stored = get_option( ARCHER_CC_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$out = array_merge( self::defaults(), $stored );

		if ( ! is_array( $input ) ) {
			return $out;
		}

		$booleans = array(
			'enabled',
			'auto_clear_cookies',
			'hide_from_bots',
			'disable_page_interaction',
			'show_floating_button',
			'exclude_logged_in',
			'equal_weight_buttons',
			'flip_buttons',
			'consent_mode',
			'ads_data_redaction',
			'url_passthrough',
			'log_enabled',
			'log_user_agent',
			'delete_data_on_uninstall',
		);

		// A tab that renders a checkbox always posts a hidden 0 companion field,
		// so absent keys legitimately mean "not on this tab" and are left alone.
		foreach ( $booleans as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
			}
		}

		if ( isset( $input['default_language'] ) ) {
			$out['default_language'] = array_key_exists( $input['default_language'], self::languages() ) ? $input['default_language'] : 'en';
		}

		if ( isset( $input['auto_detect'] ) ) {
			$out['auto_detect'] = in_array( $input['auto_detect'], array( 'browser', 'document', 'off' ), true ) ? $input['auto_detect'] : 'document';
		}

		if ( isset( $input['revision'] ) ) {
			// The consent-log column is SMALLINT UNSIGNED; a larger value would
			// make every INSERT fail under MySQL strict mode, silently killing the
			// audit trail.
			$out['revision'] = min( 65535, absint( $input['revision'] ) );
		}

		if ( isset( $input['expires_days'] ) ) {
			// COMPLIANCE: EDPB/CNIL treat >13 months as no longer "informed"; cap hard.
			$out['expires_days'] = max( 1, min( 395, absint( $input['expires_days'] ) ) );
		}

		if ( isset( $input['cookie_name'] ) ) {
			$name               = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $input['cookie_name'] );
			$out['cookie_name'] = '' === $name ? 'archer_cc_consent' : $name;
		}

		if ( isset( $input['same_site'] ) ) {
			$same_site = in_array( $input['same_site'], array( 'Lax', 'Strict', 'None' ), true ) ? $input['same_site'] : 'Lax';

			// Browsers discard a SameSite=None cookie that is not also Secure, and
			// the consent cookie silently vanishing means the banner reappears on
			// every page view. Refuse None unless the site is actually on HTTPS.
			if ( 'None' === $same_site && ! is_ssl() ) {
				$same_site = 'Lax';
			}

			$out['same_site'] = $same_site;
		}

		if ( isset( $input['consent_layout'] ) ) {
			$out['consent_layout'] = in_array( $input['consent_layout'], array( 'box', 'box wide', 'box inline', 'cloud', 'cloud inline', 'bar', 'bar inline' ), true ) ? $input['consent_layout'] : 'box';
		}

		if ( isset( $input['consent_position'] ) ) {
			$allowed                 = array( 'top left', 'top center', 'top right', 'middle left', 'middle center', 'middle right', 'bottom left', 'bottom center', 'bottom right' );
			$out['consent_position'] = in_array( $input['consent_position'], $allowed, true ) ? $input['consent_position'] : 'bottom left';
		}

		if ( isset( $input['prefs_layout'] ) ) {
			$out['prefs_layout'] = in_array( $input['prefs_layout'], array( 'box', 'bar', 'bar wide' ), true ) ? $input['prefs_layout'] : 'box';
		}

		if ( isset( $input['prefs_position'] ) ) {
			$out['prefs_position'] = in_array( $input['prefs_position'], array( 'left', 'right' ), true ) ? $input['prefs_position'] : 'right';
		}

		if ( isset( $input['color_scheme'] ) ) {
			$out['color_scheme'] = in_array( $input['color_scheme'], array( 'light', 'dark', 'auto' ), true ) ? $input['color_scheme'] : 'auto';
		}

		if ( isset( $input['font_family'] ) ) {
			// SECURITY: a font stack lands inside a CSS declaration, so strip
			// anything that could terminate it or open a new rule/url().
			$font               = preg_replace( '/[^A-Za-z0-9 ,\'"\-]/', '', (string) $input['font_family'] );
			$out['font_family'] = trim( (string) $font );
		}

		foreach ( array( 'modal_radius', 'btn_radius', 'toggle_radius' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = self::sanitize_css_length( $input[ $key ], $out[ $key ] );
			}
		}

		if ( isset( $input['overlay_opacity'] ) ) {
			$out['overlay_opacity'] = max( 0, min( 100, absint( $input['overlay_opacity'] ) ) );
		}

		foreach ( array( 'palette_light', 'palette_dark' ) as $palette_key ) {
			if ( ! isset( $input[ $palette_key ] ) || ! is_array( $input[ $palette_key ] ) ) {
				continue;
			}
			$fallbacks = 'palette_light' === $palette_key ? self::default_palette_light() : self::default_palette_dark();
			foreach ( array_keys( self::color_map() ) as $slug ) {
				if ( ! isset( $input[ $palette_key ][ $slug ] ) ) {
					continue;
				}
				// SECURITY: sanitize_hex_color() returns null on anything that is
				// not a #rgb/#rrggbb literal, which closes CSS injection here.
				$hex                          = sanitize_hex_color( (string) $input[ $palette_key ][ $slug ] );
				$out[ $palette_key ][ $slug ] = null === $hex ? $fallbacks[ $slug ] : $hex;
			}
		}

		if ( isset( $input['texts'] ) && is_array( $input['texts'] ) ) {
			foreach ( array_keys( self::languages() ) as $lang ) {
				if ( ! isset( $input['texts'][ $lang ] ) || ! is_array( $input['texts'][ $lang ] ) ) {
					continue;
				}
				$slice = $input['texts'][ $lang ];

				if ( isset( $slice['title'] ) ) {
					$out['texts'][ $lang ]['title'] = sanitize_text_field( $slice['title'] );
				}
				if ( isset( $slice['description'] ) ) {
					// A short allow-list of inline markup so admins can add a policy link.
					$out['texts'][ $lang ]['description'] = wp_kses(
						(string) $slice['description'],
						array(
							'a'      => array(
								'href'   => array(),
								'target' => array(),
								'rel'    => array(),
							),
							'strong' => array(),
							'em'     => array(),
							'br'     => array(),
						)
					);
				}
				if ( isset( $slice['policy_url'] ) ) {
					$out['texts'][ $lang ]['policy_url'] = esc_url_raw( $slice['policy_url'], array( 'http', 'https' ) );
				}
			}
		}

		if ( isset( $input['consent_mode_type'] ) ) {
			$out['consent_mode_type'] = in_array( $input['consent_mode_type'], array( 'advanced', 'basic' ), true ) ? $input['consent_mode_type'] : 'advanced';
		}

		if ( isset( $input['consent_mode_wait'] ) ) {
			$out['consent_mode_wait'] = max( 0, min( 5000, absint( $input['consent_mode_wait'] ) ) );
		}

		if ( isset( $input['gtm_id'] ) ) {
			$gtm           = strtoupper( preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $input['gtm_id'] ) );
			$out['gtm_id'] = preg_match( '/^GTM-[A-Z0-9]{4,}$/', $gtm ) ? $gtm : '';
		}

		if ( isset( $input['ga4_id'] ) ) {
			$ga4           = strtoupper( preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $input['ga4_id'] ) );
			$out['ga4_id'] = preg_match( '/^(G|AW|DC)-[A-Z0-9]{4,}$/', $ga4 ) ? $ga4 : '';
		}

		if ( isset( $input['blocked_handles'] ) ) {
			$out['blocked_handles'] = self::sanitize_handle_map( $input['blocked_handles'] );
		}

		if ( isset( $input['autoclear_cookies'] ) ) {
			$out['autoclear_cookies'] = self::sanitize_autoclear_map( $input['autoclear_cookies'] );
		}

		if ( isset( $input['log_retention_days'] ) ) {
			$out['log_retention_days'] = max( 30, min( 2000, absint( $input['log_retention_days'] ) ) );
		}

		self::flush_cache();

		/**
		 * Filters the sanitised settings just before they are stored.
		 *
		 * @param array $out   Sanitised settings.
		 * @param array $input Raw input.
		 */
		return apply_filters( 'archer_cc_sanitized_settings', $out, $input );
	}

	/**
	 * Validate a small CSS length value (px, rem, em, %).
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $fallback Value used when validation fails.
	 * @return string
	 */
	public static function sanitize_css_length( $value, $fallback ) {
		$value = trim( (string) $value );

		if ( '0' === $value ) {
			return '0';
		}

		return preg_match( '/^\d{1,3}(\.\d{1,3})?(px|rem|em|%)$/', $value ) ? $value : $fallback;
	}

	/**
	 * Sanitise the "handle|category" script-blocking map.
	 *
	 * @param mixed $raw Raw textarea content.
	 * @return string Newline-separated normalised map.
	 */
	public static function sanitize_handle_map( $raw ) {
		$lines      = preg_split( '/\r\n|\r|\n/', (string) $raw );
		$categories = array_keys( self::categories() );
		$clean      = array();

		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || false === strpos( $line, '|' ) ) {
				continue;
			}
			list( $handle, $category ) = array_map( 'trim', explode( '|', $line, 2 ) );
			$handle                    = sanitize_key( $handle );
			$category                  = sanitize_key( $category );

			if ( '' === $handle || ! in_array( $category, $categories, true ) || 'necessary' === $category ) {
				continue;
			}
			$clean[ $handle ] = $handle . '|' . $category;
		}

		return implode( "\n", array_values( $clean ) );
	}

	/**
	 * Sanitise the "category|cookie-name-or-pattern" auto-clear map.
	 *
	 * @param mixed $raw Raw textarea content.
	 * @return string Newline-separated normalised map.
	 */
	public static function sanitize_autoclear_map( $raw ) {
		$lines      = preg_split( '/\r\n|\r|\n/', (string) $raw );
		$categories = array_keys( self::categories() );
		$clean      = array();

		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || false === strpos( $line, '|' ) ) {
				continue;
			}
			list( $category, $pattern ) = array_map( 'trim', explode( '|', $line, 2 ) );
			$category                   = sanitize_key( $category );

			// A cookie name or a simple anchored pattern. Anything that could turn
			// into a catastrophic or malformed RegExp on the client is rejected.
			$pattern = preg_replace( '/[^A-Za-z0-9_\-\^\.\[\]]/', '', (string) $pattern );

			if ( '' === $pattern || ! in_array( $category, $categories, true ) || 'necessary' === $category ) {
				continue;
			}

			$clean[ $category . '|' . $pattern ] = $category . '|' . $pattern;
		}

		return implode( "\n", array_values( $clean ) );
	}

	/**
	 * Parse the stored handle map into handle => category pairs.
	 *
	 * PERF: memoised — this is consulted once per script tag on the page.
	 *
	 * @return array<string,string>
	 */
	public static function get_handle_map() {
		if ( null !== self::$handle_map_cache ) {
			return self::$handle_map_cache;
		}

		self::$handle_map_cache = self::parse_pipe_map( (string) self::get( 'blocked_handles', '' ) );

		return self::$handle_map_cache;
	}

	/**
	 * Auto-clear map as category => array of cookie names/patterns.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function get_autoclear_map() {
		$raw   = (string) self::get( 'autoclear_cookies', '' );
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$map   = array();

		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || false === strpos( $line, '|' ) ) {
				continue;
			}
			list( $category, $pattern ) = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( '' === $category || '' === $pattern ) {
				continue;
			}
			if ( ! isset( $map[ $category ] ) ) {
				$map[ $category ] = array();
			}
			$map[ $category ][] = $pattern;
		}

		return $map;
	}

	/**
	 * Parse newline-separated "left|right" pairs into an associative array.
	 *
	 * @param string $raw Raw map.
	 * @return array<string,string>
	 */
	private static function parse_pipe_map( $raw ) {
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$map   = array();

		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || false === strpos( $line, '|' ) ) {
				continue;
			}
			list( $left, $right ) = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( '' !== $left && '' !== $right ) {
				$map[ $left ] = $right;
			}
		}

		return $map;
	}
}
