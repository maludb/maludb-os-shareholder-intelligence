<?php
declare(strict_types=1);

/** The agents' whitelist presenters and chips. A dispatch's reply excerpt is shown as kept (200 characters); nothing of a run's prompt or a token is ever presented. */

function dispatch_status_chip(string $status, string $extra = ''): string
{
    return '<span class="badge ' . (DISPATCH_STATUS_CHIPS[$status] ?? 'text-bg-secondary') . '" data-status="' . e($status) . '"' . $extra . '>' . e(DISPATCH_STATUSES[$status] ?? $status) . '</span>';
}

/** Where a dispatch's record lives, for a link (null when it names none). */
function dispatch_record_url(array $d): ?string
{
    if (($d['signal_id'] ?? null) !== null) {
        return '/signals/' . (int) $d['signal_id'];
    }
    if (($d['filing_id'] ?? null) !== null) {
        return '/filings/' . (int) $d['filing_id'];
    }
    if (($d['import_id'] ?? null) !== null) {
        return '/uploads/' . (int) $d['import_id'];
    }
    return ($d['record_type'] ?? null) === 'issuer' && ($d['record_id'] ?? null) !== null ? '/issuers/' . (int) $d['record_id'] : null;
}

function present_dispatch(array $d): array
{
    return ['dispatch_id' => (int) $d['dispatch_id'], 'agent_member_id' => (int) $d['agent_member_id'], 'agent_name' => $d['agent_name'] ?? null, 'kind' => $d['kind'], 'via' => $d['via'],
            'status' => $d['status'], 'attempts' => (int) $d['attempts'], 'record_type' => $d['record_type'], 'record_id' => $d['record_id'] === null ? null : (int) $d['record_id'],
            'signal_id' => $d['signal_id'] === null ? null : (int) $d['signal_id'], 'filing_id' => $d['filing_id'] === null ? null : (int) $d['filing_id'], 'import_id' => $d['import_id'] === null ? null : (int) $d['import_id'],
            'acting_member_id' => $d['acting_member_id'] === null ? null : (int) $d['acting_member_id'], 'reply_excerpt' => $d['reply_excerpt'], 'detail' => $d['detail'],
            'cancellable' => in_array($d['status'], DISPATCH_CANCELLABLE, true), 'created_at' => json_ts($d['created_at']), 'answered_at' => json_ts($d['answered_at'])];
}

function present_agent(array $a): array
{
    $l = $a['last'];
    return ['member_id' => (int) $a['member_id'], 'display_name' => $a['display_name'], 'job_title' => $a['job_title'], 'roles' => $a['roles'],
            'last_dispatch' => $l === null ? null : ['dispatch_id' => (int) $l['dispatch_id'], 'kind' => $l['kind'], 'status' => $l['status'], 'created_at' => json_ts($l['created_at']), 'answered_at' => json_ts($l['answered_at'])],
            'week' => array_map('intval', $a['week'])];
}

function present_agent_settings(array $s): array
{
    return ['morning_note_enabled' => $s['morning_note_enabled'], 'morning_note_hour' => $s['morning_note_hour'], 'analyst_proposals' => $s['analyst_proposals'], 'pack_day' => $s['pack_day'],
            'analyst_issuer_ids' => $s['analyst_issuer_ids'], 'escalate_high_to' => $s['escalate_high_to'], 'escalate_name' => $s['escalate_name'], 'market_timezone' => $s['market_timezone']];
}
