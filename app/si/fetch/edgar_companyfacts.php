<?php
declare(strict_types=1);

/**
 * Connector `edgar_companyfacts` (edgar-and-finra-fetchers.md §2): GET <base_url>CIK##########.json (data.sec.gov/api/xbrl/companyfacts/) for
 * every issuer with a CIK and a primary security, under the EDGAR user-agent. The cover facts become share counts of the PRIMARY security with
 * source `filing`: dei:EntityCommonStockSharesOutstanding (units shares) → `outstanding`, dei:EntityPublicFloat (units USD) → `public_float_value`;
 * each fact's `end` is the as_of, its `frame` the xbrl_frame, its `accn` the filing (when the index holds it). Several filings may state one
 * date (an amendment): the latest filed wins. A person's row (another source) is never touched — the unique key carries the source. One
 * `share_count.save` line per new or changed count. A CIK without the facts (404 — a filer without XBRL) is noted, not a failure.
 */

function si_fetch_edgar_companyfacts(PDO $pdo, array $source, SiHttp $client, array $ctx): array
{
    $base = rtrim((string) ($source['base_url'] ?: 'https://data.sec.gov/api/xbrl/companyfacts/'), '/') . '/';
    $settings = json_decode((string) $source['settings'], true) ?: [];
    $only = array_map('intval', (array) ($settings['issuers'] ?? []));
    $sql = 'SELECT i.id, i.cik, s.id AS security_id FROM issuers i JOIN securities s ON s.issuer_id = i.id AND s.is_primary WHERE i.deleted_at IS NULL AND i.cik IS NOT NULL';
    $args = [];
    if (($ctx['issuer'] ?? null) !== null) {
        $sql .= ' AND i.id = :one';
        $args['one'] = (int) $ctx['issuer'];
    } elseif ($only !== []) {
        $sql .= ' AND i.id = ANY (CAST(:only AS bigint[]))';
        $args['only'] = '{' . implode(',', $only) . '}';
    }
    $st = $pdo->prepare($sql . ' ORDER BY i.id');
    $st->execute($args);
    $seen = 0; $new = 0; $changed = 0; $missing = []; $detail = [];
    $concepts = ['EntityCommonStockSharesOutstanding' => ['outstanding', 'shares'], 'EntityPublicFloat' => ['public_float_value', 'USD']];
    $upsert = $pdo->prepare("INSERT INTO share_counts (security_id, as_of, kind, value, source, filing_id, xbrl_frame, note)
                             VALUES (:s, :d, :k, :v, 'filing', (SELECT id FROM filings WHERE issuer_id = :i AND accession_number = :acc), :fr, :note)
                             ON CONFLICT (security_id, kind, as_of, source) DO UPDATE SET value = EXCLUDED.value, filing_id = COALESCE(EXCLUDED.filing_id, share_counts.filing_id), xbrl_frame = EXCLUDED.xbrl_frame,
                                    note = EXCLUDED.note, recorded_at = now()
                              WHERE (share_counts.value, share_counts.xbrl_frame, share_counts.filing_id) IS DISTINCT FROM (EXCLUDED.value, EXCLUDED.xbrl_frame, COALESCE(EXCLUDED.filing_id, share_counts.filing_id))
                             RETURNING id, (xmax = 0) AS inserted");
    foreach ($st->fetchAll() as $i) {
        $r = $client->get($base . 'CIK' . $i['cik'] . '.json', ['accept' => 'application/json', 'max_bytes' => 40 * 1024 * 1024]);
        if ($r['blocked']) {
            return ['status' => 'blocked', 'seen' => $seen, 'new' => $new, 'changed' => $changed, 'error' => 'EDGAR refused us (' . $r['status'] . ') — the user-agent or the rate.', 'detail' => $detail];
        }
        if ($r['skipped']) {
            throw new SiFetchError('robots.txt disallows the companyfacts path.');
        }
        if ($r['status'] === 404) {
            $missing[] = (int) $i['id'];
            continue;
        }
        if (!$r['ok']) {
            throw new SiFetchError('EDGAR answered ' . $r['status'] . ' for the facts of CIK ' . ltrim((string) $i['cik'], '0') . '.');
        }
        $doc = json_decode($r['body'], true);
        if (!is_array($doc) || !isset($doc['facts'])) {
            throw new SiFetchError('EDGAR\'s answer for CIK ' . ltrim((string) $i['cik'], '0') . ' is not a companyfacts document.');
        }
        $seen++;
        $counts = ['outstanding' => 0, 'public_float_value' => 0];
        foreach ($concepts as $concept => [$kind, $unit]) {
            $byEnd = [];
            foreach (($doc['facts']['dei'][$concept]['units'][$unit] ?? []) as $f) {
                if (!isset($f['end'], $f['val']) || !preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) $f['end']) || (float) $f['val'] < 0) {
                    continue;
                }
                $prev = $byEnd[$f['end']] ?? null;
                if ($prev === null || (string) ($f['filed'] ?? '') > (string) ($prev['filed'] ?? '')) {
                    $byEnd[$f['end']] = $f;
                }
            }
            foreach ($byEnd as $end => $f) {
                $upsert->execute(['s' => (int) $i['security_id'], 'd' => $end, 'k' => $kind, 'v' => $f['val'], 'i' => (int) $i['id'], 'acc' => (string) ($f['accn'] ?? ''),
                    'fr' => isset($f['frame']) ? mb_substr((string) $f['frame'], 0, 20) : null, 'note' => 'the cover of the ' . ($f['form'] ?? 'filing') . ' filed ' . ($f['filed'] ?? '?') . ' (dei:' . $concept . ')']);
                $row = $upsert->fetch();
                if ($row !== false) {
                    $row['inserted'] ? $new++ : $changed++;
                    $counts[$kind]++;
                    log_activity($pdo, 'share_count.save', 'share_count', (int) $row['id'], ['issuer_id' => (int) $i['id'], 'security_id' => (int) $i['security_id'],
                        'after' => ['kind' => $kind, 'as_of' => $end, 'value' => (float) $f['val'], 'source' => 'filing', 'frame' => $f['frame'] ?? null]]);
                }
            }
        }
        $detail[(int) $i['id']] = $counts;
    }
    return ['seen' => $seen, 'new' => $new, 'changed' => $changed, 'status' => $missing === [] ? 'ok' : 'partial',
            'error' => $missing === [] ? null : 'No XBRL facts at EDGAR for issuer ' . implode(', ', $missing) . '.', 'detail' => $detail];
}
