<?php
declare(strict_types=1);

/**
 * The home (screen `home`, sso-shell.md "Shareholder Intelligence is not Sales CRM"): for the current issuer, THE OWNERSHIP SUMMARY as tiles
 * (si_ownership_summary — db/018: outstanding, float, short as a share of float, days to cover, the DTC total and its sides, the
 * reconciliation, the 13F total, the 5% filers), the open signals by severity, the price with the events overlay (90 days), the next 30 days'
 * events, the latest upload of each kind with its tie, and the morning note's last text. A new installation (no issuer visible) is the
 * first-run page: a manager's one button, anyone else's sentence.
 */

/** Everything the home renders, in one array. $issuer = the current issuer row (current_issuer()) or null. */
function home_summary(PDO $pdo, int $memberId, ?array $issuer): array
{
    $mayAdd = has_right('issuers.manage');
    $out = ['first_run' => false, 'none_visible' => false, 'choose' => false, 'issuer' => $issuer, 'security' => null, 'summary' => null, 'signals' => [],
            'counts' => [], 'overlay' => ['prices' => [], 'events' => []], 'events' => [], 'uploads' => [], 'note' => null, 'may' => ['add_issuer' => $mayAdd, 'upload' => has_right('uploads.write')]];
    if ($issuer === null) {
        $visible = my_issuers($pdo);
        if ($visible === []) {
            // nothing visible: a manager on a new installation sees the one button; anyone else is told the issuers are not shared with them yet
            $out['first_run'] = true;
            $out['none_visible'] = (int) one_value($pdo, 'SELECT count(*) FROM issuers WHERE deleted_at IS NULL') === 0 ? false : true;
        } else {
            $out['choose'] = true;                                          // several visible and none chosen: the cards to choose from
        }
        return $out;
    }
    $sec = current_security($pdo);
    $out['security'] = $sec;
    if ($sec !== null) {
        $json = one_value($pdo, 'SELECT si_ownership_summary(:s, CURRENT_DATE)', ['s' => (int) $sec['security_id']]);
        $out['summary'] = is_string($json) ? json_decode($json, true) : null;
        $st = $pdo->prepare("SELECT DISTINCT ON (kind) kind, value, as_of, source FROM mcp_share_counts WHERE security_id = :s AND kind IN ('outstanding', 'float') AND as_of <= CURRENT_DATE ORDER BY kind, as_of DESC, recorded_at DESC");
        $st->execute(['s' => (int) $sec['security_id']]);
        $out['counts'] = array_column($st->fetchAll(), null, 'kind');
        $st = $pdo->prepare('SELECT trade_date, close, volume FROM mcp_prices WHERE security_id = :s AND trade_date >= CURRENT_DATE - 90 ORDER BY trade_date');
        $st->execute(['s' => (int) $sec['security_id']]);
        $out['overlay']['prices'] = $st->fetchAll();
    }
    $iid = (int) $issuer['issuer_id'];
    $st = $pdo->prepare("SELECT signal_id, rule_key, rule_name, evidence_class, severity, raised_on, summary, proposal_reason, repeat_count FROM mcp_signals
                          WHERE issuer_id = :i AND status = 'open' ORDER BY CASE severity WHEN 'high' THEN 1 WHEN 'watch' THEN 2 ELSE 3 END, raised_on DESC LIMIT 12");
    $st->execute(['i' => $iid]);
    $out['signals'] = $st->fetchAll();
    $st = $pdo->prepare('SELECT event_id, on_date, kind, title, severity FROM mcp_events WHERE issuer_id = :i AND on_date BETWEEN CURRENT_DATE - 90 AND CURRENT_DATE ORDER BY on_date');
    $st->execute(['i' => $iid]);
    $out['overlay']['events'] = $st->fetchAll();
    $st = $pdo->prepare('SELECT event_id, on_date, kind, title, severity FROM mcp_events WHERE issuer_id = :i AND on_date BETWEEN CURRENT_DATE AND CURRENT_DATE + 30 ORDER BY on_date, event_id LIMIT 20');
    $st->execute(['i' => $iid]);
    $out['events'] = $st->fetchAll();
    $st = $pdo->prepare('SELECT DISTINCT ON (kind) import_id, kind, file_name, record_date, period_to, status, tied, tie_note, override_at, created_at FROM mcp_imports
                          WHERE issuer_id = :i AND NOT deleted ORDER BY kind, created_at DESC');
    $st->execute(['i' => $iid]);
    $out['uploads'] = $st->fetchAll();
    $st = $pdo->prepare("SELECT title, body, created_at FROM mcp_notifications WHERE kind = 'morning_note' AND (issuer_id = :i OR issuer_id IS NULL) ORDER BY created_at DESC LIMIT 1");
    $st->execute(['i' => $iid]);
    $note = $st->fetch();
    $out['note'] = $note === false ? null : $note;
    return $out;
}
