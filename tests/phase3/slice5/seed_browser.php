<?php
/** The browser proof's data: the SMOKE issuer's 13F data sets, schedules, Form 4 and 144 pulled from the stub. Prints the ids as JSON. */
require __DIR__ . '/lib.php';
$sm = seed_ownership();
q("INSERT INTO share_counts (security_id, as_of, kind, value, source) VALUES (:s, '2024-03-25', 'outstanding', 17053900, 'filing') ON CONFLICT DO NOTHING", ['s' => $sm['security']]);
q("INSERT INTO holders (issuer_id, display_name, name_key, kind) VALUES (:i, 'ROBERT A SMITH', si_name_key('ROBERT A SMITH'), 'person')", ['i' => $sm['issuer']]);
foreach (['edgar_13f_dataset', 'edgar_13dg', 'edgar_ownership', 'edgar_144'] as $c) { pull($c); }
echo json_encode(['issuer' => $sm['issuer'], 'security' => $sm['security'], 'alpha' => (int) one("SELECT id FROM institutions WHERE cik = '0001111111'"), 'delta' => (int) one("SELECT id FROM institutions WHERE cik = '0001444444'"),
    'tx' => (int) one('SELECT id FROM insider_transactions ORDER BY row_no LIMIT 1')]) . "\n";
