<?php
/**
 * Content extractor interface for page builder integrations.
 *
 * Plugins and themes (e.g. Divi 5, Elementor, Beaver Builder) that store
 * content in structured/encoded formats can implement this interface to
 * provide clean readable text for excerpt generation and AI context.
 *
 * @package Versi_Content_Tools
 */

defined( 'ABSPATH' ) || exit;

interface Versi_Content_Extractor {

	/**
	 * Extract clean readable text from raw post_content.
	 *
	 * Should strip block comments, JSON, shortcodes, and any other
	 * encoding artifacts, returning only the visible text content.
	 *
	 * @param string $raw_content The raw post_content value.
	 * @return string Clean extracted text.
	 */
	public function extract_text( string $raw_content ): string;

	/**
	 * Unique identifier for this extractor (e.g. 'divi').
	 *
	 * @return string
	 */
	public function get_identifier(): string;

	/**
	 * Display name for admin UI (e.g. 'Divi 5').
	 *
	 * @return string
	 */
	public function get_name(): string;
}
