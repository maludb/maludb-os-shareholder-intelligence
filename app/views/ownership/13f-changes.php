<?php /** The quarter against the prior (screen `ownership-13f-changes`). Data: security, c, state, here */
$sid = (int) $security['security_id'];
$chg = static fn ($v) => (float) $v > 0 ? '<span class="text-success">+' . e(fmt_n($v)) . '</span>' : ((float) $v < 0 ? '<span class="text-danger">' . e(fmt_n($v)) . '</span>' : '0');
?>
<?= view('shared/header.php', ['id' => 'ownership-13f-changes', 'title' => '13F changes', 'crumbs' => [['Home', '/'], ['Ownership', '/ownership/13f'], ['Changes', null]]]) ?>
<div class="main-content" id="ownership-13f-changes-content">
    <?= ownership_tabs('changes', $sid) ?>
    <?php if ($c['period'] === null): ?><div class="card"><div class="card-body text-muted">No 13F holding is loaded.</div></div><?php else: ?>
    <?= period_form('/ownership/13f/changes', $sid, $c['periods'], $c['period']) ?>
    <div class="d-flex flex-wrap gap-1 mb-3" id="changes-states"><?= hx_link('/ownership/13f/changes?security=' . $sid . '&period=' . e($c['period']), 'Every change', 'btn btn-sm btn-touch ' . ($state === null ? 'btn-primary' : 'btn-light'), 'id="changes-state-all"') ?>
        <?php foreach (['new', 'up', 'down', 'out'] as $k): ?><?= hx_link('/ownership/13f/changes?security=' . $sid . '&period=' . e($c['period']) . '&state=' . $k, e(ucfirst($k)) . ' (' . (int) ($c['counts'][$k] ?? 0) . ')', 'btn btn-sm btn-touch ' . ($state === $k ? 'btn-primary' : 'btn-light'), 'id="changes-state-' . $k . '"') ?><?php endforeach; ?></div>
    <?php if ($c['prior'] === null): ?><p class="fs-12 text-muted">The first quarter loaded: every holder is new.</p><?php endif; ?>
    <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="changes-table">
        <thead class="thead-light"><tr><th>Holder</th><th>Change</th><th class="text-end d-none d-sm-table-cell"><?= $c['prior'] ? e(format_date($c['prior'])) : 'Before' ?></th><th class="text-end"><?= e(format_date($c['period'])) ?></th><th class="text-end">Net</th></tr></thead><tbody>
        <?php foreach ($c['rows'] as $r): ?><tr id="change-row-<?= (int) $r['institution_id'] ?>"><td class="text-wrap"><?= hx_link(with_back('/institutions/' . (int) $r['institution_id'] . '?security=' . $sid, $here), e((string) $r['name']), 'fw-semibold') ?></td>
            <td><?= change_state_chip((string) $r['state']) ?></td><td class="text-end d-none d-sm-table-cell"><?= e(fmt_n($r['prior_shares'])) ?></td><td class="text-end"><?= e(fmt_n($r['shares'])) ?></td><td class="text-end"><?= $chg($r['net']) ?></td></tr><?php endforeach; ?>
        <?php if ($c['rows'] === []): ?><tr><td colspan="5" class="text-muted">None.</td></tr><?php endif; ?>
        </tbody></table></div></div></div>
    <?php endif; ?>
</div>
