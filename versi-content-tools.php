<?php
/**
 * Plugin Name: Versi Content Tools
 * Plugin URI:  https://versihosting.com/
 * Description: AI-powered alt-text generation and excerpt management. Uses the WP AI Client (WordPress 7.0+).
 * Version:     0.14.0
 * Author:      Sean Thompson
 * Author URI:  https://stprojects.net/
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: versi-content-tools
 * Domain Path: /languages
 * Requires at least: 7.0
 * Requires PHP: 8.1
 *
 * @package Versi_Content_Tools
 */

defined( 'ABSPATH' ) || exit;

define( 'VERSI_VERSION', '0.14.0' );
define( 'VERSI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VERSI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Include core classes.
 */
if ( file_exists( VERSI_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once VERSI_PLUGIN_DIR . 'vendor/autoload.php';
}

if ( ! class_exists( 'Versi_Container' ) ) {
	require_once VERSI_PLUGIN_DIR . 'includes/trait-singleton.php';
	require_once VERSI_PLUGIN_DIR . 'includes/interface-content-extractor.php';
	require_once VERSI_PLUGIN_DIR . 'includes/class-container.php';
	require_once VERSI_PLUGIN_DIR . 'includes/class-processor.php';
	require_once VERSI_PLUGIN_DIR . 'includes/class-auditor.php';
	require_once VERSI_PLUGIN_DIR . 'includes/class-alt-text.php';
	require_once VERSI_PLUGIN_DIR . 'includes/class-excerpt.php';
	require_once VERSI_PLUGIN_DIR . 'includes/class-extensions.php';
	require_once VERSI_PLUGIN_DIR . 'includes/class-abilities.php';
	require_once VERSI_PLUGIN_DIR . 'includes/class-divi5.php';
	require_once VERSI_PLUGIN_DIR . 'includes/class-admin.php';
	require_once VERSI_PLUGIN_DIR . 'includes/class-admin-view.php';
	require_once VERSI_PLUGIN_DIR . 'includes/class-admin-settings.php';
	require_once VERSI_PLUGIN_DIR . 'includes/class-admin-ajax.php';
	require_once VERSI_PLUGIN_DIR . 'includes/class-batch-processor.php';
	require_once VERSI_PLUGIN_DIR . 'includes/class-cli.php';
}

/**
 * Deactivation: clean up any pending cron events.
 */
function versi_deactivate() {
	$timestamp = wp_next_scheduled( 'versi_process_batch' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'versi_process_batch' );
	}
}
register_deactivation_hook( __FILE__, 'versi_deactivate' );

/**
 * Initialize plugin.
 */
function versi_init() {
	$stored_version = get_option( 'versi_version', '' );
	if ( VERSI_VERSION !== $stored_version ) {
		// Migrations for upgrades from < 0.9.0.
		if ( '' === $stored_version || version_compare( $stored_version, '0.9.0', '<' ) ) {
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
		}

		// Strip leading whitespace from textarea prompts saved with HTML indentation.
		$prompt_options = array( 'versi_alt_single_prompt', 'versi_alt_system_prompt', 'versi_alt_compare_prompt', 'versi_excerpt_prompt', 'versi_seo_prompt' );
		foreach ( $prompt_options as $opt ) {
			$val = get_option( $opt, '' );
			if ( '' !== $val && trim( $val ) !== $val ) {
				update_option( $opt, trim( $val ) );
			}
		}

		update_option( 'versi_version', VERSI_VERSION, false );
	}

	Versi_Container::register( Versi_Processor::class );
	Versi_Container::register( Versi_Alt_Text_Processor::class );
	Versi_Container::register( Versi_Excerpt_Processor::class );
	Versi_Container::register( Versi_Extensions::class );
	Versi_Container::register( Versi_Abilities::class );
	Versi_Container::register( Versi_Auditor::class );
	Versi_Container::register( Versi_Admin_Settings::class );
	Versi_Container::register( Versi_Admin_View::class );
	Versi_Container::register( Versi_Admin::class );
	Versi_Container::register( Versi_Admin_Ajax::class );
	Versi_Container::register( Versi_Batch_Processor::class );
	Versi_Container::register( Versi_Divi5_Integration::class );

	// Register Divi 5 as a content extractor for page builder text decoding.
	if ( class_exists( Versi_Extensions::class ) && class_exists( Versi_Divi5_Integration::class ) ) {
		add_action(
			'versi_register_extension',
			function ( $extensions ) {
				$extensions->register_content_extractor( Versi_Divi5_Integration::get_instance() );
			}
		);
	}
}
add_action( 'plugins_loaded', 'versi_init' );
