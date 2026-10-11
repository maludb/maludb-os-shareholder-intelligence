<?php
/** The browser proof's data: every short-side source pulled from the stub at the fixtures' last day, an event on the spike. Prints the ids as JSON. */
require __DIR__ . '/lib.php';
$sm = seed_short_all();
q("INSERT INTO events (issuer_id, security_id, on_date, kind, title, severity) VALUES (:i, :s, '2024-03-28', 'activist_short_report', 'SMOKE: a short report', 'high')", ['i' => $sm['issuer'], 's' => $sm['security']]);
echo json_encode(['issuer' => $sm['issuer'], 'security' => $sm['security']]) . "\n";
