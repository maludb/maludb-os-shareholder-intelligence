<?php
/**
 * Proof: the upload pipeline's rules on crafted files (position-reports.md §1, §6): the CUSIP checked against the security's, a security with no
 * CUSIP, a file of no layout we read (nothing kept), the kind it is not, a type that is not a sheet, the size limit; an untied file named by its
 * date, a row that fails to type, a file with no Total row; the override (a manager's, with a note); the delete and restore with an overlapped
 * week reloaded; read again; a large file queued and finished by the worker with its uploader told; the store (the file kept whole, 640); a
 * transfer agent's extract stored without its personal columns; a Viewer reads and uploads nothing; an agent may not upload (people only).
 */
require __DIR__ . '/lib.php';
[$jP, ] = sign_on(26);   // Analyst
[$jM, ] = sign_on(27);   // IR Manager
[$jV, ] = sign_on(29);   // Viewer
$ref = reference_issuer();
$sec = $ref['security'];

/** A weekly SPR in DTC's layout: $weeks [opening date => [5 dates]], $rows [[number, name, [6 balances]]], $total: null = the true total, false = no Total row, or an array to write. */
function spr_csv(string $cusip, array $dates, array $rows, $total = null): string
{
    $fmt = static fn (string $d): string => date('m/d/Y', strtotime($d));
    $l = ['', ',,,The Depository Trust Company', ',,,Weekly Subscription Security Position Report', ',,,Daily Closing Balances', ',,,For Week Ending ' . $fmt(end($dates)), '', 'Security Description,Cusip', 'SMOKE SECURITY +,' . $cusip,
          'Participant,Participant,Opening,Monday,Tuesday,Wednesday,Thursday,Friday,Share,Percent', 'Number,Name,' . implode(',', array_map($fmt, $dates)) . ',Change,Change'];
    $sum = array_fill(0, 6, 0);
    foreach ($rows as [$n, $name, $bal]) {
        $l[] = $n . ',' . $name . ',' . implode(',', $bal) . ',0,0.0';
        foreach ($bal as $k => $v) { if (is_numeric($v)) { $sum[$k] += (int) $v; } }
    }
    if ($total !== false) {
        $l[] = 'Total,,' . implode(',', is_array($total) ? $total : $sum) . ',0,0.0';
    }
    return implode("\n", $l) . "\n";
}
$wk1 = ['2026-09-04', '2026-09-07', '2026-09-08', '2026-09-09', '2026-09-10', '2026-09-11'];
$wk2 = ['2026-09-11', '2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18'];
$rows1 = [['0164', 'CHS SCHWAB', [1000, 1000, 1100, 1100, 1200, 1300]], ['0005', 'GOLDMAN', [500, 500, 500, 400, 400, 300]], ['7999', 'SMOKE NEWBROKER', [10, 10, 10, 10, 10, 10]]];
$rows2 = [['0164', 'CHS SCHWAB', [1300, 1350, 1400, 1400, 1400, 1450]], ['0005', 'GOLDMAN', [300, 300, 250, 250, 250, 250]], ['7999', 'SMOKE NEWBROKER', [10, 10, 10, 10, 10, 10]]];
$storeFiles = static fn (): int => (int) trim((string) shell_exec('find ' . escapeshellarg(need('SI_STORAGE_DIR') . '/uploads') . ' -type f 2>/dev/null | wc -l'));

