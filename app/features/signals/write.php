<?php
declare(strict_types=1);

/**
 * The signals' writes (signals-rules.md "Query functions", signatures fixed). The database is the referee: si_rules_run() raises, refreshes
 * and expires (one open signal per rule per security), si_signal_dispose() is a person's disposition (explained_by_event names its event),
 * si_signal_propose() an agent's proposal on an open signal. PHP logs what changed — ids, rule keys, severities, figures and reasons, never a
 * note's words — and tells the people who asked: the escalation member (the signals_after trigger, for a high one), members who asked for
 * signals of a severity (kind `signal`), the IR Managers on an escalation. si_notify() never tells anyone about an issuer they may not see.
 */
require_once dirname(__DIR__) . '/notes/write.php';
require_once dirname(__DIR__, 2) . '/si/fetch/calendar.php';

const SIGNAL_SEVERITY_RANK = ['info' => 1, 'watch' => 2, 'high' => 3];
/** A disposition's reasons by the status it gives (si_signal_dispose admits these; the manifest's params). */
const SIGNAL_ACK_REASONS = ['known' => 'Known — nothing new', 'watching' => 'Watching it'];
const SIGNAL_DISMISS_REASONS = ['explained_by_event' => 'Explained by an event', 'data_defect' => 'A data defect', 'false_positive' => 'A false positive', 'other' => 'Another reason'];
/** An agent's proposal's reasons, and the action a person's "Take it" posts for each. */
const SIGNAL_PROPOSAL_REASONS = ['known' => 'acknowledge', 'watching' => 'acknowledge', 'explained_by_event' => 'dismiss', 'data_defect' => 'dismiss', 'false_positive' => 'dismiss', 'escalate' => 'escalate'];

/** Today in market time (the rules' as-of when none is given). */
function signals_today(PDO $pdo): string
{
    return (new DateTimeImmutable('now', si_market_tz($pdo)))->format('Y-m-d');
}

/**
 * Run every enabled rule for one security as of a day (today in market time when null): si_rules_run(), then what changed logged a signal at
 * a time (`signal.raise|refresh|expire`), the run remembered in rule_runs, and the members who asked told of each new signal.
 * Answers ['raised', 'refreshed', 'expired', 'raised_ids' => [...]].
 */
