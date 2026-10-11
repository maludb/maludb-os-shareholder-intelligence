<?php
declare(strict_types=1);

/**
 * The admin's writes (admin-settings.md, signatures fixed). Built ahead of slice 10: credential_set. A credential is sealed by credentials.php
 * (secretbox under SI_SECRETS_KEY); the database holds the ciphertext, the label and the last four; the log holds `{source_id, kind, label, last4}`
 * and never the secret. A source paused for want of a key (`settings.paused_for_key`, db/025) is resumed when the key arrives.
 */
require_once dirname(__DIR__, 2) . '/si/fetch/credentials.php';
require_once dirname(__DIR__, 2) . '/si/storage.php';

const CREDENTIAL_KINDS = ['api_key' => 'An API key', 'bearer' => 'A bearer token', 'basic' => 'A user and password (user:password)', 'oauth_client' => 'An OAuth client (id:secret)'];

/**
 * Set or rotate a source's credential: $f kind, label, secret. One credential a source — a rotation replaces the ciphertext and stamps rotated_at.
 * Logs `credential.set` or `credential.rotate`, and `source.resume` when the key lifts a pause made for want of it. Answers ['credential_id', 'rotated', 'resumed'].
 */
function credential_set(PDO $pdo, int $source, array $f, int $by): array
{
    $st = $pdo->prepare('SELECT id, credential_id, settings, paused_at FROM sources WHERE id = :id FOR UPDATE');
    $st->execute(['id' => $source]);
    $s = $st->fetch() ?: throw new DomainException('Not found.');
    $secret = (string) $f['secret'];
    $last4 = mb_substr($secret, -4);
    $cipher = si_credential_seal($secret);
    $st = $pdo->prepare('SELECT id FROM source_credentials WHERE source_id = :s ORDER BY id DESC LIMIT 1');
    $st->execute(['s' => $source]);
    $cid = $st->fetchColumn();
    if ($cid === false) {
        $ins = $pdo->prepare('INSERT INTO source_credentials (source_id, kind, label, last4, ciphertext, created_by) VALUES (:s, :k, :l, :f, :c, :by) RETURNING id');
        $ins->bindValue('s', $source, PDO::PARAM_INT); $ins->bindValue('k', $f['kind']); $ins->bindValue('l', $f['label']); $ins->bindValue('f', $last4);
        $ins->bindValue('c', $cipher, PDO::PARAM_LOB); $ins->bindValue('by', $by, PDO::PARAM_INT);
        $ins->execute();
        $cid = (int) $ins->fetchColumn();
        $rotated = false;
    } else {
        $up = $pdo->prepare('UPDATE source_credentials SET kind = :k, label = :l, last4 = :f, ciphertext = :c, rotated_at = now() WHERE id = :id');
        $up->bindValue('k', $f['kind']); $up->bindValue('l', $f['label']); $up->bindValue('f', $last4); $up->bindValue('c', $cipher, PDO::PARAM_LOB); $up->bindValue('id', (int) $cid, PDO::PARAM_INT);
        $up->execute();
        $cid = (int) $cid;
        $rotated = true;
    }
    $settings = json_decode((string) $s['settings'], true) ?: [];
    $resume = $s['paused_at'] !== null && !empty($settings['paused_for_key']);
    $pdo->prepare('UPDATE sources SET credential_id = :c, consecutive_failures = 0, backoff_until = NULL' . ($resume ? ", paused_at = NULL, paused_reason = NULL, settings = settings - 'paused_for_key'" : '') . ' WHERE id = :id')
        ->execute(['c' => $cid, 'id' => $source]);
    log_activity($pdo, $rotated ? 'credential.rotate' : 'credential.set', 'source', $source, ['after' => ['source_id' => $source, 'kind' => $f['kind'], 'label' => $f['label'], 'last4' => $last4]]);
    if ($resume) {
        log_activity($pdo, 'source.resume', 'source', $source, ['after' => ['source_id' => $source, 'reason' => 'the key arrived']]);
    }
    return ['credential_id' => $cid, 'rotated' => $rotated, 'resumed' => $resume];
}

/*
 * ---- Slice 10: the rest of the admin's writes (admin-settings.md, signatures fixed). Each runs inside its handler's transaction and logs one event
 * with ids, names and the keys that changed — never a secret (a credential is credential_set's, above), never a holder's name.
 */

