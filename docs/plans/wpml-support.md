# WPML support: analysis and phased plan

Status: reviewed (pi, 2026-10-03, findings merged below), approved for implementation. Target site for verification: `axio` (Local, `/Users/rio/Local_wp/axio/app/public`), see "Target site" below.

## Goal

Make the plugin work on WPML sites with the same pipeline (metabox, WP-CLI, auto-translate on publish) it has on Polylang sites, without forking the pipeline. Add a global on/off switch so the plugin can be parked while the site is translated with WPML's own tools, and turned back on when needed. WPML has priority: the plugin only supplies translated text and must never break WPML's own flows.

## Analysis

### What depends on the multilingual plugin today

43 `pll_*` call sites in 10 files plus one `PLL()` object access (`AIPT_Writer::suspend_polylang_sync`) and one Polylang-specific query argument (`get_terms(['lang' => …])` in `AIPT_CLI::source_terms`). The distinct operations are few:

| Operation | Polylang | WPML |
|---|---|---|
| language list (codes, names) | `pll_languages_list()` | `wpml_active_languages` filter (`code`, `english_name`, `native_name`, `default_locale`) |
| default language | `pll_default_language()` | `wpml_default_language` filter |
| language of post / term | `pll_get_post_language`, `pll_get_term_language` | `wpml_element_language_details` filter (`language_code`, `source_language_code`, `trid`) |
| translation of post / term in lang | `pll_get_post`, `pll_get_term` | `wpml_object_id` filter with `$return_original_if_missing = false` |
| translation group | `pll_get_post_translations` (lang → id) | `wpml_element_trid` + `wpml_get_element_translations` (lang → object with `element_id`, placeholders have none) |
| terms of one language | `get_terms(['lang' => $l])` | `get_terms` inside `wpml_switch_language($l)` … switch back, or language-neutral query + filter |
| assign language + link | `pll_set_post_language` early, `pll_save_post_translations(map)` late | one call: `wpml_set_element_language_details` action with source trid, `language_code`, `source_language_code` |
| translated post type / taxonomy | `pll_is_translated_post_type`, `pll_is_translated_taxonomy` | `wpml_is_translated_post_type`, `wpml_is_translated_taxonomy` filters |
| suspend sync-on-save | remove `pll_save_post` handlers of `PLL()->sync`, `->sync->post_metas`, `->sync->taxonomies` + metadata filters | remove `save_post`@100 of global `$wpml_post_translations` (WPML_Admin_Post_Actions in admin/REST/CLI, WPML_Frontend_Post_Actions in cron) and WCML `save_post`@PHP_INT_MAX (`WCML\Synchronization\Hooks::synchronizeProductTranslationsOnSave`) |
| drop backend caches | `clean_post_cache` (current pipeline behaviour) | `$wpml_post_translations->reload()`, `$wpml_term_translations->reload()` (`WPML_Element_Translation::reload`) |
| keep plugin markers out of meta sync | `pll_copy_post_metas` filter | `wpml-config.xml` in plugin root: `<custom-field action="ignore">_aipt_auto_scheduled</custom-field>` and `_aipt_auto_user` |
| pair-lock group id | min post ID of the group | `trid` |

Everything else (gateway, extractor, batching, job/locks, usage, writer body, pipeline, metabox, CLI, auto, settings) is multilingual-agnostic. A thin adapter is the right cut; two parallel modules would duplicate ~90% of the code.

### WPML facts that shape the design (verified in WPML 5.1.0 / WCML 5.6.2 source)

