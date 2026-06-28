<?php
/**
 * Abilities API integration.
 *
 * @package Versi_Content_Tools
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register Versi abilities with the WordPress Abilities API.
 */
class Versi_Abilities {
	use Versi_Singleton;

	/**
	 * Register abilities on init.
	 */
	public function __construct() {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Register Versi abilities.
	 */
	public function register_abilities() {
		wp_register_ability(
			'versi/generate-alt-text',
			array(
				'label'               => __( 'Generate Alt Text', 'versi-content-tools' ),
				'description'         => __( 'Generates alt text for images.', 'versi-content-tools' ),
				'category'            => 'content-generation',
				'execute_callback'    => array( $this, 'execute_alt_text' ),
				'permission_callback' => function (): bool {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		wp_register_ability(
			'versi/generate-excerpt',
			array(
				'label'               => __( 'Generate Excerpt', 'versi-content-tools' ),
				'description'         => __( 'Generates excerpts for posts.', 'versi-content-tools' ),
				'category'            => 'content-generation',
				'execute_callback'    => array( $this, 'execute_excerpt' ),
				'permission_callback' => function (): bool {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		wp_register_ability(
			'versi/generate-seo-keywords',
			array(
				'label'               => __( 'Generate SEO Keywords', 'versi-content-tools' ),
				'description'         => __( 'Generates SEO focus keywords.', 'versi-content-tools' ),
				'category'            => 'content-generation',
				'execute_callback'    => array( $this, 'execute_seo' ),
				'permission_callback' => function (): bool {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	/**
	 * Execute alt text generation.
	 *
	 * @param array $input Input data.
	 */
	public function execute_alt_text( $input ) {
		$attachment_id = isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0;
		if ( ! $attachment_id ) {
			return new WP_Error( 'invalid_input', 'Attachment ID required' );
		}
		return Versi_Alt_Text_Processor::init()->process_single( $attachment_id );
	}

	/**
	 * Execute excerpt generation.
	 *
	 * @param array $input Input data.
	 */
	public function execute_excerpt( $input ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		if ( ! $post_id ) {
			return new WP_Error( 'invalid_input', 'Post ID required' );
		}
		return Versi_Excerpt_Processor::init()->process_single( $post_id );
	}

	/**
	 * Execute SEO keyword generation.
	 *
	 * @param array $input Input data.
	 */
	public function execute_seo( $input ) {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		if ( ! $post_id ) {
			return new WP_Error( 'invalid_input', 'Post ID required' );
		}
		return Versi_Extensions::init()->generate_focus_keywords( $post_id );
	}
}
