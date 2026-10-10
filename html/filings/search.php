<?php
declare(strict_types=1);
/** /filings/search?q=&form=&page= — the words across every filing's text with highlights (screen `filings-search`; si_filings_search — the wall inside). */
require_once dirname(__DIR__, 2) . '/app/features/filings/screen.php';
require_right('filings.read');
$pdo = db();
$issuer = filings_issuer_or_empty($pdo, 'filings-search', 'Search the filings');
$q = mb_substr(trim(request_string('q')), 0, 200);
$form = isset(FILING_FORM_GROUPS[request_string('form')]) ? request_string('form') : null;
$page = max(1, request_integer('page') ?? 1);
$rows = filings_search($pdo, (int) $issuer['issuer_id'], $q, $form, $page);
$texts = (int) one_value($pdo, 'SELECT count(*) FROM mcp_filings WHERE issuer_id = :i AND has_text', ['i' => (int) $issuer['issuer_id']]);
log_screen_view($pdo, 'filings-search');
if (wants_json()) {
    respond_screen(['issuer_id' => (int) $issuer['issuer_id'], 'q' => $q, 'form' => $form, 'filings_with_text' => $texts,
        'hits' => array_map(static fn (array $r): array => ['filing_id' => (int) $r['filing_id'], 'form' => $r['form'], 'filed_on' => $r['filed_on'], 'headline' => $r['headline'], 'rank' => (float) $r['rank']], $rows)]);
}
render_screen('Search the filings', view('filings/search.php', ['q' => $q, 'form' => $form, 'rows' => $rows, 'texts' => $texts, 'page' => $page, 'here' => here_url()]), ['activeNav' => 'filings', 'screen' => 'filings-search']);
