<?php
/**
 * Proof: the proposals screen (agents-k7-api.md "Screens", "Proof") — the Surveillance Analyst, through its run token, proposes a signal's disposition, a holder match and a holder's kind; the screen lists the three
 * across issuers, each with the agent's name and its one-click button; a person takes each (the matching disposition in the proposal's words; Accept; Reject); a Viewer reads only the signal proposals and has no button;
 * the issuer wall hides a restricted issuer's proposal from whoever is not on its list; the worker's own standing proposals (a holder whose name ends in LLC, Inc or Trust) land beside the agent's.
 */
require __DIR__ . '/lib.php';
$ids = seed9();
$alpha = $ids['alpha'];
$sig = $ids['signal'];
$jP = person(26);
$jM = person(27);
$jV = person(29);
[$jD, ] = sign_on(30);
$agent = agent_headers(42, 951);
$json = ['headers' => ['Accept: application/json']];
$data = static fn (string $jar, string $path = '/proposals'): array => json_decode(page($jar, $path, $json)['body'], true)['data'] ?? [];

echo "1. The Analyst proposes, through its run token\n";
$r = agent_post($agent, '/signals/propose.php', ['signal' => $sig, 'reason' => 'explained_by_event', 'explaining_event' => $ids['ev_answer'], 'note' => 'The company\'s answer to the report explains the jump in short interest.']);
ok($r['code'] === 200 && one('SELECT proposal_by FROM signals WHERE id = :i', ['i' => $sig]) == 42, 'a signal disposition proposed (explained by the company\'s answer)');
$m = agent_post($agent, '/holders/matches/propose.php', ['row_kind' => 'register', 'row' => $ids['register_row'], 'holder' => $ids['holder_frank'], 'evidence' => 'Same street and zip; the registration adds a Jr.']);
$matchId = rid($m);
ok($m['code'] === 200 && $matchId > 0, 'a holder match proposed: the register row to Frank');
$k = agent_post($agent, '/holders/kind-propose.php', ['holder' => $ids['holder_plain'], 'kind' => 'entity', 'entity_type' => 'other', 'evidence' => 'The registration names a firm: "Plain Holder & Sons".']);
ok($k['code'] === 200, 'a holder kind proposed');
q("INSERT INTO holder_kind_proposals (holder_id, kind, entity_type, evidence, proposed_by) VALUES (:h, 'entity', 'corporation', 'Beta''s holder ends in LLC.', 42)", ['h' => $ids['holder_beta']]);

echo "2. The screen lists them across issuers, with the agent's name and its button\n";
$d = $data($jP);
$by = static fn (array $d, string $kind): array => array_values(array_filter($d['proposals'] ?? [], static fn ($p) => $p['kind'] === $kind));
ok(($d['counts'] ?? []) === ['signal' => 1, 'holder_match' => 1, 'holder_kind' => 1] && ($d['total'] ?? 0) === 3, 'an Analyst sees the three: ' . json_encode($d['counts'] ?? null) . ' (Beta\'s is behind the wall)');
$s = $by($d, 'signal')[0] ?? [];
ok(($s['proposed_by_name'] ?? '') === 'SMOKE Surveillance Analyst' && ($s['detail']['take_it'] ?? '') === 'dismiss' && ($s['detail']['event_title'] ?? '') === 'SMOKE: the company answers the report' && ($s['detail']['evidence_class'] ?? '') !== '',
    'the signal proposal: the agent, "dismiss", the explaining event, the rule\'s class');
$mm = $by($d, 'holder_match')[0] ?? [];
ok(($mm['detail']['holder_name'] ?? '') === 'SMOKE Frank Person' && str_contains((string) ($mm['detail']['registration'] ?? ''), 'FRANK PERSON JR') && ($mm['detail']['shares'] ?? 0) == 1200 && str_contains((string) ($mm['detail']['evidence'] ?? ''), 'Jr'),
    'the match proposal: the row beside its candidate, the evidence in the agent\'s words');
$kk = $by($d, 'holder_kind')[0] ?? [];
ok(($kk['detail']['holder_name'] ?? '') === 'SMOKE Plain Holder' && ($kk['detail']['proposed_kind'] ?? '') === 'entity', 'the kind proposal: the holder and the kind proposed');
$h = page($jP, '/proposals')['body'];
ok(str_contains($h, 'id="proposal-signal-' . $sig . '"') && str_contains($h, 'id="proposal-take-' . $sig . '"') && str_contains($h, 'id="proposal-not-this-' . $sig . '"') && str_contains($h, 'SMOKE Surveillance Analyst proposes:</span> dismiss'),
    'the page: the signal card with "<agent> proposes: dismiss" and Take it / Not this');
