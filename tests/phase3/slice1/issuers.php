<?php
/**
 * Proof: issuers, securities, share counts, the restriction list, tags and notes (issuers-participants.md "Proof"): the SEC lookup (refused in words
 * without the EDGAR contact; by ticker, by CIK, none), an issuer saved and read from EDGAR at once, the CIK kept zero-padded, the field errors, a
 * duplicate CIK, the securities with one primary, the CUSIP's checks, dated counts corrected and removed with their sources, THE WALL (a restricted
 * issuer gone from an Analyst's list, page, switcher, search and picker — and back when lifted; the log carrying ids, never names), the tombstone
 * and its restore, tags and notes behind both walls, a Viewer reading everything and changing nothing, and every action's log line.
 */
require __DIR__ . '/lib.php';
[$jM, ] = sign_on(27);          // Marco, IR Manager
[$jP, ] = sign_on(26);          // Priya, Analyst
[$jD, ] = sign_on(30);          // Dana, Analyst
[$jV, ] = sign_on(29);          // Ann, Viewer

echo "1. The SEC lookup\n";
edgar_contact(false);
$h = page($jM, '/issuers/new?ticker=SMOK')['body'];
ok(str_contains($h, 'id="issuer-lookup-error"') && str_contains($h, 'business name and a contact email in Settings'), 'without the EDGAR contact the lookup says what is missing, in words');
edgar_contact(true);
$d = json_decode(page($jM, '/issuers/new?ticker=smok', ['headers' => ['Accept: application/json']])['body'], true)['data'] ?? [];
ok(($d['values']['name'] ?? '') === 'SMOKE ISSUER INC' && $d['values']['cik'] === '0000999999' && $d['values']['exchange'] === 'nasdaq' && $d['values']['ticker'] === 'SMOK', 'by ticker (any case): the name, the CIK, the exchange fill the form');
$d = json_decode(page($jM, '/issuers/new?cik=999998', ['headers' => ['Accept: application/json']])['body'], true)['data'] ?? [];
ok(($d['values']['name'] ?? '') === 'SMOKE OTC HOLDINGS CORP' && $d['values']['exchange'] === 'otc', 'by CIK: an OTC company');
$d = json_decode(page($jM, '/issuers/new?ticker=SMTB', ['headers' => ['Accept: application/json']])['body'], true)['data'] ?? [];
ok(($d['lookup']['found']['tickers'] ?? []) === ['SMTA', 'SMTB'], 'a company with two classes: both tickers named');
$h = page($jM, '/issuers/new?ticker=NOPE')['body'];
ok(str_contains($h, 'id="issuer-lookup-none"'), 'an unknown ticker: the SEC lists no company — fill by hand');
ok(page($jP, '/issuers/new')['code'] === 403, 'an Analyst may not add an issuer (403)');

echo "2. Saving an issuer — read from EDGAR at once\n";
$since = last_activity_id();
$r = post_json($jM, '/issuers/save.php', ['name' => 'SMOKE ISSUER INC', 'ticker' => 'smok', 'cik' => '999999', 'exchange' => 'nasdaq', 'transfer_agent' => 'Securities Transfer Corporation', 'transfer_agent_connector' => 'stc']);
$a = (int) ($r['json']['record_id'] ?? 0);
$i = q('SELECT * FROM issuers WHERE id = :i', ['i' => $a])[0] ?? [];
ok($r['code'] === 200 && $a > 0 && ($r['json']['edgar']['status'] ?? '') === 'ok' && $r['json']['location'] === '/issuers/' . $a . '?notice=created', 'issuer_save makes it, reads EDGAR, lands on the issuer');
ok($i['cik'] === '0000999999' && $i['ticker'] === 'SMOK' && $i['state_of_incorporation'] === 'NV' && $i['edgar_synced_at'] !== null, 'the CIK kept zero-padded, the ticker upper-cased, the state filled by EDGAR');
$logs = array_column(q('SELECT action FROM activity_log WHERE id > :s ORDER BY id', ['s' => $since]), 'action');
ok(in_array('issuer.save', $logs, true) && in_array('issuer.edgar_sync', $logs, true) && in_array('source.pull_done', $logs, true), 'logged issuer.save, source.pull_done and issuer.edgar_sync');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'issuer.save' AND issuer_id = :i AND id > :s", ['i' => $a, 's' => $since]) === 1, 'the log line carries the issuer');
$h = page($jM, '/issuers/' . $a)['body'];
ok(str_contains($h, 'SMOKE ACQUISITION CORP') && str_contains($h, 'id="issuer-entity"') && str_contains($h, 'Securities Transfer Corporation'), 'its page shows the EDGAR entity, the former name and the transfer agent');
$r = post_json($jM, '/issuers/save.php', ['name' => 'SMOKE OTC HOLDINGS CORP', 'cik' => '999998', 'exchange' => 'otc']);
$b = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $b > 0 && ($r['json']['edgar']['status'] ?? '') !== 'ok' && str_contains($r['json']['location'], 'created_unsynced'), 'a CIK EDGAR does not know: the issuer is saved, and the page says EDGAR could not be read');
ok(str_contains(page($jM, '/issuers/' . $b . '?notice=created_unsynced')['body'], 'EDGAR could not be read yet'), '…in words');
$r = post_json($jM, '/issuers/save.php', ['name' => 'SMOKE GAMMA INC', 'ticker' => 'SMKG', 'exchange' => 'nyse']);
$g = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $g > 0 && !isset($r['json']['edgar']['status']), 'an issuer with no CIK is saved without a pull');

