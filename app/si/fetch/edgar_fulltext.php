<?php
declare(strict_types=1);

/**
 * Connector `edgar_fulltext` (edgar-and-finra-fetchers.md §2): EDGAR's full-text search (efts.sec.gov/LATEST/search-index — community-documented)
 * for each watch term in the issuer's own filings (ciks=), and for OTHERS' filings naming the issuer (q="<issuer name>" without ciks), from the
 * last good pull (a week's overlap) or 90 days back, up to 300 hits a query (three pages of 100), at most settings.about_limit (50) new hits a
 * pull from others' filings (each costs a document read). A refused query is noted and the others go on (the pull is partial); a refused later
 * page ends that query's paging. A hit is `<accession>:<file>`: its filing is
 * found or inserted (about_us when the filer is not the issuer; the events trigger makes a watched form's event); its snippet is the term in the
 * HIT document's own words (the search answers no highlight — the document is read once, under the same policy); a watch_hits row per
 * (filing, term); a dilution term PROPOSES an instrument (status proposed, proposed_by watch) once per filing and kind — a person confirms it.
 * Logged: watch.hit and dilution.propose, ids and the term only.
 */

require_once __DIR__ . '/filing_text.php';

/** A watch term → the instrument it proposes (null: a hit, no instrument). */
function si_watch_term_kind(string $term): ?string
{
    $t = strtolower($term);
    return match (true) {
        str_contains($t, 'at-the-market') || str_contains($t, 'at the market') => 'atm',
        str_contains($t, 'equity line') || str_contains($t, 'equity purchase agreement') || str_contains($t, 'standby equity') => 'eloc',
        str_contains($t, 'convertible preferred') => 'convertible_preferred',
        str_contains($t, 'convertible') => 'convertible_note',
        str_contains($t, 'warrant') => 'warrant',
        str_contains($t, 'registered direct') => 'registered_direct',
        str_contains($t, 'rights offering') => 'rights_offering',
        default => null,
    };
}

function si_fetch_edgar_fulltext(PDO $pdo, array $source, SiHttp $client, array $ctx): array
{
    $base = (string) ($source['base_url'] ?: 'https://efts.sec.gov/LATEST/search-index');
    $settings = json_decode((string) $source['settings'], true) ?: [];
    $terms = array_values(array_filter(array_map('strval', (array) ($settings['terms'] ?? []))));
    $forms = implode(',', array_map('strval', (array) ($settings['forms'] ?? [])));
    $only = array_map('intval', (array) ($settings['issuers'] ?? []));
    $now = $ctx['now'] ?? new DateTimeImmutable('now');
    $since = $source['last_ok_at'] !== null ? (new DateTimeImmutable((string) $source['last_ok_at']))->modify('-7 days')->format('Y-m-d') : $now->modify('-90 days')->format('Y-m-d');
    if (isset($ctx['since'])) { $since = (string) $ctx['since']; }
    $today = $now->format('Y-m-d');
    $sql = 'SELECT id, cik, name FROM issuers WHERE deleted_at IS NULL AND cik IS NOT NULL';
    $args = [];
    if (($ctx['issuer'] ?? null) !== null) { $sql .= ' AND id = :one'; $args['one'] = (int) $ctx['issuer']; }
    elseif ($only !== []) { $sql .= ' AND id = ANY (CAST(:only AS bigint[]))'; $args['only'] = '{' . implode(',', $only) . '}'; }
    $st = $pdo->prepare($sql . ' ORDER BY id');
    $st->execute($args);
    $seen = 0; $new = 0; $changed = 0; $detail = []; $errors = [];
    $aboutLimit = max(1, (int) ($settings['about_limit'] ?? 50));
    foreach ($st->fetchAll() as $iss) {
        $queries = [];
        foreach ($terms as $t) { $queries[] = ['term' => $t, 'q' => '"' . $t . '"', 'ciks' => $iss['cik'], 'forms' => $forms]; }
        $name = trim(preg_replace('~[,.]?\s+(INC|CORP|CORPORATION|LTD|LLC|CO|COMPANY|HOLDINGS|PLC)\.?$~i', '', (string) $iss['name']) ?? (string) $iss['name']);
        if (mb_strlen($name) >= 4) {
            $queries[] = ['term' => 'about: ' . $name, 'q' => '"' . $name . '"', 'ciks' => null, 'forms' => ''];
        }
        $hits = 0;
        foreach ($queries as $qq) {
            $aboutNew = 0;
            for ($from = 0; $from < 300; $from += 100) {
                $url = $base . '?' . http_build_query(array_filter(['q' => $qq['q'], 'ciks' => $qq['ciks'], 'forms' => $qq['forms'] ?: null, 'startdt' => $since, 'enddt' => $today, 'from' => $from ?: null], static fn ($v) => $v !== null && $v !== ''));
                $r = $client->get($url, ['accept' => 'application/json']);
                if ($r['blocked']) {
                    return ['status' => 'blocked', 'seen' => $seen, 'new' => $new, 'changed' => $changed, 'error' => 'EDGAR\'s full-text search refused us (' . $r['status'] . ').', 'detail' => $detail];
                }
                if (!$r['ok']) {
                    if ($from === 0) {                                   // a query refused: noted, the others go on (the pull is partial)
                        $errors[] = '“' . $qq['term'] . '” answered ' . $r['status'];
                    }
                    break;                                               // a later page refused: the query's paging ends
                }
                $j = json_decode($r['body'], true);
                $list = $j['hits']['hits'] ?? null;
                if (!is_array($list)) {
                    throw new SiFetchError('EDGAR\'s full-text search answered something that is not its result.');
                }
                foreach ($list as $h) {
                    if (str_starts_with($qq['term'], 'about: ') && $aboutNew >= $aboutLimit) {
                        break 2;                                        // others' filings naming the issuer: at most about_limit new a pull
                    }
                    $seen++;
                    $n = si_watch_hit_apply($pdo, $client, $iss, $qq['term'], $h, (int) $ctx['pull']);
                    $new += $n['new'];
                    $hits += $n['new'];
                    if (str_starts_with($qq['term'], 'about: ')) { $aboutNew += $n['new']; }
                }
                if (count($list) < 100) {
                    break;
                }
            }
        }
        $detail[(int) $iss['id']] = ['new_hits' => $hits];
    }
    if ($errors !== [] && $seen === 0) {
        throw new SiFetchError('EDGAR\'s full-text search failed: ' . implode('; ', array_slice($errors, 0, 3)) . '.');
    }
    return ['seen' => $seen, 'new' => $new, 'changed' => $changed, 'detail' => $detail, 'status' => $errors === [] ? 'ok' : 'partial',
            'error' => $errors === [] ? null : 'EDGAR\'s full-text search refused ' . count($errors) . ' quer' . (count($errors) === 1 ? 'y' : 'ies') . ': ' . implode('; ', array_slice($errors, 0, 3)) . '.'];
}

