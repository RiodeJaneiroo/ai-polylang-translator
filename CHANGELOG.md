# Changelog

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
