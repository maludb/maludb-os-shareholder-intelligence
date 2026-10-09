<?php
declare(strict_types=1);
/**
 * An issuer's form (screens `issuer-add` /issuers/new?ticker=&cik= and `issuer-edit` /issuers/{id}/edit). On add, ?ticker= (or ?cik=) asks the
 * SEC's ticker file and fills the form (issuers-participants.md divergence 1); nothing is saved until the person saves.
 */
require_once dirname(__DIR__, 2) . '/app/features/issuers/handler.php';
require_right('issuers.manage');
$pdo = db();
$id = request_integer('id');
$i = $id === null ? null : issuer_from_request($pdo, 'id');
$screen = $i === null ? 'issuer-add' : 'issuer-edit';
$lookup = [];
if ($i === null) {
    $values = ['name' => '', 'ticker' => '', 'cik' => '', 'exchange' => 'nasdaq', 'timezone' => '', 'transfer_agent' => '', 'transfer_agent_connector' => 'generic',
               'state_of_incorporation' => '', 'fiscal_year_end' => '', 'sic' => '', 'market_holidays' => [], 'notes' => ''];
    $asked = mb_substr(trim(request_string('ticker') ?: request_string('cik')), 0, 20);
    if ($asked !== '') {
        $found = lookup_ticker($pdo, $asked);
        $lookup = ['asked' => $asked];
        if (isset($found['error'])) {
            $lookup['error'] = $found['error'];
            $values['ticker'] = ctype_digit($asked) ? '' : strtoupper($asked);
        } else {
            $lookup['found'] = $found;
            if ($found !== null) {
                $values = array_merge($values, ['name' => $found['name'], 'ticker' => $found['ticker'], 'cik' => $found['cik'], 'exchange' => $found['exchange']]);
            }
        }
    }
} else {
    $values = ['name' => $i['name'], 'ticker' => (string) $i['ticker'], 'cik' => $i['cik'], 'exchange' => $i['exchange'], 'timezone' => (string) $i['timezone'], 'transfer_agent' => (string) $i['transfer_agent'],
               'transfer_agent_connector' => $i['transfer_agent_connector'], 'state_of_incorporation' => (string) $i['state_of_incorporation'], 'fiscal_year_end' => (string) $i['fiscal_year_end'],
               'sic' => (string) $i['sic'], 'market_holidays' => pg_text_array((string) $i['market_holidays']), 'notes' => (string) $i['notes']];
}
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['issuer' => $i === null ? null : present_issuer($i), 'values' => $values, 'lookup' => $lookup === [] ? null : ['asked' => $lookup['asked'], 'error' => $lookup['error'] ?? null, 'found' => $lookup['found'] ?? null],
        'exchanges' => ISSUER_EXCHANGES, 'transfer_agent_connectors' => ISSUER_TA_CONNECTORS]);
}
render_screen($i === null ? 'Add an issuer' : 'Edit ' . $i['name'], view('issuers/form.php', ['i' => $i, 'values' => $values, 'lookup' => $lookup, 'errors' => []]),
    ['activeNav' => 'issuers', 'screen' => $screen, 'entity' => 'issuer', 'recordId' => $id ?? '']);