echo "3. Field errors and a duplicate\n";
$cases = [[['name' => ''], 'name'], [['name' => 'X', 'cik' => 'abc'], 'cik'], [['name' => 'X', 'cik' => '12345678901'], 'cik'], [['name' => 'X', 'ticker' => 'BAD TICKER'], 'ticker'],
          [['name' => 'X', 'exchange' => 'lse'], 'exchange'], [['name' => 'X', 'fiscal_year_end' => '1332'], 'fiscal_year_end'], [['name' => 'X', 'sic' => '12'], 'sic'],
          [['name' => 'X', 'timezone' => 'Mars/Olympus'], 'timezone'], [['name' => 'X', 'market_holidays' => '2026-13-01'], 'market_holidays']];
$bad = [];
foreach ($cases as [$form, $field]) {
    $r = post_json($jM, '/issuers/save.php', $form);
    if ($r['code'] !== 422 || !isset($r['json']['error']['fields'][$field]) && !isset($r['json']['fields'][$field])) { $bad[] = $field . ' ' . $r['code'] . ' ' . substr($r['body'], 0, 120); }
}
ok($bad === [], count($cases) . ' bad fields each refused with 422 naming the field' . ($bad ? ': ' . implode(' | ', $bad) : ''));
$r = post_json($jM, '/issuers/save.php', ['name' => 'SMOKE DUPLICATE', 'cik' => '0000999999']);
ok($r['code'] === 422 && str_contains($r['body'], 'That CIK is another issuer'), 'a CIK another issuer has: 422 in words');
$r = post_json($jM, '/issuers/save.php', ['issuer' => $a, 'notes' => 'SMOKE: the company we watch', 'market_holidays' => '2026-12-24, 2026-11-27']);
ok($r['code'] === 200 && one('SELECT market_holidays::text FROM issuers WHERE id = :i', ['i' => $a]) === '{2026-11-27,2026-12-24}', 'an edit changes only what it names; the holidays kept sorted');
$r = post_json($jM, '/issuers/save.php', ['issuer' => $a, 'name' => 'SMOKE ISSUER INC']);
ok($r['code'] === 200 && ($r['json']['did'] ?? '') === 'Nothing to change', 'the same value again: nothing to change');
$n = json_decode((string) one("SELECT after FROM activity_log WHERE action = 'issuer.save' AND issuer_id = :i ORDER BY id DESC LIMIT 1", ['i' => $a]), true);
ok(($n['notes'] ?? '') === '(changed)' && !str_contains(json_encode($n), 'the company we watch'), 'a note\'s words never reach the log');

