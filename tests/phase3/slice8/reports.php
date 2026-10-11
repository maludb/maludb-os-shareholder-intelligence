<?php
/**
 * Proof: the pack (events-reports.md) — drafted (by an Analyst, by an agent's token), edited while a draft (period, sections, the Markdown narrative), rendered (HTML always; the PDF only when a
 * renderer is named — here a stand-in script), approved by a manager (an Analyst refused, an agent's approve paused: the registry), the narrative frozen after (in PHP and in the database), the
 * rendering kept as approved; sent to members through MaluMail (a draft refused; an agent, an outsider's address, someone who may not see the issuer and an external member off the list refused;
 * a suppressed address counted as failed); the file served after the walls; the worker renders an approved pack that lost its rendering; delete to the tombstone; the Viewer sees no draft.
 */
require __DIR__ . '/lib.php';
$ids = seed8();
$alpha = $ids['alpha'];
$sec = $ids['security'];
$jP = person(26);
$jB = person(28);
$jM = person(27);
$jV = person(29);
$json = ['headers' => ['Accept: application/json']];
$today = date('Y-m-d');
$from = date('Y-m-d', strtotime('-45 days'));

// a second Security Position Report date, so the movement has something to compare
q('INSERT INTO participant_positions (security_id, participant_id, position_date, shares, import_id) SELECT security_id, participant_id, position_date - 7, shares - 500000, import_id FROM participant_positions WHERE security_id = :s AND position_date = CURRENT_DATE - 3', ['s' => $sec]);

echo "1. Draft a pack\n";
$since = last_activity_id();
$r = post_json($jP, '/reports/save.php', ['issuer' => $alpha, 'kind' => 'weekly_pack', 'period_from' => $from, 'period_to' => $today, 'narrative' => "# The week\n\nShort interest is **elevated**; see the [filing](https://example.invalid/f).\n\n- the SMOKE report was answered\n- <b>raw html</b> stays text"]);
$rep = rid($r);
$row = q('SELECT * FROM reports WHERE id = :i', ['i' => $rep])[0] ?? [];
ok($r['code'] === 200 && $rep > 0 && ($row['status'] ?? '') === 'draft' && (int) $row['drafted_by'] === 26 && $row['kind'] === 'weekly_pack' && count(json_decode((string) $row['sections'], true)) === 13 && str_contains($row['title'], 'Weekly pack'),
    'report_draft: a draft by Priya, all thirteen sections by default, a title made from the kind and the period, record_id the report');
$a = activity('report.draft', $since)[0] ?? [];
ok((int) ($a['entity_id'] ?? 0) === $rep && json_decode($a['after'], true)['sections'] === 13 && !str_contains((string) $a['after'], 'elevated'), 'report.draft logged with the kind, the period and the section count — never the narrative');
$bad = [
    'no period' => [['issuer' => $alpha], 'period_from'],
    'a period that ends before it begins' => [['issuer' => $alpha, 'period_from' => $today, 'period_to' => $from], 'period_to'],
    'a kind not on the list' => [['issuer' => $alpha, 'period_from' => $from, 'period_to' => $today, 'kind' => 'gossip'], 'kind'],
    'an unknown section' => [['issuer' => $alpha, 'period_from' => $from, 'period_to' => $today, 'sections' => 'movement,astrology'], 'sections'],
    'a narrative too long' => [['issuer' => $alpha, 'period_from' => $from, 'period_to' => $today, 'narrative' => str_repeat('x', 20001)], 'narrative'],
];
$wrong = [];
foreach ($bad as $label => [$form, $field]) {
    $x = post_json($jP, '/reports/save.php', $form);
    if (!($x['code'] === 422 && isset($x['json']['error']['fields'][$field]))) { $wrong[] = "$label → {$x['code']}"; }
}
ok($wrong === [], 'five refusals: 422 with the sentence on the field' . ($wrong ? ': ' . implode(' | ', $wrong) : ''));

