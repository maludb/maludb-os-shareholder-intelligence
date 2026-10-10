<?php
declare(strict_types=1);

/** An institution for JSON mode (whitelisted fields) and its kind chip (slice 5). */

function present_institution(array $i): array
{
    return ['institution_id' => (int) $i['institution_id'], 'cik' => $i['cik'], 'name' => $i['name'], 'kind' => $i['kind'], 'kind_source' => $i['kind_source'], 'is_activist' => (bool) $i['is_activist'],
            'aliases' => pg_text_array((string) $i['aliases']), 'website' => $i['website'], 'notes' => $i['notes'], 'tags' => pg_text_array((string) $i['tags']),
            'shares' => isset($i['shares']) && $i['shares'] !== null ? (float) $i['shares'] : null, 'period_end' => $i['period_end'] ?? null,
            'percent_of_class' => isset($i['percent_of_class']) && $i['percent_of_class'] !== null ? (float) $i['percent_of_class'] : null];
}

function institution_kind_chip(string $k): string
{
    return '<span class="badge bg-soft-primary text-primary">' . e(INSTITUTION_KINDS[$k] ?? $k) . '</span>';
}