ok(str_contains($h, 'id="proposal-match-' . ($mm['proposal_id'] ?? 0) . '"') && str_contains($h, 'id="proposal-accept-match-' . ($mm['proposal_id'] ?? 0) . '"') && str_contains($h, 'id="proposal-reject-match-' . ($mm['proposal_id'] ?? 0) . '"')
    && str_contains($h, 'id="proposal-kind-' . ($kk['proposal_id'] ?? 0) . '"') && str_contains($h, 'id="proposal-accept-kind-') && str_contains($h, 'id="proposal-reject-kind-'), '…the match card with Accept and Reject, the kind card with Accept and Reject');
$only = $data($jP, '/proposals?kind=holder_kind');
ok(($only['total'] ?? 0) === 1 && ($only['proposals'][0]['kind'] ?? '') === 'holder_kind', 'filtered by kind: only the kind proposals');
$one = $data($jP, '/proposals?issuer=' . $alpha);
ok(($one['total'] ?? 0) === 3, 'filtered to the issuer: its three');
$dd = $data($jD);
ok(($dd['counts']['holder_kind'] ?? 0) === 2, 'Dana, on Beta\'s list, sees Beta\'s kind proposal as well');
$mgr = $data($jM);
ok(($mgr['counts']['holder_kind'] ?? 0) === 2, 'an IR Manager sees every issuer\'s, restricted or not');

echo "3. A Viewer reads the signal proposals only\n";
$vd = $data($jV);
ok(($vd['counts'] ?? []) === ['signal' => 1, 'holder_match' => 0, 'holder_kind' => 0], 'a Viewer\'s counts: the signal alone — ' . json_encode($vd['counts'] ?? null));
$vh = page($jV, '/proposals');
ok($vh['code'] === 200 && str_contains($vh['body'], 'id="proposal-signal-' . $sig . '"') && !str_contains($vh['body'], 'id="proposal-take-') && !str_contains($vh['body'], 'id="proposal-match-') && !str_contains($vh['body'], 'id="proposal-kind-')
    && !str_contains($vh['body'], 'Frank') && !str_contains($vh['body'], 'Plain Holder'), 'the page: the signal card with no button, no holder card, no registration or name');

echo "4. The worker's standing proposals land beside the agent's\n";
$since = last_activity_id();
$w = worker('proposals', date('c'));
$rep = $w['report']['ran']['proposals'] ?? [];
ok($w['rc'] === 0 && ($rep['proposed'] ?? 0) === 3, 'the pass proposed the kind of the Inc., the Trust and the LLC (not the plain name, nor Beta\'s LLC — the agent had those): ' . json_encode($rep));
$ks = q("SELECT h.display_name, k.kind, k.entity_type, k.proposed_by FROM holder_kind_proposals k JOIN holders h ON h.id = k.holder_id WHERE k.proposed_by IS NULL ORDER BY h.display_name");
ok(count($ks) === 3 && $ks[0]['display_name'] === 'SMOKE Beacon Inc.' && $ks[0]['entity_type'] === 'corporation' && $ks[1]['entity_type'] === 'trust' && $ks[2]['entity_type'] === 'corporation' && $ks[0]['kind'] === 'entity',
    'as entities, the Trust as a trust and the others as corporations (db/008\'s reading of a name), proposed_by NULL');
$a = activity('holder.kind_propose', $since)[0] ?? [];
$aj = json_decode($a['after'] ?? '{}', true);
ok(($a['source'] ?? '') === 'cron' && ($aj['by'] ?? '') === 'the worker' && !str_contains(json_encode($aj), 'SMOKE'), 'logged as cron with the holder\'s id — no name');
$w2 = worker('proposals', date('c'));
ok(($w2['report']['ran']['proposals']['proposed'] ?? -1) === 0, 'the pass again: nothing new (one proposal a holder)');
$d = $data($jP);
ok(($d['counts']['holder_kind'] ?? 0) === 4, 'the screen now lists four kind proposals: the agent\'s and the worker\'s three');
$vd = $data($jV);
ok(($vd['counts']['holder_kind'] ?? -1) === 0, 'and a Viewer still none');
q('UPDATE si_settings SET analyst_proposals = false');
q("INSERT INTO holders (issuer_id, display_name, name_key, kind) VALUES (:i, 'SMOKE Switch Off LLC', si_name_key('SMOKE Switch Off LLC'), 'unknown')", ['i' => $alpha]);
ok((($w = worker('proposals', date('c')))['report']['ran']['proposals']['proposed'] ?? -1) === 0, 'with the Analyst\'s proposals switched off in the settings the worker proposes nothing');
q('UPDATE si_settings SET analyst_proposals = true');

