# Bulk translation from wp-admin (no SSH): analysis and phased plan

Status: DEFERRED 2026-10-03. The user chose the slim, tab-driven variant in `bulk-admin.md`; this document is kept as the design for unattended drivers (cron/URL) if they are ever needed.

## Goal

Translate a whole site (terms, then posts/products/pages) from an admin page with the same safety as the CLI: resumable, skipping existing translations, pair locks, cost estimate before start, a soft cost cap during the run, the global switch honoured. The run must survive a closed browser tab: it is driven by whichever of three drivers is available (open admin tab, WP-Cron, external cron URL), all moving the same queue, and it must never double-spend or half-write when two drivers overlap or a request dies.

## Analysis: what exists and what is missing

Existing building blocks (1.6.0):

- `AIPT_Pipeline::prepare / translate_batch / finalize` — the editor pipeline: each call is one request; job + per-batch results live in transients (`AIPT_Job`, TTL 1 h); finalize is idempotent via a completion marker and takes a finalize lock + the editor pair lock (5 min).
- `AIPT_Pipeline::translate_post` — in-process variant (CLI, auto): all batches in one request under the record pair lock (30 min, refreshed per batch). Too long for one web request on big pages, so bulk uses the step pipeline.
- `AIPT_CLI_Select` — selection, parents first. Not transport-neutral: term selection calls `WP_CLI::warning()`, and the file is loaded only in the CLI branch of the bootstrap.
- CLI-only policy that bulk needs too: `AIPT_CLI::precheck`, `new_post_status`, `dry_run_post` (with its `$planned` parents context), outcome statuses (`AIPT_Record::outcome`, `skip_status`).
- `AIPT_Terms::translate(taxonomy, terms, from, target, dry_run_price, on_row)` — runs **all** API batches before creating terms; `on_row` cannot stop later batches; errors are flattened to strings. Callers supply the terms (selection and `with_ancestors` happen outside).
- Locks (`AIPT_Job`): pair locks are `INSERT IGNORE` option rows with stale takeover, but **ownership lives only in static PHP arrays**: a later request cannot refresh or release a lock taken by an earlier request, and `finalize()` acquires its own editor pair lock, so a lease held across requests is not possible today.
- Gateway: default timeout 90 s per request, retries inside one batch, pipeline raises `max_execution_time` to 180 s. A single step can therefore take minutes; items can exceed the nominal 8 KB.
- `AIPT_Settings::enabled_fresh()`, `AIPT_Usage` (cost log, 100 entries, per-job accumulation, no run id), `price_per_char()`, `aipt_translation_written` hook.
- Missing: persistent queue, cross-request lock leases, crash-safe finalize intent, admin UI beyond the metabox, any shell-less driver.

Hosting constraints: PHP `max_execution_time` 30–120 s and proxy timeouts on shared hosts; `exec` cannot be assumed; WP-Cron fires only on visits (`DISABLE_WP_CRON` disables spawning, not externally invoked `wp-cron.php`; `ALTERNATE_WP_CRON` spawns from eligible front-end GETs only, never from AJAX); the admin tab may close at any moment; several drivers may fire at once.

## Design

### Guarantees (what the implementation must hold)

1. **No double-spend.** A step's side effects (API call, batch save, usage record, write) happen only while the worker verifiably owns the tick lease; progress is saved with compare-and-swap; a duplicate worker finds the lease taken or the CAS failing and does nothing.
2. **No silent half-write.** Finalize is entered only after a persisted `finalizing` intent; an ambiguous outcome (worker died inside the writer) is reconciled, never blindly replayed.
3. **Stale policy is never applied.** Source eligibility, publish rule, permissions and prerequisites are re-validated before prepare and again before finalize.
4. **Timeouts and cost caps are soft.** The plan documents them as such: a step in flight can exceed them; the engine bounds *when the next step starts*, not the current one.

### Cross-request leases (`AIPT_Job` extension, Phase 1a)

- Pair lock rows gain an **owner token** (random, stored in the row value with holder/TTL). New API: `acquire_pair_lock(post_id, target, ttl, holder, owner)`, `refresh_pair_lock(post_id, target, owner): bool` (false when the row is gone or owned by someone else), `release_pair_lock(post_id, target, owner)` (conditional). Existing callers keep working (owner defaults to the per-request token used today).
- `AIPT_Pipeline::finalize($job_id, $job, $opts)` accepts `lease => [owner]`: instead of taking the editor pair lock it **verifies** the row is owned by that owner and unexpired (read bypasses the object cache, as all lock reads do), refreshes it, and proceeds; keeps its finalize lock, adapter `refresh()`, writer order and completion marker. No bare boolean bypass.
- Tick lock: a named lock whose TTL is sized from the configured gateway timeout: `tick_ttl = timeout × (1 + retries) + 60 s` (≥ 5 min by default); the worker re-checks ownership (`refresh` result) before every side effect and before saving progress.

