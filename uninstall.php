<?php
/**
 * Versi Content Tools - Uninstall
 *
 * @package Versi_Content_Tools
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$versi_options = array(
	// Alt-text options.
	'versi_alt_system_prompt',
	'versi_alt_compare_prompt',
	'versi_alt_single_prompt',
	'versi_alt_processing_mode',
	'versi_alt_auto_generate',
	'versi_alt_show_generated',
	'versi_alt_update_content',
	'versi_strip_self_links',
	'versi_alt_cat_filter',
	// Excerpt options.
	'versi_excerpt_auto_generate',
	'versi_excerpt_prompt',
	'versi_excerpt_length',
	'versi_excerpt_min_length',

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
	// Extension toggles — read.
	'versi_ext_smartcrawl_focus',
	'versi_ext_yoast_focus',
	'versi_ext_rankmath_focus',
	'versi_ext_seopress_focus',
	'versi_ext_woocommerce_product',
	// Extension toggles — auto-generate.
	'versi_ext_smartcrawl_auto_focus',
	'versi_ext_yoast_auto_focus',
	'versi_ext_rankmath_auto_focus',
	'versi_ext_seopress_auto_focus',
	// SEO extension.
	'versi_seo_prompt',
);

foreach ( $versi_options as $versi_opt ) {
	delete_option( $versi_opt );
}

// Delete user meta for generated notices.
$versi_users = get_users( array( 'fields' => 'ID' ) );
foreach ( $versi_users as $versi_user_id ) {
	delete_user_meta( $versi_user_id, 'versi_last_generated_alt' );
}
