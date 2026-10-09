<?php
declare(strict_types=1);

/** What every slice 1 issuer handler starts with: the bootstrap, the issuer feature, and the readers of the issuer and security fields. */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';

/** The issuer fields a request carries, checked; a field left out is absent from the answer. Errors land in $errors (field => sentence). */
function issuer_fields_from_request(array &$errors, bool $creating): array
{
    $f = [];
    $name = req_val('name');
    if ($name !== null || $creating) {
        if ($name === null || $name === '') {
            $errors['name'] = 'Give the issuer its name.';
        } elseif (mb_strlen($name) > 200) {
            $errors['name'] = 'A name is up to 200 characters.';
        } else {
            $f['name'] = $name;
        }
    }
    if (req_has('cik')) {
        $cik = normalize_cik(req_val('cik'));
        if ($cik === false) {
            $errors['cik'] = 'A CIK is up to ten digits (EDGAR\'s number for the company).';
        } else {
            $f['cik'] = $cik;
        }
    }
    if (req_has('ticker')) {
        $t = strtoupper((string) req_val('ticker'));
        if ($t !== '' && !preg_match('~^[A-Z0-9.\-]{1,12}$~', $t)) {
            $errors['ticker'] = 'A ticker is letters, digits, dots and dashes, up to twelve.';
        } else {
            $f['ticker'] = $t === '' ? null : $t;
        }
    }
    foreach (['exchange' => ISSUER_EXCHANGES, 'transfer_agent_connector' => ISSUER_TA_CONNECTORS] as $k => $allowed) {
        if (req_has($k)) {
            $v = strtolower((string) req_val($k));
            if (!isset($allowed[$v])) {
                $errors[$k] = ($k === 'exchange' ? 'The exchange is one of ' : 'The agent\'s file is one of ') . implode(', ', array_keys($allowed)) . '.';
            } else {
                $f[$k] = $v;
            }
        }
    }
    if (req_has('state_of_incorporation')) {
        $v = strtoupper((string) req_val('state_of_incorporation'));
        if (mb_strlen($v) > 60) { $errors['state_of_incorporation'] = 'Up to 60 characters.'; } else { $f['state_of_incorporation'] = $v === '' ? null : $v; }
    }
    if (req_has('fiscal_year_end')) {
        $v = str_replace(['/', '-'], '', (string) req_val('fiscal_year_end'));
        if ($v !== '' && !preg_match('~^(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])$~', $v)) { $errors['fiscal_year_end'] = 'The fiscal year end is MMDD (1231 for December 31).'; } else { $f['fiscal_year_end'] = $v === '' ? null : $v; }
    }
    if (req_has('sic')) {
        $v = (string) req_val('sic');
        if ($v !== '' && !preg_match('~^\d{4}$~', $v)) { $errors['sic'] = 'An SIC code is four digits.'; } else { $f['sic'] = $v === '' ? null : $v; }
    }
    if (req_has('transfer_agent')) {
        $v = (string) req_val('transfer_agent');
        if (mb_strlen($v) > 120) { $errors['transfer_agent'] = 'Up to 120 characters.'; } else { $f['transfer_agent'] = $v === '' ? null : $v; }
    }
    if (req_has('timezone')) {
        $v = (string) req_val('timezone');
        if ($v !== '' && !in_array($v, DateTimeZone::listIdentifiers(), true)) { $errors['timezone'] = 'A time zone such as America/New_York, or blank for the settings\'.'; } else { $f['timezone'] = $v === '' ? null : $v; }
    }
    if (req_has('market_holidays')) {
        $raw = $_POST['market_holidays'];
        $list = is_array($raw) ? $raw : preg_split('~[\s,;]+~', (string) $raw);
        $dates = [];
        foreach (array_filter(array_map('trim', array_map('strval', (array) $list)), static fn ($x) => $x !== '') as $d) {
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
            if ($dt === false || $dt->format('Y-m-d') !== $d) {
                $errors['market_holidays'] = 'Holidays are dates written 2026-12-25, separated by commas or lines.';
                break;
            }
            $dates[$d] = $d;
        }
        ksort($dates);
        $f['market_holidays'] = array_values($dates);
    }
    if (req_has('notes')) {
        $v = (string) req_val('notes');
        if (mb_strlen($v) > 4000) { $errors['notes'] = 'Notes are up to 4,000 characters.'; } else { $f['notes'] = $v === '' ? null : $v; }
    }
    return $f;
}

/** The security fields a request carries, checked. */
function security_fields_from_request(array &$errors, bool $creating): array
{
    $f = [];
    $name = req_val('name');
    if ($name !== null || $creating) {
        if ($name === null || $name === '') { $errors['name'] = 'Name the security ("Common Stock").'; } elseif (mb_strlen($name) > 120) { $errors['name'] = 'Up to 120 characters.'; } else { $f['name'] = $name; }
    }
    if (req_has('class')) {
        $v = strtolower((string) req_val('class'));
        if (!isset(SECURITY_CLASSES[$v])) { $errors['class'] = 'The class is one of ' . implode(', ', array_keys(SECURITY_CLASSES)) . '.'; } else { $f['class'] = $v; }
    }
    foreach (['cusip' => ['~^[0-9A-Z]{9}$~', 'A CUSIP is nine letters and digits.'], 'isin' => ['~^[A-Z]{2}[0-9A-Z]{9}[0-9]$~', 'An ISIN is twelve characters (US + the CUSIP + a check digit).'],
              'ticker' => ['~^[A-Z0-9.\-]{1,12}$~', 'A ticker is letters, digits, dots and dashes, up to twelve.'], 'figi' => ['~^[A-Z0-9]{12}$~', 'A FIGI is twelve letters and digits.']] as $k => [$re, $msg]) {
        if (req_has($k)) {
            $v = strtoupper(str_replace(' ', '', (string) req_val($k)));
            if ($v !== '' && !preg_match($re, $v)) { $errors[$k] = $msg; } else { $f[$k] = $v === '' ? null : $v; }
        }
    }
    if (req_has('par_value')) {
        $v = str_replace(['$', ','], '', (string) req_val('par_value'));
        if ($v !== '' && (!is_numeric($v) || (float) $v < 0)) { $errors['par_value'] = 'The par value is a number such as 0.0001.'; } else { $f['par_value'] = $v === '' ? null : $v; }
    }
    if (req_has('is_primary')) {
        $f['is_primary'] = si_yes('is_primary');
    }
    foreach (['listed_on', 'delisted_on'] as $k) {
        if (req_has($k)) {
            $d = request_date($k);
            if ($d === false) { $errors[$k] = 'A date such as 2026-10-09.'; } else { $f[$k] = $d; }
        }
    }
    return $f;
}

/** A Postgres error as the sentence a person reads, for the constraints slice 1's writes can trip. */
function issuer_constraint_sentence(PDOException $e): ?string
{
    $m = $e->getMessage();
    return match (true) {
        str_contains($m, 'issuers_cik_key') => 'That CIK is another issuer\'s.',
        str_contains($m, 'securities_cusip_key') => 'That CUSIP is another security\'s.',
        str_contains($m, 'securities_one_primary') => 'The issuer already has a primary security.',
        str_contains($m, 'securities_check') => 'A security is delisted on or after the day it was listed.',
        default => null,
    };
}
