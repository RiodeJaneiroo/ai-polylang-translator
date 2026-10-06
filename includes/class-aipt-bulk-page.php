<?php
// "Bulk translation" admin page (Settings > AI Bulk Translation, manage_options): the form,
// progress, log and "needs manual check" panel; assets/bulk.js and bulk-run.js (helpers:
// bulk-ui.js) drive AIPT_Bulk_Ajax.
// The Strings section (WPML String Translation, assets/bulk-strings.js) drives AIPT_Bulk_Strings.

if (!defined('ABSPATH')) {
	exit;
}

class AIPT_Bulk_Page {

	const PAGE = 'aipt-bulk';

	private $hook = '';

	public function __construct() {
		add_action('admin_menu', array($this, 'add_page'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue'));
	}

	public function add_page(): void {
		$this->hook = (string) add_submenu_page(
			'options-general.php',
			__('Bulk translation', 'ai-polylang-translator'),
			__('AI Bulk Translation', 'ai-polylang-translator'),
			'manage_options',
			self::PAGE,
			array($this, 'render')
		);
	}

	private static function ready(): bool {
		return AIPT_Settings::enabled() && AIPT_Settings::api_key() !== '';
	}

	public function enqueue(string $hook): void {
		if ($this->hook === '' || $hook !== $this->hook || !self::ready()) {
			return;
		}
		wp_enqueue_style('aipt-admin', AIPT_URL . 'assets/admin.css', array(), AIPT_VERSION);
		wp_enqueue_script('aipt-bulk-ui', AIPT_URL . 'assets/bulk-ui.js', array(), AIPT_VERSION, true);
		wp_enqueue_script('aipt-bulk-run', AIPT_URL . 'assets/bulk-run.js', array('aipt-bulk-ui'), AIPT_VERSION, true);
		wp_enqueue_script('aipt-bulk', AIPT_URL . 'assets/bulk.js', array('aipt-bulk-run'), AIPT_VERSION, true);
		wp_enqueue_script('aipt-bulk-strings', AIPT_URL . 'assets/bulk-strings.js', array('aipt-bulk-run'), AIPT_VERSION, true);
		wp_localize_script('aipt-bulk-ui', 'aiptBulk', array(
			'ajaxUrl'      => admin_url('admin-ajax.php'),
			'editUrl'      => admin_url('post.php?action=edit&post='),
			'nonce'        => wp_create_nonce(AIPT_Bulk_Request::NONCE),
			// Seconds a batch lock can be held: bulk-run.js sizes its busy retries from it.
			'batchLockTtl' => AIPT_Pipeline::batch_lock_ttl(),
			'i18n'         => self::strings(),
		));
	}

	private static function strings(): array {
		return array(
			'planning'       => __('Collecting records…', 'ai-polylang-translator'),
			/* translators: %1$d: records estimated so far, %2$d: total records */
			'estimating'     => __('Estimating… %1$d of %2$d', 'ai-polylang-translator'),
			'estimateDone'   => __('Estimate', 'ai-polylang-translator'),
			'terms'          => __('Terms', 'ai-polylang-translator'),
			'posts'          => __('Posts', 'ai-polylang-translator'),
			'total'          => __('Total', 'ai-polylang-translator'),
			'chars'          => __('Characters', 'ai-polylang-translator'),
			'cost'           => __('Estimated cost', 'ai-polylang-translator'),
			'running'        => __('Running…', 'ai-polylang-translator'),
			'stopping'       => __('Stopping: waiting for the requests in progress…', 'ai-polylang-translator'),
			'stopped'        => __('Stopped. Press Start to continue; finished records are skipped and unfinished ones resume.', 'ai-polylang-translator'),
			'finished'       => __('Finished.', 'ai-polylang-translator'),
			'tooManyErrors'  => __('Stopped after 5 errors in a row. Check the log, then press Start to continue.', 'ai-polylang-translator'),
			'authError'      => __('The API key was rejected. Check it in Settings > AI Translator, then press Start to continue.', 'ai-polylang-translator'),
			'costLimit'      => __('Cost limit reached: no new records are started; records whose blocks are all translated are still saved.', 'ai-polylang-translator'),
			'confirmNeeded'  => __('Confirm that existing translations will be overwritten.', 'ai-polylang-translator'),
			'nothing'        => __('Nothing to translate for this selection.', 'ai-polylang-translator'),
			/* translators: %1$s: taxonomy name, %2$s: language code */
			'termsPair'      => __('Terms: %1$s → %2$s', 'ai-polylang-translator'),
			/* translators: %1$d: current block number, %2$d: total number of blocks */
			'block'          => __('block %1$d of %2$d', 'ai-polylang-translator'),
			'preparing'      => __('preparing', 'ai-polylang-translator'),
			'saving'         => __('saving', 'ai-polylang-translator'),
			'retrying'       => __('retrying', 'ai-polylang-translator'),
			'networkError'   => __('Network error', 'ai-polylang-translator'),
			'interrupted'    => __('Interrupted: the record resumes on the next Start.', 'ai-polylang-translator'),
			'openTarget'     => __('translation', 'ai-polylang-translator'),
			'translateAnyway' => __('Translate anyway', 'ai-polylang-translator'),
			'discard'        => __('Discard', 'ai-polylang-translator'),
			'confirmDiscard' => __('Discard this job? Blocks already translated for it will be paid again if the record is translated later.', 'ai-polylang-translator'),
			'noPending'      => __('Nothing needs a manual check.', 'ai-polylang-translator'),
			'busy'           => __('Wait until the current run has stopped.', 'ai-polylang-translator'),
			/* translators: %1$d: blocks translated, %2$d: total number of blocks */
			'stageNone'      => __('unfinished, resumes on the next Start (%1$d of %2$d blocks translated)', 'ai-polylang-translator'),
			'stageFinalizing' => __('writing was interrupted: check the translation', 'ai-polylang-translator'),
			/* translators: %s: outcome, e.g. "created" */
			'stageComplete'  => __('finished: %s', 'ai-polylang-translator'),
			'targetChanged'  => __('the translation changed elsewhere: check it, then discard the job', 'ai-polylang-translator'),
			'resumed'        => __('Continuing the current run: records finished in it are skipped.', 'ai-polylang-translator'),
			'confirmNewRun'  => __('Forget the current run? The next Start begins a new run: in safe or overwrite mode, records finished in this run are translated again.', 'ai-polylang-translator'),
			'confirmAnyway'  => __('Discard this job and translate the record again in overwrite mode? Its translation, if any, is replaced and the blocks are paid again.', 'ai-polylang-translator'),
			/* translators: %d: number of terms still to translate */
			'termsLeft'      => __('%d terms left', 'ai-polylang-translator'),
			/* translators: %s: error message */
			'fatal'          => __('Stopped: %s', 'ai-polylang-translator'),
			/* translators: %s: user display name */
			'otherUser'      => __('started by %s', 'ai-polylang-translator'),
			'strings'        => self::strings_section_text(),
			'status'         => array(
				'created'                => __('created', 'ai-polylang-translator'),
				'created_with_warning'   => __('created with a warning', 'ai-polylang-translator'),
				'updated'                => __('updated', 'ai-polylang-translator'),
				'updated_with_warning'   => __('updated with a warning', 'ai-polylang-translator'),
				'linked'                 => __('linked to an existing term', 'ai-polylang-translator'),
				'would_create'           => __('would create', 'ai-polylang-translator'),
				'would_update'           => __('would update', 'ai-polylang-translator'),
				'skipped_existing'       => __('already translated', 'ai-polylang-translator'),
				'skipped_done'           => __('finished earlier in this run', 'ai-polylang-translator'),
				'skipped_pending'        => __('translation in progress in the multilingual plugin', 'ai-polylang-translator'),
				'skipped_locked'         => __('being translated elsewhere', 'ai-polylang-translator'),
				'skipped_parent_missing' => __('parent not translated', 'ai-polylang-translator'),
				'skipped_missing_terms'  => __('terms not translated', 'ai-polylang-translator'),
				'skipped_no_permission'  => __('no permission', 'ai-polylang-translator'),
				'skipped_empty'          => __('empty', 'ai-polylang-translator'),
				'skipped_no_text'        => __('nothing to translate (only placeholders or markup)', 'ai-polylang-translator'),
				'needs_check'            => __('needs manual check', 'ai-polylang-translator'),
				'review'                 => __('source changed or no longer eligible: needs manual check', 'ai-polylang-translator'),
				'interrupted'            => __('not finished', 'ai-polylang-translator'),
				'error'                  => __('error', 'ai-polylang-translator'),
			),
		);
	}

	// Texts of assets/bulk-strings.js.
	private static function strings_section_text(): array {
		return array(
			'loading'      => __('Loading domains…', 'ai-polylang-translator'),
			'noDomains'    => __('No strings in the source language.', 'ai-polylang-translator'),
			'selectDomain' => __('Select at least one string domain.', 'ai-polylang-translator'),
			'selectTarget' => __('Select at least one target language.', 'ai-polylang-translator'),
			'domain'       => __('Domain', 'ai-polylang-translator'),
			'total'        => __('Strings', 'ai-polylang-translator'),
			'untranslated' => __('Without translation', 'ai-polylang-translator'),
			/* translators: %1$s: string domain, %2$s: language code */
			'pair'         => __('%1$s → %2$s', 'ai-polylang-translator'),
			/* translators: %d: number of strings still to translate */
			'left'         => __('%d strings left', 'ai-polylang-translator'),
			/* translators: %s: total cost in USD, e.g. 0.0123 */
			'estimateDone' => __('Estimate: $%s', 'ai-polylang-translator'),
			/* translators: %s: total cost in USD, e.g. 0.0123 */
			'finished'     => __('Finished. Cost: $%s', 'ai-polylang-translator'),
			/* translators: %s: total cost in USD, e.g. 0.0123 */
			'stopped'      => __('Stopped. Cost: $%s', 'ai-polylang-translator'),
			'stopping'     => __('Stopping after the current request…', 'ai-polylang-translator'),
			'busy'         => __('Wait until the other run on this page has finished.', 'ai-polylang-translator'),
		);
	}

	public function render(): void {
		echo '<div class="wrap aipt-settings aipt-bulk">';
		echo '<h1>' . esc_html__('Bulk translation', 'ai-polylang-translator') . '</h1>';
		if (!self::ready()) {
			echo '<div class="notice notice-warning inline"><p>' . wp_kses_post(sprintf(
				/* translators: %s: settings page URL */
				__('AI Translator must be enabled and have an API key on the <a href="%s">settings page</a>.', 'ai-polylang-translator'),
				esc_url(admin_url('options-general.php?page=' . AIPT_Settings::PAGE))
			)) . '</p></div></div>';
			return;
		}
		$this->render_form();
		$this->render_progress();
		$this->render_strings();
		echo '</div>';
	}

	private function render_form(): void {
		$from      = aipt_lang()->default_language();
		$languages = aipt_lang()->languages();
		$types     = AIPT_Settings::get()['post_types'];
		?>
		<p class="description"><?php esc_html_e('Translates terms first, then posts, one record at a time, driven by this browser tab. Keep the tab open and the computer awake until the run ends. Stop and Start again at any time: records finished in this run are skipped in every mode, and unfinished records resume without paying again for blocks already translated. A run ends when it finishes by itself or with New run; after that, safe and overwrite modes translate existing translations again. Estimate first and back up the database before a large run.', 'ai-polylang-translator'); ?></p>
		<form id="aipt-bulk-form" class="aipt-panel" onsubmit="return false;">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e('Post types', 'ai-polylang-translator'); ?></th>
					<td>
						<?php foreach ($types as $type) :
							$object = get_post_type_object($type);
							if (!$object) {
								continue;
							} ?>
							<label class="aipt-cpt"><input type="checkbox" name="post_types[]" value="<?php echo esc_attr($type); ?>"> <?php echo esc_html($object->labels->name); ?></label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e('Published posts in the source language. Hierarchical types (pages) are translated parents first.', 'ai-polylang-translator'); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e('Taxonomies', 'ai-polylang-translator'); ?></th>
					<td>
						<?php
						// Term counts of the chosen source language are loaded by bulk.js
						// (aipt_bulk_taxonomies); a taxonomy without terms in it is disabled.
						$taxonomies = AIPT_Bulk_Terms::choices();
						if (!$taxonomies) {
							echo '<p>' . esc_html__('No translated taxonomy in which you can create terms.', 'ai-polylang-translator') . '</p>';
						}
						foreach ($taxonomies as $taxonomy => $label) : ?>
							<label class="aipt-cpt"><input type="checkbox" name="taxonomies[]" value="<?php echo esc_attr($taxonomy); ?>" data-label="<?php echo esc_attr($label); ?>"> <span class="aipt-bulk-tax-label"><?php echo esc_html($label); ?></span></label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e('Term names and descriptions, one short request per group of terms. An interruption re-pays at most one group (cents).', 'ai-polylang-translator'); ?></p>
					</td>
				</tr>
				<?php $this->render_languages('aipt-bulk', $from, $languages); ?>
				<tr>
					<th scope="row"><?php esc_html_e('Existing translations', 'ai-polylang-translator'); ?></th>
					<td>
						<label class="aipt-bulk-line"><input type="radio" name="mode" value="skip" checked> <?php esc_html_e('Skip (translate only what is missing)', 'ai-polylang-translator'); ?></label>
						<label class="aipt-bulk-line"><input type="radio" name="mode" value="safe"> <?php esc_html_e('Update in safe mode (filled fields are kept)', 'ai-polylang-translator'); ?></label>
						<label class="aipt-bulk-line"><input type="radio" name="mode" value="overwrite"> <?php esc_html_e('Overwrite', 'ai-polylang-translator'); ?></label>
						<label class="aipt-bulk-line aipt-bulk-confirm"><input type="checkbox" name="confirm" value="1"> <?php esc_html_e('I understand that the content of existing translations will be replaced.', 'ai-polylang-translator'); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e('New translations', 'ai-polylang-translator'); ?></th>
					<td>
						<label class="aipt-bulk-line"><input type="checkbox" name="publish" value="1"> <?php esc_html_e('Publish (sources that are not public stay private, scheduled or draft)', 'ai-polylang-translator'); ?></label>
						<label class="aipt-bulk-line"><input type="checkbox" name="keep_date" value="1"> <?php esc_html_e('Keep the dates of the source posts', 'ai-polylang-translator'); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aipt-bulk-limit"><?php esc_html_e('Cost limit (USD)', 'ai-polylang-translator'); ?></label></th>
					<td>
						<input type="number" id="aipt-bulk-limit" name="limit" min="0" step="0.01" class="small-text">
						<p class="description"><?php esc_html_e('Soft limit for this run: once reached, no new records are started. Empty = no limit.', 'ai-polylang-translator'); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aipt-bulk-streams"><?php esc_html_e('Parallel requests', 'ai-polylang-translator'); ?></label></th>
					<td>
						<select id="aipt-bulk-streams" name="streams">
							<option value="1">1</option>
							<option value="2" selected>2</option>
							<option value="3">3</option>
						</select>
						<p class="description"><?php esc_html_e('Records translated at the same time. Keep 1–2 on shared hosting so visitors keep enough PHP workers. Terms always run one at a time.', 'ai-polylang-translator'); ?></p>
					</td>
				</tr>
			</table>
			<p>
				<button type="button" class="button" id="aipt-bulk-estimate"><?php esc_html_e('Estimate', 'ai-polylang-translator'); ?></button>
				<button type="button" class="button button-primary" id="aipt-bulk-start"><?php esc_html_e('Start', 'ai-polylang-translator'); ?></button>
				<button type="button" class="button" id="aipt-bulk-stop" disabled><?php esc_html_e('Stop', 'ai-polylang-translator'); ?></button>
				<button type="button" class="button-link" id="aipt-bulk-new-run" hidden><?php esc_html_e('New run', 'ai-polylang-translator'); ?></button>
			</p>
		</form>
		<?php
	}

	private function render_progress(): void {
		$counters = array(
			'created'  => __('Created', 'ai-polylang-translator'),
			'updated'  => __('Updated', 'ai-polylang-translator'),
			'terms'    => __('Terms created', 'ai-polylang-translator'),
			'warnings' => __('Warnings', 'ai-polylang-translator'),
			'skipped'  => __('Skipped', 'ai-polylang-translator'),
			'check'    => __('Needs check', 'ai-polylang-translator'),
			'errors'   => __('Errors', 'ai-polylang-translator'),
			'cost'     => __('Cost', 'ai-polylang-translator'),
			'api'      => __('API calls', 'ai-polylang-translator'),
			'elapsed'  => __('Elapsed', 'ai-polylang-translator'),
		);
		?>
		<div class="aipt-panel" id="aipt-bulk-estimate-box" hidden></div>
		<div class="aipt-panel">
			<h2 class="aipt-panel-title"><?php esc_html_e('Progress', 'ai-polylang-translator'); ?></h2>
			<p id="aipt-bulk-message" aria-live="polite"></p>
			<progress id="aipt-bulk-progress" class="aipt-bulk-progress" value="0" max="1"></progress>
			<span id="aipt-bulk-progress-text"></span>
			<div class="aipt-stats aipt-bulk-counters">
				<?php foreach ($counters as $key => $label) : ?>
					<div class="aipt-stat">
						<span class="aipt-stat-label"><?php echo esc_html($label); ?></span>
						<span class="aipt-stat-value" data-counter="<?php echo esc_attr($key); ?>">0</span>
					</div>
				<?php endforeach; ?>
			</div>
			<p class="description" id="aipt-bulk-skipped"></p>
			<ul id="aipt-bulk-current" class="aipt-bulk-current"></ul>
		</div>
		<div class="aipt-panel">
			<h2 class="aipt-panel-title"><?php esc_html_e('Needs manual check', 'ai-polylang-translator'); ?></h2>
			<p class="description"><?php esc_html_e('Unfinished jobs of earlier runs. A job whose writing was interrupted, or whose source changed after its blocks were translated, is never written automatically: open the source and the translation, then translate again or discard the job.', 'ai-polylang-translator'); ?></p>
			<div id="aipt-bulk-pending"></div>
		</div>
		<div class="aipt-panel">
			<h2 class="aipt-panel-title"><?php esc_html_e('Log', 'ai-polylang-translator'); ?></h2>
			<?php $this->render_log_table(__('Record', 'ai-polylang-translator'), 'aipt-bulk-log'); ?>
		</div>
		<?php
	}

	// WPML String Translation strings (Yoast SEO title templates and the like), the admin
	// counterpart of `wp aipt translate-strings`. Not offered when the backend has no
	// string translation (Polylang, or WPML without String Translation): the reason is the
	// message of the adapter's string_domains() error, which it returns before any query.
	private function render_strings(): void {
		$from      = aipt_lang()->default_language();
		$languages = aipt_lang()->languages();
		echo '<div class="aipt-panel" id="aipt-strings">';
		echo '<h2 class="aipt-panel-title">' . esc_html__('Strings (WPML String Translation)', 'ai-polylang-translator') . '</h2>';
		if (!aipt_lang()->supports_strings()) {
			$unsupported = aipt_lang()->string_domains($from, $from);
			echo '<p>' . esc_html(is_wp_error($unsupported) ? $unsupported->get_error_message() : '') . '</p></div>';
			return;
		}
		?>
		<p class="description"><?php esc_html_e('Translates the strings of the selected domains (WPML "contexts", e.g. admin_texts_wpseo_titles for Yoast SEO title templates) whose language is the source language, like wp aipt translate-strings. Placeholders and HTML are kept; a translation whose placeholders changed is not saved. Empty strings and strings with only placeholders or markup are skipped. Each domain and language is sent in short requests of a few dozen strings each; an interruption re-pays at most one request.', 'ai-polylang-translator'); ?></p>
		<form id="aipt-strings-form" onsubmit="return false;">
			<table class="form-table" role="presentation">
				<?php $this->render_languages('aipt-strings', $from, $languages); ?>
				<tr>
					<th scope="row"><?php esc_html_e('Domains', 'ai-polylang-translator'); ?></th>
					<td><div id="aipt-strings-domains" class="aipt-strings-domains"></div></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e('Existing translations', 'ai-polylang-translator'); ?></th>
					<td>
						<label class="aipt-bulk-line"><input type="checkbox" name="update" value="1"> <?php esc_html_e('Update existing translations', 'ai-polylang-translator'); ?></label>
						<p class="description"><?php esc_html_e('Off: strings with a finished translation are skipped.', 'ai-polylang-translator'); ?></p>
					</td>
				</tr>
			</table>
			<p>
				<button type="button" class="button" id="aipt-strings-estimate"><?php esc_html_e('Estimate', 'ai-polylang-translator'); ?></button>
				<button type="button" class="button button-primary" id="aipt-strings-start"><?php esc_html_e('Translate', 'ai-polylang-translator'); ?></button>
				<button type="button" class="button" id="aipt-strings-stop" disabled><?php esc_html_e('Stop', 'ai-polylang-translator'); ?></button>
			</p>
		</form>
		<p id="aipt-strings-message" aria-live="polite"></p>
		<progress id="aipt-strings-progress" class="aipt-bulk-progress" value="0" max="1"></progress>
		<span id="aipt-strings-progress-text"></span>
		<table class="widefat striped aipt-strings-summary">
			<thead>
				<tr>
					<th><?php esc_html_e('Domain', 'ai-polylang-translator'); ?></th>
					<th><?php esc_html_e('Language', 'ai-polylang-translator'); ?></th>
					<th><?php esc_html_e('Result', 'ai-polylang-translator'); ?></th>
					<th><?php esc_html_e('Cost', 'ai-polylang-translator'); ?></th>
				</tr>
			</thead>
			<tbody id="aipt-strings-summary"></tbody>
		</table>
		<?php $this->render_log_table(__('String', 'ai-polylang-translator'), 'aipt-strings-log'); ?>
		</div>
		<?php
	}

	// Rows "From" (select <id_prefix>-from) and "To" (targets[] checkboxes, all but $from ticked).
	private function render_languages(string $id_prefix, string $from, array $languages): void {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr($id_prefix); ?>-from"><?php esc_html_e('From', 'ai-polylang-translator'); ?></label></th>
			<td>
				<select id="<?php echo esc_attr($id_prefix); ?>-from" name="from">
					<?php foreach ($languages as $code => $language) : ?>
						<option value="<?php echo esc_attr($code); ?>" <?php selected($code, $from); ?>><?php echo esc_html($language['name']); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e('To', 'ai-polylang-translator'); ?></th>
			<td>
				<?php foreach ($languages as $code => $language) : ?>
					<label class="aipt-cpt"><input type="checkbox" name="targets[]" value="<?php echo esc_attr($code); ?>" <?php checked($code !== $from); ?>> <?php echo esc_html($language['name']); ?></label>
				<?php endforeach; ?>
			</td>
		</tr>
		<?php
	}

	// A log table filled by the page's JS (newest row first): time, $who_label, language,
	// status, message.
	private function render_log_table(string $who_label, string $tbody_id): void {
		?>
		<div class="aipt-usage-log">
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e('Time', 'ai-polylang-translator'); ?></th>
						<th><?php echo esc_html($who_label); ?></th>
						<th><?php esc_html_e('Language', 'ai-polylang-translator'); ?></th>
						<th><?php esc_html_e('Status', 'ai-polylang-translator'); ?></th>
						<th><?php esc_html_e('Message', 'ai-polylang-translator'); ?></th>
					</tr>
				</thead>
				<tbody id="<?php echo esc_attr($tbody_id); ?>"></tbody>
			</table>
		</div>
		<?php
	}
}
