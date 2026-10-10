<?php
declare(strict_types=1);

/**
 * Connector `edgar_submissions` (edgar-and-finra-fetchers.md §2). For every issuer with a CIK (or the one issuer `issuer_edgar_sync` names;
 * a source's settings.issuers narrows the set), GET <base_url>CIK##########.json (data.sec.gov/submissions/) under the EDGAR user-agent and
 * keep the ENTITY: issuers.edgar_entity (the scalars, the tickers and exchanges, the addresses, the former names), edgar_synced_at, and the
 * blanks a person left (SIC, state of incorporation, fiscal year end, EIN, the ticker) filled — never a value a person wrote. Slice 1 builds
 * this half; slice 4 adds THE INDEX (filings.recent → filings) through si_edgar_index_apply() when that function exists.
 * THE INDEX (slice 4): filings.recent's column arrays zipped into `filings` by accession (inserted — the events trigger makes a watched form's
 * event — or, when EDGAR changed a field, updated); the older pages filings.files[] names are read once (when the issuer holds fewer filings
 * than EDGAR counts); then the text of up to settings.text_per_pull (12) watched filings without it, newest first.
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
    $changed += (int) ($GLOBALS['si_index_changed'] ?? 0);
    $GLOBALS['si_index_changed'] = 0;
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


/**
 * The filing index of one submissions document (slice 4). Answers the count of filings new to the issuer; $GLOBALS['si_index_changed'] counts
 * the changed. The older pages are read once; the text of the watched filings that lack it is fetched (up to settings.text_per_pull).
 */
function si_edgar_index_apply(PDO $pdo, int $issuer, array $doc, int $pull, SiHttp $client, string $base): int
{
    require_once __DIR__ . '/filing_text.php';
    $pages = [$doc['filings']['recent'] ?? []];
    $files = $doc['filings']['files'] ?? [];
    $edgarCount = count($pages[0]['accessionNumber'] ?? []) + array_sum(array_map(static fn ($f) => (int) ($f['filingCount'] ?? 0), $files));
    $have = (int) one_value($pdo, 'SELECT count(*) FROM filings WHERE issuer_id = :i AND NOT about_us', ['i' => $issuer]);
    if ($files !== [] && $have + count($pages[0]['accessionNumber'] ?? []) < $edgarCount) {
        foreach ($files as $f) {
            $r = $client->get($base . ltrim((string) ($f['name'] ?? ''), '/'), ['accept' => 'application/json']);
            if ($r['ok'] && is_array($j = json_decode($r['body'], true))) {
                $pages[] = $j;
            }
        }
    }
    $cik = str_pad((string) ($doc['cik'] ?? ''), 10, '0', STR_PAD_LEFT);
    $new = 0;
    $changed = 0;
    $up = $pdo->prepare('INSERT INTO filings (issuer_id, filer_cik, accession_number, form, filed_on, report_date, accepted_at, act, file_number, film_number, items, size, is_xbrl, is_inline_xbrl,
                                               primary_document, primary_doc_description, source_pull_id)
                         VALUES (:iss, :cik, :acc, :form, :filed, :rep, :acc_at, :act, :fileno, :film, CAST(:items AS text[]), :size, :xbrl, :ixbrl, :doc, :descr, :pull)
                         ON CONFLICT (issuer_id, accession_number) DO UPDATE SET form = EXCLUDED.form, report_date = EXCLUDED.report_date, items = EXCLUDED.items, size = EXCLUDED.size,
                                primary_document = EXCLUDED.primary_document, primary_doc_description = EXCLUDED.primary_doc_description
                          WHERE (filings.form, filings.report_date, filings.items, filings.size, filings.primary_document, filings.primary_doc_description)
                                IS DISTINCT FROM (EXCLUDED.form, EXCLUDED.report_date, EXCLUDED.items, EXCLUDED.size, EXCLUDED.primary_document, EXCLUDED.primary_doc_description)
                         RETURNING (xmax = 0) AS inserted');
    foreach ($pages as $p) {
        $n = count($p['accessionNumber'] ?? []);
        for ($k = 0; $k < $n; $k++) {
            $acc = (string) $p['accessionNumber'][$k];
            if (!preg_match('~^\d{10}-\d{2}-\d{6}$~', $acc) || !preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) ($p['filingDate'][$k] ?? ''))) {
                continue;
            }
            $items = array_values(array_filter(array_map('trim', explode(',', (string) ($p['items'][$k] ?? '')))));
            $rep = (string) ($p['reportDate'][$k] ?? '');
            $at = (string) ($p['acceptanceDateTime'][$k] ?? '');
            $up->execute(['iss' => $issuer, 'cik' => $cik, 'acc' => $acc, 'form' => mb_substr((string) $p['form'][$k], 0, 40), 'filed' => $p['filingDate'][$k],
                'rep' => preg_match('~^\d{4}-\d{2}-\d{2}$~', $rep) ? $rep : null, 'acc_at' => $at !== '' ? $at : null, 'act' => ($p['act'][$k] ?? '') ?: null,
                'fileno' => ($p['fileNumber'][$k] ?? '') ?: null, 'film' => ($p['filmNumber'][$k] ?? '') ?: null, 'items' => '{' . implode(',', array_map(static fn ($x) => '"' . str_replace('"', '', $x) . '"', $items)) . '}',
                'size' => isset($p['size'][$k]) ? (int) $p['size'][$k] : null, 'xbrl' => !empty($p['isXBRL'][$k]) ? 'true' : 'false', 'ixbrl' => !empty($p['isInlineXBRL'][$k]) ? 'true' : 'false',
                'doc' => ($p['primaryDocument'][$k] ?? '') ?: null, 'descr' => ($p['primaryDocDescription'][$k] ?? '') ?: null, 'pull' => $pull]);
            $row = $up->fetch();
            if ($row !== false) {
                $row['inserted'] ? $new++ : $changed++;
            }
        }
    }
    $GLOBALS['si_index_changed'] = ($GLOBALS['si_index_changed'] ?? 0) + $changed;
    if ($new > 0 || $changed > 0) {
        log_activity($pdo, 'filing.index', 'issuer', $issuer, ['issuer_id' => $issuer, 'after' => ['new' => $new, 'changed' => $changed, 'pull_id' => $pull]]);
    }
    // the watched forms' text on arrival, newest first
    $set = json_decode((string) one_value($pdo, "SELECT settings FROM sources WHERE connector = 'edgar_submissions' ORDER BY id LIMIT 1"), true) ?: [];
    $limit = max(0, min(50, (int) ($set['text_per_pull'] ?? 12)));
    $due = $pdo->prepare('SELECT id FROM filings WHERE issuer_id = :i AND fetched_text_at IS NULL AND error IS NULL AND si_watched_form(form) AND primary_document IS NOT NULL ORDER BY filed_on DESC, id DESC LIMIT ' . $limit);
    $due->execute(['i' => $issuer]);
    foreach ($due->fetchAll(PDO::FETCH_COLUMN) as $fid) {
        $t = si_filing_text_fetch($pdo, $client, (int) $fid);
        if (!empty($t['blocked'])) {
            break;
        }
        if ($t['ok']) {
            log_activity($pdo, 'filing.fetch_text', 'filing', (int) $fid, ['issuer_id' => $issuer, 'after' => ['filing_id' => (int) $fid, 'chars' => (int) $t['chars']]]);
        }
    }
    return $new;
}
