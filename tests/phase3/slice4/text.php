<?php
/**
 * Proof: a filing's text (edgar-filings.md "Proof"): the 8-K's text read, searched and highlighted — the filing page marks every hit with an id and
 * a "next hit" link; the search screen finds a phrase across the filings with the headline's markers made safe (a filing that holds "<script>"
 * shows it as text); filing_fetch_text on request (a document EDGAR lacks refused in words); the XML reader refuses an entity; a text over the
 * window is paged; filing_text_window's passages around a term; filing_event_make for a filing without an event, refused for one that has it,
 * an agent's landing with source agent.
 */
require __DIR__ . '/lib.php';
[$jP, ] = sign_on(26);
[$jV, ] = sign_on(29);
$sm = smoke_issuer();
foreach ([$jP, $jV] as $j) { page($j, '/?issuer=' . $sm['issuer']); }
edgar_contact(true);
$json = ['headers' => ['Accept: application/json']];
pull('edgar_submissions');
$f8k = filing_id('0000999999-24-000005');

echo "1. The filing page\n";
$h = page($jV, '/filings/' . $f8k . '?q=equity')['body'];
ok(str_contains($h, '<mark id="filing-hit-1">equity</mark>') && str_contains($h, 'href="#filing-hit-2"') && str_contains($h, 'id="filing-first-hit"') && str_contains($h, 'id="filing-text-hits">'), 'q=equity: each hit marked with its id and a "next hit" link');
$d = json_decode(page($jV, '/filings/' . $f8k . '?q=equity', $json)['body'], true)['data'] ?? [];
ok(($d['text']['hits'] ?? 0) === 3 && ($d['event']['severity'] ?? '') === 'watch' && $d['filing']['items'] === ['1.01', '3.02', '9.01'], 'in JSON: three hits (the agreement, the Equity Line, Equity Securities), the event at watch, the items');
ok(!str_contains($h, 'id="filing-fetch-btn"') && !str_contains($h, 'id="filing-event-form"'), 'a Viewer reads it without the buttons');

echo "2. The search\n";
$d = json_decode(page($jV, '/filings/search?q=' . rawurlencode('"equity purchase agreement"'), $json)['body'], true)['data']['hits'] ?? [];
ok(count($d) === 1 && $d[0]['filing_id'] === $f8k && $d[0]['form'] === '8-K', 'a phrase across the filings: the 8-K');
$h = page($jV, '/filings/search?q=' . rawurlencode('"equity purchase agreement"'))['body'];
ok(str_contains($h, 'id="search-hit-' . $f8k . '"') && str_contains($h, '<mark>'), 'the headline marked');
q("INSERT INTO filings (issuer_id, filer_cik, accession_number, form, filed_on, primary_document) VALUES (:i, '0000999999', '0000999999-24-000099', 'PRESS', '2024-06-01', 'x.htm')", ['i' => $sm['issuer']]);
q("INSERT INTO filing_text (filing_id, text, extractor) VALUES (:f, 'A dubious release: <script>alert(1)</script> about the warrant exercise.', 'html')", ['f' => filing_id('0000999999-24-000099')]);
q("UPDATE filings SET fetched_text_at = now(), char_count = 70 WHERE id = :f", ['f' => filing_id('0000999999-24-000099')]);
$h = page($jV, '/filings/search?q=warrant')['body'];
ok(str_contains($h, 'alert(1)') && !str_contains($h, '<script>alert'), 'a filing\'s "<script>" never reaches the page as markup (the headline drops tags; the rest is escaped)');
ok(str_contains(page($jV, '/filings/search?q=zzzzqqq')['body'], 'id="filings-search-none"'), 'no hit: said so');

echo "3. Text on request\n";
$corr = filing_id('0000999999-24-000003');
$r = post_json($jP, '/filings/fetch-text.php', ['filing' => $corr]);
ok($r['code'] === 422 && str_contains($r['body'], 'answered 404'), 'the CORRESP\'s document EDGAR lacks: 422 in words (…answered 404…)');
$r = post_json($jP, '/filings/fetch-text.php', ['filing' => $f8k]);
ok($r['code'] === 200 && ($r['json']['chars'] ?? 0) > 100, 'the 8-K read again on request: ' . ($r['json']['chars'] ?? 0) . ' characters');
ok(post_json($jV, '/filings/fetch-text.php', ['filing' => $f8k])['code'] === 403, 'a Viewer may not: 403');
edgar_contact(false);
ok(post_json($jP, '/filings/fetch-text.php', ['filing' => $f8k])['code'] === 422, 'without the EDGAR contact: 422 (the SEC\'s rule), no request');
edgar_contact(true);

