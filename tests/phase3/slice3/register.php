<?php
/**
 * Proof: the register through the pipeline (holder-identity.md §1, holders.md "Proof"): STC's position detail (the anonymised reference) loads by
 * section with Cede flagged and its record date its own, the matcher makes its holders; with the SPR of the same date (crafted, Cede's 1,581,000)
 * and the outstanding count the register implies, THE RECONCILIATION'S REGISTER HALF LIGHTS AND TIES; a transfer agent's extract (crafted, persons
 * named SMOKE) is stored WITHOUT its tax ids, dates of birth, phones and emails, one register row per type column, BOOKR restricted, the record date
 * from the file name; a holder line whose TOTAL SHARES is not the sum of its types is named and unties; an extract with no date anywhere is refused;
 * the register screen shows a Viewer the components and no row.
 */
require __DIR__ . '/lib.php';
[$jP, ] = sign_on(26);
[$jM, ] = sign_on(27);
[$jV, ] = sign_on(29);
$fx = fixture_issuer();
$sec = $fx['security'];
foreach ([$jP, $jM, $jV] as $j) { page($j, '/?issuer=' . $fx['issuer']); }
$json = ['headers' => ['Accept: application/json']];

echo "1. STC's position detail\n";
$r = upload_fixture($jP, 'register_stc.xlsx', ['security' => $sec]);
$u = (int) ($r['json']['record_id'] ?? 0);
$im = q('SELECT * FROM imports WHERE id = :i', ['i' => $u])[0] ?? [];
ok($r['code'] === 200 && ($r['json']['tied'] ?? null) === true && ($im['connector'] ?? '') === 'stc_position_detail' && $im['record_date'] === '2024-04-04', 'uploaded, kind detected, its record date its own (2024-04-04), tied to its own rows' . ($r['code'] === 200 ? '' : ' — ' . substr($r['body'], 0, 200)));
ok((int) one('SELECT count(*) FROM register_rows WHERE import_id = :i', ['i' => $u]) === 842 && (float) one('SELECT sum(shares) FROM register_rows WHERE import_id = :i', ['i' => $u]) === 17053900.0
    && (int) one('SELECT count(*) FROM register_rows WHERE import_id = :i AND is_cede', ['i' => $u]) === 2 && (float) one('SELECT sum(shares) FROM register_rows WHERE import_id = :i AND is_cede', ['i' => $u]) === 1581000.0,
    'the Phase 0 proof\'s counts: 842 rows, 17,053,900 shares, Cede & Co flagged (two lines, 1,581,000)');
ok((int) one("SELECT count(DISTINCT section) FROM register_rows WHERE import_id = :i", ['i' => $u]) >= 2, 'the sections kept by their titles');
match_all();
$m = json_decode((string) one("SELECT derived_totals -> 'matcher' FROM imports WHERE id = :i", ['i' => $u]), true) ?: [];
ok(($m['new'] ?? 0) > 300 && ($m['auto'] ?? 0) > 0 && ($m['failed'] ?? -1) === 0, sprintf('the matcher: %d new holders, %d repeats of one registration (several certificates), %d proposed', $m['new'] ?? 0, $m['auto'] ?? 0, $m['proposed'] ?? 0));
ok((int) one('SELECT count(*) FROM register_rows WHERE import_id = :i AND is_cede AND holder_id IS NOT NULL', ['i' => $u]) === 0, 'Cede & Co is never a holder');
$d = json_decode((string) one('SELECT derived_totals FROM imports WHERE id = :i', ['i' => $u]), true);
ok((int) $d['cede'] === 1581000 && array_sum($d['by_type']) + (int) $d['cede'] === 17053900, 'the upload keeps what a Viewer may read: Cede 1,581,000 and the rest by position type');

