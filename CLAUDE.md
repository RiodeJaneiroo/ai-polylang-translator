# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Standalone WordPress plugin: AI translation of posts and ACF fields into other Polylang languages via Vercel AI Gateway. No Composer, npm, or test runner. Requires PHP 8.1+, WordPress 6.0+, Polylang active; ACF and Yoast SEO are optional integrations. See AGENTS.md for contribution conventions.

## Commands

```bash
# Lint (run after every change — the only automated check)
php -l ai-polylang-translator.php
find includes -name '*.php' -exec php -l {} \;

# In a local WordPress install
wp plugin activate ai-polylang-translator
```

Manual verification happens in wp-admin: Settings > AI Translator (API-key test, model select, cost log), and the "AI Translation" metabox on a post (new translation, overwrite confirmation, safe mode, retry). Test with and without ACF/Yoast active.

## Architecture

Bootstrap `ai-polylang-translator.php` defines constants, checks for Polylang, loads one `AIPT_*` class per file from `includes/`, and instantiates `AIPT_Settings` + `AIPT_Metabox` in admin. `assets/` is dependency-free vanilla JS/CSS.

### Translation pipeline: prepare → translate_batch (loop) → finalize

Driven by `assets/metabox.js` through three AJAX actions in `AIPT_Metabox`:

1. **prepare** (`ajax_prepare`): `AIPT_Extractor::extract()` collects translatable strings, `build_batches()` greedy-packs them (8 KB / 60 items per batch), and `AIPT_Job::create()` stores an **immutable** job payload in a transient (TTL 1 hour). The job snapshots the model at creation time so the cost log reflects the model actually used.
2. **translate_batch** (called per batch index): `AIPT_Gateway::translate_map()` sends an `{id: text}` JSON map to the model and expects the same keys back. Each batch's result + token usage is saved in its **own** transient (`AIPT_Job::save_batch`) so concurrent batch requests never read-modify-write a shared blob. Idempotent: a finished batch returns success without re-translating. Usage is recorded immediately after the API call (tokens are billed even when the reply is rejected).
3. **finalize** (`ajax_finalize`): merges all batch transients, takes a DB-row finalize lock, and `AIPT_Writer::write()` creates/updates the translation post. On success a small completion marker is kept so duplicate finalize calls return the link.

### Item path addressing

`AIPT_Extractor` assigns every translatable string an id (`s0`, `s1`, …) and a path that `AIPT_Writer` later resolves:

- `['post', 'title' | 'excerpt']`, `['post', 'content', N]` (HTML chunked at 8 KB on closing block tags; concatenating chunks restores the original)
- `['meta', meta_key]` (Yoast SEO keys)
- `['acf', field_key, row_index, sub_key, ...]`, with a `['#chunk', N]` suffix for long WYSIWYG values

ACF values are read raw (`format_value = false`) — repeater/flexible rows keyed by sub-field keys with `acf_fc_layout`, the exact shape `update_field()` accepts. The full ACF tree travels in the job; translated strings are substituted into it by path.

### Writer ordering invariant

`AIPT_Writer` order matters and must be preserved: insert/update post → `pll_set_post_language` → ID remap (relationship/post_object/page_link/taxonomy fields remapped to target-language equivalents) → meta → ACF → taxonomies → `pll_save_post_translations` **last**, so Polylang sync-on-save never touches a half-built post. Polylang sync hooks are suspended for the duration and restored in `finally`. Model output (and only model output — never values copied verbatim from the source) is run through `wp_kses_post` for users without `unfiltered_html`.

### Safe mode (updating an existing translation)

`mode === 'safe'` keeps filled target fields. Three coordinated path lists flow from extractor to writer through the job: `preserve` (target value exists — keep it), `overwrite` (target empty/missing — replace in full), `flex` (flexible-content fields merged row-aligned by layout instead of replaced wholesale; see `AIPT_Safe_Merge::align_flexible_rows`). ACF fields also have three user-facing states via settings overrides: translate (default), copy, skip ("Don't touch") — policy decided in `AIPT_ACF_Schema::action()`.

### Concurrency & state

- `AIPT_Job` implements locks as `INSERT IGNORE` rows in `wp_options` (mirroring `WP_Upgrader::create_lock`), with stale-takeover by timestamp, because `add_option()` is not atomic. Lock reads bypass the object cache deliberately.
- `AIPT_Usage` (cost log + totals in options, capped at 50 entries) serialises concurrent batch writes with a global named lock; entries are idempotent per `job_id` (subsequent batches accumulate into the existing row).
- Jobs without the `usage_recorded_per_batch` marker are pre-1.3 jobs and keep finalize-time usage aggregation — don't break that upgrade path.

### Gateway

`AIPT_Gateway` talks to `https://ai-gateway.vercel.sh/v1/chat/completions` (OpenAI-compatible). It requests JSON mode, retries once without `response_format` on HTTP 400, retries missing keys once in a fresh conversation, and strips markdown fences from replies. Usage (`tokens_in`/`tokens_out`/`cost`) accumulates per `translate_map()` window. The supported-model list lives only in `AIPT_Settings::model_catalog()` — select labels, the cheat-sheet table, sanitization, and the per-model `reasoning.effort` sent by the gateway all derive from it.

## Conventions

- WordPress style: tabs for PHP/CSS indentation, same-line braces, `array()` syntax, strict comparisons, early returns.
- Prefix everything global (functions, hooks, options, script handles, CSS classes) with `AIPT_`/`aipt_`. Class files: `includes/class-aipt-<name>.php` → `AIPT_<Name>`. Keep the bootstrap small; new behavior goes in a focused class under `includes/`.
- AJAX handlers check nonce + capability first (`AIPT_Metabox::guard()`); sanitize request data, escape at render time. User-facing strings use the `ai-polylang-translator` text domain (translations in `languages/`: en source, ru_RU, uk).
- The API key is write-only in the UI: empty input keeps the stored key, and it is never echoed back into the form.
- JavaScript stays vanilla and admin-page compatible (no build step).