echo "4. Securities — one primary, the CUSIP's checks\n";
$r = post_json($jM, '/issuers/securities/save.php', ['issuer' => $a, 'name' => 'Common Stock', 'cusip' => '99900s101', 'ticker' => 'SMOK']);
$common = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $common > 0 && one('SELECT is_primary FROM securities WHERE id = :s', ['s' => $common]) === true && one('SELECT cusip FROM securities WHERE id = :s', ['s' => $common]) === '99900S101', 'the first security is the primary; the CUSIP upper-cased');
$r = post_json($jM, '/issuers/securities/save.php', ['issuer' => $a, 'name' => 'Series A Preferred', 'class' => 'preferred', 'cusip' => '99900S200']);
$pref = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && one('SELECT is_primary FROM securities WHERE id = :s', ['s' => $pref]) === false, 'a second security is not primary unless asked');
ok(post_json($jM, '/issuers/securities/save.php', ['issuer' => $a, 'name' => 'Bad', 'cusip' => '1234'])['code'] === 422, 'a CUSIP of four characters: 422');
$r = post_json($jM, '/issuers/securities/save.php', ['issuer' => $b, 'name' => 'Common Stock', 'cusip' => '99900S101']);
ok($r['code'] === 422 && str_contains($r['body'], 'That CUSIP is another security'), 'a CUSIP another security has: 422 in words');
ok(post_json($jM, '/issuers/securities/save.php', ['issuer' => $a, 'name' => 'Bad', 'listed_on' => '2026-05-01', 'delisted_on' => '2026-01-01'])['code'] === 422, 'delisted before listed: 422');
$r = post_json($jM, '/issuers/securities/save.php', ['security' => $pref, 'is_primary' => 'yes']);
ok($r['code'] === 200 && one('SELECT is_primary FROM securities WHERE id = :s', ['s' => $pref]) === true && one('SELECT is_primary FROM securities WHERE id = :s', ['s' => $common]) === false, 'making the preferred primary steps the common down (one primary, always)');
post_json($jM, '/issuers/securities/save.php', ['security' => $common, 'is_primary' => 'yes']);
ok(one('SELECT count(*) FROM securities WHERE issuer_id = :i AND is_primary', ['i' => $a]) === 1 && (int) one('SELECT id FROM securities WHERE issuer_id = :i AND is_primary', ['i' => $a]) === $common, '…and back');
$h = page($jM, '/issuers/' . $a . '/securities?security=' . $pref)['body'];
ok(str_contains($h, 'Edit Series A Preferred') && str_contains($h, 'value="99900S200"'), 'the securities page edits one on the page (?security=)');
$r = post_json($jM, '/issuers/securities/delete.php', ['security' => $pref]);
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM securities WHERE id = :s', ['s' => $pref]) === 0 && (int) one("SELECT count(*) FROM activity_log WHERE action = 'security.delete' AND security_id = :s", ['s' => $pref]) === 1, 'security_delete removes it, logged with the security');
post_json($jM, '/issuers/securities/save.php', ['issuer' => $b, 'name' => 'Common Stock', 'cusip' => '99900T101', 'ticker' => 'SMOT']);

echo "5. Share counts — dated, with a source, corrected, removed\n";
$r = post_json($jM, '/issuers/share-counts/save.php', ['security' => $common, 'as_of' => '2026-09-30', 'kind' => 'outstanding', 'value' => '17,053,900', 'note' => 'from the 10-Q cover']);
$c1 = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && (float) one('SELECT value FROM share_counts WHERE id = :c', ['c' => $c1]) === 17053900.0 && one('SELECT source FROM share_counts WHERE id = :c', ['c' => $c1]) === 'manual', 'a count recorded: 17,053,900 outstanding as of 2026-09-30, entered by a person');
$r = post_json($jM, '/issuers/share-counts/save.php', ['security' => $common, 'as_of' => '2026-09-30', 'kind' => 'outstanding', 'value' => '17053950']);
ok($r['code'] === 200 && (int) $r['json']['record_id'] === $c1 && str_starts_with($r['json']['did'], 'Corrected') && (float) one('SELECT value FROM share_counts WHERE id = :c', ['c' => $c1]) === 17053950.0, 'the same date, kind and source again corrects it');
post_json($jM, '/issuers/share-counts/save.php', ['security' => $common, 'as_of' => '2026-09-30', 'kind' => 'outstanding', 'value' => '17053900', 'source' => 'transfer_agent']);
ok((int) one("SELECT count(*) FROM share_counts WHERE security_id = :s AND kind = 'outstanding'", ['s' => $common]) === 2 && (float) one("SELECT si_share_count(:s, 'outstanding', '2026-10-01')", ['s' => $common]) === 17053900.0,
    'the transfer agent\'s count beside the person\'s: the agent\'s is the one the reconciliation reads (its source ranks first)');
