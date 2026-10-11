<?php
declare(strict_types=1);

/**
 * The exports' writes (events-reports.md "Query functions", signatures fixed). export_create() records the request (status queued, seven days to live) and builds it
 * at once as the person who asked; whatever is left queued the worker's `exports` pass builds as them. A holders export needs the Analyst's right to holders
 * (holders.read) and is logged with its row count; whether it carries natural persons' names is si_settings.pii_in_exports' (export_pii_allowed()).
 */
require_once __DIR__ . '/build.php';

/** The fields of an export from a request: kind, security (required), from, to, record_date, channel; refused with field errors. */
function export_fields(PDO $pdo): array
{
    $errors = [];
    $f = [];
    $k = (string) req_val('kind');
    isset(EXPORT_KINDS[$k]) ? $f['kind'] = $k : $errors['kind'] = 'The kind is one of ' . implode(', ', array_keys(EXPORT_KINDS)) . '.';
    $f['security'] = si_ref($pdo, 'security', null, 'SELECT 1 FROM mcp_securities WHERE security_id = :id', 'the security', $errors, false);
    if ($f['security'] === null && !isset($errors['security'])) { $errors['security'] = 'Choose the security to export.'; }
    foreach (['from' => 'The first day', 'to' => 'The last day', 'record_date' => 'The record date'] as $n => $label) {
        if (req_has($n) && (string) req_val($n) !== '') {
            $d = request_date($n);
            $d === false || $d === null ? $errors[$n] = $label . ' is a date such as 2026-09-30.' : $f[$n] = $d;
        }
    }
    if (isset($f['from'], $f['to']) && $f['to'] < $f['from']) { $errors['to'] = 'The period ends before it begins.'; }
    if (req_has('channel') && (string) req_val('channel') !== '') {
        $c = (string) req_val('channel');
        in_array($c, ['nobo', 'register'], true) ? $f['channel'] = $c : $errors['channel'] = 'The channel is nobo or register.';
    }
    if ($errors !== []) {
        si_refuse_fields($errors);
    }
    return $f;
}

/** Record an export and build it as its creator. Logs export.create (the kind and, once built, the rows). Answers the id. */
function export_create(PDO $pdo, array $f, int $by): int
{
    $sec = $pdo->prepare('SELECT issuer_id FROM mcp_securities WHERE security_id = :s');
    $sec->execute(['s' => (int) $f['security']]);
    $issuer = $sec->fetchColumn();
    if ($issuer === false) { throw new DomainException('Not found.'); }
    $params = array_filter(array_intersect_key($f, array_flip(['from', 'to', 'record_date', 'channel'])), static fn ($v): bool => $v !== null && $v !== '');
    $st = $pdo->prepare("INSERT INTO exports (kind, format, status, created_by, issuer_id, security_id, params) VALUES (:k, 'csv', 'queued', :by, :i, :s, CAST(:p AS jsonb)) RETURNING id");
    $st->execute(['k' => $f['kind'], 'by' => $by, 'i' => (int) $issuer, 's' => (int) $f['security'], 'p' => json_encode($params === [] ? new stdClass() : $params)]);
    $id = (int) $st->fetchColumn();
    $x = export_build($pdo, $id);
    log_activity($pdo, 'export.create', 'export', $id, ['issuer_id' => (int) $issuer, 'security_id' => (int) $f['security'],
        'after' => ['export_id' => $id, 'kind' => $f['kind'], 'rows' => (int) $x['item_count'], 'includes_pii' => (bool) $x['includes_pii'], 'status' => $x['status']]]);
    return $id;
}
