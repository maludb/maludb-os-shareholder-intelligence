<?php
declare(strict_types=1);

/**
 * A person's own settings (screens `settings`, `notifications`, `settings-tokens`; actions prefs_save, notification_read, notifications_read_all,
 * token_mint, token_revoke — sso-shell.md): how they are told (notification_prefs, db/014: the channels, the events, the morning note and its hour,
 * the least severity of signal worth a notice, which issuers), their in-app notifications, their MCP tokens (scope mcp; a key — scope api, acting
 * as the person — is slice 9's key_mint). The application's settings (settings_save) are slice 10's.
 */

/** Every event a person can be told about, with the sentence the settings screen shows (db/014 notifications.kind). */
const NOTICE_KINDS = [
    'signal_high'           => 'A high signal is raised on an issuer I follow',
    'signal'                => 'Any signal at or above my least severity',
    'signal_proposal'       => 'The Analyst proposes how to dispose of a signal',
    'match_proposal'        => 'A holder match waits for a decision',
    'upload_done'           => 'An upload of mine is parsed and ties',
    'upload_untied'         => 'An upload does not tie to its own totals',
    'reconciliation_untied' => 'The share count does not reconcile',
    'filing_13d'            => 'A Schedule 13D or 13G is filed on an issuer I follow',
    'filing_watched'        => 'A watched form is filed (8-K, S-3, 424B, Form 4, 144)',
    'watch_hit'             => 'The full-text watch finds a dilution term',
    'source_blocked'        => 'A source refuses us (403 or 429)',
    'source_failed'         => 'A source fails four times in a row',
    'morning_note'          => 'The Analyst\'s morning note',
    'pack_drafted'          => 'The Friday pack is drafted',
    'pack_approved'         => 'A pack is approved',
    'export_ready'          => 'An export I asked for is ready',
    'mention'               => 'Someone mentions me in a note',
    'digest'                => 'A daily digest instead of each notice',
];

/** The person's choices, or the table's defaults when they never saved any. */
function find_prefs(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare('SELECT email_enabled, text_enabled, kinds, text_kinds, morning_note, morning_hour, signals_min_severity, issuer_ids FROM notification_prefs WHERE member_id = :m');
    $st->execute(['m' => $memberId]);
    $r = $st->fetch();
    if ($r === false) {
        $d = $pdo->query("SELECT column_name, column_default FROM information_schema.columns WHERE table_name = 'notification_prefs' AND column_name IN ('kinds', 'text_kinds')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $lit = static fn (string $def): string => preg_match("/'(\{[^']*\})'/", $def, $m) ? $m[1] : '{}';
        return ['email_enabled' => true, 'text_enabled' => false, 'kinds' => pg_text_array($lit((string) ($d['kinds'] ?? ''))),
                'text_kinds' => pg_text_array($lit((string) ($d['text_kinds'] ?? ''))), 'morning_note' => true, 'morning_hour' => null,
                'signals_min_severity' => 'watch', 'issuer_ids' => [], 'saved' => false];
    }
    return ['email_enabled' => (bool) $r['email_enabled'], 'text_enabled' => (bool) $r['text_enabled'], 'kinds' => pg_text_array((string) $r['kinds']),
            'text_kinds' => pg_text_array((string) $r['text_kinds']), 'morning_note' => (bool) $r['morning_note'],
            'morning_hour' => $r['morning_hour'] === null ? null : (int) $r['morning_hour'], 'signals_min_severity' => (string) $r['signals_min_severity'],
            'issuer_ids' => pg_int_array((string) $r['issuer_ids']), 'saved' => true];
}

/** The same, by the spec's name. */
function my_prefs(PDO $pdo, int $memberId): array
{
    return find_prefs($pdo, $memberId);
}

/** Every member has their preferences before they look (sign-on and the directory sync call this). Idempotent. */
function ensure_prefs(PDO $pdo, int $memberId): void
{
    $pdo->prepare('INSERT INTO notification_prefs (member_id) VALUES (:m) ON CONFLICT (member_id) DO NOTHING')->execute(['m' => $memberId]);
}

