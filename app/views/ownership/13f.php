<?php /** A quarter's 13F holders (screen `ownership-13f`). Data: security, t, here */
$sid = (int) $security['security_id'];
$s = $t['summary'];
$money = static fn ($v) => $v === null ? '—' : '$' . number_format((float) $v, 0);
?>
<?= view('shared/header.php', ['id' => 'ownership-13f', 'title' => '13F holders', 'crumbs' => [['Home', '/'], ['Ownership', null]]]) ?>
<div class="main-content" id="ownership-13f-content">
    <?= ownership_tabs('13f', $sid) ?>
    <?php if ($t['period'] === null): ?><div class="card"><div class="card-body text-muted" id="thirteenf-empty">No 13F holding of <?= e($security['name']) ?> is loaded: the SEC's quarterly data sets are read by the worker (every tracked CUSIP's rows).</div></div>
    <?php else: ?>
    <?= period_form('/ownership/13f', $sid, $t['periods'], $t['period']) ?>
    <?php if ($s !== null): ?><div class="row g-2 mb-3" id="thirteenf-summary">
        <?php foreach ([['Holders', number_format((int) $s['holders'])], ['Shares', fmt_n($s['shares'])], ['Of outstanding', $s['pct_of_outstanding'] === null ? '—' : number_format((float) $s['pct_of_outstanding'], 2) . '%'], ['Top 10 of 13F', $s['top10_pct'] === null ? '—' : number_format((float) $s['top10_pct'], 1) . '%']] as [$l, $v]): ?>
        <div class="col-6 col-md-3"><div class="card mb-0 h-100"><div class="card-body p-2 text-center"><div class="fs-11 text-muted"><?= e($l) ?></div><div class="fs-5 fw-bold"><?= e($v) ?></div></div></div></div><?php endforeach; ?></div><?php endif; ?>
    <div class="card mb-3"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="thirteenf-table">
        <thead class="thead-light"><tr><th>#</th><th>Holder</th><th class="text-end">Shares</th><th class="text-end d-none d-md-table-cell">Value</th><th class="text-end d-none d-lg-table-cell">Voting sole / shared / none</th><th class="text-end">% out</th></tr></thead><tbody>
        <?php foreach ($t['holders'] as $k => $r): ?><tr><td><?= $k + 1 ?></td><td class="text-wrap"><?= hx_link(with_back('/institutions/' . (int) $r['institution_id'] . '?security=' . $sid, $here), e((string) $r['name']), 'fw-semibold') ?>
            <div class="fs-11"><?= institution_kind_chip((string) $r['kind']) ?> <?= change_state_chip((string) $r['state']) ?></div></td>
            <td class="text-end"><?= e(fmt_n($r['shares'])) ?><div class="d-md-none fs-11 text-muted"><?= e($money($r['value_dollars'])) ?></div></td><td class="text-end d-none d-md-table-cell"><?= e($money($r['value_dollars'])) ?></td>
            <td class="text-end d-none d-lg-table-cell"><?= e(fmt_n($r['voting_sole']) . ' / ' . fmt_n($r['voting_shared']) . ' / ' . fmt_n($r['voting_none'])) ?></td><td class="text-end"><?= $r['pct_of_outstanding'] === null ? '—' : e(number_format((float) $r['pct_of_outstanding'], 2)) ?></td></tr><?php endforeach; ?>
        <?php if ($s !== null): ?><tr class="fw-semibold table-light" id="thirteenf-total"><td></td><td>All 13F holders</td><td class="text-end"><?= e(fmt_n($s['shares'])) ?></td><td class="text-end d-none d-md-table-cell"><?= e($money($s['value_dollars'])) ?></td><td class="d-none d-lg-table-cell"></td><td class="text-end"><?= $s['pct_of_outstanding'] === null ? '—' : e(number_format((float) $s['pct_of_outstanding'], 2)) ?></td></tr><?php endif; ?>
        </tbody></table></div></div></div>
    <?php if ($t['options'] !== []): ?><div class="card mb-3" id="thirteenf-options-card"><div class="card-header"><h5 class="card-title mb-0">Puts and calls</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="thirteenf-options">
        <thead class="thead-light"><tr><th>Holder</th><th>Option</th><th class="text-end">Shares underlying</th></tr></thead><tbody>
        <?php foreach ($t['options'] as $r): ?><tr><td class="text-wrap"><?= hx_link('/institutions/' . (int) $r['institution_id'] . '?security=' . $sid, e((string) $r['name']), '') ?></td><td><span class="badge <?= $r['put_call'] === 'PUT' ? 'text-bg-danger' : 'text-bg-success' ?>"><?= e((string) $r['put_call']) ?></span></td><td class="text-end"><?= e(fmt_n($r['shares'])) ?></td></tr><?php endforeach; ?>
        </tbody></table></div><div class="p-3 fs-12 text-muted">Options are reported apart and never counted as holdings.</div></div></div><?php endif; ?>
    <?= view('shared/source-line.php', ['source' => 'the SEC\'s Form 13F data sets, the quarter ending ' . format_date($t['period']) . ' (holdings 45 days after the quarter; % of the outstanding count at the quarter\'s end)', 'date' => $t['period']]) ?>
    <?php endif; ?>
</div>
