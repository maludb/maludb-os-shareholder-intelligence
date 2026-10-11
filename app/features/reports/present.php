<?php
declare(strict_types=1);

/** The packs' whitelist presenters and chips. A pack's paths in the store are never presented: a file is fetched through /reports/{id}/file after the walls. */
const REPORT_STATUS_CHIPS = ['draft' => 'text-bg-warning', 'approved' => 'text-bg-success', 'published' => 'text-bg-primary'];

function report_status_chip(string $status, string $extra = ''): string
{
    return '<span class="badge ' . (REPORT_STATUS_CHIPS[$status] ?? 'text-bg-secondary') . '" data-status="' . e($status) . '"' . $extra . '>' . e(REPORT_STATUSES[$status] ?? $status) . '</span>';
}

function present_report(array $r, bool $withNarrative = true): array
{
    $out = ['report_id' => (int) $r['report_id'], 'issuer_id' => (int) $r['issuer_id'], 'kind' => $r['kind'], 'title' => $r['title'], 'period_from' => $r['period_from'], 'period_to' => $r['period_to'],
            'status' => $r['status'], 'sections' => report_sections_list($r['sections']), 'drafted_by' => $r['drafted_by'] === null ? null : (int) $r['drafted_by'], 'drafted_by_name' => $r['drafted_by_name'] ?? null,
            'approved_by' => $r['approved_by'] === null ? null : (int) $r['approved_by'], 'approved_by_name' => $r['approved_by_name'] ?? null, 'approved_at' => json_ts($r['approved_at']),
            'rendered' => (bool) $r['rendered'], 'has_pdf' => (bool) $r['has_pdf'], 'created_at' => json_ts($r['created_at']), 'updated_at' => json_ts($r['updated_at'])];
    return $withNarrative ? $out + ['narrative' => $r['narrative']] : $out;
}

function present_export(array $x): array
{
    return ['export_id' => (int) $x['export_id'], 'kind' => $x['kind'], 'format' => $x['format'], 'status' => $x['status'], 'item_count' => (int) $x['item_count'], 'byte_size' => $x['byte_size'] === null ? null : (int) $x['byte_size'],
            'includes_pii' => (bool) $x['includes_pii'], 'issuer_id' => $x['issuer_id'] === null ? null : (int) $x['issuer_id'], 'security_id' => $x['security_id'] === null ? null : (int) $x['security_id'],
            'created_by' => $x['created_by'] === null ? null : (int) $x['created_by'], 'created_at' => json_ts($x['created_at']), 'finished_at' => json_ts($x['finished_at']), 'expires_at' => json_ts($x['expires_at']),
            'downloaded_at' => json_ts($x['downloaded_at'] ?? null)];
}
