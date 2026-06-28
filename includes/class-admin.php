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
		add_action( 'wp_insert_post', array( $this, 'excerpt_auto_generate_on_save' ), 10, 3 );

		// AJAX: alt-text.
		add_action( 'wp_ajax_versi_alt_process_single', array( $this, 'ajax_alt_process_single' ) );
		add_action( 'wp_ajax_versi_alt_get_ids', array( $this, 'ajax_alt_get_ids' ) );
		add_action( 'wp_ajax_versi_alt_undo', array( $this, 'ajax_alt_undo' ) );

		// AJAX: excerpt.
		add_action( 'wp_ajax_versi_excerpt_process_single', array( $this, 'ajax_excerpt_process_single' ) );
		add_action( 'wp_ajax_versi_excerpt_get_ids', array( $this, 'ajax_excerpt_get_ids' ) );
		add_action( 'wp_ajax_versi_excerpt_undo', array( $this, 'ajax_excerpt_undo' ) );

		// AJAX: shared.
		add_action( 'wp_ajax_versi_get_models', array( $this, 'ajax_get_models' ) );
		add_action( 'wp_ajax_versi_create_job', array( $this, 'ajax_create_job' ) );
		add_action( 'wp_ajax_versi_job_status', array( $this, 'ajax_job_status' ) );
		add_action( 'wp_ajax_versi_cancel_job', array( $this, 'ajax_cancel_job' ) );
		add_action( 'wp_ajax_versi_save_job', array( $this, 'ajax_save_job' ) );
		add_action( 'wp_ajax_versi_load_job', array( $this, 'ajax_load_job' ) );
		add_action( 'wp_ajax_versi_dismiss_job', array( $this, 'ajax_dismiss_job' ) );
		add_action( 'versi_process_batch', array( $this, 'process_background_batch' ) );
	}

	/**
	 * Enqueue scripts for processing and settings pages.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_scripts( $hook ) {
		$is_processing = 'media_page_versi-processing' === $hook;
		$is_upload     = 'upload.php' === $hook;
		$is_settings   = 'settings_page_versi' === $hook;

		if ( ! $is_upload && ! $is_processing && ! $is_settings ) {
			return;
		}

		$js_ver = filemtime( VERSI_PLUGIN_DIR . 'assets/admin.js' );

		wp_enqueue_script(
			'versi-admin',
			VERSI_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery' ),
			$js_ver,
			true
		);

		$data = array(
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'versi_process' ),
			'getModelsNonce' => wp_create_nonce( 'versi_get_models' ),
		);

		// Only send processing params on the upload page (media library overlay).
		if ( $is_upload ) {
			$batch_sz = absint( get_option( 'versi_batch_size', 5 ) );
			if ( $batch_sz < 1 ) {
				$batch_sz = 1;
			}
			if ( $batch_sz > 50 ) {
				$batch_sz = 50;
			}
			$data['batchSize'] = $batch_sz;
			$data['action']    = '';
			$data['workload']  = 'alt';
		}

		wp_localize_script( 'versi-admin', 'versiBulkData', $data );
	}

	/**
	 * Register all settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'versi_settings',
			'versi_batch_size',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_batch_size' ),
				'default'           => 5,
			)
		);

		register_setting(
			'versi_settings',
			'versi_match_author_tone',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '0',
			)
		);

		register_setting(
			'versi_settings',
			'versi_debug_mode',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '0',
			)
		);

		register_setting(
			'versi_settings',
			'versi_vision_model',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_model_preference' ),
				'default'           => '',
			)
		);

		register_setting(
			'versi_settings',
			'versi_text_model',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_model_preference' ),
				'default'           => '',
			)
		);

		// Workload-specific model settings.
		register_setting(
			'versi_settings',
			'versi_alt_vision_model',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_model_preference' ),
				'default'           => '',
			)
		);
		register_setting(
			'versi_settings',
			'versi_alt_text_model',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_model_preference' ),
				'default'           => '',
			)
		);
		register_setting(
			'versi_settings',
			'versi_excerpt_text_model',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_model_preference' ),
				'default'           => '',
			)
		);

		register_setting(
			'versi_settings',
			'versi_content_limit',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_content_limit' ),
				'default'           => 500,
			)
		);

		// Alt-text settings.
		register_setting(
			'versi_settings',
			'versi_alt_processing_mode',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_processing_mode' ),
				'default'           => 'two-pass',
			)
		);

		register_setting(
			'versi_settings',
			'versi_alt_system_prompt',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			)
		);

		register_setting(
			'versi_settings',
			'versi_alt_compare_prompt',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			)
		);

		register_setting(
			'versi_settings',
			'versi_alt_single_prompt',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			)
		);

		register_setting(
			'versi_settings',
			'versi_alt_auto_generate',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '0',
			)
		);

		register_setting(
			'versi_settings',
			'versi_alt_show_generated',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '0',
			)
		);

		register_setting(
			'versi_settings',
			'versi_alt_cat_filter',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);

		// Excerpt settings.
		register_setting(
			'versi_settings',
			'versi_excerpt_auto_generate',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '0',
			)
		);

		register_setting(
			'versi_settings',
			'versi_excerpt_prompt',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			)
		);

		register_setting(
			'versi_settings',
			'versi_excerpt_length',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_excerpt_length' ),
				'default'           => 55,
			)
		);
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
	 * Register the Media > Versi Processing page.
	 *
	 * @return void
	 */
	public function add_processing_page() {
		add_media_page(
			__( 'Versi Processing', 'versi-content-tools' ),
			__( 'Versi Processing', 'versi-content-tools' ),
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
							</td>
						</tr>
						<tr>
							<th scope="row">
								<?php esc_html_e( 'Processing Mode', 'versi-content-tools' ); ?>
							</th>
							<td>
								<fieldset>
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
								<label for="versi_alt_single_prompt"><?php esc_html_e( 'Single-Pass Prompt', 'versi-content-tools' ); ?></label>
							</th>
							<td>
								<textarea id="versi_alt_single_prompt" name="versi_alt_single_prompt" rows="12" class="large-text code">
								<?php echo esc_textarea( get_option( 'versi_alt_single_prompt', '' ) ); ?>
								</textarea>
								<p class="description"><?php esc_html_e( 'Combined system instruction for Single-Pass mode. Use the variables below.', 'versi-content-tools' ); ?></p>
								<details style="margin-top:8px;">
									<summary><?php esc_html_e( 'Available variables', 'versi-content-tools' ); ?></summary>
									<pre style="background:#f0f0f1;padding:12px;font-size:12px;max-height:240px;overflow:auto;margin:8px 0 0;color:#666;">
{caption}         - Image caption (post_excerpt)
{title}           - Image title (post_title)
{article_title}   - Parent post title
{article_content} - Parent post body content (first <?php echo absint( get_option( 'versi_content_limit', 500 ) ); ?> chars; also available as {article_excerpt})
{existing_alt}    - Current alt text in database
{author_style}    - Author's recent writing samples (requires "Match Author Tone" setting)
									</pre>
								</details>
								<details style="margin-top:8px;">
									<summary><?php esc_html_e( 'Default prompt (click to expand)', 'versi-content-tools' ); ?></summary>
									<pre style="background:#f0f0f1;padding:12px;font-size:12px;max-height:240px;overflow:auto;margin:8px 0 0;">
									<?php echo esc_textarea( Versi_Alt_Text_Processor::init()->default_single_prompt() ); ?>
									</pre>
								</details>
							</td>
						</tr>
						<tr data-mode="two-pass">
							<th scope="row">
								<label for="versi_alt_system_prompt"><?php esc_html_e( 'Vision Prompt (Two-Pass)', 'versi-content-tools' ); ?></label>
							</th>
							<td>
								<textarea id="versi_alt_system_prompt" name="versi_alt_system_prompt" rows="12" class="large-text code">
								<?php echo esc_textarea( get_option( 'versi_alt_system_prompt', '' ) ); ?>
								</textarea>
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
{author_style}    - Author's recent writing samples (requires "Match Author Tone" setting)

Usage: Include these placeholders in your prompt text.
Example: "The image is about {article_title}. Visual: {visual_desc}"
									</pre>
								</details>
								<details style="margin-top:8px;">
									<summary><?php esc_html_e( 'Default prompt (click to expand)', 'versi-content-tools' ); ?></summary>
									<pre style="background:#f0f0f1;padding:12px;font-size:12px;max-height:240px;overflow:auto;margin:8px 0 0;">
									<?php echo esc_textarea( Versi_Alt_Text_Processor::init()->default_system_prompt() ); ?>
									</pre>
								</details>
							</td>
						</tr>
						<tr data-mode="two-pass">
							<th scope="row">
								<label for="versi_alt_compare_prompt"><?php esc_html_e( 'Synthesizer Prompt (Two-Pass)', 'versi-content-tools' ); ?></label>
							</th>
							<td>
								<textarea id="versi_alt_compare_prompt" name="versi_alt_compare_prompt" rows="8" class="large-text code">
								<?php echo esc_textarea( get_option( 'versi_alt_compare_prompt', '' ) ); ?>
								</textarea>
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
{author_style}    - Author's recent writing samples (requires "Match Author Tone" setting)
									</pre>
								</details>
								<details style="margin-top:8px;">
									<summary><?php esc_html_e( 'Default prompt (click to expand)', 'versi-content-tools' ); ?></summary>
									<pre style="background:#f0f0f1;padding:12px;font-size:12px;max-height:240px;overflow:auto;margin:8px 0 0;">
									<?php echo esc_textarea( Versi_Alt_Text_Processor::init()->default_compare_prompt() ); ?>
									</pre>
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
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="versi_excerpt_prompt"><?php esc_html_e( 'Custom Prompt', 'versi-content-tools' ); ?></label>
							</th>
							<td>
								<textarea id="versi_excerpt_prompt" name="versi_excerpt_prompt" rows="8" class="large-text code">
								<?php echo esc_textarea( get_option( 'versi_excerpt_prompt', '' ) ); ?>
								</textarea>
								<p class="description"><?php esc_html_e( 'Custom system instruction for excerpt generation. Leave empty for the built-in default.', 'versi-content-tools' ); ?></p>
								<details style="margin-top:8px;">
									<summary><?php esc_html_e( 'Available variables', 'versi-content-tools' ); ?></summary>
									<pre style="background:#f0f0f1;padding:12px;font-size:12px;max-height:240px;overflow:auto;margin:8px 0 0;color:#666;">
{post_content}    - Full post body content (truncated per Content Limit)
{existing_excerpt}- Current excerpt in the database (empty if none)
{target_length}   - Target word count from the setting above
{author_style}    - Author's recent writing samples (requires "Match Author Tone" setting)
									</pre>
								</details>
								<details style="margin-top:8px;">
									<summary><?php esc_html_e( 'Default prompt (click to expand)', 'versi-content-tools' ); ?></summary>
									<pre style="background:#f0f0f1;padding:12px;font-size:12px;max-height:240px;overflow:auto;margin:8px 0 0;">
									<?php echo esc_textarea( Versi_Excerpt_Processor::init()->build_prompt() ); ?>
									</pre>
								</details>
							</td>
						</tr>
					</table>
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
				</div>
			</div>
		</div>

		<script>
		(function($) {
			$('#versi-tabs a').on('click', function(e) {
				e.preventDefault();
				$('#versi-tabs a').removeClass('nav-tab-active');
				$(this).addClass('nav-tab-active');
				$('.versi-tab').hide();
				$($(this).attr('href')).show();
			});

			function toggleMode() {
				var mode = $('input[name="versi_alt_processing_mode"]:checked').val();
				$('tr[data-mode]').addClass('hidden');
				$('tr[data-mode="' + mode + '"]').removeClass('hidden');
			}
			$('input[name="versi_alt_processing_mode"]').on('change', toggleMode);
			toggleMode();

			$('.versi-model-select').each(function() {
				var $select = $(this);
				var savedValue = $select.val();

				$.ajax({
					url: ajaxurl,
					method: 'POST',
					data: {
						action: 'versi_get_models',
						_ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'versi_get_models' ) ); ?>',
					},
					success: function(response) {
						if (!response.success || !response.data) return;
						$.each(response.data, function(i, provider) {
							var $group = $('<optgroup>').attr('label', provider.provider);
							$.each(provider.models, function(j, model) {
								$group.append($('<option>').val(model.id).text(model.name + ' (' + model.id + ')'));
							});
							$select.append($group);
						});
						if (savedValue) $select.val(savedValue);
					},
					error: function() {
						$select.replaceWith('<input type="text" id="' + $select.attr('id') + '" name="' + $select.attr('name') + '" value="' + (savedValue || '') + '" class="regular-text code">');
					}
			});
		});
</script>
		<style>
		tr[data-mode].hidden { display: none; }
		</style>
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

		$workload = isset( $_GET['versi_workload'] ) ? sanitize_key( wp_unslash( $_GET['versi_workload'] ) ) : 'alt';
		$mode_tab = isset( $_GET['versi_mode_tab'] ) ? sanitize_key( wp_unslash( $_GET['versi_mode_tab'] ) ) : 'live';

		$alt_stats = Versi_Alt_Text_Processor::init()->get_stats();
		$exc_stats = Versi_Excerpt_Processor::init()->get_stats();

		$base_url    = admin_url( 'upload.php?page=versi-processing' );
		$alt_url     = add_query_arg( 'versi_workload', 'alt', $base_url );
		$exc_url     = add_query_arg( 'versi_workload', 'excerpt', $base_url );
		$live_url    = add_query_arg( 'versi_mode_tab', 'live', $base_url );
		$bg_url      = add_query_arg( 'versi_mode_tab', 'bg', $base_url );
		$refresh_url = 'alt' === $workload ? $alt_url : $exc_url;

		$job = get_option( 'versi_job_status' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Versi Processing', 'versi-content-tools' ); ?></h1>

			<?php if ( $job && ! empty( $job['is_running'] ) ) : ?>
				<div class="notice notice-info" style="margin: 20px 0;">
					<p><strong><?php esc_html_e( 'Background job running', 'versi-content-tools' ); ?></strong></p>
					<p>
						<?php esc_html_e( 'Progress:', 'versi-content-tools' ); ?>
						<span id="versi-bg-progress"><?php echo esc_html( $job['processed'] ); ?> / <?php echo esc_html( $job['total'] ); ?></span>
						&mdash;
						<?php echo esc_html( $job['workload'] ); ?> / <?php echo esc_html( $job['mode'] ); ?>
					</p>
					<button id="versi-bg-cancel" class="button"><?php esc_html_e( 'Cancel Job', 'versi-content-tools' ); ?></button>
				</div>
				<script>
				jQuery(function($) {
					$('#versi-bg-cancel').on('click', function() {
						$.post(ajaxurl, {
							action: 'versi_cancel_job',
							_ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'versi_process' ) ); ?>',
						}, function() {
							location.reload();
			});

		});
					function poll() {
						$.post(ajaxurl, {
							action: 'versi_job_status',
							_ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'versi_process' ) ); ?>',
						}, function(r) {
							if (r.success && r.data.is_running) {
								$('#versi-bg-progress').text(r.data.processed + ' / ' + r.data.total);
								setTimeout(poll, 3000);
							} else {
								location.reload();
							}
						});
					}
					setTimeout(poll, 3000);
				});
				</script>
			<?php endif; ?>

			<!-- Workload tabs -->
			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $alt_url ); ?>" class="nav-tab <?php echo 'alt' === $workload ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Alt Text', 'versi-content-tools' ); ?>
				</a>
				<a href="<?php echo esc_url( $exc_url ); ?>" class="nav-tab <?php echo 'excerpt' === $workload ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Excerpts', 'versi-content-tools' ); ?>
				</a>
			</h2>

			<!-- Stats bar -->
			<div class="versi-stats" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
				<?php if ( 'alt' === $workload ) : ?>
					<div class="versi-stat" style="background:#f0f6fc;padding:6px 14px;border-radius:4px;">
						<strong><?php echo esc_html( $alt_stats['total'] ); ?></strong>
						<?php esc_html_e( 'total', 'versi-content-tools' ); ?>
					</div>
					<div class="versi-stat" style="background:#fcf0f1;padding:6px 14px;border-radius:4px;">
						<strong><?php echo esc_html( $alt_stats['missing'] ); ?></strong>
						<?php esc_html_e( 'missing', 'versi-content-tools' ); ?>
					</div>
					<div class="versi-stat" style="background:#fef8ee;padding:6px 14px;border-radius:4px;">
						<strong><?php echo esc_html( $alt_stats['too_long'] ); ?></strong>
						<?php esc_html_e( 'over 125 chars', 'versi-content-tools' ); ?>
					</div>
					<div class="versi-stat" style="background:#fef8ee;padding:6px 14px;border-radius:4px;">
						<strong><?php echo esc_html( $alt_stats['too_short'] ); ?></strong>
						<?php esc_html_e( 'under 15 chars', 'versi-content-tools' ); ?>
					</div>
				<?php else : ?>
					<div class="versi-stat" style="background:#f0f6fc;padding:6px 14px;border-radius:4px;">
						<strong><?php echo esc_html( $exc_stats['total'] ); ?></strong>
						<?php esc_html_e( 'total posts', 'versi-content-tools' ); ?>
					</div>
					<div class="versi-stat" style="background:#fcf0f1;padding:6px 14px;border-radius:4px;">
						<strong><?php echo esc_html( $exc_stats['missing'] ); ?></strong>
						<?php esc_html_e( 'missing excerpts', 'versi-content-tools' ); ?>
					</div>
					<div class="versi-stat" style="background:#edfaef;padding:6px 14px;border-radius:4px;">
						<strong><?php echo esc_html( $exc_stats['has_excerpt'] ); ?></strong>
						<?php esc_html_e( 'have excerpts', 'versi-content-tools' ); ?>
					</div>
				<?php endif; ?>
				<a href="<?php echo esc_url( $refresh_url ); ?>" class="button" style="margin-left:auto;">
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
		</div>
		<?php
	}

	/**
	 * Render the Live Process tab: mode buttons, overwrite warning, processing area.
	 *
	 * @param string $workload 'alt' or 'excerpt'.
	 * @return void
	 */
	private function render_live_tab( $workload ) {
		$base_url = admin_url( 'upload.php?page=versi-processing&versi_workload=' . $workload . '&versi_mode_tab=live' );

		if ( 'alt' === $workload ) {
			$safe_label = __( 'Generate Missing Alt Text', 'versi-content-tools' );
			$safe_mode  = 'missing';
			$dest_label = __( 'Regenerate All Alt Text', 'versi-content-tools' );
			$dest_mode  = 'regenerate';
		} else {
			$safe_label = __( 'Generate Missing Excerpts', 'versi-content-tools' );
			$safe_mode  = 'missing';
			$dest_label = __( 'Improve All Excerpts', 'versi-content-tools' );
			$dest_mode  = 'improve';
		}
		?>
		<div id="versi-live-tab">
			<div class="versi-mode-selector" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;align-items:center;">
				<button type="button" class="button button-primary versi-start-btn" data-workload="<?php echo esc_attr( $workload ); ?>" data-mode="<?php echo esc_attr( $safe_mode ); ?>">
					<?php echo esc_html( $safe_label ); ?>
				</button>
				<button type="button" class="button versi-start-btn" data-workload="<?php echo esc_attr( $workload ); ?>" data-mode="<?php echo esc_attr( $dest_mode ); ?>" data-destructive="1">
					<?php echo esc_html( $dest_label ); ?>
				</button>
				<span class="versi-or-text" style="color:#888;font-style:italic;"><?php esc_html_e( 'Choose a mode above to begin.', 'versi-content-tools' ); ?></span>
			</div>

			<!-- Overwrite warning (shown via JS when destructive mode is selected) -->
			<div class="notice notice-warning versi-overwrite-warning" style="display:none;">
				<p>
					<strong><?php esc_html_e( 'Warning:', 'versi-content-tools' ); ?></strong>
					<?php esc_html_e( 'This will overwrite existing content for all items. You can undo individual items after processing using the per-item undo button. Consider running "Generate Missing" first.', 'versi-content-tools' ); ?>
				</p>
			</div>

			<!-- Resume notice (shown if a paused job exists) -->
			<div id="versi-resume-notice" class="notice notice-info" style="display:none;">
				<p>
					<strong><?php esc_html_e( 'Resume previous session?', 'versi-content-tools' ); ?></strong>
					<span id="versi-resume-text"></span>
				</p>
				<p>
					<button type="button" id="versi-resume-btn" class="button button-primary"><?php esc_html_e( 'Resume', 'versi-content-tools' ); ?></button>
					<button type="button" id="versi-dismiss-btn" class="button"><?php esc_html_e( 'Start Fresh', 'versi-content-tools' ); ?></button>
				</p>
			</div>

			<!-- Processing area (hidden until start is clicked) -->
			<div id="versi-processing-area" style="display:none;">
				<div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
					<h2 style="margin:0;padding:0;font-size:1.3em;">
						<?php esc_html_e( 'Processing', 'versi-content-tools' ); ?>&hellip;
					</h2>
					<a href="#" id="versi-stop-link" class="versi-stop-link" style="color:#d63638;text-decoration:none;font-size:13px;">
						<?php esc_html_e( 'stop', 'versi-content-tools' ); ?>
					</a>
				</div>
				<div id="versi-status" style="margin:8px 0;font-size:13px;color:#555;"></div>
				<div id="versi-results" style="background:#fff;border:1px solid #c3c4c7;padding:12px;max-height:600px;overflow-y:auto;font-family:monospace;font-size:13px;line-height:1.6;"></div>
			</div>
		</div>

		<script>
		jQuery(function($) {
			var $modeBtns = $('.versi-start-btn');
			var $warning = $('.versi-overwrite-warning');
			var $processingArea = $('#versi-processing-area');
			var $resumeNotice = $('#versi-resume-notice');
			var $stopLink = $('#versi-stop-link');
			var $status = $('#versi-status');
			var $results = $('#versi-results');
			var $orText = $('.versi-or-text');
			var running = false;
			var mode = '';
			var total = 0;
			var done = 0;
			var offset = 0;
			var catId = 0;
			var batchSize = <?php echo absint( get_option( 'versi_batch_size', 5 ) ); ?>;
			var resultsData = [];
			var workload = '<?php echo esc_js( $workload ); ?>';
			var stopRequested = false;
			var $resumeText = $('#versi-resume-text');
			var startTime = 0;
			var itemDurations = [];
			var etaTimer = null;

			// Check for saved job
			$.ajax({
				url: ajaxurl,
				method: 'POST',
				data: {
					action: 'versi_load_job',
					_ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'versi_process' ) ); ?>',
				},
				success: function(response) {
					if (!response.success || !response.data.exists) return;
					var job = response.data.data;
					if (job.workload !== workload) return;

					mode = job.mode;
					offset = job.offset;
					total = job.total;
					done = job.done;
					
					$resumeNotice.show();
					var msg = <?php echo json_encode( __( 'You have a paused job (%1$s mode, %2$s/%3$s items processed).', 'versi-content-tools' ) ); ?>;
					$resumeText.text(msg.replace('%1$s', mode).replace('%2$s', done).replace('%3$s', total));
				}
			});

			$('#versi-resume-btn').on('click', function() {
				$resumeNotice.hide();
				$processingArea.show();
				$orText.hide();
				$status.text('<?php echo esc_js( __( 'Resuming...', 'versi-content-tools' ) ); ?>');
				$stopLink.show();
				running = true;
				startTime = Date.now();
				itemDurations = [];
				if (etaTimer) clearInterval(etaTimer);
				etaTimer = setInterval(updateEtaStatus, 5000);
				fetchBatch();
			});

			$('#versi-dismiss-btn').on('click', function() {
				$resumeNotice.hide();
				dismissSavedJob();
			});

			function saveJobState(status) {
				$.ajax({
					url: ajaxurl,
					method: 'POST',
					data: {
						action: 'versi_save_job',
						_ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'versi_process' ) ); ?>',
						workload: workload,
						mode: mode,
						offset: offset,
						total: total,
						done: done,
						status: status,
					},
				});
			}

			function dismissSavedJob() {
				$.ajax({
					url: ajaxurl,
					method: 'POST',
					data: {
						action: 'versi_dismiss_job',
						_ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'versi_process' ) ); ?>',
					},
				});
			}

			$modeBtns.on('click', function() {
				console.log('Button clicked');
				var $btn = $(this);
				mode = $btn.data('mode');

				if ($btn.data('destructive') && !confirm('<?php echo esc_js( __( 'This will overwrite existing content. Are you sure?', 'versi-content-tools' ) ); ?>')) {
					return;
				}

				dismissSavedJob();
				$processingArea.show();
				$resumeNotice.hide();
				$orText.hide();
				$results.empty();
				$status.text('<?php echo esc_js( __( 'Starting...', 'versi-content-tools' ) ); ?>');
				$stopLink.show();
				resultsData = [];
				running = true;
				stopRequested = false;
				done = 0;
				offset = 0;
				startTime = Date.now();
				itemDurations = [];
				if (etaTimer) clearInterval(etaTimer);
				etaTimer = setInterval(updateEtaStatus, 5000);

				console.log('Calling fetchBatch');
				fetchBatch();
			});

			$stopLink.on('click', function(e) {
				e.preventDefault();
				if (!running) return;
				stopRequested = true;
				running = false;
				$stopLink.hide();
				var ok = 0, errs = 0;
				resultsData.forEach(function(r) {
					if (r.status === 'success') ok++;
					else if (r.status === 'error') errs++;
				});
				var summary = '<?php echo esc_js( __( 'Stopped.', 'versi-content-tools' ) ); ?> ' + done + ' / ' + total +
					' (ok: ' + ok + (errs > 0 ? ', errors: ' + errs : '') + ')';
				$status.text(summary);
				saveJobState('paused');
				if (etaTimer) clearInterval(etaTimer);
			});

			function updateSummary() {
				$stopLink.hide();
				if (etaTimer) clearInterval(etaTimer);
				var ok = 0, errs = 0;
				resultsData.forEach(function(r) {
					if (r.status === 'success') ok++;
					else if (r.status === 'error') errs++;
				});
				$status.text('<?php echo esc_js( __( 'Complete.', 'versi-content-tools' ) ); ?> ' + ok + ' ok' + (errs > 0 ? ', ' + errs + ' errors' : ''));
				dismissSavedJob();
			}

			function getActionName(prefix) {
				return workload === 'alt' ? 'versi_alt_' + prefix : 'versi_excerpt_' + prefix;
			}

			function truncateText(text, maxLen) {
				if (!text || text.length <= maxLen) return text;
				return text.substring(0, maxLen) + '…';
			}

			function makeBodyText(r, full) {
				var maxLen = full ? Infinity : 150;
				var label = r.title ? r.title + ' ' : '';
				if (r.status === 'success') {
					var cur = r.previous ? truncateText(r.previous, maxLen) : '';
					var gen = truncateText(r.generated || '', maxLen);
					if (r.changed && cur) {
						return '#' + r.id + ' ' + label + '→ REPLACED\n  was: "' + cur + '"\n  now: "' + gen + '"';
					} else if (r.changed) {
						return '#' + r.id + ' ' + label + '+ ADDED\n  value: "' + gen + '"';
					} else {
						return '#' + r.id + ' ' + label + '✓ KEPT\n  value: "' + gen + '"';
					}
				} else if (r.status === 'error') {
					return '#' + r.id + ' ' + label + '✗ ' + (r.error || 'Error');
				}
				return '#' + r.id + ' ' + label + '— ' + (r.reason || 'Skipped');
			}

			function addEntry(r) {
				var $entry = $('<div class="versi-entry" style="display:flex;align-items:flex-start;gap:8px;padding:4px 6px;margin:1px 0;border-radius:2px;">');

				if (workload === 'alt') {
					var thumbUrl = r.thumbnail || '';
					if (thumbUrl) {
						$entry.append('<img src="' + thumbUrl + '" style="width:40px;height:40px;object-fit:cover;border-radius:2px;flex-shrink:0;margin-top:2px;">');
					} else {
						$entry.append('<span style="width:40px;height:40px;flex-shrink:0;background:#f0f0f1;border-radius:2px;display:inline-block;"></span>');
					}
				}

				var $body = $('<div style="flex:1;white-space:pre-wrap;word-break:break-word;">');

				if (r.status === 'success') {
					var curFull = r.previous || '';
					var genFull = r.generated || '';
					var needsExpand = curFull.length > 150 || genFull.length > 150;

					var shortText = makeBodyText(r, false);
					$body.text(shortText);

					if (needsExpand) {
						$body.append(' <a href="#" class="versi-expand" data-full="' + encodeURIComponent(makeBodyText(r, true)) + '" data-short="' + encodeURIComponent(shortText) + '" style="font-size:11px;color:#2271b1;text-decoration:underline;white-space:nowrap;">show more</a>');
					}

					if (r.changed && curFull) {
						$entry.css('background', '#edfaef').css('border-left', '3px solid #00a32a');
					} else if (r.changed) {
						$entry.css('background', '#edfaef').css('border-left', '3px solid #00a32a');
					} else {
						$entry.css('background', '#fef8ee').css('border-left', '3px solid #dba617');
					}
				} else if (r.status === 'error') {
					$body.text(makeBodyText(r, false));
					$entry.css('background', '#fcf0f1').css('border-left', '3px solid #d63638');
				} else {
					$body.text(makeBodyText(r, false));
					$entry.css('background', '#f6f7f7').css('border-left', '3px solid #c3c4c7');
				}

				$entry.append($body);

				if (r.status === 'success' && r.previous !== undefined) {
					$entry.append(
						'<button class="versi-redo-btn" data-attachment-id="' + r.id + '" style="flex-shrink:0;font-size:11px;padding:1px 6px;cursor:pointer;background:none;border:1px solid #c3c4c7;border-radius:2px;color:#2271b1;">redo</button>' +
						'<button class="versi-undo-btn" data-attachment-id="' + r.id + '" data-previous="' + (r.previous || '').replace(/"/g, '&quot;') + '" style="flex-shrink:0;font-size:11px;padding:1px 6px;cursor:pointer;background:none;border:1px solid #c3c4c7;border-radius:2px;color:#2271b1;">undo</button>'
					);
				}

				$entry.data('attachment-id', r.id);
				$results.append($entry);
				$results.scrollTop($results[0].scrollHeight);
			}

			function formatEta(ms) {
				if (ms <= 0) return '';
				var totalSec = Math.ceil(ms / 1000);
				var min = Math.floor(totalSec / 60);
				var sec = totalSec % 60;
				if (min >= 60) {
					var hr = Math.floor(min / 60);
					min = min % 60;
					return hr + 'h ' + min + 'm remaining';
				}
				if (min > 0) return min + 'm ' + sec + 's remaining';
				return sec + 's remaining';
			}

			function updateEtaStatus() {
				var remaining = total - done;
				var eta = '';
				if (itemDurations.length > 0 && remaining > 0) {
					var avg = itemDurations.reduce(function(a, b) { return a + b; }, 0) / itemDurations.length;
					eta = ' — ' + formatEta(avg * remaining);
				}
				$status.text('Processing — ' + (done + 1) + ' / ' + total + eta);
			}

			function processId(id, cb) {
				var itemStart = Date.now();
				updateEtaStatus();

				$.ajax({
					url: ajaxurl,
					method: 'POST',
					data: {
						action: getActionName('process_single'),
						_ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'versi_process' ) ); ?>',
						id: id,
						mode: mode,
					},
					success: function(response) {
						if (stopRequested) return;
						var r = response.data;
						resultsData.push(r);
						addEntry(r);
					},
					error: function() {
						if (stopRequested) return;
						resultsData.push({ id: id, status: 'error' });
						addEntry({ id: id, title: '', status: 'error', error: '<?php echo esc_js( __( 'Request failed', 'versi-content-tools' ) ); ?>' });
					},
					complete: function() {
						done++;
						var elapsed = Date.now() - itemStart;
						itemDurations.push(elapsed);
						if (itemDurations.length > 10) itemDurations.shift();
						cb();
					},
				});
			}

			function processBatch(ids, cb) {
				if (!running || ids.length === 0) {
					cb();
					return;
				}

				var i = 0;
				function nextInBatch() {
					if (!running || i >= ids.length) {
						cb();
						return;
					}
					processId(ids[i], function() {
						i++;
						setTimeout(nextInBatch, 300);
					});
				}
				nextInBatch();
			}

			function fetchBatch() {
				if (!running) {
					console.log('fetchBatch: not running');
					return;
				}
				console.log('Fetching batch...');

				$.ajax({
					url: ajaxurl,
					method: 'POST',
					data: {
						action: getActionName('get_ids'),
						_ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'versi_process' ) ); ?>',
						mode: mode,
						catId: catId,
						offset: offset,
						batch: batchSize,
					},
					success: function(response) {
						console.log('fetchBatch: success', response);
						if (stopRequested) return;

						var d = response.data;
						total = d.total;
						var ids = d.ids || [];

						if (ids.length === 0) {
							running = false;
							updateSummary();
							return;
						}

						processBatch(ids, function() {
							if (stopRequested) return;
							offset += ids.length;
							saveJobState('paused');
							setTimeout(fetchBatch, 100);
						});
					},
					error: function(xhr, status, error) {
						console.log('fetchBatch: error', error);
						if (stopRequested) return;
						running = false;
						$stopLink.hide();
						$status.text('<?php echo esc_js( __( 'Failed to fetch item list.', 'versi-content-tools' ) ); ?>');
					},
				});
			}

			// Redo / Undo
			$results.on('click', '.versi-redo-btn', function() {
				var $btn = $(this);
				var $entry = $btn.closest('.versi-entry');
				var id = $entry.data('attachment-id');
				if (!id) return;

				$btn.text('...').prop('disabled', true);
				$entry.css('opacity', '0.5');

				$.ajax({
					url: ajaxurl,
					method: 'POST',
					data: {
						action: getActionName('process_single'),
						_ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'versi_process' ) ); ?>',
						id: id,
						mode: mode,
					},
					success: function(response) {
						var r = response.data;
						var $newEntry = $('<div class="versi-entry" style="display:flex;align-items:flex-start;gap:8px;padding:4px 6px;margin:1px 0;border-radius:2px;">');

						if (workload === 'alt') {
							var thumbUrl = r.thumbnail || '';
							if (thumbUrl) {
								$newEntry.append('<img src="' + thumbUrl + '" style="width:40px;height:40px;object-fit:cover;border-radius:2px;flex-shrink:0;margin-top:2px;">');
							} else {
								$newEntry.append('<span style="width:40px;height:40px;flex-shrink:0;background:#f0f0f1;border-radius:2px;display:inline-block;"></span>');
							}
						}

						var $body = $('<div style="flex:1;white-space:pre-wrap;word-break:break-word;">');
						var curFull = r.previous || '';
						var genFull = r.generated || '';
						var needsExpand = curFull.length > 150 || genFull.length > 150;

						$body.text(makeBodyText(r, false));

						if (needsExpand) {
							$body.append(' <a href="#" class="versi-expand" data-full="' + encodeURIComponent(makeBodyText(r, true)) + '" data-short="' + encodeURIComponent(makeBodyText(r, false)) + '" style="font-size:11px;color:#2271b1;text-decoration:underline;white-space:nowrap;">show more</a>');
						}

						if (r.changed && curFull) {
							$newEntry.css('background', '#edfaef').css('border-left', '3px solid #00a32a');
						} else if (r.changed) {
							$newEntry.css('background', '#edfaef').css('border-left', '3px solid #00a32a');
						} else {
							$newEntry.css('background', '#fef8ee').css('border-left', '3px solid #dba617');
						}

						$newEntry.append($body);

						if (r.previous !== undefined) {
							$newEntry.append(
								'<button class="versi-redo-btn" data-attachment-id="' + r.id + '" style="flex-shrink:0;font-size:11px;padding:1px 6px;cursor:pointer;background:none;border:1px solid #c3c4c7;border-radius:2px;color:#2271b1;">redo</button>' +
								'<button class="versi-undo-btn" data-attachment-id="' + r.id + '" data-previous="' + (r.previous || '').replace(/"/g, '&quot;') + '" style="flex-shrink:0;font-size:11px;padding:1px 6px;cursor:pointer;background:none;border:1px solid #c3c4c7;border-radius:2px;color:#2271b1;">undo</button>'
							);
						}
						$newEntry.data('attachment-id', r.id);
						$entry.replaceWith($newEntry);
					},
					error: function() {
						$btn.text('redo').prop('disabled', false);
						$entry.css('opacity', '1');
					},
				});
			});

			$results.on('click', '.versi-undo-btn', function() {
				var $btn = $(this);
				var $entry = $btn.closest('.versi-entry');
				var id = $btn.data('attachment-id');
				var prev = $btn.data('previous');
				if (!id) return;

				$btn.text('...').prop('disabled', true);
				$entry.css('opacity', '0.5');

				$.ajax({
					url: ajaxurl,
					method: 'POST',
					data: {
						action: getActionName('undo'),
						_ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'versi_process' ) ); ?>',
						id: id,
						alt: prev,
					},
					success: function(response) {
						var r = response.data;
						$entry.css('opacity', '1');
						$entry.css('background', '#f6f7f7').css('border-left', '3px solid #c3c4c7');
						$entry.find('.versi-redo-btn').remove();
						$entry.find('.versi-undo-btn').remove();
						$entry.find('div:last').text('#' + r.id + ' (Reverted to: "' + r.alt.substring(0, 100) + '")');
					},
					error: function() {
						$btn.text('undo').prop('disabled', false);
						$entry.css('opacity', '1');
					},
				});
			});

			$results.on('click', '.versi-expand', function(e) {
				e.preventDefault();
				var $link = $(this);
				var $body = $link.parent();
				var isExpanded = $link.data('expanded');
				if (!isExpanded) {
					$body.empty();
					$body.text(decodeURIComponent($link.data('full')));
					$link = $('<a href="#" class="versi-expand" data-full="' + $link.data('full') + '" data-expanded="1" style="font-size:11px;color:#2271b1;text-decoration:underline;white-space:nowrap;">show less</a>');
					$body.append(' ', $link);
				} else {
					$body.empty();
					$body.text(decodeURIComponent($link.data('short')));
					$link = $('<a href="#" class="versi-expand" data-full="' + $link.data('full') + '" data-short="' + $link.data('short') + '" style="font-size:11px;color:#2271b1;text-decoration:underline;white-space:nowrap;">show more</a>');
					$body.append(' ', $link);
				}
			});
		});
	</script>
		<?php
	}

	/**
	 * Render the Background Jobs tab.
	 *
	 * @param string $workload 'alt' or 'excerpt'.
	 * @return void
	 */
	private function render_background_tab( $workload ) {
		$job = get_option( 'versi_job_status', false );
		if ( 'alt' === $workload ) {
			$safe_label = __( 'Fill Missing Alt Text', 'versi-content-tools' );
			$safe_mode  = 'missing';
			$dest_label = __( 'Regenerate All Alt Text', 'versi-content-tools' );
			$dest_mode  = 'regenerate';
		} else {
			$safe_label = __( 'Generate Missing Excerpts', 'versi-content-tools' );
			$safe_mode  = 'missing';
			$dest_label = __( 'Improve All Excerpts', 'versi-content-tools' );
			$dest_mode  = 'improve';
		}
		?>
		<div id="versi-bg-tab">
			<?php if ( $job && ! empty( $job['is_running'] ) ) : ?>
				<div class="notice notice-info">
					<p><strong><?php esc_html_e( 'Background job running', 'versi-content-tools' ); ?></strong></p>
					<p>
						<?php esc_html_e( 'Progress:', 'versi-content-tools' ); ?>
						<span id="versi-bg-progress"><?php echo esc_html( $job['processed'] ); ?> / <?php echo esc_html( $job['total'] ); ?></span>
						&mdash;
						<?php echo esc_html( $job['workload'] ); ?> / <?php echo esc_html( $job['mode'] ); ?>
					</p>
					<button id="versi-bg-cancel" class="button"><?php esc_html_e( 'Cancel Job', 'versi-content-tools' ); ?></button>
				</div>
				<script>
				jQuery(function($) {
					function poll() {
						$.post(ajaxurl, {
							action: 'versi_job_status',
							_ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'versi_job_status' ) ); ?>'
						}, function(resp) {
							if (resp.success && resp.data) {
								$('#versi-bg-progress').text(resp.data.processed + ' / ' + resp.data.total);
								if (resp.data.is_running) {
									setTimeout(poll, 3000);
								} else {
									$('#versi-bg-tab .notice-info').removeClass('notice-info').addClass('notice-success')
										.append('<p><em><?php esc_html_e( 'Complete!', 'versi-content-tools' ); ?></em></p>');
								}
							}
						});
					}
					poll();
					$('#versi-bg-cancel').on('click', function() {
						$.post(ajaxurl, {
							action: 'versi_cancel_job',
							_ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'versi_cancel_job' ) ); ?>'
						});
						$(this).prop('disabled', true).text('<?php esc_html_e( 'Cancelling...', 'versi-content-tools' ); ?>');
					});
				});
				</script>
			<?php else : ?>
				<p><?php esc_html_e( 'No active background job. Start a new one:', 'versi-content-tools' ); ?></p>
				<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
					<button type="button" class="button button-primary versi-bg-start-btn" data-workload="<?php echo esc_attr( $workload ); ?>" data-mode="<?php echo esc_attr( $safe_mode ); ?>">
						<?php echo esc_html( $safe_label ); ?>
					</button>
					<button type="button" class="button versi-bg-start-btn" data-workload="<?php echo esc_attr( $workload ); ?>" data-mode="<?php echo esc_attr( $dest_mode ); ?>">
						<?php echo esc_html( $dest_label ); ?>
					</button>
				</div>
				<script>
				jQuery(function($) {
					$('.versi-bg-start-btn').on('click', function() {
						var $btn = $(this);
						var btnMode = $btn.data('mode');
						var btnWorkload = $btn.data('workload');
						if (!confirm('<?php echo esc_js( __( 'Start background processing? You can close the browser and check back later.', 'versi-content-tools' ) ); ?>')) {
							return;
						}
						$.post(ajaxurl, {
							action: 'versi_create_job',
							_ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'versi_process' ) ); ?>',
							mode: btnMode,
							workload: btnWorkload,
						});
						$btn.prop('disabled', true).text('<?php esc_html_e( 'Started', 'versi-content-tools' ); ?>');
						$('.versi-bg-start-btn').not($btn).prop('disabled', true);
					});
				});
				</script>
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// AJAX Handlers
	// -------------------------------------------------------------------------

	/**
	 * AJAX: process a single image for alt text.
	 *
	 * @return void
	 */
	public function ajax_alt_process_single() {
		check_ajax_referer( 'versi_process' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

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
		check_ajax_referer( 'versi_process' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

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
		check_ajax_referer( 'versi_process' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

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
	 * AJAX: process a single post for excerpt.
	 *
	 * @return void
	 */
	public function ajax_excerpt_process_single() {
		check_ajax_referer( 'versi_process' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

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
		check_ajax_referer( 'versi_process' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

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
		check_ajax_referer( 'versi_process' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

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
		check_ajax_referer( 'versi_process' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$mode     = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'missing';
		$workload = isset( $_POST['workload'] ) ? sanitize_key( $_POST['workload'] ) : 'alt';

		$total  = 0;
		$cat_id = 0;

		if ( 'alt' === $workload ) {
			$cat_id = absint( get_option( 'versi_alt_cat_filter', 0 ) );
			$ids    = Versi_Processor::init()->get_image_ids( $mode, 0, 1, $cat_id );
			$total  = $ids['total'];
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

		wp_schedule_single_event( time() + 5, 'versi_process_batch' );

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
		check_ajax_referer( 'versi_job_status' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$job = get_option( 'versi_job_status', false );
		if ( ! $job ) {
			wp_send_json_error( 'No job found' );
		}

		wp_send_json_success( $job );
	}

	/**
	 * AJAX: cancel background job.
	 *
	 * @return void
	 */
	public function ajax_cancel_job() {
		check_ajax_referer( 'versi_cancel_job' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

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
		check_ajax_referer( 'versi_process' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

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
		check_ajax_referer( 'versi_process' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

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
		check_ajax_referer( 'versi_process' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		delete_option( 'versi_live_job_status' );
		wp_send_json_success( array( 'dismissed' => true ) );
	}

	/**
	 * Process a background batch (called via cron).
	 *
	 * @return void
	 */
	public function process_background_batch() {
		$job = get_option( 'versi_job_status', false );
		if ( ! $job || empty( $job['is_running'] ) ) {
			return;
		}

		$shared   = Versi_Processor::init();
		$alt_proc = Versi_Alt_Text_Processor::init();
		$exc_proc = Versi_Excerpt_Processor::init();

		$batch    = absint( get_option( 'versi_batch_size', 5 ) );
		$workload = $job['workload'];
		$mode     = $job['mode'];

		$ids_result = array( 'ids' => array() );

		if ( 'alt' === $workload ) {
			$ids_result = $shared->get_image_ids( $mode, $job['offset'], $batch, $job['cat_id'] );
		} else {
			$ids_result = $shared->get_excerpt_ids( $mode, $job['offset'], $batch );
		}

		if ( empty( $ids_result['ids'] ) ) {
			$job['is_running'] = false;
			$job['completed']  = true;
			$job['updated_at'] = time();
			update_option( 'versi_job_status', $job, false );
			return;
		}

		foreach ( $ids_result['ids'] as $id ) {
			if ( 'alt' === $workload ) {
				$result = $alt_proc->process_single( $id );
			} else {
				$result = $exc_proc->process_single( $id );
			}
			++$job['processed'];

			if ( 'error' === $result['status'] ) {
				++$job['failed'];
			}
		}

		$job['offset']     = $job['offset'] + count( $ids_result['ids'] );
		$job['updated_at'] = time();

		if ( $job['processed'] >= $job['total'] ) {
			$job['is_running'] = false;
			$job['completed']  = true;
		}

		update_option( 'versi_job_status', $job, false );

		if ( $job['is_running'] ) {
			wp_schedule_single_event( time() + 5, 'versi_process_batch' );
		}
	}

	// -------------------------------------------------------------------------
	// Auto-generate on upload / save
	// -------------------------------------------------------------------------

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

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return;
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( ! is_string( $mime ) || ! str_starts_with( $mime, 'image/' ) ) {
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
	 * Auto-generate excerpt on post save.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an update.
	 * @return void
	 */
	public function excerpt_auto_generate_on_save( $post_id, $post, $update ) {
		if ( '1' !== get_option( 'versi_excerpt_auto_generate', '0' ) ) {
			return;
		}

		if ( 'post' !== $post->post_type ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			return;
		}

		if ( ! empty( $post->post_excerpt ) ) {
			return;
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return;
		}

		Versi_Excerpt_Processor::init()->process_single( $post_id );
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
					<?php echo $thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
			var orig = wp.media.view.Attachment.Library;
			if (!orig) return;
			wp.media.view.Attachment.Library = orig.extend({
				render: function() {
					var r = orig.prototype.render.apply(this, arguments);
					if (this.model && this.model.get('versi_generated')) {
						var alt = this.model.get('versi_generated');
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