1. **Writing a translation.** `wp_insert_post()` fires WPML's `save_post`@100, which assigns the *current* language (cron: default language) and a new trid, then runs field sync, `sync_with_duplicates()` and `wpml_tm_save_post`. We suspend that handler for the whole write and call `wpml_set_element_language_details` ourselves with the source's trid. After the write we fire `do_action('wpml_tm_save_post', $id, get_post($id), false)` deliberately: it creates/updates the `icl_translation_status` row as `ICL_TM_COMPLETE`, `translation_service = local`, stores the source md5 (so WPML later flags "needs update" when the original changes) and marks the job as edited in the WordPress editor. This is exactly what WPML does for a manually translated post. (`inc/post-translation/wpml-post-translation.class.php:230-277`, `inc/actions/wpml-tm-post-actions.class.php:21-121`.)
2. **Duplicates are not translations.** WPML "Duplicate" creates a copy with meta `_icl_lang_duplicate_of = <source id>`; every save of the original re-copies into it (`sync_with_duplicates`) and TM status is `ICL_TM_DUPLICATE` (9). On axio the 3 "translated" products and the home page are duplicates. A duplicate is *eligible for translation* (overwrite in place, safe mode would keep untranslated source text) and the writer deletes `_icl_lang_duplicate_of` before `wpml_tm_save_post` (what WPML's "Translate independently" does, `translation-management.class.php:632`). No mechanism re-duplicates after the flag is gone.
3. **Pending TM work.** WPML can hold a placeholder row for trid+lang with no `element_id` (ATE/TM job in progress). `wpml_set_element_language_details` fills such a placeholder (`class-wpml-set-language.php:104-120`) and `wpml_tm_save_post` would then mark the job complete. We must detect this state and refuse to write (not just "no post → free").
4. **Translation editor.** Site setting is ATE. Opening a translation we wrote in wp-admin while the original is in "WPML editor" mode redirects to ATE with an empty job. WPML stores a per-post editor preference on the original (`TranslationEditorPreference::POST_META_KEY_EDITOR`, value `EDITOR_NATIVE`). Default behaviour: set the original to the native editor when we write its first translation (setting, default on).
5. **WooCommerce Multilingual** does most product work once the translated product exists and is linked: `WCML\Synchronization\Manager::run()` syncs attachments, `_product_attributes` (keeps *our* translated **values** for non-taxonomy attributes when the post is not a duplicate, `Component/Attributes.php:75-86`; attribute **names** come from the source, translated labels live in post meta `attr_label_translations` on the translated product as `[lang => [attr_key => label]]`, `inc/class-wcml-attributes.php:436-459`), downloadable files, linked products, post parent/menu order/date, stock, taxonomies, meta per WPML preferences (`_price`, `_sku` copy) and **creates translated variations** (`Component/Variations.php:96`). Its `save_post` hook runs only in admin and WP-CLI, so we call `do_action('wcml_synchronize_product_translations', $source_post, [$id], [$id => $lang])` explicitly (registered in every context). **Gotcha:** `Component/Variations.php:57,165` removes WPML's save handler and unconditionally re-adds it, so after WCML returns our suspension is gone; the adapter must re-apply it.
6. **WPML caches translation groups in object properties**, not in the WordPress object cache (`class-wpml-element-translation.php:4-25,222-243`). Re-checks under the pair lock need an explicit `reload()`.
7. **Slugs.** WPML allows the same slug in different languages (filters `wp_unique_post_slug` / `wp_unique_term_slug` per language). `AIPT_Slug` only adds `-{lang}` when `wp_unique_post_slug` reports a collision, so no change is expected; verify on the test site.
8. **Language codes** are two-letter (`uk`, `ru`), same shape as Polylang slugs; locales come from `default_locale`. The model prompt needs the English language name (`english_name`).
9. **Yoast.** WPML SEO does not touch meta on save; WPML TM preferences on axio already mark `_yoast_wpseo_title/metadesc/focuskw` as "translate". Writing them directly is safe.

### Target site (axio)

- WPML 5.1.0 + String Translation + Media + WCML 5.6.2 + WPML SEO, Yoast 28.5, WooCommerce 11.1.2, Kadence Blocks. **No ACF, no ACFML.** Carbon Fields theme options live in `eqlab-axioma` (not post content).
- Languages: `uk` default (no prefix), `ru` under `/ru/`. Translations exist only on production; local has 3 product duplicates and 5 pages (4 real translations made with the WP editor, 1 duplicate).
- Content: 1460 published products (avg content 537 chars, ~1.04 M chars total incl. titles/excerpts, 19 with short description, 30 Yoast title/desc metas), 2864 variations (attributes are all taxonomy terms: `pa_orientation`, `pa_profile-color`), 18 published + 17 draft pages, 2 posts (trash).
- Taxonomies: `product_cat` (19 terms, 7 translated, long descriptions with the FAQ markup contract from the theme), `product_tag`, `pa_handle-color`, `pa_handle-lock`, `pa_opening-type`, `pa_orientation`, `pa_profile-color` translated; `pa_height`, `pa_width`, `product_brand` not translated (copied as-is).
- Custom (non-taxonomy) product attributes: 6 products have text name/value pairs in `_product_attributes` (e.g. "Тип монтажу" → "Під штукатурку"). Site meta `_axioma_price_unit` ("/ шт.") on 2 products is translatable text.
- Products are plain HTML; 17 pages use Kadence blocks whose text lives in inner HTML (block JSON attributes hold no text except `kadence/singlebtn` `text` and image alt/caption).
- Tooling: Local PHP 8.3 + WP-CLI; `wp db` needs the Local mysql socket, use `wp eval` with `$wpdb` instead. Env export is in the theme's CLAUDE.md.

## Design

### Principle: WPML owns the data, we only supply text

On a WPML site the plugin is a translator, not a second translation manager. Concretely:

- We write only translated text (post fields, translatable meta, custom attribute values and WCML label meta, term name/description). Language assignment, translation groups, TM status, variations, prices, stock, taxonomy links and media are WPML's and WCML's job, reached only through their public hooks (`wpml_*` filters/actions, `wpml_tm_save_post`, `wcml_synchronize_product_translations`). No direct writes to `icl_*` tables; read-only `$wpdb` queries on them are allowed only inside the WPML adapter where no public hook exists (pending-job detection).
- Fail loudly, never half-write silently: if a WPML/WCML hook or global we rely on is missing (plugin update renamed something), the write aborts with a `WP_Error` before touching the database. Mutating adapter calls return `true|WP_Error`, and the commit step verifies its postcondition (the target resolves as the source's translation). A post that was inserted but could not be linked is deleted again (nothing is left for a retry to duplicate) and the error is reported; an updated existing post that could not be re-linked keeps its new content and is reported with its ID as a partial write, never as success.
- Never fight WPML's own translations: a target with pending TM work is refused; an existing real translation is updated only when the user explicitly chooses overwrite/safe mode; auto-translate never updates real translations (it does translate duplicates, which WPML itself treats as untranslated copies).
- The global switch parks the plugin completely (no metabox UI *and* no AJAX execution, no auto scheduling or runs, CLI refuses and a running bulk run stops at the next record) so WPML's translation tools can be used alone; turning it back on needs no migration.
- Nothing WPML-specific leaks into the shared code; any WPML behaviour change is a change in one file, `includes/lang/class-aipt-lang-wpml.php`.

### Adapter

`includes/lang/interface-aipt-lang.php` (interface `AIPT_Lang`), `class-aipt-lang-polylang.php`, `class-aipt-lang-wpml.php`, and a small loader that detects the active plugin and requires exactly one implementation. Access everywhere via `aipt_lang()`.

Interface, designed from what the pipeline needs:

```php
interface AIPT_Lang {
	public function name(): string;                       // 'polylang' | 'wpml' (UI text, logs, job payload)
	public function languages(): array;                   // code => ['code','name','locale']
	public function default_language(): string;
	public function language_name(string $code): string;  // English name for the prompt
	public function post_language(int $post_id): ?string;
	public function term_language(int $term_id): ?string;
	public function post_translation(int $post_id, string $lang): int;   // 0 if none
	public function term_translation(int $term_id, string $lang): int;   // 0 if none
	public function post_translations(int $post_id): array;              // lang => id (existing posts only)
	public function translation_state(int $post_id, string $lang): array;
	//   ['state' => 'none'|'pending'|'duplicate'|'translated', 'id' => int]
	//   pending   = backend has in-progress translation work for this pair (WPML placeholder/TM job); never write
	//   duplicate = target post exists but is a backend-managed copy of the source (WPML duplicate); overwrite in place
	public function refresh(): void;                      // drop backend caches; called under the pair lock before every re-check
	public function group_id(int $post_id): int;          // pair-lock key; trid on WPML, min id on Polylang
	public function is_translated_post_type(string $type): bool;
	public function is_translated_taxonomy(string $taxonomy): bool;
	public function terms_in_language(string $taxonomy, string $lang, array $args = array()): array; // WP_Term[]
	public function begin_post(int $new_id, int $source_id, string $lang);        // true|WP_Error; Polylang: set language; WPML: no-op
	public function commit_post(int $new_id, int $source_id, string $lang, bool $created); // true|WP_Error, see below
	public function link_term(int $new_id, int $source_id, string $taxonomy, string $lang); // true|WP_Error
	public function suspend_sync(): array;                // returns state for restore_sync()
	public function restore_sync(array $state): void;     // idempotent: re-adds only hooks that are absent
	public function register_hooks(): void;               // Polylang: pll_copy_post_metas; WPML: nothing (wpml-config.xml)
}
```

`commit_post` is the late group commit. Polylang: `pll_save_post_translations` with the source map (today's line 292 behaviour). WPML: if the target is not yet the source's translation in `$lang`, `wpml_set_element_language_details` into the source trid with `source_language_code` = source language (idempotent for an already-linked target; never re-assigns the group's original member); delete `_icl_lang_duplicate_of`; `wpml_tm_save_post`; set the original's editor preference to native (if the setting is on); for products with WCML, `wcml_synchronize_product_translations`; then **re-apply the suspension** (WCML re-adds WPML's handler); then verify `post_translation($source_id, $lang) === $new_id`.

Rules:
- No `pll_`, `PLL(`, `wpml_`, `icl_`, `WCML`, `SitePress`, `'lang' =>` query args outside `includes/lang/`. Guard: `grep -rnE "pll_|PLL\(|wpml_|icl_|SitePress|WCML|'lang' *=>" --include='*.php' . | grep -v '^./includes/lang/'` must print nothing (added to CLAUDE.md next to `php -l`).
- Writer order (the invariant, extended): `suspend_sync` → insert/update → `begin_post` → ID remap → meta → ACF → taxonomies → `commit_post` → `finish_new_post` (status + slug for new posts, with sync still suspended) → `restore_sync` in `finally`. `commit_post` leaves the hook state exactly as it found it so `finish_new_post` never runs under a live WPML handler.
- `AIPT_Terms` uses `link_term` right after `wp_insert_term`; `AIPT_CLI::source_terms` uses `terms_in_language`.
- `AIPT_Job::pair_lock_key` uses `group_id`. The pipeline calls `refresh()` after acquiring the pair lock and before any existing-translation re-check (`translate_post` with `skip_existing`, finalize).
- Job payload gains `backend` (adapter name) and `schema` (int, 2). Finalize refuses a job whose `backend` differs from the active adapter (error, job left intact). Jobs without `backend` are pre-1.6 Polylang jobs: accepted only when the active adapter is Polylang; their legacy usage-aggregation path stays.
- `translation_state` is checked before spending (prepare / CLI pre-check / auto) and again at finalize/commit under the lock. The job stores the state it was prepared against; if the state at finalize differs (e.g. duplicate became a real translation, or pending work appeared) finalize aborts with a clear error unless the user explicitly chose overwrite/safe for a real translation.
- Bootstrap: if both WPML and Polylang are active → load no adapter, show an admin notice, settings page only. Else WPML → WPML adapter; else Polylang → Polylang adapter; else notice "requires Polylang or WPML".

### Global switch

New setting `enabled` (bool, default true) in `aipt_settings`, first row of the settings page. When off: metabox not registered **and** every AJAX handler returns an error from `guard()`; `AIPT_Auto` neither schedules nor runs (handler bails so already-queued events are dropped); `wp aipt` commands exit with "plugin is disabled in Settings > AI Translator" and a running bulk command re-reads the option before each record and stops. Settings page, cost log and the API-key test stay available. Independent of the multilingual plugin.

### ACF under WPML (ACFML)

On WPML sites ACF policy comes from WPML, not from us: our override UI and `field_overrides` are hidden/ignored when ACFML is active, and the per-field ACFML preference drives extraction *and* writing with WPML's semantics: `translate` → translate; `copy` → copy; `copy-once` → copy when the translation is created, preserve the target value on update; `ignore` → never written (removed from the value tree before write, in create and update alike, regardless of the field's default action). This is integration work in `AIPT_Extractor`, `AIPT_Writer` and `AIPT_ACF_Schema`, not a UI toggle. axio has no ACF, so it is a late phase, gated on its own verification with an ACF+ACFML test site.

### WooCommerce (needed for axio)

- Extractor: for `product`, items `['woo', 'attr', <key>, 'name']` and `['woo', 'attr', <key>, 'value', <i>]` for non-taxonomy attributes (values split on `|`, translated piecewise, keys and positions preserved). Writer (own module `class-aipt-woo.php`, writer is already 653 lines): rebuilds `_product_attributes` with the same keys and the translated values; writes translated names into `attr_label_translations[$lang][key]` on the translated product (WCML's own storage). WCML then preserves values and shows the labels.
- The job payload carries the attribute snapshot explicitly (versioned field, see job rules), so Phase 3a's extractor output and Phase 3b's writer agree on one shape before either lands.
- Pre-checks: `missing_terms` already covers translated taxonomies generically (`product_cat`, `product_tag`, `pa_*`); untranslated taxonomies are copied by WCML.
- New generic setting "Extra meta keys to translate" (one key per line, e.g. `_axioma_price_unit`) extracted as `['meta', key]` like Yoast keys.
- `commit_post` on WPML triggers the WCML product sync (creates translated variations). Polylang + WooCommerce is out of scope (no WCML equivalent).

### Kadence / Gutenberg blocks (optional hardening)

Mask block comment delimiters (`<!-- wp:… {json} -->`, `<!-- /wp:… -->`) with placeholders before sending a chunk and restore them after, so the model never sees block JSON. Text in attributes (`singlebtn` `text`, image `alt`/`caption`) stays untranslated for now.

## Phases

**Phase 1 — Adapter extraction, contracts, global switch (no behaviour change on Polylang).**
Files: new `includes/lang/*` (interface, Polylang adapter, loader), edits in writer, terms, record, job, auto, pipeline, metabox, cli-args, cli, settings, settings-auto, bootstrap; CLAUDE.md commands. Includes every shared contract above: `translation_state` (Polylang returns none/translated only), `refresh`, `terms_in_language`, begin/commit split with the extended writer order, idempotent `restore_sync`, `true|WP_Error` results with commit postcondition, job `backend`/`schema` with the legacy rule, `enabled` enforced in AJAX guard / auto / CLI loop, "both plugins active" refusal, "Polylang" wording replaced by the adapter name. Verify on the Polylang test site: metabox new/overwrite/safe, `wp aipt translate --dry-run` and one real run, `translate-terms`, auto on publish, disabled state (AJAX refused, cron no-op, CLI exits), boundary grep clean. One implementer (opus), one review.

**Phase 2 — WPML adapter.**
`class-aipt-lang-wpml.php` + `wpml-config.xml` + settings wording. Covers: languages, lookups, `translation_state` with duplicate and pending detection, `terms_in_language` via language switch, `commit_post` as specified (link, duplicate flag, `wpml_tm_save_post`, editor preference, WCML hook when present, re-suspend, verify), `suspend_sync`/`restore_sync` for `$wpml_post_translations` and WCML, `refresh` via `reload()`, `group_id` via trid, missing-hook detection → `WP_Error`. Verify on axio with pages: metabox uk→ru for a page, overwrite the duplicate home page (flag removed, TM status complete), `wp aipt translate --post-type=page`, auto on publish of a new page, pending-job refusal (start an ATE job for a page, then try), TM dashboard shows the pages as translated, editing the translation opens the WP editor, no PHP notices in cron/CLI paths.

**Phase 3 — WooCommerce.**
3a (adapter-agnostic, parallel with Phase 2 after the job payload shape is fixed): extractor items for custom attributes, `class-aipt-woo.php` writer part (`_product_attributes` values + `attr_label_translations`), "extra meta keys" setting, `product` in the post-type list.
3b (after 2 and 3a): WCML sync in `commit_post`; verify on axio: a simple product and a variable product (variations created, labels and values in ru, price/SKU/stock synced, category and `pa_*` terms mapped, FAQ markup in `product_cat` descriptions preserved by `translate-terms`), dry-run cost for all 1460 products.

**Phase 4 — Hardening and docs (independent items, parallel with 3).**
Block-comment masking; README/CLAUDE.md/AGENTS.md updates (architecture, WPML notes, test matrix); plugin display name "AI Translator for Polylang & WPML" (slug and text domain unchanged); ru/uk translations of new strings.

**Phase 5 — ACFML integration (needs an ACF + ACFML test site; not required for axio).**
Extraction/write semantics as specified above, override UI hidden in WPML mode.

Parallelism: Phase 1 alone. Then Phase 2 ‖ Phase 3a ‖ Phase 4. Then Phase 3b, final review, release 1.6.0 (phases 1–2) or 1.7.0 if WooCommerce ships separately.

## Decisions (confirmed by the user 2026-10-03, amended after review)

1. Disabled state = no metabox, AJAX refused server-side, no auto, CLI refuses and stops between records; settings stay.
2. After writing a translation on WPML: mark TM complete via `wpml_tm_save_post`, and switch the *original* to the native editor (setting, default on).
3. WPML duplicates are eligible for translation: overwritten in place, duplicate flag removed; state re-validated at finalize.
4. ACF under WPML follows ACFML preferences with WPML's copy-once/ignore semantics; our ACF overrides hidden. Own phase, own test site.
5. Products: we translate title, content, short description, custom attribute names (WCML label meta) and values, Yoast and extra meta keys; prices, SKU, stock, images, variations, taxonomy links are WCML's job.
6. If both Polylang and WPML are active the plugin refuses to translate (notice, settings only). Changed from "WPML wins": selecting one adapter would not disable the other plugin's sync hooks during our write.

## Review outcome (pi, 2026-10-03)

Architecture kept. All findings accepted and folded into the design above: WPML group cache needs `refresh()` under the lock (1); `pending` state for in-progress TM work (2); WCML re-adds WPML's handler, so `commit_post` re-suspends and `finish_new_post` runs under suspension (3); attribute names go through WCML's `attr_label_translations` (4); `enabled` enforced in AJAX/CLI loop, not only in UI registration (5); begin/commit split keeps Polylang's late group commit (6); ACFML copy-once/ignore semantics make it an integration phase (7); `terms_in_language` replaces the Polylang-only `lang` query arg (8); `true|WP_Error` + postcondition for mutating calls (9); duplicates keep their target ID and state is re-validated at finalize, linking never re-assigns the original member (10); both-plugins-active → refuse (11); job `backend`/`schema` fields with the legacy rule (12).

## Risks and open questions

- WPML's save handler is suspended by removing a hook on a global object; a WPML update that renames the class/method must fail loudly (`WP_Error` before any write), not silently write into the wrong language.
- `wpml_tm_save_post` reads `$_POST` (`icl_trid`, `needs_second_update`); in CLI/cron these are empty, which is the path WPML itself uses for programmatic saves. Verify no notice/warning is emitted.
- WCML `Manager::run()` on a product whose translation we just wrote also runs `COMPONENT_POST` (parent/menu order/date) and `COMPONENT_META`; confirm it does not overwrite `post_title`/`post_content` of the translation (code reading says it only touches parent, menu_order, date).
- Pending-work detection has no public WPML filter; the adapter reads `icl_translations` / `icl_translation_status` read-only for the trid+lang. A schema change in WPML must degrade to "pending" (refuse), not to "none".
- Kadence `advancedheading` inner HTML carries the uniqueID class; the model must keep classes intact (existing prompt rule). Masking block comments reduces, not removes, this risk.
- Cost: ~1.04 M chars of product text; the CLI dry-run gives the exact figure per model before the bulk run.
- Local has no production translations; final verification of overwrite/safe mode on real translations happens on a staging copy or on production with `--dry-run` first.