/** Save the choices ($prefs keys present are changed; absent are kept). Answers ['before' => …, 'after' => …] of the changed keys. */
function save_prefs(PDO $pdo, int $memberId, array $prefs): array
{
    $before = find_prefs($pdo, $memberId);
    unset($before['saved']);
    $after = array_intersect_key($prefs, $before) + $before;
    $lit = static fn (array $a): string => '{' . implode(',', array_map(static fn (string $k): string => preg_replace('/[^a-z_]/', '', $k), $a)) . '}';
    $pdo->prepare('INSERT INTO notification_prefs (member_id, email_enabled, text_enabled, kinds, text_kinds, morning_note, morning_hour, signals_min_severity, issuer_ids)
                   VALUES (:m, :e, :t, CAST(:k AS text[]), CAST(:tk AS text[]), :mn, :mh, :sev, CAST(:iss AS bigint[]))
                   ON CONFLICT (member_id) DO UPDATE SET email_enabled = EXCLUDED.email_enabled, text_enabled = EXCLUDED.text_enabled, kinds = EXCLUDED.kinds,
                       text_kinds = EXCLUDED.text_kinds, morning_note = EXCLUDED.morning_note, morning_hour = EXCLUDED.morning_hour,
                       signals_min_severity = EXCLUDED.signals_min_severity, issuer_ids = EXCLUDED.issuer_ids, updated_at = now()')
        ->execute(['m' => $memberId, 'e' => $after['email_enabled'] ? 't' : 'f', 't' => $after['text_enabled'] ? 't' : 'f', 'k' => $lit($after['kinds']), 'tk' => $lit($after['text_kinds']),
                   'mn' => $after['morning_note'] ? 't' : 'f', 'mh' => $after['morning_hour'], 'sev' => $after['signals_min_severity'], 'iss' => pg_array_literal(array_map('intval', $after['issuer_ids']))]);
    $changed = array_keys(array_filter($after, static fn ($v, string $k): bool => $v !== $before[$k], ARRAY_FILTER_USE_BOTH));
    return ['before' => array_intersect_key($before, array_flip($changed)), 'after' => array_intersect_key($after, array_flip($changed)), 'prefs' => $after];
}

/**
 * What the kernel last said about this person's texts, from our own outbox (this application never sees a phone number):
 * 'no_verified_phone', 'opted_out', 'no_sender' or null when the latest text was sent (or none was ever tried).
 */
function last_text_refusal(PDO $pdo, int $memberId): ?string
{
    $st = $pdo->prepare("SELECT status, detail FROM notification_outbox WHERE member_id = :m AND channel = 'text' AND status IN ('sent', 'skipped', 'failed') ORDER BY id DESC LIMIT 1");
    $st->execute(['m' => $memberId]);
    $r = $st->fetch();
    if ($r === false || $r['status'] === 'sent') {
        return null;
    }
    foreach (['no_verified_phone', 'opted_out', 'no_sender'] as $code) {
        if (str_contains((string) $r['detail'], $code)) {
            return $code;
        }
    }
    return null;
}

// ---- notifications (the bell) ----------------------------------------------------------------------------------------
function find_my_notifications(PDO $pdo, int $memberId, bool $unreadOnly = false, int $limit = 50, int $page = 1): array
{
    $st = $pdo->prepare('SELECT notification_id, kind, record_type, record_id, signal_id, issuer_id, title, body, read_at, created_at FROM mcp_notifications'
        . ($unreadOnly ? ' WHERE read_at IS NULL' : '') . ' ORDER BY (read_at IS NULL) DESC, created_at DESC LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . (max(1, $page) - 1) * max(1, min(200, $limit)));
    $st->execute();
    return $st->fetchAll();
}

/** Mark one of the member's own notifications read, or every unread one (null). Returns how many changed. */
function mark_notifications_read(PDO $pdo, int $memberId, ?int $id): int
{
    if ($id === null) {
        $st = $pdo->prepare('UPDATE notifications SET read_at = now() WHERE member_id = :m AND read_at IS NULL');
        $st->execute(['m' => $memberId]);
    } else {
        $st = $pdo->prepare('UPDATE notifications SET read_at = now() WHERE member_id = :m AND id = :id AND read_at IS NULL');
        $st->execute(['m' => $memberId, 'id' => $id]);
    }
    return $st->rowCount();
}

// ---- tokens -----------------------------------------------------------------------------------------------------------
/** My tokens: the MCP tokens and, beside them, my API keys (scope api — minted, rotated and revoked by slice 9's key actions). */
function find_my_tokens(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare("SELECT id, label, scope, last_used_at, expires_at, revoked_at, created_at FROM mcp_access_tokens WHERE member_id = :m AND scope IN ('mcp', 'api') ORDER BY scope DESC, created_at DESC");
    $st->execute(['m' => $memberId]);
    return $st->fetchAll();
}

function find_my_token(PDO $pdo, int $memberId, int $tokenId): ?array
{
    $st = $pdo->prepare("SELECT id, label, scope, last_used_at, expires_at, revoked_at, created_at FROM mcp_access_tokens WHERE member_id = :m AND id = :id AND scope = 'mcp'");
    $st->execute(['m' => $memberId, 'id' => $tokenId]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** Mint a person's own MCP token (read-only): `mcp_` + 48 hex, shown once; only the hash is stored. A key (scope api, acting as the person) is slice 9's key_mint. */
function mint_token(PDO $pdo, int $memberId, string $label, string $scope): array
{
    $raw = 'mcp_' . bin2hex(random_bytes(24));
    $st = $pdo->prepare('INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES (:m, :l, :h, :s) RETURNING id');
    $st->execute(['m' => $memberId, 'l' => $label, 'h' => hash('sha256', $raw), 's' => $scope]);
    return ['id' => (int) $st->fetchColumn(), 'raw' => $raw, 'label' => $label, 'scope' => $scope];
}

/** Revoke one of the member's own live tokens. False when there is none such. */
function revoke_token(PDO $pdo, int $memberId, int $tokenId): bool
{
    $st = $pdo->prepare("UPDATE mcp_access_tokens SET revoked_at = now() WHERE id = :id AND member_id = :m AND scope = 'mcp' AND revoked_at IS NULL RETURNING id");
    $st->execute(['id' => $tokenId, 'm' => $memberId]);
    return $st->fetchColumn() !== false;
}
