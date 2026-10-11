<?php
declare(strict_types=1);

/** The admin's whitelist presenters (the JSON branch). Built ahead of slice 10 for the credential door; a credential is its kind, label and last four — never the secret. */

function present_source_credential(array $s): array
{
    $c = $s['credential'];
    return ['source_id' => (int) $s['source_id'], 'name' => $s['name'], 'connector' => $s['connector'], 'paused' => $s['paused_at'] !== null, 'paused_reason' => $s['paused_reason'],
            'credential' => $c === null ? null : ['kind' => $c['kind'], 'label' => $c['label'], 'last4' => $c['last4'], 'set_at' => json_ts($c['created_at']), 'rotated_at' => json_ts($c['rotated_at'])]];
}
