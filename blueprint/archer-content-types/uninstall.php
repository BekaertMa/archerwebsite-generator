<?php
/**
 * Uninstall: the definitions and all items stay in the database on purpose.
 * Removing the plugin must never remove a client's projects, references or cars;
 * installing it again brings everything back.
 *
 * @package Archer_Content_Types
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'archer_ct_flush' );
