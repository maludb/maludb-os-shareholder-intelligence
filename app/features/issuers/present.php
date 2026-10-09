<?php
declare(strict_types=1);

/** The JSON shapes of slice 1's issuer screens — whitelists, never a raw row. */

function present_issuer_card(array $i): array
{
    return ['issuer_id' => (int) $i['issuer_id'], 'name' => $i['name'], 'ticker' => $i['ticker'], 'cik' => $i['cik'], 'exchange' => $i['exchange'], 'restricted' => (bool) $i['restricted'],
            'primary_security_id' => $i['primary_security_id'] === null ? null : (int) $i['primary_security_id'], 'cusip' => $i['cusip'] ?? null,
            'last_position_date' => $i['last_position_date'] ?? null, 'securities_count' => (int) ($i['securities_count'] ?? 0), 'tags' => pg_text_array((string) ($i['tags'] ?? '{}'))];
}

function present_issuer(array $i): array
{
    $entity = json_decode((string) ($i['edgar_entity'] ?? '{}'), true) ?: [];
    return ['issuer_id' => (int) $i['id'], 'name' => $i['name'], 'cik' => $i['cik'], 'ticker' => $i['ticker'], 'exchange' => $i['exchange'], 'state_of_incorporation' => $i['state_of_incorporation'],
            'fiscal_year_end' => $i['fiscal_year_end'], 'sic' => $i['sic'], 'transfer_agent' => $i['transfer_agent'], 'transfer_agent_connector' => $i['transfer_agent_connector'],
            'timezone' => $i['timezone'], 'market_holidays' => pg_text_array((string) ($i['market_holidays'] ?? '{}')), 'notes' => $i['notes'], 'restricted' => (bool) $i['restricted'],
            'tags' => pg_text_array((string) ($i['tags'] ?? '{}')), 'edgar_synced_at' => json_ts($i['edgar_synced_at']),
            'edgar_entity' => ['name' => $entity['name'] ?? null, 'sic_description' => $entity['sicDescription'] ?? null, 'category' => isset($entity['category']) ? trim(strip_tags((string) $entity['category'])) : null,
                               'tickers' => $entity['tickers'] ?? [], 'exchanges' => $entity['exchanges'] ?? [], 'addresses' => $entity['addresses'] ?? null, 'former_names' => $entity['formerNames'] ?? []]];
}

function present_security(array $s): array
{
    return ['security_id' => (int) $s['security_id'], 'class' => $s['class'], 'name' => $s['name'], 'cusip' => $s['cusip'], 'isin' => $s['isin'], 'ticker' => $s['ticker'], 'figi' => $s['figi'],
            'par_value' => $s['par_value'], 'is_primary' => (bool) $s['is_primary'], 'listed_on' => $s['listed_on'], 'delisted_on' => $s['delisted_on'], 'active' => (bool) $s['active'],
            'outstanding' => $s['outstanding'] === null ? null : (float) $s['outstanding'], 'last_position_date' => $s['last_position_date']];
}

function present_share_count(array $c): array
{
    return ['share_count_id' => (int) $c['share_count_id'], 'kind' => $c['kind'], 'value' => (float) $c['value'], 'as_of' => $c['as_of'], 'source' => $c['source'],
            'filing_id' => ($c['filing_id'] ?? null) === null ? null : (int) $c['filing_id'], 'xbrl_frame' => $c['xbrl_frame'] ?? null, 'note' => $c['note'] ?? null];
}

function present_note(array $n): array
{
    return ['note_id' => (int) $n['note_id'], 'author' => $n['author'], 'body' => $n['body'], 'created_at' => json_ts($n['created_at'])];
}

/** A whole number with separators; '—' for null. */
function fmt_n($v): string
{
    return $v === null || $v === '' ? '—' : number_format((float) $v, 0);
}
