<?php
/**
 * Alt-text workload: prompts, cleanup, single-pass/two-pass processing.
 *
 * @package Versi_Content_Tools
 */

defined( 'ABSPATH' ) || exit;

/**
 * Processes images to generate, review, or regenerate alt text.
 */
class Versi_Alt_Text_Processor {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get or create the singleton.
	 *
	 * @return self
	 */
	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Process a single attachment: generate alt text via AI.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array
	 */
	public function process_single( $attachment_id ) {
		$shared = Versi_Processor::init();
		$context = $shared->get_attachment_context( $attachment_id );
		$file    = get_attached_file( $attachment_id );
		$mime    = get_post_mime_type( $attachment_id );
		$title   = get_the_title( $attachment_id );

		if ( ! $file || ! file_exists( $file ) ) {
			return $shared->result( $attachment_id, $title, 'error', null, __( 'File not found on server.', 'versi-content-tools' ) );
		}

		if ( ! is_string( $mime ) ) {
			return $shared->result( $attachment_id, $title, 'error', null, __( 'Could not determine file type.', 'versi-content-tools' ) );
		}

		if ( ! str_starts_with( $mime, 'image/' ) ) {
			return $shared->result( $attachment_id, $title, 'skipped', null, null, __( 'Not an image.', 'versi-content-tools' ) );
		}

		if ( 'image/svg+xml' === $mime ) {
			return $shared->result( $attachment_id, $title, 'skipped', null, null, __( 'SVG images are not supported.', 'versi-content-tools' ) );
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return $shared->result( $attachment_id, $title, 'error', null, __( 'AI Client not available.', 'versi-content-tools' ) );
		}

		$mode = get_option( 'versi_alt_processing_mode', 'two-pass' );

		if ( '1' === get_option( 'versi_debug_mode', '0' ) ) {
			error_log( '--- VERSI MODE DEBUG --- Alt-text processing mode: ' . $mode . ' for attachment #' . $attachment_id );
		}

		if ( 'single-pass' === $mode ) {
			list( $system, $prompt ) = $this->build_single_prompt( $context );
			$builder = wp_ai_client_prompt( $prompt )
				->using_system_instruction( $system )
				->with_file( $file, $mime );
			$builder = $shared->apply_vision_preference( $builder );

			if ( '1' === get_option( 'versi_debug_mode', '0' ) ) {
				error_log( '--- VERSI PROMPT DEBUG (single-pass) ---' );
				error_log( 'SYSTEM: ' . $system );
				error_log( 'PROMPT: ' . $prompt );
			}

			$alt_text = $builder->generate_text();

			if ( is_wp_error( $alt_text ) ) {
				return $shared->result( $attachment_id, $title, 'error', null, sprintf(
					/* translators: %s: AI provider error message */
					__( 'AI generation failed: %s', 'versi-content-tools' ),
					$alt_text->get_error_message()
				) );
			}
		} else {
			list( $prompt, $system ) = $this->build_prompt();
			$builder = wp_ai_client_prompt( $prompt )
				->using_system_instruction( $system )
				->with_file( $file, $mime );
			$builder = $shared->apply_vision_preference( $builder );

			if ( '1' === get_option( 'versi_debug_mode', '0' ) ) {
				error_log( '--- VERSI PROMPT DEBUG (two-pass vision) ---' );
				error_log( 'SYSTEM: ' . $system );
				error_log( 'PROMPT: ' . $prompt );
			}

			$alt_text = $builder->generate_text();

			if ( is_wp_error( $alt_text ) ) {
				return $shared->result( $attachment_id, $title, 'error', null, sprintf(
					/* translators: %s: AI provider error message */
					__( 'AI generation failed: %s', 'versi-content-tools' ),
					$alt_text->get_error_message()
				) );
			}

			$alt_text = $this->compare_alt_texts( $context, $alt_text );
		}

		$alt_text = $this->clean_alt_text( $alt_text );

		$changed = $alt_text !== $context['existing_alt'];
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

		return $shared->result( $attachment_id, $title, 'success', $context['existing_alt'], null, null, $alt_text, $changed, $shared->thumbnail_url( $attachment_id ) );
	}

