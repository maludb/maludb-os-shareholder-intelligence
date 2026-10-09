<?php
declare(strict_types=1);

/**
 * Connector `edgar_submissions` (edgar-and-finra-fetchers.md §2). For every issuer with a CIK (or the one issuer `issuer_edgar_sync` names;
 * a source's settings.issuers narrows the set), GET <base_url>CIK##########.json (data.sec.gov/submissions/) under the EDGAR user-agent and
 * keep the ENTITY: issuers.edgar_entity (the scalars, the tickers and exchanges, the addresses, the former names), edgar_synced_at, and the
 * blanks a person left (SIC, state of incorporation, fiscal year end, EIN, the ticker) filled — never a value a person wrote. Slice 1 builds
 * this half; slice 4 adds THE INDEX (filings.recent → filings) through si_edgar_index_apply() when that function exists.
 * A CIK the SEC does not know (404) is noted for that issuer and the pull is `partial`; a restricted issuer is fetched like any other (the
 * wall is on reading; the public record is public).
 */

function si_fetch_edgar_submissions(PDO $pdo, array $source, SiHttp $client, array $ctx): array
{
    $base = rtrim((string) ($source['base_url'] ?: 'https://data.sec.gov/submissions/'), '/') . '/';
    $settings = json_decode((string) $source['settings'], true) ?: [];
    $only = array_map('intval', (array) ($settings['issuers'] ?? []));
    $sql = 'SELECT id, cik, name, ticker, sic, state_of_incorporation, fiscal_year_end, ein, edgar_entity FROM issuers WHERE deleted_at IS NULL AND cik IS NOT NULL';
    $args = [];
    if (($ctx['issuer'] ?? null) !== null) {
        $sql .= ' AND id = :one';
        $args['one'] = (int) $ctx['issuer'];
    } elseif ($only !== []) {
        $sql .= ' AND id = ANY (CAST(:only AS bigint[]))';
        $args['only'] = '{' . implode(',', $only) . '}';
    }
    $st = $pdo->prepare($sql . ' ORDER BY id');
    $st->execute($args);
    $issuers = $st->fetchAll();
    $seen = 0; $changed = 0; $new = 0; $missing = []; $detail = [];
    foreach ($issuers as $i) {
        $r = $client->get($base . 'CIK' . $i['cik'] . '.json', ['accept' => 'application/json']);
        if ($r['blocked']) {
            return ['status' => 'blocked', 'seen' => $seen, 'changed' => $changed, 'error' => 'EDGAR refused us (' . $r['status'] . ') — the user-agent or the rate.', 'detail' => $detail];
        }
        if ($r['skipped']) {
            throw new SiFetchError('robots.txt disallows the submissions path.');
        }
        if ($r['status'] === 404) {
            $missing[] = (int) $i['id'];
            $detail[(int) $i['id']] = ['error' => 'not_found'];
            continue;
        }
        if (!$r['ok']) {
            throw new SiFetchError('EDGAR answered ' . $r['status'] . ' for CIK ' . ltrim((string) $i['cik'], '0') . '.');
        }
        $doc = json_decode($r['body'], true);
        if (!is_array($doc) || !isset($doc['name'])) {
            throw new SiFetchError('EDGAR\'s answer for CIK ' . ltrim((string) $i['cik'], '0') . ' is not a submissions document.');
        }
        $seen++;
        $res = si_edgar_entity_apply($pdo, $i, $doc);
        if ($res['changed'] !== []) {
            $changed++;
        }
        $detail[(int) $i['id']] = $res;
        if (function_exists('si_edgar_index_apply')) {                       // slice 4: the filing index
            $new += (int) si_edgar_index_apply($pdo, (int) $i['id'], $doc, (int) $ctx['pull'], $client, $base);
        }
    }
    return ['seen' => $seen, 'new' => $new, 'changed' => $changed, 'status' => $missing === [] ? 'ok' : ($seen > 0 ? 'partial' : 'failed'),
            'error' => $missing === [] ? null : 'EDGAR does not know ' . count($missing) . ' CIK' . (count($missing) === 1 ? '' : 's') . ' (issuer ' . implode(', ', $missing) . ').', 'detail' => $detail];
}

/** Keep the entity of one submissions document on its issuer; fill only the blanks. Answers ['changed' => [field…], 'entity_changed' => bool]. */
function si_edgar_entity_apply(PDO $pdo, array $issuer, array $doc): array
{
    $keep = ['name', 'entityType', 'sic', 'sicDescription', 'ein', 'category', 'fiscalYearEnd', 'stateOfIncorporation', 'stateOfIncorporationDescription', 'tickers', 'exchanges',
             'addresses', 'formerNames', 'phone', 'website', 'investorWebsite', 'description', 'lei', 'flags', 'ownerOrg'];
    $entity = array_intersect_key($doc, array_flip($keep));
    $before = json_decode((string) $issuer['edgar_entity'], true) ?: [];
    $fill = [];
    $sic = preg_match('~^\d{4}$~', (string) ($doc['sic'] ?? '')) ? (string) $doc['sic'] : null;
    if ($issuer['sic'] === null && $sic !== null) { $fill['sic'] = $sic; }
    $state = strtoupper(trim((string) ($doc['stateOfIncorporation'] ?? '')));
    if ($issuer['state_of_incorporation'] === null && $state !== '') { $fill['state_of_incorporation'] = $state; }
    $fye = (string) ($doc['fiscalYearEnd'] ?? '');
    if ($issuer['fiscal_year_end'] === null && preg_match('~^(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])$~', $fye)) { $fill['fiscal_year_end'] = $fye; }
    $ein = preg_replace('~\D~', '', (string) ($doc['ein'] ?? '')) ?? '';
    if ($issuer['ein'] === null && $ein !== '' && $ein !== '000000000') { $fill['ein'] = $ein; }
    $ticker = strtoupper((string) (($doc['tickers'] ?? [])[0] ?? ''));
    if ($issuer['ticker'] === null && preg_match('~^[A-Z0-9.\-]{1,12}$~', $ticker)) { $fill['ticker'] = $ticker; }
    $set = ['edgar_entity = CAST(:entity AS jsonb)', 'edgar_synced_at = now()'];
    $args = ['entity' => json_encode($entity), 'id' => (int) $issuer['id']];
    foreach ($fill as $k => $v) {
        $set[] = $k . ' = :' . $k;
        $args[$k] = $v;
    }
    $pdo->prepare('UPDATE issuers SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($args);
    $entityChanged = $before !== $entity;
    return ['changed' => array_keys($fill), 'entity_changed' => $entityChanged, 'name' => (string) $doc['name']];
}
