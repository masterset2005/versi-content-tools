<?php
/**
 * Admin: settings, processing page, AJAX, batch processing.
 *
 * @package Versi_Content_Tools
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin hooks for all Versi workloads.
 */
class Versi_Admin {

	use Versi_Singleton;

	/**
	 * Hook into WordPress admin.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_menu', array( $this, 'add_processing_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_notices', array( $this, 'alt_quick_action_notice' ) );
		add_action( 'admin_notices', array( $this, 'alt_generated_notice' ) );
		add_filter( 'wp_prepare_attachment_for_js', array( $this, 'mark_auto_generated' ), 10, 2 );
		add_action( 'admin_footer-upload.php', array( $this, 'generated_script' ) );
		add_action( 'add_attachment', array( $this, 'alt_auto_generate_on_upload' ) );
		add_action( 'transition_post_status', array( $this, 'excerpt_auto_generate_on_publish' ), 10, 3 );

		// Content filter: update alt attributes in embedded images on the fly.
		if ( '1' === get_option( 'versi_alt_update_content', '0' ) ) {
			add_filter( 'the_content', array( $this, 'filter_content_alt_attributes' ) );
		}

		// Content filter: strip self-linking image wrappers.
		if ( '1' === get_option( 'versi_strip_self_links', '0' ) ) {
			add_filter( 'the_content', array( $this, 'filter_strip_self_linking_images' ) );
		}

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
		add_action( 'versi_process_batch', array( $this, 'process_background_batch' ) );
	}

	/**
	 * No-op stub (scripts are inlined). Kept for future extensibility.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_scripts( $hook ) {
		$plugin_pages = array( 'media_page_versi-processing', 'settings_page_versi-content-tools' );
		if ( ! in_array( $hook, $plugin_pages, true ) ) {
			return;
		}

		$css_ver = filemtime( VERSI_PLUGIN_DIR . 'assets/css/admin.css' );
		wp_enqueue_style( 'versi-admin-css', VERSI_PLUGIN_URL . 'assets/css/admin.css', array(), $css_ver );

		if ( 'settings_page_versi-content-tools' === $hook ) {
			$js_ver = filemtime( VERSI_PLUGIN_DIR . 'assets/js/admin.js' );
			wp_enqueue_script( 'versi-admin', VERSI_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), $js_ver, true );
			wp_localize_script(
				'versi-admin',
				'versiAdmin',
				array(
					'modelsNonce' => wp_create_nonce( 'versi_get_models' ),
				)
			);
		}

		if ( 'media_page_versi-processing' === $hook ) {
			$js_ver = filemtime( VERSI_PLUGIN_DIR . 'assets/js/processing.js' );
			wp_enqueue_script( 'versi-processing', VERSI_PLUGIN_URL . 'assets/js/processing.js', array( 'jquery' ), $js_ver, true );
			wp_localize_script(
				'versi-processing',
				'versiProcessing',
				array(
					'nonce'     => wp_create_nonce( 'versi_process' ),
					'batchSize' => get_option( 'versi_batch_size', 5 ),
					'workload'  => isset( $_GET['versi_workload'] ) ? sanitize_key( wp_unslash( $_GET['versi_workload'] ) ) : '',
					'l10n'      => array(
						'pausedJobMsg'     => __( 'You have a paused job (%1$s mode, %2$s/%3$s items processed).', 'versi-content-tools' ),
						'resuming'         => __( 'Resuming...', 'versi-content-tools' ),
						'starting'         => __( 'Starting...', 'versi-content-tools' ),
						'resume'           => __( 'Resume', 'versi-content-tools' ),
						'pause'            => __( 'Pause', 'versi-content-tools' ),
						'stopped'          => __( 'Stopped.', 'versi-content-tools' ),
						'complete'         => __( 'Complete.', 'versi-content-tools' ),
						'errors'           => __( 'errors: ', 'versi-content-tools' ),
						'downloadCsv'      => __( 'Download CSV', 'versi-content-tools' ),
						'rateLimited'      => __( 'Rate limited — retrying', 'versi-content-tools' ),
						'aiFailed'         => __( 'AI generation failed.', 'versi-content-tools' ),
						'rateLimitExceeded' => __( '(rate limit exceeded after retries)', 'versi-content-tools' ),
						'requestFailed'    => __( 'Request failed', 'versi-content-tools' ),
						'paused'           => __( 'Paused.', 'versi-content-tools' ),
						'failedFetch'      => __( 'Failed to fetch item list.', 'versi-content-tools' ),
						'failedReview'     => __( 'Failed to fetch review batch.', 'versi-content-tools' ),
						'reviewConfirm'    => __( 'This will send batches to AI for quality review (no content will be changed). Continue?', 'versi-content-tools' ),
						'overwriteConfirm' => __( 'This will overwrite existing content. Are you sure?', 'versi-content-tools' ),
						'reviewComplete'   => __( 'Review complete.', 'versi-content-tools' ),
						'processing'       => __( 'Processing', 'versi-content-tools' ),
						'remaining'        => __( 'remaining', 'versi-content-tools' ),
						'in'               => __( 'in', 'versi-content-tools' ),
					),
				)
			);

			$js_ver2 = filemtime( VERSI_PLUGIN_DIR . 'assets/js/background.js' );
			wp_enqueue_script( 'versi-background', VERSI_PLUGIN_URL . 'assets/js/background.js', array( 'jquery' ), $js_ver2, true );
			wp_localize_script(
				'versi-background',
				'versiBackground',
				array(
					'nonce'        => wp_create_nonce( 'versi_process' ),
					'cancelNonce'  => wp_create_nonce( 'versi_cancel_job' ),
					'statusNonce'  => wp_create_nonce( 'versi_job_status' ),
					'l10n'         => array(
						'cancelling'   => __( 'Cancelling...', 'versi-content-tools' ),
						'complete'     => __( 'Complete!', 'versi-content-tools' ),
						'startConfirm' => __( 'Start background processing? You can close the browser and check back later.', 'versi-content-tools' ),
						'started'      => __( 'Started', 'versi-content-tools' ),
					),
				)
			);

			$js_ver3 = filemtime( VERSI_PLUGIN_DIR . 'assets/js/history.js' );
			wp_enqueue_script( 'versi-history', VERSI_PLUGIN_URL . 'assets/js/history.js', array( 'jquery' ), $js_ver3, true );
			wp_localize_script(
				'versi-history',
				'versiHistory',
				array(
					'nonce' => wp_create_nonce( 'versi_process' ),
					'l10n'  => array(
						'clearConfirm' => __( 'Clear all processing history?', 'versi-content-tools' ),
						'downloadCsv'  => __( 'Download CSV', 'versi-content-tools' ),
					),
				)
			);

			$js_ver4 = filemtime( VERSI_PLUGIN_DIR . 'assets/js/audit.js' );
			wp_enqueue_script( 'versi-audit', VERSI_PLUGIN_URL . 'assets/js/audit.js', array( 'jquery' ), $js_ver4, true );
			wp_localize_script(
				'versi-audit',
				'versiAudit',
				array(
					'nonce'       => wp_create_nonce( 'versi_run_audit' ),
					'processNonce' => wp_create_nonce( 'versi_process' ),
					'linkNonce'   => wp_create_nonce( 'versi_link_attachment' ),
					'batchSize'   => Versi_Auditor::BATCH_SIZE,
					'l10n'        => array(
						'initializing'   => __( 'Initializing audit...', 'versi-content-tools' ),
						'noneFound'      => __( 'No unlinked images found. All media library images appear to be linked to posts.', 'versi-content-tools' ),
						'failed'         => __( 'Scan failed. Please try again.', 'versi-content-tools' ),
						'serverError'    => __( 'Server error (500). This often happens due to memory limits on very large sites.', 'versi-content-tools' ),
						'timeoutError'   => __( 'Server timeout (504/502). The scan took too long to respond.', 'versi-content-tools' ),
						'statusError'    => __( 'Request failed with status: ', 'versi-content-tools' ),
						'scanning'       => __( 'Scanning', 'versi-content-tools' ),
						'found'          => __( 'Found', 'versi-content-tools' ),
						'unlinkedImages' => __( 'unlinked image(s) across', 'versi-content-tools' ),
						'acrossPosts'    => __( 'post(s).', 'versi-content-tools' ),
						'linkSelected'   => __( 'Link Selected', 'versi-content-tools' ),
						'exportCsv'      => __( 'Export CSV', 'versi-content-tools' ),
						'allResults'     => __( 'All Results', 'versi-content-tools' ),
						'verifiedOnly'   => __( 'Verified Only', 'versi-content-tools' ),
						'image'          => __( 'Image', 'versi-content-tools' ),
						'foundIn'        => __( 'Found In', 'versi-content-tools' ),
						'action'         => __( 'Action', 'versi-content-tools' ),
						'link'           => __( 'Link', 'versi-content-tools' ),
						'linked'         => __( 'Linked', 'versi-content-tools' ),
					),
				)
			);
		}
	}

	/**
	 * Register all settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		$settings = array(
			// Shared.
			'versi_batch_size'            => array( 'type' => 'integer', 'sanitize' => array( $this, 'sanitize_batch_size' ), 'default' => 5 ),
			'versi_match_author_tone'     => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field', 'default' => '0' ),
			'versi_debug_mode'            => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field', 'default' => '0' ),
			'versi_content_limit'         => array( 'type' => 'integer', 'sanitize' => array( $this, 'sanitize_content_limit' ), 'default' => 500 ),
			// Alt-text.
			'versi_alt_processing_mode'   => array( 'type' => 'string', 'sanitize' => array( $this, 'sanitize_processing_mode' ), 'default' => 'two-pass' ),
			'versi_alt_vision_model'      => array( 'type' => 'string', 'sanitize' => array( $this, 'sanitize_model_preference' ), 'default' => '' ),
			'versi_alt_vision_fallback'   => array( 'type' => 'string', 'sanitize' => array( $this, 'sanitize_model_preference' ), 'default' => '' ),
			'versi_alt_text_model'        => array( 'type' => 'string', 'sanitize' => array( $this, 'sanitize_model_preference' ), 'default' => '' ),
			'versi_alt_text_fallback'     => array( 'type' => 'string', 'sanitize' => array( $this, 'sanitize_model_preference' ), 'default' => '' ),
			'versi_alt_image_size'        => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field', 'default' => 'large' ),
			'versi_alt_system_prompt'     => array( 'type' => 'string', 'sanitize' => 'sanitize_textarea_field', 'default' => '' ),
			'versi_alt_compare_prompt'    => array( 'type' => 'string', 'sanitize' => 'sanitize_textarea_field', 'default' => '' ),
			'versi_alt_single_prompt'     => array( 'type' => 'string', 'sanitize' => 'sanitize_textarea_field', 'default' => '' ),
			'versi_alt_auto_generate'     => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field', 'default' => '0' ),
			'versi_alt_show_generated'    => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field', 'default' => '0' ),
			'versi_alt_update_content'    => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field', 'default' => '0' ),
			'versi_strip_self_links'      => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field', 'default' => '0' ),
			'versi_alt_cat_filter'        => array( 'type' => 'integer', 'sanitize' => 'absint', 'default' => 0 ),
			// Excerpt.
			'versi_excerpt_text_model'    => array( 'type' => 'string', 'sanitize' => array( $this, 'sanitize_model_preference' ), 'default' => '' ),
			'versi_excerpt_text_fallback' => array( 'type' => 'string', 'sanitize' => array( $this, 'sanitize_model_preference' ), 'default' => '' ),
			'versi_excerpt_auto_generate' => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field', 'default' => '0' ),
			'versi_excerpt_prompt'        => array( 'type' => 'string', 'sanitize' => 'sanitize_textarea_field', 'default' => '' ),
			'versi_excerpt_length'        => array( 'type' => 'integer', 'sanitize' => array( $this, 'sanitize_excerpt_length' ), 'default' => 55 ),
			'versi_excerpt_min_length'    => array( 'type' => 'integer', 'sanitize' => 'absint', 'default' => 50 ),
			// SEO.
			'versi_seo_text_model'        => array( 'type' => 'string', 'sanitize' => array( $this, 'sanitize_model_preference' ), 'default' => '' ),
			'versi_seo_text_fallback'     => array( 'type' => 'string', 'sanitize' => array( $this, 'sanitize_model_preference' ), 'default' => '' ),
			'versi_seo_prompt'            => array( 'type' => 'string', 'sanitize' => 'sanitize_textarea_field', 'default' => '' ),
		);

		foreach ( $settings as $option => $cfg ) {
			register_setting(
				'versi_settings',
				$option,
				array(
					'type'              => $cfg['type'],
					'sanitize_callback' => $cfg['sanitize'],
					'default'           => $cfg['default'],
				)
			);
		}
	}

	/**
	 * Sanitize: clamp integer 1-50.
	 *
	 * @param mixed $value Raw.
	 * @return int
	 */
	public function sanitize_batch_size( $value ) {
		$value = absint( $value );
		if ( $value < 1 ) {
			$value = 1;
		}
		if ( $value > 50 ) {
			$value = 50;
		}
		return $value;
	}

