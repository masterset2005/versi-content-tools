<?php

defined( 'ABSPATH' ) || exit;

class Versi_Admin_Ajax {

	use Versi_Singleton;

	public function __construct() {
		// AJAX: alt-text.
		add_action( 'wp_ajax_versi_alt_process_single', array( $this, 'ajax_alt_process_single' ) );
		add_action( 'wp_ajax_versi_alt_get_ids', array( $this, 'ajax_alt_get_ids' ) );
		add_action( 'wp_ajax_versi_alt_undo', array( $this, 'ajax_alt_undo' ) );

		// AJAX: excerpt.
		add_action( 'wp_ajax_versi_excerpt_process_single', array( $this, 'ajax_excerpt_process_single' ) );
		add_action( 'wp_ajax_versi_excerpt_get_ids', array( $this, 'ajax_excerpt_get_ids' ) );
		add_action( 'wp_ajax_versi_excerpt_undo', array( $this, 'ajax_excerpt_undo' ) );

		// AJAX: SEO.
		add_action( 'wp_ajax_versi_seo_process_single', array( $this, 'ajax_seo_process_single' ) );
		add_action( 'wp_ajax_versi_seo_get_ids', array( $this, 'ajax_seo_get_ids' ) );

		// AJAX: Content Cleanup.
		add_action( 'wp_ajax_versi_content_process_single', array( $this, 'ajax_content_process_single' ) );
		add_action( 'wp_ajax_versi_content_get_ids', array( $this, 'ajax_content_get_ids' ) );
		add_action( 'wp_ajax_versi_alt_bulk_review', array( $this, 'ajax_alt_bulk_review' ) );
		add_action( 'wp_ajax_versi_excerpt_bulk_review', array( $this, 'ajax_excerpt_bulk_review' ) );
		add_action( 'wp_ajax_versi_alt_save_single', array( $this, 'ajax_alt_save_single' ) );
		add_action( 'wp_ajax_versi_excerpt_save_single', array( $this, 'ajax_excerpt_save_single' ) );

		// AJAX: shared.
		add_action( 'wp_ajax_versi_get_models', array( $this, 'ajax_get_models' ) );
		add_action( 'wp_ajax_versi_create_job', array( $this, 'ajax_create_job' ) );
		add_action( 'wp_ajax_versi_job_status', array( $this, 'ajax_job_status' ) );
		add_action( 'wp_ajax_versi_cancel_job', array( $this, 'ajax_cancel_job' ) );
		add_action( 'wp_ajax_versi_save_job', array( $this, 'ajax_save_job' ) );
		add_action( 'wp_ajax_versi_load_job', array( $this, 'ajax_load_job' ) );
		add_action( 'wp_ajax_versi_dismiss_job', array( $this, 'ajax_dismiss_job' ) );
		add_action( 'wp_ajax_versi_run_audit', array( $this, 'ajax_run_audit' ) );
		add_action( 'wp_ajax_versi_audit_progress', array( $this, 'ajax_audit_progress' ) );
		add_action( 'wp_ajax_versi_link_attachment', array( $this, 'ajax_link_attachment' ) );
		add_action( 'wp_ajax_versi_save_results', array( $this, 'ajax_save_results' ) );
		add_action( 'wp_ajax_versi_get_history', array( $this, 'ajax_get_history' ) );
		add_action( 'wp_ajax_versi_get_history_run', array( $this, 'ajax_get_history_run' ) );
		add_action( 'wp_ajax_versi_clear_history', array( $this, 'ajax_clear_history' ) );
	}

	private function ajax_check( $nonce_action = 'versi_process' ) {
		check_ajax_referer( $nonce_action );

		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$this->check_rate_limit();
	}

