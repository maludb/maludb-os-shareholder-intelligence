<?php
declare(strict_types=1);

/**
 * Connector `edgar_13f_dataset` (edgar-and-finra-fetchers.md §2; D9): the SEC's quarterly Form 13F data sets. The data sets' page
 * (settings.listing_url, default https://www.sec.gov/data-research/sec-markets-data/form-13f-data-sets) is read for the zips' links — each covers
 * three months of FILING dates ("01mar2024-31may2024_form13f.zip"); the zips not yet loaded (settings.loaded) are read, oldest first — the newest
 * `backfill` (2) when none is loaded yet, else every newer one. A zip is read whole into a file (no cache) and opened: SUBMISSION.tsv (the filer's
 * CIK and the filing date), COVERPAGE.tsv (the period, the amendment and its type, the manager's name) and INFOTABLE.tsv streamed — only the
 * tracked securities' CUSIPs kept, a filer's several rows for one CUSIP summed (by accession and put/call). VALUE is dollars from 2023-01-03,
 * thousands before (scaled). A RESTATEMENT amendment replaces the filer's rows for its period; NEW HOLDINGS adds. holding.load per zip.
 * A data set runs to tens of megabytes: fetch_now queues it for the worker rather than reading it in a request.
 */

require_once __DIR__ . '/ownership_common.php';

/** The zips on the page, newest first: [name => url, …] with their window's end date. */
function si_13f_zips(string $html, string $base): array
{
    $out = [];
    if (preg_match_all('~href="([^"]*?(\d{2}[a-z]{3}\d{4})-(\d{2}[a-z]{3}\d{4})_form13f\.zip)"~i', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            $end = strtotime($x[3]);
            $out[basename($x[1])] = ['url' => si_http_resolve($base, $x[1]), 'end' => $end === false ? '0' : date('Y-m-d', $end)];
        }
    }
    uasort($out, static fn ($a, $b) => strcmp($b['end'], $a['end']));
    return $out;
}

