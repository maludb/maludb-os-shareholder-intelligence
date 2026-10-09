<?php
declare(strict_types=1);

/**
 * The Shareholder Intelligence worker — one pass every five minutes from shareholder_intelligence-worker.timer (design §14): the fetch
 * schedule by source (EDGAR's index and facts, the full-text watch, FINRA's short interest and daily short volume, the SEC's fails, the
 * threshold lists, DTCC's directory, prices — each under its policy), the upload queue (parse, tie, the rows), the matcher's proposals,
 * the rules after every pull and upload, the reconciliation, the search index catch-up, notifications and the outbox, the pack's render,
 * exports, the tombstone purge window, dispatches to agents, key-usage pruning. Idempotent; advisory-locked so two runs never overlap;
 * each pass its own try (one failing never stops the next); the report is printed as one JSON line, logged once as `worker.pass`
 * (counts and sentences, never a holder's name, a filing's text, a credential, a token or a key) and kept as a worker_passes row when
 * the table exists (db/014).
 *   php bin/worker.php [--passes=a,b] [--limit=200]
 * With --passes the named passes run whether or not they are due; without it every pass runs. SI_WORKER_NOW (ISO 8601) replaces the clock
 * a pass selects by; honoured only when APP_ENV is not prod. Exits 1 when a pass failed.
 *
 * PHASE 0: every pass is a STUB that changes nothing and answers 0 — the slice whose data it touches fills it in (design §10):
 * `uploads`, `reconciliation` slice 2's (and 3's); `matcher` slice 3's; `fetch` slices 1 (the directory), 4, 5 and 6's; `rules` slice 7's;
 * `search_catch_up` slice 1's; `packs`, `exports` slice 8's; `outbox`, `dispatches`, `proposals` slice 9's; `tombstones_purge`,
 * `key_usage_prune` slice 10's.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
require_once dirname(__DIR__) . '/app/bootstrap.php';

/** The passes, in the order they run. A new pass is a name here and a function worker_pass_<name>(PDO, int $limit, DateTimeImmutable $now): array. */
const WORKER_PASSES = ['fetch', 'uploads', 'matcher', 'reconciliation', 'rules', 'search_catch_up', 'packs', 'exports',
    'outbox', 'dispatches', 'proposals', 'tombstones_purge', 'key_usage_prune'];

$only = [];
$limit = 200;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--passes=')) {
        $only = array_values(array_filter(explode(',', substr($a, 9))));
        if (array_diff($only, WORKER_PASSES) !== []) {
            fwrite(STDERR, 'Unknown pass: ' . implode(', ', array_diff($only, WORKER_PASSES)) . '. Passes: ' . implode(', ', WORKER_PASSES) . ".\n");
            exit(2);
        }
    } elseif (str_starts_with($a, '--limit=')) {
        $limit = max(1, min(1000, (int) substr($a, 8)));
    } else {
        fwrite(STDERR, "Usage: php bin/worker.php [--passes=a,b] [--limit=N]\n");
        exit(2);
    }
}
$now = new DateTimeImmutable();
if (($fake = getenv('SI_WORKER_NOW')) !== false && $fake !== '' && app_is_prod() === false) {
    $now = new DateTimeImmutable($fake);
}
$pdo = db();
if (!(bool) $pdo->query("SELECT pg_try_advisory_lock(hashtext('shareholder_intelligence_worker'))")->fetchColumn()) {
    echo json_encode(['skipped' => 'another pass holds the lock']) . "\n";
    exit(0);
}
$GLOBALS['__public_door'] = 'cron';                 // every row a pass writes is source cron, actor none
$started = microtime(true);
$report = ['at' => $now->format(DATE_ATOM), 'ran' => [], 'errors' => []];
foreach ($only === [] ? WORKER_PASSES : $only as $pass) {
    try {
        $report['ran'][$pass] = ('worker_pass_' . $pass)($pdo, $limit, $now);
    } catch (Throwable $e) {
        error_log('worker ' . $pass . ': ' . $e->getMessage());
        $report['errors'][] = $pass . ': ' . mb_substr($e->getMessage(), 0, 200);
    }
}
$report['seconds'] = round(microtime(true) - $started, 2);
log_activity($pdo, 'worker.pass', null, null, ['source' => 'cron', 'actor_member_id' => null, 'after' => $report]);
try {
    if ($pdo->query("SELECT to_regclass('public.worker_passes') IS NOT NULL")->fetchColumn()) {
        $pdo->prepare('INSERT INTO worker_passes (started_at, finished_at, steps, error) VALUES (:s, now(), :steps, :err)')
            ->execute(['s' => date(DATE_ATOM, (int) $started), 'steps' => json_encode($report['ran'], JSON_UNESCAPED_SLASHES),
                       'err' => $report['errors'] === [] ? null : implode('; ', $report['errors'])]);
    }
} catch (Throwable $e) {
    error_log('worker_passes: ' . $e->getMessage());
}
echo json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";
$pdo->query("SELECT pg_advisory_unlock(hashtext('shareholder_intelligence_worker'))");
exit($report['errors'] === [] ? 0 : 1);