echo "2. The page: narrative, thirteen sections drawn from the data\n";
$h = page($jP, '/reports/' . $rep)['body'];
ok(str_contains($h, 'id="report-narrative"') && str_contains($h, '<h3>The week</h3>') && str_contains($h, '<strong>elevated</strong>') && str_contains($h, '&lt;b&gt;raw html&lt;/b&gt; stays text') && !str_contains($h, '<b>raw html</b>'), 'the narrative is rendered from Markdown; the author\'s HTML is text');
$missing = array_filter(array_keys(['movement' => 1, 'flow' => 1, 'top_participants' => 1, 'holders_ranges' => 1, 'holders_geography' => 1, 'thirteen_f' => 1, 'beneficial' => 1, 'insiders' => 1, 'short_side' => 1, 'fails_threshold' => 1, 'reconciliation' => 1, 'signals' => 1, 'events' => 1]),
    static fn ($k) => !str_contains($h, 'id="pack-section-' . $k . '"'));
ok($missing === [] && str_contains($h, 'id="report-sections"'), 'all thirteen sections are in the page' . ($missing ? ' — missing ' . implode(', ', $missing) : ''));
ok(str_contains($h, 'SMOKE Frank Person') === false && str_contains($h, 'Individual holder') === false, 'and no natural person\'s name is in the pack');
ok(preg_match('/Largest participants.*?<table>.*?6,000,000/s', $h) === 1, 'the largest participants: 6,000,000 shares on the top row (the SPR on record)');
ok(str_contains($h, 'DTC\'s total went from') || str_contains($h, 'DTC&#039;s total went from'), 'the movement compares the two reports (the total from … to …)');
ok(str_contains($h, 'SMOKE: short interest is 20.0% of the float') && str_contains($h, 'SMOKE: a short report is published') && str_contains($h, '3,000,000'), 'the signal, the event and the short interest are drawn from the data');
ok(str_contains($h, 'Source:'), 'every number has its source line');
ok(str_contains($h, 'id="report-edit-link"') && str_contains($h, 'id="report-render-btn"') && !str_contains($h, 'id="report-approve-btn"') && !str_contains($h, 'id="report-send-form"'), 'an Analyst sees Edit and Render — no Approve, no Send');
$hm = page($jM, '/reports/' . $rep)['body'];
ok(str_contains($hm, 'id="report-approve-btn"') && !str_contains($hm, 'id="report-send-form"') && str_contains($hm, 'id="report-delete-btn"'), 'the IR Manager sees Approve and Delete; the send form waits for an approval');

echo "3. Edit while a draft\n";
$since = last_activity_id();
$r = post_json($jB, '/reports/save.php', ['report' => $rep, 'sections' => 'short_side,signals,events,reconciliation', 'title' => 'SMOKE: the week in four sections']);
$row = q('SELECT * FROM reports WHERE id = :i', ['i' => $rep])[0];
ok($r['code'] === 200 && rid($r) === $rep && json_decode((string) $row['sections'], true) === ['short_side', 'reconciliation', 'signals', 'events'] && $row['title'] === 'SMOKE: the week in four sections' && str_contains((string) $row['narrative'], 'elevated'),
    'another Analyst (Bea) changes the sections and the title; the narrative stays');
$a = activity('report.update', $since)[0] ?? [];
ok(json_decode($a['after'], true)['fields_changed'] === ['title', 'sections'] || json_decode($a['after'], true)['fields_changed'] === ['sections', 'title'], 'report.update logged the fields changed');
$h = page($jP, '/reports/' . $rep)['body'];
ok(substr_count($h, 'id="pack-section-') === 4 && !str_contains($h, 'id="pack-section-movement"'), 'the page draws the four chosen sections only');
ok(str_contains(page($jP, '/reports/' . $rep . '/edit')['body'], 'id="report-form-section-short_side"') && post_json($jV, '/reports/save.php', ['report' => $rep, 'title' => 'x'])['code'] === 403, 'the edit form has the section checkboxes; a Viewer cannot edit (403)');
post_json($jP, '/reports/save.php', ['report' => $rep, 'sections' => implode(',', ['movement', 'flow', 'top_participants', 'holders_ranges', 'holders_geography', 'thirteen_f', 'beneficial', 'insiders', 'short_side', 'fails_threshold', 'reconciliation', 'signals', 'events'])]);

