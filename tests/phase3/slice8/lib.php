<?php
/**
 * Helpers for slice 8's proofs (docs/build-specs/events-reports.md "Proof"): slice 1's library (sign-on, JSON posts, the worker, app_php) and Phase 2's SMOKE store
 * (seed_store: SMOKE Alpha Corp with prices, an SPR, short interest, three events, an open signal, two holders; Beta restricted to Dana). The members of
 * bin/dev_directory.json: 1 the owner (admin), 26 Priya (Analyst), 27 Marco (IR Manager), 28 Bea (Analyst by capability), 29 Ann (a Viewer, EXTERNAL),
 * 30 Dana (on Beta's list), 31 Lee (admitted, no role), 42 the Surveillance Analyst (an agent).
 */
require dirname(__DIR__) . '/slice1/lib.php';

/** The SMOKE store's ids (idempotent). */
function seed8(): array
{
    static $ids = null;
    return $ids ??= seed_store();
}

/** The fake MaluMail's log of sends since it was emptied: [{from, to, subject, text, html}]. */
function mail_log(): array
{
    $f = need('SI_DEV_STATE') . '/malumail.log';
    return is_file($f) ? array_values(array_filter(array_map(static fn ($l) => json_decode($l, true), explode("\n", (string) file_get_contents($f))))) : [];
}
function mail_reset(): void
{
    file_put_contents(need('SI_DEV_STATE') . '/malumail.log', '');
}

/** An agent's request headers under a run token of $member (the kernel's facts for $run made valid). */
function agent_headers(int $member, int $run): array
{
    kernel_state(function ($s) use ($member, $run) { $s['facts'][(string) $run] = ['valid' => true, 'member_id' => $member, 'run_id' => $run, 'request_id' => 'req-' . $run, 'is_eval' => false]; return $s; });
    return as_agent(run_token($member, $run));
}
/** A POST under an agent's run token: ['code', 'json', 'body']. */
function agent_post(array $headers, string $path, array $form): array
{
    $r = req('POST', $path, ['headers' => $headers, 'form' => $form]);
    $r['json'] = json_decode($r['body'], true) ?: [];
    return $r;
}
/** The id a JSON write answered as record_id (0 when it did not). */
function rid(array $r): int
{
    return (int) ($r['json']['record_id'] ?? 0);
}
/** A signed-on person's jar with the SMOKE Alpha issuer chosen. */
function person(int $member): string
{
    [$j, ] = sign_on($member);
    page($j, '/?issuer=' . seed8()['alpha']);
    return $j;
}
