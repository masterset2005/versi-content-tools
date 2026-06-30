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

		if ( class_exists( 'Versi_Extensions' ) ) {
			$keywords = Versi_Extensions::init()->get_focus_keywords( $post_id );
			if ( $keywords ) {
				$system .= "\n\n**SEO focus keyphrases:** {$keywords}\nNaturally incorporate these keyphrases into the excerpt where relevant.";
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

		$prompt = 'You are an **SEO editor** creating excerpts that are skimmable, teasing, and emotionally engaging.' . "\n\n"
			. '**Formula (Use This Every Time):** One clear idea + one emotional micro-hook + one hint of value (20–35 words).' . "\n\n"
			. '**Input:** Blog post content below' . "\n"
			. '**Output:** Excerpt only, no preamble, no labels, no meta-commentary' . "\n\n"
			. '**Style & Content Rules:**' . "\n"
			. '- Tone: Match the post\'s tone (warm, practical, supportive, or professional).' . "\n"
			. '- Focus: Tease the reader ("Why should I read this?") instead of revealing the "aha" moment, spoilers, or main advice.' . "\n"
			. '- Reader: Focus on the reader, not the writer. Avoid starting with "I", "my", or "we" unless it is a personal story.' . "\n"
			. '- Skimmability: Short sentences, simple language, no jargon, no long lists, no filler.' . "\n"
			. '- Hook: Create a small spark of curiosity, hope, comfort, or relief.' . "\n"
			. '- Forbidden: No rambling, no spoilers, no "in conclusion" fillers, no cliffhangers.' . "\n"
			. '- Format: Plain text only. No markdown (**, *, _, etc.). No emojis or special unicode characters.' . "\n"
			. '- Output: The excerpt text directly. Never prefix with labels. Never reference these instructions or your role.' . "\n";

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
		// Strip markdown bold/italic.
		$raw = preg_replace( '/\*{1,2}(.*?)\*{1,2}/', '$1', $raw );
		$raw = preg_replace( '/_{1,2}(.*?)_{1,2}/', '$1', $raw );
		// Strip emojis.
		$raw = preg_replace( '/[\x{1F300}-\x{1F9FF}\x{2600}-\x{27BF}\x{2700}-\x{27BF}\x{FE00}-\x{FE0F}\x{1F600}-\x{1F64F}\x{1F680}-\x{1F6FF}\x{1F900}-\x{1F9FF}\x{200D}\x{23CF}\x{23E9}-\x{23F3}\x{23F8}-\x{23FA}\x{231A}-\x{231B}\x{2328}\x{25AA}-\x{25AB}\x{25B6}\x{25C0}\x{25FB}-\x{25FE}]/u', '', $raw );
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