function rules_run(PDO $pdo, int $security, ?string $asOf): array
{
    $asOf ??= signals_today($pdo);
    $issuer = (int) one_value($pdo, 'SELECT issuer_id FROM securities WHERE id = :s', ['s' => $security]);
    $snap = static function () use ($pdo, $security): array {
        $st = $pdo->prepare('SELECT s.id, s.status, s.repeat_count, s.severity, s.value, r.key FROM signals s JOIN signal_rules r ON r.id = s.rule_id WHERE s.security_id = :s');
        $st->execute(['s' => $security]);
        return array_column($st->fetchAll(), null, 'id');
    };
    $before = $snap();
    $st = $pdo->prepare('SELECT * FROM si_rules_run(:s, CAST(:d AS date))');
    $st->execute(['s' => $security, 'd' => $asOf]);
    $counts = $st->fetch() ?: ['raised' => 0, 'refreshed' => 0, 'expired' => 0];
    $after = $snap();
    $raised = [];
    foreach ($after as $id => $s) {
        $was = $before[$id] ?? null;
        $event = match (true) {
            $was === null => 'signal.raise',
            $was['status'] === 'open' && $s['status'] === 'expired' => 'signal.expire',
            $was['status'] === 'open' && $s['status'] === 'open' && (int) $s['repeat_count'] > (int) $was['repeat_count'] => 'signal.refresh',
            default => null,
        };
        if ($event === null) {
            continue;
        }
        log_activity($pdo, $event, 'signal', (int) $id, ['issuer_id' => $issuer, 'security_id' => $security, 'signal_id' => (int) $id,
            'after' => ['rule' => $s['key'], 'severity' => $s['severity'], 'value' => $s['value'] === null ? null : (float) $s['value'], 'security_id' => $security]]);
        if ($event === 'signal.raise') {
            $raised[] = (int) $id;
        }
    }
    $pdo->prepare('INSERT INTO rule_runs (security_id, ran_at, as_of, raised, refreshed, expired) VALUES (:s, now(), CAST(:d AS date), :r, :f, :e)
                   ON CONFLICT (security_id) DO UPDATE SET ran_at = now(), as_of = EXCLUDED.as_of, raised = EXCLUDED.raised, refreshed = EXCLUDED.refreshed, expired = EXCLUDED.expired')
        ->execute(['s' => $security, 'd' => $asOf, 'r' => (int) $counts['raised'], 'f' => (int) $counts['refreshed'], 'e' => (int) $counts['expired']]);
    foreach ($raised as $id) {
        signal_tell_subscribers($pdo, $id);
    }
    return ['raised' => (int) $counts['raised'], 'refreshed' => (int) $counts['refreshed'], 'expired' => (int) $counts['expired'], 'raised_ids' => $raised];
}

/** A new signal to every person who asked for signals at its severity or above (notification_prefs: kind `signal`, the least severity, the issuers). */
function signal_tell_subscribers(PDO $pdo, int $id): int
{
    $st = $pdo->prepare("SELECT s.id, s.issuer_id, s.severity, s.summary, r.name AS rule_name, sec.name AS security_name FROM signals s JOIN signal_rules r ON r.id = s.rule_id JOIN securities sec ON sec.id = s.security_id WHERE s.id = :id");
    $st->execute(['id' => $id]);
    $s = $st->fetch();
    if ($s === false) {
        return 0;
    }
    $rank = SIGNAL_SEVERITY_RANK[$s['severity']] ?? 1;
    $who = $pdo->prepare("SELECT p.member_id FROM notification_prefs p JOIN members m ON m.id = p.member_id
                           WHERE m.status = 'active' AND m.member_kind = 'human' AND 'signal' = ANY (p.kinds)
                             AND CASE p.signals_min_severity WHEN 'high' THEN 3 WHEN 'watch' THEN 2 ELSE 1 END <= :rank
                             AND (cardinality(p.issuer_ids) = 0 OR :iss = ANY (p.issuer_ids))");
    $who->execute(['rank' => $rank, 'iss' => (int) $s['issuer_id']]);
    $n = 0;
    $tell = $pdo->prepare("SELECT si_notify(:m, 'signal', :t, :b, 'signal', :id, :id2, :iss, NULL, :d)");
    foreach ($who->fetchAll(PDO::FETCH_COLUMN) as $m) {
        $tell->execute(['m' => (int) $m, 't' => ucfirst((string) $s['severity']) . ' signal: ' . $s['rule_name'] . ' — ' . $s['security_name'], 'b' => $s['summary'],
                        'id' => $id, 'id2' => $id, 'iss' => (int) $s['issuer_id'], 'd' => 'signal:' . $id . ':' . $m]);
        $n += $tell->fetchColumn() === null ? 0 : 1;
    }
    return $n;
}

/** The signal row (base table) with its rule's key and the security's issuer, for the writes' logs; DomainException('Not found.') when absent. */
function signal_row(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT s.*, r.key AS rule_key, r.name AS rule_name FROM signals s JOIN signal_rules r ON r.id = s.rule_id WHERE s.id = :id');
    $st->execute(['id' => $id]);
    return $st->fetch() ?: throw new DomainException('Not found.');
}

/**
 * A person's disposition: acknowledged (known | watching), dismissed (explained_by_event + the event | data_defect | false_positive | other) or
 * escalated (reason `escalated`). The database refuses a signal that is not open and an explained_by_event without its event. Logs
 * `signal.acknowledge|dismiss|escalate` with the reason, the event and whether it took the agent's proposal.
 */
function signal_dispose(PDO $pdo, int $id, string $status, string $reason, ?string $note, ?int $event): void
{
    $was = signal_row($pdo, $id);
    $pdo->prepare('SELECT si_signal_dispose(:id, :st, :r, :n, :e)')->execute(['id' => $id, 'st' => $status, 'r' => $reason, 'n' => $note, 'e' => $event]);
    $now = signal_row($pdo, $id);
    $verb = ['acknowledged' => 'signal.acknowledge', 'dismissed' => 'signal.dismiss', 'escalated' => 'signal.escalate'][$status];
    log_activity($pdo, $verb, 'signal', $id, ['issuer_id' => (int) $was['issuer_id'], 'security_id' => (int) $was['security_id'], 'signal_id' => $id,
        'before' => ['status' => $was['status']],
        'after' => ['status' => $status, 'reason' => $reason, 'event_id' => $event, 'rule' => $was['rule_key'], 'proposal' => proposal_fate($now + ['proposal_event_id' => $was['proposal_event_id']])]]);
    if ($status === 'escalated') {
        signal_tell_escalation($pdo, $now, $note);
    }
}

/** An escalation: the IR Managers and the escalation member told (the one escalating is not told of their own act). */
function signal_tell_escalation(PDO $pdo, array $s, ?string $note): int
{
    $by = current_member_id();
    $sec = (string) one_value($pdo, 'SELECT name FROM securities WHERE id = :s', ['s' => (int) $s['security_id']]);
    $who = $pdo->query("SELECT m.id FROM members m WHERE m.status = 'active' AND m.member_kind = 'human' AND 'manager' = ANY (si_member_roles(m.id))
                        UNION SELECT escalate_high_to FROM si_settings WHERE id = 1 AND escalate_high_to IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    $byName = $by === null ? 'Someone' : (string) (one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => $by]) ?: 'Someone');
    $tell = $pdo->prepare("SELECT si_notify(:m, 'signal_high', :t, :b, 'signal', :id, :id2, :iss, :by, :d)");
    $n = 0;
    foreach (array_unique(array_map('intval', $who)) as $m) {
        if ($m === $by) {
            continue;
        }
        $tell->execute(['m' => $m, 't' => 'Escalated by ' . $byName . ': ' . $s['rule_name'] . ' — ' . $sec, 'b' => $note !== null && $note !== '' ? $note : $s['summary'],
                        'id' => (int) $s['id'], 'id2' => (int) $s['id'], 'iss' => (int) $s['issuer_id'], 'by' => $by, 'd' => 'escalate:' . $s['id'] . ':' . $m . ':' . time()]);
        $n += $tell->fetchColumn() === null ? 0 : 1;
    }
    return $n;
}

/**
 * A proposal on an open signal (an agent's door; a person may propose too): the reason (a disposition's, or `escalate`), the explaining event,
 * one sentence. Stored on the signal by si_signal_propose(); a person takes it in one click. Logs `signal.propose`.
 */
function signal_propose(PDO $pdo, int $id, string $reason, ?int $event, ?string $note): void
{
    $was = signal_row($pdo, $id);
    $pdo->prepare('SELECT si_signal_propose(:id, :r, :n, :e)')->execute(['id' => $id, 'r' => $reason, 'n' => $note, 'e' => $event]);
    log_activity($pdo, 'signal.propose', 'signal', $id, ['issuer_id' => (int) $was['issuer_id'], 'security_id' => (int) $was['security_id'], 'signal_id' => $id,
        'after' => ['reason' => $reason, 'event_id' => $event, 'rule' => $was['rule_key']]]);
}

/** A note on a signal (notes, record_type `signal`); logs `signal.note` with its length, never its words. Answers the note's id. */
function signal_note(PDO $pdo, int $id, string $body, int $by): int
{
    $s = signal_row($pdo, $id);
    $nid = note_add($pdo, 'signal', $id, $body, $by);
    log_activity($pdo, 'signal.note', 'signal', $id, ['issuer_id' => (int) $s['issuer_id'], 'security_id' => (int) $s['security_id'], 'signal_id' => $id,
        'after' => ['note_id' => $nid, 'length' => mb_strlen($body)]]);
    return $nid;
}

/**
 * Delete a signal raised on data since corrected (a manager's; an agent's pauses — deletion): the signal, its outcome, notes and tags go; the
 * trail keeps what it was and the reason (`signal.delete`).
 */
function signal_delete(PDO $pdo, int $id, string $reason): void
{
    $s = signal_row($pdo, $id);
    $pdo->prepare("DELETE FROM notes WHERE record_type = 'signal' AND record_id = :id")->execute(['id' => $id]);
    $pdo->prepare("DELETE FROM record_tags WHERE record_type = 'signal' AND record_id = :id")->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM signals WHERE id = :id')->execute(['id' => $id]);
    log_activity($pdo, 'signal.delete', 'signal', $id, ['issuer_id' => (int) $s['issuer_id'], 'security_id' => (int) $s['security_id'], 'signal_id' => $id,
        'before' => ['rule' => $s['rule_key'], 'severity' => $s['severity'], 'status' => $s['status'], 'raised_on' => $s['raised_on'], 'value' => $s['value'] === null ? null : (float) $s['value']],
        'after' => ['reason' => $reason]]);
}
