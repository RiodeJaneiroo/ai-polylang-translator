<?php
// Page caches after a translation write. The writer cleans the object cache of the
// translation and the source and fires aipt_translation_written; this class then:
//
// - fires do_action('aipt_purge_urls', $urls, $post_id, $source_id, $lang) when something
//   hooks it: $urls are the permalinks of the translation and the source that are
//   published. Page caches and site code hook it to purge both pages (the source's
//   language switcher and hreflang links change when a translation is linked);
// - purges both posts in LiteSpeed Cache when it is active (its public
//   'litespeed_purge_post' action is hooked; never its classes).
//
// Other cache plugins are left to site code (aipt_purge_urls).

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Cache {

	public static function register(): void {
		add_action('aipt_translation_written', array(__CLASS__, 'purge'), 20, 3);
	}

	/**
	 * @param int    $post_id   The translation.
	 * @param int    $source_id
	 * @param string $lang      Target language.
	 */
	public static function purge($post_id, $source_id, $lang): void {
		$post_id   = (int) $post_id;
		$source_id = (int) $source_id;
		if (has_action('aipt_purge_urls')) {
			$urls = array();
			foreach (array($post_id, $source_id) as $id) {
				if ($id && get_post_status($id) === 'publish') {
					$url = get_permalink($id);
					if ($url) {
						$urls[] = $url;
					}
				}
			}
			do_action('aipt_purge_urls', $urls, $post_id, $source_id, (string) $lang);
		}

		if (has_action('litespeed_purge_post')) {
			do_action('litespeed_purge_post', $post_id);
			do_action('litespeed_purge_post', $source_id);
		}
	}
}