### Queue state (`AIPT_Bulk_Queue`, option `aipt_bulk`, autoload off)

One queue at a time. Shape:

```
schema (int), backend ('polylang'|'wpml'), run_id, user_id, status: idle|running|paused|stopped|done,
paused_reason: ''|cost_limit|disabled|auth|backoff|manual|reconcile, diagnostics (last terminal message, kept until a new Start)
selection: { post_types[], taxonomies[], from, targets[], post_status[] (default publish), mode: skip|safe|overwrite, publish, keep_date, cost_limit|null }
plan:      { term_pairs: [[taxonomy, lang], …], post_ids: [int…] (fixed at Start, parents first) }
cursor:    { phase: terms|posts, pair_index, post_index, lang_index,
             current: null | post record { post_id, lang, job_id, owner, lock_key, done_batches, total_batches, applied: [batch indexes whose usage was added to counters], stage: prepared|translating|ready|finalizing|reconcile, started }
                            | terms record { taxonomy, lang, items, batches, results, done_batches, applied, stage: translating|writing|finalizing } }
counters:  { created, updated, created_with_warning, updated_with_warning, skipped_*, error, cost, api_calls, records_done, records_total }
log:       last 200 rows { time, kind, id, lang, status, message (capped 500 chars) }
drivers:   { tab, cron, url }, token_hash, token_generation, last_tick, last_driver, backoff_until, repeated_error { code, count }
```

Writes go through **CAS**: a separate small option `aipt_bulk_version` is bumped with `UPDATE … SET option_value = %d WHERE option_name = %s AND option_value = %d` (one atomic statement); only on success is the state written. `run_id` is part of every tick's precondition: a worker from a stopped/replaced run finds a mismatch and exits without side effects.

### Tick (`AIPT_Bulk::tick(string $driver): array`)

Under the tick lease. Each tick re-reads state, checks `run_id`, `schema`, `backend === aipt_lang()->name()` (mismatch → `stopped`, diagnostics kept), the stored user (exists, `manage_options`; set as current user, restored in `finally`), `enabled_fresh()` (→ `paused: disabled`), `backoff_until`, and the soft cost limit (→ `paused: cost_limit`, but **finalize of an already fully translated record is still allowed**, it costs nothing). Then exactly one step:

**Terms record** (resumable, replaces "whole taxonomy per tick"): `AIPT_Terms` gets a step API — `plan(taxonomy, terms, from, target) → {items, batches, pending}`, `translate_batch(items, batch) → results|WP_Error` (structured errors kept), `write(pending, results, …) → rows` (creates terms parents first; idempotent per term via the existing "translation exists" check). The CLI keeps its behaviour by calling the same three steps in-process. Each tick does one API batch or the write step; `applied` tracks usage checkpoints.

**Post record**:
- *none* → pre-check via the shared runner (source status still selected, language, existing/duplicate/pending, parent, terms, `edit_post` on the source, create/edit on the target); skip statuses advance; else acquire the pair lock with an owner token, `prepare()` (`confirm` = true, `post_status` from the shared rule, `keep_date`); store `current` with `stage: prepared`.
- *translating* → if the job or the next batch transient is missing (TTL/eviction) → re-prepare (warning row "re-translated after expiry"); else `translate_batch(done_batches)`, refresh the lease (false → abort step, no progress saved), add the batch usage to counters once (checkpoint in `applied`), `done_batches++`.
- *ready* → reconcile stored batches with `AIPT_Job::get_batches`: missing ones are re-run first. Then **revalidate**: source still exists, not trashed, status still eligible; publish rule recomputed (a now-private/draft source downgrades the translation status at finalize via a `post_status` override opt); parent/terms re-checked (missing → skip with a row); permissions re-checked (`publish_*` cap when publishing). Persist `stage: finalizing` (CAS), then `finalize(job_id, job, ['lease' => owner, 'post_status' => …])`; persist the full outcome (status, warnings, target id), release the lock, advance.
- *finalizing found on entry* (worker died inside the write) → **reconcile, never replay**: if `translation_state(source, lang)` shows a linked target created/updated by this job (writer marker + job id recorded on the post as meta `_aipt_job`), record `created_unverified`/`updated_unverified` with a warning; otherwise record `error: needs manual check` with the target id if any; advance. No automatic second write.

