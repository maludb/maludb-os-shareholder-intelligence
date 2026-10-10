<?php
/**
 * The upload page's middle for a NOBO list, a register or a Share Range Analysis (slice 3): what the file says of itself against its rows,
 * the matcher's counts with the way to the queue, the Bank/Broker listing, the rows that failed to type (the PII roles only — a Viewer is
 * told the count). Data: u, facts, totals
 */
$n = static fn ($v) => $v === null || $v === '' ? '—' : number_format((float) $v, fmod((float) $v, 1.0) === 0.0 ? 0 : 5);
$c = $totals['control'];
$d = $totals['derived'];
$m = $d['matcher'] ?? null;
$pii = si_sees_pii();
$id = (int) $u['import_id'];
$rd = (string) $u['record_date'];
$facts = $facts ?? ['job' => null, 'clients' => [], 'failed' => [], 'waiting' => 0, 'ranges' => []];
$cmp = static function (string $label, $file, $rows) use ($n): string {
    if ($file === null) {
        return '';
    }
    $ok = (string) $file === (string) $rows || (float) $file === (float) $rows;
    return '<tr><td>' . e($label) . '</td><td class="text-end">' . e($n($file)) . '</td><td class="text-end">' . e($n($rows)) . '</td><td class="text-end ' . ($ok ? 'text-success' : 'text-danger fw-semibold') . '">'
        . ($ok ? '<i class="feather-check"></i> 0' : e(number_format((float) $rows - (float) $file))) . '</td></tr>';
};
?>
<div class="card mb-3" id="upload-totals"><div class="card-header"><h5 class="card-title mb-0">What the file says of itself, against its rows</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12" id="upload-totals-table">
    <thead class="thead-light"><tr><th>The file's statement</th><th class="text-end">The file</th><th class="text-end">The rows</th><th class="text-end">Difference</th></tr></thead><tbody>
    <?php if ($u['kind'] === 'nobo'): ?>
        <?= $cmp('NOBOs in the header', $c['nobo_count'] ?? null, $d['nobo_count'] ?? null) ?>
        <?= $cmp('Shares in the summary', $c['summary_shares'] ?? null, $d['shares'] ?? null) ?>
        <?= $cmp('The section\'s total', $c['section_total'] ?? null, $d['shares'] ?? null) ?>
        <?= $cmp('Shares in the Bank/Broker listing', $c['clients_shares'] ?? null, $d['shares'] ?? null) ?>
        <?= $cmp('Shares in the footer\'s total', $c['footer_shares'] ?? null, $d['shares'] ?? null) ?>
    <?php elseif ($u['kind'] === 'register'): ?>
        <?= $cmp('TOTAL SHARES over the holders', $c['total_shares'] ?? null, $d['shares'] ?? null) ?>
        <?php if (($c['layout'] ?? '') === 'stc_position_detail'): ?><tr><td>Positions</td><td class="text-end">—</td><td class="text-end"><?= e($n($d['rows'] ?? null)) ?></td><td class="text-end text-muted">no total in the file</td></tr><?php endif; ?>
    <?php else: ?>
        <?= $cmp('Accounts, every holder (TOTALS)', $c['all']['accounts'] ?? null, $d['all']['accounts'] ?? null) ?>
        <?= $cmp('Shares, every holder (TOTALS)', $c['all']['shares'] ?? null, $d['all']['shares'] ?? null) ?>
        <?= $cmp('Accounts, the OBOs (TOTALS)', $c['obo']['accounts'] ?? null, $d['obo']['accounts'] ?? null) ?>
        <?= $cmp('Shares, the OBOs (TOTALS)', $c['obo']['shares'] ?? null, $d['obo']['shares'] ?? null) ?>
    <?php endif; ?>
    </tbody></table></div>
    <div class="p-3 fs-12 text-muted" id="upload-control-words"><?= e(match (true) {
        ($c['layout'] ?? '') === 'stc_position_detail' => 'STC\'s position detail carries no total: it is tied to its own rows.',
        ($c['layout'] ?? '') === 'names' => (string) ($c['no_clients'] ?? ''),
        $u['kind'] === 'share_range' => 'No person is in a Share Range Analysis: a Viewer reads it whole.',
        default => 'Every statement the file makes of itself is checked against its rows; a difference keeps the upload out of use until an IR Manager accepts it.' }) ?>
        <?= ($c['fractional_rows'] ?? 0) > 0 ? '<div class="mt-1">' . (int) $c['fractional_rows'] . ' row(s) hold a fraction of a share: tied exactly, stored to the whole share.</div>' : '' ?>
        <?= $u['tie_note'] ? '<div class="text-danger mt-1" id="upload-tie-note">' . e((string) $u['tie_note']) . '</div>' : '' ?></div></div></div>