echo "1. What is refused before anything is kept\n";
$before = [(int) one('SELECT count(*) FROM imports'), $storeFiles()];
$r = upload($jP, ['name' => 'wrong-cusip.csv', 'bytes' => spr_csv('99999X999', $wk1, $rows1)], ['security' => $sec]);
ok($r['code'] === 422 && str_contains($r['body'], 'CUSIP 99999X999') && str_contains($r['body'], '09175A107'), 'another security\'s CUSIP: 422, naming both');
q("INSERT INTO issuers (name, ticker) VALUES ('SMOKE NOCUSIP CORP', 'SMNC')");
q("INSERT INTO securities (issuer_id, name, is_primary) VALUES ((SELECT id FROM issuers WHERE ticker = 'SMNC'), 'Common Stock', true)");
$noCusip = (int) one("SELECT s.id FROM securities s JOIN issuers i ON i.id = s.issuer_id WHERE i.ticker = 'SMNC'");
$r = upload($jP, ['name' => 'nocusip.csv', 'bytes' => spr_csv('09175A107', $wk1, $rows1)], ['security' => $noCusip]);
ok($r['code'] === 422 && str_contains($r['body'], 'has no CUSIP yet'), 'a security with no CUSIP: 422 — an IR Manager adds it first');
$r = upload($jP, ['name' => 'random.csv', 'bytes' => "a,b,c\n1,2,3\n"], ['security' => $sec]);
ok($r['code'] === 422 && str_contains($r['body'], 'none of the files read here'), 'a CSV of no layout we read: 422 in words');
$r = upload($jP, ['name' => 'spr.csv', 'bytes' => spr_csv('09175A107', $wk1, $rows1)], ['security' => $sec, 'kind' => 'nobo']);
ok($r['code'] === 422 && str_contains($r['body'], 'not a nobo list'), 'an SPR sent as a NOBO list: 422 — choose its kind');
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$r = upload($jP, ['name' => 'picture.csv', 'bytes' => $png], ['security' => $sec]);
ok($r['code'] === 422 && str_contains($r['body'], 'image/png'), 'a picture named .csv: 422 — finfo judges the type, never the name');
q('UPDATE si_settings SET max_upload_bytes = 1048576 WHERE id = 1');
$r = upload($jP, ['name' => 'big.csv', 'bytes' => str_replace('Participant,Participant', str_repeat(",,,,,,,,,,\n", 120000) . 'Participant,Participant', spr_csv('09175A107', $wk1, $rows1))], ['security' => $sec]);
q('UPDATE si_settings SET max_upload_bytes = 52428800 WHERE id = 1');
ok($r['code'] === 422 && str_contains($r['body'], 'larger than 1 MB'), 'over the installation\'s limit (1 MB set): 422 in MB');
ok([(int) one('SELECT count(*) FROM imports'), $storeFiles()] === $before, 'none of them left an upload or a file in the store');

echo "2. Tied, kept whole, logged\n";
$since = last_activity_id();
$r = upload($jP, ['name' => 'week1.csv', 'bytes' => spr_csv('09175A107', $wk1, $rows1)], ['security' => $sec]);
$u1 = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $r['json']['tied'] === true && $r['json']['positions'] === 18 && $r['json']['location'] === '/uploads/' . $u1 . '?notice=tied', 'a crafted week: tied, 18 positions, landing on the upload');
$a = q('SELECT a.* FROM attachments a JOIN imports i ON i.attachment_id = a.id WHERE i.id = :i', ['i' => $u1])[0];
$path = need('SI_STORAGE_DIR') . '/' . $a['storage_path'];
ok(is_file($path) && hash_file('sha256', $path) === $a['sha256'] && substr(sprintf('%o', fileperms($path)), -3) === '640', 'the file is kept whole in the store (its hash, mode 640)');
ok((int) one("SELECT count(*) FROM participants WHERE number = '7999' AND name = 'SMOKE NEWBROKER'") === 1, 'a number the directory lacks becomes a participant named as the file names it');
$acts = array_column(q('SELECT action FROM activity_log WHERE id > :s AND import_id = :i ORDER BY id', ['s' => $since, 'i' => $u1]), 'action');
ok($acts === ['upload.create', 'upload.parse', 'upload.tie', 'position.load'], 'logged upload.create, upload.parse, upload.tie, position.load — each with the upload: ' . implode(', ', $acts));
ok((float) one("SELECT value FROM share_counts WHERE import_id = :i AND kind = 'cede' AND source = 'spr'", ['i' => $u1]) === 1610.0, 'a tied SPR writes its last date\'s total as the Cede figure it implies (1,610)');
ok((int) one("SELECT count(*) FROM activity_log WHERE action IN ('reconciliation.run', 'reconciliation.tie', 'reconciliation.untie') AND security_id = :s AND id > :x", ['s' => $sec, 'x' => $since]) === 1, 'the reconciliation ran after it');

