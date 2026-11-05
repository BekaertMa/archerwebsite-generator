<?php
/**
 * Plugin Name:       Archer Content Types
 * Plugin URI:        https://archerwebsites.be/
 * Description:       Custom content types without code: realisaties, projecten, referenties, wagens, vacatures or anything else, each with its own fields and categories. Ready for Bricks templates and query loops.
 * Version:           1.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Archer Websites
 * Author URI:        https://archerwebsites.be/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       archer-content-types
 * Domain Path:       /languages
 *
 * @package Archer_Content_Types
 */

defined( 'ABSPATH' ) || exit;

define( 'ARCHER_CT_VERSION', '1.1.0' );
define( 'ARCHER_CT_FILE', __FILE__ );
define( 'ARCHER_CT_DIR', plugin_dir_path( __FILE__ ) );
define( 'ARCHER_CT_URL', plugin_dir_url( __FILE__ ) );
define( 'ARCHER_CT_OPTION', 'archer_ct_types' );

require_once ARCHER_CT_DIR . 'includes/class-archer-ct-types.php';
require_once ARCHER_CT_DIR . 'includes/class-archer-ct-labels.php';
require_once ARCHER_CT_DIR . 'includes/class-archer-ct-presets.php';
require_once ARCHER_CT_DIR . 'includes/class-archer-ct-register.php';
require_once ARCHER_CT_DIR . 'includes/class-archer-ct-fields.php';
require_once ARCHER_CT_DIR . 'includes/class-archer-ct-bricks.php';
require_once ARCHER_CT_DIR . 'includes/class-archer-ct-rest.php';
require_once ARCHER_CT_DIR . 'includes/class-archer-ct-admin.php';

/**
 * Bootstrap. Everything hooks in on plugins_loaded so other code can filter first.
 *
 * @return void
 */
function archer_ct_bootstrap() {
	load_plugin_textdomain( 'archer-content-types', false, dirname( plugin_basename( ARCHER_CT_FILE ) ) . '/languages' );

	( new Archer_CT_Register() )->register();
	( new Archer_CT_Fields() )->register();
	( new Archer_CT_Bricks() )->register();
	( new Archer_CT_Rest() )->register();

	if ( is_admin() ) {
		( new Archer_CT_Admin() )->register();
	}
}
add_action( 'plugins_loaded', 'archer_ct_bootstrap', 10, 0 );

/**
 * Activation: keep existing definitions, make sure the permalinks are rebuilt.
 *
 * @return void
 */
function archer_ct_activate() {
	add_option( ARCHER_CT_OPTION, array(), '', true );
	Archer_CT_Types::request_flush();
}
register_activation_hook( __FILE__, 'archer_ct_activate' );

/**
 * Deactivation: the post types disappear, so the rewrite rules must go too.
 *
 * @return void
 */
function archer_ct_deactivate() {
	flush_rewrite_rules( false );
}
register_deactivation_hook( __FILE__, 'archer_ct_deactivate' );
