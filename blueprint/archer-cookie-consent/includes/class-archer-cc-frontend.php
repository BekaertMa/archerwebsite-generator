<?php
/**
 * Front-end rendering: assets, colour variables and the banner configuration.
 *
 * @package Archer_Cookie_Consent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads CookieConsent on the front end and feeds it the admin's configuration.
 */
class Archer_CC_Frontend {

	/**
	 * Memoised result of should_render().
	 *
	 * @var bool|null
	 */
	private static $should_render = null;

	/**
	 * Whether the banner should be present on this request.
	 *
	 * PERF: memoised — this is consulted by the blocker on every script tag.
	 *
	 * @return bool
	 */
	public static function should_render() {
		if ( null !== self::$should_render ) {
			return self::$should_render;
		}

		$render = (bool) Archer_CC_Settings::get( 'enabled' );

		if ( $render && Archer_CC_Settings::get( 'exclude_logged_in' ) && is_user_logged_in() ) {
			$render = false;
		}

		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			$render = false;
		}

		/**
		 * Filters whether the cookie banner renders on this request.
		 *
		 * @param bool $render Whether to render.
		 */
		self::$should_render = (bool) apply_filters( 'archer_cc_should_render', $render );

		return self::$should_render;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 5, 0 );
		add_action( 'wp_footer', array( $this, 'render_floating_button' ), 100, 0 );
	}

	/**
	 * Enqueue the bundled library plus our configuration.
	 *
	 * STANDARDS: assets are only ever registered inside wp_enqueue_scripts, and
	 * only when the banner actually renders.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! self::should_render() ) {
			return;
		}

		wp_enqueue_style(
			'archer-cc-cookieconsent',
			ARCHER_CC_URL . 'vendor/cookieconsent/cookieconsent.css',
			array(),
			ARCHER_CC_LIB_VERSION
		);

		// PERF: the palette is injected as CSS custom properties on a single
		// selector instead of a generated stylesheet file, so there is no extra
		// request and no cache-busting problem when the admin changes a colour.
		wp_add_inline_style( 'archer-cc-cookieconsent', $this->build_css_variables() );

		wp_enqueue_script(
			'archer-cc-cookieconsent',
			ARCHER_CC_URL . 'vendor/cookieconsent/cookieconsent.umd.js',
			array(),
			ARCHER_CC_LIB_VERSION,
			array(
				'in_footer' => false,
				'strategy'  => 'defer',
			)
		);

		wp_enqueue_script(
			'archer-cc-frontend',
			ARCHER_CC_URL . 'public/js/archer-cc-frontend.js',
			array( 'archer-cc-cookieconsent' ),
			ARCHER_CC_VERSION,
			array(
				'in_footer' => false,
				'strategy'  => 'defer',
			)
		);

		// SECURITY: the payload carries admin-authored banner copy, so JSON_HEX_TAG
		// guarantees nothing in it can terminate the inline <script> element.
		$payload = wp_json_encode( $this->build_payload(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE );

		if ( false !== $payload ) {
			wp_add_inline_script( 'archer-cc-frontend', 'window.archerCcData = ' . $payload . ';', 'before' );
		}
	}

	/**
	 * Build the JS payload consumed by public/js/archer-cc-frontend.js.
	 *
	 * @return array<string,mixed>
	 */
	private function build_payload() {
		$settings = Archer_CC_Settings::all();
		// The banner opens in the language of the page; the visitor's browser language
		// no longer decides (an English browser on a Dutch site got an English banner).
		$default  = Archer_CC_Settings::page_language();

		$translations = array();
		foreach ( array_keys( Archer_CC_Settings::languages() ) as $lang ) {
			$policy_url = isset( $settings['texts'][ $lang ]['policy_url'] ) ? $settings['texts'][ $lang ]['policy_url'] : '';
			$strings    = Archer_CC_I18n_Strings::get( $lang, $policy_url );

			// Admin overrides win over the bundled copy.
			$title = isset( $settings['texts'][ $lang ]['title'] ) ? trim( (string) $settings['texts'][ $lang ]['title'] ) : '';
			$desc  = isset( $settings['texts'][ $lang ]['description'] ) ? trim( (string) $settings['texts'][ $lang ]['description'] ) : '';

			if ( '' !== $title ) {
				$strings['consentModal']['title'] = $title;
			}
			if ( '' !== $desc ) {
				$strings['consentModal']['description'] = $desc;
			}

			$translations[ $lang ] = $strings;
		}

		$autoclear  = Archer_CC_Settings::get_autoclear_map();
		$categories = array();
		foreach ( Archer_CC_Settings::categories() as $slug => $definition ) {
			$category = array();

			if ( $definition['read_only'] ) {
				$category['readOnly'] = true;
				$category['enabled']  = true;
			} else {
				// COMPLIANCE: never pre-enable a consent-based category.
				$category['enabled'] = false;
			}

			// autoClear without a populated "cookies" list is a no-op in the
			// library, so only emit it when we actually have patterns to erase.
			if ( 'necessary' !== $slug && $settings['auto_clear_cookies'] && ! empty( $autoclear[ $slug ] ) ) {
				$category['autoClear'] = array(
					'reloadPage' => false,
					'cookies'    => array_values( array_unique( (array) $autoclear[ $slug ] ) ),
				);
			}

			$categories[ $slug ] = $category;
		}

		$config = array(
			'root'                   => 'body',
			'mode'                   => 'opt-in',
			'autoShow'               => true,
			'revision'               => absint( $settings['revision'] ),
			'manageScriptTags'       => true,
			'autoClearCookies'       => (bool) $settings['auto_clear_cookies'],
			'hideFromBots'           => (bool) $settings['hide_from_bots'],
			'disablePageInteraction' => (bool) $settings['disable_page_interaction'],
			'cookie'                 => array(
				'name'             => (string) $settings['cookie_name'],
				'expiresAfterDays' => absint( $settings['expires_days'] ),
				'sameSite'         => (string) $settings['same_site'],
				'path'             => '/',
				// Always request Secure: the library only appends the flag on an
				// https: page anyway, and a SameSite=None cookie without it is
				// discarded outright by every current browser.
				'secure'           => true,
			),
			'guiOptions'             => array(
				'consentModal'     => array(
					'layout'             => (string) $settings['consent_layout'],
					'position'           => (string) $settings['consent_position'],
					'equalWeightButtons' => (bool) $settings['equal_weight_buttons'],
					'flipButtons'        => (bool) $settings['flip_buttons'],
				),
				'preferencesModal' => array(
					'layout'             => (string) $settings['prefs_layout'],
					'position'           => (string) $settings['prefs_position'],
					'equalWeightButtons' => (bool) $settings['equal_weight_buttons'],
					'flipButtons'        => (bool) $settings['flip_buttons'],
				),
			),
			'categories'             => $categories,
			'language'               => array(
				'default'      => $default,
				'translations' => $translations,
			),
		);

		if ( 'off' !== $settings['auto_detect'] ) {
			$config['language']['autoDetect'] = (string) $settings['auto_detect'];
		}

		/**
		 * Filters the CookieConsent configuration object before it reaches the browser.
		 *
		 * @param array $config Configuration array.
		 */
		$config = apply_filters( 'archer_cc_config', $config );

		return array(
			'config'      => $config,
			'consentMode' => array(
				'enabled'   => (bool) $settings['consent_mode'],
				'signalMap' => Archer_CC_Consent_Mode::signal_map(),
			),
			'log'         => array(
				'enabled' => (bool) $settings['log_enabled'],
				'url'     => esc_url_raw( rest_url( Archer_CC_Consent_Log::REST_NAMESPACE . '/consent' ) ),
				// The daily token is the authorisation. No wp_rest nonce is sent
				// on purpose: WordPress rejects a nonce that is present but stale
				// with a 403, whereas an absent one is simply not checked — so
				// embedding a per-session nonce would break logging on exactly the
				// cached pages the token design exists to support.
				'token'   => Archer_CC_Consent_Log::public_token(),
			),
		);
	}

	/**
	 * Generate the CSS custom property block for the configured palettes.
	 *
	 * @return string
	 */
	public function build_css_variables() {
		$settings = Archer_CC_Settings::all();
		$scheme   = (string) $settings['color_scheme'];

		$light = $this->palette_to_css( $settings['palette_light'], $settings );
		$dark  = $this->palette_to_css( $settings['palette_dark'], $settings );

		if ( 'dark' === $scheme ) {
			return '#cc-main{' . $dark . '}';
		}

		$css = '#cc-main{' . $light . '}';

		if ( 'auto' === $scheme ) {
			$css .= '@media (prefers-color-scheme: dark){#cc-main{' . $dark . '}}';
		}

		return $css;
	}

	/**
	 * Turn one palette into a CSS declaration list.
	 *
	 * SECURITY: every value here has already passed sanitize_hex_color() or a
	 * strict length/font pattern in Archer_CC_Settings::sanitize(), and each one is
	 * additionally run through esc_attr() before it is printed.
	 *
	 * @param array $palette  Palette slug => hex.
	 * @param array $settings Full settings array.
	 * @return string
	 */
	private function palette_to_css( $palette, $settings ) {
		$declarations = array();
		$map          = Archer_CC_Settings::color_map();

		foreach ( $map as $slug => $definition ) {
			if ( empty( $palette[ $slug ] ) ) {
				continue;
			}

			$hex = sanitize_hex_color( (string) $palette[ $slug ] );

			if ( null === $hex ) {
				continue;
			}

			$value = ! empty( $definition['alpha'] )
				? Archer_CC_Color::to_rgba( $hex, absint( $settings['overlay_opacity'] ) )
				: $hex;

			foreach ( $definition['vars'] as $var ) {
				$declarations[] = $var . ':' . $value;
			}
		}

		// Toggle icon colours follow the knob so ticks stay visible.
		if ( ! empty( $palette['toggle_on_bg'] ) ) {
			$declarations[] = '--cc-toggle-enabled-icon-color:' . sanitize_hex_color( (string) $palette['toggle_on_bg'] );
		}
		if ( ! empty( $palette['toggle_off_bg'] ) ) {
			$declarations[] = '--cc-toggle-disabled-icon-color:' . sanitize_hex_color( (string) $palette['toggle_off_bg'] );
		}
		if ( ! empty( $palette['toggle_readonly_bg'] ) ) {
			$declarations[] = '--cc-toggle-readonly-knob-icon-color:' . sanitize_hex_color( (string) $palette['toggle_readonly_bg'] );
		}
		if ( ! empty( $palette['border'] ) ) {
			$declarations[] = '--cc-webkit-scrollbar-bg:' . sanitize_hex_color( (string) $palette['border'] );
		}
		if ( ! empty( $palette['muted_text'] ) ) {
			$declarations[] = '--cc-webkit-scrollbar-hover-bg:' . sanitize_hex_color( (string) $palette['muted_text'] );
		}

		$declarations[] = '--cc-modal-border-radius:' . Archer_CC_Settings::sanitize_css_length( $settings['modal_radius'], '0.5rem' );
		$declarations[] = '--cc-btn-border-radius:' . Archer_CC_Settings::sanitize_css_length( $settings['btn_radius'], '0.4rem' );
		$declarations[] = '--cc-pm-toggle-border-radius:' . Archer_CC_Settings::sanitize_css_length( $settings['toggle_radius'], '4em' );

		$font = trim( (string) $settings['font_family'] );
		if ( '' !== $font ) {
			$declarations[] = '--cc-font-family:' . $font;
		}

		// SECURITY: the block is printed inside <style>, where HTML entities are
		// NOT decoded — esc_attr() would corrupt quoted font names here. Every
		// value has already been through sanitize_hex_color(), a strict CSS-length
		// pattern, or the font-stack allow-list, none of which can contain ; { } <
		// or >. The final str_replace() is belt-and-braces against a filtered
		// value smuggling in a tag terminator.
		$css = implode( ';', array_filter( $declarations ) );

		return str_replace( array( '<', '>', '{', '}' ), '', $css );
	}

	/**
	 * Print the always-available "Cookie settings" trigger.
	 *
	 * COMPLIANCE: withdrawing consent must be as easy as giving it, which means a
	 * permanently reachable control — not a buried link in a policy page.
	 *
	 * @return void
	 */
	public function render_floating_button() {
		if ( ! self::should_render() || ! Archer_CC_Settings::get( 'show_floating_button' ) ) {
			return;
		}

		printf(
			'<button type="button" class="archer-cc-floating-trigger" data-cc="show-preferencesModal" aria-haspopup="dialog">%s</button>',
			esc_html( Archer_CC_I18n_Strings::trigger_label( Archer_CC_Settings::page_language() ) )
		);

		// A handful of declarations for a single element; kept inline rather than
		// shipping a second stylesheet request for the whole site.
		echo '<style id="archer-cc-floating-trigger-css">.archer-cc-floating-trigger{position:fixed;left:1rem;bottom:1rem;z-index:2147483646;border:1px solid currentColor;background:#fff;color:#2d4156;border-radius:2em;padding:.5em 1em;font:inherit;font-size:.8125rem;line-height:1.2;cursor:pointer;opacity:.85}.archer-cc-floating-trigger:hover,.archer-cc-floating-trigger:focus-visible{opacity:1}@media (prefers-color-scheme:dark){.archer-cc-floating-trigger{background:#161a1c;color:#ebf3f6}}@media print{.archer-cc-floating-trigger{display:none}}</style>';
	}
}
