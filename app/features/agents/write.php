<?php
declare(strict_types=1);

/**
 * The agents' writes and the worker's four passes (agents-k7-api.md "Query functions" and "The worker's passes", signatures fixed).
 *
 *  - notify_member(): an agent's (or a person's) notice to a member — the bell and the outbox rows their preferences ask for (si_notify, db/014 — which never tells anyone about an issuer
 *    they may not see). The words go in the notification; the log carries {to, kind, issuer_id} and never them.
 *  - dispatch_cancel(), agents_settings_save(): a person's, the database keeping the right and the shape (db/026 si_agents_settings_save).
 *  - dispatches_run(): each `sent` dispatch (a high signal, a 13D, an upload read, the Friday pack) is ONE chat turn on the kernel's chat endpoint as the agent, with the dispatch's words;
 *    the reply's first 200 characters are kept; three attempts; a cancelled dispatch is never made. The agent's own actions are the kernel's and the actions MCP's, under their approvals.
 *  - outbox_run(): the notification outbox — email through MaluMail to members, texts through the kernel (K6) — retried at 1, 5 and 30 minutes, then failed.
 *  - standing_proposals_run(): the proposals the worker can make without a model (a holder whose kind is unknown and whose name ends in LLC, Inc or Trust → a proposal a person decides).
 *  - pack_day_dispatches(): on the pack's day, the Analyst is asked to draft each issuer's weekly pack.
 * Nothing here decides anything about the business's data: an agent proposes, a person disposes.
 */
require_once dirname(__DIR__, 2) . '/si/fetch/calendar.php';
require_once dirname(__DIR__) . '/settings/queries.php';
require_once dirname(__DIR__) . '/settings/present.php';
require_once __DIR__ . '/queries.php';

/** The kinds of notice an agent may send (db/014's list, the ones that are words rather than events). */
const NOTIFY_KINDS = ['morning_note' => 'Morning note', 'signal' => 'Signal', 'mention' => 'Mention', 'digest' => 'Digest'];
const DISPATCH_MAX_ATTEMPTS = 3;
/** Minutes to wait after a dispatch's first and second failed turns. */
const DISPATCH_RETRY_MINUTES = [5, 15];
/** A run the kernel has not finished after this long is a failed turn. */
const DISPATCH_RUN_MINUTES = 15;
/** The outbox's waits after the first, second and third failed attempts; a fourth failure is final. */
const OUTBOX_RETRY_MINUTES = [1, 5, 30];

// ---- notifications --------------------------------------------------------------------------------------------------------------

/**
 * A notice to a member: kind (morning_note, signal, mention or digest), a title, an optional body, the issuer it is about (never one the SENDER may not see, and — the database's rule — never
 * one the RECIPIENT may not see: then nothing is sent and null is answered). $by = the sender. Answers the notification's id. Logs `notification.send` {to, kind, issuer_id}.
 */
function notify_member(PDO $pdo, int $to, string $kind, string $title, ?string $body, ?int $issuer, int $by): ?int
{
    if (!isset(NOTIFY_KINDS[$kind])) {
        throw new DomainException('The kind is one of ' . implode(', ', array_keys(NOTIFY_KINDS)) . '.');
    }
    $r = $pdo->prepare('SELECT member_kind, status, capability FROM members WHERE id = :m');
    $r->execute(['m' => $to]);
    $m = $r->fetch();
    if ($m === false || $m['status'] !== 'active' || $m['capability'] === null) {
        throw new DomainException('Not found.');
    }
    if ($m['member_kind'] !== 'human') {
        throw new DomainException('A notice goes to a person; an agent is messaged through the kernel.');
    }
    if ($issuer !== null && one_value($pdo, 'SELECT 1 FROM mcp_issuers WHERE issuer_id = :i', ['i' => $issuer]) === null) {
        throw new DomainException('Not found.');
    }
    $id = one_value($pdo, 'SELECT si_notify(:m, :k, :t, :b, :rt, :rid, NULL, :i, :by, NULL)',
        ['m' => $to, 'k' => $kind, 't' => $title, 'b' => $body, 'rt' => $issuer === null ? null : 'issuer', 'rid' => $issuer, 'i' => $issuer, 'by' => $by]);
    if ($id === null) {
        return null;
    }
    log_activity($pdo, 'notification.send', 'notification', (int) $id, ['issuer_id' => $issuer, 'after' => ['to' => $to, 'kind' => $kind, 'issuer_id' => $issuer]]);
    return (int) $id;
}

// ---- the agents screen's writes ---------------------------------------------------------------------------------------------------

