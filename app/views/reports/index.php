<?php /** The packs as cards (screen `report-list`). Data: issuer, f, rows, may, here, notice */
$sel = static fn (string $name, array $opts, ?string $val, string $label): string => '<div class="col-6 col-md"><label class="form-label fs-11 text-muted mb-1" for="reports-filter-' . $name . '">' . e($label) . '</label><select name="' . $name . '" id="reports-filter-' . $name . '" class="form-select form-select-sm">'
    . implode('', array_map(static fn ($k, $v) => '<option value="' . e((string) $k) . '"' . ((string) $k === (string) $val ? ' selected' : '') . '>' . e($v) . '</option>', array_keys($opts), $opts)) . '</select></div>';
$action = hx_link('/exports', '<i class="feather-download me-1"></i>Exports', 'btn btn-light btn-touch btn-sm me-2', 'id="reports-exports-link"')
    . ($may ? hx_link(with_back('/reports/new', $here), '<i class="feather-plus me-1"></i>Draft a pack', 'btn btn-primary btn-touch btn-sm', 'id="reports-add-link"') : '');
?>
<?= view('shared/header.php', ['id' => 'report-list', 'title' => 'Reports', 'crumbs' => [['Home', '/'], ['Reports', null]], 'action' => $action]) ?>
<div class="main-content" id="report-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/reports" hx-get="/reports" hx-target="#page-content" hx-push-url="true" class="card mb-3" id="reports-filters"><div class="card-body py-2"><div class="row g-2 align-items-end">
        <?= $sel('kind', ['' => 'Any kind'] + REPORT_KINDS, $f['kind'], 'Kind') ?>
        <?= $sel('status', ['' => 'Any'] + REPORT_STATUSES, $f['status'], 'Status') ?>
        <div class="col-12 col-md-auto"><button type="submit" class="btn btn-light btn-sm btn-touch w-100" id="reports-filter-btn">Show</button></div>
    </div></div></form>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body text-muted" id="reports-empty"><?= $f['kind'] === null && $f['status'] === null ? 'No pack has been drafted for ' . e($issuer['name']) . '. The Surveillance Analyst drafts the weekly pack; ' . ($may ? 'or draft one by hand.' : 'an Analyst can also draft one.') : 'No pack matches these filters.' ?></div></div>
    <?php else: ?>
        <div class="row g-3" id="reports-cards"><?php foreach ($rows as $r): $rid = (int) $r['report_id']; ?>
            <div class="col-md-6 col-xl-4"><div class="card h-100" id="report-card-<?= $rid ?>" data-status="<?= e((string) $r['status']) ?>"><div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-2"><span class="badge bg-soft-primary text-primary"><?= e(REPORT_KINDS[$r['kind']] ?? (string) $r['kind']) ?></span><?= report_status_chip((string) $r['status']) ?><?= $r['has_pdf'] ? '<span class="badge bg-soft-secondary text-secondary">PDF</span>' : '' ?></div>
                <h6 class="mb-1"><?= hx_link(with_back('/reports/' . $rid, $here), e((string) $r['title']), 'text-dark fw-semibold', 'id="report-link-' . $rid . '"') ?></h6>
                <div class="fs-12 text-muted"><?= e(format_date((string) $r['period_from'])) ?> to <?= e(format_date((string) $r['period_to'])) ?></div>
                <div class="fs-11 text-muted mt-2"><?= $r['approved_by_name'] ? 'Approved by ' . e((string) $r['approved_by_name']) : 'Drafted by ' . e((string) ($r['drafted_by_name'] ?? 'someone')) . ($r['drafted_by_kind'] === 'agent' ? ' (agent)' : '') ?></div>
            </div></div></div>
        <?php endforeach; ?></div>
    <?php endif; ?>
    <p class="fs-11 text-muted mt-3" id="reports-footnote">A pack is the numbers on record for a period, in the order of the board's questions. The narrative is the only text a person or the Analyst writes; the tables are drawn from the same data as the screens. A pack goes to members only.</p>
</div>
