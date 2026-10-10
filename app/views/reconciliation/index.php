<?php /** The reconciliation (screen `reconciliation`): the latest run as its two equations, each figure with its date and source, the differences; the history; Run now. Data: security, page, notice, tz */
$sid = (int) $security['security_id'];
$r = $page['latest'];
$n = static fn ($v) => $v === null ? '—' : number_format((float) $v, 0);
$state = $r === null ? 'incomplete' : reconciliation_state($r);
$badge = static fn (?bool $t): string => $t === null ? '<span class="badge text-bg-secondary">not enough to compare</span>' : ($t ? '<span class="badge text-bg-success">ties</span>' : '<span class="badge text-bg-danger">does not tie</span>');
?>
<?= view('shared/header.php', ['id' => 'reconciliation', 'title' => 'Reconciliation', 'crumbs' => [['Home', '/'], ['Positions', '/positions?security=' . $sid], ['Reconciliation', null]]]) ?>
<div class="main-content" id="reconciliation-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('positions/partials/subnav.php', ['active' => 'reconciliation', 'security' => $security]) ?>
    <?php if ($r === null): ?><div class="card mb-3"><div class="card-body text-muted" id="recon-none">No reconciliation has run for <?= e($security['name']) ?> yet. It runs after every upload that ties; run it now below.</div></div>
    <?php else: ?>
    <div class="row g-3 mb-3" id="recon-latest">
        <div class="col-xl-6"><div class="card h-100 mb-0" id="recon-registered"><div class="card-header d-flex align-items-center"><h5 class="card-title mb-0">Outstanding = the register</h5><span class="ms-auto"><?= $badge($r['tied_registered']) ?></span></div><div class="card-body fs-12">
            <table class="table table-sm mb-2"><tbody>
                <tr><td>Shares outstanding</td><td class="text-end fw-semibold"><?= e($n($r['outstanding'])) ?></td></tr>
                <tr><td class="ps-3">Cede &amp; Co <span class="text-muted">(<?= e(match ($r['cede_source']) { 'register' => 'the register', 'share_count' => 'a recorded count', default => '—' }) ?>)</span></td><td class="text-end"><?= e($n($r['cede_registered'])) ?></td></tr>
                <tr><td class="ps-3">DRS and book</td><td class="text-end"><?= e($n($r['drs_book'])) ?></td></tr><tr><td class="ps-3">Certificated</td><td class="text-end"><?= e($n($r['certificated'])) ?></td></tr>
                <tr><td class="ps-3">Plan</td><td class="text-end"><?= e($n($r['plan'])) ?></td></tr><tr><td class="ps-3">Other registered</td><td class="text-end"><?= e($n($r['registered_other'])) ?></td></tr>
                <tr class="fw-semibold"><td>Difference</td><td class="text-end <?= $r['tied_registered'] === false ? 'text-danger' : '' ?>" id="recon-difference-registered"><?= e($n($r['difference_registered'])) ?></td></tr>
            </tbody></table>
            <?= view('shared/source-line.php', ['source' => $r['register_date'] !== null ? 'the register of ' . format_date($r['register_date']) : 'no register uploaded yet', 'extra' => 'tolerance ' . number_format((int) $r['tolerance']) . ' shares']) ?>
        </div></div></div>
        <div class="col-xl-6"><div class="card h-100 mb-0" id="recon-spr"><div class="card-header d-flex align-items-center"><h5 class="card-title mb-0">Cede &amp; Co = the SPR total</h5><span class="ms-auto"><?= $badge($r['tied_spr']) ?></span></div><div class="card-body fs-12">
            <table class="table table-sm mb-2"><tbody>
                <tr><td>Cede &amp; Co registered</td><td class="text-end fw-semibold"><?= e($n($r['cede_registered'])) ?></td></tr>
                <tr><td>The SPR's total on <?= $r['spr_date'] ? e(format_date($r['spr_date'])) : '—' ?></td><td class="text-end" id="recon-spr-total"><?= e($n($r['spr_total'])) ?></td></tr>
                <tr class="fw-semibold"><td>Difference</td><td class="text-end <?= $r['tied_spr'] === false ? 'text-danger' : '' ?>" id="recon-difference-spr"><?= e($n($r['difference_spr'])) ?></td></tr>
            </tbody></table>
            <?= view('shared/source-line.php', ['source' => 'the DTC Security Position Report and ' . ($r['cede_source'] === 'register' ? 'the register' : 'the transfer agent\'s Cede count'), 'date' => $r['as_of']]) ?>
            <?php if ($state === 'untied'): ?><div class="alert alert-danger fs-12 mt-2 mb-0" id="recon-defect">A difference is a data defect before it is anything else: check the files' dates and the share count before reading a signal into it.</div><?php endif; ?>
        </div></div></div>
    </div>
    <?php endif; ?>
    <?php if (has_right('uploads.write')): ?>
    <form method="post" action="/reconciliation/run.php" hx-post="/reconciliation/run.php" hx-target="#flash" class="card mb-3" id="recon-run-form"><?= csrf_field() ?><input type="hidden" name="security" value="<?= $sid ?>">
        <div class="card-body row g-2 align-items-end">
            <div class="col-6 col-md-3"><label class="form-label fs-11 text-muted mb-1" for="recon-run-as-of">As of</label><input type="date" name="as_of" id="recon-run-as-of" class="form-control btn-touch fs-12 px-2" value="<?= e(date('Y-m-d')) ?>"></div>
            <div class="col-6 col-md-6"><label class="form-label fs-11 text-muted mb-1" for="recon-run-note">Note</label><input type="text" name="note" id="recon-run-note" class="form-control btn-touch" maxlength="500"></div>
            <div class="col-12 col-md-3"><button type="submit" class="btn btn-primary btn-touch w-100" id="recon-run">Run it now</button></div>
        </div>
    </form>
    <?php endif; ?>
    <?php if ($page['history'] !== []): ?>
    <div class="card" id="recon-history"><div class="card-header"><h5 class="card-title mb-0">Every run</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12" id="recon-history-table">
        <thead class="thead-light"><tr><th>As of</th><th>State</th><th class="text-end d-none d-md-table-cell">Register off by</th><th class="text-end">SPR off by</th><th class="d-none d-md-table-cell">Ran</th></tr></thead><tbody>
        <?php foreach ($page['history'] as $h): $s = reconciliation_state($h); ?><tr><td class="text-nowrap"><?= e(format_date($h['as_of'])) ?></td><td><span class="badge text-bg-<?= $s === 'tied' ? 'success' : ($s === 'untied' ? 'danger' : 'secondary') ?>"><?= e($s === 'incomplete' ? 'not enough to compare' : $s) ?></span></td>
            <td class="text-end d-none d-md-table-cell"><?= e($n($h['difference_registered'])) ?></td><td class="text-end"><?= e($n($h['difference_spr'])) ?></td><td class="d-none d-md-table-cell text-muted"><?= e(format_ts($h['ran_at'], $tz, 'M j, g:i A')) ?><?= $h['note'] ? ' · ' . e($h['note']) : '' ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div></div>
    <?php endif; ?>
</div>
