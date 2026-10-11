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
 * Each pass belongs to the slice whose data it touches (design §10): `uploads`, `reconciliation` slice 2's (and 3's); `matcher` slice 3's; `fetch` slices 1 (the directory), 4, 5 and 6's; `rules` slice 7's;
 * `search_catch_up` slice 1's; `packs`, `exports` slice 8's; `outbox`, `dispatches`, `proposals`, and the Friday dispatch in `packs`, slice 9's; the two left as stubs, `tombstones_purge` and
 * `key_usage_prune`, are slice 10's.
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
/** SLICES 2, 3 — queued uploads (a file over 5 MB) parsed and loaded, one transaction each (upload_ingest); the uploader told it is done or does not tie. */
function worker_pass_uploads(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    require_once dirname(__DIR__) . '/app/features/uploads/write.php';
    $out = ['parsed' => 0, 'tied' => 0, 'untied' => 0, 'failed' => 0];
    foreach ($pdo->query("SELECT id, created_by, issuer_id, file_name FROM imports WHERE status = 'queued' AND deleted_at IS NULL ORDER BY created_at LIMIT " . max(1, min(20, $limit)))->fetchAll() as $q) {
        $r = upload_ingest($pdo, (int) $q['id']);
        $out['parsed']++;
        $key = $r['status'] === 'failed' ? 'failed' : ($r['tied'] ? 'tied' : 'untied');
        $out[$key]++;
        if ($q['created_by'] !== null) {
            $pdo->prepare('SELECT si_notify(:m, :k, :t, :b, \'import\', :r, NULL, :i, NULL, :d)')->execute(['m' => (int) $q['created_by'], 'k' => $key === 'tied' ? 'upload_done' : 'upload_untied',
                't' => ($key === 'tied' ? 'Read and tied: ' : ($key === 'failed' ? 'Could not be read: ' : 'Does not tie: ')) . $q['file_name'], 'b' => $key === 'tied' ? null : (string) $r['tie_note'],
                'r' => (int) $q['id'], 'i' => (int) $q['issuer_id'], 'd' => 'upload:' . $q['id'] . ':' . $key]);
        }
    }
    return $out;
}
/**
 * SLICE 3 — the matcher: every usable NOBO list and register whose matching was queued (more than 200 rows waiting), oldest record date first —
 * the order a holder is first seen in — scored against the holders (si_match_import: >= the threshold accepted and logged auto, below it a
 * proposal); the uploader told how many wait for an Analyst.
 */