/** Cancel a dispatch that has not been answered (sent, failed with attempts left, awaiting approval): the worker never makes it. Logs `dispatch.cancel` {dispatch_id, kind, was}. */
function dispatch_cancel(PDO $pdo, int $id): void
{
    $d = find_dispatch($pdo, $id) ?? throw new DomainException('Not found.');
    if (!in_array($d['status'], DISPATCH_CANCELLABLE, true)) {
        throw new DomainException('The dispatch is ' . strtolower(DISPATCH_STATUSES[$d['status']] ?? $d['status']) . ': it cannot be cancelled.');
    }
    $st = $pdo->prepare("UPDATE agent_dispatches SET status = 'cancelled', detail = 'Cancelled by a person.', next_attempt_at = NULL WHERE id = :id AND status = :was");
    $st->execute(['id' => $id, 'was' => $d['status']]);
    if ($st->rowCount() !== 1) {
        throw new DomainException('The dispatch changed while you looked: reload it.');
    }
    log_activity($pdo, 'dispatch.cancel', 'agent_dispatch', $id, ['signal_id' => $d['signal_id'], 'after' => ['dispatch_id' => $id, 'kind' => $d['kind'], 'was' => $d['status']]]);
}

/**
 * Save the Analyst's settings: $f holds what the request gave (morning_note_enabled, morning_note_hour, analyst_proposals, pack_day, analyst_issuers (a list of issuer ids; empty = every issuer),
 * escalate_high_to (a member id; 0 or null = none)); a field left out stays as it was. The database checks the right and the shape. Answers ['before' => …, 'after' => …] of the fields that changed;
 * logs `agents_settings.save` with them.
 */
function agents_settings_save(PDO $pdo, array $f): array
{
    $now = agents_overview($pdo)['settings'];
    $next = [
        'morning_note_enabled' => array_key_exists('morning_note_enabled', $f) ? (bool) $f['morning_note_enabled'] : $now['morning_note_enabled'],
        'morning_note_hour' => array_key_exists('morning_note_hour', $f) ? (int) $f['morning_note_hour'] : $now['morning_note_hour'],
        'analyst_proposals' => array_key_exists('analyst_proposals', $f) ? (bool) $f['analyst_proposals'] : $now['analyst_proposals'],
        'pack_day' => array_key_exists('pack_day', $f) ? (int) $f['pack_day'] : $now['pack_day'],
        'analyst_issuer_ids' => array_key_exists('analyst_issuers', $f) ? array_values(array_unique(array_map('intval', (array) $f['analyst_issuers']))) : $now['analyst_issuer_ids'],
        'escalate_high_to' => array_key_exists('escalate_high_to', $f) ? ((int) $f['escalate_high_to'] > 0 ? (int) $f['escalate_high_to'] : null) : $now['escalate_high_to'],
    ];
    sort($next['analyst_issuer_ids']);
    $st = $pdo->prepare('SELECT si_agents_settings_save(:ne, CAST(:nh AS smallint), :pr, CAST(:pd AS smallint), CAST(:iss AS bigint[]), CAST(:esc AS bigint))');
    $st->execute(['ne' => $next['morning_note_enabled'] ? 't' : 'f', 'nh' => $next['morning_note_hour'], 'pr' => $next['analyst_proposals'] ? 't' : 'f', 'pd' => $next['pack_day'],
                  'iss' => pg_array_literal($next['analyst_issuer_ids']), 'esc' => $next['escalate_high_to']]);
    $b = [];
    $a = [];
    foreach ($next as $k => $v) {
        if ($now[$k] !== $v) {
            $b[$k] = $now[$k];
            $a[$k] = $v;
        }
    }
    log_activity($pdo, 'agents_settings.save', 'settings', 1, ['before' => $b, 'after' => $a]);
    return ['before' => $b, 'after' => $a];
}

// ---- dispatches: one chat turn per dispatch ---------------------------------------------------------------------------------------

/**
 * Make a dispatch to the Surveillance Analyst (the first active agent whose name says analyst) for a record — unless one is already made for it (a signal, a filing and an upload once; the pack once an
 * issuer a day). Answers the id, or null when there is no Analyst or the dispatch exists. The worker's dispatches pass sends it.
 */
