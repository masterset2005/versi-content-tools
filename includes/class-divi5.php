<?php
/**
 * Divi 5 integration: live alt text updates, content cleanup, and text extraction.
 *
 * @package Versi_Content_Tools
 */

defined( 'ABSPATH' ) || exit;

/**
 * Integrates with Divi 5 block-based content.
 *
 * Divi 5 stores content as WordPress block comments with JSON attributes
 * in post_content (e.g. <!-- wp:divi/image {...} /-->). This class provides:
 *   1. A render_block filter for non-destructive front-end alt updates.
 *   2. A post_content parser for permanent database-level alt cleanup.
 *   3. A content extractor that decodes Divi block JSON into clean text
 *      for excerpt generation, SEO analysis, and AI context.
 *
 * @implements Versi_Content_Extractor
 */
class Versi_Divi5_Integration implements Versi_Content_Extractor {

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
	 * Unique identifier for this extractor.
	 *
	 * @return string
	 */
	public function get_identifier(): string {
		return 'divi';
	}

	/**
	 * Display name for admin UI.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'Divi 5';
	}

	// -------------------------------------------------------------------------
	// Live alt-text filter (render_block)
	// -------------------------------------------------------------------------

	/**
	 * Intercept rendered Divi 5 blocks and swap alt text from attachment metadata.
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

		// divi/image: content.module.src.desktop.value.
		if ( isset( $attrs['content']['module']['src']['desktop']['value'] ) ) {
			$src = $attrs['content']['module']['src']['desktop']['value'];
		}

		// divi/blurb: content.module.image.desktop.value.
		if ( empty( $src ) && isset( $attrs['content']['module']['image']['desktop']['value'] ) ) {
			$src = $attrs['content']['module']['image']['desktop']['value'];
		}

		return is_string( $src ) ? $src : '';
	}

	// -------------------------------------------------------------------------
	// DB-level alt-text update
	// -------------------------------------------------------------------------

	/**
	 * Update alt text in Divi 5 block JSON within post_content.
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
				$json = $matches[1];
				$data = json_decode( $json, true );
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

	// -------------------------------------------------------------------------
	// Content extraction (Versi_Content_Extractor interface)
	// -------------------------------------------------------------------------

	/**
	 * Extract clean readable text from Divi 5 block content.
	 *
	 * Strips all Divi block comments and recursively walks the JSON to
	 * collect user-visible text values from any module type. Non-text
	 * values (URLs, shortcodes, pure numbers, fragments) are discarded.
	 *
	 * @param string $raw_content Raw post_content containing Divi 5 blocks.
	 * @return string Clean text with block artifacts removed.
	 */
	public function extract_text( string $raw_content ): string {
		if ( '' === $raw_content || false === stripos( $raw_content, '<!-- wp:divi/' ) ) {
			return $raw_content;
		}

		$text_parts = array();

		// 1. Extract text from self-closing blocks: <!-- wp:divi/text {...} /-->
		$raw_content = preg_replace_callback(
			'/<!--\s+wp:divi\/(\w+)\s+(.*?)\s*\/-->/s',
			function ( $m ) use ( &$text_parts ) {
				$data = json_decode( $m[2], true );
				if ( is_array( $data ) ) {
					$this->collect_text_values( $data, $text_parts );
				}
				return '';
			},
			$raw_content
		);

		// 2. Extract text from inner blocks with closing tags:
		// <!-- wp:divi/accordion {...}--> ... <!-- /wp:divi/accordion -->
		$raw_content = preg_replace_callback(
			'/<!--\s+wp:divi\/(\w+)\s+(.*?)-->(.*?)<!--\s+\/wp:divi\/\1\s+-->/s',
			function ( $m ) use ( &$text_parts ) {
				$data = json_decode( $m[2], true );
				if ( is_array( $data ) ) {
					$this->collect_text_values( $data, $text_parts );
				}
				// Inner HTML may contain more blocks; return it for further processing.
				return $m[3];
			},
			$raw_content
		);

		// 3. Remove any remaining orphaned Divi comments (opening or closing).
		$raw_content = preg_replace( '/<!--\s+\/?wp:divi\/?\w*\s*.*?-->/s', '', $raw_content );

		$text_parts[] = $raw_content;

		// Merge all parts, strip HTML, and normalize whitespace.
		$result = implode( "\n\n", array_filter( $text_parts, 'strlen' ) );
		$result = wp_strip_all_tags( $result );
		$result = preg_replace( '/\s+/', ' ', $result );
		$result = trim( $result );

		return $result;
	}

	/**
	 * Recursively walk decoded block JSON and collect string values that
	 * look like visible text content.
	 *
	 * Any array that has a string 'value' key at any depth is a candidate.
	 * URLs, shortcodes, standalone numbers, 1-2 char fragments, and
	 * CSS-selector-like strings are filtered out.
	 *
	 * @param array $data  Decoded JSON array (portion of block attrs).
	 * @param array $texts Reference array to collect text strings into.
	 */
	private function collect_text_values( array $data, array &$texts ): void {
		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				// If this array has a string 'value' key, check it.
				if ( isset( $value['value'] ) && is_string( $value['value'] ) ) {
					$v = trim( $value['value'] );
					if ( $this->is_visible_text( $v ) ) {
						$texts[] = $v;
					}
				} else {
					$this->collect_text_values( $value, $texts );
				}
			}
		}
	}

	/**
	 * Determine whether a string looks like visible text content
	 * rather than internal data (URLs, shortcodes, numbers, etc.).
	 *
	 * @param string $value The trimmed string to check.
	 * @return bool
	 */
	private function is_visible_text( string $value ): bool {
		if ( '' === $value ) {
			return false;
		}

		// Minimum length: at least 3 characters of substantive text.
		if ( mb_strlen( $value ) < 3 ) {
			return false;
		}

		// Skip URLs.
		if ( str_starts_with( $value, 'http' ) || str_starts_with( $value, '//' ) ) {
			return false;
		}

		// Skip filesystem paths.
		if ( str_starts_with( $value, '/' ) ) {
			return false;
		}

		// Skip shortcodes and template tags.
		if ( str_starts_with( $value, '[' ) || str_starts_with( $value, '{' ) ) {
			return false;
		}

		// Skip standalone numbers and hex values.
		if ( is_numeric( $value ) || preg_match( '/^#[a-fA-F0-9]{6}$/', $value ) ) {
			return false;
		}

		// Skip things that look like CSS classes or IDs.
		if ( preg_match( '/^[.#][a-zA-Z]/', $value ) ) {
			return false;
		}

		// Skip data URIs and base64.
		if ( str_starts_with( $value, 'data:' ) || preg_match( '/^[A-Za-z0-9+\/]{20,}={0,2}$/', $value ) ) {
			return false;
		}

		return true;
	}
}
