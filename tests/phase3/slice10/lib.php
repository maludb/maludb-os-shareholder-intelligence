<?php
/**
 * Helpers for slice 10's proofs (docs/build-specs/admin-settings.md "Proof"): slice 6's library (slice 4's SMOKE issuer of the EDGAR samples, the stub server, the worker, a source's
 * sealed key) and Phase 2's sign-on. The members of bin/dev_directory.json: 1 the owner (SI admin, super-admin), 26 Priya (Analyst), 27 Marco (IR Manager + Analyst), 28 Bea (an Analyst by
 * capability), 29 Ann (a Viewer, EXTERNAL), 40 the Expert, 42 the Surveillance Analyst (agents).
 */
require dirname(__DIR__) . '/slice6/lib.php';

/** A signed-on jar for a member. */
function who(int $member): string
{
    [$j, ] = sign_on($member);
    return $j;
}

/** A GET answering JSON: ['code', 'data' (the {data} object), 'json']. */
function get_json(string $jar, string $path): array
{
    $r = page($jar, $path, ['headers' => ['Accept: application/json']]);
    $j = json_decode($r['body'], true) ?: [];
    return $r + ['json' => $j, 'data' => $j['data'] ?? []];
}

/** The id of the source of a connector. */
function source_id(string $connector): int
{
    return (int) one('SELECT id FROM sources WHERE connector = :c ORDER BY id LIMIT 1', ['c' => $connector]);
}

/**
 * The worker's fetch pass with only $connector's source active (every other source made inactive for the pass and restored as it was) at the clock $at.
 * Answers the worker's report. (slice 4's pull() un-pauses every other source afterwards; the admin proofs keep the sources' states.)
 */
function fetch_only(string $connector, string $at): array
{
    $saved = q('SELECT id, active FROM sources');
    q('UPDATE sources SET active = false WHERE connector <> :c', ['c' => $connector]);
    $w = worker('fetch', $at);
    foreach ($saved as $s) { q('UPDATE sources SET active = :a WHERE id = :i', ['a' => $s['active'] ? 't' : 'f', 'i' => $s['id']]); }
    return $w;
}

/** The pulls of a source. */
function pulls_of(int $source): int
{
    return (int) one('SELECT count(*) FROM source_pulls WHERE source_id = :s', ['s' => $source]);
}

/** The form fields of the form on a page whose action is $action: [name => value] for the inputs and selects it holds (a checkbox only when checked). */
function form_values(string $html, string $action): array
{
    if (!preg_match_all('~<form[^>]*action="' . preg_quote($action, '~') . '"[^>]*>(.*?)</form>~s', $html, $m)) { return []; }
    $out = [];
    foreach ($m[1] as $body) {
        preg_match_all('~<input[^>]*name="([a-z_\[\]]+)"[^>]*>~', $body, $in, PREG_SET_ORDER);
        foreach ($in as [$tag, $name]) {
            if (str_contains($tag, 'type="checkbox"') && !str_contains($tag, ' checked')) { continue; }
            if (preg_match('~value="([^"]*)"~', $tag, $v)) { $out[$name] = html_entity_decode($v[1], ENT_QUOTES); }
        }
        break;
    }
    return $out;
}

/** A token row for a member (as the real handlers write it; the hash is a placeholder). Answers its id. */
function mint_token_row(int $member, string $label, string $scope): int
{
    return (int) q('INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES (:m, :l, :h, :s) RETURNING id', ['m' => $member, 'l' => $label, 'h' => 'h-' . bin2hex(random_bytes(6)), 's' => $scope])[0]['id'];
}

/** An agent's request headers under a run token of $member (the fake kernel's facts for $run made valid). */
function agent_headers(int $member, int $run): array
{
    kernel_state(function ($s) use ($member, $run) { $s['facts'][(string) $run] = ['valid' => true, 'member_id' => $member, 'run_id' => $run, 'request_id' => 'req-' . $run, 'is_eval' => false]; return $s; });
    return as_agent(run_token($member, $run));
}
