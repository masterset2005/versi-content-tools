<?php
/**
 * Excerpt workload: prompts, cleanup, excerpt generation.
 *
 * @package Versi_Content_Tools
 */

defined( 'ABSPATH' ) || exit;

/**
 * Processes posts to generate or improve excerpts using AI.
 */
class Versi_Excerpt_Processor {

	use Versi_Singleton;

	/**
	 * Process a single post: generate excerpt via AI.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public function process_single( $post_id ) {
		$shared = Versi_Processor::init();
		$post   = get_post( $post_id );

		if ( ! $post ) {
			return $shared->result( $post_id, '', 'error', null, __( 'Post not found.', 'versi-content-tools' ) );
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return $shared->result( $post_id, $post->post_title, 'error', null, __( 'AI Client not available.', 'versi-content-tools' ) );
		}

		$content = wp_strip_all_tags( $post->post_content );
		if ( mb_strlen( $content ) < 20 ) {
			return $shared->result( $post_id, $post->post_title, 'skipped', null, null, __( 'Post content too short.', 'versi-content-tools' ) );
		}

		$existing_excerpt = $shared->sanitize_input( $post->post_excerpt );
		$target_length    = absint( get_option( 'versi_excerpt_length', 55 ) );
		if ( $target_length < 10 ) {
			$target_length = 55;
		}

		if ( '1' === get_option( 'versi_debug_mode', '0' ) ) {
			error_log( '--- VERSI MODE DEBUG --- Excerpt processing for post #' . $post_id );
		}

		$system = $this->build_prompt( $existing_excerpt, $target_length );
		$prompt = mb_substr( $content, 0, absint( get_option( 'versi_content_limit', 500 ) ) );

		if ( '1' === get_option( 'versi_match_author_tone', '0' ) ) {
			$style = $shared->get_author_style_sample( $post_id );
			if ( $style ) {
				$system .= "\n\n" . $style;
			}
		}

		if ( '1' === get_option( 'versi_debug_mode', '0' ) ) {
			error_log( '--- VERSI PROMPT DEBUG (excerpt) ---' );
			error_log( 'SYSTEM: ' . $system );
			error_log( 'CONTENT: ' . mb_substr( $prompt, 0, 300 ) );
		}

		$builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $system );
		$builder = $shared->apply_text_preference( $builder, 'excerpt' );

		$generated = $builder->generate_text();

		if ( is_wp_error( $generated ) ) {
			return $shared->result(
				$post_id,
				$post->post_title,
				'error',
				null,
				sprintf(
				/* translators: %s: AI provider error message */
					__( 'AI generation failed: %s', 'versi-content-tools' ),
					$generated->get_error_message()
				)
			);
		}

		$generated = $this->clean_excerpt( $generated, $target_length );

		if ( empty( $generated ) ) {
			return $shared->result( $post_id, $post->post_title, 'error', null, __( 'Generated excerpt was empty after cleaning.', 'versi-content-tools' ) );
		}

		$changed = $generated !== $existing_excerpt;

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_excerpt' => $generated,
			)
		);

		update_post_meta( $post_id, 'versi_excerpt_generated', '1' );

		return $shared->result( $post_id, $post->post_title, 'success', $existing_excerpt, null, null, $generated, $changed );
	}

	/**
	 * Build the system prompt for excerpt generation.
	 *
	 * @param string $existing      Current excerpt (may be empty).
	 * @param int    $target_length Target word count.
	 * @return string
	 */
	public function build_prompt( $existing = '', $target_length = 55 ) {
		$custom = get_option( 'versi_excerpt_prompt', '' );
		if ( ! empty( trim( $custom ) ) ) {
			return $custom;
		}

		$prompt = 'You are an **editorial assistant**. Generate a compelling post excerpt.' . "\n\n"
			. '**Input:** Blog post content below' . "\n"
			. '**Output:** Excerpt only, no preamble, no labels, no meta-commentary' . "\n\n"
			. '**Rules:**' . "\n"
			. '- Structure: 1-2 sentences. Maximum **' . $target_length . ' words**.' . "\n"
			. '- IMPORTANT: Complete the final sentence naturally. Do not end mid-sentence.' . "\n"
			. '- Capture the essence: hook the reader, summarize the core angle.' . "\n"
			. '- Output the excerpt text directly. Never prefix with labels like "Excerpt:", "Here", "Summary", "Output", or any introductory phrase. Never reference these instructions or your role in the output.' . "\n"
			. '- Do not start with filler like `An article about` or `This post discusses`.' . "\n";

		if ( ! empty( $existing ) ) {
			$prompt .= "\n" . '**Existing excerpt for reference:** ' . $existing . "\n"
				. 'Improve upon it — do not repeat it verbatim.' . "\n";
		}

		return $prompt;
	}

	/**
	 * Clean generated excerpt: strip labels, trim, enforce length.
	 *
	 * @param string $raw           Raw excerpt from AI.
	 * @param int    $target_length Target word count.
	 * @return string
	 */
	public function clean_excerpt( $raw, $target_length = 55 ) {
		$raw = sanitize_text_field( $raw );
		$raw = preg_replace( '/^(?:Excerpt|Summary|Output)::?\s*/i', '', $raw );
		$raw = preg_replace( '/^(?:Here\'[sz]\s+.*?:\s*)/i', '', $raw );
		$raw = preg_replace( '/^(?:Here\s+(?:is|are|was)\s+.*?:\s*)/i', '', $raw );
		$raw = preg_replace( '/^(?:\*{1,2}Excerpt\s*Generating\s*AI\*{0,2}:?\s*)/i', '', $raw );
		$raw = preg_replace( '/^["\'\x{2018}\x{2019}\x{201C}\x{201D}]+|["\'\x{2018}\x{2019}\x{201C}\x{201D}]+$/u', '', $raw );
		$raw = preg_replace( '/\[\[.*?\]\]/s', '', $raw );
		$raw = trim( $raw );

		$words = str_word_count( $raw, 0, '0123456789' );
		if ( $words > $target_length ) {
			// Try to truncate at the last full sentence.
			$sentences = preg_split( '/(?<=[.!?])\s+/', $raw );
			$new_raw   = '';
			foreach ( $sentences as $s ) {
				$words_in_s = preg_split( '/\s+/', $new_raw . ' ' . $s );
				if ( count( $words_in_s ) <= $target_length ) {
					$new_raw .= ( $new_raw ? ' ' : '' ) . $s;
				} else {
					break;
				}
			}

			if ( ! empty( $new_raw ) ) {
				$raw = $new_raw;
			} else {
				// First sentence is too long, force truncate.
				$words_arr = preg_split( '/\s+/', $raw );
				$raw       = implode( ' ', array_slice( $words_arr, 0, $target_length ) );
				// Remove trailing partial word to avoid mid-sentence cutoffs.
				$raw = preg_replace( '/\s+[^\s]+$/', '', $raw );
			}
		}

		return $raw;
	}

	/**
	 * Get excerpt stats.
	 *
	 * @return array{total: int, missing: int, has_excerpt: int}
	 */
	public function get_stats() {
		global $wpdb;

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'"
		);

		$missing = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND (post_excerpt IS NULL OR post_excerpt = '')"
		);

		$has_excerpt = $total - $missing;

		return compact( 'total', 'missing', 'has_excerpt' );
	}
}
