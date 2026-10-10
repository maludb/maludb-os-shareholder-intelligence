<?php /** The register on a record date (screen `holders-register`). Data: security, dates, date, reg, types, rec, here */
$c = $reg['components'];
$typeWords = static fn (string $t): string => match ($t) { 'CERT', 'PHY' => 'Certificates', 'BOOK', 'BK' => 'Book entry', 'DRS' => 'DRS', 'BOOKR' => 'Restricted book (the insiders\' shares)', 'PLAN1', 'PLAN2', 'PLAN3', 'PLAN4' => 'Plan (' . $t . ')', default => $t };
?>
<?= view('shared/header.php', ['id' => 'holders-register', 'title' => 'The register', 'crumbs' => [['Home', '/'], ['Holders', '/holders'], ['Register', null]]]) ?>
<div class="main-content" id="holders-register-content">
    <?= view('holders/partials/subnav.php', ['active' => 'register', 'security' => $security, 'dates' => $dates, 'date' => $date]) ?>
    <?php if ($reg['import'] === null): ?><div class="card"><div class="card-body text-muted" id="register-empty">No register is loaded for this security: <?= hx_link('/uploads/new?kind=register', 'upload the transfer agent\'s extract', 'fw-semibold') ?>.</div></div>
    <?php else: ?>
    <div class="row g-3">
        <div class="col-xl-5"><div class="card mb-0 h-100" id="register-components"><div class="card-header"><h5 class="card-title mb-0">What the reconciliation reads</h5></div><div class="card-body p-0">
            <div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="register-table"><tbody>
                <tr class="table-light"><td>Cede &amp; Co — everything at DTC</td><td class="text-end fw-semibold" id="register-cede"><?= e(fmt_n($c['cede'])) ?></td></tr>
                <?php foreach ($c['by_type'] as $t => $v): ?><tr><td><?= e($typeWords((string) $t)) ?></td><td class="text-end"><?= e(fmt_n($v)) ?></td></tr><?php endforeach; ?>
                <tr><td class="fw-semibold">On the register</td><td class="text-end fw-semibold"><?= e(fmt_n((float) ($c['cede'] ?? 0) + array_sum(array_map('floatval', $c['by_type'])))) ?></td></tr>
            </tbody></table></div>
            <div class="p-3 fs-12"><?php if ($rec !== null): ?><?= hx_link('/reconciliation?security=' . (int) $security['security_id'], 'The reconciliation of ' . e(format_date($rec['as_of'])), 'fw-semibold') ?>: <?= reconciliation_state($rec) === 'tied' ? '<span class="text-success">ties</span>' : (reconciliation_state($rec) === 'untied' ? '<span class="text-danger">does not tie</span>' : 'not enough to compare') ?><?php else: ?>No reconciliation has read this register yet.<?php endif; ?>
                <?= view('shared/source-line.php', ['source' => 'the transfer agent\'s register uploaded (' . hx_link('/uploads/' . (int) $reg['import']['import_id'], 'upload ' . (int) $reg['import']['import_id'], '') . ')', 'date' => $date]) ?></div>
        </div></div></div>
        <div class="col-xl-7"><div class="card mb-0 h-100" id="register-rows"><div class="card-header"><h5 class="card-title mb-0">The rows</h5></div><div class="card-body p-0">
            <?php if (!si_sees_pii()): ?><div class="p-3 fs-12 text-muted" id="register-rows-withheld"><i class="bi bi-shield-lock me-1"></i>The register's rows name natural persons: they are an Analyst's. The holders by position type:
                <ul class="mb-0 mt-2"><?php foreach ($types as $t): ?><li><?= e(ucfirst((string) $t['account_kind'])) ?>: <?= number_format((int) $t['holders']) ?> holders, <?= e(fmt_n($t['shares'])) ?> shares</li><?php endforeach; ?></ul></div>
            <?php else: ?><div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="register-rows-table"><thead class="thead-light"><tr><th>Registration</th><th class="d-none d-sm-table-cell">Type</th><th class="text-end">Shares</th></tr></thead><tbody>
                <?php foreach ($reg['rows'] as $r): ?><tr class="<?= $r['is_cede'] ? 'table-info' : ($r['error'] ? 'table-danger' : '') ?>"><td class="text-wrap"><?= $r['holder_id'] ? hx_link(with_back('/holders/' . (int) $r['holder_id'], $here), e(strtok((string) $r['registration_name'], "\n") ?: '—'), '') : e(strtok((string) $r['registration_name'], "\n") ?: '—') ?>
                    <?= $r['is_cede'] ? ' <span class="badge text-bg-info">Cede</span>' : '' ?><div class="d-sm-none fs-11 text-muted"><?= e((string) $r['position_type']) ?></div></td><td class="d-none d-sm-table-cell"><?= e((string) $r['position_type']) ?></td><td class="text-end"><?= e(fmt_n($r['shares'])) ?></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </div></div></div>
    </div>
    <?php endif; ?>
</div>