echo "4. The XML reader\n";
$out = app_php("require_once " . var_export(dirname(__DIR__, 3) . '/app/si/fetch/xml.php', true) . "; try { si_xml_text((string) file_get_contents(" . var_export(dirname(__DIR__, 2) . '/fixtures/samples/archives/evil.xml', true) . ")); echo 'read'; } catch (SiXmlError \$e) { echo 'refused: ', \$e->getMessage(); }");
ok(is_string($out) && str_starts_with($out, 'refused') && !str_contains($out, 'root:'), 'an XML document declaring an entity is refused before it is parsed');
q("UPDATE filings SET primary_document = 'evil.xml' WHERE id = :i", ['i' => $corr]);
$r = post_json($jP, '/filings/fetch-text.php', ['filing' => $corr]);
ok($r['code'] === 422 && str_contains($r['body'], 'entity') && one('SELECT count(*) FROM filing_text WHERE filing_id = :i', ['i' => $corr]) === 0, '…and a filing whose document does: refused in words, no text kept');

echo "5. A long text, by window\n";
$big = str_repeat("Paragraph of filler text about the business. ", 3000) . 'THE NEEDLE SENTENCE about a convertible note.';
q("UPDATE filing_text SET text = :t WHERE filing_id = :f", ['t' => $big, 'f' => filing_id('0000999999-24-000099')]);
$bid = filing_id('0000999999-24-000099');
$h = page($jP, '/filings/' . $bid)['body'];
ok(str_contains($h, 'id="filing-text-next"') && str_contains($h, 'of ' . number_format(mb_strlen($big))), 'a text over 100,000 characters: the first window and "More of the text"');
ok(str_contains(page($jP, '/filings/' . $bid . '?at=100000')['body'], 'THE NEEDLE SENTENCE'), 'the second window holds the end');
$w = app_php("db()->exec(\"SELECT set_config('app.member_id', '26', false)\"); require_once " . var_export(dirname(__DIR__, 3) . '/app/features/filings/queries.php', true) . "; echo json_encode(filing_text_window(db(), $bid, 0, 6000, 'convertible note'));");
ok(($w['hits'] ?? 0) === 1 && str_contains($w['passages'][0]['text'] ?? '', 'NEEDLE') && mb_strlen($w['text']) === 6000, 'filing_text_window: 6,000 characters, and the passage around the term');

echo "6. The event\n";
q("UPDATE filings SET primary_document = 'filename1.htm' WHERE id = :i", ['i' => $corr]);
$since = last_activity_id();
$r = post_json($jP, '/filings/event-make.php', ['filing' => $corr, 'title' => 'SMOKE: the SEC asked about revenue recognition', 'severity' => 'watch']);
$ev = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && one('SELECT severity FROM events WHERE id = :e', ['e' => $ev]) === 'watch' && one('SELECT source FROM events WHERE id = :e', ['e' => $ev]) === 'person'
    && (int) one("SELECT count(*) FROM activity_log WHERE id > :s AND action = 'filing.event_make'", ['s' => $since]) === 1, 'an event made from the CORRESP, at watch, logged');
$r = post_json($jP, '/filings/event-make.php', ['filing' => $f8k]);
ok($r['code'] === 422 && str_contains($r['body'], 'already has its event'), 'the 8-K has its event: 422 naming it');
kernel_state(function ($s) { $s['facts']['901'] = ['valid' => true, 'member_id' => 42, 'run_id' => 901, 'request_id' => 'req-901', 'is_eval' => false]; return $s; });
$r = req('POST', '/filings/event-make.php', ['headers' => as_agent(run_token(42, 901)), 'form' => ['filing' => filing_id('0000999999-24-000099'), 'title' => 'SMOKE: a press release', 'severity' => 'info']]);
ok($r['code'] === 200 && one("SELECT source FROM events WHERE filing_id = :f", ['f' => filing_id('0000999999-24-000099')]) === 'agent', 'an agent\'s filing_event_make lands, with source agent');
ok(post_json($jP, '/filings/event-make.php', ['filing' => $corr, 'severity' => 'loud'])['code'] === 422, 'a severity that is not one: 422');
finish();
