<?php
declare(strict_types=1);

/**
 * The issuer form's Look up (issuers-participants.md, divergence 1): the SEC's own ticker file, company_tickers_exchange.json (the same list as
 * company_tickers.json with the exchange beside each ticker), read under the EDGAR user-agent through the one client (an ETag / Last-Modified
 * cache: the 10,000-line file is refetched only when the SEC changes it). The URL is the edgar_submissions source's settings.tickers_url
 * (the proofs point it at the stub), else the SEC's. A ticker, a CIK (any number of digits) or a name finds the company.
 *   si_sec_lookup(PDO, string $ref): ?array{cik, name, ticker, exchange, tickers[]}   — throws SiFetchError when it cannot ask
 */

require_once dirname(__DIR__) . '/fetch/source.php';

/** The SEC's exchange words → ours (issuers.exchange). */
function si_sec_exchange(?string $sec): string
{
    return match (strtoupper(trim((string) $sec))) {
        'NASDAQ' => 'nasdaq', 'NYSE' => 'nyse', 'NYSE AMERICAN', 'NYSE MKT', 'AMEX' => 'nyse_american', 'OTC' => 'otc', 'CSE' => 'cse', 'TSX' => 'tsx', 'TSXV' => 'tsxv',
        default => 'other',
    };
}

function si_sec_lookup(PDO $pdo, string $ref, ?SiHttp $client = null): ?array
{
    $ref = trim($ref);
    if ($ref === '' || mb_strlen($ref) > 120) {
        return null;
    }
    $source = si_source_by_connector($pdo, 'edgar_submissions') ?? ['connector' => 'edgar_submissions', 'settings' => '{}', 'rate_per_second' => 2];
    $ua = si_fetch_user_agent($pdo, $source);
    if ($ua === null) {
        throw new SiFetchError('Looking up needs the business name and a contact email in Settings (the SEC\'s rule for EDGAR).');
    }
    $settings = json_decode((string) $source['settings'], true) ?: [];
    $url = (string) ($settings['tickers_url'] ?? 'https://www.sec.gov/files/company_tickers_exchange.json');
    $client ??= si_http_client(['user_agent' => $ua, 'rate_per_second' => (float) $source['rate_per_second']]);
    $r = $client->get($url, ['accept' => 'application/json', 'max_bytes' => 16 * 1024 * 1024]);
    if ($r['blocked']) {
        throw new SiFetchError('The SEC refused the lookup (' . $r['status'] . ').');
    }
    if (!$r['ok']) {
        throw new SiFetchError('The SEC\'s ticker file answered ' . $r['status'] . '.');
    }
    $doc = json_decode($r['body'], true);
    $fields = $doc['fields'] ?? null;
    if (!is_array($fields) || !is_array($doc['data'] ?? null)) {
        throw new SiFetchError('The SEC\'s ticker file is not in the shape we know.');
    }
    $ix = array_flip($fields);
    $byCik = static fn (array $row): string => str_pad((string) $row[$ix['cik']], 10, '0', STR_PAD_LEFT);
    $up = strtoupper($ref);
    $match = null;
    foreach ($doc['data'] as $row) {
        if (strtoupper((string) $row[$ix['ticker']]) === $up) { $match = $row; break; }
    }
    if ($match === null && ctype_digit($ref) && strlen($ref) <= 10) {
        foreach ($doc['data'] as $row) {
            if ($byCik($row) === str_pad($ref, 10, '0', STR_PAD_LEFT)) { $match = $row; break; }
        }
    }
    if ($match === null) {
        foreach ($doc['data'] as $row) {
            if (strtoupper((string) $row[$ix['name']]) === $up) { $match = $row; break; }
        }
    }
    if ($match === null) {
        return null;
    }
    $cik = $byCik($match);
    $tickers = [];
    foreach ($doc['data'] as $row) {
        if ($byCik($row) === $cik) { $tickers[] = (string) $row[$ix['ticker']]; }
    }
    return ['cik' => $cik, 'name' => (string) $match[$ix['name']], 'ticker' => strtoupper((string) $match[$ix['ticker']]),
            'exchange' => si_sec_exchange($match[$ix['exchange']] ?? null), 'tickers' => $tickers];
}
