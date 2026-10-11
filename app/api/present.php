<?php
declare(strict_types=1);

/** The JSON shapes of the API's own records — whitelists, never a raw row; never a key's value or hash. */

/** A key as its owner (or a holder of tokens.manage) sees it: the label, the limits, the use — never the key itself. */
function present_api_key(array $k): array
{
    return ['key_id' => (int) $k['key_id'], 'member_id' => (int) $k['member_id'], 'label' => $k['label'], 'rate_per_minute' => (int) $k['rate_per_minute'], 'rate_per_day' => (int) $k['rate_per_day'],
            'calls_today' => (int) ($k['calls_today'] ?? 0), 'rotated_from' => ($k['rotated_from'] ?? null) === null ? null : (int) $k['rotated_from'], 'last_used_at' => json_ts($k['last_used_at'] ?? null),
            'expires_at' => json_ts($k['expires_at'] ?? null), 'revoked' => ($k['revoked_at'] ?? null) !== null, 'created_at' => json_ts($k['created_at'])];
}
