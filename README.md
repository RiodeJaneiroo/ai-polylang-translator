# AI Translator for Polylang & WPML

WordPress plugin that translates posts, pages, WooCommerce products, taxonomy terms, Yoast SEO fields and ACF fields into the other languages of a Polylang or WPML site, using models served through the Vercel AI Gateway. The multilingual plugin keeps owning languages, translation groups and sync; this plugin only supplies the translated text.

Plugin slug, text domain and file names stay `ai-polylang-translator` for compatibility with existing installs.

## Requirements

- PHP 8.1+, WordPress 6.0+
- Polylang **or** WPML (Multilingual CMS). If both are active, the plugin refuses to translate and shows a notice until one is deactivated.
- A Vercel AI Gateway API key
- Optional: WooCommerce (+ WooCommerce Multilingual on WPML), Yoast SEO, ACF

## Settings (Settings > AI Translator)

- **Status / Enable AI Translator**: the global switch. Off parks the plugin so the multilingual plugin's own translation tools can be used alone: no AI Translation box, every AJAX request refused, no automatic translation (queued events do nothing), `wp aipt` refuses to start and a running bulk command stops before the next record. Settings, the cost log and the API-key test stay available; turning it back on needs no migration.
- **API**: key (write-only: an empty field keeps the stored key), key test, model, site context for the prompt.
- **Post types**: which public post types get the AI Translation box and can be translated (including `product`).
- **Automatic translation**: translate a post in the default language when it is first published (off by default), target languages.
- **Advanced**:
  - *Extra meta keys to translate*: plain-text custom fields, one meta key per line (e.g. a price-unit label). Only non-empty string values are sent; an empty source value never clears the translation's value.
  - *WPML translation editor* (WPML only, default on): see below.
- **ACF fields**: per-field translate / copy / don't touch overrides.
- **Cost log**: the latest 100 translations with tokens and cost, plus totals.

## Usage

- **Editor**: the "AI Translation" box on a post lists the target languages. A new translation is created as a draft; an existing one asks for confirmation before it is overwritten, or can be updated in safe mode (filled fields of the translation are kept). Failed steps can be retried.
- **Automatic translation on publish**: one background event two minutes after the first publish translates the post's missing terms, then the post, into every selected language without a real translation. Existing translations are never updated.
- **WP-CLI**: `wp aipt translate` (bulk posts, resumable, `--dry-run` shows the estimated cost) and `wp aipt translate-terms` (term names and descriptions). See `wp help aipt translate`.

## WPML

On a WPML site the plugin writes only translated text; language assignment, translation groups (trid), TM status, media and WooCommerce Multilingual sync stay WPML's job and are reached only through WPML's public hooks. If a hook the plugin relies on is missing (for example after a WPML update), nothing is written and an error is shown.

- **Duplicates**: a WPML duplicate counts as untranslated. It is overwritten in place (same post ID, always in full, never in safe mode), the duplicate flag is removed, and automatic translation and `wp aipt translate` treat it as missing.
- **Pending translation jobs**: a language pair with translation work in progress in WPML (a Translation Management / Advanced Translation Editor job or a placeholder) is never written. The editor shows a message, WP-CLI and automatic translation skip the pair.
- **TM status**: after writing, the translation is marked complete in WPML Translation Management, so the Translation Dashboard shows it as translated.
- **Native editor setting**: when the first AI translation of a post is written, the original is switched to WPML's native-editor mode, so opening the translation in wp-admin does not redirect to WPML's translation editor. Can be turned off in Advanced settings.
- `wpml-config.xml` keeps the plugin's internal post meta out of WPML's custom-field sync.

## WooCommerce

Products translate their title, description, short description, Yoast fields and extra meta keys, plus the custom (non-taxonomy) product attributes: each option of an attribute value is translated, and the attribute label is stored where WooCommerce Multilingual reads label translations. Safe mode keeps filled options and labels of the attributes the source has; the set of attributes always follows the source (WooCommerce Multilingual rebuilds it from the original), so attributes that exist only on the translation are dropped. Taxonomy attributes (`pa_*`) are terms and are translated with `wp aipt translate-terms`. Prices, SKU, stock, images, variations and taxonomy links are synced by WooCommerce Multilingual on WPML.

## Gutenberg and Kadence blocks

Block delimiter comments (`<!-- wp:… {json} -->`, `<!-- /wp:… -->`) are replaced with `[[AIPT-B<n>]]` tokens before content is sent to the model, so block JSON is kept out of the model's input and restored from the source afterwards. If the model drops or changes a token in a part of the content, or a part's block markup cannot be masked, that part keeps its original text, a new translation is left as a draft, and the translation is reported with a warning. Text inside block attributes (e.g. Kadence button text, image alt/caption) is not translated.

## Hooks

`aipt_translation_written` (`$post_id, $source_id, $lang, $created`) fires after every translation write, once meta, attributes and terms are saved, right after `clean_post_cache()`.

## Testing

There is no automated test suite. After a change, run `php -l` on every PHP file (see `CLAUDE.md` for the commands and the multilingual boundary grep), then check manually:

- **Polylang site**: Settings page (key test, cost log), metabox new translation / overwrite / safe mode / retry, `wp aipt translate --dry-run` and one real record, `wp aipt translate-terms`, automatic translation on publish, the disabled state (box hidden, AJAX refused, cron no-op, CLI exits with status 1). Test with and without ACF and Yoast.
- **WPML site**: the same flows, plus overwriting a duplicate (flag removed, TM status complete), refusal while a translation job is pending, the Translation Dashboard showing AI translations as translated, the translation opening in the WordPress editor, a simple and a variable WooCommerce product (labels and options translated, variations, prices and stock synced by WCML), a Kadence page (block markup unchanged), and no PHP notices in cron and WP-CLI runs.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).
