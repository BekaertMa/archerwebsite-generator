<?php
/**
 * Admin settings screen, built entirely on the Settings API.
 *
 * @package Archer_Cookie_Consent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings screen controller.
 */
class Archer_CC_Admin {

	const PAGE_SLUG  = 'archer-cookie-consent';
	const CAPABILITY = 'manage_options';

	/**
	 * Tab slug => label.
	 *
	 * @return array<string,string>
	 */
	public static function tabs() {
		return array(
			'general'    => __( 'General', 'archer-cookie-consent' ),
			'layout'     => __( 'Layout', 'archer-cookie-consent' ),
			'appearance' => __( 'Colours', 'archer-cookie-consent' ),
			'content'    => __( 'Texts', 'archer-cookie-consent' ),
			'google'     => __( 'Google & scripts', 'archer-cookie-consent' ),
			'log'        => __( 'Consent log', 'archer-cookie-consent' ),
		);
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 10, 0 );
		add_action( 'admin_init', array( $this, 'register_settings' ), 10, 0 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 10, 1 );
		add_action( 'admin_notices', array( $this, 'render_compliance_notices' ), 10, 0 );
		add_action( 'admin_post_archer_cc_export_log', array( 'Archer_CC_Consent_Log', 'export_csv' ), 10, 0 );
		add_filter( 'plugin_action_links_' . plugin_basename( ARCHER_CC_FILE ), array( $this, 'add_action_link' ), 10, 1 );
	}

	/**
	 * Add the settings page.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_options_page(
			__( 'Cookie Consent', 'archer-cookie-consent' ),
			__( 'Cookie Consent', 'archer-cookie-consent' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Add a "Settings" link on the plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function add_action_link( $links ) {
		$url = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'options-general.php' ) );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'archer-cookie-consent' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Current tab, validated against the tab whitelist.
	 *
	 * @return string
	 */
	private function current_tab() {
		// SECURITY: read-only navigation state; sanitised and whitelisted. A nonce
		// is not applicable to a GET tab switch that changes nothing.
		$raw = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return array_key_exists( $raw, self::tabs() ) ? $raw : 'general';
	}

	/**
	 * Enqueue admin assets — only on our own screen.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style(
			'archer-cc-admin',
			ARCHER_CC_URL . 'admin/css/archer-cc-admin.css',
			array( 'wp-color-picker' ),
			ARCHER_CC_VERSION
		);

		wp_enqueue_script(
			'archer-cc-admin',
			ARCHER_CC_URL . 'admin/js/archer-cc-admin.js',
			array( 'jquery', 'wp-color-picker' ),
			ARCHER_CC_VERSION,
			true
		);
	}

	/**
	 * Register every setting, section and field.
	 *
	 * ADMIN: one option, one sanitise callback, registered per tab group so that
	 * saving one tab never clobbers another (the callback merges).
	 *
	 * @return void
	 */
	public function register_settings() {
		foreach ( array_keys( self::tabs() ) as $tab ) {
			if ( 'log' === $tab ) {
				continue;
			}

			register_setting(
				'archer_cc_group_' . $tab,
				ARCHER_CC_OPTION,
				array(
					'type'              => 'array',
					'sanitize_callback' => array( 'Archer_CC_Settings', 'sanitize' ),
					'default'           => Archer_CC_Settings::defaults(),
					'show_in_rest'      => false,
				)
			);
		}

		$this->register_general_fields();
		$this->register_layout_fields();
		$this->register_appearance_fields();
		$this->register_content_fields();
		$this->register_google_fields();
	}

	/**
	 * Shortcut for add_settings_field().
	 *
	 * @param string $tab     Tab slug.
	 * @param string $section Section id.
	 * @param string $key     Setting key.
	 * @param string $title   Field label.
	 * @param string $type    Field renderer type.
	 * @param array  $args    Extra renderer arguments.
	 * @return void
	 */
	private function field( $tab, $section, $key, $title, $type, $args = array() ) {
		$base = array(
			'key'  => $key,
			'type' => $type,
		);

		// ADMIN: a checkbox renders its own <label>, so adding label_for would make
		// do_settings_fields() wrap the row title in a second label for the same
		// input and screen readers would announce it twice.
		if ( 'checkbox' !== $type ) {
			$base['label_for'] = 'archer-cc-' . $key;
		}

		add_settings_field(
			'archer_cc_' . $key,
			$title,
			array( $this, 'render_field' ),
			'archer_cc_page_' . $tab,
			$section,
			array_merge( $base, $args )
		);
	}

	/**
	 * General tab.
	 *
	 * @return void
	 */
	private function register_general_fields() {
		add_settings_section(
			'archer_cc_section_general',
			__( 'Banner behaviour', 'archer-cookie-consent' ),
			array( $this, 'section_general_intro' ),
			'archer_cc_page_general'
		);

		$this->field( 'general', 'archer_cc_section_general', 'enabled', __( 'Enable the banner', 'archer-cookie-consent' ), 'checkbox', array( 'description' => __( 'Turn the whole front-end banner and script blocking on or off.', 'archer-cookie-consent' ) ) );
		$this->field( 'general', 'archer_cc_section_general', 'default_language', __( 'Default language', 'archer-cookie-consent' ), 'select', array( 'options' => Archer_CC_Settings::languages() ) );
		$this->field(
			'general',
			'archer_cc_section_general',
			'auto_detect',
			__( 'Language detection', 'archer-cookie-consent' ),
			'select',
			array(
				'options'     => array(
					'browser'  => __( 'Follow the visitor’s browser language', 'archer-cookie-consent' ),
					'document' => __( 'Follow the <html lang> attribute', 'archer-cookie-consent' ),
					'off'      => __( 'Always use the default language', 'archer-cookie-consent' ),
				),
				'description' => __( 'Falls back to the default language when the detected one is not available.', 'archer-cookie-consent' ),
			)
		);
		$this->field(
			'general',
			'archer_cc_section_general',
			'expires_days',
			__( 'Consent lifetime (days)', 'archer-cookie-consent' ),
			'number',
			array(
				'min'         => 1,
				'max'         => 395,
				'description' => __( 'How long a choice is remembered before the visitor is asked again. 182 days (6 months) is the conservative EU default; regulators such as the CNIL treat anything beyond 13 months as no longer informed, so the field is capped at 395.', 'archer-cookie-consent' ),
			)
		);
		$this->field(
			'general',
			'archer_cc_section_general',
			'revision',
			__( 'Policy revision', 'archer-cookie-consent' ),
			'number',
			array(
				'min'         => 0,
				'description' => __( 'Increase this number whenever your cookie use changes materially. Every visitor is then asked for consent again, which is what keeps old consent valid.', 'archer-cookie-consent' ),
			)
		);
		$this->field( 'general', 'archer_cc_section_general', 'cookie_name', __( 'Consent cookie name', 'archer-cookie-consent' ), 'text' );
		$this->field(
			'general',
			'archer_cc_section_general',
			'same_site',
			__( 'SameSite', 'archer-cookie-consent' ),
			'select',
			array(
				'options' => array(
					'Lax'    => 'Lax',
					'Strict' => 'Strict',
					'None'   => 'None',
				),
			)
		);
		$this->field( 'general', 'archer_cc_section_general', 'auto_clear_cookies', __( 'Clear rejected cookies', 'archer-cookie-consent' ), 'checkbox', array( 'description' => __( 'Erase the cookies listed below when a visitor refuses or withdraws consent for their category.', 'archer-cookie-consent' ) ) );
		$this->field(
			'general',
			'archer_cc_section_general',
			'autoclear_cookies',
			__( 'Cookies to erase', 'archer-cookie-consent' ),
			'textarea',
			array(
				'rows'        => 10,
				'description' => __( 'One per line, in the form <code>category|cookie</code>. A value starting with <code>^</code> is treated as a pattern, so <code>analytics|^_ga</code> matches <code>_ga</code>, <code>_ga_ABC123</code> and so on. The defaults cover Google, Meta, Microsoft, TikTok, LinkedIn, Hotjar and Clarity — add your own for anything else, because a cookie that is not listed here will not be deleted.', 'archer-cookie-consent' ),
			)
		);
		$this->field( 'general', 'archer_cc_section_general', 'hide_from_bots', __( 'Hide from crawlers', 'archer-cookie-consent' ), 'checkbox', array( 'description' => __( 'Do not show the banner to detected bots, so it never ends up in search snippets.', 'archer-cookie-consent' ) ) );
		$this->field(
			'general',
			'archer_cc_section_general',
			'disable_page_interaction',
			__( 'Block page interaction', 'archer-cookie-consent' ),
			'checkbox',
			array(
				'description' => __( 'Adds a full-page overlay until a choice is made. Leave this off: an overlay that makes the site unusable until the visitor accepts is treated as a cookie wall, and cookie walls do not produce freely given consent.', 'archer-cookie-consent' ),
			)
		);
		$this->field( 'general', 'archer_cc_section_general', 'show_floating_button', __( 'Floating “Cookie settings” button', 'archer-cookie-consent' ), 'checkbox', array( 'description' => __( 'A permanently reachable control for changing or withdrawing consent. If you switch this off, place the [archer_cc_preferences] shortcode in your footer instead — withdrawal has to stay as easy as consenting.', 'archer-cookie-consent' ) ) );
		$this->field( 'general', 'archer_cc_section_general', 'exclude_logged_in', __( 'Hide for logged-in users', 'archer-cookie-consent' ), 'checkbox', array( 'description' => __( 'Convenient while editing, but remember that logged-in visitors also need a lawful basis for tracking.', 'archer-cookie-consent' ) ) );

		add_settings_section(
			'archer_cc_section_log',
			__( 'Proof of consent', 'archer-cookie-consent' ),
			array( $this, 'section_log_intro' ),
			'archer_cc_page_general'
		);

		$this->field( 'general', 'archer_cc_section_log', 'log_enabled', __( 'Log consent actions', 'archer-cookie-consent' ), 'checkbox' );
		$this->field( 'general', 'archer_cc_section_log', 'log_user_agent', __( 'Store the user agent', 'archer-cookie-consent' ), 'checkbox', array( 'description' => __( 'Helps distinguish devices in a dispute. Switch it off if you prefer stricter data minimisation.', 'archer-cookie-consent' ) ) );
		$this->field(
			'general',
			'archer_cc_section_log',
			'log_retention_days',
			__( 'Keep records for (days)', 'archer-cookie-consent' ),
			'number',
			array(
				'min'         => 30,
				'max'         => 2000,
				'description' => __( 'Older records are deleted automatically by a daily task. Keep them at least as long as the consent they document.', 'archer-cookie-consent' ),
			)
		);
		$this->field( 'general', 'archer_cc_section_log', 'delete_data_on_uninstall', __( 'Delete all data on uninstall', 'archer-cookie-consent' ), 'checkbox', array( 'description' => __( 'Drops the consent log table and removes the settings when the plugin is deleted.', 'archer-cookie-consent' ) ) );
	}

	/**
	 * Layout tab.
	 *
	 * @return void
	 */
	private function register_layout_fields() {
		add_settings_section(
			'archer_cc_section_layout',
			__( 'Placement', 'archer-cookie-consent' ),
			'__return_false',
			'archer_cc_page_layout'
		);

		$this->field(
			'layout',
			'archer_cc_section_layout',
			'consent_layout',
			__( 'Banner layout', 'archer-cookie-consent' ),
			'select',
			array(
				'options' => array(
					'box'          => __( 'Box', 'archer-cookie-consent' ),
					'box wide'     => __( 'Box — wide', 'archer-cookie-consent' ),
					'box inline'   => __( 'Box — inline buttons', 'archer-cookie-consent' ),
					'cloud'        => __( 'Cloud', 'archer-cookie-consent' ),
					'cloud inline' => __( 'Cloud — inline buttons', 'archer-cookie-consent' ),
					'bar'          => __( 'Bar', 'archer-cookie-consent' ),
					'bar inline'   => __( 'Bar — inline buttons', 'archer-cookie-consent' ),
				),
			)
		);
		$this->field(
			'layout',
			'archer_cc_section_layout',
			'consent_position',
			__( 'Banner position', 'archer-cookie-consent' ),
			'select',
			array(
				'options' => array(
					'top left'      => __( 'Top left', 'archer-cookie-consent' ),
					'top center'    => __( 'Top center', 'archer-cookie-consent' ),
					'top right'     => __( 'Top right', 'archer-cookie-consent' ),
					'middle left'   => __( 'Middle left', 'archer-cookie-consent' ),
					'middle center' => __( 'Middle center', 'archer-cookie-consent' ),
					'middle right'  => __( 'Middle right', 'archer-cookie-consent' ),
					'bottom left'   => __( 'Bottom left', 'archer-cookie-consent' ),
					'bottom center' => __( 'Bottom center', 'archer-cookie-consent' ),
					'bottom right'  => __( 'Bottom right', 'archer-cookie-consent' ),
				),
			)
		);
		$this->field(
			'layout',
			'archer_cc_section_layout',
			'prefs_layout',
			__( 'Preferences layout', 'archer-cookie-consent' ),
			'select',
			array(
				'options' => array(
					'box'      => __( 'Box', 'archer-cookie-consent' ),
					'bar'      => __( 'Bar', 'archer-cookie-consent' ),
					'bar wide' => __( 'Bar — wide', 'archer-cookie-consent' ),
				),
			)
		);
		$this->field(
			'layout',
			'archer_cc_section_layout',
			'prefs_position',
			__( 'Preferences position', 'archer-cookie-consent' ),
			'select',
			array(
				'options' => array(
					'left'  => __( 'Left', 'archer-cookie-consent' ),
					'right' => __( 'Right', 'archer-cookie-consent' ),
				),
			)
		);
		$this->field(
			'layout',
			'archer_cc_section_layout',
			'equal_weight_buttons',
			__( 'Equal-weight buttons', 'archer-cookie-consent' ),
			'checkbox',
			array(
				'description' => __( 'Gives “Accept all” and “Reject all” the same size and layout weight. The EDPB cookie banner taskforce singles out an accept button that is more prominent than reject as a reason to consider consent invalid — keep this on.', 'archer-cookie-consent' ),
			)
		);
		$this->field( 'layout', 'archer_cc_section_layout', 'flip_buttons', __( 'Swap button order', 'archer-cookie-consent' ), 'checkbox' );
	}

	/**
	 * Colours tab.
	 *
	 * @return void
	 */
	private function register_appearance_fields() {
		add_settings_section(
			'archer_cc_section_appearance',
			__( 'Shape & typography', 'archer-cookie-consent' ),
			'__return_false',
			'archer_cc_page_appearance'
		);

		$this->field(
			'appearance',
			'archer_cc_section_appearance',
			'color_scheme',
			__( 'Colour scheme', 'archer-cookie-consent' ),
			'select',
			array(
				'options' => array(
					'light' => __( 'Always light', 'archer-cookie-consent' ),
					'dark'  => __( 'Always dark', 'archer-cookie-consent' ),
					'auto'  => __( 'Follow the visitor’s system setting', 'archer-cookie-consent' ),
				),
			)
		);
		$this->field( 'appearance', 'archer_cc_section_appearance', 'font_family', __( 'Font stack', 'archer-cookie-consent' ), 'text', array( 'description' => __( 'For example: Readex Pro, Helvetica, Arial, sans-serif. Leave empty to inherit the library default. This plugin does not load any web font for you.', 'archer-cookie-consent' ) ) );
		$this->field(
			'appearance',
			'archer_cc_section_appearance',
			'modal_radius',
			__( 'Modal corner radius', 'archer-cookie-consent' ),
			'text',
			array(
				'small'       => true,
				'description' => __( 'A CSS length such as 0.5rem, 8px or 0.', 'archer-cookie-consent' ),
			)
		);
		$this->field( 'appearance', 'archer_cc_section_appearance', 'btn_radius', __( 'Button corner radius', 'archer-cookie-consent' ), 'text', array( 'small' => true ) );
		$this->field( 'appearance', 'archer_cc_section_appearance', 'toggle_radius', __( 'Toggle corner radius', 'archer-cookie-consent' ), 'text', array( 'small' => true ) );
		$this->field(
			'appearance',
			'archer_cc_section_appearance',
			'overlay_opacity',
			__( 'Overlay opacity (%)', 'archer-cookie-consent' ),
			'number',
			array(
				'min' => 0,
				'max' => 100,
			)
		);
	}

	/**
	 * Texts tab.
	 *
	 * @return void
	 */
	private function register_content_fields() {
		foreach ( Archer_CC_Settings::languages() as $lang => $label ) {
			$section = 'archer_cc_section_text_' . $lang;

			add_settings_section(
				$section,
				/* translators: %s: language name */
				sprintf( __( '%s texts', 'archer-cookie-consent' ), $label ),
				'__return_false',
				'archer_cc_page_content'
			);

			$defaults = Archer_CC_I18n_Strings::get( $lang );

			$this->field(
				'content',
				$section,
				'texts_' . $lang . '_title',
				__( 'Banner title', 'archer-cookie-consent' ),
				'lang_text',
				array(
					'lang'        => $lang,
					'subkey'      => 'title',
					'placeholder' => $defaults['consentModal']['title'],
				)
			);
			$this->field(
				'content',
				$section,
				'texts_' . $lang . '_description',
				__( 'Banner text', 'archer-cookie-consent' ),
				'lang_textarea',
				array(
					'lang'        => $lang,
					'subkey'      => 'description',
					'placeholder' => wp_strip_all_tags( $defaults['consentModal']['description'] ),
					'description' => __( 'Leave empty to use the built-in text. Allowed markup: a, strong, em, br.', 'archer-cookie-consent' ),
				)
			);
			$this->field(
				'content',
				$section,
				'texts_' . $lang . '_policy_url',
				__( 'Cookie policy URL', 'archer-cookie-consent' ),
				'lang_url',
				array(
					'lang'        => $lang,
					'subkey'      => 'policy_url',
					'description' => __( 'Linked from the banner text. Required for a transparent first layer.', 'archer-cookie-consent' ),
				)
			);
		}
	}

	/**
	 * Google & scripts tab.
	 *
	 * @return void
	 */
	private function register_google_fields() {
		add_settings_section(
			'archer_cc_section_consent_mode',
			__( 'Google Consent Mode v2', 'archer-cookie-consent' ),
			array( $this, 'section_consent_mode_intro' ),
			'archer_cc_page_google'
		);

		$this->field( 'google', 'archer_cc_section_consent_mode', 'consent_mode', __( 'Enable Consent Mode v2', 'archer-cookie-consent' ), 'checkbox' );
		$this->field(
			'google',
			'archer_cc_section_consent_mode',
			'consent_mode_type',
			__( 'Implementation', 'archer-cookie-consent' ),
			'select',
			array(
				'options'     => array(
					'advanced' => __( 'Advanced — load Google tags immediately in denied state', 'archer-cookie-consent' ),
					'basic'    => __( 'Basic — do not load Google tags at all until consent', 'archer-cookie-consent' ),
				),
				'description' => __( 'Advanced keeps conversion modelling available because the tag loads with all storage denied and sends cookieless pings. Basic is the stricter reading of ePrivacy Art. 5(3): nothing from Google is requested before consent, at the cost of modelling.', 'archer-cookie-consent' ),
			)
		);
		$this->field(
			'google',
			'archer_cc_section_consent_mode',
			'consent_mode_wait',
			__( 'wait_for_update (ms)', 'archer-cookie-consent' ),
			'number',
			array(
				'min' => 0,
				'max' => 5000,
			)
		);
		$this->field( 'google', 'archer_cc_section_consent_mode', 'ads_data_redaction', __( 'Redact ads data while denied', 'archer-cookie-consent' ), 'checkbox' );
		$this->field( 'google', 'archer_cc_section_consent_mode', 'url_passthrough', __( 'URL passthrough', 'archer-cookie-consent' ), 'checkbox', array( 'description' => __( 'Passes click identifiers through URLs while storage is denied. Assess this against your own DPA’s position before enabling.', 'archer-cookie-consent' ) ) );
		$this->field( 'google', 'archer_cc_section_consent_mode', 'gtm_id', __( 'Google Tag Manager ID', 'archer-cookie-consent' ), 'text', array( 'placeholder' => 'GTM-XXXXXXX' ) );
		$this->field( 'google', 'archer_cc_section_consent_mode', 'ga4_id', __( 'GA4 / Ads ID', 'archer-cookie-consent' ), 'text', array( 'placeholder' => 'G-XXXXXXXXXX' ) );

		add_settings_section(
			'archer_cc_section_blocking',
			__( 'Script blocking', 'archer-cookie-consent' ),
			array( $this, 'section_blocking_intro' ),
			'archer_cc_page_google'
		);

		$this->field(
			'google',
			'archer_cc_section_blocking',
			'blocked_handles',
			__( 'Blocked script handles', 'archer-cookie-consent' ),
			'textarea',
			array(
				'rows'        => 8,
				'description' => __( 'One per line, in the form <code>handle|category</code>. Categories: functionality, analytics, marketing. Example: <code>hotjar|analytics</code>.', 'archer-cookie-consent' ),
			)
		);
	}

	/**
	 * General section intro.
	 *
	 * @return void
	 */
	public function section_general_intro() {
		echo '<p>' . esc_html__( 'Non-necessary cookies are never set before the visitor makes a choice, no category is pre-selected, and “Reject all” sits on the first layer next to “Accept all”. Those three properties are not configurable on purpose.', 'archer-cookie-consent' ) . '</p>';
	}

	/**
	 * Log section intro.
	 *
	 * @return void
	 */
	public function section_log_intro() {
		echo '<p>' . esc_html__( 'Article 7(1) GDPR puts the burden of demonstrating consent on you. Each action is stored with a timestamp, the categories chosen, the policy revision and a salted hash of the IP address — never the address itself.', 'archer-cookie-consent' ) . '</p>';
	}

	/**
	 * Consent Mode section intro.
	 *
	 * @return void
	 */
	public function section_consent_mode_intro() {
		echo '<p>' . esc_html__( 'Consent Mode v2 is mandatory in the EEA if you use Google Ads or GA4 audience features. The default state is emitted before any Google tag with every signal denied except security_storage, and updated the moment the visitor chooses.', 'archer-cookie-consent' ) . '</p>';
	}

	/**
	 * Blocking section intro.
	 *
	 * @return void
	 */
	public function section_blocking_intro() {
		echo '<p>' . wp_kses_post( __( 'Any script listed here is rewritten to <code>type="text/plain"</code> with a <code>data-category</code> attribute, so the browser cannot execute it until the matching consent is given. For hand-written tags in a template, use <code>&lt;script &lt;?php echo archer_cc_blocked_script_atts( "marketing" ); ?&gt;&gt;</code>.', 'archer-cookie-consent' ) ) . '</p>';
	}

	/**
	 * Render one settings field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_field( $args ) {
		$settings = Archer_CC_Settings::all();
		$key      = isset( $args['key'] ) ? $args['key'] : '';
		$type     = isset( $args['type'] ) ? $args['type'] : 'text';
		$id       = 'archer-cc-' . $key;
		$option   = ARCHER_CC_OPTION;

		switch ( $type ) {
			case 'checkbox':
				$value = ! empty( $settings[ $key ] );
				printf(
					'<input type="hidden" name="%1$s[%2$s]" value="0" /><label for="%3$s"><input type="checkbox" id="%3$s" name="%1$s[%2$s]" value="1" %4$s /> %5$s</label>',
					esc_attr( $option ),
					esc_attr( $key ),
					esc_attr( $id ),
					checked( $value, true, false ),
					esc_html__( 'Enabled', 'archer-cookie-consent' )
				);
				break;

			case 'select':
				$value   = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
				$choices = isset( $args['options'] ) ? (array) $args['options'] : array();
				printf( '<select id="%1$s" name="%2$s[%3$s]">', esc_attr( $id ), esc_attr( $option ), esc_attr( $key ) );
				foreach ( $choices as $choice_value => $choice_label ) {
					printf(
						'<option value="%1$s" %2$s>%3$s</option>',
						esc_attr( $choice_value ),
						selected( $value, (string) $choice_value, false ),
						esc_html( $choice_label )
					);
				}
				echo '</select>';
				break;

			case 'number':
				printf(
					'<input type="number" id="%1$s" name="%2$s[%3$s]" value="%4$s" class="small-text" min="%5$s" max="%6$s" step="1" />',
					esc_attr( $id ),
					esc_attr( $option ),
					esc_attr( $key ),
					esc_attr( isset( $settings[ $key ] ) ? $settings[ $key ] : '' ),
					esc_attr( isset( $args['min'] ) ? $args['min'] : '' ),
					esc_attr( isset( $args['max'] ) ? $args['max'] : '' )
				);
				break;

			case 'textarea':
				printf(
					'<textarea id="%1$s" name="%2$s[%3$s]" rows="%4$d" class="large-text code">%5$s</textarea>',
					esc_attr( $id ),
					esc_attr( $option ),
					esc_attr( $key ),
					absint( isset( $args['rows'] ) ? $args['rows'] : 5 ),
					esc_textarea( isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '' )
				);
				break;

			case 'lang_text':
			case 'lang_url':
				$lang   = isset( $args['lang'] ) ? $args['lang'] : 'en';
				$subkey = isset( $args['subkey'] ) ? $args['subkey'] : 'title';
				printf(
					'<input type="%1$s" id="%2$s" name="%3$s[texts][%4$s][%5$s]" value="%6$s" class="regular-text" placeholder="%7$s" />',
					'lang_url' === $type ? 'url' : 'text',
					esc_attr( $id ),
					esc_attr( $option ),
					esc_attr( $lang ),
					esc_attr( $subkey ),
					esc_attr( isset( $settings['texts'][ $lang ][ $subkey ] ) ? $settings['texts'][ $lang ][ $subkey ] : '' ),
					esc_attr( isset( $args['placeholder'] ) ? $args['placeholder'] : '' )
				);
				break;

			case 'lang_textarea':
				$lang   = isset( $args['lang'] ) ? $args['lang'] : 'en';
				$subkey = isset( $args['subkey'] ) ? $args['subkey'] : 'description';
				printf(
					'<textarea id="%1$s" name="%2$s[texts][%3$s][%4$s]" rows="5" class="large-text" placeholder="%5$s">%6$s</textarea>',
					esc_attr( $id ),
					esc_attr( $option ),
					esc_attr( $lang ),
					esc_attr( $subkey ),
					esc_attr( isset( $args['placeholder'] ) ? $args['placeholder'] : '' ),
					esc_textarea( isset( $settings['texts'][ $lang ][ $subkey ] ) ? (string) $settings['texts'][ $lang ][ $subkey ] : '' )
				);
				break;

			case 'text':
			default:
				printf(
					'<input type="text" id="%1$s" name="%2$s[%3$s]" value="%4$s" class="%5$s" placeholder="%6$s" />',
					esc_attr( $id ),
					esc_attr( $option ),
					esc_attr( $key ),
					esc_attr( isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '' ),
					empty( $args['small'] ) ? 'regular-text' : 'small-text',
					esc_attr( isset( $args['placeholder'] ) ? $args['placeholder'] : '' )
				);
				break;
		}

		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . wp_kses_post( $args['description'] ) . '</p>';
		}
	}

	/**
	 * Render the colour pickers for one palette.
	 *
	 * @param string $palette_key Either palette_light or palette_dark.
	 * @param string $label       Human label.
	 * @return void
	 */
	private function render_palette_grid( $palette_key, $label ) {
		$settings = Archer_CC_Settings::all();
		$palette  = isset( $settings[ $palette_key ] ) ? (array) $settings[ $palette_key ] : array();
		$defaults = 'palette_light' === $palette_key ? Archer_CC_Settings::default_palette_light() : Archer_CC_Settings::default_palette_dark();

		echo '<div class="archer-cc-palette">';
		echo '<h3>' . esc_html( $label ) . '</h3>';
		echo '<div class="archer-cc-palette-grid">';

		foreach ( Archer_CC_Settings::color_map() as $slug => $definition ) {
			$value = isset( $palette[ $slug ] ) ? $palette[ $slug ] : $defaults[ $slug ];
			$id    = 'archer-cc-' . $palette_key . '-' . $slug;

			printf(
				'<p class="archer-cc-color-field"><label for="%1$s">%2$s</label><input type="text" id="%1$s" class="archer-cc-color-picker" name="%3$s[%4$s][%5$s]" value="%6$s" data-default-color="%7$s" /></p>',
				esc_attr( $id ),
				esc_html( $definition['label'] ),
				esc_attr( ARCHER_CC_OPTION ),
				esc_attr( $palette_key ),
				esc_attr( $slug ),
				esc_attr( $value ),
				esc_attr( $defaults[ $slug ] )
			);
		}

		echo '</div></div>';
	}

	/**
	 * Render the palette audit panel.
	 *
	 * @return void
	 */
	private function render_palette_audit() {
		$settings = Archer_CC_Settings::all();
		// Only audit palettes that can actually be rendered.
		$issues = array_merge(
			'dark' === $settings['color_scheme'] ? array() : Archer_CC_Color::audit_palette( $settings['palette_light'], __( 'Light', 'archer-cookie-consent' ) ),
			'light' === $settings['color_scheme'] ? array() : Archer_CC_Color::audit_palette( $settings['palette_dark'], __( 'Dark', 'archer-cookie-consent' ) )
		);

		echo '<div class="archer-cc-audit">';
		echo '<h3>' . esc_html__( 'Palette compliance check', 'archer-cookie-consent' ) . '</h3>';

		if ( empty( $issues ) ) {
			echo '<p class="archer-cc-audit-ok">' . esc_html__( 'No contrast or prominence problems detected in the saved palettes.', 'archer-cookie-consent' ) . '</p>';
		} else {
			echo '<ul>';
			foreach ( $issues as $issue ) {
				printf(
					'<li class="archer-cc-audit-%1$s">%2$s</li>',
					esc_attr( $issue['level'] ),
					esc_html( $issue['message'] )
				);
			}
			echo '</ul>';
		}

		echo '<p class="description">' . esc_html__( 'Checks run against the saved values — save your changes to re-run them.', 'archer-cookie-consent' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Surface configuration choices that weaken compliance.
	 *
	 * @return void
	 */
	public function render_compliance_notices() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || 'settings_page_' . self::PAGE_SLUG !== $screen->id ) {
			return;
		}

		$settings = Archer_CC_Settings::all();
		$warnings = array();

		if ( ! $settings['equal_weight_buttons'] ) {
			$warnings[] = __( 'Equal-weight buttons are switched off. Reject must carry the same visual weight as accept.', 'archer-cookie-consent' );
		}

		if ( $settings['disable_page_interaction'] ) {
			$warnings[] = __( '“Block page interaction” is on. Making the site unusable until the visitor accepts is a cookie wall and undermines freely given consent.', 'archer-cookie-consent' );
		}

		if ( ! $settings['show_floating_button'] ) {
			$warnings[] = __( 'The floating “Cookie settings” button is off. Make sure the [archer_cc_preferences] shortcode is present on every page, or visitors cannot withdraw consent.', 'archer-cookie-consent' );
		}

		if ( ! $settings['log_enabled'] ) {
			$warnings[] = __( 'Consent logging is off, so you have no record to demonstrate consent under Article 7(1) GDPR.', 'archer-cookie-consent' );
		}

		if ( $settings['expires_days'] > 395 ) {
			$warnings[] = __( 'The consent lifetime exceeds 13 months.', 'archer-cookie-consent' );
		}

		foreach ( array_keys( Archer_CC_Settings::languages() ) as $lang ) {
			if ( empty( $settings['texts'][ $lang ]['policy_url'] ) ) {
				/* translators: %s: language code */
				$warnings[] = sprintf( __( 'No cookie policy URL is set for “%s”, so the first layer has no link to the full information.', 'archer-cookie-consent' ), strtoupper( $lang ) );
			}
		}

		if ( empty( $warnings ) ) {
			return;
		}

		// ADMIN: rendered through the admin_notices hook, never as hardcoded HTML.
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Cookie Consent — compliance review', 'archer-cookie-consent' ) . '</strong></p><ul style="margin-left:1.5em;list-style:disc;">';
		foreach ( $warnings as $warning ) {
			echo '<li>' . esc_html( $warning ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Render the consent log tab.
	 *
	 * @return void
	 */
	private function render_log_tab() {
		$per_page = 25;
		// SECURITY: pagination is read-only navigation; the value is cast to int.
		$page   = isset( $_GET['log_page'] ) ? absint( wp_unslash( $_GET['log_page'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result = Archer_CC_Consent_Log::get_page( $per_page, $page );
		$total  = $result['total'];
		$pages  = (int) ceil( $total / $per_page );

		$export_url = wp_nonce_url(
			add_query_arg( 'action', 'archer_cc_export_log', admin_url( 'admin-post.php' ) ),
			'archer_cc_export_log'
		);
		?>
		<p>
			<?php
			/* translators: %s: number of records */
			printf( esc_html__( '%s consent records stored.', 'archer-cookie-consent' ), esc_html( number_format_i18n( $total ) ) );
			?>
			<a class="button button-secondary" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'archer-cookie-consent' ); ?></a>
		</p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'When (UTC)', 'archer-cookie-consent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Consent ID', 'archer-cookie-consent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Action', 'archer-cookie-consent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Accepted', 'archer-cookie-consent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Rejected', 'archer-cookie-consent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Rev.', 'archer-cookie-consent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Lang', 'archer-cookie-consent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'IP hash', 'archer-cookie-consent' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $result['rows'] ) ) : ?>
				<tr><td colspan="8"><?php esc_html_e( 'Nothing logged yet.', 'archer-cookie-consent' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $result['rows'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['created_at'] ); ?></td>
						<td><code><?php echo esc_html( substr( (string) $row['consent_id'], 0, 8 ) ); ?></code></td>
						<td><?php echo esc_html( $row['action'] ); ?></td>
						<td><?php echo esc_html( $row['accepted_categories'] ); ?></td>
						<td><?php echo esc_html( $row['rejected_categories'] ); ?></td>
						<td><?php echo esc_html( absint( $row['revision'] ) ); ?></td>
						<td><?php echo esc_html( $row['language'] ); ?></td>
						<td><code><?php echo esc_html( substr( (string) $row['ip_hash'], 0, 12 ) ); ?></code></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
		if ( $pages > 1 ) {
			$base = add_query_arg(
				array(
					'page'     => self::PAGE_SLUG,
					'tab'      => 'log',
					'log_page' => '%#%',
				),
				admin_url( 'options-general.php' )
			);

			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'      => $base,
						'format'    => '',
						'current'   => max( 1, $page ),
						'total'     => $pages,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					)
				)
			);
			echo '</div></div>';
		}
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		// SECURITY: capability re-checked in the callback, not only in add_options_page().
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'archer-cookie-consent' ), 403 );
		}

		$tab  = $this->current_tab();
		$tabs = self::tabs();
		?>
		<div class="wrap archer-cc-wrap">
			<h1><?php esc_html_e( 'Cookie Consent', 'archer-cookie-consent' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a class="nav-tab <?php echo esc_attr( $slug === $tab ? 'nav-tab-active' : '' ); ?>"
						href="
						<?php
						echo esc_url(
							add_query_arg(
								array(
									'page' => self::PAGE_SLUG,
									'tab'  => $slug,
								),
								admin_url( 'options-general.php' )
							)
						);
						?>
								">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php if ( 'log' === $tab ) : ?>
				<?php $this->render_log_tab(); ?>
			<?php else : ?>
				<form action="options.php" method="post">
					<?php
					// SECURITY: settings_fields() emits the nonce and option_page
					// field that options.php verifies before writing anything.
					settings_fields( 'archer_cc_group_' . $tab );
					do_settings_sections( 'archer_cc_page_' . $tab );

					if ( 'appearance' === $tab ) {
						$this->render_palette_grid( 'palette_light', __( 'Light palette', 'archer-cookie-consent' ) );
						$this->render_palette_grid( 'palette_dark', __( 'Dark palette', 'archer-cookie-consent' ) );
						$this->render_palette_audit();
					}

					submit_button();
					?>
				</form>
			<?php endif; ?>

			<?php if ( 'general' === $tab ) : ?>
				<hr />
				<h2><?php esc_html_e( 'Shortcodes', 'archer-cookie-consent' ); ?></h2>
				<p><code>[archer_cc_preferences]</code> — <?php esc_html_e( 'a button or link that reopens the preferences modal. Accepts label, class and tag="a".', 'archer-cookie-consent' ); ?></p>
				<p><code>[archer_cc_cookie_policy]</code> — <?php esc_html_e( 'a purpose and legal-basis table for your cookie policy page. Accepts lang="nl".', 'archer-cookie-consent' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