<?php if ($u['kind'] !== 'share_range'): ?>
<div class="card mb-3" id="upload-matcher"><div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap"><h5 class="card-title mb-0">The holders</h5>
    <?php if ($pii && $facts['waiting'] > 0): ?><?= hx_link('/holders/unmatched?upload=' . $id, '<i class="feather-git-pull-request me-1"></i>' . number_format($facts['waiting']) . ' waiting for an Analyst', 'btn btn-warning btn-touch', 'id="upload-queue-link"') ?><?php endif; ?></div>
    <div class="card-body">
    <?php if ($m !== null && ($m['queued'] ?? false)): ?><p class="fs-12 text-muted mb-0" id="upload-matcher-queued" hx-get="/uploads/<?= $id ?>/status?part=matcher" hx-trigger="every 3s" hx-swap="outerHTML"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Matching <?= number_format((int) ($m['waiting'] ?? 0)) ?> rows to holders — a long list is matched in the background; this page updates when it is done.</p>
    <?php elseif ($m === null): ?><p class="fs-12 text-muted mb-0" id="upload-matcher-none"><?= $u['tied'] === false && $u['override_at'] === null ? 'The rows are matched to holders once the upload ties or an IR Manager accepts it.' : 'Not matched yet.' ?></p>
    <?php else: ?>
        <div class="row g-2 text-center" id="upload-matcher-counts">
            <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="fs-4 fw-bold"><?= number_format((int) $m['auto']) ?></div><div class="fs-11 text-muted">matched by the rules</div></div></div>
            <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="fs-4 fw-bold"><?= number_format((int) $m['new']) ?></div><div class="fs-11 text-muted">new holders</div></div></div>
            <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="fs-4 fw-bold"><?= number_format((int) $m['proposed']) ?></div><div class="fs-11 text-muted">proposed to an Analyst</div></div></div>
            <div class="col-6 col-md-3"><div class="border rounded p-2"><div class="fs-4 fw-bold"><?= number_format((int) $m['failed']) ?></div><div class="fs-11 text-muted">could not be read</div></div></div>
        </div>
        <p class="fs-12 text-muted mt-2 mb-0">A match at <?= e((string) (one_value(db(), 'SELECT auto_match_threshold FROM mcp_settings') ?: '0.95')) ?> or above is taken and logged; below, an Analyst decides — a rejection is remembered.</p>
    <?php endif; ?>
    <?php if ($u['kind'] === 'register' && isset($d['by_type'])): ?>
        <div class="table-responsive mt-3"><table class="table table-sm mb-0 fs-12" id="upload-register-types"><thead class="thead-light"><tr><th>On the register</th><th class="text-end">Shares</th></tr></thead><tbody>
            <tr><td>Cede &amp; Co (everything at DTC)</td><td class="text-end fw-semibold"><?= e($n($d['cede'] ?? 0)) ?></td></tr>
            <?php foreach ($d['by_type'] as $t => $v): ?><tr><td><?= e(match ($t) { 'CERT', 'PHY' => 'Certificates', 'BOOK', 'BK' => 'Book entry', 'DRS' => 'DRS', 'BOOKR' => 'Restricted book', 'PLAN1', 'PLAN2', 'PLAN3', 'PLAN4' => 'Plan (' . $t . ')', default => $t }) ?></td><td class="text-end"><?= e($n($v)) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
    </div></div>
