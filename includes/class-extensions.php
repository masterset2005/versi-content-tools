<?php
/**
 * Third-party plugin integrations for Versi Content Tools.
 *
 * Detects installed plugins and provides toggle-able integrations
 * that extend prompts with plugin-specific data (e.g. SEO focus keyphrases).
 *
 * @package Versi_Content_Tools
 */

defined( 'ABSPATH' ) || exit;

/**
 * Discovers and manages integrations with third-party WordPress plugins.
 */
class Versi_Extensions {

	use Versi_Singleton;

	/**
	 * Populated by generate_focus_keywords() when the last call was rate-limited.
	 *
	 * @var array{retry_after: float}|null
	 */
	public static ?array $last_rate_limit = null;

	/**
	 * Discovered integrations.
	 *
	 * @var array
	 */
	private array $integrations = array();

	/**
	 * Hook into WordPress.
	 */
	private function __construct() {
		add_action( 'wp_loaded', array( $this, 'discover' ), 1 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Scan for installed plugins and register integrations.
	 *
	 * @return void
	 */
	public function discover(): void {
		if ( ! empty( $this->integrations ) ) {
			return;
		}
		// SmartCrawl Pro / SmartCrawl.
		if ( defined( 'SMARTCRAWL_VERSION' ) ) {
			$this->integrations['smartcrawl'] = array(
				'id'       => 'smartcrawl',
				'name'     => 'SmartCrawl',
				'slug'     => 'smartcrawl-seo/wpmu-dev-seo.php',
				'detected' => true,
				'desc'     => __( 'SEO plugin by WPMU DEV.', 'versi-content-tools' ),
				'meta_key' => '_wds_focus-keywords',
				'toggles'  => array(
					'focus_keywords' => array(
						'id'    => 'versi_ext_smartcrawl_focus',
						'label' => __( 'Inject focus keyphrases into prompts', 'versi-content-tools' ),
						'desc'  => __( 'Make SmartCrawl focus keyphrases available as {focus_keywords} in alt-text and excerpt prompts.', 'versi-content-tools' ),
					),
					'auto_focus'     => array(
						'id'    => 'versi_ext_smartcrawl_auto_focus',
						'label' => __( 'AI-generate and save focus keyphrases', 'versi-content-tools' ),
						'desc'  => __( 'When processing a post, auto-generate focus keyphrases via AI and save them to SmartCrawl.', 'versi-content-tools' ),
					),
				),
			);
		}

		// Yoast SEO.
		if ( defined( 'WPSEO_VERSION' ) ) {
			$this->integrations['yoast'] = array(
				'id'       => 'yoast',
				'name'     => 'Yoast SEO',
				'slug'     => 'wordpress-seo/wp-seo.php',
				'detected' => true,
				'desc'     => __( 'Popular SEO framework.', 'versi-content-tools' ),
				'meta_key' => '_yoast_wpseo_focuskw',
				'toggles'  => array(
					'focus_keywords' => array(
						'id'    => 'versi_ext_yoast_focus',
						'label' => __( 'Inject focus keyphrases into prompts', 'versi-content-tools' ),
						'desc'  => __( 'Make Yoast focus keyphrases available as {focus_keywords} in alt-text and excerpt prompts.', 'versi-content-tools' ),
					),
					'auto_focus'     => array(
						'id'    => 'versi_ext_yoast_auto_focus',
						'label' => __( 'AI-generate and save focus keyphrases', 'versi-content-tools' ),
						'desc'  => __( 'When processing a post, auto-generate focus keyphrases via AI and save them to Yoast SEO.', 'versi-content-tools' ),
					),
				),
			);
		}

		// Rank Math SEO.
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$this->integrations['rankmath'] = array(
				'id'       => 'rankmath',
				'name'     => 'Rank Math SEO',
				'slug'     => 'seo-by-rank-math/rank-math.php',
				'detected' => true,
				'desc'     => __( 'Advanced SEO plugin with rich snippet support.', 'versi-content-tools' ),
				'meta_key' => 'rank_math_focus_keyword',
				'toggles'  => array(
					'focus_keywords' => array(
						'id'    => 'versi_ext_rankmath_focus',
						'label' => __( 'Inject focus keyphrases into prompts', 'versi-content-tools' ),
						'desc'  => __( 'Make Rank Math focus keyphrases available as {focus_keywords} in alt-text and excerpt prompts.', 'versi-content-tools' ),
					),
					'auto_focus'     => array(
						'id'    => 'versi_ext_rankmath_auto_focus',
						'label' => __( 'AI-generate and save focus keyphrases', 'versi-content-tools' ),
						'desc'  => __( 'When processing a post, auto-generate focus keyphrases via AI and save them to Rank Math.', 'versi-content-tools' ),
					),
				),
			);
		}

		// SEOPress.
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			$this->integrations['seopress'] = array(
				'id'       => 'seopress',
				'name'     => 'SEOPress',
				'slug'     => 'wp-seopress/seopress.php',
				'detected' => true,
				'desc'     => __( 'All-in-one SEO plugin.', 'versi-content-tools' ),
				'meta_key' => '_seopress_analysis_target_kw',
				'toggles'  => array(
					'focus_keywords' => array(
						'id'    => 'versi_ext_seopress_focus',
						'label' => __( 'Inject focus keyphrases into prompts', 'versi-content-tools' ),
						'desc'  => __( 'Make SEOPress target keywords available as {focus_keywords} in alt-text and excerpt prompts.', 'versi-content-tools' ),
					),
					'auto_focus'     => array(
						'id'    => 'versi_ext_seopress_auto_focus',
						'label' => __( 'AI-generate and save focus keyphrases', 'versi-content-tools' ),
						'desc'  => __( 'When processing a post, auto-generate focus keyphrases via AI and save them to SEOPress.', 'versi-content-tools' ),
					),
				),
			);
		}

		// WooCommerce.
		if ( defined( 'WC_VERSION' ) ) {
			$this->integrations['woocommerce'] = array(
				'id'       => 'woocommerce',
				'name'     => 'WooCommerce',
				'slug'     => 'woocommerce/woocommerce.php',
				'detected' => true,
				'desc'     => __( 'E-commerce platform.', 'versi-content-tools' ),
				'meta_key' => '',
				'toggles'  => array(
					'product_context' => array(
						'id'    => 'versi_ext_woocommerce_product',
						'label' => __( 'Include product context in prompts', 'versi-content-tools' ),
						'desc'  => __( 'Pass product SKU, price, and description into the article context when processing product images.', 'versi-content-tools' ),
					),
				),
			);
		}
	}

