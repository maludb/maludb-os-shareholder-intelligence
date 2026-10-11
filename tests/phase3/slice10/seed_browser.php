<?php
/** The browser proof's data (argument: phone or desktop): the SMOKE store, a tombstoned event past its window and one inside it, and a key of Priya's. Prints the ids as JSON. */
require __DIR__ . '/lib.php';
$ids = seed_store();
$tag = $argv[1] ?? 'phone';
$mk = static function (string $t, int $days) use ($ids): int {
    $id = (int) q("INSERT INTO events (issuer_id, on_date, kind, title, source) VALUES (:i, CURRENT_DATE, 'note', :t, 'person') RETURNING id", ['i' => $ids['alpha'], 't' => $t])[0]['id'];
    q("UPDATE events SET deleted_at = now() - make_interval(days => :d) WHERE id = :i", ['d' => $days, 'i' => $id]);
    return $id;
};
$k = mint_token_row(26, 'SMOKE browser key ' . $tag, 'api');
echo json_encode(['alpha' => $ids['alpha'], 'due_' . $tag => $mk("SMOKE browser due $tag", 45), 'young_' . $tag => $mk("SMOKE browser young $tag", 3), 'key_' . $tag => $k]) . "\n";
