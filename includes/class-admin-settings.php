<?php

defined( 'ABSPATH' ) || exit;

class Versi_Admin_Settings {

	use Versi_Singleton;

	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_settings() {
		$settings = array(
			'versi_batch_size'            => array( 'type' => 'integer', 'sanitize' => array( $this, 'sanitize_batch_size' ), 'default' => 5 ),
			'versi_match_author_tone'     => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field', 'default' => '0' ),
			'versi_debug_mode'            => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field', 'default' => '0' ),
			'versi_content_limit'         => array( 'type' => 'integer', 'sanitize' => array( $this, 'sanitize_content_limit' ), 'default' => 500 ),
			'versi_alt_processing_mode'   => array( 'type' => 'string', 'sanitize' => array( $this, 'sanitize_processing_mode' ), 'default' => 'two-pass' ),
			'versi_alt_vision_model'      => array( 'type' => 'string', 'sanitize' => array( $this, 'sanitize_model_preference' ), 'default' => '' ),
			'versi_alt_vision_fallback'   => array( 'type' => 'string', 'sanitize' => array( $this, 'sanitize_model_preference' ), 'default' => '' ),
			'versi_alt_text_model'        => array( 'type' => 'string', 'sanitize' => array( $this, 'sanitize_model_preference' ), 'default' => '' ),
			'versi_alt_text_fallback'     => array( 'type' => 'string', 'sanitize' => array( $this, 'sanitize_model_preference' ), 'default' => '' ),
			'versi_alt_image_size'        => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field', 'default' => 'large' ),
			'versi_alt_system_prompt'     => array( 'type' => 'string', 'sanitize' => 'sanitize_textarea_field', 'default' => '' ),
			'versi_alt_compare_prompt'    => array( 'type' => 'string', 'sanitize' => 'sanitize_textarea_field', 'default' => '' ),
			'versi_alt_single_prompt'     => array( 'type' => 'string', 'sanitize' => 'sanitize_textarea_field', 'default' => '' ),
			'versi_alt_auto_generate'     => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field', 'default' => '0' ),
			'versi_alt_show_generated'    => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field', 'default' => '0' ),
			'versi_alt_update_content'    => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field', 'default' => '0' ),
			'versi_strip_self_links'      => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field', 'default' => '0' ),
			'versi_alt_cat_filter'        => array( 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0 ),
			'versi_excerpt_text_model'    => array( 'type' => 'string', 'sanitize' => array( $this, 'sanitize_model_preference' ), 'default' => '' ),
			'versi_excerpt_text_fallback' => array( 'type' => 'string', 'sanitize' => array( $this, 'sanitize_model_preference' ), 'default' => '' ),
			'versi_excerpt_auto_generate' => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field', 'default' => '0' ),
			'versi_excerpt_prompt'        => array( 'type' => 'string', 'sanitize' => 'sanitize_textarea_field', 'default' => '' ),
			'versi_excerpt_length'        => array( 'type' => 'integer', 'sanitize' => array( $this, 'sanitize_excerpt_length' ), 'default' => 55 ),
			'versi_excerpt_min_length'    => array( 'type' => 'integer', 'sanitize' => 'absint', 'default' => 50 ),
			'versi_excerpt_max_length'    => array( 'type' => 'integer', 'sanitize' => 'absint', 'default' => 155 ),
			'versi_seo_text_model'        => array( 'type' => 'string', 'sanitize' => array( $this, 'sanitize_model_preference' ), 'default' => '' ),
			'versi_seo_text_fallback'     => array( 'type' => 'string', 'sanitize' => array( $this, 'sanitize_model_preference' ), 'default' => '' ),
			'versi_seo_prompt'            => array( 'type' => 'string', 'sanitize' => 'sanitize_textarea_field', 'default' => '' ),
			'versi_post_types'            => array( 'type' => 'string', 'sanitize' => array( $this, 'sanitize_post_types' ), 'default' => 'post' ),
		);

		foreach ( $settings as $option => $cfg ) {
			register_setting(
				'versi_settings',
				$option,
				array(
					'type'              => $cfg['type'],
					'sanitize_callback' => $cfg['sanitize'],
					'default'           => $cfg['default'],
				)
			);
		}
	}

	public function sanitize_batch_size( $value ) {
		return min( max( absint( $value ), 1 ), 50 );
	}

	public function sanitize_content_limit( $value ) {
		return min( max( absint( $value ), 0 ), 5000 );
	}

	public function sanitize_excerpt_length( $value ) {
		return min( max( absint( $value ), 10 ), 200 );
	}

	public function sanitize_processing_mode( $value ) {
		if ( ! in_array( $value, array( 'single-pass', 'two-pass' ), true ) ) {
			return 'two-pass';
		}
		return $value;
	}

	public function sanitize_model_preference( $value ) {
		return trim( preg_replace( '/[^a-zA-Z0-9:.\-_\/,]/', '', $value ) );
	}

	public function sanitize_post_types( $value ) {
		$allowed = get_post_types( array( 'public' => true ), 'names' );
		$types   = array_map( 'trim', explode( ',', $value ) );
		$types   = array_intersect( $types, $allowed );
		return implode( ',', $types );
	}

}
