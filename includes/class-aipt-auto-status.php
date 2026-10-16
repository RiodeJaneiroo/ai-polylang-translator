<?php
// Status of the automatic translation of one source post (AIPT_Auto), kept in one post
// meta on the source so the AI Translation box can show it and offer a retry:
//
//   status   scheduled | running | done | failed
//   time     scheduled: when the event runs; running: the run's last progress (its
//            start, then every language row written); done/failed: when it finished
//            (UTC timestamps)
//   attempt  0 on the first schedule, +1 per automatic reschedule
//   user_id  the event's user argument (the event is looked up by post + user)
//   message  post-level message (a pre-check failure, an exception), else ''
//   run      token of the run that owns the status (running() sets it, scheduled()
//            clears it): lang() and finish() of an older run change nothing
//   langs    lang => {state: pending|running|done|failed|skipped, message, time,
//            existing (only when true: a translation already existed, not updated)}
//
// get() adds 'post_id' to what it returns; it is not stored. A marked post without a
// status (left by a version before 1.8.0) is handled by AIPT_Auto::legacy_retryable().

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Auto_Status {

	const META = '_aipt_auto_status';
	// A run without progress (a language row written) for this long was killed (PHP time
	// limit, host). Measured from the last progress, not the start: one language may take
	// up to 900 s and a post may have many.
	const STALE = HOUR_IN_SECONDS;
	// A scheduled event this far past its time is overdue: lost when it no longer exists
	// (plugin deactivated with it queued, a fatal error before the run started), else
	// still queued and WP-Cron has not run it.
	const LOST = 5 * MINUTE_IN_SECONDS;

	const STATUSES = array('scheduled', 'running', 'done', 'failed');
	const STATES   = array('pending', 'running', 'done', 'failed', 'skipped');

	public static function get(int $post_id): ?array {
		$raw = get_post_meta($post_id, self::META, true);
		if (!is_array($raw) || !in_array($raw['status'] ?? null, self::STATUSES, true)) {
			return null;
		}
		$langs = array();
		foreach ((array) ($raw['langs'] ?? array()) as $lang => $row) {
			if ((string) $lang !== '' && is_array($row) && in_array($row['state'] ?? null, self::STATES, true)) {
				$langs[(string) $lang] = self::row($row['state'], (string) ($row['message'] ?? ''), (int) ($row['time'] ?? 0), !empty($row['existing']));
			}
		}
		return array(
			'status'  => $raw['status'],
			'time'    => (int) ($raw['time'] ?? 0),
			'attempt' => (int) ($raw['attempt'] ?? 0),
			'user_id' => (int) ($raw['user_id'] ?? 0),
			'message' => (string) ($raw['message'] ?? ''),
			'run'     => (string) ($raw['run'] ?? ''),
			'langs'   => $langs,
			'post_id' => $post_id,
		);
	}

	/**
	 * @param int $attempt 0 (a first schedule, a re-publish or Retry): every selected
	 *                     language becomes pending except rows already done, which are
	 *                     kept. An automatic retry (> 0) keeps the language rows: finished
	 *                     ones as they are, the transient failures pending with their
	 *                     messages.
	 */
	public static function scheduled(int $post_id, int $when, int $attempt, int $user_id): void {
		$rows = self::get($post_id)['langs'] ?? array();
		if ($attempt === 0) {
			$kept = array();
			foreach (AIPT_Settings_Auto::languages(AIPT_Settings::get()) as $lang) {
				$lang        = (string) $lang;
				$done        = ($rows[$lang]['state'] ?? '') === 'done';
				$kept[$lang] = $done ? $rows[$lang] : self::row('pending', '', time());
			}
			$rows = $kept;
		}
		self::save($post_id, array(
			'status'  => 'scheduled',
			'time'    => $when,
			'attempt' => $attempt,
			'user_id' => $user_id,
			'message' => '',
			'langs'   => $rows,
		));
	}

	/**
	 * @param int      $user_id The event's user, stored when the status has none (an
	 *                          event queued before 1.8 has no status).
	 * @param string[] $langs   The languages this run handles: rows of other languages
	 *                          (deselected since the event was scheduled) are dropped.
	 * @return string The run token for lang() and finish().
	 */
	public static function running(int $post_id, int $user_id, array $langs): string {
		$status            = self::get($post_id) ?? self::blank();
		$status['status']  = 'running';
		$status['time']    = time();
		$status['message'] = '';
		$status['run']     = wp_generate_uuid4();
		$status['user_id'] = $status['user_id'] ?: $user_id;
		$status['langs']   = array_intersect_key($status['langs'], array_flip($langs));
		self::save($post_id, $status);
		return $status['run'];
	}

	/**
	 * While running, every row written is progress: the status time moves with it (stale()).
	 *
	 * @return bool False when the status belongs to another run (nothing written).
	 */
	public static function lang(int $post_id, string $run, string $lang, string $state, string $message = '', bool $existing = false): bool {
		$status = self::get($post_id);
		if ($status === null || $status['run'] !== $run) {
			return false;
		}
		$status['langs'][$lang] = self::row($state, $message, time(), $existing);
		if ($status['status'] === 'running') {
			$status['time'] = time();
		}
		self::save($post_id, $status);
		return true;
	}

	// done when every language is done, else failed. A language still pending or running
	// (retries exhausted) is failed with its message. Nothing when the status belongs to
	// another run.
	public static function finish(int $post_id, string $run): void {
		$status = self::get($post_id);
		if ($status === null || $status['run'] !== $run) {
			return;
		}
		$done = true;
		foreach ($status['langs'] as $lang => $row) {
			if ($row['state'] === 'pending' || $row['state'] === 'running') {
				$row['state']            = 'failed';
				$row['message']          = $row['message'] !== '' ? $row['message'] : __('The translation did not finish.', 'ai-polylang-translator');
				$row['time']             = time();
				$status['langs'][$lang] = $row;
			}
			$done = $done && $row['state'] === 'done';
		}
		$status['status']  = $done ? 'done' : 'failed';
		$status['time']    = time();
		$status['message'] = '';
		self::save($post_id, $status);
	}

	// Post-level failure. Unfinished lang rows are left as they are (lines() hides them).
	public static function fail(int $post_id, string $message): void {
		$status            = self::get($post_id) ?? self::blank();
		$status['status']  = 'failed';
		$status['time']    = time();
		$status['message'] = $message;
		self::save($post_id, $status);
	}

	/**
	 * Whether re-publishing the post or the metabox's Retry may schedule it again: the
	 * last run failed, its event was lost (gone from the cron queue: the plugin was
	 * deactivated with it queued, or a fatal error before the run started), or its run
	 * made no progress for STALE. An overdue event that is still queued is not retryable
	 * (a retry would only queue a second one): WP-Cron has to run it.
	 *
	 * @param array|null $status get().
	 */
	public static function retryable(?array $status): bool {
		return $status !== null && self::retryable_with($status, self::overdue($status));
	}

	/**
	 * Display text for the AI Translation box.
	 *
	 * @param array $status    get().
	 * @param array $languages aipt_lang()->languages().
	 * @return array{post: string, langs: array<int, array{code: string, state: string, text: string}>, retryable: bool}
	 *               Languages in the site's language order; in a finished status,
	 *               languages that never finished are left out (the post line says why).
	 *               retryable: retryable() of the status.
	 */
	public static function lines(array $status, array $languages): array {
		$post = $status['message'];
		if ($post !== '' && $status['status'] === 'failed') {
			/* translators: %s: error message */
			$post = sprintf(__('Automatic translation failed: %s', 'ai-polylang-translator'), $post);
		}

		$final   = in_array($status['status'], array('done', 'failed'), true);
		// One cron lookup for all lines.
		$overdue = self::overdue($status);
		$order = array_flip(array_keys($languages));
		$langs = $status['langs'];
		uksort($langs, static fn($a, $b): int => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));

		$lines = array();
		foreach ($langs as $lang => $row) {
			$unfinished = $row['state'] === 'pending' || $row['state'] === 'running';
			if ($final && $unfinished) {
				continue;
			}
			$lines[] = array(
				'code'  => (string) $lang,
				'state' => $row['state'],
				'text'  => self::text($status, $row, $overdue),
			);
		}
		return array('post' => $post, 'langs' => $lines, 'retryable' => self::retryable_with($status, $overdue));
	}

	// retryable() with overdue() of the status already looked up.
	private static function retryable_with(array $status, string $overdue): bool {
		switch ($status['status']) {
			case 'failed':
				return true;
			case 'scheduled':
				return $overdue === 'lost';
			case 'running':
				return self::stale($status);
		}
		return false;
	}

	// $overdue: overdue() of the status.
	private static function text(array $status, array $row, string $overdue): string {
		switch ($row['state']) {
			case 'pending':
				if ($status['status'] === 'running') {
					return self::stale($status)
						/* translators: %s: time */
						? sprintf(__('Automatic translation stopped before this language (no progress since %s)', 'ai-polylang-translator'), self::when($status['time']))
						: __('Waiting: automatic translation of the other languages is running', 'ai-polylang-translator');
				}
				if ($overdue === 'lost') {
					/* translators: %s: time the event was due */
					return sprintf(__('Automatic translation did not run (the scheduled event for %s is gone)', 'ai-polylang-translator'), self::when($status['time']));
				}
				if ($overdue === 'queued') {
					/* translators: %s: time the event was due */
					return sprintf(__('Automatic translation was due at %s and has not run yet — is WP-Cron running? (see README: server cron)', 'ai-polylang-translator'), self::when($status['time']));
				}
				/* translators: %s: time */
				$text = sprintf(__('Automatic translation is scheduled for %s', 'ai-polylang-translator'), self::when($status['time']));
				if ($status['attempt'] > 0) {
					/* translators: %d: retry number */
					$text .= ' ' . sprintf(__('(retry %d)', 'ai-polylang-translator'), $status['attempt']);
				}
				return $text;
			case 'running':
				return self::stale($status)
					/* translators: %s: time */
					? sprintf(__('Automatic translation started at %s and did not finish', 'ai-polylang-translator'), self::when($row['time']))
					/* translators: %s: time */
					: sprintf(__('Automatic translation is running since %s', 'ai-polylang-translator'), self::when($row['time']));
			case 'done':
				if (!empty($row['existing'])) {
					return __('A translation already existed (not updated)', 'ai-polylang-translator');
				}
				if ($row['message'] !== '') {
					/* translators: %1$s: time, %2$s: warning message */
					return sprintf(__('Translated automatically at %1$s, with a warning: %2$s', 'ai-polylang-translator'), self::when($row['time']), $row['message']);
				}
				/* translators: %s: time */
				return sprintf(__('Translated automatically at %s', 'ai-polylang-translator'), self::when($row['time']));
			case 'failed':
				/* translators: %s: error message */
				return sprintf(__('Automatic translation failed: %s', 'ai-polylang-translator'), $row['message']);
		}
		/* translators: %s: reason */
		return sprintf(__('Automatic translation skipped: %s', 'ai-polylang-translator'), $row['message']);
	}

	// '' (not overdue), 'queued' (still in the cron queue: WP-Cron has not run it) or
	// 'lost' (gone from the queue).
	private static function overdue(array $status): string {
		if ($status['status'] !== 'scheduled' || $status['time'] >= time() - self::LOST) {
			return '';
		}
		return wp_next_scheduled(AIPT_Auto::HOOK, array((int) $status['post_id'], (int) $status['user_id'])) ? 'queued' : 'lost';
	}

	private static function stale(array $status): bool {
		return $status['status'] === 'running' && $status['time'] < time() - self::STALE;
	}

	// Site time format; with the date when it is not today.
	private static function when(int $time): string {
		$format = (string) get_option('time_format');
		if (wp_date('Y-m-d', $time) !== wp_date('Y-m-d')) {
			$format = get_option('date_format') . ' ' . $format;
		}
		return (string) wp_date($format, $time);
	}

	private static function row(string $state, string $message, int $time, bool $existing = false): array {
		$row = array('state' => $state, 'message' => $message, 'time' => $time);
		if ($existing) {
			$row['existing'] = true;
		}
		return $row;
	}

	private static function blank(): array {
		return array('status' => 'running', 'time' => time(), 'attempt' => 0, 'user_id' => 0, 'message' => '', 'run' => '', 'langs' => array());
	}

	private static function save(int $post_id, array $status): void {
		unset($status['post_id']);
		// update_metadata() unslashes the value.
		update_post_meta($post_id, self::META, wp_slash($status));
	}
}
