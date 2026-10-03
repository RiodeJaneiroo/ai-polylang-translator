# Changelog

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
