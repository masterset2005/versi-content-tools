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
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_menu', array( $this, 'add_processing_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_notices', array( $this, 'alt_quick_action_notice' ) );
		add_action( 'admin_notices', array( $this, 'alt_generated_notice' ) );
		add_filter( 'wp_prepare_attachment_for_js', array( $this, 'mark_auto_generated' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'generated_script' ) );
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

	}

	/**
	 * No-op stub (scripts are inlined). Kept for future extensibility.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_scripts( $hook ) {
		$plugin_pages = array( 'media_page_versi-processing', 'settings_page_versi' );
		if ( ! in_array( $hook, $plugin_pages, true ) ) {
			return;
		}

		$css_ver = filemtime( VERSI_PLUGIN_DIR . 'assets/css/admin.css' );
		wp_enqueue_style( 'versi-admin-css', VERSI_PLUGIN_URL . 'assets/css/admin.css', array(), $css_ver );

		if ( 'settings_page_versi' === $hook ) {
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
						'all'              => __( 'All', 'versi-content-tools' ),
						'searchResults'    => __( 'Search by ID or title...', 'versi-content-tools' ),
						'noResults'        => __( 'No results match your filter.', 'versi-content-tools' ),
						'accept'           => __( 'Accept', 'versi-content-tools' ),
						'skip'             => __( 'Skip', 'versi-content-tools' ),
						'reviewEditPrompt' => __( 'Edit and accept, or regenerate below:', 'versi-content-tools' ),
						'acceptConfirm'    => __( 'Save this value for the item?', 'versi-content-tools' ),
						'saved'            => __( 'Saved', 'versi-content-tools' ),
						'filterAll'        => __( 'All', 'versi-content-tools' ),
						'filterSuccess'    => __( 'Success', 'versi-content-tools' ),
						'filterErrors'     => __( 'Errors', 'versi-content-tools' ),
						'filterSkipped'    => __( 'Skipped', 'versi-content-tools' ),
						'filterGood'       => __( 'Good', 'versi-content-tools' ),
						'filterBad'        => __( 'Bad', 'versi-content-tools' ),
						'statusSuccess'    => __( 'Success', 'versi-content-tools' ),
						'statusError'      => __( 'Error', 'versi-content-tools' ),
						'statusSkipped'    => __( 'Skipped', 'versi-content-tools' ),
						'statusGood'       => __( 'Good', 'versi-content-tools' ),
						'statusBad'        => __( 'Bad', 'versi-content-tools' ),
						'statusInfo'       => __( 'Info', 'versi-content-tools' ),
						'statusKept'       => __( 'Kept', 'versi-content-tools' ),
						'replaced'         => __( 'Replaced', 'versi-content-tools' ),
						'added'            => __( 'Added', 'versi-content-tools' ),
						'kept'             => __( 'Kept (unchanged)', 'versi-content-tools' ),
						'previous'         => __( 'Previous', 'versi-content-tools' ),
						'newValue'         => __( 'New', 'versi-content-tools' ),
						'actions'          => __( 'Actions', 'versi-content-tools' ),
						'title'            => __( 'Title', 'versi-content-tools' ),
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

	public function render_settings_page() {
		Versi_Container::get( Versi_Admin_View::class )->render_settings_page();
	}

	/**
	 * Render the processing page with workload tabs and live/background modes.
	 *
	 * @return void
	 */
	public function render_processing_page() {
		Versi_Container::get( Versi_Admin_View::class )->render_processing_page();
	}

	public function format_error( $error ) {
		return Versi_Container::get( Versi_Admin_View::class )->format_error( $error );
	}

	private function render_result_entry( $result, $workload ) {
		Versi_Container::get( Versi_Admin_View::class )->render_result_entry( $result, $workload );
	}

	private function render_live_tab( $workload ) {
		Versi_Container::get( Versi_Admin_View::class )->render_live_tab( $workload );
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
		Versi_Container::get( Versi_Admin_View::class )->render_history_tab();
	}

	private function render_background_tab( $workload ) {
		Versi_Container::get( Versi_Admin_View::class )->render_background_tab( $workload );
	}

	private function render_dashboard_tab() {
		Versi_Container::get( Versi_Admin_View::class )->render_dashboard_tab();
	}

	private function render_auditor_tab() {
		Versi_Container::get( Versi_Admin_View::class )->render_auditor_tab();
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

		$result = Versi_Container::get(Versi_Alt_Text_Processor::class)->process_single( $attachment_id );

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

		Versi_Container::get(Versi_Excerpt_Processor::class)->process_single( $post->ID );
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

		$stats = Versi_Container::get(Versi_Alt_Text_Processor::class)->get_stats();

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
	public function generated_script( $hook ) {
		if ( 'upload.php' !== $hook ) {
			return;
		}
		$js_ver = filemtime( VERSI_PLUGIN_DIR . 'assets/js/media-library.js' );
		wp_enqueue_script( 'versi-media-library', VERSI_PLUGIN_URL . 'assets/js/media-library.js', array( 'jquery' ), $js_ver, true );
	}
}
