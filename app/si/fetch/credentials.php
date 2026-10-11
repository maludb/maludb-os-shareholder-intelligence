<?php
declare(strict_types=1);

/**
 * THE ONE FILE that opens a source's credential (admin-settings.md divergence 1): libsodium secretbox under SI_SECRETS_KEY (64 hex characters,
 * config/.env), the ciphertext the 24-byte nonce followed by the box. The fetcher that needs a key asks here at the moment of the request;
 * the secret is never logged, never in a pull's policy or error, never shown back (the form shows the label and the last four). Slice 6 needs
 * it for the keyed price providers; slice 10 builds the form (`credential_set`) on si_credential_seal().
 */

final class SiCredentialError extends RuntimeException {}

function si_secrets_key(): string
{
    $hex = trim((string) env('SI_SECRETS_KEY', ''));
    if (!preg_match('~^[0-9a-f]{64}$~i', $hex)) {
        throw new SiCredentialError('The credential store is not set up (SI_SECRETS_KEY) — the installer sets it; ask the admin.');
    }
    return (string) hex2bin($hex);
}

/** The ciphertext of a secret (nonce || box). */
function si_credential_seal(string $plain): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return $nonce . sodium_crypto_secretbox($plain, $nonce, si_secrets_key());
}

/** A source's secret in clear, or null when the source has none; throws when the ciphertext does not open under this key. */
function si_source_secret(PDO $pdo, array $source): ?string
{
    $cid = $source['credential_id'] ?? null;
    $st = $cid === null ? $pdo->prepare('SELECT ciphertext FROM source_credentials WHERE source_id = :s ORDER BY id DESC LIMIT 1')
                        : $pdo->prepare('SELECT ciphertext FROM source_credentials WHERE id = :s');
    $st->execute(['s' => $cid === null ? (int) $source['id'] : (int) $cid]);
    $c = $st->fetchColumn();
    if ($c === false || $c === null) {
        return null;
    }
    $bytes = is_resource($c) ? (string) stream_get_contents($c) : (string) $c;
    if (strlen($bytes) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        throw new SiCredentialError('The source\'s credential is damaged: set it again.');
    }
    $plain = sodium_crypto_secretbox_open(substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), si_secrets_key());
    if ($plain === false) {
        throw new SiCredentialError('The source\'s credential does not open under this installation\'s key: set it again.');
    }
    return $plain;
}