/** A source's key in the settings that would carry a secret: refused, a source's settings are shown and logged. */
const SOURCE_SETTINGS_FORBIDDEN_KEY = '~(secret|password|passwd|api[_-]?key|apikey|token|bearer|authorization|credential)~i';

/** Why $url is not a base URL a fetcher may be pointed at, or null when it is: http(s), a host, no user:password in it. */
function base_url_problem(string $url): ?string
{
    $p = parse_url($url);
    if (mb_strlen($url) > 500 || $p === false || !in_array($p['scheme'] ?? '', ['http', 'https'], true) || ($p['host'] ?? '') === '') {
        return 'The base URL is a web address (http or https) of up to 500 characters.';
    }
    if (isset($p['user']) || isset($p['pass'])) {
        return 'The base URL carries no user or password: a key is set on the credential.';
    }
    return null;
}

/** Why $settings is not a source's settings object, or null: an object, up to 8 KB, with no key (at any depth) that would hold a secret. */
function source_settings_problem(mixed $settings): ?string
{
    if (!is_array($settings) || ($settings !== [] && array_is_list($settings))) {
        return 'The settings are a JSON object.';
    }
    $text = json_encode($settings);
    if ($text === false || strlen($text) > 8192) {
        return 'The settings are up to 8 KB.';
    }
    $bad = [];
    $walk = static function (array $a) use (&$walk, &$bad): void {
        foreach ($a as $k => $v) {
            if (is_string($k) && $k !== 'paused_for_key' && preg_match(SOURCE_SETTINGS_FORBIDDEN_KEY, $k)) { $bad[$k] = true; }
            if (is_array($v)) { $walk($v); }
        }
    };
    $walk($settings);
    return $bad === [] ? null : 'The settings hold no secret: "' . implode('", "', array_keys($bad)) . '" belongs on the credential.';
}

/** The EDGAR sources' ceiling for a rate (si_settings.edgar_rate_per_second). */
function edgar_rate_ceiling(PDO $pdo): float
{
    return (float) one_value($pdo, 'SELECT edgar_rate_per_second FROM si_settings WHERE id = 1');
}

/**
 * Change a source: $f may hold name, base_url, settings (an array — replaces the object), rate_per_second, user_agent, active. A key left out stays. Answers
 * ['before' => …, 'after' => …] of the keys that changed (the settings by the names of the keys that differ) and logs `source.save`.
 */
