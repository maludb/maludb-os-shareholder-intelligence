<?php
declare(strict_types=1);

/**
 * Slice 3's loaders (holder-identity.md §1–§2): a parsed NOBO list, register or Share Range Analysis written to its tables inside
 * upload_ingest()'s transaction, its tie recorded from the file's own statements; after the commit a usable NOBO list or register is matched
 * (si_match_import — the database decides every score) and the reconciliation runs as of its record date. One upload of a kind per security
 * and record date: a second is refused at upload_create() naming the first (a holder's position on a date is the sum of its rows on that
 * date's list — two lists of one date would count it twice). Logged with ids and counts only — never a name, an address or a line.
 */

/** The record date an upload is for: the file's own, else the one the person gave, else the file name's (06-30-24, 06302024, 2024-06-30). */
function upload_record_date(?string $fromFile, ?string $given, string $filename): ?string
{
    if ($fromFile !== null) {
        return $fromFile;
    }
    if ($given !== null && $given !== '') {
        return $given;
    }
    if (preg_match('~(?<!\d)(\d{4})-(\d{2})-(\d{2})(?!\d)~', $filename, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return "$m[1]-$m[2]-$m[3]";
    }
    if (preg_match('~(?<!\d)(\d{2})[-_.](\d{2})[-_.](\d{2}|\d{4})(?!\d)~', $filename, $m) || preg_match('~(?<!\d)(\d{2})(\d{2})(\d{4})(?!\d)~', $filename, $m)) {
        $y = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
        if (checkdate((int) $m[1], (int) $m[2], $y)) {
            return sprintf('%04d-%02d-%02d', $y, $m[1], $m[2]);
        }
    }
    return null;
}

/** The bytes the store keeps: a transfer agent's extract without its never-stored columns; a names export without its contact columns. */
function upload_bytes_to_store(PDO $pdo, string $connector, string $bytes): string
{
    if ($connector === 'ta_extract_csv') {
        $drop = json_decode((string) one_value($pdo, "SELECT mapping -> 'drop_columns' FROM import_templates WHERE connector = 'ta_extract_csv' ORDER BY id LIMIT 1"), true) ?: [];
        return si_strip_csv_columns($bytes, $drop);
    }
    if ($connector === 'broadridge_nobo_names_xlsx') {
        require_once dirname(__DIR__, 2) . '/si/parse/broadridge_nobo_names.php';
        return si_names_export_kept($bytes);
    }
    return $bytes;
}

/** A client of the Bank/Broker listing (or a custodian column) → a participant: its name or alias, the name without punctuation, the custodian words. */
function upload_participant_for(PDO $pdo, string $name): ?int
{
    $id = one_value($pdo, "SELECT COALESCE(si_participant_find(NULL, :n),
                              (SELECT id FROM participants WHERE regexp_replace(upper(name), '[^A-Z0-9]', '', 'g') = regexp_replace(upper(:n), '[^A-Z0-9]', '', 'g')
                                  OR regexp_replace(upper(:n), '[^A-Z0-9]', '', 'g') = ANY (ARRAY(SELECT regexp_replace(upper(a), '[^A-Z0-9]', '', 'g') FROM unnest(aliases) a)) ORDER BY number LIMIT 1),
                              si_custodian_from_lines(:n))", ['n' => $name]);
    return $id === null || $id === false ? null : (int) $id;
}

/** Remove what an earlier parse of this upload wrote (its holder positions, its rows' proposals, its rows). */
function upload_clear_rows(PDO $pdo, int $id, string $kind): void
{
    $pdo->prepare('DELETE FROM holder_positions WHERE import_id = :i')->execute(['i' => $id]);
    if ($kind === 'nobo') {
        $pdo->prepare("DELETE FROM holder_match_proposals WHERE row_kind = 'nobo' AND row_id IN (SELECT id FROM nobo_rows WHERE import_id = :i)")->execute(['i' => $id]);
        $pdo->prepare('DELETE FROM nobo_jobs WHERE import_id = :i')->execute(['i' => $id]);
    } elseif ($kind === 'register') {
        $pdo->prepare("DELETE FROM holder_match_proposals WHERE row_kind = 'register' AND row_id IN (SELECT id FROM register_rows WHERE import_id = :i)")->execute(['i' => $id]);
        $pdo->prepare('DELETE FROM register_rows WHERE import_id = :i')->execute(['i' => $id]);
    } elseif ($kind === 'share_range') {
        $pdo->prepare('DELETE FROM share_range_reports WHERE import_id = :i')->execute(['i' => $id]);
    }
}

/** The upload's row recorded as read: its tie, its totals, its date. */
function upload_finish(PDO $pdo, int $id, array $parsed, string $rd, int $made, int $failed): void
{
    $pdo->prepare("UPDATE imports SET status = 'done', finished_at = now(), tied = :t, tie_note = :n, rows_made = :m, rows_failed = :f, record_date = :rd, period_from = :rd, period_to = :rd,
                          derived_totals = CAST(:d AS jsonb) WHERE id = :i")
        ->execute(['t' => $parsed['tie'][0] ? 'true' : 'false', 'n' => $parsed['tie'][1], 'm' => $made, 'f' => $failed, 'rd' => $rd, 'd' => json_encode($parsed['derived'] ?? new stdClass()), 'i' => $id]);
}

/** A NOBO list (the job file or the names export) into nobo_jobs, nobo_rows and nobo_broker_rows. Answers [made, failed]. */
function upload_load_nobo(PDO $pdo, int $id, array $im, array $parsed, string $rd): array
{
    upload_clear_rows($pdo, $id, 'nobo');
    $j = $parsed['job'];
    $st = $pdo->prepare('INSERT INTO nobo_jobs (import_id, security_id, job_number, cusip, issuer_name_as_filed, record_date, sort_order, nobo_count_header, nobo_count_rows, shares_total)
                         VALUES (:i, :s, :jn, :c, :n, :rd, :so, :h, :r, :t) RETURNING id');
    $st->execute(['i' => $id, 's' => (int) $im['security_id'], 'jn' => $j['job_number'], 'c' => $j['cusip'], 'n' => $j['issuer_name'] === null ? null : mb_substr($j['issuer_name'], 0, 200), 'rd' => $rd,
                  'so' => $j['sort_order'] === null ? null : mb_substr($j['sort_order'], 0, 20), 'h' => $j['nobo_count_header'], 'r' => (int) $parsed['derived']['nobo_count'],
                  't' => (string) intdiv((int) round((float) $parsed['derived']['shares'] * 100000) + 50000, 100000)]);
    $job = (int) $st->fetchColumn();
    $ins = $pdo->prepare('INSERT INTO nobo_rows (job_id, import_id, row_no, shares, line1, line2, line3, line4, line5, line6, line7, zip_sort, cusip, custodian_participant_id, raw, error)
                          VALUES (:j, :i, :r, :s, :l1, :l2, :l3, :l4, :l5, :l6, :l7, :z, :c, :cust, CAST(:raw AS jsonb), :e)');
    $custodians = [];
    $failed = 0;
    foreach ($parsed['rows'] as $row) {
        [$rowNo, $shares, $lines, $zip, $cusip, $err, $cell] = $row;
        $cust = null;
        if (isset($row[7]) && $row[7] !== null) {
            $cust = $custodians[$row[7]] ??= upload_participant_for($pdo, (string) $row[7]);
        }
        $l = array_map(static fn ($x) => $x === '' ? null : mb_substr((string) $x, 0, 200), $lines);
        $ins->execute(['j' => $job, 'i' => $id, 'r' => $rowNo, 's' => $shares, 'l1' => $l[0], 'l2' => $l[1], 'l3' => $l[2], 'l4' => $l[3], 'l5' => $l[4], 'l6' => $l[5], 'l7' => $l[6],
                       'z' => $zip === '' ? null : mb_substr((string) $zip, 0, 20), 'c' => $cusip ?: null, 'cust' => $cust,
                       'raw' => json_encode(['shares' => mb_substr((string) $cell, 0, 30)] + (isset($row[8]) ? ['country' => $row[8]] : [])), 'e' => $err]);
        $failed += $err === null ? 0 : 1;
    }
    $bins = $pdo->prepare('INSERT INTO nobo_broker_rows (job_id, row_no, client, participant_id, nobo_count, shares) VALUES (:j, :r, :c, :p, :n, :s)');
    foreach ($parsed['clients'] as [$rowNo, $client, $count, $shares]) {
        $bins->execute(['j' => $job, 'r' => $rowNo, 'c' => mb_substr($client, 0, 200), 'p' => upload_participant_for($pdo, $client), 'n' => $count, 's' => $shares]);
    }
    upload_finish($pdo, $id, $parsed, $rd, count($parsed['rows']) - $failed, $failed);
    return [count($parsed['rows']) - $failed, $failed];
}

/** A register (the agent's extract or STC's position detail) into register_rows. Answers [made, failed]. */
function upload_load_register(PDO $pdo, int $id, array $im, array $parsed, string $rd): array
{
    upload_clear_rows($pdo, $id, 'register');
    $ins = $pdo->prepare('INSERT INTO register_rows (import_id, security_id, record_date, row_no, section, position_id, position_type, holder_ref, registration_name, address_line, city, state, country, zip,
                                                     issue_date, shares, cost_basis, restriction_text, percent_owned, is_employee, is_officer_director, is_us_citizen, raw, error)
                          VALUES (:i, :s, :rd, :r, :sec, :pid, :pt, :hr, :rn, :al, :ci, :st, :co, :z, :id, :sh, :cb, :rt, :po, :ie, :io, :iu, CAST(:raw AS jsonb), :e)');
    $failed = 0;
    $b = static fn (?bool $v): ?string => $v === null ? null : ($v ? 'true' : 'false');
    $cut = static fn (?string $v, int $n = 200): ?string => $v === null ? null : mb_substr($v, 0, $n);
    foreach ($parsed['rows'] as $r) {
        $ins->execute(['i' => $id, 's' => (int) $im['security_id'], 'rd' => $rd, 'r' => $r['row_no'], 'sec' => $cut($r['section']), 'pid' => $cut($r['position_id'], 40), 'pt' => $cut($r['position_type'], 40),
                       'hr' => $cut($r['holder_ref'], 60), 'rn' => $cut($r['registration_name'], 600), 'al' => $cut($r['address_line'], 300), 'ci' => $cut($r['city'], 120), 'st' => $cut($r['state'], 40),
                       'co' => $cut($r['country'], 60), 'z' => $cut($r['zip'], 20), 'id' => $r['issue_date'], 'sh' => $r['shares'], 'cb' => $r['cost_basis'], 'rt' => $cut($r['restriction_text'], 300),
                       'po' => $r['percent_owned'], 'ie' => $b($r['is_employee']), 'io' => $b($r['is_officer_director']), 'iu' => $b($r['is_us_citizen']), 'raw' => json_encode($r['raw']), 'e' => $r['error']]);
        $failed += $r['error'] === null ? 0 : 1;
    }
    // what a Viewer may read of it (the counts, never a row): the shares by position type, the Cede line, the holders listed
    $byType = [];
    $cede = 0;
    foreach ($parsed['rows'] as $r) {
        if ($r['error'] !== null || $r['shares'] === null) {
            continue;
        }
        if (preg_match('~^CEDE\s*(&|AND)\s*CO~i', (string) $r['registration_name'])) {
            $cede += (int) $r['shares'];
            continue;
        }
        $t = strtoupper((string) ($r['position_type'] ?? 'OTHER')) ?: 'OTHER';
        $byType[$t] = ($byType[$t] ?? 0) + (int) $r['shares'];
    }
    $parsed['derived'] = ($parsed['derived'] ?? []) + ['by_type' => $byType, 'cede' => $cede, 'holders' => $parsed['control']['holders'] ?? null];
    upload_finish($pdo, $id, $parsed, $rd, count($parsed['rows']) - $failed, $failed);
    return [count($parsed['rows']) - $failed, $failed];
}

/** A Share Range Analysis into share_range_reports and share_range_rows (no person in it). Answers [made, 0]. */
function upload_load_share_range(PDO $pdo, int $id, array $im, array $parsed, string $rd): array
{
    upload_clear_rows($pdo, $id, 'share_range');
    $st = $pdo->prepare("INSERT INTO share_range_reports (import_id, security_id, record_date, job_number, source_kind) VALUES (:i, :s, :rd, :j, 'broadridge_xls') RETURNING id");
    $st->execute(['i' => $id, 's' => (int) $im['security_id'], 'rd' => $rd, 'j' => $parsed['job_number']]);
    $rep = (int) $st->fetchColumn();
    $ins = $pdo->prepare('INSERT INTO share_range_rows (report_id, population, range_label, range_from, range_to, accounts, shares) VALUES (:r, :p, :l, :f, :t, :a, :s)
                          ON CONFLICT (report_id, population, range_from) DO UPDATE SET accounts = share_range_rows.accounts + EXCLUDED.accounts, shares = share_range_rows.shares + EXCLUDED.shares');
    $made = 0;
    foreach ($parsed['populations'] as $pop => $p) {
        foreach ($p['rows'] as $r) {
            $ins->execute(['r' => $rep, 'p' => $pop, 'l' => mb_substr($r['label'], 0, 60), 'f' => $r['from'], 't' => $r['to'], 'a' => $r['accounts'], 's' => $r['shares']]);
            $made++;
        }
    }
    upload_finish($pdo, $id, $parsed, $rd, $made, 0);
    return [$made, 0];
}

/** A list or register of more rows than this waiting is matched by the worker's matcher pass, not in the request (≈10 ms a row on first match). */
const UPLOAD_MATCH_INLINE_ROWS = 200;

/** The rows of an upload still waiting for a holder (unmatched, typed, not Cede). */
function upload_rows_waiting(PDO $pdo, int $id, string $kind): int
{
    return (int) one_value($pdo, $kind === 'nobo' ? 'SELECT count(*) FROM nobo_rows WHERE import_id = :i AND holder_id IS NULL AND shares IS NOT NULL'
        : 'SELECT count(*) FROM register_rows WHERE import_id = :i AND holder_id IS NULL AND shares IS NOT NULL AND NOT is_cede', ['i' => $id]);
}

/** Match now (a short list) or queue for the worker (a long one): answers the counts, or null when queued. */
function upload_match_or_queue(PDO $pdo, int $id): ?array
{
    $kind = (string) one_value($pdo, 'SELECT kind FROM imports WHERE id = :i', ['i' => $id]);
    if (!in_array($kind, ['nobo', 'register'], true)) {
        return null;
    }
    $waiting = upload_rows_waiting($pdo, $id, $kind);
    if ($waiting <= UPLOAD_MATCH_INLINE_ROWS) {
        return upload_match($pdo, $id);
    }
    $pdo->prepare("UPDATE imports SET derived_totals = derived_totals || jsonb_build_object('matcher', jsonb_build_object('queued', true, 'waiting', CAST(:w AS integer), 'at', CAST(:at AS text))) WHERE id = :i")
        ->execute(['w' => $waiting, 'at' => gmdate('c'), 'i' => $id]);
    return null;
}

/** An upload's matcher is waiting for the worker. */
function upload_matcher_queued(array $u): bool
{
    $d = json_decode((string) ($u['derived_totals'] ?? '{}'), true) ?: [];
    return ($d['matcher']['queued'] ?? false) === true;
}

/**
 * The matcher over an upload's unmatched rows (after the tie, after an override, matcher_run): the database scores and decides; the counts
 * go to the upload's derived totals and the log (matcher.run), each automatic match and each proposal its own line (ids and the score).
 */
function upload_match(PDO $pdo, int $id): array
{
    $im = $pdo->query('SELECT id, kind, issuer_id, security_id FROM imports WHERE id = ' . $id)->fetch();
    if ($im === false || !in_array($im['kind'], ['nobo', 'register'], true)) {
        return ['auto' => 0, 'proposed' => 0, 'new' => 0, 'failed' => 0];
    }
    $ids = ['issuer_id' => (int) $im['issuer_id'], 'security_id' => (int) $im['security_id'], 'import_id' => $id];
    $since = (int) one_value($pdo, 'SELECT COALESCE(max(id), 0) FROM holder_match_proposals');
    $pdo->beginTransaction();
    try {
        $m = $pdo->query('SELECT * FROM si_match_import(' . $id . ')')->fetch();
        $counts = ['auto' => (int) $m['auto'], 'proposed' => (int) $m['proposed'], 'new' => (int) $m['new_holders'], 'failed' => (int) $m['failed']];
        $table = $im['kind'] === 'nobo' ? 'nobo_rows' : 'register_rows';
        foreach ($pdo->query("SELECT p.id, p.row_kind, p.row_id, p.holder_id, p.score, p.status FROM holder_match_proposals p WHERE p.id > $since AND p.row_kind = '{$im['kind']}'
                                AND p.row_id IN (SELECT id FROM $table WHERE import_id = $id) AND p.status IN ('auto', 'proposed') ORDER BY p.id") as $p) {
            log_activity($pdo, $p['status'] === 'auto' ? 'match.auto' : 'match.propose', 'holder_match_proposal', (int) $p['id'],
                $ids + ['after' => ['row_kind' => $p['row_kind'], 'row_id' => (int) $p['row_id'], 'holder_id' => (int) $p['holder_id'], 'score' => (float) $p['score']]]);
        }
        $pdo->prepare("UPDATE imports SET derived_totals = derived_totals || jsonb_build_object('matcher', CAST(:m AS jsonb)) WHERE id = :i")->execute(['m' => json_encode($counts + ['at' => gmdate('c')]), 'i' => $id]);
        log_activity($pdo, 'matcher.run', 'import', $id, $ids + ['after' => $counts]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $counts;
}

/**
 * A price file (slice 6, `prices_csv`) into `prices` under the source `prices_manual`: the bars upserted by date — an untied file (a line
 * that did not read) still writes the bars that did, the tie note naming the rest. Answers [made, failed].
 */
function upload_load_prices(PDO $pdo, int $id, array $im, array $parsed, string $rd): array
{
    require_once dirname(__DIR__, 2) . '/si/fetch/prices.php';
    $src = one_value($pdo, "SELECT id FROM sources WHERE connector = 'prices_manual' ORDER BY id LIMIT 1");
    [$new, $changed] = si_prices_upsert($pdo, (int) $im['security_id'], (int) $im['issuer_id'], $src === false || $src === null ? null : (int) $src, $parsed['bars']);
    $pdo->prepare("UPDATE imports SET status = 'done', finished_at = now(), tied = :t, tie_note = :n, rows_made = :m, rows_failed = :f, record_date = :rd, period_from = :a, period_to = :rd,
                          derived_totals = CAST(:d AS jsonb) || jsonb_build_object('new', CAST(:new AS integer), 'changed', CAST(:ch AS integer)) WHERE id = :i")
        ->execute(['t' => $parsed['tie'][0] ? 'true' : 'false', 'n' => $parsed['tie'][1], 'm' => count($parsed['bars']), 'f' => (int) $parsed['derived']['rows_failed'], 'rd' => $rd,
                   'a' => $parsed['period'][0], 'd' => json_encode($parsed['derived']), 'new' => $new, 'ch' => $changed, 'i' => $id]);
    return [count($parsed['bars']), (int) $parsed['derived']['rows_failed']];
}