	/**
	 * Clean raw alt text: decorative check, sanitize, strip labels, truncate.
	 *
	 * @param string $raw Raw alt text from AI.
	 * @return string
	 */
	public function clean_alt_text( $raw ) {
		if ( preg_match( '/\[\[DECORATIVE(?:_ALT)?\]\]/i', $raw ) ) {
			$raw = '';
		}

		$raw = sanitize_text_field( $raw );
		$raw = preg_replace( '/^["\'\x{2018}\x{2019}\x{201C}\x{201D}]+|["\'\x{2018}\x{2019}\x{201C}\x{201D}]+$/u', '', $raw );
		$raw = preg_replace( '/^(?:An?|The)\s+(?:image|photo|picture|shot|scene|view)(?:\s+(?:shows?|features?|depicts?|showcases?|displays?|presents?|captures?|of|with|in))?\s+/i', '', $raw );
		$raw = preg_replace( '/^(?:Informative|Decorative|Functional)(?:\s+alt)?(?::|\s+)?\s*/i', '', $raw );
		$raw = preg_replace( '/^Output:\s+/i', '', $raw );
		$raw = preg_replace( '/\[\[.*?\]\]/s', '', $raw );
		$raw = trim( $raw );

		if ( strlen( $raw ) > 125 ) {
			$raw = substr( $raw, 0, 125 );
		}

		return $raw;
	}

	/**
	 * Build prompt and system instruction for the two-pass vision model.
	 *
	 * @return array{0: string, 1: string}
	 */
	public function build_prompt() {
		$custom = get_option( 'versi_alt_system_prompt', '' );
		if ( ! empty( trim( $custom ) ) ) {
			$system = $custom;
			$prompt = 'Analyze this image and describe everything visible.';
		} else {
			$system = 'You are a **visual description specialist**.' . "\n"
				. 'Describe only what is visibly present in the image:' . "\n"
				. '- Subjects, objects, actions, setting, text, and details' . "\n"
				. '- Do not infer purpose, meaning, emotions, or context' . "\n"
				. '- Do not shorten for accessibility' . "\n"
				. '- Be factual and concise';
			$prompt = 'Describe everything visible in this image.';
		}

		return array( $prompt, $system );
	}

	/**
	 * Build system + user prompt for single-pass mode.
	 *
	 * @param array $context Attachment context.
	 * @return array{0: string, 1: string}
	 */
	public function build_single_prompt( $context ) {
		$custom = get_option( 'versi_alt_single_prompt', '' );
		if ( ! empty( trim( $custom ) ) ) {
			$system = $custom;
		} else {
			$system = $this->default_single_prompt();
		}

		$system = str_replace(
			array( '{caption}', '{title}', '{article_title}', '{article_excerpt}', '{article_content}', '{existing_alt}', '{visual_desc}' ),
			array( $context['caption'], $context['title'], $context['article_title'], $context['article_content'], $context['article_content'], $context['existing_alt'], '' ),
			$system
		);

		return array( $system, 'Generate alt text for this image following the system instructions.' );
	}

	/**
	 * Compare old and new alt text using a text-only AI call (Synthesizer).
	 *
	 * @param array  $context Attachment context.
	 * @param string $new_alt Newly generated alt text.
	 * @return string
	 */
	public function compare_alt_texts( $context, $new_alt ) {
		$custom = get_option( 'versi_alt_compare_prompt', '' );
		if ( ! empty( trim( $custom ) ) ) {
			$system = $custom;
			$system = str_replace(
				array( '{caption}', '{title}', '{article_title}', '{article_excerpt}', '{article_content}', '{existing_alt}', '{visual_desc}' ),
				array( $context['caption'], $context['title'], $context['article_title'], $context['article_content'], $context['article_content'], $context['existing_alt'], $new_alt ),
				$system
			);
		} else {
			$system = $this->default_compare_prompt();
		}

		$prompt = "**Caption:** " . $context['caption'] . "\n"
			. "**Title:** " . $context['title'] . "\n"
			. "**Article:** " . $context['article_title'] . "\n"
			. "**Content:** " . $context['article_content'] . "\n"
			. "**Current alt:** " . $context['existing_alt'] . "\n\n"
			. "**Vision:** " . $new_alt;

		if ( '1' === get_option( 'versi_debug_mode', '0' ) ) {
			error_log( '--- VERSI PROMPT DEBUG (synthesizer) ---' );
			error_log( 'SYSTEM: ' . $system );
			error_log( 'PROMPT: ' . $prompt );
		}

		$builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $system );

