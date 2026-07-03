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

		list( $system, $prompt ) = $this->build_prompt( $existing_excerpt, $target_length );
		$content                 = mb_substr( $content, 0, absint( get_option( 'versi_content_limit', 500 ) ) );

		if ( '1' === get_option( 'versi_match_author_tone', '0' ) ) {
			$style = $shared->get_author_style_sample( $post_id );
			if ( $style ) {
				$system .= "\n\n" . $style;
			}
		}

		if ( class_exists( 'Versi_Extensions' ) ) {
			$keywords = Versi_Extensions::init()->get_focus_keywords( $post_id );
			if ( $keywords ) {
				$system .= "\n\n**SEO focus keyphrases:** {$keywords}\nNaturally incorporate these keyphrases into the excerpt text. Do NOT list, label, or append them separately — never output \"Keywords:\" or \"Keyphrases:\" or any similar prefix.";
			}

			if ( 'product' === get_post_type( $post_id ) ) {
				$product_ctx = Versi_Extensions::init()->get_product_context( $post_id );
				if ( $product_ctx ) {
					$system .= "\n\n**Product context:** {$product_ctx}\nUse this context to inform the excerpt, but stay focused on the article itself.";
				}
			}
		}

		$builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $system );
		$builder = $shared->apply_text_preference( $builder, 'excerpt' );

		$generated = $builder->generate_text();

		if ( is_wp_error( $generated ) ) {
			$error_info = $shared->classify_error( $generated->get_error_message() );
			if ( $error_info['should_retry'] ) {
				$fallback = $shared->get_text_fallback( 'excerpt' );
				if ( '' !== $fallback ) {
					$fb_builder = wp_ai_client_prompt( $prompt )
						->using_system_instruction( $system )
						->using_model_preference( $fallback );
					$generated  = $fb_builder->generate_text();
				}
			}
		}

		if ( is_wp_error( $generated ) ) {
			$error_msg = sprintf(
			/* translators: %s: AI provider error message */
				__( 'AI generation failed: %s', 'versi-content-tools' ),
				$generated->get_error_message()
			);
			$error_info = $shared->classify_error( $generated->get_error_message() );
			return $shared->result(
				$post_id,
				$post->post_title,
				'error',
				null,
				$error_msg,
				null,
				null,
				false,
				'',
				$error_info['should_retry'],
				$error_info['should_retry'] ? (float) $error_info['retry_after'] : 0
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
	 * @return string
	 */
	public function default_prompt(): string {
		return 'You are an **editor** crafting engaging blog excerpts. Write with clarity, warmth, and genuine reader value.' . "\n\n"
			. '**Tone principles:**' . "\n"
			. '- Acknowledge the reader\'s real challenges or curiosity.' . "\n"
			. '- Be factual and helpful — never hype, fear-monger, or judge.' . "\n"
			. '- Show, don\'t sell. Focus on practical takeaways.' . "\n"
			. '- Respect the reader\'s experience and intelligence.' . "\n\n"
			. '**Structure:** One clear idea + one emotional micro-hook + one hint of practical value (20–35 words).' . "\n\n"
			. '**Format rules:**' . "\n"
			. '- Plain text only. No markdown (** , * , _). No emojis or special unicode characters.' . "\n"
			. '- Short sentences, simple language, no jargon, no long lists.' . "\n"
			. '- Output the excerpt text directly. Never prefix with labels. Never reference these instructions.' . "\n"
			. '- Never start with "I", "my", or "we" unless the post is a first-person story.' . "\n"
			. '- Avoid alarmist, judgmental, or sales-like phrasing.' . "\n"
			. '- No spoilers or revealing the main "aha" moment.' . "\n"
			. '- No "in conclusion" fillers, rambling, cliffhangers.' . "\n";
	}

	/**
	 * Build the system prompt for excerpt generation.
	 *
	 * @param string $existing      Current excerpt (may be empty).
	 * @param int    $target_length Target word count.
	 * @return array
	 */
	public function build_prompt( $existing = '', $target_length = 55 ) {
		$custom = get_option( 'versi_excerpt_prompt', '' );
		if ( ! empty( trim( $custom ) ) ) {
			return array( '', $custom );
		}

		$prompt = $this->default_prompt();

		if ( ! empty( $existing ) ) {
			$prompt .= "\n" . '**Existing excerpt for reference:** ' . $existing . "\n"
				. 'Improve upon it — do not repeat it verbatim.' . "\n";
		}

		return array( '', $prompt );
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
		// Strip trailing "(Keywords: ...)" or "(Keyphrases: ...)" appendix.
		$raw = preg_replace( '/\s*\(?(?:Keywords?|Keyphrases?)\s*:.*?\)?\s*$/i', '', $raw );
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

		$min   = absint( get_option( 'versi_excerpt_min_length', 50 ) );
		$short = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_excerpt != '' AND CHAR_LENGTH(post_excerpt) < %d",
				$min
			)
		);

		return compact( 'total', 'missing', 'has_excerpt', 'short' );
	}

	/**
	 * Bulk review excerpts for quality issues. Sends a batch of items to the
	 * AI in a single call and returns flagged items with reasons.
	 *
	 * @param int[] $ids Array of post IDs to review.
	 * @return array[] Each item: {id, title, excerpt, status, reason}
	 */
	public function bulk_review( $ids ) {
		$shared = Versi_Processor::init();
		$items  = array();

		foreach ( $ids as $id ) {
			$post    = get_post( $id );
			$excerpt = $post ? $shared->sanitize_input( $post->post_excerpt ) : '';
			$title   = $post ? $post->post_title : '';
			if ( '' === $excerpt ) {
				$items[] = array(
					'id'      => (int) $id,
					'excerpt' => '',
					'title'   => $title,
					'status'  => 'info',
					'reason'  => 'Missing excerpt (will be generated)',
				);
				continue;
			}
			$items[] = array(
				'id'      => (int) $id,
				'excerpt' => $excerpt,
				'title'   => $title,
			);
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			foreach ( $items as &$item ) {
				if ( ! isset( $item['status'] ) ) {
					$item['status'] = 'info';
					$item['reason'] = 'AI Client not available — review skipped.';
				}
			}
			return $items;
		}

		$lines = array();
		foreach ( $items as $i => $item ) {
			if ( isset( $item['status'] ) ) {
				continue;
			}
			$lines[] = 'ITEM ' . ( $i + 1 ) . ":\nID: " . $item['id'] . "\nExcerpt: " . $item['excerpt'];
		}

		if ( empty( $lines ) ) {
			return $items;
		}

		$prompt  = "Review each blog post excerpt below for quality.\n\n";
		$prompt .= implode( "\n---\n", $lines );
		$prompt .= "\n\n---\n";
		$prompt .= "For each ITEM, respond with one line:\n";
		$prompt .= "ITEM <num> | GOOD\n";
		$prompt .= "ITEM <num> | BAD | <reason>\n\n";
		$prompt .= 'Flag as BAD if: contains a URL, contains "(Keywords:" appendix, contains markdown/HTML/emojis, is very short (<10 words), is overly long, reads as generic/spammy, or is meaningless.';

		$system = 'You are an excerpt quality reviewer. Evaluate excerpt quality for blogs. Respond ONLY with the pipe-delimited format requested. No preamble. No commentary.';

		$builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $system );
		$builder = $shared->apply_text_preference( $builder, 'excerpt' );

		$result = $builder->generate_text();

		if ( is_wp_error( $result ) ) {
			foreach ( $items as &$item ) {
				if ( ! isset( $item['status'] ) ) {
					$item['status'] = 'info';
					$item['reason'] = 'Review failed: ' . $result->get_error_message();
				}
			}
			return $items;
		}

		$result_text = $result;
		preg_match_all( '/ITEM\s+(\d+)\s*\|\s*(GOOD|BAD)\s*(?:\|\s*(.*))?/i', $result_text, $matches, PREG_SET_ORDER );

		foreach ( $matches as $m ) {
			$idx    = (int) $m[1] - 1;
			$status = 'good' === strtolower( $m[2] ) ? 'good' : 'bad';
			$reason = isset( $m[3] ) ? trim( $m[3] ) : '';
			if ( isset( $items[ $idx ] ) ) {
				$items[ $idx ]['status'] = $status;
				$items[ $idx ]['reason'] = $reason;
			}
		}

		foreach ( $items as &$item ) {
			if ( ! isset( $item['status'] ) ) {
				$item['status'] = 'info';
				$item['reason'] = 'Could not parse AI review result.';
			}
		}

		return $items;
	}
}
