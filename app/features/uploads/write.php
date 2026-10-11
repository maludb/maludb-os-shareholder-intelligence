<?php
declare(strict_types=1);

/**
 * The upload pipeline (position-reports.md §1): a file becomes an `imports` row with the file kept whole (an `attachments` row in the store),
 * its raw rows written with the cells as read and the typed columns beside, and the kind's database verb run — si_spr_load() for an SPR — IN
 * ONE TRANSACTION; then the reconciliation as of the file's date. The handler (a person's form or the API) calls upload_create(); the worker
 * and upload_reparse call upload_ingest() on a stored file. Every step logs its line (ids, counts, dates, never a person's data).
 */

require_once dirname(__DIR__, 2) . '/si/parse/detect.php';
require_once dirname(__DIR__, 2) . '/si/parse/generic_mapped.php';
require_once dirname(__DIR__, 2) . '/si/storage.php';
require_once __DIR__ . '/load.php';

const UPLOAD_KINDS = ['spr' => 'DTC position report', 'nobo' => 'NOBO list', 'register' => 'Register', 'share_range' => 'Share range analysis', 'prices_csv' => 'Prices'];
/** Files at or under this size are parsed in the upload's own request; larger ones are queued for the worker. */
const UPLOAD_INLINE_BYTES = 5 * 1024 * 1024;

/**
 * Make an upload from a file a person sent. $f: kind, security (the mcp_securities row), connector (or null — detected), record_date (or null),
 * bytes, filename, by. Refuses (DomainException, in words) a file of another layout, another security's CUSIP, a file already uploaded. Answers
 * ['import_id', 'queued' => bool, 'result' => upload_ingest's answer or null].
 */
