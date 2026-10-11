<?php
/**
 * The browser proof's data (argument: a tag, 'phone' or 'desktop' — each viewport acts on proposals of its own): the SMOKE store, then the Surveillance Analyst's proposals through its run token — a signal's disposition on a
 * signal of its own, a holder match on a register row of its own, a holder's kind on a holder of its own — and a dispatch still to be made. Prints the ids as JSON.
 */
require __DIR__ . '/lib.php';
$ids = seed9();
$tag = $argv[1] ?? 'phone';
$sec = $ids['security'];
$agent = agent_headers(42, $tag === 'phone' ? 961 : 962);
$rule = $tag === 'phone' ? 'borrow_fee' : 'ftd_spike';
$sig = (int) one("SELECT s.id FROM signals s JOIN signal_rules r ON r.id = s.rule_id WHERE r.key = :k AND s.security_id = :s", ['k' => $rule, 's' => $sec]);
if ($sig === 0) { $sig = new_signal($sec, $rule); }
agent_post($agent, '/signals/propose.php', ['signal' => $sig, 'reason' => 'explained_by_event', 'explaining_event' => $ids['ev_answer'], 'note' => 'The company\'s answer explains it (' . $tag . ').']);
$holder = $tag === 'phone' ? $ids['holder_frank'] : $ids['holder_zeta'];
$imp = (int) one("SELECT import_id FROM register_rows WHERE id = :r", ['r' => $ids['register_row']]);
$rowNo = 10 + ($tag === 'phone' ? 1 : 2);
if (one('SELECT 1 FROM register_rows WHERE import_id = :i AND row_no = :n', ['i' => $imp, 'n' => $rowNo]) === false) {
    q("INSERT INTO register_rows (import_id, security_id, record_date, row_no, registration_name, address_line, city, state, zip, shares) VALUES (:i, :s, CURRENT_DATE - 5, :n, :nm, '9 Oak Lane', 'Springfield', 'IL', '62701', 700)",
        ['i' => $imp, 's' => $sec, 'n' => $rowNo, 'nm' => $tag === 'phone' ? 'SMOKE FRANK PERSON TTEE' : 'SMOKE ZETA HOLDINGS L L C']);
}
$row = (int) one('SELECT id FROM register_rows WHERE import_id = :i AND row_no = :n', ['i' => $imp, 'n' => $rowNo]);
$m = agent_post($agent, '/holders/matches/propose.php', ['row_kind' => 'register', 'row' => $row, 'holder' => $holder, 'evidence' => 'The same street; the registration adds a legend (' . $tag . ').']);
$name = 'SMOKE Browser ' . ucfirst($tag) . ' Capital';
if (one('SELECT 1 FROM holders WHERE display_name = :n', ['n' => $name]) === false) {
    pdo()->exec("SELECT set_config('app.member_id', '27', false)");
    q("INSERT INTO holders (issuer_id, display_name, name_key, kind) VALUES (:i, :n, si_name_key(:n2), 'unknown')", ['i' => $ids['alpha'], 'n' => $name, 'n2' => $name]);
}
$hk = (int) one('SELECT id FROM holders WHERE display_name = :n', ['n' => $name]);
agent_post($agent, '/holders/kind-propose.php', ['holder' => $hk, 'kind' => 'entity', 'entity_type' => 'fund', 'evidence' => 'A capital firm (' . $tag . ').']);
$mp = (int) one("SELECT id FROM holder_match_proposals WHERE row_kind = 'register' AND row_id = :r AND holder_id = :h", ['r' => $row, 'h' => $holder]);
$kp = (int) one("SELECT id FROM holder_kind_proposals WHERE holder_id = :h", ['h' => $hk]);
$disp = dispatch_of_signal(new_signal($sec, $tag === 'phone' ? 'nobo_drain' : 'register_untied'));
echo json_encode($ids + ['signal_' . $tag => $sig, 'match_' . $tag => $mp, 'kind_' . $tag => $kp, 'dispatch_' . $tag => (int) $disp['id']]) . "\n";