	private function check_rate_limit() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		$key   = 'versi_rate_' . $user_id;
		$count = (int) get_transient( $key );
		if ( $count > 20 ) {
			wp_send_json_error( 'Rate limit exceeded. Please wait.' );
		}
		set_transient( $key, $count + 1, 10 );
	}

	private function user_can_edit_post( $post_id ) {
		if ( current_user_can( 'edit_others_posts' ) ) {
			return true;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}
		return get_current_user_id() === (int) $post->post_author;
	}

	private function user_can_edit_attachment( $attachment_id ) {
		if ( current_user_can( 'edit_others_posts' ) ) {
			return true;
		}
		$attachment = get_post( $attachment_id );
		if ( ! $attachment ) {
			return false;
		}
		if ( $attachment->post_parent ) {
			return $this->user_can_edit_post( $attachment->post_parent );
		}
		return get_current_user_id() === (int) $attachment->post_author;
	}

	private function validate_mode( $mode, $valid_modes = array() ) {
		if ( empty( $valid_modes ) ) {
			$valid_modes = array( 'missing', 'regenerate', 'review', 'bulk_review', 'improve', 'short', 'update_alt', 'strip_links', 'both', 'too_long', 'too_short' );
		}
		if ( ! in_array( $mode, $valid_modes, true ) ) {
			wp_send_json_error( 'Invalid mode.' );
		}
		return $mode;
	}

	public function ajax_alt_process_single() {
		$this->ajax_check();

		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$mode = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'missing';

		if ( ! $id ) {
			wp_send_json_error( 'No ID provided' );
		}

		if ( ! $this->user_can_edit_attachment( $id ) ) {
			wp_send_json_error( 'Insufficient permissions for this attachment.' );
		}

		$result = Versi_Container::get(Versi_Alt_Text_Processor::class)->process_single( $id );
		wp_send_json_success( $result );
	}

	public function ajax_alt_get_ids() {
		$this->ajax_check();

		$mode   = isset( $_POST['mode'] ) ? $this->validate_mode( sanitize_key( $_POST['mode'] ), array( 'missing', 'regenerate', 'review', 'too_long', 'too_short' ) ) : 'missing';
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$batch  = isset( $_POST['batch'] ) ? absint( $_POST['batch'] ) : 5;
		$cat_id = isset( $_POST['catId'] ) ? absint( $_POST['catId'] ) : 0;

		$result = Versi_Container::get(Versi_Processor::class)->get_image_ids( $mode, $offset, $batch, $cat_id );
		wp_send_json_success( $result );
	}

	public function ajax_alt_undo() {
		$this->ajax_check();

		$id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$alt = isset( $_POST['alt'] ) ? sanitize_text_field( wp_unslash( $_POST['alt'] ) ) : '';

		if ( ! $id ) {
			wp_send_json_error( 'No ID' );
		}

		if ( ! $this->user_can_edit_attachment( $id ) ) {
			wp_send_json_error( 'Insufficient permissions for this attachment.' );
		}

		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		wp_send_json_success(
			array(
				'id'  => $id,
				'alt' => $alt,
			)
		);
	}

	public function ajax_seo_process_single() {
		$this->ajax_check();

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( ! $id ) {
			wp_send_json_error( 'No ID provided' );
		}

		$post = get_post( $id );
		if ( ! $post ) {
			wp_send_json_error( 'Post not found' );
		}

		if ( ! $this->user_can_edit_post( $id ) ) {
			wp_send_json_error( 'Insufficient permissions for this post.' );
		}

		$ext       = Versi_Container::get(Versi_Extensions::class);
		$previous  = $ext->get_focus_keywords( $id );
		$generated = $ext->generate_focus_keywords( $id );
		$status    = ! empty( $generated );
		$rl        = Versi_Extensions::$last_rate_limit;

		$result = Versi_Container::get(Versi_Processor::class)->result(
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
		wp_send_json_success( $result );
	}

	public function ajax_seo_get_ids() {
		$this->ajax_check();

		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$batch  = isset( $_POST['batch'] ) ? absint( $_POST['batch'] ) : 5;

		$result = Versi_Container::get(Versi_Processor::class)->get_seo_ids( $offset, $batch );
		wp_send_json_success( $result );
	}

	public function ajax_content_get_ids() {
		$this->ajax_check();

		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$batch  = isset( $_POST['batch'] ) ? absint( $_POST['batch'] ) : 5;

		$result = Versi_Container::get(Versi_Processor::class)->get_post_ids( $offset, $batch );
		wp_send_json_success( $result );
	}

	public function ajax_content_process_single() {
		$this->ajax_check();

		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$mode = isset( $_POST['mode'] ) ? $this->validate_mode( sanitize_key( $_POST['mode'] ), array( 'update_alt', 'strip_links', 'both' ) ) : 'update_alt';

		if ( ! $id ) {
			wp_send_json_error( 'No ID provided' );
		}

		if ( ! $this->user_can_edit_post( $id ) ) {
			wp_send_json_error( 'Insufficient permissions for this post.' );
		}

		$result = Versi_Container::get(Versi_Batch_Processor::class)->process_content_single( $id, $mode );
		wp_send_json_success( $result );
	}

	public function ajax_alt_bulk_review() {
		$this->ajax_check();

		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$batch  = isset( $_POST['batch'] ) ? absint( $_POST['batch'] ) : absint( get_option( 'versi_batch_size', 5 ) );

		$ids_result = Versi_Container::get(Versi_Processor::class)->get_image_ids( 'regenerate', $offset, $batch, 0 );
		$ids        = $ids_result['ids'];
		$total      = $ids_result['total'];

		if ( empty( $ids ) ) {
			wp_send_json_success(
				array(
					'items' => array(),
					'total' => $total,
				)
			);
		}

		$items = Versi_Container::get(Versi_Alt_Text_Processor::class)->bulk_review( $ids );
		wp_send_json_success(
			array(
				'items' => $items,
				'total' => $total,
			)
		);
	}

	public function ajax_excerpt_bulk_review() {
		$this->ajax_check();

		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$batch  = isset( $_POST['batch'] ) ? absint( $_POST['batch'] ) : absint( get_option( 'versi_batch_size', 5 ) );

		$ids_result = Versi_Container::get(Versi_Processor::class)->get_excerpt_ids( 'improve', $offset, $batch );
		$ids        = $ids_result['ids'];
		$total      = $ids_result['total'];

		if ( empty( $ids ) ) {
			wp_send_json_success(
				array(
					'items' => array(),
					'total' => $total,
				)
			);
		}

		$items = Versi_Container::get(Versi_Excerpt_Processor::class)->bulk_review( $ids );
		wp_send_json_success(
			array(
				'items' => $items,
				'total' => $total,
			)
		);
	}

	public function ajax_excerpt_process_single() {
		$this->ajax_check();

		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$mode = isset( $_POST['mode'] ) ? $this->validate_mode( sanitize_key( $_POST['mode'] ), array( 'missing', 'improve', 'short', 'long' ) ) : '';

		if ( ! $id ) {
			wp_send_json_error( 'No ID provided' );
		}

		if ( ! $this->user_can_edit_post( $id ) ) {
			wp_send_json_error( 'Insufficient permissions for this post.' );
		}

		$result = Versi_Container::get(Versi_Excerpt_Processor::class)->process_single( $id, $mode );
		wp_send_json_success( $result );
	}

	public function ajax_excerpt_get_ids() {
		$this->ajax_check();

		$mode   = isset( $_POST['mode'] ) ? $this->validate_mode( sanitize_key( $_POST['mode'] ), array( 'missing', 'improve', 'short', 'long' ) ) : 'missing';
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$batch  = isset( $_POST['batch'] ) ? absint( $_POST['batch'] ) : 5;

		$result = Versi_Container::get(Versi_Processor::class)->get_excerpt_ids( $mode, $offset, $batch );
		wp_send_json_success( $result );
	}

	public function ajax_excerpt_undo() {
		$this->ajax_check();

		$id      = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$excerpt = isset( $_POST['alt'] ) ? sanitize_text_field( wp_unslash( $_POST['alt'] ) ) : '';

		if ( ! $id ) {
			wp_send_json_error( 'No ID' );
		}

		if ( ! $this->user_can_edit_post( $id ) ) {
			wp_send_json_error( 'Insufficient permissions for this post.' );
		}

		wp_update_post(
			array(
				'ID'           => $id,
				'post_excerpt' => $excerpt,
			)
		);

		wp_send_json_success(
			array(
				'id'  => $id,
				'alt' => $excerpt,
			)
		);
	}

	public function ajax_get_models() {
		check_ajax_referer( 'versi_get_models' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		if ( ! class_exists( 'WordPress\AiClient\AiClient' ) ) {
			wp_send_json_error( 'AI Client not available' );
		}

		$cached = get_transient( 'versi_ai_models' );
		if ( false !== $cached ) {
			wp_send_json_success( $cached );
		}

		try {
			$registry     = \WordPress\AiClient\AiClient::defaultRegistry();
			$provider_ids = $registry->getRegisteredProviderIds();
			$models       = array();

			foreach ( $provider_ids as $provider_id ) {
				if ( ! $registry->isProviderConfigured( $provider_id ) ) {
					continue;
				}

				$class_name      = $registry->getProviderClassName( $provider_id );
				$model_dir       = $class_name::modelMetadataDirectory();
				$provider_models = $model_dir->listModelMetadata();
				$group           = array();

				foreach ( $provider_models as $model ) {
					$group[] = array(
						'id'   => $model->getId(),
						'name' => $model->getName(),
					);
				}

				if ( ! empty( $group ) ) {
					$models[] = array(
						'provider' => $provider_id,
						'models'   => $group,
					);
				}
			}

			set_transient( 'versi_ai_models', $models, HOUR_IN_SECONDS );
			wp_send_json_success( $models );
		} catch ( \Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	public function ajax_create_job() {
		$this->ajax_check();

		$mode     = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'missing';
		$workload = isset( $_POST['workload'] ) ? sanitize_key( $_POST['workload'] ) : 'alt';

		$total  = 0;
		$cat_id = 0;

		if ( 'alt' === $workload ) {
			$cat_id = absint( get_option( 'versi_alt_cat_filter', 0 ) );
			$ids    = Versi_Container::get(Versi_Processor::class)->get_image_ids( $mode, 0, 1, $cat_id );
			$total  = $ids['total'];
		} elseif ( 'seo' === $workload ) {
			$ids   = Versi_Container::get(Versi_Processor::class)->get_seo_ids( 0, 1 );
			$total = $ids['total'];
		} elseif ( 'content' === $workload ) {
			$ids   = Versi_Container::get(Versi_Processor::class)->get_post_ids( 0, 1 );
			$total = $ids['total'];
		} else {
			$ids   = Versi_Container::get(Versi_Processor::class)->get_excerpt_ids( $mode, 0, 1 );
			$total = $ids['total'];
		}

		update_option(
			'versi_job_status',
			array(
				'is_running' => true,
				'completed'  => false,
				'processed'  => 0,
				'failed'     => 0,
				'total'      => $total,
				'mode'       => $mode,
				'workload'   => $workload,
				'offset'     => 0,
				'cat_id'     => $cat_id,
				'updated_at' => time(),
			),
			false
		);

		if ( ! wp_next_scheduled( 'versi_process_batch' ) ) {
			wp_schedule_single_event( time() + 10, 'versi_process_batch' );
		}

		wp_send_json_success(
			array(
				'total'    => $total,
				'workload' => $workload,
			)
		);
	}

	public function ajax_job_status() {
		$this->ajax_check( 'versi_job_status' );

		$job = get_option( 'versi_job_status', false );
		if ( ! $job ) {
			wp_send_json_error( 'No job found' );
		}

		$response                  = $job;
		$response['cron_disabled'] = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		if ( ! empty( $job['is_running'] ) && ! empty( $job['updated_at'] ) ) {
			$response['stalled'] = ( time() - (int) $job['updated_at'] ) > 120;
		}

		wp_send_json_success( $response );
	}

	public function ajax_cancel_job() {
		$this->ajax_check( 'versi_cancel_job' );

		update_option(
			'versi_job_status',
			array(
				'is_running' => false,
				'completed'  => false,
				'cancelled'  => true,
				'updated_at' => time(),
			),
			false
		);

		wp_send_json_success( array( 'cancelled' => true ) );
	}

	public function ajax_save_job() {
		$this->ajax_check();

		$workload = isset( $_POST['workload'] ) ? sanitize_key( $_POST['workload'] ) : '';
		$mode     = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : '';
		$offset   = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$total    = isset( $_POST['total'] ) ? absint( $_POST['total'] ) : 0;
		$done     = isset( $_POST['done'] ) ? absint( $_POST['done'] ) : 0;
		$status   = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'paused';

		if ( ! $workload || ! $mode ) {
			wp_send_json_error( 'Missing workload or mode' );
		}

		update_option(
			'versi_live_job_status',
			array(
				'workload' => $workload,
				'mode'     => $mode,
				'offset'   => $offset,
				'total'    => $total,
				'done'     => $done,
				'status'   => $status,
				'updated'  => time(),
			),
			false
		);

		wp_send_json_success( array( 'saved' => true ) );
	}

	public function ajax_load_job() {
		$this->ajax_check();

		$status = get_option( 'versi_live_job_status' );
		if ( ! $status ) {
			wp_send_json_success( array( 'exists' => false ) );
		}

		wp_send_json_success(
			array(
				'exists' => true,
				'data'   => $status,
			)
		);
	}

	public function ajax_dismiss_job() {
		$this->ajax_check();

		delete_option( 'versi_live_job_status' );
		wp_send_json_success( array( 'dismissed' => true ) );
	}

	public function ajax_save_results() {
		$this->ajax_check();

		$workload = isset( $_POST['workload'] ) ? sanitize_key( $_POST['workload'] ) : '';
		$mode     = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : '';
		$results  = isset( $_POST['results'] ) ? wp_unslash( $_POST['results'] ) : array();

		if ( ! $workload || ! $mode ) {
			wp_send_json_error( 'Missing workload or mode' );
		}

		$sanitized = array();
		foreach ( $results as $r ) {
			if ( ! isset( $r['id'], $r['status'] ) ) {
				continue;
			}
			$sanitized[] = array(
				'id'         => absint( $r['id'] ),
				'title'      => isset( $r['title'] ) ? sanitize_text_field( wp_unslash( $r['title'] ) ) : '',
				'status'     => sanitize_key( $r['status'] ),
				'previous'   => isset( $r['previous'] ) ? sanitize_text_field( wp_unslash( $r['previous'] ) ) : null,
				'error'      => isset( $r['error'] ) ? sanitize_text_field( wp_unslash( $r['error'] ) ) : null,
				'reason'     => isset( $r['reason'] ) ? sanitize_text_field( wp_unslash( $r['reason'] ) ) : null,
				'generated'  => isset( $r['generated'] ) ? sanitize_textarea_field( wp_unslash( $r['generated'] ) ) : null,
				'changed'    => ! empty( $r['changed'] ),
				'type'       => isset( $r['type'] ) ? sanitize_text_field( wp_unslash( $r['type'] ) ) : '',
				'rate_limited' => ! empty( $r['rate_limited'] ),
				'retry_after'  => isset( $r['retry_after'] ) ? absint( $r['retry_after'] ) : 0,
			);
		}

		$summary = array(
			'ok'      => 0,
			'errors'  => 0,
			'skipped' => 0,
		);
		if ( 'review' === $workload || 'bulk_review' === $mode ) {
			$summary = array(
				'good' => 0,
				'bad'  => 0,
				'info' => 0,
			);
		}
		foreach ( $sanitized as $r ) {
			if ( 'success' === $r['status'] || 'good' === $r['status'] ) {
				if ( isset( $summary['ok'] ) ) {
					++$summary['ok'];
				} else {
					++$summary['good'];
				}
			} elseif ( 'error' === $r['status'] || 'bad' === $r['status'] ) {
				if ( isset( $summary['errors'] ) ) {
					++$summary['errors'];
				} elseif ( isset( $summary['bad'] ) ) {
					++$summary['bad'];
				}
			} elseif ( isset( $summary['skipped'] ) ) {
				++$summary['skipped'];
			} else {
				++$summary['info'];
			}
		}

		$run_id = wp_generate_uuid4();

		update_option( 'versi_history_run_' . $run_id, $sanitized, false );

		$entry = array(
			'id'        => $run_id,
			'workload'  => $workload,
			'mode'      => $mode,
			'timestamp' => time(),
			'summary'   => $summary,
			'count'     => count( $sanitized ),
		);

		$history   = get_option( 'versi_processing_history', array() );
		$history[] = $entry;

		if ( count( $history ) > 50 ) {
			$old = array_slice( $history, 0, count( $history ) - 50 );
			$history = array_slice( $history, count( $history ) - 50 );
			foreach ( $old as $old_entry ) {
				delete_option( 'versi_history_run_' . $old_entry['id'] );
			}
		}

		update_option( 'versi_processing_history', $history, false );

		wp_send_json_success(
			array(
				'run_id' => $run_id,
			)
		);
	}

	public function ajax_get_history() {
		$this->ajax_check();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$history = get_option( 'versi_processing_history', array() );

		$runs = array();
		foreach ( array_reverse( $history ) as $entry ) {
			$runs[] = array(
				'id'        => $entry['id'],
				'workload'  => $entry['workload'],
				'mode'      => $entry['mode'],
				'timestamp' => $entry['timestamp'],
				'summary'   => $entry['summary'],
				'count'     => $entry['count'],
			);
		}

		wp_send_json_success( $runs );
	}

	public function ajax_get_history_run() {
		$this->ajax_check();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';

		if ( empty( $run_id ) ) {
			wp_send_json_error( 'No run ID provided' );
		}

		$results = get_option( 'versi_history_run_' . $run_id, false );
		if ( false === $results ) {
			wp_send_json_error( 'Run not found' );
		}

		wp_send_json_success( $results );
	}

	public function ajax_clear_history() {
		$this->ajax_check();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$history = get_option( 'versi_processing_history', array() );
		foreach ( $history as $entry ) {
			delete_option( 'versi_history_run_' . $entry['id'] );
		}
		delete_option( 'versi_processing_history' );
		wp_send_json_success( array( 'cleared' => true ) );
	}

	public function ajax_run_audit() {
		check_ajax_referer( 'versi_run_audit' );
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}
		$this->check_rate_limit();

		try {
			$total = Versi_Container::get(Versi_Auditor::class)->get_unlinked_count();
			wp_send_json_success(
				array(
					'total'    => $total,
					'complete' => ( 0 === $total ),
					'results'  => array(),
				)
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public function ajax_audit_progress() {
		check_ajax_referer( 'versi_run_audit' );
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}
		$this->check_rate_limit();

		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$limit  = isset( $_POST['limit'] ) ? max( 1, (int) $_POST['limit'] ) : Versi_Auditor::BATCH_SIZE;

		try {
			$total         = Versi_Container::get(Versi_Auditor::class)->get_unlinked_count();
			$batch_results = Versi_Container::get(Versi_Auditor::class)->find_unlinked_batch( $offset, $limit );
			$scanned       = min( $offset + $limit, $total );
			$complete      = $scanned >= $total;

			wp_send_json_success(
				array(
					'complete' => $complete,
					'results'  => $batch_results,
					'scanned'  => $scanned,
					'total'    => $total,
				)
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public function ajax_link_attachment() {
		check_ajax_referer( 'versi_link_attachment' );
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}
		$this->check_rate_limit();
		$att_id  = isset( $_POST['attachment_id'] ) ? (int) $_POST['attachment_id'] : 0;
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( ! $att_id || ! $post_id ) {
			wp_send_json_error( array( 'message' => 'Invalid attachment or post ID.' ) );
		}
		if ( ! $this->user_can_edit_attachment( $att_id ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions for this attachment.' ) );
		}
		if ( ! $this->user_can_edit_post( $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions for this post.' ) );
		}
		try {
			$result = Versi_Container::get(Versi_Auditor::class)->link_attachment( $att_id, $post_id );
			wp_send_json_success( $result );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public function ajax_alt_save_single() {
		$this->ajax_check();
		$id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$value = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => 'Invalid ID.' ) );
		}
		if ( ! $this->user_can_edit_attachment( $id ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}
		$title = get_the_title( $id );
		update_post_meta( $id, '_wp_attachment_image_alt', $value );
		$shared = Versi_Container::get( Versi_Processor::class );
		wp_send_json_success( $shared->result( $id, $title, 'success', null, $value ) );
	}

	public function ajax_excerpt_save_single() {
		$this->ajax_check();
		$id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$value = isset( $_POST['value'] ) ? sanitize_textarea_field( wp_unslash( $_POST['value'] ) ) : '';
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => 'Invalid ID.' ) );
		}
		if ( ! $this->user_can_edit_post( $id ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}
		wp_update_post(
			array(
				'ID'           => $id,
				'post_excerpt' => $value,
			)
		);
		$title = get_the_title( $id );
		$shared = Versi_Container::get( Versi_Processor::class );
		wp_send_json_success( $shared->result( $id, $title, 'success', null, $value ) );
	}

}