Each tick returns a compact status (phase, counters, current stage, status, new log rows since a sequence number).

**Error policy** (classification by the gateway's structured codes `aipt_http_401/403`, `aipt_http_429`, `aipt_http_5xx`, network, plus pipeline/adapter codes):
- 401/403/missing key → `paused: auth`, record kept (resumes where it stopped).
- 429 / 5xx / network → bounded exponential backoff (`backoff_until`), after N consecutive failures → `paused: backoff`.
- Same error code on K consecutive records → `paused` with diagnostics (configuration problem), not mass continuation.
- `aipt_sync_missing`, `aipt_wpml_api`, backend mismatch → `stopped`.
- Pair lock held by **this run's** owner (crash) → recover ownership (same owner token) or wait; never counted as `skipped_locked`.
- Ordinary per-record errors (bad model reply, kses, link failure) → row + continue, as the CLI does.

### Shared runner and selector (Phase 1a, zero behaviour change for the CLI)

- `AIPT_Runner`: `precheck`, `new_post_status`, `revalidate_before_write`, `estimate(post_id, target, from, opts, price, Context $ctx)` where the context carries planned parents and planned terms across chunked estimate requests (serialized in a transient between AJAX chunks); CLI uses the same functions.
- `AIPT_Select` (renamed from `AIPT_CLI_Select`, loaded in every context): returns `WP_Error` for failed term queries; the CLI prints the warning.
- Cleanup between loop iterations = the CLI's `free_memory()` moved to a shared helper (runtime flush + adapter refresh + `gc_collect_cycles`), used by cron/URL loops.

### Drivers

1. **Admin tab**: `assets/bulk.js` loops `admin-ajax.php?action=aipt_bulk_tick` (nonce, `manage_options`) while `running`, back-off on `busy`/network errors; renders progress. Closing the tab only stops this driver.
2. **WP-Cron**: recurring `aipt_bulk_cron` every minute while `running` (cleared on pause/stop/done, re-armed on resume); handler loops ticks within a ~20 s budget. UI shows `last_tick`/`last_driver` and scheduling errors instead of guessing from `DISABLE_WP_CRON`; a note explains the alternate-cron limitation.
3. **External URL** (opt-in): `admin-ajax.php?action=aipt_bulk_tick_url`, token preferably in header `X-AIPT-Key` (query `key` accepted for schedulers that cannot set headers), HTTPS required (refused over HTTP unless a constant allows it), strict scalar/length validation, `hash_equals` against a **hash at rest**, bound to `run_id` + `token_generation` and re-validated inside the loop, admission through an atomic `INSERT IGNORE` rate-limit row (one URL loop per 30 s), honours driver enablement and terminal state. The secret is shown once on generation and never appears in status responses or logs; revoked on Stop or regeneration.

### Lifecycle

- Start: validates selection, fixes `plan`, writes `schema`/`backend`/`run_id`/`user_id`, arms cron.
- Pause/Resume: pause keeps the record and lease state; resume re-arms cron and continues (re-prepare if the job expired).
- Stop: deletes the job and batch transients of the current record, releases owned locks, clears cron and the token, keeps `diagnostics` and counters for display until the next Start.
- Plugin deactivation: unschedules cron (state kept). Uninstall: removes the queue, version, tick lock, rate-limit rows, token.
- Bulk job TTL: 24 h for job, batches and completion marker (one constant), still reconciled (eviction can happen earlier).

### Cost accounting

Run counters are the source of truth for the run: usage is added once per `(job_id, batch)` checkpoint recorded in `applied`; re-prepared records add their new cost; rejected replies still count (billed). The cost log stays as is (per job, 100 entries) and is not used to reconstruct a run. The cap is documented as **soft**.

### Admin page (`AIPT_Bulk_Page`, submenu "Bulk translation")

Form (post types enabled in settings; translated taxonomies with terms in `from`; from/targets; mode skip/safe/overwrite with confirmation; publish; keep dates; soft cost limit), **Estimate** (chunked by elapsed time, ~10 s per chunk, with the shared estimate context), Start/Pause/Resume/Stop, progress bar, counters, current record, error/warning rows with links, driver panel (tab toggle, cron status with last tick, URL section). Vanilla JS, `assets/admin.css`, strings in ru/uk. Capability `manage_options`.

### Interaction with existing features

Metabox and CLI keep working during a run (pair locks arbitrate; metabox shows "busy: bulk"). Auto-translate independent; bulk-written posts carry the marker and `_aipt_job`. The global switch pauses bulk. Polylang and WPML both supported (runner talks to the pipeline and `aipt_lang()` only).

## Phases

**Phase 1a — Groundwork, no behaviour change.** `AIPT_Job` owner-token leases + `finalize(lease)`; `AIPT_Runner` extracted from the CLI (precheck, publish rule, revalidate, estimate with context); `AIPT_Select` transport-neutral and always loaded; `AIPT_Terms` step API with the CLI using it; shared loop cleanup helper; gateway error codes verified (`aipt_http_*`). Verify: CLI dry-run outputs on sport-liga and axio identical before/after; one real CLI record per site; metabox finalize still works (it now passes no lease and takes the editor lock as today).

**Phase 1b — Queue engine.** `AIPT_Bulk_Queue` (CAS, schema/backend/run_id), `AIPT_Bulk` tick state machine for terms and posts (prepare/translating/ready/finalizing/reconcile), error classification and backoff, lifecycle (start/pause/resume/stop, deactivation, uninstall), WP-Cron driver, admin-ajax tick for logged-in admins, `_aipt_job` meta. Verify with `wp eval` on axio: a 2-product + 1-page + 1-taxonomy run driven by ticks; pause/resume; kill a worker mid-batch and mid-finalize (simulated) and watch reconcile; two drivers at once (parallel `wp eval` loops) with no double spend; job expiry path with a shortened TTL.

**Phase 2 — Admin page, tab driver, estimate UI.** Parallel with Phase 3 after 1b. Verify in the browser on axio: estimate, start, close/reopen tab, pause, stop, WPML and Polylang pages.

**Phase 3 — External URL driver, docs, release.** Token lifecycle and hardening as specified; README "Bulk translation without SSH" (drivers, recommended order terms → products → pages, estimate first, backup, cache purge, soft limits); CHANGELOG 1.7.0; CLAUDE.md paragraph; ru/uk. Final review, release 1.7.0.

Parallelism: 1a → 1b, then 2 ‖ 3, then review.

## Decisions to confirm

1. One queue at a time, state in one non-autoloaded option with CAS via a version row; fixed selection at Start, eligibility re-validated per record (posts published after Start belong to the next run). (Recommended.)
2. A tick is one pipeline step; timeouts and the cost cap are soft, documented as such; the tick lease is sized from the gateway timeout. (Recommended.)
3. Terms become resumable (step API) in Phase 1a rather than "whole taxonomy per tick". (Changed after review.)
4. Ambiguous finalize outcomes are reconciled and flagged, never auto-replayed. (Changed after review.)
5. Drivers: tab + WP-Cron on by default, external URL opt-in with hashed token, header preferred. Admin-ajax kept (REST would not be safer). (Recommended.)
6. Default mode skip existing, publish off, cost limit optional; the run acts as the admin who started it with full per-record permission re-checks. (Recommended.)

## Review outcome (pi, 2026-10-03)

All 15 findings accepted: cross-request lock ownership with verified leases instead of a finalize bypass (1); lease TTL from the gateway timeout and CAS saves (2); persisted finalizing intent + reconcile, full outcome persisted (3); source/publish revalidation before prepare and finalize (4); per-tick authorization re-checks (5); resumable terms with structured errors (6); durable per-run accounting with checkpoints and a soft cap that still allows no-API finalize (7); batch-eviction reconciliation and a consistent longer TTL (8); transport-neutral selector loaded everywhere (9); estimate context across chunks, time-bounded chunks (10); safety policy and lifecycle moved into Phase 1 (11); circuit breaker with real gateway codes (12); token hardening list (13); snapshot queue kept with revalidation (14); cron availability shown from actual ticks, alternate-cron note, CLI-equivalent cleanup (15).

## Risks

- Hosts with aggressive proxy timeouts (<60 s) can still kill a tick mid-batch; the lease/CAS protocol makes that safe but slow (the batch is retried, usage of a timed-out reply may be billed twice). Documented.
- WP-Cron-only sites with little traffic will crawl; the UI must make that visible so the admin enables the URL driver.
- Option size for ~5k IDs plus log is tens of KB; fine, but the log and messages are capped.
