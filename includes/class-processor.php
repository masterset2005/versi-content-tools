<?php
/**
 * Shared AI processor: model preferences, context, sanitization.
 *
 * @package Versi_Content_Tools
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared infrastructure for AI-powered content workloads.
 */
class Versi_Processor {

	/**
	 * Depth counter for in-flight AI HTTP calls. While above zero, the
	 * HTTP timeout for outbound requests is extended to versi_ai_timeout.
	 *
	 * @var int
	 */
	private $ai_call_depth = 0;

	/**
	 * Mark the start of an AI HTTP call, extending the request timeout.
	 *
	 * @return void
	 */
	public function begin_ai_call() {
		if ( 0 === $this->ai_call_depth ) {
			add_filter( 'http_request_args', array( $this, 'extend_http_timeout' ), PHP_INT_MAX, 2 );
			// Best effort: allow the request to run at least as long as the AI timeout.
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( max( absint( get_option( 'versi_ai_timeout', 300 ) ), 300 ) );
			}
		}
		++$this->ai_call_depth;
	}

	/**
	 * Mark the end of an AI HTTP call.
	 *
	 * @return void
	 */
	public function end_ai_call() {
		if ( $this->ai_call_depth > 0 ) {
			--$this->ai_call_depth;
		}
		if ( 0 === $this->ai_call_depth ) {
			remove_filter( 'http_request_args', array( $this, 'extend_http_timeout' ), PHP_INT_MAX );
		}
	}

	/**
	 * Extend the HTTP timeout for requests made during AI calls so slow
	 * local/self-hosted models are not cut off by short defaults.
	 *
	 * @param array  $args HTTP request arguments.
	 * @param string $url  Request URL (unused).
	 * @return array
	 */
	public function extend_http_timeout( $args, $url = '' ) {
		unset( $url );
		$timeout = absint( get_option( 'versi_ai_timeout', 300 ) );
		if ( $timeout > 0 && ( ! isset( $args['timeout'] ) || (int) $args['timeout'] < $timeout ) ) {
			$args['timeout'] = $timeout;
		}
		return $args;
	}

	/**
	 * Get vision model preference as an array (for alt-text).
	 *
	 * @return string[]
	 */
	public function get_vision_model_preference() {
		$models = get_option( 'versi_alt_vision_model', '' );
		if ( '' === trim( $models ) ) {
			return array();
		}
		return array_map( 'trim', explode( ',', $models ) );
	}

	/**
	 * Get vision model fallback (if any).
	 *
	 * @return string
	 */
	public function get_vision_fallback() {
		return trim( get_option( 'versi_alt_vision_fallback', '' ) );
	}

	/**
	 * Get text model preference as an array.
	 *
	 * @param string $workload 'alt', 'excerpt', or 'seo'.
	 * @return string[]
	 */
	public function get_text_model_preference( $workload ) {
		$option_map  = array(
			'alt'     => 'versi_alt_text_model',
			'excerpt' => 'versi_excerpt_text_model',
			'seo'     => 'versi_seo_text_model',
		);
		$option_name = $option_map[ $workload ] ?? 'versi_alt_text_model';
		$models      = get_option( $option_name, '' );
		if ( '' === trim( $models ) ) {
			return array();
		}
		return array_map( 'trim', explode( ',', $models ) );
	}

	/**
	 * Get text model fallback for a workload.
	 *
	 * @param string $workload 'alt', 'excerpt', or 'seo'.
	 * @return string
	 */
	public function get_text_fallback( $workload ) {
		$map = array(
			'alt'     => 'versi_alt_text_fallback',
			'excerpt' => 'versi_excerpt_text_fallback',
			'seo'     => 'versi_seo_text_fallback',
		);
		$opt = $map[ $workload ] ?? '';
		return '' !== $opt ? trim( get_option( $opt, '' ) ) : '';
	}

	/**
	 * Apply vision model preference to a prompt builder.
	 *
	 * @param object $builder WP_AI_Client_Prompt_Builder instance.
	 * @return object
	 */
	public function apply_vision_preference( $builder ) {
		$models = $this->get_vision_model_preference();
		if ( ! empty( $models ) ) {
			$builder = $builder->using_model_preference( ...$models );
		}
		return $builder;
	}

	/**
	 * Apply text model preference to a prompt builder.
	 *
	 * @param object $builder  WP_AI_Client_Prompt_Builder instance.
	 * @param string $workload 'alt' or 'excerpt'.
	 * @return object
	 */
	public function apply_text_preference( $builder, $workload ) {
		$models = $this->get_text_model_preference( $workload );
		if ( ! empty( $models ) ) {
			$builder = $builder->using_model_preference( ...$models );
		}
		return $builder;
	}

	/**
	 * Gather context for an attachment: caption, title, article content.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{caption: string, title: string, article_title: string, article_content: string, existing_alt: string, author_style: string, filename_label: string}
	 */
	public function get_attachment_context( $attachment_id ) {
		$post      = get_post( $attachment_id );
		$parent_id = $post ? (int) $post->post_parent : 0;
		$context   = array(
			'caption'         => $this->sanitize_input( $post ? (string) $post->post_excerpt : '' ),
			'title'           => $this->sanitize_input( $post ? (string) $post->post_title : '' ),
			'article_title'   => '',
			'article_content' => '',
			'existing_alt'    => $this->sanitize_input( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ),
			'author_style'    => '',
			'filename_label'  => $this->extract_filename_label( $attachment_id ),
		);

		$parent = null;
		if ( $parent_id ) {
			$parent = get_post( $parent_id );
		}

		if ( $parent ) {
			$context['article_title'] = $this->sanitize_input( $parent->post_title );

			// Extract clean text through registered page builder extractors,
			// then strip any remaining HTML tags.
			$clean_content              = Versi_Extensions::get_clean_content( $parent->post_content, $parent->ID );
			$clean_content              = wp_strip_all_tags( $clean_content );
			$context['article_content'] = $this->sanitize_input(
				mb_substr(
					$clean_content,
					0,
					absint( get_option( 'versi_content_limit', 500 ) )
				)
			);
		}

		if ( '1' === get_option( 'versi_match_author_tone', '0' ) ) {
			$style_post_id = $parent ? $parent->ID : 0;
			if ( $style_post_id ) {
				$context['author_style'] = $this->get_author_style_sample( $style_post_id );
			}
		}

		return $context;
	}

	/**
	 * Sample recent posts by the same author to extract tone/style reference.
	 *
	 * @param int $post_id Current post ID to derive author from.
	 * @return string Empty if no samples, or a formatted style reference.
	 */
	public function get_author_style_sample( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		$samples = get_posts(
			array(
				'author'           => $post->post_author,
				'post_type'        => $post->post_type,
				'post_status'      => 'publish',
				'posts_per_page'   => 3,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'exclude'          => array( $post_id ),
				'suppress_filters' => true,
			)
		);

		if ( empty( $samples ) ) {
			return '';
		}

		$pieces = array();
		foreach ( $samples as $sample ) {
			$text = Versi_Extensions::get_clean_content( $sample->post_content, $sample->ID );
			$text = trim( preg_replace( '/\s+/', ' ', $text ) );
			if ( mb_strlen( $text ) < 30 ) {
				continue;
			}
			$pieces[] = mb_substr( $text, 0, 250 );
			if ( count( $pieces ) >= 2 ) {
				break;
			}
		}

		if ( empty( $pieces ) ) {
			return '';
		}

		return "Author's writing style (recent samples):\n- \"" . implode( "\"\n- \"", $pieces ) . '"';
	}

	/**
	 * Sanitize input: strip tags, trim, collapse whitespace.
	 *
	 * @param string $value Raw input.
	 * @return string
	 */
	public function sanitize_input( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = wp_strip_all_tags( $value );
		$value = trim( $value );
		$value = preg_replace( '/\s+/', ' ', $value );

		if ( mb_strlen( $value ) < 5 ) {
			return '';
		}

		$blacklist = array(
			'img_',
			'dsc_',
			'file_',
			'image',
			'photo',
			'picture',
			'placeholder',
			'untitled',
			'default',
			'no alt',
			'no description',
			'no title',
		);
		foreach ( $blacklist as $prefix ) {
			if ( 0 === stripos( $value, $prefix ) ) {
				return '';
			}
		}

		return $value;
	}

	/**
	 * Extract a likely label (e.g., person name) from an attachment filename.
	 *
	 * Converts separators to spaces, removes common non-name words
	 * (headshot, profile, photo, etc.) and standalone digits. Returns
	 * the result in Title Case when at least two meaningful words remain.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Label or empty string.
	 */
	public function extract_filename_label( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file ) {
			return '';
		}

		$basename = pathinfo( $file, PATHINFO_FILENAME );
		// Strip WordPress image size suffixes like -150x150.
		$basename = preg_replace( '/-\d+x\d+$/', '', $basename );
		// Replace separators with spaces.
		$name = preg_replace( '/[_-]+/', ' ', $basename );
		// Remove standalone digits.
		$name = preg_replace( '/\b\d+\b/', '', $name );
		// Collapse whitespace.
		$name = preg_replace( '/\s+/', ' ', $name );
		$name = trim( $name );

		// Remove common non-name tokens.
		$stop_words = array(
			'headshot',
			'profile',
			'photo',
			'picture',
			'portrait',
			'thumb',
			'thumbnail',
			'img',
			'image',
			'dsc',
			'pic',
			'mugshot',
			'selfie',
			'avatar',
			'screenshot',
		);
		$parts      = explode( ' ', $name );
		$filtered   = array();
		foreach ( $parts as $part ) {
			$lower = strtolower( $part );
			if ( in_array( $lower, $stop_words, true ) ) {
				continue;
			}
			if ( strlen( $part ) <= 1 ) {
				continue;
			}
			$filtered[] = $part;
		}

		if ( empty( $filtered ) ) {
			return '';
		}

		$name = implode( ' ', $filtered );
		$name = mb_convert_case( $name, MB_CASE_TITLE, 'UTF-8' );

		// Require at least two words or a title prefix (Dr, Mr, etc.).
		$word_count = count( explode( ' ', $name ) );
		if ( $word_count >= 2 || preg_match( '/^(Dr|Mr|Mrs|Ms|Prof|Rev|Sir|Lady|Lord)\s/i', $name ) ) {
			return $name;
		}

		return '';
	}

	/**
	 * Get the admin thumbnail URL for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public function thumbnail_url( $attachment_id ) {
		$src = wp_get_attachment_image_src( $attachment_id, 'thumbnail' );
		return $src ? $src[0] : '';
	}

	/**
	 * Get image IDs for bulk processing, filtered by mode and optional category.
	 *
	 * @param string $mode   'missing', 'too_long', 'too_short', 'review', or 'regenerate'.
	 * @param int    $offset Pagination offset.
	 * @param int    $batch  Batch size.
	 * @param int    $cat_id Category ID filter (0 = all).
	 * @return array{ids: int[], total: int}
	 */
	public function get_image_ids( $mode, $offset, $batch, $cat_id = 0 ) {
		$args = array(
			'post_type'        => 'attachment',
			'post_mime_type'   => 'image',
			'post_status'      => 'inherit',
			'posts_per_page'   => $batch,
			'offset'           => $offset,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'fields'           => 'ids',
			'no_found_rows'    => false,
			'suppress_filters' => true,
		);

		if ( ! empty( $cat_id ) ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'category',
					'field'    => 'term_id',
					'terms'    => (int) $cat_id,
				),
			);
		}

		if ( 'missing' === $mode ) {
			$args['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key'     => '_wp_attachment_image_alt',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_wp_attachment_image_alt',
					'value'   => '',
					'compare' => '=',
				),
			);
		}

		if ( 'too_long' === $mode || 'too_short' === $mode ) {
			$args['suppress_filters'] = false;
			$args['meta_query']       = array(
				array(
					'key'     => '_wp_attachment_image_alt',
					'compare' => 'EXISTS',
				),
			);
			$hook                     = 'too_long' === $mode ? 'filter_alt_too_long' : 'filter_alt_too_short';
			add_filter( 'posts_where', array( $this, $hook ) );
		}

		$query = new WP_Query( $args );

		if ( 'too_long' === $mode ) {
			remove_filter( 'posts_where', array( $this, 'filter_alt_too_long' ) );
		}
		if ( 'too_short' === $mode ) {
			remove_filter( 'posts_where', array( $this, 'filter_alt_too_short' ) );
		}

		return array(
			'ids'   => $query->posts,
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * Filter posts_where to only include images with alt text over 125 chars.
	 *
	 * @param string $where The WHERE clause.
	 * @return string
	 */
	public function filter_alt_too_long( $where ) {
		global $wpdb;
		$where .= $wpdb->prepare(
			" AND {$wpdb->postmeta}.meta_key = '_wp_attachment_image_alt' AND CHAR_LENGTH({$wpdb->postmeta}.meta_value) > %d",
			125
		);
		return $where;
	}

	/**
	 * Filter posts_where to only include images with alt text under 15 chars.
	 *
	 * @param string $where The WHERE clause.
	 * @return string
	 */
	public function filter_alt_too_short( $where ) {
		global $wpdb;
		$where .= $wpdb->prepare(
			" AND {$wpdb->postmeta}.meta_key = '_wp_attachment_image_alt' AND CHAR_LENGTH({$wpdb->postmeta}.meta_value) BETWEEN 1 AND %d",
			15
		);
		return $where;
	}

	/**
	 * Get enabled post types for processing.
	 *
	 * @return string[]
	 */
	public function get_enabled_post_types(): array {
		$saved = get_option( 'versi_post_types', 'post' );
		$types = array_map( 'trim', explode( ',', $saved ) );
		return array_filter( $types, 'post_type_exists' );
	}

	/**
	 * Get post IDs for content cleanup processing.
	 *
	 * @param int $offset Pagination offset.
	 * @param int $batch  Batch size.
	 * @return array{ids: int[], total: int}
	 */
	public function get_post_ids( $offset, $batch ) {
		$args = array(
			'post_type'        => $this->get_enabled_post_types(),
			'post_status'      => 'publish',
			'posts_per_page'   => $batch,
			'offset'           => $offset,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'fields'           => 'ids',
			'no_found_rows'    => false,
			'suppress_filters' => true,
		);

		$query = new WP_Query( $args );
		return array(
			'ids'   => $query->posts,
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * Get post IDs for SEO processing.
	 *
	 * @param int $offset Pagination offset.
	 * @param int $batch  Batch size.
	 * @return array{ids: int[], total: int}
	 */
	public function get_seo_ids( $offset, $batch ) {
		$args = array(
			'post_type'        => $this->get_enabled_post_types(),
			'post_status'      => 'publish',
			'posts_per_page'   => $batch,
			'offset'           => $offset,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'fields'           => 'ids',
			'no_found_rows'    => false,
			'suppress_filters' => true,
		);

		$query = new WP_Query( $args );
		return array(
			'ids'   => $query->posts,
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * Get post IDs for excerpt processing.
	 *
	 * @param string $mode   'missing', 'short', 'long', or 'improve'.
	 * @param int    $offset Pagination offset.
	 * @param int    $batch  Batch size.
	 * @return array{ids: int[], total: int}
	 */
	public function get_excerpt_ids( $mode, $offset, $batch ) {
		$args = array(
			'post_type'      => $this->get_enabled_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => $batch,
			'offset'         => $offset,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => false,
		);

		if ( 'missing' === $mode ) {
			add_filter( 'posts_where', array( $this, 'filter_missing_excerpt' ) );
		} elseif ( 'short' === $mode ) {
			add_filter( 'posts_where', array( $this, 'filter_short_excerpt' ) );
		} elseif ( 'long' === $mode ) {
			add_filter( 'posts_where', array( $this, 'filter_long_excerpt' ) );
		}

		$query = new WP_Query( $args );

		if ( 'missing' === $mode ) {
			remove_filter( 'posts_where', array( $this, 'filter_missing_excerpt' ) );
		} elseif ( 'short' === $mode ) {
			remove_filter( 'posts_where', array( $this, 'filter_short_excerpt' ) );
		} elseif ( 'long' === $mode ) {
			remove_filter( 'posts_where', array( $this, 'filter_long_excerpt' ) );
		}

		return array(
			'ids'   => $query->posts,
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * Filter posts_where to only include posts with empty post_excerpt.
	 *
	 * @param string $where The WHERE clause.
	 * @return string
	 */
	public function filter_missing_excerpt( $where ) {
		global $wpdb;
		$where .= " AND {$wpdb->posts}.post_excerpt = ''";
		return $where;
	}

	/**
	 * Filter posts_where to only include posts with a non-empty but very
	 * short excerpt (below versi_excerpt_min_length).
	 *
	 * @param string $where The WHERE clause.
	 * @return string
	 */
	public function filter_short_excerpt( $where ) {
		global $wpdb;
		$min    = absint( get_option( 'versi_excerpt_min_length', 50 ) );
		$where .= $wpdb->prepare(
			" AND {$wpdb->posts}.post_excerpt != '' AND CHAR_LENGTH({$wpdb->posts}.post_excerpt) < %d",
			$min
		);
		return $where;
	}

	/**
	 * Filter posts_where to only include posts with a non-empty but overly
	 * long excerpt (above versi_excerpt_max_length).
	 *
	 * @param string $where The WHERE clause.
	 * @return string
	 */
	public function filter_long_excerpt( $where ) {
		global $wpdb;
		$max    = absint( get_option( 'versi_excerpt_max_length', 155 ) );
		$where .= $wpdb->prepare(
			" AND {$wpdb->posts}.post_excerpt != '' AND CHAR_LENGTH({$wpdb->posts}.post_excerpt) > %d",
			$max
		);
		return $where;
	}

	/**
	 * Format a result array.
	 *
	 * @param int    $id        Post/attachment ID.
	 * @param string $title     Title.
	 * @param string $status    success|error|skipped.
	 * @param string $previous  Previous value (nullable).
	 * @param string $error     Error message (nullable).
	 * @param string $reason    Skip reason (nullable).
	 * @param string $generated Generated value (nullable).
	 * @param bool   $changed   Whether value changed.
	 * @param string $thumbnail Thumbnail URL.
	 * @param bool   $rate_limited Whether this was a rate-limit error.
	 * @param int    $retry_after  Seconds to wait before retry.
	 * @return array
	 */
	public function result( $id, $title, $status, $previous = null, $error = null, $reason = null, $generated = null, $changed = false, $thumbnail = '', $rate_limited = false, $retry_after = 0 ) {
		$out = array(
			'id'     => $id,
			'title'  => $title,
			'status' => $status,
		);
		if ( null !== $previous ) {
			$out['previous'] = $previous;
		}
		if ( null !== $error ) {
			$out['error'] = $error;
		}
		if ( null !== $reason ) {
			$out['reason'] = $reason;
		}
		if ( null !== $generated ) {
			$out['generated'] = $generated;
		}
		$out['changed']      = (bool) $changed;
		$out['thumbnail']    = $thumbnail;
		$out['rate_limited'] = (bool) $rate_limited;
		$out['retry_after']  = (float) $retry_after;
		return $out;
	}

	/**
	 * Classify error message for retry/fallback logic.
	 *
	 * @param string $message The error message.
	 * @return array
	 */
	public function classify_error( $message ) {
		// Default: no retry.
		$result = array(
			'retry_after'  => false,
			'should_retry' => false,
			'reason'       => 'unknown',
		);

		// 1. Rate Limit parsing.
		$retry_after = false;
		if ( preg_match( '/Please retry in\s+([\d.]+)\s*s/i', $message, $m ) ) {
			$retry_after = (float) $m[1];
		} elseif ( preg_match( '/(?:retry|try again)(?:\s+after)?\s+in?\s+([\d.]+)\s*s(?:econds?)?/i', $message, $m ) ) {
			$retry_after = (float) $m[1];
		} elseif ( preg_match( '/\b(?:429|Too Many Requests|rate.limit|quota.exceeded|resource.exhausted)\b/i', $message ) ) {
			$retry_after = 5.0;
		}

		if ( false !== $retry_after ) {
			$result['retry_after']  = $retry_after;
			$result['should_retry'] = true;
			$result['reason']       = 'rate_limit';
			return $result;
		}

		// 2. Transient Errors (503, Timeouts, incomplete AI responses).
		if ( preg_match( '/\b(?:503|Service Unavailable|timeout|cURL error 28)\b/i', $message ) || str_contains( $message, 'Missing the "candidates[0].content" key' ) || str_contains( $message, 'ai_incomplete' ) ) {
			$result['retry_after']  = 30.0; // Default backoff.
			$result['should_retry'] = true;
			$result['reason']       = 'transient_error';
			return $result;
		}

		// 3. Fatal/Non-retryable (400).
		if ( preg_match( '/\b(?:400|Bad Request)\b/i', $message ) ) {
			$result['should_retry'] = false;
			$result['reason']       = 'bad_request';
			return $result;
		}

		return $result;
	}

	/**
	 * Generate text with automatic retry and fallback model support.
	 *
	 * @param object $builder    WP_AI_Client_Prompt_Builder instance.
	 * @param string $fallback   Fallback model ID (empty = no fallback).
	 * @return string|\WP_Error Generated text or WP_Error.
	 */
	public function generate_with_retry( $builder, $fallback = '' ) {
		$this->begin_ai_call();
		try {
			$result = $builder->generate_text();

			if ( is_wp_error( $result ) ) {
				$error_info = $this->classify_error( $result->get_error_message() );
				if ( $error_info['should_retry'] && '' !== $fallback ) {
					$result = $builder->using_model_preference( $fallback )->generate_text();
				}
			}

			return $result;
		} finally {
			$this->end_ai_call();
		}
	}
}