echo "2. The reconciliation's register half\n";
$csv = spr_csv3('07831X206', ['2024-03-29', '2024-04-01', '2024-04-02', '2024-04-03', '2024-04-04', '2024-04-05'], [['0164', 'CHS SCHWAB', [600000, 600000, 600000, 600000, 600000, 600000]], ['0226', 'NATL FIN', [981000, 981000, 981000, 981000, 981000, 981000]]]);
$r = upload($jP, ['name' => 'spr-fixture.csv', 'bytes' => $csv], ['security' => $sec, 'kind' => '']);
ok($r['code'] === 200 && ($r['json']['tied'] ?? null) === true, 'an SPR of the same week for the fixture CUSIP: 1,581,000 at DTC, tied');
$sc = post_json($jM, '/issuers/share-counts/save.php', ['security' => $sec, 'as_of' => '2024-04-04', 'kind' => 'outstanding', 'value' => '17,053,900', 'source' => 'transfer_agent']);
ok($sc['code'] === 200, 'the transfer agent\'s outstanding recorded: 17,053,900' . ($sc['code'] === 200 ? '' : ' — ' . substr($sc['body'], 0, 200)));
$r = post_json($jP, '/reconciliation/run.php', ['security' => $sec, 'as_of' => '2024-04-04']);
$rec = q('SELECT * FROM share_reconciliations WHERE id = :i', ['i' => (int) ($r['json']['record_id'] ?? 0)])[0] ?? [];
ok(($r['json']['state'] ?? '') === 'tied' && $rec['register_date'] === '2024-04-04' && $rec['cede_source'] === 'register' && $rec['tied_registered'] === true && (float) $rec['difference_registered'] === 0.0 && (float) $rec['outstanding'] === 17053900.0 && $rec['tied_spr'] === true,
    'IT TIES: outstanding = Cede + the register\'s rest (the register\'s Cede line, not a typed count); Cede = the SPR total');
$h = page($jP, '/reconciliation?security=' . $sec)['body'];
ok(str_contains($h, 'the register of Apr 4, 2024') || str_contains($h, 'the register of'), 'the page names the register it read');
$hv = page($jV, '/holders/register?security=' . $sec)['body'];
ok(str_contains($hv, 'id="register-cede"') && str_contains($hv, '1,581,000') && str_contains($hv, 'id="register-rows-withheld"') && !str_contains($hv, 'id="register-rows-table"'), 'the register screen: a Viewer reads the components and no row');
$hp = page($jP, '/holders/register?security=' . $sec)['body'];
ok(str_contains($hp, 'id="register-rows-table"') && str_contains($hp, 'badge text-bg-info">Cede'), 'an Analyst reads the rows with Cede flagged');

echo "3. A transfer agent's extract: stored without what is never stored\n";
$ex = extract_csv([
    ['name' => 'SMOKE ALPHA PERSON', 'street' => '1 ELM ST', 'types' => ['BOOK' => 500, 'BOOKR' => 9500]],
    ['name' => 'SMOKE BETA HOLDINGS LLC', 'street' => '2 OAK AVE', 'type' => 'CORPORATION', 'types' => ['CERT' => 1000]],
    ['name' => 'CEDE & CO (FAST)', 'street' => '570 WASHINGTON BLVD', 'city' => 'JERSEY CITY', 'state' => 'NJ', 'zip' => '07310', 'type' => 'NOMINEE/DEPOSITORY', 'types' => ['BOOK' => 1581000]],
]);
$r = upload($jP, ['name' => 'Shareholder Extract - SMOK 06-30-24.csv', 'bytes' => $ex], ['security' => $sec, 'kind' => '']);
$u2 = (int) ($r['json']['record_id'] ?? 0);
$im2 = q('SELECT * FROM imports WHERE id = :i', ['i' => $u2])[0] ?? [];
ok($r['code'] === 200 && ($r['json']['tied'] ?? null) === true && ($im2['connector'] ?? '') === 'ta_extract_csv' && $im2['record_date'] === '2024-06-30', 'the extract: detected, its record date from its name (06-30-24), tied' . ($r['code'] === 200 ? '' : ' — ' . substr($r['body'], 0, 200)));
$path = need('SI_STORAGE_DIR') . '/' . one('SELECT a.storage_path FROM attachments a JOIN imports i ON i.attachment_id = a.id WHERE i.id = :i', ['i' => $u2]);
$stored = (string) file_get_contents($path);
ok(!str_contains($stored, 'TAX-ID') && !str_contains($stored, '123-45-') && !str_contains($stored, 'DATE OF BIRTH') && !str_contains($stored, '/1960') && !str_contains($stored, '555-01') && !str_contains($stored, 'example.invalid')
    && str_contains($stored, 'SMOKE ALPHA PERSON') && str_contains($stored, 'TOTAL SHARES'), 'the stored copy has no tax id, date of birth, phone or email — the registration and the shares stay');