function dispatch_make(PDO $pdo, string $kind, ?string $recordType, ?int $recordId, ?int $signal = null, ?int $filing = null, ?int $import = null, string $detail = ''): ?int
{
    $agent = one_value($pdo, 'SELECT si_analyst_agent()');
    if ($agent === null) {
        return null;
    }
    $dup = match ($kind) {
        'upload_done' => one_value($pdo, "SELECT 1 FROM agent_dispatches WHERE kind = 'upload_done' AND import_id = :i", ['i' => $import]),                    // a cancelled one stays cancelled
        'pack' => one_value($pdo, "SELECT 1 FROM agent_dispatches WHERE kind = 'pack' AND record_id = :r AND created_at >= date_trunc('day', now()) - interval '1 day'", ['r' => $recordId]),
        default => null,
    };
    if ($dup !== null) {
        return null;
    }
    $st = $pdo->prepare("INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, signal_id, filing_id, import_id, detail) VALUES (:rt, :ri, :a, :k, 'chat', :s, :f, :i, :d) RETURNING id");
    $st->execute(['rt' => $recordType, 'ri' => $recordId, 'a' => (int) $agent, 'k' => $kind, 's' => $signal, 'f' => $filing, 'i' => $import, 'd' => $detail === '' ? null : $detail]);
    return (int) $st->fetchColumn();
}

/**
 * What to ask the agent, from the facts of the record now (not as they were when the dispatch was made): ['skip' => a reason the dispatch is no longer worth making, or null, 'issuer_id', 'utterance',
 * 'context']. The words carry names of rules, securities and issuers, counts and dates — never a holder.
 */
