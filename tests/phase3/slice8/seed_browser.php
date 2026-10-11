<?php
/**
 * The browser proof's data: the SMOKE store, a second Security Position Report date, a filing (and the event the trigger makes), a coming earnings call, a draft pack by Priya and an approved one by Marco
 * (sent to nobody yet). Prints the ids as JSON.
 */
require __DIR__ . '/lib.php';
$ids = seed8();
$alpha = $ids['alpha'];
$sec = $ids['security'];
q('INSERT INTO participant_positions (security_id, participant_id, position_date, shares, import_id) SELECT security_id, participant_id, position_date - 7, shares - 500000, import_id FROM participant_positions WHERE security_id = :s AND position_date = CURRENT_DATE - 3', ['s' => $sec]);
q("INSERT INTO filings (issuer_id, filer_cik, accession_number, form, filed_on, items, primary_document, primary_doc_description) VALUES (:i, '0000999001', '0000999001-24-000011', '8-K', CURRENT_DATE - 4, '{3.02}', 'a8k.htm', 'Unregistered sales of equity securities')", ['i' => $alpha]);
$filing = (int) one("SELECT id FROM filings WHERE accession_number = '0000999001-24-000011'");
[$jP, ] = sign_on(26);
[$jM, ] = sign_on(27);
page($jP, '/?issuer=' . $alpha);
page($jM, '/?issuer=' . $alpha);
$call = rid(post_json($jP, '/events/save.php', ['issuer' => $alpha, 'on_date' => date('Y-m-d', strtotime('+6 days')), 'kind' => 'earnings', 'title' => 'SMOKE: the quarterly call', 'severity' => 'watch', 'tags' => 'earnings']));
$from = date('Y-m-d', strtotime('-30 days'));
$to = date('Y-m-d');
$draft = rid(post_json($jP, '/reports/save.php', ['issuer' => $alpha, 'period_from' => $from, 'period_to' => $to, 'title' => 'SMOKE: the draft pack', 'narrative' => "# This week\n\nThe **short interest** is the thing to read.\n\n- one\n- two"]));
$draft2 = rid(post_json($jP, '/reports/save.php', ['issuer' => $alpha, 'period_from' => $from, 'period_to' => $to, 'title' => 'SMOKE: a draft that stays a draft', 'narrative' => 'Not approved.']));
$approved = rid(post_json($jP, '/reports/save.php', ['issuer' => $alpha, 'period_from' => $from, 'period_to' => $to, 'title' => 'SMOKE: the approved pack', 'narrative' => 'Approved on the numbers.']));
post_json($jM, '/reports/approve.php', ['report' => $approved]);
echo json_encode($ids + ['filing' => $filing, 'filing_event' => (int) one("SELECT id FROM events WHERE filing_id = :f", ['f' => $filing]), 'call' => $call, 'draft' => $draft, 'draft2' => $draft2, 'approved' => $approved,
    'month' => date('Y-m', strtotime('+6 days')), 'call_date' => date('Y-m-d', strtotime('+6 days'))]) . "\n";
