<?php
declare(strict_types=1);
/** /filings?form=&from=&to=&about_us=&q=&page= — the current issuer's filings and others' about it (screen `filing-list`): a ledger by date with form chips, items, the text and structured marks. */
require_once dirname(__DIR__, 2) . '/app/features/filings/screen.php';
require_right('filings.read');
$pdo = db();
$issuer = filings_issuer_or_empty($pdo, 'filing-list', 'Filings');
$from = request_date('from');
$to = request_date('to');
$f = ['form' => (isset(FILING_FORM_GROUPS[request_string('form')]) || request_string('form') === 'other') ? request_string('form') : '', 'from' => is_string($from) ? $from : null, 'to' => is_string($to) ? $to : null,
      'about_us' => request_bool('about_us'), 'q' => mb_substr(trim(request_string('q')), 0, 80)];
$page = max(1, request_integer('page') ?? 1);
$rows = find_filings($pdo, (int) $issuer['issuer_id'], $f, $page);
$total = (int) ($rows[0]['total'] ?? 0);
$src = $pdo->query("SELECT source_id, last_ok_at, backoff_until FROM si_source_health() WHERE connector = 'edgar_submissions'")->fetch() ?: null;
log_screen_view($pdo, 'filing-list');
if (wants_json()) {
    respond_screen(['issuer_id' => (int) $issuer['issuer_id'], 'filters' => $f, 'total' => $total, 'page' => $page, 'filings' => array_map('present_filing', $rows)]);
}
render_screen('Filings', view('filings/index.php', ['issuer' => $issuer, 'rows' => $rows, 'f' => $f, 'page' => $page, 'total' => $total, 'src' => $src, 'here' => here_url(),
    'notice' => si_notice($_GET['notice'] ?? null, ['pulled' => ['success', 'Pulled.']])]), ['activeNav' => 'filings', 'screen' => 'filing-list']);
