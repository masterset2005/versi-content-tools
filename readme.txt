=== Versi Content Tools ===
Contributors: masterset2005
Donate link: https://versihosting.com/
Tags: AI, alt text, excerpts, accessibility, WP AI Client
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.3.2
License: GPL v2 or later

AI-powered alt-text generation and excerpt management using WordPress 7.0's built-in WP AI Client.

== Description ==

Versi Content Tools generates descriptive alt text for images and compelling excerpts for posts using
your configured AI providers (OpenAI, Anthropic, Google, local Ollama, etc.) via WordPress 7.0's
built-in `wp_ai_client_prompt()` API.

**Two workloads in one plugin:**

* **Alt Text** &mdash; Single-pass or two-pass processing. Sets `_wp_attachment_image_alt` on
  attachment images. Three modes: Fill Missing, Review & Improve, Regenerate All.
* **Excerpts** &mdash; Text-only AI calls using post content. Sets `post_excerpt` on published posts.
  Two modes: Generate Missing, Improve All.

**Key features:**

* Bulk processing on a dedicated **Media > Versi Content Actions** page with a workload selector
* Live sequential batch processor with per-item redo/undo
* Background cron-based processing (close the browser, check back later)
* WP-CLI commands: `wp versi alt <mode>` and `wp versi excerpt <mode>`
* Auto-generate on upload (alt text) and on save (excerpts)
* Tabbed **Settings > Versi Content Tools** page with General / Alt Text / Excerpts tabs
* Independently editable prompts with variable placeholder guides
* 125-character server-side truncation + regex firewall for alt text
* Target word count (10&ndash;200) for excerpts
* Model preference dropdowns populated live from your configured AI providers
* No custom database tables &mdash; uses `postmeta` and `options` only

== Installation ==

1. Upload the `versi-content-tools` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** screen
3. Configure your AI providers under **Settings > Connectors** (part of WordPress 7.0)
4. Go to **Settings > Versi Content Tools** to configure model preferences and prompts
5. Process content under **Media > Versi Content Actions**

== Extensions ==

Versi Content Tools detects installed SEO plugins (SmartCrawl, Yoast, Rank Math, SEOPress) and WooCommerce
to provide context-aware content generation.

* **Focus Keywords:** Automatically detect focus keywords and inject them into AI prompts for
  improved SEO relevance.
* **Auto-Focus:** AI-generate focus keywords based on your content and save them directly to the
  SEO plugin's meta fields.

== Frequently Asked Questions ==

= Do I need an API key? =

Yes. You must have at least one AI provider configured under Settings > Connectors.
WordPress 7.0 includes built-in support for OpenAI, Anthropic, Google AI, and local providers.

= What models are supported? =

Any model registered with the WP AI Client. The Vision Model dropdown is used for image
analysis; the Text Model dropdown is used for the excerpt workload and the alt-text
synthesizer (two-pass mode). Leave both at "- Default -" to use the provider's default model.

= Does this work with the Classic Editor or block editor? =

Both. The processing page and WP-CLI commands are editor-agnostic. Auto-generate on
upload/save works regardless of which editor you use.

= Will my existing excerpts be overwritten? =

The excerpt auto-generate on save only fires when `post_excerpt` is empty, so existing
excerpts are never overwritten by the auto-generate hook. Bulk "Improve All" explicitly
targets all excerpts.

== Screenshots ==

1. Settings page &mdash; General tab with batch size, content limit, and model preferences
2. Settings page &mdash; Alt Text tab with processing modes and prompts
3. Settings page &mdash; Excerpts tab with target length and custom prompt
4. Processing page showing live batch results with redo/undo

== Changelog ==

= 1.3.1 =
* Add support for SEO plugin Extensions system (SmartCrawl, Yoast, Rank Math, SEOPress)
* Add support for AI-generated focus keyword writing to SEO plugin metadata

= 1.2.2 =
* UI: Show "show more/less" toggle for truncated text in processing results
* UI: Add estimated time remaining to live processing status
* Fix: Stronger excerpt prompt and cleaning to remove preamble from AI output

= 1.2.1 =
* UI cleanup: Move model selectors to workload-specific settings tabs (Alt Text / Excerpts)

= 1.2.0 =
* Add support for workload-specific model selection (alt-vision, alt-text, excerpt-text)
* Add state persistence for live processing (resume after refresh/nav)
* Remove legacy autoalt_* migration and backward compatibility

= 1.1.0 =
* Initial release as Versi Content Tools
* Alt-text workload (single-pass / two-pass) with three modes
* Excerpt workload (missing / improve) with configurable word count
* Tabbed settings page, bulk processing page, background cron jobs
* WP-CLI commands, auto-generate on upload/save, Media Library overlay
