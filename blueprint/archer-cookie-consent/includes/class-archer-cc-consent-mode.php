<?php
/**
 * Google Consent Mode v2.
 *
 * @package Archer_Cookie_Consent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Emits the Consent Mode v2 default state and (optionally) the Google loader.
 */
class Archer_CC_Consent_Mode {

	/**
	 * Map of consent categories to Consent Mode v2 signals.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function signal_map() {
		/**
		 * Filters the category => Consent Mode signal mapping.
		 *
		 * @param array $map Signal map.
		 */
		return apply_filters(
			'archer_cc_consent_mode_signal_map',
			array(
				'functionality' => array( 'functionality_storage', 'personalization_storage' ),
				'analytics'     => array( 'analytics_storage' ),
				'marketing'     => array( 'ad_storage', 'ad_user_data', 'ad_personalization' ),
			)
		);
	}

	/**
	 * All signals that start out denied.
	 *
	 * @return array<int,string>
	 */
	public static function denied_signals() {
		$signals = array();

		foreach ( self::signal_map() as $category_signals ) {
			$signals = array_merge( $signals, $category_signals );
		}

		return array_values( array_unique( $signals ) );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		// HOOKS: deliberately no settings read here. register() runs during
		// plugins_loaded, and touching the options would memoise the settings
		// before a theme's functions.php has had a chance to hook
		// archer_cc_default_settings. The enabled check lives in the callbacks, which
		// first run on wp_head.
		//
		// COMPLIANCE: priority 1 on wp_head means the default "denied" state is
		// declared before any theme, plugin or tag-manager snippet can fire.
		add_action( 'wp_head', array( $this, 'print_default_state' ), 1, 0 );
		add_action( 'wp_head', array( $this, 'print_google_loader' ), 2, 0 );
	}

	/**
	 * Print the gtag stub and the default (denied) consent state.
	 *
	 * @return void
	 */
	public function print_default_state() {
		if ( ! Archer_CC_Settings::get( 'consent_mode' ) || ! Archer_CC_Frontend::should_render() ) {
			return;
		}

		$wait     = absint( Archer_CC_Settings::get( 'consent_mode_wait', 500 ) );
		$redact   = Archer_CC_Settings::get( 'ads_data_redaction' ) ? 'true' : 'false';
		$pass     = Archer_CC_Settings::get( 'url_passthrough' ) ? 'true' : 'false';
		$defaults = array();

		foreach ( self::denied_signals() as $signal ) {
			$defaults[ $signal ] = 'denied';
		}

		// security_storage covers CSRF/fraud-prevention storage and is exempt
		// from consent, so it is granted from the start.
		$defaults['security_storage'] = 'granted';
		$defaults['wait_for_update']  = $wait;

		// SECURITY: JSON_HEX_TAG escapes < and > so no encoded value can ever close
		// the surrounding <script> element, even after a third-party filter.
		$json = wp_json_encode( $defaults, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE );

		if ( false === $json ) {
			return;
		}

		?>
<!-- Archer Cookie Consent: Google Consent Mode v2 default state -->
<script id="archer-cc-consent-mode-default">
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('consent', 'default', <?php echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode() output, safe in a JS context. ?>);
gtag('set', 'ads_data_redaction', <?php echo esc_js( $redact ); ?>);
gtag('set', 'url_passthrough', <?php echo esc_js( $pass ); ?>);
</script>
		<?php
	}

	/**
	 * Print the GTM / GA4 loader.
	 *
	 * In "advanced" mode the loader runs immediately: Consent Mode keeps it from
	 * writing storage while everything is denied. In "basic" mode the loader is
	 * emitted as an inert text/plain tag and CookieConsent activates it only
	 * after the matching category is granted — the stricter reading of ePrivacy
	 * Art. 5(3), at the cost of losing modelled conversions.
	 *
	 * @return void
	 */
	public function print_google_loader() {
		if ( ! Archer_CC_Settings::get( 'consent_mode' ) || ! Archer_CC_Frontend::should_render() ) {
			return;
		}

		$gtm_id = (string) Archer_CC_Settings::get( 'gtm_id', '' );
		$ga4_id = (string) Archer_CC_Settings::get( 'ga4_id', '' );
		$basic  = 'basic' === Archer_CC_Settings::get( 'consent_mode_type', 'advanced' );

		if ( '' === $gtm_id && '' === $ga4_id ) {
			return;
		}

		// COMPLIANCE: an AW-/DC- id is an advertising tag, so it belongs to the
		// marketing category — gating it behind "analytics" would load an ad
		// vendor for a visitor who accepted statistics and refused marketing.
		$ga4_category = ( 0 === strpos( $ga4_id, 'AW-' ) || 0 === strpos( $ga4_id, 'DC-' ) ) ? 'marketing' : 'analytics';

		// No data-service: see Archer_CC_Blocker::filter_script_tag() for why a
		// service name would strand the tag for already-consented visitors.
		$type_attr = $basic ? ' type="text/plain" data-category="' . esc_attr( $ga4_category ) . '"' : '';

		if ( '' !== $gtm_id ) {
			?>
<script<?php echo $basic ? ' type="text/plain" data-category="analytics"' : ''; ?>>
(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});
var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';
j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','<?php echo esc_js( $gtm_id ); ?>');
</script>
			<?php
		}

		if ( '' !== $ga4_id ) {
			?>
<script<?php echo $type_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal chosen above. ?> async src="<?php echo esc_url( add_query_arg( 'id', $ga4_id, 'https://www.googletagmanager.com/gtag/js' ) ); ?>"></script>
<script<?php echo $type_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal chosen above. ?>>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', '<?php echo esc_js( $ga4_id ); ?>');
</script>
			<?php
		}
	}
}
