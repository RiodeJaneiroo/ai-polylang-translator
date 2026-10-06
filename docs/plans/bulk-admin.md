# Bulk translation from wp-admin, slim variant (tab-driven + recovery journal)

Status: IMPLEMENTED 2026-10-03 as 1.7.0 (two phases, two opus reviews, all findings merged; browser-tested on axio incl. a killed tab mid-batch with no re-pay). Changes after review: completed records leave a tombstone locator + run token (`since`) so Stop→Start never re-pays in any mode; terms run in slices of ≤40 with immediate creation; locator read before precheck; finalize-locked retry. Supersedes `bulk-admin-full-deferred.md` (unattended drivers, kept for later). Follows 1.6.0.

## Goal and the guarantee we actually give

A "Bulk translation" admin page that translates terms, then posts/products/pages, driven only by the open browser tab, one request at a time. No queue of our own, no cron, no external URL, no pause/resume state: **the database is the state**. Stop = stop sending requests; Start again = continue, because records that already have a translation are skipped.

Guarantee (stated honestly, as pi insisted): work that reached a **checkpoint** (a saved translated batch, a finished record) is never paid for again, whatever happens to the tab, the connection or the power. The one thing no design can close is an API call that was billed but whose reply never reached our storage (request killed mid-flight). For a once-only run this residual risk is accepted and shown in the UI as "retried batch".

## What exists and what changes

Reused as is: `AIPT_Pipeline::prepare / translate_batch / finalize` (step pipeline), `AIPT_Job` batch idempotency and completion marker, `AIPT_Terms::translate`, `AIPT_CLI_Select` + `AIPT_Terms::with_ancestors`, CLI policy (`precheck`, `new_post_status`, `dry_run_post`) extracted into a shared `AIPT_Runner` (zero behaviour change for the CLI), `AIPT_Settings::enabled()`, pair locks, `aipt_translation_written`.

Changes, all small (the "recovery journal"):

1. **Prepare-or-reuse, atomic.** Before any paid request, bulk looks up a per-pair locator option `aipt_bulk_job_<post_id>_<lang>` (one option per pair, no shared array → no read-modify-write races). If it points to an unfinished job that matches (same user, backend, source, language, mode options), the job is reused and already-saved batches are skipped; otherwise `prepare` runs and the locator is written **before** the first batch. The locator is removed only when it still points to the job that just finished.
2. **Batch lock.** `translate_batch` wraps "lookup saved batch → API → save batch + usage" in a named lock per `(job_id, index)` (`AIPT_Job::acquire_named_lock`, TTL from the gateway timeout), repeating the lookup under the lock. A reopened tab overlapping its dead predecessor cannot send the same batch twice. Applies to the metabox too (harmless).
3. **Durable job storage for bulk.** Jobs created by bulk store the immutable payload, batches and completion marker in non-autoloaded options (not transients), deleted on completion or explicit discard. A transient TTL or an object-cache flush can no longer throw paid results away. Implemented as a storage flag on `AIPT_Job` (`durable => true`); the metabox keeps transients.
4. **Finalize intent.** Before entering the writer, finalize persists `finalizing` in the job (durable); after the write it persists the full outcome (status, target id, warnings) and only then removes paid batches and the locator. On the next run, a job found in `finalizing` without an outcome is **not** skipped by "translation exists" and **not** replayed: the page lists it under "needs manual check" with the source and (if any) target link, and the admin decides (open the target, or discard the job). Posts written by bulk carry `_aipt_job = job_id` to locate partial writes.
5. **Server-side policy, not JS.** A bulk-specific AJAX layer (`AIPT_Bulk_Ajax`) wraps the pipeline: it enforces `skip_existing` (duplicates stay eligible, pending targets are refused), passes `mode`, `post_status` (publish rule from the shared runner), `keep_date`, runs the shared pre-check (source language/status, parent, terms, `edit_post` on source, create/edit/publish capabilities) before prepare, and **revalidates before finalize**: source still exists and eligible, publish rule recomputed, parent/terms re-checked, capabilities re-checked, and a changed source (post_modified or content/ACF hash stored in the job) is flagged "source changed, review" instead of written. Responses carry structured error codes and batch usage (cost) for the UI. Nonces are per page, not per post; jobs belong to the user who started them.
6. **`ignore_user_abort(true)`** after authorization in the bulk handlers so a request whose client vanished still reaches its checkpoint. Documented as best effort: PHP-FPM limits still apply, and LiteSpeed needs its own `noabort` setting (README note). Existing `set_time_limit(180)` kept.
7. **Terms** stay one taxonomy × language per request (`AIPT_Terms::translate`), but with the same batch checkpointing: the terms job (items/batches/results) is stored durably like a post job, so an interrupted taxonomy resumes from the saved batches and already-created terms are skipped. Insert-then-link orphan window on terms is left as is (pre-existing, cents).