echo "4. A draft is the Analysts' alone\n";
ok(page($jV, '/reports/' . $rep)['code'] === 404 && !str_contains(page($jV, '/reports')['body'], 'id="report-card-' . $rep . '"') && page($jV, '/reports/' . $rep . '/file')['code'] === 404, 'a Viewer: the draft\'s page, its card and its file do not exist');
ok(str_contains(page($jP, '/reports')['body'], 'id="report-card-' . $rep . '"') && str_contains(page($jP, '/reports')['body'], 'data-status="draft"'), 'an Analyst sees the draft as a card with its status');

echo "5. Render — HTML always, the PDF when a renderer is named\n";
$since = last_activity_id();
$r = post_json($jP, '/reports/render.php', ['report' => $rep]);
$row = q('SELECT * FROM reports WHERE id = :i', ['i' => $rep])[0];
ok($r['code'] === 200 && rid($r) === $rep && $row['rendered_path'] !== null && str_starts_with($row['rendered_path'], 'reports/' . $rep . '-') && $row['pdf_path'] === null && ($r['json']['pdf'] ?? null) === false, 'report_render: the HTML kept under the store; no renderer named, no PDF');
$a = activity('report.render', $since)[0] ?? [];
ok(json_decode($a['after'], true)['html'] === true && json_decode($a['after'], true)['pdf'] === false, 'report.render logged: html yes, pdf no');
$f = req('GET', '/reports/' . $rep . '/file?format=html', ['jar' => $jP]);
ok($f['code'] === 200 && str_starts_with(ltrim($f['body']), '<!doctype html>') && str_contains($f['body'], '<strong>elevated</strong>') && str_contains($f['body'], 'id="pack-section-signals"') && preg_match('/^Content-Security-Policy: default-src \'none\'/mi', $f['headers']) === 1 && stripos($f['headers'], 'text/html') !== false,
    'the stored HTML is served after the walls: a stand-alone document with the narrative and the sections, under a policy that runs nothing');
ok(req('GET', '/reports/' . $rep . '/file?format=pdf', ['jar' => $jP])['code'] === 404 && str_contains(req('GET', '/reports/' . $rep . '/file?format=pdf', ['jar' => $jP])['body'], 'no PDF'), 'the PDF: 404 in words — the renderer is not installed');
ok(page(jar(), '/reports/' . $rep . '/file')['code'] === 302, 'a file with no session: the launcher');
$fake = need('SI_DEV_STATE') . '/fake-wkhtmltopdf.sh';
file_put_contents($fake, "#!/bin/sh\nfor last; do :; done\nprintf '%%PDF-1.4 stand-in' > \"\$last\"\n");
chmod($fake, 0755);
$out = app_php("require_once " . var_export(dirname(__DIR__, 3) . '/app/features/reports/screen.php', true) . ";\nputenv('SI_WKHTMLTOPDF=" . $fake . "');\n\$o = as_member(\$pdo, 27, fn () => render_pack(\$pdo, $rep));\necho json_encode(['pdf' => \$o['pdf_path'], 'exists' => is_file(si_store_root() . '/' . \$o['pdf_path']), 'html' => str_contains(\$o['html'], 'pack-section-signals')]);");
if (!is_array($out)) { fwrite(STDERR, 'app_php: ' . substr((string) $out, 0, 400) . "\n"); $out = []; }
ok(is_array($out) && str_starts_with((string) ($out['pdf'] ?? ''), 'reports/' . $rep . '-') && ($out['exists'] ?? false) && ($out['html'] ?? false), 'with a renderer named (a stand-in script), render_pack also stores the PDF beside the HTML');
q('UPDATE reports SET pdf_path = :p WHERE id = :i', ['p' => $out['pdf'], 'i' => $rep]);
$f = req('GET', '/reports/' . $rep . '/file?format=pdf', ['jar' => $jP]);
ok($f['code'] === 200 && str_starts_with($f['body'], '%PDF') && stripos($f['headers'], 'application/pdf') !== false && stripos($f['headers'], 'attachment') !== false, 'the PDF is a download');
ok(str_contains(page($jP, '/reports/' . $rep)['body'], 'id="report-download-pdf"') && str_contains(page($jP, '/reports/' . $rep)['body'], 'id="report-download-html"'), 'the page offers both downloads');
q('UPDATE reports SET pdf_path = NULL WHERE id = :i', ['i' => $rep]);