echo "3. Untied: named by the date, a bad cell, no Total row\n";
$bad = spr_csv('09175A107', $wk2, $rows2, [1610, 1660, 1660, 1760, 1660, 1710]);
$r = upload($jP, ['name' => 'week2-wrong-total.csv', 'bytes' => $bad], ['security' => $sec]);
$u2 = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $r['json']['tied'] === false && str_contains((string) one('SELECT tie_note FROM imports WHERE id = :i', ['i' => $u2]), '2026-09-16: file 1760, rows 1660'), 'a Total row off by 100 on Wednesday: untied, the date and both figures named');
ok((int) one("SELECT count(*) FROM share_counts WHERE import_id = :i", ['i' => $u2]) === 0, '…and it writes no Cede figure');
ok(str_contains(page($jP, '/uploads/' . $u2)['body'], 'id="upload-total-2026-09-16"') && str_contains(page($jP, '/uploads/' . $u2)['body'], '+-100') === false, 'its page lists the totals by date');
$rowsBad = $rows1; $rowsBad[1][2][3] = 'abc';
$r = upload($jP, ['name' => 'bad-cell.csv', 'bytes' => spr_csv('09175A107', ['2026-08-28', '2026-08-31', '2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04'], $rowsBad)], ['security' => $sec]);
$u3 = (int) ($r['json']['record_id'] ?? 0);
$u3r = q('SELECT tied, rows_failed, tie_note FROM imports WHERE id = :i', ['i' => $u3])[0];
ok($r['json']['tied'] === false && (int) $u3r['rows_failed'] === 1 && str_contains((string) $u3r['tie_note'], 'failed to type'), 'a balance of "abc": the row kept with its error, the upload untied');
ok((int) one("SELECT count(*) FROM spr_rows WHERE import_id = :i AND error LIKE '%not a number: abc%'", ['i' => $u3]) === 1, '…the row says why');
$r = upload($jP, ['name' => 'no-total.csv', 'bytes' => spr_csv('09175A107', ['2026-08-21', '2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27', '2026-08-28'], $rows1, false)], ['security' => $sec]);
ok(($r['json']['tied'] ?? null) === false && str_contains((string) one('SELECT tie_note FROM imports WHERE id = :i', ['i' => (int) ($r['json']['record_id'] ?? 0)]), 'no Total row'), 'a file with no Total row: untied — nothing to tie it to' . (($r['json']['tied'] ?? null) === false ? '' : ' — ' . substr($r['body'], 0, 200)));

echo "4. The override\n";
ok(post_json($jP, '/uploads/override.php', ['upload' => $u2, 'note' => 'x'])['code'] === 403, 'an Analyst may not accept a difference (403)');
ok(post_json($jM, '/uploads/override.php', ['upload' => $u2])['code'] === 422, 'without a note: 422');
ok(post_json($jM, '/uploads/override.php', ['upload' => $u1, 'note' => 'x'])['code'] === 422, 'a tied upload is not overridden: 422');
$since = last_activity_id();
$r = post_json($jM, '/uploads/override.php', ['upload' => $u2, 'note' => 'SMOKE: DTC confirmed its Wednesday total is wrong']);
ok($r['code'] === 200 && one('SELECT override_by FROM imports WHERE id = :i', ['i' => $u2]) === 27 && (float) one("SELECT value FROM share_counts WHERE import_id = :i AND kind = 'cede'", ['i' => $u2]) === 1710.0,
    'the manager accepts it: its rows usable, its Cede figure written');
$l = json_decode((string) one("SELECT after FROM activity_log WHERE action = 'upload.override' AND id > :s", ['s' => $since]), true);
ok(isset($l['note_length']) && !str_contains(json_encode($l), 'DTC confirmed'), 'upload.override logged, the note\'s length never its words');
ok(post_json($jM, '/uploads/override.php', ['upload' => $u2, 'note' => 'again'])['code'] === 422, 'a second override: 422');

