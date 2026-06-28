<?php
/**
 * Versi Content Tools - Uninstall
 *
 * @package Versi_Content_Tools
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$options = array(
	// Alt-text options.
	'versi_alt_system_prompt',
	'versi_alt_compare_prompt',
	'versi_alt_single_prompt',
	'versi_alt_processing_mode',
	'versi_alt_auto_generate',
	'versi_alt_show_generated',
	'versi_alt_cat_filter',
	// Excerpt options.
	'versi_excerpt_auto_generate',
	'versi_excerpt_prompt',
	'versi_excerpt_length',

	// Shared options.
	'versi_version',
	'versi_batch_size',
	'versi_vision_model',
	'versi_text_model',
	'versi_alt_vision_model',
	'versi_alt_text_model',
	'versi_excerpt_text_model',
	'versi_content_limit',
	'versi_match_author_tone',
	'versi_debug_mode',
	'versi_job_status',
	'versi_live_job_status',
);

foreach ( $options as $opt ) {
	delete_option( $opt );
}

// Delete user meta for generated notices.
$users = get_users( array( 'fields' => 'ID' ) );
foreach ( $users as $user_id ) {
	delete_user_meta( $user_id, 'versi_last_generated_alt' );
}
