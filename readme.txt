=== Versi Content Tools ===
Contributors: masterset2005
Donate link: https://versihosting.com/
Tags: AI, alt text, excerpts, accessibility, WP AI Client
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.13.0
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered content generation for alt text, excerpts, content cleanup, and SEO keywords using WordPress 7.0's built-in WP AI Client.

== Description ==

Versi Content Tools generates descriptive alt text for images, compelling excerpts for posts, cleans up
embedded content, and manages SEO focus keywords — all via your configured AI providers (OpenAI,
Anthropic, Google, local Ollama, etc.) through WordPress 7.0's built-in `wp_ai_client_prompt()` API.

**Workloads:**

* **Alt Text** &mdash; Single-pass or two-pass AI processing. Sets `_wp_attachment_image_alt` on
  attachment images. Three modes: Fill Missing, Review & Improve, Regenerate All. Configurable vision
  model per workload.
* **Excerpts** &mdash; Text-only AI calls using post content. Sets `post_excerpt` on published posts.
  Modes: Generate Missing, Fix Short, Improve All, Bulk Review. Configurable target word count and
  custom prompt.
* **Content Cleanup** &mdash; Bulk-edit alt attributes in `post_content` and strip self-linking image
  wrappers, with an optional `the_content` filter for real-time updates.
* **Attachment Auditor** &mdash; Scan your entire content for unlinked images and link them to their
  proper attachment posts in bulk.
* **SEO Extensions** &mdash; Detects Yoast SEO, Rank Math, SEOPress, and SmartCrawl. Injects focus
  keywords into AI prompts for context-aware generation and auto-generates keywords saved directly to
  plugin meta fields.
* **WooCommerce** &mdash; Product content and excerpt support.

**Processing modes:**

* **Live mode** &mdash; Sequential batch processor with real-time results, per-item redo/undo, filterable
  results (Success / Errors / Skipped), and CSV export.
* **Background mode** &mdash; Cron-based batch processing. Start a job, close the browser, and check back
  later. Supports pause, resume, and cancel.
* **Bulk Review mode** &mdash; AI evaluates existing alt text or excerpts in batches, flags items with
  reasons, and provides one-click regenerate buttons.
* **WP-CLI** &mdash; `wp versi alt <mode>`, `wp versi excerpt <mode>`, and `wp versi content <mode>`.

**Key features:**

* Dedicated **Media > Versi Content Actions** processing page with workload selector and Dashboard tab
* Tabbed **Settings > Versi Content Tools** page (General / Alt Text / Excerpts / Extensions / About)
* Post type selection &mdash; choose which post types to process (Posts, Pages, Products, etc.)
* Independently editable prompts with variable placeholder guides per workload
* Model preference dropdowns populated live from your configured AI providers
* Fallback model per workload when the primary is rate-limited or unavailable
* Auto-retry on API rate limits (429) &mdash; detects retry-after headers, retries up to 5 times
* Image size optimization &mdash; choose which attachment size to send to the AI vision model
* 125-character server-side truncation + regex firewall for alt text safety
* Target word count (10&ndash;200) for excerpts
* Auto-generate on upload (alt text) and on publish (excerpts when empty)
* Per-user rate limiting on all AJAX endpoints (20 requests per 10 seconds)
* Processing history with CSV export, download, and clear
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

= 0.13.0 =
* New: Divi 5 integration — live render_block filter updates alt text in Image and Blurb modules
* New: Divi 5 Content Cleanup — rewrites content.module.alt.desktop.value in block JSON during DB cleanup
* New: "Update Divi 5 Alt" toggle on Settings > Alt Text tab
* New: Profiles & People prompt rules with {filename_label} variable extracted from image filenames
* Tweak: AI prompts now use "available context" language instead of enumerating specific fields

= 1.12.0 =
* Fix: Clear History button now works without JavaScript (form POST fallback with nonce)
* Fix: Admin notices now dismissible (`is-dismissible` class) per WP directory guidelines
* Fix: Cache-busting uses `VERSI_VERSION` constant instead of `filemtime` for deployment safety
* Tweak: Added `License URI:` to plugin header and readme.txt for WP directory compliance
* Tweak: Added `license` field to composer.json
* Tweak: Added `== Upgrade Notice ==` section to readme.txt

