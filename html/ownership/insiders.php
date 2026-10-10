<?php
declare(strict_types=1);
/** /ownership/insiders?from=&to=&owner=&code=&page= — the insider ledger (screen `ownership-insiders`): Forms 3/4/5 by person with the codes, shares, price, owned after; the register link's holder for the PII roles. */
require_once dirname(__DIR__, 2) . '/app/features/ownership/screen.php';
require_right('institutions.read');
$pdo = db();
[$issuer, $security] = ownership_context_or_empty($pdo, 'ownership-insiders', 'Insiders');
$from = request_date('from');
$to = request_date('to');
$f = ['from' => is_string($from) ? $from : null, 'to' => is_string($to) ? $to : null, 'owner' => preg_match('~^\d{10}$~', request_string('owner')) ? request_string('owner') : '', 'code' => preg_match('~^[A-Z]$~', request_string('code')) ? request_string('code') : ''];
$page = max(1, request_integer('page') ?? 1);
$rows = insiders($pdo, (int) $security['issuer_id'], $f, $page);
$summary = $pdo->prepare('SELECT * FROM si_insider_summary(:i, :f, :t)');
$summary->execute(['i' => (int) $security['issuer_id'], 'f' => $f['from'] ?? '1900-01-01', 't' => $f['to'] ?? '2999-12-31']);
$summary = $summary->fetchAll();                                 // the security came through the wall (positions_context)
log_screen_view($pdo, 'ownership-insiders');
if (wants_json()) {
    $num = static fn ($v) => $v === null ? null : (float) $v;
    respond_screen(['issuer_id' => (int) $security['issuer_id'], 'filters' => $f, 'total' => (int) ($rows[0]['total'] ?? 0),
        'rows' => array_map(static fn ($r) => ['transaction_id' => (int) $r['transaction_id'], 'filing_id' => $r['filing_id'] === null ? null : (int) $r['filing_id'], 'form' => $r['form'], 'reporting_owner_cik' => $r['reporting_owner_cik'],
            'reporting_owner_name' => $r['reporting_owner_name'], 'relationship' => pg_text_array((string) $r['relationship']), 'officer_title' => $r['officer_title'], 'transaction_date' => $r['transaction_date'], 'transaction_code' => $r['transaction_code'],
            'shares' => $num($r['shares']), 'price' => $num($r['price']), 'acquired_disposed' => $r['acquired_disposed'], 'shares_owned_after' => $num($r['shares_owned_after']), 'ownership' => $r['ownership'],
            'is_derivative' => (bool) $r['is_derivative'], 'is_10b5_1' => $r['is_10b5_1'], 'holder_id' => $r['holder_name'] === null ? null : (int) $r['holder_id'], 'holder_name' => $r['holder_name']], $rows),
        'summary' => array_map(static fn ($s) => ['reporting_owner_cik' => $s['reporting_owner_cik'], 'reporting_owner_name' => $s['reporting_owner_name'], 'sales' => (int) $s['sales'], 'sale_shares' => (float) $s['sale_shares'],
            'buys' => (int) $s['buys'], 'buy_shares' => (float) $s['buy_shares'], 'last_owned_after' => $num($s['last_owned_after'])], $summary)]);
}
render_screen('Insiders', view('ownership/insiders.php', ['security' => $security, 'rows' => $rows, 'summary' => $summary, 'f' => $f, 'page' => $page, 'here' => here_url(),
    'notice' => si_notice($_GET['notice'] ?? null, ['linked' => ['success', 'Linked to the register\'s holder.']])]), ['activeNav' => 'ownership', 'screen' => 'ownership-insiders']);
