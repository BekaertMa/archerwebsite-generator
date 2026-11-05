<?php
/**
 * Plugin Name:       Archer Cookie Consent
 * Plugin URI:        https://archerwebsites.be/
 * Description:       EU/GDPR-compliant cookie consent banner built on CookieConsent v3, with prior blocking, Google Consent Mode v2, proof-of-consent logging and a full colour theme editor.
 * Version:           1.1.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Archer Websites
 * Author URI:        https://archerwebsites.be/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       archer-cookie-consent
 * Domain Path:       /languages
 *
 * Bundles CookieConsent v3.1.0 by Orest Bida (MIT) — see vendor/cookieconsent/LICENSE.
 *
 * @package Archer_Cookie_Consent
 */

// HOOKS: nothing but constant definition and a single bootstrap hook lives in this file.
// SECURITY: block direct file access.
defined( 'ABSPATH' ) || exit;

define( 'ARCHER_CC_VERSION', '1.1.0' );
define( 'ARCHER_CC_LIB_VERSION', '3.1.0' );
define( 'ARCHER_CC_FILE', __FILE__ );
define( 'ARCHER_CC_DIR', plugin_dir_path( __FILE__ ) );
define( 'ARCHER_CC_URL', plugin_dir_url( __FILE__ ) );
define( 'ARCHER_CC_OPTION', 'archer_cc_settings' );
define( 'ARCHER_CC_DB_VERSION', '1' );

require_once ARCHER_CC_DIR . 'includes/class-archer-cc-settings.php';
require_once ARCHER_CC_DIR . 'includes/class-archer-cc-i18n-strings.php';
require_once ARCHER_CC_DIR . 'includes/class-archer-cc-color.php';
require_once ARCHER_CC_DIR . 'includes/class-archer-cc-consent-log.php';
require_once ARCHER_CC_DIR . 'includes/class-archer-cc-consent-mode.php';
require_once ARCHER_CC_DIR . 'includes/class-archer-cc-blocker.php';
require_once ARCHER_CC_DIR . 'includes/functions-template-tags.php';
require_once ARCHER_CC_DIR . 'includes/class-archer-cc-frontend.php';
require_once ARCHER_CC_DIR . 'includes/class-archer-cc-shortcodes.php';
require_once ARCHER_CC_DIR . 'includes/class-archer-cc-admin.php';
require_once ARCHER_CC_DIR . 'includes/class-archer-cc-plugin.php';

/**
 * Bootstrap the plugin.
 *
 * HOOKS: business logic is registered on plugins_loaded, never at include time,
 * so other plugins/themes can filter our defaults before anything runs.
 *
 * @return void
 */
function archer_cc_bootstrap() {
	Archer_CC_Plugin::instance()->register();
}
add_action( 'plugins_loaded', 'archer_cc_bootstrap', 10, 0 );

// Activation / deactivation must be registered at file scope (WP requirement).
register_activation_hook( __FILE__, array( 'Archer_CC_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Archer_CC_Plugin', 'deactivate' ) );
