<?php
// Translation cost log: per-job entries plus running totals, stored in options.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Usage {

	const OPTION_LOG    = 'aipt_usage_log';    // entries newest first, capped at MAX_ENTRIES.
	const OPTION_TOTALS = 'aipt_usage_totals';
	const MAX_ENTRIES   = 50;

	// The cost comes straight from the gateway response (usage.cost, USD).
	public static function record(int $post_id, string $target, string $model, int $tokens_in, int $tokens_out, float $cost = 0.0): void {

		$entry = array(
			'time'       => time(),
			'post_id'    => $post_id,
			'title'      => get_the_title($post_id),
			'target'     => $target,
			'model'      => $model,
			'tokens_in'  => $tokens_in,
			'tokens_out' => $tokens_out,
			'cost'       => $cost,
		);

		$log = self::log();
		array_unshift($log, $entry);
		$log = array_slice($log, 0, self::MAX_ENTRIES);
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
		$totals['jobs']       += 1;
		$totals['day_cost']   += $cost;

		update_option(self::OPTION_TOTALS, $totals, false);
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
