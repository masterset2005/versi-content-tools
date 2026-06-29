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

	use Versi_Singleton;

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
	 * @return array{caption: string, title: string, article_title: string, article_content: string, existing_alt: string, author_style: string}
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
		);

		$parent = null;
		if ( $parent_id ) {
			$parent = get_post( $parent_id );
		}

		if ( $parent ) {
			$context['article_title']   = $this->sanitize_input( $parent->post_title );
			$context['article_content'] = $this->sanitize_input(
				mb_substr(
					wp_strip_all_tags( $parent->post_content ),
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
			$text = wp_strip_all_tags( $sample->post_content );
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
	 * @param string $mode   'missing', 'review', or 'regenerate'.
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

		$query = new WP_Query( $args );
		return array(
			'ids'   => $query->posts,
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * Get post IDs for SEO focus keyword generation.
	 *
	 * @param int $offset Pagination offset.
	 * @param int $batch  Batch size.
	 * @return array{ids: int[], total: int}
	 */
	public function get_seo_ids( $offset, $batch ) {
		$args = array(
			'post_type'        => 'post',
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
	 * @param string $mode   'missing' or 'improve'.
	 * @param int    $offset Pagination offset.
	 * @param int    $batch  Batch size.
	 * @return array{ids: int[], total: int}
	 */
	public function get_excerpt_ids( $mode, $offset, $batch ) {
		$args = array(
			'post_type'        => 'post',
			'post_status'      => 'publish',
			'posts_per_page'   => $batch,
			'offset'           => $offset,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'fields'           => 'ids',
			'no_found_rows'    => false,
			'suppress_filters' => true,
		);

		if ( 'missing' === $mode ) {
			add_action( 'posts_where', array( $this, 'filter_missing_excerpt' ) );
		}

		$query = new WP_Query( $args );

		if ( 'missing' === $mode ) {
			remove_action( 'posts_where', array( $this, 'filter_missing_excerpt' ) );
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
	 * @return array
	 */
	public function result( $id, $title, $status, $previous = null, $error = null, $reason = null, $generated = null, $changed = false, $thumbnail = '' ) {
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
		$out['changed']   = (bool) $changed;
		$out['thumbnail'] = $thumbnail;
		return $out;
	}
}
