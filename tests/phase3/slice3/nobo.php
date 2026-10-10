<?php
/**
 * Proof: Broadridge's NOBO list through the pipeline (holder-identity.md §1–§2, §6): the anonymised job file uploads, its header's 855 against
 * the SHARES section's 841 rows and the Bank/Broker listing's difference NAMED, untied, nothing matched; an IR Manager accepts it and the
 * matcher runs — the Phase 0 proof's counts (hundreds new, the repeat registrations automatic, the namesakes proposed, none failed), each
 * automatic match and proposal its own log line with ids and the score; the CLIENTS section matched to participants; a second list of the
 * same record date refused naming the first; a crafted CSV list that ties is matched in the upload's own request; the reconciliation knows
 * the NOBO shares and the OBO remainder; read again; delete and restore with the holder positions summed back.
 */
require __DIR__ . '/lib.php';
[$jP, ] = sign_on(26);
[$jM, ] = sign_on(27);
[$jV, ] = sign_on(29);
$fx = fixture_issuer();
$sec = $fx['security'];
page($jP, '/?issuer=' . $fx['issuer']);
page($jM, '/?issuer=' . $fx['issuer']);
page($jV, '/?issuer=' . $fx['issuer']);
$json = ['headers' => ['Accept: application/json']];

echo "1. The anonymised job file: read, its differences named, untied\n";
$since = last_activity_id();
$r = upload_fixture($jP, 'nobo_list.xlsx', ['security' => $sec]);
$u = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && ($r['json']['tied'] ?? null) === false && str_contains($r['json']['did'] ?? '', 'does not tie'), 'uploaded (kind detected): read, but it does not tie' . ($r['code'] === 200 ? '' : ' — ' . substr($r['body'], 0, 300)));
$im = q('SELECT * FROM imports WHERE id = :i', ['i' => $u])[0] ?? [];
ok(($im['kind'] ?? '') === 'nobo' && $im['connector'] === 'broadridge_nobo_xlsx' && $im['record_date'] === '2024-04-04' && str_contains((string) $im['tie_note'], 'The header says 855 NOBOs, the SHARES section has 841 rows')
    && str_contains((string) $im['tie_note'], 'the Bank/Broker listing adds to 1,460,101 shares, the rows to 1,450,101'), 'the header\'s 855 against 841 rows and the listing\'s 1,460,101 against 1,450,101 — each named');
$job = q('SELECT * FROM nobo_jobs WHERE import_id = :i', ['i' => $u])[0] ?? [];
ok(($job['job_number'] ?? '') === 'O08888' && (int) $job['nobo_count_header'] === 855 && (int) $job['nobo_count_rows'] === 841 && (float) $job['shares_total'] === 1450101.0 && $job['cusip'] === '07831X206',
    'the job: O08888, 855 in the header, 841 rows, 1,450,101 shares');
ok((int) one('SELECT count(*) FROM nobo_rows WHERE import_id = :i', ['i' => $u]) === 841 && (int) one('SELECT count(*) FROM nobo_broker_rows WHERE job_id = :j', ['j' => (int) $job['id']]) === 27, '841 rows and the 27 clients of the Bank/Broker listing kept');
ok((int) one('SELECT count(*) FROM nobo_broker_rows WHERE job_id = :j AND participant_id IS NOT NULL', ['j' => (int) $job['id']]) >= 10, (int) one('SELECT count(*) FROM nobo_broker_rows WHERE job_id = :j AND participant_id IS NOT NULL', ['j' => (int) $job['id']]) . ' of the clients matched to a participant of the directory');
ok((int) one('SELECT count(*) FROM nobo_rows WHERE import_id = :i AND holder_id IS NOT NULL', ['i' => $u]) === 0 && (int) one('SELECT count(*) FROM holders') === 0, 'untied: nothing is matched, no holder made');
$acts = array_column(q('SELECT action FROM activity_log WHERE id > :s AND import_id = :i ORDER BY id', ['s' => $since, 'i' => $u]), 'action');
ok($acts === ['upload.create', 'upload.parse', 'upload.untie'], 'logged upload.create, upload.parse, upload.untie: ' . implode(', ', $acts));
$h = page($jP, '/uploads/' . $u)['body'];
ok(str_contains($h, 'id="upload-totals-table"') && str_contains($h, 'NOBOs in the header') && str_contains($h, 'id="upload-clients-table"') && str_contains($h, 'once the upload ties or an IR Manager accepts it'), 'its page: the statements against the rows, the listing, why nothing is matched yet');
$hv = page($jV, '/uploads/' . $u)['body'];
ok(str_contains($hv, 'id="upload-clients-table"') && !str_contains($hv, 'upload-queue-link'), 'a Viewer reads the page — the brokers, never a registration');