echo "6. Approve — the manager's; the narrative frozen\n";
$ah = agent_headers(42, 961);
ok(post_json($jP, '/reports/approve.php', ['report' => $rep])['code'] === 403, 'an Analyst cannot approve: 403');
$r = agent_post($ah, '/reports/approve.php', ['report' => $rep]);
ok($r['code'] === 403 && one('SELECT status FROM reports WHERE id = :i', ['i' => $rep]) === 'draft', 'the Surveillance Analyst (an agent holding Analyst) cannot approve either');
$ap = array_column(json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true)['approvals'] ?? [], 'category', 'action');
ok(($ap['report_approve'] ?? '') === 'other' && ($ap['report_send'] ?? '') === 'external_send' && ($ap['report_delete'] ?? '') === 'deletion' && !isset($ap['report_draft']) && !isset($ap['report_update']) && !isset($ap['report_render']),
    'for an agent holding the right, approve (other), send (external_send) and delete (deletion) pause; draft, update and render do not');
$since = last_activity_id();
$old = $row['rendered_path'];
$r = post_json($jM, '/reports/approve.php', ['report' => $rep]);
$row = q('SELECT * FROM reports WHERE id = :i', ['i' => $rep])[0];
ok($r['code'] === 200 && rid($r) === $rep && $row['status'] === 'approved' && (int) $row['approved_by'] === 27 && $row['approved_at'] !== null, 'the IR Manager approves: approved, by Marco, at a time');
ok($row['rendered_path'] !== null && $row['rendered_path'] !== $old && !is_file(need('SI_DEV_STATE') . '/storage/' . $old), 'approval rendered the pack again (the numbers it was approved on) and removed the draft\'s older file');
ok(activity('report.approve', $since) !== [] && (int) one("SELECT count(*) FROM notifications WHERE member_id = 26 AND kind = 'pack_approved' AND record_id = :r", ['r' => $rep]) === 1, 'report.approve logged; the drafter (Priya) is told');
$r = post_json($jP, '/reports/save.php', ['report' => $rep, 'narrative' => 'changed after approval']);
ok($r['code'] === 422 && str_contains($r['body'], 'frozen') && str_contains((string) one('SELECT narrative FROM reports WHERE id = :i', ['i' => $rep]), 'elevated'), 'the narrative of an approved pack: 422, frozen');
$r = post_json($jP, '/reports/save.php', ['report' => $rep, 'sections' => 'events']);
ok($r['code'] === 422 && count(json_decode((string) one('SELECT sections FROM reports WHERE id = :i', ['i' => $rep]), true)) === 13, 'its sections do not change either');
// the owner, 2026-10-11: a renderer installed after the approval — the worker makes the approved pack's PDF from its STORED HTML, the content unchanged
$html0 = (string) one('SELECT rendered_path FROM reports WHERE id = :i', ['i' => $rep]);
$since = last_activity_id();
$late = static function () use ($fake): array {
    exec('SI_WKHTMLTOPDF=' . escapeshellarg($fake) . ' SI_WORKER_NOW=' . escapeshellarg('2026-10-12T09:00:00-04:00') . ' php bin/worker.php --passes=packs 2>&1', $o);
    return json_decode((string) end($o), true)['ran']['packs'] ?? [];
};
$w = $late();
$row = q('SELECT * FROM reports WHERE id = :i', ['i' => $rep])[0];
ok(($w['pdf_late'] ?? 0) === 1 && $row['pdf_path'] !== null && is_file(need('SI_DEV_STATE') . '/storage/' . $row['pdf_path']) && $row['rendered_path'] === $html0 && str_contains((string) $row['narrative'], 'elevated'),
    'a renderer installed later: the worker makes the approved pack\'s PDF from its stored HTML — the HTML and the narrative unchanged');