function source_save(PDO $pdo, int $id, array $f): array
{
    $s = $pdo->prepare('SELECT * FROM sources WHERE id = :id FOR UPDATE');
    $s->execute(['id' => $id]);
    $cur = $s->fetch() ?: throw new DomainException('Not found.');
    $set = [];
    $args = ['id' => $id];
    $before = [];
    $after = [];
    foreach (['name', 'base_url', 'user_agent'] as $k) {
        if (array_key_exists($k, $f) && $f[$k] !== $cur[$k]) {
            $before[$k] = $cur[$k];
            $after[$k] = $f[$k];
            $set[] = "$k = :$k";
            $args[$k] = $f[$k];
        }
    }
    if (array_key_exists('rate_per_second', $f) && abs((float) $f['rate_per_second'] - (float) $cur['rate_per_second']) > 0.0001) {
        if (str_starts_with((string) $cur['connector'], 'edgar_') && (float) $f['rate_per_second'] > edgar_rate_ceiling($pdo)) {
            throw new DomainException('An EDGAR source reads at most ' . rtrim(rtrim(number_format(edgar_rate_ceiling($pdo), 2, '.', ''), '0'), '.') . ' a second (Settings → the EDGAR rate).');
        }
        $before['rate_per_second'] = (float) $cur['rate_per_second'];
        $after['rate_per_second'] = (float) $f['rate_per_second'];
        $set[] = 'rate_per_second = :rate';
        $args['rate'] = $f['rate_per_second'];
    }
    if (array_key_exists('active', $f) && (bool) $f['active'] !== (bool) $cur['active']) {
        $before['active'] = (bool) $cur['active'];
        $after['active'] = (bool) $f['active'];
        $set[] = 'active = :active';
        $args['active'] = $f['active'] ? 't' : 'f';
    }
    if (array_key_exists('settings', $f)) {
        $old = json_decode((string) $cur['settings'], true) ?: [];
        $changed = array_values(array_unique(array_merge(array_keys(array_diff_key($f['settings'], $old)), array_keys(array_diff_key($old, $f['settings'])),
            array_keys(array_filter($f['settings'], static fn ($v, $k): bool => array_key_exists($k, $old) && json_encode($old[$k]) !== json_encode($v), ARRAY_FILTER_USE_BOTH)))));
        if ($changed !== []) {
            $before['settings'] = ['keys' => $changed];
            $after['settings'] = ['keys' => $changed];
            $set[] = 'settings = CAST(:settings AS jsonb)';
            $args['settings'] = json_encode($f['settings'] === [] ? new stdClass() : $f['settings']);
        }
    }
    if ($set !== []) {
        $pdo->prepare('UPDATE sources SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($args);
        log_activity($pdo, 'source.save', 'source', $id, ['before' => $before, 'after' => ['source_id' => $id, 'connector' => $cur['connector']] + $after]);
    }
    return ['before' => $before, 'after' => $after];
}

/** A source's schedule in minutes (0 = manual only: the worker never pulls it by itself). Logs `source.schedule` {before, after}. Answers ['before', 'after']. */
function source_schedule_set(PDO $pdo, int $id, int $minutes): array
{
    $s = $pdo->prepare('SELECT schedule_minutes, connector FROM sources WHERE id = :id FOR UPDATE');
    $s->execute(['id' => $id]);
    $cur = $s->fetch() ?: throw new DomainException('Not found.');
    if ($minutes < 0 || $minutes > 525600) {
        throw new DomainException('The schedule is 0 (manual only) to 525,600 minutes (a year).');
    }
    $was = (int) ($cur['schedule_minutes'] ?? 0);
    if ($was !== $minutes) {
        $pdo->prepare('UPDATE sources SET schedule_minutes = :m WHERE id = :id')->execute(['m' => $minutes, 'id' => $id]);
        log_activity($pdo, 'source.schedule', 'source', $id, ['before' => ['schedule_minutes' => $was], 'after' => ['source_id' => $id, 'connector' => $cur['connector'], 'schedule_minutes' => $minutes]]);
    }
    return ['before' => $was, 'after' => $minutes];
}

/**
 * Pause a source (the worker skips it; a manual pull still names it paused) or resume it. A source paused for want of its key resumes only once a credential is set
 * (credential_set resumes it by itself). Logs `source.pause` {reason} or `source.resume`. Answers ['changed' => bool].
 */
function source_pause(PDO $pdo, int $id, ?string $reason, bool $resume): array
{
    $s = $pdo->prepare('SELECT connector, settings, paused_at, credential_id FROM sources WHERE id = :id FOR UPDATE');
    $s->execute(['id' => $id]);
    $cur = $s->fetch() ?: throw new DomainException('Not found.');
    if ($resume) {
        if ($cur['paused_at'] === null) {
            throw new DomainException('The source is not paused.');
        }
        $settings = json_decode((string) $cur['settings'], true) ?: [];
        if (!empty($settings['paused_for_key']) && $cur['credential_id'] === null) {
            throw new DomainException('This source waits for its key: set the credential first, and it resumes by itself.');
        }
        $pdo->prepare("UPDATE sources SET paused_at = NULL, paused_reason = NULL, settings = settings - 'paused_for_key' WHERE id = :id")->execute(['id' => $id]);
        log_activity($pdo, 'source.resume', 'source', $id, ['after' => ['source_id' => $id, 'connector' => $cur['connector']]]);
        return ['changed' => true];
    }
    if ($cur['paused_at'] !== null) {
        throw new DomainException('The source is paused already.');
    }
    $reason = $reason === null || $reason === '' ? 'Paused by an administrator.' : mb_substr($reason, 0, 300);
    $pdo->prepare('UPDATE sources SET paused_at = now(), paused_reason = :r WHERE id = :id')->execute(['r' => $reason, 'id' => $id]);
    log_activity($pdo, 'source.pause', 'source', $id, ['after' => ['source_id' => $id, 'connector' => $cur['connector'], 'reason' => $reason]]);
    return ['changed' => true];
}

/** A participant's class, set by a person (class_source becomes manual — the directory never overwrites it). Logs `participant.class_set` {number, class}. Answers ['before' => class, 'after' => class]. */
function participant_class_set(PDO $pdo, int $participant, string $class): array
{
    require_once __DIR__ . '/../participants/write.php';
    $s = $pdo->prepare('SELECT number, class_key, class_source FROM participants WHERE id = :id FOR UPDATE');
    $s->execute(['id' => $participant]);
    $cur = $s->fetch() ?: throw new DomainException('Not found.');
    if (one_value($pdo, 'SELECT 1 FROM participant_classes WHERE key = :k', ['k' => $class]) === false) {
        throw new DomainException('That class is not in the vocabulary.');
    }
    if ($cur['class_key'] !== $class || $cur['class_source'] !== 'manual') {
        participant_update($pdo, $participant, ['class' => $class]);
        log_activity($pdo, 'participant.class_set', 'participant', $participant, ['before' => ['class' => $cur['class_key'], 'class_source' => $cur['class_source']], 'after' => ['participant_id' => $participant, 'number' => $cur['number'], 'class' => $class]]);
    }
    return ['before' => $cur['class_key'], 'after' => $class];
}

/** Why $mapping is not an import template's mapping, or null: an object of up to 20 KB; the transfer agent's extract keeps naming the columns never stored. */
function template_mapping_problem(mixed $mapping, string $connector, ?string $kind = null): ?string
{
    if ($connector === 'generic_mapped' && $kind !== null) {          // a generic template is read as written: its fields, its columns, never a never-stored column
        require_once dirname(__DIR__, 2) . '/si/parse/generic_mapped.php';
        if (isset(SI_GENERIC_FIELDS[$kind]) && ($p = si_generic_mapping_problem($kind, $mapping)) !== null) {
            return $p;
        }
        foreach (is_array($mapping['columns'] ?? null) ? $mapping['columns'] : [] as $ref) {   // a kind no reader reads yet is kept as written, but never names a never-stored column
            foreach ((array) $ref as $r) {
                if (is_string($r) && preg_match(SI_GENERIC_NEVER_MAPPED, $r)) {
                    return 'The column "' . $r . '" holds what the application never stores (a tax id, a birth date, a phone, an email): it cannot be mapped.';
                }
            }
        }
    }
    if (!is_array($mapping) || $mapping === [] || array_is_list($mapping)) {
        return 'The mapping is a JSON object.';
    }
    if (strlen((string) json_encode($mapping)) > 20480) {
        return 'The mapping is up to 20 KB.';
    }
    if ($connector === 'ta_extract_csv') {
        $drop = array_map(static fn ($c): string => strtoupper(trim((string) $c)), (array) ($mapping['drop_columns'] ?? []));
        $missing = array_values(array_diff(SI_NEVER_STORED_COLUMNS, $drop));
        if ($missing !== []) {
            return 'The extract\'s drop_columns keep naming what the application never stores: ' . implode(', ', $missing) . '.';
        }
    }
    return null;
}

/**
 * Create ($id null) or change an import template: $f name, kind, connector, mapping (an array). Answers the template's id; logs `template.save` {template_id, name, kind, connector}.
 * What the parsers read of a mapping: a `generic_mapped` template's whole mapping (app/si/parse/generic_mapped.php — checked here as it will be read); of a vendor
 * connector's, only the extract's drop_columns (at storing time) — those layouts are the connectors' code (see admin-settings.md "Built").
 */
function template_save(PDO $pdo, ?int $id, array $f, int $by): int
{
    require_once dirname(__DIR__, 2) . '/si/storage.php';
    if (!isset(TEMPLATE_KINDS[$f['kind']])) {
        throw new DomainException('The kind is one of ' . implode(', ', array_keys(TEMPLATE_KINDS)) . '.');
    }
    if (($p = template_mapping_problem($f['mapping'], (string) $f['connector'], (string) $f['kind'])) !== null) {
        throw new DomainException($p);
    }
    $json = json_encode($f['mapping']);
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO import_templates (name, kind, connector, mapping, created_by) VALUES (:n, :k, :c, CAST(:m AS jsonb), :by) RETURNING id');
        $st->execute(['n' => $f['name'], 'k' => $f['kind'], 'c' => $f['connector'], 'm' => $json, 'by' => $by]);
        $id = (int) $st->fetchColumn();
        $before = null;
    } else {
        $cur = $pdo->prepare('SELECT name, kind, connector FROM import_templates WHERE id = :id FOR UPDATE');
        $cur->execute(['id' => $id]);
        $before = $cur->fetch() ?: throw new DomainException('Not found.');
        if ($before['connector'] === 'ta_extract_csv' && $f['connector'] !== 'ta_extract_csv') {
            throw new DomainException('The transfer agent\'s extract template keeps its connector: it names the columns never stored.');
        }
        $pdo->prepare('UPDATE import_templates SET name = :n, kind = :k, connector = :c, mapping = CAST(:m AS jsonb) WHERE id = :id')
            ->execute(['n' => $f['name'], 'k' => $f['kind'], 'c' => $f['connector'], 'm' => $json, 'id' => $id]);
    }
    log_activity($pdo, 'template.save', 'import_template', $id, ['before' => $before, 'after' => ['template_id' => $id, 'name' => $f['name'], 'kind' => $f['kind'], 'connector' => $f['connector'], 'created' => $before === null]]);
    return $id;
}

