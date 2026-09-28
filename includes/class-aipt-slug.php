<?php
// Slugs for newly created translations (posts and terms).
// Polylang Free does not share slugs across languages, so a translated title that
// transliterates to the source slug would get WordPress's numeric suffix
// (/uk/futbol-2/). Such collisions get a language suffix instead (/uk/futbol-uk/).
// Detection goes through wp_unique_post_slug()/wp_unique_term_slug(), so setups that
// allow shared slugs (e.g. Polylang Pro filters) see no collision and get no suffix.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Slug {

	const MAX_LENGTH = 200;

	// Model output may carry entities (&amp; → "-amp-") and accents that transliteration
	// plugins such as cyr3lat turn into dashes (Polyák → "poly-k").
	public static function from_title(string $title): string {
		$title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		return sanitize_title(remove_accents($title));
	}

	// Always checked as 'publish': WordPress skips the uniqueness check for drafts and
	// would only add "-2" later, when the draft is published.
	public static function for_post(string $slug, string $lang, int $post_id, string $post_type, int $parent): string {
		return self::resolve($slug, $lang, static function (string $candidate) use ($post_id, $post_type, $parent): string {
			return wp_unique_post_slug($candidate, $post_id, 'publish', $post_type, $parent);
		});
	}

	public static function for_term(string $slug, string $lang, string $taxonomy, int $parent): string {
		$term = (object) array('taxonomy' => $taxonomy, 'parent' => $parent);
		return self::resolve($slug, $lang, static function (string $candidate) use ($term): string {
			return wp_unique_term_slug($candidate, $term);
		});
	}

	// post_name and term slugs are both varchar(200): trim the base so the suffix survives.
	public static function with_lang(string $slug, string $lang): string {
		$suffix = '-' . sanitize_title($lang);
		return _truncate_post_slug($slug, self::MAX_LENGTH - strlen($suffix)) . $suffix;
	}

	private static function resolve(string $slug, string $lang, callable $unique): string {
		if ($slug === '') {
			return '';
		}
		if ($unique($slug) === $slug) {
			return $slug;
		}
		// Let WordPress uniquify further only if the language-suffixed slug is taken too.
		return $unique(self::with_lang($slug, $lang));
	}
}
