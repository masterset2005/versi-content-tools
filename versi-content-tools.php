<?php
/**
 * Plugin Name: Versi Content Tools
 * Plugin URI:  https://versihosting.com/
 * Description: AI-powered alt-text generation and excerpt management. Uses the WP AI Client (WordPress 7.0+).
 * Version:     1.1.0
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

define( 'VERSI_VERSION', '1.1.0' );
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
 * Upgrade routine: migrate autoalt_* options to versi_* and clear saved prompts.
 */
function versi_upgrade() {
	$stored_version = get_option( 'versi_version', '' );

	if ( VERSI_VERSION === $stored_version ) {
		return;
	}

	$migration_map = array(
		'autoalt_version'         => 'versi_version',
		'autoalt_batch_size'      => 'versi_batch_size',
		'autoalt_debug_mode'      => 'versi_debug_mode',
		'autoalt_vision_model'    => 'versi_vision_model',
		'autoalt_text_model'      => 'versi_text_model',
		'autoalt_excerpt_limit'   => 'versi_content_limit',
		'autoalt_system_prompt'   => 'versi_alt_system_prompt',
		'autoalt_compare_prompt'  => 'versi_alt_compare_prompt',
		'autoalt_single_prompt'   => 'versi_alt_single_prompt',
		'autoalt_processing_mode' => 'versi_alt_processing_mode',
		'autoalt_auto_generate'   => 'versi_alt_auto_generate',
		'autoalt_show_generated'  => 'versi_alt_show_generated',
		'autoalt_cat_filter'      => 'versi_alt_cat_filter',
		'autoalt_job_status'      => 'versi_job_status',
	);

	foreach ( $migration_map as $old => $new ) {
		$value = get_option( $old, null );
		if ( null !== $value ) {
			update_option( $new, $value, false );
		}
	}

	// On upgrade from v1.2+ (prompt-clear versions), clear saved prompts to force fresh defaults.
	if ( '' === $stored_version || version_compare( $stored_version, '1.2.0', '>=' ) ) {
		delete_option( 'versi_alt_system_prompt' );
		delete_option( 'versi_alt_compare_prompt' );
		delete_option( 'versi_alt_single_prompt' );
		delete_option( 'versi_excerpt_prompt' );
	}

	update_option( 'versi_version', VERSI_VERSION, false );
}
add_action( 'admin_init', 'versi_upgrade' );

/**
 * Initialize singletons.
 */
Versi_Processor::init();
Versi_Alt_Text_Processor::init();
Versi_Excerpt_Processor::init();
Versi_Admin::init();