$bad = [];
foreach ([['as_of' => '2026-09-30', 'kind' => 'outstanding', 'value' => '1', 'source' => 'filing'], ['as_of' => '2099-01-01', 'kind' => 'outstanding', 'value' => '1'], ['as_of' => '2026-09-30', 'kind' => 'shares', 'value' => '1'],
          ['as_of' => '2026-09-30', 'kind' => 'float', 'value' => '1.5'], ['as_of' => '', 'kind' => 'float', 'value' => '1'], ['as_of' => '2026-09-30', 'kind' => 'float', 'value' => '-5']] as $f) {
    if (post_json($jM, '/issuers/share-counts/save.php', $f + ['security' => $common])['code'] !== 422) { $bad[] = json_encode($f); }
}
ok($bad === [], 'refused: a source a person may not claim (filing), a future date, an unknown kind, a fraction of a share, no date, a negative count' . ($bad ? ': ' . implode(' ', $bad) : ''));
$h = page($jP, '/issuers/' . $a)['body'];
ok(str_contains($h, '17,053,900') && str_contains($h, 'Source: the transfer agent'), 'the issuer\'s overview shows the count with its source and date');
$r = post_json($jM, '/issuers/share-counts/delete.php', ['share_count' => $c1]);
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM share_counts WHERE id = :c', ['c' => $c1]) === 0, 'share_count_delete removes one');

echo "6. THE WALL — a restriction list\n";
$since = last_activity_id();
$r = post_json($jM, '/issuers/access/save.php', ['issuer' => $b, 'members' => ['30']]);
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM issuer_members WHERE issuer_id = :b', ['b' => $b]) === 1, 'Marco restricts SMOKE OTC to Dana');
$l = json_decode((string) one("SELECT after FROM activity_log WHERE action = 'restriction.save' AND id > :s", ['s' => $since]), true);
ok($l['members'] === 1 && $l['added'] === [30] && !str_contains(json_encode($l), 'Dana'), 'restriction.save logs ids and counts, never a name');
[$jP2, ] = sign_on(26);
$ids = array_column(json_decode(page($jP2, '/issuers', ['headers' => ['Accept: application/json']])['body'], true)['data']['issuers'] ?? [], 'issuer_id');
ok(!in_array($b, $ids, true) && in_array($a, $ids, true), 'Priya\'s list no longer holds it');
ok(page($jP2, '/issuers/' . $b)['code'] === 404 && page($jP2, '/issuers/' . $b . '/securities')['code'] === 404 && page($jP2, '/issuers/' . $b . '/share-counts')['code'] === 404, 'its page and its tabs answer 404 to her — the same as an issuer that does not exist');
ok(page($jP2, '/issuers/999999')['code'] === 404, '(an issuer that does not exist: 404)');
ok(!str_contains(page($jP2, '/?issuer=' . $b)['body'], 'id="issuer-switcher-item-' . $b . '"'), 'the switcher never offers it');
ok(!str_contains(page($jP2, '/pick/?source=issuer&q=OTC')['body'], 'SMOKE OTC'), 'the picker never offers it');
ok(!str_contains(page($jP2, '/search?scope=all&q=OTC', ['headers' => ['Accept: application/json']])['body'], 'SMOKE OTC'), 'the search never finds it');
ok(post_json($jP2, '/notes/add.php', ['kind' => 'issuer', 'record' => $b, 'body' => 'x'])['code'] === 404 && post_json($jP2, '/tags/apply.php', ['kind' => 'issuer', 'record' => $b, 'tag' => 'x'])['code'] === 404, 'a note or a tag on it: 404');
[$jD2, ] = sign_on(30);
ok(page($jD2, '/issuers/' . $b)['code'] === 200 && str_contains(page($jD2, '/issuers/' . $b)['body'], 'restricted to named members'), 'Dana, on the list, sees it, marked restricted');
ok(page($jM, '/issuers/' . $b)['code'] === 200, 'the IR Manager keeps it');
ok(page($jP2, '/issuers/' . $a . '/access')['code'] === 403, 'the access page is a manager\'s (403 to an Analyst)');
$r = post_json($jM, '/issuers/access/save.php', ['issuer' => $b, 'members' => '']);
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM issuer_members WHERE issuer_id = :b', ['b' => $b]) === 0 && page($jP2, '/issuers/' . $b)['code'] === 200, 'an empty list lifts it: Priya sees it again');
ok(post_json($jM, '/issuers/access/save.php', ['issuer' => $b, 'members' => ['41']])['code'] === 422, 'a member not admitted cannot be listed (the unadmitted Watcher): 422');
post_json($jM, '/issuers/access/save.php', ['issuer' => $b, 'members' => ['30']]);