/** Delete an import template. The transfer agent's extract template cannot go: it is what keeps the personal columns out of the store. Logs `template.delete` {template_id, name}. */
function template_delete(PDO $pdo, int $id): void
{
    $cur = $pdo->prepare('SELECT name, connector FROM import_templates WHERE id = :id FOR UPDATE');
    $cur->execute(['id' => $id]);
    $t = $cur->fetch() ?: throw new DomainException('Not found.');
    if ($t['connector'] === 'ta_extract_csv') {
        throw new DomainException('The transfer agent\'s extract template stays: it names the columns the application never stores.');
    }
    $pdo->prepare('DELETE FROM import_templates WHERE id = :id')->execute(['id' => $id]);
    log_activity($pdo, 'template.delete', 'import_template', $id, ['before' => ['name' => $t['name'], 'connector' => $t['connector']], 'after' => ['template_id' => $id]]);
}

/** The columns of si_settings the form changes, with how each is written. */
const SETTINGS_COLUMNS = ['business_name', 'edgar_contact_email', 'default_exchange', 'market_timezone', 'market_holidays', 'edgar_rate_per_second', 'reconciliation_tolerance_shares', 'auto_match_threshold',
    'pii_in_exports', 'escalate_high_to', 'signal_grace_days', 'tombstone_days', 'retain_raw_rows_days', 'key_rate_per_minute', 'key_rate_per_day', 'max_upload_bytes', 'price_source_default', 'timezone'];

