<?php
/** The browser proof's data: the crafted fixtures, the rules run by the worker on the morning of 2024-04-05, the Analyst agent's proposal on the threshold signal. Prints the ids as JSON. */
require __DIR__ . '/lib.php';
$ids = seed7();
worker('rules', MORNING);
$th = signal_of('threshold_list', $ids['security']);
pdo()->exec("SELECT set_config('app.member_id', '42', false)");
q("SELECT si_signal_propose(:s, 'explained_by_event', 'The short report of Apr 3 drove the fails that put SMOK on the list.', :e)", ['s' => $th, 'e' => $ids['ev_short']]);
echo json_encode($ids + ['th' => $th, 'ftd' => signal_of('ftd_spike', $ids['security']), 'sof' => signal_of('si_over_float', $ids['security']), 'bf' => signal_of('borrow_fee', $ids['security']),
    'pm' => signal_of('prime_migration', $ids['security']), 'rule' => (int) one("SELECT id FROM signal_rules WHERE key = 'si_over_float'")]) . "\n";
