<?php
declare(strict_types=1);

/**
 * Connector `edgar_ownership` (edgar-and-finra-fetchers.md §2): Forms 3, 4 and 5 of the issuer from its index; each one's ownershipDocument XML →
 * insider_transactions, the non-derivative and derivative tables (transactions and holdings) in order (row_no), with the codes, shares, price,
 * acquired/disposed, owned after, direct/indirect, the footnotes' words and is_10b5_1 (the 2023 checkbox aff10b5One, or a footnote naming the plan).
 * The trigger si_insider_mark proposes the register's holder by name (db/022); a person confirms it (insider_holder_link). insider.load per filing.
 */

require_once __DIR__ . '/ownership_common.php';

const SI_OWNERSHIP_FORMS = ['3', '4', '5', '3/A', '4/A', '5/A'];

function si_fetch_edgar_ownership(PDO $pdo, array $source, SiHttp $client, array $ctx): array
{
    $seen = 0; $new = 0; $failed = 0;
    foreach (si_ownership_due($pdo, $source, $ctx, SI_OWNERSHIP_FORMS) as $f) {
        $d = si_ownership_document($pdo, $client, $f);
        if ($d['blocked']) {
            return ['status' => 'blocked', 'seen' => $seen, 'new' => $new, 'changed' => 0, 'error' => $d['error']];
        }
        $seen++;
        if (!$d['ok']) { si_ownership_mark($pdo, (int) $f['id'], $d['error']); $failed++; continue; }
        try {
            if (!si_ownership_about(si_xml_load($d['body']), (string) $f['issuer_cik'])) { si_ownership_mark($pdo, (int) $f['id'], SI_NOT_ABOUT_US); continue; }
            $rows = si_parse_ownership_document($d['body']);
        } catch (Throwable $e) {
            si_ownership_mark($pdo, (int) $f['id'], 'Not read as an ownership form: ' . $e->getMessage());
            $failed++;
            continue;
        }
        $sec = one_value($pdo, 'SELECT id FROM securities WHERE issuer_id = :i AND is_primary LIMIT 1', ['i' => (int) $f['issuer_id']]);
        $ins = $pdo->prepare('INSERT INTO insider_transactions (issuer_id, security_id, filing_id, form, reporting_owner_cik, reporting_owner_name, relationship, officer_title, transaction_date, transaction_code,
                                  shares, price, acquired_disposed, shares_owned_after, ownership, indirect_nature, security_title, is_derivative, exercise_price, expiration_date, footnotes, is_10b5_1, row_no)
                              VALUES (:iss, :sec, :fil, :form, :cik, :name, CAST(:rel AS text[]), :title, :td, :code, :sh, :px, :ad, :after, :own, :nat, :st, :der, :ex, :exp, CAST(:foot AS text[]), :plan, :row)
                              ON CONFLICT (filing_id, is_derivative, row_no) DO NOTHING RETURNING id');
        $n = 0;
        $pdo->beginTransaction();
        foreach ($rows['rows'] as $r) {
            $ins->execute(['iss' => (int) $f['issuer_id'], 'sec' => $sec ?: null, 'fil' => (int) $f['id'], 'form' => in_array($f['form'], SI_OWNERSHIP_FORMS, true) ? $f['form'] : '4',
                'cik' => $rows['owner_cik'], 'name' => $rows['owner_name'], 'rel' => '{' . implode(',', $rows['relationship']) . '}', 'title' => $rows['officer_title'],
                'td' => $r['date'], 'code' => $r['code'], 'sh' => $r['shares'], 'px' => $r['price'], 'ad' => $r['ad'], 'after' => $r['after'], 'own' => $r['own'], 'nat' => $r['nature'], 'st' => $r['title'],
                'der' => $r['derivative'] ? 'true' : 'false', 'ex' => $r['exercise'], 'exp' => $r['expires'], 'foot' => '{' . implode(',', array_map(static fn ($t) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $t) . '"', $r['footnotes'])) . '}',
                'plan' => $r['plan'] === null ? null : ($r['plan'] ? 'true' : 'false'), 'row' => $r['row_no']]);
            $n += $ins->fetchColumn() !== false ? 1 : 0;
        }
        si_ownership_mark($pdo, (int) $f['id'], null);
        log_activity($pdo, 'insider.load', 'filing', (int) $f['id'], ['issuer_id' => (int) $f['issuer_id'], 'after' => ['filing_id' => (int) $f['id'], 'rows' => $n]]);
        $pdo->commit();
        $new += $n;
    }
    return ['seen' => $seen, 'new' => $new, 'changed' => 0, 'status' => $failed > 0 && $seen === $failed ? 'partial' : 'ok', 'error' => $failed > 0 ? $failed . ' filing(s) could not be read (their error is on the filing).' : null];
}