// ---- the passes (stubs until their slice) ------------------------------------------------------------------------------------------

/**
 * SLICES 1, 4, 5, 6 — every active source whose schedule is due and whose connector this build has (si_fetchers()), on its calendar: one
 * pull under its policy (user-agent, rate, If-Modified-Since, robots, back-off on 403/429); a source_pulls row each (app/si/fetch/source.php).
 */
function worker_pass_fetch(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    require_once dirname(__DIR__) . '/app/si/fetch/source.php';
    $lines = si_fetch_pass($pdo, $now);
    $out = ['pulled' => count($lines), 'blocked' => 0, 'failed' => 0];
    foreach ($lines as $l) {
        if (str_contains($l, ': blocked')) { $out['blocked']++; }
        if (str_contains($l, ': failed')) { $out['failed']++; }
    }
    return $out + ['runs' => $lines];
}
/** SLICES 2, 3 — queued uploads parsed in one transaction each: the raw rows, the typed columns, the control totals tied or the difference named. */
function worker_pass_uploads(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['parsed' => 0, 'tied' => 0, 'untied' => 0, 'stub' => true]; }
/** SLICE 3 — the matcher: raw NOBO and register rows without a holder scored against holders; >= the threshold accepted and logged auto, below it a proposal. */
function worker_pass_matcher(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['auto' => 0, 'proposed' => 0, 'stub' => true]; }
/** SLICES 2, 3 — si_reconcile() for every security with a new share count, SPR date or register since the last run. */
function worker_pass_reconciliation(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['ran' => 0, 'untied' => 0, 'stub' => true]; }
/** SLICE 7 — si_rules_run() after every pull and upload: the enabled rules over their windows; one open signal per rule per security; expiries. */
function worker_pass_rules(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['raised' => 0, 'expired' => 0, 'stub' => true]; }
/** SLICE 1 — si_search_catch_up(): every record whose index row is older than its change (a participant the directory renamed, a holder, an event). */
function worker_pass_search_catch_up(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    return ['indexed' => (int) one_value($pdo, 'SELECT si_search_catch_up(:n)', ['n' => max(1, $limit)])];
}
/** SLICE 8 — the pack's draft on pack_day (a dispatch to the Analyst) and queued renders (HTML, PDF when SI_WKHTMLTOPDF). */
function worker_pass_packs(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['drafted' => 0, 'rendered' => 0, 'stub' => true]; }
/** SLICE 8 — queued exports built (CSV; a holders export logged with its row count); expired exports' files removed. */
function worker_pass_exports(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['built' => 0, 'expired' => 0, 'stub' => true]; }
/** SLICE 9 — the outbox: email through MaluMail (malumail_send), texts to members through the kernel (kernel_send_text), retries with backoff. */
function worker_pass_outbox(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['sent' => 0, 'skipped' => 0, 'retried' => 0, 'failed' => 0, 'stub' => true]; }
/** SLICE 9 — agent dispatches: a new 13D, a high signal, an upload finished, a filing of a watched form → one chat turn, at most three attempts. */
function worker_pass_dispatches(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['dispatched' => 0, 'retried' => 0, 'stub' => true]; }
/** SLICE 9 — the Analyst's standing proposals the worker can make without a model (a holder whose kind is unknown, a signal explained by an event on the same day). */
function worker_pass_proposals(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['proposed' => 0, 'stub' => true]; }
/** SLICE 10 — tombstoned uploads and records older than tombstone_days purged with their rows. */
function worker_pass_tombstones_purge(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['purged' => 0, 'stub' => true]; }
/** SLICE 10 — si_prune_key_usage(): minute buckets older than a day, day buckets older than 35 days. */
function worker_pass_key_usage_prune(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['pruned' => 0, 'stub' => true]; }
