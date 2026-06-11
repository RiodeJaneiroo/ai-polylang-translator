# Repository Guidelines

## Project Structure & Module Organization

This repository is a standalone WordPress plugin.

- `ai-polylang-translator.php` is the bootstrap: plugin metadata, constants, dependency checks, and class loading.
- `includes/` contains one responsibility per `AIPT_*` class. Settings and metabox classes own admin UI/AJAX; extractor, gateway, job, and writer classes implement the translation pipeline.
- `assets/` contains dependency-free admin JavaScript and CSS.
- `uninstall.php` removes the plugin options.

Keep new PHP behavior in a focused class under `includes/`; keep the bootstrap small. Preserve the flow `prepare -> translate_batch -> finalize`, and the writer order documented in `class-aipt-writer.php`.

## Development & Validation Commands

There is no Composer, npm, or bundled test runner. Work in a local WordPress installation with PHP 8.1+, WordPress 6.0+, and Polylang active. ACF and Yoast SEO are optional integration targets.

```bash
php -l ai-polylang-translator.php
find includes -name '*.php' -exec php -l {} \;
wp plugin activate ai-polylang-translator
```

Run PHP linting after every change. In wp-admin, verify Settings > AI Translator, the API-key test, metabox visibility, new translation creation, overwrite confirmation, and retry behavior. Test both with and without optional plugins enabled.

## Coding Style & Naming Conventions

Follow the existing WordPress-oriented style: tabs for PHP/CSS indentation, braces on the same line, `array()` syntax, strict comparisons, and early returns. Prefix global symbols, hooks, options, script handles, and CSS classes with `AIPT_` or `aipt_`. Class files use `includes/class-aipt-<name>.php`; classes use `AIPT_<Name>`.

Sanitize request data with WordPress helpers, unslash when needed, check capabilities and nonces on AJAX actions, and escape output at render time. Keep user-facing strings translatable with the `ai-polylang-translator` text domain. JavaScript should remain vanilla and compatible with WordPress admin pages.

## Testing Guidelines

No automated suite or coverage threshold exists. Add focused tests if introducing a test framework, especially for HTML chunking, batch limits, JSON response validation, ACF path updates, and Polylang ID remapping. Name PHP tests after the unit and behavior, for example `AIPT_Extractor_Test::test_chunk_html_preserves_content()`.

## Commit & Pull Request Guidelines

This checkout contains no Git history to infer a house convention. Use short, imperative commit subjects, optionally scoped, such as `fix: preserve translated post status`. Pull requests should explain behavior changes, list manual test cases and plugin versions, link relevant issues, and include screenshots for settings or metabox UI changes. Never commit API keys or translated production content.
