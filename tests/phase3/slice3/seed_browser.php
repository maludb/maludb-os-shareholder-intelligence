<?php
/** The browser proof's data (run by browser.mjs): the fixture issuer's NOBO list (accepted) and register, matched, and a short list leaving two rows in the queue. Prints the ids as JSON. */
require __DIR__ . '/lib.php';
[$jP, ] = sign_on(26);
[$jM, ] = sign_on(27);
$fx = fixture_issuer();
foreach ([$jP, $jM] as $j) { page($j, '/?issuer=' . $fx['issuer']); }
$seed = seed_fixture_holders($jP, $jM, $fx['security']);
$csv = "JOB NUMBER,CUSIP,ISSUER NAME,RECORD DATE,SORT:(D)ESCENDING (Z)IP CODE,NUMBER OF NOBOS\nO09999,07831X206,SMOKE ISSUER INC,060124,D,0000002\n\n"
     . "SHARES,1ST LINE OF NAME & ADDRESS,2ND LINE OF NAME & ADDRESS,3RD LINE OF NAME & ADDRESS,4TH LINE OF NAME & ADDRESS,5TH LINE OF NAME & ADDRESS,6TH LINE OF NAME & ADDRESS,7TH LINE OF NAME & ADDRESS,ZIP CODE FOR SORTING,CUSIP\n\n";
$people = q("SELECT h.name_key FROM holders h WHERE h.kind = 'person' AND h.issuer_id = :i ORDER BY (SELECT sum(shares) FROM holder_positions WHERE holder_id = h.id) DESC NULLS LAST, h.id LIMIT 2", ['i' => $fx['issuer']]);
foreach ($people as $k => $p) { $csv .= sprintf("%015.5f", 100 + $k) . ',' . $p['name_key'] . ',' . (500 + $k) . ' SMOKE MOVED ST,SMOKEVILLE TX 7' . sprintf('%04d', $k) . ",,,,,7" . sprintf('%04d', $k) . ",07831X206\n"; }
$csv .= "\n# OF NOBOS,SHARES OF NOBOS\n0000002,000000000201.00000\n";
$u = upload($jP, ['name' => 'NOBO.07831X.240601.csv', 'bytes' => $csv], ['security' => $fx['security'], 'kind' => '']);
$person = (int) one("SELECT id FROM holders WHERE kind = 'person' AND issuer_id = :i ORDER BY (SELECT sum(shares) FROM holder_positions WHERE holder_id = holders.id) DESC NULLS LAST, id LIMIT 1", ['i' => $fx['issuer']]);
$person2 = (int) one("SELECT id FROM holders WHERE kind = 'person' AND issuer_id = :i ORDER BY (SELECT sum(shares) FROM holder_positions WHERE holder_id = holders.id) DESC NULLS LAST, id LIMIT 1 OFFSET 2", ['i' => $fx['issuer']]);
echo json_encode(['issuer' => $fx['issuer'], 'security' => $fx['security'], 'nobo' => $seed['nobo'], 'register' => $seed['register'], 'small' => (int) ($u['json']['record_id'] ?? 0),
    'person' => $person, 'person2' => $person2, 'waiting' => (int) one("SELECT count(*) FROM holder_match_proposals WHERE status = 'proposed'")]) . "\n";
