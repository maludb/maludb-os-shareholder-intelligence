<?php /** The threshold-list history (screen `short-threshold`): the status now and every run. Data: security, t (threshold_runs()) */ $sid = (int) $security['security_id']; $st = $t['status']; ?>
<?= view('shared/header.php', ['id' => 'short-threshold', 'title' => 'Threshold lists', 'crumbs' => [['Home', '/'], ['Short side', '/short?security=' . $sid], ['Threshold lists', null]]]) ?>
<div class="main-content" id="short-threshold-content">
    <?= short_tabs('threshold', $sid) ?>
    <p class="fs-12 text-muted">A Reg SHO threshold security has had fails of 10,000 shares and 0.5% of its outstanding for five settlement days running; a firm with a fail it has carried thirteen days must close it out. Days are counted on the list days published (a weekend or a holiday does not break a run).</p>
    <div class="card"><div class="card-body" id="threshold-status">
        <?php if ($st !== null && $st['on_list']): ?><div class="fw-semibold text-danger">On the <?= e(THRESHOLD_LISTS[$st['list']] ?? $st['list']) ?> list — <?= (int) $st['consecutive_days'] ?> list days since <?= e(format_date($st['since'])) ?>.</div>
        <?php else: ?><div class="fw-semibold">Not on a threshold list<?= ($st['latest_list_date'] ?? null) ? ' as of ' . e(format_date($st['latest_list_date'])) : '' ?>.</div><?php endif; ?>
        <div class="fs-11 text-muted mt-1"><?php foreach ($t['lists_read'] as $l): ?><span class="me-3"><?= e(THRESHOLD_LISTS[$l['list']] ?? $l['list']) ?>: <?= (int) $l['days'] ?> days read, the latest <?= e(format_date($l['latest'])) ?></span><?php endforeach; ?><?= $t['lists_read'] === [] ? 'No list has been read yet.' : '' ?></div>
    </div></div>
    <?php if ($t['runs'] === []): ?><div class="card"><div class="card-body text-muted" id="threshold-runs-empty">This security has not been on a threshold list in the days read.</div></div>
    <?php else: ?><div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="threshold-runs">
        <thead class="thead-light"><tr><th>List</th><th>From</th><th>To</th><th class="text-end">List days</th></tr></thead><tbody>
        <?php foreach ($t['runs'] as $k => $r): ?><tr id="threshold-run-<?= $k ?>" class="<?= $r['current'] ? 'table-danger' : '' ?>"><td><?= e(THRESHOLD_LISTS[$r['list']] ?? $r['list']) ?><?= $r['current'] ? ' <span class="badge text-bg-danger">now</span>' : '' ?></td><td class="text-nowrap"><?= e(format_date($r['from_date'])) ?></td><td class="text-nowrap"><?= e(format_date($r['to_date'])) ?></td><td class="text-end"><?= (int) $r['list_days'] ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div><?php endif; ?>
    <div class="mt-2"><?= view('shared/source-line.php', ['source' => 'Nasdaq\'s and NYSE\'s Reg SHO lists, FINRA\'s OTC list', 'date' => $st['latest_list_date'] ?? null]) ?></div>
</div>
