<?php

defined( 'ABSPATH' ) || exit;

class Versi_Batch_Processor {

	use Versi_Singleton;

	public function __construct() {
		add_action( 'versi_process_batch', array( $this, 'process_background_batch' ) );
	}

	public function process_background_batch() {
		$lock = get_transient( 'versi_batch_lock' );
		if ( $lock ) {
			return;
		}
		set_transient( 'versi_batch_lock', 1, 120 );

		$job = get_option( 'versi_job_status', false );
		if ( ! $job || empty( $job['is_running'] ) ) {
			delete_transient( 'versi_batch_lock' );
			return;
		}

		$this->process_single_batch( $job );

		delete_transient( 'versi_batch_lock' );

		if ( $job['is_running'] ) {
			$delay = ! empty( $job['retry_after'] ) ? $job['retry_after'] : 5;
			unset( $job['retry_after'] );

			if ( ! wp_next_scheduled( 'versi_process_batch' ) ) {
				wp_schedule_single_event( time() + $delay, 'versi_process_batch' );
			}
		}
	}

	private function process_single_batch( array &$job ): bool {
		$shared   = Versi_Container::get(Versi_Processor::class);
		$alt_proc = Versi_Container::get(Versi_Alt_Text_Processor::class);
		$exc_proc = Versi_Container::get(Versi_Excerpt_Processor::class);

		$batch    = absint( get_option( 'versi_batch_size', 5 ) );
		$workload = $job['workload'];
		$mode     = $job['mode'];

		$ids_result = array( 'ids' => array() );

		if ( 'alt' === $workload ) {
			$ids_result = $shared->get_image_ids( $mode, $job['offset'], $batch, $job['cat_id'] );
		} elseif ( 'seo' === $workload ) {
			$ids_result = $shared->get_seo_ids( $job['offset'], $batch );
		} elseif ( 'content' === $workload ) {
			$ids_result = $shared->get_post_ids( $job['offset'], $batch );
		} else {
			$ids_result = $shared->get_excerpt_ids( $mode, $job['offset'], $batch );
		}

		if ( empty( $ids_result['ids'] ) ) {
			$job['is_running'] = false;
			$job['completed']  = true;
			$job['updated_at'] = time();
			update_option( 'versi_job_status', $job, false );
			return false;
		}

		$counted  = 0;
		$min_wait = 0;

		foreach ( $ids_result['ids'] as $id ) {
			if ( 'alt' === $workload ) {
				$result = $alt_proc->process_single( $id );
			} elseif ( 'seo' === $workload ) {
				$result = $this->process_seo_single( $id );
			} elseif ( 'content' === $workload ) {
				$result = $this->process_content_single( $id, $mode );
			} else {
				$result = $exc_proc->process_single( $id );
			}

			if ( ! empty( $result['rate_limited'] ) ) {
				$wait     = ! empty( $result['retry_after'] ) ? max( (int) ceil( $result['retry_after'] ), 5 ) : 30;
				$min_wait = $min_wait ? min( $min_wait, $wait ) : $wait;
				continue;
			}

			++$job['processed'];
			++$counted;

			if ( 'error' === $result['status'] ) {
				++$job['failed'];
			}
		}

		$job['offset']    += $counted;
		$job['updated_at'] = time();

		if ( $min_wait ) {
			$job['retry_after'] = $min_wait;
		}

		if ( $job['processed'] >= $job['total'] ) {
			$job['is_running'] = false;
			$job['completed']  = true;
		}

		update_option( 'versi_job_status', $job, false );

		return $job['is_running'];
	}

	public function process_seo_single( $id ) {
		$post = get_post( $id );
		if ( ! $post ) {
			return Versi_Container::get(Versi_Processor::class)->result( $id, '', 'error', null, __( 'Post not found.', 'versi-content-tools' ) );
		}

		$ext       = Versi_Container::get(Versi_Extensions::class);
		$previous  = $ext->get_focus_keywords( $id );
		$generated = $ext->generate_focus_keywords( $id );
		$status    = ! empty( $generated );
		$rl        = Versi_Extensions::$last_rate_limit;

		return Versi_Container::get(Versi_Processor::class)->result(
			$id,
			$post->post_title,
			$status ? 'success' : 'error',
			$previous,
			$status ? null : __( 'AI generation failed.', 'versi-content-tools' ),
			null,
			$generated,
			$status,
			'',
			null !== $rl,
			null !== $rl ? $rl['retry_after'] : 0
		);
	}

	public function process_content_single( $id, $mode ) {
		$post = get_post( $id );
		if ( ! $post ) {
			return Versi_Container::get(Versi_Processor::class)->result( $id, '', 'error', null, __( 'Post not found.', 'versi-content-tools' ) );
		}

		$content     = $post->post_content;
		$changed     = false;
		$new_content = $content;

		if ( 'update_alt' === $mode || 'both' === $mode ) {
			$new_content = $this->process_content_update_alt( $new_content, $changed );
		}

		if ( 'strip_links' === $mode || 'both' === $mode ) {
			$new_content = $this->process_content_strip_links( $new_content, $changed );
		}

		if ( ! $changed ) {
			return Versi_Container::get(Versi_Processor::class)->result(
				$id,
				$post->post_title,
				'skipped',
				null,
				null,
				__( 'No changes needed.', 'versi-content-tools' )
			);
		}

		global $wpdb;
		$updated = $wpdb->update(
			$wpdb->posts,
			array( 'post_content' => $new_content ),
			array( 'ID' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return Versi_Container::get(Versi_Processor::class)->result(
				$id,
				$post->post_title,
				'error',
				null,
				__( 'Database update failed.', 'versi-content-tools' )
			);
		}

		clean_post_cache( $id );

		return Versi_Container::get(Versi_Processor::class)->result(
			$id,
			$post->post_title,
			'success',
			null,
			null,
			null,
			null,
			true
		);
	}

	private function process_content_update_alt( $content, &$changed ) {
		$changed = false;

		if ( empty( $content ) || false === stripos( $content, 'wp-image-' ) ) {
			return $content;
		}

		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $content;
		}

		$processor = new WP_HTML_Tag_Processor( $content );
		$processed = '';

		while ( $processor->next_tag( 'img' ) ) {
			$class = $processor->get_attribute( 'class' );
			if ( ! $class || ! preg_match( '/wp-image-(\d+)/i', $class, $m ) ) {
				continue;
			}

			$img_id   = (int) $m[1];
			$alt_meta = get_post_meta( $img_id, '_wp_attachment_image_alt', true );
			if ( '' === $alt_meta ) {
				continue;
			}

			$existing = $processor->get_attribute( 'alt' );
			if ( $existing === $alt_meta ) {
				continue;
			}

			$processor->set_attribute( 'alt', $alt_meta );
			$changed = true;
		}

		return $processor->get_updated_html();
	}

	private function process_content_strip_links( $content, &$changed ) {
		$changed = false;

		if ( '' === $content || false === stripos( $content, '<a' ) || false === stripos( $content, '<img' ) ) {
			return $content;
		}

		$pattern = '~<a\s[^>]*?href=["\']?[^"\'\s]+\.(?:jpg|jpeg|png|gif|webp)["\'\s>][^>]*>\s*(<img[^>]+>)\s*</a>~is';
		$new     = preg_replace( $pattern, '$1', $content, -1, $count );

		if ( $count > 0 ) {
			$changed = true;
		}

		return false !== $new ? $new : $content;
	}
}