ok(one('SELECT control_totals ->> \'original_sha256\' FROM imports WHERE id = :i', ['i' => $u2]) === hash('sha256', $ex) && upload($jP, ['name' => 'again.csv', 'bytes' => $ex], ['security' => $sec, 'kind' => ''])['code'] === 422,
    'the same extract again is refused (the original\'s hash is kept)');
$rows = q('SELECT position_type, shares, restriction_text, holder_ref, is_cede FROM register_rows WHERE import_id = :i ORDER BY row_no', ['i' => $u2]);
ok(array_column($rows, 'position_type') === ['BOOK', 'BOOKR', 'CERT', 'BOOK'] && $rows[1]['restriction_text'] === 'restricted book' && $rows[0]['holder_ref'] === '100' && $rows[3]['is_cede'] === true,
    'one register row per non-zero type column (BOOK and BOOKR of one holder), BOOKR restricted, the ACCT# unwrapped (="100" → 100), Cede flagged');
ok((string) one("SELECT raw::text FROM register_rows WHERE import_id = :i ORDER BY row_no LIMIT 1", ['i' => $u2]) !== '' && !str_contains((string) one("SELECT string_agg(raw::text || coalesce(registration_name, ''), ' ') FROM register_rows WHERE import_id = :i", ['i' => $u2]), '123-45-'),
    'no row holds a tax id, in its columns or its raw cells');
ok(one("SELECT kind FROM holders WHERE name_key = 'SMOKE BETA HOLDINGS LLC'") === 'entity', 'the corporation is an entity holder');

echo "4. What unties or refuses an extract\n";
$bad = extract_csv([['name' => 'SMOKE GAMMA PERSON', 'street' => '3 PINE RD', 'types' => ['BOOK' => 100], 'total' => 150]]);
$r = upload($jP, ['name' => 'Shareholder Extract - SMOK 09-30-24.csv', 'bytes' => $bad], ['security' => $sec, 'kind' => '']);
$u3 = (int) ($r['json']['record_id'] ?? 0);
ok(($r['json']['tied'] ?? null) === false && str_contains((string) one('SELECT tie_note FROM imports WHERE id = :i', ['i' => $u3]), 'TOTAL SHARES adds to 150, the rows written to 0') && (int) one('SELECT rows_failed FROM imports WHERE id = :i', ['i' => $u3]) === 1,
    'a holder line whose TOTAL SHARES (150) is not its types\' sum (100): the row keeps its error, the upload unties, named');
$r = upload($jP, ['name' => 'extract.csv', 'bytes' => extract_csv([['name' => 'SMOKE DELTA PERSON', 'street' => '4 ASH CT', 'types' => ['DRS' => 10]]])], ['security' => $sec, 'kind' => 'register']);
ok($r['code'] === 422 && str_contains($r['body'], 'does not say its record date'), 'an extract with no date in it or in its name, and none given: 422 — give the record date');
$r = upload($jP, ['name' => 'extract.csv', 'bytes' => extract_csv([['name' => 'SMOKE DELTA PERSON', 'street' => '4 ASH CT', 'types' => ['DRS' => 10]]])], ['security' => $sec, 'kind' => 'register', 'record_date' => '2024-12-31']);
ok($r['code'] === 200 && one('SELECT record_date FROM imports WHERE id = :i', ['i' => (int) ($r['json']['record_id'] ?? 0)]) === '2024-12-31', '…given, it loads as of that date');
$r = upload($jP, ['name' => 'stc.xlsx', 'bytes' => file_get_contents(FIX . 'register_stc.xlsx') . "\0"], ['security' => $sec, 'kind' => '']);
ok($r['code'] === 422 && str_contains($r['body'], 'already loaded (upload ' . $u . ')'), 'a second register of 2024-04-04: refused, naming the first');
finish();
