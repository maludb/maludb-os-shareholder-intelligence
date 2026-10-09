<?php
/**
 * Helpers for slice 1's proofs (docs/build-specs/issuers-participants.md "Proof"): the Phase 2 library (the servers, sign-on, req/page/q)
 * plus a JSON post as a person, a post under an agent's run token, the stub's request log and state, and the source rows pointed at the stub.
 */
require dirname(__DIR__, 2) . '/phase2/lib.php';
const STUB = 'http://127.0.0.1:8905';

/** A POST as a signed-on person, answering JSON. */
function post_json(string $jar, string $path, array $form): array
{
    $r = req('POST', $path, ['jar' => $jar, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . page_csrf($jar)], 'form' => $form]);
    $r['json'] = json_decode($r['body'], true) ?: [];
    return $r;
}
/** A POST as a person through the form (HTML): the response a browser gets. */
function post_form(string $jar, string $path, array $form): array
{
    return req('POST', $path, ['jar' => $jar, 'form' => $form + ['csrf_token' => page_csrf($jar)]]);
}
/** The stub's requests since the log was emptied: [{path, ua, status, t}]. */
function stub_requests(): array
{
    $f = need('SI_DEV_STATE') . '/stub.log';
    return is_file($f) ? array_map(static fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($f)))) : [];
}
function stub_reset(array $state = []): void
{
    file_put_contents(need('SI_DEV_STATE') . '/stub.log', '');
    file_put_contents(need('SI_DEV_STATE') . '/stub.json', json_encode($state ?: new stdClass()));
}
/** The EDGAR contact on or off (si_settings). */
function edgar_contact(bool $on): void
{
    q('UPDATE si_settings SET business_name = :b, edgar_contact_email = :e WHERE id = 1', $on ? ['b' => 'Kinetic Seas', 'e' => 'claude@kineticseas.com'] : ['b' => 'Kinetic Seas', 'e' => null]);
}
/** A source made due again (its last pull forgotten, no back-off). */
function source_due(string $connector): void
{
    q("UPDATE sources SET last_ok_at = NULL, backoff_until = NULL, consecutive_failures = 0 WHERE connector = :c", ['c' => $connector]);
}
/** The worker, as cron runs it, with a clock. */
function worker(string $passes, string $now): array
{
    exec('SI_WORKER_NOW=' . escapeshellarg($now) . ' php bin/worker.php --passes=' . escapeshellarg($passes) . ' 2>&1', $out, $rc);
    return ['rc' => $rc, 'report' => json_decode((string) end($out), true) ?: [], 'out' => implode("\n", $out)];
}
/** Run PHP inside the application (its bootstrap and the fetch layer loaded) in its own process; answers what it echoes, decoded when JSON. */
function app_php(string $code): mixed
{
    $f = tempnam(sys_get_temp_dir(), 'siapp') . '.php';
    file_put_contents($f, "<?php\nrequire " . var_export(dirname(__DIR__, 3) . '/app/bootstrap.php', true) . ";\nrequire_once " . var_export(dirname(__DIR__, 3) . '/app/si/fetch/source.php', true) . ";\n\$pdo = db();\n" . $code);
    $out = (string) shell_exec('php ' . escapeshellarg($f) . ' 2>&1');
    unlink($f);
    $j = json_decode($out, true);
    return $j === null && trim($out) !== 'null' ? $out : $j;
}
