<?php
/**
 * Proof: Schedules 13D and 13G (ownership-forms.md "Proof"): read from the issuer's index — the 2024 XML schedule (the filer, its group, the
 * powers, the percent, the event, item 4's purpose; an active filer), an older HTML 13G best-effort (the cover's name, amount, percent; its
 * filer named by the accession's prefix), a schedule declaring an entity refused in words and nothing read from it; each read once; the 5%
 * screen and its cards; an institution's 13D history.
 */
require __DIR__ . '/lib.php';
[$jP, ] = sign_on(26);
[$jV, ] = sign_on(29);
$sm = seed_ownership();
foreach ([$jP, $jV] as $j) { page($j, '/?issuer=' . $sm['issuer']); }
$json = ['headers' => ['Accept: application/json']];

echo "1. The pull\n";
$since = last_activity_id();
stub_reset();
pull('edgar_13dg');
$p = last_pull('edgar_13dg');
ok($p['status'] === 'ok' && (int) $p['listings_seen'] === 4 && (int) $p['listings_new'] === 3, 'four schedules read, three kept — ' . $p['status'] . ' ' . $p['listings_seen'] . '/' . $p['listings_new'] . ($p['error'] ? ' — ' . $p['error'] : ''));
$paths = array_column(stub_requests(), 'path');
ok(in_array('/Archives/edgar/data/999999/000144444424000003/primary_doc.xml', $paths, true), 'the raw XML read, not the rendered page (the index\'s xsl folder dropped)');
$d = q("SELECT b.*, i.name, i.cik FROM beneficial_ownership b JOIN institutions i ON i.id = b.institution_id WHERE b.schedule = '13D'")[0] ?? [];
ok(($d['name'] ?? '') === 'SMOKE DELTA PARTNERS LP' && $d['cik'] === '0001444444' && (float) $d['percent_of_class'] === 6.2 && (float) $d['shares_beneficially_owned'] === 1057000.0, 'the 13D: Delta Partners (its CIK), 6.2%, 1,057,000 shares');
ok($d['event_date'] === '2024-03-26' && (float) $d['sole_voting'] === 1057000.0 && (float) $d['shared_dispositive'] === 0.0 && $d['filer_type'] === 'active' && $d['group_members'] === '{"SMOKE DELTA GP LLC"}',
    'the event date, the powers, an active filer, its group named');
ok(str_contains((string) $d['purpose_text'], 'engage the Issuer') && $d['raw_xml_sha'] !== null && $d['filing_id'] === filing_id('0001444444-24-000003'), 'item 4\'s purpose, the document\'s hash, the filing');
$g = q("SELECT b.*, i.name, i.cik FROM beneficial_ownership b JOIN institutions i ON i.id = b.institution_id WHERE b.schedule = '13G'")[0] ?? [];
ok(($g['name'] ?? '') === 'SMOKE EPSILON INDEX FUNDS TRUST' && $g['cik'] === '0001888888' && (float) $g['percent_of_class'] === 5.8 && (float) $g['shares_beneficially_owned'] === 950000.0 && $g['is_amendment'] === true && $g['filer_type'] === null,
    'the older HTML 13G/A read from its cover: Epsilon (named by the accession\'s filer), 5.8%, 950,000, an amendment, its type unknown');
$x = q('SELECT structured_at, error FROM filings WHERE accession_number = :a', ['a' => '0001444444-24-000099'])[0];
ok($x['structured_at'] !== null && str_contains((string) $x['error'], 'declares an entity') && (int) one('SELECT count(*) FROM beneficial_ownership WHERE filing_id = :f', ['f' => filing_id('0001444444-24-000099')]) === 0,
    'the schedule declaring an entity refused in words on the filing; nothing kept of it');
$z = q("SELECT b.*, i.name, i.cik FROM beneficial_ownership b JOIN institutions i ON i.id = b.institution_id WHERE i.cik = '0001777777'")[0] ?? [];
ok(($z['name'] ?? '') === 'SMOKE ZETA GROWTH FUND LP' && (float) $z['percent_of_class'] === 5.1 && (float) $z['shares_beneficially_owned'] === 870000.0 && (float) $z['shared_voting'] === 870000.0 && $z['filer_type'] === 'passive'
    && $z['group_members'] === '{"SMOKE ZETA GP LLC"}' && $z['purpose_text'] === null && $z['event_date'] === '2024-04-30',
    'the 2024 13G layout: the filer\'s CIK from the header, 5.1%, the shared powers, a passive filer (13d-1(c)), its group; no purpose (a 13G\'s item 4 is the amount)');
ok((int) one("SELECT count(*) FROM activity_log WHERE id > :s AND action = 'ownership.load'", ['s' => $since]) === 3 && (int) one("SELECT count(*) FROM activity_log WHERE id > :s AND action = 'institution.create'", ['s' => $since]) === 3,
    'ownership.load logged per schedule kept; institution.create per filer made');

echo "2. Read once\n";
stub_reset();
pull('edgar_13dg');
ok((int) last_pull('edgar_13dg')['listings_seen'] === 0 && array_filter(array_column(stub_requests(), 'path'), static fn ($x) => str_contains($x, 'primary_doc')) === [], 'a second pull reads no schedule again');

echo "3. The screens\n";
$d = json_decode(page($jV, '/ownership/beneficial', $json)['body'], true)['data']['owners'] ?? [];
ok(count($d) === 3 && $d[0]['name'] === 'SMOKE DELTA PARTNERS LP' && $d[0]['schedule'] === '13D' && $d[1]['percent_of_class'] === 5.8 && $d[2]['percent_of_class'] === 5.1, 'a Viewer reads the 5% holders, the largest first: Delta\'s 13D, Epsilon\'s and Zeta\'s 13Gs');
$h = page($jV, '/ownership/beneficial')['body'];
$delta = (int) one("SELECT id FROM institutions WHERE cik = '0001444444'");
ok(str_contains($h, 'id="beneficial-card-' . $delta . '"') && str_contains($h, 'role="progressbar"') && str_contains($h, 'id="beneficial-purpose-' . $delta . '"'), 'the cards: the percent as a bar, the purpose');
$v = page($jV, '/institutions/' . $delta . '?security=' . $sm['security'])['body'];
ok(str_contains($v, 'id="institution-beneficial"') && str_contains($v, '6.2%'), 'the institution\'s page carries its 13D history');
$other = q("INSERT INTO issuers (name, ticker, cik) VALUES ('SMOKE OTHER CO', 'SMOT', '0000888777') RETURNING id")[0]['id'];
q("INSERT INTO securities (issuer_id, name, cusip, is_primary) VALUES (:i, 'Common', '99999X109', true)", ['i' => $other]);
q('INSERT INTO issuer_members (issuer_id, member_id) VALUES (:i, 30)', ['i' => $other]);                 // restricted to Dana
$r = page($jV, '/ownership/beneficial?security=' . (int) one('SELECT id FROM securities WHERE issuer_id = :i', ['i' => $other]), $json);
ok(in_array($r['code'], [403, 404], true) || (json_decode($r['body'], true)['data']['security_id'] ?? null) !== (int) one('SELECT id FROM securities WHERE issuer_id = :i', ['i' => $other]), 'an issuer behind the wall is not read through the screen');
finish();