/**
 * Change the settings: $f holds validated values for any of SETTINGS_COLUMNS (market_holidays a list of ISO dates, retain_raw_rows_days and escalate_high_to null for none); a key left out stays.
 * Lowering the EDGAR rate lowers any EDGAR source above it. Answers ['before' => …, 'after' => … (the keys that changed)] and logs `settings.save`.
 */
function settings_save(PDO $pdo, array $f): array
{
    $cur = settings_form($pdo);
    $before = [];
    $after = [];
    $set = [];
    $args = [];
    foreach (SETTINGS_COLUMNS as $k) {
        if (!array_key_exists($k, $f)) {
            continue;
        }
        $old = $cur[$k] ?? null;
        $new = $f[$k];
        if ($k === 'market_holidays') {
            sort($new);
            sort($old);
            if (json_encode($old) === json_encode($new)) { continue; }
            $set[] = 'market_holidays = CAST(:market_holidays AS date[])';
            $args['market_holidays'] = '{' . implode(',', $new) . '}';
        } else {
            $same = $old === null || $new === null ? $old === $new : (is_numeric($old) && is_numeric($new) ? (float) $old === (float) $new : (string) $old === (string) $new);
            if ($same) { continue; }
            $set[] = "$k = :$k";
            $args[$k] = $new;
        }
        $before[$k] = $k === 'edgar_contact_email' ? '(changed)' : $old;
        $after[$k] = $k === 'edgar_contact_email' ? '(changed)' : $new;
    }
    $lowered = 0;
    if ($set !== []) {
        $st = $pdo->prepare('UPDATE si_settings SET ' . implode(', ', $set) . ' WHERE id = 1');
        foreach ($args as $k => $v) {
            $st->bindValue($k, $v, $v === null ? PDO::PARAM_NULL : (is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR));
        }
        $st->execute();
        if (isset($after['edgar_rate_per_second'])) {
            $n = $pdo->prepare("UPDATE sources SET rate_per_second = :r WHERE connector LIKE 'edgar\\_%' AND rate_per_second > :r2");
            $n->execute(['r' => $f['edgar_rate_per_second'], 'r2' => $f['edgar_rate_per_second']]);
            $lowered = $n->rowCount();
        }
        log_activity($pdo, 'settings.save', 'settings', 1, ['before' => $before, 'after' => $after + ($lowered > 0 ? ['edgar_sources_lowered' => $lowered] : [])]);
    }
    return ['before' => $before, 'after' => $after, 'sources_lowered' => $lowered];
}

