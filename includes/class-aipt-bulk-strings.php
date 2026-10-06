<?php
// Strings section of the bulk admin page (assets/bulk-strings.js): WPML String Translation
// domains with their counts, and one domain × language per request through
// AIPT_Strings::translate() with the parameters of `wp aipt translate-strings` (dry run
// for Estimate). A domain is sent in parts of at most MAX_BATCHES gateway batches
// (AIPT_Strings::slice(), next → after), so an interruption re-pays at most one part. Every action first runs AIPT_Bulk_Request::guard().

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Bulk_Strings {

	// Gateway batches per request (AIPT_Strings::slice()), like AIPT_Bulk_Terms.
	const MAX_BATCHES = 2;
	// Lock rows aipt_bulk_strings_<md5(domain)>_<lang>: one part of a pair at a time.
	const LOCK_PREFIX = 'aipt_bulk_strings_';

	public function __construct() {
		add_action('wp_ajax_aipt_bulk_string_domains', array($this, 'ajax_domains'));
		add_action('wp_ajax_aipt_bulk_strings', array($this, 'ajax_strings'));
	}

	/**
	 * Input from, targets[]. Output {domains: [{domain, total, untranslated: {lang: n}}]}
	 * by domain name; untranslated is 0 for a target in which the domain has no gaps.
	 */
	public function ajax_domains(): void {
		AIPT_Bulk_Request::guard();
		$from    = AIPT_Bulk_Request::from();
		$targets = AIPT_Bulk_Request::targets($from);

		$domains = array();
		foreach ($targets as $target) {
			$rows = aipt_lang()->string_domains($from, $target);
			if (is_wp_error($rows)) {
				AIPT_Bulk_Request::send($rows);
			}
			foreach ($rows as $row) {
				$name = $row['domain'];
				if (!isset($domains[$name])) {
					$domains[$name] = array(
						'domain'       => $name,
						'total'        => $row['total'],
						'untranslated' => array_fill_keys($targets, 0),
					);
				}
				$domains[$name]['untranslated'][$target] = $row['untranslated'];
			}
		}
		ksort($domains, SORT_STRING);
		wp_send_json_success(array('domains' => array_values($domains)));
	}

	/**
	 * Input domain, from, target, update (1 = re-translate finished strings, CLI --mode=update),
	 * dry_run (1 = Estimate: no API calls, no writes), after (string ID from the previous
	 * part's next, 0 = start). Output {rows: [{id, name, status, cost, chars, message}] for
	 * the strings that are not skipped, counts: {status: n} for all of them, chars, cost,
	 * next (null = the domain is done), remaining, error_code (gateway code of a failed
	 * batch, '' if none)}. 'aipt_strings_busy' (retry) while another request runs the pair.
	 */
	public function ajax_strings(): void {
		AIPT_Bulk_Request::guard();
		$from   = AIPT_Bulk_Request::from();
		$lang   = AIPT_Bulk_Request::target($from);
		$domain = sanitize_text_field(wp_unslash((string) ($_POST['domain'] ?? '')));
		if ($domain === '') {
			AIPT_Bulk_Request::fail('aipt_invalid_domain', __('Select at least one string domain.', 'ai-polylang-translator'));
		}
		$update  = !empty($_POST['update']);
		$dry_run = !empty($_POST['dry_run']);
		$after   = absint($_POST['after'] ?? 0);

		$strings = aipt_lang()->strings($domain, $from, $lang);
		if (is_wp_error($strings)) {
			AIPT_Bulk_Request::send($strings);
		}
		$slice = AIPT_Strings::slice($strings, $update, $after, self::MAX_BATCHES);
		if ($dry_run) {
			AIPT_Bulk_Request::send(self::run($domain, $slice, $from, $lang, $update, AIPT_Settings::price_per_char()));
		}

		// At most MAX_BATCHES gateway batches, each within the batch-lock budget.
		$ttl = self::MAX_BATCHES * AIPT_Pipeline::batch_lock_ttl();
		if (function_exists('set_time_limit')) {
			set_time_limit($ttl);
		}
		// A retry sent while an earlier request still runs (its client timed out) waits
		// instead of paying for the same strings twice.
		AIPT_Bulk_Request::send(AIPT_Job::with_named_lock(
			self::LOCK_PREFIX . md5($domain) . '_' . $lang,
			$ttl,
			static function () use ($domain, $from, $lang, $update, $after) {
				// Re-read under the lock: an earlier request may have saved some of them.
				$strings = aipt_lang()->strings($domain, $from, $lang);
				if (is_wp_error($strings)) {
					return $strings;
				}
				return self::run($domain, AIPT_Strings::slice($strings, $update, $after, self::MAX_BATCHES), $from, $lang, $update, null);
			},
			new WP_Error('aipt_strings_busy', __('These strings are being translated by another request. Try again in a minute.', 'ai-polylang-translator')),
			1
		));
	}

	// One part of a domain × language: AIPT_Strings::translate() rows in the response shape.
	private static function run(string $domain, array $slice, string $from, string $lang, bool $update, ?float $price): array {
		$result = array(
			'rows'       => array(),
			'counts'     => array(),
			'chars'      => 0,
			'cost'       => 0.0,
			'next'       => $slice['next'],
			'remaining'  => $slice['remaining'],
			'error_code' => '',
		);
		AIPT_Strings::translate($domain, $slice['strings'], $from, $lang, $update, $price, static function (array $row) use (&$result): void {
			$status                    = $row['status'];
			$result['counts'][$status] = ($result['counts'][$status] ?? 0) + 1;
			$result['chars']          += (int) ($row['chars'] ?? 0);
			$result['cost']           += (float) $row['cost'];
			if (!empty($row['code']) && $result['error_code'] === '') {
				$result['error_code'] = (string) $row['code'];
			}
			if (str_starts_with($status, 'skipped_')) {
				return;
			}
			$result['rows'][] = array(
				'id'      => (int) $row['string']['id'],
				'name'    => (string) $row['string']['name'],
				'status'  => $status,
				'cost'    => (float) $row['cost'],
				'chars'   => (int) ($row['chars'] ?? 0),
				'message' => (string) $row['message'],
			);
		});
		return $result;
	}
}