<?php endif; ?>

<?php if ($facts['clients'] !== []): ?>
<div class="card mb-3" id="upload-clients"><div class="card-header"><h5 class="card-title mb-0">The Bank/Broker listing</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="upload-clients-table">
    <thead class="thead-light"><tr><th>Broker</th><th class="text-end">NOBOs</th><th class="text-end">Shares</th></tr></thead><tbody>
    <?php foreach ($facts['clients'] as $b): ?><tr><td class="text-wrap"><?= $b['participant_id'] ? hx_link('/participants/' . (int) $b['participant_id'], e($b['number'] . ' ' . $b['client']), '') : e($b['client']) . ' <span class="badge text-bg-light">not in the directory</span>' ?></td>
        <td class="text-end"><?= e($n($b['nobo_count'])) ?></td><td class="text-end"><?= e($n($b['shares'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <div class="p-3 fs-12 text-muted">A broker not in the directory is never guessed: give its name as an alias of its participant and read the list again. What each participant's position on the SPR holds beyond these NOBO shares is its OBO remainder: <?= hx_link('/holders/custodians?record_date=' . e($rd), 'the custodians', '') ?>.</div></div></div>
<?php endif; ?>

<?php if ($facts['ranges'] !== []): $by = []; foreach ($facts['ranges'] as $r) { $by[(int) $r['range_from']][$r['population']] = $r; $by[(int) $r['range_from']]['label'] = $r['range_label']; } ?>
<div class="card mb-3" id="upload-ranges"><div class="card-header"><h5 class="card-title mb-0">The ranges as read</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="upload-ranges-table">
    <thead class="thead-light"><tr><th>Shares</th><th class="text-end">Accounts</th><th class="text-end">OBO accounts</th><th class="text-end d-none d-sm-table-cell">Shares</th><th class="text-end d-none d-sm-table-cell">OBO shares</th></tr></thead><tbody>
    <?php foreach ($by as $r): ?><tr><td class="text-nowrap"><?= e((string) $r['label']) ?></td><td class="text-end"><?= e($n($r['all']['accounts'] ?? 0)) ?></td><td class="text-end"><?= e($n($r['obo']['accounts'] ?? 0)) ?></td>
        <td class="text-end d-none d-sm-table-cell"><?= e($n($r['all']['shares'] ?? 0)) ?></td><td class="text-end d-none d-sm-table-cell"><?= e($n($r['obo']['shares'] ?? 0)) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div></div>
<?php endif; ?>

<?php if ($facts['failed'] !== []): ?>
<div class="card mb-3" id="upload-failed-rows"><div class="card-header"><h5 class="card-title mb-0">Rows that failed to type</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 fs-12">
    <thead class="thead-light"><tr><th>Row</th><th>As read</th><th class="text-end">Shares</th></tr></thead><tbody>
    <?php foreach ($facts['failed'] as $r): ?><tr class="table-danger"><td><?= (int) $r['row_no'] ?></td><td class="text-wrap"><?= e(trim(implode(' · ', array_filter([(string) $r['line1'], (string) $r['line2']])))) ?><div class="text-danger"><?= e((string) $r['error']) ?></div></td><td class="text-end"><?= e($n($r['shares'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div></div>
<?php elseif (!$pii && (int) $u['rows_failed'] > 0): ?>
<div class="alert alert-light fs-12" id="upload-failed-count"><i class="bi bi-shield-lock me-1"></i><?= (int) $u['rows_failed'] ?> row(s) failed to type; the registrations are an Analyst's to read.</div>
<?php endif; ?>