echo "5. A person takes each, in one click\n";
$h = page($jP, '/proposals')['body'];
$f = form_of($h, '/signals/dismiss.php', 'proposal-take-' . $sig);
ok($f !== null && $f['signal'] == $sig && $f['reason'] === 'explained_by_event' && (int) $f['explaining_event'] === $ids['ev_answer'] && str_starts_with($f['note'], 'The company'), 'Take it posts the dismissal with the proposal\'s reason, event and sentence');
$since = last_activity_id();
$r = post_json($jP, '/signals/dismiss.php', $f);
$row = q('SELECT status, disposition_reason, event_id, disposed_by FROM signals WHERE id = :i', ['i' => $sig])[0];
ok($r['code'] === 200 && $row['status'] === 'dismissed' && (int) $row['event_id'] === $ids['ev_answer'] && (int) $row['disposed_by'] === 26, 'taken: dismissed by the Analyst, explained by the event');
ok(json_decode(activity('signal.dismiss', $since)[0]['after'] ?? '{}', true)['proposal'] === 'accepted', 'the trail says the proposal was taken');
ok((($data($jP)['counts']['signal'] ?? -1)) === 0, 'and the proposal leaves the screen');
$f = form_of($h, '/holders/matches/accept.php', 'proposal-accept-match-');
$r = post_json($jP, '/holders/matches/accept.php', $f ?: []);
ok($r['code'] === 200 && (int) one('SELECT holder_id FROM register_rows WHERE id = :r', ['r' => $ids['register_row']]) === $ids['holder_frank'], 'Accept on the match: the register row joins Frank');
$f = form_of($h, '/holders/kind.php', 'proposal-accept-kind-' . $kk['proposal_id']);
ok($f !== null && !isset($f['decision']) && $f['kind'] === 'entity' && (int) $f['proposal'] === (int) $kk['proposal_id'], 'Accept on the kind posts the holder, the kind, the type and the proposal');
$r = post_json($jP, '/holders/kind.php', $f);
$kp = q('SELECT status, decided_by FROM holder_kind_proposals WHERE id = :i', ['i' => $kk['proposal_id']])[0];
ok($r['code'] === 200 && one('SELECT kind FROM holders WHERE id = :h', ['h' => $ids['holder_plain']]) === 'entity' && $kp['status'] === 'accepted' && (int) $kp['decided_by'] === 26, 'taken: the kind is set and the proposal is ACCEPTED (not merely closed)');
$f = form_of($h, '/holders/kind.php', 'proposal-reject-kind-');
ok($f !== null && $f['decision'] === 'reject', 'Reject on a kind posts decision=reject');
$orion = (int) one("SELECT id FROM holder_kind_proposals WHERE holder_id = :h", ['h' => $ids['holder_orion']]);
$since = last_activity_id();
$r = post_json($jP, '/holders/kind.php', ['holder' => $ids['holder_orion'], 'proposal' => $orion, 'decision' => 'reject']);
ok($r['code'] === 200 && one('SELECT status FROM holder_kind_proposals WHERE id = :i', ['i' => $orion]) === 'rejected' && one('SELECT kind FROM holders WHERE id = :h', ['h' => $ids['holder_orion']]) === 'unknown', 'rejected: the proposal is closed and the holder is as it was');
ok(count(activity('holder.kind_reject', $since)) === 1, 'logged holder.kind_reject');
$w3 = worker('proposals', date('c'));
ok((int) one("SELECT count(*) FROM holder_kind_proposals WHERE holder_id = :h", ['h' => $ids['holder_orion']]) === 1, 'and the worker never proposes that holder again (a decided proposal is remembered)');
$r = post_json($jP, '/holders/kind.php', ['holder' => $ids['holder_orion'], 'proposal' => $orion, 'decision' => 'reject']);
ok($r['code'] === 422, 'rejecting a proposal that is no longer open: refused in words');
$r = post_json($jV, '/holders/kind.php', ['holder' => $ids['holder_orion'], 'proposal' => $orion, 'decision' => 'reject']);
ok($r['code'] === 403, 'a Viewer deciding a kind: 403');
$r = post_json($jV, '/signals/dismiss.php', ['signal' => $ids['signal'], 'reason' => 'other', 'note' => 'x']);
ok($r['code'] === 403, 'a Viewer taking a signal proposal: 403');
$imp = (int) one('SELECT import_id FROM register_rows WHERE id = :r', ['r' => $ids['register_row']]);
q("INSERT INTO register_rows (import_id, security_id, record_date, row_no, registration_name, address_line, city, state, zip, shares) VALUES (:i, :s, CURRENT_DATE - 5, 2, 'SMOKE ZETA HOLDINGS L L C', '5 Delaware Ave', 'Wilmington', 'DE', '19801', 5000)", ['i' => $imp, 's' => $ids['security']]);
$row2 = (int) one('SELECT id FROM register_rows WHERE import_id = :i AND row_no = 2', ['i' => $imp]);
$m2 = agent_post($agent, '/holders/matches/propose.php', ['row_kind' => 'register', 'row' => $row2, 'holder' => $ids['holder_zeta'], 'evidence' => 'A guess.']);
$d = $data($jM);
$pid = $by($d, 'holder_match')[0]['proposal_id'] ?? 0;
$r = post_json($jM, '/holders/matches/reject.php', ['proposal' => $pid]);
ok($m2['code'] === 200 && $r['code'] === 200 && (int) $data($jM)['counts']['holder_match'] === 0, 'a second match proposed, rejected (remembered): the screen empties of matches');
finish();
