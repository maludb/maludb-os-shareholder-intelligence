<?php
declare(strict_types=1);

/**
 * The admin's writes (admin-settings.md, signatures fixed). Built ahead of slice 10: credential_set. A credential is sealed by credentials.php
 * (secretbox under SI_SECRETS_KEY); the database holds the ciphertext, the label and the last four; the log holds `{source_id, kind, label, last4}`
 * and never the secret. A source paused for want of a key (`settings.paused_for_key`, db/025) is resumed when the key arrives.
 */
require_once dirname(__DIR__, 2) . '/si/fetch/credentials.php';

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