= 1.11.0 =
* Security: Fixed privilege escalation — all AJAX handlers now require `edit_others_posts` instead of `edit_posts` (Contributors can no longer modify others' content)
* Security: Fixed IDOR — ownership checks added to all undo/save/link AJAX handlers (attachment and post author verification)
* Security: Added per-user rate limiting (20 requests per 10 seconds) across all AJAX endpoints
* Security: Processing history now restricted to administrators (`manage_options`)
* Security: Mode parameter validated against a whitelist before processing
* Security: AI error messages sanitized to prevent infrastructure information disclosure
* Security: Attachment path exposure in auditor reduced to basename only

= 1.10.0 =
* Refactor: God class (class-admin.php) split into focused files — AJAX handlers, settings, and admin page rendering each in their own class
* Fix: Broken PHP tags in render_processing_page() causing URL variables to render as literal HTML text
* Fix: Versi_Admin_Settings and Versi_Admin_Ajax now properly initialized on plugin load (hooks were silently not registering)
* Dev: phpstan clean (0 errors)

= 1.9.0 =
* Fix: Auditor scan reliability using tiered verification (Featured Image lookup + ID class lookup + tokenized filename matching)
* Fix: Optimized SQL queries to prevent timeouts on large media libraries
* Dev: Full codebase audit against WP coding standards and security practices

= 1.8.0 =
* New: Image size optimization setting — choose which attachment size (thumbnail, medium, large, full) to send to the AI
* New: Attachment Auditor — scan content for unlinked images and link them to their attachment posts
* New: Dashboard tab on processing page showing tool overview, stats, and recommended run order
* New: Improved AI error handling with structured error classification (503, timeout, 400, rate-limit)
* Fix: "Generate Missing Excerpts" now correctly processes only posts without excerpts
* Fix: Duplicate HTML IDs on processing tab panels resolved for WCAG compliance
* Fix: WCAG focus management — tabindex, focus() calls on panels and headings, global focus outline
* Fix: Color contrast improvements for small-status text (gray-600 replaces gray-500)
* Fix: Decorative SVG icons marked aria-hidden + focusable="false" for screen readers
* Tweak: UI modernization — card-based design with gradient backgrounds, SVG icons, rounded corners
* Tweak: Media Auditor promoted to top-level workload tab alongside Alt Text, Excerpts, etc.
* Tweak: Auditor scan now shows animated spinner, result counts, and error handling feedback
* Tweak: Live/Background sub-tabs hidden for Dashboard and Auditor tabs
* Dev: PHPCS auto-fixes (34+ formatting issues), PHPDoc corrections, undefined variable hardening
* Dev: Fix plugin version header to match readme stable tag

= 1.6.0 =
* New: Auto-retry on API rate limits (429) — detects provider retry-after, waits and retries up to 5 times
* New: Fallback model per workload — secondary model used when primary is rate-limited or unavailable
* Fix: Custom prompt textareas no longer save HTML indentation whitespace
* Fix: "Default prompt" expandable section now shows the hardcoded default, not the custom prompt
* Fix: versi_seo_text_model and versi_seo_prompt added to uninstall cleanup

= 1.5.0 =
* New: Bulk Review mode — AI evaluates existing alt text / excerpts in batches of 30, flags bad items with reasons and regenerate buttons
* New: Content Cleanup workload tab — bulk-edit alt in post_content + strip self-linking image wrappers (database writes)
* New: Fix Short Excerpts mode with configurable min length
* New: Strip Self-Linking Images the_content filter toggle
* New: GPL disclaimer on About tab
* Fix: Focus keywords no longer leak as "(Keywords: ...)" appendix in excerpts
* Fix: Corrected add_filter/add_action mixup on posts_where hooks (PHPStan error)
* Fix: Nonce mismatches in background job cancel/poll notification
* Security: Directory hardening (index.php stubs), escaped thumbnail output, JS .prop() injection hardening
* Tweak: Neutralized default excerpt prompt for site-neutral use; custom prompt textarea for niche overrides
* Tweak: Available variables + Default prompt display on Extensions tab

= 1.4.0 =
* New: Content Cleanup workload tab (alt attributes in content + strip self-linking images — writes to DB)
* New: Strip Self-Linking Images toggle (the_content filter)
* New: "Fix Short Excerpts" processing mode with configurable min length
* New: GPL license disclaimer on About tab
* Fix: Focus keywords no longer leak as "(Keywords: ...)" appendix in generated excerpts
* Fix: Correct nonce actions on background job notification poll/cancel
* Security: Directory hardening (index.php in includes/, assets/, languages/)
* Security: Hardened JS thumbnail rendering via .prop() instead of string interpolation

= 1.3.3 =
* New: Optional the_content filter dynamically updates alt attributes in embedded images to match current attachment meta
* New: "Update Content Alt" toggle on Settings > Versi Content Tools > Alt Text tab
* Fix: Remove error_log() debug calls from production code
* Tweak: Reduce background job to 1 batch per cron fire with 30s gap for lower server load
* Tweak: Prefixed variables in uninstall.php per WPCS standards

= 1.3.2 =
* Fix: Properly unschedule cron events on plugin deactivation (per WP docs)
* Fix: Guard duplicate cron scheduling with wp_next_scheduled() check
* Fix: Remove overly-aggressive DISABLE_WP_CRON synchronous drain
* Fix: Detect stalled background jobs and show user-facing error with guidance
* Fix: Add cron scheduling fallback when wp_schedule_single_event() fails to store

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

== Upgrade Notice ==

= 0.13.0 =
Developer preview — Divi 5 integration, Profiles & People prompt rules.

= 1.12.0 =
Plugin directory compliance fixes — dismissible notices, license URI headers, and Clear History fallback.

= 1.11.0 =
Security hardening — privilege escalation, IDOR, rate limiting, and AI error disclosure fixes. All users should upgrade.
