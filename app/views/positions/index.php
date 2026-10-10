<?php /** The position table (screen `positions`): the participants ranked on a date with their class, share of the DTC total, the change against the previous date and the window's net; the total tied to the reconciliation. Data: security, dates, date, prev, from, p, classes, class, q, rec, here */
$n = static fn ($v) => $v === null ? '—' : number_format((float) $v, 0);
$chg = static function ($v): string { if ($v === null) { return '<span class="text-muted">—</span>'; } $v = (float) $v; return $v == 0 ? '<span class="text-muted">0</span>' : '<span class="' . ($v > 0 ? 'text-success' : 'text-danger') . '">' . ($v > 0 ? '+' : '') . number_format($v) . '</span>'; };
$sid = (int) $security['security_id'];
$recState = $rec !== null ? reconciliation_state($rec) : 'incomplete';
?>
<?= view('shared/header.php', ['id' => 'positions', 'title' => 'Positions at DTC', 'crumbs' => [['Home', '/'], ['Positions', null]]]) ?>
<div class="main-content" id="positions-content">
    <?= view('positions/partials/subnav.php', ['active' => 'positions', 'security' => $security]) ?>
    <?php if ($dates === []): ?>
        <div class="card"><div class="card-body text-muted" id="positions-empty">No position yet for <?= e($security['name']) ?>. <?= has_right('uploads.write') ? hx_link('/uploads/new?security=' . $sid, 'Upload the weekly DTC position report', 'fw-semibold') . '.' : '' ?></div></div>
    <?php else: ?>
    <form method="get" action="/positions" class="row g-2 align-items-end mb-3" hx-get="/positions" hx-target="#page-content" hx-push-url="true" id="positions-filters">
        <input type="hidden" name="security" value="<?= $sid ?>">
        <div class="col-6 col-md-3"><label class="form-label fs-11 text-muted mb-1" for="positions-date">Position date</label><select name="date" id="positions-date" class="form-select btn-touch fs-12">
            <?php foreach (array_reverse($dates) as $d): ?><option value="<?= e($d) ?>" <?= $d === $date ? 'selected' : '' ?>><?= e(date('D M j, Y', strtotime($d))) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label fs-11 text-muted mb-1" for="positions-class">Class</label><select name="class" id="positions-class" class="form-select btn-touch fs-12"><option value="">Every class</option>
            <?php foreach ($classes as $k => [$name]): ?><option value="<?= e($k) ?>" <?= $class === $k ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select></div>
        <div class="col-9 col-md-4"><label class="form-label fs-11 text-muted mb-1" for="positions-q">Participant</label><input type="search" name="q" id="positions-q" value="<?= e((string) $q) ?>" class="form-control btn-touch fs-12" placeholder="A number or a name"></div>
        <div class="col-3 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="positions-filter-btn">Show</button></div>
    </form>
    <div class="card" id="positions-card"><div class="card-header d-flex align-items-center flex-wrap gap-2"><h5 class="card-title mb-0"><?= e(date('l, F j, Y', strtotime($date))) ?></h5>
        <span class="ms-auto fs-12">Total <span class="fw-bold" id="positions-total"><?= e($n($p['total'])) ?></span>
        <?php if ($recState === 'tied' && $rec['spr_date'] === $date): ?> <span class="badge text-bg-success" id="positions-tie">ties to Cede &amp; Co</span><?php elseif ($recState === 'untied' && $rec['spr_date'] === $date): ?> <span class="badge text-bg-danger" id="positions-tie">does not tie to Cede &amp; Co</span><?php endif; ?></span></div>
        <div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12" id="positions-table">
            <thead class="thead-light"><tr><th class="text-end">#</th><th>Participant</th><th class="text-end">Shares<span class="d-sm-none fw-normal"> · change</span></th><th class="text-end d-none d-sm-table-cell">% of DTC</th><th class="text-end d-none d-sm-table-cell">Since <?= $prev ? e(date('M j', strtotime($prev))) : '—' ?></th><th class="text-end d-none d-md-table-cell">Since <?= e(date('M j', strtotime($from))) ?></th></tr></thead><tbody>
            <?php foreach ($p['rows'] as $k => $r): $pid = (int) $r['participant_id']; ?><tr id="position-<?= $pid ?>">
                <td class="text-end text-muted"><?= $k + 1 ?></td>
                <td class="text-wrap"><?= hx_link(with_back('/participants/' . $pid . '?security=' . $sid, $here), e($r['number'] . ' ' . $r['name']), 'text-dark') ?> <?= class_chip(db(), (string) $r['class_key']) ?><?= $r['is_new'] ? ' <span class="badge bg-soft-primary text-primary">new</span>' : '' ?></td>
                <td class="text-end fw-semibold"><?= e($n($r['shares'])) ?><div class="d-sm-none fw-normal fs-11"><?= $r['is_new'] ? '<span class="text-success">new</span>' : $chg($r['change_prev']) ?></div></td><td class="text-end d-none d-sm-table-cell"><?= e(number_format((float) $r['pct'], 2)) ?>%</td>
                <td class="text-end d-none d-sm-table-cell"><?= $r['is_new'] ? '<span class="text-success">new</span>' : $chg($r['change_prev']) ?></td><td class="text-end d-none d-md-table-cell"><?= $chg($r['net_window']) ?></td>
            </tr><?php endforeach; ?>
            <?php foreach ($p['gone'] as $g): ?><tr class="text-muted"><td></td><td class="text-wrap"><?= e($g['number'] . ' ' . $g['name']) ?> <span class="badge bg-soft-danger text-danger">gone</span></td><td class="text-end">0<div class="d-sm-none text-danger fs-11">−<?= e($n($g['shares'])) ?></div></td><td class="d-none d-sm-table-cell"></td><td class="text-end text-danger d-none d-sm-table-cell">−<?= e($n($g['shares'])) ?></td><td class="d-none d-md-table-cell"></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <div class="p-3"><?= view('shared/source-line.php', ['source' => 'the DTC Security Position Report', 'date' => $date, 'extra' => 'a participant absent from a date is absent, not zero']) ?></div></div></div>
    <?php endif; ?>
</div>
