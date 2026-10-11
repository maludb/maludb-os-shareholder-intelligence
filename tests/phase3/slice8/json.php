<?php
/**
 * Proof: JSON mode on every slice 8 screen ({data}: the timeline with what is coming, one event, the forms, the month, the packs, one pack, the exports) and handler under a person's action token
 * ({ok, did, record_id, location}); a refusal is a 422 in JSON; the registry: ten screens and eleven actions built, four approvals in step with the manifest.
 */
require __DIR__ . '/lib.php';
$ids = seed8();
$alpha = $ids['alpha'];
$sec = $ids['security'];
$jP = person(26);
$jM = person(27);
$json = ['headers' => ['Accept: application/json']];
$today = date('Y-m-d');
$from = date('Y-m-d', strtotime('-30 days'));
$hp = ['Accept: application/json', 'X-Action-Token: ' . person_token(26)];
$hm = ['Accept: application/json', 'X-Action-Token: ' . person_token(27)];
$post = static fn (array $hdr, string $path, array $form): array => req('POST', $path, ['headers' => $hdr, 'form' => $form]);
$shape = static fn (array $r): bool => $r['code'] === 200 && (($b = json_decode($r['body'], true) ?: [])['ok'] ?? false) === true && isset($b['did'], $b['location']) && array_key_exists('record_id', $b);
$rid_ = static fn (array $r): int => (int) (json_decode($r['body'], true)['record_id'] ?? 0);

echo "1. Every handler under a person's action token\n";
$r1 = $post($hp, '/events/save.php', ['issuer' => $alpha, 'on_date' => $today, 'kind' => 'note', 'title' => 'SMOKE: json event']);
$ev = $rid_($r1);
$r2 = $post($hp, '/events/save.php', ['event' => $ev, 'severity' => 'watch']);
$r3 = $post($hp, '/events/delete.php', ['event' => $ev]);
$r4 = $post($hp, '/events/restore.php', ['event' => $ev]);
$r5 = $post($hp, '/reports/save.php', ['issuer' => $alpha, 'period_from' => $from, 'period_to' => $today, 'narrative' => 'json pack']);
$rep = $rid_($r5);
$r6 = $post($hp, '/reports/save.php', ['report' => $rep, 'sections' => 'events,signals']);
$r7 = $post($hp, '/reports/render.php', ['report' => $rep]);
$r8 = $post($hm, '/reports/approve.php', ['report' => $rep]);
mail_reset();
$r9 = $post($hm, '/reports/send.php', ['report' => $rep, 'recipients' => '26']);
$r10 = $post($hm, '/reports/delete.php', ['report' => $rep]);
$r11 = $post($hp, '/exports/create.php', ['security' => $sec, 'kind' => 'filings_csv']);
$results = ['event_create' => [$r1, $ev], 'event_update' => [$r2, $ev], 'event_delete' => [$r3, $ev], 'event_restore' => [$r4, $ev], 'report_draft' => [$r5, $rep], 'report_update' => [$r6, $rep], 'report_render' => [$r7, $rep],
            'report_approve' => [$r8, $rep], 'report_send' => [$r9, $rep], 'report_delete' => [$r10, $rep], 'export_create' => [$r11, null]];
$bad = [];
foreach ($results as $k => [$r, $want]) {
    if (!$shape($r) || ($want !== null && $rid_($r) !== $want) || ($want === null && $rid_($r) <= 0)) { $bad[] = $k . ' ' . $r['code'] . ' ' . substr($r['body'], 0, 120); }
}
ok($bad === [], 'the eleven handlers answer {ok, did, record_id, location} — the record the event, the pack or the export' . ($bad ? ': ' . implode(' | ', $bad) : ''));
ok(count(mail_log()) === 1 && mail_log()[0]['to'] === 'priya@example.invalid', 'report_send under a manager\'s action token sent one message (the fake MaluMail)');
$r = $post($hp, '/events/save.php', ['issuer' => $alpha, 'on_date' => $today, 'kind' => 'nonsense', 'title' => 'x']);
ok($r['code'] === 422 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'invalid' && isset(json_decode($r['body'], true)['error']['fields']['kind']), 'a refusal: 422 as JSON, the field named');
$r = $post($hp, '/reports/send.php', ['report' => $rep, 'recipients' => '26']);
ok($r['code'] === 403, 'an Analyst\'s token cannot send: 403 as JSON');

