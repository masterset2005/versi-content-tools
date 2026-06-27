# TODO

## Potential new workloads

### Image Captions (`post_excerpt` on attachments)
- Same attachment pipeline as alt text — already have `{caption}` in context
- AI generates from `{article_content}` + image, saves to `post_excerpt`
- Would share the alt-text processing page, stats, batch machinery
- Risk: `post_excerpt` on attachments is the caption field; some themes display it
- **Approach**: "Fill Missing Captions" mode (safe), "Regenerate All" (with warning)

### Term Descriptions (category/tag description fields)
- Taxonomy term `description` field — almost always empty on most sites
- Generate a 1–3 sentence description based on the term name + slug + count of posts
- Static text — no API call per item needed if we batch via content sampling
- **Approach**: Dedicated tab on the processing page or a new sub-section
- Use existing `versi_excerpt_prompt`-style custom prompt with `{term_name}`, `{term_slug}`, `{post_count}` placeholders

### Image Descriptions (`post_content` on attachments)
- The "Description" field in the media modal — distinct from caption and alt text
- Longer-form than caption, often used by galleries and lightbox plugins
- Same pipeline as alt text / captions — just different meta key

## Non-core ideas

### Auto-tagging / Auto-categorization
- Scan `post_content`, map to existing tags/categories, assign
- Requires heuristics: create new tags vs stick to existing, confidence thresholds
- Higher complexity, more settings surface
- **Risk**: can create tag spam if not carefully tuned

### Author Bios (`description` user meta)
- Generate from author's published post history
- One-shot per user, not per post — very fast
- Custom prompt with `{author_name}`, `{post_count}`, `{recent_titles}` placeholders
- Could live under Users > Profile or a dedicated tool

## Infra improvements

- [ ] Add `composer lint` and `composer lint:fix` scripts
- [ ] Abstract the processing page tabs so new workloads register via filter/hook
- [ ] Add term description query methods to `Versi_Processor`
- [ ] Export / import settings (JSON blob of all `versi_*` options)
