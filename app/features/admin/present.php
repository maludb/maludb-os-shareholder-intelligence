<?php
declare(strict_types=1);

/** The admin's whitelist presenters (the JSON branch). Built ahead of slice 10 for the credential door; a credential is its kind, label and last four — never the secret. */

function present_source_credential(array $s): array
{
    $c = $s['credential'];
    return ['source_id' => (int) $s['source_id'], 'name' => $s['name'], 'connector' => $s['connector'], 'paused' => $s['paused_at'] !== null, 'paused_reason' => $s['paused_reason'],
            'credential' => $c === null ? null : ['kind' => $c['kind'], 'label' => $c['label'], 'last4' => $c['last4'], 'set_at' => json_ts($c['created_at']), 'rotated_at' => json_ts($c['rotated_at'])]];
}

/* ---- Slice 10: the rest of the admin's shapes (whitelists — never a raw row, never a secret) ---- */

/** The settings object of a source as an array (a connector's bookkeeping included; no secret is ever kept there, source_save refuses one). */
function source_settings_array(array $s): array
{
    $v = $s['settings'] ?? [];
    return is_array($v) ? $v : (json_decode((string) $v, true) ?: []);
}

function present_source_row(array $r): array
{
    return ['source_id' => (int) $r['source_id'], 'name' => $r['name'], 'connector' => $r['connector'], 'role' => $r['role'], 'active' => (bool) $r['active'], 'paused' => $r['paused_at'] !== null,
            'paused_reason' => $r['paused_reason'], 'schedule_minutes' => $r['schedule_minutes'] === null ? null : (int) $r['schedule_minutes'], 'rate_per_second' => (float) $r['rate_per_second'],
            'has_credential' => (bool) $r['has_credential'], 'robots_state' => $r['robots_state'], 'last_ok_at' => json_ts($r['last_ok_at']), 'last_pull_at' => json_ts($r['last_pull_at'] ?? null),
            'last_status' => $r['last_status'] ?? null, 'last_error' => $r['last_error'] ?? null, 'consecutive_failures' => (int) $r['consecutive_failures'], 'backoff_until' => json_ts($r['backoff_until']),
            'due' => (bool) ($r['due'] ?? false), 'needs_user_agent' => (bool) ($r['needs_user_agent'] ?? false),
            'next_slot' => ($r['next_slot'] ?? null) instanceof DateTimeInterface ? $r['next_slot']->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z') : null];
}

/** A pull and the policy it followed (the whitelist of the policy: who it said it was, how fast, robots, the cache, the refusals; never a URL). */
function present_pull(array $p): array
{
    $pol = is_array($p['policy'] ?? null) ? $p['policy'] : (json_decode((string) ($p['policy'] ?? '{}'), true) ?: []);
    return ['pull_id' => (int) $p['pull_id'], 'kind' => $p['kind'], 'started_at' => json_ts($p['started_at']), 'finished_at' => json_ts($p['finished_at']), 'status' => $p['status'],
            'items_seen' => (int) $p['items_seen'], 'items_new' => (int) $p['items_new'], 'items_changed' => (int) $p['items_changed'], 'http_requests' => (int) $p['http_requests'], 'bytes' => (int) $p['bytes'],
            'error' => $p['error'], 'started_by' => $p['started_by'] === null ? null : (int) $p['started_by'],
            'policy' => ['user_agent' => $pol['user_agent'] ?? null, 'rate_per_second' => isset($pol['rate_per_second']) ? (float) $pol['rate_per_second'] : null, 'robots' => $pol['robots'] ?? null,
                         'cached' => isset($pol['cached']) ? (int) $pol['cached'] : null, 'blocked' => isset($pol['blocked']) ? (int) $pol['blocked'] : null,
                         'robots_skipped' => isset($pol['robots_skipped']) ? (int) $pol['robots_skipped'] : null, 'refused_before_request' => (bool) ($pol['refused_before_request'] ?? false)]];
}

function present_source_detail(array $s, array $pulls, ?DateTimeImmutable $next): array
{
    $c = $s['credential'] ?? null;
    return ['source_id' => (int) $s['source_id'], 'name' => $s['name'], 'connector' => $s['connector'], 'role' => $s['role'], 'base_url' => $s['base_url'], 'active' => (bool) $s['active'],
            'paused' => $s['paused_at'] !== null, 'paused_reason' => $s['paused_reason'], 'schedule_minutes' => $s['schedule_minutes'] === null ? null : (int) $s['schedule_minutes'],
            'rate_per_second' => (float) $s['rate_per_second'], 'robots_state' => $s['robots_state'], 'last_ok_at' => json_ts($s['last_ok_at']), 'consecutive_failures' => (int) $s['consecutive_failures'],
            'backoff_until' => json_ts($s['backoff_until']), 'next_slot' => $next === null ? null : $next->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'settings' => (object) source_settings_array($s),
            'credential' => $c === null ? null : ['kind' => $c['kind'], 'label' => $c['label'], 'last4' => $c['last4'], 'set_at' => json_ts($c['created_at']), 'rotated_at' => json_ts($c['rotated_at'])],
            'pulls' => array_map('present_pull', $pulls)];
}

function present_class(array $c): array
{
    return ['class_key' => $c['class_key'], 'name' => $c['name'], 'description' => $c['description'], 'side' => $c['side'], 'participants' => (int) $c['count'], 'set_by_a_person' => (int) $c['manual']];
}

function present_template(array $t): array
{
    return ['template_id' => (int) $t['template_id'], 'name' => $t['name'], 'kind' => $t['kind'], 'connector' => $t['connector'], 'mapping' => json_decode((string) $t['mapping'], true) ?: (object) [], 'updated_at' => json_ts($t['updated_at'])];
}

/** The settings as JSON: every field the form changes, the EDGAR user-agent they make (null while the contact is missing) and nothing else. */
function present_settings(array $s): array
{
    $o = [];
    foreach (SETTINGS_COLUMNS as $k) {
        $v = $s[$k] ?? null;
        $o[$k] = match ($k) {
            'edgar_rate_per_second', 'auto_match_threshold' => $v === null ? null : (float) $v,
            'reconciliation_tolerance_shares', 'signal_grace_days', 'tombstone_days', 'key_rate_per_minute', 'key_rate_per_day', 'max_upload_bytes' => $v === null ? null : (int) $v,
            'retain_raw_rows_days', 'escalate_high_to' => $v === null ? null : (int) $v,
            'market_holidays' => array_values($s['market_holidays'] ?? []),
            default => $v,
        };
    }
    return $o + ['edgar_user_agent' => $s['edgar_user_agent'], 'edgar_user_agent_set' => $s['edgar_user_agent'] !== null, 'escalate_name' => $s['escalate_name'] ?? null];
}

function present_admin_token(array $t): array
{
    return ['token_id' => (int) $t['token_id'], 'member_id' => (int) $t['member_id'], 'person' => $t['member_name'], 'member_kind' => $t['member_kind'], 'label' => $t['label'], 'scope' => $t['scope'],
            'rate_per_minute' => $t['rate_per_minute'] === null ? null : (int) $t['rate_per_minute'], 'rate_per_day' => $t['rate_per_day'] === null ? null : (int) $t['rate_per_day'],
            'calls_today' => $t['calls_today'] === null ? null : (int) $t['calls_today'], 'rotated' => $t['rotated_from'] !== null, 'last_used_at' => json_ts($t['last_used_at']), 'expires_at' => json_ts($t['expires_at']),
            'revoked' => $t['revoked_at'] !== null, 'created_at' => json_ts($t['created_at'])];
}

function present_tombstone(array $t): array
{
    return ['kind' => $t['kind'], 'record_id' => (int) $t['record_id'], 'label' => $t['label'], 'issuer_id' => $t['issuer_id'] === null ? null : (int) $t['issuer_id'], 'deleted_at' => json_ts($t['deleted_at']),
            'deleted_by' => $t['deleted_by'] === null ? null : (int) $t['deleted_by'], 'deleted_by_name' => $t['deleted_by_name'], 'purge_after' => json_ts($t['purge_after']), 'due' => (bool) $t['due']];
}

/** "every 6 hours", "every 30 days", "manual only" for a schedule in minutes. */
function schedule_words(?int $m): string
{
    if ($m === null || $m <= 0) {
        return 'manual only';
    }
    if ($m % 1440 === 0) { $n = intdiv($m, 1440); return 'every ' . ($n === 1 ? 'day' : $n . ' days'); }
    if ($m % 60 === 0) { $n = intdiv($m, 60); return 'every ' . ($n === 1 ? 'hour' : $n . ' hours'); }
    return 'every ' . $m . ' minutes';
}

/** A pull's status as a badge (ok, partial, failed, blocked, running). */
function pull_status_chip(?string $status): string
{
    if ($status === null || $status === '') {
        return '<span class="badge text-bg-light">never pulled</span>';
    }
    $cls = ['ok' => 'text-bg-success', 'partial' => 'text-bg-info', 'failed' => 'text-bg-danger', 'blocked' => 'text-bg-dark', 'running' => 'text-bg-secondary'][$status] ?? 'text-bg-light';
    return '<span class="badge ' . $cls . '">' . e($status) . '</span>';
}
