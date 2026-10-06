<?php
// Reusable block references (core/block, `<!-- wp:block {"ref":N} /-->`) in the target
// language, at two points:
// - remap(): in a written translation's post content, every ref whose block N has a
//   translation into the target language is pointed at that translation; a ref without
//   one is left as it is. Only the core/block delimiter with a ref-only JSON object is
//   touched, everything around the ID stays byte-identical. Called by the writer on the
//   assembled content (translated chunks after AIPT_Blocks::restore(), unsent chunks, or
//   the source content), before safe mode keeps a filled target content.
// - filter_render(): on render_block_data (registered in every context when an adapter is
//   loaded), a core/block whose ref has a translation into the language of the post being
//   rendered renders that translation, so a page translated before its blocks (or edited
//   by hand) still shows the target-language block, whichever was translated first.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Block_Refs {

	// <!-- wp:block {"ref":123} /--> as WordPress serializes it (also the explicit
	// core/ namespace and whitespace variations); group 2 is the referenced block ID.
	const PATTERN = '~(<!--\s+wp:(?:core/)?block\s+\{\s*"ref"\s*:\s*)(\d+)(\s*\}\s+/-->)~';

	public static function register(): void {
		add_filter('render_block_data', array(__CLASS__, 'filter_render'));
	}

	/**
	 * render_block_data: point a core/block's ref at its translation into the language of
	 * the global post being rendered; unchanged when there is no such post, it has no
	 * language, or the block has no other translation in it.
	 */
	public static function filter_render($block) {
		if (!is_array($block) || ($block['blockName'] ?? '') !== 'core/block' || !is_numeric($block['attrs']['ref'] ?? null)) {
			return $block;
		}
		$post_id = (int) get_the_ID();
		if (!$post_id) {
			return $block;
		}
		$lang = (string) aipt_lang()->post_language($post_id);
		if ($lang === '') {
			return $block;
		}
		$ref         = (int) $block['attrs']['ref'];
		$translation = $ref ? aipt_lang()->post_translation($ref, $lang) : 0;
		if ($translation && $translation !== $ref) {
			$block['attrs']['ref'] = $translation;
		}
		return $block;
	}

	public static function remap(string $content, string $target): string {
		if (!str_contains($content, 'block')) {
			return $content;
		}
		$ids      = array();
		$remapped = preg_replace_callback(
			self::PATTERN,
			static function (array $match) use (&$ids, $target): string {
				$ref = (int) $match[2];
				if (!array_key_exists($ref, $ids)) {
					$ids[$ref] = $ref ? aipt_lang()->post_translation($ref, $target) : 0;
				}
				return $ids[$ref] ? $match[1] . $ids[$ref] . $match[3] : $match[0];
			},
			$content
		);
		// PCRE failure: keep the content, refs unchanged.
		return is_string($remapped) ? $remapped : $content;
	}
}
