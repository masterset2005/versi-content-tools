<?php
/**
 * Divi 5 integration: live alt text updates and content cleanup.
 *
 * @package Versi_Content_Tools
 */

defined( 'ABSPATH' ) || exit;

/**
 * Integrates with Divi 5 block-based content to update image alt text.
 *
 * Divi 5 stores content as WordPress block comments with JSON attributes
 * in post_content (e.g. <!-- wp:divi/image {...} /-->). The alt text is
 * stored as a module setting, not read dynamically from attachment metadata.
 * This class provides:
 *   1. A render_block filter for non-destructive front-end alt updates.
 *   2. A post_content parser for permanent database-level cleanup.
 */
class Versi_Divi5_Integration {

	use Versi_Singleton;

	/**
	 * Hook into WordPress.
	 */
	public function __construct() {
		if ( '1' === get_option( 'versi_ext_divi_update_alt', '0' ) ) {
			add_filter( 'render_block', array( $this, 'filter_block_alt' ), 10, 2 );
		}
	}

	/**
	 * Intercept rendered Divi 5 blocks and swap alt text from attachment metadata.
	 *
	 * Hooked into render_block so it fires for every block during the_content.
	 * Only acts on Divi 5 blocks that contain images.
	 *
	 * @param string $block_content The rendered block HTML.
	 * @param array  $block         The parsed block data.
	 * @return string Updated block HTML.
	 */
	public function filter_block_alt( $block_content, $block ) {
		if ( empty( $block_content ) || ! is_string( $block_content ) ) {
			return $block_content;
		}

		$block_name = $block['blockName'] ?? '';

		if ( 'divi/image' === $block_name ) {
			return $this->update_img_alt_from_block( $block_content, $block );
		}

		if ( 'divi/blurb' === $block_name ) {
			return $this->update_img_alt_from_block( $block_content, $block );
		}

		return $block_content;
	}

	/**
	 * Update the rendered image alt attribute using block attachment metadata.
	 *
	 * @param string $html  Rendered block HTML.
	 * @param array  $block Parsed block data.
	 * @return string
	 */
	private function update_img_alt_from_block( $html, $block ) {
		$src = $this->get_image_src_from_block( $block );
		if ( '' === $src ) {
			return $html;
		}

		$attachment_id = attachment_url_to_postid( $src );
		if ( ! $attachment_id ) {
			return $html;
		}

		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( ! is_string( $alt ) || '' === trim( $alt ) ) {
			return $html;
		}

		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$processor = new WP_HTML_Tag_Processor( $html );
		if ( $processor->next_tag( 'img' ) ) {
			$existing = $processor->get_attribute( 'alt' );
			if ( $existing !== $alt ) {
				$processor->set_attribute( 'alt', $alt );
			}
		}

		return $processor->get_updated_html();
	}

	/**
	 * Extract the image source URL from a Divi 5 block's JSON attributes.
	 *
	 * @param array $block Parsed block data.
	 * @return string Image URL or empty string.
	 */
	private function get_image_src_from_block( $block ) {
		$attrs = $block['attrs'] ?? array();
		$src   = '';

		// divi/image: content.module.src.desktop.value
		if ( isset( $attrs['content']['module']['src']['desktop']['value'] ) ) {
			$src = $attrs['content']['module']['src']['desktop']['value'];
		}

		// divi/blurb: content.module.image.desktop.value
		if ( empty( $src ) && isset( $attrs['content']['module']['image']['desktop']['value'] ) ) {
			$src = $attrs['content']['module']['image']['desktop']['value'];
		}

		return is_string( $src ) ? $src : '';
	}

	/**
	 * Update alt text in Divi 5 block JSON within post_content.
	 *
	 * Parses post_content for <!-- wp:divi/image ... /--> blocks,
	 * maps their src URL to attachment metadata, and updates the alt
	 * attribute in the JSON. Returns the modified content.
	 *
	 * @param string $content Raw post_content (may contain Divi 5 blocks).
	 * @return string Updated content.
	 */
	public function update_alt_in_content( $content ) {
		if ( '' === $content || false === stripos( $content, '<!-- wp:divi/image' ) ) {
			return $content;
		}

		return preg_replace_callback(
			'/<!--\s+wp:divi\/image\s+(.*?)\s*\/-->/s',
			function ( $matches ) {
				$json  = $matches[1];
				$data  = json_decode( $json, true );
				if ( ! is_array( $data ) ) {
					return $matches[0];
				}

				$src = $data['content']['module']['src']['desktop']['value'] ?? '';
				if ( ! is_string( $src ) || '' === $src ) {
					return $matches[0];
				}

				$attachment_id = attachment_url_to_postid( $src );
				if ( ! $attachment_id ) {
					return $matches[0];
				}

				$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
				if ( ! is_string( $alt ) || '' === trim( $alt ) ) {
					return $matches[0];
				}

				// Ensure the alt path exists before setting.
				if ( ! isset( $data['content']['module']['alt'] ) ) {
					$data['content']['module']['alt'] = array();
				}
				if ( ! isset( $data['content']['module']['alt']['desktop'] ) ) {
					$data['content']['module']['alt']['desktop'] = array();
				}
				$data['content']['module']['alt']['desktop']['value'] = $alt;

				return '<!-- wp:divi/image ' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ' /-->';
			},
			$content
		);
	}
}
