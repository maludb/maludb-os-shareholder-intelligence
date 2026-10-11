<?php /** Every signal of a period with its disposition (screen `signal-history`). Data: issuer, f, h, rules, tz, here */
$qs = static fn (array $over): string => '/signals/history?' . http_build_query(array_filter(array_merge($f, $over), static fn ($v) => $v !== null && $v !== ''));
$fateChip = ['accepted' => ['text-bg-success', 'taken'], 'rejected' => ['text-bg-warning', 'not taken'], 'waiting' => ['text-bg-light border', 'waiting'], 'lapsed' => ['text-bg-light border', 'lapsed']];
?>
<?= view('shared/header.php', ['id' => 'signal-history', 'title' => 'Signal history', 'crumbs' => [['Home', '/'], ['Signals', '/signals'], ['History', null]]]) ?>
<div class="main-content" id="signal-history-content">
    <form method="get" action="/signals/history" hx-get="/signals/history" hx-target="#page-content" hx-push-url="true" class="card mb-3" id="history-filters"><div class="card-body py-2"><div class="row g-2 align-items-end">
        <div class="col-6 col-md"><label class="form-label fs-11 text-muted mb-1" for="history-from">From</label><input type="date" name="from" id="history-from" value="<?= e($f['from']) ?>" class="form-control form-control-sm"></div>
        <div class="col-6 col-md"><label class="form-label fs-11 text-muted mb-1" for="history-to">To</label><input type="date" name="to" id="history-to" value="<?= e($f['to']) ?>" class="form-control form-control-sm"></div>
        <div class="col-6 col-md"><label class="form-label fs-11 text-muted mb-1" for="history-status">Status</label><select name="status" id="history-status" class="form-select form-select-sm">
            <?php foreach (SIGNAL_STATUS_FILTERS as $k => $w): ?><option value="<?= e($k) ?>"<?= $k === $f['status'] ? ' selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md"><label class="form-label fs-11 text-muted mb-1" for="history-rule">Rule</label><select name="rule" id="history-rule" class="form-select form-select-sm"><option value="">Any rule</option>
            <?php foreach ($rules as $r): ?><option value="<?= e($r['key']) ?>"<?= $r['key'] === $f['rule'] ? ' selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-auto"><button type="submit" class="btn btn-light btn-sm btn-touch w-100" id="history-filter-btn">Show</button></div>
    </div></div></form>
    <div class="d-flex flex-wrap gap-3 fs-12 mb-3" id="history-proposals">
        <span class="text-muted">The agents' proposals in these dates:</span>
        <?php foreach (['accepted' => 'taken', 'rejected' => 'not taken', 'waiting' => 'waiting', 'lapsed' => 'lapsed with the signal'] as $k => $w): ?><span id="history-proposals-<?= e($k) ?>"><span class="fw-semibold"><?= (int) $h['proposals'][$k] ?></span> <?= e($w) ?></span><?php endforeach; ?>
    </div>
    <?php if ($h['rows'] === []): ?><div class="card"><div class="card-body text-muted" id="history-empty">No signal was raised on <?= e($issuer['name']) ?> between <?= e(format_date($f['from'])) ?> and <?= e(format_date($f['to'])) ?>.</div></div>
    <?php else: ?>
    <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="history-table">
        <thead class="thead-light"><tr><th>Raised</th><th>Signal</th><th>Status</th><th class="d-none d-md-table-cell">Why</th><th class="d-none d-lg-table-cell">By</th><th class="d-none d-md-table-cell">The proposal</th></tr></thead><tbody>
        <?php foreach ($h['rows'] as $s): $fate = proposal_fate($s); ?><tr id="history-row-<?= (int) $s['signal_id'] ?>">
            <td class="text-nowrap"><?= e(format_date((string) $s['raised_on'])) ?></td>
            <td class="text-wrap"><?= signal_severity_chip((string) $s['severity']) ?> <?= hx_link(with_back('/signals/' . (int) $s['signal_id'], $here), e((string) $s['rule_name']), 'fw-semibold') ?>
                <div class="mt-1"><span class="badge <?= e(SIGNAL_CLASS_CHIPS[$s['evidence_class']][0] ?? 'text-bg-secondary') ?> signal-class"><?= e(SIGNAL_CLASS_CHIPS[$s['evidence_class']][1] ?? '') ?></span></div>
                <div class="d-md-none text-muted mt-1"><?= $s['disposition_reason'] !== null ? e(SIGNAL_REASON_WORDS[$s['disposition_reason']] ?? '') : '' ?><?= $fate !== null ? ' · proposal ' . e($fateChip[$fate][1]) : '' ?></div></td>
            <td><?= signal_status_chip((string) $s['status']) ?></td>
            <td class="d-none d-md-table-cell text-wrap"><?= $s['disposition_reason'] === null ? '—' : e(SIGNAL_REASON_WORDS[$s['disposition_reason']] ?? (string) $s['disposition_reason']) ?><?= $s['event_title'] !== null ? '<div class="text-muted">' . e((string) $s['event_title']) . '</div>' : '' ?></td>
            <td class="d-none d-lg-table-cell"><?= e((string) ($s['disposed_by_name'] ?? '—')) ?><?= $s['disposed_at'] !== null ? '<div class="text-muted">' . e(format_ts((string) $s['disposed_at'], $tz, 'M j, Y')) . '</div>' : '' ?></td>
            <td class="d-none d-md-table-cell text-wrap"><?php if ($fate !== null): ?><span class="badge <?= e($fateChip[$fate][0]) ?>"><?= e($fateChip[$fate][1]) ?></span> <span class="text-muted"><?= e(SIGNAL_REASON_WORDS[$s['proposal_reason']] ?? '') ?> · <?= e((string) ($s['proposal_by_name'] ?? '')) ?></span><?php else: ?>—<?php endif; ?></td>
        </tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php if ($h['pages'] > 1): ?><nav class="d-flex justify-content-between align-items-center fs-12 mt-2" id="history-pages"><span class="text-muted"><?= (int) $h['total'] ?> signals · page <?= (int) $h['page'] ?> of <?= (int) $h['pages'] ?></span>
        <span class="d-flex gap-2"><?= $h['page'] > 1 ? hx_link($qs(['page' => $h['page'] - 1]), 'Newer', 'btn btn-light btn-sm') : '' ?><?= $h['page'] < $h['pages'] ? hx_link($qs(['page' => $h['page'] + 1]), 'Older', 'btn btn-light btn-sm') : '' ?></span></nav><?php endif; ?>
    <?php endif; ?>
</div>