echo "2. Every screen answers JSON\n";
$ev2 = $ev;
$rep2 = rid(post_json($jP, '/reports/save.php', ['issuer' => $alpha, 'period_from' => $from, 'period_to' => $today, 'narrative' => 'a draft for the forms']));
post_json($jP, '/exports/create.php', ['security' => $sec, 'kind' => 'events_csv']);
$screens = ['/events', '/events/' . $ev2, '/events/new', '/events/' . $ev2 . '/edit', '/calendar', '/reports', '/reports/' . $rep2, '/reports/new', '/reports/' . $rep2 . '/edit', '/exports'];
$bad = [];
$data = [];
foreach ($screens as $u) {
    $p = page($jP, $u, $json);
    $d = json_decode($p['body'], true);
    if ($p['code'] !== 200 || !isset($d['data'])) { $bad[] = "$u {$p['code']}"; }
    $data[preg_replace('~/\d+~', '/{id}', $u)] = $d['data'] ?? [];
}
ok($bad === [], 'the ten slice 8 screens answer {data}' . ($bad ? ': ' . implode(', ', $bad) : ''));
ok(isset($data['/events']['events'], $data['/events']['upcoming'], $data['/events']['total']) && ($data['/events/{id}']['event']['event_id'] ?? 0) === $ev2 && isset($data['/events/{id}']['signals'], $data['/events/{id}']['notes']) && isset($data['/events/new']['kinds']) && ($data['/events/{id}/edit']['event']['title'] ?? '') === 'SMOKE: json event',
    'events: the list with what is coming, one event with its signals and notes, the forms with the kinds');
ok(isset($data['/calendar']['days'], $data['/calendar']['prev'], $data['/calendar']['next']) && preg_match('/^\d{4}-\d{2}$/', $data['/calendar']['month'] ?? '') === 1, 'the calendar: the month, its neighbours and the days');
ok(($data['/reports/{id}']['report']['status'] ?? '') === 'draft' && isset($data['/reports/{id}']['report']['narrative'], $data['/reports/{id}']['sections_as']) && isset($data['/reports/new']['sections']) && ($data['/reports/{id}/edit']['report']['report_id'] ?? 0) === $rep2 && isset($data['/reports']['reports']), 'packs: the list, one pack with its narrative, the forms with the sections');
ok(isset($data['/exports']['exports']) && count($data['/exports']['exports']) >= 2, 'exports: the list');
$r = page($jM, '/reports/' . $rep, $json);
ok($r['code'] === 404, 'a deleted pack: 404');

echo "3. The registry\n";
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$screens = ['event-list', 'event-view', 'event-add', 'event-edit', 'calendar', 'report-list', 'report-view', 'report-add', 'report-edit', 'export-list'];
$actions = ['event_create', 'event_update', 'event_delete', 'event_restore', 'report_draft', 'report_update', 'report_render', 'report_approve', 'report_send', 'report_delete', 'export_create'];
ok(array_filter($screens, static fn ($s) => empty($reg['screens'][$s]['built'])) === [] && array_filter($actions, static fn ($a) => empty($reg['actions'][$a]['built'])) === [], 'the registry marks the ten screens and the eleven actions built');
ok(array_filter($actions, static fn ($a) => $reg['actions'][$a]['agent'] === false) === [], 'none is people-only: an agent drafts, records and exports (the pauses guard the rest)');
$ap = array_column(json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true)['approvals'] ?? [], 'category', 'action');
$mine = array_intersect_key($ap, array_flip($actions));
ksort($mine);
ok($mine === ['event_delete' => 'deletion', 'report_approve' => 'other', 'report_delete' => 'deletion', 'report_send' => 'external_send'], 'four approvals in step with the manifest: event_delete and report_delete (deletion), report_approve (other), report_send (external_send)');
$placeholders = trim((string) shell_exec('grep -rl "render_nav_stub(" ' . escapeshellarg(dirname(__DIR__, 3) . '/html/events') . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/html/reports') . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/html/calendar') . ' 2>/dev/null'));
ok($placeholders === '', 'no placeholder is left in /events, /reports or /calendar');
finish();
