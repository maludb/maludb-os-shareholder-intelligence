<?php
declare(strict_types=1);

/**
 * Connector `dtcc_participants` (edgar-and-finra-fetchers.md §2; slice 1): DTCC's public participant directory. The directory page
 * (sources.base_url — https://www.dtcc.com/support/dtc-directories) links the month's files; the participant listing ("DTC Participant in
 * Alphabetical Listing - MM.DD.YYYY.xlsx", else the numerical one, else a CSV of the same two columns) is read and every row upserted by
 * si_participant_upsert(number, name, 'participant', file date): a new number classed from its name, a renamed one keeping its class with the
 * old name as an alias, a manual class never touched. The settling-bank, pledgee and DRS listings number their own registers (a pledgee's
 * number can be a participant's) and are not read in version 1 — the SPR names participants only.
 * The file's layout (2026-09-30): a few title rows ("Run Time:…"), a header naming the columns ("Participant Account Name", "No"), then one
 * row per participant — the name padded with spaces, the number as four characters; either column order. A file that yields no participant
 * is a failure in words, never an empty directory.
 */

function si_fetch_dtcc_participants(PDO $pdo, array $source, SiHttp $client, array $ctx): array
{
    $page = (string) ($source['base_url'] ?: 'https://www.dtcc.com/support/dtc-directories');
    $r = $client->get($page, ['accept' => 'text/html']);
    if ($r['skipped']) {
        throw new SiFetchError('robots.txt disallows the directory page.');
    }
    if ($r['blocked']) {
        return ['status' => 'blocked', 'error' => 'DTCC refused the directory page (' . $r['status'] . ').'];
    }
    if (!$r['ok']) {
        throw new SiFetchError('The directory page answered ' . $r['status'] . '.');
    }
    $file = si_dtcc_pick_file($r['body'], $r['url']);
    if ($file === null) {
        throw new SiFetchError('The directory page links no participant listing.');
    }
    $f = $client->get($file['url'], ['accept' => '*/*', 'max_bytes' => 20 * 1024 * 1024]);
    if ($f['blocked']) {
        return ['status' => 'blocked', 'error' => 'DTCC refused the listing (' . $f['status'] . ').'];
    }
    if (!$f['ok']) {
        throw new SiFetchError('The participant listing answered ' . $f['status'] . '.');
    }
    $rows = si_dtcc_rows($f['body']);
    if (count($rows) < 1) {
        throw new SiFetchError('The participant listing holds no participant.');
    }
    $seen = $file['date'] ?? $ctx['now']->format('Y-m-d');
    $counts = ['inserted' => 0, 'renamed' => 0, 'unchanged' => 0];
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT si_participant_upsert(:n, :name, :k, CAST(:d AS date))');
        foreach ($rows as [$number, $name]) {
            $st->execute(['n' => $number, 'name' => $name, 'k' => 'participant', 'd' => $seen]);
            $counts[(string) $st->fetchColumn()]++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    log_activity($pdo, 'participant.directory_sync', 'source', (int) $source['id'], ['actor_member_id' => $ctx['by'] ?? null, 'after' => $counts + ['file_date' => $seen, 'rows' => count($rows)]]);
    return ['seen' => count($rows), 'new' => $counts['inserted'], 'changed' => $counts['renamed'], 'detail' => $counts + ['file_date' => $seen]];
}

/** The participant listing among the page's links: the alphabetical one, else the numerical one, else a CSV; with its date when the name has one. */
function si_dtcc_pick_file(string $html, string $base): ?array
{
    preg_match_all('~href\s*=\s*["\']([^"\']+)["\']~i', $html, $m);
    $best = null;
    foreach ($m[1] as $href) {
        $url = si_http_resolve($base, html_entity_decode($href, ENT_QUOTES | ENT_HTML5));
        $name = urldecode(str_replace('+', ' ', $url));
        if (!preg_match('~participant~i', $name) || !preg_match('~\.(xlsx|csv)(/|$|\?)~i', $name)) {
            continue;
        }
        $rank = preg_match('~alphabetic~i', $name) ? 1 : (preg_match('~numeric~i', $name) ? 2 : 3);
        $date = preg_match('~(\d{2})\.(\d{2})\.(\d{4})~', $name, $d) ? $d[3] . '-' . $d[1] . '-' . $d[2] : null;
        if ($best === null || $rank < $best['rank']) {
            $best = ['url' => $url, 'rank' => $rank, 'date' => $date];
        }
    }
    return $best;
}

/** The listing's participants as [[number, name], …]: the rows with a four-digit number in one cell and a name in another. */
function si_dtcc_rows(string $bytes): array
{
    if (str_starts_with($bytes, "PK\x03\x04")) {
        require_once dirname(__DIR__) . '/xlsx.php';
        $sheets = si_xlsx_sheets($bytes);
        $rows = (array) (reset($sheets) ?: []);
    } else {
        $rows = [];
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $bytes);
        rewind($fh);
        while (($r = fgetcsv($fh, 0, ',', '"', '\\')) !== false) { $rows[] = $r; }
        fclose($fh);
    }
    $out = [];
    foreach ($rows as $r) {
        $cells = array_values(array_map(static fn ($c): string => trim(preg_replace('~\s+~', ' ', (string) $c) ?? ''), (array) $r));
        $num = null;
        $name = null;
        foreach ($cells as $c) {
            if ($num === null && preg_match('~^\d{1,4}$~', $c)) {
                $num = str_pad($c, 4, '0', STR_PAD_LEFT);
            } elseif ($name === null && $c !== '' && preg_match('~[A-Za-z]~', $c)) {
                $name = $c;
            }
        }
        if ($num !== null && $name !== null && !preg_match('~^(no\.?|number|participant)~i', $name)) {
            $out[$num] = [$num, $name];
        }
    }
    return array_values($out);
}
