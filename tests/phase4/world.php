<?php
/** The Phase 4 world: slice 9's store and slice 7's fixtures composed (p4_world(), tests/phase4/lib.php and world.sql); built once, checked here with counts only. */
require __DIR__ . '/lib.php';
$w = p4_world();
ok($w['alpha'] > 0 && $w['beta'] > 0 && $w['smok_issuer'] > 0 && $w['delta_issuer'] > 0, 'the issuers: Alpha, Beta (restricted to Dana), SMOK, Delta (restricted to Marco)');
ok((int) one('SELECT count(*) FROM issuer_members WHERE issuer_id = :i', ['i' => $w['delta_issuer']]) === 1 && (int) one('SELECT member_id FROM issuer_members WHERE issuer_id = :i', ['i' => $w['delta_issuer']]) === MARCO, 'Delta\'s restriction list names Marco alone');
$holders = (int) one('SELECT count(*) FROM holders WHERE issuer_id = :i AND deleted_at IS NULL', ['i' => $w['smok_issuer']]);
$persons = (int) one("SELECT count(*) FROM holders WHERE issuer_id = :i AND kind = 'person' AND deleted_at IS NULL", ['i' => $w['smok_issuer']]);
ok($holders > 100 && $persons > 50, "the matcher made $holders holders for SMOK, $persons of them natural persons");
ok((int) one("SELECT count(DISTINCT record_date) FROM holder_positions WHERE security_id = :s AND channel = 'register'", ['s' => $w['smok_security']]) === 2 && (int) one("SELECT count(DISTINCT record_date) FROM holder_positions WHERE security_id = :s AND channel = 'nobo'", ['s' => $w['smok_security']]) === 2, 'two register dates and two NOBO dates have holder positions');
ok((int) one('SELECT count(*) FROM signals WHERE security_id = :s', ['s' => $w['smok_security']]) >= 15, 'the rules raised signals on SMOK (' . (int) one('SELECT count(*) FROM signals WHERE security_id = :s', ['s' => $w['smok_security']]) . ')');
ok($w['smok_signal_proposed'] > 0 && (string) one('SELECT proposal_reason FROM signals WHERE id = :i', ['i' => $w['smok_signal_proposed']]) === 'known' && (string) one('SELECT status FROM signals WHERE id = :i', ['i' => $w['smok_signal_disposed']]) === 'acknowledged', 'one signal proposed by the Analyst agent, one acknowledged by Priya');
ok((int) one('SELECT count(*) FROM signal_outcomes') > 0, 'outcomes exist (' . (int) one('SELECT count(*) FROM signal_outcomes') . ')');
ok((int) one("SELECT count(*) FROM mcp_access_tokens WHERE scope = 'api'") >= 2 && $w['report_draft'] > 0 && $w['export_priya'] > 0 && $w['filing_text'] > 0, 'keys, packs, an export and a filing\'s text');
finish();