function upload_create(PDO $pdo, array $f): array
{
    $kind = (string) $f['kind'];
    $mapping = null;
    $template = ($f['template'] ?? null) ? upload_template($pdo, (int) $f['template']) : null;
    if ($template !== null) {                                          // the person's choice: a vendor template names its connector; a generic one maps the columns
        [$detected, $what] = [$template['connector'], 'a file read by the template ' . $template['name']];
        $connector = $template['connector'];
        if ($connector === 'generic_mapped') {
            $mapping = json_decode((string) $template['mapping'], true) + ['kind' => $template['kind'], 'template_id' => (int) $template['template_id'], 'template_name' => $template['name'],
                'record_date_given' => upload_record_date(null, $f['record_date'] ?? null, (string) $f['filename'])];
            if (($p = si_generic_mapping_problem((string) $template['kind'], $mapping)) !== null) {
                throw new DomainException('The template ' . $template['name'] . ' cannot read a file: ' . $p);
            }
        }
    } else {
        [$detected, $what] = si_detect_connector((string) $f['bytes'], (string) $f['filename']);
        $connector = $f['connector'] ?: $detected;
        if ($connector === 'generic_mapped') {
            throw new DomainException('A generic file is read by a template: choose the template for its layout.');
        }
    }
    if ($connector === null) {
        throw new DomainException($what);
    }
    $c = SI_CONNECTORS[$connector] ?? null;
    if ($c === null) {
        throw new DomainException('Unknown connector ' . $connector . '.');
    }
    if ($mapping !== null) {
        $c[0] = $mapping['kind'];
    }
    if ($kind === '') {
        $kind = $c[0];                                                     // "detect from the file"
    }
    if ($c[0] !== $kind) {
        throw new DomainException('This file is ' . $what . ', not a ' . strtolower(UPLOAD_KINDS[$kind] ?? $kind) . '. Choose ' . (UPLOAD_KINDS[$c[0]] ?? $c[0]) . ' as its kind.');
    }
    if ($c[2] === null) {
        throw new DomainException(ucfirst($what) . ' is read from slice ' . $c[1] . ', which is not built yet.');
    }
    $sha = hash('sha256', (string) $f['bytes']);
    $dup = one_value($pdo, 'SELECT i.id FROM imports i LEFT JOIN attachments a ON a.id = i.attachment_id WHERE i.security_id = :s AND i.kind = :k AND i.deleted_at IS NULL
                             AND (a.sha256 = :h OR i.control_totals ->> \'original_sha256\' = :h) ORDER BY i.id LIMIT 1', ['s' => (int) $f['security']['security_id'], 'k' => $kind, 'h' => $sha]);
    if ($dup !== null && $dup !== false) {
        $when = (string) one_value($pdo, 'SELECT created_at::date FROM imports WHERE id = :i', ['i' => (int) $dup]);
        throw new DomainException('This file was already uploaded on ' . $when . ' (upload ' . $dup . ').|' . $dup);
    }
    $big = strlen((string) $f['bytes']) > UPLOAD_INLINE_BYTES;
    $parsed = null;
    $rd = $f['record_date'] ?? null;
    if (!$big) {
        try {
            $parsed = si_parse_file($connector, (string) $f['bytes'], $mapping);
        } catch (SiParseError $e) {
            throw new DomainException($e->getMessage());
        }
        upload_check_cusip($parsed['cusip'] ?? null, $f['security'], $parsed['cusip6'] ?? null);
        if ($kind !== 'spr') {
            $rd = upload_record_date($parsed['record_date'] ?? null, $f['record_date'] ?? null, (string) $f['filename']);
            if ($rd === null) {
                throw new DomainException('The file does not say its record date: give it (the date the list or the register is as of).');
            }
            if ($kind !== 'prices_csv') {                                 // prices are upserted by date: a second file of the same days is welcome
                upload_check_same_date($pdo, $kind, (int) $f['security']['security_id'], $rd);
            }
        }
    }
    try {
        $stored = si_store_bytes(upload_bytes_to_store($pdo, $connector, (string) $f['bytes'], $mapping));
    } catch (SiParseError $e) {                                        // a generic file is kept as its mapped columns: a file the template cannot read is refused here
        throw new DomainException($e->getMessage());
    }
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('INSERT INTO imports (kind, file_name, status, created_by, issuer_id, security_id, connector, mapping, record_date, control_totals)
                             VALUES (:k, :fn, \'queued\', :by, :i, :s, :c, CAST(:m AS jsonb), :rd, CAST(:ct AS jsonb)) RETURNING id');
        $st->execute(['k' => $kind, 'fn' => mb_substr((string) $f['filename'], 0, 200), 'by' => $f['by'], 'i' => (int) $f['security']['issuer_id'], 's' => (int) $f['security']['security_id'],
                      'c' => $connector, 'm' => $mapping === null ? null : json_encode($mapping), 'rd' => $rd, 'ct' => json_encode(['original_sha256' => $sha])]);
        $id = (int) $st->fetchColumn();
        $st = $pdo->prepare('INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by) VALUES (\'import\', :r, :fn, :m, :b, :h, :p, :by) RETURNING id');
        $st->execute(['r' => $id, 'fn' => mb_substr((string) $f['filename'], 0, 200), 'm' => (string) ($f['mime'] ?? 'application/octet-stream'), 'b' => $stored['bytes'], 'h' => $stored['sha256'], 'p' => $stored['path'], 'by' => $f['by']]);
        $pdo->prepare('UPDATE imports SET attachment_id = :a WHERE id = :i')->execute(['a' => (int) $st->fetchColumn(), 'i' => $id]);
        log_activity($pdo, 'upload.create', 'import', $id, ['issuer_id' => (int) $f['security']['issuer_id'], 'security_id' => (int) $f['security']['security_id'], 'import_id' => $id,
            'after' => ['kind' => $kind, 'connector' => $connector, 'bytes' => $stored['bytes'], 'queued' => $big] + ($template === null ? [] : ['template_id' => (int) $template['template_id']])]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['import_id' => $id, 'queued' => $big, 'result' => $big ? null : upload_ingest($pdo, $id, (string) $f['bytes'], $parsed)];
}

/** An import template a person chose for a file (any member may read the templates: mcp_import_templates); refused in words when there is none. */
function upload_template(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT template_id, name, kind, connector, mapping FROM mcp_import_templates WHERE template_id = :id');
    $st->execute(['id' => $id]);
    $t = $st->fetch();
    if ($t === false) {
        throw new DomainException('There is no such import template.');
    }
    if (!isset(SI_CONNECTORS[$t['connector']])) {
        throw new DomainException('The template ' . $t['name'] . ' names a connector this build does not read (' . $t['connector'] . ').');
    }
    return $t;
}

/** The file's CUSIP must be the security's (an SPR for the preferred uploaded against the common is refused, both named); a Share Range Analysis names six characters. */
function upload_check_cusip(?string $fileCusip, array $security, ?string $cusip6 = null): void
{
    if ($fileCusip === null && $cusip6 !== null && $security['cusip'] !== null && $security['cusip'] !== '') {
        if (strtoupper(substr((string) $security['cusip'], 0, 6)) !== strtoupper($cusip6)) {
            throw new DomainException('The file is for CUSIP ' . $cusip6 . '…, but ' . $security['name'] . ' is ' . $security['cusip'] . '. Upload it to the security it is for.');
        }
        return;
    }
    if ($fileCusip === null) {
        return;
    }
    if ($security['cusip'] === null || $security['cusip'] === '') {
        throw new DomainException('The file is for CUSIP ' . $fileCusip . ', and ' . $security['name'] . ' has no CUSIP yet: an IR Manager adds it on the issuer\'s Securities first.');
    }
    if (strtoupper((string) $security['cusip']) !== $fileCusip) {
        throw new DomainException('The file is for CUSIP ' . $fileCusip . ', but ' . $security['name'] . ' is ' . $security['cusip'] . '. Upload it to the security it is for.');
    }
}

/** One NOBO list, register or share range per security and record date: a second is refused, naming the first. */
function upload_check_same_date(PDO $pdo, string $kind, int $security, string $rd, int $except = 0): void
{
    $other = one_value($pdo, 'SELECT id FROM imports WHERE kind = :k AND security_id = :s AND record_date = :d AND deleted_at IS NULL AND id <> :x ORDER BY id LIMIT 1',
        ['k' => $kind, 's' => $security, 'd' => $rd, 'x' => $except]);
    if ($other !== null && $other !== false) {
        throw new DomainException('A ' . strtolower(UPLOAD_KINDS[$kind] ?? $kind) . ' for ' . $rd . ' is already loaded (upload ' . $other . '): delete it first to load this one in its place.|' . $other);
    }
}

/**
 * Parse a stored upload and load it, in one transaction: the raw rows (replacing any earlier parse of this upload), the kind's verb, the tie;
 * then the reconciliation as of its last date. $bytes / $parsed spare a second read when the caller has them. A file that no longer parses
 * marks the upload failed with the words. Answers ['status', 'tied', 'tie_note', 'positions', 'failed', 'participants_new', 'period' => [from, to], 'reconciliation_id'].
 */
function upload_ingest(PDO $pdo, int $id, ?string $bytes = null, ?array $parsed = null): array
{
    $im = $pdo->query('SELECT i.*, a.storage_path, s.cusip, s.name AS security_name FROM imports i LEFT JOIN attachments a ON a.id = i.attachment_id JOIN securities s ON s.id = i.security_id WHERE i.id = ' . $id)->fetch();
    if ($im === false) {
        throw new DomainException('Not found.');
    }
    $ids = ['issuer_id' => (int) $im['issuer_id'], 'security_id' => (int) $im['security_id'], 'import_id' => $id];
    try {
        $bytes ??= si_stored_bytes((string) $im['storage_path']);
        $parsed ??= si_parse_file((string) $im['connector'], $bytes, $im['mapping'] === null ? null : json_decode((string) $im['mapping'], true));
        upload_check_cusip($parsed['cusip'] ?? null, ['cusip' => $im['cusip'], 'name' => $im['security_name']], $parsed['cusip6'] ?? null);
        if ($im['kind'] !== 'spr') {
            $rd = upload_record_date($parsed['record_date'] ?? null, $im['record_date'], (string) $im['file_name']);
            if ($rd === null) {
                throw new DomainException('The file does not say its record date: delete this upload and upload it again with its record date.');
            }
            if ($im['kind'] !== 'prices_csv') {
                upload_check_same_date($pdo, (string) $im['kind'], (int) $im['security_id'], $rd, $id);
            }
        }
    } catch (SiParseError | DomainException | SiStoreError $e) {
        $pdo->prepare("UPDATE imports SET status = 'failed', finished_at = now(), log = :l, tied = NULL WHERE id = :i")->execute(['l' => mb_substr($e->getMessage(), 0, 500), 'i' => $id]);
        log_activity($pdo, 'upload.parse', 'import', $id, $ids + ['after' => ['status' => 'failed']]);
        return ['status' => 'failed', 'tied' => null, 'tie_note' => $e->getMessage(), 'positions' => 0, 'failed' => 0, 'participants_new' => 0, 'period' => [null, null], 'reconciliation_id' => null];
    }
    $pdo->beginTransaction();
    try {
        $ctrl = $parsed['control'] + ['cusip' => $parsed['cusip'] ?? null];
        if (($ctrl['shares_by_date'] ?? null) === []) {
            $ctrl['shares_by_date'] = new stdClass();                   // an empty map is a JSON object (si_spr_load iterates it)
        }
        $pdo->prepare("UPDATE imports SET status = 'running', control_totals = CAST(:ct AS jsonb) || jsonb_build_object('original_sha256', control_totals ->> 'original_sha256') WHERE id = :i")
            ->execute(['ct' => json_encode($ctrl), 'i' => $id]);
        if ($im['kind'] !== 'spr') {
            [$made, $failedRows] = match ($im['kind']) {
                'nobo' => upload_load_nobo($pdo, $id, $im, $parsed, $rd),
                'register' => upload_load_register($pdo, $id, $im, $parsed, $rd),
                'share_range' => upload_load_share_range($pdo, $id, $im, $parsed, $rd),
                'prices_csv' => upload_load_prices($pdo, $id, $im, $parsed, $rd),
                default => throw new DomainException('This kind of upload is read from a later slice.'),
            };
            $tied = (bool) $parsed['tie'][0];
            log_activity($pdo, 'upload.parse', 'import', $id, $ids + ['after' => ['status' => 'done', 'rows' => $made, 'failed' => $failedRows, 'record_date' => $rd]]);
            log_activity($pdo, $tied ? 'upload.tie' : 'upload.untie', 'import', $id, $ids + ['after' => ['tied' => $tied, 'record_date' => $rd]]);
            $pdo->commit();
            try {                                                           // the load stands; the matcher and the reconciliation can be run again
                $match = $tied && $im['kind'] !== 'prices_csv' ? upload_match_or_queue($pdo, $id) : null;
                $rec = $tied && !in_array($im['kind'], ['share_range', 'prices_csv'], true) ? reconciliation_after($pdo, (int) $im['security_id'], $rd, 'after upload ' . $id) : null;
            } catch (Throwable $e) {
                error_log('upload_ingest ' . $id . ' after the load: ' . $e->getMessage());
                [$match, $rec] = [null, null];
            }
            if ($tied && $im['kind'] !== 'prices_csv' && $im['kind'] !== 'share_range') {
                upload_dispatch_done($pdo, $id);                           // the Surveillance Analyst is handed the follow-up (slice 9)
            }
            return ['status' => 'done', 'tied' => $tied, 'tie_note' => $parsed['tie'][1], 'positions' => 0, 'rows' => $made, 'failed' => $failedRows, 'participants_new' => 0,
                    'period' => [$rd, $rd], 'record_date' => $rd, 'matcher' => $match, 'matching' => $tied && $match === null && !in_array($im['kind'], ['share_range', 'prices_csv'], true), 'reconciliation_id' => $rec];
        }
        $pdo->prepare('DELETE FROM spr_rows WHERE import_id = :i')->execute(['i' => $id]);
        $ins = $pdo->prepare('INSERT INTO spr_rows (import_id, row_no, participant_number, participant_name, position_date, shares, raw, error) VALUES (:i, :r, :n, :name, :d, :s, CAST(:raw AS jsonb), :e)
                              ON CONFLICT (import_id, row_no, position_date) DO NOTHING');
        foreach ($parsed['rows'] as [$rowNo, $num, $name, $date, $shares, $raw, $err]) {
            $ins->execute(['i' => $id, 'r' => $rowNo, 'n' => $num, 'name' => $name === '' ? null : $name, 'd' => $date, 's' => $shares, 'raw' => json_encode($raw), 'e' => $err]);
        }
        $load = $pdo->query('SELECT * FROM si_spr_load(' . $id . ')')->fetch();
        $tied = (bool) $load['tied'];
        $note = $load['tie_note'];
        if (!empty($parsed['control']['no_total'])) {
            $tied = false;
            $note = trim('The file has no Total row: nothing to tie it to. ' . (string) $note);
            $pdo->prepare('UPDATE imports SET tied = false, tie_note = :n WHERE id = :i')->execute(['n' => $note, 'i' => $id]);
            $pdo->prepare("DELETE FROM share_counts WHERE import_id = :i AND kind = 'cede' AND source = 'spr'")->execute(['i' => $id]);
        }
        $period = $pdo->query('SELECT period_from, period_to FROM imports WHERE id = ' . $id)->fetch();
        log_activity($pdo, 'upload.parse', 'import', $id, $ids + ['after' => ['status' => 'done', 'rows' => count($parsed['rows']), 'failed' => (int) $load['rows_failed']]]);
        log_activity($pdo, $tied ? 'upload.tie' : 'upload.untie', 'import', $id, $ids + ['after' => ['tied' => $tied, 'dates' => count($parsed['control']['shares_by_date'] ?? [])]]);
        log_activity($pdo, 'position.load', 'import', $id, $ids + ['after' => ['positions' => (int) $load['positions'], 'participants_new' => (int) $load['participants_new'], 'from' => $period['period_from'], 'to' => $period['period_to']]]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $msg = $e instanceof DomainException ? $e->getMessage() : 'The file could not be loaded; the server log has why.';
        if (!$e instanceof DomainException) { error_log('upload_ingest ' . $id . ': ' . $e->getMessage()); }
        $pdo->prepare("UPDATE imports SET status = 'failed', finished_at = now(), log = :l WHERE id = :i")->execute(['l' => $msg, 'i' => $id]);
        return ['status' => 'failed', 'tied' => null, 'tie_note' => $msg, 'positions' => 0, 'failed' => 0, 'participants_new' => 0, 'period' => [null, null], 'reconciliation_id' => null];
    }
    $rec = $tied ? reconciliation_after($pdo, (int) $im['security_id'], (string) $period['period_to'], 'after upload ' . $id) : null;
    if ($tied) {
        upload_dispatch_done($pdo, $id);                                   // the Surveillance Analyst is handed the follow-up (slice 9)
    }
    return ['status' => 'done', 'tied' => $tied, 'tie_note' => $note, 'positions' => (int) $load['positions'], 'failed' => (int) $load['rows_failed'],
            'participants_new' => (int) $load['participants_new'], 'period' => [$period['period_from'], $period['period_to']], 'reconciliation_id' => $rec];
}

/** A tied upload hands the Surveillance Analyst one dispatch (agents/write.php dispatch_upload_done); a failure to is logged and never undoes the upload. */
function upload_dispatch_done(PDO $pdo, int $import): void
{
    try {
        require_once dirname(__DIR__) . '/agents/write.php';
        dispatch_upload_done($pdo, $import);
    } catch (Throwable $e) {
        error_log('upload_ingest ' . $import . ' dispatch: ' . $e->getMessage());
    }
}

/** A reconciliation run as of a date, logged (the worker, an upload, an override, a person's button). Answers its id. */
function reconciliation_after(PDO $pdo, int $security, string $asOf, ?string $note = null): int
{
    $rid = (int) one_value($pdo, 'SELECT si_reconcile(:s, CAST(:d AS date), :n)', ['s' => $security, 'd' => $asOf, 'n' => $note]);
    $r = $pdo->query('SELECT r.*, s.issuer_id FROM share_reconciliations r JOIN securities s ON s.id = r.security_id WHERE r.id = ' . $rid)->fetch();
    $state = reconciliation_state($r);
    log_activity($pdo, $state === 'tied' ? 'reconciliation.tie' : ($state === 'untied' ? 'reconciliation.untie' : 'reconciliation.run'), 'share_reconciliation', $rid,
        ['issuer_id' => (int) $r['issuer_id'], 'security_id' => $security, 'after' => ['as_of' => $asOf, 'state' => $state, 'difference_registered' => $r['difference_registered'], 'difference_spr' => $r['difference_spr']]]);
    return $rid;
}

/** A reconciliation's state in words: tied, untied, or incomplete (neither side could be measured yet). */
function reconciliation_state(array|false|null $r): string
{
    if (!$r || ($r['tied_registered'] === null && $r['tied_spr'] === null)) {
        return 'incomplete';
    }
    return ($r['tied'] === true || $r['tied'] === 't') ? 'tied' : 'untied';
}

/** A manager accepts an untied upload with a note: its rows become usable (the SPR's Cede count written as a tied one would) and the reconciliation runs. */
function upload_override(PDO $pdo, int $id, string $note, int $by): void
{
    $pdo->prepare('UPDATE imports SET override_by = :by, override_at = now(), override_note = :n WHERE id = :i')->execute(['by' => $by, 'n' => $note, 'i' => $id]);
    $pdo->prepare("INSERT INTO share_counts (security_id, as_of, kind, value, source, import_id, note)
                   SELECT i.security_id, i.period_to, 'cede', sum(p.shares), 'spr', i.id, 'the SPR total on its last date (an untied upload, overridden)'
                     FROM imports i JOIN participant_positions p ON p.import_id = i.id AND p.position_date = i.period_to WHERE i.id = :i AND i.kind = 'spr' GROUP BY i.security_id, i.period_to, i.id
                   ON CONFLICT (security_id, kind, as_of, source) DO UPDATE SET value = EXCLUDED.value, import_id = EXCLUDED.import_id, recorded_at = now()")->execute(['i' => $id]);
}

/** After a delete: the uploads this one superseded load their dates again (the earlier week's Friday comes back). Answers how many reloaded. */
function upload_reclaim_superseded(PDO $pdo, int $id): int
{
    $n = 0;
    foreach ($pdo->query('SELECT id FROM imports WHERE superseded_by = ' . $id . " AND deleted_at IS NULL AND kind = 'spr' AND status = 'done' ORDER BY period_to")->fetchAll(PDO::FETCH_COLUMN) as $other) {
        $pdo->prepare('UPDATE imports SET superseded_by = NULL WHERE id = :i')->execute(['i' => (int) $other]);
        $pdo->query('SELECT * FROM si_spr_load(' . (int) $other . ')')->fetch();
        $n++;
    }
    return $n;
}
