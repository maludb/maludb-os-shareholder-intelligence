<?php
declare(strict_types=1);

/** The JSON shapes of a person's settings — whitelists, never a raw row; never a token's value or hash. */

function present_prefs(array $p): array
{
    return ['email_enabled' => (bool) $p['email_enabled'], 'text_enabled' => (bool) $p['text_enabled'], 'kinds' => array_values($p['kinds']),
            'text_kinds' => array_values($p['text_kinds']), 'morning_note' => (bool) $p['morning_note'], 'morning_hour' => $p['morning_hour'],
            'signals_min_severity' => $p['signals_min_severity'], 'issuer_ids' => array_values($p['issuer_ids']), 'saved' => (bool) ($p['saved'] ?? true)];
}

function present_token(array $t): array
{
    return ['token_id' => (int) $t['id'], 'label' => $t['label'], 'scope' => $t['scope'], 'created_at' => json_ts($t['created_at']),
            'last_used_at' => json_ts($t['last_used_at'] ?? null), 'expires_at' => json_ts($t['expires_at'] ?? null), 'revoked' => ($t['revoked_at'] ?? null) !== null];
}

function present_notification(array $n): array
{
    return ['notification_id' => (int) $n['notification_id'], 'kind' => $n['kind'], 'record_type' => $n['record_type'],
            'record_id' => $n['record_id'] !== null ? (int) $n['record_id'] : null, 'title' => $n['title'], 'body' => $n['body'],
            'signal_id' => ($n['signal_id'] ?? null) !== null ? (int) $n['signal_id'] : null, 'issuer_id' => ($n['issuer_id'] ?? null) !== null ? (int) $n['issuer_id'] : null,
            'read' => $n['read_at'] !== null, 'created_at' => json_ts($n['created_at'])];
}

/** The screen a notification points at, by the record it names (null when none). The slices' screens; a placeholder until each is built. */
function notification_record_url(array $n): ?string
{
    $id = ($n['record_id'] ?? null) !== null ? (int) $n['record_id'] : null;
    if (($n['signal_id'] ?? null) !== null) {
        return '/signals/' . (int) $n['signal_id'];
    }
    if ($id === null) {
        return null;
    }
    return match ($n['record_type'] ?? null) {
        'signal' => '/signals/' . $id,
        'import', 'upload' => '/uploads/' . $id,
        'holder' => '/holders/' . $id,
        'holder_match_proposal' => '/holders/unmatched',
        'filing' => '/filings/' . $id,
        'event' => '/events/' . $id,
        'report' => '/reports/' . $id,
        'issuer' => '/issuers/' . $id,
        'source' => '/admin/sources/' . $id,
        'export' => '/exports',
        'share_reconciliation' => '/reconciliation',
        default => null,
    };
}

/** The icon of a kind of notification. */
function notification_icon(string $kind): string
{
    return match (true) {
        str_starts_with($kind, 'signal') => 'feather-flag',
        str_starts_with($kind, 'upload'), $kind === 'reconciliation_untied' => 'feather-upload',
        str_starts_with($kind, 'filing'), $kind === 'watch_hit' => 'feather-file-text',
        str_starts_with($kind, 'source') => 'feather-alert-triangle',
        str_starts_with($kind, 'pack'), $kind === 'morning_note', $kind === 'digest' => 'feather-book-open',
        $kind === 'match_proposal' => 'feather-users',
        default => 'feather-bell',
    };
}
