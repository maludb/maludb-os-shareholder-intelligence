<?php
/**
 * Helpers for slice 6's proofs (docs/build-specs/short-side.md "Proof"): slice 4's library (the SMOKE issuer of the samples, CUSIP 07831X206,
 * ticker SMOK; a pull by the worker), the share counts the fixtures were written against, a pull at the fixtures' last trading day after the
 * lists are out (2024-04-04 23:30 in New York — a Thursday; Good Friday the holiday run.sh sets), and a source's credential sealed under the
 * scratch environment's key.
 */
require dirname(__DIR__) . '/slice4/lib.php';
const AT_S6 = '2024-04-04T23:30:00-04:00';

/** The SMOKE issuer with its counts as of 2024-01-01: 17,053,900 outstanding, 15,000,000 float. */
function seed_short(): array
{
    $sm = smoke_issuer();
    foreach (['outstanding' => 17053900, 'float' => 15000000] as $k => $v) {
        q("INSERT INTO share_counts (security_id, as_of, kind, value, source) VALUES (:s, '2024-01-01', :k, :v, 'manual') ON CONFLICT DO NOTHING", ['s' => $sm['security'], 'k' => $k, 'v' => $v]);
    }
    return $sm;
}

/** One short-side source pulled by the worker at AT_S6 (made due, the others paused for the pass). */
function pull6(string $connector, string $at = AT_S6): array
{
    return pull($connector, $at);
}

/** A source's settings merged (the proofs' knobs: backfill_days, loaded, done). */
function source_settings(string $connector, array $merge): void
{
    q('UPDATE sources SET settings = settings || CAST(:m AS jsonb) WHERE connector = :c', ['m' => json_encode($merge), 'c' => $connector]);
}

/** Seal a key for a source the way slice 10's form will (credentials.php under the scratch SI_SECRETS_KEY). */
function set_credential(string $connector, string $secret): void
{
    $sid = (int) one('SELECT id FROM sources WHERE connector = :c', ['c' => $connector]);
    $hex = app_php('require_once ' . var_export(dirname(__DIR__, 3) . '/app/si/fetch/credentials.php', true) . '; echo json_encode(bin2hex(si_credential_seal(' . var_export($secret, true) . ')));');
    q("INSERT INTO source_credentials (source_id, kind, label, last4, ciphertext) VALUES (:s, 'api_key', 'SMOKE key', :l, decode(:c, 'hex'))", ['s' => $sid, 'l' => substr($secret, -4), 'c' => $hex]);
    q('UPDATE sources SET credential_id = (SELECT max(id) FROM source_credentials WHERE source_id = :s), paused_at = NULL, paused_reason = NULL WHERE id = :s2', ['s' => $sid, 's2' => $sid]);
}

/** Every short-side source pulled once (the screens' data). */
function seed_short_all(): array
{
    $sm = seed_short();
    edgar_contact(true);
    q("UPDATE sources SET paused_at = NULL, paused_reason = NULL WHERE connector = 'prices_stooq'");
    foreach (['finra_short_interest', 'finra_daily_short_volume', 'sec_ftd', 'threshold_nasdaq', 'threshold_nyse', 'threshold_otc', 'prices_stooq'] as $c) {
        pull6($c);
    }
    return $sm;
}

/** Upload a file through the form as the person of $jar (slice 2's helper; multipart, the JSON answer): ['code', 'json', 'body']. */
function upload(string $jar, array $file, array $form = []): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'up');
    file_put_contents($tmp, $file['bytes']);
    $ch = curl_init(BASE . '/uploads/save.php');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-CSRF-Token: ' . page_csrf($jar)], CURLOPT_POSTFIELDS => $form + ['file' => new CURLFile($tmp, 'text/csv', $file['name'])]]);
    $b = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    @unlink($tmp);
    return ['code' => $code, 'body' => $b, 'json' => json_decode($b, true) ?: []];
}