	/**
	 * Return all discovered integrations.
	 *
	 * @return array
	 */
	public function get_detected_plugins(): array {
		return $this->integrations;
	}

	/**
	 * Check whether any detected plugin has at least one toggle active.
	 *
	 * @return bool
	 */
	public function has_active_integrations(): bool {
		foreach ( $this->integrations as $plugin ) {
			foreach ( $plugin['toggles'] as $toggle ) {
				if ( '1' === get_option( $toggle['id'], '0' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Check whether a specific toggle is enabled.
	 *
	 * @param string $plugin_id Plugin identifier (e.g. 'yoast').
	 * @param string $toggle_id Toggle identifier (e.g. 'focus_keywords').
	 * @return bool
	 */
	public function is_toggle_active( string $plugin_id, string $toggle_id ): bool {
		$plugin = $this->integrations[ $plugin_id ] ?? null;
		if ( ! $plugin ) {
			return false;
		}
		$toggle = $plugin['toggles'][ $toggle_id ] ?? null;
		if ( ! $toggle ) {
			return false;
		}
		return '1' === get_option( $toggle['id'], '0' );
	}

	/**
	 * Register extensions settings.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'versi_settings',
			'versi_seo_text_model',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( Versi_Admin::init(), 'sanitize_model_preference' ),
				'default'           => '',
			)
		);

		register_setting(
			'versi_settings',
			'versi_seo_prompt',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			)
		);
		foreach ( $this->integrations as $plugin ) {
			foreach ( $plugin['toggles'] as $toggle ) {
				register_setting(
					'versi_settings',
					$toggle['id'],
					array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '0',
					)
				);
			}
		}
	}

	/**
	 * Return the built-in default SEO prompt.
	 *
	 * @return string
	 */
	public static function default_prompt(): string {
		return 'You are an SEO keyword optimizer. Your job is to convert legacy article titles and content into modern, rankable keyword clusters. Each cluster must contain exactly three keyphrases:'
			. "\n\n" . 'primary'
			. "\n" . 'secondary'
			. "\n" . 'support'
			. "\n\n" . 'RULES:'
			. "\n" . '- Short: 2 to 5 words each.'
			. "\n" . '- One intent per cluster: all three keyphrases must match the article\'s core topic.'
			. "\n" . '- Natural: write keyphrases exactly how real people search on Google.'
			. "\n" . '- Tight: no clunky chains, no stacked concepts, no multi-topic phrases.'
			. "\n" . '- Avoid filler words ("very", "really", "helpful", etc.).'
			. "\n" . '- Avoid weak stop words: do not use "a", "an", "the", "and", "or".'
			. "\n" . '- Allowed stop words ONLY when needed for natural search phrasing: "for", "to", "in", "with", "on", "by".'
			. "\n\n" . 'BAD keyword (stacked):'
			. "\n" . 'how to travel with disabled children tips guides'
			. "\n\n" . 'GOOD keyword (tight):'
			. "\n" . 'travel tips for disabled children'
			. "\n\n" . 'BAD cluster (mixed topics):'
			. "\n" . 'vacation planning'
			. "\n" . 'GFCF diet'
			. "\n" . 'hydrocephalus treatment'
			. "\n\n" . 'GOOD cluster (same intent):'
			. "\n" . 'GFCF diet benefits'
			. "\n" . 'casein free recipes'
			. "\n" . 'GFCF meal planning'
			. "\n\n" . 'OUTPUT FORMAT:'
			. "\n" . 'Write ONE keyphrase per line in this exact order:'
			. "\n" . 'primary'
			. "\n" . 'secondary'
			. "\n" . 'support'
			. "\n\n" . 'No numbers, no bullets, no labels, no emojis, no commentary, no extra text.';
	}

	/**
	 * Render the Extensions settings tab.
	 *
	 * @return void
	 */
	public function render_tab(): void {
		?>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="versi_seo_text_model"><?php esc_html_e( 'SEO Keywords Model', 'versi-content-tools' ); ?></label>
				</th>
				<td>
					<select id="versi_seo_text_model" name="versi_seo_text_model" class="regular-text versi-model-select" style="max-width:400px;">
						<option value=""><?php esc_html_e( '- Default -', 'versi-content-tools' ); ?></option>
						<?php
						$saved = get_option( 'versi_seo_text_model', '' );
						if ( '' !== $saved ) {
							echo '<option value="' . esc_attr( $saved ) . '" selected>' . esc_html( $saved ) . '</option>';
						}
						?>
					</select>
					<br>
					<select id="versi_seo_text_fallback" name="versi_seo_text_fallback" aria-label="<?php esc_attr_e( 'SEO model fallback', 'versi-content-tools' ); ?>" class="regular-text versi-model-select" style="max-width:400px;margin-top:4px;">
						<option value=""><?php esc_html_e( '- No Fallback -', 'versi-content-tools' ); ?></option>
						<?php
						$saved_fb = get_option( 'versi_seo_text_fallback', '' );
						if ( '' !== $saved_fb ) {
							echo '<option value="' . esc_attr( $saved_fb ) . '" selected>' . esc_html( $saved_fb ) . '</option>';
						}
						?>
					</select>
				</td>
			</tr>
		</table>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="versi_seo_prompt"><?php esc_html_e( 'Custom Prompt', 'versi-content-tools' ); ?></label>
				</th>
				<td>
					<textarea id="versi_seo_prompt" name="versi_seo_prompt" rows="8" class="large-text code"><?php echo esc_textarea( get_option( 'versi_seo_prompt', '' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Custom system instruction for SEO keyword generation. Leave empty for the built-in default.', 'versi-content-tools' ); ?></p>
					<details style="margin-top:8px;">
						<summary><?php esc_html_e( 'Available variables', 'versi-content-tools' ); ?></summary>
						<pre style="background:#f0f0f1;padding:12px;font-size:12px;max-height:240px;overflow:auto;margin:8px 0 0;color:#666;">
{title}           - Post / page title
{content}         - Post / page body content (first 3000 chars)
						</pre>
					</details>
					<details style="margin-top:8px;">
						<summary><?php esc_html_e( 'Default prompt (click to expand)', 'versi-content-tools' ); ?></summary>
						<pre style="background:#f0f0f1;padding:12px;font-size:12px;max-height:240px;overflow:auto;margin:8px 0 0;"><?php echo esc_textarea( self::default_prompt() ); ?></pre>
					</details>
				</td>
			</tr>
		</table>
		<?php
		if ( empty( $this->integrations ) ) {
			echo '<div class="notice notice-info" style="margin-top:20px;"><p>';
			esc_html_e( 'No supported third-party plugins detected. Install Yoast SEO, Rank Math, SEOPress, SmartCrawl, or WooCommerce to enable integrations.', 'versi-content-tools' );
			echo '</p></div>';
			return;
		}
		?>
		<table class="form-table">
			<?php foreach ( $this->integrations as $plugin ) : ?>
				<tr>
					<th scope="row" style="vertical-align:top;padding-top:16px;">
						<strong><?php echo esc_html( $plugin['name'] ); ?></strong>
						<?php if ( ! empty( $plugin['desc'] ) ) : ?>
							<p style="font-weight:normal;color:#646970;margin:4px 0 0;font-size:12px;">
								<?php echo esc_html( $plugin['desc'] ); ?>
							</p>
						<?php endif; ?>
					</th>
					<td style="padding-top:16px;">
						<?php foreach ( $plugin['toggles'] as $toggle ) : ?>
							<label style="display:block;margin-bottom:8px;">
								<input type="checkbox" name="<?php echo esc_attr( $toggle['id'] ); ?>" value="1" <?php checked( get_option( $toggle['id'], '0' ), '1' ); ?>>
								<strong><?php echo esc_html( $toggle['label'] ); ?></strong>
							</label>
							<?php if ( ! empty( $toggle['desc'] ) ) : ?>
								<p style="color:#646970;font-size:12px;margin:0 0 12px 24px;">
									<?php echo esc_html( $toggle['desc'] ); ?>
								</p>
							<?php endif; ?>
						<?php endforeach; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	/**
	 * Collect the {focus_keywords} string from all active integrations.
	 *
	 * @param int $post_id Post / attachment parent ID.
	 * @return string Comma-separated keyphrases, or empty string.
	 */
	public function get_focus_keywords( int $post_id ): string {
		$all = array();

		foreach ( $this->integrations as $plugin_id => $plugin ) {
			if ( empty( $plugin['meta_key'] ) ) {
				continue;
			}

			$has_active_toggle = false;
			foreach ( $plugin['toggles'] as $toggle_id => $toggle ) {
				if ( $this->is_toggle_active( $plugin_id, $toggle_id ) ) {
					$has_active_toggle = true;
					break;
				}
			}
			if ( ! $has_active_toggle ) {
				continue;
			}

			$raw = get_post_meta( $post_id, $plugin['meta_key'], true );
			if ( ! empty( $raw ) && is_string( $raw ) ) {
				$all[] = $raw;
			}
		}

		if ( empty( $all ) ) {
			return '';
		}

		return implode( ', ', $all );
	}

	/**
	 * AI-generate focus keyphrases and save to active integrations.
	 *
	 * Called after a post is successfully processed (alt-text or excerpt).
	 * Skips integrations that do not have the auto_focus toggle enabled.
	 *
	 * @param int $post_id Post ID to generate keywords for.
	 * @return string The generated keywords on success, empty string on failure.
	 */
	public function generate_focus_keywords( int $post_id ): string {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return '';
		}

		$targets = array();
		foreach ( $this->integrations as $plugin_id => $plugin ) {
			if ( empty( $plugin['meta_key'] ) ) {
				continue;
			}
			if ( ! $this->is_toggle_active( $plugin_id, 'auto_focus' ) ) {
				continue;
			}
			$targets[ $plugin_id ] = $plugin['meta_key'];
		}

		if ( empty( $targets ) ) {
			return '';
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		$content = wp_strip_all_tags( $post->post_content );
		$content = mb_substr( $content, 0, 1500 );

		if ( mb_strlen( $content ) < 20 ) {
			return '';
		}

		$custom = get_option( 'versi_seo_prompt', '' );
		if ( ! empty( trim( $custom ) ) ) {
			$system = $custom;
		} else {
			$system = self::default_prompt();
		}

		$prompt = 'Title: ' . $post->post_title . "\n\nContent:\n" . $content;

		$builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $system );
		$builder = Versi_Processor::init()->apply_text_preference( $builder, 'seo' );

		self::$last_rate_limit = null;
		$generated = $builder->generate_text();

		if ( is_wp_error( $generated ) ) {
			$error_info = Versi_Processor::init()->classify_error( $generated->get_error_message() );
			if ( $error_info['should_retry'] ) {
				$fallback = Versi_Processor::init()->get_text_fallback( 'seo' );
				if ( '' !== $fallback ) {
					$fb_builder = wp_ai_client_prompt( $prompt )
						->using_system_instruction( $system )
						->using_model_preference( $fallback );
					$generated  = $fb_builder->generate_text();
				}
			}
		}

		if ( is_wp_error( $generated ) ) {
			$error_info = Versi_Processor::init()->classify_error( $generated->get_error_message() );
			if ( $error_info['should_retry'] ) {
				self::$last_rate_limit = array( 'retry_after' => (float) $error_info['retry_after'] );
			}
			return '';
		}

		if ( empty( trim( $generated ) ) ) {
			return '';
		}

		// Split by newlines to get individual keyphrases, then join with commas.
		$lines = preg_split( '/[\r\n]+/', $generated );
		$lines = array_map( 'trim', $lines );
		// Strip any label prefixes the model might add despite instructions.
		$lines = preg_replace( '/^(?:primary|secondary|support|keyword)\s*[:\-–—]\s*/i', '', $lines );
		$lines = array_map( 'trim', $lines );
		$lines = array_filter(
			$lines,
			function ( $v ) {
				return ! empty( $v ) && ! preg_match( '/^\d+[\.\)]/', $v );
			}
		);
		$lines = array_slice( $lines, 0, 3 );

		if ( empty( $lines ) ) {
			return '';
		}

		$generated = implode( ', ', $lines );
		$generated = sanitize_text_field( $generated );

		foreach ( $targets as $meta_key ) {
			update_post_meta( $post_id, $meta_key, $generated );
		}

		return $generated;
	}
}