/** An ownershipDocument → the owner and the rows. */
function si_parse_ownership_document(string $xml): array
{
    $x = si_xml_load($xml);
    if ($x->getName() !== 'ownershipDocument') {
        throw new SiXmlError('not an ownershipDocument');
    }
    $owner = $x->xpath('.//reportingOwner')[0] ?? null;
    $cik = str_pad((string) (si_x($x, 'rptOwnerCik', $owner) ?? ''), 10, '0', STR_PAD_LEFT);
    if (!preg_match('~^\d{10}$~', $cik) || $cik === '0000000000') {
        throw new SiXmlError('the reporting owner has no CIK');
    }
    $rel = [];
    foreach (['isDirector' => 'director', 'isOfficer' => 'officer', 'isTenPercentOwner' => 'ten_percent_owner', 'isOther' => 'other'] as $k => $v) {
        if (in_array(strtolower((string) si_x($x, $k, $owner)), ['1', 'true'], true)) { $rel[] = $v; }
    }
    $notes = [];
    foreach ($x->xpath('.//footnotes/footnote') ?: [] as $fn) {
        $notes[(string) $fn['id']] = trim(preg_replace('~\s+~', ' ', (string) $fn) ?? '');
    }
    $aff = in_array(strtolower((string) si_x($x, 'aff10b5One')), ['1', 'true'], true) ? true : null;
    $out = [];
    $k = 0;
    foreach ([['nonDerivativeTransaction', false], ['nonDerivativeHolding', false], ['derivativeTransaction', true], ['derivativeHolding', true]] as [$tag, $der]) {
        foreach ($x->xpath('.//' . $tag) ?: [] as $t) {
            $fids = array_map(static fn ($e) => (string) $e['id'], $t->xpath('.//footnoteId') ?: []);
            $fn = array_values(array_filter(array_map(static fn ($id) => $notes[$id] ?? null, array_unique($fids))));
            $plan = $aff ?? (preg_grep('~10b5-1~i', $fn) ? true : null);
            $out[] = ['row_no' => ++$k, 'derivative' => $der, 'title' => si_x($x, 'securityTitle', $t), 'date' => si_date_any(si_x($x, 'transactionDate', $t)), 'code' => ($c = strtoupper((string) si_x($x, 'transactionCode', $t))) !== '' && preg_match('~^[A-Z]$~', $c) ? $c : null,
                      'shares' => si_num(si_x($x, 'transactionShares', $t)), 'price' => si_num(si_x($x, 'transactionPricePerShare', $t)), 'ad' => in_array($a = (string) si_x($x, 'transactionAcquiredDisposedCode', $t), ['A', 'D'], true) ? $a : null,
                      'after' => si_num(si_x($x, 'sharesOwnedFollowingTransaction', $t)), 'own' => in_array($o = (string) si_x($x, 'directOrIndirectOwnership', $t), ['D', 'I'], true) ? $o : null,
                      'nature' => si_x($x, 'natureOfOwnership', $t), 'exercise' => si_num(si_x($x, 'conversionOrExercisePrice', $t)), 'expires' => si_date_any(si_x($x, 'expirationDate', $t)), 'footnotes' => $fn, 'plan' => $plan];
        }
    }
    return ['owner_cik' => $cik, 'owner_name' => (string) (si_x($x, 'rptOwnerName', $owner) ?? 'UNKNOWN'), 'relationship' => $rel, 'officer_title' => si_x($x, 'officerTitle', $owner), 'rows' => $out];
}