$a = activity('report.render', $since)[0] ?? [];
ok(($a['after'] ?? '') !== '' && json_decode($a['after'], true)['late'] === true && json_decode($a['after'], true)['pdf'] === true, 'report.render logged as a late PDF');
ok(($late()['pdf_late'] ?? -1) === 0, 'the next pass makes none: the pack has its PDF');
q('UPDATE reports SET pdf_path = NULL WHERE id = :i', ['i' => $rep]);                   // back to no PDF for the send's checks below
$frozen = false;
try { pdo()->exec("UPDATE reports SET narrative = 'sneaked in' WHERE id = $rep"); } catch (PDOException $e) { $frozen = str_contains($e->getMessage(), 'frozen'); }
ok($frozen, 'and the database refuses the change by itself (the trigger): "An approved pack\'s narrative is frozen"');
ok(post_json($jM, '/reports/approve.php', ['report' => $rep])['code'] === 422 && post_json($jM, '/reports/render.php', ['report' => $rep])['code'] === 422, 'approving it again, or rendering it again: 422 — the approved rendering is kept');
$h = page($jP, '/reports/' . $rep)['body'];
ok(str_contains($h, 'id="report-frozen"') && !str_contains($h, 'id="report-edit-link"') && !str_contains($h, 'id="report-send-form"') && str_contains($h, 'Approved by SMOKE Marco'), 'the page: frozen, approved by Marco, no Edit, no Send for an Analyst');
ok(page($jP, '/reports/' . $rep . '/edit')['code'] === 422, 'and the edit form is refused');
ok(page($jV, '/reports/' . $rep)['code'] === 200 && req('GET', '/reports/' . $rep . '/file?format=html', ['jar' => $jV])['code'] === 200 && !str_contains(page($jV, '/reports/' . $rep)['body'], 'id="report-send-form"'), 'a Viewer reads the approved pack and downloads its HTML; no buttons');

echo "7. Send to members\n";
$hm = page($jM, '/reports/' . $rep)['body'];
ok(str_contains($hm, 'id="report-send-form"') && substr_count($hm, 'name="recipients[]"') === 3 && str_contains($hm, 'data-picker-source="member"') && !str_contains($hm, '<select name="recipients'), 'the manager\'s send form: three recipients chosen through the member picker');
$draft2 = rid(post_json($jP, '/reports/save.php', ['issuer' => $alpha, 'period_from' => $from, 'period_to' => $today, 'title' => 'SMOKE: a second draft']));
mail_reset();
$r = post_json($jM, '/reports/send.php', ['report' => $draft2, 'recipients' => '26']);
ok($r['code'] === 422 && str_contains($r['body'], 'Approve the pack') && mail_log() === [], 'a draft is not sent: 422, no mail');
ok(post_json($jP, '/reports/send.php', ['report' => $rep, 'recipients' => '28'])['code'] === 403 && mail_log() === [], 'an Analyst cannot send: 403, no mail');
$tests = [
    'an address outside the members' => ['outsider@example.com', 'not to an address'],
    'a person who is not a member' => ['999999', 'not a member'],
    'an agent' => ['40', 'is an agent'],
    'an external member the issuer\'s list does not name' => ['29', 'outside the business'],
];
$wrong = [];
foreach ($tests as $label => [$rcp, $words]) {
    $x = post_json($jM, '/reports/send.php', ['report' => $rep, 'recipients' => $rcp]);
    if (!($x['code'] === 422 && str_contains($x['body'], $words))) { $wrong[] = "$label → {$x['code']} " . substr($x['body'], 0, 120); }
}
ok($wrong === [] && mail_log() === [], 'four refusals, each in words, and not a message sent' . ($wrong ? ': ' . implode(' | ', $wrong) : ''));
$x = post_json($jM, '/reports/send.php', ['report' => $rep, 'recipients' => '26,28,999999']);
ok($x['code'] === 422 && mail_log() === [], 'one bad recipient among good ones refuses the whole send before any mail');
$since = last_activity_id();
$r = post_json($jM, '/reports/send.php', ['report' => $rep, 'recipients' => '26,28', 'message' => 'For Thursday\'s call.']);
$m = mail_log();
ok($r['code'] === 200 && rid($r) === $rep && ($r['json']['sent'] ?? 0) === 2 && ($r['json']['failed'] ?? -1) === 0 && count($m) === 2 && array_column($m, 'to') === ['priya@example.invalid', 'bea@example.invalid'],
    'report_send to two members: two MaluMail messages, one each, to their own addresses (the fake MaluMail)');