/** One search hit: the filing (found or inserted), the watch hit, the snippet from the hit document, the proposed instrument. Answers ['new' => 0|1]. */
function si_watch_hit_apply(PDO $pdo, SiHttp $client, array $iss, string $term, array $h, int $pull): array
{
    $src = $h['_source'] ?? [];
    [$adsh, $file] = array_pad(explode(':', (string) ($h['_id'] ?? ''), 2), 2, '');
    $adsh = (string) ($src['adsh'] ?? $adsh);
    if (!preg_match('~^\d{10}-\d{2}-\d{6}$~', $adsh) || !preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) ($src['file_date'] ?? ''))) {
        return ['new' => 0];
    }
    $ciks = array_values(array_map(static fn ($c) => str_pad((string) $c, 10, '0', STR_PAD_LEFT), (array) ($src['ciks'] ?? [])));
    $ours = in_array((string) $iss['cik'], $ciks, true);
    $about = str_starts_with($term, 'about: ');
    if ($ours === $about) {
        return ['new' => 0];       // a term hit is the issuer's own filing; the about-us query keeps only filings that do not list the issuer (a 13D or a Form 4 is in its index already)
    }
    $filer = $ours ? (string) $iss['cik'] : ($ciks[0] ?? null);
    if ($filer === null) {
        return ['new' => 0];
    }
    $fid = one_value($pdo, 'SELECT id FROM filings WHERE issuer_id = :i AND accession_number = :a', ['i' => (int) $iss['id'], 'a' => $adsh]);
    if ($fid === null) {
        $form = (string) ($src['form'] ?? ($src['root_forms'][0] ?? 'other'));
        $items = array_values(array_filter(array_map('strval', (array) ($src['items'] ?? []))));
        $st = $pdo->prepare('INSERT INTO filings (issuer_id, filer_cik, accession_number, form, filed_on, report_date, items, primary_document, primary_doc_description, about_us, source_pull_id)
                             VALUES (:i, :c, :a, :f, :d, :r, CAST(:items AS text[]), :doc, :descr, :about, :pull) ON CONFLICT (issuer_id, accession_number) DO NOTHING RETURNING id');
        $st->execute(['i' => (int) $iss['id'], 'c' => $filer, 'a' => $adsh, 'f' => mb_substr($form, 0, 40), 'd' => $src['file_date'], 'r' => preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) ($src['period_ending'] ?? '')) ? $src['period_ending'] : null,
            'items' => '{' . implode(',', array_map(static fn ($x) => '"' . str_replace('"', '', $x) . '"', $items)) . '}', 'doc' => $file !== '' ? $file : null,
            'descr' => isset($src['file_description']) ? mb_substr((string) $src['file_description'], 0, 200) : null, 'about' => $ours ? 'false' : 'true', 'pull' => $pull]);
        $fid = $st->fetchColumn() ?: one_value($pdo, 'SELECT id FROM filings WHERE issuer_id = :i AND accession_number = :a', ['i' => (int) $iss['id'], 'a' => $adsh]);
    }
    $fid = (int) $fid;
    $hitTerm = str_starts_with($term, 'about: ') ? 'about us' : $term;
    if (one_value($pdo, 'SELECT 1 FROM watch_hits WHERE issuer_id = :i AND filing_id = :f AND term = :t', ['i' => (int) $iss['id'], 'f' => $fid, 't' => $hitTerm])) {
        return ['new' => 0];
    }
    // the snippet: the term in the hit document's own words
    $snippet = null;
    if ($file !== '') {
        $r = $client->get(si_archives_url($pdo, $filer, $adsh, $file), ['accept' => 'text/html, application/xml, text/plain']);
        if ($r['ok']) {
            try {
                [$text] = si_document_text($file, $r['body']);
                $snippet = si_text_snippet($text, $hitTerm === 'about us' ? substr($term, 7) : $hitTerm);
            } catch (Throwable) {
                $snippet = null;
            }
        }
    }
    if ($snippet === null) {                                             // the term not in that document: the filing's own text, when it is read
        $ft = one_value($pdo, 'SELECT text FROM filing_text WHERE filing_id = :f', ['f' => $fid]);
        $snippet = $ft === null ? null : si_text_snippet((string) $ft, $hitTerm === 'about us' ? substr($term, 7) : $hitTerm);
    }
    $snippet ??= trim(implode(' · ', array_filter([(string) ($src['form'] ?? ''), (string) ($src['file_description'] ?? ''), implode(', ', (array) ($src['display_names'] ?? []))])));
    $st = $pdo->prepare('INSERT INTO watch_hits (issuer_id, filing_id, term, snippet) VALUES (:i, :f, :t, :s) ON CONFLICT (issuer_id, filing_id, term) DO NOTHING RETURNING id');
    $st->execute(['i' => (int) $iss['id'], 'f' => $fid, 't' => $hitTerm, 's' => mb_substr((string) $snippet, 0, 600)]);
    $hid = $st->fetchColumn();
    if ($hid === false) {
        return ['new' => 0];
    }
    log_activity($pdo, 'watch.hit', 'watch_hit', (int) $hid, ['issuer_id' => (int) $iss['id'], 'after' => ['term' => $hitTerm, 'filing_id' => $fid, 'about_us' => !$ours]]);
    $kind = $hitTerm === 'about us' ? null : si_watch_term_kind($hitTerm);
    if ($kind !== null && !one_value($pdo, 'SELECT 1 FROM dilution_instruments WHERE issuer_id = :i AND filing_id = :f AND kind = :k', ['i' => (int) $iss['id'], 'f' => $fid, 'k' => $kind])) {
        $st = $pdo->prepare("INSERT INTO dilution_instruments (issuer_id, security_id, kind, filed_on, filing_id, status, proposed_by, watch_term, notes)
                             VALUES (:i, (SELECT id FROM securities WHERE issuer_id = :i AND is_primary LIMIT 1), :k, :d, :f, 'proposed', 'watch', :t, :n) RETURNING id");
        $st->execute(['i' => (int) $iss['id'], 'k' => $kind, 'd' => $src['file_date'], 'f' => $fid, 't' => $hitTerm, 'n' => $snippet === null ? null : 'The watch found: ' . mb_substr((string) $snippet, 0, 500)]);
        $did = (int) $st->fetchColumn();
        log_activity($pdo, 'dilution.propose', 'dilution_instrument', $did, ['issuer_id' => (int) $iss['id'], 'after' => ['kind' => $kind, 'filing_id' => $fid, 'term' => $hitTerm, 'watch_hit_id' => (int) $hid]]);
    }
    return ['new' => 1];
}
