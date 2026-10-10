<?php
declare(strict_types=1);

/** The reconciliation (slice 2): a security's runs, newest first, read through mcp_share_reconciliations (the wall). */
function reconciliation_page(PDO $pdo, int $security): array
{
    $st = $pdo->prepare('SELECT * FROM mcp_share_reconciliations WHERE security_id = :s ORDER BY as_of DESC, ran_at DESC LIMIT 60');
    $st->execute(['s' => $security]);
    $rows = $st->fetchAll();
    return ['latest' => $rows[0] ?? null, 'history' => $rows];
}

function present_reconciliation(array $r): array
{
    $n = static fn ($v) => $v === null ? null : (float) $v;
    return ['reconciliation_id' => (int) ($r['reconciliation_id'] ?? $r['id']), 'as_of' => $r['as_of'], 'state' => reconciliation_state($r), 'outstanding' => $n($r['outstanding']),
            'cede_registered' => $n($r['cede_registered']), 'cede_source' => $r['cede_source'], 'register_date' => $r['register_date'], 'spr_total' => $n($r['spr_total']), 'spr_date' => $r['spr_date'],
            'drs_book' => $n($r['drs_book']), 'certificated' => $n($r['certificated']), 'plan' => $n($r['plan']), 'registered_other' => $n($r['registered_other']),
            'difference_registered' => $n($r['difference_registered']), 'difference_spr' => $n($r['difference_spr']), 'tolerance' => (int) $r['tolerance'],
            'tied_registered' => $r['tied_registered'], 'tied_spr' => $r['tied_spr'], 'note' => $r['note'], 'ran_at' => json_ts($r['ran_at'])];
}