echo "7. The tombstone\n";
$r = post_json($jM, '/issuers/delete.php', ['issuer' => $g]);
ok($r['code'] === 200 && one('SELECT deleted_at IS NOT NULL FROM issuers WHERE id = :g', ['g' => $g]) && page($jM, '/issuers/' . $g)['code'] === 404, 'issuer_delete: to the tombstone; its page is gone');
$d = json_decode(page($jM, '/issuers', ['headers' => ['Accept: application/json']])['body'], true)['data'] ?? [];
ok(in_array($g, array_column($d['tombstone'] ?? [], 'issuer_id'), true) && !in_array($g, array_column($d['issuers'], 'issuer_id'), true), 'the manager\'s list shows it in the tombstone, not among the issuers');
ok(!isset(json_decode(page($jP, '/issuers', ['headers' => ['Accept: application/json']])['body'], true)['data']['tombstone'][0]), 'an Analyst sees no tombstone');
ok(post_json($jP, '/issuers/restore.php', ['issuer' => $g])['code'] === 403, 'an Analyst may not restore (403)');
$r = post_json($jM, '/issuers/restore.php', ['issuer' => $g]);
ok($r['code'] === 200 && page($jM, '/issuers/' . $g)['code'] === 200 && (int) one("SELECT count(*) FROM activity_log WHERE action IN ('issuer.delete', 'issuer.restore') AND issuer_id = :g", ['g' => $g]) === 2, 'issuer_restore brings it back; both logged');
ok(post_json($jM, '/issuers/restore.php', ['issuer' => $a])['code'] === 404, 'restoring an issuer that is not in the tombstone: 404');

echo "8. EDGAR on demand\n";
ok(post_json($jM, '/issuers/edgar-sync.php', ['issuer' => $g])['code'] === 422 && str_contains(post_json($jM, '/issuers/edgar-sync.php', ['issuer' => $g])['body'], 'no CIK'), 'an issuer with no CIK: 422 in words');
q("UPDATE issuers SET state_of_incorporation = NULL WHERE id = :a", ['a' => $a]);
$r = post_json($jM, '/issuers/edgar-sync.php', ['issuer' => $a]);
ok($r['code'] === 200 && ($r['json']['filled'] ?? []) === ['state_of_incorporation'] && one('SELECT state_of_incorporation FROM issuers WHERE id = :a', ['a' => $a]) === 'NV', 'issuer_edgar_sync reads it again and fills what was blank');
$r = post_json($jM, '/issuers/edgar-sync.php', ['issuer' => $b]);
ok($r['code'] === 422 && str_contains($r['body'], 'EDGAR does not know CIK 999998'), 'a CIK EDGAR does not know: in words');

echo "9. Tags and notes\n";
$r = post_json($jP, '/tags/apply.php', ['kind' => 'issuer', 'record' => $a, 'tag' => 'Watch List']);
ok($r['code'] === 200 && in_array('Watch List', pg_text_array_t((string) one("SELECT si_tags_of('issuer', :a)", ['a' => $a])), true), 'an Analyst tags an issuer (a new tag made)');
$r = post_json($jP, '/tags/apply.php', ['kind' => 'issuer', 'record' => $a, 'tag' => 'watch list']);
ok($r['code'] === 200 && str_contains($r['json']['did'], 'already') && (int) one("SELECT count(*) FROM tags WHERE lower(label) = 'watch list'") === 1, 'the same label in another case: the same tag, already applied');
ok(post_json($jP, '/tags/apply.php', ['kind' => 'galaxy', 'record' => $a, 'tag' => 'x'])['code'] === 422, 'an unknown kind: 422');
$r = post_json($jP, '/tags/remove.php', ['kind' => 'issuer', 'record' => $a, 'tag' => 'WATCH LIST']);
ok($r['code'] === 200 && pg_text_array_t((string) one("SELECT si_tags_of('issuer', :a)", ['a' => $a])) === [], 'tag_remove by label');
$since = last_activity_id();
$r = post_json($jP, '/notes/add.php', ['kind' => 'issuer', 'record' => $a, 'body' => 'SMOKE: the CFO called about the short report']);
ok($r['code'] === 200 && str_contains(page($jP, '/issuers/' . $a . '?tab=notes')['body'], 'the CFO called'), 'note_add: the note on the issuer\'s Notes tab');
$nl = json_decode((string) one("SELECT after FROM activity_log WHERE action = 'note.add' AND id > :s", ['s' => $since]), true);
ok(($nl['length'] ?? 0) === 44 && !str_contains(json_encode($nl), 'CFO'), 'note.add logs the length, never the words');
post_json($jM, '/notes/add.php', ['kind' => 'issuer', 'record' => $b, 'body' => 'SMOKE: a note on the restricted issuer']);
ok(!str_contains(page($jP, '/issuers/' . $a . '?tab=notes')['body'], 'restricted issuer') && (int) app_php('db()->exec("SELECT set_config(\'app.member_id\', \'26\', false)"); echo json_encode((int) db()->query("SELECT count(*) FROM mcp_notes WHERE record_type = \'issuer\' AND record_id = ' . $b . '")->fetchColumn());') === 0,
    'a note on a restricted issuer is not readable through mcp_notes by someone off the list (db/020)');

