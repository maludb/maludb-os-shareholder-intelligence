<?php
declare(strict_types=1);

/**
 * API keys (agents-k7-api.md; design §4): a key is a PERSON'S OWN — scope `api`, acting as them, seeing exactly what they see, rate-limited per minute and per day, logged under its
 * label. The raw key (`sik_` + 48 hex) exists in the request that minted it and nowhere else: only its sha256 is stored (db/003), and the log carries the LABEL, never the key.
 * The database keeps the rules it can (the defaults a trigger fills, si_key_rotate's overlap, si_resolve_key's refusals); these functions keep who may do it.
 */

/** The most live keys a person keeps. */
const KEY_MAX_LIVE = 10;

/** A key the caller may see (their own, or anyone's with tokens.manage — the mcp_keys view), or null. */
function find_key(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_keys WHERE key_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The keys of a person (the Tokens screen lists the caller's own), newest first. */
function find_keys_of(PDO $pdo, int $member): array
{
    $st = $pdo->prepare('SELECT * FROM mcp_keys WHERE member_id = :m ORDER BY revoked_at IS NOT NULL, created_at DESC, key_id DESC');
    $st->execute(['m' => $member]);
    return $st->fetchAll();
}

/** A new raw key. */
function key_new_raw(): string
{
    return 'sik_' . bin2hex(random_bytes(24));
}

/**
 * Mint a key for $member (a person, active and admitted). Answers ['raw' => the key, shown once, 'id' => the key's id]; logs `key.mint` {key_id, label, member_id}.
 * A person keeps at most KEY_MAX_LIVE live keys.
 */
function key_mint(PDO $pdo, int $member, string $label): array
{
    $m = $pdo->prepare("SELECT member_kind, status, capability FROM members WHERE id = :m");
    $m->execute(['m' => $member]);
    $p = $m->fetch();
    if ($p === false || $p['status'] !== 'active' || $p['capability'] === null) {
        throw new DomainException('Not found.');
    }
    if ($p['member_kind'] !== 'human') {
        throw new DomainException('A key acts as a person; an agent uses the tools.');
    }
    if ((int) one_value($pdo, "SELECT count(*) FROM mcp_access_tokens WHERE member_id = :m AND scope = 'api' AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > now())", ['m' => $member]) >= KEY_MAX_LIVE) {
        throw new DomainException('At most ' . KEY_MAX_LIVE . ' live keys a person: revoke one first.');
    }
    $raw = key_new_raw();
    $st = $pdo->prepare("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES (:m, :l, :h, 'api') RETURNING id");
    $st->execute(['m' => $member, 'l' => $label, 'h' => hash('sha256', $raw)]);
    $id = (int) $st->fetchColumn();
    log_activity($pdo, 'key.mint', 'mcp_access_token', $id, ['after' => ['key_id' => $id, 'label' => $label, 'member_id' => $member]]);
    return ['raw' => $raw, 'id' => $id];
}

/**
 * Rotate a live key: a new key for the same person with the same label and limits, the old one working for the overlap (si_settings.key_rotation_overlap_hours, 24 by default)
 * and then no more. Answers ['raw', 'id' (the new key)]; logs `key.rotate` {key_id (new), rotated_from, label}.
 */
function key_rotate(PDO $pdo, int $id): array
{
    $old = find_key($pdo, $id) ?? throw new DomainException('Not found.');
    $raw = key_new_raw();
    $nid = (int) one_value($pdo, 'SELECT si_key_rotate(:o, :h)', ['o' => $id, 'h' => hash('sha256', $raw)]);
    log_activity($pdo, 'key.rotate', 'mcp_access_token', $nid, ['after' => ['key_id' => $nid, 'rotated_from' => $id, 'label' => $old['label'], 'member_id' => (int) $old['member_id']]]);
    return ['raw' => $raw, 'id' => $nid];
}

/** Revoke a live key at once (its rotated predecessor, still inside its overlap, is a key of its own and is revoked on its own). Logs `key.revoke` {key_id, label}. */
function key_revoke(PDO $pdo, int $id): void
{
    $k = find_key($pdo, $id) ?? throw new DomainException('Not found.');
    $st = $pdo->prepare("UPDATE mcp_access_tokens SET revoked_at = now() WHERE id = :id AND scope = 'api' AND revoked_at IS NULL");
    $st->execute(['id' => $id]);
    if ($st->rowCount() !== 1) {
        throw new DomainException('The key is revoked already.');
    }
    log_activity($pdo, 'key.revoke', 'mcp_access_token', $id, ['before' => ['label' => $k['label']], 'after' => ['key_id' => $id, 'label' => $k['label'], 'member_id' => (int) $k['member_id']]]);
}