echo "5. The delete, the restore and the overlapped week\n";
ok((float) one("SELECT shares FROM participant_positions WHERE security_id = :s AND position_date = '2026-09-11' AND participant_id = (SELECT id FROM participants WHERE number = '0164')", ['s' => $sec]) === 1300.0
    && (int) one('SELECT superseded_by FROM imports WHERE id = :i', ['i' => $u1]) === $u2, 'week 2\'s Opening (Sept 11) replaced week 1\'s Friday — the same figure — and marked week 1 overlapped');
ok(post_json($jP, '/uploads/delete.php', ['upload' => $u2])['code'] === 403, 'an Analyst may not delete an upload (403)');
$r = post_json($jM, '/uploads/delete.php', ['upload' => $u2]);
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM participant_positions WHERE import_id = :i', ['i' => $u2]) === 0 && (int) one('SELECT count(*) FROM share_counts WHERE import_id = :i', ['i' => $u2]) === 0,
    'the delete takes its positions and its Cede figure');
ok((int) one("SELECT import_id FROM participant_positions WHERE security_id = :s AND position_date = '2026-09-11' AND participant_id = (SELECT id FROM participants WHERE number = '0164')", ['s' => $sec]) === $u1
    && one('SELECT superseded_by FROM imports WHERE id = :i', ['i' => $u1]) === null, '…and week 1 loads Sept 11 again (no longer overlapped)');
ok((int) one('SELECT count(*) FROM spr_rows WHERE import_id = :i', ['i' => $u2]) === 18, 'its raw rows stay for the restore');
$d = json_decode(page($jM, '/uploads?status=deleted&issuer=' . $ref['issuer'], ['headers' => ['Accept: application/json']])['body'], true)['data']['uploads'] ?? [];
ok(in_array($u2, array_column($d, 'import_id'), true), 'the manager sees it in the tombstone');
$r = post_json($jM, '/uploads/restore.php', ['upload' => $u2]);
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM participant_positions WHERE import_id = :i', ['i' => $u2]) === 18, 'the restore reloads its positions from its rows');
ok(post_json($jM, '/uploads/restore.php', ['upload' => $u2])['code'] === 422, 'a restore of one not in the tombstone: 422');

echo "6. Read again\n";
$since = last_activity_id();
$r = post_json($jP, '/uploads/reparse.php', ['upload' => $u1]);
ok($r['code'] === 200 && one('SELECT tied FROM imports WHERE id = :i', ['i' => $u1]) === true && (int) one('SELECT count(*) FROM spr_rows WHERE import_id = :i', ['i' => $u1]) === 18
    && (int) one("SELECT count(*) FROM activity_log WHERE action = 'upload.reparse' AND id > :s", ['s' => $since]) === 1, 'upload_reparse: the kept file read again, the rows replaced, still tied, logged');

echo "7. A large file is queued and finished by the worker\n";
$big = spr_csv('09175A107', ['2026-09-18', '2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25'], $rows2);
$big = str_replace("Participant,Participant", str_repeat(",,,,,,,,,,\n", 520000) . "Participant,Participant", $big);
ok(strlen($big) > 5 * 1024 * 1024, sprintf('a %.1f MB report (blank lines padding it)', strlen($big) / 1048576));
$r = upload($jP, ['name' => 'big-week.csv', 'bytes' => $big], ['security' => $sec]);
$ub = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && ($r['json']['queued'] ?? null) === true && one('SELECT status FROM imports WHERE id = :i', ['i' => $ub]) === 'queued' && str_contains($r['json']['location'] ?? '', 'notice=queued'), 'queued, not read in the request' . (($r['json']['queued'] ?? null) === true ? '' : ' — ' . $r['code'] . ' ' . substr($r['body'], 0, 200)));
ok(str_contains(page($jP, '/uploads/' . $ub)['body'], 'hx-trigger="every 2s"'), 'its page polls its status');
$w = worker('uploads', '2026-10-07T10:00:00-04:00');
ok(($w['report']['ran']['uploads']['tied'] ?? 0) === 1 && one('SELECT tied FROM imports WHERE id = :i', ['i' => $ub]) === true, 'the worker\'s uploads pass reads it: tied');
ok((int) one("SELECT count(*) FROM notifications WHERE member_id = 26 AND kind = 'upload_done' AND record_id = :i", ['i' => $ub]) === 1, 'the uploader is told it is done');
$s = req('GET', '/uploads/' . $ub . '/status', ['jar' => $jP, 'headers' => ['HX-Request: true']]);
ok(str_contains($s['body'], 'tied') && preg_match('/^HX-Refresh: true/mi', $s['headers']) === 1, 'the status fragment says tied and refreshes the page');