echo "10. A Viewer reads everything and changes nothing\n";
$reads = ['/issuers', '/issuers/' . $a, '/issuers/' . $a . '?tab=sources', '/issuers/' . $a . '?tab=notes', '/issuers/' . $a . '/securities', '/issuers/' . $a . '/share-counts'];
$bad = array_filter($reads, static fn ($u) => page($jV, $u)['code'] !== 200);
ok($bad === [], 'a Viewer reads the issuer screens' . ($bad ? ': ' . implode(', ', $bad) : ''));
$h = page($jV, '/issuers/' . $a . '/securities')['body'];
ok(!str_contains($h, 'id="security-form"') && !str_contains($h, 'id="issuer-edit-btn"') && !str_contains(page($jV, '/issuers/' . $a)['body'], 'id="issuer-delete-btn"'), '…with no form, edit or delete button');
$writes = [['/issuers/save.php', ['name' => 'X']], ['/issuers/save.php', ['issuer' => $a, 'notes' => 'x']], ['/issuers/delete.php', ['issuer' => $a]], ['/issuers/edgar-sync.php', ['issuer' => $a]],
           ['/issuers/securities/save.php', ['issuer' => $a, 'name' => 'X']], ['/issuers/securities/delete.php', ['security' => $common]], ['/issuers/share-counts/save.php', ['security' => $common, 'as_of' => '2026-09-30', 'kind' => 'float', 'value' => '1']],
           ['/issuers/access/save.php', ['issuer' => $a, 'members' => '29']], ['/participants/save.php', ['participant' => 1, 'class' => 'other']], ['/tags/apply.php', ['kind' => 'issuer', 'record' => $a, 'tag' => 'x']],
           ['/notes/add.php', ['kind' => 'issuer', 'record' => $a, 'body' => 'x']], ['/issuers/restore.php', ['issuer' => $g]]];
$bad = [];
foreach ($writes as [$u, $f]) {
    $c = post_json($jV, $u, $f)['code'];
    if ($c !== 403) { $bad[] = "$u $c"; }
}
ok($bad === [], 'every one of ' . count($writes) . ' slice 1 actions refuses a Viewer with 403' . ($bad ? ': ' . implode(', ', $bad) : ''));
ok(post_json($jP, '/issuers/securities/save.php', ['issuer' => $a, 'name' => 'X'])['code'] === 403 && post_json($jP, '/issuers/access/save.php', ['issuer' => $a, 'members' => ''])['code'] === 403, 'an Analyst may not change securities or the restriction list (403)');

echo "11. The approvals the kernel holds for an agent\n";
$reg = json_decode((string) file_get_contents('mcp/action_registry.json'), true)['actions'];
$man = array_column(json_decode((string) file_get_contents('maludb-os.json'), true)['approvals'], 'category', 'action');
$want = ['issuer_save' => 'other', 'issuer_delete' => 'deletion', 'security_save' => 'other', 'security_delete' => 'deletion', 'share_count_save' => 'other', 'share_count_delete' => 'deletion', 'restriction_save' => 'other'];
$bad = [];
foreach ($want as $k => $cat) {
    if (($reg[$k]['approval'] ?? null) !== $cat || ($man[$k] ?? null) !== $cat || empty($reg[$k]['built'])) { $bad[] = $k; }
}
ok($bad === [], 'the seven pausing actions are built and declared to the kernel with their categories (an agent\'s call waits for a person)' . ($bad ? ': ' . implode(', ', $bad) : ''));
$built = array_keys(array_filter($reg, static fn ($x) => $x['section'] === 'Issuers, securities, share counts and the DTC participants (slice 1)' && !empty($x['built'])));
ok(count($built) === 13, 'the registry counts slice 1\'s thirteen actions built: ' . count($built));
finish();

function pg_text_array_t(string $l): array { $l = trim($l, '{}'); return $l === '' ? [] : array_map(static fn ($v) => trim($v, '"'), str_getcsv($l, ',', '"', '\\')); }
