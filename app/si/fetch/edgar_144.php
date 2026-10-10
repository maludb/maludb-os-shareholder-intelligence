<?php
declare(strict_types=1);

/**
 * Connector `edgar_144` (edgar-and-finra-fetchers.md §2): Forms 144 about the issuer from its index; each one's XML → form144_notices (the seller,
 * the relationship, the shares to be sold, their value, the approximate sale date, the broker, the outstanding the notice states). form144.load.
 */

require_once __DIR__ . '/ownership_common.php';

function si_fetch_edgar_144(PDO $pdo, array $source, SiHttp $client, array $ctx): array
{
    $seen = 0; $new = 0; $failed = 0;
    foreach (si_ownership_due($pdo, $source, $ctx, ['144', '144/A']) as $f) {
        $d = si_ownership_document($pdo, $client, $f);
        if ($d['blocked']) {
            return ['status' => 'blocked', 'seen' => $seen, 'new' => $new, 'changed' => 0, 'error' => $d['error']];
        }
        $seen++;
        if (!$d['ok']) { si_ownership_mark($pdo, (int) $f['id'], $d['error']); $failed++; continue; }
        try {
            $x = si_xml_load($d['body']);
            if (!si_ownership_about($x, (string) $f['issuer_cik'])) { si_ownership_mark($pdo, (int) $f['id'], SI_NOT_ABOUT_US); continue; }
            $seller = si_x($x, 'nameOfPersonForWhoseAccountTheSecuritiesAreToBeSold') ?? throw new SiXmlError('no seller');
        } catch (Throwable $e) {
            si_ownership_mark($pdo, (int) $f['id'], 'Not read as a Form 144: ' . $e->getMessage());
            $failed++;
            continue;
        }
        $cik = si_x($x, 'cik');
        $pdo->beginTransaction();
        $st = $pdo->prepare('INSERT INTO form144_notices (issuer_id, security_id, filing_id, filed_on, seller_name, seller_cik, relationship, shares_to_sell, aggregate_market_value, approx_sale_date, broker, shares_outstanding_stated)
                             VALUES (:i, (SELECT id FROM securities WHERE issuer_id = :i AND is_primary LIMIT 1), :f, :d, :n, :c, :r, :sh, :v, :ad, :b, :o) ON CONFLICT (filing_id) DO NOTHING RETURNING id');
        $st->execute(['i' => (int) $f['issuer_id'], 'f' => (int) $f['id'], 'd' => $f['filed_on'], 'n' => mb_substr($seller, 0, 200), 'c' => $cik !== null && preg_match('~^\d{1,10}$~', $cik) && (int) $cik > 0 ? str_pad($cik, 10, '0', STR_PAD_LEFT) : null,
            'r' => si_x($x, 'relationshipToIssuer'), 'sh' => si_num(si_x($x, 'noOfUnitsSold')), 'v' => si_num(si_x($x, 'aggregateMarketValue')), 'ad' => si_date_any(si_x($x, 'approxSaleDate')),
            'b' => ($b = $x->xpath('.//brokerOrMarketmakerDetails')) ? si_x($x, 'name', $b[0]) : null, 'o' => si_num(si_x($x, 'noOfUnitsOutstanding'))]);
        $made = $st->fetchColumn() !== false;
        si_ownership_mark($pdo, (int) $f['id'], null);
        log_activity($pdo, 'form144.load', 'filing', (int) $f['id'], ['issuer_id' => (int) $f['issuer_id'], 'after' => ['filing_id' => (int) $f['id']]]);
        $pdo->commit();
        $new += $made ? 1 : 0;
    }
    return ['seen' => $seen, 'new' => $new, 'changed' => 0, 'status' => 'ok', 'error' => $failed > 0 ? $failed . ' notice(s) could not be read (their error is on the filing).' : null];
}