		$builder = Versi_Processor::init()->apply_text_preference( $builder );

		$result = $builder->generate_text();

		if ( is_wp_error( $result ) ) {
			return $new_alt;
		}

		return trim( $result );
	}

	/**
	 * Default two-pass vision system prompt.
	 *
	 * @return string
	 */
	public function default_system_prompt() {
		return 'You are a **visual description specialist**. Describe only what is visibly present in the image.' . "\n"
			. '- List subjects, objects, actions, setting, text, and details.' . "\n"
			. '- Do not infer purpose, meaning, emotions, or context.' . "\n"
			. '- Do not shorten for accessibility or style.' . "\n"
			. '- Be factual, neutral, and concise.';
	}

	/**
	 * Default single-pass prompt with full context and W3C rules.
	 *
	 * @return string
	 */
	public function default_single_prompt() {
		return 'You are an **accessibility expert** generating alt text for HTML images.' . "\n\n"
			. '**Input:** Context below + attached image' . "\n"
			. '**Output:** One sentence only' . "\n\n"
			. '**W3C Alt Decision Tree (follow in order):**' . "\n\n"
			. '1. **Decorative or redundant?** Image is purely decorative OR the same information is already in adjacent text.' . "\n"
			. '   → `[[DECORATIVE_ALT]]`' . "\n\n"
			. '2. **Functional?** Image is a link, button, control, or the only content of a link.' . "\n"
			. '   → Short text describing the action or destination — not the appearance.' . "\n\n"
			. '3. **Otherwise** → One sentence describing the image, using context when relevant.' . "\n\n"
			. '**Rules:**' . "\n"
			. '- Max **125 characters** — no quotes, no preamble, no explanations' . "\n"
			. '- **Forbidden starts:** `Image of`, `Photo of`, `Picture of`, `An image shows`, `The image features`' . "\n"
			. '- **Forbidden labels:** `Informative:`, `Output:`, `Functional:`, `Alt:`' . "\n"
			. '- Start with a noun phrase' . "\n\n"
			. '**Context:**' . "\n"
			. '**Caption:** {caption}' . "\n"
			. '**Title:** {title}' . "\n"
			. '**Article:** {article_title}' . "\n"
			. '**Content:** {article_content}' . "\n"
			. '**Current alt:** {existing_alt}' . "\n\n"
			. 'Output a single clean string. When uncertain, use `[[DECORATIVE_ALT]]`.';
	}

	/**
	 * Default compare (synthesizer) prompt for two-pass mode.
	 *
	 * @return string
	 */
	public function default_compare_prompt() {
		return 'You are an **AI formatter**.' . "\n\n"
			. '**Input:** Context + Visual Description' . "\n"
			. '**Output:** Final alt text only' . "\n\n"
			. '**Rules:**' . "\n"
			. '- If decorative or redundant → `[[DECORATIVE_ALT]]`' . "\n"
			. '- Otherwise → one sentence describing the image using context' . "\n"
			. '- **Forbidden labels:** `Informative:`, `Output:`, `Functional:`, `Alt:`' . "\n"
			. '- **Forbidden starts:** `Image of`, `Photo of`, `Picture of`, `An image shows`, `The image features`' . "\n"
			. '- Max **125 characters** — no quotes, no preamble, no explanations' . "\n\n"
			. 'Output a single clean string. When uncertain, use `[[DECORATIVE_ALT]]`.';
	}

	/**
	 * Get image alt-text stats: total, missing, too-long, too-short.
	 *
	 * @return array{total: int, missing: int, too_long: int, too_short: int}
	 */
	public function get_stats() {
		global $wpdb;

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%' AND post_status = 'inherit'"
		);

		$missing = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_wp_attachment_image_alt' WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%' AND p.post_status = 'inherit' AND (m.meta_id IS NULL OR m.meta_value = '')"
		);

		$too_long = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_image_alt' AND CHAR_LENGTH(meta_value) > %d",
				125
			)
		);

		$too_short = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_image_alt' AND CHAR_LENGTH(meta_value) BETWEEN 1 AND %d",
				15
			)
		);

		return compact( 'total', 'missing', 'too_long', 'too_short' );
	}
}
