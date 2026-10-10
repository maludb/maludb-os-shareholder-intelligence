<?php
/** The browser proof's data: the SMOKE issuer's index, text and watch pulled from the stub. Prints the ids as JSON. */
require __DIR__ . '/lib.php';
$sm = smoke_issuer();
edgar_contact(true);
pull('edgar_submissions');
pull('edgar_fulltext');
echo json_encode(['issuer' => $sm['issuer'], 'f8k' => filing_id('0000999999-24-000005'), 'hit' => (int) one("SELECT id FROM watch_hits WHERE term = 'equity purchase agreement'")]) . "\n";
