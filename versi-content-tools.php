<?php
/**
 * Plugin Name: Versi Content Tools
 * Plugin URI:  https://versihosting.com/
 * Description: AI-powered alt-text generation and excerpt management. Uses the WP AI Client (WordPress 7.0+).
 * Version:     1.2.1
 * Author:      Sean Thompson
 * Author URI:  https://stprojects.net/
 * License:     GPL v2 or later
 * Text Domain: versi-content-tools
 * Domain Path: /languages
 * Requires at least: 7.0
 * Requires PHP: 8.1
 *
 * @package Versi_Content_Tools
 */

defined( 'ABSPATH' ) || exit;

define( 'VERSI_VERSION', '1.2.1' );
define( 'VERSI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VERSI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Include core classes.
 */
require_once VERSI_PLUGIN_DIR . 'includes/class-processor.php';
require_once VERSI_PLUGIN_DIR . 'includes/class-alt-text.php';
require_once VERSI_PLUGIN_DIR . 'includes/class-excerpt.php';
require_once VERSI_PLUGIN_DIR . 'includes/class-admin.php';
require_once VERSI_PLUGIN_DIR . 'includes/class-cli.php';

/**
 * Initialize plugin.
 */
function versi_init() {
	$stored_version = get_option( 'versi_version', '' );
	if ( VERSI_VERSION !== $stored_version ) {
		// Migrate legacy global model settings to workload-specific settings.
		$vision = get_option( 'versi_vision_model' );
		if ( $vision ) {
			update_option( 'versi_alt_vision_model', $vision );
			delete_option( 'versi_vision_model' );
		}
		$text = get_option( 'versi_text_model' );
		if ( $text ) {
			update_option( 'versi_alt_text_model', $text );
			update_option( 'versi_excerpt_text_model', $text );
			delete_option( 'versi_text_model' );
		}

		update_option( 'versi_version', VERSI_VERSION, false );
	}

	Versi_Processor::init();
	Versi_Alt_Text_Processor::init();
	Versi_Excerpt_Processor::init();
}
add_action( 'plugins_loaded', 'versi_init' );
Versi_Admin::init();
