<?php /** The movement matrix (screen `positions-matrix`): participants × position dates, each cell its shares coloured by the day's change; on a phone one date at a time. Data: security, m, from, to, focus, classes, class, rows, here */
$sid = (int) $security['security_id'];
$max = 1;
foreach ($m['rows'] as $r) { foreach ($r['cells'] as $c) { if ($c['change'] !== null) { $max = max($max, abs((float) $c['change'])); } } }
$bg = static function ($change) use ($max): string {
    if ($change === null || (float) $change == 0) { return ''; }
    $a = 0.15 + 0.6 * min(1, abs((float) $change) / $max);
    return ' style="background: rgba(' . ((float) $change > 0 ? '22,163,74' : '220,38,38') . ',' . sprintf('%.2f', $a) . ')"';
};
$n = static fn ($v) => $v === null ? '—' : number_format((float) $v, 0);
?>
<?= view('shared/header.php', ['id' => 'positions-matrix', 'title' => 'Movement', 'crumbs' => [['Home', '/'], ['Positions', '/positions?security=' . $sid], ['Movement', null]]]) ?>
<div class="main-content" id="positions-matrix-content">
    <?= view('positions/partials/subnav.php', ['active' => 'matrix', 'security' => $security, 'from' => $from, 'to' => $to, 'extra' => array_filter(['class' => $class])]) ?>
    <div class="d-flex flex-wrap gap-1 mb-3" id="matrix-classes">
        <?= hx_link('/positions/matrix?' . http_build_query(['security' => $sid, 'from' => $from, 'to' => $to]), 'Every class', 'btn btn-sm btn-touch ' . ($class === null ? 'btn-primary' : 'btn-light'), 'id="matrix-class-all"') ?>
        <?php foreach ($classes as $k => [$name]): ?><?= hx_link('/positions/matrix?' . http_build_query(['security' => $sid, 'from' => $from, 'to' => $to, 'class' => $k]), e($name), 'btn btn-sm btn-touch ' . ($class === $k ? 'btn-primary' : 'btn-light'), 'id="matrix-class-' . e($k) . '"') ?><?php endforeach; ?>
    </div>
    <?php if ($m['dates'] === []): ?><div class="card"><div class="card-body text-muted" id="matrix-empty">No position date in this window.</div></div>
    <?php else: ?>
    <div class="card d-md-none" id="matrix-phone"><div class="card-body">
        <form method="get" action="/positions/matrix" class="mb-3" hx-get="/positions/matrix" hx-target="#page-content" hx-push-url="true" id="matrix-focus-form">
            <input type="hidden" name="security" value="<?= $sid ?>"><input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>"><?php if ($class): ?><input type="hidden" name="class" value="<?= e($class) ?>"><?php endif; ?>
            <label class="form-label fs-11 text-muted mb-1" for="matrix-focus">Date</label>
            <select name="focus" id="matrix-focus" class="form-select btn-touch" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()"><?php foreach (array_reverse($m['dates']) as $d): ?><option value="<?= e($d) ?>" <?= $d === $focus ? 'selected' : '' ?>><?= e(date('D M j, Y', strtotime($d))) ?></option><?php endforeach; ?></select>
        </form>
        <table class="table table-sm mb-0 fs-12" id="matrix-phone-table"><thead><tr><th>Participant</th><th class="text-end">Shares</th><th class="text-end">Change</th></tr></thead><tbody>
            <?php foreach ($m['rows'] as $r): $c = $r['cells'][$focus] ?? null; ?><tr><td class="text-wrap"><?= hx_link(with_back('/participants/' . (int) $r['participant_id'] . '?security=' . $sid, $here), e($r['number'] . ' ' . $r['name']), 'text-dark') ?></td>
                <td class="text-end <?= $c === null ? 'si-absent' : '' ?>"><?= $c === null ? '<span class="text-muted">absent</span>' : e($n($c['shares'])) ?></td><td class="text-end"<?= $bg($c['change'] ?? null) ?>><?= $c === null || $c['change'] === null ? '—' : e(((float) $c['change'] > 0 ? '+' : '') . $n($c['change'])) ?></td></tr><?php endforeach; ?>
        </tbody></table>
    </div></div>
    <div class="card d-none d-md-block" id="matrix-card"><div class="card-body p-0"><div class="table-responsive si-matrix-wrap"><table class="table table-sm mb-0 fs-11 si-matrix" id="matrix">
        <thead class="thead-light"><tr><th class="si-matrix-head">Participant</th><th class="text-end">Net</th><?php foreach ($m['dates'] as $d): ?><th class="text-end text-nowrap"><?= e(date('M j', strtotime($d))) ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach ($m['rows'] as $r): $pid = (int) $r['participant_id']; ?><tr id="matrix-row-<?= $pid ?>"><td class="si-matrix-head text-nowrap"><?= hx_link(with_back('/participants/' . $pid . '?security=' . $sid, $here), e($r['number'] . ' ' . mb_substr($r['name'], 0, 22)), 'text-dark') ?></td>
            <td class="text-end fw-semibold <?= (float) $r['net'] > 0 ? 'text-success' : ((float) $r['net'] < 0 ? 'text-danger' : '') ?>"><?= e(((float) $r['net'] > 0 ? '+' : '') . $n($r['net'])) ?></td>
            <?php foreach ($m['dates'] as $d): $c = $r['cells'][$d] ?? null; ?><td class="text-end <?= $c === null ? 'si-absent' : '' ?>" id="matrix-cell-<?= $pid ?>-<?= e($d) ?>"<?= $bg($c['change'] ?? null) ?> title="<?= e($c === null ? 'absent' : number_format((float) $c['shares']) . ($c['change'] !== null ? ' (' . ((float) $c['change'] > 0 ? '+' : '') . number_format((float) $c['change']) . ')' : '')) ?>"><?= $c === null ? '' : e($n($c['shares'])) ?></td><?php endforeach; ?>
        </tr><?php endforeach; ?>
    </tbody></table></div></div></div>
    <?php if ($m['more']): ?><div class="mt-2"><?= hx_link('/positions/matrix?' . http_build_query(array_filter(['security' => $sid, 'from' => $from, 'to' => $to, 'class' => $class, 'rows' => $rows + 40])), 'Show more participants', 'btn btn-light btn-touch w-100', 'id="matrix-more"') ?></div><?php endif; ?>
    <div class="mt-2"><?= view('shared/source-line.php', ['source' => 'the DTC Security Position Reports', 'date' => end($m['dates']), 'extra' => 'green a rise, red a fall, hatched absent; sorted by the window\'s net']) ?></div>
    <?php endif; ?>
</div>