function dispatch_prepare(PDO $pdo, array $d): array
{
    $none = static fn (string $why): array => ['skip' => $why, 'issuer_id' => null, 'utterance' => '', 'context' => []];
    switch ($d['kind']) {
        case 'signal':
            $st = $pdo->prepare('SELECT s.status, s.severity, s.summary, s.issuer_id, r.name AS rule, sec.name AS security, i.name AS issuer, i.ticker FROM signals s JOIN signal_rules r ON r.id = s.rule_id
                                   JOIN securities sec ON sec.id = s.security_id JOIN issuers i ON i.id = s.issuer_id WHERE s.id = :id');
            $st->execute(['id' => (int) $d['signal_id']]);
            $s = $st->fetch();
            if ($s === false) { return $none('The signal is gone.'); }
            if ($s['status'] !== 'open') { return $none('The signal is ' . $s['status'] . ' already.'); }
            return ['skip' => null, 'issuer_id' => (int) $s['issuer_id'], 'context' => ['entity' => 'signal', 'record_id' => (int) $d['signal_id'], 'issuer_id' => (int) $s['issuer_id'], 'issuer' => $s['issuer']],
                    'utterance' => 'A ' . $s['severity'] . ' signal was raised on ' . $s['issuer'] . ($s['ticker'] ? ' (' . $s['ticker'] . ')' : '') . ': ' . $s['rule'] . ' — ' . mb_substr((string) $s['summary'], 0, 300)
                        . ' Read it (signals_open) and propose how a person should dispose of it with signal_propose: a reason, the explaining event when an event explains it, and one sentence of why. Do not dispose of it yourself. Tell me anything a person must look at.'];
        case 'filing':
            $st = $pdo->prepare('SELECT f.form, f.filed_on, f.issuer_id, i.name AS issuer, i.ticker FROM filings f JOIN issuers i ON i.id = f.issuer_id WHERE f.id = :id');
            $st->execute(['id' => (int) $d['filing_id']]);
            $f = $st->fetch();
            if ($f === false) { return $none('The filing is gone.'); }
            return ['skip' => null, 'issuer_id' => (int) $f['issuer_id'], 'context' => ['entity' => 'filing', 'record_id' => (int) $d['filing_id'], 'issuer_id' => (int) $f['issuer_id'], 'issuer' => $f['issuer']],
                    'utterance' => 'A ' . $f['form'] . ' was filed on ' . $f['issuer'] . ($f['ticker'] ? ' (' . $f['ticker'] . ')' : '') . ' on ' . $f['filed_on'] . '. Read what it says with the filing tools. If it matters — a new beneficial owner, a financing, '
                        . 'an insider sale — record an event (event_create) and say what a person should look at. Do not send anything outside the business.'];
        case 'upload_done':
            $st = $pdo->prepare('SELECT x.kind, x.record_date, x.period_to, x.tied, x.issuer_id, i.name AS issuer, i.ticker FROM imports x JOIN issuers i ON i.id = x.issuer_id WHERE x.id = :id');
            $st->execute(['id' => (int) $d['import_id']]);
            $x = $st->fetch();
            if ($x === false) { return $none('The upload is gone.'); }
            return ['skip' => null, 'issuer_id' => (int) $x['issuer_id'], 'context' => ['entity' => 'import', 'record_id' => (int) $d['import_id'], 'issuer_id' => (int) $x['issuer_id'], 'issuer' => $x['issuer']],
                    'utterance' => 'An upload of ' . $x['issuer'] . ' was read and ' . ($x['tied'] ? 'ties' : 'does not tie') . ' to its own totals: a ' . str_replace('_', ' ', (string) $x['kind']) . ' as of ' . ($x['record_date'] ?? $x['period_to']) . '. '
                        . 'Check the reconciliation (reconciliation_latest) and the rows waiting for a holder: propose the matches that have a clear candidate (holder_match_propose) and the kinds you are sure of (holder_kind_propose). A person decides each. Tell me what needs a person.'];
        case 'pack':
            $st = $pdo->prepare('SELECT i.id, i.name, i.ticker FROM issuers i WHERE i.id = :id AND i.deleted_at IS NULL');
            $st->execute(['id' => (int) $d['record_id']]);
            $i = $st->fetch();
            if ($i === false) { return $none('The issuer is gone.'); }
            $to = substr((string) $d['created_at'], 0, 10);
            $from = (new DateTimeImmutable($to))->modify('-6 days')->format('Y-m-d');
            return ['skip' => null, 'issuer_id' => (int) $i['id'], 'context' => ['entity' => 'issuer', 'record_id' => (int) $i['id'], 'issuer_id' => (int) $i['id'], 'issuer' => $i['name']],
                    'utterance' => 'Draft the weekly pack for ' . $i['name'] . ($i['ticker'] ? ' (' . $i['ticker'] . ')' : '') . ' for ' . $from . ' to ' . $to . ' with report_draft: the narrative over the standard sections — what moved at DTC, the public record, '
                        . 'the short side, the reconciliation and the open signals. A manager approves it; do not send it.'];
        default:
            return ['skip' => null, 'issuer_id' => null, 'context' => [], 'utterance' => (string) ($d['detail'] ?: 'Please look at the work waiting for you and tell me what needs a person.')];
    }
}

/**
 * One chat turn: the kernel's chat endpoint as the agent (`agent` = its member id), acting as $acting (the person the dispatch names, else the agent itself), with the conversation $conversation.
 * Answers ['status' => answered | refused | failed | awaiting_approval | running, 'detail', 'reply' (200 characters), 'run_id'].
 */
function dispatch_turn(int $agent, int $acting, string $utterance, string $conversation, array $context): array
{
    $answer = kernel_call('POST', '/api/v1/agents/chat.php?agent=' . $agent, ['utterance' => mb_substr($utterance, 0, 4000), 'context' => ['application' => app_key()] + $context, 'conversation_id' => $conversation, 'wait' => 20],
        ['X-Acting-Member: ' . $acting], 40);
    return dispatch_result($answer);
}

/** A running turn asked again (GET ?run=). */
function dispatch_poll(int $run): array
{
    return dispatch_result(kernel_call('GET', '/api/v1/agents/chat.php?run=' . $run, null, [], 20));
}

/** The kernel's answer as a dispatch's outcome. A busy agent (409) is a failed turn to try again, not a refusal. */
function dispatch_result(?array $answer): array
{
    $out = static fn (string $status, ?string $detail = null, ?string $reply = null, ?int $run = null): array => ['status' => $status, 'detail' => $detail === null ? null : mb_substr($detail, 0, 200), 'reply' => $reply === null ? null : mb_substr($reply, 0, 200), 'run_id' => $run];
    if ($answer === null || $answer['status'] >= 500 || $answer['status'] === 401 || $answer['status'] === 409) {
        return $out('failed', $answer === null ? 'The kernel did not answer.' : ($answer['status'] === 409 ? 'The agent is busy with another turn.' : 'The kernel answered HTTP ' . $answer['status'] . '.'));
    }
    $b = $answer['body'] ?? [];
    if ($answer['status'] >= 400) {
        return $out('refused', (string) ($b['error']['message'] ?? 'The kernel refused it.'));
    }
    $run = isset($b['run_id']) ? (int) $b['run_id'] : null;
    $status = (string) ($b['status'] ?? '');
    if ($status === 'awaiting_approval') {
        return $out('awaiting_approval', null, null, $run);
    }
    if (in_array($status, ['failed', 'error', 'cancelled', 'timed_out'], true)) {
        return $out('failed', 'The turn ended ' . $status . '.', null, $run);
    }
    if (empty($b['finished']) && $status !== 'succeeded') {
        return $run === null ? $out('failed', 'The turn did not finish (' . ($status ?: 'unknown') . ').') : $out('running', null, null, $run);
    }
    return $out('answered', null, (string) ($b['reply'] ?? ''), $run);
}

/** Record a turn's outcome on its row and in the trail: agent.dispatch (the turn was made), agent.reply (answered; its length only), agent.fail (refused, or failed for good). */
function dispatch_finish(PDO $pdo, array $d, array $r, int $attempt): void
{
    $final = $r['status'] !== 'failed' || $attempt >= DISPATCH_MAX_ATTEMPTS;
    $next = !$final ? ['m' => DISPATCH_RETRY_MINUTES[min($attempt, count(DISPATCH_RETRY_MINUTES)) - 1]] : null;
    $status = $r['status'] === 'running' ? 'sent' : $r['status'];
    $pdo->prepare("UPDATE agent_dispatches SET status = :s, detail = :d, reply_excerpt = COALESCE(:r, reply_excerpt), run_id = COALESCE(:run, run_id), attempts = :a, request_id = :rid,
                          answered_at = CASE WHEN :s2 IN ('answered', 'awaiting_approval') THEN now() ELSE answered_at END,
                          next_attempt_at = CASE WHEN CAST(:m AS integer) IS NULL THEN NULL ELSE now() + make_interval(mins => CAST(:m2 AS integer)) END WHERE id = :id AND status <> 'cancelled'")      // a cancel made while the turn ran stands
        ->execute(['s' => $status, 'd' => $r['detail'], 'r' => $r['reply'], 'run' => $r['run_id'], 'a' => $attempt, 'rid' => 'si-dispatch-' . $d['id'], 's2' => $status, 'm' => $next['m'] ?? null, 'm2' => $next['m'] ?? null, 'id' => (int) $d['id']]);
    $ids = ['signal_id' => $d['signal_id'] === null ? null : (int) $d['signal_id']];
    $after = ['kind' => $d['kind'], 'via' => 'chat', 'status' => $status, 'attempt' => $attempt, 'agent_member_id' => (int) $d['agent_member_id'], 'record_type' => $d['record_type'], 'record_id' => $d['record_id'] === null ? null : (int) $d['record_id']];
    log_activity($pdo, 'agent.dispatch', 'agent_dispatch', (int) $d['id'], $ids + ['after' => $after]);
    if ($status === 'answered') {
        log_activity($pdo, 'agent.reply', 'agent_dispatch', (int) $d['id'], $ids + ['after' => ['kind' => $d['kind'], 'reply_length' => mb_strlen((string) $r['reply'])]]);
    } elseif ($final && in_array($status, ['refused', 'failed'], true)) {
        log_activity($pdo, 'agent.fail', 'agent_dispatch', (int) $d['id'], $ids + ['after' => ['kind' => $d['kind'], 'status' => $status, 'attempts' => $attempt]]);
    }
}

/**
 * The worker's dispatches pass: first the turns still running at the kernel (asked again; one not finished in 15 minutes has failed), then each `sent` dispatch that has never had a turn,
 * then each failed one with attempts left whose wait is over. A cancelled dispatch is never read; a dispatch whose record no longer needs the agent (a signal disposed meanwhile, an issuer the
 * Analyst does not cover) is cancelled with its reason. At most 12 turns a pass. Answers the counts.
 */
function dispatches_run(PDO $pdo, int $limit): array
{
    $out = ['dispatched' => 0, 'retried' => 0, 'answered' => 0, 'refused' => 0, 'failed' => 0, 'awaiting_approval' => 0, 'cancelled' => 0, 'polled' => 0, 'running' => 0];
    $budget = max(1, min(12, $limit));
    $analyst = one_value($pdo, 'SELECT si_analyst_agent()');
    foreach ($pdo->query("SELECT * FROM agent_dispatches WHERE status = 'sent' AND run_id IS NOT NULL ORDER BY id LIMIT 20")->fetchAll() as $d) {
        $r = dispatch_poll((int) $d['run_id']);
        $out['polled']++;
        if ($r['status'] === 'running') {
            if (strtotime((string) $d['created_at']) < time() - DISPATCH_RUN_MINUTES * 60) {
                dispatch_finish($pdo, $d, ['status' => 'failed', 'detail' => 'The turn did not finish in ' . DISPATCH_RUN_MINUTES . ' minutes.', 'reply' => null, 'run_id' => (int) $d['run_id']], DISPATCH_MAX_ATTEMPTS);
                $out['failed']++;
            } else {
                $out['running']++;
            }
            continue;
        }
        dispatch_finish($pdo, $d, $r, max(1, (int) $d['attempts']));
        $out[$r['status']] = ($out[$r['status']] ?? 0) + 1;
    }
    $due = $pdo->query("SELECT * FROM (
            SELECT * FROM agent_dispatches WHERE status = 'sent' AND run_id IS NULL AND request_id IS NULL
            UNION ALL
            SELECT * FROM agent_dispatches WHERE status = 'failed' AND attempts < " . DISPATCH_MAX_ATTEMPTS . ' AND (next_attempt_at IS NULL OR next_attempt_at <= now())) q ORDER BY id LIMIT 60')->fetchAll();
    foreach ($due as $d) {
        if ($budget <= 0) {
            break;
        }
        $attempt = $d['status'] === 'failed' ? (int) $d['attempts'] + 1 : 1;
        $p = dispatch_prepare($pdo, $d);
        if ($p['skip'] === null && $analyst !== null && (int) $d['agent_member_id'] === (int) $analyst && $p['issuer_id'] !== null
            && !(bool) one_value($pdo, 'SELECT si_analyst_covers(:i)', ['i' => $p['issuer_id']])) {
            $p['skip'] = 'The Analyst does not cover this issuer.';
        }
        $a = $pdo->prepare("SELECT 1 FROM members WHERE id = :a AND member_kind = 'agent' AND status = 'active' AND capability IS NOT NULL");
        $a->execute(['a' => (int) $d['agent_member_id']]);
        if ($p['skip'] === null && $a->fetchColumn() === false) {
            $p['skip'] = 'The agent is no longer active here.';
        }
        if ($p['skip'] !== null) {
            $pdo->prepare("UPDATE agent_dispatches SET status = 'cancelled', detail = :d, next_attempt_at = NULL WHERE id = :id AND status IN ('sent', 'failed')")->execute(['d' => $p['skip'], 'id' => (int) $d['id']]);
            log_activity($pdo, 'dispatch.cancel', 'agent_dispatch', (int) $d['id'], ['signal_id' => $d['signal_id'], 'after' => ['dispatch_id' => (int) $d['id'], 'kind' => $d['kind'], 'was' => $d['status'], 'by' => 'the worker', 'reason' => $p['skip']]]);
            $out['cancelled']++;
            continue;
        }
        $budget--;
        $r = dispatch_turn((int) $d['agent_member_id'], (int) ($d['acting_member_id'] ?? $d['agent_member_id']), $p['utterance'], 'si-dispatch-' . $d['id'], $p['context']);
        dispatch_finish($pdo, $d, $r, $attempt);
        $out[$attempt > 1 ? 'retried' : 'dispatched']++;
        if ($r['status'] !== 'running') {
            $out[$r['status']] = ($out[$r['status']] ?? 0) + 1;
        } else {
            $out['running']++;
        }
    }
    return $out;
}

/**
 * On the pack's day (si_settings.pack_day, ISO: 5 = Friday, in market time) each issuer the Analyst covers that has no pack for the week gets a `pack` dispatch: the Analyst is asked to draft it with
 * report_draft. Once an issuer a day. Answers how many were made.
 */
function pack_day_dispatches(PDO $pdo, DateTimeImmutable $now): int
{
    $local = $now->setTimezone(si_market_tz($pdo));
    if ((int) $local->format('N') !== (int) one_value($pdo, 'SELECT pack_day FROM si_settings WHERE id = 1') || one_value($pdo, 'SELECT si_analyst_agent()') === null) {
        return 0;
    }
    $made = 0;
    $agent = (int) one_value($pdo, 'SELECT si_analyst_agent()');
    // the issuers the Analyst covers AND can see (a restricted issuer's list must name it), with no weekly pack for the week yet
    $st = $pdo->prepare("SELECT i.id FROM issuers i WHERE i.deleted_at IS NULL AND si_analyst_covers(i.id)
                           AND (NOT EXISTS (SELECT 1 FROM issuer_members m WHERE m.issuer_id = i.id) OR EXISTS (SELECT 1 FROM issuer_members m WHERE m.issuer_id = i.id AND m.member_id = {$agent}))
                           AND NOT EXISTS (SELECT 1 FROM reports r WHERE r.issuer_id = i.id AND r.kind = 'weekly_pack' AND r.deleted_at IS NULL AND r.period_to >= CAST(:d AS date) - 6)
                         ORDER BY i.id");
    $st->execute(['d' => $local->format('Y-m-d')]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $issuer) {
        $id = dispatch_make($pdo, 'pack', 'issuer', (int) $issuer, null, null, null, 'the weekly pack');
        $made += $id === null ? 0 : 1;
    }
    return $made;
}

/** An upload read and tied hands the Analyst the follow-up (the matches to propose, the reconciliation to read): one dispatch per upload. Answers its id (or null). */
function dispatch_upload_done(PDO $pdo, int $import): ?int
{
    $x = $pdo->prepare('SELECT issuer_id FROM imports WHERE id = :i');
    $x->execute(['i' => $import]);
    $issuer = $x->fetchColumn();
    return $issuer === false ? null : dispatch_make($pdo, 'upload_done', 'import', $import, null, null, $import, 'upload read');
}

// ---- the standing proposals the worker can make without a model ------------------------------------------------------------------------

/**
 * A holder whose kind is unknown and whose name ends in LLC, Inc or Trust gets a kind proposal (db/018 holder_kind_proposals, proposed_by NULL = the worker) a person accepts or rejects — the entity
 * type as db/008's own rule reads a name. One proposal a holder ever (a decided one is remembered); only while the settings say the Analyst proposes, and for the issuers it covers. A signal explained
 * by an event is NOT proposed here: the agent proposes, the worker never disposes. Answers ['proposed' => n].
 */
function standing_proposals_run(PDO $pdo, int $limit): array
{
    if (!(bool) one_value($pdo, 'SELECT analyst_proposals FROM si_settings WHERE id = 1')) {
        return ['proposed' => 0, 'off' => true];
    }
    $st = $pdo->query("SELECT h.id, h.issuer_id, h.display_name FROM holders h
                        WHERE h.kind = 'unknown' AND h.deleted_at IS NULL AND h.merged_into_id IS NULL AND si_analyst_covers(h.issuer_id)
                          AND h.display_name ~* '[[:space:],.]+(LLC|L\\.L\\.C\\.|INC|INC\\.|TRUST)[[:space:].]*$'
                          AND NOT EXISTS (SELECT 1 FROM holder_kind_proposals k WHERE k.holder_id = h.id)
                        ORDER BY h.id LIMIT " . max(1, min(500, $limit)));
    $n = 0;
    $ins = $pdo->prepare("INSERT INTO holder_kind_proposals (holder_id, kind, entity_type, evidence, proposed_by) VALUES (:h, 'entity', :t, :e, NULL) ON CONFLICT DO NOTHING");
    foreach ($st->fetchAll() as $h) {
        $name = strtoupper((string) $h['display_name']);
        $type = str_contains($name, 'TRUST') ? 'trust' : 'corporation';
        $word = preg_match('~(LLC|L\.L\.C\.|INC\.?|TRUST)[\s.]*$~i', (string) $h['display_name'], $m) ? strtoupper(rtrim($m[1], '.')) : 'entity';
        $ins->execute(['h' => (int) $h['id'], 't' => $type, 'e' => 'The name ends in ' . $word . ' (the worker\'s rule; a person decides).']);
        if ($ins->rowCount() === 1) {
            $n++;
            log_activity($pdo, 'holder.kind_propose', 'holder', (int) $h['id'], ['source' => 'cron', 'actor_member_id' => null, 'issuer_id' => (int) $h['issuer_id'],
                'after' => ['holder_id' => (int) $h['id'], 'kind' => 'entity', 'entity_type' => $type, 'by' => 'the worker']]);
        }
    }
    return ['proposed' => $n];
}

// ---- the outbox: email through MaluMail, texts through the kernel -------------------------------------------------------------------------

/** A row of the outbox as the message it becomes: [subject, html, text]. The words are the notification's; the link points at the record inside the application. */
function outbox_message(array $o): array
{
    $url = notification_record_url($o + ['signal_id' => ($o['record_type'] ?? null) === 'signal' ? $o['record_id'] : null]);
    $link = $url === null ? app_url('/notifications') : app_url($url);
    $data = ['title' => (string) $o['subject'], 'body' => (string) $o['body'], 'url' => $link, 'kind' => (string) $o['kind']];
    $html = view($o['kind'] === 'morning_note' ? 'emails/morning-note.php' : 'emails/notification.php', $data);
    return [(string) $o['subject'], $html, trim($o['body'] . "\n\n" . $link . "\n\n" . app_name() . ' — for the people it was sent to; not for the market.')];
}

/**
 * The worker's outbox pass: each queued row whose time has come — an email to the member through MaluMail (a refused or suppressed address is skipped, never retried; a transport failure or a 5xx is),
 * a text to the member through the kernel (K6: the kernel decides the phone, the grant and the opt-out; its refusal is skipped). A failed attempt waits 1, 5 and then 30 minutes; the fourth is final.
 * Logs `outbox.sent` and `outbox.failed` {outbox_id, channel, kind, attempts} — never an address, a phone or the words. Answers the counts.
 */
function outbox_run(PDO $pdo, int $limit): array
{
    $out = ['sent' => 0, 'skipped' => 0, 'retried' => 0, 'failed' => 0];
    $rows = $pdo->query("SELECT o.*, m.status AS member_status FROM notification_outbox o JOIN members m ON m.id = o.member_id WHERE o.status = 'queued' AND o.send_after <= now() ORDER BY o.id LIMIT " . max(1, min(200, $limit)))->fetchAll();
    $upd = $pdo->prepare('UPDATE notification_outbox SET status = :s, attempts = :a, detail = :d, provider_ref = COALESCE(:p, provider_ref), sent_at = CASE WHEN :s2 = \'sent\' THEN now() ELSE sent_at END,
                                 send_after = CASE WHEN CAST(:m AS integer) IS NULL THEN send_after ELSE now() + make_interval(mins => CAST(:m2 AS integer)) END WHERE id = :id');
    foreach ($rows as $o) {
        $attempt = (int) $o['attempts'] + 1;
        [$outcome, $detail, $ref] = outbox_send_one($o);
        $status = match ($outcome) {
            'sent' => 'sent',
            'skipped' => 'skipped',
            default => $attempt > count(OUTBOX_RETRY_MINUTES) ? 'failed' : 'queued',
        };
        $wait = $status === 'queued' ? OUTBOX_RETRY_MINUTES[$attempt - 1] : null;
        $upd->execute(['s' => $status, 'a' => $attempt, 'd' => $detail === null ? null : mb_substr($detail, 0, 300), 'p' => $ref, 's2' => $status, 'm' => $wait, 'm2' => $wait, 'id' => (int) $o['id']]);
        $ids = ['sent' => 'sent', 'skipped' => 'skipped', 'queued' => 'retried', 'failed' => 'failed'][$status];
        $out[$ids]++;
        if ($status === 'sent' || $status === 'failed') {
            log_activity($pdo, $status === 'sent' ? 'outbox.sent' : 'outbox.failed', 'notification_outbox', (int) $o['id'], ['after' => ['outbox_id' => (int) $o['id'], 'channel' => $o['channel'], 'kind' => $o['kind'], 'attempts' => $attempt]
                + ($status === 'failed' ? ['detail' => mb_substr((string) $detail, 0, 120)] : [])]);
        }
    }
    return $out;
}

/** One send: ['sent' | 'skipped' | 'retry', detail, provider ref]. */
function outbox_send_one(array $o): array
{
    if ($o['member_status'] !== 'active') {
        return ['skipped', 'The member is no longer active here.', null];
    }
    if ($o['channel'] === 'text') {
        [$outcome, $code, $ref] = kernel_send_text((int) $o['member_id'], trim((string) $o['subject'] . (($o['body'] ?? '') !== '' && $o['body'] !== $o['subject'] ? ': ' . $o['body'] : '')), 'si-outbox-' . $o['id']);
        return [$outcome === 'unconfigured' ? 'skipped' : $outcome, $code, $ref];
    }
    if ($o['to_email'] === null || $o['to_email'] === '') {
        return ['skipped', 'The member has no address on record.', null];
    }
    [$subject, $html, $text] = outbox_message($o);
    try {
        $res = malumail_send(['from' => (string) env('MAIL_FROM', 'shareholders@localhost'), 'from_name' => (string) env('MAIL_FROM_NAME', app_name()), 'to' => (string) $o['to_email'],
                              'subject' => $subject, 'html' => $html, 'text' => $text]);
    } catch (RuntimeException $e) {
        return str_contains($e->getMessage(), 'not configured') ? ['skipped', 'Mail is not configured here (the MaluMail key).', null] : ['retry', 'MaluMail did not answer.', null];
    }
    $accepted = array_map('strtolower', array_map('strval', (array) ($res['body']['accepted'] ?? [])));
    if ($res['status'] === 200 && in_array(strtolower((string) $o['to_email']), $accepted, true)) {
        return ['sent', null, $res['message_id']];
    }
    if ($res['status'] === 200 || $res['status'] === 400 || $res['status'] === 422) {
        $why = (string) ($res['body']['rejected'][0]['reason'] ?? $res['body']['error'] ?? 'not accepted');
        return ['skipped', 'MaluMail did not take the address: ' . $why, null];
    }
    return ['retry', 'MaluMail answered HTTP ' . $res['status'] . '.', null];
}