/** Revoke anyone's token or key at once (the admin's). Logs `token.revoke_any` {token_id, label, scope, member_id} — the label, never the token. Answers the token's person, label and scope. */
function token_revoke_any(PDO $pdo, int $id): array
{
    $r = json_decode((string) one_value($pdo, 'SELECT si_token_revoke_any(:t)', ['t' => $id]), true) ?: [];
    if (!empty($r['was_revoked'])) {
        throw new DomainException('The token is revoked already.');
    }
    log_activity($pdo, 'token.revoke_any', 'mcp_access_token', $id, ['after' => ['token_id' => $id, 'label' => $r['label'], 'scope' => $r['scope'], 'member_id' => (int) $r['member_id']]]);
    return $r;
}

/**
 * Purge for good what waited past the tombstone window (si_tombstones_purge_due), of one kind and one record or of every kind; the stored files nothing else keeps are removed after.
 * Logs `records.purge` {kind, count} for each kind that had any. Answers ['total' => n, 'by_kind' => [kind => n], 'paths' => [the stored files to remove]]. Call it inside a transaction and pass 'paths' to purge_remove_files() after the commit.
 */
function records_purge(PDO $pdo, ?string $kind, ?int $record = null, int $limit = 1000): array
{
    require_once dirname(__DIR__, 2) . '/si/storage.php';
    $st = $pdo->prepare('SELECT kind, record_id, storage_path FROM si_tombstones_purge_due(CAST(:k AS text), CAST(:r AS bigint), :l)');
    $st->execute(['k' => $kind === '' || $kind === 'all' ? null : $kind, 'r' => $record, 'l' => $limit]);
    $by = [];
    $paths = [];
    foreach ($st->fetchAll() as $row) {
        if ($row['kind'] === 'file') {
            $paths[] = (string) $row['storage_path'];
        } else {
            $by[$row['kind']] = ($by[$row['kind']] ?? 0) + 1;
        }
    }
    foreach ($by as $k => $n) {
        log_activity($pdo, 'records.purge', null, null, ['after' => ['kind' => $k, 'count' => $n]]);
    }
    return ['total' => array_sum($by), 'by_kind' => $by, 'paths' => $paths];
}

/** Remove the kept files a purge named (after its commit); answers how many were removed. A file already gone is not an error. */
function purge_remove_files(array $paths): int
{
    require_once dirname(__DIR__, 2) . '/si/storage.php';
    $n = 0;
    foreach ($paths as $p) {
        try {
            $n += si_unlink_stored($p) ? 1 : 0;
        } catch (SiStoreError) {
        }
    }
    return $n;
}

/** A request field holding a JSON object (a form's text, or an agent's object): [array|null (left out), error|null]. */
function request_json_object(string $name): array
{
    if (!req_has($name)) {
        return [null, null];
    }
    $v = $_POST[$name];
    if (is_array($v)) {
        return [$v, null];
    }
    $text = trim((string) $v);
    if ($text === '') {
        return [[], null];
    }
    $d = json_decode($text, true);
    if (!is_array($d) || json_last_error() !== JSON_ERROR_NONE) {
        return [null, 'That is not valid JSON: ' . (json_last_error() !== JSON_ERROR_NONE ? json_last_error_msg() : 'an object is expected') . '.'];
    }
    return [$d, null];
}
