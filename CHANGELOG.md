# Changelog

## 1.8.0

### Added

- Full Yoast SEO handling for posts, pages and products. Translated: SEO title, meta description, breadcrumb title, focus keyphrase, Facebook and X (Twitter) titles and descriptions (Polylang's list of translatable Yoast keys). Copied to the translation as they are: robots settings (noindex, nofollow, advanced), Facebook and X images, and the primary category or term, replaced with its translation in the target language (left unset when the term has no translation: a wrong primary term is worse than none). Canonical URL and redirect are never copied. Before, a noindexed source got indexable translations and the primary category and social texts and images were lost.
- New adapter methods `copy_meta_keys()` and `translate_meta_value()`: the meta keys the multilingual plugin would copy to a new translation, and a copied value adjusted for the target language. Polylang: what its integrations add through `pll_copy_post_metas` (Yoast SEO, `wpml-config.xml` custom fields) and `pll_translate_post_meta`. WPML: the custom fields set to copy, copy once or translate (stored preferences and `wpml-config.xml` preferences of the source's keys), copied by the plugin because WPML's own copy runs in the save handler the writer suspends; values go through `wpml_sync_custom_field_copied_value`, and the Yoast primary term is replaced with its translation, also in WPML's own copy when the new translation is linked (a filter hooked only for the duration of the write). Yoast's images and robots keys are added to either list while Yoast SEO is handled, because Yoast's `wpml-config.xml` marks the robots keys with an action spelling (`copy_once`) that neither Polylang nor WPML accepts. Copied on every write of a translation, new or updated; on an update, filled values of copied keys are kept (per-language values such as a translated subtitle), except Yoast's robots, images and primary term in overwrite mode, which follow the source. Translated keys, ACF, WooCommerce attributes, the plugin's and the multilingual plugin's own keys are left out.
- New filter `aipt_translated_meta_keys` (`$keys, $post_id`): the meta keys translated besides the *Extra meta keys* setting (the plugin adds the Yoast SEO keys at priority 5 when Yoast is active and its box is ticked).
- New filter `aipt_copied_meta_keys` (`$keys, $source_id, $target_id, $lang`): the meta keys copied from the multilingual plugin's list (e.g. remove a key a WPML site set to ignore). The plugin adds Yoast's copied keys and the source's primary-term keys at priority 5 (so the primary term is copied whatever the backend's config, also in WP-Cron under `ALTERNATE_WP_CRON`, where Polylang's Yoast integration is not loaded; the term is then remapped by the plugin) and removes every other Yoast key (canonical URL, redirect, WPML's translate-mode keyword keys; all Yoast keys when Yoast is not handled) at `PHP_INT_MAX`.
- Automatic translation status: each source post keeps the state of its last automatic translation in one post meta (`_aipt_auto_status`: scheduled, running, done or failed, with a row per language and its message). The AI Translation box shows it above the language rows: when the event is due, since when it is running, when each language was translated, and why a language failed or was skipped (parent or terms not translated, pending work in WPML), and whether a translation already existed (left alone). An overdue event that is still queued (WP-Cron has not run it: `DISABLE_WP_CRON` without a server cron, no traffic) is reported with a hint to fix WP-Cron; an event that is gone from the queue (the plugin was deactivated with it queued, a fatal error before the run started) and a run with no progress for an hour are reported as lost and stalled.
- "Retry automatic translation" button in the AI Translation box when the last automatic translation failed, its event was lost (gone from the cron queue) or its run stalled, and the post can still be translated automatically (published, in the default language, an enabled type, the setting on). It schedules the post again 10 seconds later; only languages without a real translation are translated. An overdue event that is still queued gets no button: WP-Cron has to run it.
- Action `aipt_purge_urls` (`$urls, $post_id, $source_id, $lang`) after every translation write, with the permalinks of the translation and the source that are published, for page caches and site code. When LiteSpeed Cache is active, both posts are purged through its `litespeed_purge_post` action.

### Changed

- The Yoast SEO checkbox ("Translate Yoast SEO fields") now controls translation and copying: unticked, Yoast SEO fields are not touched at all.
- A meta value that is only placeholders, separators and markup (a Yoast template such as `%%title%% %%sep%% %%sitename%%`) is not sent to the model and is copied verbatim; a translated meta value whose `%%…%%` or printf placeholders changed keeps the source value, and the write reports a warning naming the field (without leaving a new translation as a draft). A printf letter followed by a letter or digit (percent-encoding, `100%free`) is no longer taken for a placeholder.
- Safe mode keeps a filled target value of a copied key; an empty or missing source value never clears the target there. In overwrite mode an update makes Yoast's fields follow the source: translated SEO fields, robots, images and the primary term the source no longer has (or a primary term without a translation) are deleted from the translation, as before 1.8 for the translated ones. *Extra meta keys* never clear a value.
- A translated meta key (Yoast, `aipt_translated_meta_keys`, *Extra meta keys*) is sent only when its source value is a non-empty string: an empty source value no longer clears the translation's Yoast fields in overwrite mode, and a non-string value is no longer turned into the text "Array".
- Transient errors of an automatic translation (connection error or timeout, HTTP 408, 429 and 5xx, another process writing the same translation) no longer give up: the affected languages are retried automatically, 5 and then 30 minutes later. Other errors fail that language and the next language runs (a hard error in the first language used to stop the remaining ones), except errors that would repeat for every language (API key rejected or missing, the multilingual plugin's API, sync suspension or translation link failing): they stop the post, the remaining languages are marked as not attempted, and the post can be retried. A status write of an older run never overwrites a newer schedule or run.
- Re-publishing a post (draft → publish) schedules its automatic translation again when the last run failed, was lost or stalled; it used to stay untranslated for good after one failure. Retry and re-publish keep the languages already done. Posts marked by versions before 1.8.0 (marked as scheduled, but without a status) that still lack a selected language are scheduled again on re-publish, and the AI Translation box lists the missing languages with a *Translate missing languages automatically* button; only languages without a real translation are translated, which also covers a language selected later. Existing translations are still never updated; posts written by the plugin since 1.7 are never auto-translated, and default-language posts it wrote before 1.7 count as such older posts.
- The event runs 10 seconds after a publish from the classic editor, Quick Edit, WP-CLI, code or a scheduled post; the two-minute delay is kept only for block-editor saves, which save the legacy metaboxes (ACF, Yoast, the language box) in a second request.
- Sites with `DISABLE_WP_CRON` or little traffic should run WP-Cron from a server cron every minute (`wp cron event run --due-now`, or a request to `wp-cron.php`); otherwise the event waits for the next page view (see README).

### Fixed

- After a translation is written, the source post's object cache is cleaned as well as the translation's: its translation group and the language switcher and hreflang links derived from it change when a translation is linked.

## 1.7.1

### Fixed

- Text stored in block attributes is translated (e.g. Kadence button labels, navigation link labels): it used to stay in the source language, because block delimiters are restored from the source. Each text value is a separate item (path `['blockattr', chunk, delimiter, ...keys]`) and is written back into its delimiter with the rest of the block JSON unchanged; a value that cannot be written back exactly keeps its source text.
- Which attributes hold text comes from generic sources only: the `<gutenberg-block>` definitions of `wpml-config.xml` files (on WPML the list WPML parsed, new adapter method `block_text_attributes()`, with the files read when that list is empty; on Polylang the files of the active plugins, mu-plugins and themes, cached in a transient until one of them changes) and block attributes WordPress marks as content (`"role": "content"`, string, without a `source`, and a name that does not look like a setting: a word such as URL, link, target, type, slug, icon, date, size, ID, unless the name ends in text/label/title/…, so `linkTarget` is skipped and `linkText` is kept; their lowercase one-word values such as `_blank` or `image` are skipped).
- New filter `aipt_block_text_attributes` (`$config, $backend`) to add or remove attribute keys.

## 1.7.0

### Added

- Bulk translation page (Settings > AI Bulk Translation, administrators): translates selected taxonomies, then published posts, products and pages into the selected languages from wp-admin, driven by the open browser tab. Estimate (same pre-checks and cost estimate as `wp aipt translate --dry-run`), Start, Stop; progress, counters (created, updated, terms, warnings, skipped by reason, needs check, errors, cost, API calls, elapsed time), records in progress and a log of everything that was not a plain success, with links. Existing translations are skipped by default (or updated in safe mode, or overwritten after a confirmation); Stop → Start continues the same run and skips records finished in it in every mode, until the run finishes or "New run" is clicked; new translations can be published (non-public sources stay private, scheduled or draft) and keep the source dates. 1–3 parallel requests (default 2); pages run parents first; terms in short requests of up to 40 missing terms each. Stops after 5 errors in a row, at once on errors no record can get past, and when the API key is rejected; an optional soft cost limit stops starting new work. Form values are remembered in the browser.
- Recovery journal: every record of a bulk run has a durable job (stored in options, not transients) and a locator option pointing to it, written before the first paid block. Start again resumes an unfinished record from its saved blocks, so a block that was saved is never paid for twice; a block request is retried automatically after a network error or while another request holds it. A record whose earlier write did not finish, whose source changed after its blocks were translated, whose translation changed elsewhere, or whose job belongs to another user or other options is never written automatically: it is listed under "Needs manual check" with Translate anyway and Discard.
- Copy meta keys (Advanced settings): custom fields copied verbatim to translations, one meta key per line, a trailing `*` matches a prefix (e.g. `_kad_post_*`). Applied on every write of a translation, new or updated; translated and internal keys are never copied.
- Copy term meta keys (Advanced settings): the same for term meta, applied when a translated term is created or linked.
- `wp aipt translate-strings` (WPML only): translates WPML String Translation strings of the given domains (e.g. Yoast SEO title templates `admin_texts_wpseo_titles`, term SEO fields `yoast-seo-term-meta`) in batches and saves them as finished string translations. Placeholders and HTML are kept (a translation with changed placeholders is not saved); empty and placeholder-only strings are skipped; existing translations are skipped unless `--mode=update`; `--dry-run`, `--log` and the exit status work as in `translate-terms`.
- Strings section on the bulk translation page (WPML String Translation, for sites without WP-CLI): a table of string domains with their string counts and the number without a finished translation per target language, Update existing translations, Estimate (dry run) and Translate. Each request handles one domain × language through the same code as `wp aipt translate-strings` (in short requests of at most two gateway batches, a few dozen strings each, so an interruption re-pays at most one request; a domain continues request by request), with a summary per domain × language, a log per string and the cost. New adapter method `string_domains()` (read-only; Polylang reports strings as not supported).
- Reusable blocks (`wp_block`) can be enabled in the Post types setting (they are not public, but pages embed them by reference, so an untranslated block shows the source language on a translated page). A translated page's `wp:block` refs are written pointing at the target-language block when it has a translation (`AIPT_Block_Refs::remap`), and on the front end a translated page shows the target-language block when it has a translation, whichever was translated first (`render_block_data` filter). One shared list, `AIPT_Settings::selectable_post_types()`, feeds the settings page and its sanitizing; the bulk page, `wp aipt` and auto-translation follow the enabled types.
- Shared groundwork (also used by WP-CLI, unchanged behaviour): `AIPT_Runner` (pre-checks, publish rule, estimate, revalidation before the write), durable job storage, a per-block lock so two requests never pay for the same block, and the finalize stage/outcome kept in the job. Posts written by the plugin carry the job id (`_aipt_job`).

### Changed

- Uninstall also removes the bulk page's job locators and lock rows (`aipt_bulk_*`), durable jobs and block locks.

## 1.6.0

### Added

- WPML support. A multilingual adapter (`includes/lang/`) sits between the plugin and the multilingual plugin; Polylang and WPML each have one implementation, and nothing else calls them. On WPML the plugin only supplies translated text: translation groups, TM status (marked complete after writing), media and WooCommerce Multilingual sync stay WPML's job. WPML duplicates are overwritten in place and lose the duplicate flag; a pair with a pending WPML translation job is never written; the original can be switched to WPML's native editor (Advanced setting, on by default). If Polylang and WPML are both active, the plugin refuses to translate.
- Global switch (Settings > AI Translator > Status): turning it off parks the plugin (no metabox, AJAX refused, no automatic translation, WP-CLI refuses and a running bulk command stops between records).
- WooCommerce: custom product attribute labels and options are translated (labels go where WooCommerce Multilingual reads them).
- Extra meta keys to translate (Advanced settings): plain-text custom fields, one meta key per line.
- Block masking: Gutenberg/Kadence block delimiter comments are replaced with tokens before content goes to the model, so block JSON is kept out of the model's input and restored from the source afterwards. A content part whose tokens come back changed keeps its original text, a new translation is then left as a draft, and the translation is reported with a warning.

### Changed

- Plugin name: "AI Translator for Polylang & WPML" (slug, text domain and file names unchanged).
- Jobs record the multilingual backend they were prepared with; a job from another backend is refused.

## 1.5.1

### Changed

- The cost log keeps the latest 100 entries (was 50). Totals still cover all translations.
- Settings > AI Translator: the cost log table scrolls (max height 420px) with a sticky header, and a line above it states how many entries are shown.

## 1.5.0

### Added

- Automatic translation on publish (Settings > AI Translator > Automatic translation, off by default). When a post of an enabled post type in the Polylang default language is published for the first time (block editor, classic editor, or a scheduled post going live), one background wp-cron event runs two minutes later (after the block editor's metabox save) and translates it into each selected language that has no translation yet: the post's untranslated terms first (ancestors first), then the post, published with the source date. A language whose parent page has no translation is skipped before anything is paid for. Costs go to the cost log. Existing translations are never updated and later edits of the source are not re-translated; use "Update translation" in the metabox. Failures are written to the PHP error log (`AIPT auto-translate: …`) and stop that post; there are no retries. A scheduled post is translated as the user who scheduled it (else the author).
- Target languages: all non-default languages by default. With every box ticked, languages added to Polylang later are included too; clearing every box selects none.
- Posts written by the plugin (editor, WP-CLI, auto-translation) are marked and never auto-translated, also when published later. Imports (`WP_IMPORTING`) never schedule anything.
- Known limits: switching the language to the default one in the Polylang metabox during the first publish is not detected; an old post re-published without ever having been scheduled is scheduled (only missing languages are translated); two cron runners may create the same new term concurrently.

### Changed

- Term translation (`AIPT_Terms`), the per-record pre-checks (`AIPT_Record`), the WP-CLI run log (`AIPT_CLI_Log`) and WP-CLI flag parsing (`AIPT_CLI_Args`) moved out of `AIPT_CLI` into their own classes. `wp aipt translate` and `wp aipt translate-terms` behave as before (same output, log format and exit codes).
- The editor names an automatic translation in progress when it holds the translation lock.
- Creating translated terms requires the taxonomy's `edit_terms` capability in every path (WP-CLI checks it up front; auto-translation skips the language and logs it).
- `AIPT_Pipeline::translate_post()` now owns the whole pair-lock sequence (lock, re-check of an existing translation, refresh per batch) for WP-CLI and auto-translation.
- Deactivation clears pending auto-translation events; uninstall also removes the `_aipt_auto_scheduled` and `_aipt_auto_user` post meta. Polylang's custom-field sync never copies these keys.

## 1.4.0

### Added

- `wp aipt translate`: bulk translation of posts through WP-CLI, one record (source post × target language) at a time, with the same pipeline as the editor metabox. Select records by `--post_type` or `--ids`, `--post_status`, `--after`, and `--shard=i/n` for parallel workers on disjoint sets. Existing translations are skipped by default, so re-running the command resumes it; use `--mode=safe|overwrite` to update them instead. `--publish` publishes new translations of published sources (private sources stay private, scheduled ones stay scheduled with `--keep-date`, anything else becomes a draft), `--keep-date` copies the source dates, and `--limit` caps the number of API-backed translations. Hierarchical post types go parents first. Records whose parent has no translation (`skipped_parent_missing`) or whose terms have no translation (`skipped_missing_terms`) are skipped, not created half-linked. One failing record never stops the run, but the command exits with status 1 if any record failed. Batch results stay in memory, so long records do not depend on transient lifetimes. `--log` appends a TSV line per record plus a summary and is refused inside ABSPATH, WP_CONTENT_DIR or (for WordPress in a subdirectory) the web root. `--dry-run` lists records, text sizes and estimated cost without API calls or writes.
- `wp aipt translate-terms`: translates term names and descriptions of Polylang-translated taxonomies in batches, creates terms parents first, and links them to their source terms. `--only-used-since` limits the run to terms used by recent posts (plus their ancestors). Costs appear in the cost log as "Terms: <taxonomy>".
- A lock for each translation group and target language, so a CLI run and the editor never write the same translation at once. The editor shows a clear message when a CLI run holds the translation.

### Changed

- The translation pipeline (prepare → batches → finalize) moved from `AIPT_Metabox` to the new `AIPT_Pipeline` class, shared by the AJAX handlers and WP-CLI. The metabox responses are unchanged.
- New translations are created as drafts and get a requested non-draft status only after the whole translation is written, so SEO plugins and other publish hooks see the complete post in its real language. If that last status/slug update fails, the translation is still kept and linked, and the editor's Retry returns its link.

### Fixed

- Slugs of new translations: HTML entities and accents in the translated title no longer produce slugs like `-amp-` or `poly-k`.
- When the slug of a new translation is already taken in another language, the translation gets a language suffix (`futbol-uk`) instead of WordPress's numeric suffix (`futbol-2`); long slugs are shortened so the suffix fits the 200-character limit. Setups where slugs can be shared across languages keep the plain slug.