echo "2. The manager accepts it: the worker matches the 841 rows\n";
$since = last_activity_id();
$r = post_json($jM, '/uploads/override.php', ['upload' => $u, 'note' => 'SMOKE: Broadridge counts fourteen suppressed rows in the header']);
$q0 = json_decode((string) one("SELECT derived_totals -> 'matcher' FROM imports WHERE id = :i", ['i' => $u]), true) ?: [];
ok(($q0['queued'] ?? false) === true && (int) $q0['waiting'] === 841, 'a list over 200 rows is queued for the worker\'s matcher pass (841 waiting)');
ok(str_contains(page($jP, '/uploads/' . $u)['body'], 'id="upload-matcher-queued"') && str_contains(page($jP, '/uploads/' . $u)['body'], 'status?part=matcher'), 'its page says it is being matched and polls');
$mr = match_all();
ok($mr['uploads'] === 1 && $mr['errors'] === [], 'the worker\'s matcher pass took it');
ok((int) one("SELECT count(*) FROM notifications WHERE member_id = 26 AND kind = 'upload_done' AND record_id = :i", ['i' => $u]) === 1, 'the uploader is told it is matched');
$s = req('GET', '/uploads/' . $u . '/status?part=matcher', ['jar' => $jP, 'headers' => ['HX-Request: true']]);
ok(preg_match('/^HX-Refresh: true/mi', $s['headers']) === 1, 'the page\'s poll now refreshes it');
$m = json_decode((string) one("SELECT derived_totals -> 'matcher' FROM imports WHERE id = :i", ['i' => $u]), true) ?: [];
ok($r['code'] === 200 && ($m['new'] ?? 0) > 500 && ($m['auto'] ?? 0) > 0 && ($m['proposed'] ?? 0) > 0 && ($m['failed'] ?? -1) === 0,
    sprintf('the matcher over the accepted list: %d new holders, %d automatic, %d proposed, none failed', $m['new'] ?? 0, $m['auto'] ?? 0, $m['proposed'] ?? 0));
ok((int) one("SELECT count(*) FROM activity_log WHERE id > :s AND action = 'match.auto'", ['s' => $since]) === $m['auto'] && (int) one("SELECT count(*) FROM activity_log WHERE id > :s AND action = 'match.propose'", ['s' => $since]) === (int) one("SELECT count(*) FROM holder_match_proposals WHERE status = 'proposed'"),
    'each automatic match and each proposal is its own log line');
$l = json_decode((string) one("SELECT after FROM activity_log WHERE id > :s AND action = 'match.auto' ORDER BY id LIMIT 1", ['s' => $since]), true);
$k = array_keys($l ?? []); sort($k);
ok($k === ['holder_id', 'row_id', 'row_kind', 'score'] && $l['score'] >= 0.95, 'a match line carries row_kind, row_id, holder_id and the score — nothing else: ' . json_encode(array_keys($l ?? [])));
ok((int) one("SELECT count(*) FROM activity_log WHERE id > :s AND action = 'matcher.run' AND import_id = :i", ['s' => $since, 'i' => $u]) === 1, 'matcher.run logged with its counts');
ok((float) one("SELECT sum(shares) FROM holder_positions WHERE import_id = :i", ['i' => $u]) + (float) one("SELECT COALESCE(sum(shares), 0) FROM nobo_rows WHERE import_id = :i AND holder_id IS NULL", ['i' => $u]) === 1450101.0,
    'the holders\' positions and the rows still waiting add up to the list');
$f = q("SELECT n.holder_id, n.account_kind, n.custodian_participant_id FROM nobo_rows n WHERE n.import_id = :i AND n.row_no IN (6, 7) ORDER BY n.row_no", ['i' => $u]);
ok(count($f) === 2 && $f[0]['holder_id'] !== null && $f[0]['holder_id'] === $f[1]['holder_id'] && $f[1]['account_kind'] === 'ira' && (int) $f[1]['custodian_participant_id'] === (int) one("SELECT id FROM participants WHERE number = '0164'"),
    'the same person\'s TOD and Schwab IRA rows are one holder; the IRA row carries its kind and custodian (0164)');
$h = page($jP, '/uploads/' . $u)['body'];
ok(str_contains($h, 'id="upload-matcher-counts"') && str_contains($h, 'id="upload-queue-link"'), 'the upload\'s page shows the counts and the way to the queue');

echo "3. A second list of the same record date is refused\n";
$r = upload($jP, ['name' => 'again.xlsx', 'bytes' => file_get_contents(FIX . 'nobo_list.xlsx') . ' '], ['security' => $sec, 'kind' => 'nobo']);
ok($r['code'] === 422 && str_contains($r['body'], 'for 2024-04-04 is already loaded (upload ' . $u . ')'), 'another NOBO list for 2024-04-04: 422, naming upload ' . $u);

