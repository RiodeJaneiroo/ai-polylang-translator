<?php
// Run log and summary for `wp aipt` commands: one console line and one TSV line
// (time, source_id, target_lang, target_id, status, cost, message) per record, a
// header and a summary line, and the exit status (1 when any record ended in error).

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_CLI_Log {

	private $handle  = null;
	private $counts  = array();
	private $cost    = 0.0;
	private $chars   = 0;
	private $started = 0;
	private $dry_run = false;

	// Opens --log (required unless dry run) and writes the header line.
	public function __construct(array $assoc_args, string $command, bool $dry_run) {
		$this->dry_run = $dry_run;
		$path          = trim((string) ($assoc_args['log'] ?? ''));
		if ($path !== '') {
			$this->handle = self::open($path);
		} elseif (!$dry_run) {
			WP_CLI::error('--log=<path> is required (unless --dry-run).');
		}
		$this->begin($command, $assoc_args);
	}

	/**
	 * @return resource
	 */
	private static function open(string $path) {
		$dir = realpath(dirname($path));
		if ($dir === false || !is_dir($dir)) {
			WP_CLI::error(sprintf('Log directory does not exist: %s', dirname($path)));
		}
		$file = wp_normalize_path($dir) . '/' . basename($path);
		$real = file_exists($file) ? wp_normalize_path((string) realpath($file)) : $file;
		foreach (self::public_roots() as $root) {
			foreach (array($file, $real) as $candidate) {
				if ($candidate === $root || str_starts_with($candidate, $root . '/')) {
					WP_CLI::error(sprintf('--log must point outside %s (the log could be publicly readable there).', $root));
				}
			}
		}

		$handle = fopen($file, 'ab');
		if (!$handle) {
			WP_CLI::error(sprintf('Cannot open the log file for writing: %s', $file));
		}
		return $handle;
	}

	public function record(string $prefix, int $source_id, string $target, array $outcome): void {
		$status = $outcome['status'];
		$this->counts[$status] = ($this->counts[$status] ?? 0) + 1;
		$this->cost           += $outcome['cost'];
		// Dry run: text size counted towards the summary's estimate.
		$this->chars          += (int) ($outcome['chars'] ?? 0);

		$line = sprintf('%s #%d -> %s: %s', $prefix, $source_id, $target, $status);
		if ($outcome['target_id']) {
			$line .= ' #' . $outcome['target_id'];
		}
		if ($outcome['cost'] > 0) {
			$line .= sprintf(' ($%.4f)', $outcome['cost']);
		}
		if ($outcome['message'] !== '') {
			$line .= ' — ' . $outcome['message'];
		}
		if (in_array($status, array('error', 'skipped_parent_missing', 'created_with_warning', 'updated_with_warning'), true)) {
			WP_CLI::warning($line);
		} else {
			WP_CLI::log($line);
		}

		$fields = array(
			wp_date('c'),
			$source_id,
			$target,
			$outcome['target_id'] ?: '',
			$status,
			sprintf('%.6f', $outcome['cost']),
			$outcome['message'],
		);
		$fields = array_map(static fn($field): string => str_replace(array("\t", "\r", "\n"), ' ', (string) $field), $fields);
		$this->write_line(implode("\t", $fields));
	}

	public function finish(): void {
		$elapsed = time() - $this->started;
		$counts  = array();
		foreach ($this->counts as $status => $count) {
			$counts[] = $status . '=' . $count;
		}
		$summary = sprintf(
			'Summary: %s; %s $%.4f; elapsed %d:%02d:%02d',
			$counts ? implode(', ', $counts) : 'nothing to do',
			$this->dry_run ? sprintf('%d chars, estimated cost', $this->chars) : 'cost',
			$this->cost,
			intdiv($elapsed, 3600),
			intdiv($elapsed % 3600, 60),
			$elapsed % 60
		);
		if ($this->dry_run) {
			$summary .= sprintf(' (model %s; catalog price per ~%s characters)', AIPT_Settings::get()['model'], number_format(AIPT_Settings::PRICE_CHARS));
		}

		$this->write_line('# ' . wp_date('c') . ' ' . $summary);
		if ($this->handle) {
			fclose($this->handle);
			$this->handle = null;
		}

		// Non-zero exit when any record failed, so scripts and schedulers notice.
		$errors = (int) ($this->counts['error'] ?? 0);
		if ($errors) {
			WP_CLI::warning($summary);
			WP_CLI::warning(sprintf('%d record(s) ended in error; see the log for details.', $errors));
			WP_CLI::halt(1);
		}
		WP_CLI::success($summary);
	}

	// Directories that may be web-served: ABSPATH, WP_CONTENT_DIR and, when WordPress
	// lives in a subdirectory (Bedrock-style content dir outside ABSPATH, or a home URL
	// that differs from the site URL), the directory above ABSPATH too.
	private static function public_roots(): array {
		$abspath = self::real_dir(ABSPATH);
		$content = defined('WP_CONTENT_DIR') ? self::real_dir(WP_CONTENT_DIR) : '';
		$roots   = array($abspath, $content);

		$content_outside = $content !== '' && $abspath !== ''
			&& $content !== $abspath && !str_starts_with($content, $abspath . '/');
		$home_differs    = untrailingslashit((string) wp_parse_url(home_url(), PHP_URL_PATH))
			!== untrailingslashit((string) wp_parse_url(site_url(), PHP_URL_PATH));
		if ($abspath !== '' && ($content_outside || $home_differs)) {
			$roots[] = self::real_dir(dirname($abspath));
		}
		return array_values(array_unique(array_filter($roots, static fn(string $root): bool => $root !== '' && $root !== '/')));
	}

	private static function real_dir(string $path): string {
		$real = realpath($path);
		return $real === false ? '' : untrailingslashit(wp_normalize_path($real));
	}

	private function begin(string $command, array $assoc_args): void {
		$this->started = time();
		$flags         = array();
		foreach ($assoc_args as $key => $value) {
			$flags[] = $value === true ? '--' . $key : '--' . $key . '=' . $value;
		}
		$this->write_line(sprintf(
			'# %s wp aipt %s %s (user #%d, pid %d)',
			wp_date('c'),
			$command,
			implode(' ', $flags),
			get_current_user_id(),
			getmypid()
		));
	}

	// Several shards may append to the same file.
	private function write_line(string $line): void {
		if (!$this->handle) {
			return;
		}
		flock($this->handle, LOCK_EX);
		fwrite($this->handle, $line . "\n");
		fflush($this->handle);
		flock($this->handle, LOCK_UN);
	}
}