echo "8. A transfer agent's extract is stored without its personal columns\n";
$extract = "\"CUSIP\",\"TAX-ID\",\"ACCT#\",\"TAX-ID 2\",\"HOLDER REG 1\",\"CITY\",\"DATE OF BIRTH\",\"PHONE NUMBER\",\"EMAIL ADDRESS\",\"OWNERSHIP TYPE\",\"TOTAL SHARES\"\n"
         . "\"09175A107\",\"=\"\"123-45-6789\"\"\",\"=\"\"210\"\"\",\"\",\"SMOKE A PERSON\",\"RENO\",\"01/02/1960\",\"555-0100\",\"a@example.invalid\",\"INDIVIDUAL\",\"100\"\n";
$out = app_php('require_once ' . var_export(getcwd() . '/app/si/storage.php', true) . '; $t = (array) db()->query("SELECT mapping->\'drop_columns\' AS d FROM import_templates WHERE connector = \'ta_extract_csv\'")->fetch(); echo json_encode(si_strip_csv_columns(' . var_export($extract, true) . ', json_decode($t[\'d\'], true)));');
ok(is_string($out) && !str_contains($out, '123-45-6789') && !str_contains($out, '1960') && !str_contains($out, '555-0100') && !str_contains($out, 'example.invalid') && str_contains($out, 'ACCT#') && str_contains($out, 'TOTAL SHARES') && str_contains($out, 'SMOKE A PERSON'),
    'the stored copy keeps the account, the registration and the shares — never a tax id, a date of birth, a phone or an email');

echo "9. Who may upload\n";
ok(page($jV, '/uploads')['code'] === 200 && page($jV, '/uploads/' . $u1)['code'] === 200 && page($jV, '/uploads/new')['code'] === 403, 'a Viewer reads the uploads and may not open the form (403)');
ok(upload($jV, ['name' => 'v.csv', 'bytes' => spr_csv('09175A107', $wk1, $rows1)], ['security' => $sec])['code'] === 403, '…nor upload (403)');
ok(post_json($jV, '/uploads/reparse.php', ['upload' => $u1])['code'] === 403 && post_json($jV, '/reconciliation/run.php', ['security' => $sec])['code'] === 403, '…nor read one again, nor run the reconciliation');
kernel_state(function ($s) { $s['facts']['601'] = ['valid' => true, 'member_id' => 42, 'run_id' => 601, 'request_id' => 'req-601', 'is_eval' => false]; return $s; });
$rt = as_agent(run_token(42, 601));
ok(req('POST', '/uploads/save.php', ['headers' => $rt, 'form' => ['kind' => 'spr', 'security' => $sec]])['code'] === 403, 'an agent may not upload (people only): 403');
ok(req('POST', '/uploads/reparse.php', ['headers' => $rt, 'form' => ['upload' => $u1]])['code'] === 200, 'an agent holding Analyst may read one again');
ok(page(jar(), '/uploads/' . $u1, ['headers' => $rt])['code'] === 200, '…and read it');
$other = (int) one("SELECT id FROM issuers WHERE ticker = 'SMNC'");
q('INSERT INTO issuer_members (issuer_id, member_id) VALUES (:i, 30)', ['i' => $ref['issuer']]);
[$jP2, ] = sign_on(26);
ok(page($jP2, '/uploads/' . $u1)['code'] === 404 && upload($jP2, ['name' => 'w.csv', 'bytes' => spr_csv('09175A107', $wk1, $rows1)], ['security' => $sec])['code'] === 422, 'the issuer restricted away from her: its upload is a 404 and she may not upload to it');
q('DELETE FROM issuer_members WHERE issuer_id = :i', ['i' => $ref['issuer']]);
finish();