function si_fetch_edgar_13f_dataset(PDO $pdo, array $source, SiHttp $client, array $ctx): array
{
    $settings = json_decode((string) $source['settings'], true) ?: [];
    $listing = (string) ($settings['listing_url'] ?? 'https://www.sec.gov/data-research/sec-markets-data/form-13f-data-sets');
    $loaded = array_values(array_map('strval', (array) ($settings['loaded'] ?? [])));
    $r = $client->get($listing, ['accept' => 'text/html']);
    if ($r['blocked']) {
        return ['status' => 'blocked', 'seen' => 0, 'new' => 0, 'changed' => 0, 'error' => 'The SEC refused the 13F page (' . $r['status'] . ').'];
    }
    if ($r['skipped']) {
        throw new SiFetchError('robots.txt disallows the 13F page.');
    }
    if (!$r['ok']) {
        throw new SiFetchError('The SEC answered ' . $r['status'] . ' for the 13F data sets\' page.');
    }
    $zips = si_13f_zips($r['body'], $listing);
    if ($zips === []) {
        throw new SiFetchError('The 13F data sets\' page links no data set (the page changed?).');
    }
    $tracked = [];
    foreach ($pdo->query("SELECT id, upper(cusip) AS cusip FROM securities WHERE cusip IS NOT NULL AND cusip <> ''")->fetchAll() as $s) {
        $tracked[$s['cusip']] = (int) $s['id'];
    }
    if ($loaded === []) {
        $todo = array_slice($zips, 0, max(1, (int) ($settings['backfill'] ?? 2)), true);
    } else {
        $newestLoaded = max(array_map(static fn ($n) => $zips[$n]['end'] ?? '0', $loaded));
        $todo = array_filter($zips, static fn ($z, $n) => !in_array($n, $loaded, true) && $z['end'] > $newestLoaded, ARRAY_FILTER_USE_BOTH);
    }
    $todo = array_reverse($todo, true);                                   // oldest first: the prior quarter before the next
    if (!empty($ctx['listing_only'])) {
        return ['seen' => count($zips), 'new' => 0, 'changed' => 0, 'detail' => ['zips' => array_keys($zips), 'would_read' => array_keys($todo)]];
    }
    $seen = 0; $new = 0; $changed = 0; $detail = [];
    foreach ($todo as $name => $z) {
        $resp = $client->get($z['url'], ['accept' => 'application/zip', 'max_bytes' => 900 * 1024 * 1024, 'timeout' => 900, 'no_cache' => true]);
        if ($resp['blocked']) {
            return ['status' => 'blocked', 'seen' => $seen, 'new' => $new, 'changed' => $changed, 'error' => 'The SEC refused ' . $name . ' (' . $resp['status'] . ').', 'detail' => $detail];
        }
        if (!$resp['ok']) {
            throw new SiFetchError('The SEC answered ' . $resp['status'] . ' for ' . $name . '.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'si13f');
        file_put_contents($tmp, $resp['body']);
        unset($resp);
        try {
            $res = si_13f_load_zip($pdo, $tmp, $tracked, $name);
        } finally {
            @unlink($tmp);
        }
        $seen++;
        $new += $res['new'];
        $changed += $res['changed'];
        $detail[$name] = $res;
        $loaded[] = $name;
        $pdo->prepare("UPDATE sources SET settings = settings || jsonb_build_object('loaded', CAST(:l AS jsonb)) WHERE id = :s")->execute(['l' => json_encode(array_values(array_unique($loaded))), 's' => (int) $source['id']]);
    }
    return ['seen' => $seen, 'new' => $new, 'changed' => $changed, 'detail' => $detail];
}

/** One data set's zip into institutional_holdings (the tracked CUSIPs only). */
function si_13f_load_zip(PDO $pdo, string $file, array $tracked, string $name): array
{
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) {
        throw new SiFetchError($name . ' is not a readable zip.');
    }
    $tsv = static function (string $entry) use ($zip, $name): Generator {
        $h = $zip->getStream($entry) ?: $zip->getStream(strtolower($entry));
        if ($h === false) {
            throw new SiFetchError($name . ' has no ' . $entry . '.');
        }
        $head = null;
        while (($line = fgets($h)) !== false) {
            $cells = explode("\t", rtrim($line, "\r\n"));
            if ($head === null) { $head = array_map('strtoupper', $cells); continue; }
            yield array_combine($head, array_pad(array_slice($cells, 0, count($head)), count($head), ''));
        }
        fclose($h);
    };
    $sub = [];
    foreach ($tsv('SUBMISSION.tsv') as $r) {
        $sub[$r['ACCESSION_NUMBER']] = ['cik' => str_pad($r['CIK'], 10, '0', STR_PAD_LEFT), 'filed' => si_date_any($r['FILING_DATE']), 'type' => $r['SUBMISSIONTYPE']];
    }
    $cover = [];
    foreach ($tsv('COVERPAGE.tsv') as $r) {
        $cover[$r['ACCESSION_NUMBER']] = ['period' => si_date_any($r['REPORTCALENDARORQUARTER']), 'amend' => strtoupper($r['ISAMENDMENT']) === 'Y', 'type' => strtoupper($r['AMENDMENTTYPE'] ?? ''),
                                          'no' => $r['AMENDMENTNO'] ?? '', 'name' => $r['FILINGMANAGER_NAME'] ?? ''];
    }
    $agg = [];
    foreach ($tsv('INFOTABLE.tsv') as $r) {
        $cusip = strtoupper(trim($r['CUSIP'] ?? ''));
        if (!isset($tracked[$cusip]) || !isset($sub[$r['ACCESSION_NUMBER']], $cover[$r['ACCESSION_NUMBER']])) {
            continue;
        }
        $pc = strtoupper(trim($r['PUTCALL'] ?? ''));
        $k = $r['ACCESSION_NUMBER'] . '|' . $cusip . '|' . $pc;
        $a = $agg[$k] ?? ['acc' => $r['ACCESSION_NUMBER'], 'sec' => $tracked[$cusip], 'cusip' => $cusip, 'pc' => in_array($pc, ['PUT', 'CALL'], true) ? $pc : null, 'value' => 0.0, 'shares' => 0.0, 'vs' => 0.0, 'vsh' => 0.0, 'vn' => 0.0,
                                'type' => strtoupper($r['SSHPRNAMTTYPE'] ?? 'SH') === 'PRN' ? 'PRN' : 'SH', 'disc' => in_array($d = strtoupper($r['INVESTMENTDISCRETION'] ?? ''), ['SOLE', 'DEFINED', 'OTHER'], true) ? $d : null,
                                'other' => $r['OTHERMANAGER'] ?? '', 'name' => $r['NAMEOFISSUER'] ?? null, 'class' => $r['TITLEOFCLASS'] ?? null, 'figi' => ($r['FIGI'] ?? '') ?: null];
        $a['value'] += (float) ($r['VALUE'] ?? 0);
        $a['shares'] += (float) ($r['SSHPRNAMT'] ?? 0);
        $a['vs'] += (float) ($r['VOTING_AUTH_SOLE'] ?? 0);
        $a['vsh'] += (float) ($r['VOTING_AUTH_SHARED'] ?? 0);
        $a['vn'] += (float) ($r['VOTING_AUTH_NONE'] ?? 0);
        $agg[$k] = $a;
    }
    $zip->close();
    $new = 0; $changed = 0; $periods = []; $insts = []; $made = 0;
    $known = array_flip($pdo->query('SELECT cik FROM institutions')->fetchAll(PDO::FETCH_COLUMN));
    $pdo->beginTransaction();
    $up = $pdo->prepare("INSERT INTO institutional_holdings (institution_id, security_id, period_end, accession_number, value_dollars, shares, shares_type, put_call, discretion, other_managers, voting_sole, voting_shared, voting_none,
                                is_amendment, name_of_issuer, title_of_class, cusip, figi)
                         VALUES (:i, :s, :p, :acc, :v, :sh, :t, :pc, :d, :o, :vs, :vsh, :vn, :am, :n, :c, :cu, :figi)
                         ON CONFLICT (institution_id, security_id, period_end, (COALESCE(accession_number, '')), (COALESCE(put_call, ''))) DO UPDATE SET value_dollars = EXCLUDED.value_dollars, shares = EXCLUDED.shares,
                                voting_sole = EXCLUDED.voting_sole, voting_shared = EXCLUDED.voting_shared, voting_none = EXCLUDED.voting_none
                          WHERE (institutional_holdings.shares, institutional_holdings.value_dollars) IS DISTINCT FROM (EXCLUDED.shares, EXCLUDED.value_dollars)
                         RETURNING (xmax = 0) AS inserted");
    foreach ($agg as $a) {
        $s = $sub[$a['acc']];
        $c = $cover[$a['acc']];
        if ($c['period'] === null) {
            continue;
        }
        $inst = (int) one_value($pdo, 'SELECT si_institution_upsert(:c, :n)', ['c' => $s['cik'], 'n' => $c['name'] !== '' ? $c['name'] : 'CIK ' . ltrim($s['cik'], '0')]);
        if (!isset($known[$s['cik']])) { $known[$s['cik']] = true; $made++; }     // counted in holding.load, not logged one by one (a data set makes hundreds)
        if ($c['amend'] && $c['type'] === 'RESTATEMENT') {                  // a restatement replaces the filer's rows for its period
            $pdo->prepare('DELETE FROM institutional_holdings WHERE institution_id = :i AND security_id = :s AND period_end = :p AND COALESCE(accession_number, \'\') <> :acc')
                ->execute(['i' => $inst, 's' => $a['sec'], 'p' => $c['period'], 'acc' => $a['acc']]);
        }
        $scale = ($s['filed'] ?? '2023-01-03') < '2023-01-03' ? 1000 : 1;
        $up->execute(['i' => $inst, 's' => $a['sec'], 'p' => $c['period'], 'acc' => $a['acc'], 'v' => $a['value'] * $scale, 'sh' => $a['shares'], 't' => $a['type'], 'pc' => $a['pc'], 'd' => $a['disc'],
            'o' => $a['other'] === '' ? null : mb_substr($a['other'], 0, 200), 'vs' => $a['vs'], 'vsh' => $a['vsh'], 'vn' => $a['vn'], 'am' => $c['amend'] ? 'true' : 'false', 'n' => $a['name'], 'c' => $a['class'], 'cu' => $a['cusip'], 'figi' => $a['figi']]);
        $row = $up->fetch();
        if ($row !== false) {
            $row['inserted'] ? $new++ : $changed++;
        }
        $periods[$c['period']] = true;
        $insts[$inst] = true;
    }
    log_activity($pdo, 'holding.load', 'source', null, ['after' => ['data_set' => $name, 'periods' => array_keys($periods), 'rows' => count($agg), 'institutions' => count($insts), 'institutions_new' => $made, 'new' => $new, 'changed' => $changed]]);
    $pdo->commit();
    return ['rows' => count($agg), 'new' => $new, 'changed' => $changed, 'institutions' => count($insts), 'periods' => array_keys($periods)];
}