JS (`assets/bulk.js`): plan → terms pairs → records. Terms run single-stream and first; hierarchical post types run single-stream (or a child never starts while its parent is in flight); flat types (products, posts) run through a small worker pool with a "streams" setting 1–3 (default 2, capped at 3 so a shared host keeps PHP workers for visitors). The server needs no change for this: pair locks, per-job storage and the usage lock already serialise what must be serialised. Within one stream requests are strictly sequential; progress, counters and cost from server responses; Stop; stop after N consecutive errors; pause on gateway 401/403 (by error code in the JSON, not HTTP status); soft cost cap that still allows free finalization. All of this is session-local convenience; the server-side journal is what protects the money.

## Admin page

Submenu "Bulk translation" (`manage_options`): post types (those enabled in settings), taxonomies (translated ones with terms), from/to languages, mode (skip existing [default] / update safe / overwrite with confirmation checkbox), publish new translations, keep dates, soft cost limit. **Estimate** (chunked by time, shared runner's dry-run with the planned-parents/terms context carried in a transient between chunks). Start / Stop. Progress bar, counters, current record, errors and warnings with links, "needs manual check" list with discard buttons. Vanilla JS, `assets/admin.css`, ru/uk strings.

## Operational notes (README)

Terms first, then products, then pages; estimate before starting; database backup; keep the tab open and the computer awake (≈ 2 h for 1,460 products); do not run CLI/metabox translations of the same posts concurrently and keep auto-translate off during the run; purge page caches at the end (the per-post `aipt_translation_written` hook already fires); on LiteSpeed set `noabort` for admin-ajax if available.

## Phases

**Phase 1 — Groundwork (zero behaviour change).** `AIPT_Runner` extracted from the CLI (precheck, publish rule, dry-run estimate with context, revalidate); `AIPT_Job` durable storage flag + per-batch lock; `finalize` intent/outcome persistence; `_aipt_job` meta on written posts; structured error codes in pipeline results where missing. Verify: CLI dry-run outputs identical before/after on both sites; one real CLI record per site; metabox flow unchanged.

**Phase 2 — Bulk AJAX layer + page + JS.** Worker pool with the streams setting; `AIPT_Bulk_Ajax` (plan/estimate, terms step, prepare-or-reuse, batch, finalize with revalidation, discard), `AIPT_Bulk_Page`, `assets/bulk.js`, strings, README/CHANGELOG/CLAUDE.md, version 1.7.0. Verify on axio (limited run: 1 taxonomy, 3 products, 1 page): start/stop/restart mid-record reuses the job and pays no batch twice (compare the cost log); kill the PHP worker mid-finalize (simulated) and see the "needs manual check" entry; estimate matches the CLI dry-run; Polylang page renders on sport-liga.

One implementer per phase, one review per phase, no parallel work (the phases touch the same files).

## Decisions to confirm

1. Tab-only driver; no queue/cron/URL; Stop + Start instead of pause/resume. (User's choice.)
2. Recovery journal as listed (locator, batch lock, durable bulk jobs, finalize intent, revalidation): this is the minimum for "never pay twice for checkpointed work", about 300 lines of server code on top of the page. (pi: must-have.)
3. The residual risk of one in-flight API call being billed twice is accepted and surfaced, not engineered away. (pi: reasonable for a once-only run.)
4. Terms keep one request per taxonomy × language but with checkpointed batches. (Compromise.)
5. Default mode skip existing, publish off, soft cost cap optional; the run acts as the admin who started it with per-record permission checks. (Recommended.)