function worker_pass_matcher(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    require_once dirname(__DIR__) . '/app/features/uploads/write.php';
    $out = ['uploads' => 0, 'auto' => 0, 'proposed' => 0, 'new' => 0, 'failed' => 0];
    foreach ($pdo->query("SELECT id, created_by, issuer_id, file_name FROM imports WHERE kind IN ('nobo', 'register') AND status = 'done' AND deleted_at IS NULL AND (tied OR override_by IS NOT NULL)
                            AND derived_totals -> 'matcher' ->> 'queued' = 'true' ORDER BY record_date, id LIMIT " . max(1, min(50, $limit)))->fetchAll() as $q) {
        $m = upload_match($pdo, (int) $q['id']);
        $out['uploads']++;
        foreach (['auto', 'proposed', 'new', 'failed'] as $k) { $out[$k] += $m[$k]; }
        if ($q['created_by'] !== null) {
            $pdo->prepare('SELECT si_notify(:m, \'upload_done\', :t, :b, \'import\', :r, NULL, :i, NULL, :d)')->execute(['m' => (int) $q['created_by'],
                't' => 'Matched to holders: ' . $q['file_name'], 'b' => $m['proposed'] > 0 ? $m['proposed'] . ' row(s) wait for an Analyst.' : null,
                'r' => (int) $q['id'], 'i' => (int) $q['issuer_id'], 'd' => 'upload:' . $q['id'] . ':matched']);
        }
    }
    return $out;
}
/** SLICES 2, 3 — si_reconcile() as of today for every security whose positions, counts or register changed since its last run (an upload runs its own at once). */
function worker_pass_reconciliation(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    require_once dirname(__DIR__) . '/app/features/uploads/write.php';
    $due = $pdo->query("SELECT s.id FROM securities s
                         WHERE GREATEST((SELECT max(finished_at) FROM imports i WHERE i.security_id = s.id), (SELECT max(recorded_at) FROM share_counts c WHERE c.security_id = s.id), (SELECT max(deleted_at) FROM imports i WHERE i.security_id = s.id))
                               > COALESCE((SELECT max(ran_at) FROM share_reconciliations r WHERE r.security_id = s.id), '-infinity')
                         ORDER BY s.id LIMIT " . max(1, min(200, $limit)))->fetchAll(PDO::FETCH_COLUMN);
    $out = ['ran' => 0, 'tied' => 0, 'untied' => 0, 'incomplete' => 0];
    foreach ($due as $sid) {
        $rid = reconciliation_after($pdo, (int) $sid, $now->format('Y-m-d'), 'the worker');
        $out['ran']++;
        $out[reconciliation_state($pdo->query('SELECT * FROM share_reconciliations WHERE id = ' . $rid)->fetch())]++;
    }
    return $out;
}
/**
 * SLICE 7 — the rules (signals.md §3): rules_run() for every security whose inputs changed since its last run (si_rules_due: an upload tied or
 * tombstoned, a pull that wrote short interest, volume, fails, a threshold list, prices, filings or ownership rows, a borrow observation, an event,
 * a share count, a dilution instrument) as of today in market time; and once each MARKET day from 06:45 market time, every security (so the morning
 * note at 07:00 reads today's signals and a signal past its window and grace expires), then the outcomes (si_signal_outcomes_update). A holiday has
 * no 06:45 run. The members who asked are told of each new signal; a high one also notifies the escalation member and dispatches the Analyst (db/014).
 */
function worker_pass_rules(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    require_once dirname(__DIR__) . '/app/features/issuers/queries.php';
    require_once dirname(__DIR__) . '/app/features/signals/queries.php';
    require_once dirname(__DIR__) . '/app/features/signals/write.php';
    $local = $now->setTimezone(si_market_tz($pdo));
    $today = $local->format('Y-m-d');
    $out = ['securities' => 0, 'raised' => 0, 'refreshed' => 0, 'expired' => 0, 'daily' => false, 'outcomes' => 0];
    $run = static function (int $sid, bool $daily) use ($pdo, $today, &$out): void {
        $pdo->beginTransaction();
        try {
            $r = rules_run($pdo, $sid, $today);
            if ($daily) {
                $pdo->prepare('UPDATE rule_runs SET daily_on = CAST(:d AS date) WHERE security_id = :s')->execute(['d' => $today, 's' => $sid]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $out['securities']++;
        foreach (['raised', 'refreshed', 'expired'] as $k) { $out[$k] += $r[$k]; }
    };
    $morning = (int) $local->format('Hi') >= 645 && si_is_market_day_on($pdo, $now);
    $st = $pdo->prepare('SELECT security_id FROM si_rules_due(:n)');
    $st->execute(['n' => max(1, min(500, $limit))]);
    $ranToday = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        $run((int) $sid, $morning);                                          // after 06:45 a changed security's run is also its run of the day
        $ranToday += $morning ? 1 : 0;
    }
    if ($morning) {
        $st = $pdo->prepare('SELECT s.id FROM securities s JOIN issuers i ON i.id = s.issuer_id LEFT JOIN rule_runs r ON r.security_id = s.id
                              WHERE i.deleted_at IS NULL AND (r.daily_on IS NULL OR r.daily_on < CAST(:d AS date)) ORDER BY s.id LIMIT ' . max(1, min(500, $limit)));
        $st->execute(['d' => $today]);
        $due = $st->fetchAll(PDO::FETCH_COLUMN);
        foreach ($due as $sid) {
            $run((int) $sid, true);
        }
        if ($due !== [] || $ranToday > 0) {
            $out['daily'] = true;
            $out['outcomes'] = (int) $pdo->query('SELECT si_signal_outcomes_update(' . max(1, min(2000, $limit * 5)) . ')')->fetchColumn();
            log_activity($pdo, 'outcomes.update', null, null, ['source' => 'cron', 'actor_member_id' => null, 'after' => ['signals' => $out['outcomes'], 'on' => $today]]);
        }
    }
    return $out;
}
/** SLICE 1 — si_search_catch_up(): every record whose index row is older than its change (a participant the directory renamed, a holder, an event). */
function worker_pass_search_catch_up(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    return ['indexed' => (int) one_value($pdo, 'SELECT si_search_catch_up(:n)', ['n' => max(1, $limit)])];
}
/**
 * SLICE 8 — the packs: an APPROVED pack that has no rendering (the renderer was missing, the file was lost, the approval pre-dated it) is rendered as its approver, and the PDF
 * made when SI_WKHTMLTOPDF names a binary. A draft is rendered by a person, never here. SLICE 9 — on the pack's day (si_settings.pack_day, in market time) the Surveillance Analyst is dispatched
 * once for each issuer it covers that has no weekly pack for the week: it answers with report_draft, and a manager approves (pack_day_dispatches).
 */
function worker_pass_packs(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    require_once dirname(__DIR__) . '/app/features/reports/screen.php';
    require_once dirname(__DIR__) . '/app/features/agents/write.php';
    $out = ['rendered' => 0, 'failed' => 0, 'dispatched' => pack_day_dispatches($pdo, $now)];
    $st = $pdo->query("SELECT id, approved_by FROM reports WHERE status IN ('approved', 'published') AND rendered_path IS NULL AND deleted_at IS NULL AND approved_by IS NOT NULL ORDER BY id LIMIT " . max(1, min(20, $limit)));
    foreach ($st->fetchAll() as $r) {
        try {
            as_member($pdo, (int) $r['approved_by'], static function () use ($pdo, $r): void {
                $pdo->beginTransaction();
                report_render($pdo, (int) $r['id'], true);
                $pdo->commit();
            });
            $out['rendered']++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('worker packs ' . $r['id'] . ': ' . $e->getMessage());
            $out['failed']++;
        }
    }
    return $out;
}
/**
 * SLICE 8 — exports: what was left queued is built as the person who asked (the database's acting member set to them, so the walls read as them; they are told it is ready), and
 * an export past its seven days has its file removed (the row stays as the trail's record; the download answers that it expired).
 */
function worker_pass_exports(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    require_once dirname(__DIR__) . '/app/features/reports/screen.php';
    require_once dirname(__DIR__) . '/app/features/exports/write.php';
    $out = ['built' => 0, 'failed' => 0, 'expired' => 0];
    foreach ($pdo->query("SELECT x.id, x.created_by, x.kind, x.issuer_id, (m.status = 'active') AS active FROM exports x LEFT JOIN members m ON m.id = x.created_by WHERE x.status = 'queued' ORDER BY x.id LIMIT " . max(1, min(50, $limit)))->fetchAll() as $q) {
        if ($q['created_by'] === null || !$q['active']) {
            $pdo->prepare("UPDATE exports SET status = 'failed', finished_at = now(), log = 'The person who asked is no longer active.' WHERE id = :id AND status = 'queued'")->execute(['id' => $q['id']]);
            $out['failed']++;
            continue;
        }
        $x = as_member($pdo, (int) $q['created_by'], static fn (): array => export_build($pdo, (int) $q['id']));
        if ($x['status'] === 'done') {
            $out['built']++;
            $pdo->prepare("SELECT si_notify(:m, 'export_ready', :t, NULL, 'export', :r, NULL, :i, NULL, :d)")->execute(['m' => (int) $q['created_by'], 't' => 'Your export is ready: ' . (EXPORT_KINDS[$q['kind']] ?? $q['kind']),
                'r' => (int) $q['id'], 'i' => $q['issuer_id'] === null ? null : (int) $q['issuer_id'], 'd' => 'export_ready:' . $q['id']]);
        } else {
            $out['failed']++;
        }
    }
    foreach ($pdo->query("SELECT id, storage_path FROM exports WHERE status = 'done' AND storage_path IS NOT NULL AND expires_at < now() ORDER BY id LIMIT " . max(1, min(200, $limit)))->fetchAll() as $e) {
        si_unlink_named((string) $e['storage_path']);
        $pdo->prepare('UPDATE exports SET storage_path = NULL WHERE id = :id')->execute(['id' => $e['id']]);
        $out['expired']++;
    }
    return $out;
}
/**
 * SLICE 9 — the outbox: each queued notification whose time has come — an email through MaluMail to the member (malumail_send), a text to the member through the kernel (K6, kernel_send_text) — retried
 * at 1, 5 and 30 minutes, then failed; a refused address or an opted-out phone is skipped, never retried (agents/write.php outbox_run).
 */
function worker_pass_outbox(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    require_once dirname(__DIR__) . '/app/features/agents/write.php';
    return outbox_run($pdo, $limit);
}
/**
 * SLICE 9 — agent dispatches: a high signal, a Schedule 13D, an upload that tied and the Friday pack each hand the Surveillance Analyst ONE chat turn on the kernel's chat endpoint (as the agent, with the
 * dispatch's words); a turn still running is asked again, a failed one retried after 5 and 15 minutes, three attempts in all; a cancelled dispatch, or one whose record no longer needs the agent, is not made.
 */
function worker_pass_dispatches(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    require_once dirname(__DIR__) . '/app/features/agents/write.php';
    return dispatches_run($pdo, $limit);
}
/**
 * SLICE 9 — the Analyst's standing proposals the worker can make without a model: a holder whose kind is unknown and whose name ends in LLC, Inc or Trust gets a kind proposal a person decides.
 * A signal explained by an event is the agent's to propose, never the worker's; the worker disposes of nothing.
 */
function worker_pass_proposals(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    require_once dirname(__DIR__) . '/app/features/agents/write.php';
    return standing_proposals_run($pdo, $limit);
}
/** SLICE 10 — tombstoned uploads and records older than tombstone_days purged with their rows. */
function worker_pass_tombstones_purge(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['purged' => 0, 'stub' => true]; }
/** SLICE 10 — si_prune_key_usage(): minute buckets older than a day, day buckets older than 35 days. */
function worker_pass_key_usage_prune(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['pruned' => 0, 'stub' => true]; }