	/**
	 * Sanitize content limit: clamp 0-5000.
	 *
	 * @param mixed $value Raw.
	 * @return int
	 */
	public function sanitize_content_limit( $value ) {
		$value = absint( $value );
		return min( max( $value, 0 ), 5000 );
	}

	/**
	 * Sanitize excerpt target length: clamp 10-200.
	 *
	 * @param mixed $value Raw.
	 * @return int
	 */
	public function sanitize_excerpt_length( $value ) {
		$value = absint( $value );
		return min( max( $value, 10 ), 200 );
	}

	/**
	 * Sanitize processing mode.
	 *
	 * @param string $value Raw.
	 * @return string
	 */
	public function sanitize_processing_mode( $value ) {
		if ( ! in_array( $value, array( 'single-pass', 'two-pass' ), true ) ) {
			return 'two-pass';
		}
		return $value;
	}

	/**
	 * Sanitize model preference: strip unsafe chars.
	 *
	 * @param string $value Raw.
	 * @return string
	 */
	public function sanitize_model_preference( $value ) {
		$value = preg_replace( '/[^a-zA-Z0-9:.\-_\/,]/', '', $value );
		return trim( $value );
	}

	/**
	 * Register the Settings > Versi Content Tools page.
	 *
	 * @return void
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Versi Content Tools', 'versi-content-tools' ),
			__( 'Versi Content Tools', 'versi-content-tools' ),
			'manage_options',
			'versi',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register the Media > Versi Content Actions page.
	 *
	 * @return void
	 */
	public function add_processing_page() {
		add_media_page(
			__( 'Versi Content Actions', 'versi-content-tools' ),
			__( 'Versi Content Actions', 'versi-content-tools' ),
			'edit_posts',
			'versi-processing',
			array( $this, 'render_processing_page' )
		);
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Versi Content Tools', 'versi-content-tools' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'versi_settings' ); ?>
				<h2 class="nav-tab-wrapper" id="versi-tabs">
					<a class="nav-tab nav-tab-active" href="#versi-tab-general"><?php esc_html_e( 'General', 'versi-content-tools' ); ?></a>
					<a class="nav-tab" href="#versi-tab-alt"><?php esc_html_e( 'Alt Text', 'versi-content-tools' ); ?></a>
					<a class="nav-tab" href="#versi-tab-excerpt"><?php esc_html_e( 'Excerpts', 'versi-content-tools' ); ?></a>
					<a class="nav-tab" href="#versi-tab-extensions"><?php esc_html_e( 'Extensions', 'versi-content-tools' ); ?></a>
					<a class="nav-tab" href="#versi-tab-about"><?php esc_html_e( 'About', 'versi-content-tools' ); ?></a>
				</h2>

				<div id="versi-tab-general" class="versi-tab">
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="versi_batch_size"><?php esc_html_e( 'Batch Size', 'versi-content-tools' ); ?></label>
							</th>
							<td>
								<input type="number" id="versi_batch_size" name="versi_batch_size" value="<?php echo esc_attr( get_option( 'versi_batch_size', 5 ) ); ?>" min="1" max="50" step="1" style="width:80px;">
								<p class="description"><?php esc_html_e( 'Number of items to process per batch. Lower values reduce server load.', 'versi-content-tools' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="versi_content_limit"><?php esc_html_e( 'Content Limit', 'versi-content-tools' ); ?></label>
							</th>
							<td>
								<input type="number" id="versi_content_limit" name="versi_content_limit" value="<?php echo esc_attr( get_option( 'versi_content_limit', 500 ) ); ?>" min="0" max="5000" step="100" style="width:120px;">
								<p class="description"><?php esc_html_e( 'Maximum characters of the parent post body content sent to the AI. Set to 0 to disable.', 'versi-content-tools' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="versi_match_author_tone"><?php esc_html_e( 'Match Author Tone', 'versi-content-tools' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" id="versi_match_author_tone" name="versi_match_author_tone" value="1" <?php checked( get_option( 'versi_match_author_tone', '0' ), '1' ); ?>>
									<?php esc_html_e( 'Include samples of the author\'s recent writing in prompts so generated content matches their tone and style.', 'versi-content-tools' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="versi_debug_mode"><?php esc_html_e( 'Debug Mode', 'versi-content-tools' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" id="versi_debug_mode" name="versi_debug_mode" value="1" <?php checked( get_option( 'versi_debug_mode', '0' ), '1' ); ?>>
									<?php esc_html_e( 'Log prompts and results to error log for troubleshooting.', 'versi-content-tools' ); ?>
								</label>
							</td>
						</tr>
					</table>
				</div>

				<div id="versi-tab-alt" class="versi-tab" style="display:none;">
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="versi_alt_auto_generate"><?php esc_html_e( 'Auto-Generate on Upload', 'versi-content-tools' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" id="versi_alt_auto_generate" name="versi_alt_auto_generate" value="1" <?php checked( get_option( 'versi_alt_auto_generate', '0' ), '1' ); ?>>
									<?php esc_html_e( 'Automatically generate alt text when a new image is uploaded.', 'versi-content-tools' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="versi_alt_show_generated"><?php esc_html_e( 'Show Generated Alt', 'versi-content-tools' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" id="versi_alt_show_generated" name="versi_alt_show_generated" value="1" <?php checked( get_option( 'versi_alt_show_generated', '0' ), '1' ); ?>>
									<?php esc_html_e( 'Display a notice on the Media Library after alt text is generated.', 'versi-content-tools' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="versi_alt_update_content"><?php esc_html_e( 'Update Content Alt', 'versi-content-tools' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" id="versi_alt_update_content" name="versi_alt_update_content" value="1" <?php checked( get_option( 'versi_alt_update_content', '0' ), '1' ); ?>>
									<?php esc_html_e( 'Dynamically update alt attributes in post/page content to match the current attachment alt text.', 'versi-content-tools' ); ?>
								</label>
								<p class="description" style="margin-top:4px;">
									<?php esc_html_e( 'When enabled, embedded images in posts and pages will display the latest alt text. This is non-destructive — nothing is saved to the database.', 'versi-content-tools' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="versi_strip_self_links"><?php esc_html_e( 'Strip Self-Linking Images', 'versi-content-tools' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" id="versi_strip_self_links" name="versi_strip_self_links" value="1" <?php checked( get_option( 'versi_strip_self_links', '0' ), '1' ); ?>>
									<?php esc_html_e( 'Remove anchor tags wrapping images when the link points to the same image file.', 'versi-content-tools' ); ?>
								</label>
								<p class="description" style="margin-top:4px;">
									<?php esc_html_e( 'Strips <a> wrappers from images in post content when the href ends in .jpg, .jpeg, .png, .gif, or .webp. The image and all its attributes are preserved. Useful for cleaning up legacy content or imported markup. Non-destructive — no database changes.', 'versi-content-tools' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="versi_alt_cat_filter"><?php esc_html_e( 'Category Filter', 'versi-content-tools' ); ?></label>
							</th>
							<td>
								<?php
								wp_dropdown_categories(
									array(
										'name'             => 'versi_alt_cat_filter',
										'id'               => 'versi_alt_cat_filter',
										'show_option_none' => __( 'All Categories', 'versi-content-tools' ),
										'option_none_value' => 0,
										'selected'         => get_option( 'versi_alt_cat_filter', 0 ),
										'hierarchical'     => true,
										'hide_empty'       => false,
									)
								);
								?>
								<p class="description"><?php esc_html_e( 'Only process images attached to posts in this category.', 'versi-content-tools' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="versi_alt_vision_model"><?php esc_html_e( 'Vision Model', 'versi-content-tools' ); ?></label>
							</th>
							<td>
								<select id="versi_alt_vision_model" name="versi_alt_vision_model" class="regular-text versi-model-select" style="max-width:400px;">
									<option value=""><?php esc_html_e( '- Default -', 'versi-content-tools' ); ?></option>
									<?php
									$saved = get_option( 'versi_alt_vision_model', '' );
									if ( '' !== $saved ) {
										echo '<option value="' . esc_attr( $saved ) . '" selected>' . esc_html( $saved ) . '</option>';
									}
									?>
								</select>
								<br>
								<select id="versi_alt_vision_fallback" name="versi_alt_vision_fallback" aria-label="<?php esc_attr_e( 'Vision model fallback', 'versi-content-tools' ); ?>" class="regular-text versi-model-select" style="max-width:400px;margin-top:4px;">
									<option value=""><?php esc_html_e( '- No Fallback -', 'versi-content-tools' ); ?></option>
									<?php
									$saved_fb = get_option( 'versi_alt_vision_fallback', '' );
									if ( '' !== $saved_fb ) {
										echo '<option value="' . esc_attr( $saved_fb ) . '" selected>' . esc_html( $saved_fb ) . '</option>';
									}
									?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="versi_alt_text_model"><?php esc_html_e( 'Text Model', 'versi-content-tools' ); ?></label>
							</th>
							<td>
								<select id="versi_alt_text_model" name="versi_alt_text_model" class="regular-text versi-model-select" style="max-width:400px;">
									<option value=""><?php esc_html_e( '- Default -', 'versi-content-tools' ); ?></option>
									<?php
									$saved = get_option( 'versi_alt_text_model', '' );
									if ( '' !== $saved ) {
										echo '<option value="' . esc_attr( $saved ) . '" selected>' . esc_html( $saved ) . '</option>';
									}
									?>
								</select>
								<br>
								<select id="versi_alt_text_fallback" name="versi_alt_text_fallback" aria-label="<?php esc_attr_e( 'Text model fallback', 'versi-content-tools' ); ?>" class="regular-text versi-model-select" style="max-width:400px;margin-top:4px;">
									<option value=""><?php esc_html_e( '- No Fallback -', 'versi-content-tools' ); ?></option>
									<?php
									$saved_fb = get_option( 'versi_alt_text_fallback', '' );
									if ( '' !== $saved_fb ) {
										echo '<option value="' . esc_attr( $saved_fb ) . '" selected>' . esc_html( $saved_fb ) . '</option>';
									}
									?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<?php esc_html_e( 'Processing Mode', 'versi-content-tools' ); ?>
							</th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><?php esc_html_e( 'Processing Mode', 'versi-content-tools' ); ?></legend>
									<label style="display:block;margin-bottom:6px;">
										<input type="radio" name="versi_alt_processing_mode" value="single-pass" <?php checked( get_option( 'versi_alt_processing_mode', 'two-pass' ), 'single-pass' ); ?>>
										<strong><?php esc_html_e( 'Single-Pass', 'versi-content-tools' ); ?></strong>
										&mdash; <?php esc_html_e( 'One AI call with image + full instructions. Requires a high-end model.', 'versi-content-tools' ); ?>
									</label>
									<label>
										<input type="radio" name="versi_alt_processing_mode" value="two-pass" <?php checked( get_option( 'versi_alt_processing_mode', 'two-pass' ), 'two-pass' ); ?>>
										<strong><?php esc_html_e( 'Two-Pass', 'versi-content-tools' ); ?></strong>
										&mdash; <?php esc_html_e( 'Vision Agent observes, then Synthesizer formats. Designed for smaller models.', 'versi-content-tools' ); ?>
									</label>
								</fieldset>
							</td>
						</tr>
						<tr data-mode="single-pass">
							<th scope="row">
								<label for="versi_alt_image_size"><?php esc_html_e( 'Image Size for AI', 'versi-content-tools' ); ?></label>
							</th>
							<td>
								<select id="versi_alt_image_size" name="versi_alt_image_size" aria-label="<?php esc_attr_e( 'Image Size for AI', 'versi-content-tools' ); ?>" class="regular-text">
									<?php
									$sizes    = array(
										'full'         => __( 'Full Size (Original)', 'versi-content-tools' ),
										'large'        => __( 'Large', 'versi-content-tools' ),
										'medium_large' => __( 'Medium Large', 'versi-content-tools' ),
										'medium'       => __( 'Medium', 'versi-content-tools' ),
									);
									$selected = get_option( 'versi_alt_image_size', 'large' );
									foreach ( $sizes as $value => $label ) {
										echo '<option value="' . esc_attr( $value ) . '" ' . selected( $selected, $value, false ) . '>' . esc_html( $label ) . '</option>';
									}
									?>
								</select>
								<p class="description"><?php esc_html_e( 'Choose the image size sent to the AI. Smaller sizes reduce token usage.', 'versi-content-tools' ); ?></p>
							</td>
						</tr>
						<tr data-mode="single-pass">
							<th scope="row">
								<label for="versi_alt_single_prompt"><?php esc_html_e( 'Single-Pass Prompt', 'versi-content-tools' ); ?></label>
							</th>
							<td>
								<textarea id="versi_alt_single_prompt" name="versi_alt_single_prompt" rows="12" class="large-text code"><?php echo esc_textarea( get_option( 'versi_alt_single_prompt', '' ) ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Combined system instruction for Single-Pass mode. Use the variables below.', 'versi-content-tools' ); ?></p>
								<details style="margin-top:8px;">
									<summary><?php esc_html_e( 'Available variables', 'versi-content-tools' ); ?></summary>
									<pre style="background:#f0f0f1;padding:12px;font-size:12px;max-height:240px;overflow:auto;margin:8px 0 0;color:#666;">
{caption}         - Image caption (post_excerpt)
{title}           - Image title (post_title)
{article_title}   - Parent post title
{article_content} - Parent post body content (first <?php echo absint( get_option( 'versi_content_limit', 500 ) ); ?> chars; also available as {article_excerpt})
{existing_alt}    - Current alt text in database
{filename_label}     - Label extracted from the image filename (e.g., a name, event, or subject)
{author_style}    - Author's recent writing samples (requires "Match Author Tone" setting)
{focus_keywords}  - SEO focus keyphrases (requires Extensions integration)
{product_context} - WooCommerce product SKU, price, description (requires Extensions integration)
									</pre>
								</details>
								<details style="margin-top:8px;">
									<summary><?php esc_html_e( 'Default prompt (click to expand)', 'versi-content-tools' ); ?></summary>
									<pre style="background:#f0f0f1;padding:12px;font-size:12px;max-height:240px;overflow:auto;margin:8px 0 0;"><?php echo esc_textarea( Versi_Alt_Text_Processor::init()->default_single_prompt() ); ?></pre>
								</details>
							</td>
						</tr>
						<tr data-mode="two-pass">
							<th scope="row">
								<label for="versi_alt_system_prompt"><?php esc_html_e( 'Vision Prompt (Two-Pass)', 'versi-content-tools' ); ?></label>
							</th>
							<td>
								<textarea id="versi_alt_system_prompt" name="versi_alt_system_prompt" rows="12" class="large-text code"><?php echo esc_textarea( get_option( 'versi_alt_system_prompt', '' ) ); ?></textarea>
								<p class="description"><?php esc_html_e( 'System instruction for the vision model in Two-Pass mode.', 'versi-content-tools' ); ?></p>
								<details style="margin-top:8px;">
									<summary><?php esc_html_e( 'Available variables', 'versi-content-tools' ); ?></summary>
									<pre style="background:#f0f0f1;padding:12px;font-size:12px;max-height:240px;overflow:auto;margin:8px 0 0;color:#666;">
{caption}         - Image caption (post_excerpt)
{title}           - Image title (post_title)
{article_title}   - Parent post title
{article_content} - Parent post body content (first <?php echo absint( get_option( 'versi_content_limit', 500 ) ); ?> chars; also available as {article_excerpt})
{existing_alt}    - Current alt text in database
{visual_desc}     - Raw output from Vision model
{filename_label}     - Label extracted from the image filename (e.g., a name, event, or subject)
{author_style}    - Author's recent writing samples (requires "Match Author Tone" setting)
{focus_keywords}  - SEO focus keyphrases (requires Extensions integration)
{product_context} - WooCommerce product SKU, price, description (requires Extensions integration)

Usage: Include these placeholders in your prompt text.
Example: "The image is about {article_title}. Visual: {visual_desc}"
									</pre>
								</details>
								<details style="margin-top:8px;">
									<summary><?php esc_html_e( 'Default prompt (click to expand)', 'versi-content-tools' ); ?></summary>
									<pre style="background:#f0f0f1;padding:12px;font-size:12px;max-height:240px;overflow:auto;margin:8px 0 0;"><?php echo esc_textarea( Versi_Alt_Text_Processor::init()->default_system_prompt() ); ?></pre>
								</details>
							</td>
						</tr>
						<tr data-mode="two-pass">
							<th scope="row">
								<label for="versi_alt_compare_prompt"><?php esc_html_e( 'Synthesizer Prompt (Two-Pass)', 'versi-content-tools' ); ?></label>
							</th>
							<td>
								<textarea id="versi_alt_compare_prompt" name="versi_alt_compare_prompt" rows="8" class="large-text code"><?php echo esc_textarea( get_option( 'versi_alt_compare_prompt', '' ) ); ?></textarea>
								<p class="description"><?php esc_html_e( 'System instruction for the text-only Synthesizer step.', 'versi-content-tools' ); ?></p>
								<details style="margin-top:8px;">
									<summary><?php esc_html_e( 'Available variables', 'versi-content-tools' ); ?></summary>
									<pre style="background:#f0f0f1;padding:12px;font-size:12px;max-height:240px;overflow:auto;margin:8px 0 0;color:#666;">
{caption}         - Image caption (post_excerpt)
{title}           - Image title (post_title)
{article_title}   - Parent post title
{article_content} - Parent post body content (first <?php echo absint( get_option( 'versi_content_limit', 500 ) ); ?> chars; also available as {article_excerpt})
{existing_alt}    - Current alt text in database
{visual_desc}     - Raw output from Vision model
{filename_label}     - Label extracted from the image filename (e.g., a name, event, or subject)
{author_style}    - Author's recent writing samples (requires "Match Author Tone" setting)
{focus_keywords}  - SEO focus keyphrases (requires Extensions integration)
{product_context} - WooCommerce product SKU, price, description (requires Extensions integration)
									</pre>
								</details>
								<details style="margin-top:8px;">
									<summary><?php esc_html_e( 'Default prompt (click to expand)', 'versi-content-tools' ); ?></summary>
									<pre style="background:#f0f0f1;padding:12px;font-size:12px;max-height:240px;overflow:auto;margin:8px 0 0;"><?php echo esc_textarea( Versi_Alt_Text_Processor::init()->default_compare_prompt() ); ?></pre>
								</details>
							</td>
						</tr>
					</table>
				</div>

				<div id="versi-tab-excerpt" class="versi-tab" style="display:none;">
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="versi_excerpt_auto_generate"><?php esc_html_e( 'Auto-Generate on Save', 'versi-content-tools' ); ?></label>
							</th>
							<td>
								<label>
									<input type="checkbox" id="versi_excerpt_auto_generate" name="versi_excerpt_auto_generate" value="1" <?php checked( get_option( 'versi_excerpt_auto_generate', '0' ), '1' ); ?>>
									<?php esc_html_e( 'Automatically generate an excerpt when a post is saved (only if excerpt is empty).', 'versi-content-tools' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="versi_excerpt_length"><?php esc_html_e( 'Target Length', 'versi-content-tools' ); ?></label>
							</th>
							<td>
								<input type="number" id="versi_excerpt_length" name="versi_excerpt_length" value="<?php echo esc_attr( get_option( 'versi_excerpt_length', 55 ) ); ?>" min="10" max="200" step="5" style="width:80px;">
								<p class="description"><?php esc_html_e( 'Target word count for generated excerpts.', 'versi-content-tools' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="versi_excerpt_min_length"><?php esc_html_e( 'Min Excerpt Length', 'versi-content-tools' ); ?></label>
							</th>
							<td>
								<input type="number" id="versi_excerpt_min_length" name="versi_excerpt_min_length" value="<?php echo esc_attr( get_option( 'versi_excerpt_min_length', 50 ) ); ?>" min="10" max="500" step="5" style="width:80px;">
								<p class="description"><?php esc_html_e( 'Minimum character count for excerpts. Excerpts below this length are considered "short" and will be targeted by the Fix Short Excerpts mode.', 'versi-content-tools' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="versi_excerpt_text_model"><?php esc_html_e( 'Text Model', 'versi-content-tools' ); ?></label>
							</th>
							<td>
								<select id="versi_excerpt_text_model" name="versi_excerpt_text_model" class="regular-text versi-model-select" style="max-width:400px;">
									<option value=""><?php esc_html_e( '- Default -', 'versi-content-tools' ); ?></option>
									<?php
									$saved = get_option( 'versi_excerpt_text_model', '' );
									if ( '' !== $saved ) {
										echo '<option value="' . esc_attr( $saved ) . '" selected>' . esc_html( $saved ) . '</option>';
									}
									?>
								</select>
								<br>
								<select id="versi_excerpt_text_fallback" name="versi_excerpt_text_fallback" aria-label="<?php esc_attr_e( 'Excerpt model fallback', 'versi-content-tools' ); ?>" class="regular-text versi-model-select" style="max-width:400px;margin-top:4px;">
									<option value=""><?php esc_html_e( '- No Fallback -', 'versi-content-tools' ); ?></option>
									<?php
									$saved_fb = get_option( 'versi_excerpt_text_fallback', '' );
									if ( '' !== $saved_fb ) {
										echo '<option value="' . esc_attr( $saved_fb ) . '" selected>' . esc_html( $saved_fb ) . '</option>';
									}
									?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="versi_excerpt_prompt"><?php esc_html_e( 'Custom Prompt', 'versi-content-tools' ); ?></label>
							</th>
							<td>
								<textarea id="versi_excerpt_prompt" name="versi_excerpt_prompt" rows="8" class="large-text code"><?php echo esc_textarea( get_option( 'versi_excerpt_prompt', '' ) ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Custom system instruction for excerpt generation. Leave empty for the built-in default.', 'versi-content-tools' ); ?></p>
								<details style="margin-top:8px;">
									<summary><?php esc_html_e( 'Available variables', 'versi-content-tools' ); ?></summary>
									<pre style="background:#f0f0f1;padding:12px;font-size:12px;max-height:240px;overflow:auto;margin:8px 0 0;color:#666;">
{post_content}    - Full post body content (truncated per Content Limit)
{existing_excerpt}- Current excerpt in the database (empty if none)
{target_length}   - Target word count from the setting above
{author_style}    - Author's recent writing samples (requires "Match Author Tone" setting)
{focus_keywords}  - SEO focus keyphrases (requires Extensions integration)
{product_context} - WooCommerce product SKU, price, description (requires Extensions integration)
									</pre>
								</details>
								<details style="margin-top:8px;">
									<summary><?php esc_html_e( 'Default prompt (click to expand)', 'versi-content-tools' ); ?></summary>
									<pre style="background:#f0f0f1;padding:12px;font-size:12px;max-height:240px;overflow:auto;margin:8px 0 0;"><?php echo esc_textarea( Versi_Excerpt_Processor::init()->default_prompt() ); ?></pre>
								</details>
							</td>
						</tr>
					</table>
				</div>

				<div id="versi-tab-extensions" class="versi-tab" style="display:none;">
					<?php Versi_Extensions::init()->render_tab(); ?>
				</div>

				<?php submit_button(); ?>
			</form>

			<div id="versi-tab-about" class="versi-tab" style="display:none;margin-top:20px;">
				<div class="postbox" style="max-width:640px;padding:24px;">
					<h2><?php esc_html_e( 'Versi Content Tools', 'versi-content-tools' ); ?> <span class="version">v<?php echo esc_html( VERSI_VERSION ); ?></span></h2>
					<p><?php esc_html_e( 'Generate image alt text and post excerpts using WordPress AI Client (WP 7.0+).', 'versi-content-tools' ); ?></p>
					<hr>
					<p>
						<strong><?php esc_html_e( 'Author:', 'versi-content-tools' ); ?></strong>
						<a href="https://profiles.wordpress.org/masterset2005/" target="_blank" rel="noopener noreferrer">masterset2005</a>
					</p>
					<p>
						<a href="https://github.com/masterset2005/versi-content-tools" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View on GitHub', 'versi-content-tools' ); ?></a>
						&middot;
						<a href="https://wordpress.org/support/plugin/versi-content-tools/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Support', 'versi-content-tools' ); ?></a>
					</p>
					<hr>
					<p>
						<a href="https://www.paypal.com/donate/?hosted_button_id=YOUR_BUTTON_ID" target="_blank" rel="noopener noreferrer" class="button button-secondary">
							<?php esc_html_e( 'Donate via PayPal', 'versi-content-tools' ); ?>
						</a>
					</p>
					<hr>
					<p style="font-size:0.85em;color:#646970;">
						<?php esc_html_e( 'This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.', 'versi-content-tools' ); ?>
					</p>
					<p style="font-size:0.85em;color:#646970;">
						<?php esc_html_e( 'This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.', 'versi-content-tools' ); ?>
					</p>
				</div>
			</div>
		</div>

		<?php // Styles and scripts are enqueued via enqueue_scripts(). ?>
		<?php
	}

	/**
	 * Render the processing page with workload tabs and live/background modes.
	 *
	 * @return void
	 */
	public function render_processing_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$workload = isset( $_GET['versi_workload'] ) ? sanitize_key( wp_unslash( $_GET['versi_workload'] ) ) : 'dashboard';
		$mode_tab = isset( $_GET['versi_mode_tab'] ) ? sanitize_key( wp_unslash( $_GET['versi_mode_tab'] ) ) : 'live';

		$alt_stats        = Versi_Alt_Text_Processor::init()->get_stats();
		$exc_stats        = Versi_Excerpt_Processor::init()->get_stats();
		$is_proc_workload = in_array( $workload, array( 'alt', 'excerpt', 'seo', 'content' ), true );
		?>
		$exc_stats = Versi_Excerpt_Processor::init()->get_stats();

		$base_url    = admin_url( 'upload.php?page=versi-processing' );
		$dash_url    = add_query_arg( 'versi_workload', 'dashboard', $base_url );
		$alt_url     = add_query_arg( 'versi_workload', 'alt', $base_url );
		$exc_url     = add_query_arg( 'versi_workload', 'excerpt', $base_url );
		$seo_url     = add_query_arg( 'versi_workload', 'seo', $base_url );
		$content_url = add_query_arg( 'versi_workload', 'content', $base_url );
		$auditor_url = add_query_arg( 'versi_workload', 'auditor', $base_url );
		$live_url    = add_query_arg( 'versi_mode_tab', 'live', $base_url );
		$bg_url      = add_query_arg( 'versi_mode_tab', 'bg', $base_url );
		$refresh_url = 'alt' === $workload ? $alt_url : ( 'seo' === $workload ? $seo_url : ( 'content' === $workload ? $content_url : ( 'excerpt' === $workload ? $exc_url : $dash_url ) ) );

		$job = get_option( 'versi_job_status' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Versi Content Actions', 'versi-content-tools' ); ?></h1>

			<?php if ( $job && ! empty( $job['is_running'] ) ) : ?>
				<div class="notice notice-info" style="margin: 20px 0;">
					<p><strong><?php esc_html_e( 'Background job running', 'versi-content-tools' ); ?></strong></p>
					<p>
						<?php esc_html_e( 'Progress:', 'versi-content-tools' ); ?>
						<span id="versi-bg-progress"><?php echo esc_html( $job['processed'] ); ?> / <?php echo esc_html( $job['total'] ); ?></span>
						&mdash;
						<?php echo esc_html( $job['workload'] ); ?> / <?php echo esc_html( $job['mode'] ); ?>
					</p>
					<p id="versi-bg-stall-warn" style="color:#b32d2e;display:none;margin:8px 0 0;">
						<?php esc_html_e( 'This job appears stalled. WP-Cron events may not be firing. If you have set DISABLE_WP_CRON, make sure your system cron is calling wp-cron.php regularly. Otherwise, remove that constant from wp-config.php.', 'versi-content-tools' ); ?>
					</p>
					<button id="versi-bg-cancel" class="button"><?php esc_html_e( 'Cancel Job', 'versi-content-tools' ); ?></button>
				</div>
			<?php endif; ?>

			<!-- Workload tabs -->
			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $dash_url ); ?>" class="nav-tab <?php echo 'dashboard' === $workload ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Dashboard', 'versi-content-tools' ); ?>
				</a>
				<a href="<?php echo esc_url( $alt_url ); ?>" class="nav-tab <?php echo 'alt' === $workload ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Alt Text', 'versi-content-tools' ); ?>
				</a>
				<a href="<?php echo esc_url( $exc_url ); ?>" class="nav-tab <?php echo 'excerpt' === $workload ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Excerpts', 'versi-content-tools' ); ?>
				</a>
				<a href="<?php echo esc_url( $seo_url ); ?>" class="nav-tab <?php echo 'seo' === $workload ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'SEO Keywords', 'versi-content-tools' ); ?>
				</a>
				<a href="<?php echo esc_url( $content_url ); ?>" class="nav-tab <?php echo 'content' === $workload ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Content Cleanup', 'versi-content-tools' ); ?>
				</a>
				<a href="<?php echo esc_url( $auditor_url ); ?>" class="nav-tab <?php echo 'auditor' === $workload ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Media Auditor', 'versi-content-tools' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'versi_workload', 'history', $base_url ) ); ?>" class="nav-tab <?php echo 'history' === $workload ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'History', 'versi-content-tools' ); ?>
				</a>
			</h2>

			<?php if ( $is_proc_workload ) : ?>
			<!-- Stats bar -->
			<div class="versi-stats" style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px;">
				<?php if ( 'alt' === $workload ) : ?>
					<div class="versi-stat-card">
						<div class="versi-stat-icon" style="background:#eff6ff;color:#2563eb;">
							<svg aria-hidden="true" focusable="false" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
						</div>
						<div>
							<div class="versi-stat-number"><?php echo esc_html( $alt_stats['total'] ); ?></div>
							<div class="versi-stat-label"><?php esc_html_e( 'total images', 'versi-content-tools' ); ?></div>
						</div>
					</div>
					<div class="versi-stat-card">
						<div class="versi-stat-icon" style="background:#fef2f2;color:#dc2626;">
							<svg aria-hidden="true" focusable="false" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4"/></svg>
						</div>
						<div>
							<div class="versi-stat-number"><?php echo esc_html( $alt_stats['missing'] ); ?></div>
							<div class="versi-stat-label"><?php esc_html_e( 'missing alt text', 'versi-content-tools' ); ?></div>
						</div>
					</div>
					<div class="versi-stat-card">
						<div class="versi-stat-icon" style="background:#fffbeb;color:#d97706;">
							<svg aria-hidden="true" focusable="false" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01"/></svg>
						</div>
						<div>
							<div class="versi-stat-number"><?php echo esc_html( $alt_stats['too_long'] ); ?></div>
							<div class="versi-stat-label"><?php esc_html_e( 'over 125 chars', 'versi-content-tools' ); ?></div>
						</div>
					</div>
					<div class="versi-stat-card">
						<div class="versi-stat-icon" style="background:#fffbeb;color:#d97706;">
							<svg aria-hidden="true" focusable="false" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01"/></svg>
						</div>
						<div>
							<div class="versi-stat-number"><?php echo esc_html( $alt_stats['too_short'] ); ?></div>
							<div class="versi-stat-label"><?php esc_html_e( 'under 15 chars', 'versi-content-tools' ); ?></div>
						</div>
					</div>
			<?php elseif ( 'seo' === $workload ) : ?>
				<div class="versi-stat-card">
					<div class="versi-stat-icon" style="background:#eff6ff;color:#2563eb;">
						<svg aria-hidden="true" focusable="false" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
					</div>
					<div>
						<div class="versi-stat-number"><?php esc_html_e( 'SEO', 'versi-content-tools' ); ?></div>
						<div class="versi-stat-label"><?php esc_html_e( 'bulk keyword generation', 'versi-content-tools' ); ?></div>
					</div>
				</div>
			<?php elseif ( 'content' === $workload ) : ?>
				<div class="versi-stat-card">
					<div class="versi-stat-icon" style="background:#eff6ff;color:#2563eb;">
						<svg aria-hidden="true" focusable="false" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
					</div>
					<div>
						<div class="versi-stat-number"><?php esc_html_e( 'Database', 'versi-content-tools' ); ?></div>
						<div class="versi-stat-label"><?php esc_html_e( 'bulk post content edit', 'versi-content-tools' ); ?></div>
					</div>
				</div>
				<div class="versi-stat-card" style="border-color:#fca5a5;background:#fef2f2;">
					<div class="versi-stat-icon" style="background:#fef2f2;color:#dc2626;">
						<svg aria-hidden="true" focusable="false" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
					</div>
					<div>
						<div class="versi-stat-number" style="color:#dc2626;"><?php esc_html_e( 'WARNING', 'versi-content-tools' ); ?></div>
						<div class="versi-stat-label"><?php esc_html_e( 'permanently modifies posts', 'versi-content-tools' ); ?></div>
					</div>
				</div>
				<?php else : ?>
					<div class="versi-stat-card">
						<div class="versi-stat-icon" style="background:#eff6ff;color:#2563eb;">
							<svg aria-hidden="true" focusable="false" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
						</div>
						<div>
							<div class="versi-stat-number"><?php echo esc_html( $exc_stats['total'] ); ?></div>
							<div class="versi-stat-label"><?php esc_html_e( 'total posts', 'versi-content-tools' ); ?></div>
						</div>
					</div>
					<div class="versi-stat-card">
						<div class="versi-stat-icon" style="background:#fef2f2;color:#dc2626;">
							<svg aria-hidden="true" focusable="false" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4"/></svg>
						</div>
						<div>
							<div class="versi-stat-number"><?php echo esc_html( $exc_stats['missing'] ); ?></div>
							<div class="versi-stat-label"><?php esc_html_e( 'missing excerpts', 'versi-content-tools' ); ?></div>
						</div>
					</div>
					<div class="versi-stat-card">
						<div class="versi-stat-icon" style="background:#f0fdf4;color:#16a34a;">
							<svg aria-hidden="true" focusable="false" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
						</div>
						<div>
							<div class="versi-stat-number"><?php echo esc_html( $exc_stats['has_excerpt'] ); ?></div>
							<div class="versi-stat-label"><?php esc_html_e( 'have excerpts', 'versi-content-tools' ); ?></div>
						</div>
					</div>
					<div class="versi-stat-card">
						<div class="versi-stat-icon" style="background:#fffbeb;color:#d97706;">
							<svg aria-hidden="true" focusable="false" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01"/></svg>
						</div>
						<div>
							<div class="versi-stat-number"><?php echo esc_html( $exc_stats['short'] ); ?></div>
							<div class="versi-stat-label"><?php esc_html_e( 'short excerpts', 'versi-content-tools' ); ?></div>
						</div>
					</div>
				<?php endif; ?>
				<a href="<?php echo esc_url( $refresh_url ); ?>" class="button" style="margin-left:auto;align-self:center;">
					<?php esc_html_e( 'Refresh', 'versi-content-tools' ); ?>
				</a>
			</div>

			<!-- Mode sub-tabs: Live Process / Background Jobs -->
			<h3 class="nav-tab-wrapper" style="margin-bottom:16px;">
				<a href="<?php echo esc_url( add_query_arg( 'versi_mode_tab', 'live', $refresh_url ) ); ?>" class="nav-tab <?php echo 'live' === $mode_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Live Process', 'versi-content-tools' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'versi_mode_tab', 'bg', $refresh_url ) ); ?>" class="nav-tab <?php echo 'bg' === $mode_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Background Jobs', 'versi-content-tools' ); ?>
				</a>
			</h3>

				<?php if ( 'bg' === $mode_tab ) : ?>
					<?php $this->render_background_tab( $workload ); ?>
			<?php else : ?>
				<?php $this->render_live_tab( $workload ); ?>
			<?php endif; ?>
			<?php elseif ( 'dashboard' === $workload ) : ?>
				<?php $this->render_dashboard_tab(); ?>
			<?php elseif ( 'auditor' === $workload ) : ?>
				<?php $this->render_auditor_tab(); ?>
			<?php elseif ( 'history' === $workload ) : ?>
				<?php $this->render_history_tab(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

		// Helper to format error message with potential link.
	public function format_error( $error ) {
		if ( preg_match( '/https?:\/\/[^\s]+/', $error, $matches ) ) {
			$url = esc_url( $matches[0] );
			return str_replace( $url, '<a href="' . $url . '" target="_blank">' . $url . '</a>', esc_html( $error ) );
		}
		return esc_html( $error );
	}

		/**
		 * Render results entry (with retry button).
		 */
	private function render_result_entry( $result, $workload ) {
		$status_color = 'success' === $result['status'] ? '#16a34a' : ( 'error' === $result['status'] ? '#dc2626' : '#6b7280' );
		?>
			<div class="versi-entry" style="border-left:4px solid <?php echo esc_attr( $status_color ); ?>;">
				<span>#<?php echo esc_html( $result['id'] ); ?></span>
				<strong><?php echo esc_html( $result['title'] ); ?></strong>
				<span style="color:<?php echo esc_attr( $status_color ); ?>;"><?php echo esc_html( $result['status'] ); ?></span>
			<?php if ( ! empty( $result['error'] ) ) : ?>
					<span style="color:#dc2626;"><?php echo wp_kses_post( $this->format_error( $result['error'] ) ); ?></span>
					<button class="button button-small versi-retry-btn" data-id="<?php echo esc_attr( $result['id'] ); ?>" data-workload="<?php echo esc_attr( $workload ); ?>">
						<?php esc_html_e( 'Retry', 'versi-content-tools' ); ?>
					</button>
				<?php endif; ?>
			</div>
			<?php
	}
		/**
		 * @param string $workload 'alt' or 'excerpt' .
		 * @return void
		 */
	private function render_live_tab( $workload ) {
		$base_url = admin_url( 'upload.php?page=versi-processing&versi_workload=' . $workload . '&versi_mode_tab=live' );

		if ( 'alt' === $workload ) {
			$safe_label   = __( 'Generate Missing Alt Text', 'versi-content-tools' );
			$safe_mode    = 'missing';
			$review_label = __( 'Bulk Review Alt Text', 'versi-content-tools' );
			$review_mode  = 'bulk_review';
			$dest_label   = __( 'Regenerate All Alt Text', 'versi-content-tools' );
			$dest_mode    = 'regenerate';
		} elseif ( 'content' === $workload ) {
			$safe_label  = __( 'Update Alt Attributes in Content', 'versi-content-tools' );
			$safe_mode   = 'update_alt';
			$short_label = __( 'Strip Self-Linking Image Wrappers', 'versi-content-tools' );
			$short_mode  = 'strip_links';
			$dest_label  = __( 'Apply Both (Alt + Strip Links)', 'versi-content-tools' );
			$dest_mode   = 'both';
		} elseif ( 'excerpt' === $workload ) {
			$safe_label   = __( 'Generate Missing Excerpts', 'versi-content-tools' );
			$safe_mode    = 'missing';
			$short_label  = __( 'Fix Short Excerpts', 'versi-content-tools' );
			$short_mode   = 'short';
			$review_label = __( 'Bulk Review Excerpts', 'versi-content-tools' );
			$review_mode  = 'bulk_review';
			$dest_label   = __( 'Improve All Excerpts', 'versi-content-tools' );
			$dest_mode    = 'improve';
		} else {
			$safe_label = __( 'Generate Missing Keywords', 'versi-content-tools' );
			$safe_mode  = 'missing';
			$dest_label = __( 'Regenerate All Keywords', 'versi-content-tools' );
			$dest_mode  = 'regenerate';
		}
		?>
		<div id="versi-live-tab">
			<div class="versi-mode-selector" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px;align-items:center;">
				<button type="button" class="versi-mode-card versi-mode-primary versi-start-btn" data-workload="<?php echo esc_attr( $workload ); ?>" data-mode="<?php echo esc_attr( $safe_mode ); ?>">
					<svg aria-hidden="true" focusable="false" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
					<?php echo esc_html( $safe_label ); ?>
				</button>
				<button type="button" class="versi-mode-card versi-mode-primary" id="versi-pause-btn" style="display:none;" data-status="running">
					<svg aria-hidden="true" focusable="false" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 9v6m4-6v6"/></svg>
					<?php esc_html_e( 'Pause', 'versi-content-tools' ); ?>
				</button>
				<?php if ( isset( $review_label ) ) : ?>
					<button type="button" class="versi-mode-card versi-start-btn" data-workload="<?php echo esc_attr( $workload ); ?>" data-mode="<?php echo esc_attr( $review_mode ); ?>">
						<svg aria-hidden="true" focusable="false" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
						<?php echo esc_html( $review_label ); ?>
					</button>
				<?php endif; ?>
				<?php if ( 'excerpt' === $workload ) : ?>
					<button type="button" class="versi-mode-card versi-start-btn" data-workload="<?php echo esc_attr( $workload ); ?>" data-mode="<?php echo esc_attr( $short_mode ); ?>">
						<svg aria-hidden="true" focusable="false" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
						<?php echo esc_html( $short_label ); ?>
					</button>
				<?php elseif ( 'content' === $workload ) : ?>
					<button type="button" class="versi-mode-card versi-start-btn" data-workload="<?php echo esc_attr( $workload ); ?>" data-mode="<?php echo esc_attr( $short_mode ); ?>">
						<svg aria-hidden="true" focusable="false" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
						<?php echo esc_html( $short_label ); ?>
					</button>
				<?php endif; ?>
				<button type="button" class="versi-mode-card versi-mode-danger versi-start-btn" data-workload="<?php echo esc_attr( $workload ); ?>" data-mode="<?php echo esc_attr( $dest_mode ); ?>" data-destructive="1">
					<svg aria-hidden="true" focusable="false" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
					<?php echo esc_html( $dest_label ); ?>
				</button>
				<span class="versi-or-text" style="color:#4b5563;font-style:italic;font-size:13px;"><?php esc_html_e( 'Choose a mode above to begin.', 'versi-content-tools' ); ?></span>
			</div>

			<!-- Overwrite warning (shown via JS when destructive mode is selected) -->
			<div class="notice notice-warning versi-overwrite-warning" style="display:none;border-radius:10px;">
				<p>
					<strong><?php esc_html_e( 'Warning:', 'versi-content-tools' ); ?></strong>
					<?php esc_html_e( 'This will overwrite existing content for all items. You can undo individual items after processing using the per-item undo button. Consider running "Generate Missing" first.', 'versi-content-tools' ); ?>
				</p>
			</div>

			<!-- Resume notice (shown if a paused job exists) -->
				<div id="versi-resume-notice" class="versi-job-notice" style="display:none;">
					<div class="versi-job-header">
						<p style="margin:0;font-size:14px;font-weight:600;color:#1e40af;">
							<svg aria-hidden="true" focusable="false" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:text-bottom;margin-right:6px;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
							<?php esc_html_e( 'Resume previous session?', 'versi-content-tools' ); ?>
						</p>
						<span id="versi-resume-text" style="font-size:13px;color:#4b5563;display:block;margin:4px 0 0;"></span>
					</div>
					<div class="versi-job-body" style="display:flex;gap:8px;">
						<button type="button" id="versi-resume-btn" class="button button-primary"><?php esc_html_e( 'Resume', 'versi-content-tools' ); ?></button>
						<button type="button" id="versi-dismiss-btn" class="button"><?php esc_html_e( 'Start Fresh', 'versi-content-tools' ); ?></button>
					</div>
				</div>

			<!-- Processing area (hidden until start is clicked) -->
			<div id="versi-processing-area" style="display:none;margin-top:20px;">
				<div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
					<div style="width:8px;height:8px;border-radius:50%;background:#22c55e;animation:pulse 1.5s infinite;flex-shrink:0;"></div>
					<h2 style="margin:0;padding:0;font-size:1.2rem;font-weight:600;color:#1e1e1e;" tabindex="-1">
						<?php esc_html_e( 'Processing', 'versi-content-tools' ); ?>&hellip;
					</h2>
					<a href="#" id="versi-stop-link" class="versi-stop-link" style="color:#dc2626;text-decoration:none;font-size:12px;font-weight:500;margin-left:auto;padding:4px 10px;border-radius:6px;border:1px solid #fca5a5;">
						<?php esc_html_e( 'Stop', 'versi-content-tools' ); ?>
					</a>
				</div>
				<div id="versi-status" style="margin:0 0 10px 0;font-size:13px;color:#4b5563;"></div>
				<div id="versi-results" aria-live="polite" role="status" class="versi-results-box"></div>
			</div>
		</div>

		<?php
	}

	/**
	 * Render the Background Jobs tab.
	 *
	 * @param string $workload 'alt' or 'excerpt'.
	 * @return void
	 */
	/**
	 * Render the Processing History tab.
	 *
	 * @return void
	 */
	private function render_history_tab() {
		$history = get_option( 'versi_processing_history', array() );
		?>
		<div class="versi-history-card">
			<div class="versi-history-header">
				<svg aria-hidden="true" focusable="false" width="20" height="20" fill="none" stroke="#6366f1" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
				<div>
					<h3 style="margin:0;font-size:15px;font-weight:600;color:#1e1e1e;"><?php esc_html_e( 'Processing History', 'versi-content-tools' ); ?></h3>
					<p style="margin:2px 0 0;font-size:13px;color:#4b5563;"><?php esc_html_e( 'Recent processing runs and exported results.', 'versi-content-tools' ); ?></p>
				</div>
				<?php if ( ! empty( $history ) ) : ?>
					<button type="button" id="versi-clear-history" class="button" style="margin-left:auto;"><?php esc_html_e( 'Clear History', 'versi-content-tools' ); ?></button>
				<?php endif; ?>
			</div>
			<div class="versi-history-body">
				<?php if ( empty( $history ) ) : ?>
					<div class="versi-history-empty">
						<svg aria-hidden="true" focusable="false" width="40" height="40" fill="none" stroke="#d1d5db" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
						<p style="margin:12px 0 0;"><?php esc_html_e( 'No processing history yet. Results are saved automatically when a processing run completes.', 'versi-content-tools' ); ?></p>
					</div>
				<?php else : ?>
					<table class="versi-history-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date', 'versi-content-tools' ); ?></th>
								<th><?php esc_html_e( 'Workload', 'versi-content-tools' ); ?></th>
								<th><?php esc_html_e( 'Mode', 'versi-content-tools' ); ?></th>
								<th><?php esc_html_e( 'Results', 'versi-content-tools' ); ?></th>
								<th><?php esc_html_e( 'Export', 'versi-content-tools' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( array_reverse( $history ) as $run ) : ?>
								<tr>
									<td style="white-space:nowrap;">
										<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $run['timestamp'] ) ); ?>
									</td>
									<td>
										<span class="versi-history-badge <?php echo esc_attr( $run['workload'] ); ?>">
											<?php echo esc_html( ucfirst( $run['workload'] ) ); ?>
										</span>
									</td>
									<td style="color:#6b7280;font-size:12px;">
										<?php echo esc_html( str_replace( '_', ' ', $run['mode'] ) ); ?>
									</td>
									<td>
										<?php
										$summary = $run['summary'];
										$parts = array();
										if ( ! empty( $summary['ok'] ) ) {
											$parts[] = '<span style="color:#16a34a;font-weight:600;">' . esc_html( $summary['ok'] ) . ' ok</span>';
										}
										if ( ! empty( $summary['errors'] ) ) {
											$parts[] = '<span style="color:#dc2626;font-weight:600;">' . esc_html( $summary['errors'] ) . ' err</span>';
										}
										if ( ! empty( $summary['skipped'] ) ) {
											$parts[] = '<span style="color:#6b7280;">' . esc_html( $summary['skipped'] ) . ' skipped</span>';
										}
										if ( ! empty( $summary['good'] ) ) {
											$parts[] = '<span style="color:#16a34a;font-weight:600;">' . esc_html( $summary['good'] ) . ' good</span>';
										}
										if ( ! empty( $summary['bad'] ) ) {
											$parts[] = '<span style="color:#dc2626;font-weight:600;">' . esc_html( $summary['bad'] ) . ' bad</span>';
										}
										echo wp_kses_post( implode( ' &middot; ', $parts ) );
										?>
										<span style="color:#9ca3af;font-size:11px;margin-left:6px;">
											(<?php echo esc_html( count( $run['results'] ) ); ?> <?php esc_html_e( 'items', 'versi-content-tools' ); ?>)
										</span>
									</td>
									<td>
										<button type="button" class="button button-small versi-history-download" data-run-id="<?php echo esc_attr( $run['id'] ); ?>">
											<?php esc_html_e( 'Download CSV', 'versi-content-tools' ); ?>
										</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function render_background_tab( $workload ) {
		$job = get_option( 'versi_job_status', false );
		if ( 'alt' === $workload ) {
			$safe_label = __( 'Fill Missing Alt Text', 'versi-content-tools' );
			$safe_mode  = 'missing';
			$dest_label = __( 'Regenerate All Alt Text', 'versi-content-tools' );
			$dest_mode  = 'regenerate';
		} elseif ( 'content' === $workload ) {
			$safe_label  = __( 'Update Alt Attributes in Content', 'versi-content-tools' );
			$safe_mode   = 'update_alt';
			$short_label = __( 'Strip Self-Linking Image Wrappers', 'versi-content-tools' );
			$short_mode  = 'strip links';
			$dest_label  = __( 'Apply Both (Alt + Strip Links)', 'versi-content-tools' );
			$dest_mode   = 'both';
		} elseif ( 'seo' === $workload ) {
			$safe_label = __( 'Generate Focus Keywords', 'versi-content-tools' );
			$safe_mode  = 'generate';
			$dest_label = __( 'Regenerate All Keywords', 'versi-content-tools' );
			$dest_mode  = 'regenerate';
		} else {
			$safe_label  = __( 'Generate Missing Excerpts', 'versi-content-tools' );
			$safe_mode   = 'missing';
			$short_label = __( 'Fix Short Excerpts', 'versi-content-tools' );
			$short_mode  = 'short';
			$dest_label  = __( 'Improve All Excerpts', 'versi-content-tools' );
			$dest_mode   = 'improve';
		}
		?>
		<div id="versi-bg-tab">
			<?php if ( $job && ! empty( $job['is_running'] ) ) : ?>
				<div class="versi-job-notice">
					<div class="versi-job-header" style="display:flex;align-items:center;gap:12px;">
						<svg aria-hidden="true" focusable="false" width="20" height="20" fill="none" stroke="#1e40af" stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
						<div>
							<p style="margin:0;font-size:14px;font-weight:600;color:#1e40af;"><strong><?php esc_html_e( 'Background job running', 'versi-content-tools' ); ?></strong></p>
					<p>
						<?php esc_html_e( 'Progress:', 'versi-content-tools' ); ?>
						<span id="versi-bg-progress-tab"><?php echo esc_html( $job['processed'] ); ?> / <?php echo esc_html( $job['total'] ); ?></span>
						&mdash;
						<?php echo esc_html( $job['workload'] ); ?> / <?php echo esc_html( $job['mode'] ); ?>
					</p>
					<p id="versi-bg-stall-warn-tab" style="color:#b32d2e;display:none;margin:8px 0 0;">
						<?php esc_html_e( 'This job appears stalled. WP-Cron events may not be firing. If you have set DISABLE_WP_CRON, make sure your system cron is calling wp-cron.php regularly. Otherwise, remove that constant from wp-config.php.', 'versi-content-tools' ); ?>
					</p>
					<button id="versi-bg-cancel-tab" class="button"><?php esc_html_e( 'Cancel Job', 'versi-content-tools' ); ?></button>
				</div>
			<?php else : ?>
				<p><?php esc_html_e( 'No active background job. Start a new one:', 'versi-content-tools' ); ?></p>
				<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
					<button type="button" class="button button-primary versi-bg-start-btn" data-workload="<?php echo esc_attr( $workload ); ?>" data-mode="<?php echo esc_attr( $safe_mode ); ?>">
						<?php echo esc_html( $safe_label ); ?>
					</button>
				<?php if ( 'content' === $workload || 'excerpt' === $workload ) : ?>
					<button type="button" class="button versi-bg-start-btn" data-workload="<?php echo esc_attr( $workload ); ?>" data-mode="<?php echo esc_attr( $short_mode ); ?>">
						<?php echo esc_html( $short_label ); ?>
					</button>
				<?php endif; ?>
					<button type="button" class="button versi-bg-start-btn" data-workload="<?php echo esc_attr( $workload ); ?>" data-mode="<?php echo esc_attr( $dest_mode ); ?>">
						<?php echo esc_html( $dest_label ); ?>
					</button>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the Dashboard tab with overview stats and recommended order.
	 *
	 * @return void
	 */
	private function render_dashboard_tab() {
		$alt_stats   = Versi_Alt_Text_Processor::init()->get_stats();
		$exc_stats   = Versi_Excerpt_Processor::init()->get_stats();
		$auditor     = Versi_Auditor::init();
		$has_ai      = function_exists( 'wp_ai_client_prompt' );
		$base_url    = admin_url( 'upload.php?page=versi-processing' );
		$alt_url     = add_query_arg( 'versi_workload', 'alt', $base_url );
		$exc_url     = add_query_arg( 'versi_workload', 'excerpt', $base_url );
		$auditor_url = add_query_arg( 'versi_workload', 'auditor', $base_url );
		?>
		<div class="versi-dash-grid">
			<div class="versi-dash-card">
				<div class="versi-dash-card-header">
					<svg aria-hidden="true" focusable="false" width="20" height="20" fill="none" stroke="#2563eb" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
					<span style="font-weight:600;font-size:14px;"><?php esc_html_e( 'Alt Text', 'versi-content-tools' ); ?></span>
				</div>
				<div class="versi-dash-card-body">
					<div class="versi-dash-number"><?php echo esc_html( $alt_stats['missing'] ); ?></div>
					<div class="versi-dash-label"><?php esc_html_e( 'images missing alt text', 'versi-content-tools' ); ?></div>
					<a href="<?php echo esc_url( $alt_url ); ?>" class="button button-primary" style="border-radius:8px;"><?php esc_html_e( 'Generate Alt Text', 'versi-content-tools' ); ?></a>
				</div>
			</div>
			<div class="versi-dash-card">
				<div class="versi-dash-card-header">
					<svg aria-hidden="true" focusable="false" width="20" height="20" fill="none" stroke="#16a34a" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
					<span style="font-weight:600;font-size:14px;"><?php esc_html_e( 'Excerpts', 'versi-content-tools' ); ?></span>
				</div>
				<div class="versi-dash-card-body">
					<div class="versi-dash-number"><?php echo esc_html( $exc_stats['missing'] ); ?></div>
					<div class="versi-dash-label"><?php esc_html_e( 'posts missing excerpts', 'versi-content-tools' ); ?></div>
					<a href="<?php echo esc_url( $exc_url ); ?>" class="button button-primary" style="border-radius:8px;"><?php esc_html_e( 'Generate Excerpts', 'versi-content-tools' ); ?></a>
				</div>
			</div>
			<div class="versi-dash-card">
				<div class="versi-dash-card-header">
					<svg aria-hidden="true" focusable="false" width="20" height="20" fill="none" stroke="#6366f1" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
					<span style="font-weight:600;font-size:14px;"><?php esc_html_e( 'Media Auditor', 'versi-content-tools' ); ?></span>
				</div>
				<div class="versi-dash-card-body">
					<div class="versi-dash-number">&mdash;</div>
					<div class="versi-dash-label"><?php esc_html_e( 'Run a scan to find unlinked images', 'versi-content-tools' ); ?></div>
					<a href="<?php echo esc_url( $auditor_url ); ?>" class="button" style="border-radius:8px;"><?php esc_html_e( 'Open Auditor', 'versi-content-tools' ); ?></a>
				</div>
			</div>
		</div>

		<div class="versi-dash-card" style="max-width:700px;">
			<div class="versi-dash-card-header">
				<svg aria-hidden="true" focusable="false" width="20" height="20" fill="none" stroke="#d97706" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
				<span style="font-weight:600;font-size:14px;"><?php esc_html_e( 'Recommended Order', 'versi-content-tools' ); ?></span>
			</div>
			<div class="versi-dash-card-body">
				<ol class="versi-dash-order">
					<li>
						<div><strong><?php esc_html_e( 'Run Media Auditor', 'versi-content-tools' ); ?></strong><br>
						<span style="color:#4b5563;"><?php esc_html_e( 'Link unlinked images to their parent posts for better AI context.', 'versi-content-tools' ); ?></span></div>
					</li>
					<li>
						<div><strong><?php esc_html_e( 'Generate Alt Text', 'versi-content-tools' ); ?></strong><br>
						<span style="color:#4b5563;"><?php esc_html_e( 'Create descriptive alt text for all images with AI context from linked posts.', 'versi-content-tools' ); ?></span></div>
					</li>
					<li>
						<div><strong><?php esc_html_e( 'Generate Excerpts', 'versi-content-tools' ); ?></strong><br>
						<span style="color:#4b5563;"><?php esc_html_e( 'AI-generated post excerpts benefit from alt text context on featured images.', 'versi-content-tools' ); ?></span></div>
					</li>
					<li>
						<div><strong><?php esc_html_e( 'SEO Keywords', 'versi-content-tools' ); ?></strong><br>
						<span style="color:#4b5563;"><?php esc_html_e( 'Generate focus keywords last, using the complete post content including excerpts.', 'versi-content-tools' ); ?></span></div>
					</li>
				</ol>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Auditor tab.
	 *
	 * @return void
	 */
	private function render_auditor_tab() {
		?>
		<div class="versi-auditor-card">
			<div class="versi-auditor-header">
				<svg aria-hidden="true" focusable="false" width="24" height="24" fill="none" stroke="#6366f1" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
				<div>
					<h3 style="margin:0;font-size:15px;font-weight:600;color:#1e1e1e;"><?php esc_html_e( 'Media Auditor', 'versi-content-tools' ); ?></h3>
					<p style="margin:2px 0 0;font-size:13px;color:#4b5563;"><?php esc_html_e( 'Find images used in your content that are not linked to any post.', 'versi-content-tools' ); ?></p>
				</div>
			</div>
			<div class="versi-auditor-body">
				<p style="margin:0 0 16px;font-size:13px;color:#4b5563;line-height:1.5;">
					<?php esc_html_e( 'Linking unlinked images to their parent post gives the AI better context when generating alt text, excerpts, and SEO keywords. The scan searches published post content for image filenames that match media library items with no post parent.', 'versi-content-tools' ); ?>
				</p>
				<button type="button" class="button button-primary" id="versi-audit-btn" style="border-radius:8px;">
					<?php esc_html_e( 'Run Audit', 'versi-content-tools' ); ?>
				</button>
				<div id="versi-audit-results" style="margin-top:16px;"></div>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// AJAX Handlers
	// -------------------------------------------------------------------------

	/**
	 * Verify AJAX nonce and edit_posts capability.
	 *
	 * @param string $nonce_action Nonce action name.
	 * @return void Sends error and exits on failure.
	 */
	private function ajax_check( $nonce_action = 'versi_process' ) {
		check_ajax_referer( $nonce_action );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}
	}

	/**
	 * AJAX: process a single image for alt text.
	 *
	 * @return void
	 */
	public function ajax_alt_process_single() {
		$this->ajax_check();

		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$mode = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'missing';

		if ( ! $id ) {
			wp_send_json_error( 'No ID provided' );
		}

		$result = Versi_Alt_Text_Processor::init()->process_single( $id );
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: get image IDs for alt-text batch processing.
	 *
	 * @return void
	 */
	public function ajax_alt_get_ids() {
		$this->ajax_check();

		$mode   = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'missing';
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$batch  = isset( $_POST['batch'] ) ? absint( $_POST['batch'] ) : 5;
		$cat_id = isset( $_POST['catId'] ) ? absint( $_POST['catId'] ) : 0;

		$result = Versi_Processor::init()->get_image_ids( $mode, $offset, $batch, $cat_id );
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: undo alt text change.
	 *
	 * @return void
	 */
	public function ajax_alt_undo() {
		$this->ajax_check();

		$id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$alt = isset( $_POST['alt'] ) ? sanitize_text_field( wp_unslash( $_POST['alt'] ) ) : '';

		if ( ! $id ) {
			wp_send_json_error( 'No ID' );
		}

		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		wp_send_json_success(
			array(
				'id'  => $id,
				'alt' => $alt,
			)
		);
	}

	/**
	 * AJAX: process a single post for SEO keywords.
	 *
	 * @return void
	 */
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

		$ext       = Versi_Extensions::init();
		$previous  = $ext->get_focus_keywords( $id );
		$generated = $ext->generate_focus_keywords( $id );
		$status    = ! empty( $generated );
		$rl        = Versi_Extensions::$last_rate_limit;

		$result = Versi_Processor::init()->result(
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

	/**
	 * AJAX: get post IDs for SEO batch processing.
	 *
	 * @return void
	 */
	public function ajax_seo_get_ids() {
		$this->ajax_check();

		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$batch  = isset( $_POST['batch'] ) ? absint( $_POST['batch'] ) : 5;

		$result = Versi_Processor::init()->get_seo_ids( $offset, $batch );
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: get post IDs for content cleanup processing.
	 *
	 * @return void
	 */
	public function ajax_content_get_ids() {
		$this->ajax_check();

		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$batch  = isset( $_POST['batch'] ) ? absint( $_POST['batch'] ) : 5;

		$result = Versi_Processor::init()->get_post_ids( $offset, $batch );
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: process a single post for content cleanup.
	 *
	 * @return void
	 */
	public function ajax_content_process_single() {
		$this->ajax_check();

		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$mode = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'update_alt';

		if ( ! $id ) {
			wp_send_json_error( 'No ID provided' );
		}

		$post = get_post( $id );
		if ( ! $post ) {
			$result = Versi_Processor::init()->result( $id, '', 'error', null, __( 'Post not found.', 'versi-content-tools' ) );
			wp_send_json_success( $result );
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

		$status = 'skipped';
		$reason = null;

		if ( $changed ) {
			global $wpdb;
			$updated = $wpdb->update(
				$wpdb->posts,
				array( 'post_content' => $new_content ),
				array( 'ID' => $id ),
				array( '%s' ),
				array( '%d' )
			);
			if ( false !== $updated ) {
				clean_post_cache( $id );
				$status = 'success';
			} else {
				$status = 'error';
				$reason = __( 'Database update failed.', 'versi-content-tools' );
			}
		} elseif ( ! $changed && 'both' === $mode ) {
			$reason = __( 'No changes needed.', 'versi-content-tools' );
		} elseif ( ! $changed ) {
			$reason = __( 'No changes needed.', 'versi-content-tools' );
		}

		$result = Versi_Processor::init()->result(
			$id,
			$post->post_title,
			$status,
			null,
			'error' === $status && ! $reason ? __( 'Processing failed.', 'versi-content-tools' ) : null,
			$reason,
			null,
			$changed
		);
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: bulk review alt texts for quality.
	 *
	 * @return void
	 */
	public function ajax_alt_bulk_review() {
		$this->ajax_check();

		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$batch  = isset( $_POST['batch'] ) ? absint( $_POST['batch'] ) : 30;

		$ids_result = Versi_Processor::init()->get_image_ids( 'regenerate', $offset, $batch, 0 );
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

		$items = Versi_Alt_Text_Processor::init()->bulk_review( $ids );
		wp_send_json_success(
			array(
				'items' => $items,
				'total' => $total,
			)
		);
	}

	/**
	 * AJAX: bulk review excerpts for quality.
	 *
	 * @return void
	 */
	public function ajax_excerpt_bulk_review() {
		$this->ajax_check();

		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$batch  = isset( $_POST['batch'] ) ? absint( $_POST['batch'] ) : 30;

		$ids_result = Versi_Processor::init()->get_excerpt_ids( 'improve', $offset, $batch );
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

		$items = Versi_Excerpt_Processor::init()->bulk_review( $ids );
		wp_send_json_success(
			array(
				'items' => $items,
				'total' => $total,
			)
		);
	}

	/**
	 * AJAX: process a single post for excerpt.
	 *
	 * @return void
	 */
	public function ajax_excerpt_process_single() {
		$this->ajax_check();

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( ! $id ) {
			wp_send_json_error( 'No ID provided' );
		}

		$result = Versi_Excerpt_Processor::init()->process_single( $id );
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: get post IDs for excerpt batch processing.
	 *
	 * @return void
	 */
	public function ajax_excerpt_get_ids() {
		$this->ajax_check();

		$mode   = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'missing';
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$batch  = isset( $_POST['batch'] ) ? absint( $_POST['batch'] ) : 5;

		$result = Versi_Processor::init()->get_excerpt_ids( $mode, $offset, $batch );
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: undo excerpt change (restore previous excerpt).
	 *
	 * @return void
	 */
	public function ajax_excerpt_undo() {
		$this->ajax_check();

		$id      = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$excerpt = isset( $_POST['alt'] ) ? sanitize_text_field( wp_unslash( $_POST['alt'] ) ) : '';

		if ( ! $id ) {
			wp_send_json_error( 'No ID' );
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

	/**
	 * AJAX: list available models from all configured AI providers.
	 *
	 * @return void
	 */
	public function ajax_get_models() {
		check_ajax_referer( 'versi_get_models' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		if ( ! class_exists( 'WordPress\AiClient\AiClient' ) ) {
			wp_send_json_error( 'AI Client not available' );
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

			wp_send_json_success( $models );
		} catch ( \Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	// -------------------------------------------------------------------------
	// Background Job Handlers
	// -------------------------------------------------------------------------

	/**
	 * AJAX: create a background job.
	 *
	 * @return void
	 */
	public function ajax_create_job() {
		$this->ajax_check();

		$mode     = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'missing';
		$workload = isset( $_POST['workload'] ) ? sanitize_key( $_POST['workload'] ) : 'alt';

		$total  = 0;
		$cat_id = 0;

		if ( 'alt' === $workload ) {
			$cat_id = absint( get_option( 'versi_alt_cat_filter', 0 ) );
			$ids    = Versi_Processor::init()->get_image_ids( $mode, 0, 1, $cat_id );
			$total  = $ids['total'];
		} elseif ( 'seo' === $workload ) {
			$ids   = Versi_Processor::init()->get_seo_ids( 0, 1 );
			$total = $ids['total'];
		} elseif ( 'content' === $workload ) {
			$ids   = Versi_Processor::init()->get_post_ids( 0, 1 );
			$total = $ids['total'];
		} else {
			$ids   = Versi_Processor::init()->get_excerpt_ids( $mode, 0, 1 );
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

		// Schedule the first cron event; don't process synchronously — let
		// background processing advance at its own pace.
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

	/**
	 * AJAX: get background job status.
	 *
	 * @return void
	 */
	public function ajax_job_status() {
		$this->ajax_check( 'versi_job_status' );

		$job = get_option( 'versi_job_status', false );
		if ( ! $job ) {
			wp_send_json_error( 'No job found' );
		}

		$response                  = $job;
		$response['cron_disabled'] = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		// Report stalled if updated_at hasn't moved in 120+ seconds (accounting for slow AI responses).
		if ( ! empty( $job['is_running'] ) && ! empty( $job['updated_at'] ) ) {
			$response['stalled'] = ( time() - (int) $job['updated_at'] ) > 120;
		}

		wp_send_json_success( $response );
	}

	/**
	 * AJAX: cancel background job.
	 *
	 * @return void
	 */
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

	/**
	 * AJAX: save live job state so the user can resume later.
	 *
	 * @return void
	 */
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

	/**
	 * AJAX: load saved live job state.
	 *
	 * @return void
	 */
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

	/**
	 * AJAX: dismiss a saved live job (user chose to start fresh).
	 *
	 * @return void
	 */
	public function ajax_dismiss_job() {
		$this->ajax_check();

		delete_option( 'versi_live_job_status' );
		wp_send_json_success( array( 'dismissed' => true ) );
	}

	/**
	 * AJAX: Save processing results to history.
	 *
	 * @return void
	 */
	public function ajax_save_results() {
		$this->ajax_check();

		$workload = isset( $_POST['workload'] ) ? sanitize_key( wp_unslash( $_POST['workload'] ) ) : '';
		$mode     = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		$results  = isset( $_POST['results'] ) ? wp_unslash( $_POST['results'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( empty( $workload ) || ! is_array( $results ) ) {
			wp_send_json_error( 'Invalid data' );
		}

		// Sanitize results array.
		$sanitized = array();
		foreach ( $results as $r ) {
			$item = array(
				'id'        => isset( $r['id'] ) ? absint( $r['id'] ) : 0,
				'title'     => isset( $r['title'] ) ? sanitize_text_field( wp_unslash( $r['title'] ) ) : '',
				'status'    => isset( $r['status'] ) ? sanitize_key( $r['status'] ) : '',
				'previous'  => isset( $r['previous'] ) ? sanitize_textarea_field( wp_unslash( $r['previous'] ) ) : '',
				'generated' => isset( $r['generated'] ) ? sanitize_textarea_field( wp_unslash( $r['generated'] ) ) : '',
				'error'     => isset( $r['error'] ) ? sanitize_textarea_field( wp_unslash( $r['error'] ) ) : '',
				'reason'    => isset( $r['reason'] ) ? sanitize_textarea_field( wp_unslash( $r['reason'] ) ) : '',
				'changed'   => isset( $r['changed'] ) ? (bool) $r['changed'] : false,
			);

			// Include auditor-specific fields.
			if ( 'auditor' === $workload ) {
				$item['attachment_id']  = isset( $r['attachment_id'] ) ? absint( $r['attachment_id'] ) : 0;
				$item['attachment_url'] = isset( $r['attachment_url'] ) ? esc_url_raw( wp_unslash( $r['attachment_url'] ) ) : '';
				$item['att_path']       = isset( $r['att_path'] ) ? sanitize_text_field( wp_unslash( $r['att_path'] ) ) : '';
				$item['post_id']        = isset( $r['post_id'] ) ? absint( $r['post_id'] ) : 0;
				$item['post_title']     = isset( $r['post_title'] ) ? sanitize_text_field( wp_unslash( $r['post_title'] ) ) : '';
			}

			// Include review-specific fields.
			if ( 'review' === $workload || 'bulk_review' === $mode ) {
				$item['alt']     = isset( $r['alt'] ) ? sanitize_textarea_field( wp_unslash( $r['alt'] ) ) : '';
				$item['excerpt'] = isset( $r['excerpt'] ) ? sanitize_textarea_field( wp_unslash( $r['excerpt'] ) ) : '';
			}

			$sanitized[] = $item;
		}

		// Build summary.
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

		// Store full results in a dedicated option keyed by run ID.
		update_option( 'versi_history_run_' . $run_id, $sanitized, false );

		// Store only summary in the history list.
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

		// Keep max 50 entries; clean up old run data.
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

	/**
	 * AJAX: Get list of history runs (summary only, no results).
	 *
	 * @return void
	 */
	public function ajax_get_history() {
		$this->ajax_check();

		$history = get_option( 'versi_processing_history', array() );

		// Return only summary info (no results) for listing.
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

	/**
	 * AJAX: Get a specific history run with full results.
	 *
	 * @return void
	 */
	public function ajax_get_history_run() {
		$this->ajax_check();

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

	/**
	 * AJAX: Clear all processing history.
	 *
	 * @return void
	 */
	public function ajax_clear_history() {
		$this->ajax_check();

		$history = get_option( 'versi_processing_history', array() );
		foreach ( $history as $entry ) {
			delete_option( 'versi_history_run_' . $entry['id'] );
		}
		delete_option( 'versi_processing_history' );
		wp_send_json_success( array( 'cleared' => true ) );
	}

	/**
	 * Process a background batch (called via cron).
	 *
	 * @return void
	 */
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

	/**
	 * Process a single batch of items and update the job status.
	 *
	 * @param array $job Job status (passed by reference).
	 * @return bool True if more items remain, false if done.
	 */
	private function process_single_batch( array &$job ): bool {
		$shared   = Versi_Processor::init();
		$alt_proc = Versi_Alt_Text_Processor::init();
		$exc_proc = Versi_Excerpt_Processor::init();

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
				$wait = ! empty( $result['retry_after'] ) ? max( (int) ceil( $result['retry_after'] ), 5 ) : 30;
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

	/**
	 * AJAX: Initialize attachment audit.
	 */
	public function ajax_run_audit() {
		check_ajax_referer( 'versi_run_audit' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		try {
			$total = Versi_Auditor::init()->get_unlinked_count();
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

	/**
	 * AJAX: Get auditor progress and results batch.
	 */
	public function ajax_audit_progress() {
		check_ajax_referer( 'versi_run_audit' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$limit  = isset( $_POST['limit'] ) ? max( 1, (int) $_POST['limit'] ) : Versi_Auditor::BATCH_SIZE;

		try {
			$total         = Versi_Auditor::init()->get_unlinked_count();
			$batch_results = Versi_Auditor::init()->find_unlinked_batch( $offset, $limit );
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

	/**
	 * AJAX: Link attachment.
	 */
	public function ajax_link_attachment() {
		check_ajax_referer( 'versi_link_attachment' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}
		$att_id  = isset( $_POST['attachment_id'] ) ? (int) $_POST['attachment_id'] : 0;
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( ! $att_id || ! $post_id ) {
			wp_send_json_error( array( 'message' => 'Invalid attachment or post ID.' ) );
		}
		try {
			$result = Versi_Auditor::init()->link_attachment( $att_id, $post_id );
			wp_send_json_success( $result );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	// -------------------------------------------------------------------------
	// Content Cleanup Helpers
	// -------------------------------------------------------------------------

	/**
	 * Process a single post for SEO (used by background job).
	 *
	 * @param int $id Post ID.
	 * @return array Result array.
	 */
	private function process_seo_single( $id ) {
		$post = get_post( $id );
		if ( ! $post ) {
			return Versi_Processor::init()->result( $id, '', 'error', null, __( 'Post not found.', 'versi-content-tools' ) );
		}

		$ext       = Versi_Extensions::init();
		$previous  = $ext->get_focus_keywords( $id );
		$generated = $ext->generate_focus_keywords( $id );
		$status    = ! empty( $generated );
		$rl        = Versi_Extensions::$last_rate_limit;

		return Versi_Processor::init()->result(
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

	/**
	 * Process a single post for content cleanup (used by background job).
	 *
	 * @param int    $id   Post ID.
	 * @param string $mode 'update_alt', 'strip_links', or 'both'.
	 * @return array Result array.
	 */
	private function process_content_single( $id, $mode ) {
		$post = get_post( $id );
		if ( ! $post ) {
			return Versi_Processor::init()->result( $id, '', 'error', null, __( 'Post not found.', 'versi-content-tools' ) );
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
			return Versi_Processor::init()->result(
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
			return Versi_Processor::init()->result(
				$id,
				$post->post_title,
				'error',
				null,
				__( 'Database update failed.', 'versi-content-tools' )
			);
		}

		clean_post_cache( $id );

		return Versi_Processor::init()->result(
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

	/**
	 * Update alt attributes in post_content to match current attachment meta.
	 * Modifies the content string in place via pass-by-reference $changed flag.
	 *
	 * @param string $content Post content (modified in place).
	 * @param bool   $changed Set to true if any replacement was made.
	 * @return string Updated content.
	 */
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

			$img_id  = (int) $m[1];
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

	/**
	 * Strip <a> wrappers from images in post_content when the link points
	 * to an image file. Modifies via pass-by-reference $changed flag.
	 *
	 * @param string $content Post content (modified in place).
	 * @param bool   $changed Set to true if any replacement was made.
	 * @return string Updated content.
	 */
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

	// -------------------------------------------------------------------------
	// Auto-generate on upload / save
	// -------------------------------------------------------------------------

	/**
	 * Dynamically update alt attributes in post content to match current
	 * attachment meta. Hooked into the_content when the option is enabled.
	 *
	 * @param string $content Post content.
	 * @return string Updated content.
	 */
	public function filter_content_alt_attributes( $content ) {
		if ( empty( $content ) || false === stripos( $content, 'wp-image-' ) ) {
			return $content;
		}

		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $content;
		}

		$processor = new WP_HTML_Tag_Processor( $content );

		while ( $processor->next_tag( 'img' ) ) {
			$class = $processor->get_attribute( 'class' );
			if ( ! $class || ! preg_match( '/wp-image-(\d+)/i', $class, $m ) ) {
				continue;
			}

			$attachment_id = (int) $m[1];
			$alt           = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			if ( ! is_string( $alt ) || '' === trim( $alt ) ) {
				continue;
			}

			$existing = $processor->get_attribute( 'alt' );
			if ( $existing === $alt ) {
				continue;
			}

			$processor->set_attribute( 'alt', $alt );
		}

		return $processor->get_updated_html();
	}

	/**
	 * Strip <a> wrappers from images when the link points to the same image
	 * file. Non-destructive — only affects rendered output.
	 *
	 * @param string $content Post content.
	 * @return string Updated content.
	 */
	public function filter_strip_self_linking_images( $content ) {
		if ( '' === $content || false === stripos( $content, '<a' ) || false === stripos( $content, '<img' ) ) {
			return $content;
		}

		$pattern = '~<a\s[^>]*?href=["\']?[^"\'\s]+\.(?:jpg|jpeg|png|gif|webp)["\'\s>][^>]*>\s*(<img[^>]+>)\s*</a>~is';
		return preg_replace( $pattern, '$1', $content );
	}

	/**
	 * Auto-generate alt text on image upload.
	 *
	 * @param int $attachment_id New attachment ID.
	 * @return void
	 */
	public function alt_auto_generate_on_upload( $attachment_id ) {
		if ( '1' !== get_option( 'versi_alt_auto_generate', '0' ) ) {
			return;
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( ! is_string( $mime ) || ! str_starts_with( $mime, 'image/' ) ) {
			return;
		}

		// Skip if alt text already exists.
		$existing = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( is_string( $existing ) && '' !== trim( $existing ) ) {
			return;
		}

		$result = Versi_Alt_Text_Processor::init()->process_single( $attachment_id );

		if ( 'success' === $result['status'] && get_option( 'versi_alt_show_generated', '0' ) ) {
			update_user_meta(
				get_current_user_id(),
				'versi_last_generated_alt',
				array(
					'attachment_id' => $attachment_id,
					'alt_text'      => $result['generated'],
				)
			);
		}
	}

	/**
	 * Auto-generate excerpt when a post is published.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 * @return void
	 */
	public function excerpt_auto_generate_on_publish( $new_status, $old_status, $post ) {
		if ( '1' !== get_option( 'versi_excerpt_auto_generate', '0' ) ) {
			return;
		}

		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		if ( 'post' !== $post->post_type ) {
			return;
		}

		if ( ! empty( $post->post_excerpt ) ) {
			return;
		}

		Versi_Excerpt_Processor::init()->process_single( $post->ID );
	}

	// -------------------------------------------------------------------------
	// Media Library integration
	// -------------------------------------------------------------------------

	/**
	 * Show quick-action notice on Media Library.
	 *
	 * @return void
	 */
	public function alt_quick_action_notice() {
		$screen = get_current_screen();
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return;
		}

		$stats = Versi_Alt_Text_Processor::init()->get_stats();

		if ( empty( $stats['total'] ) ) {
			return;
		}

		$needs_help = (int) $stats['missing'] + (int) $stats['too_long'] + (int) $stats['too_short'];
		if ( ! $needs_help ) {
			return;
		}

		?>
		<div class="notice notice-info" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px 16px;">
			<p style="margin:8px 0;">
				<strong><?php echo esc_html( $stats['missing'] ); ?></strong>
				<?php esc_html_e( 'missing', 'versi-content-tools' ); ?>
				&middot;
				<strong><?php echo esc_html( $stats['too_long'] ); ?></strong>
				<?php esc_html_e( 'too long', 'versi-content-tools' ); ?>
				&middot;
				<strong><?php echo esc_html( $stats['too_short'] ); ?></strong>
				<?php esc_html_e( 'too short', 'versi-content-tools' ); ?>
				&mdash;
				<?php esc_html_e( 'Let AI fix them:', 'versi-content-tools' ); ?>
			</p>
			<p style="margin:8px 0;">
				<a href="<?php echo esc_url( admin_url( 'upload.php?page=versi-processing&versi_workload=alt&versi_action=missing' ) ); ?>" class="button button-primary" style="text-decoration:none;">
					<?php esc_html_e( 'Fill Missing', 'versi-content-tools' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'upload.php?page=versi-processing&versi_workload=alt&versi_action=review' ) ); ?>" class="button" style="text-decoration:none;">
					<?php esc_html_e( 'Review & Improve', 'versi-content-tools' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Show notice after alt-text generation on upload.
	 *
	 * @return void
	 */
	public function alt_generated_notice() {
		if ( ! get_option( 'versi_alt_show_generated', false ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}

		$data = get_user_meta( get_current_user_id(), 'versi_last_generated_alt', true );
		if ( empty( $data ) || empty( $data['alt_text'] ) ) {
			return;
		}

		delete_user_meta( get_current_user_id(), 'versi_last_generated_alt' );

		$thumbnail = wp_get_attachment_image( $data['attachment_id'], array( 60, 60 ), true );
		?>
		<div class="notice notice-success is-dismissible">
			<p style="display:flex;align-items:center;gap:12px;margin:8px 0;">
				<?php if ( $thumbnail ) : ?>
					<?php echo wp_kses_post( $thumbnail ); ?>
				<?php endif; ?>
				<span>
					<strong><?php esc_html_e( 'Alt Text Generated:', 'versi-content-tools' ); ?></strong>
					<?php echo esc_html( $data['alt_text'] ); ?>
				</span>
			</p>
		</div>
		<?php
	}

	/**
	 * Mark auto-generated attachments with a flag for JS.
	 *
	 * @param array   $response   Attachment data for JS.
	 * @param WP_Post $attachment Attachment post object.
	 * @return array
	 */
	public function mark_auto_generated( $response, $attachment ) {
		$alt = get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true );
		if ( ! empty( $alt ) ) {
			$response['versi_generated'] = $alt;
		}
		return $response;
	}

	/**
	 * Overlay generated alt text on attachment thumbnails in grid view.
	 *
	 * @return void
	 */
	public function generated_script() {
		?>
		<script>
		jQuery(function($) {
			const orig = wp.media.view.Attachment.Library;
			if (!orig) return;
			wp.media.view.Attachment.Library = orig.extend({
				render() {
					const r = orig.prototype.render.apply(this, arguments);
					if (this.model && this.model.get('versi_generated')) {
						const alt = this.model.get('versi_generated');
						this.$el.append(
							'<div style="position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,0.7);color:#fff;font-size:10px;padding:2px 4px;line-height:1.3;word-break:break-word;max-height:100%;overflow:hidden;">AI: ' + $('<span>').text(alt).html() + '</div>'
						);
					}
					return r;
				}
			});
		});
		</script>
		<?php
	}
}
