<?php
declare(strict_types=1);
/** /dilution/new?filing=&kind=&hit= and /dilution/{id}/edit — record an instrument by hand, confirm a watch hit's (prefilled; it posts to watch_hit_confirm), or confirm or change one (screens `dilution-add`, `dilution-edit`; PEOPLE). */
require_once dirname(__DIR__, 2) . '/app/features/filings/screen.php';
require_right('events.write');
require_human();
$pdo = db();
$d = request_integer('id') !== null ? instrument_from_request($pdo, 'id') : null;
$hit = request_integer('hit') !== null ? find_watch_hit($pdo, (int) request_integer('hit')) : null;
if (request_integer('hit') !== null && ($hit === null || $hit['status'] !== 'new')) { refuse($hit === null ? 404 : 422, $hit === null ? 'Not found.' : 'This hit was already ' . $hit['status'] . '.'); }
$issuer = $d !== null ? find_issuer($pdo, (int) $d['issuer_id']) : ($hit !== null ? find_issuer($pdo, (int) $hit['issuer_id']) : current_issuer($pdo));
if ($issuer === null) { refuse(404, 'Not found.'); }
$issuer['issuer_id'] ??= $issuer['id'];
$prop = $hit === null ? null : ($pdo->query("SELECT * FROM mcp_dilution_instruments WHERE filing_id = " . (int) $hit['filing_id'] . " AND status = 'proposed' ORDER BY instrument_id LIMIT 1")->fetch() ?: null);
$v = $d ?? $prop ?? ['kind' => request_string('kind') ?: ($hit !== null ? (si_watch_term_kind((string) $hit['term']) ?? 'other') : 'atm'), 'filing_id' => request_integer('filing') ?? ($hit['filing_id'] ?? null),
    'filed_on' => $hit['filed_on'] ?? null, 'currency' => 'USD', 'floating_price' => false, 'status' => 'active'];
log_screen_view($pdo, $d === null ? 'dilution-add' : 'dilution-edit');
if (wants_json()) {
    respond_screen(['instrument' => $d === null ? null : present_instrument($d), 'watch_hit' => $hit === null ? null : present_watch_hit($hit), 'kinds' => DILUTION_KINDS, 'statuses' => DILUTION_STATUSES]);
}
require_once dirname(__DIR__, 2) . '/app/si/fetch/edgar_fulltext.php';
render_screen($d === null ? ($hit !== null ? 'Confirm the instrument' : 'Record an instrument') : 'Edit the instrument', view('dilution/form.php', ['d' => $d, 'hit' => $hit, 'v' => $v, 'issuer' => $issuer,
    'securities' => find_securities($pdo, (int) $issuer['issuer_id'])]), ['activeNav' => 'filings', 'screen' => $d === null ? 'dilution-add' : 'dilution-edit']);
