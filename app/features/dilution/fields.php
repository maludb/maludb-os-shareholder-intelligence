<?php
declare(strict_types=1);

/** The instrument's fields from a request (dilution_save, watch_hit_confirm): a field left out stays as it was; refused with field errors. */
function dilution_fields(PDO $pdo, int $issuer, ?array $before): array
{
    $errors = [];
    $f = [];
    if (req_has('kind') || $before === null) {
        $k = (string) req_val('kind');
        isset(DILUTION_KINDS[$k]) ? $f['kind'] = $k : $errors['kind'] = 'The kind is one of ' . implode(', ', array_keys(DILUTION_KINDS)) . '.';
    }
    foreach (['counterparty' => 200, 'price_terms' => 1000, 'notes' => 4000] as $k => $max) {
        if (req_has($k)) {
            $v = (string) req_val($k);
            mb_strlen($v) > $max ? $errors[$k] = ucfirst(str_replace('_', ' ', $k)) . ' is up to ' . $max . ' characters.' : $f[$k] = $v === '' ? null : $v;
        }
    }
    foreach (['facility_amount' => 'The facility', 'remaining_amount' => 'The remaining amount', 'shares_issuable_max' => 'The most shares issuable'] as $k => $label) {
        if (req_has($k)) {
            $v = str_replace([',', '$', ' '], '', (string) req_val($k));
            if ($v === '') { $f[$k] = null; continue; }
            (is_numeric($v) && (float) $v >= 0 && (float) $v < 1e14) ? $f[$k] = $v : $errors[$k] = $label . ' is an amount of 0 or more.';
        }
    }
    foreach (['filed_on' => 'The date filed', 'expires_on' => 'The expiry'] as $k => $label) {
        if (req_has($k)) {
            $d = request_date($k);
            $d === false ? $errors[$k] = $label . ' is a date such as 2026-09-30.' : $f[$k] = $d;
        }
    }
    if (req_has('floating_price')) { $f['floating_price'] = si_yes('floating_price'); }
    if (req_has('currency')) {
        $c = strtoupper((string) req_val('currency'));
        preg_match('~^[A-Z]{3}$~', $c) ? $f['currency'] = $c : $errors['currency'] = 'A currency is three letters (USD).';
    }
    if (req_has('status')) {
        $s = (string) req_val('status');
        in_array($s, ['proposed', 'active', 'exhausted', 'terminated'], true) ? $f['status'] = $s : $errors['status'] = 'The status is proposed, active, exhausted or terminated.';
    }
    if (req_has('security')) {
        $f['security'] = si_ref($pdo, 'security', null, 'SELECT 1 FROM mcp_securities WHERE security_id = :id AND issuer_id = ' . $issuer, 'the security', $errors);
    }
    if (req_has('filing')) {
        $f['filing'] = si_ref($pdo, 'filing', null, 'SELECT 1 FROM mcp_filings WHERE filing_id = :id AND issuer_id = ' . $issuer, 'the filing', $errors);
    }
    if ($errors !== []) {
        si_refuse_fields($errors);
    }
    return $f;
}