echo "4. A crafted CSV list that ties is matched in its own request\n";
$csv = "JOB NUMBER,CUSIP,ISSUER NAME,RECORD DATE,SORT:(D)ESCENDING (Z)IP CODE,NUMBER OF NOBOS\nO07777,07831X206,SMOKE ISSUER INC,030124,D,0000003\n\n"
     . "SHARES,1ST LINE OF NAME & ADDRESS,2ND LINE OF NAME & ADDRESS,3RD LINE OF NAME & ADDRESS,4TH LINE OF NAME & ADDRESS,5TH LINE OF NAME & ADDRESS,6TH LINE OF NAME & ADDRESS,7TH LINE OF NAME & ADDRESS,ZIP CODE FOR SORTING,CUSIP\n\n"
     . "000090000.00000,FRANK U FOSTER,DESIGNATED BENE PLAN/TOD,8117 SPRUCE ST,WINCHESTER NV 72752,,,,72752,07831X206\n"
     . "000000500.00000,FRANK U FOSTER,1 OTHER ST,ELSEWHERE TX 77777,,,,,77777,07831X206\n"
     . "000025000.00000,ZETA HOLDINGS LLC,100 MAIN ST,AUSTIN TX 78701,,,,,78701,07831X206\n\n"
     . "# OF NOBOS,SHARES OF NOBOS\n0000003,000000115500.00000\n\n"
     . "CUSIP,CLIENT,NUMBER OF NOBOS, SHARES\n07831X206,CHARLES SCHWAB & CO.  INC.,0000003,000000115500.00000\n";
$r = upload($jP, ['name' => 'NOBO.07831X.240301.O07777.csv', 'bytes' => $csv], ['security' => $sec, 'kind' => '']);
$u2 = (int) ($r['json']['record_id'] ?? 0);
$m2 = json_decode((string) one("SELECT derived_totals -> 'matcher' FROM imports WHERE id = :i", ['i' => $u2]), true) ?: [];
ok($r['code'] === 200 && ($r['json']['tied'] ?? null) === true && one('SELECT connector FROM imports WHERE id = :i', ['i' => $u2]) === 'broadridge_nobo_csv', 'the CSV job file: read and tied (the header, the summary and the listing agree)' . (($r['json']['tied'] ?? null) === true ? '' : ' — ' . substr($r['body'], 0, 300)));
ok(($m2['auto'] ?? 0) === 1 && ($m2['proposed'] ?? 0) === 1 && ($m2['new'] ?? 0) === 1, 'matched at once: the repeat registration automatic, the name at another address proposed, the LLC new');
ok(one("SELECT kind FROM holders WHERE name_key = 'ZETA HOLDINGS LLC'") === 'entity', 'the LLC is an entity');

echo "5. The reconciliation knows the NOBO side\n";
q("INSERT INTO participant_positions (security_id, participant_id, position_date, shares, import_id) VALUES (:s, (SELECT id FROM participants WHERE number = '0164'), '2024-04-04', 400000, :i)", ['s' => $sec, 'i' => $u]);
$rr = post_json($jP, '/reconciliation/run.php', ['security' => $sec, 'as_of' => '2024-04-04']);
$rec = q('SELECT nobo_shares, nobo_date, obo_remainder FROM share_reconciliations WHERE security_id = :s ORDER BY id DESC LIMIT 1', ['s' => $sec])[0] ?? [];
ok($rr['code'] === 200 && (float) $rec['nobo_shares'] === 1450101.0 && $rec['nobo_date'] === '2024-04-04', 'the reconciliation of 2024-04-04 carries the NOBO shares of the list');
$schwab = q("SELECT spr_shares, nobo_shares, obo_remainder FROM si_obo_remainder(:s, '2024-04-04') WHERE number = '0164'", ['s' => $sec])[0] ?? [];
ok((float) ($schwab['spr_shares'] ?? 0) === 400000.0 && (float) $schwab['obo_remainder'] === 400000.0 - (float) $schwab['nobo_shares'], 'the OBO remainder at Schwab = its SPR position − the listing\'s NOBO shares');

echo "6. Read again, delete, restore\n";
$before = (float) one('SELECT sum(shares) FROM holder_positions WHERE import_id = :i', ['i' => $u]);
$r = post_json($jP, '/uploads/reparse.php', ['upload' => $u2]);
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM nobo_rows WHERE import_id = :i', ['i' => $u2]) === 3 && (int) one('SELECT count(*) FROM nobo_rows WHERE import_id = :i AND holder_id IS NOT NULL', ['i' => $u2]) === 2,
    'read again: the rows replaced and matched again (the remembered holders take them; the proposal waits again)');
$r = post_json($jM, '/uploads/delete.php', ['upload' => $u]);
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM holder_positions WHERE import_id = :i', ['i' => $u]) === 0, 'deleted: its holder positions go');
$r = post_json($jM, '/uploads/restore.php', ['upload' => $u]);
ok($r['code'] === 200 && (float) one('SELECT sum(shares) FROM holder_positions WHERE import_id = :i', ['i' => $u]) === $before, 'restored: the positions come back from the rows, an account\'s rows summed (' . number_format($before) . ')');
finish();
