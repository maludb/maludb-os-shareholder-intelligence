<?php
declare(strict_types=1);
/** /institutions/{id}?security= — one institution (screen `institution-view`): its holdings in the security by quarter as bars, its 13D/13G history, its filings about us, aliases, notes, tags. */
require_once dirname(__DIR__, 2) . '/app/features/ownership/screen.php';
require_right('institutions.read');
$pdo = db();
$i = find_institution($pdo, (int) (request_integer('id') ?? 0), null) ?? refuse(404, 'Not found.');
[, $security] = positions_context($pdo);
$h = $security === null ? ['holdings' => [], 'beneficial' => []] : institution_holdings($pdo, (int) $i['institution_id'], (int) $security['security_id']);
$about = $security === null ? [] : $pdo->query("SELECT * FROM mcp_filings WHERE issuer_id = " . (int) $security['issuer_id'] . " AND about_us AND filer_cik = " . $pdo->quote((string) $i['cik']) . " ORDER BY filed_on DESC LIMIT 20")->fetchAll();
$notes = find_notes($pdo, 'institution', (int) $i['institution_id']);
log_screen_view($pdo, 'institution-view');
if (wants_json()) {
    respond_screen(['institution' => present_institution($i), 'security_id' => $security === null ? null : (int) $security['security_id'],
        'holdings' => array_map(static fn ($r) => ['period_end' => $r['period_end'], 'put_call' => $r['put_call'], 'shares' => (float) $r['shares'], 'value_dollars' => $r['value'] === null ? null : (float) $r['value'], 'accession_number' => $r['accession']], $h['holdings']),
        'beneficial' => array_map(static fn ($b) => ['schedule' => $b['schedule'], 'filed_on' => $b['filed_on'], 'percent_of_class' => $b['percent_of_class'] === null ? null : (float) $b['percent_of_class'], 'shares' => $b['shares_beneficially_owned'] === null ? null : (float) $b['shares_beneficially_owned']], $h['beneficial']),
        'filings_about_us' => array_map('present_filing', $about), 'notes' => array_map('present_note', $notes)]);
}
render_screen((string) $i['name'], view('institutions/view.php', ['i' => $i, 'security' => $security, 'h' => $h, 'about' => $about, 'notes' => $notes, 'tz' => member_timezone(), 'back' => back_link(),
    'notice' => si_notice($_GET['notice'] ?? null, ['updated' => ['success', 'Saved.'], 'note_added' => ['success', 'Added the note.']])]), ['activeNav' => 'ownership', 'screen' => 'institution-view', 'entity' => 'institution', 'recordId' => (string) $i['institution_id']]);
