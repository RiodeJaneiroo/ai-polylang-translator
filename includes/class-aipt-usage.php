<?php
// Translation cost log: per-job entries plus running totals, stored in options.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Usage {

	const OPTION_LOG    = 'aipt_usage_log';    // entries newest first, capped at MAX_ENTRIES.
	const OPTION_TOTALS = 'aipt_usage_totals';
	const MAX_ENTRIES   = 100;

	// Record (or incrementally update) usage for one batch of a job.
	// $delta keys: tokens_in (int), tokens_out (int), cost (float).
	// Called at request time — tokens are billed even when the reply is rejected,
	// so we record immediately regardless of whether the translation succeeded.
	// Idempotent per job_id: subsequent calls for the same job accumulate into the
	// existing log entry rather than creating a new row.
	public static function record_job_usage(array $job_meta, string $job_id, array $delta): void {

		$tokens_in  = (int) ($delta['tokens_in']  ?? 0);
		$tokens_out = (int) ($delta['tokens_out'] ?? 0);
		$cost       = (float) ($delta['cost']     ?? 0.0);

		// Nothing to record.
		if (!$tokens_in && !$tokens_out && !$cost) {
			return;
		}

		// Serialise concurrent batch writes with a global named lock so
		// read-modify-write on the log and totals options is effectively atomic.
		// The lock spans only the option read-modify-write below, never the gateway
		// call — the 30 s TTL is a safety net for a crashed writer, not a request budget.
		$lock_key      = 'aipt_usage_lock';
		$lock_ttl      = 30;
		$lock_acquired = AIPT_Job::acquire_named_lock($lock_key, $lock_ttl, 1600, 20000);

		// Never run the option read-modify-write without the lock: doing so would let
		// concurrent batches overwrite each other's paid usage. The retry window exceeds
		// the lock TTL, so normal contention either releases cleanly or becomes stale and
		// is taken over. Exhaustion therefore indicates a storage failure that prevents
		// option persistence altogether.
		if (!$lock_acquired) {
			error_log('AI Translator for Polylang & WPML: could not acquire the usage log lock.');
			return;
		}

		try {
			// Bypass the object cache so we read the values committed by whichever
			// concurrent request last held the lock.
			wp_cache_delete(self::OPTION_LOG,    'options');
			wp_cache_delete(self::OPTION_TOTALS, 'options');

			$log     = self::log();
			$is_new  = true;

			// Look for an existing entry for this job and update it in-place.
			foreach ($log as &$entry) {
				if (($entry['job_id'] ?? '') === $job_id) {
					$entry['tokens_in']  += $tokens_in;
					$entry['tokens_out'] += $tokens_out;
					$entry['cost']       += $cost;
					$entry['time']        = time();
					$is_new = false;
					break;
				}
			}
			unset($entry);

			if ($is_new) {
				$post_id = (int) ($job_meta['post_id'] ?? 0);
				$new_entry = array(
					'job_id'     => $job_id,
					'time'       => time(),
					'post_id'    => $post_id,
					// Term runs have no post; they pass their own title (e.g. the taxonomy).
					'title'      => isset($job_meta['title']) ? (string) $job_meta['title'] : get_the_title($post_id),
					'target'     => (string) ($job_meta['target'] ?? ''),
					'model'      => (string) ($job_meta['model'] ?? AIPT_Settings::get()['model']),
					'tokens_in'  => $tokens_in,
					'tokens_out' => $tokens_out,
					'cost'       => $cost,
				);
				array_unshift($log, $new_entry);
				// Known limitation: if this job's entry was already evicted from the
				// 100-entry cap between batches, a second entry is created and 'jobs'
				// is incremented again. Token/cost sums remain correct.
				$log = array_slice($log, 0, self::MAX_ENTRIES);
			}

			update_option(self::OPTION_LOG, $log, false);

			$totals = self::stored_totals();
			$today  = wp_date('Y-m-d');
			if (($totals['day'] ?? '') !== $today) {
				$totals['day']      = $today;
				$totals['day_cost'] = 0.0;
			}

			$totals['total_cost'] += $cost;
			$totals['tokens_in']  += $tokens_in;
			$totals['tokens_out'] += $tokens_out;
			$totals['day_cost']   += $cost;
			// Increment the job counter only on the first batch — subsequent batches
			// for the same job update the existing entry without bumping the count.
			if ($is_new) {
				$totals['jobs'] += 1;
			}

			update_option(self::OPTION_TOTALS, $totals, false);

		} finally {
			AIPT_Job::release_named_lock($lock_key);
		}
	}

	public static function log(): array {
		$log = get_option(self::OPTION_LOG, array());
		return is_array($log) ? $log : array();
	}

	public static function totals(): array {
		$totals = self::stored_totals();
		if (($totals['day'] ?? '') !== wp_date('Y-m-d')) {
			$totals['day_cost'] = 0.0;
		}
		return $totals;
	}

	private static function stored_totals(): array {
		$defaults = array(
			'total_cost' => 0.0,
			'tokens_in'  => 0,
			'tokens_out' => 0,
			'jobs'       => 0,
			'day'        => '',
			'day_cost'   => 0.0,
		);
		$totals = get_option(self::OPTION_TOTALS, array());
		if (!is_array($totals)) {
			$totals = array();
		}
		return array_merge($defaults, $totals);
	}
}
