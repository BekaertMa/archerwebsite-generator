<?php
/**
 * Plugin orchestrator.
 *
 * @package Archer_Cookie_Consent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the components together. No work happens in the constructor.
 */
class Archer_CC_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Archer_CC_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the shared instance.
	 *
	 * @return Archer_CC_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {}

	/**
	 * Register every component's hooks.
	 *
	 * @return void
	 */
	public function register() {
		load_plugin_textdomain( 'archer-cookie-consent', false, dirname( plugin_basename( ARCHER_CC_FILE ) ) . '/languages' );

		// The log and Consent Mode components must hook early enough for wp_head.
		$log = new Archer_CC_Consent_Log();
		$log->register();

		$consent_mode = new Archer_CC_Consent_Mode();
		$consent_mode->register();

		$blocker = new Archer_CC_Blocker();
		$blocker->register();

		$frontend = new Archer_CC_Frontend();
		$frontend->register();

		$shortcodes = new Archer_CC_Shortcodes();
		$shortcodes->register();

		if ( is_admin() ) {
			$admin = new Archer_CC_Admin();
			$admin->register();
		}

		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ), 20, 0 );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ), 5, 0 );
		add_action( 'init', array( $this, 'ensure_cron' ), 10, 0 );
		add_action( 'init', array( $this, 'maybe_migrate_settings' ), 5, 0 );
	}

	/**
	 * 1.1.0: the banner follows the page language.
	 *
	 * Sites created with 1.0.0 stored "follow the browser" and English as default,
	 * so a Dutch site showed an English banner in an English browser. Runs on init
	 * (not admin_init): sites cloned from the blueprint are visited on the front end
	 * long before anyone opens wp-admin.
	 *
	 * @return void
	 */
	public function maybe_migrate_settings() {
		if ( (int) get_option( 'archer_cc_settings_rev', 1 ) >= 2 ) {
			return;
		}

		$settings = get_option( ARCHER_CC_OPTION );

		if ( is_array( $settings ) ) {
			if ( isset( $settings['auto_detect'] ) && 'browser' === $settings['auto_detect'] ) {
				$settings['auto_detect'] = 'document';
			}
			if ( ! isset( $settings['default_language'] ) || 'en' === $settings['default_language'] ) {
				$settings['default_language'] = Archer_CC_Settings::site_language();
			}
			update_option( ARCHER_CC_OPTION, $settings );
			Archer_CC_Settings::flush_cache();
		}

		update_option( 'archer_cc_settings_rev', 2, true );
	}

	/**
	 * Make sure the retention cron event exists.
	 *
	 * HOOKS: scheduled on init rather than only on activation, so a site that was
	 * migrated or had its cron table cleared self-heals.
	 *
	 * @return void
	 */
	public function ensure_cron() {
		if ( ! wp_next_scheduled( Archer_CC_Consent_Log::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Archer_CC_Consent_Log::CRON_HOOK );
		}
	}

	/**
	 * Create the log table after an update that changed the schema.
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		if ( get_option( 'archer_cc_db_version' ) === ARCHER_CC_DB_VERSION ) {
			return;
		}

		Archer_CC_Consent_Log::install_table();
	}

	/**
	 * Suggest privacy policy wording, as WordPress core expects plugins to do.
	 *
	 * @return void
	 */
	public function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content  = '<p>' . __( 'This site asks for your consent before placing any cookie that is not strictly necessary. Your choice is stored in a first-party cookie on your own device. To be able to demonstrate that consent was given, we also keep a record containing the moment of your choice, which purposes you accepted or rejected, the version of our cookie policy, the language of the banner and a one-way hash of your IP address. The IP address itself is never stored, and the record is deleted automatically once the retention period ends.', 'archer-cookie-consent' ) . '</p>';
		$content .= '<p>' . __( 'You can change or withdraw your consent at any time through the “Cookie settings” control, with the same single click it took to give it.', 'archer-cookie-consent' ) . '</p>';

		wp_add_privacy_policy_content( __( 'Archer Cookie Consent', 'archer-cookie-consent' ), wp_kses_post( $content ) );
	}

	/**
	 * Activation: create the table, seed defaults, schedule the purge.
	 *
	 * @return void
	 */
	public static function activate() {
		Archer_CC_Consent_Log::install_table();

		// STANDARDS: add_option() rather than update_option() so an existing
		// configuration survives a deactivate/reactivate cycle.
		add_option( ARCHER_CC_OPTION, Archer_CC_Settings::defaults() );

		if ( ! wp_next_scheduled( Archer_CC_Consent_Log::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Archer_CC_Consent_Log::CRON_HOOK );
		}
	}

	/**
	 * Deactivation: unschedule the purge. Data is kept.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Clears every scheduled instance, not just the next one.
		wp_clear_scheduled_hook( Archer_CC_Consent_Log::CRON_HOOK );
	}
}