ok(str_contains($m[0]['subject'], 'SMOKE Alpha Corp') && str_contains($m[0]['subject'], 'the week in four sections') && str_contains($m[0]['text'], 'Thursday') && str_contains($m[0]['text'], 'elevated') && str_contains($m[0]['text'], BASE . '/reports/' . $rep) && str_contains($m[0]['html'], '<strong>elevated</strong>') && str_contains($m[0]['html'], 'href="' . BASE . '/reports/' . $rep . '"') && !isset($m[0]['attachments']) && $m[0]['from'] === 'shareholders@example.invalid',
    'each carries the subject, the sender\'s message, the narrative and a link to the pack in the application — no attachment (MaluMail\'s send takes none)');
$a = activity('report.send', $since)[0] ?? [];
$aa = json_decode($a['after'] ?? '{}', true);
ok($aa['recipients'] === 2 && $aa['failed'] === 0 && $aa['pdf'] === false && !str_contains((string) $a['after'], 'example.invalid') && !str_contains((string) $a['after'], 'Thursday'), 'report.send logged {recipients: 2, failed: 0, pdf: false} — no address, no message');
ok(one('SELECT status FROM reports WHERE id = :i', ['i' => $rep]) === 'published' && str_contains(page($jM, '/reports/' . $rep . '?notice=sent&count=2&failed=0')['body'], 'Sent the pack to 2 members'), 'the pack is published; the page says whom it went to');
// someone the issuer's list does not name
q('INSERT INTO issuer_members (issuer_id, member_id, added_by) VALUES (:i, 29, 27)', ['i' => $alpha]);
mail_reset();
$x = post_json($jM, '/reports/send.php', ['report' => $rep, 'recipients' => '26']);
ok($x['code'] === 422 && str_contains($x['body'], 'may not see this issuer') && mail_log() === [], 'with a list on the issuer, a member off it (Priya) is refused: she may not see it');
$x = post_json($jM, '/reports/send.php', ['report' => $rep, 'recipients' => '29']);
ok($x['code'] === 200 && array_column(mail_log(), 'to') === ['ann@client.invalid'], 'an external member the list names (Ann, the advisory firm\'s client) is admitted');
q('DELETE FROM issuer_members WHERE issuer_id = :i', ['i' => $alpha]);
// MaluMail refuses an address (suppressed): counted as failed, never as sent
q("UPDATE members SET email = 'lee-suppressed@example.invalid' WHERE id = 31");
mail_reset();
$x = post_json($jM, '/reports/send.php', ['report' => $rep, 'recipients' => '26,31']);
ok($x['code'] === 200 && ($x['json']['sent'] ?? 0) === 1 && ($x['json']['failed'] ?? 0) === 1, 'one suppressed address among two: one sent, one failed');
$x = post_json($jM, '/reports/send.php', ['report' => $rep, 'recipients' => '31']);
ok($x['code'] === 422 && str_contains($x['body'], 'accepted no recipient'), 'only a suppressed address: 422, nothing sent');
q("UPDATE members SET email = 'lee@example.invalid' WHERE id = 31");

echo "8. The worker renders an approved pack that has no rendering\n";
q('UPDATE reports SET rendered_path = NULL WHERE id = :i', ['i' => $rep]);
$w = worker('packs', date('c'));
ok($w['rc'] === 0 && ($w['report']['ran']['packs']['rendered'] ?? 0) === 1 && one('SELECT rendered_path FROM reports WHERE id = :i', ['i' => $rep]) !== null && req('GET', '/reports/' . $rep . '/file?format=html', ['jar' => $jV])['code'] === 200,
    'the packs pass renders it as its approver, and the file is served again');
$w = worker('packs', date('c'));
ok(($w['report']['ran']['packs']['rendered'] ?? -1) === 0, 'and the next pass has nothing to do (a draft is never rendered by the worker)');

echo "9. An agent drafts; the IR Managers are told\n";
$r = agent_post($ah, '/reports/save.php', ['issuer' => $alpha, 'period_from' => $from, 'period_to' => $today, 'narrative' => 'Drafted by the Surveillance Analyst.']);
$rep3 = rid($r);
ok($r['code'] === 200 && $rep3 > 0 && (int) one('SELECT drafted_by FROM reports WHERE id = :i', ['i' => $rep3]) === 42 && one('SELECT status FROM reports WHERE id = :i', ['i' => $rep3]) === 'draft', 'report_draft under the agent\'s run token: a draft by the agent');
ok((int) one("SELECT count(*) FROM notifications WHERE member_id = 27 AND kind = 'pack_drafted' AND record_id = :r", ['r' => $rep3]) === 1 && (int) one("SELECT count(*) FROM notifications WHERE member_id = 26 AND kind = 'pack_drafted'") === 0, 'Marco is told a pack waits for his approval; the Analyst is not');
ok(str_contains(page($jM, '/reports')['body'], 'Drafted by SMOKE Surveillance Analyst (agent)'), 'the card says it was drafted by an agent');
$pick = req('GET', '/pick/?source=report&issuer=' . $alpha, ['jar' => $jP])['body'];
ok(str_contains($pick, 'the week in four sections') && str_contains($pick, 'SMOKE: a second draft'), 'the report picker lists the packs');
ok(!str_contains(req('GET', '/pick/?source=report&issuer=' . $alpha, ['jar' => $jV])['body'], 'a second draft'), 'a Viewer\'s picker has no draft');

echo "10. Delete to the tombstone\n";
ok(post_json($jP, '/reports/delete.php', ['report' => $draft2])['code'] === 403, 'an Analyst cannot delete a pack: 403');
$since = last_activity_id();
$r = post_json($jM, '/reports/delete.php', ['report' => $draft2]);
ok($r['code'] === 200 && one('SELECT deleted_at IS NOT NULL FROM reports WHERE id = :i', ['i' => $draft2]) === true && page($jP, '/reports/' . $draft2)['code'] === 404 && activity('report.delete', $since) !== [], 'the manager deletes a draft: tombstoned, gone from the Analyst\'s view, logged');
ok(post_json($jM, '/reports/delete.php', ['report' => $draft2])['code'] === 404, 'deleting it again: 404');

echo "11. JSON, the wall, the registry\n";
$d = json_decode(page($jP, '/reports', $json)['body'], true)['data'] ?? [];
$one = array_values(array_filter($d['reports'] ?? [], static fn ($x) => $x['report_id'] === $rep))[0] ?? [];
ok(($d['issuer_id'] ?? 0) === $alpha && ($one['status'] ?? '') === 'published' && ($one['approved_by_name'] ?? '') === 'SMOKE Marco' && ($one['rendered'] ?? false) === true && !array_key_exists('narrative', $one) && !array_key_exists('rendered_path', $one), 'the list answers JSON: status, approver, rendered — no narrative, no path in the list');
$d = json_decode(page($jP, '/reports/' . $rep, $json)['body'], true)['data'] ?? [];
ok(($d['report']['narrative'] ?? '') !== '' && count($d['report']['sections'] ?? []) === 13 && ($d['sections_as'] ?? '') === 'rendered at approval' && !array_key_exists('rendered_path', $d['report']), 'one pack as JSON: the narrative, the sections, "rendered at approval", no path');
$bp = (int) q("INSERT INTO reports (issuer_id, kind, title, period_from, period_to, status, approved_by, approved_at) VALUES (:i, 'custom', 'SMOKE: Beta pack', '2024-01-01', '2024-01-07', 'approved', 27, now()) RETURNING id", ['i' => $ids['beta']])[0]['id'];
ok(page($jP, '/reports/' . $bp)['code'] === 404 && page($jV, '/reports/' . $bp)['code'] === 404 && post_json($jP, '/reports/save.php', ['report' => $bp, 'title' => 'x'])['code'] === 404, 'a pack of an issuer behind the wall does not exist to those off its list: its page and its edit are 404');
ok(page(sign_on(30)[0], '/reports/' . $bp)['code'] === 200, 'Dana, on that issuer\'s list, reads it');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$screens = ['report-list', 'report-view', 'report-add', 'report-edit'];
$actions = ['report_draft', 'report_update', 'report_render', 'report_approve', 'report_send', 'report_delete'];
ok(array_filter($screens, static fn ($s) => empty($reg['screens'][$s]['built'])) === [] && array_filter($actions, static fn ($a) => empty($reg['actions'][$a]['built'])) === [], 'the registry marks the four screens and the six actions built');
finish();
